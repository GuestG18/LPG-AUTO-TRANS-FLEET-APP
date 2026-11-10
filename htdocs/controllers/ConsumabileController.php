<?php
declare(strict_types=1);

/**
 * Stoc Piese → tab „Consumabile” (Consumabile & Fluide Tehnice).
 * Pagina: ?page=mentenanta&action=stock&tab=consumabile&sub=overview|stocuri|miscari|vehicule
 * Operatiunile sunt POST JSON (CSRF), validate si executate atomic in ConsumabileStockService.
 */
final class ConsumabileController
{
    public const SUBTABS = [
        'overview' => 'Situație generală',
        'stocuri' => 'Stocuri și recipiente',
        'miscari' => 'Transferuri și consum',
        'vehicule' => 'Situație vehicule',
        'adblue' => 'AdBlue',
    ];

    private ConsumabileStockService $service;

    private VehicleFluidService $fluids;

    public function __construct(private PDO $db)
    {
        $this->service = new ConsumabileStockService($db);
        $this->fluids = new VehicleFluidService($db);
    }

    /** Datele tabului (doar subtabul deschis se incarca). */
    public function viewData(): array
    {
        if (!can('mentenanta', 'consumabile_view')) {
            access_deny_403();
        }
        $this->service->ensureSchema();
        (new AdBlueService($this->db))->ensureSchema();
        $sub = (string) ($_GET['sub'] ?? 'overview');
        if (!isset(self::SUBTABS[$sub])) {
            $sub = 'overview';
        }
        $filters = $this->filters();
        $data = [
            'sub' => $sub,
            'filters' => $filters,
            'products' => $this->service->products(),
            'containers' => $this->service->containers(['include_inactive' => $sub === 'stocuri' && !empty($_GET['inactive'])]),
            'vehicles' => $this->service->vehicles(),
            'components' => array_filter(
                $this->service->catalogComponents(),
                static fn (array $component): bool => $component['active'] && in_array($component['item_type'], ['consumabil', 'fluid'], true)
            ),
            'can_operate' => can('mentenanta', 'consumabile_operate'),
            'can_manage' => can('mentenanta', 'consumabile_manage'),
            'recent_dispenses' => [],
            'systems' => array_map(static fn (array $s): array => [
                'id' => (int) $s['id'], 'vehicle_id' => (int) $s['vehicle_id'], 'sistem' => (string) $s['sistem'], 'denumire' => (string) $s['denumire'],
                'produs_id' => (int) ($s['produs_id'] ?? 0), 'um' => (string) $s['um'], 'tip_fluid' => (string) ($s['tip_fluid'] ?? ''),
                'capacitate_nominala' => $s['capacitate_nominala'], 'interval_km' => $s['interval_km'], 'interval_luni' => $s['interval_luni'],
                'interval_ore' => $s['interval_ore'], 'sursa_km' => (string) $s['sursa_km'], 'sursa_ore' => (string) $s['sursa_ore'],
                'avertizare_procent' => (int) $s['avertizare_procent'], 'verificare_zile' => $s['verificare_zile'], 'prag_consum' => $s['prag_consum'],
                'critic' => (int) $s['critic'], 'circuit_inchis' => (int) $s['circuit_inchis'], 'alimentare_la_plin' => (int) ($s['alimentare_la_plin'] ?? 0),
                'referinta_data' => $s['referinta_data'] !== null ? str_replace(' ', 'T', substr((string) $s['referinta_data'], 0, 16)) : '',
                'referinta_km_can' => $s['referinta_km_can'], 'referinta_km_gps' => $s['referinta_km_gps'],
            ], $this->fluids->systems()),
        ];
        if ($sub === 'overview') {
            $data['overview'] = $this->service->overview();
            $data['overview']['fluid_status'] = $this->fluids->statusCounts();
            $alerts = new ConsumabileAlertService($this->db);
            $alerts->ensureSchema();
            $alerts->refreshIfDue();
            $data['alerts'] = $alerts->openAlerts();
            $data['alerts_resolved'] = $alerts->recentlyResolved(7);
        } elseif ($sub === 'stocuri') {
            if (!empty($filters['state']) || !empty($filters['produs_id']) || !empty($filters['tip_fluid'])) {
                $data['containers'] = $this->service->containers($filters + ['include_inactive' => !empty($_GET['inactive'])]);
            }
        } elseif ($sub === 'miscari') {
            $data['movements'] = $this->service->movements($filters, max(1, (int) ($_GET['p'] ?? 1)), 25);
        } elseif ($sub === 'vehicule') {
            $data['vehicle_rows'] = $this->fluids->statusRows($filters);
            $configured = array_flip(array_map(static fn (array $s): int => (int) $s['vehicle_id'], $data['systems']));
            $data['unconfigured_vehicles'] = array_values(array_filter($data['vehicles'], static fn (array $v): bool => !isset($configured[(int) $v['id']])));
        } elseif ($sub === 'adblue') {
            $data['adblue'] = (new AdBlueService($this->db))->analysis($filters);
            $data['can_fix_fillups'] = (function_exists('is_admin') && is_admin()) || can('carburanti', 'set_full');
        }
        if ($data['can_operate']) {
            $data['recent_dispenses'] = $this->service->recentDispenses();
        }

        return $data;
    }

