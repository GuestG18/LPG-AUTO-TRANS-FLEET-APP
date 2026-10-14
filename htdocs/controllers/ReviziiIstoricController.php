<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/models/ReviziiIstoricModel.php';
require_once dirname(__DIR__) . '/services/ReviziiIstoricExportService.php';
require_once dirname(__DIR__) . '/services/InvoiceStorageService.php';

/**
 * Revizii → Istoric revizii (?page=revizii&tip=istoric). Apelat din ReviziiController,
 * care a verificat deja accesul la pagina; aici se verifica actiunile (si routerul le
 * verifica o data in plus din registrul permissions/modules/revizii.php).
 *
 *   istoric_data             GET JSON  KPI + pagina din tabel + grafice + rapoarte (filtre server-side)
 *   istoric_detail           GET JSON  o interventie (documente, ansamblul la data interventiei)
 *   istoric_doc              GET       documentul atasat (inline sau &download=1)
 *   istoric_doc_upload       POST JSON documente noi la o interventie       [documents]
 *   istoric_doc_delete       POST JSON eliminare logica a unui document     [documents]
 *   istoric_verify           POST JSON marcheaza / anuleaza verificarea      [edit]
 *   istoric_reference        POST JSON referinta interventiei (ex. factura)  [edit]
 *   istoric_delete           POST JSON sterge interventia (copie in revizii_istoric_sterse), T0 revine [delete_history]
 *   istoric_export           GET       Excel / PDF pe filtrele curente (fisier temporar) [export]
 *   istoric_report           POST JSON genereaza si salveaza un raport       [export]
 *   istoric_reports          GET JSON  toate rapoartele vizibile             [export]
 *   istoric_report_download  GET       descarca un raport salvat             [export]
 */
class ReviziiIstoricController
{
    private const PAGE = 'revizii';
    private const PER_PAGE = 10;

