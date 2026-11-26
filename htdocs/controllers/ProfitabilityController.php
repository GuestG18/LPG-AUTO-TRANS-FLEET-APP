<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ProfitabilityRegisterService.php';

/**
 * Pagina „Rentabilitate Operationala” (?page=rentabilitate). Doar citire; nu scrie tranzactii.
 *
 *   action=index        — pagina
 *   action=list         — JSON: randuri Vehicule / Soferi, totaluri, indicatorii cardurilor KPI, optiuni de filtru (paginat pe server)
 *   action=kpi          — JSON: doar indicatorii cardurilor KPI pentru un scop (cardul din Tablou de bord, scop propriu)
 *   action=kpi_breakdown— JSON: defalcarea unui card (by=beneficiar|tip|vehicul|sofer) in scopul curent
 *   action=vehicle      — JSON: rezumatul vehiculului (categorii de cost, soferi, semiremorci)
 *   action=costs        — JSON: inregistrarile unei categorii (cat=...)
 *   action=driver_costs — JSON: inregistrarile lunare ale unei componente de cost a soferului (cat=cod)
 *   action=line_view    — HTML: fisa unei inregistrari (scope=sofer pentru sofer; partial=1 in panoul peste pagina)
 *   action=trips        — JSON: cursele vehiculului / soferului (scope=vehicul|sofer, beneficiar optional)
 *   action=beneficiaries— JSON: rentabilitatea pe beneficiar
 *   action=investments  — JSON: dosar achizitie + proiecte (din Istoric Financiar Vehicul)
 *   action=documents    — JSON: documentele vehiculului / ansamblului (pagina Documente)
 *   action=drivers      — JSON: soferi / simulare (fara schimbarea asignarii)
 *   action=driver       — JSON: rezumatul soferului
 *   action=export       — CSV cu tabelul curent (aceleasi filtre)
 *   action=location_detail — JSON: o locatie din modul Locatii, pe vehicule si pe rute (id = loc fizic, -1 = fara loc)
 *   action=locations    — JSON: locurile fizice cu sursele lor (ecranul „Locații fizice”) + sugestii de unire
 *   action=save_location— POST JSON: op=update|move|merge (dreptul rentabilitate.locatii)
 *
 * Filtrele de locatie (oricare mod): origine / destinatie / depozit = id loc fizic (-1 = fara loc); modul Locatii
 * grupeaza randurile pe dimensiunea dim (origine | destinatie | depozit). Vezi LocationDimensionService.
 */
class ProfitabilityController
{
    public function __construct(private PDO $db)
    {
    }

