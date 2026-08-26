<?php
declare(strict_types=1);

/**
 * Controller pentru pagina "Cost operațional / km" (?page=cost_operational).
 *
 * Rute:
 *   action=index          — pagina (view)
 *   action=data           — JSON: modelul financiar complet pentru filtrele date
 *   action=details        — JSON: trasabilitatea unui scop (vehicul / categorie)
 *   action=simulate       — JSON: scenariu de simulare (in-memory, nu scrie nimic)
 *   action=element_save   — POST (admin): creare/editare element financiar (configurare)
 *   action=element_toggle — POST (admin): activare/dezactivare element
 *   action=element_delete — POST (admin): ștergere element
 *   action=settings_save  — POST (admin): parametrii de calcul (curs EUR/RON etc.)
 *   action=export         — CSV: structura costurilor pe categorii
 */
class OperationalCostController
{
    private PDO $db;
    private OperationalCostService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new OperationalCostService($db);
    }

    public function handle(string $action): void
    {
        if (function_exists('can') && !can('cost_operational')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis', 'currentPage' => '']);
            return;
        }

        switch ($action) {
            case 'data':
                $this->data();
                return;
            case 'details':
                $this->details();
                return;
            case 'simulate':
                $this->simulate();
                return;
            case 'element_save':
                $this->elementSave();
                return;
            case 'element_toggle':
                $this->elementToggle();
                return;
            case 'element_delete':
                $this->elementDelete();
                return;
            case 'settings_save':
                $this->settingsSave();
                return;
            case 'export':
                $this->export();
                return;
            default:
                $this->index();
        }
    }

    private function index(): void
    {
        $model = $this->service->model();
        render('cost_operational/index.php', [
            'pageTitle' => 'Cost operațional / km',
            'currentPage' => 'cost_operational',
            'schemaReady' => $model->schemaReady(),
            'beneficiaries' => $model->getBeneficiaries(),
            'canConfigure' => !function_exists('can') || can('cost_operational', 'configure'),
            'settings' => $model->getSettings(),
        ]);
    }

    /** @return array{period:string,beneficiar_id:int,categorie:string,vehicle_id:int,driver_id:int,km_source:string} */
    private function readFilters(): array
    {
        return [
            'period' => (string) ($_GET['period'] ?? ''),
            'beneficiar_id' => (int) ($_GET['beneficiar_id'] ?? 0),
            'categorie' => (string) ($_GET['categorie'] ?? ''),
            'vehicle_id' => (int) ($_GET['vehicle_id'] ?? 0),
            'driver_id' => (int) ($_GET['driver_id'] ?? 0),
            'km_source' => (string) ($_GET['km_source'] ?? ''),
        ];
    }

    private function data(): void
    {
        try {
            $payload = $this->service->compute($this->readFilters());
            $payload['success'] = true;
            $this->sendJson($payload);
        } catch (Throwable $e) {
            error_log('[OperationalCostController][data] ' . $e->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Eroare la calcularea modelului financiar.'], 500);
        }
    }

    private function details(): void
    {
        $scope = (string) ($_GET['scope'] ?? '');
        $id = $_GET['id'] ?? '';
        if (!in_array($scope, ['vehicle', 'category'], true) || $id === '') {
            $this->sendJson(['success' => false, 'message' => 'Parametri invalizi.'], 400);
        }
        try {
            $result = $this->service->computeDetails(
                $this->readFilters(),
                $scope,
                $scope === 'vehicle' ? (int) $id : (string) $id
            );
            $this->sendJson(['success' => true] + $result);
        } catch (Throwable $e) {
            error_log('[OperationalCostController][details] ' . $e->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Eroare la încărcarea detaliilor.'], 500);
        }
    }

    private function simulate(): void
    {
        try {
            $payload = $this->service->compute($this->readFilters());
            $scenario = CostBreakEvenService::simulate($payload['breakeven'], [
                'km' => (float) ($_GET['sim_km'] ?? 0),
                'trips' => (int) ($_GET['sim_trips'] ?? 0),
                'revenue_per_km' => (float) ($_GET['sim_revenue_km'] ?? 0),
            ]);
            $this->sendJson(['success' => true, 'baseline' => $payload['breakeven'], 'scenario' => $scenario]);
        } catch (Throwable $e) {
            error_log('[OperationalCostController][simulate] ' . $e->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Eroare la simulare.'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Configurare (element financiar + parametri) — doar admin
    // ------------------------------------------------------------------

    private function requireConfigureJson(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['success' => false, 'message' => 'Metodă invalidă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->sendJson(['success' => false, 'message' => 'Token CSRF invalid. Reîncearcă operațiunea.'], 419);
        }
        if (function_exists('can') && !can('cost_operational', 'configure')) {
            $this->sendJson(['success' => false, 'message' => 'Nu ai dreptul de configurare a elementelor financiare.'], 403);
        }
    }

    private function elementSave(): void
    {
        $this->requireConfigureJson();
        $model = $this->service->model();
        if (!$model->schemaReady()) {
            $this->sendJson(['success' => false, 'message' => 'Rulați migrarea database/update_cost_operational_km.sql mai întâi.'], 409);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $cod = trim((string) ($_POST['cod'] ?? ''));
        $nume = trim((string) ($_POST['nume'] ?? ''));
        if ($nume === '') {
            $this->sendJson(['success' => false, 'message' => 'Numele elementului este obligatoriu.'], 422);
        }
        if ($cod === '') {
            $cod = strtolower(preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nume) ?: $nume) ?? '');
            $cod = trim($cod, '_') ?: ('element_' . time());
        }
        try {
            $savedId = $model->saveElement([
                'cod' => $cod,
                'nume' => $nume,
                'tip' => (string) ($_POST['tip'] ?? 'fix'),
                'clasa_sursa' => (string) ($_POST['clasa_sursa'] ?? 'config'),
                'sursa_referinta' => (string) ($_POST['sursa_referinta'] ?? 'manual'),
                'sursa_filtru' => (string) ($_POST['sursa_filtru'] ?? ''),
                'scop' => (string) ($_POST['scop'] ?? 'vehicle'),
                'periodicitate' => (string) ($_POST['periodicitate'] ?? 'anual'),
                'alocare' => (string) ($_POST['alocare'] ?? 'direct'),
                'valoare_config' => $_POST['valoare_config'] ?? '',
                'valoare_moneda' => (string) ($_POST['valoare_moneda'] ?? 'RON'),
                'amortizare_ani' => $_POST['amortizare_ani'] ?? '',
                'regim_tva' => (string) ($_POST['regim_tva'] ?? 'net'),
                'tipuri_vehicul' => (string) ($_POST['tipuri_vehicul'] ?? ''),
                'activ' => !empty($_POST['activ']),
                'ordine' => (int) ($_POST['ordine'] ?? 500),
                'observatii' => (string) ($_POST['observatii'] ?? ''),
            ], $id > 0 ? $id : null);
            $this->sendJson(['success' => true, 'id' => $savedId]);
        } catch (Throwable $e) {
            error_log('[OperationalCostController][element_save] ' . $e->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Salvarea a eșuat (cod duplicat sau date invalide).'], 422);
        }
    }

    private function elementToggle(): void
    {
        $this->requireConfigureJson();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->sendJson(['success' => false, 'message' => 'Element inexistent.'], 400);
        }
        $this->service->model()->toggleElement($id, !empty($_POST['activ']));
        $this->sendJson(['success' => true]);
    }

    private function elementDelete(): void
    {
        $this->requireConfigureJson();
        $id = (int) ($_POST['id'] ?? 0);
        $model = $this->service->model();
        $element = $model->getElementById($id);
        if ($element === null) {
            $this->sendJson(['success' => false, 'message' => 'Element inexistent.'], 404);
        }
        // elementele cu sursă automată se dezactivează, nu se șterg (păstrează maparea)
        if ((string) $element['sursa_referinta'] !== 'manual') {
            $this->sendJson(['success' => false, 'message' => 'Elementele cu sursă automată se pot doar dezactiva, nu șterge.'], 422);
        }
        $model->deleteElement($id);
        $this->sendJson(['success' => true]);
    }

    private function settingsSave(): void
    {
        $this->requireConfigureJson();
        $model = $this->service->model();
        if (!$model->schemaReady()) {
            $this->sendJson(['success' => false, 'message' => 'Rulați migrarea database/update_cost_operational_km.sql mai întâi.'], 409);
        }
        $userId = function_exists('current_user') ? (int) (current_user()['id'] ?? 0) : null;
        $allowed = ['eur_ron_rate', 'salariu_multiplicator', 'tva_carburant_fallback', 'management_alocare', 'diurna_tarif_zi', 'km_source'];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $value = trim((string) $_POST[$key]);
            if (in_array($key, ['eur_ron_rate', 'salariu_multiplicator', 'tva_carburant_fallback'], true) && $value !== '') {
                $value = str_replace(',', '.', $value);
                if (!is_numeric($value) || (float) $value < 0) {
                    $this->sendJson(['success' => false, 'message' => 'Valoare numerică invalidă pentru ' . $key . '.'], 422);
                }
            }
            $model->setSetting($key, $value, $userId ?: null);
        }
        $this->sendJson(['success' => true]);
    }

    private function export(): void
    {
        $payload = $this->service->compute($this->readFilters());
        $filename = 'cost_operational_km_' . $payload['period']['key'] . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM pentru Excel
        fputcsv($out, ['Raport cost operațional / km', $payload['period']['label']], ';');
        fputcsv($out, [], ';');
        fputcsv($out, ['Categorie', 'Vehicule', 'Cost fix (lei)', 'Fix lei/km', 'Cost variabil (lei)', 'Variabil lei/km', 'Cost total (lei)', 'Total lei/km', 'Km', 'Venit (lei)', 'Pondere %'], ';');
        foreach ($payload['categories'] as $cat) {
            fputcsv($out, [
                $cat['label'], $cat['vehicles'],
                number_format($cat['fixed_total'], 2, ',', ''), $cat['fixed_per_km'] !== null ? number_format($cat['fixed_per_km'], 2, ',', '') : 'n/a',
                number_format($cat['variable_total'], 2, ',', ''), $cat['variable_per_km'] !== null ? number_format($cat['variable_per_km'], 2, ',', '') : 'n/a',
                number_format($cat['total'], 2, ',', ''), $cat['total_per_km'] !== null ? number_format($cat['total_per_km'], 2, ',', '') : 'n/a',
                $cat['km'], number_format($cat['revenue'], 2, ',', ''),
                number_format($cat['share_pct'], 1, ',', ''),
            ], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, ['Total flotă', '',
            number_format($payload['summary']['fixed_total'], 2, ',', ''), '',
            number_format($payload['summary']['variable_total'], 2, ',', ''), '',
            number_format($payload['summary']['total'], 2, ',', ''),
            $payload['summary']['cost_per_km'] !== null ? number_format($payload['summary']['cost_per_km'], 2, ',', '') : 'n/a',
            $payload['summary']['km'], number_format($payload['summary']['revenue'], 2, ',', ''), '100'], ';');
        fclose($out);
        exit;
    }

    private function sendJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
