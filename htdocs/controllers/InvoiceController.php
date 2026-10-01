<?php
declare(strict_types=1);

/**
 * Pagina "Facturi": registrul global al facturilor de cheltuieli de cursa.
 *
 * Lista (filtre tip / status / perioada / vehicul / sofer), detaliul cu preview si
 * asocierea la cursa, plus incarcarea manuala (sursa = manual). Asocierea propriu-zisa
 * e in InvoiceModel / InvoiceTripMatcher, fisierele in InvoiceStorageService.
 */
class InvoiceController
{
    private const PER_PAGE_OPTIONS = [20, 50, 100];

    /** Cate facturi in asteptare se reverifica la fiecare deschidere a listei. */
    private const REMATCH_ON_LOAD_LIMIT = 50;

    private PDO $db;
    private InvoiceModel $model;
    private InvoiceStorageService $storage;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new InvoiceModel($db);
        $this->storage = new InvoiceStorageService();
    }

    public function handle(string $action): void
    {
        require_page_or_403('facturi');

        try {
            $this->model->ensureFacturiSchema();
        } catch (Throwable $exception) {
            error_log('[InvoiceController][schema] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Facturi necesita actualizarea bazei de date. Ruleaza database/migrations/2026_10_01_000001_facturi.sql.');
        }

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'view':
                $this->viewAction();
                return;
            case 'document':
                $this->documentAction();
                return;
            case 'store':
                $this->requireAction('create');
                $this->storeAction();
                return;
            case 'update':
                $this->requireAction('edit');
                $this->updateAction();
                return;
            case 'confirm':
            case 'choose':
                $this->requireAction('link');
                $this->linkAction();
                return;
            case 'unlink':
                $this->requireAction('link');
                $this->unlinkAction();
                return;
            case 'rematch':
                $this->requireAction('link');
                $this->rematchAction();
                return;
            case 'reject':
                $this->requireAction('link');
                $this->rejectAction();
                return;
            case 'delete':
                $this->requireAction('delete');
                $this->deleteAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'facturi',
                ]);
                return;
        }
    }

    // -------------------------------------------------------------------------
    // Lista si detaliu
    // -------------------------------------------------------------------------

    private function indexAction(): void
    {
        $filters = $this->collectFilters();
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = (int) ($_GET['pp'] ?? 20);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        // O cursa introdusa intre timp poate prelua facturile in asteptare. Cazarile se
        // reasociaza de modulul Cazare (cum facea pagina Cazare la deschidere), apoi
        // cele noi / modificate (ex. din importul orar din Sheet) se aduc in Facturi.
        try {
            (new AccommodationExpenseModel($this->db))->rematchPending();
            $this->model->syncLegacyCazare(true, true);
        } catch (Throwable $exception) {
            error_log('[InvoiceController][legacy_cazare_sync] ' . $exception->getMessage());
        }
        try {
            $this->model->rematchPending(self::REMATCH_ON_LOAD_LIMIT);
        } catch (Throwable $exception) {
            error_log('[InvoiceController][rematch_on_load] ' . $exception->getMessage());
        }

        try {
            $result = $this->model->getPaginated($filters, $page, $perPage);
            $summary = $this->model->getSummary($filters);
            $vehicles = $this->model->getVehicles();
            $drivers = $this->model->getDrivers();
        } catch (Throwable $exception) {
            error_log('[InvoiceController][index] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-au putut incarca facturile.');
            $result = ['rows' => [], 'page' => 1, 'per_page' => $perPage, 'total_rows' => 0, 'total_pages' => 1];
            $summary = ['count' => 0, 'total_cu_tva' => 0.0, 'asociate' => 0, 'de_verificat' => 0, 'neasociate' => 0, 'in_procesare' => 0, 'respinse' => 0];
            $vehicles = [];
            $drivers = [];
        }

        render('facturi/index.php', [
            'pageTitle' => 'Facturi',
            'currentPage' => 'facturi',
            'filters' => $filters,
            'rows' => $result['rows'],
            'summary' => $summary,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total_rows' => $result['total_rows'],
                'total_pages' => $result['total_pages'],
            ],
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'canCreate' => can('facturi', 'create'),
            'canImportSheet' => can('cazare', 'create'),
            'scanStatus' => $this->scanStatus(),
        ]);
    }

    private function viewAction(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $row = $id > 0 ? $this->model->getById($id) : null;
        if ($row === null || $row['deleted_at'] !== null) {
            flash_set('warning', 'Factura nu exista.');
            redirect(build_query_url(['page' => 'facturi']));
        }

        $tripOptions = [];
        if ($row['data_document'] !== null && can('facturi', 'link')) {
            try {
                $tripOptions = (new InvoiceTripMatcher($this->db))->loadTripsAround((string) $row['data_document']);
            } catch (Throwable $exception) {
                error_log('[InvoiceController][trip_options] ' . $exception->getMessage());
            }
        }

        $hasDocument = $this->storage->resolveReadablePath($row['document_path'] ?? null) !== null;

        // Cazarile importate: datele se editeaza in Cazare; asocierea se poate face si
        // de aici (o executa modulul Cazare), daca utilizatorul are dreptul in ambele.
        $isLegacy = (string) $row['sursa'] === 'legacy_cazare';
        $legacyDocuments = $isLegacy && $row['legacy_cazare_id'] !== null
            ? $this->model->getLegacyCazareDocuments((int) $row['legacy_cazare_id'])
            : [];

        render('facturi/view.php', [
            'pageTitle' => 'Factura #' . $id,
            'currentPage' => 'facturi',
            'invoice' => $row,
            'candidates' => $this->model->decodeCandidates($row['match_candidates'] ?? null),
            'tripOptions' => $tripOptions,
            'vehicles' => $this->model->getVehicles(),
            'drivers' => $this->model->getDrivers(),
            'hasDocument' => $hasDocument,
            'documentUrl' => build_query_url(['page' => 'facturi', 'action' => 'document', 'id' => $id]),
            'legacyDocuments' => $legacyDocuments,
            'ocrDetails' => $this->model->ocrDetails($row),
            'scanSiblings' => $this->model->getScanSiblings($row),
            'canEdit' => can('facturi', 'edit') && !$isLegacy,
            'canLink' => can('facturi', 'link') && (!$isLegacy || can('cazare', 'link')),
            'canReject' => can('facturi', 'link') && !$isLegacy,
            'canDelete' => can('facturi', 'delete') && !$isLegacy,
        ]);
    }

    /**
     * Deschide documentul facturii inline (preview in iframe / tab nou).
     * Singurul drum catre fisierele din storage/invoices.
     */
    private function documentAction(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $row = $id > 0 ? $this->model->getById($id) : null;
        $path = $row !== null ? $this->storage->resolveReadablePath($row['document_path'] ?? null) : null;

        if ($path === null) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Document inexistent',
                'currentPage' => 'facturi',
            ]);
            return;
        }

        $mime = trim((string) ($row['document_mime'] ?? ''));
        $name = basename((string) ($row['document_original_name'] ?: basename($path)));
        $name = str_replace(['"', "\r", "\n"], '', $name);

        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    // -------------------------------------------------------------------------
    // Scriere
    // -------------------------------------------------------------------------

    /** Incarcare manuala: fisier + campuri, apoi asociere automata. */
    private function storeAction(): void
    {
        $listUrl = $this->listUrl();
        $this->requirePost($listUrl);
        ensure_csrf_or_redirect($listUrl);

        [$data, $errors] = $this->collectFormData(true);
        [$document, $uploadError] = $this->storage->storeUpload($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($listUrl);
        }

        $sourceKey = 'manual:' . $document['document_sha256'];
        $existing = $this->model->findBySourceKey($sourceKey);
        if ($existing !== null && $existing['deleted_at'] === null) {
            flash_set('warning', 'Aceasta factura (acelasi fisier) exista deja.', [
                'url' => build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => (int) $existing['id']]),
                'label' => 'Deschide factura #' . (int) $existing['id'],
            ]);
            redirect($listUrl);
        }
        if ($existing !== null) {
            // A fost stearsa: o reincarcare intentionata primeste o cheie noua.
            $sourceKey .= ':' . date('YmdHis');
        }

        try {
            $id = $this->model->create($data + $document + [
                'sursa' => 'manual',
                'sursa_key' => $sourceKey,
                'created_by' => $this->currentUserId(),
            ]);
            flash_set('success', 'Factura a fost salvata. ' . $this->associationMessage($this->model->getById($id)));
            redirect(build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => $id]));
        } catch (Throwable $exception) {
            error_log('[InvoiceController][store] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva factura.');
        }

        redirect($listUrl);
    }

    private function updateAction(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $viewUrl = $this->viewUrl($id);
        $this->requirePost($viewUrl);
        ensure_csrf_or_redirect($viewUrl);

        [$data, $errors] = $this->collectFormData(false);
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($viewUrl);
        }

        try {
            if ($this->model->update($id, $data)) {
                flash_set('success', 'Factura a fost actualizata. ' . $this->associationMessage($this->model->getById($id)));
            } else {
                flash_set('warning', 'Factura nu poate fi modificata aici.');
            }
        } catch (Throwable $exception) {
            error_log('[InvoiceController][update] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut actualiza factura.');
        }

        redirect($viewUrl);
    }

    /** Confirmarea unui candidat sau alegerea altei curse. */
    private function linkAction(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $viewUrl = $this->viewUrl($id);
        $this->requirePost($viewUrl);
        ensure_csrf_or_redirect($viewUrl);
        $this->requireLegacyLinkRight($id);

        $raceId = (int) ($_POST['cursa_id'] ?? 0);
        if ($raceId <= 0) {
            flash_set('warning', 'Alege o cursa.');
            redirect($viewUrl);
        }

        try {
            $error = $this->model->linkManually($id, $raceId);
            flash_set($error === null ? 'success' : 'warning', $error ?? ('Factura a fost asociata cursei #' . $raceId . '. Cheltuiala a fost adaugata pe cursa.'));
        } catch (Throwable $exception) {
            error_log('[InvoiceController][link] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut face asocierea.');
        }

        redirect($viewUrl);
    }

    private function unlinkAction(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $viewUrl = $this->viewUrl($id);
        $this->requirePost($viewUrl);
        ensure_csrf_or_redirect($viewUrl);
        $this->requireLegacyLinkRight($id);

        try {
            $ok = $this->model->unlink($id);
            flash_set($ok ? 'success' : 'warning', $ok
                ? 'Factura a fost dezasociata, iar cheltuiala a fost scoasa de pe cursa. Nu se mai asociaza automat pana la "Reruleaza asocierea".'
                : 'Factura nu poate fi dezasociata aici.');
        } catch (Throwable $exception) {
            error_log('[InvoiceController][unlink] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut dezasocia factura.');
        }

        redirect($viewUrl);
    }

    /** Cu id: reruleaza (fortat) o factura. Fara id: reverifica toate cele in asteptare. */
    private function rematchAction(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $returnUrl = $id > 0 ? $this->viewUrl($id) : $this->listUrl();
        $this->requirePost($returnUrl);
        ensure_csrf_or_redirect($returnUrl);
        $this->requireLegacyLinkRight($id);

        try {
            if ($id > 0) {
                $current = $this->model->getById($id);
                if ($current !== null && (string) $current['status'] === 'respinsa') {
                    $this->model->reopen($id);
                } else {
                    $this->model->runMatching($id, true);
                }
                flash_set('success', 'Asocierea a fost rerulata. ' . $this->associationMessage($this->model->getById($id)));
            } else {
                $result = $this->model->rematchPending(500);
                flash_set('success', sprintf('Reverificare finalizata: %d facturi analizate, %d asociate la cursa.', $result['procesate'], $result['asociate']));
            }
        } catch (Throwable $exception) {
            error_log('[InvoiceController][rematch] ' . $exception->getMessage());
            flash_set('danger', 'Reverificarea a esuat.');
        }

        redirect($returnUrl);
    }

    private function rejectAction(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $viewUrl = $this->viewUrl($id);
        $this->requirePost($viewUrl);
        ensure_csrf_or_redirect($viewUrl);

        try {
            $ok = $this->model->reject($id, (string) ($_POST['motiv'] ?? ''));
            flash_set($ok ? 'success' : 'warning', $ok ? 'Factura a fost respinsa; nu mai apare pe nicio cursa.' : 'Factura nu poate fi respinsa aici.');
        } catch (Throwable $exception) {
            error_log('[InvoiceController][reject] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut respinge factura.');
        }

        redirect($viewUrl);
    }

    private function deleteAction(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $this->requirePost($this->viewUrl($id));
        ensure_csrf_or_redirect($this->viewUrl($id));

        try {
            $ok = $this->model->softDelete($id);
            flash_set($ok ? 'success' : 'warning', $ok ? 'Factura #' . $id . ' a fost stearsa (si cheltuiala de pe cursa).' : 'Factura nu poate fi stearsa aici.');
        } catch (Throwable $exception) {
            error_log('[InvoiceController][delete] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge factura.');
            redirect($this->viewUrl($id));
        }

        redirect($this->listUrl());
    }

    // -------------------------------------------------------------------------
    // Utilitare
    // -------------------------------------------------------------------------

    /**
     * Starea fluxului de scanari, din fisierele scrise de cele doua scripturi
     * (fetch_invoice_emails.py si process_invoice_inbox.php) dupa fiecare rulare.
     *
     * @return array{fetch: ?array<string, mixed>, ocr: ?array<string, mixed>, in_procesare: int, configurat: bool}
     */
    private function scanStatus(): array
    {
        $dir = dirname(BASE_PATH) . '/storage/invoices/inbox';
        $read = static function (string $file): ?array {
            if (!is_file($file)) {
                return null;
            }
            $data = json_decode((string) @file_get_contents($file), true);

            return is_array($data) ? $data : null;
        };

        $pending = 0;
        try {
            $pending = (int) $this->db->query("SELECT COUNT(*) FROM facturi WHERE sursa = 'scan' AND status = 'in_procesare' AND deleted_at IS NULL")->fetchColumn();
        } catch (Throwable) {
        }

        $fetch = $read($dir . '/.last_fetch.json');
        $ocr = $read($dir . '/.last_ocr.json');

        return [
            'fetch' => $fetch,
            'ocr' => $ocr,
            'in_procesare' => $pending,
            'configurat' => $fetch !== null || $ocr !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function collectFilters(): array
    {
        return [
            'tip' => InvoiceModel::isValidType((string) ($_GET['tip'] ?? '')) ? (string) $_GET['tip'] : '',
            'status' => trim((string) ($_GET['status'] ?? '')),
            'data_start' => $this->normalizeDate((string) ($_GET['data_start'] ?? '')),
            'data_end' => $this->normalizeDate((string) ($_GET['data_end'] ?? '')),
            'vehicle_id' => (int) ($_GET['vehicle_id'] ?? 0) > 0 ? (int) $_GET['vehicle_id'] : null,
            'driver_id' => (int) ($_GET['driver_id'] ?? 0) > 0 ? (int) $_GET['driver_id'] : null,
            'q' => trim((string) ($_GET['q'] ?? '')),
            'sursa' => isset(InvoiceModel::SOURCES[(string) ($_GET['sursa'] ?? '')]) ? (string) $_GET['sursa'] : '',
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function collectFormData(bool $isNew): array
    {
        $errors = [];

        $type = trim((string) ($_POST['tip'] ?? ''));
        if (!InvoiceModel::isValidType($type)) {
            $errors[] = 'Alege tipul facturii.';
        }

        $date = $this->normalizeDate((string) ($_POST['data_document'] ?? ''));
        if ($date === null) {
            $errors[] = 'Data documentului este obligatorie.';
        }

        $withoutVat = $this->normalizeDecimal((string) ($_POST['valoare_fara_tva'] ?? ''));
        $withVat = $this->normalizeDecimal((string) ($_POST['valoare_cu_tva'] ?? ''));
        if ($withVat === null || $withVat <= 0) {
            $errors[] = 'Valoarea cu TVA trebuie sa fie mai mare decat 0.';
        }
        if ($withoutVat !== null && $withoutVat < 0) {
            $errors[] = 'Valoarea fara TVA nu poate fi negativa.';
        }
        if ($withoutVat !== null && $withVat !== null && $withVat + 0.001 < $withoutVat) {
            $errors[] = 'Valoarea cu TVA nu poate fi mai mica decat cea fara TVA.';
        }

        $currency = strtoupper(trim((string) ($_POST['moneda'] ?? 'RON')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors[] = 'Moneda trebuie sa fie un cod de 3 litere (RON, EUR...).';
        }

        $text = static fn(string $key, int $max): ?string => ($value = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max)) !== '' ? $value : null;

        $data = [
            'tip' => $type,
            'furnizor' => $text('furnizor', 255),
            'numar_document' => $text('numar_document', 100),
            'data_document' => $date,
            'valoare_fara_tva' => $withoutVat !== null ? number_format($withoutVat, 2, '.', '') : null,
            'valoare_cu_tva' => $withVat !== null ? number_format($withVat, 2, '.', '') : null,
            'moneda' => $currency,
            'nr_inmatriculare_extras' => $text('nr_inmatriculare_extras', 30),
            'sofer_extras' => $text('sofer_extras', 150),
            'vehicle_id' => (int) ($_POST['vehicle_id'] ?? 0) > 0 ? (int) $_POST['vehicle_id'] : null,
            'driver_id' => (int) ($_POST['driver_id'] ?? 0) > 0 ? (int) $_POST['driver_id'] : null,
            'observatii' => $text('observatii', 5000),
        ];

        // La incarcare, numarul / soferul ales din lista inlocuieste textul (nu exista inca OCR).
        if ($isNew && $data['vehicle_id'] === null && $data['nr_inmatriculare_extras'] === null && $data['driver_id'] === null && $data['sofer_extras'] === null) {
            $errors[] = 'Completeaza vehiculul sau soferul, ca factura sa poata fi asociata unei curse.';
        }

        return [$data, $errors];
    }

    private function associationMessage(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return match ((string) $row['status']) {
            'asociata_auto', 'asociata_manual' => 'Asociata cursei #' . (int) $row['cursa_id']
                . ($row['data_inceput'] !== null ? ' (' . format_date_ro((string) $row['data_inceput']) . ' - ' . format_date_ro((string) $row['data_sfarsit']) . ')' : '') . '.',
            'de_verificat' => 'De verificat: ' . (string) $row['match_reason'],
            'neasociata' => 'Nicio cursa gasita inca; se reincearca automat cand apare cursa.',
            'respinsa' => 'Factura este respinsa.',
            default => '',
        };
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $value : null;
    }

    private function normalizeDecimal(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // "1.234,56" (RO) sau "1234.56"
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ' '], '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(' ', '', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function currentUserId(): ?int
    {
        $userId = current_user()['id'] ?? null;

        return is_numeric((string) $userId) && (int) $userId > 0 ? (int) $userId : null;
    }

    private function requirePost(string $fallbackUrl): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($fallbackUrl);
        }
    }

    /**
     * Asocierea unei cazari importate o executa modulul Cazare, deci cere si dreptul
     * de asociere din Cazare (nu doar cel din Facturi).
     */
    private function requireLegacyLinkRight(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $row = $this->model->getById($id);
        if ($row !== null && (string) $row['sursa'] === 'legacy_cazare' && !can('cazare', 'link')) {
            access_deny_403();
        }
    }

    private function requireAction(string $action): void
    {
        if (!can('facturi', $action)) {
            access_deny_403();
        }
    }

    private function viewUrl(int $id): string
    {
        return $id > 0 ? build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => $id]) : $this->listUrl();
    }

    /** Lista cu filtrele trimise in formular (pastreaza contextul dupa o actiune). */
    private function listUrl(): string
    {
        $params = ['page' => 'facturi'];
        foreach (['tip', 'status', 'sursa', 'data_start', 'data_end', 'vehicle_id', 'driver_id', 'q', 'p', 'pp'] as $key) {
            $value = trim((string) ($_POST[$key] ?? $_GET[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return build_query_url($params);
    }
}
