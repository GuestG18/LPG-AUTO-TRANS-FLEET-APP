<?php
declare(strict_types=1);

class ModuleController
{
    private array $modules;
    private ModuleModel $moduleModel;
    private UserModel $userModel;
    private DocumentModel $documentModel;
    private VehicleCouplingModel $vehicleCouplingModel;
    private TireModel $tireModel;
    private EntityStatusService $entityStatusService;

    public function __construct(PDO $db, array $modules)
    {
        $this->modules = $modules;
        $this->moduleModel = new ModuleModel($db);
        $this->userModel = new UserModel($db);
        $this->documentModel = new DocumentModel($db);
        $this->vehicleCouplingModel = new VehicleCouplingModel($db);
        $this->tireModel = new TireModel($db);
        $this->entityStatusService = new EntityStatusService($db);
    }

    public function handle(string $moduleKey, string $action): void
    {
        $module = $this->modules[$moduleKey] ?? null;

        if ($module === null) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Modul inexistent',
                'currentPage' => '',
            ]);
            return;
        }

        if (($module['admin_only'] ?? false) === true) {
            require_admin_or_403();
        }

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction($moduleKey, $module);
                return;
            case 'create':
                $this->createAction($moduleKey, $module);
                return;
            case 'store':
                $this->storeAction($moduleKey, $module);
                return;
            case 'edit':
                $this->editAction($moduleKey, $module);
                return;
            case 'update':
                $this->updateAction($moduleKey, $module);
                return;
            case 'cupleaza':
                $this->attachCouplingAction($moduleKey, $module);
                return;
            case 'decupleaza':
                $this->detachCouplingAction($moduleKey, $module);
                return;
            case 'update_tire_layout':
                $this->updateVehicleTireLayoutAction($moduleKey, $module);
                return;
            case 'add_tire':
                $this->addVehicleTireAction($moduleKey, $module);
                return;
            case 'mount_tire':
                $this->mountVehicleTireAction($moduleKey, $module);
                return;
            case 'unmount_tire':
                $this->unmountVehicleTireAction($moduleKey, $module);
                return;
            case 'add_tire_stock':
                $this->addMaintenanceTireStockAction($moduleKey, $module);
                return;
            case 'bulk_tire_stock':
                $this->bulkMaintenanceTireStockAction($moduleKey, $module);
                return;
            case 'update_tire_stock':
                $this->updateMaintenanceTireStockAction($moduleKey, $module);
                return;
            case 'delete_tire_stock':
                $this->deleteMaintenanceTireStockAction($moduleKey, $module);
                return;
            case 'delete':
                $this->deleteAction($moduleKey, $module);
                return;
            case 'show':
                $this->showAction($moduleKey, $module);
                return;
            case 'preview':
                $this->previewDocumentAction($moduleKey, $module);
                return;
            case 'export':
                $this->exportCsvAction($moduleKey, $module);
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => $moduleKey,
                ]);
                return;
        }
    }

    private function indexAction(string $moduleKey, array $module): void
    {
        if ($moduleKey === 'vehicule') {
            $this->entityStatusService->syncAllVehicleStatuses();
        }

        if ($moduleKey === 'soferi') {
            $this->entityStatusService->syncAllDriverStatuses();
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $filters = $this->collectFilters($module);
        $currentPage = $this->resolveCurrentPage($moduleKey, $module);

        if ($moduleKey === 'mentenanta') {
            try {
                if ($this->tireModel->hasMaintenanceSyncGaps()) {
                    $this->tireModel->syncTireMaintenanceEntries();
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][mentenanta][tire-sync] ' . $exception->getMessage());
            }
        }

        $result = $this->moduleModel->getPaginated($module, $search, $filters, $page, ITEMS_PER_PAGE);

        if ($moduleKey === 'vehicule') {
            $vehicleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $result['rows']);
            $couplingLabels = $this->vehicleCouplingModel->getActiveCouplingLabelsForVehicleIds($vehicleIds);
            $assemblyStatuses = $this->vehicleCouplingModel->getActiveAssemblyStatusByVehicleIds($vehicleIds);

            foreach ($result['rows'] as &$row) {
                $vehicleId = (int) ($row['id'] ?? 0);
                $row['cuplaj_curent'] = $couplingLabels[$vehicleId] ?? '-';
                $row['ansamblu_status'] = $assemblyStatuses[$vehicleId]['status'] ?? null;
            }
            unset($row);
        }

        $viewData = [
            'pageTitle' => $module['title'],
            'currentPage' => $currentPage,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'rows' => $result['rows'],
            'search' => $search,
            'filters' => $filters,
            'filterOptions' => $this->buildFilterOptions($module),
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total_rows' => $result['total_rows'],
                'per_page' => ITEMS_PER_PAGE,
            ],
        ];

        if ($moduleKey === 'documente') {
            $vehicleId = $this->extractVehicleIdFromFilters($filters);
            $viewData['documentSummary'] = $this->documentModel->getNotificationSummary($vehicleId);
            $viewData['urgentDocuments'] = $this->documentModel->getUrgentDocuments($vehicleId, 5);
        }

        if ($moduleKey === 'mentenanta') {
            try {
                $viewData['maintenanceTireStockContext'] = $this->tireModel->buildMaintenanceStockContext();
            } catch (Throwable $exception) {
                error_log('[ModuleController][mentenanta][tire-stock-context] ' . $exception->getMessage());
                flash_set('warning', 'Modulul de stoc anvelope necesita actualizare baza de date. Ruleaza scripturile database/update_tire_stock_target_type.sql si database/update_tire_maintenance_link.sql.');
                $viewData['maintenanceTireStockContext'] = null;
            }
        }

        if ($moduleKey === 'alimentari') {
            $allRows = $this->moduleModel->getAll($module, $search, $filters);
            $viewData['fuelConsumptionSummary'] = $this->buildFuelConsumptionSummary($allRows);
        }

        render('module/list.php', $viewData);
    }

    private function createAction(string $moduleKey, array $module): void
    {
        $formFlash = consume_form_flash();
        $old = $formFlash['old'];
        $errors = $formFlash['errors'];
        $currentPage = $this->resolveCurrentPage($moduleKey, $module);
        $keepDocumentVehicleContext = false;

        $formData = $old !== [] ? $old : $this->defaultFormData($module);
        $backUrl = $this->buildModuleBackUrl($moduleKey, $module, null, $formData);

        if ($moduleKey === 'documente' && $old === []) {
            $prefillVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
            if ($prefillVehicleId > 0 && $this->moduleModel->existsId('vehicule', $prefillVehicleId)) {
                $formData['vehicle_id'] = $prefillVehicleId;
                $backUrl = $this->buildModuleBackUrl($moduleKey, $module, null, $formData);
                $keepDocumentVehicleContext = true;
            }
        }

        if ($moduleKey === 'documente' && !$keepDocumentVehicleContext) {
            $contextVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
            if ($contextVehicleId > 0 && $this->moduleModel->existsId('vehicule', $contextVehicleId)) {
                $keepDocumentVehicleContext = true;
            }
        }

        if ($moduleKey === 'documente_soferi' && $old === []) {
            $prefillDriverId = (int) ($_GET['driver_id'] ?? 0);
            if ($prefillDriverId > 0 && $this->moduleModel->existsId('soferi', $prefillDriverId)) {
                $formData['driver_id'] = $prefillDriverId;
                $backUrl = $this->buildModuleBackUrl($moduleKey, $module, null, $formData);
            }
        }

        $vehicleKmBordById = $moduleKey === 'alimentari'
            ? $this->buildVehicleKmBordMapForAlimentari($module)
            : [];
        $driverVehicleById = $moduleKey === 'alimentari'
            ? $this->buildDriverVehicleMapForAlimentari($module)
            : [];
        $fuelConsumptionSummary = $moduleKey === 'alimentari'
            ? $this->buildFuelConsumptionSummary($this->moduleModel->getAll($module, '', []))
            : null;

        render('module/form.php', [
            'pageTitle' => 'Adauga ' . ucfirst($module['singular']),
            'currentPage' => $currentPage,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'mode' => 'create',
            'recordId' => null,
            'formData' => $formData,
            'errors' => $errors,
            'selectOptions' => $this->buildFormSelectOptions($module),
            'vehicleKmBordById' => $vehicleKmBordById,
            'driverVehicleById' => $driverVehicleById,
            'fuelConsumptionSummary' => $fuelConsumptionSummary,
            'backUrl' => $backUrl,
            'keepDocumentVehicleContext' => $keepDocumentVehicleContext,
        ]);
    }

    private function storeAction(string $moduleKey, array $module): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => $moduleKey]));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => $moduleKey, 'action' => 'create']));

        [$data, $errors] = $this->validateAndPrepareData($module, $_POST, $_FILES, 'create', null);
        if ($moduleKey === 'alimentari') {
            $this->validateAlimentareDriverSelection($data, $errors);
        }
        $keepDocumentVehicleContext = $moduleKey === 'documente'
            && (string) ($_POST['keep_vehicle_context'] ?? '') === '1';
        $documentVehicleId = (int) ($_POST['vehicle_id'] ?? 0);

        $uploadedFileData = null;

        if (in_array($moduleKey, ['documente', 'documente_soferi', 'mentenanta'], true) && $errors === []) {
            [$uploadedFileData, $uploadError] = $this->storeUploadedDocumentFile($_FILES['fisier_upload'] ?? null);

            if ($uploadError !== null) {
                $errors['fisier_upload'] = $uploadError;
            }
        } elseif ($moduleKey === 'vehicule' && $errors === []) {
            [$uploadedFileData, $uploadError] = $this->storeUploadedVehiclePhoto($_FILES['poza_upload'] ?? null);

            if ($uploadError !== null) {
                $errors['poza_upload'] = $uploadError;
            }
        }

        if ($errors !== []) {
            set_form_flash($this->sanitizeOldInput($module, $_POST), $errors);
            $createRoute = ['page' => $moduleKey, 'action' => 'create'];
            if ($moduleKey === 'documente' && $keepDocumentVehicleContext && $documentVehicleId > 0) {
                $createRoute['vehicle_id'] = $documentVehicleId;
            }
            if ($moduleKey === 'documente_soferi') {
                $createRoute['driver_id'] = (int) ($_POST['driver_id'] ?? 0);
            }
            redirect(build_query_url($createRoute));
        }

        if ($uploadedFileData !== null) {
            $data = array_merge($data, $uploadedFileData);
        }

        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        try {
            $recordId = $this->moduleModel->insertRecord($module['table'], $data);
            $currentRecordForStatus = $data + ['id' => $recordId];

            if (in_array($moduleKey, ['documente', 'documente_soferi'], true)) {
                $createdRecord = $this->moduleModel->findById($module, $recordId);
                if ($createdRecord !== null) {
                    $currentRecordForStatus = $createdRecord;
                }

                if ($moduleKey === 'documente' && $createdRecord !== null) {
                    $this->logDocumentAuditSafe(
                        'create',
                        $recordId,
                        $this->buildDocumentAuditDescription('create', $createdRecord),
                        null,
                        $this->documentAuditSnapshot($createdRecord)
                    );
                }
            }

            if ($moduleKey === 'vehicule') {
                $savedVehicle = $this->moduleModel->findById($module, $recordId);
                if ($savedVehicle !== null) {
                    $currentRecordForStatus = $savedVehicle;
                    $this->syncVehicleTireLayoutSafe($savedVehicle);
                }
            }

            $this->syncStatusesAfterMutation($moduleKey, $currentRecordForStatus, null);

            if ($moduleKey === 'vehicule') {
                flash_set('success', 'Vehicul adaugat cu succes. Continua direct cu configurarea anvelopelor.');
            } else {
                flash_set('success', ucfirst($module['singular']) . ' adaugat cu succes.');
            }
        } catch (PDOException $exception) {
            if ($uploadedFileData !== null) {
                if ($moduleKey === 'vehicule') {
                    $this->cleanupUploadedVehiclePhoto($uploadedFileData);
                } else {
                    $this->cleanupUploadedDocumentFile($uploadedFileData);
                }
            }

            error_log(sprintf(
                '[ModuleController][store][%s] SQLSTATE %s: %s',
                $moduleKey,
                (string) $exception->getCode(),
                $exception->getMessage()
            ));

            flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'salvare'));

            set_form_flash($this->sanitizeOldInput($module, $_POST), []);
            $createRoute = ['page' => $moduleKey, 'action' => 'create'];
            if ($moduleKey === 'documente' && $keepDocumentVehicleContext && $documentVehicleId > 0) {
                $createRoute['vehicle_id'] = $documentVehicleId;
            }
            if ($moduleKey === 'documente_soferi') {
                $createRoute['driver_id'] = (int) ($_POST['driver_id'] ?? 0);
            }
            redirect(build_query_url($createRoute));
        }

        if ($moduleKey === 'documente' && $keepDocumentVehicleContext) {
            $savedVehicleId = (int) ($data['vehicle_id'] ?? 0);
            if ($savedVehicleId > 0) {
                redirect(build_query_url([
                    'page' => 'documente',
                    'action' => 'create',
                    'vehicle_id' => $savedVehicleId,
                ]));
            }
        }

        if ($moduleKey === 'vehicule') {
            redirect(build_query_url([
                'page' => 'vehicule',
                'action' => 'show',
                'id' => $recordId,
            ]));
        }

        redirect($this->buildModuleBackUrl($moduleKey, $module, $data + ['id' => $recordId]));
    }

    private function editAction(string $moduleKey, array $module): void
    {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            flash_set('warning', 'ID invalid.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        if ($moduleKey === 'vehicule') {
            $this->entityStatusService->syncVehicleStatus($id);
        }

        if ($moduleKey === 'soferi') {
            $this->entityStatusService->syncDriverStatus($id);
        }

        $record = $this->moduleModel->findById($module, $id);

        if ($record === null) {
            flash_set('warning', 'Inregistrarea nu a fost gasita.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        $formFlash = consume_form_flash();
        $old = $formFlash['old'];
        $errors = $formFlash['errors'];
        $currentPage = $this->resolveCurrentPage($moduleKey, $module);

        $formData = $record;
        if ($old !== []) {
            $formData = array_merge($formData, $old);
        }

        $vehicleKmBordById = $moduleKey === 'alimentari'
            ? $this->buildVehicleKmBordMapForAlimentari($module)
            : [];
        $driverVehicleById = $moduleKey === 'alimentari'
            ? $this->buildDriverVehicleMapForAlimentari($module)
            : [];
        $fuelConsumptionSummary = $moduleKey === 'alimentari'
            ? $this->buildFuelConsumptionSummary($this->moduleModel->getAll($module, '', []))
            : null;

        render('module/form.php', [
            'pageTitle' => 'Editeaza ' . ucfirst($module['singular']),
            'currentPage' => $currentPage,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'mode' => 'edit',
            'recordId' => $id,
            'formData' => $formData,
            'errors' => $errors,
            'selectOptions' => $this->buildFormSelectOptions($module),
            'vehicleKmBordById' => $vehicleKmBordById,
            'driverVehicleById' => $driverVehicleById,
            'fuelConsumptionSummary' => $fuelConsumptionSummary,
            'backUrl' => $this->buildModuleBackUrl($moduleKey, $module, $record, $formData),
        ]);
    }

    private function updateAction(string $moduleKey, array $module): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => $moduleKey]));
        }

        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            flash_set('warning', 'ID invalid.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => $id]));

        $existing = $this->moduleModel->findById($module, $id);
        if ($existing === null) {
            flash_set('warning', 'Inregistrarea nu exista.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        [$data, $errors] = $this->validateAndPrepareData($module, $_POST, $_FILES, 'edit', $id);
        if ($moduleKey === 'alimentari') {
            $this->validateAlimentareDriverSelection($data, $errors);
        }
        if ($moduleKey === 'utilizatori') {
            $this->validateUserSafetyOnUpdate($id, $existing, $data, $errors);
        }

        $uploadedFileData = null;
        $removeExistingFile = false;

        if (in_array($moduleKey, ['documente', 'documente_soferi', 'mentenanta'], true) && $errors === []) {
            [$uploadedFileData, $uploadError] = $this->storeUploadedDocumentFile($_FILES['fisier_upload'] ?? null);

            if ($uploadError !== null) {
                $errors['fisier_upload'] = $uploadError;
            } else {
                $removeExistingFile = isset($_POST['sterge_fisier']) && (string) $_POST['sterge_fisier'] === '1';

                if ($uploadedFileData !== null) {
                    $data = array_merge($data, $uploadedFileData);
                    $removeExistingFile = false;
                } elseif ($removeExistingFile) {
                    $data['fisier_original'] = null;
                    $data['fisier_stocat'] = null;
                }
            }
        } elseif ($moduleKey === 'vehicule' && $errors === []) {
            [$uploadedFileData, $uploadError] = $this->storeUploadedVehiclePhoto($_FILES['poza_upload'] ?? null);

            if ($uploadError !== null) {
                $errors['poza_upload'] = $uploadError;
            } else {
                $removeExistingFile = isset($_POST['sterge_poza']) && (string) $_POST['sterge_poza'] === '1';

                if ($uploadedFileData !== null) {
                    $data = array_merge($data, $uploadedFileData);
                    $removeExistingFile = false;
                } elseif ($removeExistingFile) {
                    $data['poza_original'] = null;
                    $data['poza_stocata'] = null;
                }
            }
        }

        if ($errors !== []) {
            if ($uploadedFileData !== null) {
                if ($moduleKey === 'vehicule') {
                    $this->cleanupUploadedVehiclePhoto($uploadedFileData);
                } else {
                    $this->cleanupUploadedDocumentFile($uploadedFileData);
                }
            }

            set_form_flash($this->sanitizeOldInput($module, $_POST), $errors);
            redirect(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => $id]));
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $this->moduleModel->updateRecord($module['table'], $id, $data);
            $currentRecordForStatus = $data + $existing + ['id' => $id];

            if (in_array($moduleKey, ['documente', 'documente_soferi', 'mentenanta'], true)) {
                if (($uploadedFileData !== null || $removeExistingFile) && !empty($existing['fisier_stocat'])) {
                    $this->deleteDocumentPhysicalFile((string) $existing['fisier_stocat']);
                }

                $updatedRecord = $this->moduleModel->findById($module, $id);
                if ($updatedRecord !== null) {
                    $currentRecordForStatus = $updatedRecord;
                }

                if ($moduleKey === 'documente' && $updatedRecord !== null) {
                    $this->logDocumentAuditSafe(
                        'update',
                        $id,
                        $this->buildDocumentAuditDescription('update', $updatedRecord),
                        $this->documentAuditSnapshot($existing),
                        $this->documentAuditSnapshot($updatedRecord)
                    );
                }
            } elseif ($moduleKey === 'vehicule') {
                if (($uploadedFileData !== null || $removeExistingFile) && !empty($existing['poza_stocata'])) {
                    $this->deleteVehiclePhotoPhysicalFile((string) $existing['poza_stocata']);
                }

                $updatedVehicle = $this->moduleModel->findById($module, $id);
                if ($updatedVehicle !== null) {
                    $currentRecordForStatus = $updatedVehicle;
                    $this->syncVehicleTireLayoutSafe($updatedVehicle);
                } else {
                    $this->syncVehicleTireLayoutSafe($data + $existing + ['id' => $id]);
                }
            }

            $this->syncStatusesAfterMutation($moduleKey, $currentRecordForStatus, $existing);

            flash_set('success', ucfirst($module['singular']) . ' actualizat cu succes.');
        } catch (PDOException $exception) {
            if ($uploadedFileData !== null) {
                if ($moduleKey === 'vehicule') {
                    $this->cleanupUploadedVehiclePhoto($uploadedFileData);
                } else {
                    $this->cleanupUploadedDocumentFile($uploadedFileData);
                }
            }

            error_log(sprintf(
                '[ModuleController][update][%s] SQLSTATE %s: %s',
                $moduleKey,
                (string) $exception->getCode(),
                $exception->getMessage()
            ));

            flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));

            set_form_flash($this->sanitizeOldInput($module, $_POST), []);
            redirect(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => $id]));
        }

        redirect($this->buildModuleBackUrl($moduleKey, $module, $data + ['id' => $id] + $existing));
    }

    private function attachCouplingAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'vehicule' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'vehicule']));

        $tractorId = (int) ($_POST['tractor_id'] ?? 0);
        $trailerId = (int) ($_POST['semiremorca_id'] ?? 0);
        $redirectVehicleId = (int) ($_POST['redirect_vehicle_id'] ?? 0);

        if ($tractorId <= 0 || $trailerId <= 0 || $tractorId === $trailerId) {
            flash_set('danger', 'Selectia pentru cuplaj este invalida.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $redirectVehicleId > 0 ? $redirectVehicleId : $tractorId]));
        }

        $tractor = $this->vehicleCouplingModel->getVehicleById($tractorId);
        $trailer = $this->vehicleCouplingModel->getVehicleById($trailerId);

        if ($tractor === null || $trailer === null) {
            flash_set('danger', 'Vehiculele selectate nu exista.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        if ((string) ($tractor['tip_vehicul'] ?? '') !== 'cap_tractor') {
            flash_set('danger', 'Vehiculul selectat ca tractor nu este de tip cap tractor.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $tractorId]));
        }

        if ((string) ($trailer['tip_vehicul'] ?? '') !== 'semiremorca') {
            flash_set('danger', 'Vehiculul selectat ca semiremorca nu este de tip semiremorca.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $trailerId]));
        }

        if ((string) ($tractor['status'] ?? '') !== 'activ') {
            flash_set('danger', 'Capul tractor selectat este inactiv si nu poate fi folosit pentru cuplaj.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $tractorId]));
        }

        if ((string) ($trailer['status'] ?? '') !== 'activ') {
            flash_set('danger', 'Semiremorca selectata este inactiva si nu poate fi cuplata.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $redirectVehicleId > 0 ? $redirectVehicleId : $tractorId]));
        }

        $currentCoupling = $this->vehicleCouplingModel->getActiveCouplingByTrailer($trailerId);
        if ($currentCoupling !== null
            && (int) ($currentCoupling['tractor_id'] ?? 0) === $tractorId
            && (int) ($currentCoupling['semiremorca_id'] ?? 0) === $trailerId
        ) {
            flash_set('info', 'Semiremorca este deja cuplata la acest tractor.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $redirectVehicleId > 0 ? $redirectVehicleId : $tractorId]));
        }

        if ($currentCoupling !== null) {
            $currentTractorNr = (string) ($currentCoupling['tractor_nr'] ?? '');
            $message = 'Semiremorca selectata este deja cuplata la alt cap tractor.';
            if ($currentTractorNr !== '') {
                $message .= ' Tractor curent: ' . $currentTractorNr . '.';
            }
            $message .= ' Decupleaza mai intai semiremorca, apoi incearca din nou.';
            flash_set('warning', $message);
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $redirectVehicleId > 0 ? $redirectVehicleId : $tractorId]));
        }

        try {
            $this->vehicleCouplingModel->assignTrailerToTractor($tractorId, $trailerId, $this->currentUserId());
            flash_set('success', 'Cuplajul tractor-semiremorca a fost salvat.');
        } catch (PDOException $exception) {
            error_log(sprintf(
                '[ModuleController][cupleaza][vehicule] SQLSTATE %s: %s',
                (string) $exception->getCode(),
                $exception->getMessage()
            ));

            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'actualizare'));
        }

        $targetId = $redirectVehicleId > 0 ? $redirectVehicleId : $tractorId;
        redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $targetId]));
    }

    private function detachCouplingAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'vehicule' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'vehicule']));

        $tractorId = (int) ($_POST['tractor_id'] ?? 0);
        $trailerId = (int) ($_POST['semiremorca_id'] ?? 0);
        $redirectVehicleId = (int) ($_POST['redirect_vehicle_id'] ?? 0);

        if ($tractorId <= 0 && $trailerId <= 0) {
            flash_set('danger', 'Nu ai selectat un cuplaj valid pentru decuplare.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        try {
            $updated = false;

            if ($tractorId > 0) {
                $tractor = $this->vehicleCouplingModel->getVehicleById($tractorId);
                if ($tractor === null || (string) ($tractor['tip_vehicul'] ?? '') !== 'cap_tractor') {
                    flash_set('danger', 'Vehiculul selectat nu este cap tractor.');
                    redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $tractorId]));
                }

                $updated = $this->vehicleCouplingModel->detachByTractor($tractorId);
            } elseif ($trailerId > 0) {
                $trailer = $this->vehicleCouplingModel->getVehicleById($trailerId);
                if ($trailer === null || (string) ($trailer['tip_vehicul'] ?? '') !== 'semiremorca') {
                    flash_set('danger', 'Vehiculul selectat nu este semiremorca.');
                    redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $trailerId]));
                }

                $updated = $this->vehicleCouplingModel->detachByTrailer($trailerId);
            }

            if ($updated) {
                flash_set('success', 'Cuplajul activ a fost decuplat.');
            } else {
                flash_set('info', 'Nu exista un cuplaj activ de decuplat.');
            }
        } catch (PDOException $exception) {
            error_log(sprintf(
                '[ModuleController][decupleaza][vehicule] SQLSTATE %s: %s',
                (string) $exception->getCode(),
                $exception->getMessage()
            ));

            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'actualizare'));
        }

        $targetId = $redirectVehicleId > 0
            ? $redirectVehicleId
            : ($tractorId > 0 ? $tractorId : $trailerId);

        redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $targetId]));
    }

    private function updateVehicleTireLayoutAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'vehicule' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        if ($vehicleId <= 0) {
            flash_set('warning', 'Vehicul invalid.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));

        $vehicle = $this->moduleModel->findById($module, $vehicleId);
        if ($vehicle === null) {
            flash_set('warning', 'Vehiculul nu a fost gasit.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicleType = (string) ($vehicle['tip_vehicul'] ?? 'autovehicul');
        $layoutInput = trim((string) ($_POST['tire_layout_value'] ?? ''));
        $normalizedLayout = $this->tireModel->normalizeLayoutForType($vehicleType, $layoutInput);
        $now = date('Y-m-d H:i:s');

        try {
            $this->tireModel->updateVehicleLayout($vehicleId, $normalizedLayout, $now);
            $this->tireModel->syncVehiclePositions($vehicleId, $vehicleType, $normalizedLayout);
            flash_set('success', 'Configuratia dinamica de anvelope a fost actualizata.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());
            if ($sqlState === '42S02' || str_contains($exceptionMessage, 'anvelope')) {
                flash_set('danger', 'Structura bazei de date pentru modulul de anvelope nu este actualizata. Ruleaza scriptul database/update_vehicle_tire_management.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
    }

    private function addVehicleTireAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'vehicule' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        ensure_csrf_or_redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));

        if ($vehicleId <= 0) {
            flash_set('warning', 'Vehicul invalid.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicle = $this->moduleModel->findById($module, $vehicleId);
        if ($vehicle === null) {
            flash_set('warning', 'Vehiculul nu a fost gasit.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $brand = trim((string) ($_POST['tire_brand'] ?? ''));
        $modelName = trim((string) ($_POST['tire_model'] ?? ''));
        $tireSize = trim((string) ($_POST['tire_size'] ?? ''));
        $dotCode = strtoupper(trim((string) ($_POST['tire_dot_code'] ?? '')));
        $serialNumber = strtoupper(trim((string) ($_POST['tire_serial_number'] ?? '')));
        $mountDate = trim((string) ($_POST['tire_mount_date'] ?? date('Y-m-d')));
        $estimatedLifeKmRaw = trim((string) ($_POST['tire_estimated_life_km'] ?? ''));
        $kmInitialRaw = trim((string) ($_POST['tire_km_initial'] ?? ''));
        $notes = trim((string) ($_POST['tire_notes'] ?? ''));
        $mountPositionId = (int) ($_POST['mount_position_id'] ?? 0);
        $now = date('Y-m-d H:i:s');

        if ($brand === '') {
            flash_set('danger', 'Brandul anvelopei este obligatoriu.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if ($serialNumber === '') {
            flash_set('danger', 'Numarul de serie al anvelopei este obligatoriu.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data montaj nu este valida (format YYYY-MM-DD).');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if ($estimatedLifeKmRaw !== '' && !preg_match('/^\d+$/', $estimatedLifeKmRaw)) {
            flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if ($kmInitialRaw !== '' && !preg_match('/^\d+$/', $kmInitialRaw)) {
            flash_set('danger', 'Km curenti initiali trebuie sa fie numerici.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        $vehicleKmBord = max(0, (int) ($vehicle['km_bord'] ?? 0));
        $kmInitial = $kmInitialRaw !== '' ? (int) $kmInitialRaw : $vehicleKmBord;
        if ($kmInitial === $vehicleKmBord) {
            // Value prefilled from odometer is a mount baseline, not already consumed tire km.
            $kmInitial = 0;
        }
        $targetVehicleType = $this->tireModel->normalizeTargetVehicleType((string) ($vehicle['tip_vehicul'] ?? ''));

        $tireData = [
            'brand' => $brand,
            'model' => $modelName !== '' ? $modelName : null,
            'tire_size' => $tireSize !== '' ? $tireSize : null,
            'dot_code' => $dotCode !== '' ? $dotCode : null,
            'serial_number' => $serialNumber,
            'target_vehicle_type' => $targetVehicleType,
            'mount_date' => $mountDate,
            'km_initial' => $kmInitial,
            'estimated_life_km' => $estimatedLifeKmRaw !== '' ? (int) $estimatedLifeKmRaw : null,
            'tread_depth_mm' => null,
            'min_tread_depth_mm' => 2.0,
            'status' => $mountPositionId > 0 ? TireModel::STATUS_ACTIVE : TireModel::STATUS_SPARE,
            'notes' => $notes !== '' ? $notes : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $newTireId = 0;
        try {
            $newTireId = $this->tireModel->createTire($tireData);

            if ($mountPositionId > 0) {
                $this->tireModel->mountTire(
                    $newTireId,
                    $vehicleId,
                    $mountPositionId,
                    $vehicleKmBord,
                    $mountDate,
                    $now,
                    $this->currentUserId()
                );
            }

            $this->tireModel->syncTireMaintenanceEntries([$newTireId]);

            flash_set('success', $mountPositionId > 0
                ? 'Anvelopa a fost adaugata si montata pe pozitia selectata.'
                : 'Anvelopa a fost adaugata in stoc.');
        } catch (PDOException $exception) {
            if (strtoupper((string) $exception->getCode()) === '23000') {
                flash_set('danger', 'Numarul de serie exista deja in sistem.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'salvare'));
            }
        } catch (Throwable $exception) {
            if ($newTireId > 0 && $mountPositionId > 0) {
                $this->moduleModel->updateRecord('anvelope', $newTireId, [
                    'status' => TireModel::STATUS_SPARE,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
    }

    private function mountVehicleTireAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'vehicule' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        ensure_csrf_or_redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));

        if ($vehicleId <= 0) {
            flash_set('warning', 'Vehicul invalid.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicle = $this->moduleModel->findById($module, $vehicleId);
        if ($vehicle === null) {
            flash_set('warning', 'Vehiculul nu a fost gasit.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $tireId = (int) ($_POST['tire_id'] ?? 0);
        $positionId = (int) ($_POST['position_id'] ?? 0);
        $mountDate = trim((string) ($_POST['mount_date'] ?? date('Y-m-d')));

        if ($tireId <= 0 || $positionId <= 0) {
            flash_set('danger', 'Selecteaza anvelopa si pozitia pentru montaj.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data montaj nu este valida (format YYYY-MM-DD).');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        $tire = $this->tireModel->getTireById($tireId);
        if ($tire === null) {
            flash_set('danger', 'Anvelopa selectata nu exista.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if (!$this->tireModel->isTireCompatibleWithVehicleType($tire, (string) ($vehicle['tip_vehicul'] ?? ''))) {
            flash_set('danger', 'Anvelopa selectata nu este compatibila cu tipul acestui vehicul.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        try {
            $this->tireModel->mountTire(
                $tireId,
                $vehicleId,
                $positionId,
                max(0, (int) ($vehicle['km_bord'] ?? 0)),
                $mountDate,
                date('Y-m-d H:i:s'),
                $this->currentUserId()
            );

            flash_set('success', 'Anvelopa a fost montata cu succes.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
    }

    private function unmountVehicleTireAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'vehicule' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        ensure_csrf_or_redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));

        if ($vehicleId <= 0) {
            flash_set('warning', 'Vehicul invalid.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $vehicle = $this->moduleModel->findById($module, $vehicleId);
        if ($vehicle === null) {
            flash_set('warning', 'Vehiculul nu a fost gasit.');
            redirect(build_query_url(['page' => 'vehicule']));
        }

        $allocationId = (int) ($_POST['allocation_id'] ?? 0);
        $unmountDate = trim((string) ($_POST['unmount_date'] ?? date('Y-m-d')));
        $statusEnd = strtolower(trim((string) ($_POST['status_end'] ?? TireModel::STATUS_SPARE)));

        if ($allocationId <= 0) {
            flash_set('danger', 'Selectia pentru demontare este invalida.');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if (!$this->isValidDate($unmountDate)) {
            flash_set('danger', 'Data demontaj nu este valida (format YYYY-MM-DD).');
            redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
        }

        if (!in_array($statusEnd, [TireModel::STATUS_SPARE, TireModel::STATUS_REMOVED, TireModel::STATUS_DAMAGED, TireModel::STATUS_RETREADED], true)) {
            $statusEnd = TireModel::STATUS_SPARE;
        }

        try {
            $updated = $this->tireModel->unmountTire(
                $allocationId,
                $vehicleId,
                max(0, (int) ($vehicle['km_bord'] ?? 0)),
                $unmountDate,
                $statusEnd,
                date('Y-m-d H:i:s')
            );

            if ($updated) {
                flash_set('success', 'Anvelopa a fost demontata.');
            } else {
                flash_set('info', 'Nu exista o alocare activa pentru demontare.');
            }
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]));
    }

    private function addMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'mentenanta']));

        $brand = trim((string) ($_POST['stock_brand'] ?? ''));
        $modelName = trim((string) ($_POST['stock_model'] ?? ''));
        $tireSize = trim((string) ($_POST['stock_tire_size'] ?? ''));
        $dotCode = strtoupper(trim((string) ($_POST['stock_dot_code'] ?? '')));
        $serialPrefix = trim((string) ($_POST['stock_serial_prefix'] ?? 'STOC'));
        $targetVehicleTypeRaw = (string) ($_POST['stock_target_vehicle_type'] ?? 'universal');
        $mountDate = trim((string) ($_POST['stock_mount_date'] ?? date('Y-m-d')));
        $quantityRaw = trim((string) ($_POST['stock_quantity'] ?? '1'));
        $kmInitialRaw = trim((string) ($_POST['stock_km_initial'] ?? '0'));
        $estimatedLifeRaw = trim((string) ($_POST['stock_estimated_life_km'] ?? ''));
        $statusRaw = strtolower(trim((string) ($_POST['stock_status'] ?? TireModel::STATUS_SPARE)));
        $notes = trim((string) ($_POST['stock_notes'] ?? ''));

        if ($brand === '') {
            flash_set('danger', 'Brandul este obligatoriu pentru adaugarea in stoc.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        if ($quantityRaw === '' || !preg_match('/^\d+$/', $quantityRaw)) {
            flash_set('danger', 'Cantitatea trebuie sa fie un numar intreg pozitiv.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }
        $quantity = max(1, min(1000, (int) $quantityRaw));

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data este invalida. Foloseste formatul YYYY-MM-DD.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        if ($kmInitialRaw === '' || !preg_match('/^\d+$/', $kmInitialRaw)) {
            flash_set('danger', 'Km initial trebuie sa fie numeric.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }
        $kmInitial = max(0, (int) $kmInitialRaw);

        $estimatedLifeKm = null;
        if ($estimatedLifeRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedLifeRaw)) {
                flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
                redirect(build_query_url(['page' => 'mentenanta']));
            }
            $estimatedLifeKm = max(0, (int) $estimatedLifeRaw);
        }

        $allowedStatuses = [TireModel::STATUS_SPARE, TireModel::STATUS_RETREADED, TireModel::STATUS_DAMAGED, TireModel::STATUS_REMOVED];
        $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : TireModel::STATUS_SPARE;
        $targetVehicleType = $this->tireModel->normalizeTargetVehicleType($targetVehicleTypeRaw);

        try {
            $createdTireIds = $this->tireModel->createStockTireBatchWithIds([
                'brand' => $brand,
                'model' => $modelName !== '' ? $modelName : null,
                'tire_size' => $tireSize !== '' ? $tireSize : null,
                'dot_code' => $dotCode !== '' ? $dotCode : null,
                'serial_prefix' => $serialPrefix,
                'target_vehicle_type' => $targetVehicleType,
                'mount_date' => $mountDate,
                'quantity' => $quantity,
                'km_initial' => $kmInitial,
                'estimated_life_km' => $estimatedLifeKm,
                'status' => $status,
                'tread_depth_mm' => null,
                'min_tread_depth_mm' => 2.0,
                'notes' => $notes !== '' ? $notes : null,
                'now' => date('Y-m-d H:i:s'),
            ]);
            $created = count($createdTireIds);
            if ($createdTireIds !== []) {
                $this->tireModel->syncTireMaintenanceEntries($createdTireIds);
            }

            flash_set('success', 'Au fost adaugate in stoc ' . $created . ' anvelope.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'salvare'));
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'mentenanta']));
    }

    private function bulkMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'mentenanta']));

        $brand = trim((string) ($_POST['bulk_brand'] ?? ''));
        $modelName = trim((string) ($_POST['bulk_model'] ?? ''));
        $tireSize = trim((string) ($_POST['bulk_tire_size'] ?? ''));
        $mountDate = trim((string) ($_POST['bulk_mount_date'] ?? date('Y-m-d')));
        $estimatedLifeRaw = trim((string) ($_POST['bulk_estimated_life_km'] ?? ''));
        $statusRaw = strtolower(trim((string) ($_POST['bulk_status'] ?? TireModel::STATUS_SPARE)));
        $serialPrefixBase = trim((string) ($_POST['bulk_serial_prefix'] ?? 'BULK'));
        $notes = trim((string) ($_POST['bulk_notes'] ?? ''));
        $selectedTypesRaw = $_POST['bulk_vehicle_types'] ?? [];
        $spareExtraRaw = $_POST['bulk_spare_extra'] ?? [];

        if ($brand === '') {
            flash_set('danger', 'Brandul este obligatoriu pentru generare bulk.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data este invalida. Foloseste formatul YYYY-MM-DD.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        $estimatedLifeKm = null;
        if ($estimatedLifeRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedLifeRaw)) {
                flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
                redirect(build_query_url(['page' => 'mentenanta']));
            }
            $estimatedLifeKm = max(0, (int) $estimatedLifeRaw);
        }

        $allowedStatuses = [TireModel::STATUS_SPARE, TireModel::STATUS_RETREADED];
        $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : TireModel::STATUS_SPARE;

        $allowedTypes = ['autovehicul', 'camion', 'cap_tractor', 'semiremorca'];
        $selectedTypes = [];
        if (is_array($selectedTypesRaw)) {
            foreach ($selectedTypesRaw as $typeValue) {
                $normalizedType = $this->tireModel->normalizeTargetVehicleType((string) $typeValue);
                if (in_array($normalizedType, $allowedTypes, true)) {
                    $selectedTypes[$normalizedType] = $normalizedType;
                }
            }
        }

        $spareExtra = [];
        if (is_array($spareExtraRaw)) {
            foreach ($spareExtraRaw as $typeKey => $qtyValue) {
                $normalizedType = $this->tireModel->normalizeTargetVehicleType((string) $typeKey);
                if (!in_array($normalizedType, $allowedTypes, true)) {
                    continue;
                }
                $qtyString = trim((string) $qtyValue);
                if ($qtyString === '' || !preg_match('/^\d+$/', $qtyString)) {
                    $spareExtra[$normalizedType] = 0;
                    continue;
                }
                $spareExtra[$normalizedType] = max(0, min(1000, (int) $qtyString));
            }
        }

        try {
            $context = $this->tireModel->buildMaintenanceStockContext();
            $needsByTypeRows = is_array($context['needs_by_type'] ?? null) ? $context['needs_by_type'] : [];
            $needsByType = [];
            foreach ($needsByTypeRows as $row) {
                $type = $this->tireModel->normalizeTargetVehicleType((string) ($row['vehicle_type'] ?? ''));
                if (!in_array($type, $allowedTypes, true)) {
                    continue;
                }
                $needsByType[$type] = $row;
            }

            if ($selectedTypes === []) {
                foreach ($allowedTypes as $type) {
                    $missing = (int) ($needsByType[$type]['missing_tires'] ?? 0);
                    if ($missing > 0) {
                        $selectedTypes[$type] = $type;
                    }
                }
            }

            if ($selectedTypes === []) {
                flash_set('info', 'Nu exista tipuri selectate pentru generare sau nu exista vehicule active cu necesar.');
                redirect(build_query_url(['page' => 'mentenanta']));
            }

            $totalCreated = 0;
            $createdDetails = [];
            $now = date('Y-m-d H:i:s');

            foreach ($selectedTypes as $type) {
                $missing = max(0, (int) ($needsByType[$type]['missing_tires'] ?? 0));
                $extra = max(0, (int) ($spareExtra[$type] ?? 0));
                $quantity = $missing + $extra;
                if ($quantity <= 0) {
                    continue;
                }

                $typeLabel = (string) ($needsByType[$type]['vehicle_type_label'] ?? vehicle_type_label($type));
                $prefix = $serialPrefixBase . '-' . strtoupper(str_replace('_', '', $type));
                $typeNotes = 'Generat bulk pentru ' . $typeLabel . ' | Lipsa curenta: ' . $missing . ' | Rezerve extra: ' . $extra;
                if ($notes !== '') {
                    $typeNotes .= ' | ' . $notes;
                }

                $createdTireIds = $this->tireModel->createStockTireBatchWithIds([
                    'brand' => $brand,
                    'model' => $modelName !== '' ? $modelName : null,
                    'tire_size' => $tireSize !== '' ? $tireSize : null,
                    'dot_code' => null,
                    'serial_prefix' => $prefix,
                    'target_vehicle_type' => $type,
                    'mount_date' => $mountDate,
                    'quantity' => $quantity,
                    'km_initial' => 0,
                    'estimated_life_km' => $estimatedLifeKm,
                    'status' => $status,
                    'tread_depth_mm' => null,
                    'min_tread_depth_mm' => 2.0,
                    'notes' => $typeNotes,
                    'now' => $now,
                ]);
                if ($createdTireIds !== []) {
                    $this->tireModel->syncTireMaintenanceEntries($createdTireIds);
                }
                $created = count($createdTireIds);

                $totalCreated += $created;
                $createdDetails[] = $typeLabel . ': ' . $created;
            }

            if ($totalCreated <= 0) {
                flash_set('info', 'Nu a fost necesara generarea de anvelope in acest moment.');
            } else {
                $suffix = $createdDetails !== [] ? ' (' . implode(', ', $createdDetails) . ')' : '';
                flash_set('success', 'Generare bulk finalizata: ' . $totalCreated . ' anvelope adaugate.' . $suffix);
            }
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'salvare'));
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'mentenanta']));
    }

    private function updateMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'mentenanta']));

        $tireId = (int) ($_POST['stock_tire_id'] ?? 0);
        if ($tireId <= 0) {
            flash_set('danger', 'Anvelopa selectata pentru editare este invalida.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        $tire = $this->tireModel->getStockTireById($tireId);
        if ($tire === null) {
            flash_set('danger', 'Anvelopa selectata nu exista.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        if ((int) ($tire['active_allocation_id'] ?? 0) > 0) {
            flash_set('danger', 'Anvelopa este montata pe vehicul. Editeaza din Detalii Vehicul.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        $brand = trim((string) ($_POST['stock_edit_brand'] ?? ''));
        $modelName = trim((string) ($_POST['stock_edit_model'] ?? ''));
        $tireSize = trim((string) ($_POST['stock_edit_tire_size'] ?? ''));
        $dotCode = strtoupper(trim((string) ($_POST['stock_edit_dot_code'] ?? '')));
        $targetTypeRaw = (string) ($_POST['stock_edit_target_vehicle_type'] ?? 'universal');
        $statusRaw = strtolower(trim((string) ($_POST['stock_edit_status'] ?? TireModel::STATUS_SPARE)));
        $mountDate = trim((string) ($_POST['stock_edit_mount_date'] ?? date('Y-m-d')));
        $estimatedLifeRaw = trim((string) ($_POST['stock_edit_estimated_life_km'] ?? ''));
        $notes = trim((string) ($_POST['stock_edit_notes'] ?? ''));

        if ($brand === '') {
            flash_set('danger', 'Brandul anvelopei este obligatoriu.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data montaj este invalida (format YYYY-MM-DD).');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        $estimatedLifeKm = null;
        if ($estimatedLifeRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedLifeRaw)) {
                flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
                redirect(build_query_url(['page' => 'mentenanta']));
            }
            $estimatedLifeKm = max(0, (int) $estimatedLifeRaw);
        }

        $allowedStatuses = [
            TireModel::STATUS_SPARE,
            TireModel::STATUS_RETREADED,
            TireModel::STATUS_DAMAGED,
            TireModel::STATUS_REMOVED,
            TireModel::STATUS_ACTIVE,
        ];
        $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : TireModel::STATUS_SPARE;
        $targetVehicleType = $this->tireModel->normalizeTargetVehicleType($targetTypeRaw);

        try {
            $this->tireModel->updateStockTire($tireId, [
                'brand' => $brand,
                'model' => $modelName !== '' ? $modelName : null,
                'tire_size' => $tireSize !== '' ? $tireSize : null,
                'dot_code' => $dotCode !== '' ? $dotCode : null,
                'target_vehicle_type' => $targetVehicleType,
                'mount_date' => $mountDate,
                'estimated_life_km' => $estimatedLifeKm,
                'status' => $status,
                'notes' => $notes !== '' ? $notes : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->tireModel->syncTireMaintenanceEntries([$tireId]);

            flash_set('success', 'Datele anvelopei din stoc au fost actualizate.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'actualizare'));
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'mentenanta']));
    }

    private function deleteMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'mentenanta']));

        $tireId = (int) ($_POST['stock_tire_id'] ?? 0);
        if ($tireId <= 0) {
            flash_set('danger', 'Anvelopa selectata pentru stergere este invalida.');
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        try {
            $this->tireModel->deleteStockTire($tireId);
            flash_set('success', 'Anvelopa din stoc a fost stearsa.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'stergere'));
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect(build_query_url(['page' => 'mentenanta']));
    }

    private function buildVehicleCouplingContext(array $vehicle): array
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $vehicleType = (string) ($vehicle['tip_vehicul'] ?? 'autovehicul');

        $activeCoupling = null;
        $currentLabel = '-';

        if ($vehicleType === 'cap_tractor') {
            $activeCoupling = $this->vehicleCouplingModel->getActiveCouplingByTractor($vehicleId);
            if ($activeCoupling !== null) {
                $currentLabel = 'Semiremorca: ' . (string) ($activeCoupling['semiremorca_nr'] ?? '-');
            }
        } elseif ($vehicleType === 'semiremorca') {
            $activeCoupling = $this->vehicleCouplingModel->getActiveCouplingByTrailer($vehicleId);
            if ($activeCoupling !== null) {
                $currentLabel = 'Tractor: ' . (string) ($activeCoupling['tractor_nr'] ?? '-');
            }
        }

        return [
            'vehicle_type' => $vehicleType,
            'active_coupling' => $activeCoupling,
            'current_label' => $currentLabel,
            'tractor_options' => $this->vehicleCouplingModel->getTractorSelectOptions(),
            'trailer_options' => $this->vehicleCouplingModel->getTrailerSelectOptions(),
            'history' => $this->vehicleCouplingModel->getHistoryForVehicle($vehicleId, 10),
        ];
    }

    private function syncVehicleTireLayoutSafe(array $vehicle): void
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        if ($vehicleId <= 0) {
            return;
        }

        $vehicleType = (string) ($vehicle['tip_vehicul'] ?? 'autovehicul');
        $layout = isset($vehicle['formula_axelor']) ? (string) $vehicle['formula_axelor'] : '';

        try {
            $normalizedLayout = $this->tireModel->normalizeLayoutForType($vehicleType, $layout);
            if ($normalizedLayout !== $layout) {
                $this->tireModel->updateVehicleLayout($vehicleId, $normalizedLayout, date('Y-m-d H:i:s'));
                $vehicle['formula_axelor'] = $normalizedLayout;
            }

            $this->tireModel->syncVehiclePositions($vehicleId, $vehicleType, (string) ($vehicle['formula_axelor'] ?? ''));
        } catch (Throwable $exception) {
            error_log('[ModuleController][tires][sync] ' . $exception->getMessage());
        }
    }

    private function buildVehicleTireManagementContext(array $vehicle): array
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $vehicleType = (string) ($vehicle['tip_vehicul'] ?? 'autovehicul');
        $vehicleKmBord = max(0, (int) ($vehicle['km_bord'] ?? 0));
        $layout = isset($vehicle['formula_axelor']) ? (string) $vehicle['formula_axelor'] : '';

        $this->syncVehicleTireLayoutSafe($vehicle);

        $normalizedLayout = $this->tireModel->normalizeLayoutForType($vehicleType, $layout);
        $layoutOptions = $this->tireModel->getLayoutOptionsByVehicleType($vehicleType);
        $context = [
            'layout' => [
                'vehicle_type' => $vehicleType,
                'layout_value' => $normalizedLayout,
                'axle_count' => 0,
                'expected_tires' => 0,
                'mounted_tires' => 0,
                'unmounted_positions' => 0,
            ],
            'positions' => [],
            'alerts' => [],
            'available_tires' => [],
            'history' => [],
        ];

        try {
            $context = $this->tireModel->buildVehicleTireContext($vehicleId, $vehicleKmBord, $vehicleType, $normalizedLayout);
        } catch (Throwable $exception) {
            error_log('[ModuleController][tires][context] ' . $exception->getMessage());
            flash_set('warning', 'Modulul de anvelope necesita actualizare baza de date. Ruleaza scriptul database/update_vehicle_tire_management.sql.');
        }

        return $context + [
            'vehicle_id' => $vehicleId,
            'vehicle_type' => $vehicleType,
            'layout_options' => $layoutOptions,
            'layout_current_value' => $normalizedLayout,
            'today' => date('Y-m-d'),
        ];
    }

    private function deleteAction(string $moduleKey, array $module): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => $moduleKey]));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => $moduleKey]));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'ID invalid pentru stergere.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        $record = $this->moduleModel->findById($module, $id);
        if ($record === null) {
            flash_set('warning', 'Inregistrarea nu exista.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        if ($moduleKey === 'utilizatori' && !$this->validateUserSafetyOnDelete($id, $record)) {
            redirect(build_query_url(['page' => $moduleKey]));
        }

        try {
            $this->moduleModel->deleteRecord($module['table'], $id);

            if (in_array($moduleKey, ['documente', 'documente_soferi', 'mentenanta'], true)) {
                if (!empty($record['fisier_stocat'])) {
                    $this->deleteDocumentPhysicalFile((string) $record['fisier_stocat']);
                }

                if ($moduleKey === 'documente') {
                    $this->logDocumentAuditSafe(
                        'delete',
                        $id,
                        $this->buildDocumentAuditDescription('delete', $record),
                        $this->documentAuditSnapshot($record),
                        null
                    );
                }
            } elseif ($moduleKey === 'vehicule' && !empty($record['poza_stocata'])) {
                $this->deleteVehiclePhotoPhysicalFile((string) $record['poza_stocata']);
            }

            $this->syncStatusesAfterMutation($moduleKey, null, $record);

            flash_set('success', ucfirst($module['singular']) . ' sters cu succes.');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                flash_set('danger', 'Nu poti sterge aceasta inregistrare deoarece este folosita in alte module.');
            } else {
                flash_set('danger', 'A aparut o eroare la stergere.');
            }
        }

        redirect($this->buildModuleBackUrl($moduleKey, $module, $record));
    }

    private function showAction(string $moduleKey, array $module): void
    {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            flash_set('warning', 'ID invalid.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        $record = $this->moduleModel->findById($module, $id);

        if ($record === null) {
            flash_set('warning', 'Inregistrarea nu a fost gasita.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        $currentPage = $this->resolveCurrentPage($moduleKey, $module);
        $viewData = [
            'pageTitle' => 'Detalii ' . ucfirst($module['singular']),
            'currentPage' => $currentPage,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'record' => $record,
            'backUrl' => $this->buildModuleBackUrl($moduleKey, $module, $record),
        ];

        if ($moduleKey === 'documente') {
            $viewData['documentAuditLogs'] = $this->documentModel->getAuditLogsForDocument($id);
        }

        if ($moduleKey === 'vehicule') {
            $syncedStatusContext = $this->entityStatusService->syncVehicleStatus($id);
            $viewData['vehicleDocuments'] = $this->documentModel->getDocumentsForVehicle($id);
            $viewData['statusContext'] = $syncedStatusContext ?? $this->entityStatusService->evaluateVehicleStatus($id);
            if ($syncedStatusContext !== null) {
                $viewData['record']['status'] = $syncedStatusContext['status'];
            }
            $viewData['vehicleCouplingContext'] = $this->buildVehicleCouplingContext($record);
            $viewData['record']['cuplaj_curent'] = (string) ($viewData['vehicleCouplingContext']['current_label'] ?? '-');
            $viewData['vehicleTireContext'] = $this->buildVehicleTireManagementContext($viewData['record']);

            $activeCoupling = $viewData['vehicleCouplingContext']['active_coupling'] ?? null;
            if (is_array($activeCoupling)) {
                $partnerId = (int) (
                    ((int) ($activeCoupling['tractor_id'] ?? 0) === $id)
                        ? ($activeCoupling['semiremorca_id'] ?? 0)
                        : ($activeCoupling['tractor_id'] ?? 0)
                );

                if ($partnerId > 0) {
                    $this->entityStatusService->syncVehicleStatus($partnerId);
                }
            }

            $assemblyStatus = $this->vehicleCouplingModel->getActiveAssemblyStatusForVehicle($id);
            $viewData['record']['ansamblu_status'] = $assemblyStatus['status'] ?? null;
        }

        if ($moduleKey === 'soferi') {
            $viewData['driverDocuments'] = $this->documentModel->getDocumentsForDriver($id);
            $viewData['statusContext'] = $this->entityStatusService->evaluateDriverStatus($id);
        }

        render('module/show.php', $viewData);
    }

    private function previewDocumentAction(string $moduleKey, array $module): void
    {
        if (!in_array($moduleKey, ['documente', 'documente_soferi', 'mentenanta'], true)) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Actiune inexistenta',
                'currentPage' => $moduleKey,
            ]);
            return;
        }

        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            flash_set('warning', 'ID invalid.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        $record = $this->moduleModel->findById($module, $id);

        if ($record === null) {
            flash_set('warning', 'Fisierul nu a fost gasit.');
            redirect(build_query_url(['page' => $moduleKey]));
        }

        render('module/document_preview.php', [
            'pageTitle' => 'Previzualizare document',
            'currentPage' => $this->resolveCurrentPage($moduleKey, $module),
            'moduleKey' => $moduleKey,
            'module' => $module,
            'record' => $record,
            'backUrl' => $this->buildModuleBackUrl($moduleKey, $module, $record),
        ]);
    }

    private function exportCsvAction(string $moduleKey, array $module): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $filters = $this->collectFilters($module);
        $rows = $this->moduleModel->getAll($module, $search, $filters);

        if ($moduleKey === 'vehicule') {
            $vehicleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows);
            $couplingLabels = $this->vehicleCouplingModel->getActiveCouplingLabelsForVehicleIds($vehicleIds);

            foreach ($rows as &$row) {
                $row['cuplaj_curent'] = $couplingLabels[(int) ($row['id'] ?? 0)] ?? '-';
            }
            unset($row);
        }

        $filename = $moduleKey . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'wb');

        if ($output === false) {
            exit;
        }

        $header = [];
        foreach ($module['list_columns'] as $column => $meta) {
            $header[] = $meta['label'];
        }
        fputcsv($output, $header, ';');

        foreach ($rows as $row) {
            $line = [];
            foreach ($module['list_columns'] as $column => $meta) {
                $line[] = $this->formatValueForCsv($row[$column] ?? null, $meta);
            }
            fputcsv($output, $line, ';');
        }

        fclose($output);
        exit;
    }

    private function formatValueForCsv(mixed $value, array $meta): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $type = $meta['type'] ?? 'text';

        return match ($type) {
            'date' => format_date_ro((string) $value),
            'datetime' => format_datetime_ro((string) $value),
            'year' => format_year_ro($value),
            'integer' => number_format((float) $value, 0, ',', '.'),
            'number' => format_number_ro($value, (int) ($meta['decimals'] ?? 2)),
            'currency' => format_number_ro($value, 2) . ' lei',
            'status' => (string) $value,
            'vehicle_type' => vehicle_type_label((string) $value),
            'role' => (string) $value,
            'expiry' => format_date_ro((string) $value),
            default => (string) $value,
        };
    }

    private function collectFilters(array $module): array
    {
        $filters = [];

        foreach ($module['filters'] ?? [] as $key => $meta) {
            $value = $_GET[$key] ?? '';
            if (is_array($value)) {
                $cleanValues = [];
                foreach ($value as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }
                    $cleanValue = trim((string) $item);
                    if ($cleanValue === '') {
                        continue;
                    }
                    $cleanValues[$cleanValue] = $cleanValue;
                }
                $value = array_values($cleanValues);
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    private function buildFilterOptions(array $module): array
    {
        $options = [];

        foreach ($module['filters'] ?? [] as $key => $meta) {
            if (!in_array((string) ($meta['type'] ?? ''), ['select', 'multiselect'], true)) {
                continue;
            }

            if (isset($meta['options'])) {
                $options[$key] = $meta['options'];
                continue;
            }

            if (isset($meta['source'])) {
                $options[$key] = $this->optionsFromSource($meta['source']);
            }
        }

        return $options;
    }

    private function buildFormSelectOptions(array $module): array
    {
        $options = [];

        foreach ($module['form_fields'] as $field => $meta) {
            if (($meta['type'] ?? '') !== 'select') {
                continue;
            }

            if (isset($meta['options'])) {
                $options[$field] = $meta['options'];
                continue;
            }

            if (isset($meta['source'])) {
                $options[$field] = $this->optionsFromSource($meta['source']);
            }
        }

        return $options;
    }

    private function optionsFromSource(array $source): array
    {
        $rows = $this->moduleModel->getSelectOptions($source);
        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['value']] = $row['label'];
        }

        return $options;
    }

    private function buildVehicleKmBordMapForAlimentari(array $module): array
    {
        $vehicleField = $module['form_fields']['vehicle_id'] ?? null;
        if (!is_array($vehicleField)) {
            return [];
        }

        $source = $vehicleField['source'] ?? null;
        if (!is_array($source)) {
            return [];
        }

        $kmSource = $source;
        $kmSource['label'] = 'COALESCE(km_bord, 0)';

        try {
            $rows = $this->moduleModel->getSelectOptions($kmSource);
        } catch (Throwable $exception) {
            error_log('[ModuleController][alimentari][km-bord] ' . $exception->getMessage());
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $vehicleId = isset($row['value']) ? (int) $row['value'] : 0;
            if ($vehicleId <= 0) {
                continue;
            }

            $map[(string) $vehicleId] = max(0, (int) ($row['label'] ?? 0));
        }

        return $map;
    }

    private function buildDriverVehicleMapForAlimentari(array $module): array
    {
        $driverField = $module['form_fields']['driver_id'] ?? null;
        if (!is_array($driverField)) {
            return [];
        }

        $source = $driverField['source'] ?? null;
        if (!is_array($source)) {
            return [];
        }

        $driverSource = $source;
        $driverSource['label'] = 'COALESCE(vehicle_id, 0)';

        try {
            $rows = $this->moduleModel->getSelectOptions($driverSource);
        } catch (Throwable $exception) {
            error_log('[ModuleController][alimentari][driver-vehicle-map] ' . $exception->getMessage());
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $driverId = isset($row['value']) ? (int) $row['value'] : 0;
            if ($driverId <= 0) {
                continue;
            }

            $map[(string) $driverId] = max(0, (int) ($row['label'] ?? 0));
        }

        return $map;
    }

    private function validateAlimentareDriverSelection(array $data, array &$errors): void
    {
        if (!isset($errors['vehicle_id'])) {
            $vehicleId = (int) ($data['vehicle_id'] ?? 0);
            if ($vehicleId > 0 && !$this->moduleModel->isVehicleEligibleForRefuel($vehicleId)) {
                $errors['vehicle_id'] = 'Vehiculul selectat nu este eligibil pentru alimentare (inactiv sau semiremorca).';
            }
        }

        if (isset($errors['vehicle_id']) || isset($errors['driver_id'])) {
            return;
        }

        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $driverId = isset($data['driver_id']) && $data['driver_id'] !== null && $data['driver_id'] !== ''
            ? (int) $data['driver_id']
            : 0;

        if ($vehicleId <= 0 || $driverId <= 0) {
            return;
        }

        if (!$this->moduleModel->isDriverAssignedToVehicle($driverId, $vehicleId, true)) {
            $errors['driver_id'] = 'Soferul selectat nu este alocat vehiculului ales.';
        }
    }

    private function defaultFormData(array $module): array
    {
        $data = [];

        foreach ($module['form_fields'] as $field => $meta) {
            $data[$field] = $meta['default'] ?? '';
        }

        return $data;
    }

    private function sanitizeOldInput(array $module, array $input): array
    {
        $old = [];

        foreach ($module['form_fields'] as $field => $meta) {
            $type = $meta['type'] ?? 'text';

            if ($type === 'password' || $type === 'file') {
                continue;
            }

            $value = $input[$field] ?? '';
            if (is_string($value)) {
                $value = trim($value);
            }

            $old[$field] = $value;
        }

        foreach ($module['form_fields'] as $meta) {
            if (($meta['type'] ?? 'text') !== 'file') {
                continue;
            }

            $removeField = $meta['remove_field'] ?? 'sterge_fisier';
            if (isset($input[$removeField])) {
                $old[$removeField] = (string) $input[$removeField];
            }
        }

        return $old;
    }

    private function validateAndPrepareData(array $module, array $input, array $files, string $mode, ?int $recordId): array
    {
        $data = [];
        $errors = [];
        $rawValues = [];

        foreach ($module['form_fields'] as $field => $meta) {
            $type = $meta['type'] ?? 'text';
            $store = $meta['store'] ?? true;
            $column = $meta['column'] ?? $field;

            $required = (bool) ($meta['required'] ?? false);
            if (isset($meta['required_on'])) {
                $required = $meta['required_on'] === $mode;
            }

            if ($type === 'file') {
                $file = $files[$field] ?? null;
                $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    if ($required) {
                        $errors[$field] = 'Camp obligatoriu.';
                    }

                    continue;
                }

                if ($uploadError !== UPLOAD_ERR_OK) {
                    $errors[$field] = $this->uploadErrorMessage($uploadError);
                    continue;
                }

                $originalName = (string) ($file['name'] ?? '');
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedExtensions = array_map('strtolower', $meta['allowed_extensions'] ?? []);

                if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
                    $errors[$field] = 'Format de fisier invalid.';
                    continue;
                }

                $maxSize = (int) ($meta['max_size'] ?? 0);
                $fileSize = (int) ($file['size'] ?? 0);

                if ($maxSize > 0 && $fileSize > $maxSize) {
                    $errors[$field] = 'Fisierul depaseste dimensiunea maxima permisa.';
                }

                continue;
            }

            $value = $input[$field] ?? '';
            if (is_string($value)) {
                $value = trim($value);

                if (($meta['remove_spaces'] ?? false) === true) {
                    $value = preg_replace('/\s+/', '', $value) ?? $value;
                }

                if (($meta['uppercase'] ?? false) === true) {
                    $value = strtoupper($value);
                }
            }
            $rawValues[$field] = $value;

            $isEmpty = ($value === '' || $value === null);
            if ($isEmpty) {
                if ($required) {
                    $errors[$field] = 'Camp obligatoriu.';
                }

                if ($store && !($type === 'password' && $mode === 'edit')) {
                    $data[$column] = ($meta['nullable'] ?? false) ? null : '';
                }

                continue;
            }

            if ($type === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = 'Adresa de email nu este valida.';
                continue;
            }

            if ($type === 'date' && !$this->isValidDate((string) $value)) {
                $errors[$field] = 'Data nu este valida. Format acceptat: YYYY-MM-DD.';
                continue;
            }

            if ($type === 'number') {
                $normalized = str_replace(',', '.', (string) $value);
                if (!is_numeric($normalized)) {
                    $errors[$field] = 'Introdu o valoare numerica valida.';
                    continue;
                }

                $numericValue = (float) $normalized;

                if (isset($meta['min']) && $numericValue < (float) $meta['min']) {
                    $errors[$field] = 'Valoarea minima este ' . $meta['min'] . '.';
                    continue;
                }

                if (isset($meta['max']) && $numericValue > (float) $meta['max']) {
                    $errors[$field] = 'Valoarea maxima este ' . $meta['max'] . '.';
                    continue;
                }

                if (($meta['integer'] ?? false) === true) {
                    $value = (int) round($numericValue);
                } else {
                    $value = $numericValue;
                }
            }

            if ($type === 'select') {
                if (isset($meta['options']) && !array_key_exists((string) $value, $meta['options'])) {
                    $errors[$field] = 'Valoare selectata invalida.';
                    continue;
                }

                if (isset($meta['source']) && $value !== '') {
                    if (!is_numeric((string) $value)) {
                        $errors[$field] = 'Selectia nu este valida.';
                        continue;
                    }

                    $idValue = (int) $value;
                    if (!$this->moduleModel->existsId($meta['source']['table'], $idValue)) {
                        $errors[$field] = 'Inregistrarea selectata nu exista.';
                        continue;
                    }

                    $value = $idValue;
                }
            }

            if ($type === 'password') {
                $minLength = (int) ($meta['minlength'] ?? 8);
                if (strlen((string) $value) < $minLength) {
                    $errors[$field] = 'Parola trebuie sa aiba cel putin ' . $minLength . ' caractere.';
                    continue;
                }

                if (($meta['hash'] ?? false) === true) {
                    $value = password_hash((string) $value, PASSWORD_DEFAULT);
                }
            }

            if (isset($meta['maxlength']) && is_string($value) && strlen($value) > (int) $meta['maxlength']) {
                $errors[$field] = 'Textul este prea lung (maxim ' . $meta['maxlength'] . ' caractere).';
                continue;
            }

            if (isset($meta['minlength']) && is_string($value) && strlen($value) < (int) $meta['minlength']) {
                $errors[$field] = 'Textul trebuie sa aiba cel putin ' . $meta['minlength'] . ' caractere.';
                continue;
            }

            if (isset($meta['exactlength']) && is_string($value) && strlen($value) !== (int) $meta['exactlength']) {
                $errors[$field] = 'Valoarea trebuie sa aiba exact ' . $meta['exactlength'] . ' caractere.';
                continue;
            }

            if (isset($meta['matches'])) {
                $targetField = $meta['matches'];
                if (($rawValues[$targetField] ?? null) !== $value) {
                    $errors[$field] = 'Campul nu coincide cu parola.';
                    continue;
                }
            }

            if ($store) {
                $data[$column] = $value;
            }
        }

        foreach ($module['unique_fields'] ?? [] as $rule) {
            if (is_array($rule)) {
                $field = $rule['field'];
                $column = $rule['column'] ?? $field;
            } else {
                $field = (string) $rule;
                $column = (string) $rule;
            }

            if (isset($errors[$field])) {
                continue;
            }

            $value = $data[$column] ?? ($rawValues[$field] ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            if ($this->moduleModel->existsValue($module['table'], $column, $value, $recordId)) {
                $errors[$field] = 'Valoarea exista deja in sistem.';
            }
        }

        return [$data, $errors];
    }

    private function isValidDate(string $date): bool
    {
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);

        return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
    }

    private function validateUserSafetyOnUpdate(int $id, array $existing, array $data, array &$errors): void
    {
        $currentUserId = (int) (current_user()['id'] ?? 0);
        $newRole = $data['rol'] ?? $existing['rol'];
        $newStatus = $data['status'] ?? $existing['status'];

        if ($id === $currentUserId && $newStatus !== 'activ') {
            $errors['status'] = 'Nu iti poti dezactiva propriul cont.';
        }

        $wasActiveAdmin = $existing['rol'] === 'admin' && $existing['status'] === 'activ';
        $willRemainActiveAdmin = $newRole === 'admin' && $newStatus === 'activ';

        if ($wasActiveAdmin && !$willRemainActiveAdmin && $this->userModel->countActiveAdminsExcept($id) === 0) {
            $errors['rol'] = 'Trebuie sa existe cel putin un administrator activ in sistem.';
        }
    }

    private function buildPersistenceErrorMessage(string $moduleKey, PDOException $exception, string $operation): string
    {
        $sqlState = strtoupper((string) $exception->getCode());
        $exceptionMessage = strtolower($exception->getMessage());

        if ($sqlState === '23000') {
            return $operation === 'actualizare'
                ? 'Nu s-a putut actualiza inregistrarea. Exista deja o valoare unica identica.'
                : 'Nu s-a putut salva inregistrarea. Verifica daca exista deja aceeasi valoare unica.';
        }

        if ($moduleKey === 'mentenanta' && ($sqlState === '42S22' || str_contains($exceptionMessage, 'unknown column'))) {
            return 'Structura bazei de date pentru Mentenanta nu este actualizata. Ruleaza scriptul database/update_mentenanta_invoice_and_suppliers.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'alimentari'
            && (
                str_contains($exceptionMessage, 'km_alimentare')
                || (($sqlState === '42S22' || str_contains($exceptionMessage, 'unknown column')) && str_contains($exceptionMessage, 'alimentari'))
            )
        ) {
            return 'Structura bazei de date pentru campul Km alimentare nu este actualizata. Ruleaza scriptul database/update_alimentari_km_alimentare.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'km_revizie')
            )
        ) {
            return 'Structura bazei de date pentru campul Km revizie nu este actualizata. Ruleaza scriptul database/update_vehicle_km_revizie.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'km_bord')
                || (str_contains($exceptionMessage, 'tip_vehicul') && str_contains($exceptionMessage, 'data truncated'))
                || (str_contains($exceptionMessage, 'tip_vehicul') && str_contains($exceptionMessage, 'incorrect'))
            )
        ) {
            return 'Structura bazei de date pentru tipul CAMION si campul Km bord nu este actualizata. Ruleaza scriptul database/update_vehicle_camion_km.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'garaj')
            )
        ) {
            return 'Structura bazei de date pentru campul Garaj nu este actualizata. Ruleaza scriptul database/update_vehicle_garaj.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'nr_fabricatie')
                || str_contains($exceptionMessage, 'capacitate_transport')
                || str_contains($exceptionMessage, 'formula_axelor')
                || str_contains($exceptionMessage, 'capacitate_rezervor')
                || str_contains($exceptionMessage, 'mma')
                || str_contains($exceptionMessage, 'organism_notificat')
            )
        ) {
            return 'Structura bazei de date pentru detalii suplimentare vehicul nu este actualizata. Ruleaza scriptul database/update_vehicle_additional_details.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'mentenanta_id')
            )
        ) {
            return 'Structura bazei de date pentru legatura anvelope-mentenanta nu este actualizata. Ruleaza scriptul database/update_tire_maintenance_link.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'target_vehicle_type')
            )
        ) {
            return 'Structura bazei de date pentru compatibilitatea stocului de anvelope nu este actualizata. Ruleaza scriptul database/update_tire_stock_target_type.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && (
                str_contains($exceptionMessage, 'anvelope')
                || str_contains($exceptionMessage, 'tire_')
                || str_contains($exceptionMessage, 'vehicule_anvelope_pozitii')
                || str_contains($exceptionMessage, 'anvelope_alocari')
            )
        ) {
            return 'Structura bazei de date pentru modulul de anvelope nu este actualizata. Ruleaza scriptul database/update_vehicle_tire_management.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'vehicule'
            && ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'unknown column')
                || str_contains($exceptionMessage, 'vehicule_cuplaje'))
        ) {
            return 'Structura bazei de date pentru cuplaje vehicule nu este actualizata. Ruleaza scriptul database/update_vehicle_tractor_trailer_links.sql, apoi incearca din nou.';
        }

        $generic = $operation === 'actualizare'
            ? 'A aparut o eroare la actualizare.'
            : 'A aparut o eroare la salvare.';

        if (APP_DEBUG) {
            return $generic . ' Detalii: ' . $exception->getMessage();
        }

        return $generic;
    }

    private function validateUserSafetyOnDelete(int $id, array $record): bool
    {
        $currentUserId = (int) (current_user()['id'] ?? 0);

        if ($id === $currentUserId) {
            flash_set('danger', 'Nu iti poti sterge propriul cont.');
            return false;
        }

        $isActiveAdmin = $record['rol'] === 'admin' && $record['status'] === 'activ';
        if ($isActiveAdmin && $this->userModel->countActiveAdminsExcept($id) === 0) {
            flash_set('danger', 'Nu poti sterge ultimul administrator activ.');
            return false;
        }

        return true;
    }

    private function extractVehicleIdFromFilters(array $filters): ?int
    {
        $vehicleId = $filters['vehicle_id'] ?? null;

        if (is_string($vehicleId)) {
            $vehicleId = trim($vehicleId);
        }

        if ($vehicleId === null || $vehicleId === '' || !is_numeric((string) $vehicleId)) {
            return null;
        }

        $vehicleId = (int) $vehicleId;

        return $vehicleId > 0 ? $vehicleId : null;
    }

    private function buildFuelConsumptionSummary(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $grouped[$vehicleId][] = $row;
        }

        if ($grouped === []) {
            return null;
        }

        $totalDistanceKm = 0.0;
        $totalFuelLiters = 0.0;
        $intervalCount = 0;
        $vehicleCount = 0;

        foreach ($grouped as $vehicleRows) {
            usort($vehicleRows, static function (array $a, array $b): int {
                $dateA = (string) ($a['data_alimentare'] ?? '');
                $dateB = (string) ($b['data_alimentare'] ?? '');
                if ($dateA !== $dateB) {
                    return strcmp($dateA, $dateB);
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });

            $prevKm = null;
            $hasValidIntervalForVehicle = false;

            foreach ($vehicleRows as $row) {
                $currentKmRaw = $row['km_alimentare'] ?? null;
                if ($currentKmRaw === null || $currentKmRaw === '' || !is_numeric((string) $currentKmRaw)) {
                    continue;
                }

                $currentKm = (float) $currentKmRaw;
                if ($prevKm === null) {
                    $prevKm = $currentKm;
                    continue;
                }

                $distanceKm = $currentKm - $prevKm;
                $prevKm = $currentKm;
                if ($distanceKm <= 0) {
                    continue;
                }

                $litersRaw = $row['litri'] ?? null;
                if ($litersRaw === null || $litersRaw === '' || !is_numeric((string) $litersRaw)) {
                    continue;
                }

                $liters = (float) $litersRaw;
                if ($liters < 0) {
                    continue;
                }

                $totalDistanceKm += $distanceKm;
                $totalFuelLiters += $liters;
                $intervalCount++;
                $hasValidIntervalForVehicle = true;
            }

            if ($hasValidIntervalForVehicle) {
                $vehicleCount++;
            }
        }

        if ($totalDistanceKm <= 0 || $intervalCount === 0) {
            return null;
        }

        return [
            'average_l_per_100km' => round(($totalFuelLiters / $totalDistanceKm) * 100, 2),
            'total_distance_km' => round($totalDistanceKm, 2),
            'total_fuel_liters' => round($totalFuelLiters, 2),
            'interval_count' => $intervalCount,
            'vehicle_count' => $vehicleCount,
        ];
    }

    private function storeUploadedDocumentFile(?array $file): array
    {
        return $this->storeUploadedAssetFile($file, 'documente', 'document', 'fisier_original', 'fisier_stocat');
    }

    private function storeUploadedVehiclePhoto(?array $file): array
    {
        return $this->storeUploadedAssetFile($file, 'vehicule', 'vehicul', 'poza_original', 'poza_stocata');
    }

    private function sanitizeUploadedFileName(string $fileName): string
    {
        $fileName = preg_replace('/\s+/', '_', trim($fileName)) ?? 'document';
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '', $fileName) ?? 'document';

        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return 'document';
        }

        return substr($fileName, 0, 255);
    }

    private function cleanupUploadedDocumentFile(array $uploadedFileData): void
    {
        $storedFile = $uploadedFileData['fisier_stocat'] ?? null;

        if (!is_string($storedFile) || trim($storedFile) === '') {
            return;
        }

        $this->deleteDocumentPhysicalFile($storedFile);
    }

    private function cleanupUploadedVehiclePhoto(array $uploadedFileData): void
    {
        $storedFile = $uploadedFileData['poza_stocata'] ?? null;

        if (!is_string($storedFile) || trim($storedFile) === '') {
            return;
        }

        $this->deleteVehiclePhotoPhysicalFile($storedFile);
    }

    private function deleteDocumentPhysicalFile(string $storedFile): void
    {
        if (trim($storedFile) === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/documente/' . $storedFile;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function deleteVehiclePhotoPhysicalFile(string $storedFile): void
    {
        if (trim($storedFile) === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/vehicule/' . $storedFile;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function storeUploadedAssetFile(?array $file, string $directory, string $prefix, string $originalColumn, string $storedColumn): array
    {
        if ($file === null || !is_array($file)) {
            return [null, null];
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ($uploadError !== UPLOAD_ERR_OK) {
            return [null, $this->uploadErrorMessage($uploadError)];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [null, 'Fisierul incarcat nu este valid.'];
        }

        $originalName = $this->sanitizeUploadedFileName((string) ($file['name'] ?? $prefix));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $uploadDir = BASE_PATH . '/uploads/' . $directory;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [null, 'Nu s-a putut crea folderul pentru fisiere.'];
        }

        try {
            $storedName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            $storedName = $prefix . '_' . date('Ymd_His') . '_' . uniqid('', true);
        }

        if ($extension !== '') {
            $storedName .= '.' . $extension;
        }

        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $destination)) {
            return [null, 'Fisierul nu a putut fi salvat pe server.'];
        }

        return [[
            $originalColumn => $originalName,
            $storedColumn => $storedName,
        ], null];
    }

    private function uploadErrorMessage(int $uploadError): string
    {
        return match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fisierul este prea mare.',
            UPLOAD_ERR_PARTIAL => 'Fisierul a fost incarcat partial.',
            UPLOAD_ERR_NO_TMP_DIR => 'Lipseste folderul temporar pentru upload.',
            UPLOAD_ERR_CANT_WRITE => 'Fisierul nu a putut fi scris pe disc.',
            UPLOAD_ERR_EXTENSION => 'Upload-ul a fost blocat de configuratia serverului.',
            default => 'Fisierul nu a putut fi incarcat.',
        };
    }

    private function documentAuditSnapshot(array $record): array
    {
        return [
            'vehicul' => $record['vehicul_label'] ?? null,
            'tip_document' => $record['tip_document'] ?? null,
            'numar_document' => $record['numar_document'] ?? null,
            'data_expirare' => $record['data_expirare'] ?? null,
            'fisier_original' => $record['fisier_original'] ?? null,
        ];
    }

    private function buildDocumentAuditDescription(string $action, array $record): string
    {
        $tipDocument = (string) ($record['tip_document'] ?? 'Document');
        $numarDocument = (string) ($record['numar_document'] ?? '-');
        $vehicul = (string) ($record['vehicul_label'] ?? 'vehicul necunoscut');

        return match ($action) {
            'create' => 'Document creat: ' . $tipDocument . ' (' . $numarDocument . ') pentru ' . $vehicul,
            'update' => 'Document actualizat: ' . $tipDocument . ' (' . $numarDocument . ') pentru ' . $vehicul,
            'delete' => 'Document sters: ' . $tipDocument . ' (' . $numarDocument . ') pentru ' . $vehicul,
            default => 'Actiune pe document: ' . $tipDocument . ' (' . $numarDocument . ')',
        };
    }

    private function logDocumentAuditSafe(string $action, int $recordId, string $description, ?array $beforeData, ?array $afterData): void
    {
        try {
            $this->documentModel->logAudit(
                'documente',
                $recordId,
                $action,
                $description,
                $this->currentUserId(),
                $beforeData,
                $afterData
            );
        } catch (Throwable $exception) {
            error_log('Audit log documente: ' . $exception->getMessage());
        }
    }

    private function currentUserId(): ?int
    {
        $userId = current_user()['id'] ?? null;

        if (!is_int($userId) && !is_numeric((string) $userId)) {
            return null;
        }

        $userId = (int) $userId;

        return $userId > 0 ? $userId : null;
    }

    private function resolveCurrentPage(string $moduleKey, array $module): string
    {
        return (string) ($module['nav_parent'] ?? $moduleKey);
    }

    private function buildModuleBackUrl(string $moduleKey, array $module, ?array $record = null, ?array $formData = null): string
    {
        if ($moduleKey === 'documente_soferi') {
            $driverId = (int) ($record['driver_id'] ?? $formData['driver_id'] ?? $_GET['driver_id'] ?? 0);

            if ($driverId > 0) {
                return build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => $driverId]);
            }
        }

        if ($moduleKey === 'documente') {
            $vehicleId = (int) ($record['vehicle_id'] ?? $formData['vehicle_id'] ?? $_GET['vehicle_id'] ?? 0);

            if ($vehicleId > 0 && isset($_GET['vehicle_id'])) {
                return build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId]);
            }
        }

        return build_query_url(['page' => $moduleKey]);
    }

    private function syncStatusesAfterMutation(string $moduleKey, ?array $currentRecord, ?array $previousRecord): void
    {
        if ($moduleKey === 'vehicule') {
            $vehicleId = (int) ($currentRecord['id'] ?? $previousRecord['id'] ?? 0);
            if ($vehicleId > 0) {
                $this->entityStatusService->syncVehicleStatus($vehicleId);
            }
            return;
        }

        if ($moduleKey === 'soferi') {
            $driverId = (int) ($currentRecord['id'] ?? $previousRecord['id'] ?? 0);
            if ($driverId > 0) {
                $this->entityStatusService->syncDriverStatus($driverId);
            }
            return;
        }

        if ($moduleKey === 'documente') {
            $vehicleIds = array_unique(array_filter([
                (int) ($previousRecord['vehicle_id'] ?? 0),
                (int) ($currentRecord['vehicle_id'] ?? 0),
            ]));

            foreach ($vehicleIds as $vehicleId) {
                $this->entityStatusService->syncVehicleStatus((int) $vehicleId);
            }

            return;
        }

        if ($moduleKey === 'documente_soferi') {
            $driverIds = array_unique(array_filter([
                (int) ($previousRecord['driver_id'] ?? 0),
                (int) ($currentRecord['driver_id'] ?? 0),
            ]));

            foreach ($driverIds as $driverId) {
                $this->entityStatusService->syncDriverStatus((int) $driverId);
            }
        }
    }
}
