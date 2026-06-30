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
            case 'tire_stock':
                $this->maintenanceTireStockAction($moduleKey, $module);
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
            case 'move_tire':
                $this->moveMaintenanceTireAction($moduleKey, $module);
                return;
            case 'change_tire_status':
                $this->changeTireStatusAction($moduleKey, $module);
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
            case 'add_document_type_config':
                $this->addDocumentTypeConfigAction($moduleKey, $module);
                return;
            case 'manage_document_type_config':
                $this->manageDocumentTypeConfigAction($moduleKey, $module);
                return;
            case 'update_document_type_expiry':
                $this->updateDocumentTypeExpiryAction($moduleKey, $module);
                return;
            case 'delete_document_type_config':
                $this->deleteDocumentTypeConfigAction($moduleKey, $module);
                return;
            case 'add_document_custom_field_config':
                $this->addDocumentCustomFieldConfigAction($moduleKey, $module);
                return;
            case 'delete_document_custom_field_config':
                $this->deleteDocumentCustomFieldConfigAction($moduleKey, $module);
                return;
            case 'add_driver_document_type_config':
                $this->addDriverDocumentTypeConfigAction($moduleKey, $module);
                return;
            case 'manage_driver_document_type_config':
                $this->manageDriverDocumentTypeConfigAction($moduleKey, $module);
                return;
            case 'update_driver_document_type_expiry':
                $this->updateDriverDocumentTypeExpiryAction($moduleKey, $module);
                return;
            case 'delete_driver_document_type_config':
                $this->deleteDriverDocumentTypeConfigAction($moduleKey, $module);
                return;
            case 'add_driver_document_custom_field_config':
                $this->addDriverDocumentCustomFieldConfigAction($moduleKey, $module);
                return;
            case 'delete_driver_document_custom_field_config':
                $this->deleteDriverDocumentCustomFieldConfigAction($moduleKey, $module);
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

            $driverDocumentModule = $this->modules['documente_soferi'] ?? null;
            if (is_array($driverDocumentModule)) {
                $driverDocumentModule = $this->buildDocumentPageDriverModule($driverDocumentModule);
                $driverDocumentFilters = $this->collectFilters($driverDocumentModule);
                $driverDocumentPage = max(1, (int) ($_GET['driver_p'] ?? 1));
                $driverDocumentResult = $this->moduleModel->getPaginated($driverDocumentModule, $search, $driverDocumentFilters, $driverDocumentPage, ITEMS_PER_PAGE);

                $viewData['driverDocumentModule'] = $driverDocumentModule;
                $viewData['driverDocumentRows'] = $driverDocumentResult['rows'];
                $viewData['driverDocumentPagination'] = [
                    'page' => $driverDocumentResult['page'],
                    'total_pages' => $driverDocumentResult['total_pages'],
                    'total_rows' => $driverDocumentResult['total_rows'],
                    'per_page' => ITEMS_PER_PAGE,
                ];
            }
        }

        if ($moduleKey === 'alimentari') {
            $allRows = $this->moduleModel->getAll($module, $search, $filters);
            $viewData['fuelConsumptionSummary'] = $this->buildFuelConsumptionSummary($allRows);
        }

        if ($moduleKey === 'configurare_costuri_documente_vehicule_override') {
            $viewData['documentTypeVehicleOptions'] = $this->getDocumentTypeVehicleOptions();
            $driverCostModule = $this->modules['configurare_costuri_documente_soferi'] ?? null;
            if (is_array($driverCostModule)) {
                try {
                    $driverCostModule = $this->buildDocumentCostPageDriverModule($driverCostModule);
                    $driverCostFilters = $this->collectFilters($driverCostModule);
                    $driverCostPage = max(1, (int) ($_GET['driver_cost_p'] ?? 1));
                    $driverCostResult = $this->moduleModel->getPaginated($driverCostModule, $search, $driverCostFilters, $driverCostPage, ITEMS_PER_PAGE);

                    $viewData['driverDocumentCostModule'] = $driverCostModule;
                    $viewData['driverDocumentCostRows'] = $this->applyRemainingValidityDaysToDriverCostRows(
                        $driverCostResult['rows']
                    );
                    $viewData['driverDocumentCostPagination'] = [
                        'page' => $driverCostResult['page'],
                        'total_pages' => $driverCostResult['total_pages'],
                        'total_rows' => $driverCostResult['total_rows'],
                        'per_page' => ITEMS_PER_PAGE,
                    ];
                } catch (Throwable $exception) {
                    error_log('[ModuleController][driver-doc-cost-list] ' . $exception->getMessage());
                    $viewData['driverDocumentCostModule'] = $driverCostModule;
                    $viewData['driverDocumentCostRows'] = [];
                    $viewData['driverDocumentCostPagination'] = [
                        'page' => 1,
                        'total_pages' => 1,
                        'total_rows' => 0,
                        'per_page' => ITEMS_PER_PAGE,
                    ];
                    flash_set('warning', 'Configurarea costurilor pentru documentele soferilor necesita actualizare baza de date. Ruleaza scriptul database/update_configurare_costuri_documente_soferi.sql.');
                }
            }
        }

        render('module/list.php', $viewData);
    }

    private function maintenanceTireStockAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'mentenanta') {
            redirect(build_query_url(['page' => 'mentenanta']));
        }

        try {
            if ($this->tireModel->hasMaintenanceSyncGaps()) {
                $this->tireModel->syncTireMaintenanceEntries();
            }
        } catch (Throwable $exception) {
            error_log('[ModuleController][mentenanta][tire-stock-sync] ' . $exception->getMessage());
        }

        $stockContext = null;
        try {
            $stockContext = $this->tireModel->buildMaintenanceStockContext($this->collectTireStockFilters());
        } catch (Throwable $exception) {
            error_log('[ModuleController][mentenanta][tire-stock-context] ' . $exception->getMessage());
            flash_set('warning', 'Modulul de stoc anvelope necesita actualizare baza de date. Ruleaza scripturile database/update_tire_stock_target_type.sql si database/update_tire_maintenance_link.sql.');
        }

        $stockModule = $module;
        $stockModule['title'] = 'Anvelope';

        render('module/list.php', [
            'pageTitle' => 'Anvelope',
            'currentPage' => $this->resolveCurrentPage($moduleKey, $module),
            'moduleKey' => $moduleKey,
            'module' => $stockModule,
            'rows' => [],
            'search' => '',
            'filters' => [],
            'filterOptions' => [],
            'pagination' => [
                'page' => 1,
                'total_pages' => 1,
                'total_rows' => 0,
                'per_page' => ITEMS_PER_PAGE,
            ],
            'maintenanceTireStockContext' => $stockContext,
            'isMaintenanceTireStockPage' => true,
        ]);
    }

    private function createAction(string $moduleKey, array $module): void
    {
        $formFlash = consume_form_flash();
        $old = $formFlash['old'];
        $errors = $formFlash['errors'];
        $currentPage = $this->resolveCurrentPage($moduleKey, $module);
        $keepDocumentVehicleContext = false;
        $vehicleDocumentCustomFieldErrors = [];
        $driverDocumentCustomFieldErrors = [];

        $formData = $old !== [] ? $old : $this->defaultFormData($module);
        $backUrl = $this->buildModuleBackUrl($moduleKey, $module, null, $formData);

        if ($moduleKey === 'documente') {
            $vehicleDocumentCustomFieldErrors = is_array($errors['_vehicle_custom_fields'] ?? null)
                ? $errors['_vehicle_custom_fields']
                : [];
            unset($errors['_vehicle_custom_fields']);
        }

        if ($moduleKey === 'documente_soferi') {
            $driverDocumentCustomFieldErrors = is_array($errors['_driver_custom_fields'] ?? null)
                ? $errors['_driver_custom_fields']
                : [];
            unset($errors['_driver_custom_fields']);
        }

        if ($moduleKey === 'vehicule') {
            $formData['tip_vehicul'] = normalize_vehicle_type_for_form_select((string) ($formData['tip_vehicul'] ?? 'autovehicul'));
        }

        if ($moduleKey === 'vehicule' && $old === []) {
            $vehicleType = (string) ($formData['tip_vehicul'] ?? 'autovehicul');
            $formData['formula_axelor'] = $this->tireModel->normalizeLayoutForType($vehicleType, (string) ($formData['formula_axelor'] ?? ''));
        }

        if ($moduleKey === 'soferi' && $old === []) {
            $formData['vehicle_ids'] = [];
        }

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
        $selectOptions = $this->buildFormSelectOptions($module);
        $documentTypeOptionsByVehicleType = [];
        $documentVehicleTypeByVehicleId = [];
        $documentTypeOptionsByVehicleId = [];
        $documentValidityDaysByVehicleIdAndType = [];
        $documentExpiryRequirementByVehicleType = [];
        $vehicleDocumentCustomFieldsByVehicleType = [];
        $vehicleDocumentCustomFieldValues = $moduleKey === 'documente'
            ? $this->extractDocumentCustomFieldValuesForForm($formData)
            : [];
        $documentTypeOptionsByDriverId = [];
        $documentValidityDaysByDriverIdAndType = [];
        $driverDocumentExpiryRequirementByType = [];
        $driverDocumentCustomFieldsByType = [];
        $driverDocumentCustomFieldValues = $moduleKey === 'documente_soferi'
            ? $this->extractDriverDocumentCustomFieldValuesForForm($formData)
            : [];
        $usesVehicleDocumentTypeConfig = in_array($moduleKey, ['documente', 'configurare_costuri_documente_vehicule_override'], true);

        if ($usesVehicleDocumentTypeConfig) {
            try {
                $vehicleIds = array_map('intval', array_keys($selectOptions['vehicle_id'] ?? []));
                $selectedVehicleId = (int) ($formData['vehicle_id'] ?? 0);

                if ($moduleKey === 'documente') {
                    $documentVehicleTypeByVehicleId = $this->documentModel->getVehicleTypeByVehicleIds($vehicleIds);
                    $documentTypeOptionsByVehicleType = $this->documentModel->getVehicleDocumentTypeOptionsByVehicleType();
                    $documentExpiryRequirementByVehicleType = $this->documentModel->getDocumentExpiryRequirementByVehicleType();
                    $vehicleDocumentCustomFieldsByVehicleType = $this->documentModel->getVehicleDocumentCustomFieldConfigsByVehicleType();
                    $selectedVehicleType = $documentVehicleTypeByVehicleId[(string) $selectedVehicleId] ?? '';
                    $selectOptions['tip_document'] = is_string($selectedVehicleType)
                        ? ($documentTypeOptionsByVehicleType[$selectedVehicleType] ?? [])
                        : [];
                } else {
                    $documentTypeOptionsByVehicleId = $this->documentModel->getExistingDocumentTypeOptionsByVehicleIds($vehicleIds);
                    $documentValidityDaysByVehicleIdAndType = $this->documentModel->getRemainingValidityDaysByVehicleIds($vehicleIds);
                    $selectOptions['document_type'] = $documentTypeOptionsByVehicleId[(string) $selectedVehicleId] ?? [];
                    $formData = $this->applyRemainingValidityDaysToCostFormData($formData, 'vehicle_id', $documentValidityDaysByVehicleIdAndType);
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][doc-config][form-config] ' . $exception->getMessage());
                flash_set('warning', 'Configurarea costurilor documente pe tip de vehicul nu este disponibila. Ruleaza scriptul database/update_configurare_costuri_documente_vehicule.sql.');
                if ($moduleKey === 'documente') {
                    $selectOptions['tip_document'] = [];
                    $documentExpiryRequirementByVehicleType = [];
                    $vehicleDocumentCustomFieldsByVehicleType = [];
                } else {
                    $selectOptions['document_type'] = [];
                    $documentTypeOptionsByVehicleId = [];
                    $documentValidityDaysByVehicleIdAndType = [];
                }
            }
        }

        if (in_array($moduleKey, ['documente_soferi', 'configurare_costuri_documente_soferi'], true)) {
            try {
                $driverIds = array_map('intval', array_keys($selectOptions['driver_id'] ?? []));
                $selectedDriverId = (int) ($formData['driver_id'] ?? 0);

                if ($moduleKey === 'documente_soferi') {
                    $documentTypeOptionsByDriverId = $this->documentModel->getAvailableDriverDocumentTypeOptionsByDriverIds($driverIds);
                    $driverDocumentExpiryRequirementByType = $this->documentModel->getDriverDocumentExpiryRequirementByType();
                    $driverDocumentCustomFieldsByType = $this->documentModel->getDriverDocumentCustomFieldConfigsByType();
                    $selectOptions['tip_document'] = $documentTypeOptionsByDriverId[(string) $selectedDriverId] ?? [];
                } else {
                    $documentTypeOptionsByDriverId = $this->documentModel->getExistingDriverDocumentTypeOptionsByDriverIds($driverIds);
                    $documentValidityDaysByDriverIdAndType = $this->documentModel->getRemainingValidityDaysByDriverIds($driverIds);
                    $selectOptions['document_type'] = $documentTypeOptionsByDriverId[(string) $selectedDriverId] ?? [];
                    $formData = $this->applyRemainingValidityDaysToCostFormData($formData, 'driver_id', $documentValidityDaysByDriverIdAndType);
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][form-config] ' . $exception->getMessage());
                $exceptionMessage = strtolower($exception->getMessage());
                if (str_contains($exceptionMessage, 'custom_fields_json') || str_contains($exceptionMessage, 'unknown column')) {
                    flash_set('warning', 'Configurarea campurilor personalizate pentru documentele soferilor nu este disponibila. Ruleaza scriptul database/update_driver_document_custom_fields.sql.');
                } else {
                    flash_set('warning', 'Configurarea costurilor documente soferi nu este disponibila. Ruleaza scriptul database/update_configurare_costuri_documente_soferi.sql.');
                }
                if ($moduleKey === 'documente_soferi') {
                    $selectOptions['tip_document'] = [];
                    $driverDocumentExpiryRequirementByType = [];
                    $driverDocumentCustomFieldsByType = [];
                } else {
                    $selectOptions['document_type'] = [];
                    $documentValidityDaysByDriverIdAndType = [];
                }
            }
        }

        render('module/form.php', [
            'pageTitle' => 'Adauga ' . ucfirst($module['singular']),
            'currentPage' => $currentPage,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'mode' => 'create',
            'recordId' => null,
            'formData' => $formData,
            'errors' => $errors,
            'selectOptions' => $selectOptions,
            'vehicleKmBordById' => $vehicleKmBordById,
            'driverVehicleById' => $driverVehicleById,
            'fuelConsumptionSummary' => $fuelConsumptionSummary,
            'backUrl' => $backUrl,
            'keepDocumentVehicleContext' => $keepDocumentVehicleContext,
            'documentTypeOptionsByVehicleType' => $documentTypeOptionsByVehicleType,
            'documentVehicleTypeByVehicleId' => $documentVehicleTypeByVehicleId,
            'documentTypeOptionsByVehicleId' => $documentTypeOptionsByVehicleId,
            'documentValidityDaysByVehicleIdAndType' => $documentValidityDaysByVehicleIdAndType,
            'documentExpiryRequirementByVehicleType' => $documentExpiryRequirementByVehicleType,
            'vehicleDocumentCustomFieldsByVehicleType' => $vehicleDocumentCustomFieldsByVehicleType,
            'vehicleDocumentCustomFieldValues' => $vehicleDocumentCustomFieldValues,
            'vehicleDocumentCustomFieldErrors' => $vehicleDocumentCustomFieldErrors,
            'documentTypeOptionsByDriverId' => $documentTypeOptionsByDriverId,
            'documentValidityDaysByDriverIdAndType' => $documentValidityDaysByDriverIdAndType,
            'driverDocumentExpiryRequirementByType' => $driverDocumentExpiryRequirementByType,
            'driverDocumentCustomFieldsByType' => $driverDocumentCustomFieldsByType,
            'driverDocumentCustomFieldValues' => $driverDocumentCustomFieldValues,
            'driverDocumentCustomFieldErrors' => $driverDocumentCustomFieldErrors,
            'vehicleMountedTires' => 0,
            'vehicleLayoutOptionsByType' => $moduleKey === 'vehicule' ? $this->buildVehicleLayoutOptionsByType() : [],
        ]);
    }

    private function storeAction(string $moduleKey, array $module): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => $moduleKey]));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => $moduleKey, 'action' => 'create']));

        if ($moduleKey === 'configurare_costuri_documente_vehicule_override') {
            $postedValidity = trim((string) ($_POST['validity_days'] ?? ''));
            if ($postedValidity === '') {
                $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
                $documentType = trim((string) ($_POST['document_type'] ?? ''));
                $resolvedValidityDays = $this->resolveRemainingValidityDaysForVehicleDocument($vehicleId, $documentType);
                if ($resolvedValidityDays !== null) {
                    $_POST['validity_days'] = (string) $resolvedValidityDays;
                }
            }
        }

        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            $postedValidity = trim((string) ($_POST['validity_days'] ?? ''));
            if ($postedValidity === '') {
                $driverId = (int) ($_POST['driver_id'] ?? 0);
                $documentType = trim((string) ($_POST['document_type'] ?? ''));
                $resolvedValidityDays = $this->resolveRemainingValidityDaysForDriverDocument($driverId, $documentType);
                if ($resolvedValidityDays !== null) {
                    $_POST['validity_days'] = (string) $resolvedValidityDays;
                }
            }
        }

        $module = $this->applyDynamicDocumentTypeOptions($moduleKey, $module, $_POST, null);
        $module = $this->applyDynamicDocumentExpiryRequirement($moduleKey, $module, $_POST, null);
        [$data, $errors] = $this->validateAndPrepareData($module, $_POST, $_FILES, 'create', null);
        $driverVehicleIds = [];
        if ($moduleKey === 'soferi') {
            $driverVehicleIds = $this->prepareDriverVehicleAssignmentsData($data, $errors, $_POST);
        }
        if ($moduleKey === 'alimentari') {
            $this->validateAlimentareDriverSelection($data, $errors);
        }
        if (in_array($moduleKey, ['documente', 'configurare_costuri_documente_vehicule_override'], true)) {
            $this->validateVehicleDocumentTypeSelection($moduleKey, $data, $errors);
        }
        if ($moduleKey === 'documente_soferi') {
            $this->validateDriverDocumentTypeSelection($data, $errors, null);
        }
        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            $this->validateDriverDocumentCostTypeSelection($data, $errors);
        }

        if ($moduleKey === 'documente') {
            try {
                [$customFieldsJson, $customFieldErrors, $hasConfiguredCustomFields] = $this->validateDocumentCustomFieldValues($data, $_POST);
                if ($customFieldErrors !== []) {
                    $errors['_vehicle_custom_fields'] = $customFieldErrors;
                }
                if ($hasConfiguredCustomFields || $customFieldsJson !== null || array_key_exists('custom_field_values', $_POST)) {
                    $data['custom_fields_json'] = $customFieldsJson;
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][doc-config][custom-fields-create] ' . $exception->getMessage());
                $errors['tip_document'] = 'Configurarea campurilor personalizate pentru documentele vehiculelor nu este disponibila. Ruleaza scriptul database/update_vehicle_document_custom_fields.sql.';
            }
        }

        if ($moduleKey === 'documente_soferi') {
            try {
                [$customFieldsJson, $customFieldErrors, $hasConfiguredCustomFields] = $this->validateDriverDocumentCustomFieldValues($data, $_POST);
                if ($customFieldErrors !== []) {
                    $errors['_driver_custom_fields'] = $customFieldErrors;
                }
                if ($hasConfiguredCustomFields || $customFieldsJson !== null || array_key_exists('custom_field_values', $_POST)) {
                    $data['custom_fields_json'] = $customFieldsJson;
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][custom-fields-create] ' . $exception->getMessage());
                $errors['tip_document'] = 'Configurarea campurilor personalizate pentru documentele soferilor nu este disponibila. Ruleaza scriptul database/update_driver_document_custom_fields.sql.';
            }
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
        } elseif (in_array($moduleKey, ['vehicule', 'soferi'], true) && $errors === []) {
            [$uploadedFileData, $uploadError] = $moduleKey === 'soferi'
                ? $this->storeUploadedDriverPhoto($_FILES['poza_upload'] ?? null)
                : $this->storeUploadedVehiclePhoto($_FILES['poza_upload'] ?? null);

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

        if ($moduleKey === 'soferi' && !array_key_exists('permis_expira_la', $data)) {
            // Legacy non-null column; the actual expiry is tracked in documente_soferi.
            $data['permis_expira_la'] = '9999-12-31';
        }

        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        try {
            $recordId = $this->moduleModel->insertRecord($module['table'], $data);
            if ($moduleKey === 'soferi') {
                $this->moduleModel->syncDriverVehicleAssignments($recordId, $driverVehicleIds);
            }
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
                if (in_array($moduleKey, ['vehicule', 'soferi'], true)) {
                    $this->cleanupUploadedPhotoForModule($moduleKey, $uploadedFileData);
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
        $vehicleDocumentCustomFieldErrors = [];
        $driverDocumentCustomFieldErrors = [];

        $formData = $record;
        if ($old !== []) {
            $formData = array_merge($formData, $old);
        }

        if ($moduleKey === 'documente') {
            $vehicleDocumentCustomFieldErrors = is_array($errors['_vehicle_custom_fields'] ?? null)
                ? $errors['_vehicle_custom_fields']
                : [];
            unset($errors['_vehicle_custom_fields']);
        }

        if ($moduleKey === 'documente_soferi') {
            $driverDocumentCustomFieldErrors = is_array($errors['_driver_custom_fields'] ?? null)
                ? $errors['_driver_custom_fields']
                : [];
            unset($errors['_driver_custom_fields']);
        }

        if ($moduleKey === 'vehicule') {
            $formData['tip_vehicul'] = normalize_vehicle_type_for_form_select((string) ($formData['tip_vehicul'] ?? 'autovehicul'));
        }

        if ($old === [] && $moduleKey === 'vehicule') {
            $vehicleType = (string) ($formData['tip_vehicul'] ?? 'autovehicul');
            $formData['formula_axelor'] = $this->tireModel->normalizeLayoutForType($vehicleType, (string) ($formData['formula_axelor'] ?? ''));
        }

        if ($moduleKey === 'soferi' && $old === []) {
            $formData['vehicle_ids'] = $this->moduleModel->getDriverVehicleIds($id);
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
        $selectOptions = $this->buildFormSelectOptions($module);
        $documentTypeOptionsByVehicleType = [];
        $documentVehicleTypeByVehicleId = [];
        $documentTypeOptionsByVehicleId = [];
        $documentValidityDaysByVehicleIdAndType = [];
        $documentExpiryRequirementByVehicleType = [];
        $vehicleDocumentCustomFieldsByVehicleType = [];
        $vehicleDocumentCustomFieldValues = $moduleKey === 'documente'
            ? $this->extractDocumentCustomFieldValuesForForm($formData)
            : [];
        $documentTypeOptionsByDriverId = [];
        $documentValidityDaysByDriverIdAndType = [];
        $driverDocumentExpiryRequirementByType = [];
        $driverDocumentCustomFieldsByType = [];
        $driverDocumentCustomFieldValues = $moduleKey === 'documente_soferi'
            ? $this->extractDriverDocumentCustomFieldValuesForForm($formData)
            : [];
        $usesVehicleDocumentTypeConfig = in_array($moduleKey, ['documente', 'configurare_costuri_documente_vehicule_override'], true);

        if ($usesVehicleDocumentTypeConfig) {
            try {
                $vehicleIds = array_map('intval', array_keys($selectOptions['vehicle_id'] ?? []));
                $selectedVehicleId = (int) ($formData['vehicle_id'] ?? 0);

                if ($moduleKey === 'documente') {
                    $documentVehicleTypeByVehicleId = $this->documentModel->getVehicleTypeByVehicleIds($vehicleIds);
                    $documentTypeOptionsByVehicleType = $this->documentModel->getVehicleDocumentTypeOptionsByVehicleType();
                    $documentExpiryRequirementByVehicleType = $this->documentModel->getDocumentExpiryRequirementByVehicleType();
                    $vehicleDocumentCustomFieldsByVehicleType = $this->documentModel->getVehicleDocumentCustomFieldConfigsByVehicleType();
                    $selectedVehicleType = $documentVehicleTypeByVehicleId[(string) $selectedVehicleId] ?? '';
                    $selectOptions['tip_document'] = is_string($selectedVehicleType)
                        ? ($documentTypeOptionsByVehicleType[$selectedVehicleType] ?? [])
                        : [];
                } else {
                    $documentTypeOptionsByVehicleId = $this->documentModel->getExistingDocumentTypeOptionsByVehicleIds($vehicleIds);
                    $documentValidityDaysByVehicleIdAndType = $this->documentModel->getRemainingValidityDaysByVehicleIds($vehicleIds);
                    $selectOptions['document_type'] = $documentTypeOptionsByVehicleId[(string) $selectedVehicleId] ?? [];
                    $formData = $this->applyRemainingValidityDaysToCostFormData($formData, 'vehicle_id', $documentValidityDaysByVehicleIdAndType);
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][doc-config][form-config-edit] ' . $exception->getMessage());
                flash_set('warning', 'Configurarea costurilor documente pe tip de vehicul nu este disponibila. Ruleaza scriptul database/update_configurare_costuri_documente_vehicule.sql.');
                if ($moduleKey === 'documente') {
                    $selectOptions['tip_document'] = [];
                    $documentExpiryRequirementByVehicleType = [];
                    $vehicleDocumentCustomFieldsByVehicleType = [];
                } else {
                    $selectOptions['document_type'] = [];
                    $documentTypeOptionsByVehicleId = [];
                    $documentValidityDaysByVehicleIdAndType = [];
                }
            }
        }

        if (in_array($moduleKey, ['documente_soferi', 'configurare_costuri_documente_soferi'], true)) {
            try {
                $driverIds = array_map('intval', array_keys($selectOptions['driver_id'] ?? []));
                $selectedDriverId = (int) ($formData['driver_id'] ?? 0);

                if ($moduleKey === 'documente_soferi') {
                    $documentTypeOptionsByDriverId = $this->documentModel->getAvailableDriverDocumentTypeOptionsByDriverIds($driverIds, $id);
                    $driverDocumentExpiryRequirementByType = $this->documentModel->getDriverDocumentExpiryRequirementByType();
                    $driverDocumentCustomFieldsByType = $this->documentModel->getDriverDocumentCustomFieldConfigsByType();
                    $selectOptions['tip_document'] = $documentTypeOptionsByDriverId[(string) $selectedDriverId] ?? [];
                } else {
                    $documentTypeOptionsByDriverId = $this->documentModel->getExistingDriverDocumentTypeOptionsByDriverIds($driverIds);
                    $documentValidityDaysByDriverIdAndType = $this->documentModel->getRemainingValidityDaysByDriverIds($driverIds);
                    $selectOptions['document_type'] = $documentTypeOptionsByDriverId[(string) $selectedDriverId] ?? [];
                    $formData = $this->applyRemainingValidityDaysToCostFormData($formData, 'driver_id', $documentValidityDaysByDriverIdAndType);
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][form-config-edit] ' . $exception->getMessage());
                $exceptionMessage = strtolower($exception->getMessage());
                if (str_contains($exceptionMessage, 'custom_fields_json') || str_contains($exceptionMessage, 'unknown column')) {
                    flash_set('warning', 'Configurarea campurilor personalizate pentru documentele soferilor nu este disponibila. Ruleaza scriptul database/update_driver_document_custom_fields.sql.');
                } else {
                    flash_set('warning', 'Configurarea costurilor documente soferi nu este disponibila. Ruleaza scriptul database/update_configurare_costuri_documente_soferi.sql.');
                }
                if ($moduleKey === 'documente_soferi') {
                    $selectOptions['tip_document'] = [];
                    $driverDocumentExpiryRequirementByType = [];
                    $driverDocumentCustomFieldsByType = [];
                } else {
                    $selectOptions['document_type'] = [];
                    $documentValidityDaysByDriverIdAndType = [];
                }
            }
        }

        render('module/form.php', [
            'pageTitle' => 'Editeaza ' . ucfirst($module['singular']),
            'currentPage' => $currentPage,
            'moduleKey' => $moduleKey,
            'module' => $module,
            'mode' => 'edit',
            'recordId' => $id,
            'formData' => $formData,
            'errors' => $errors,
            'selectOptions' => $selectOptions,
            'vehicleKmBordById' => $vehicleKmBordById,
            'driverVehicleById' => $driverVehicleById,
            'fuelConsumptionSummary' => $fuelConsumptionSummary,
            'backUrl' => $this->buildModuleBackUrl($moduleKey, $module, $record, $formData),
            'documentTypeOptionsByVehicleType' => $documentTypeOptionsByVehicleType,
            'documentVehicleTypeByVehicleId' => $documentVehicleTypeByVehicleId,
            'documentTypeOptionsByVehicleId' => $documentTypeOptionsByVehicleId,
            'documentValidityDaysByVehicleIdAndType' => $documentValidityDaysByVehicleIdAndType,
            'documentExpiryRequirementByVehicleType' => $documentExpiryRequirementByVehicleType,
            'vehicleDocumentCustomFieldsByVehicleType' => $vehicleDocumentCustomFieldsByVehicleType,
            'vehicleDocumentCustomFieldValues' => $vehicleDocumentCustomFieldValues,
            'vehicleDocumentCustomFieldErrors' => $vehicleDocumentCustomFieldErrors,
            'documentTypeOptionsByDriverId' => $documentTypeOptionsByDriverId,
            'documentValidityDaysByDriverIdAndType' => $documentValidityDaysByDriverIdAndType,
            'driverDocumentExpiryRequirementByType' => $driverDocumentExpiryRequirementByType,
            'driverDocumentCustomFieldsByType' => $driverDocumentCustomFieldsByType,
            'driverDocumentCustomFieldValues' => $driverDocumentCustomFieldValues,
            'driverDocumentCustomFieldErrors' => $driverDocumentCustomFieldErrors,
            'vehicleMountedTires' => $moduleKey === 'vehicule' ? $this->getVehicleMountedTireCountSafe($id) : 0,
            'vehicleLayoutOptionsByType' => $moduleKey === 'vehicule' ? $this->buildVehicleLayoutOptionsByType() : [],
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

        if ($moduleKey === 'configurare_costuri_documente_vehicule_override') {
            $postedValidity = trim((string) ($_POST['validity_days'] ?? ''));
            if ($postedValidity === '') {
                $vehicleId = (int) ($_POST['vehicle_id'] ?? ($existing['vehicle_id'] ?? 0));
                $documentType = trim((string) ($_POST['document_type'] ?? ($existing['document_type'] ?? '')));
                $resolvedValidityDays = $this->resolveRemainingValidityDaysForVehicleDocument($vehicleId, $documentType);
                if ($resolvedValidityDays !== null) {
                    $_POST['validity_days'] = (string) $resolvedValidityDays;
                }
            }
        }

        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            $postedValidity = trim((string) ($_POST['validity_days'] ?? ''));
            if ($postedValidity === '') {
                $driverId = (int) ($_POST['driver_id'] ?? ($existing['driver_id'] ?? 0));
                $documentType = trim((string) ($_POST['document_type'] ?? ($existing['document_type'] ?? '')));
                $resolvedValidityDays = $this->resolveRemainingValidityDaysForDriverDocument($driverId, $documentType);
                if ($resolvedValidityDays !== null) {
                    $_POST['validity_days'] = (string) $resolvedValidityDays;
                }
            }
        }

        $module = $this->applyDynamicDocumentTypeOptions($moduleKey, $module, $_POST, $id);
        $module = $this->applyDynamicDocumentExpiryRequirement($moduleKey, $module, $_POST, $id);
        [$data, $errors] = $this->validateAndPrepareData($module, $_POST, $_FILES, 'edit', $id);
        $driverVehicleIds = [];
        if ($moduleKey === 'soferi') {
            $driverVehicleIds = $this->prepareDriverVehicleAssignmentsData($data, $errors, $_POST);
        }
        if ($moduleKey === 'alimentari') {
            $this->validateAlimentareDriverSelection($data, $errors);
        }
        if (in_array($moduleKey, ['documente', 'configurare_costuri_documente_vehicule_override'], true)) {
            $this->validateVehicleDocumentTypeSelection($moduleKey, $data, $errors);
        }
        if ($moduleKey === 'documente_soferi') {
            $this->validateDriverDocumentTypeSelection($data, $errors, $id);
        }
        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            $this->validateDriverDocumentCostTypeSelection($data, $errors);
        }
        if ($moduleKey === 'documente') {
            try {
                [$customFieldsJson, $customFieldErrors, $hasConfiguredCustomFields] = $this->validateDocumentCustomFieldValues($data, $_POST);
                if ($customFieldErrors !== []) {
                    $errors['_vehicle_custom_fields'] = $customFieldErrors;
                }
                if (
                    $hasConfiguredCustomFields
                    || $customFieldsJson !== null
                    || array_key_exists('custom_field_values', $_POST)
                    || !empty($existing['custom_fields_json'])
                ) {
                    $data['custom_fields_json'] = $customFieldsJson;
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][doc-config][custom-fields-update] ' . $exception->getMessage());
                $errors['tip_document'] = 'Configurarea campurilor personalizate pentru documentele vehiculelor nu este disponibila. Ruleaza scriptul database/update_vehicle_document_custom_fields.sql.';
            }
        }
        if ($moduleKey === 'documente_soferi') {
            try {
                [$customFieldsJson, $customFieldErrors, $hasConfiguredCustomFields] = $this->validateDriverDocumentCustomFieldValues($data, $_POST);
                if ($customFieldErrors !== []) {
                    $errors['_driver_custom_fields'] = $customFieldErrors;
                }
                if (
                    $hasConfiguredCustomFields
                    || $customFieldsJson !== null
                    || array_key_exists('custom_field_values', $_POST)
                    || !empty($existing['custom_fields_json'])
                ) {
                    $data['custom_fields_json'] = $customFieldsJson;
                }
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][custom-fields-update] ' . $exception->getMessage());
                $errors['tip_document'] = 'Configurarea campurilor personalizate pentru documentele soferilor nu este disponibila. Ruleaza scriptul database/update_driver_document_custom_fields.sql.';
            }
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
        } elseif (in_array($moduleKey, ['vehicule', 'soferi'], true) && $errors === []) {
            [$uploadedFileData, $uploadError] = $moduleKey === 'soferi'
                ? $this->storeUploadedDriverPhoto($_FILES['poza_upload'] ?? null)
                : $this->storeUploadedVehiclePhoto($_FILES['poza_upload'] ?? null);

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
                if (in_array($moduleKey, ['vehicule', 'soferi'], true)) {
                    $this->cleanupUploadedPhotoForModule($moduleKey, $uploadedFileData);
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
            if ($moduleKey === 'soferi') {
                $this->moduleModel->syncDriverVehicleAssignments($id, $driverVehicleIds);
            }
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
            } elseif (in_array($moduleKey, ['vehicule', 'soferi'], true)) {
                if (($uploadedFileData !== null || $removeExistingFile) && !empty($existing['poza_stocata'])) {
                    $this->deletePhotoPhysicalFileForModule($moduleKey, (string) $existing['poza_stocata']);
                }

                if ($moduleKey === 'vehicule') {
                    $updatedVehicle = $this->moduleModel->findById($module, $id);
                    if ($updatedVehicle !== null) {
                        $currentRecordForStatus = $updatedVehicle;
                        $this->syncVehicleTireLayoutSafe($updatedVehicle);
                    } else {
                        $this->syncVehicleTireLayoutSafe($data + $existing + ['id' => $id]);
                    }
                }
            }

            $this->syncStatusesAfterMutation($moduleKey, $currentRecordForStatus, $existing);

            flash_set('success', ucfirst($module['singular']) . ' actualizat cu succes.');
        } catch (PDOException $exception) {
            if ($uploadedFileData !== null) {
                if (in_array($moduleKey, ['vehicule', 'soferi'], true)) {
                    $this->cleanupUploadedPhotoForModule($moduleKey, $uploadedFileData);
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

        if (!is_trailer_vehicle_type((string) ($trailer['tip_vehicul'] ?? ''))) {
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
                if ($trailer === null || !is_trailer_vehicle_type((string) ($trailer['tip_vehicul'] ?? ''))) {
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
        $allowSwap = isset($_POST['allow_swap']);

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
                $this->currentUserId(),
                $allowSwap,
                'Montaj din pagina vehicul',
                trim((string) ($_POST['observation'] ?? '')) ?: null
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

        if (!in_array($statusEnd, [TireModel::STATUS_IN_STOCK, TireModel::STATUS_SPARE, TireModel::STATUS_REMOVED, TireModel::STATUS_DAMAGED, TireModel::STATUS_MISSING, TireModel::STATUS_SCRAPPED], true)) {
            $statusEnd = TireModel::STATUS_SPARE;
        }

        try {
            $updated = $this->tireModel->unmountTire(
                $allocationId,
                $vehicleId,
                max(0, (int) ($vehicle['km_bord'] ?? 0)),
                $unmountDate,
                $statusEnd,
                date('Y-m-d H:i:s'),
                $this->currentUserId(),
                'Demontare din pagina vehicul',
                trim((string) ($_POST['observation'] ?? '')) ?: null
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

    private function moveMaintenanceTireAction(string $moduleKey, array $module): void
    {
        $fallbackUrl = $moduleKey === 'vehicule'
            ? build_query_url(['page' => 'vehicule'])
            : $this->maintenanceTireStockUrl();

        if (!in_array($moduleKey, ['mentenanta', 'vehicule'], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($fallbackUrl);
        }

        $sourceVehicleId = (int) ($_POST['source_vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);
        $redirectUrl = $sourceVehicleId > 0 && $moduleKey === 'vehicule'
            ? build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $sourceVehicleId])
            : $this->maintenanceTireStockUrl();

        ensure_csrf_or_redirect($redirectUrl);

        $tireId = (int) ($_POST['tire_id'] ?? 0);
        $targetVehicleId = (int) ($_POST['target_vehicle_id'] ?? 0);
        $targetPositionId = (int) ($_POST['target_position_id'] ?? 0);
        $moveDate = trim((string) ($_POST['move_date'] ?? date('Y-m-d')));
        $reason = trim((string) ($_POST['move_reason'] ?? 'Mutare anvelopa'));
        $observation = trim((string) ($_POST['move_observation'] ?? ''));
        $allowSwap = isset($_POST['allow_swap']);

        if ($tireId <= 0 || $targetPositionId <= 0) {
            flash_set('danger', 'Selecteaza anvelopa si pozitia tinta pentru mutare.');
            redirect($redirectUrl);
        }

        if (!$this->isValidDate($moveDate)) {
            flash_set('danger', 'Data mutarii nu este valida (format YYYY-MM-DD).');
            redirect($redirectUrl);
        }

        $position = $this->tireModel->getVehiclePositionById($targetPositionId);
        if ($position === null) {
            flash_set('danger', 'Pozitia tinta nu exista.');
            redirect($redirectUrl);
        }
        $targetVehicleId = $targetVehicleId > 0 ? $targetVehicleId : (int) ($position['vehicle_id'] ?? 0);

        $vehicleModule = $this->modules['vehicule'] ?? null;
        $vehicle = is_array($vehicleModule) ? $this->moduleModel->findById($vehicleModule, $targetVehicleId) : null;
        if ($vehicle === null) {
            flash_set('danger', 'Vehiculul tinta nu exista.');
            redirect($redirectUrl);
        }

        try {
            $this->tireModel->mountTire(
                $tireId,
                $targetVehicleId,
                $targetPositionId,
                max(0, (int) ($vehicle['km_bord'] ?? 0)),
                $moveDate,
                date('Y-m-d H:i:s'),
                $this->currentUserId(),
                $allowSwap,
                $reason !== '' ? $reason : 'Mutare anvelopa',
                $observation !== '' ? $observation : null
            );

            flash_set('success', 'Anvelopa a fost mutata cu succes.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect($redirectUrl);
    }

    private function changeTireStatusAction(string $moduleKey, array $module): void
    {
        if (!in_array($moduleKey, ['mentenanta', 'vehicule'], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($moduleKey === 'vehicule' ? build_query_url(['page' => 'vehicule']) : $this->maintenanceTireStockUrl());
        }

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $redirectUrl = $moduleKey === 'vehicule' && $vehicleId > 0
            ? build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId])
            : $this->maintenanceTireStockUrl();

        ensure_csrf_or_redirect($redirectUrl);

        $tireId = (int) ($_POST['tire_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? 'Schimbare status'));
        $observation = trim((string) ($_POST['observation'] ?? ''));

        if ($tireId <= 0 || $status === '') {
            flash_set('danger', 'Selecteaza anvelopa si statusul nou.');
            redirect($redirectUrl);
        }

        try {
            $this->tireModel->changeTireStatus(
                $tireId,
                $status,
                $reason !== '' ? $reason : 'Schimbare status',
                $observation !== '' ? $observation : null,
                $this->currentUserId()
            );
            flash_set('success', 'Statusul anvelopei a fost actualizat.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect($redirectUrl);
    }

    private function maintenanceTireStockUrl(): string
    {
        return build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock']);
    }

    private function collectTireStockFilters(): array
    {
        $keys = [
            'q',
            'vehicle_type',
            'axle_config',
            'tire_type',
            'status',
            'condition',
            'location',
            'mounted',
        ];

        $filters = [];
        foreach ($keys as $key) {
            $value = trim((string) ($_GET[$key] ?? ''));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $filters['page'] = max(1, (int) ($_GET['p'] ?? $_GET['page_no'] ?? 1));
        $filters['per_page'] = max(5, min(50, (int) ($_GET['per_page'] ?? 10)));

        return $filters;
    }

    private function addMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        $stockRedirectUrl = $this->maintenanceTireStockUrl();

        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($stockRedirectUrl);
        }

        ensure_csrf_or_redirect($stockRedirectUrl);

        $brand = trim((string) ($_POST['stock_brand'] ?? ''));
        $modelName = trim((string) ($_POST['stock_model'] ?? ''));
        $tireSize = trim((string) ($_POST['stock_tire_size'] ?? ''));
        $dotCode = strtoupper(trim((string) ($_POST['stock_dot_code'] ?? '')));
        $serialPrefix = trim((string) ($_POST['stock_serial_prefix'] ?? 'STOC'));
        $targetVehicleTypesRaw = $_POST['stock_target_vehicle_types'] ?? ($_POST['stock_target_vehicle_type'] ?? []);
        $targetAxleConfigRaw = trim((string) ($_POST['stock_target_axle_config'] ?? ''));
        $requiresVehicleCompatibility = (string) ($_POST['stock_require_vehicle_compatibility'] ?? '') === '1';
        $mountDate = trim((string) ($_POST['stock_mount_date'] ?? date('Y-m-d')));
        $quantityRaw = trim((string) ($_POST['stock_quantity'] ?? '1'));
        $kmInitialRaw = trim((string) ($_POST['stock_km_initial'] ?? '0'));
        $estimatedLifeRaw = trim((string) ($_POST['stock_estimated_life_km'] ?? ''));
        $estimatedRemainingRaw = trim((string) ($_POST['stock_estimated_remaining_km'] ?? $estimatedLifeRaw));
        $statusRaw = strtolower(trim((string) ($_POST['stock_status'] ?? TireModel::STATUS_IN_STOCK)));
        $axleTypeRaw = trim((string) ($_POST['stock_axle_type'] ?? ''));
        $tireTypeRaw = trim((string) ($_POST['stock_tire_type'] ?? TireModel::TIRE_TYPE_TRAILER));
        $usageCompatibility = trim((string) ($_POST['stock_usage_compatibility'] ?? ''));
        $locationLabel = trim((string) ($_POST['stock_location_label'] ?? 'Depozit'));
        $manufacturingYearRaw = trim((string) ($_POST['stock_manufacturing_year'] ?? ''));
        $purchaseDate = trim((string) ($_POST['stock_purchase_date'] ?? ''));
        $purchasePriceRaw = trim((string) ($_POST['stock_purchase_price'] ?? ''));
        $supplier = trim((string) ($_POST['stock_supplier'] ?? ''));
        $invoiceNumber = trim((string) ($_POST['stock_invoice_number'] ?? ''));
        $currentMileageRaw = trim((string) ($_POST['stock_current_mileage'] ?? $kmInitialRaw));
        $initialConditionRaw = trim((string) ($_POST['stock_initial_condition'] ?? 'good'));
        $conditionStatusRaw = trim((string) ($_POST['stock_condition_status'] ?? $initialConditionRaw));
        $seasonRaw = trim((string) ($_POST['stock_season'] ?? 'all_season'));
        $directional = isset($_POST['stock_directional']) ? 1 : 0;
        $rotationDirection = trim((string) ($_POST['stock_rotation_direction'] ?? ''));
        $notes = trim((string) ($_POST['stock_notes'] ?? ''));

        if ($brand === '') {
            flash_set('danger', 'Brandul este obligatoriu pentru adaugarea in stoc.');
            redirect($stockRedirectUrl);
        }

        if ($quantityRaw === '' || !preg_match('/^\d+$/', $quantityRaw)) {
            flash_set('danger', 'Cantitatea trebuie sa fie un numar intreg pozitiv.');
            redirect($stockRedirectUrl);
        }
        $quantity = max(1, min(1000, (int) $quantityRaw));

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data este invalida. Foloseste formatul YYYY-MM-DD.');
            redirect($stockRedirectUrl);
        }

        if ($kmInitialRaw === '' || !preg_match('/^\d+$/', $kmInitialRaw)) {
            flash_set('danger', 'Km initial trebuie sa fie numeric.');
            redirect($stockRedirectUrl);
        }
        $kmInitial = max(0, (int) $kmInitialRaw);

        $estimatedLifeKm = null;
        if ($estimatedLifeRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedLifeRaw)) {
                flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
                redirect($stockRedirectUrl);
            }
            $estimatedLifeKm = max(0, (int) $estimatedLifeRaw);
        }

        $estimatedRemainingKm = null;
        if ($estimatedRemainingRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedRemainingRaw)) {
                flash_set('danger', 'Km ramasi estimati trebuie sa fie numerici.');
                redirect($stockRedirectUrl);
            }
            $estimatedRemainingKm = max(0, (int) $estimatedRemainingRaw);
        }

        if ($currentMileageRaw === '' || !preg_match('/^\d+$/', $currentMileageRaw)) {
            flash_set('danger', 'Kilometrajul curent trebuie sa fie numeric.');
            redirect($stockRedirectUrl);
        }

        $manufacturingYear = null;
        if ($manufacturingYearRaw !== '') {
            if (!preg_match('/^\d{4}$/', $manufacturingYearRaw)) {
                flash_set('danger', 'Anul fabricatiei trebuie sa aiba format YYYY.');
                redirect($stockRedirectUrl);
            }
            $manufacturingYear = (int) $manufacturingYearRaw;
        }

        if ($purchaseDate !== '' && !$this->isValidDate($purchaseDate)) {
            flash_set('danger', 'Data achizitiei este invalida (format YYYY-MM-DD).');
            redirect($stockRedirectUrl);
        }

        $purchasePrice = null;
        if ($purchasePriceRaw !== '') {
            $normalizedPrice = str_replace(',', '.', $purchasePriceRaw);
            if (!is_numeric($normalizedPrice)) {
                flash_set('danger', 'Pretul de achizitie trebuie sa fie numeric.');
                redirect($stockRedirectUrl);
            }
            $purchasePrice = round((float) $normalizedPrice, 2);
        }

        $allowedStatuses = [
            TireModel::STATUS_IN_STOCK,
            TireModel::STATUS_SPARE,
            TireModel::STATUS_DAMAGED,
            TireModel::STATUS_MISSING,
            TireModel::STATUS_REMOVED,
            TireModel::STATUS_SCRAPPED,
        ];
        $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : TireModel::STATUS_IN_STOCK;
        $targetVehicleTypes = $this->tireModel->normalizeTargetVehicleTypes($targetVehicleTypesRaw, null);
        $targetVehicleType = (string) ($targetVehicleTypes[0] ?? 'universal');
        $axleType = $this->tireModel->normalizeAxleType($axleTypeRaw);
        if ($tireTypeRaw === '' || !array_key_exists($tireTypeRaw, $this->tireModel->getTireTypeOptions())) {
            flash_set('danger', 'Tipul anvelopei este obligatoriu.');
            redirect($stockRedirectUrl);
        }
        $tireType = $this->tireModel->normalizeTireType($tireTypeRaw);
        if ($axleType === '') {
            flash_set('danger', 'Tipul axei este obligatoriu pentru anvelopa.');
            redirect($stockRedirectUrl);
        }

        if (!$this->tireModel->isTireTypeAllowedForAxleType($axleType, $tireType)) {
            flash_set('danger', 'Combinatia Tip axa / Tip anvelopa nu este valida.');
            redirect($stockRedirectUrl);
        }

        $hasSpecificTargetType = array_values(array_filter($targetVehicleTypes, static fn (string $targetType): bool => $targetType !== 'universal')) !== [];
        if ($requiresVehicleCompatibility && !$hasSpecificTargetType) {
            flash_set('danger', 'Tipul vehiculului este obligatoriu pentru anvelopa.');
            redirect($stockRedirectUrl);
        }

        $targetAxleConfig = $targetAxleConfigRaw !== ''
            ? $this->tireModel->normalizeTargetAxleConfig($targetVehicleType, $targetAxleConfigRaw)
            : null;
        [$invoiceUpload, $invoiceUploadError] = $this->storeUploadedTireInvoice($_FILES['stock_invoice_upload'] ?? null);
        if ($invoiceUploadError !== null) {
            flash_set('danger', $invoiceUploadError);
            redirect($stockRedirectUrl);
        }
        [$profilePhotoUpload, $profilePhotoUploadError] = $this->storeUploadedTirePhoto($_FILES['stock_profile_photo_upload'] ?? null, 'anvelope_profil', 'anvelopa_profil', 'profile_photo_original_name', 'profile_photo_path');
        if ($profilePhotoUploadError !== null) {
            flash_set('danger', $profilePhotoUploadError);
            redirect($stockRedirectUrl);
        }
        [$locationPhotoUpload, $locationPhotoUploadError] = $this->storeUploadedTirePhoto($_FILES['stock_location_photo_upload'] ?? null, 'anvelope_locatii', 'anvelopa_locatie', 'location_photo_original_name', 'location_photo_path');
        if ($locationPhotoUploadError !== null) {
            flash_set('danger', $locationPhotoUploadError);
            redirect($stockRedirectUrl);
        }

        try {
            $createdTireIds = $this->tireModel->createStockTireBatchWithIds([
                'brand' => $brand,
                'model' => $modelName !== '' ? $modelName : null,
                'tire_size' => $tireSize !== '' ? $tireSize : null,
                'dot_code' => $dotCode !== '' ? $dotCode : null,
                'serial_prefix' => $serialPrefix,
                'target_vehicle_type' => $targetVehicleType,
                'target_vehicle_types' => $targetVehicleTypes,
                'target_axle_config' => $targetAxleConfig,
                'axle_type' => $axleType,
                'tire_type' => $tireType,
                'usage_compatibility' => $usageCompatibility !== '' ? $usageCompatibility : null,
                'location_label' => $locationLabel !== '' ? $locationLabel : null,
                'profile_photo_original_name' => $profilePhotoUpload['profile_photo_original_name'] ?? null,
                'profile_photo_path' => $profilePhotoUpload['profile_photo_path'] ?? null,
                'location_photo_original_name' => $locationPhotoUpload['location_photo_original_name'] ?? null,
                'location_photo_path' => $locationPhotoUpload['location_photo_path'] ?? null,
                'manufacturing_year' => $manufacturingYear,
                'purchase_date' => $purchaseDate !== '' ? $purchaseDate : null,
                'purchase_price' => $purchasePrice,
                'supplier' => $supplier !== '' ? $supplier : null,
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'invoice_document_original_name' => $invoiceUpload['invoice_document_original_name'] ?? null,
                'invoice_document_path' => $invoiceUpload['invoice_document_path'] ?? null,
                'mount_date' => $mountDate,
                'quantity' => $quantity,
                'km_initial' => $kmInitial,
                'current_mileage' => max(0, (int) $currentMileageRaw),
                'estimated_life_km' => $estimatedLifeKm,
                'estimated_remaining_km' => $estimatedRemainingKm,
                'status' => $status,
                'tread_depth_mm' => null,
                'min_tread_depth_mm' => 2.0,
                'initial_condition' => $initialConditionRaw,
                'condition_status' => $conditionStatusRaw,
                'season' => in_array($seasonRaw, ['summer', 'winter', 'all_season'], true) ? $seasonRaw : 'all_season',
                'directional' => $directional,
                'rotation_direction' => $rotationDirection !== '' ? $rotationDirection : null,
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

        redirect($stockRedirectUrl);
    }

    private function bulkMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        $stockRedirectUrl = $this->maintenanceTireStockUrl();

        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($stockRedirectUrl);
        }

        ensure_csrf_or_redirect($stockRedirectUrl);

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
            redirect($stockRedirectUrl);
        }

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data este invalida. Foloseste formatul YYYY-MM-DD.');
            redirect($stockRedirectUrl);
        }

        $estimatedLifeKm = null;
        if ($estimatedLifeRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedLifeRaw)) {
                flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
                redirect($stockRedirectUrl);
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
                redirect($stockRedirectUrl);
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

        redirect($stockRedirectUrl);
    }

    private function updateMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        $stockRedirectUrl = $this->maintenanceTireStockUrl();

        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($stockRedirectUrl);
        }

        ensure_csrf_or_redirect($stockRedirectUrl);

        $tireId = (int) ($_POST['stock_tire_id'] ?? 0);
        if ($tireId <= 0) {
            flash_set('danger', 'Anvelopa selectata pentru editare este invalida.');
            redirect($stockRedirectUrl);
        }

        $tire = $this->tireModel->getStockTireById($tireId);
        if ($tire === null) {
            flash_set('danger', 'Anvelopa selectata nu exista.');
            redirect($stockRedirectUrl);
        }

        $brand = trim((string) ($_POST['stock_edit_brand'] ?? ''));
        $modelName = trim((string) ($_POST['stock_edit_model'] ?? ''));
        $tireSize = trim((string) ($_POST['stock_edit_tire_size'] ?? ''));
        $dotCode = strtoupper(trim((string) ($_POST['stock_edit_dot_code'] ?? '')));
        $targetTypesRaw = $_POST['stock_edit_target_vehicle_types'] ?? ($_POST['stock_edit_target_vehicle_type'] ?? ($tire['target_vehicle_types'] ?? ($tire['target_vehicle_type'] ?? 'universal')));
        $targetAxleConfigRaw = trim((string) ($_POST['stock_edit_target_axle_config'] ?? ($tire['target_axle_config'] ?? '')));
        $statusRaw = strtolower(trim((string) ($_POST['stock_edit_status'] ?? ($tire['status'] ?? TireModel::STATUS_IN_STOCK))));
        $axleTypeRaw = trim((string) ($_POST['stock_edit_axle_type'] ?? ($tire['axle_type'] ?? '')));
        $tireTypeRaw = trim((string) ($_POST['stock_edit_tire_type'] ?? ($tire['tire_type'] ?? TireModel::TIRE_TYPE_TRAILER)));
        $usageCompatibility = trim((string) ($_POST['stock_edit_usage_compatibility'] ?? ($tire['usage_compatibility'] ?? '')));
        $locationLabel = trim((string) ($_POST['stock_edit_location_label'] ?? ($tire['location_label'] ?? '')));
        $manufacturingYearRaw = trim((string) ($_POST['stock_edit_manufacturing_year'] ?? ($tire['manufacturing_year'] ?? '')));
        $purchaseDate = trim((string) ($_POST['stock_edit_purchase_date'] ?? ($tire['purchase_date'] ?? '')));
        $purchasePriceRaw = trim((string) ($_POST['stock_edit_purchase_price'] ?? ($tire['purchase_price'] ?? '')));
        $supplier = trim((string) ($_POST['stock_edit_supplier'] ?? ($tire['supplier'] ?? '')));
        $invoiceNumber = trim((string) ($_POST['stock_edit_invoice_number'] ?? ($tire['invoice_number'] ?? '')));
        $mountDate = trim((string) ($_POST['stock_edit_mount_date'] ?? ($tire['mount_date'] ?? date('Y-m-d'))));
        $currentMileageRaw = trim((string) ($_POST['stock_edit_current_mileage'] ?? ($tire['current_mileage'] ?? '0')));
        $estimatedLifeRaw = trim((string) ($_POST['stock_edit_estimated_life_km'] ?? ($tire['estimated_life_km'] ?? '')));
        $estimatedRemainingRaw = trim((string) ($_POST['stock_edit_estimated_remaining_km'] ?? ($tire['estimated_remaining_km'] ?? '')));
        $initialConditionRaw = trim((string) ($_POST['stock_edit_initial_condition'] ?? ($tire['initial_condition'] ?? 'good')));
        $conditionStatusRaw = trim((string) ($_POST['stock_edit_condition_status'] ?? ($tire['condition_status'] ?? $initialConditionRaw)));
        $seasonRaw = trim((string) ($_POST['stock_edit_season'] ?? ($tire['season'] ?? 'all_season')));
        $directional = array_key_exists('stock_edit_directional', $_POST) ? (isset($_POST['stock_edit_directional']) ? 1 : 0) : (int) ($tire['directional'] ?? 0);
        $rotationDirection = trim((string) ($_POST['stock_edit_rotation_direction'] ?? ($tire['rotation_direction'] ?? '')));
        $notes = trim((string) ($_POST['stock_edit_notes'] ?? ''));

        if ($brand === '') {
            flash_set('danger', 'Brandul anvelopei este obligatoriu.');
            redirect($stockRedirectUrl);
        }

        if (!$this->isValidDate($mountDate)) {
            flash_set('danger', 'Data montaj este invalida (format YYYY-MM-DD).');
            redirect($stockRedirectUrl);
        }

        $estimatedLifeKm = null;
        if ($estimatedLifeRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedLifeRaw)) {
                flash_set('danger', 'Durata estimata (km) trebuie sa fie numerica.');
                redirect($stockRedirectUrl);
            }
            $estimatedLifeKm = max(0, (int) $estimatedLifeRaw);
        }

        $estimatedRemainingKm = null;
        if ($estimatedRemainingRaw !== '') {
            if (!preg_match('/^\d+$/', $estimatedRemainingRaw)) {
                flash_set('danger', 'Km ramasi estimati trebuie sa fie numerici.');
                redirect($stockRedirectUrl);
            }
            $estimatedRemainingKm = max(0, (int) $estimatedRemainingRaw);
        }

        if ($currentMileageRaw === '' || !preg_match('/^\d+$/', $currentMileageRaw)) {
            flash_set('danger', 'Kilometrajul curent trebuie sa fie numeric.');
            redirect($stockRedirectUrl);
        }

        $manufacturingYear = null;
        if ($manufacturingYearRaw !== '') {
            if (!preg_match('/^\d{4}$/', $manufacturingYearRaw)) {
                flash_set('danger', 'Anul fabricatiei trebuie sa aiba format YYYY.');
                redirect($stockRedirectUrl);
            }
            $manufacturingYear = (int) $manufacturingYearRaw;
        }

        if ($purchaseDate !== '' && !$this->isValidDate($purchaseDate)) {
            flash_set('danger', 'Data achizitiei este invalida (format YYYY-MM-DD).');
            redirect($stockRedirectUrl);
        }

        $purchasePrice = null;
        if ($purchasePriceRaw !== '') {
            $normalizedPrice = str_replace(',', '.', $purchasePriceRaw);
            if (!is_numeric($normalizedPrice)) {
                flash_set('danger', 'Pretul de achizitie trebuie sa fie numeric.');
                redirect($stockRedirectUrl);
            }
            $purchasePrice = round((float) $normalizedPrice, 2);
        }

        $allowedStatuses = [
            TireModel::STATUS_IN_STOCK,
            TireModel::STATUS_SPARE,
            TireModel::STATUS_DAMAGED,
            TireModel::STATUS_MISSING,
            TireModel::STATUS_REMOVED,
            TireModel::STATUS_SCRAPPED,
            TireModel::STATUS_ACTIVE,
        ];
        $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : $this->tireModel->normalizeTireStatus((string) ($tire['status'] ?? TireModel::STATUS_IN_STOCK));
        $targetVehicleTypes = $this->tireModel->normalizeTargetVehicleTypes($targetTypesRaw, (string) ($tire['target_vehicle_type'] ?? 'universal'));
        $targetVehicleType = (string) ($targetVehicleTypes[0] ?? 'universal');
        $targetAxleConfig = $targetAxleConfigRaw !== ''
            ? $this->tireModel->normalizeTargetAxleConfig($targetVehicleType, $targetAxleConfigRaw)
            : null;
        if ($tireTypeRaw === '' || !array_key_exists($tireTypeRaw, $this->tireModel->getTireTypeOptions())) {
            flash_set('danger', 'Tipul anvelopei este obligatoriu.');
            redirect($stockRedirectUrl);
        }
        $tireType = $this->tireModel->normalizeTireType($tireTypeRaw);
        $axleType = $this->tireModel->normalizeAxleType($axleTypeRaw);
        if ($axleType === '') {
            $axleType = $this->tireModel->defaultAxleTypeForTireType($tireType);
        }

        if (!$this->tireModel->isTireTypeAllowedForAxleType($axleType, $tireType)) {
            flash_set('danger', 'Combinatia Tip axa / Tip anvelopa nu este valida.');
            redirect($stockRedirectUrl);
        }

        [$invoiceUpload, $invoiceUploadError] = $this->storeUploadedTireInvoice($_FILES['stock_edit_invoice_upload'] ?? null);
        if ($invoiceUploadError !== null) {
            flash_set('danger', $invoiceUploadError);
            redirect($stockRedirectUrl);
        }
        [$profilePhotoUpload, $profilePhotoUploadError] = $this->storeUploadedTirePhoto($_FILES['stock_edit_profile_photo_upload'] ?? null, 'anvelope_profil', 'anvelopa_profil', 'profile_photo_original_name', 'profile_photo_path');
        if ($profilePhotoUploadError !== null) {
            flash_set('danger', $profilePhotoUploadError);
            redirect($stockRedirectUrl);
        }
        [$locationPhotoUpload, $locationPhotoUploadError] = $this->storeUploadedTirePhoto($_FILES['stock_edit_location_photo_upload'] ?? null, 'anvelope_locatii', 'anvelopa_locatie', 'location_photo_original_name', 'location_photo_path');
        if ($locationPhotoUploadError !== null) {
            flash_set('danger', $locationPhotoUploadError);
            redirect($stockRedirectUrl);
        }

        $updateData = [
            'brand' => $brand,
            'model' => $modelName !== '' ? $modelName : null,
            'tire_size' => $tireSize !== '' ? $tireSize : null,
            'dot_code' => $dotCode !== '' ? $dotCode : null,
            'target_vehicle_type' => $targetVehicleType,
            'target_vehicle_types' => $targetVehicleTypes,
            'target_axle_config' => $targetAxleConfig,
            'axle_type' => $axleType,
            'tire_type' => $tireType,
            'usage_compatibility' => $usageCompatibility !== '' ? $usageCompatibility : null,
            'location_label' => $locationLabel !== '' ? $locationLabel : null,
            'manufacturing_year' => $manufacturingYear,
            'purchase_date' => $purchaseDate !== '' ? $purchaseDate : null,
            'purchase_price' => $purchasePrice,
            'supplier' => $supplier !== '' ? $supplier : null,
            'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            'mount_date' => $mountDate,
            'current_mileage' => max(0, (int) $currentMileageRaw),
            'estimated_life_km' => $estimatedLifeKm,
            'estimated_remaining_km' => $estimatedRemainingKm,
            'status' => $status,
            'initial_condition' => $initialConditionRaw,
            'condition_status' => $conditionStatusRaw,
            'season' => in_array($seasonRaw, ['summer', 'winter', 'all_season'], true) ? $seasonRaw : 'all_season',
            'directional' => $directional,
            'rotation_direction' => $rotationDirection !== '' ? $rotationDirection : null,
            'notes' => $notes !== '' ? $notes : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (is_array($invoiceUpload)) {
            $updateData['invoice_document_original_name'] = $invoiceUpload['invoice_document_original_name'] ?? null;
            $updateData['invoice_document_path'] = $invoiceUpload['invoice_document_path'] ?? null;
        }
        if (is_array($profilePhotoUpload)) {
            $updateData['profile_photo_original_name'] = $profilePhotoUpload['profile_photo_original_name'] ?? null;
            $updateData['profile_photo_path'] = $profilePhotoUpload['profile_photo_path'] ?? null;
        }
        if (is_array($locationPhotoUpload)) {
            $updateData['location_photo_original_name'] = $locationPhotoUpload['location_photo_original_name'] ?? null;
            $updateData['location_photo_path'] = $locationPhotoUpload['location_photo_path'] ?? null;
        }

        try {
            $this->tireModel->updateStockTire($tireId, $updateData);
            $this->tireModel->syncTireMaintenanceEntries([$tireId]);

            flash_set('success', 'Datele anvelopei din stoc au fost actualizate.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'actualizare'));
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect($stockRedirectUrl);
    }

    private function deleteMaintenanceTireStockAction(string $moduleKey, array $module): void
    {
        $stockRedirectUrl = $this->maintenanceTireStockUrl();

        if ($moduleKey !== 'mentenanta' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($stockRedirectUrl);
        }

        ensure_csrf_or_redirect($stockRedirectUrl);

        $tireId = (int) ($_POST['stock_tire_id'] ?? 0);
        if ($tireId <= 0) {
            flash_set('danger', 'Anvelopa selectata pentru stergere este invalida.');
            redirect($stockRedirectUrl);
        }

        try {
            $this->tireModel->deleteStockTire($tireId);
            flash_set('success', 'Anvelopa din stoc a fost stearsa.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage('vehicule', $exception, 'stergere'));
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect($stockRedirectUrl);
    }

    private function addDocumentTypeConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_vehicule_override' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));

        $vehicleTypeRaw = strtolower(trim((string) ($_POST['doc_cfg_vehicle_type'] ?? '')));
        if ($vehicleTypeRaw === 'semiremorca') {
            $vehicleTypeRaw = 'semiremorca_primar';
        }

        $documentTypeRaw = trim((string) ($_POST['doc_cfg_document_type'] ?? ''));
        $documentTypeRaw = preg_replace('/\s+/u', ' ', $documentTypeRaw);
        $documentType = is_string($documentTypeRaw) ? trim($documentTypeRaw) : '';


        $vehicleTypeOptions = $this->getDocumentTypeVehicleOptions();
        if (!array_key_exists($vehicleTypeRaw, $vehicleTypeOptions)) {
            flash_set('danger', 'Tipul de vehicul selectat pentru configurare document nu este valid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        if ($documentType === '') {
            flash_set('danger', 'Tipul de document este obligatoriu.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        if (mb_strlen($documentType) > 120) {
            flash_set('danger', 'Tipul de document poate avea maximum 120 caractere.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        $validityDays = 365;

        $documentCost = '0.00';
        $requiresExpiry = isset($_POST['doc_cfg_requires_expiry']) && (string) $_POST['doc_cfg_requires_expiry'] === '1';

        $now = date('Y-m-d H:i:s');
        try {
            $this->moduleModel->insertRecord('configurare_costuri_documente_vehicule', [
                'vehicle_type' => $vehicleTypeRaw,
                'document_type' => $documentType,
                'document_cost' => $documentCost,
                'validity_days' => $validityDays,
                'requires_expiry' => $requiresExpiry ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->syncVehicleStatusesForDocumentTypeConfig(null, $vehicleTypeRaw);
            flash_set('success', 'Tipul de document a fost adaugat pentru ' . vehicle_type_label($vehicleTypeRaw) . '.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());

            if ($sqlState === '23000') {
                flash_set('danger', 'Acest tip de document este deja configurat pentru tipul de vehicul selectat.');
            } elseif ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'unknown column')
                || str_contains($exceptionMessage, 'configurare_costuri_documente_vehicule')
            ) {
                flash_set('danger', 'Structura bazei de date pentru configurarea tipurilor de documente nu este actualizata. Ruleaza scriptul database/update_documente_expiry_requirement.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'salvare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
    }

    private function manageDocumentTypeConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_vehicule_override') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        require_admin_or_403();

        $search = trim((string) ($_GET['q'] ?? ''));

        try {
            $documentTypeRows = $this->documentModel->getConfiguredDocumentTypes();
            $customFieldsByVehicleType = $this->documentModel->getVehicleDocumentCustomFieldConfigsByVehicleType();
        } catch (Throwable $exception) {
            error_log('[ModuleController][manage_document_type_config] ' . $exception->getMessage());
            $exceptionMessage = strtolower($exception->getMessage());
            if (str_contains($exceptionMessage, 'custom_fields_json') || str_contains($exceptionMessage, 'unknown column')) {
                flash_set('danger', 'Structura bazei de date pentru campurile personalizate ale documentelor vehiculelor nu este actualizata. Ruleaza scriptul database/update_vehicle_document_custom_fields.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', 'Nu s-au putut incarca tipurile de documente configurate.');
            }
            $documentTypeRows = [];
            $customFieldsByVehicleType = [];
        }

        if ($search !== '') {
            $searchNeedle = mb_strtolower($search, 'UTF-8');
            $documentTypeRows = array_values(array_filter($documentTypeRows, static function (array $row) use ($searchNeedle): bool {
                $documentType = mb_strtolower((string) ($row['document_type'] ?? ''), 'UTF-8');
                $vehicleType = mb_strtolower(vehicle_type_label((string) ($row['vehicle_type'] ?? '')), 'UTF-8');

                return str_contains($documentType, $searchNeedle) || str_contains($vehicleType, $searchNeedle);
            }));
        }

        render('module/document_type_config.php', [
            'pageTitle' => 'Gestionare tipuri documente',
            'currentPage' => $this->resolveCurrentPage($moduleKey, $module),
            'moduleKey' => $moduleKey,
            'module' => $module,
            'documentTypeRows' => $documentTypeRows,
            'customFieldsByVehicleType' => $customFieldsByVehicleType,
            'customFieldTypeOptions' => $this->documentCustomFieldTypeOptions(),
            'search' => $search,
            'backUrl' => build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']),
        ]);
    }

    private function updateDocumentTypeExpiryAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_vehicule_override' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));

        $id = (int) ($_POST['id'] ?? 0);
        $requiresExpiry = (string) ($_POST['requires_expiry'] ?? '1') === '1';

        if ($id <= 0) {
            flash_set('danger', 'Tipul de document selectat este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        try {
            $configuredDocumentType = $this->documentModel->getConfiguredDocumentTypeById($id);
            $this->documentModel->updateConfiguredDocumentTypeRequiresExpiry($id, $requiresExpiry);
            $this->syncVehicleStatusesForDocumentTypeConfig($configuredDocumentType);
            flash_set('success', 'Regula pentru data de expirare a fost actualizata.');
        } catch (PDOException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'requires_expiry') || str_contains($message, 'unknown column')) {
                flash_set('danger', 'Structura bazei de date nu este actualizata. Ruleaza scriptul database/update_documente_expiry_requirement.sql.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
    }

    private function deleteDocumentTypeConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_vehicule_override' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Tipul de document selectat pentru stergere este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        try {
            $configuredDocumentType = $this->documentModel->getConfiguredDocumentTypeById($id);
            $this->documentModel->deleteConfiguredDocumentType($id);
            $this->syncVehicleStatusesForDocumentTypeConfig($configuredDocumentType);
            flash_set('success', 'Tipul de document a fost sters din lista configurata.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'stergere'));
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
    }

    private function addDocumentCustomFieldConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_vehicule_override' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));

        $documentTypeId = (int) ($_POST['document_type_id'] ?? 0);
        $fieldLabel = trim((string) ($_POST['doc_custom_field_label'] ?? ''));
        $fieldLabel = preg_replace('/\s+/u', ' ', $fieldLabel);
        $fieldLabel = is_string($fieldLabel) ? trim($fieldLabel) : '';
        $fieldType = strtolower(trim((string) ($_POST['doc_custom_field_type'] ?? 'text')));
        $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['doc_custom_field_show_when_checked'] ?? ''));
        $showWhenChecked = is_string($showWhenChecked) ? $showWhenChecked : '';
        $allowedTypes = array_keys($this->documentCustomFieldTypeOptions());

        if ($documentTypeId <= 0) {
            flash_set('danger', 'Tipul de document selectat este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        if ($fieldLabel === '') {
            flash_set('danger', 'Eticheta campului este obligatorie.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        if (mb_strlen($fieldLabel) > 120) {
            flash_set('danger', 'Eticheta campului poate avea maximum 120 de caractere.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        if (!in_array($fieldType, $allowedTypes, true)) {
            flash_set('danger', 'Tipul campului selectat este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        try {
            $documentTypeRow = $this->documentModel->getConfiguredDocumentTypeById($documentTypeId);
            if ($documentTypeRow === null) {
                flash_set('danger', 'Tipul de document selectat nu exista.');
                redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
            }

            $customFields = $this->documentModel->getVehicleDocumentCustomFieldConfigsForVehicleType(
                (string) ($documentTypeRow['vehicle_type'] ?? ''),
                (string) ($documentTypeRow['document_type'] ?? '')
            );
            $checkboxFieldKeys = [];
            foreach ($customFields as $customField) {
                if (mb_strtolower((string) ($customField['label'] ?? ''), 'UTF-8') === mb_strtolower($fieldLabel, 'UTF-8')) {
                    flash_set('danger', 'Exista deja un camp cu aceasta eticheta pentru tipul de document selectat.');
                    redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
                }

                if ((string) ($customField['type'] ?? 'text') === 'checkbox') {
                    $checkboxFieldKeys[(string) ($customField['key'] ?? '')] = true;
                }
            }

            if ($showWhenChecked !== '' && !isset($checkboxFieldKeys[$showWhenChecked])) {
                flash_set('danger', 'Regula de afisare conditionata este invalida. Selecteaza un camp de tip checkbox.');
                redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
            }

            if ($fieldType === 'checkbox') {
                $showWhenChecked = '';
            }

            $customFields[] = [
                'key' => $this->generateDocumentCustomFieldKey(),
                'label' => $fieldLabel,
                'type' => $fieldType,
                'show_when_checked' => $showWhenChecked,
            ];

            $this->documentModel->updateConfiguredDocumentTypeCustomFields($documentTypeId, $customFields);
            $this->syncVehicleStatusesForDocumentTypeConfig($documentTypeRow);
            flash_set('success', 'Campul personalizat a fost adaugat pentru tipul de document selectat.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());

            if ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'custom_fields_json')
                || str_contains($exceptionMessage, 'configurare_costuri_documente_vehicule')
            ) {
                flash_set('danger', 'Structura bazei de date pentru campurile personalizate ale documentelor vehiculelor nu este actualizata. Ruleaza scriptul database/update_vehicle_document_custom_fields.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
    }

    private function deleteDocumentCustomFieldConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_vehicule_override' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));

        $documentTypeId = (int) ($_POST['document_type_id'] ?? 0);
        $fieldKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['custom_field_key'] ?? ''));
        $fieldKey = is_string($fieldKey) ? $fieldKey : '';

        if ($documentTypeId <= 0 || $fieldKey === '') {
            flash_set('danger', 'Campul selectat pentru stergere este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
        }

        try {
            $documentTypeRow = $this->documentModel->getConfiguredDocumentTypeById($documentTypeId);
            if ($documentTypeRow === null) {
                flash_set('danger', 'Tipul de document selectat nu exista.');
                redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
            }

            $customFields = $this->documentModel->getVehicleDocumentCustomFieldConfigsForVehicleType(
                (string) ($documentTypeRow['vehicle_type'] ?? ''),
                (string) ($documentTypeRow['document_type'] ?? '')
            );
            $remainingFields = [];
            foreach ($customFields as $customField) {
                if ((string) ($customField['key'] ?? '') === $fieldKey) {
                    continue;
                }

                if ((string) ($customField['show_when_checked'] ?? '') === $fieldKey) {
                    unset($customField['show_when_checked']);
                }

                $remainingFields[] = $customField;
            }

            $this->documentModel->updateConfiguredDocumentTypeCustomFields($documentTypeId, $remainingFields);
            $this->syncVehicleStatusesForDocumentTypeConfig($documentTypeRow);
            flash_set('success', 'Campul personalizat a fost sters din tipul de document selectat.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());

            if ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'custom_fields_json')
                || str_contains($exceptionMessage, 'configurare_costuri_documente_vehicule')
            ) {
                flash_set('danger', 'Structura bazei de date pentru campurile personalizate ale documentelor vehiculelor nu este actualizata. Ruleaza scriptul database/update_vehicle_document_custom_fields.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']));
    }

    private function addDriverDocumentTypeConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_soferi' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect($this->driverDocumentTypeConfigRedirectUrl());

        $documentTypeRaw = trim((string) ($_POST['driver_doc_cfg_document_type'] ?? ''));
        $documentTypeRaw = preg_replace('/\s+/u', ' ', $documentTypeRaw);
        $documentType = is_string($documentTypeRaw) ? trim($documentTypeRaw) : '';
        $requiresExpiry = (string) ($_POST['driver_doc_cfg_requires_expiry'] ?? '1') === '1';

        if ($documentType === '') {
            flash_set('danger', 'Tipul de document este obligatoriu.');
            redirect($this->driverDocumentTypeConfigRedirectUrl());
        }

        if (mb_strlen($documentType) > 100) {
            flash_set('danger', 'Tipul de document poate avea maximum 100 caractere.');
            redirect($this->driverDocumentTypeConfigRedirectUrl());
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->moduleModel->insertRecord('configurare_documente_obligatorii_soferi', [
                'document_type' => $documentType,
                'requires_expiry' => $requiresExpiry ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->entityStatusService->syncAllDriverStatuses();
            flash_set('success', 'Tipul de document a fost adaugat in lista necesara pentru soferi.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());

            if ($sqlState === '23000') {
                flash_set('danger', 'Acest tip de document este deja configurat in lista necesara pentru soferi.');
            } elseif ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'unknown column')
                || str_contains($exceptionMessage, 'requires_expiry')
                || str_contains($exceptionMessage, 'configurare_documente_obligatorii_soferi')
            ) {
                flash_set('danger', 'Structura bazei de date pentru documentele necesare soferilor nu este actualizata. Ruleaza scripturile database/update_documente_obligatorii_soferi.sql si database/update_driver_document_expiry_requirement.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'salvare'));
            }
        }

        redirect($this->driverDocumentTypeConfigRedirectUrl());
    }

    private function manageDriverDocumentTypeConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_soferi') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
        }

        require_admin_or_403();

        $search = trim((string) ($_GET['q'] ?? ''));

        try {
            $documentTypeRows = $this->documentModel->getConfiguredDriverDocumentTypes();
            $customFieldsByType = $this->documentModel->getDriverDocumentCustomFieldConfigsByType();
        } catch (Throwable $exception) {
            error_log('[ModuleController][manage_driver_document_type_config] ' . $exception->getMessage());
            $exceptionMessage = strtolower($exception->getMessage());
            if (str_contains($exceptionMessage, 'custom_fields_json') || str_contains($exceptionMessage, 'unknown column')) {
                flash_set('danger', 'Structura bazei de date pentru campurile personalizate ale documentelor soferilor nu este actualizata. Ruleaza scriptul database/update_driver_document_custom_fields.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', 'Nu s-au putut incarca tipurile de documente configurate pentru soferi.');
            }
            $documentTypeRows = [];
            $customFieldsByType = [];
        }

        if ($search !== '') {
            $searchNeedle = mb_strtolower($search, 'UTF-8');
            $documentTypeRows = array_values(array_filter($documentTypeRows, static function (array $row) use ($searchNeedle): bool {
                $documentType = mb_strtolower((string) ($row['document_type'] ?? ''), 'UTF-8');

                return str_contains($documentType, $searchNeedle);
            }));
        }

        render('module/driver_document_type_config.php', [
            'pageTitle' => 'Gestionare tipuri documente soferi',
            'currentPage' => $this->resolveCurrentPage($moduleKey, $module),
            'moduleKey' => $moduleKey,
            'module' => $module,
            'documentTypeRows' => $documentTypeRows,
            'customFieldsByType' => $customFieldsByType,
            'customFieldTypeOptions' => $this->driverDocumentCustomFieldTypeOptions(),
            'search' => $search,
            'backUrl' => build_query_url(['page' => 'configurare_costuri_documente_soferi']),
        ]);
    }

    private function updateDriverDocumentTypeExpiryAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_soferi' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));

        $id = (int) ($_POST['id'] ?? 0);
        $requiresExpiry = (string) ($_POST['requires_expiry'] ?? '1') === '1';

        if ($id <= 0) {
            flash_set('danger', 'Tipul de document sofer selectat este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        try {
            $this->documentModel->updateConfiguredDriverDocumentTypeRequiresExpiry($id, $requiresExpiry);
            $this->entityStatusService->syncAllDriverStatuses();
            flash_set('success', 'Regula pentru data de expirare a documentului sofer a fost actualizata.');
        } catch (PDOException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'requires_expiry') || str_contains($message, 'unknown column')) {
                flash_set('danger', 'Structura bazei de date nu este actualizata. Ruleaza scriptul database/update_driver_document_expiry_requirement.sql.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
    }

    private function deleteDriverDocumentTypeConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_soferi' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Tipul de document selectat pentru stergere este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        try {
            $this->documentModel->deleteConfiguredDriverDocumentType($id);

            $this->entityStatusService->syncAllDriverStatuses();

            flash_set('success', 'Tipul de document a fost sters din lista necesara pentru soferi.');
        } catch (PDOException $exception) {
            flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'stergere'));
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
    }

    private function addDriverDocumentCustomFieldConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_soferi' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));

        $documentTypeId = (int) ($_POST['document_type_id'] ?? 0);
        $fieldLabel = trim((string) ($_POST['driver_doc_custom_field_label'] ?? ''));
        $fieldLabel = preg_replace('/\s+/u', ' ', $fieldLabel);
        $fieldLabel = is_string($fieldLabel) ? trim($fieldLabel) : '';
        $fieldType = strtolower(trim((string) ($_POST['driver_doc_custom_field_type'] ?? 'text')));
        $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['driver_doc_custom_field_show_when_checked'] ?? ''));
        $showWhenChecked = is_string($showWhenChecked) ? $showWhenChecked : '';
        $allowedTypes = array_keys($this->driverDocumentCustomFieldTypeOptions());

        if ($documentTypeId <= 0) {
            flash_set('danger', 'Tipul de document selectat este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        if ($fieldLabel === '') {
            flash_set('danger', 'Eticheta campului este obligatorie.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        if (mb_strlen($fieldLabel) > 120) {
            flash_set('danger', 'Eticheta campului poate avea maximum 120 de caractere.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        if (!in_array($fieldType, $allowedTypes, true)) {
            flash_set('danger', 'Tipul campului selectat este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        try {
            $documentTypeRow = $this->documentModel->getConfiguredDriverDocumentTypeById($documentTypeId);
            if ($documentTypeRow === null) {
                flash_set('danger', 'Tipul de document selectat nu exista.');
                redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
            }

            $customFields = $this->documentModel->getDriverDocumentCustomFieldConfigsForDocumentType(
                (string) ($documentTypeRow['document_type'] ?? '')
            );
            $checkboxFieldKeys = [];
            foreach ($customFields as $customField) {
                if (mb_strtolower((string) ($customField['label'] ?? ''), 'UTF-8') === mb_strtolower($fieldLabel, 'UTF-8')) {
                    flash_set('danger', 'Exista deja un camp cu aceasta eticheta pentru tipul de document selectat.');
                    redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
                }

                if ((string) ($customField['type'] ?? 'text') === 'checkbox') {
                    $checkboxFieldKeys[(string) ($customField['key'] ?? '')] = true;
                }
            }

            if ($showWhenChecked !== '' && !isset($checkboxFieldKeys[$showWhenChecked])) {
                flash_set('danger', 'Regula de afisare conditionata este invalida. Selecteaza un camp de tip checkbox.');
                redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
            }

            if ($fieldType === 'checkbox') {
                $showWhenChecked = '';
            }

            $customFields[] = [
                'key' => $this->generateDriverDocumentCustomFieldKey(),
                'label' => $fieldLabel,
                'type' => $fieldType,
                'show_when_checked' => $showWhenChecked,
            ];

            $this->documentModel->updateConfiguredDriverDocumentTypeCustomFields($documentTypeId, $customFields);
            $this->entityStatusService->syncAllDriverStatuses();
            flash_set('success', 'Campul personalizat a fost adaugat pentru tipul de document selectat.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());

            if ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'custom_fields_json')
                || str_contains($exceptionMessage, 'configurare_documente_obligatorii_soferi')
            ) {
                flash_set('danger', 'Structura bazei de date pentru campurile personalizate ale documentelor soferilor nu este actualizata. Ruleaza scriptul database/update_driver_document_custom_fields.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
    }

    private function deleteDriverDocumentCustomFieldConfigAction(string $moduleKey, array $module): void
    {
        if ($moduleKey !== 'configurare_costuri_documente_soferi' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        require_admin_or_403();
        ensure_csrf_or_redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));

        $documentTypeId = (int) ($_POST['document_type_id'] ?? 0);
        $fieldKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['custom_field_key'] ?? ''));
        $fieldKey = is_string($fieldKey) ? $fieldKey : '';

        if ($documentTypeId <= 0 || $fieldKey === '') {
            flash_set('danger', 'Campul selectat pentru stergere este invalid.');
            redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
        }

        try {
            $documentTypeRow = $this->documentModel->getConfiguredDriverDocumentTypeById($documentTypeId);
            if ($documentTypeRow === null) {
                flash_set('danger', 'Tipul de document selectat nu exista.');
                redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
            }

            $customFields = $this->documentModel->getDriverDocumentCustomFieldConfigsForDocumentType(
                (string) ($documentTypeRow['document_type'] ?? '')
            );
            $remainingFields = [];
            foreach ($customFields as $customField) {
                if ((string) ($customField['key'] ?? '') === $fieldKey) {
                    continue;
                }

                if ((string) ($customField['show_when_checked'] ?? '') === $fieldKey) {
                    unset($customField['show_when_checked']);
                }

                $remainingFields[] = $customField;
            }

            $this->documentModel->updateConfiguredDriverDocumentTypeCustomFields($documentTypeId, $remainingFields);
            $this->entityStatusService->syncAllDriverStatuses();
            flash_set('success', 'Campul personalizat a fost sters din tipul de document selectat.');
        } catch (PDOException $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $exceptionMessage = strtolower($exception->getMessage());

            if ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'custom_fields_json')
                || str_contains($exceptionMessage, 'configurare_documente_obligatorii_soferi')
            ) {
                flash_set('danger', 'Structura bazei de date pentru campurile personalizate ale documentelor soferilor nu este actualizata. Ruleaza scriptul database/update_driver_document_custom_fields.sql, apoi incearca din nou.');
            } else {
                flash_set('danger', $this->buildPersistenceErrorMessage($moduleKey, $exception, 'actualizare'));
            }
        }

        redirect(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']));
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
        } elseif (is_trailer_vehicle_type($vehicleType)) {
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
            } elseif (in_array($moduleKey, ['vehicule', 'soferi'], true) && !empty($record['poza_stocata'])) {
                $this->deletePhotoPhysicalFileForModule($moduleKey, (string) $record['poza_stocata']);
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
            $viewData['documentCustomFieldRows'] = $this->buildDocumentCustomFieldDisplayRows($record);
        }

        if ($moduleKey === 'documente_soferi') {
            $viewData['driverDocumentCustomFieldRows'] = $this->buildDriverDocumentCustomFieldDisplayRows($record);
        }

        if ($moduleKey === 'vehicule') {
            $syncedStatusContext = $this->entityStatusService->syncVehicleStatus($id);
            $vehicleDocuments = $this->documentModel->getDocumentsForVehicle($id);
            foreach ($vehicleDocuments as &$vehicleDocument) {
                $vehicleDocument['custom_field_display_rows'] = $this->buildDocumentCustomFieldDisplayRows($vehicleDocument);
            }
            unset($vehicleDocument);

            $viewData['vehicleDocuments'] = $vehicleDocuments;
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
            $driverDocuments = $this->documentModel->getDocumentsForDriver($id);
            foreach ($driverDocuments as &$driverDocument) {
                $driverDocument['custom_field_display_rows'] = $this->buildDriverDocumentCustomFieldDisplayRows($driverDocument);
            }
            unset($driverDocument);

            $viewData['driverDocuments'] = $driverDocuments;
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
            'vehicle_photo', 'vehicle_photo_detail', 'driver_photo', 'driver_photo_detail' => (string) $value,
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

            if (
                ($module['table'] ?? '') === 'vehicule'
                && $key === 'tip_vehicul'
                && is_array($value)
                && in_array('semiremorca_primar', $value, true)
                && !in_array('semiremorca', $value, true)
            ) {
                // Keep legacy trailer rows filterable while older records still use semiremorca.
                $value[] = 'semiremorca';
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    private function buildDocumentPageDriverModule(array $driverDocumentModule): array
    {
        $driverDocumentModule['select'] = 't.*, DATEDIFF(t.data_expirare, CURDATE()) AS zile_expirare, s.nume AS sofer_label, s.telefon AS sofer_telefon, v.nr_inmatriculare AS vehicul_label';
        $driverDocumentModule['default_order'] = 't.data_expirare IS NULL ASC, t.data_expirare ASC, t.id DESC';
        $driverDocumentModule['list_columns'] = [
            'sofer_label' => ['label' => 'Șofer'],
            'vehicul_label' => ['label' => 'Vehicul alocat'],
            'tip_document' => ['label' => 'Tip document'],
            'numar_document' => ['label' => 'Serie / numar'],
            'fisier_original' => ['label' => 'Fisier', 'type' => 'document_file'],
            'data_expirare' => ['label' => 'Data expirare', 'type' => 'expiry'],
            'zile_expirare' => ['label' => 'Zile expirare', 'type' => 'integer'],
            'updated_at' => ['label' => 'Actualizat la', 'type' => 'datetime'],
        ];

        $driverDocumentModule['filters']['vehicle_id'] = [
            'label' => 'Vehicul',
            'type' => 'select',
            'column' => 'v.id',
            'operator' => '=',
            'source' => [
                'table' => 'vehicule',
                'value' => 'id',
                'label' => "CONCAT(nr_inmatriculare, ' - ', marca, ' ', model)",
                'order' => 'nr_inmatriculare ASC',
            ],
        ];

        $driverDocumentModule['filters']['stare_expirare'] = [
            'label' => 'Stare expirare',
            'type' => 'select',
            'options' => [
                'expirate' => 'Expirate',
                'expira_7_zile' => 'Expira in 7 zile',
                'expira_30_zile' => 'Expira in 30 zile',
                'valabile' => 'Valabile peste 30 zile',
                'fara_expirare' => 'Fara expirare',
            ],
            'custom_conditions' => [
                'expirate' => ['sql' => 't.data_expirare < CURDATE()'],
                'expira_7_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'],
                'expira_30_zile' => ['sql' => 't.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
                'valabile' => ['sql' => 't.data_expirare > DATE_ADD(CURDATE(), INTERVAL 30 DAY)'],
                'fara_expirare' => ['sql' => 't.data_expirare IS NULL'],
            ],
        ];

        $driverDocumentModule['filters']['are_fisier'] = [
            'label' => 'Fisier atasat',
            'type' => 'select',
            'options' => [
                'da' => 'Da',
                'nu' => 'Nu',
            ],
            'custom_conditions' => [
                'da' => ['sql' => "COALESCE(t.fisier_stocat, '') <> ''"],
                'nu' => ['sql' => "COALESCE(t.fisier_stocat, '') = ''"],
            ],
        ];

        return $driverDocumentModule;
    }

    private function buildDocumentCostPageDriverModule(array $driverCostModule): array
    {
        $driverCostModule['select'] = 't.*, s.nume AS sofer_label, s.telefon AS sofer_telefon, v.id AS assigned_vehicle_id, v.nr_inmatriculare AS vehicul_label';
        $driverCostModule['list_columns']['sofer_label']['label'] = 'Șofer';
        $driverCostModule['detail_fields']['sofer_label']['label'] = 'Șofer';
        $driverCostModule['detail_fields']['sofer_telefon']['label'] = 'Telefon șofer';
        $driverCostModule['filters']['vehicle_id'] = [
            'label' => 'Vehicul',
            'type' => 'select',
            'column' => 'v.id',
            'operator' => '=',
            'source' => [
                'table' => 'vehicule',
                'value' => 'id',
                'label' => "CONCAT(nr_inmatriculare, ' - ', marca, ' ', model)",
                'where' => "nr_inmatriculare <> 'STOC-ANVELOPE' AND serie_sasiu <> 'STOCANVELOPE00001'",
                'order' => 'nr_inmatriculare ASC',
            ],
        ];

        return $driverCostModule;
    }

    private function applyRemainingValidityDaysToDriverCostRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $driverIds = [];
        foreach ($rows as $row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            if ($driverId > 0) {
                $driverIds[] = $driverId;
            }
        }

        if ($driverIds === []) {
            return $rows;
        }

        try {
            $validityDaysByDriverIdAndType = $this->documentModel->getRemainingValidityDaysByDriverIds($driverIds);
        } catch (Throwable $exception) {
            error_log('[ModuleController][driver-doc-cost-list-validity] ' . $exception->getMessage());
            return $rows;
        }

        foreach ($rows as &$row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            $documentType = trim((string) ($row['document_type'] ?? ''));
            $validityDays = (int) ($validityDaysByDriverIdAndType[(string) $driverId][$documentType] ?? 0);

            if ($driverId > 0 && $documentType !== '' && $validityDays > 0) {
                $row['validity_days'] = $validityDays;
            }
        }
        unset($row);

        return $rows;
    }

    private function applyRemainingValidityDaysToCostFormData(array $formData, string $ownerField, array $validityDaysByOwnerIdAndType): array
    {
        $ownerId = (int) ($formData[$ownerField] ?? 0);
        $documentType = trim((string) ($formData['document_type'] ?? ''));
        if ($ownerId <= 0 || $documentType === '') {
            return $formData;
        }

        $validityDays = (int) ($validityDaysByOwnerIdAndType[(string) $ownerId][$documentType] ?? 0);
        if ($validityDays > 0) {
            $formData['validity_days'] = (string) $validityDays;
        }

        return $formData;
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
            if (!in_array((string) ($meta['type'] ?? ''), ['select', 'multiselect'], true)) {
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
        $driverSource['label'] = 'id';

        try {
            $rows = $this->moduleModel->getSelectOptions($driverSource);
        } catch (Throwable $exception) {
            error_log('[ModuleController][alimentari][driver-vehicle-map] ' . $exception->getMessage());
            return [];
        }

        $driverIds = [];
        foreach ($rows as $row) {
            $driverId = isset($row['value']) ? (int) $row['value'] : 0;
            if ($driverId <= 0) {
                continue;
            }

            $driverIds[$driverId] = $driverId;
        }

        $assignmentMap = $this->moduleModel->getDriverVehicleIdsMap(array_values($driverIds));
        $map = [];
        foreach ($assignmentMap as $driverId => $vehicleIds) {
            $map[(string) $driverId] = array_values(array_filter(array_map('intval', (array) $vehicleIds)));
        }

        return $map;
    }

    private function prepareDriverVehicleAssignmentsData(array &$data, array &$errors, array $input): array
    {
        $vehicleIds = $this->normalizeDriverVehicleIdsFromInput($input['vehicle_ids'] ?? []);

        if ($vehicleIds !== [] && !isset($errors['vehicle_ids'])) {
            $invalidVehicleIds = $this->moduleModel->findInvalidDriverAssignmentVehicleIds($vehicleIds);
            if ($invalidVehicleIds !== []) {
                $errors['vehicle_ids'] = 'Selectia contine vehicule inactive sau neeligibile pentru alocare la sofer.';
            }
        }

        $data['vehicle_id'] = $vehicleIds[0] ?? null;

        return $vehicleIds;
    }

    private function normalizeDriverVehicleIdsFromInput(mixed $rawValue): array
    {
        if (!is_array($rawValue)) {
            $rawValue = [$rawValue];
        }

        $vehicleIds = [];
        foreach ($rawValue as $value) {
            if (is_array($value) || !is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized === '' || !ctype_digit($normalized)) {
                continue;
            }

            $vehicleId = (int) $normalized;
            if ($vehicleId > 0) {
                $vehicleIds[$vehicleId] = $vehicleId;
            }
        }

        return array_values($vehicleIds);
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

    private function applyDynamicDocumentTypeOptions(string $moduleKey, array $module, array $input, ?int $recordId): array
    {
        if ($moduleKey === 'documente_soferi') {
            $driverId = (int) ($input['driver_id'] ?? 0);
            if ($driverId <= 0 && $recordId !== null && $recordId > 0) {
                $existing = $this->moduleModel->findById($module, $recordId);
                if (is_array($existing)) {
                    $driverId = (int) ($existing['driver_id'] ?? 0);
                }
            }

            try {
                $module['form_fields']['tip_document']['options'] = $this->documentModel->getAvailableDriverDocumentTypeOptionsForDriver($driverId, $recordId);
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][dynamic-options] ' . $exception->getMessage());
                $module['form_fields']['tip_document']['options'] = [];
            }

            return $module;
        }

        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            $driverId = (int) ($input['driver_id'] ?? 0);
            if ($driverId <= 0 && $recordId !== null && $recordId > 0) {
                $existing = $this->moduleModel->findById($module, $recordId);
                if (is_array($existing)) {
                    $driverId = (int) ($existing['driver_id'] ?? 0);
                }
            }

            try {
                $module['form_fields']['document_type']['options'] = $this->documentModel->getExistingDriverDocumentTypeOptionsForDriver($driverId);
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][dynamic-cost-options] ' . $exception->getMessage());
                $module['form_fields']['document_type']['options'] = [];
            }

            return $module;
        }

        if (!in_array($moduleKey, ['documente', 'configurare_costuri_documente_vehicule_override'], true)) {
            return $module;
        }

        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        if ($vehicleId <= 0 && $recordId !== null && $recordId > 0) {
            $existing = $this->moduleModel->findById($module, $recordId);
            if (is_array($existing)) {
                $vehicleId = (int) ($existing['vehicle_id'] ?? 0);
            }
        }

        try {
            $documentTypeField = $moduleKey === 'documente' ? 'tip_document' : 'document_type';
            if ($moduleKey === 'documente') {
                $module['form_fields'][$documentTypeField]['options'] = $this->documentModel->getDocumentTypeOptionsForVehicle($vehicleId);
            } else {
                $module['form_fields'][$documentTypeField]['options'] = $this->documentModel->getExistingDocumentTypeOptionsForVehicle($vehicleId);
            }
        } catch (Throwable $exception) {
            error_log('[ModuleController][doc-config][dynamic-options] ' . $exception->getMessage());
            $documentTypeField = $moduleKey === 'documente' ? 'tip_document' : 'document_type';
            $module['form_fields'][$documentTypeField]['options'] = [];
        }

        return $module;
    }

    private function applyDynamicDocumentExpiryRequirement(string $moduleKey, array $module, array &$input, ?int $recordId): array
    {
        if (!in_array($moduleKey, ['documente', 'documente_soferi'], true) || !isset($module['form_fields']['data_expirare'])) {
            return $module;
        }

        $documentType = trim((string) ($input['tip_document'] ?? ''));

        if ($moduleKey === 'documente') {
            $vehicleId = (int) ($input['vehicle_id'] ?? 0);
            if (($vehicleId <= 0 || $documentType === '') && $recordId !== null && $recordId > 0) {
                $existing = $this->moduleModel->findById($module, $recordId);
                if (is_array($existing)) {
                    if ($vehicleId <= 0) {
                        $vehicleId = (int) ($existing['vehicle_id'] ?? 0);
                    }
                    if ($documentType === '') {
                        $documentType = trim((string) ($existing['tip_document'] ?? ''));
                    }
                }
            }

            if ($vehicleId <= 0 || $documentType === '') {
                return $module;
            }

            try {
                $requiresExpiry = $this->documentModel->documentTypeRequiresExpiryForVehicle($vehicleId, $documentType);
            } catch (Throwable $exception) {
                error_log('[ModuleController][doc-config][expiry-requirement] ' . $exception->getMessage());
                $requiresExpiry = true;
            }
        } else {
            if ($documentType === '' && $recordId !== null && $recordId > 0) {
                $existing = $this->moduleModel->findById($module, $recordId);
                if (is_array($existing)) {
                    $documentType = trim((string) ($existing['tip_document'] ?? ''));
                }
            }

            if ($documentType === '') {
                return $module;
            }

            try {
                $requiresExpiry = $this->documentModel->driverDocumentTypeRequiresExpiry($documentType);
            } catch (Throwable $exception) {
                error_log('[ModuleController][driver-doc-config][expiry-requirement] ' . $exception->getMessage());
                $requiresExpiry = true;
            }
        }

        if (!$requiresExpiry) {
            $module['form_fields']['data_expirare']['required'] = false;
            $module['form_fields']['data_expirare']['nullable'] = true;
            $input['data_expirare'] = '';
        }

        return $module;
    }

    private function validateVehicleDocumentTypeSelection(string $moduleKey, array $data, array &$errors): void
    {
        if (isset($errors['vehicle_id'])) {
            return;
        }

        $documentTypeField = $moduleKey === 'documente'
            ? 'tip_document'
            : ($moduleKey === 'configurare_costuri_documente_vehicule_override' ? 'document_type' : null);
        if ($documentTypeField === null) {
            return;
        }

        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $documentType = trim((string) ($data[$documentTypeField] ?? ''));

        if ($vehicleId <= 0) {
            return;
        }

        try {
            if ($moduleKey === 'documente') {
                $allowedOptions = $this->documentModel->getDocumentTypeOptionsForVehicle($vehicleId);
            } else {
                $allowedOptions = $this->documentModel->getExistingDocumentTypeOptionsForVehicle($vehicleId);
            }
        } catch (Throwable $exception) {
            error_log('[ModuleController][doc-config][validate-type] ' . $exception->getMessage());
            $errors[$documentTypeField] = $moduleKey === 'documente'
                ? 'Configurarea costurilor documente pe tip de vehicul nu este disponibila. Ruleaza scriptul database/update_configurare_costuri_documente_vehicule.sql.'
                : 'Nu s-au putut incarca documentele deja adaugate pentru vehiculul selectat.';
            return;
        }

        if ($allowedOptions === []) {
            $errors[$documentTypeField] = $moduleKey === 'documente'
                ? 'Nu exista tipuri de document configurate pentru tipul vehiculului selectat.'
                : 'Vehiculul selectat nu are documente adaugate in modulul Documente.';
            return;
        }

        if ($documentType === '') {
            return;
        }

        if (!array_key_exists($documentType, $allowedOptions)) {
            $errors[$documentTypeField] = $moduleKey === 'documente'
                ? 'Tipul de document selectat nu este permis pentru acest tip de vehicul.'
                : 'Tipul de document selectat nu exista in documentele deja adaugate pentru acest vehicul.';
        }
    }

    private function validateDriverDocumentTypeSelection(array $data, array &$errors, ?int $recordId): void
    {
        if (isset($errors['driver_id']) || isset($errors['tip_document'])) {
            return;
        }

        $driverId = (int) ($data['driver_id'] ?? 0);
        $documentType = trim((string) ($data['tip_document'] ?? ''));
        if ($driverId <= 0 || $documentType === '') {
            return;
        }

        try {
            $allowedOptions = $this->documentModel->getRequiredDriverDocumentTypeOptions();
        } catch (Throwable $exception) {
            error_log('[ModuleController][driver-doc-config][validate-type] ' . $exception->getMessage());
            $errors['tip_document'] = 'Configurarea costurilor documente soferi nu este disponibila. Ruleaza scriptul database/update_configurare_costuri_documente_soferi.sql.';
            return;
        }

        if ($allowedOptions === []) {
            $errors['tip_document'] = 'Nu exista tipuri de document configurate pentru soferi.';
            return;
        }

        if (!array_key_exists($documentType, $allowedOptions)) {
            $errors['tip_document'] = 'Tipul de document selectat nu este permis pentru documentele soferilor.';
            return;
        }

        try {
            if ($this->documentModel->driverDocumentTypeExists($driverId, $documentType, $recordId)) {
                $errors['tip_document'] = 'Acest tip de document este deja adaugat pentru soferul selectat. Foloseste Editare pentru actualizare.';
            }
        } catch (Throwable $exception) {
            error_log('[ModuleController][driver-doc-config][duplicate-check] ' . $exception->getMessage());
            $errors['tip_document'] = 'Nu s-a putut verifica daca documentul exista deja pentru acest sofer.';
        }
    }

    private function validateDriverDocumentCostTypeSelection(array $data, array &$errors): void
    {
        if (isset($errors['driver_id']) || isset($errors['document_type'])) {
            return;
        }

        $driverId = (int) ($data['driver_id'] ?? 0);
        $documentType = trim((string) ($data['document_type'] ?? ''));
        if ($driverId <= 0 || $documentType === '') {
            return;
        }

        try {
            $allowedOptions = $this->documentModel->getExistingDriverDocumentTypeOptionsForDriver($driverId);
        } catch (Throwable $exception) {
            error_log('[ModuleController][driver-doc-config][validate-cost-type] ' . $exception->getMessage());
            $errors['document_type'] = 'Nu s-au putut incarca documentele deja adaugate pentru soferul selectat.';
            return;
        }

        if ($allowedOptions === []) {
            $errors['document_type'] = 'Soferul selectat nu are documente adaugate in modulul Documente soferi.';
            return;
        }

        if (!array_key_exists($documentType, $allowedOptions)) {
            $errors['document_type'] = 'Tipul de document selectat nu exista in documentele deja adaugate pentru acest sofer.';
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
            if (is_array($value)) {
                $sanitizedValues = [];
                foreach ($value as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }

                    $itemValue = trim((string) $item);
                    if ($itemValue !== '') {
                        $sanitizedValues[] = $itemValue;
                    }
                }
                $value = $sanitizedValues;
            } elseif (is_string($value)) {
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

        if (in_array(($module['table'] ?? ''), ['documente', 'documente_soferi'], true)) {
            $customFieldValues = $input['custom_field_values'] ?? [];
            if (is_array($customFieldValues)) {
                $sanitizedCustomFieldValues = [];
                foreach ($customFieldValues as $fieldKey => $value) {
                    $normalizedKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $fieldKey);
                    if (!is_string($normalizedKey) || $normalizedKey === '') {
                        continue;
                    }

                    if (is_scalar($value)) {
                        $sanitizedCustomFieldValues[$normalizedKey] = trim((string) $value);
                    }
                }

                $old['custom_field_values'] = $sanitizedCustomFieldValues;
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

            if ($type === 'multiselect') {
                $rawItems = is_array($input[$field] ?? null) ? (array) $input[$field] : [];
                $values = [];
                foreach ($rawItems as $rawItem) {
                    if (!is_scalar($rawItem)) {
                        continue;
                    }

                    $itemValue = trim((string) $rawItem);
                    if ($itemValue !== '') {
                        $values[$itemValue] = $itemValue;
                    }
                }
                $values = array_values($values);
                $rawValues[$field] = $values;

                if ($values === []) {
                    if ($required) {
                        $errors[$field] = 'Camp obligatoriu.';
                    }

                    if ($store) {
                        $data[$column] = ($meta['nullable'] ?? false) ? null : '';
                    }

                    continue;
                }

                if (isset($meta['options'])) {
                    foreach ($values as $itemValue) {
                        if (!array_key_exists((string) $itemValue, $meta['options'])) {
                            $errors[$field] = 'Selectia contine valori invalide.';
                            continue 2;
                        }
                    }
                }

                if (isset($meta['source'])) {
                    $idValues = [];
                    foreach ($values as $itemValue) {
                        if (!ctype_digit((string) $itemValue)) {
                            $errors[$field] = 'Selectia contine valori invalide.';
                            continue 2;
                        }

                        $idValue = (int) $itemValue;
                        if (!$this->moduleModel->existsId($meta['source']['table'], $idValue)) {
                            $errors[$field] = 'Selectia contine inregistrari inexistente.';
                            continue 2;
                        }

                        $idValues[] = $idValue;
                    }

                    $values = $idValues;
                }

                if ($store) {
                    $data[$column] = implode(',', array_map('strval', $values));
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

            if ($type === 'date') {
                $normalizedDate = $this->normalizeDateInput((string) $value);
                if ($normalizedDate === null) {
                    $errors[$field] = 'Data nu este valida. Formate acceptate: DD/MM/YYYY sau YYYY-MM-DD.';
                    continue;
                }

                $value = $normalizedDate;
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

    private function normalizeDateInput(string $date): ?string
    {
        $raw = trim($date);
        if ($raw === '') {
            return '';
        }

        if ($this->isValidDate($raw)) {
            return $raw;
        }

        foreach (['d/m/Y', 'd.m.Y', 'd-m-Y'] as $format) {
            $dateTime = DateTime::createFromFormat($format, $raw);
            if ($dateTime instanceof DateTime && $dateTime->format($format) === $raw) {
                return $dateTime->format('Y-m-d');
            }
        }

        return null;
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
            return 'Structura bazei de date pentru campurile Tip vehicul/Km bord nu este actualizata. Ruleaza scriptul database/update_vehicle_camion_km.sql, apoi incearca din nou.';
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

        if ($moduleKey === 'configurare_costuri_documente_vehicule_override'
            && ($sqlState === '42S22'
                || $sqlState === '42S02'
                || str_contains($exceptionMessage, 'unknown column')
                || str_contains($exceptionMessage, 'configurare_costuri_documente_vehicule_override'))
        ) {
            return 'Structura bazei de date pentru configurarea costurilor individuale pe vehicul nu este actualizata. Ruleaza scriptul database/update_configurare_costuri_documente_vehicule_override.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'documente'
            && (
                str_contains($exceptionMessage, 'custom_fields_json')
                || str_contains($exceptionMessage, 'documente')
            )
            && ($sqlState === '42S22' || str_contains($exceptionMessage, 'unknown column'))
        ) {
            return 'Structura bazei de date pentru campurile personalizate ale documentelor vehiculelor nu este actualizata. Ruleaza scriptul database/update_vehicle_document_custom_fields.sql, apoi incearca din nou.';
        }

        if ($moduleKey === 'documente_soferi'
            && (
                str_contains($exceptionMessage, 'custom_fields_json')
                || str_contains($exceptionMessage, 'documente_soferi')
            )
            && ($sqlState === '42S22' || str_contains($exceptionMessage, 'unknown column'))
        ) {
            return 'Structura bazei de date pentru campurile personalizate ale documentelor soferilor nu este actualizata. Ruleaza scriptul database/update_driver_document_custom_fields.sql, apoi incearca din nou.';
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

    private function storeUploadedDriverPhoto(?array $file): array
    {
        return $this->storeUploadedAssetFile($file, 'soferi', 'sofer', 'poza_original', 'poza_stocata');
    }

    private function storeUploadedTirePhoto(?array $file, string $directory, string $prefix, string $originalColumn, string $storedColumn): array
    {
        if ($file === null || !is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $originalName = $this->sanitizeUploadedFileName((string) ($file['name'] ?? $prefix));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                return [null, 'Tipul fisierului pentru poza anvelopei nu este permis.'];
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size > 5 * 1024 * 1024) {
                return [null, 'Poza anvelopei trebuie sa fie mai mica de 5 MB.'];
            }
        }

        return $this->storeUploadedAssetFile($file, $directory, $prefix, $originalColumn, $storedColumn);
    }

    private function storeUploadedTireInvoice(?array $file): array
    {
        if ($file === null || !is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $originalName = $this->sanitizeUploadedFileName((string) ($file['name'] ?? 'factura'));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], true)) {
                return [null, 'Tipul fisierului pentru factura nu este permis.'];
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size > 5 * 1024 * 1024) {
                return [null, 'Factura incarcata trebuie sa fie mai mica de 5 MB.'];
            }
        }

        return $this->storeUploadedAssetFile($file, 'anvelope_facturi', 'anvelopa_factura', 'invoice_document_original_name', 'invoice_document_path');
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

    private function cleanupUploadedDriverPhoto(array $uploadedFileData): void
    {
        $storedFile = $uploadedFileData['poza_stocata'] ?? null;

        if (!is_string($storedFile) || trim($storedFile) === '') {
            return;
        }

        $this->deleteDriverPhotoPhysicalFile($storedFile);
    }

    private function cleanupUploadedPhotoForModule(string $moduleKey, array $uploadedFileData): void
    {
        if ($moduleKey === 'soferi') {
            $this->cleanupUploadedDriverPhoto($uploadedFileData);
            return;
        }

        $this->cleanupUploadedVehiclePhoto($uploadedFileData);
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

    private function deleteDriverPhotoPhysicalFile(string $storedFile): void
    {
        if (trim($storedFile) === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/soferi/' . $storedFile;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function deletePhotoPhysicalFileForModule(string $moduleKey, string $storedFile): void
    {
        if ($moduleKey === 'soferi') {
            $this->deleteDriverPhotoPhysicalFile($storedFile);
            return;
        }

        $this->deleteVehiclePhotoPhysicalFile($storedFile);
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
        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            return build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']);
        }

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

    private function driverDocumentTypeConfigRedirectUrl(): string
    {
        if ((string) ($_POST['return_to'] ?? '') === 'manage') {
            return build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config']);
        }

        return build_query_url(['page' => 'configurare_costuri_documente_soferi']);
    }

    private function documentCustomFieldTypeOptions(): array
    {
        return $this->driverDocumentCustomFieldTypeOptions();
    }

    private function generateDocumentCustomFieldKey(): string
    {
        try {
            return 'vcf_' . bin2hex(random_bytes(6));
        } catch (Throwable) {
            return 'vcf_' . str_replace('.', '', uniqid('', true));
        }
    }

    private function driverDocumentCustomFieldTypeOptions(): array
    {
        return [
            'text' => 'Text',
            'number' => 'Numeric',
            'date' => 'Data',
            'checkbox' => 'Checkbox',
        ];
    }

    private function generateDriverDocumentCustomFieldKey(): string
    {
        try {
            return 'dcf_' . bin2hex(random_bytes(6));
        } catch (Throwable) {
            return 'dcf_' . str_replace('.', '', uniqid('', true));
        }
    }

    private function decodeDriverDocumentTypeCustomFields(mixed $rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowedTypes = array_keys($this->driverDocumentCustomFieldTypeOptions());
        $rows = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['show_when_checked'] ?? ''));

            if (!is_string($key) || $key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $rows[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'show_when_checked' => is_string($showWhenChecked) ? $showWhenChecked : '',
            ];
        }

        $checkboxKeys = [];
        foreach ($rows as $fieldKey => $row) {
            if (($row['type'] ?? 'text') === 'checkbox') {
                $checkboxKeys[$fieldKey] = true;
            }
        }

        foreach ($rows as $fieldKey => &$row) {
            $showWhenChecked = (string) ($row['show_when_checked'] ?? '');
            if (
                $showWhenChecked === ''
                || $showWhenChecked === $fieldKey
                || !isset($checkboxKeys[$showWhenChecked])
            ) {
                unset($row['show_when_checked']);
            }
        }
        unset($row);

        return array_values($rows);
    }

    private function decodeDriverDocumentCustomFieldValueSnapshot(mixed $rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowedTypes = array_keys($this->driverDocumentCustomFieldTypeOptions());
        $rows = [];

        foreach ($decoded as $fieldKey => $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['key'] ?? $fieldKey));
            $label = trim((string) ($item['label'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            $value = $item['value'] ?? '';

            if (!is_scalar($value)) {
                continue;
            }

            if (!is_string($key) || $key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $rows[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'value' => trim((string) $value),
            ];
        }

        return $rows;
    }

    private function extractDriverDocumentCustomFieldValuesForForm(array $formData): array
    {
        $postedValues = $formData['custom_field_values'] ?? null;
        if (is_array($postedValues)) {
            $values = [];
            foreach ($postedValues as $fieldKey => $value) {
                $normalizedKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $fieldKey);
                if (!is_string($normalizedKey) || $normalizedKey === '' || !is_scalar($value)) {
                    continue;
                }

                $values[$normalizedKey] = trim((string) $value);
            }

            return $values;
        }

        $snapshotRows = $this->decodeDriverDocumentCustomFieldValueSnapshot($formData['custom_fields_json'] ?? null);
        $values = [];
        foreach ($snapshotRows as $fieldKey => $row) {
            $values[$fieldKey] = (string) ($row['value'] ?? '');
        }

        return $values;
    }

    private function extractDocumentCustomFieldValuesForForm(array $formData): array
    {
        return $this->extractDriverDocumentCustomFieldValuesForForm($formData);
    }

    private function validateDocumentCustomFieldValues(array $data, array $input): array
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $documentType = trim((string) ($data['tip_document'] ?? ''));
        if ($vehicleId <= 0 || $documentType === '') {
            return [null, [], false];
        }

        $postedValues = $input['custom_field_values'] ?? null;
        if (!is_array($postedValues)) {
            return [null, [], false];
        }

        $configs = $this->documentModel->getVehicleDocumentCustomFieldConfigsForVehicle($vehicleId, $documentType);
        if ($configs === []) {
            return [null, [], false];
        }

        return $this->validateCustomFieldValuesFromConfigs($configs, $postedValues);
    }

    private function validateDriverDocumentCustomFieldValues(array $data, array $input): array
    {
        $documentType = trim((string) ($data['tip_document'] ?? ''));
        if ($documentType === '') {
            return [null, [], false];
        }

        $postedValues = $input['custom_field_values'] ?? null;
        if (!is_array($postedValues)) {
            return [null, [], false];
        }

        $configs = $this->documentModel->getDriverDocumentCustomFieldConfigsForDocumentType($documentType);
        if ($configs === []) {
            return [null, [], false];
        }

        return $this->validateCustomFieldValuesFromConfigs($configs, $postedValues);
    }

    private function validateCustomFieldValuesFromConfigs(array $configs, array $postedValues): array
    {
        $errors = [];
        $payload = [];
        $checkboxStates = [];

        foreach ($configs as $config) {
            $fieldKey = (string) ($config['key'] ?? '');
            $fieldType = strtolower(trim((string) ($config['type'] ?? 'text')));

            if ($fieldKey === '' || $fieldType !== 'checkbox') {
                continue;
            }

            $rawValue = $postedValues[$fieldKey] ?? '';
            if (!is_scalar($rawValue)) {
                $rawValue = '';
            }

            $checkboxStates[$fieldKey] = $this->isDriverDocumentCustomCheckboxChecked($rawValue);
        }

        foreach ($configs as $config) {
            $fieldKey = (string) ($config['key'] ?? '');
            $fieldLabel = trim((string) ($config['label'] ?? ''));
            $fieldType = strtolower(trim((string) ($config['type'] ?? 'text')));
            $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($config['show_when_checked'] ?? ''));

            if ($fieldKey === '' || $fieldLabel === '') {
                continue;
            }

            if (
                is_string($showWhenChecked)
                && $showWhenChecked !== ''
                && !($checkboxStates[$showWhenChecked] ?? false)
            ) {
                continue;
            }

            $rawValue = $postedValues[$fieldKey] ?? '';
            if (!is_scalar($rawValue)) {
                $rawValue = '';
            }

            $rawValue = trim((string) $rawValue);
            $normalizedValue = $rawValue;

            if ($fieldType === 'checkbox') {
                if (!($checkboxStates[$fieldKey] ?? false)) {
                    continue;
                }

                $normalizedValue = '1';
            } elseif ($rawValue === '') {
                if ($fieldType === 'date' && is_string($showWhenChecked) && $showWhenChecked !== '') {
                    $errors[$fieldKey] = 'Introdu data de expirare pentru campul afisat.';
                }
                continue;
            } elseif ($fieldType === 'date') {
                $normalizedDate = $this->normalizeDateInput($rawValue);
                if ($normalizedDate === null) {
                    $errors[$fieldKey] = 'Introdu o data valida pentru acest camp.';
                    continue;
                }

                $normalizedValue = $normalizedDate;
            } elseif ($fieldType === 'number') {
                $normalizedNumber = str_replace(',', '.', $rawValue);
                if (!preg_match('/^-?\d+(?:\.\d+)?$/', $normalizedNumber)) {
                    $errors[$fieldKey] = 'Introdu o valoare numerica valida.';
                    continue;
                }

                $normalizedValue = $normalizedNumber;
            } elseif (mb_strlen($rawValue) > 255) {
                $errors[$fieldKey] = 'Textul este prea lung (maxim 255 de caractere).';
                continue;
            }

            $payload[$fieldKey] = [
                'key' => $fieldKey,
                'label' => $fieldLabel,
                'type' => $fieldType,
                'value' => $normalizedValue,
            ];
        }

        if ($payload === []) {
            return [null, $errors, true];
        }

        return [
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $errors,
            true,
        ];
    }

    private function buildDocumentCustomFieldDisplayRows(array $record): array
    {
        $savedRows = $this->decodeDriverDocumentCustomFieldValueSnapshot($record['custom_fields_json'] ?? null);
        if ($savedRows === []) {
            return [];
        }

        $vehicleId = (int) ($record['vehicle_id'] ?? 0);
        $documentType = trim((string) ($record['tip_document'] ?? ''));
        if ($vehicleId <= 0 || $documentType === '') {
            return array_values($savedRows);
        }

        try {
            $configuredRows = $this->documentModel->getVehicleDocumentCustomFieldConfigsForVehicle($vehicleId, $documentType);
        } catch (Throwable $exception) {
            error_log('[ModuleController][doc-config][display-custom-fields] ' . $exception->getMessage());
            return array_values($savedRows);
        }

        return $this->orderCustomFieldDisplayRows($savedRows, $configuredRows);
    }

    private function buildDriverDocumentCustomFieldDisplayRows(array $record): array
    {
        $savedRows = $this->decodeDriverDocumentCustomFieldValueSnapshot($record['custom_fields_json'] ?? null);
        if ($savedRows === []) {
            return [];
        }

        $documentType = trim((string) ($record['tip_document'] ?? ''));
        if ($documentType === '') {
            return array_values($savedRows);
        }

        try {
            $configuredRows = $this->documentModel->getDriverDocumentCustomFieldConfigsForDocumentType($documentType);
        } catch (Throwable $exception) {
            error_log('[ModuleController][driver-doc-config][display-custom-fields] ' . $exception->getMessage());
            return array_values($savedRows);
        }

        return $this->orderCustomFieldDisplayRows($savedRows, $configuredRows);
    }

    private function orderCustomFieldDisplayRows(array $savedRows, array $configuredRows): array
    {
        $orderedRows = [];
        $usedKeys = [];

        foreach ($configuredRows as $configuredRow) {
            $fieldKey = (string) ($configuredRow['key'] ?? '');
            if ($fieldKey === '' || !isset($savedRows[$fieldKey])) {
                continue;
            }

            $orderedRows[] = [
                'key' => $fieldKey,
                'label' => trim((string) ($configuredRow['label'] ?? $savedRows[$fieldKey]['label'] ?? '')),
                'type' => strtolower(trim((string) ($configuredRow['type'] ?? $savedRows[$fieldKey]['type'] ?? 'text'))),
                'value' => (string) ($savedRows[$fieldKey]['value'] ?? ''),
            ];
            $usedKeys[$fieldKey] = true;
        }

        foreach ($savedRows as $fieldKey => $savedRow) {
            if (isset($usedKeys[$fieldKey])) {
                continue;
            }

            $orderedRows[] = $savedRow;
        }

        return $orderedRows;
    }

    private function isDriverDocumentCustomCheckboxChecked(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'on', 'yes', 'da'], true);
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
            return;
        }

        if ($moduleKey === 'configurare_costuri_documente_soferi') {
            $driverIds = array_unique(array_filter([
                (int) ($previousRecord['driver_id'] ?? 0),
                (int) ($currentRecord['driver_id'] ?? 0),
            ]));

            foreach ($driverIds as $driverId) {
                $this->entityStatusService->syncDriverStatus((int) $driverId);
            }
        }
    }

    private function syncVehicleStatusesForDocumentTypeConfig(?array $configRow, ?string $fallbackVehicleType = null): void
    {
        $vehicleType = trim((string) ($configRow['vehicle_type'] ?? $fallbackVehicleType ?? ''));
        if ($vehicleType === '') {
            return;
        }

        try {
            $this->entityStatusService->syncVehicleStatusesByConfiguredType($vehicleType);
        } catch (Throwable $exception) {
            error_log('[ModuleController][doc-type-config][status-sync] ' . $exception->getMessage());
        }
    }

    private function getVehicleMountedTireCountSafe(int $vehicleId): int
    {
        if ($vehicleId <= 0) {
            return 0;
        }

        try {
            return $this->tireModel->countMountedTiresForVehicle($vehicleId);
        } catch (Throwable $exception) {
            error_log('[ModuleController][vehicule][mounted-tires-count] ' . $exception->getMessage());
            return 0;
        }
    }

    private function getDocumentTypeVehicleOptions(): array
    {
        return [
            'cap_tractor' => 'Cap tractor',
            'semiremorca_primar' => 'Semi-remorca primar',
            'semiremorca_distributie' => 'Semi-remorca distributie',
            'camion' => 'Camion',
            'autovehicul' => 'Autoturism',
        ];
    }

    private function resolveRemainingValidityDaysForVehicleDocument(int $vehicleId, string $documentType): ?int
    {
        $documentType = trim($documentType);
        if ($vehicleId <= 0 || $documentType === '') {
            return null;
        }

        try {
            $map = $this->documentModel->getRemainingValidityDaysByVehicleIds([$vehicleId]);
        } catch (Throwable $exception) {
            error_log('[ModuleController][doc-config][resolve-remaining-validity-days] ' . $exception->getMessage());
            return null;
        }

        $validityDays = (int) ($map[(string) $vehicleId][$documentType] ?? 0);
        if ($validityDays > 0) {
            return $validityDays;
        }

        return null;
    }

    private function resolveRemainingValidityDaysForDriverDocument(int $driverId, string $documentType): ?int
    {
        $documentType = trim($documentType);
        if ($driverId <= 0 || $documentType === '') {
            return null;
        }

        try {
            $map = $this->documentModel->getRemainingValidityDaysByDriverIds([$driverId]);
        } catch (Throwable $exception) {
            error_log('[ModuleController][driver-doc-config][resolve-remaining-validity-days] ' . $exception->getMessage());
            return null;
        }

        $validityDays = (int) ($map[(string) $driverId][$documentType] ?? 0);
        if ($validityDays > 0) {
            return $validityDays;
        }

        return null;
    }

    private function buildVehicleLayoutOptionsByType(): array
    {
        return [
            'autovehicul' => $this->tireModel->getLayoutOptionsByVehicleType('autovehicul'),
            'autoutilitara' => $this->tireModel->getLayoutOptionsByVehicleType('autovehicul'),
            'camion' => $this->tireModel->getLayoutOptionsByVehicleType('camion'),
            'cap_tractor' => $this->tireModel->getLayoutOptionsByVehicleType('cap_tractor'),
            'semiremorca' => $this->tireModel->getLayoutOptionsByVehicleType('semiremorca'),
            'semiremorca_primar' => $this->tireModel->getLayoutOptionsByVehicleType('semiremorca'),
            'semiremorca_distributie' => $this->tireModel->getLayoutOptionsByVehicleType('semiremorca'),
        ];
    }
}
