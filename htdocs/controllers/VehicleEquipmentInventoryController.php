<?php
declare(strict_types=1);

class VehicleEquipmentInventoryController
{
    private VehicleEquipmentInventoryModel $inventoryModel;
    private EntityStatusService $entityStatusService;

    public function __construct(private PDO $db)
    {
        $this->inventoryModel = new VehicleEquipmentInventoryModel($db);
        $this->entityStatusService = new EntityStatusService($db);
    }

    public function handle(string $action): void
    {
        match ($action) {
            'catalog' => $this->catalogAction(),
            'save_catalog' => $this->saveCatalogAction(),
            'delete_catalog' => $this->deleteCatalogAction(),
            'rules' => $this->rulesAction(),
            'add_rule' => $this->addRuleAction(),
            'delete_rule' => $this->deleteRuleAction(),
            'create_assignment' => $this->createAssignmentAction(),
            'update_assignment' => $this->updateAssignmentAction(),
            'delete_assignment' => $this->deleteAssignmentAction(),
            'delete_vehicle_assignments' => $this->deleteVehicleAssignmentsAction(),
            'export' => $this->exportAction(),
            default => $this->indexAction(),
        };
    }

    private function indexAction(): void
    {
        $this->entityStatusService->syncAllVehicleStatuses();

        $filters = $this->collectFilters();
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $sort = (string) ($_GET['sort'] ?? 'nr_inmatriculare');
        $direction = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $result = $this->inventoryModel->getVehicleSummaries($filters, $sort, $direction, $page, ITEMS_PER_PAGE);

        render('inventar_dotari/index.php', [
            'pageTitle' => 'Inventar Dotări Vehicule',
            'currentPage' => 'inventar_dotari_vehicule',
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'rows' => $result['rows'],
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total_rows' => $result['total_rows'],
                'per_page' => $result['per_page'],
            ],
            'stats' => $this->inventoryModel->getDashboardStats(),
            'vehicleTypeOptions' => $this->inventoryModel->getVehicleTypeOptions(),
            'statusOptions' => $this->inventoryModel->getStatusOptions(),
            'catalogItems' => $this->inventoryModel->getCatalogItems(true),
        ]);
    }

    private function catalogAction(): void
    {
        render('inventar_dotari/catalog.php', [
            'pageTitle' => 'Catalog Dotări',
            'currentPage' => 'inventar_dotari_vehicule',
            'catalogItems' => $this->inventoryModel->getCatalogItems(false),
            'equipmentTypeOptions' => VehicleEquipmentInventoryModel::EQUIPMENT_TYPES,
        ]);
    }

    private function rulesAction(): void
    {
        render('inventar_dotari/rules.php', [
            'pageTitle' => 'Reguli Dotări',
            'currentPage' => 'inventar_dotari_vehicule',
            'rules' => $this->inventoryModel->getRules(),
            'catalogItems' => $this->inventoryModel->getCatalogItems(true),
            'vehicleTypeOptions' => $this->inventoryModel->getVehicleTypeOptions(),
        ]);
    }

    private function saveCatalogAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));

        $id = (int) ($_POST['catalog_id'] ?? 0);
        $existing = $id > 0 ? $this->inventoryModel->getCatalogItem($id) : null;
        $data = $this->catalogPayload($existing);
        $errors = $this->validateCatalogPayload($data);

        [$imageData, $uploadError] = $this->storeUploadedImage($_FILES['poza_produs'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($imageData !== null) {
            $data = array_merge($data, $imageData);
        }

        if ((string) ($_POST['sterge_poza'] ?? '') === '1') {
            $data['poza_original'] = null;
            $data['poza_stocata'] = null;
        }

        if ($errors !== []) {
            if ($imageData !== null) {
                $this->cleanupImage($imageData['poza_stocata'] ?? null);
            }
            flash_set('danger', implode(' ', $errors));
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
        }

        try {
            $savedId = $this->inventoryModel->saveCatalogItem($data, $id > 0 ? $id : null);
            if ($existing !== null && (!empty($imageData['poza_stocata']) || (string) ($_POST['sterge_poza'] ?? '') === '1')) {
                $this->cleanupImage($existing['poza_stocata'] ?? null);
            }
            flash_set('success', $savedId === $id ? 'Dotarea din catalog a fost actualizată.' : 'Dotarea a fost adăugată în catalog.');
        } catch (Throwable $exception) {
            if ($imageData !== null) {
                $this->cleanupImage($imageData['poza_stocata'] ?? null);
            }
            error_log('[VehicleEquipmentInventory][catalog-save] ' . $exception->getMessage());
            flash_set('danger', 'Catalogul nu a putut fi salvat. Verifică dacă numele dotării este unic.');
        }

        redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
    }

    private function deleteCatalogAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
        $id = (int) ($_POST['catalog_id'] ?? 0);
        $existing = $id > 0 ? $this->inventoryModel->getCatalogItem($id) : null;

        if ($existing === null) {
            flash_set('warning', 'Dotarea din catalog nu există.');
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
        }

        try {
            $this->inventoryModel->deleteCatalogItem($id);
            $this->cleanupImage($existing['poza_stocata'] ?? null);
            flash_set('success', 'Dotarea a fost ștearsă din catalog.');
        } catch (Throwable $exception) {
            error_log('[VehicleEquipmentInventory][catalog-delete] ' . $exception->getMessage());
            flash_set('danger', 'Dotarea este folosită în reguli sau asignări. Dezactiveaz-o în loc să o ștergi.');
        }

        redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog']));
    }

    private function addRuleAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
        $vehicleType = (string) ($_POST['vehicle_type'] ?? '');
        $catalogId = (int) ($_POST['catalog_id'] ?? 0);
        $allowedTypes = array_keys($this->inventoryModel->getVehicleTypeOptions());

        if (!in_array($vehicleType, $allowedTypes, true) || $this->inventoryModel->getCatalogItem($catalogId) === null) {
            flash_set('danger', 'Selectează un tip de vehicul și o dotare validă.');
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
        }

        $this->inventoryModel->addRule($vehicleType, $catalogId);
        flash_set('success', 'Regula de dotare a fost salvată.');
        redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
    }

    private function deleteRuleAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
        $id = (int) ($_POST['rule_id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Regula selectată nu este validă.');
        } else {
            $this->inventoryModel->deleteRule($id);
            flash_set('success', 'Regula de dotare a fost ștearsă.');
        }

        redirect(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules']));
    }

    private function createAssignmentAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        $data = $this->assignmentPayload(null);
        $errors = $this->validateAssignmentPayload($data);
        [$imageData, $uploadError] = $this->storeUploadedImage($_FILES['poza_produs'] ?? null);

        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($imageData !== null) {
            $data = array_merge($data, $imageData);
        }

        if ($errors !== []) {
            if ($imageData !== null) {
                $this->cleanupImage($imageData['poza_stocata'] ?? null);
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->inventoryBackUrl());
        }

        try {
            $this->inventoryModel->createAssignment($data);
            flash_set('success', 'Dotarea a fost asignată vehiculului.');
        } catch (Throwable $exception) {
            if ($imageData !== null) {
                $this->cleanupImage($imageData['poza_stocata'] ?? null);
            }
            error_log('[VehicleEquipmentInventory][assignment-create] ' . $exception->getMessage());
            flash_set('danger', 'Dotarea nu a putut fi asignată.');
        }

        redirect($this->inventoryBackUrl());
    }

    private function updateAssignmentAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        $id = (int) ($_POST['assignment_id'] ?? 0);
        $existing = $id > 0 ? $this->inventoryModel->getAssignment($id) : null;
        if ($existing === null) {
            flash_set('warning', 'Dotarea selectată nu există.');
            redirect($this->inventoryBackUrl());
        }

        $data = $this->assignmentPayload($existing);
        $errors = $this->validateAssignmentPayload($data);
        [$imageData, $uploadError] = $this->storeUploadedImage($_FILES['poza_produs'] ?? null);

        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($imageData !== null) {
            $data = array_merge($data, $imageData);
        }

        if ((string) ($_POST['sterge_poza'] ?? '') === '1') {
            $data['poza_original'] = null;
            $data['poza_stocata'] = null;
        }

        if ($errors !== []) {
            if ($imageData !== null) {
                $this->cleanupImage($imageData['poza_stocata'] ?? null);
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->inventoryBackUrl());
        }

        try {
            $this->inventoryModel->updateAssignment($id, $data);
            if (!empty($imageData['poza_stocata']) || (string) ($_POST['sterge_poza'] ?? '') === '1') {
                $this->cleanupImage($existing['poza_stocata'] ?? null);
            }
            flash_set('success', 'Dotarea vehiculului a fost actualizată.');
        } catch (Throwable $exception) {
            if ($imageData !== null) {
                $this->cleanupImage($imageData['poza_stocata'] ?? null);
            }
            error_log('[VehicleEquipmentInventory][assignment-update] ' . $exception->getMessage());
            flash_set('danger', 'Dotarea nu a putut fi actualizată.');
        }

        redirect($this->inventoryBackUrl());
    }

    private function deleteAssignmentAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        $id = (int) ($_POST['assignment_id'] ?? 0);
        $existing = $id > 0 ? $this->inventoryModel->getAssignment($id) : null;

        if ($existing === null) {
            flash_set('warning', 'Dotarea selectată nu există.');
            redirect($this->inventoryBackUrl());
        }

        $this->inventoryModel->deleteAssignment($id);
        $this->cleanupImage($existing['poza_stocata'] ?? null);
        flash_set('success', 'Dotarea a fost ștearsă de pe vehicul.');
        redirect($this->inventoryBackUrl());
    }

    private function deleteVehicleAssignmentsAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inventar_dotari_vehicule']));
        $vehicleIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => (int) $value,
            (array) ($_POST['vehicle_ids'] ?? [])
        ))));
        $fallbackVehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        if ($vehicleIds === [] && $fallbackVehicleId > 0) {
            $vehicleIds[] = $fallbackVehicleId;
        }

        $inventories = [];
        foreach ($vehicleIds as $vehicleId) {
            $inventory = $this->inventoryModel->getVehicleInventory($vehicleId);
            if ($inventory !== null) {
                $inventories[$vehicleId] = $inventory;
            }
        }

        if ($inventories === []) {
            flash_set('warning', 'Vehiculul selectat nu există.');
            redirect($this->inventoryBackUrl());
        }

        foreach ($inventories as $vehicleId => $inventory) {
            foreach ($inventory['assignments'] as $assignment) {
                $this->cleanupImage($assignment['poza_stocata'] ?? null);
            }
            $this->inventoryModel->deleteVehicleAssignments((int) $vehicleId);
        }
        flash_set('success', count($inventories) > 1 ? 'Toate dotările asignate ansamblului au fost șterse.' : 'Toate dotările asignate vehiculului au fost șterse.');
        redirect($this->inventoryBackUrl());
    }

    private function exportAction(): void
    {
        $this->entityStatusService->syncAllVehicleStatuses();

        $filters = $this->collectFilters();
        $sort = (string) ($_GET['sort'] ?? 'nr_inmatriculare');
        $direction = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $rows = $this->inventoryModel->buildExportRows($filters, $sort, $direction);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="inventar_dotari_vehicule_' . date('Ymd_His') . '.csv"');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            return;
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'Nr. Înmatriculare',
            'Tip Vehicul',
            'Dotări Asignate',
            'Dotări Lipsă',
            'Cost Total Dotări',
            'Expiră Curând',
            'Expirate',
            'Status General',
        ]);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['nr_inmatriculare'] ?? '',
                $row['tip_vehicul_label'] ?? '',
                (int) ($row['assigned_count'] ?? 0),
                (int) ($row['missing_count'] ?? 0),
                number_format((float) ($row['total_cost'] ?? 0), 2, '.', ''),
                (int) ($row['expiring_soon_count'] ?? 0),
                (int) ($row['expired_count'] ?? 0),
                $row['status_label'] ?? '',
            ]);
        }

        fclose($output);
        exit;
    }

    private function collectFilters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'tip_vehicul' => trim((string) ($_GET['tip_vehicul'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'missing' => (string) ($_GET['missing'] ?? ''),
            'expired' => (string) ($_GET['expired'] ?? ''),
        ];
    }

    private function catalogPayload(?array $existing): array
    {
        return [
            'nume' => trim((string) ($_POST['nume'] ?? '')),
            'categorie' => trim((string) ($_POST['categorie'] ?? '')),
            'equipment_type' => trim((string) ($_POST['equipment_type'] ?? ($existing['equipment_type'] ?? 'mandatory'))),
            'poza_original' => $existing['poza_original'] ?? null,
            'poza_stocata' => $existing['poza_stocata'] ?? null,
            'cost_implicit' => trim((string) ($_POST['cost_implicit'] ?? '0')),
            'necesita_data_fabricatie' => (string) ($_POST['necesita_data_fabricatie'] ?? '') === '1',
            'necesita_inspectie' => (string) ($_POST['necesita_inspectie'] ?? '') === '1',
            'interval_implicit_inspectie_luni' => trim((string) ($_POST['interval_implicit_inspectie_luni'] ?? '')),
            'necesita_data_expirarii' => (string) ($_POST['necesita_data_expirarii'] ?? '') === '1',
            'activ' => (string) ($_POST['activ'] ?? '1') === '1',
        ];
    }

    private function validateCatalogPayload(array $data): array
    {
        $errors = [];
        if ((string) ($data['nume'] ?? '') === '') {
            $errors[] = 'Numele dotării este obligatoriu.';
        }
        if ((string) ($data['categorie'] ?? '') === '') {
            $errors[] = 'Categoria este obligatorie.';
        }
        if (!array_key_exists((string) ($data['equipment_type'] ?? ''), VehicleEquipmentInventoryModel::EQUIPMENT_TYPES)) {
            $errors[] = 'Tipul dotării este invalid.';
        }
        if (!is_numeric((string) ($data['cost_implicit'] ?? ''))) {
            $errors[] = 'Costul implicit trebuie să fie numeric.';
        }

        return $errors;
    }

    private function assignmentPayload(?array $existing): array
    {
        return [
            'vehicle_id' => (int) ($_POST['vehicle_id'] ?? ($existing['vehicle_id'] ?? 0)),
            'catalog_id' => (int) ($_POST['catalog_id'] ?? ($existing['catalog_id'] ?? 0)),
            'poza_original' => $existing['poza_original'] ?? null,
            'poza_stocata' => $existing['poza_stocata'] ?? null,
            'cost' => trim((string) ($_POST['cost'] ?? ($existing['cost'] ?? ''))),
            'data_achizitiei' => trim((string) ($_POST['data_achizitiei'] ?? ($existing['data_achizitiei'] ?? ''))),
            'data_fabricatiei' => trim((string) ($_POST['data_fabricatiei'] ?? ($existing['data_fabricatiei'] ?? ''))),
            'data_ultimei_inspectii' => trim((string) ($_POST['data_ultimei_inspectii'] ?? ($existing['data_ultimei_inspectii'] ?? ''))),
            'interval_inspectie_luni' => trim((string) ($_POST['interval_inspectie_luni'] ?? ($existing['interval_inspectie_luni'] ?? ''))),
            'data_expirarii' => trim((string) ($_POST['data_expirarii'] ?? ($existing['data_expirarii'] ?? ''))),
            'serie_cod_produs' => trim((string) ($_POST['serie_cod_produs'] ?? ($existing['serie_cod_produs'] ?? ''))),
            'observatii' => trim((string) ($_POST['observatii'] ?? ($existing['observatii'] ?? ''))),
        ];
    }

    private function validateAssignmentPayload(array $data): array
    {
        $errors = [];
        if ($this->inventoryModel->findVehicle((int) ($data['vehicle_id'] ?? 0)) === null) {
            $errors[] = 'Vehiculul selectat nu este valid.';
        }
        $catalogItem = $this->inventoryModel->getCatalogItem((int) ($data['catalog_id'] ?? 0));
        if ($catalogItem === null || (int) ($catalogItem['activ'] ?? 0) !== 1) {
            $errors[] = 'Selectează o dotare activă din catalog.';
        }
        if ((string) ($data['cost'] ?? '') !== '' && !is_numeric((string) $data['cost'])) {
            $errors[] = 'Costul trebuie să fie numeric.';
        }
        if ((string) ($data['interval_inspectie_luni'] ?? '') !== '' && (int) $data['interval_inspectie_luni'] <= 0) {
            $errors[] = 'Intervalul de inspecție trebuie să fie un număr pozitiv.';
        }

        return $errors;
    }

    private function storeUploadedImage(?array $file): array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Imaginea nu a putut fi încărcată.'];
        }

        $originalName = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [null, 'Poza produsului trebuie să fie JPG, PNG sau WEBP.'];
        }

        if ($size > 5242880) {
            return [null, 'Poza produsului nu poate depăși 5 MB.'];
        }

        if (!is_uploaded_file($tmpName)) {
            return [null, 'Upload invalid pentru poza produsului.'];
        }

        $uploadDir = BASE_PATH . '/uploads/inventar_dotari';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return [null, 'Folderul pentru poze inventar nu poate fi creat.'];
        }

        $storedName = 'dotare_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!move_uploaded_file($tmpName, $uploadDir . '/' . $storedName)) {
            return [null, 'Poza produsului nu a putut fi salvată.'];
        }

        return [[
            'poza_original' => $originalName,
            'poza_stocata' => $storedName,
        ], null];
    }

    private function cleanupImage(mixed $storedFile): void
    {
        $storedFile = trim((string) ($storedFile ?? ''));
        if ($storedFile === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/inventar_dotari/' . basename($storedFile);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function inventoryBackUrl(): string
    {
        $params = ['page' => 'inventar_dotari_vehicule'];
        foreach (['q', 'tip_vehicul', 'status', 'missing', 'expired', 'sort', 'dir', 'p'] as $key) {
            if (isset($_POST[$key]) && trim((string) $_POST[$key]) !== '') {
                $params[$key] = (string) $_POST[$key];
            } elseif (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
                $params[$key] = (string) $_GET[$key];
            }
        }

        return build_query_url($params);
    }
}
