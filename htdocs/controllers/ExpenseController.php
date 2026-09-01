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
    private const ALLOWED_EXTENSIONS = ['pdf', 'xml', 'jpg', 'jpeg', 'png'];

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
                'overallRange' => $this->model->getOverallRange(),
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
                'overallRange' => ['count' => 0, 'min_date' => null, 'max_date' => null],
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
            'Document',
            'Furnizor / Comerciant',
            'CUI/CIF',
            'Descriere',
            'Categorie',
            'Subcategorie',
            'Alocari (detaliu)',
            'Sofer responsabil',
            'Beneficiar / Client',
            'Total',
            'Moneda',
            'Valoare fara TVA',
            'TVA',
            'Modalitate plata',
            'Status plata',
            'Data platii',
            'Scadenta',
            'Sursa',
            'Observatii',
            'Adaugat de',
        ], ';');

        foreach ($rows as $index => $row) {
            $detail = [];
            foreach ($allocations[(int) ($row['id'] ?? 0)] ?? [] as $allocation) {
                $detail[] = $this->allocationLabel($allocation) . ': ' . format_number_ro($allocation['suma'] ?? 0, 2);
            }

            $documentLabel = ExpenseModel::DOCUMENT_TYPES[(string) ($row['tip_document'] ?? '')] ?? 'Factură';
            if (trim((string) ($row['numar_document'] ?? '')) !== '') {
                $documentLabel .= ' · ' . trim((string) $row['numar_document']);
            }

            fputcsv($output, [
                (string) ($index + 1),
                !empty($row['data_cheltuiala']) ? format_date_ro((string) $row['data_cheltuiala']) : '',
                $documentLabel,
                (string) ($row['furnizor'] ?? ''),
                (string) ($row['cui'] ?? ''),
                (string) ($row['descriere'] ?? ''),
                ExpenseModel::CATEGORIES[(string) ($row['categorie'] ?? '')] ?? (string) ($row['categorie'] ?? ''),
                (string) ($row['tip_nume'] ?? ''),
                implode(' | ', $detail),
                (string) ($row['sofer_responsabil_nume'] ?? ''),
                (string) ($row['beneficiar_nume'] ?? ''),
                format_number_ro($row['valoare'] ?? 0, 2),
                (string) ($row['moneda'] ?? 'RON'),
                ($row['valoare_neta'] ?? null) !== null ? format_number_ro($row['valoare_neta'], 2) : '',
                ($row['tva'] ?? null) !== null ? format_number_ro($row['tva'], 2) : '',
                ExpenseModel::PAYMENT_METHODS[(string) ($row['modalitate_plata'] ?? '')] ?? '',
                ExpenseModel::PAYMENT_STATUSES[(string) ($row['status_plata'] ?? '')] ?? '',
                !empty($row['data_platii']) ? format_date_ro((string) $row['data_platii']) : '',
                !empty($row['scadenta']) ? format_date_ro((string) $row['scadenta']) : '',
                ExpenseModel::SOURCES[(string) ($row['sursa'] ?? 'manual')] ?? 'Manual',
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

        // Sursa originala (SPV/OCR/Import) se pastreaza la editarea manuala.
        $existing = $this->model->findExpense($id);
        if ($existing !== null) {
            $data['sursa'] = (string) ($existing['sursa'] ?? 'manual');
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

        $tipDocument = (string) ($input['tip_document'] ?? 'factura');
        if (!isset(ExpenseModel::DOCUMENT_TYPES[$tipDocument])) {
            $errors[] = 'Selectează tipul documentului.';
            $tipDocument = 'factura';
        }

        $valoare = $this->parseMoney($input['valoare'] ?? null) ?? 0.0;
        if ($valoare <= 0) {
            $errors[] = 'Totalul trebuie să fie mai mare decât zero.';
        }

        $valoareNeta = $this->parseMoney($input['valoare_neta'] ?? null);
        $tva = $this->parseMoney($input['tva'] ?? null);
        if ($valoareNeta !== null && $valoareNeta > 0 && $tva !== null && $tva > 0
            && abs(($valoareNeta + $tva) - $valoare) > 0.01) {
            $errors[] = sprintf(
                'Valoarea fără TVA + TVA (%s lei) diferă de total (%s lei).',
                format_number_ro($valoareNeta + $tva, 2),
                format_number_ro($valoare, 2)
            );
        }

        $moneda = strtoupper(trim((string) ($input['moneda'] ?? 'RON')));
        if (!in_array($moneda, ExpenseModel::CURRENCIES, true)) {
            $moneda = 'RON';
        }

        $modalitatePlata = (string) ($input['modalitate_plata'] ?? '');
        if (!isset(ExpenseModel::PAYMENT_METHODS[$modalitatePlata])) {
            $modalitatePlata = '';
        }

        // Campurile specifice facturii (status plata, data platii, scadenta)
        // se pastreaza doar pentru facturi; pentru bon fiscal / chitanta nu au sens.
        $statusPlata = '';
        $dataPlatii = null;
        $scadenta = null;
        if ($tipDocument === 'factura') {
            $statusPlata = (string) ($input['status_plata'] ?? '');
            if (!isset(ExpenseModel::PAYMENT_STATUSES[$statusPlata])) {
                $statusPlata = '';
            }
            $dataPlatii = $this->normalizeDate((string) ($input['data_platii'] ?? ''));
            if ($statusPlata === 'neplatita') {
                $dataPlatii = null;
            }
            $scadenta = $this->normalizeDate((string) ($input['scadenta'] ?? ''));
        }

        $furnizor = trim((string) ($input['furnizor'] ?? ''));
        if ($furnizor === '') {
            $errors[] = 'Furnizorul / comerciantul este obligatoriu.';
        }

        $beneficiarId = 0;
        if ((string) ($input['beneficiar_activ'] ?? '') === '1') {
            $beneficiarId = (int) ($input['beneficiar_id'] ?? 0);
            $validIds = array_map(static fn(array $row): int => (int) $row['id'], $this->model->getBeneficiaries());
            if ($beneficiarId <= 0 || !in_array($beneficiarId, $validIds, true)) {
                $errors[] = 'Selectează beneficiarul / clientul.';
            }
        }

        // Soferul responsabil este optional si pur informativ (nu preia din valoare).
        $soferResponsabilId = (int) ($input['sofer_responsabil_id'] ?? 0);
        if ($soferResponsabilId > 0) {
            $driverIds = array_map(static fn(array $row): int => (int) $row['id'], $this->model->getDrivers());
            if (!in_array($soferResponsabilId, $driverIds, true)) {
                $errors[] = 'Șoferul responsabil selectat este invalid.';
                $soferResponsabilId = 0;
            }
        }

        [$allocations, $alocareTip, $distribuire, $allocationErrors] = $this->collectAllocations($input, $valoare);
        foreach ($allocationErrors as $allocationError) {
            $errors[] = $allocationError;
        }

        $data = [
            'categorie' => $categorie,
            'tip_id' => $tipId,
            'tip_document' => $tipDocument,
            'data_cheltuiala' => $this->normalizeDate((string) ($input['data_cheltuiala'] ?? '')) ?: date('Y-m-d'),
            'furnizor' => $furnizor,
            'descriere' => trim((string) ($input['descriere'] ?? '')),
            'cui' => trim((string) ($input['cui'] ?? '')),
            'valoare' => $valoare,
            'valoare_neta' => $valoareNeta,
            'tva' => $tva,
            'moneda' => $moneda,
            'modalitate_plata' => $modalitatePlata,
            'status_plata' => $statusPlata,
            'data_platii' => $dataPlatii,
            'scadenta' => $scadenta,
            'sursa' => 'manual',
            'numar_document' => trim((string) ($input['numar_document'] ?? '')),
            'observatii' => trim((string) ($input['observatii'] ?? '')),
            'beneficiar_id' => $beneficiarId,
            'sofer_responsabil_id' => $soferResponsabilId,
            'alocare_tip' => $alocareTip,
            'distribuire' => $distribuire,
        ];

        return [$data, $allocations, $errors];
    }

    /**
     * Construieste randurile de alocare din formular. Dimensiunile Vehicul /
     * Sofer se pot combina liber pe aceeasi factura; fara nicio selectie,
     * cheltuiala ramane nealocata (nivel de firma, tip_alocare=companie in
     * date). Suma alocarilor trebuie sa fie exact valoarea
     * cheltuielii (o diferenta de rotunjire de maximum 0,01 lei este
     * absorbita in ultimul rand).
     *
     * @return array{0:array,1:string,2:string,3:array} [allocations, alocare_tip, distribuire, errors]
     */
    private function collectAllocations(array $input, float $valoare): array
    {
        $errors = [];

        $vehicleLabels = [];
        foreach ($this->model->getVehicles() as $vehicle) {
            $vehicleLabels[(int) $vehicle['id']] = trim((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']);
        }
        $driverLabels = [];
        foreach ($this->model->getDrivers() as $driver) {
            $driverLabels[(int) $driver['id']] = (string) $driver['nume'];
        }

        $dimVehicul = (string) ($input['aloc_vehicul'] ?? '') === '1';
        $dimSofer = (string) ($input['aloc_sofer'] ?? '') === '1';
        $distribuire = (string) ($input['distribuire'] ?? 'egal') === 'manual' ? 'manual' : 'egal';

        // Alocarea banilor este exclusiva: fie pe vehicule, fie pe soferi.
        // Pentru a lega un sofer de o cheltuiala a vehiculului exista campul
        // informativ "Sofer responsabil", care nu imparte valoarea.
        if ($dimVehicul && $dimSofer) {
            $errors[] = 'Alocarea se face fie pe vehicule, fie pe șoferi. Folosește câmpul „Șofer responsabil” pentru a lega un șofer de o cheltuială a vehiculului.';
            return [[], 'companie', 'egal', $errors];
        }

        // Lista de entitati selectate, in ordinea afisata in formular.
        $entities = [];
        $kinds = [];

        if ($dimVehicul) {
            $ids = [];
            foreach ((array) ($input['vehicule'] ?? []) as $rawId) {
                $id = (int) $rawId;
                if ($id > 0 && isset($vehicleLabels[$id]) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                $errors[] = 'Selectează cel puțin un vehicul sau debifează alocarea pe vehicul.';
            }
            foreach ($ids as $id) {
                $entities[] = ['tip_alocare' => 'vehicul', 'vehicul_id' => $id, 'eticheta' => $vehicleLabels[$id]];
            }
            $kinds[] = 'vehicul';
        }

        if ($dimSofer) {
            $ids = [];
            foreach ((array) ($input['soferi'] ?? []) as $rawId) {
                $id = (int) $rawId;
                if ($id > 0 && isset($driverLabels[$id]) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                $errors[] = 'Selectează cel puțin un șofer sau debifează alocarea pe șofer.';
            }
            foreach ($ids as $id) {
                $entities[] = ['tip_alocare' => 'sofer', 'sofer_id' => $id, 'eticheta' => $driverLabels[$id]];
            }
            $kinds[] = 'sofer';
        }

        // Fara vehicul/sofer selectat, cheltuiala este a companiei (100%).
        if (!$dimVehicul && !$dimSofer) {
            return [[
                ['tip_alocare' => 'companie', 'eticheta' => 'Companie', 'suma' => round($valoare, 2)],
            ], 'companie', 'egal', $errors];
        }

        if ($entities === []) {
            return [[], $kinds[0] ?? 'companie', $distribuire, $errors];
        }

        $alocareTip = count($kinds) === 1 ? $kinds[0] : 'mixt';

        $allocations = [];
        if (count($entities) === 1 || $distribuire === 'egal') {
            // Impartire egala cu rotunjire: ultimul rand absoarbe diferenta,
            // astfel incat totalul alocat sa fie exact valoarea cheltuielii.
            $count = count($entities);
            $base = floor(($valoare / $count) * 100) / 100;
            foreach ($entities as $index => $entity) {
                $entity['suma'] = $index === $count - 1 ? round($valoare - $base * ($count - 1), 2) : $base;
                $allocations[] = $entity;
            }
            $distribuire = 'egal';
        } else {
            $vehicleAmounts = (array) ($input['suma_vehicul'] ?? []);
            $driverAmounts = (array) ($input['suma_sofer'] ?? []);
            foreach ($entities as $entity) {
                if ($entity['tip_alocare'] === 'vehicul') {
                    $id = (int) $entity['vehicul_id'];
                    $suma = $this->parseMoney($vehicleAmounts[(string) $id] ?? ($vehicleAmounts[$id] ?? null)) ?? 0.0;
                } else {
                    $id = (int) $entity['sofer_id'];
                    $suma = $this->parseMoney($driverAmounts[(string) $id] ?? ($driverAmounts[$id] ?? null)) ?? 0.0;
                }

                if ($suma <= 0) {
                    $errors[] = 'Introdu o sumă mai mare decât zero pentru ' . $entity['eticheta'] . '.';
                    continue;
                }
                $entity['suma'] = $suma;
                $allocations[] = $entity;
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
            return 'Birou / Administrativ';
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