    private PDO $db;
    private ReviziiIstoricModel $model;
    private string $projectRoot;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new ReviziiIstoricModel($db);
        $this->projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    }

    // ---------------------------------------------------------------- pagina

    public function index(): void
    {
        $query = $_GET;
        // Fara filtre in URL: implicit „Anul curent”, ca in macheta.
        if (!isset($query['de_la']) && !isset($query['pana_la'])) {
            $query['de_la'] = date('Y') . '-01-01';
            $query['pana_la'] = date('Y') . '-12-31';
        }
        $filters = ReviziiIstoricModel::normalizeFilters($query);

        render('revizii/istoric.php', [
            'pageTitle' => 'Revizii · Istoric revizii',
            'currentPage' => self::PAGE,
            'filters' => $filters,
            'options' => $this->model->filterOptions(),
            'initial' => $this->payload($filters, (string) ($query['sort'] ?? 'data'), (string) ($query['dir'] ?? 'desc'), (int) ($query['p'] ?? 1), $this->year($query), true),
            'sort' => (string) ($query['sort'] ?? 'data'),
            'dir' => strtolower((string) ($query['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'canEdit' => can(self::PAGE, 'edit'),
            'canExport' => can(self::PAGE, 'export'),
            'canDocuments' => can(self::PAGE, 'documents'),
            'canDelete' => can(self::PAGE, 'delete_history'),
        ]);
    }

    private function year(array $query): int
    {
        $year = (int) ($query['an'] ?? 0);

        return $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
    }

    /** @return array<string, mixed> */
    private function payload(array $filters, string $sort, string $dir, int $page, int $year, bool $full): array
    {
        $data = ['tabel' => $this->model->page($filters, $sort, $dir, $page, self::PER_PAGE)];
        if ($full) {
            $data['kpi'] = $this->model->kpis($filters);
            $data['categorii'] = $this->model->byCategory($filters);
            $data['lunar'] = ['an' => $year, 'serii' => $this->model->monthly($filters, $year)];
            $data['rapoarte'] = can(self::PAGE, 'export') ? $this->model->reports($this->userId(), is_admin(), 3) : [];
        }

        return $data;
    }

    public function dataAction(): void
    {
        $filters = ReviziiIstoricModel::normalizeFilters($_GET);
        try {
            $this->json(['ok' => true] + $this->payload(
                $filters,
                (string) ($_GET['sort'] ?? 'data'),
                (string) ($_GET['dir'] ?? 'desc'),
                (int) ($_GET['p'] ?? 1),
                $this->year($_GET),
                ($_GET['scope'] ?? '') !== 'tabel'
            ));
        } catch (Throwable $exception) {
            error_log('[ReviziiIstoric][data] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Istoricul nu a putut fi încărcat.'], 500);
        }
    }

    public function detailAction(): void
    {
        $item = $this->model->find((int) ($_GET['id'] ?? 0));
        if ($item === null) {
            $this->json(['ok' => false, 'message' => 'Intervenția nu există.'], 404);
        }
        $this->json(['ok' => true, 'item' => $item]);
    }

    // ---------------------------------------------------------------- documente

    private function storage(): InvoiceStorageService
    {
        return new InvoiceStorageService($this->projectRoot, ReviziiIstoricModel::DOCS_DIR);
    }

    /** Singurul drum catre fisierele atasate (in afara web root-ului). */
    public function documentAction(): void
    {
        $document = $this->model->findDocument((int) ($_GET['id'] ?? 0));
        $path = $document !== null ? $this->storage()->resolveReadablePath((string) $document['fisier_path']) : null;
        if ($path === null) {
            http_response_code(404);
            render('errors/404.php', ['pageTitle' => 'Document inexistent', 'currentPage' => self::PAGE]);
            return;
        }
        $this->sendFile($path, (string) $document['mime'], (string) $document['nume_original'], !empty($_GET['download']));
    }

    public function uploadAction(): void
    {
        $this->requireJsonPost();
        $istoricId = (int) ($_POST['istoric_id'] ?? 0);
        if (!$this->model->exists($istoricId)) {
            $this->json(['ok' => false, 'message' => 'Intervenția nu există.'], 404);
        }
        $files = $this->uploadedFiles('documente');
        if ($files === []) {
            $this->json(['ok' => false, 'message' => 'Alege cel puțin un fișier (PDF, JPG sau PNG, max. 10 MB).'], 422);
        }
        if (count($files) > 10) {
            $this->json(['ok' => false, 'message' => 'Poți încărca cel mult 10 fișiere odată.'], 422);
        }

        $storage = $this->storage();
        $stored = [];
        $errors = [];
        foreach ($files as $file) {
            [$saved, $error] = $storage->storeUpload($file);
            if ($error !== null) {
                $errors[] = (string) ($file['name'] ?? 'fișier') . ': ' . $error;
                continue;
            }
            $stored[] = $saved;
        }
        if ($errors !== []) {
            // Nimic nu se ataseaza partial: fie toate fisierele sunt valide, fie niciunul.
            $this->json(['ok' => false, 'message' => implode(' ', $errors)], 422);
        }
        foreach ($stored as $saved) {
            $this->model->addDocument($istoricId, $saved, $this->userId());
        }

        $this->json(['ok' => true, 'message' => count($stored) === 1 ? 'Documentul a fost atașat.' : count($stored) . ' documente au fost atașate.', 'item' => $this->model->find($istoricId)]);
    }

    public function deleteDocumentAction(): void
    {
        $this->requireJsonPost();
        $document = $this->model->findDocument((int) ($_POST['id'] ?? 0));
        if ($document === null) {
            $this->json(['ok' => false, 'message' => 'Documentul nu există sau a fost deja eliminat.'], 404);
        }
        $this->model->removeDocument($document, $this->userId());

        $this->json(['ok' => true, 'message' => 'Documentul a fost eliminat.', 'item' => $this->model->find((int) $document['istoric_id'])]);
    }

    /**
     * $_FILES['x'] (simplu sau multiplu) ca lista de fisiere.
     *
     * @return array<int, array<string, mixed>>
     */
    private function uploadedFiles(string $field): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }
        if (!is_array($raw['name'])) {
            return (int) ($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$raw];
        }
        $files = [];
        foreach (array_keys($raw['name']) as $i) {
            if ((int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $raw['name'][$i], 'type' => $raw['type'][$i] ?? '', 'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => $raw['error'][$i], 'size' => $raw['size'][$i] ?? 0,
            ];
        }

        return $files;
    }

    // ---------------------------------------------------------------- verificare / referinta

    public function verifyAction(): void
    {
        $this->requireJsonPost();
        $id = (int) ($_POST['id'] ?? 0);
        if (!$this->model->exists($id)) {
            $this->json(['ok' => false, 'message' => 'Intervenția nu există.'], 404);
        }
        $verified = (string) ($_POST['verificat'] ?? '1') === '1';
        $this->model->setVerified($id, $verified, $this->userId());

        $this->json(['ok' => true, 'message' => $verified ? 'Intervenția a fost marcată verificată.' : 'Verificarea a fost anulată.', 'item' => $this->model->find($id)]);
    }

    public function referenceAction(): void
    {
        $this->requireJsonPost();
        $id = (int) ($_POST['id'] ?? 0);
        if (!$this->model->exists($id)) {
            $this->json(['ok' => false, 'message' => 'Intervenția nu există.'], 404);
        }
        $reference = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['referinta'] ?? '')) ?? '');
        if (mb_strlen($reference) > 80) {
            $this->json(['ok' => false, 'message' => 'Referința poate avea cel mult 80 de caractere.'], 422);
        }
        $this->model->setReference($id, $reference !== '' ? $reference : null, $this->userId());

        $this->json(['ok' => true, 'message' => 'Referința a fost salvată.', 'item' => $this->model->find($id)]);
    }

    // ---------------------------------------------------------------- stergere

    public function deleteAction(): void
    {
        $this->requireJsonPost();
        if (!can(self::PAGE, 'delete_history')) {
            $this->json(['ok' => false, 'message' => 'Nu ai dreptul să ștergi intervenții din istoric.'], 403);
        }
        $reason = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['motiv'] ?? '')) ?? '');
        if (mb_strlen($reason) > 500) {
            $this->json(['ok' => false, 'message' => 'Motivul poate avea cel mult 500 de caractere.'], 422);
        }
        try {
            $result = $this->model->deleteIntervention((int) ($_POST['id'] ?? 0), $reason !== '' ? $reason : null, $this->userId());
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 404);
        } catch (Throwable $exception) {
            error_log('[ReviziiIstoric][delete] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Intervenția nu a putut fi ștearsă.'], 500);
        }

        $message = sprintf('Intervenția %s · %s a fost ștearsă din istoric.', $result['nr'], $result['componenta']);
        if ($result['t0_restaurat'] !== null) {
            $message .= ' T0 a revenit la ' . date('d.m.Y H:i', (int) strtotime($result['t0_restaurat'])) . ', iar calculul se reface de acolo.';
            $_SESSION['revizii_autosync'] = true; // zilele de la noul T0 se completeaza din SasFleet pe pagina categoriei
        } elseif ($result['plan_sters']) {
            $message .= ' Componenta a revenit la „neconfigurat”.';
        }
        $this->json(['ok' => true, 'message' => $message]);
    }

    // ---------------------------------------------------------------- export / rapoarte

    /** Export direct pe filtrele din pagina; fisierul temporar se sterge imediat dupa trimitere. */
    public function exportAction(): void
    {
        $format = ($_GET['format'] ?? '') === 'pdf' ? 'pdf' : 'xlsx';
        $filters = ReviziiIstoricModel::normalizeFilters($_GET);
        $service = new ReviziiIstoricExportService($this->model);
        $title = 'Istoric revizii';
        $tmp = tempnam(sys_get_temp_dir(), 'rvx');
        if ($tmp === false) {
            $this->exportError('Nu s-a putut crea fișierul temporar.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(180);
        try {
            $format === 'pdf' ? $service->writePdf($filters, $tmp, $title) : $service->writeXlsx($filters, $tmp, $title);
        } catch (InvalidArgumentException $exception) {
            @unlink($tmp);
            $this->exportError($exception->getMessage());
        } catch (Throwable $exception) {
            @unlink($tmp);
            error_log('[ReviziiIstoric][export] ' . $exception->getMessage());
            $this->exportError('Exportul nu a putut fi generat.');
        }

        $name = 'Istoric_revizii_' . date('Ymd_Hi') . '.' . $format;
        $this->sendFile($tmp, $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name, true, true);
    }

    private function exportError(string $message): never
    {
        http_response_code(422);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Export</title><p style="font-family:sans-serif;padding:2rem">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        exit;
    }

    public function reportAction(): void
    {
        $this->requireJsonPost();
        $input = $_POST;
        if (!empty($input['flota'])) {
            $input['vehicule'] = []; // „Întreaga flotă”: selectia de vehicule e ignorata
        }
        $filters = ReviziiIstoricModel::normalizeFilters([
            'de_la' => $input['de_la'] ?? '',
            'pana_la' => $input['pana_la'] ?? '',
            'categorii' => $input['categorii'] ?? [],
            'vehicule' => $input['vehicule'] ?? [],
        ]);
        if ($filters['de_la'] === null || $filters['pana_la'] === null) {
            $this->json(['ok' => false, 'message' => 'Alege perioada raportului (de la / până la).'], 422);
        }
        if (empty($input['flota']) && $filters['vehicule'] === []) {
            $this->json(['ok' => false, 'message' => 'Alege cel puțin un vehicul sau „Întreaga flotă”.'], 422);
        }
        if ($filters['categorii'] === []) {
            $this->json(['ok' => false, 'message' => 'Alege cel puțin o categorie.'], 422);
        }
        $format = ($input['format'] ?? '') === 'pdf' ? 'pdf' : 'xlsx';
        $options = ['observatii' => !empty($input['observatii']), 'documente' => !empty($input['documente'])];

        $service = new ReviziiIstoricExportService($this->model);
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($input['titlu'] ?? '')) ?? '');
        if ($title === '') {
            $title = 'Raport revizii' . (count($filters['categorii']) === 1 ? ' – ' . ReviziiModel::TABS[$filters['categorii'][0]]['label'] : '') . ' · ' . $service->periodLabel($filters);
        }
        $title = mb_substr($title, 0, 190);

        $relativeDir = ReviziiIstoricModel::REPORTS_DIR . '/' . date('Y') . '/' . date('m');
        $absoluteDir = $this->projectRoot . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
            $this->json(['ok' => false, 'message' => 'Folderul rapoartelor nu a putut fi creat.'], 500);
        }
        $denyFile = $this->projectRoot . '/storage/revizii/.htaccess';
        if (!is_file($denyFile)) {
            @file_put_contents($denyFile, "Require all denied\n");
        }
        $relativePath = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $format;
        $absolutePath = $this->projectRoot . '/' . $relativePath;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(180);
        try {
            $count = $format === 'pdf'
                ? $service->writePdf($filters, $absolutePath, $title, $options)
                : $service->writeXlsx($filters, $absolutePath, $title, $options);
        } catch (InvalidArgumentException $exception) {
            @unlink($absolutePath);
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            @unlink($absolutePath);
            error_log('[ReviziiIstoric][report] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Raportul nu a putut fi generat.'], 500);
        }

        $id = $this->model->createReport([
            'titlu' => $title, 'format' => $format, 'de_la' => $filters['de_la'], 'pana_la' => $filters['pana_la'],
            'filtre' => $filters + ['optiuni' => $options], 'nr' => $count, 'path' => $relativePath,
            'marime' => (int) @filesize($absolutePath), 'created_by' => $this->userId(),
        ]);

        $this->json([
            'ok' => true,
            'message' => sprintf('Raportul a fost generat (%d intervenții).', $count),
            'id' => $id,
            'download' => build_query_url(['page' => self::PAGE, 'action' => 'istoric_report_download', 'id' => $id]),
            'rapoarte' => $this->model->reports($this->userId(), is_admin(), 3),
        ]);
    }

    public function reportsAction(): void
    {
        $this->json(['ok' => true, 'rapoarte' => $this->model->reports($this->userId(), is_admin(), 100)]);
    }

    public function reportDownloadAction(): void
    {
        $report = $this->model->findReport((int) ($_GET['id'] ?? 0));
        // Un raport se descarca doar de cine l-a generat (sau de administrator).
        $visible = $report !== null && (is_admin() || (int) ($report['created_by'] ?? 0) === (int) $this->userId());
        $path = $visible ? $this->resolveReportPath((string) $report['fisier_path']) : null;
        if ($path === null) {
            http_response_code(404);
            render('errors/404.php', ['pageTitle' => 'Raport inexistent', 'currentPage' => self::PAGE]);
            return;
        }
        $format = (string) $report['format'];
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $report['titlu']) ?: 'Raport_revizii')) ?? '', '_');
        $this->sendFile(
            $path,
            $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ($slug !== '' ? mb_substr($slug, 0, 120) : 'Raport_revizii') . '.' . $format,
            true
        );
    }

    /** Calea absoluta a unui raport, doar daca e in folderul rapoartelor (fara „../”). */
    private function resolveReportPath(string $relativePath): ?string
    {
        $real = realpath($this->projectRoot . '/' . ltrim(str_replace('\\', '/', $relativePath), '/'));
        $root = realpath($this->projectRoot . '/' . ReviziiIstoricModel::REPORTS_DIR);
        if ($real === false || $root === false || !is_file($real)) {
            return null;
        }
        $real = str_replace('\\', '/', $real);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with(strtolower($real), strtolower($root)) ? $real : null;
    }

    // ---------------------------------------------------------------- utilitare

    private function sendFile(string $path, string $mime, string $name, bool $download, bool $deleteAfter = false): never
    {
        $name = str_replace(['"', "\r", "\n", '/', '\\'], '', $name);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        if ($deleteAfter) {
            @unlink($path);
        }
        exit;
    }

    private function requireJsonPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Cerere invalidă (sesiunea a expirat? reîncarcă pagina).'], 400);
        }
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function userId(): ?int
    {
        $userId = (int) (current_user()['id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }
}