    public function handle(string $action): void
    {
        $this->requireJsonPost();
        try {
            $this->service->ensureSchema();
            (new AdBlueService($this->db))->ensureSchema();
            switch ($action) {
                case 'consumabile_alert_close':
                    (new ConsumabileAlertService($this->db))->close((int) ($_POST['id'] ?? 0), (string) ($_POST['motiv'] ?? ''), $this->userId());
                    audit_log_write($this->db, 'consumabile_alerte', (int) ($_POST['id'] ?? 0), 'update', 'Alertă închisă după verificare: ' . (string) ($_POST['motiv'] ?? ''), null, null, $this->userId());
                    $this->json(['ok' => true, 'message' => 'Alerta a fost închisă. Se redeschide doar dacă apar date noi.']);
                case 'consumabile_adblue_sync':
                    $stats = (new AdBlueService($this->db))->syncCanDiesel($this->filtersFrom($_POST), 30);
                    $this->json(['ok' => !$stats['locked'], 'stats' => $stats, 'message' => $stats['message']], $stats['locked'] ? 409 : 200);
                case 'consumabile_op':
                    $type = (string) ($_POST['op'] ?? '');
                    if ($type === 'stornare' && !can('mentenanta', 'consumabile_manage')) {
                        $this->json(['ok' => false, 'message' => 'Stornarea cere dreptul de administrare consumabile.'], 403);
                    }
                    $result = $this->service->operate($type, $_POST, $this->userId());
                    $movement = $result['movement'];
                    if (!$result['duplicate']) {
                        audit_log_write($this->db, 'consumabile_miscari', (int) ($movement['id'] ?? 0), 'create',
                            ($movement['tip_label'] ?? $type) . ': ' . ($movement['cantitate'] ?? '') . ' ' . ($movement['um'] ?? '') . ' ' . ($movement['produs'] ?? ''),
                            null, $movement, $this->userId());
                    }
                    $this->json(['ok' => true, 'duplicate' => $result['duplicate'], 'movement' => $movement,
                        'message' => $result['duplicate'] ? 'Operațiunea fusese deja înregistrată (nu s-a dublat).' : 'Operațiunea a fost înregistrată.']);
                case 'consumabile_container_save':
                    $id = (int) ($_POST['id'] ?? 0);
                    $before = $id > 0 ? $this->service->container($id) : null;
                    $savedId = $this->service->saveContainer($_POST, $id > 0 ? $id : null, $this->userId());
                    audit_log_write($this->db, 'consumabile_recipiente', $savedId, $id > 0 ? 'update' : 'create',
                        'Recipient ' . ($this->service->container($savedId)['cod'] ?? $savedId), $before, $this->service->container($savedId), $this->userId());
                    $this->json(['ok' => true, 'id' => $savedId, 'message' => $id > 0 ? 'Recipientul a fost actualizat.' : 'Recipientul a fost înregistrat.']);
                case 'consumabile_product_save':
                    $id = (int) ($_POST['id'] ?? 0);
                    $before = $id > 0 ? $this->service->product($id) : null;
                    $savedId = $this->service->saveProduct($_POST, $id > 0 ? $id : null, $this->userId());
                    audit_log_write($this->db, 'consumabile_produse', $savedId, $id > 0 ? 'update' : 'create',
                        'Produs ' . trim((string) ($_POST['denumire'] ?? '')), $before, $this->service->product($savedId), $this->userId());
                    $this->json(['ok' => true, 'id' => $savedId, 'message' => $id > 0 ? 'Produsul a fost actualizat.' : 'Produsul a fost adăugat în catalog.']);
                case 'consumabile_system_save':
                    $id = (int) ($_POST['id'] ?? 0);
                    $before = $id > 0 ? $this->fluids->system($id) : null;
                    $saved = $this->fluids->saveSystem($_POST, $id > 0 ? $id : null, $this->userId());
                    audit_log_write($this->db, 'vehicule_sisteme_fluide', $saved['id'], $id > 0 ? 'update' : 'create',
                        'Sistem fluid ' . (string) ($_POST['sistem'] ?? '') . ' vehicul ' . $saved['vehicle_id'] . ($saved['copied'] > 0 ? ' (+' . $saved['copied'] . ' vehicule)' : ''),
                        $before, $this->fluids->system($saved['id']), $this->userId());
                    $message = $id > 0 ? 'Sistemul a fost actualizat.' : 'Sistemul a fost configurat'
                        . ($saved['copied'] > 0 ? ' și copiat pe încă ' . $saved['copied'] . ' vehicule' : '')
                        . ($saved['skipped'] > 0 ? ' (' . $saved['skipped'] . ' vehicule aveau deja sistemul)' : '') . '.';
                    $this->json(['ok' => true, 'id' => $saved['id'], 'message' => $message]);
                case 'consumabile_system_event':
                    $result = $this->fluids->recordEvent($_POST, $this->userId());
                    if (!$result['duplicate']) {
                        audit_log_write($this->db, 'vehicule_fluide_evenimente', $result['id'], 'create',
                            'Fluid vehicul: ' . (string) ($_POST['tip'] ?? '') . ' sistem ' . (int) ($_POST['sistem_id'] ?? 0), null, array_diff_key($_POST, ['_token' => 1]), $this->userId());
                    }
                    $this->json(['ok' => true, 'duplicate' => $result['duplicate'], 'message' => $result['duplicate'] ? 'Înregistrarea exista deja (nu s-a dublat).' : 'Înregistrarea a fost salvată.']);
                case 'consumabile_event_cancel':
                    $this->fluids->cancelEvent((int) ($_POST['id'] ?? 0), (string) ($_POST['motiv'] ?? ''), $this->userId());
                    audit_log_write($this->db, 'vehicule_fluide_evenimente', (int) ($_POST['id'] ?? 0), 'update', 'Anulare înregistrare fluid: ' . (string) ($_POST['motiv'] ?? ''), null, null, $this->userId());
                    $this->json(['ok' => true, 'message' => 'Înregistrarea a fost anulată (rămâne în istoric).']);
                case 'consumabile_compat_save':
                    $compatible = (string) ($_POST['compatibil'] ?? '1') === '1';
                    $this->service->setCompatible((int) ($_POST['produs_a'] ?? 0), (int) ($_POST['produs_b'] ?? 0), $compatible, (string) ($_POST['note'] ?? ''), $this->userId());
                    audit_log_write($this->db, 'consumabile_compatibilitate', 0, 'update',
                        'Compatibilitate produse ' . (int) ($_POST['produs_a'] ?? 0) . ' / ' . (int) ($_POST['produs_b'] ?? 0) . ': ' . ($compatible ? 'compatibile' : 'retrasă'), null, null, $this->userId());
                    $this->json(['ok' => true, 'message' => $compatible ? 'Produsele au fost declarate compatibile.' : 'Compatibilitatea a fost retrasă.']);
                default:
                    $this->json(['ok' => false, 'message' => 'Acțiune necunoscută.'], 404);
            }
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            error_log('[ConsumabileController][' . $action . '] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Operațiunea nu a putut fi salvată. Reîncearcă.'], 500);
        }
    }

    /** Filtrele din POST (butonul de actualizare trimite filtrele paginii). */
    private function filtersFrom(array $source): array
    {
        $backup = $_GET;
        $_GET = $source;
        try {
            return $this->filters();
        } finally {
            $_GET = $backup;
        }
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $date = static function (mixed $value): string {
            $value = trim((string) $value);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };
        $vehicleIds = $_GET['vehicle_ids'] ?? [];
        if (!is_array($vehicleIds)) {
            $vehicleIds = explode(',', (string) $vehicleIds);
        }

        return [
            'tip' => (string) ($_GET['tip'] ?? ''),
            'produs_id' => max(0, (int) ($_GET['produs_id'] ?? 0)),
            'recipient_id' => max(0, (int) ($_GET['recipient_id'] ?? 0)),
            'tip_fluid' => isset(ConsumabileStockService::FLUID_TYPES[(string) ($_GET['tip_fluid'] ?? '')]) ? (string) $_GET['tip_fluid'] : '',
            'state' => in_array((string) ($_GET['state'] ?? ''), ['ok', 'stoc_scazut', 'gol', 'inactiv'], true) ? (string) $_GET['state'] : '',
            'sistem' => isset(VehicleFluidService::SYSTEM_PRESETS[(string) ($_GET['sistem'] ?? '')]) ? (string) $_GET['sistem'] : '',
            'status' => isset(VehicleFluidService::STATUSES[(string) ($_GET['status'] ?? '')]) ? (string) $_GET['status'] : '',
            'vehicle_ids' => array_values(array_filter(array_map('intval', $vehicleIds))),
            'from' => $date($_GET['from'] ?? ''),
            'to' => $date($_GET['to'] ?? ''),
        ];
    }

    /** Istoricul unui sistem (GET JSON, pentru panoul lateral). */
    public function systemHistory(): never
    {
        if (!can('mentenanta', 'consumabile_view')) {
            $this->json(['ok' => false, 'message' => 'Acces interzis.'], 403);
        }
        $id = (int) ($_GET['id'] ?? 0);
        $system = $this->fluids->system($id);
        if ($system === null) {
            $this->json(['ok' => false, 'message' => 'Sistemul nu mai există.'], 404);
        }
        $rows = $this->fluids->statusRows(['vehicle_ids' => [(int) $system['vehicle_id']]]);
        $row = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $id))[0] ?? null;
        $this->json(['ok' => true, 'system' => $row, 'events' => $this->fluids->events($id)]);
    }

    private function requireJsonPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina și reîncearcă.'], 419);
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function userId(): ?int
    {
        $id = (int) (current_user()['id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