    public function handle(string $action): void
    {
        if (function_exists('can') && !can('rentabilitate')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis', 'currentPage' => '']);
            return;
        }
        if ($action === 'export' && function_exists('can') && !can('rentabilitate', 'export')) {
            http_response_code(403);
            exit('Nu aveți dreptul de export.');
        }
        if ($action === 'save_location') {
            $this->saveLocation();
            return;
        }
        if ($action === 'index' || $action === '') {
            $this->index();
            return;
        }
        if ($action === 'line_view') {
            $this->lineView();
            return;
        }
        $service = new ProfitabilityRegisterService($this->db);
        [$from, $to] = ProfitabilityRegisterService::range($_GET['from'] ?? null, $_GET['to'] ?? null);
        $id = (int) ($_GET['id'] ?? 0);
        // Scopul de analiza (acelasi pentru tabel, cardurile KPI si defalcarile lor).
        $scope = [
            'mode' => (string) ($_GET['mode'] ?? 'vehicule'), 'q' => (string) ($_GET['q'] ?? ''), 'tip' => (string) ($_GET['tip'] ?? ''),
            'beneficiar' => self::listParam('beneficiar'), 'status' => (string) ($_GET['status'] ?? ''), 'from' => $from, 'to' => $to,
            'vehicle_id' => self::listParam('vehicle_id'), 'driver_id' => self::listParam('driver_id'), 'keep' => (string) ($_GET['keep'] ?? ''),
        ] + self::locationParams();
        try {
            $payload = match ($action) {
                'list' => $service->list([
                    'mode' => (string) ($_GET['mode'] ?? 'vehicule'), 'q' => (string) ($_GET['q'] ?? ''), 'tip' => (string) ($_GET['tip'] ?? ''),
                    'beneficiar' => self::listParam('beneficiar'), 'status' => (string) ($_GET['status'] ?? ''), 'from' => $from, 'to' => $to,
                    'page' => (int) ($_GET['p'] ?? 1), 'per_page' => (int) ($_GET['per_page'] ?? 25), 'sort' => (string) ($_GET['sort'] ?? ''),
                    'vehicle_id' => self::listParam('vehicle_id'), 'driver_id' => self::listParam('driver_id'), 'keep' => (string) ($_GET['keep'] ?? ''),
                ] + self::locationParams()),
                'location_detail' => $service->locationDetail($scope, $id),
                'locations' => $service->locations()->overview() + ['canEdit' => self::canEditLocations()],
                'kpi' => $service->kpiSummary($scope),
                'kpi_breakdown' => $service->kpiBreakdown($scope, (string) ($_GET['by'] ?? '')),
                'vehicle' => $service->vehicleSummary($id, $from, $to),
                'costs' => $service->costLines($id, (string) ($_GET['cat'] ?? ''), $from, $to),
                'driver_costs' => $service->driverCostLines($id, (string) ($_GET['cat'] ?? ''), $from, $to),
                'trips' => $service->trips(($_GET['scope'] ?? '') === 'sofer' ? 'sofer' : 'vehicul', $id, $from, $to,
                    isset($_GET['beneficiar']) && $_GET['beneficiar'] !== '' ? (int) $_GET['beneficiar'] : null, max(1, (int) ($_GET['p'] ?? 1))),
                'beneficiaries' => ['rows' => $service->beneficiaryBreakdown(($_GET['scope'] ?? '') === 'sofer' ? 'sofer' : 'vehicul', $id, $from, $to)],
                'investments' => ['rows' => $service->investments($id, $from, $to)],
                'documents' => ['rows' => $service->documents($id, $from, $to)],
                'drivers' => $service->driverSimulation($id, $from, $to),
                'driver' => $service->driverSummary($id, $from, $to),
                'export' => $this->export($service, $from, $to),
                default => throw new InvalidArgumentException('Acțiune necunoscută.'),
            };
            $this->json(['ok' => true, 'from' => $from, 'to' => $to] + $payload);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[ProfitabilityController][' . $action . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['ok' => false, 'message' => 'Datele nu au putut fi calculate. Reîncearcă.'], 500);
        }
    }

    private function index(): void
    {
        $service = new ProfitabilityRegisterService($this->db);
        [$from, $to] = ProfitabilityRegisterService::range($_GET['from'] ?? null, $_GET['to'] ?? null);
        render('rentabilitate/index.php', [
            'pageTitle' => 'Rentabilitate Operațională',
            'currentPage' => 'rentabilitate',
            'from' => $from,
            'to' => $to,
            'beneficiaries' => $service->beneficiaries(),
            'canExport' => !function_exists('can') || can('rentabilitate', 'export'),
            'canEditLocations' => self::canEditLocations(),
            'links' => [
                'cursa_noua' => function_exists('can') && can('dispecer_curse') ? build_query_url(['page' => 'dispecer_curse']) : null,
                'cheltuiala' => function_exists('can') && can('cheltuieli') ? build_query_url(['page' => 'cheltuieli']) : null,
                'dosar' => function_exists('can') && can('istoric_financiar') ? build_query_url(['page' => 'istoric_financiar']) : null,
                'cost_km' => function_exists('can') && can('cost_operational') ? build_query_url(['page' => 'cost_operational']) : null,
            ],
        ]);
    }

    /**
     * Fisa unei inregistrari de cost (clic pe rand in Costuri): in panoul peste pagina (partial=1,
     * race-view-overlay.js — ca fisa cursei din Dispecer curse), altfel pagina completa (Ctrl + clic).
     * Inregistrarile sunt calculate (fara id propriu): se recalculeaza categoria pentru aceeasi perioada
     * (exact ca action=costs) si se ia randul i.
     */
    private function lineView(): void
    {
        $partial = (string) ($_GET['partial'] ?? '') === '1';
        [$from, $to] = ProfitabilityRegisterService::range($_GET['from'] ?? null, $_GET['to'] ?? null);
        $data = null;
        try {
            $service = new ProfitabilityRegisterService($this->db);
            $args = [(int) ($_GET['id'] ?? 0), (string) ($_GET['cat'] ?? ''), (int) ($_GET['i'] ?? -1), $from, $to];
            $data = ($_GET['scope'] ?? '') === 'sofer' ? $service->driverCostLineDetail(...$args) : $service->costLineDetail(...$args);
        } catch (InvalidArgumentException) {
            $data = null;
        } catch (Throwable $e) {
            error_log('[ProfitabilityController][line_view] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        if ($data === null) {
            http_response_code(404);
            if ($partial) {
                exit;
            }
            render('errors/404.php', ['pageTitle' => 'Înregistrare inexistentă', 'currentPage' => 'rentabilitate']);
            return;
        }
        $viewData = $data + [
            'pageTitle' => 'Înregistrare de cost — ' . $data['subiect']['text'],
            'currentPage' => 'rentabilitate',
            'from' => $from,
            'to' => $to,
            'viewPartial' => $partial,
        ];
        if ($partial) {
            header('Content-Type: text/html; charset=UTF-8');
            (static function (array $__data): void {
                extract($__data, EXTR_SKIP);
                require BASE_PATH . '/views/rentabilitate/line_view.php';
            })($viewData);
            exit;
        }
        render('rentabilitate/line_view.php', $viewData);
    }

    private function export(ProfitabilityRegisterService $service, string $from, string $to): never
    {
        $csv = $service->exportCsv([
            'mode' => (string) ($_GET['mode'] ?? 'vehicule'), 'q' => (string) ($_GET['q'] ?? ''), 'tip' => (string) ($_GET['tip'] ?? ''),
            'beneficiar' => self::listParam('beneficiar'), 'status' => (string) ($_GET['status'] ?? ''), 'from' => $from, 'to' => $to,
            'vehicle_id' => self::listParam('vehicle_id'), 'driver_id' => self::listParam('driver_id'),
        ] + self::locationParams());
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rentabilitate_' . $from . '_' . $to . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    /** Filtru cu selectie multipla din URL: "3,5,8" (sau 3); validat in serviciu (ProfitabilityRegisterService::ids). */
    private static function listParam(string $name): string
    {
        $v = $_GET[$name] ?? '';
        return is_array($v) ? implode(',', array_map('strval', $v)) : substr((string) $v, 0, 2000);
    }

    /** dim + filtrele de locatie din cerere (validate in serviciu). */
    private static function locationParams(): array
    {
        return ['dim' => (string) ($_GET['dim'] ?? ''), 'origine' => self::listParam('origine'),
            'destinatie' => self::listParam('destinatie'), 'depozit' => self::listParam('depozit'), 'ruta' => self::listParam('ruta'), 'capacitate' => self::listParam('capacitate')];
    }

    private static function canEditLocations(): bool
    {
        return !function_exists('can') || can('rentabilitate', 'locatii');
    }

    /**
     * Gestionarea locurilor fizice (doar stratul de raportare; tarifele si configurarea transportului raman neatinse):
     *   op=update  id, nume, tip          — redenumire / punct fizic sau regiune
     *   op=move    sursa, sursa_id, tinta — muta un loc de incarcare / o zona la alt loc fizic (tinta 0 = loc nou)
     *   op=merge   from, into             — uneste doua locuri fizice
     */
    private function saveLocation(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!self::canEditLocations()) {
            $this->json(['ok' => false, 'message' => 'Nu aveți dreptul de a gestiona locațiile.'], 403);
        }
        $in = json_decode((string) file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : $_POST;
        if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_token'] ?? null))) {
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina.'], 419);
        }
        $locations = new LocationDimensionService($this->db);
        try {
            match ((string) ($in['op'] ?? '')) {
                'update' => $locations->updatePlace((int) ($in['id'] ?? 0), (string) ($in['nume'] ?? ''), (string) ($in['tip'] ?? '')),
                'move' => $locations->moveSource((string) ($in['sursa'] ?? ''), (int) ($in['sursa_id'] ?? 0), (int) ($in['tinta'] ?? 0)),
                'merge' => $locations->merge((int) ($in['from'] ?? 0), (int) ($in['into'] ?? 0)),
                default => throw new InvalidArgumentException('Operație necunoscută.'),
            };
            $this->json(['ok' => true] + $locations->overview() + ['canEdit' => true]);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[ProfitabilityController][save_location] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['ok' => false, 'message' => 'Modificarea nu a putut fi salvată.'], 500);
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
}
