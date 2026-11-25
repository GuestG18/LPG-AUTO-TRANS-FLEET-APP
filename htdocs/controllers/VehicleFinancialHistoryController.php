<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/VehicleFinancialRegisterService.php';
require_once dirname(__DIR__) . '/services/FinancialClassificationService.php';

/**
 * Pagina „Istoric Financiar Vehicul” (?page=istoric_financiar).
 *
 *   action=index   — pagina
 *   action=list    — JSON: carduri, contoare, randuri (filtre q / tab / status / tara / perioada / page)
 *   action=detail  — JSON: dosarul (acquisition_id) sau vehiculul fara dosar (vehicle_id)
 *   action=file    — documentul unui dosar / al unei linii (doar prin controller)
 *   action=op      — POST JSON: operatiile dosarului si ale proiectelor (op=...)
 *
 * Toate sumele vin din serviciile fundatiei financiare; controllerul nu calculeaza nimic.
 */
class VehicleFinancialHistoryController
{
    private const UPLOAD_DIR = 'achizitii_vehicule';
    private const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];

    public function __construct(private PDO $db)
    {
    }

    public function handle(string $action): void
    {
        if (function_exists('can') && !can('istoric_financiar')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis', 'currentPage' => '']);
            return;
        }
        if (!FinancialFoundationSchema::isReady($this->db)) {
            // Schema se aplica prin MaintenanceModel (DDL aditiv, in afara tranzactiilor).
            new MaintenanceModel($this->db);
            FinancialFoundationSchema::resetCache();
        }
        match ($action) {
            'list' => $this->list(),
            'kpi_breakdown' => $this->kpiBreakdown(),
            'detail' => $this->detail(),
            'file' => $this->file(),
            'line_view' => $this->lineView(),
            'op' => $this->op(),
            default => $this->index(),
        };
    }

    private function index(): void
    {
        $categories = $this->db->query('SELECT cod, denumire, grup FROM achizitii_categorii_cheltuieli WHERE activ = 1 ORDER BY grup_ordine, ordine')->fetchAll(PDO::FETCH_ASSOC);
        render('istoric_financiar/index.php', [
            'pageTitle' => 'Istoric Vehicul',
            'currentPage' => 'istoric_financiar',
            'permissions' => $this->permissions(),
            'categories' => $categories,
            'drivers' => $this->db->query("SELECT id, nume FROM soferi WHERE status = 'activ' ORDER BY nume")->fetchAll(PDO::FETCH_ASSOC),
            'vehicles' => $this->db->query("SELECT id, nr_inmatriculare, tip_vehicul, serie_sasiu FROM vehicule WHERE status = 'activ' AND nr_inmatriculare <> 'STOC-ANVELOPE' ORDER BY nr_inmatriculare")->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    /** Filtrele paginii (lista, cardurile KPI si defalcarea cardului „Valoare initiala” folosesc aceleasi). */
    private function pageFilters(): array
    {
        return [
            'q' => (string) ($_GET['q'] ?? ''),
            'tab' => (string) ($_GET['tab'] ?? 'toate'),
            'status' => (string) ($_GET['status'] ?? ''),
            'tara' => (string) ($_GET['tara'] ?? ''),
            'perioada' => (string) ($_GET['perioada'] ?? ''),
        ];
    }

    private function list(): void
    {
        try {
            $data = (new VehicleFinancialRegisterService($this->db))->list($this->pageFilters() + [
                'page' => (int) ($_GET['p'] ?? 1),
                'per_page' => (int) ($_GET['per_page'] ?? 10),
                // categoria aleasa in cardul „Valoare initiala” (restrange doar tabelul)
                'fin_node' => (int) ($_GET['fin_node'] ?? 0),
                'fin_tip' => (string) ($_GET['fin_tip'] ?? ''),
                'fin_masura' => (string) ($_GET['fin_masura'] ?? 'total'),
                // tipul de vehicul ales in cardul „Total vehicule” (restrange doar tabelul)
                'tip_vehicul' => (string) ($_GET['tip_vehicul'] ?? ''),
            ]);
            unset($data['_visible'], $data['_filtered']);
            $this->json(['ok' => true] + $data);
        } catch (Throwable $e) {
            error_log('[VehicleFinancialHistoryController][list] ' . $e->getMessage());
            $this->json(['ok' => false, 'message' => 'Lista nu a putut fi încărcată.'], 500);
        }
    }

    /** Navigarea din cardul „Valoare initiala (total)”: categorii → subcategorii → tipuri → tranzactii (doar citire). */
    private function kpiBreakdown(): void
    {
        try {
            $node = (int) ($_GET['node'] ?? 0);
            $type = (string) ($_GET['tip'] ?? '');
            $group = (string) ($_GET['grup'] ?? '');
            $service = new VehicleFinancialRegisterService($this->db);
            // cardul KPI: valoare_initiala | capitalizat | necapitalizabile (categorii) · vehicule (tipuri) · derulare (etape)
            $data = match ((string) ($_GET['card'] ?? 'valoare_initiala')) {
                'valoare_initiala' => $service->initialValueBreakdown($this->pageFilters(), $node > 0 ? $node : null, $type !== '' ? $type : null, 'total'),
                'capitalizat' => $service->initialValueBreakdown($this->pageFilters(), $node > 0 ? $node : null, $type !== '' ? $type : null, 'capitalizabil'),
                'necapitalizabile' => $service->initialValueBreakdown($this->pageFilters(), $node > 0 ? $node : null, $type !== '' ? $type : null, 'necapitalizabil'),
                'vehicule' => $service->vehicleBreakdown($this->pageFilters(), $group !== '' ? $group : null),
                'derulare' => $service->dossierBreakdown($this->pageFilters(), $group !== '' ? $group : null),
                default => throw new InvalidArgumentException('Card necunoscut.'),
            };
            $this->json(['ok' => true] + $data);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[VehicleFinancialHistoryController][kpi_breakdown] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['ok' => false, 'message' => 'Defalcarea nu a putut fi calculată.'], 500);
        }
    }

    private function detail(): void
    {
        $acquisitionId = (int) ($_GET['acquisition_id'] ?? 0);
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        try {
            $data = (new VehicleFinancialRegisterService($this->db))->detail($acquisitionId > 0 ? $acquisitionId : null, $vehicleId > 0 ? $vehicleId : null);
            $this->json(['ok' => true, 'detail' => $data, 'can' => $this->permissions()]);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            error_log('[VehicleFinancialHistoryController][detail] ' . $e->getMessage());
            $this->json(['ok' => false, 'message' => 'Dosarul nu a putut fi încărcat.'], 500);
        }
    }

    /**
     * Fisa unei inregistrari de cost: in panoul peste pagina (partial=1, race-view-overlay.js — ca fisa
     * cursei din Dispecer curse), altfel ca pagina completa (Ctrl + clic pe rand).
     */
    private function lineView(): void
    {
        $partial = (string) ($_GET['partial'] ?? '') === '1';
        $data = null;
        try {
            $data = (new VehicleFinancialRegisterService($this->db))->lineDetail((int) ($_GET['id'] ?? 0));
        } catch (Throwable $e) {
            error_log('[VehicleFinancialHistoryController][line_view] ' . $e->getMessage());
        }
        if ($data === null) {
            http_response_code(404);
            if ($partial) {
                exit;
            }
            render('errors/404.php', ['pageTitle' => 'Înregistrare inexistentă', 'currentPage' => 'istoric_financiar']);
            return;
        }
        $viewData = $data + [
            'pageTitle' => 'Înregistrare de cost #' . $data['linie']['id'],
            'currentPage' => 'istoric_financiar',
            'permissions' => $this->permissions(),
            'viewPartial' => $partial,
        ];
        if ($partial) {
            header('Content-Type: text/html; charset=UTF-8');
            (static function (array $__data): void {
                extract($__data, EXTR_SKIP);
                require BASE_PATH . '/views/istoric_financiar/line_view.php';
            })($viewData);
            exit;
        }
        render('istoric_financiar/line_view.php', $viewData);
    }

    private function file(): void
    {
        $source = (string) ($_GET['sursa'] ?? '');
        $id = (int) ($_GET['id'] ?? 0);
        $row = null;
        if ($source === 'dosar') {
            $stmt = $this->db->prepare('SELECT fisier_original AS original, fisier_stocat AS fisier FROM achizitii_vehicule_documente WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($source === 'linie') {
            $stmt = $this->db->prepare('SELECT document_numar AS original, document_fisier AS fisier FROM achizitii_vehicule_cheltuieli WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $stored = $row !== null ? basename((string) $row['fisier']) : '';
        $path = $stored !== '' ? BASE_PATH . '/uploads/' . self::UPLOAD_DIR . '/' . $stored : '';
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            exit('Documentul nu mai există.');
        }
        $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $inline = isset($types[$extension]) && empty($_GET['download']);
        $name = (string) ($row['original'] ?: $stored);
        if (!str_contains($name, '.')) {
            $name .= '.' . $extension;
        }
        header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ operatii

    private function op(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina.'], 419);
        }
        $op = (string) ($_POST['op'] ?? '');
        $can = $this->permissions();
        $needs = match ($op) {
            'classify' => 'clasificare',
            'create_project', 'update_project', 'project_stage', 'project_link', 'project_life' => 'proiecte',
            default => 'manage',
        };
        if (!$can[$needs]) {
            $this->json(['ok' => false, 'message' => 'Nu aveți dreptul pentru această operație.'], 403);
        }

        $acq = new VehicleAcquisitionService($this->db);
        $projects = new InvestmentProjectService($this->db);
        $user = $this->userId();
        $id = (int) ($_POST['acquisition_id'] ?? 0);
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $post = $_POST;
        unset($post['_token'], $post['op']);

        try {
            $result = [];
            switch ($op) {
                case 'create_dossier':
                    $id = $acq->createDossier($post, $user);
                    $result = ['acquisition_id' => $id];
                    break;
                case 'update_dossier':
                    unset($post['acquisition_id']);
                    $acq->updateDossier($id, $post, $user);
                    break;
                case 'add_cost':
                    $lineId = $acq->addCost($id, $post, $user);
                    $this->attachLineFile($acq, $lineId, $user);
                    $result = ['line_id' => $lineId];
                    break;
                case 'update_cost':
                    $lineId = (int) ($post['line_id'] ?? 0);
                    unset($post['line_id'], $post['acquisition_id']);
                    $acq->updateCost($lineId, $post, $user);
                    $this->attachLineFile($acq, $lineId, $user);
                    break;
                case 'cancel_cost':
                    $acq->cancelCost((int) ($post['line_id'] ?? 0), (string) ($post['motiv'] ?? ''), $user);
                    break;
                case 'verify_cost':
                    $acq->setCostVerification((int) ($post['line_id'] ?? 0), (string) ($post['status_verificare'] ?? ''), $user, (string) ($post['motiv'] ?? ''));
                    break;
                case 'cost_vat':
                    $acq->setCostVat((int) ($post['line_id'] ?? 0), (string) ($post['tva_deductibil'] ?? ''),
                        ($post['tva_recuperabil'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $post['tva_recuperabil']) : null, $user, (string) ($post['motiv'] ?? ''));
                    break;
                case 'classify':
                    $entity = (string) ($post['entity'] ?? 'achizitie_cheltuiala');
                    if (!in_array($entity, ['achizitie_cheltuiala', 'proiect_investitie', 'mentenanta'], true)) {
                        throw new InvalidArgumentException('Entitate invalidă.');
                    }
                    (new FinancialClassificationService($this->db))->setTreatment($entity, (int) ($post['entity_id'] ?? 0), (string) ($post['tratament'] ?? ''), $user, (string) ($post['motiv'] ?? ''));
                    break;
                case 'add_payment':
                    $result = ['payment_id' => $acq->addPayment($id, $post, $user)];
                    break;
                case 'record_event':
                    $acq->recordEvent($id, (string) ($post['tip'] ?? ''), (string) ($post['data'] ?? ''), $user, $post['document_ref'] ?? null, $post['observatii'] ?? null);
                    break;
                case 'save_transport':
                    $acq->saveTransport($id, $post, $user);
                    break;
                case 'set_status':
                    $acq->setStatus($id, (string) ($post['status'] ?? ''), $user, (string) ($post['motiv'] ?? ''));
                    break;
                case 'link_vehicle':
                    $acq->linkVehicle($id, $vehicleId, $user);
                    break;
                case 'upload_document':
                    $stored = $this->storeUpload($_FILES['document'] ?? null, 'dosar_' . $id);
                    if ($stored === []) {
                        throw new InvalidArgumentException('Alege fișierul de încărcat.');
                    }
                    $result = ['document_id' => $acq->addDocument($id, (string) ($post['tip'] ?? 'Document'), $post['numar'] ?? null, $stored['original'], $stored['stored'], $user)];
                    break;
                case 'create_project':
                    $result = ['project_id' => $projects->create($post + ['achizitie_id' => $id ?: null], $user)];
                    break;
                case 'update_project':
                    $projects->update((int) ($post['project_id'] ?? 0), array_intersect_key($post, array_flip(['buget_planificat', 'status', 'data_start', 'data_punere_in_functiune', 'observatii', 'denumire'])), $user);
                    break;
                case 'project_stage':
                    $projects->confirmStage((int) ($post['project_id'] ?? 0), (string) ($post['stadiu'] ?? ''), (string) ($post['data'] ?? ''), $user,
                        !empty($post['document_id']) ? (int) $post['document_id'] : null, $post['observatii'] ?? null);
                    break;
                case 'project_link':
                    $projects->link((int) ($post['project_id'] ?? 0), (string) ($post['sursa'] ?? ''), (int) ($post['sursa_id'] ?? 0), $user);
                    break;
                case 'project_life':
                    $projects->approveUsefulLife((int) ($post['project_id'] ?? 0), (int) ($post['luni'] ?? 0), $user, (string) ($post['motiv'] ?? ''));
                    break;
                default:
                    throw new InvalidArgumentException('Operație necunoscută.');
            }
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[VehicleFinancialHistoryController][' . $op . '] ' . $e->getMessage());
            $this->json(['ok' => false, 'message' => 'Operația nu a putut fi salvată. Reîncearcă.'], 500);
        }

        $detail = null;
        try {
            if ($id > 0 || $vehicleId > 0) {
                $detail = (new VehicleFinancialRegisterService($this->db))->detail($id > 0 ? $id : null, $vehicleId > 0 ? $vehicleId : null);
            }
        } catch (Throwable) {
            $detail = null;
        }
        $this->json(['ok' => true, 'result' => $result, 'detail' => $detail]);
    }

    private function attachLineFile(VehicleAcquisitionService $acq, int $lineId, ?int $user): void
    {
        $stored = $this->storeUpload($_FILES['document'] ?? null, 'linie_' . $lineId);
        if ($stored !== []) {
            $acq->updateCost($lineId, ['document_fisier' => $stored['stored']], $user);
        }
    }

    /** @return array{original?:string,stored?:string} */
    private function storeUpload(mixed $file, string $prefix): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new InvalidArgumentException('Fișierul nu a putut fi încărcat.');
        }
        if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Fișierul trebuie să fie mai mic de 10 MB.');
        }
        $original = basename((string) ($file['name'] ?? 'document'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Tipul fișierului nu este permis.');
        }
        $directory = BASE_PATH . '/uploads/' . self::UPLOAD_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Directorul pentru documente nu poate fi creat.');
        }
        if (!is_file($directory . '/.htaccess')) {
            file_put_contents($directory . '/.htaccess', "Require all denied\n"); // se servesc doar prin controller
        }
        $stored = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
        if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $stored)) {
            throw new RuntimeException('Fișierul nu a putut fi salvat.');
        }
        return ['original' => $original, 'stored' => $stored];
    }

    /** @return array{manage:bool,clasificare:bool,proiecte:bool} */
    private function permissions(): array
    {
        $has = static fn (string $action): bool => !function_exists('can') || can('istoric_financiar', $action);
        return ['manage' => $has('manage'), 'clasificare' => $has('clasificare'), 'proiecte' => $has('proiecte')];
    }

    private function userId(): ?int
    {
        $user = function_exists('current_user') ? current_user() : ($_SESSION['auth_user'] ?? null);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
