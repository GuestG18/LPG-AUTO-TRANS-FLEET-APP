<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ReviziiService.php';
require_once dirname(__DIR__) . '/services/ReviziiSasSyncService.php';

/**
 * Revizii: schimb ulei, placute frana, anvelope (cate un tab, ?tip=<tab>).
 *
 * Rute:
 *   ?page=revizii&tip=prezentare        Prezentare generală (KPI flota, categorii, alerte, istoric)
 *   ?page=revizii[&tip=placute_frana]   pagina unei categorii (doar BD: telemetria e deja persistata)
 *   ?page=revizii&action=save_config    POST: intervale / km la ultima revizie / factor ore motor
 *   ?page=revizii&action=record         POST: inregistreaza schimbul de ulei
 *   ?page=revizii&action=sync           POST JSON: sincronizare SasFleet cu buget limitat
 *   ?page=revizii&action=simulate       POST JSON: simulare pe date istorice (nu salveaza nimic)
 *   ?page=revizii&tip=istoric           Istoric revizii + actiunile istoric_* (ReviziiIstoricController)
 */
class ReviziiController
{
    private const PAGE = 'revizii';

    private PDO $db;
    private ReviziiService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new ReviziiService($db);
    }

    public function handle(string $action): void
    {
        if (!can(self::PAGE)) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis']);
            return;
        }

        match ($action) {
            'save_config' => $this->saveConfigAction(),
            'record' => $this->recordAction(),
            'sync' => $this->syncAction(),
            'simulate' => $this->simulateAction(),
            'istoric_data' => $this->istoric()->dataAction(),
            'istoric_detail' => $this->istoric()->detailAction(),
            'istoric_doc' => $this->istoric()->documentAction(),
            'istoric_doc_upload' => $this->istoric()->uploadAction(),
            'istoric_doc_delete' => $this->istoric()->deleteDocumentAction(),
            'istoric_verify' => $this->istoric()->verifyAction(),
            'istoric_reference' => $this->istoric()->referenceAction(),
            'istoric_export' => $this->istoric()->exportAction(),
            'istoric_report' => $this->istoric()->reportAction(),
            'istoric_reports' => $this->istoric()->reportsAction(),
            'istoric_report_download' => $this->istoric()->reportDownloadAction(),
            default => $this->index(),
        };
    }

    /** Revizii → Istoric revizii (?tip=istoric) are controller propriu. */
    private function istoric(): ReviziiIstoricController
    {
        require_once __DIR__ . '/ReviziiIstoricController.php';

        return new ReviziiIstoricController($this->db);
    }

    /** Tab-ul curent (GET la afisare, POST la salvare); necunoscut = schimb ulei. */
    private function currentTab(): string
    {
        $tab = (string) ($_POST['tab'] ?? $_GET['tip'] ?? ReviziiModel::TIP_SCHIMB_ULEI);

        return isset(ReviziiModel::TABS[$tab]) ? $tab : ReviziiModel::TIP_SCHIMB_ULEI;
    }

    private function pageUrl(string $tab): string
    {
        return build_query_url($tab === ReviziiModel::TIP_SCHIMB_ULEI ? ['page' => self::PAGE] : ['page' => self::PAGE, 'tip' => $tab]);
    }

    /**
     * Componenta (vehicul + tip) din formular, validata contra registrului de tab-uri.
     *
     * @param array<int, array<string, mixed>> $vehicles
     */
    private function validItem(array $vehicles, int $vehicleId, string $tip, string $tab): ?string
    {
        if (!isset($vehicles[$vehicleId])) {
            return null;
        }
        $items = ReviziiModel::itemsFor($tab, is_trailer_vehicle_type((string) $vehicles[$vehicleId]['tip_vehicul']));
        if (!array_key_exists($tip, $items)) {
            return null;
        }

        return trim($vehicles[$vehicleId]['nr_inmatriculare'] . ' ' . ($tab === ReviziiModel::TIP_SCHIMB_ULEI ? '' : ReviziiModel::TABS[$tab]['label'] . ' ') . $items[$tip]);
    }

    private function overview(): void
    {
        $sync = new ReviziiSasSyncService($this->db);
        render('revizii/overview.php', [
            'pageTitle' => 'Revizii · Prezentare generală',
            'currentPage' => self::PAGE,
            'overview' => $this->service->buildOverview(),
            'canEdit' => can(self::PAGE, 'edit'),
            'canSimulate' => can(self::PAGE, 'simulate'),
            'vehicles' => $this->service->vehicles(),
            'credentialsAvailable' => $sync->credentialsAvailable(),
            'autoSync' => $this->consumeAutoSync(),
        ]);
    }

    private function index(): void
    {
        if (($_GET['tip'] ?? '') === 'prezentare') {
            $this->overview();
            return;
        }
        if (($_GET['tip'] ?? '') === 'istoric') {
            $this->istoric()->index();
            return;
        }
        $tab = $this->currentTab();
        $dashboard = $this->service->buildDashboard($tab);
        $sync = new ReviziiSasSyncService($this->db);

        render('revizii/index.php', [
            'pageTitle' => 'Revizii',
            'currentPage' => self::PAGE,
            'dashboard' => $dashboard,
            'canEdit' => can(self::PAGE, 'edit'),
            'canSimulate' => can(self::PAGE, 'simulate'),
            'vehicles' => $this->service->vehicles(),
            'credentialsAvailable' => $sync->credentialsAvailable(),
            'autoSync' => $this->consumeAutoSync(),
        ]);
    }

    private function saveConfigAction(): void
    {
        $this->requirePostWithCsrf();
        $tab = $this->currentTab();
        $url = $this->pageUrl($tab);
        if (!can(self::PAGE, 'edit')) {
            access_deny_403();
        }

        $factor = str_replace(',', '.', trim((string) ($_POST['factor_km_ora'] ?? '')));
        $hourSource = (string) ($_POST['sursa_ore_motor'] ?? '');
        $components = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        $vehicles = $this->service->model()->getVehicles();
        $userId = $this->currentUserId();

        $errors = [];
        // Orele motor exista doar in tab-ul de ulei.
        $globalHours = ReviziiModel::TABS[$tab]['ore'] && ($factor !== '' || $hourSource !== '');
        if ($globalHours && ($factor === '' || !is_numeric($factor) || (float) $factor < 0 || (float) $factor > 500)) {
            $errors[] = 'Factorul ore motor trebuie să fie între 0 și 500 km/oră.';
        }
        if ($globalHours && !isset(ReviziiModel::HOUR_SOURCES[$hourSource])) {
            $errors[] = 'Sursa orelor motor eligibile este invalidă.';
        }
        $plans = [];
        foreach ($components as $component) {
            if (!is_array($component) || empty($component['enabled'])) {
                continue; // componenta nebifata in modal: configurarea ei ramane neschimbata
            }
            $vehicleId = (int) ($component['vehicle_id'] ?? 0);
            $tip = (string) ($component['tip'] ?? '');
            if ($vehicleId <= 0) {
                continue;
            }
            $label = $this->validItem($vehicles, $vehicleId, $tip, $tab);
            if ($label === null) {
                $errors[] = 'Vehicul inexistent sau componentă necunoscută.';
                continue;
            }
            $interval = $this->parseKm($component['interval_km'] ?? null);
            $kmLast = $this->parseKm($component['km_ultima_revizie'] ?? null);
            $warn = $this->parseKm($component['prag_avertizare_km'] ?? null);
            if ($interval === null || $interval < 500 || $interval > 300000) {
                $errors[] = sprintf('Intervalul de revizie pentru %s trebuie să fie între 500 și 300.000 km.', $label);
                continue;
            }
            if ($kmLast !== null && $kmLast > $vehicles[$vehicleId]['km_bord'] && $vehicles[$vehicleId]['km_bord'] > 0) {
                $errors[] = sprintf('Km la ultima revizie pentru %s depășesc km la bord (%s).', $label, number_format($vehicles[$vehicleId]['km_bord'], 0, ',', '.'));
                continue;
            }
            if ($warn === null || $warn >= $interval) {
                $errors[] = sprintf('Pragul de notificare pentru %s trebuie să fie între 0 și intervalul de revizie.', $label);
                continue;
            }
            $plans[] = [$vehicleId, $tip, $interval, $kmLast, $warn];
        }
        if ($plans === [] && !$globalHours && $errors === []) {
            $errors[] = 'Alege cel puțin o componentă de configurat.';
        }
        if ($errors !== []) {
            flash_set('danger', implode(' ', array_unique($errors)));
            redirect($url);
            return;
        }

        $this->db->beginTransaction();
        try {
            if ($globalHours) {
                $this->service->model()->saveSetting('factor_km_ora', (string) (float) $factor, $userId);
                $this->service->model()->saveSetting('sursa_ore_motor', $hourSource, $userId);
            }
            foreach ($plans as [$vehicleId, $tip, $interval, $kmLast, $warn]) {
                $this->service->model()->savePlan($vehicleId, $interval, $kmLast, (int) $vehicles[$vehicleId]['km_bord'], $userId, $warn, $tip);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            error_log('[ReviziiController][save_config] ' . $exception->getMessage());
            flash_set('danger', 'Configurarea nu a putut fi salvată.');
            redirect($url);
            return;
        }

        flash_set('success', $plans === [] ? 'Setările orelor motor au fost salvate.' : 'Configurarea reviziei a fost salvată.');
        $_SESSION['revizii_autosync'] = true;
        redirect($url);
    }

    private function recordAction(): void
    {
        $this->requirePostWithCsrf();
        $tab = $this->currentTab();
        $url = $this->pageUrl($tab);
        if (!can(self::PAGE, 'edit')) {
            access_deny_403();
        }

        // Fiecare componenta bifata (vehicul + tip) vine cu propriul T0 (data + ora) si km bord optional.
        $entries = [];
        foreach (is_array($_POST['rec'] ?? null) ? $_POST['rec'] : [] as $item) {
            if (!is_array($item) || empty($item['enabled']) || (int) ($item['vehicle_id'] ?? 0) <= 0) {
                continue;
            }
            $entries[] = [
                'vehicle_id' => (int) $item['vehicle_id'],
                'tip' => (string) ($item['tip'] ?? ''),
                'at' => trim((string) ($item['at'] ?? '')),
                'km' => $this->parseKm($item['km'] ?? null),
            ];
        }
        $notes = trim((string) ($_POST['observatii'] ?? ''));

        if ($entries === []) {
            flash_set('danger', 'Bifează cel puțin o componentă revizuită.');
            redirect($url);
            return;
        }

        try {
            $count = $this->service->recordService($entries, $notes !== '' ? mb_substr($notes, 0, 500) : null, $this->currentUserId());
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
            redirect($url);
            return;
        } catch (Throwable $exception) {
            error_log('[ReviziiController][record] ' . $exception->getMessage());
            flash_set('danger', 'Revizia nu a putut fi înregistrată.');
            redirect($url);
            return;
        }

        $what = $tab === ReviziiModel::TIP_SCHIMB_ULEI ? 'Schimbul de ulei' : 'Înlocuirea (' . ReviziiModel::TABS[$tab]['label'] . ')';
        flash_set('success', $count === 1 ? $what . ' a fost înregistrat(ă).' : sprintf('%s a fost înregistrat(ă) pentru %d componente.', $what, $count));
        // T0 nou in trecut: telemetria de la T0 se aduce imediat (pagina porneste sincronizarea).
        $_SESSION['revizii_autosync'] = true;
        // Inregistrata dintr-o alerta din Prezentare generală: revine acolo, cu datele reimprospatate.
        redirect(($_POST['return'] ?? '') === 'prezentare' ? build_query_url(['page' => self::PAGE, 'tip' => 'prezentare']) : $url);
    }

    /** Sincronizare la cerere, cu buget mic (restul il face cron-ul). */
    private function syncAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Cerere invalidă.']);
            return;
        }
        // Sesiunea nu trebuie tinuta blocata cat timp asteptam SAS.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(120);

        try {
            // Transe scurte: php -S serveste o cerere odata; pagina repeta cat timp raman ferestre.
            $stats = (new ReviziiSasSyncService($this->db))->run(15, 15);
        } catch (Throwable $exception) {
            error_log('[ReviziiController][sync] ' . $exception->getMessage());
            http_response_code(502);
            echo json_encode(['ok' => false, 'message' => 'SasFleet indisponibil: ' . $exception->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['ok' => !$stats['aborted'] && !$stats['locked'], 'stats' => $stats, 'message' => $stats['message']], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Simulare pe un T0 istoric. Nu scrie nimic: nici planuri, nici istoric, nici telemetrie.
     * Zilele nesincronizate local se aduc read-only din SasFleet (buget limitat).
     */
    private function simulateAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Cerere invalidă.']);
            return;
        }
        if (!can(self::PAGE, 'simulate')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Nu ai dreptul de a rula simulări.']);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(120);

        $interval = $this->parseKm($_POST['interval_km'] ?? null);
        $warn = $this->parseKm($_POST['prag_km'] ?? null);
        $factor = str_replace(',', '.', trim((string) ($_POST['factor'] ?? '')));
        $source = (string) ($_POST['sursa_ore'] ?? '');
        $simTab = (string) ($_POST['tip'] ?? ReviziiModel::TIP_SCHIMB_ULEI);
        if (!isset(ReviziiModel::TABS[$simTab])) {
            $simTab = ReviziiModel::TIP_SCHIMB_ULEI;
        }
        $errors = [];
        if ($interval === null || $interval < 500 || $interval > 300000) {
            $errors[] = 'Intervalul de revizie trebuie să fie între 500 și 300.000 km.';
        }
        if ($warn === null || ($interval !== null && $warn >= $interval)) {
            $errors[] = 'Pragul de notificare trebuie să fie mai mic decât intervalul.';
        }
        if (!is_numeric($factor) || (float) $factor < 0 || (float) $factor > 500) {
            $errors[] = 'Factorul de conversie trebuie să fie între 0 și 500 km/oră.';
        }
        if (!isset(ReviziiModel::HOUR_SOURCES[$source])) {
            $errors[] = 'Sursa orelor motor este invalidă.';
        }
        if ($errors !== []) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => implode(' ', $errors)], JSON_UNESCAPED_UNICODE);
            return;
        }

        $simVehicle = $this->service->vehicles()[(int) ($_POST['vehicle_id'] ?? 0)] ?? null;
        if ($simVehicle !== null && ReviziiModel::itemsFor($simTab, is_trailer_vehicle_type((string) $simVehicle['tip_vehicul'])) === []) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => ReviziiModel::TABS[$simTab]['label'] . ' nu se aplică semi-remorcilor (nu au motor).'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $sync = new ReviziiSasSyncService($this->db);
        $fetcher = $sync->credentialsAvailable()
            ? static fn (int $carId, string $from, string $to): array => $sync->fetchForSimulation($carId, $from, $to)
            : null;
        try {
            $result = $this->service->simulate(
                (int) ($_POST['vehicle_id'] ?? 0),
                str_replace('T', ' ', (string) ($_POST['de_la'] ?? '')),
                str_replace('T', ' ', (string) ($_POST['pana_la'] ?? '')),
                ['interval' => $interval, 'warn_km' => $warn, 'factor' => (float) $factor, 'source' => $source, 'ore' => ReviziiModel::TABS[$simTab]['ore']],
                $fetcher
            );
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        } catch (Throwable $exception) {
            error_log('[ReviziiController][simulate] ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Simularea nu a putut fi calculată.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['ok' => true, 'result' => $result, 'sources' => ReviziiModel::HOUR_SOURCES], JSON_UNESCAPED_UNICODE);
    }

    private function consumeAutoSync(): bool
    {
        $flag = !empty($_SESSION['revizii_autosync']);
        unset($_SESSION['revizii_autosync']);

        return $flag;
    }

    private function parseKm(mixed $value): ?int
    {
        $value = preg_replace('/[\s.,]/', '', trim((string) $value)) ?? '';
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function requirePostWithCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => self::PAGE]));
        }
        ensure_csrf_or_redirect(build_query_url(['page' => self::PAGE]));
    }

    private function currentUserId(): ?int
    {
        $userId = (int) (current_user()['id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }
}
