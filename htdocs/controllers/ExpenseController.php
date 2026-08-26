<?php
declare(strict_types=1);

/**
 * Pagina unificata "Cheltuieli" (administrative + operationale, cu alocare
 * independenta pe vehicul / sofer / companie si beneficiar optional).
 * Inlocuieste OfficeExpenseController (cheltuieli_birou) si
 * AdministrativeExpenseController (cheltuieli_administrative).
 */
class ExpenseController
{
    private const PER_PAGE_OPTIONS = [10, 20, 50];
    private const MAX_UPLOAD_SIZE = 10485760; // 10 MB, conform formularului
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private ExpenseModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new ExpenseModel($db);
    }

    public function handle(string $action): void
    {
        require_page_or_403('cheltuieli');

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'export':
                $this->requireAction('export');
                $this->exportAction();
                return;
            case 'store':
                $this->requireAction('create');
                $this->storeAction();
                return;
            case 'update':
                $this->requireAction('edit');
                $this->updateAction();
                return;
            case 'delete':
                $this->requireAction('delete');
                $this->deleteAction();
                return;
            case 'download_document':
                $this->downloadDocumentAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'cheltuieli',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $filters = $this->collectFilters();
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = (int) ($_GET['pp'] ?? 20);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        try {
            $result = $this->model->getPaginatedExpenses($filters, $page, $perPage);
            $rows = $result['rows'];
            $viewData = [
                'pageTitle' => 'Cheltuieli',
                'currentPage' => 'cheltuieli',
                'filters' => $filters,
                'rows' => $rows,
                'allocationsByExpense' => $this->model->getAllocationsForRows($rows),
                'summary' => $this->model->getSummary($filters),
                'types' => $this->model->getTypes(),
                'vehicles' => $this->model->getVehicles(),
                'drivers' => $this->model->getDrivers(),
                'beneficiaries' => $this->model->getBeneficiaries(),
                'suppliers' => $this->model->getSuppliers(),
                'pagination' => [
                    'page' => $result['page'],
                    'total_pages' => $result['total_pages'],
                    'total_rows' => $result['total_rows'],
                    'per_page' => $result['per_page'],
                ],
                'perPageOptions' => self::PER_PAGE_OPTIONS,
            ];
        } catch (Throwable $exception) {
            error_log('[ExpenseController][index] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Cheltuieli necesita actualizarea bazei de date. Ruleaza database/update_cheltuieli_unificat.sql.');
            $viewData = [
                'pageTitle' => 'Cheltuieli',
                'currentPage' => 'cheltuieli',
                'filters' => $filters,
                'rows' => [],
                'allocationsByExpense' => [],
                'summary' => [
                    'total' => 0, 'count' => 0, 'count_administrativa' => 0, 'count_operationala' => 0,
                    'administrativa' => 0, 'operationala' => 0,
                    'procent_administrativa' => 0, 'procent_operationala' => 0,
                    'alocare' => ['vehicul' => 0, 'sofer' => 0, 'companie' => 0],
                    'alocare_total' => 0, 'top_tipuri' => [],
                ],
                'types' => [],
                'vehicles' => [],
                'drivers' => [],
                'beneficiaries' => [],
                'suppliers' => [],
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => $perPage],
                'perPageOptions' => self::PER_PAGE_OPTIONS,
            ];
        }

        render('cheltuieli/index.php', $viewData);
    }

    private function exportAction(): void
    {
        $filters = $this->collectFilters();
        $rows = $this->model->getExpensesForExport($filters);
        $allocations = $this->model->getAllocationsForRows($rows);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cheltuieli_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            exit;
        }

        fputcsv($output, [
            '#',
            'Data',
            'Categorie',
            'Tip cheltuiala',
            'Alocata catre',
            'Alocari (detaliu)',
            'Beneficiar / Client',
            'Furnizor',
            'Valoare (lei)',
            'Nr. document',
            'Observatii',
            'Adaugat de',
        ], ';');

        foreach ($rows as $index => $row) {
            $detail = [];
            foreach ($allocations[(int) ($row['id'] ?? 0)] ?? [] as $allocation) {
                $detail[] = $this->allocationLabel($allocation) . ': ' . format_number_ro($allocation['suma'] ?? 0, 2) . ' lei';
            }

            fputcsv($output, [
                (string) ($index + 1),
                !empty($row['data_cheltuiala']) ? format_date_ro((string) $row['data_cheltuiala']) : '',
                ExpenseModel::CATEGORIES[(string) ($row['categorie'] ?? '')] ?? (string) ($row['categorie'] ?? ''),
                (string) ($row['tip_nume'] ?? ''),
                ExpenseModel::ALLOCATION_TYPES[(string) ($row['alocare_tip'] ?? '')] ?? (string) ($row['alocare_tip'] ?? ''),
                implode(' | ', $detail),
                (string) ($row['beneficiar_nume'] ?? ''),
                (string) ($row['furnizor'] ?? ''),
                format_number_ro($row['valoare'] ?? 0, 2),
                (string) ($row['numar_document'] ?? ''),
                (string) ($row['observatii'] ?? ''),
                (string) ($row['added_by_name'] ?? ''),
            ], ';');
        }

        fclose($output);
        exit;
    }

    private function storeAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        [$data, $allocations, $errors] = $this->collectAndValidateInput($_POST);
        [$documentData, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            $this->model->createExpense($data, $allocations, $documentData, $this->currentUserId());
            flash_set('success', 'Cheltuiala a fost adăugată.');
        } catch (Throwable $exception) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            error_log('[ExpenseController][store] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva cheltuiala.');
        }

        redirect($this->indexUrl());
    }

    private function updateAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        [$data, $allocations, $errors] = $this->collectAndValidateInput($_POST);
        if ($id <= 0) {
            $errors[] = 'Cheltuiala selectată este invalidă.';
        }

        [$documentData, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            if (!$this->model->updateExpense($id, $data, $allocations, $documentData, $this->currentUserId())) {
                flash_set('warning', 'Cheltuiala nu a fost găsită.');
            } else {
                flash_set('success', 'Cheltuiala a fost actualizată.');
            }
        } catch (Throwable $exception) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            error_log('[ExpenseController][update] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza cheltuiala.');
        }

        redirect($this->indexUrl());
    }

    private function deleteAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Cheltuiala selectată este invalidă.');
            redirect($this->indexUrl());
        }

        try {
            $documents = $this->model->deleteExpense($id);
            foreach ($documents as $document) {
                // Documentele migrate din modulele legacy pastreaza fisierul fizic:
                // randul original din arhiva legacy inca il refera.
                if (trim((string) ($document['legacy_source'] ?? '')) !== '') {
                    continue;
                }
                $this->deletePhysicalDocument((string) ($document['stored_name'] ?? ''));
            }
            flash_set('success', 'Cheltuiala a fost ștearsă.');
        } catch (Throwable $exception) {
            error_log('[ExpenseController][delete] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut șterge cheltuiala.');
        }

        redirect($this->indexUrl());
    }

    private function downloadDocumentAction(): void
    {
        $documentId = (int) ($_GET['document_id'] ?? 0);
        $document = $this->model->findDocument($documentId);
        if ($document === null || trim((string) ($document['stored_name'] ?? '')) === '') {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Document inexistent',
                'currentPage' => 'cheltuieli',
            ]);
            return;
        }

        $path = BASE_PATH . '/uploads/documente/' . basename((string) $document['stored_name']);
        if (!is_file($path)) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Document inexistent',
                'currentPage' => 'cheltuieli',
            ]);
            return;
        }

        $downloadName = trim((string) ($document['original_name'] ?? ''));
        if ($downloadName === '') {
            $downloadName = basename($path);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ filtre

    private function collectFilters(): array
    {
        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('first day of this month')->format('Y-m-d');
        $defaultEnd = $today->modify('last day of this month')->format('Y-m-d');

        $categorie = trim((string) ($_GET['categorie'] ?? ''));
        if (!isset(ExpenseModel::CATEGORIES[$categorie])) {
            $categorie = '';
        }

        $alocare = trim((string) ($_GET['alocare'] ?? ''));
        if (!in_array($alocare, ['vehicul', 'sofer', 'companie'], true)) {
            $alocare = '';
        }

        return [
            'date_start' => $this->normalizeDate((string) ($_GET['date_start'] ?? $defaultStart)) ?: $defaultStart,
            'date_end' => $this->normalizeDate((string) ($_GET['date_end'] ?? $defaultEnd)) ?: $defaultEnd,
            'categorie' => $categorie,
            'alocare' => $alocare,
            'beneficiar_id' => (int) ($_GET['beneficiar_id'] ?? 0),
            'vehicul_id' => (int) ($_GET['vehicul_id'] ?? 0),
            'sofer_id' => (int) ($_GET['sofer_id'] ?? 0),
            'tip_id' => (int) ($_GET['tip_id'] ?? 0),
            'furnizor' => trim((string) ($_GET['furnizor'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    // ------------------------------------------------------- validare formular

    /**
     * @return array{0:array,1:array,2:array} [data, allocations, errors]
     */
    private function collectAndValidateInput(array $input): array
    {
        $errors = [];

        $categorie = trim((string) ($input['categorie'] ?? ''));
        if (!isset(ExpenseModel::CATEGORIES[$categorie])) {
            $errors[] = 'Selectează categoria cheltuielii.';
            $categorie = 'administrativa';
        }

        $tipId = (int) ($input['tip_id'] ?? 0);
        $tip = $this->model->findType($tipId);
        if ($tip === null || (string) ($tip['status'] ?? '') !== 'activ') {
            $errors[] = 'Selectează un tip de cheltuială valid.';
        } elseif ((string) ($tip['categorie'] ?? '') !== $categorie) {
            $errors[] = 'Tipul de cheltuială nu aparține categoriei selectate.';
        }

        $valoare = $this->parseMoney($input['valoare'] ?? null) ?? 0.0;
        if ($valoare <= 0) {
            $errors[] = 'Valoarea trebuie să fie mai mare decât zero.';
        }

        $furnizor = trim((string) ($input['furnizor'] ?? ''));
        if ($furnizor === '') {
            $errors[] = 'Furnizorul este obligatoriu.';
        }

        $beneficiarId = 0;
        if ((string) ($input['beneficiar_activ'] ?? '') === '1') {
            $beneficiarId = (int) ($input['beneficiar_id'] ?? 0);
            $validIds = array_map(static fn(array $row): int => (int) $row['id'], $this->model->getBeneficiaries());
            if ($beneficiarId <= 0 || !in_array($beneficiarId, $validIds, true)) {
                $errors[] = 'Selectează beneficiarul / clientul.';
            }
        }

        [$allocations, $alocareTip, $distribuire, $allocationErrors] = $this->collectAllocations($input, $valoare);
        foreach ($allocationErrors as $allocationError) {
            $errors[] = $allocationError;
        }

        $data = [
            'categorie' => $categorie,
            'tip_id' => $tipId,
            'data_cheltuiala' => $this->normalizeDate((string) ($input['data_cheltuiala'] ?? '')) ?: date('Y-m-d'),
            'furnizor' => $furnizor,
            'valoare' => $valoare,
            'numar_document' => trim((string) ($input['numar_document'] ?? '')),
            'observatii' => trim((string) ($input['observatii'] ?? '')),
            'beneficiar_id' => $beneficiarId,
            'alocare_tip' => $alocareTip,
            'distribuire' => $distribuire,
        ];

        return [$data, $allocations, $errors];
    }

    /**
     * Construieste randurile de alocare din formular si valideaza ca suma lor
     * este exact valoarea cheltuielii (tolerata o diferenta de rotunjire de
     * maximum 0,01 lei, absorbita in ultimul rand).
     *
     * @return array{0:array,1:string,2:string,3:array} [allocations, alocare_tip, distribuire, errors]
     */
    private function collectAllocations(array $input, float $valoare): array
    {
        $errors = [];
        $mode = (string) ($input['aloc_mode'] ?? 'simplu');

        $vehicleLabels = [];
        foreach ($this->model->getVehicles() as $vehicle) {
            $vehicleLabels[(int) $vehicle['id']] = trim((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']);
        }
        $driverLabels = [];
        foreach ($this->model->getDrivers() as $driver) {
            $driverLabels[(int) $driver['id']] = (string) $driver['nume'];
        }

        if ($mode === 'mixt') {
            $allocations = [];
            $lines = is_array($input['pozitii'] ?? null) ? $input['pozitii'] : [];
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $tip = (string) ($line['tip'] ?? '');
                $suma = $this->parseMoney($line['suma'] ?? null) ?? 0.0;
                if ($suma <= 0) {
                    $errors[] = 'Fiecare poziție din factura mixtă trebuie să aibă o sumă mai mare decât zero.';
                    continue;
                }

                if ($tip === 'vehicul') {
                    $vehiculId = (int) ($line['vehicul_id'] ?? 0);
                    if (!isset($vehicleLabels[$vehiculId])) {
                        $errors[] = 'Selectează vehiculul pentru fiecare poziție de tip Vehicul.';
                        continue;
                    }
                    $allocations[] = ['tip_alocare' => 'vehicul', 'vehicul_id' => $vehiculId, 'eticheta' => $vehicleLabels[$vehiculId], 'suma' => $suma];
                } elseif ($tip === 'sofer') {
                    $soferId = (int) ($line['sofer_id'] ?? 0);
                    if (!isset($driverLabels[$soferId])) {
                        $errors[] = 'Selectează șoferul pentru fiecare poziție de tip Șofer.';
                        continue;
                    }
                    $allocations[] = ['tip_alocare' => 'sofer', 'sofer_id' => $soferId, 'eticheta' => $driverLabels[$soferId], 'suma' => $suma];
                } elseif ($tip === 'companie') {
                    $allocations[] = ['tip_alocare' => 'companie', 'eticheta' => 'Companie', 'suma' => $suma];
                } else {
                    $errors[] = 'Tipul unei poziții din factura mixtă este invalid.';
                }
            }

            if ($allocations === []) {
                $errors[] = 'Adaugă cel puțin o poziție în factura cu alocări multiple.';
            }

            $allocations = $this->reconcileTotals($allocations, $valoare, $errors);

            return [$allocations, 'mixt', 'manual', $errors];
        }

        // Modul simplu: o singura dimensiune de alocare.
        $alocareTip = (string) ($input['alocare_tip'] ?? 'companie');
        if (!in_array($alocareTip, ['vehicul', 'sofer', 'companie'], true)) {
            $alocareTip = 'companie';
        }
        $distribuire = (string) ($input['distribuire'] ?? 'egal') === 'manual' ? 'manual' : 'egal';

        if ($alocareTip === 'companie') {
            return [[
                ['tip_alocare' => 'companie', 'eticheta' => 'Companie', 'suma' => round($valoare, 2)],
            ], 'companie', 'egal', $errors];
        }

        $isVehicle = $alocareTip === 'vehicul';
        $labels = $isVehicle ? $vehicleLabels : $driverLabels;
        $idsInput = $input[$isVehicle ? 'vehicule' : 'soferi'] ?? [];
        $ids = [];
        foreach ((array) $idsInput as $rawId) {
            $id = (int) $rawId;
            if ($id > 0 && isset($labels[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            $errors[] = $isVehicle ? 'Selectează cel puțin un vehicul.' : 'Selectează cel puțin un șofer.';
            return [[], $alocareTip, $distribuire, $errors];
        }

        $allocations = [];
        if (count($ids) === 1 || $distribuire === 'egal') {
            // Impartire egala cu rotunjire: ultimul rand absoarbe diferenta,
            // astfel incat totalul alocat sa fie exact valoarea cheltuielii.
            $count = count($ids);
            $base = floor(($valoare / $count) * 100) / 100;
            foreach ($ids as $index => $id) {
                $suma = $index === $count - 1 ? round($valoare - $base * ($count - 1), 2) : $base;
                $allocations[] = [
                    'tip_alocare' => $alocareTip,
                    ($isVehicle ? 'vehicul_id' : 'sofer_id') => $id,
                    'eticheta' => $labels[$id],
                    'suma' => $suma,
                ];
            }
            $distribuire = 'egal';
        } else {
            $amounts = (array) ($input[$isVehicle ? 'suma_vehicul' : 'suma_sofer'] ?? []);
            foreach ($ids as $id) {
                $suma = $this->parseMoney($amounts[(string) $id] ?? ($amounts[$id] ?? null)) ?? 0.0;
                if ($suma <= 0) {
                    $errors[] = 'Introdu o sumă mai mare decât zero pentru ' . $labels[$id] . '.';
                    continue;
                }
                $allocations[] = [
                    'tip_alocare' => $alocareTip,
                    ($isVehicle ? 'vehicul_id' : 'sofer_id') => $id,
                    'eticheta' => $labels[$id],
                    'suma' => $suma,
                ];
            }
            $allocations = $this->reconcileTotals($allocations, $valoare, $errors);
        }

        return [$allocations, $alocareTip, $distribuire, $errors];
    }

    /**
     * Blocheaza salvarea cand suma alocarilor difera de valoarea cheltuielii.
     * O diferenta de rotunjire de cel mult 0,01 lei este absorbita in ultimul rand.
     */
    private function reconcileTotals(array $allocations, float $valoare, array &$errors): array
    {
        if ($allocations === []) {
            return $allocations;
        }

        $sum = 0.0;
        foreach ($allocations as $allocation) {
            $sum += (float) $allocation['suma'];
        }
        $sum = round($sum, 2);
        $diff = round($valoare - $sum, 2);

        if (abs($diff) > 0.01) {
            $errors[] = sprintf(
                'Total alocat %s lei diferă de valoarea cheltuielii %s lei. Ajustează sumele alocate.',
                format_number_ro($sum, 2),
                format_number_ro($valoare, 2)
            );
            return $allocations;
        }

        if ($diff !== 0.0) {
            $lastIndex = array_key_last($allocations);
            $allocations[$lastIndex]['suma'] = round((float) $allocations[$lastIndex]['suma'] + $diff, 2);
        }

        return $allocations;
    }

    private function allocationLabel(array $allocation): string
    {
        $tip = (string) ($allocation['tip_alocare'] ?? '');
        if ($tip === 'companie') {
            return 'Companie';
        }
        if ($tip === 'vehicul') {
            $label = trim((string) ($allocation['vehicul_nr'] ?? ''));
            return $label !== '' ? $label : (string) ($allocation['eticheta'] ?? 'Vehicul șters');
        }
        $label = trim((string) ($allocation['sofer_nume'] ?? ''));

        return $label !== '' ? $label : (string) ($allocation['eticheta'] ?? 'Șofer șters');
    }

    // ---------------------------------------------------------------- documente

    private function storeUploadedDocument(?array $file): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Documentul nu a putut fi încărcat.'];
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_UPLOAD_SIZE) {
            return [null, 'Documentul depășește limita de 10 MB.'];
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [null, 'Formatul documentului nu este permis (PDF, JPG, PNG).'];
        }

        $uploadDir = BASE_PATH . '/uploads/documente';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return [null, 'Directorul de upload nu poate fi creat.'];
        }

        try {
            $storedName = 'cheltuiala_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        } catch (Throwable) {
            $storedName = 'cheltuiala_' . date('YmdHis') . '_' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
        }

        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
            return [null, 'Documentul nu a putut fi salvat.'];
        }

        return [[
            'original_name' => $originalName,
            'stored_name' => $storedName,
        ], null];
    }

    private function deletePhysicalDocument(string $storedFile): void
    {
        $storedFile = trim($storedFile);
        if ($storedFile === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/documente/' . basename($storedFile);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------- utile

    private function parseMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        return round((float) $raw, 2);
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function requireAction(string $action): void
    {
        if (function_exists('can') && !can('cheltuieli', $action)) {
            access_deny_403();
        }
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }
    }

    private function currentUserId(): ?int
    {
        $user = current_user();
        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function indexUrl(): string
    {
        return build_query_url(['page' => 'cheltuieli']);
    }
}
