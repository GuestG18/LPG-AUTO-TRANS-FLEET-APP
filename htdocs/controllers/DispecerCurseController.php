<?php
declare(strict_types=1);
class DispecerCurseController
{
    private PDO $db;
    private DispecerCurseModel $model;
    private InactiveResourceStatusService $inactiveStatusService;
    private InactiveResourceApprovalModel $inactiveApprovalModel;

    private const TRANSPORT_TYPES = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar+Distributie',
        'compresor' => 'Compresor',
    ];

    private const EXPENSE_TYPES = [
        'motorina' => 'Motorina',
        'taxe_drum' => 'Taxe drum',
        'diurna' => 'Diurna',
        'service' => 'Reparatii',
        'alte' => 'Alte cheltuieli',
    ];
    private const FUEL_EXPENSE_TYPE = 'motorina';

    private const GOODS_TYPES = [
        'butan' => 'Butan',
        'propan' => 'Propan',
        'autogaz' => 'Autogaz',
    ];

    private const BILLING_STATUSES = [
        'in_curs_facturare' => 'in curs de facturare',
        'facturat' => 'facturat',
        'nefacturat' => 'nefacturat',
    ];

    private const DEFAULT_BILLING_STATUS = 'in_curs_facturare';
    private const ROAD_TAX_DETAILS_MARKER = '[ROAD_TAX_DETAILS]';
    private const DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE = 'distributie';
    private const DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE = 'primar_distributie';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH = 'tona_km';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_TON = 'tona';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_KM = 'km';

    private const DISTRIBUTION_ROUTE_TARIFF_MODE_LABELS = [
        self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH => 'Pret tona + Pret km',
        self::DISTRIBUTION_ROUTE_TARIFF_MODE_TON => 'Doar Pret tona',
        self::DISTRIBUTION_ROUTE_TARIFF_MODE_KM => 'Doar Pret km',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new DispecerCurseModel($db);
        $this->inactiveStatusService = new InactiveResourceStatusService($db);
        $this->inactiveApprovalModel = new InactiveResourceApprovalModel($db);
    }

    private function deletedRaceTransportTypes(): array
    {
        return [
            'primar' => 'Primar',
            'primar_tona' => 'Primar tone',
            'distributie' => 'Distribuție',
            'primar_distributie' => 'Primar+Distribuție',
            'compresor' => 'Compresor',
        ];
    }

    private function expenseEntryTypes(): array
    {
        $types = self::EXPENSE_TYPES;
        unset($types[self::FUEL_EXPENSE_TYPE]);

        return $types;
    }

    private function expenseCategories(): array
    {
        return $this->model->getExpenseCategories(true);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'inactive_resource_status':
                $this->inactiveResourceStatusAction();
                return;
            case 'request_inactive_vehicle_approval':
                $this->requestInactiveVehicleApprovalAction();
                return;
            case 'cancel_inactive_vehicle_approval':
                $this->cancelInactiveVehicleApprovalAction();
                return;
            case 'store':
                $this->storeAction();
                return;
            case 'edit':
                $this->editAction();
                return;
            case 'update':
                $this->updateAction();
                return;
            case 'delete':
                $this->deleteAction();
                return;
            case 'delete_bulk':
                $this->deleteBulkAction();
                return;
            case 'save_expense':
                $this->saveExpenseAction();
                return;
            case 'delete_expense':
                $this->deleteExpenseAction();
                return;
            case 'update_status':
                $this->updateStatusAction();
                return;
            case 'update_expense_status':
                $this->updateExpenseStatusAction();
                return;
            case 'config':
                $this->configAction();
                return;
            case 'config_v2':
                $this->configAction(true);
                return;
            case 'refacturari':
                $this->refacturariAction();
                return;
            case 'curse_sterse':
                $this->requireDeletedRacesAdminAccess();
                $this->deletedRacesAction();
                return;
            case 'curse_sterse_details':
                $this->requireDeletedRacesAdminAccess();
                $this->deletedRaceDetailsAction();
                return;
            case 'restore_cursa':
                $this->requireDeletedRacesAdminAccess();
                $this->restoreDeletedRaceAction();
                return;
            case 'delete_cursa_stearsa':
                $this->requireDeletedRacesAdminAccess();
                $this->permanentlyDeleteDeletedRaceAction();
                return;
            case 'store_refacturare':
                $this->storeRefacturareAction();
                return;
            case 'toggle_refacturare_facturata':
                $this->toggleRefacturareInvoicedAction();
                return;
            case 'config_store_distributie':
                $this->configStoreDistributionAction();
                return;
            case 'config_store_primar_ruta':
                $this->configStorePrimaryRouteAction();
                return;
            case 'config_store_catalog':
                $this->configStoreCatalogAction();
                return;
            case 'config_delete_ruta':
                $this->configDeleteDistributionRouteAction();
                return;
            case 'config_delete_ruta_primar':
                $this->configDeletePrimaryRouteAction();
                return;
            case 'config_store_loc':
                $this->configStoreLocationAction();
                return;
            case 'config_delete_loc':
                $this->configDeleteLocationAction();
                return;
            case 'config_store_zona':
                $this->configStoreZoneAction();
                return;
            case 'config_delete_zona':
                $this->configDeleteZoneAction();
                return;
            case 'config_store_beneficiar':
                $this->configStoreBeneficiaryAction();
                return;
            case 'config_delete_beneficiar':
                $this->configDeleteBeneficiaryAction();
                return;
            case 'config_delete_beneficiari':
                $this->configDeleteBeneficiariesAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'dispecer_curse',
                ]);
                return;
        }
    }

    private function requireDeletedRacesAdminAccess(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        if ($this->wantsJson()) {
            $this->sendJson([
                'success' => false,
                'message' => 'Acces permis doar administratorilor.',
            ], 403);
        }

        http_response_code(403);
        render('errors/403.php', [
            'pageTitle' => 'Acces interzis',
            'currentPage' => '',
        ]);
        exit;
    }

    private function inactiveResourceStatusAction(): void
    {
        $vehicleId = $this->positiveIntFromInput($_GET['vehicle_id'] ?? null);
        $driverId = $this->positiveIntFromInput($_GET['driver_id'] ?? null);
        $tripId = $this->positiveIntFromInput($_GET['trip_id'] ?? null);
        $normalUserMode = !$this->canReviewInactiveApprovals();

        try {
            $status = $this->inactiveStatusService->getResourcesStatus($vehicleId, $driverId);
            foreach ($status['resources'] as &$resource) {
                $resourceType = $this->approvalResourceTypeForInactiveResource($resource);
                $resourceId = (int) ($resource['resource_id'] ?? 0);
                $resource['approval_resource_type'] = $resourceType;
                $resource['existing_approval_status'] = null;
                $resource['user_pending_approval'] = null;

                if (!empty($resource['is_inactive']) && $tripId !== null && $resourceId > 0) {
                    $resource['existing_approval_status'] = $this->inactiveApprovalModel
                        ->getExistingOpenStatusForResourceTrip($resourceType, $resourceId, $tripId);
                }

                if (!empty($resource['is_inactive']) && $normalUserMode && $resourceId > 0) {
                    $pendingApproval = $this->pendingRequesterApprovalForInactiveResource($resource);
                    if ($pendingApproval !== null) {
                        $resource['existing_approval_status'] = 'pending';
                        $resource['user_pending_approval'] = $this->inactiveApprovalModalPayload($pendingApproval, $resource);
                    }
                }
            }
            unset($resource);

            $status['inactive_resources'] = array_values(array_filter(
                $status['resources'],
                static fn(array $resource): bool => !empty($resource['is_inactive'])
            ));
            $status['has_inactive_resources'] = $status['inactive_resources'] !== [];

            $this->sendJson([
                'success' => true,
                'resources' => $status['resources'],
                'inactive_resources' => $status['inactive_resources'],
                'has_inactive_resources' => $status['has_inactive_resources'],
            ]);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][inactive_resource_status] ' . $exception->getMessage());
            $this->sendJson([
                'success' => false,
                'message' => 'Nu s-a putut verifica statusul resurselor inactive.',
            ], 500);
        }
    }

    private function requestInactiveVehicleApprovalAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson([
                'success' => false,
                'message' => 'Metoda invalida pentru solicitarea aprobarii.',
            ], 405);
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->sendJson([
                'success' => false,
                'message' => 'Token CSRF invalid. Reincearca operatiunea.',
            ], 419);
        }

        if ($this->canReviewInactiveApprovals()) {
            $this->sendJson([
                'success' => false,
                'message' => 'Fluxul de solicitare este disponibil doar utilizatorilor normali.',
            ], 403);
        }

        $userId = (int) ($this->currentUserId() ?? 0);
        if ($userId <= 0) {
            $this->sendJson([
                'success' => false,
                'message' => 'Trebuie sa fii autentificat pentru a solicita aprobarea.',
            ], 401);
        }

        $vehicleId = $this->positiveIntFromInput($_POST['vehicle_id'] ?? null);
        if ($vehicleId === null) {
            $this->sendJson([
                'success' => false,
                'message' => 'Vehicul invalid.',
            ], 422);
        }

        try {
            $resource = $this->inactiveStatusService->getVehicleStatus($vehicleId);
            if ($resource === null) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Vehiculul nu a fost gasit.',
                ], 404);
            }

            if (empty($resource['is_inactive'])) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Vehiculul selectat nu este inactiv.',
                ], 409);
            }

            $resource['approval_resource_type'] = $this->approvalResourceTypeForInactiveResource($resource);
            $approval = $this->inactiveApprovalModel->createPendingForRequesterResource($resource, $userId);
            if ($approval === null) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Solicitarea nu a putut fi creata.',
                ], 500);
            }

            $approvalPayload = $this->inactiveApprovalModalPayload($approval, $resource);
            $resource['existing_approval_status'] = 'pending';
            $resource['user_pending_approval'] = $approvalPayload;

            $this->sendJson([
                'success' => true,
                'message' => 'Solicitarea de aprobare a fost trimisa.',
                'approval' => $approvalPayload,
                'resource' => $resource,
            ]);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][request_inactive_vehicle_approval] ' . $exception->getMessage());
            $this->sendJson([
                'success' => false,
                'message' => 'Solicitarea nu a putut fi trimisa. Reincearca.',
            ], 500);
        }
    }

    private function cancelInactiveVehicleApprovalAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson([
                'success' => false,
                'message' => 'Metoda invalida pentru anularea solicitarii.',
            ], 405);
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->sendJson([
                'success' => false,
                'message' => 'Token CSRF invalid. Reincearca operatiunea.',
            ], 419);
        }

        if ($this->canReviewInactiveApprovals()) {
            $this->sendJson([
                'success' => false,
                'message' => 'Anularea din acest flux este disponibila doar utilizatorilor normali.',
            ], 403);
        }

        $userId = (int) ($this->currentUserId() ?? 0);
        $approvalId = $this->positiveIntFromInput($_POST['approval_id'] ?? null);
        if ($userId <= 0 || $approvalId === null) {
            $this->sendJson([
                'success' => false,
                'message' => 'Solicitare invalida.',
            ], 422);
        }

        try {
            $approval = $this->inactiveApprovalModel->getById($approvalId);
            if ($approval === null) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Solicitarea nu a fost gasita.',
                ], 404);
            }

            if ((int) ($approval['requested_by_user_id'] ?? 0) !== $userId) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Nu poti anula solicitarea altui utilizator.',
                ], 403);
            }

            if ((string) ($approval['status'] ?? '') !== 'pending') {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Solicitarea nu mai poate fi anulata deoarece statusul ei s-a modificat.',
                    'approval_id' => $approvalId,
                    'current_status' => (string) ($approval['status'] ?? ''),
                    'summary' => $this->inactiveApprovalModel->getRequesterSummary($userId, 5),
                ], 409);
            }

            $ok = $this->inactiveApprovalModel->cancelPendingByRequester($approvalId, $userId);
            $this->sendJson([
                'success' => $ok,
                'approval_id' => $approvalId,
                'message' => $ok
                    ? 'Solicitarea a fost anulata.'
                    : 'Solicitarea nu mai poate fi anulata deoarece statusul ei s-a modificat.',
                'summary' => $this->inactiveApprovalModel->getRequesterSummary($userId, 5),
            ], $ok ? 200 : 409);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][cancel_inactive_vehicle_approval] ' . $exception->getMessage());
            $this->sendJson([
                'success' => false,
                'message' => 'Solicitarea nu a putut fi anulata. Reincearca.',
            ], 500);
        }
    }

    private function indexAction(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $filters = $this->collectFilters();
        $page = 1;
        $formFlash = $this->consumeFormFlash('race_create');
        $formData = $this->defaultRaceFormData();
        if ($formFlash['old'] !== []) {
            $formData = array_merge($formData, $formFlash['old']);
        }
        $formData['tip_marfa'] = $this->normalizeGoodsTypeSelection($formData['tip_marfa'] ?? []);
        $incompleteConfirmItems = (array) ($_SESSION['_dispecer_incomplete_confirm_race_create'] ?? []);
        unset($_SESSION['_dispecer_incomplete_confirm_race_create']);

        // Reluare cursa: precompleteaza formularul dintr-o cursa existenta; segmentul nou
        // pastreaza contextul (beneficiar, tip transport, traseu), dar km/cantitatile/orele
        // se introduc pentru segmentul curent, iar soferul/vehiculul pot fi schimbate.
        $resumeSource = null;
        $resumeId = (int) ($_GET['resume_id'] ?? 0);
        if ($resumeId > 0) {
            $resumeSource = $this->model->getRaceById($resumeId);
            if ($resumeSource === null) {
                flash_set('warning', 'Cursa selectata pentru reluare nu a fost gasita.');
            } elseif ($formFlash['old'] === []) {
                $formData = array_merge($formData, $this->buildResumeFormData($resumeSource, $resumeId));
            }
        }
        if ($resumeSource === null && (int) ($formData['parent_cursa_id'] ?? 0) > 0) {
            $resumeSource = $this->model->getRaceById((int) $formData['parent_cursa_id']);
        }

        $postCreateExpensePrompt = $this->consumePostCreateExpensePrompt();

        try {
            $result = $this->model->getPaginatedRaces($filters, $search, $page, 0);
            $raceVehicles = $this->model->getVehicleOptions(false);
            $filterVehicles = $this->model->getVehicleOptions();
            $vehicleGarageMap = $this->model->getVehicleGarageMap(true);
            $loadLocations = $this->model->getLoadLocations(true);
            $loadLocationTariffs = $this->model->getLoadLocationTariffs(true);
            $vehicleDefaultLoadLocationMap = $this->model->getVehicleDefaultLoadLocationMap();
            $vehicleDefaultDistributionZoneMap = $this->model->getVehicleDefaultDistributionZoneMap();
            $vehicleDefaultLoadLocationMapByBeneficiary = $this->model->getVehicleDefaultLoadLocationMapByBeneficiary();
            $vehicleDefaultDistributionZoneMapByBeneficiary = $this->model->getVehicleDefaultDistributionZoneMapByBeneficiary();
            $compressorVehicleMapByBeneficiary = $this->model->getCompressorVehicleMapByBeneficiary();
            $beneficiaries = $this->model->getTransportBeneficiaries(true);
            $activeDriverVehicleIds = $this->model->getActiveVehicleIdsWithAssignedDriver();
            $driversByVehicle = $this->model->getDriversGroupedByVehicle();
            $allActiveDrivers = $this->model->getDriverOptions(true);
            $distributionZones = $this->model->getDistributionZones(true);
            $loadLocationsByBeneficiary = $this->model->getLoadLocationsGroupedByBeneficiary(true);
            $distributionZonesByBeneficiary = $this->model->getDistributionZonesGroupedByBeneficiary(true);
            $zoneTariffs = $this->model->getDistributionZoneTariffs(true);
            $zoneExtraKmCosts = $this->model->getDistributionZoneExtraKmCosts(true);
            $distributionRouteTariffMap = $this->model->getDistributionRouteTariffMap(true);
            $primaryRouteKmMap = $this->model->getPrimaryRouteKmMap(true);
            $beneficiaryPricing = $this->buildBeneficiaryPricingMap($beneficiaries);
            $openRacesOverview = $this->buildOpenRacesOverviewData($this->model->getOpenRacesOverview(250));
            [$resumeParents, $resumeChildren] = $this->getResumeLinksForRows($result['rows']);
        } catch (PDOException $exception) {
            error_log('[DispecerCurseController][index] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));

            $result = [
                'rows' => [],
                'page' => 1,
                'total_pages' => 1,
                'total_rows' => 0,
            ];
            $raceVehicles = [];
            $filterVehicles = [];
            $vehicleGarageMap = [];
            $loadLocations = [];
            $loadLocationTariffs = [];
            $vehicleDefaultLoadLocationMap = [];
            $vehicleDefaultDistributionZoneMap = [];
            $vehicleDefaultLoadLocationMapByBeneficiary = [];
            $vehicleDefaultDistributionZoneMapByBeneficiary = [];
            $compressorVehicleMapByBeneficiary = [];
            $beneficiaries = [];
            $activeDriverVehicleIds = [];
            $driversByVehicle = [];
            $allActiveDrivers = [];
            $distributionZones = [];
            $loadLocationsByBeneficiary = [];
            $distributionZonesByBeneficiary = [];
            $zoneTariffs = [];
            $zoneExtraKmCosts = [];
            $distributionRouteTariffMap = [];
            $primaryRouteKmMap = [];
            $beneficiaryPricing = [];
            $openRacesOverview = [
                'count' => 0,
                'rows' => [],
                'severity_counts' => ['critical' => 0, 'important' => 0, 'minor' => 0],
                'plates' => [],
                'transport_types_present' => [],
            ];
            $resumeParents = [];
            $resumeChildren = [];
        }

        render('dispecer_curse/index.php', [
            'pageTitle' => 'Dispecer curse',
            'currentPage' => 'dispecer_curse',
            'incompleteConfirmItems' => $incompleteConfirmItems,
            'rows' => $result['rows'],
            'search' => $search,
            'filters' => $filters,
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total_rows' => $result['total_rows'],
                'per_page' => 0,
            ],
            'transportTypes' => self::TRANSPORT_TYPES,
            'expenseTypes' => self::EXPENSE_TYPES,
            'expenseEntryTypes' => $this->expenseEntryTypes(),
            'expenseCategories' => $this->expenseCategories(),
            'raceVehicles' => $raceVehicles,
            'filterVehicles' => $filterVehicles,
            'vehicleGarageMap' => $vehicleGarageMap,
            'loadLocations' => $loadLocations,
            'loadLocationTariffs' => $loadLocationTariffs,
            'vehicleDefaultLoadLocationMap' => $vehicleDefaultLoadLocationMap,
            'vehicleDefaultDistributionZoneMap' => $vehicleDefaultDistributionZoneMap,
            'vehicleDefaultLoadLocationMapByBeneficiary' => $vehicleDefaultLoadLocationMapByBeneficiary,
            'vehicleDefaultDistributionZoneMapByBeneficiary' => $vehicleDefaultDistributionZoneMapByBeneficiary,
            'compressorVehicleMapByBeneficiary' => $compressorVehicleMapByBeneficiary,
            'beneficiaries' => $beneficiaries,
            'activeDriverVehicleIds' => $activeDriverVehicleIds,
            'driversByVehicle' => $driversByVehicle,
            'allActiveDrivers' => $allActiveDrivers,
            'distributionZones' => $distributionZones,
            'loadLocationsByBeneficiary' => $loadLocationsByBeneficiary,
            'distributionZonesByBeneficiary' => $distributionZonesByBeneficiary,
            'zoneTariffs' => $zoneTariffs,
            'zoneExtraKmCosts' => $zoneExtraKmCosts,
            'distributionRouteTariffMap' => $distributionRouteTariffMap,
            'primaryRouteKmMap' => $primaryRouteKmMap,
            'beneficiaryPricing' => $beneficiaryPricing,
            'goodsTypeOptions' => self::GOODS_TYPES,
            'billingStatuses' => self::BILLING_STATUSES,
            'formData' => $formData,
            'formErrors' => $formFlash['errors'],
            'postCreateExpensePrompt' => $postCreateExpensePrompt,
            'openRacesOverview' => $openRacesOverview,
            'maintenancePopupMessages' => $this->consumeMaintenancePopupMessages(),
            'resumeSource' => $resumeSource,
            'resumeParents' => $resumeParents,
            'resumeChildren' => $resumeChildren,
        ]);
    }

    /**
     * Datele precompletate pentru un segment nou care continua cursa $source.
     * Contextul comercial se pastreaza; valorile masurate per segment se reintroduc.
     */
    private function buildResumeFormData(array $source, int $resumeId): array
    {
        $sourceEndDate = trim((string) ($source['data_sfarsit'] ?? ''));
        $sourceEndTime = trim((string) ($source['ora_sfarsit'] ?? ''));

        return [
            'parent_cursa_id' => (string) $resumeId,
            'beneficiar_id' => (string) ($source['beneficiar_id'] ?? ''),
            'tip_transport' => (string) ($source['tip_transport'] ?? ''),
            'vehicle_id' => (string) ($source['vehicle_id'] ?? ''),
            'driver_id' => (string) ($source['driver_id'] ?? ''),
            'data_incarcare' => (string) ($source['data_incarcare'] ?? ''),
            // Segmentul nou incepe unde s-a terminat segmentul anterior.
            'data_inceput' => $sourceEndDate !== '' ? $sourceEndDate : date('Y-m-d'),
            'data_sfarsit' => $sourceEndDate !== '' ? $sourceEndDate : date('Y-m-d'),
            'ora_inceput' => $sourceEndTime !== '' ? substr($sourceEndTime, 0, 5) : '',
            'loc_incarcare_id' => (string) ($source['loc_incarcare_id'] ?? ''),
            'loc_plecare' => (string) ($source['loc_plecare'] ?? ''),
            'loc_aspirare' => (string) ($source['loc_aspirare'] ?? ''),
            'loc_livrare' => (string) ($source['loc_livrare'] ?? ''),
            'loc_livrare_cursa' => (string) ($source['loc_livrare_cursa'] ?? ''),
            'zona_distributie_id' => (string) ($source['zona_distributie_id'] ?? ''),
            'capacitate_transport' => (string) ($source['capacitate_transport'] ?? ''),
            'tip_marfa' => $this->normalizeGoodsTypeSelection($source['tip_marfa'] ?? []),
        ];
    }

    /**
     * Decizia "Adauga permanent pe ruta": vehiculul este adaugat in Configurare Transport
     * pe regulile de ruta potrivite (beneficiar + loc incarcare / zona), in functie de
     * tipul de transport. Esecul nu blocheaza salvarea cursei — doar informeaza adminul.
     */
    private function applyPermanentVehicleRouteConfig(array $data): void
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $beneficiaryId = (int) ($data['beneficiar_id'] ?? 0);
        $transportType = (string) ($data['tip_transport'] ?? '');
        if ($vehicleId <= 0 || $beneficiaryId <= 0 || $transportType === '') {
            return;
        }

        try {
            if ($this->isVehicleAllowedForBeneficiaryAndTransport($beneficiaryId, $vehicleId, $transportType)) {
                flash_set('info', 'Vehiculul era deja configurat pe aceasta ruta.');
                return;
            }

            if ($transportType === 'compresor') {
                $now = date('Y-m-d H:i:s');
                $stmt = $this->db->prepare('
                    INSERT INTO configurare_compresor_vehicule (beneficiar_id, vehicle_id, created_at, updated_at)
                    VALUES (:beneficiar_id, :vehicle_id, :created_at, :updated_at)
                ');
                $stmt->execute([
                    'beneficiar_id' => $beneficiaryId,
                    'vehicle_id' => $vehicleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                flash_set('info', 'Vehiculul a fost adaugat permanent la vehiculele Compresor ale beneficiarului.');
                return;
            }

            $isPrimary = $this->isPrimaryKmTransportType($transportType) || $this->isPrimaryTonTransportType($transportType);
            $rules = $isPrimary
                ? $this->model->getPrimaryRouteRules(true, $beneficiaryId)
                : $this->model->getDistributionRouteRules(
                    true,
                    $beneficiaryId,
                    $this->resolveDistributionRouteScopeFromTransportType($transportType)
                );

            $locId = (int) ($data['loc_incarcare_id'] ?? 0);
            $zonaId = (int) ($data['zona_distributie_id'] ?? 0);
            $pickRules = static function (bool $matchLoc, bool $matchZona) use ($rules, $locId, $zonaId): array {
                $picked = [];
                foreach ($rules as $rule) {
                    if ($matchLoc && $locId > 0 && (int) ($rule['loc_incarcare_id'] ?? 0) !== $locId) {
                        continue;
                    }
                    if ($matchZona && $zonaId > 0 && (int) ($rule['zona_distributie_id'] ?? 0) !== $zonaId) {
                        continue;
                    }
                    $picked[] = $rule;
                }
                return $picked;
            };

            // Cea mai specifica potrivire disponibila: loc + zona, apoi doar loc, apoi doar zona.
            $matchedRules = $pickRules(true, true);
            if ($matchedRules === []) {
                $matchedRules = $pickRules(true, false);
            }
            if ($matchedRules === []) {
                $matchedRules = $pickRules(false, true);
            }

            if ($matchedRules === []) {
                flash_set('warning', 'Nu exista o ruta configurata potrivita (loc incarcare / zona) pentru acest beneficiar; vehiculul a fost folosit doar pentru aceasta cursa. Adauga intai ruta din Configurare Transport.');
                return;
            }

            $table = $isPrimary ? 'configurare_rute_primar' : 'configurare_rute_distributie';
            $updatedRules = 0;
            foreach ($matchedRules as $rule) {
                $ruleId = (int) ($rule['id'] ?? 0);
                if ($ruleId <= 0) {
                    continue;
                }
                $ruleVehicleIds = $this->parseDistributionRouteVehicleIds((string) ($rule['vehicle_ids'] ?? ''));
                if (in_array($vehicleId, $ruleVehicleIds, true)) {
                    continue;
                }
                $ruleVehicleIds[] = $vehicleId;
                $updateStmt = $this->db->prepare("UPDATE {$table} SET vehicle_ids = :vehicle_ids WHERE id = :id");
                $updateStmt->execute(['vehicle_ids' => implode(',', $ruleVehicleIds), 'id' => $ruleId]);
                $updatedRules++;
            }

            flash_set(
                'info',
                $updatedRules > 0
                    ? 'Vehiculul a fost adaugat permanent pe ruta in Configurare Transport (' . $updatedRules . ' reguli actualizate).'
                    : 'Vehiculul era deja prezent pe regulile rutei.'
            );
        } catch (Throwable $exception) {
            error_log('[DispecerCurse][vehicle-route-config] ' . $exception->getMessage());
            flash_set('warning', 'Nu am putut adauga vehiculul permanent pe ruta; a fost folosit doar pentru aceasta cursa.');
        }
    }

    private const MISSING_SEVERITY_ORDER = ['critical' => 3, 'important' => 2, 'minor' => 1];

    /**
     * Pregateste datele popup-ului "curse cu informatii lipsa": pentru fiecare cursa
     * candidata calculeaza lista informatiilor lipsa (per tip de transport), severitatea
     * fiecareia si severitatea cursei (cea mai mare dintre campuri). Cursele complete
     * sunt excluse. Tot aici se aduna contoarele pentru tab-uri si optiunile de filtrare.
     */
    private function buildOpenRacesOverviewData(array $overview): array
    {
        $rows = [];
        $severityCounts = ['critical' => 0, 'important' => 0, 'minor' => 0];
        $plates = [];
        $transportTypesPresent = [];

        foreach ((array) ($overview['rows'] ?? []) as $race) {
            $missing = $this->buildRaceMissingInformation($race);
            if ($missing === []) {
                continue;
            }

            $raceSeverity = 'minor';
            $raceSeverityRank = 0;
            foreach ($missing as $item) {
                $rank = self::MISSING_SEVERITY_ORDER[$item['severity']] ?? 0;
                if ($rank > $raceSeverityRank) {
                    $raceSeverityRank = $rank;
                    $raceSeverity = $item['severity'];
                }
            }

            $race['missing_information'] = $missing;
            $race['missing_severity'] = $raceSeverity;
            $severityCounts[$raceSeverity]++;

            $plate = trim((string) ($race['nr_inmatriculare'] ?? ''));
            if ($plate !== '') {
                $plates[$plate] = $plate;
            }
            $transportTypesPresent[(string) ($race['tip_transport'] ?? '')] = true;

            $rows[] = $race;
        }

        ksort($plates);

        return [
            'count' => count($rows),
            'rows' => $rows,
            'severity_counts' => $severityCounts,
            'plates' => array_values($plates),
            'transport_types_present' => array_keys($transportTypesPresent),
        ];
    }

    /**
     * Detecteaza informatiile lipsa ale unei curse, per tip de transport.
     *
     * Reguli NULL/0: doar NULL / sirul gol inseamna "lipsa"; un 0 numeric legitim NU este
     * raportat ca lipsa. Exceptie: cantitatea 0 pe tipurile facturate la tona este o
     * problema de validare (salvarea ar fi respinsa) si este raportata drept critica.
     *
     * Fiecare element: field, label, severity (critical|important|minor), explanation,
     * focus (cheia deep-link pentru pagina de editare; '' = fara camp dedicat).
     */
    private function buildRaceMissingInformation(array $race): array
    {
        $isMissing = static function ($value): bool {
            return $value === null || trim((string) $value) === '';
        };
        $isZero = static function ($value): bool {
            $text = trim((string) $value);
            return $text !== '' && is_numeric($text) && abs((float) $text) < 0.005;
        };

        $type = (string) ($race['tip_transport'] ?? '');
        $items = [];
        $add = static function (string $field, string $label, string $severity, string $explanation, string $focus = '') use (&$items): void {
            $items[] = [
                'field' => $field,
                'label' => $label,
                'severity' => $severity,
                'explanation' => $explanation,
                'focus' => $focus,
            ];
        };

        // --- Comune tuturor tipurilor: resurse, cronometrare si documente ---
        $driverId = (int) ($race['driver_id'] ?? 0);
        if ($driverId <= 0 && trim((string) ($race['sofer_nume'] ?? '')) === '') {
            $add('driver_id', 'Șofer neasignat', 'critical', 'Cursa nu are șofer — asignează șoferul pentru pontaj și raportare.', 'driver');
        }
        $beneficiaryId = (int) ($race['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0 && trim((string) ($race['beneficiar_nume'] ?? '')) === '') {
            $add('beneficiar_id', 'Beneficiar transport', 'critical', 'Cursa nu are beneficiar — fără el nu se poate factura.', 'beneficiary');
        }
        if ($isMissing($race['tip_marfa'] ?? null)) {
            $add('tip_marfa', 'Tip marfă', 'important', 'Selectează tipul de marfă pentru documentele de transport.', 'goods');
        }
        if ($isMissing($race['ora_inceput'] ?? null)) {
            $add('ora_inceput', 'Ora de început', 'critical', 'Completează ora de început — fără ea nu se poate seta ora finală și durata cursei.', 'start_time');
        }
        if ($isMissing($race['ora_sfarsit'] ?? null)) {
            $add('ora_sfarsit', 'Ora finală a cursei', 'critical', 'Setează ora de finalizare pentru raportare corectă.', 'end_time');
        }
        if ($isMissing($race['data_incarcare'] ?? null)) {
            $add('data_incarcare', 'Data încărcare', 'minor', 'Selectează data încărcării pentru completarea documentelor.', 'loading_date');
        }
        if ($type !== 'compresor' && $isMissing($race['loc_incarcare_id'] ?? null)) {
            $add('loc_incarcare_id', 'Loc încărcare', 'important', 'Selectează locul de încărcare — este folosit la potrivirea rutelor și în documente.', 'loading_location');
        }

        $hasPricingGap = false;

        // --- Reguli per tip de transport ---
        if ($type === 'primar' || $type === 'primar_tona') {
            if ($isMissing($race['km_totali'] ?? null)) {
                $add('km_totali', 'Km efectuați', 'important', 'Completează km efectuați — sunt folosiți la sincronizarea bordului și la mentenanță.', 'km_total');
            }
            if ($type === 'primar' && ($isMissing($race['km_cursa'] ?? null) || $isZero($race['km_cursa'] ?? null))) {
                $add('km_cursa', 'Km agreați (tarifare)', 'critical', 'Km agreați lipsesc — totalul de facturare nu se poate calcula.', 'km');
                $hasPricingGap = true;
            }
            if ($type === 'primar_tona') {
                $quantity = $race['cantitate_incarcata'] ?? null;
                if ($isMissing($quantity)) {
                    $add('cantitate_incarcata', 'Cantitate încărcată', 'critical', 'Cantitatea încărcată este necesară pentru facturarea pe tone.', 'quantity');
                    $hasPricingGap = true;
                } elseif ($isZero($quantity)) {
                    $add('cantitate_incarcata', 'Cantitate încărcată (valoare invalidă: 0)', 'critical', 'Valoarea 0 nu este acceptată la facturarea pe tone — corectează cantitatea.', 'quantity');
                    $hasPricingGap = true;
                }
            }
            if ($type === 'primar' && $isMissing($race['cantitate_incarcata'] ?? null)) {
                $add('cantitate_incarcata', 'Cantitate încărcată', 'minor', 'Completează cantitatea încărcată pentru raportarea operațională.', 'quantity');
            }
            if ($isMissing($race['zona_distributie_id'] ?? null)) {
                $add('zona_distributie_id', 'Loc descărcare', 'important', 'Selectează locul de descărcare — perechea Loc ↔ Zonă valideză ruta din Setări Primar.', 'distribution_zone');
            }
        } elseif ($type === 'distributie' || $type === 'primar_distributie') {
            $quantity = $race['cantitate_incarcata'] ?? null;
            if ($isMissing($quantity)) {
                $add('cantitate_incarcata', 'Cantitate încărcată', 'critical', 'Cantitatea încărcată este necesară pentru facturarea distribuției.', 'quantity');
                $hasPricingGap = true;
            } elseif ($isZero($quantity)) {
                $add('cantitate_incarcata', 'Cantitate încărcată (valoare invalidă: 0)', 'critical', 'Valoarea 0 nu este acceptată la facturarea pe tone — corectează cantitatea.', 'quantity');
                $hasPricingGap = true;
            }
            if ($isMissing($race['zona_distributie_id'] ?? null)) {
                $add('zona_distributie_id', 'Zona distribuție', 'important', 'Selectează zona de distribuție — determină tariful aplicat.', 'distribution_zone');
                $hasPricingGap = true;
            }
            if ($isMissing($race['nr_clienti'] ?? null)) {
                $add('nr_clienti', 'Nr. clienți', 'important', 'Completează numărul de clienți pentru raportarea distribuției.', 'clients');
            }
            if ($type === 'distributie' && $isMissing($race['km_cursa'] ?? null)) {
                $add('km_cursa', 'Km efectuați', 'important', 'Completează km efectuați — pot intra în componenta de tarif pe km.', 'km');
            }
            if ($type === 'primar_distributie') {
                if ($isMissing($race['km_totali'] ?? null)) {
                    $add('km_totali', 'Km efectuați (totali)', 'important', 'Fără km efectuați, Cost/km distribuție și Cost/km mixt rămân 0.', 'km_total');
                    if ($isZero($race['cost_km_mixt'] ?? null)) {
                        $add('cost_km_mixt', 'Cost/km mixt = 0', 'important', 'Valoarea 0 este cauzată de lipsa km efectuați — se corectează completându-i.', 'km_total');
                    }
                }
            }
        } elseif ($type === 'compresor') {
            $compressorLocationDefinitions = [
                ['loc_plecare', 'Loc plecare', 'departure_location'],
                ['loc_aspirare', 'Loc aspirare', 'suction_location'],
                ['loc_livrare', 'Loc livrare', 'delivery_location'],
                ['loc_livrare_cursa', 'Loc închidere cursă', 'closing_location'],
            ];
            foreach ($compressorLocationDefinitions as [$locationField, $locationLabel, $locationFocus]) {
                if ($isMissing($race[$locationField] ?? null)) {
                    $add($locationField, $locationLabel, 'important', 'Completează ' . mb_strtolower($locationLabel) . ' pentru traseul cursei de compresor.', $locationFocus);
                }
            }
            $metricDefinitions = [
                ['ore_aspirare', 'Ore aspirare', 'Completează orele de aspirare — componentă de facturare și de mentenanță.', 'aspiration_hours'],
                ['km_dislocare', 'Km efectuați (dislocare)', 'Completează km de dislocare — componentă de facturare.', 'displacement_km'],
                ['tona_livrata', 'Cantitate livrată', 'Completează cantitatea livrată — componentă de facturare.', 'delivered_quantity'],
                ['tona_aspirata_lichida', 'Tona lichidă aspirată', 'Completează tona lichidă aspirată pentru facturare și raportare.', 'liquid_tons'],
                ['tona_aspirata_gazoasa', 'Tona gazoasă aspirată', 'Completează tona gazoasă aspirată pentru facturare și raportare.', 'gas_tons'],
            ];
            foreach ($metricDefinitions as [$field, $label, $explanation, $focus]) {
                if ($isMissing($race[$field] ?? null)) {
                    $add($field, $label, 'important', $explanation, $focus);
                    $hasPricingGap = true;
                }
            }
            if ($isMissing($race['cantitate_prelevata'] ?? null)) {
                $add('cantitate_prelevata', 'Cantitate prelevată', 'minor', 'Cantitatea prelevată lipsește din raportarea operațională.', '');
            }
        }

        // --- Simptom financiar: total 0 cauzat de date de tarifare lipsa ---
        if ($hasPricingGap && $isZero($race['total_facturare'] ?? null)) {
            $add('total_facturare', 'Total facturare = 0,00 lei', 'critical', 'Totalul este 0 pentru că lipsesc date de tarifare — completează câmpurile marcate.', '');
        }

        // --- Cheltuieli neasociate (pastreaza acoperirea popup-ului existent) ---
        $expenseCount = (int) ($race['expense_count'] ?? 0);
        $expenseStatus = (string) ($race['cheltuieli_status'] ?? 'pending');
        if ($expenseCount === 0 && $expenseStatus !== 'not_applicable') {
            $add('cheltuieli', 'Cheltuieli neasociate', 'minor', 'Adaugă cheltuielile cursei sau marchează-le ca nefiind aplicabile.', 'expenses');
        }

        return $items;
    }

    /**
     * Legaturile parinte/copil pentru randurile afisate in Desfasurator.
     * Returneaza [copil => parinte, parinte => [copii]].
     */
    private function getResumeLinksForRows(array $rows): array
    {
        $rowIds = [];
        foreach ($rows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId > 0) {
                $rowIds[] = $rowId;
            }
        }
        if ($rowIds === []) {
            return [[], []];
        }

        $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, parent_cursa_id
             FROM curse_dispecer
             WHERE deleted_at IS NULL
               AND parent_cursa_id IS NOT NULL
               AND (id IN ($placeholders) OR parent_cursa_id IN ($placeholders))"
        );
        $stmt->execute(array_merge($rowIds, $rowIds));

        $parents = [];
        $children = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $childId = (int) ($link['id'] ?? 0);
            $parentId = (int) ($link['parent_cursa_id'] ?? 0);
            if ($childId <= 0 || $parentId <= 0) {
                continue;
            }
            $parents[$childId] = $parentId;
            $children[$parentId][] = $childId;
        }

        return [$parents, $children];
    }

    private function storeAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse']));

        [$data, $errors, $old, $softErrors] = $this->validateRaceInput($_POST, false);

        // Reluare cursa: segmentul nou refera cursa-parinte.
        $parentCursaId = (int) ($_POST['parent_cursa_id'] ?? 0);
        if ($parentCursaId > 0) {
            $old['parent_cursa_id'] = (string) $parentCursaId;
            if ($this->model->getRaceById($parentCursaId) === null) {
                $errors['parent_cursa_id'] = 'Cursa sursa pentru reluare nu mai exista.';
            }
        }

        // Decizia adminului pentru un vehicul neconfigurat pe ruta.
        $vehicleConfigDecision = trim((string) ($_POST['vehicle_config_decision'] ?? ''));
        if (!in_array($vehicleConfigDecision, ['trip', 'permanent'], true)) {
            $vehicleConfigDecision = '';
        }

        if ($errors !== []) {
            $this->setFormFlash('race_create', $old, $errors + $softErrors);
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        // Informatii lipsa (ne-blocante): cerem confirmare explicita inainte de salvare.
        $confirmIncomplete = trim((string) ($_POST['confirm_incomplete'] ?? '')) === '1';
        if ($softErrors !== [] && !$confirmIncomplete) {
            $_SESSION['_dispecer_incomplete_confirm_race_create'] = array_values($softErrors);
            $this->setFormFlash('race_create', $old, []);
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        $approvalDecision = $this->normalizeInactiveApprovalDecision($_POST['inactive_approval_decision'] ?? '');
        $inactiveResources = $this->getInactiveResourcesForRaceData($data);
        $resourcesNeedingDecision = $this->resourcesNeedingInactiveApprovalDecision($inactiveResources, null);
        if ($resourcesNeedingDecision !== [] && $approvalDecision === '') {
            $old['inactive_approval_decision'] = '';
            $errors['inactive_resources'] = $this->buildInactiveApprovalRequiredMessage($resourcesNeedingDecision);
            $this->setFormFlash('race_create', $old, $errors);
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        // Tariful se rezolva dupa DATA CURSEI, nu dupa data curenta.
        $data = $this->applyVersionedPricing($data);

        $now = date('Y-m-d H:i:s');
        $data['created_by'] = $this->currentUserId();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        try {
            $duplicateRaceId = $this->model->findDuplicateRaceId($data);
            if ($duplicateRaceId !== null) {
                flash_set('warning', $this->buildDuplicateRaceMessage($duplicateRaceId));
                $this->setPostCreateExpensePrompt(0);
                $this->setFormFlash('race_create', $old, []);
                redirect(build_query_url(['page' => 'dispecer_curse']));
            }

            $result = $this->model->createRaceAndSyncVehicleKm($data);
            $this->queueMaintenancePopupAlerts((array) ($result['maintenance_alerts'] ?? []));
            $raceId = (int) ($result['race_id'] ?? 0);
            $this->persistTariffTraceability($raceId, $data);
            $resourcesForTripApproval = $this->resourcesForTripInactiveApprovalCreation($inactiveResources, $approvalDecision);
            if ($raceId > 0 && $resourcesForTripApproval !== []) {
                $createdApprovalIds = $this->inactiveApprovalModel->createForInactiveResources(
                    $resourcesForTripApproval,
                    $raceId,
                    $approvalDecision !== '' ? $approvalDecision : 'pending',
                    $this->currentUserId()
                );
                if ($createdApprovalIds !== []) {
                    flash_set(
                        $approvalDecision === 'approved' ? 'info' : 'warning',
                        $approvalDecision === 'approved'
                            ? 'Utilizarea resurselor inactive a fost aprobata imediat.'
                            : 'Utilizarea resurselor inactive a fost trimisa spre aprobare.'
                    );
                }
            }
            if ($raceId > 0 && $parentCursaId > 0) {
                $linkStmt = $this->db->prepare('UPDATE curse_dispecer SET parent_cursa_id = :parent WHERE id = :id');
                $linkStmt->execute(['parent' => $parentCursaId, 'id' => $raceId]);
            }
            if ($raceId > 0 && $vehicleConfigDecision === 'permanent') {
                $this->applyPermanentVehicleRouteConfig($data);
            } elseif ($raceId > 0 && $vehicleConfigDecision === 'trip') {
                flash_set('info', 'Vehiculul a fost folosit doar pentru aceasta cursa, fara modificarea Configurarii Transport.');
            }
            $this->setPostCreateExpensePrompt($raceId, 'created');
            flash_set(
                'success',
                $raceId > 0 && $parentCursaId > 0
                    ? 'Cursa #' . $raceId . ' a fost adaugata ca o continuare a cursei #' . $parentCursaId . '.'
                    : 'Cursa a fost adaugata cu succes.'
            );
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][store] ' . $exception->getMessage());
            flash_set(
                $this->isDuplicateRacePersistenceError($exception) ? 'warning' : 'danger',
                $this->isDuplicateRacePersistenceError($exception)
                    ? $this->buildDuplicateRaceMessage()
                    : $this->buildPersistenceErrorMessage($exception)
            );
            $this->setPostCreateExpensePrompt(0);
            $this->setFormFlash('race_create', $old, []);
        }

        redirect(build_query_url(['page' => 'dispecer_curse']));
    }

    private function editAction(): void
    {
        $raceId = (int) ($_GET['id'] ?? 0);
        if ($raceId <= 0) {
            flash_set('warning', 'ID cursÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ invalid.');
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        $race = $this->model->getRaceById($raceId);
        if ($race === null) {
            flash_set('warning', 'Cursa nu a fost gÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢sitÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        $raceFlash = $this->consumeFormFlash('race_edit_' . $raceId);
        $incompleteConfirmItems = (array) ($_SESSION['_dispecer_incomplete_confirm_race_edit_' . $raceId] ?? []);
        unset($_SESSION['_dispecer_incomplete_confirm_race_edit_' . $raceId]);
        $raceFormData = $race;
        if ($raceFlash['old'] !== []) {
            $raceFormData = array_merge($raceFormData, $raceFlash['old']);
        }
        $raceFormData['tip_marfa'] = $this->normalizeGoodsTypeSelection($raceFormData['tip_marfa'] ?? []);
        $postCreateExpensePrompt = $this->consumePostCreateExpensePrompt();

        $expenseFlash = $this->consumeFormFlash('expense_' . $raceId);
        $expenseFormData = $this->defaultExpenseFormData();
        $expenseBeingEdited = null;
        $expenseId = (int) ($_GET['expense_id'] ?? 0);
        if ($expenseId > 0) {
            $expense = $this->model->getExpenseById($expenseId);
            if ($expense !== null && (int) ($expense['cursa_id'] ?? 0) === $raceId) {
                $parsedExpenseObservations = $this->splitExpenseObservationsAndRoadTaxDetails((string) ($expense['observatii'] ?? ''));
                $roadTaxFormValues = $this->buildRoadTaxFormValuesFromDetails($parsedExpenseObservations['road_tax_details']);
                $refacturareDetails = json_decode((string) ($expense['refacturare_detalii'] ?? ''), true);
                $refacturareRoadTaxFormValues = is_array($refacturareDetails)
                    ? $this->buildRoadTaxFormValuesFromDetails($this->normalizeRoadTaxDetailsPayload($refacturareDetails), 'refacturare_')
                    : [];
                $expenseBeingEdited = $expense;
                $expenseFormData = array_merge($expenseFormData, [
                    'expense_id' => (int) $expense['id'],
                    'tip_cheltuiala' => (string) ($expense['tip_cheltuiala'] ?? ''),
                    'categorie_id' => (string) ($expense['categorie_id'] ?? ''),
                    'refacturare_enabled' => trim((string) ($expense['refacturare_tip_cheltuiala'] ?? '')) !== '' ? '1' : '0',
                    'refacturare_tip_cheltuiala' => (string) ($expense['refacturare_tip_cheltuiala'] ?? ''),
                    'refacturare_suma' => (string) ($expense['refacturare_suma'] ?? ''),
                    'refacturare_data' => (string) ($expense['refacturare_data'] ?? date('Y-m-d')),
                    'refacturare_observatii' => (string) ($expense['refacturare_observatii'] ?? ''),
                    'suma' => (string) ($expense['suma'] ?? ''),
                    'data_cheltuiala' => (string) ($expense['data_cheltuiala'] ?? ''),
                    'observatii' => $parsedExpenseObservations['plain_observations'],
                ], $roadTaxFormValues, $refacturareRoadTaxFormValues);
            }
        }

        if ($expenseFlash['old'] !== []) {
            $expenseFormData = array_merge($expenseFormData, $expenseFlash['old']);
        }

        $beneficiaries = $this->model->getTransportBeneficiaries(false);
        $activeDriverVehicleIds = $this->model->getActiveVehicleIdsWithAssignedDriver();
        $driversByVehicle = $this->model->getDriversGroupedByVehicle();
        $loadLocationTariffs = $this->model->getLoadLocationTariffs(true);
        $vehicleGarageMap = $this->model->getVehicleGarageMap();
        $vehicleDefaultLoadLocationMap = $this->model->getVehicleDefaultLoadLocationMap();
        $vehicleDefaultDistributionZoneMap = $this->model->getVehicleDefaultDistributionZoneMap();
        $vehicleDefaultLoadLocationMapByBeneficiary = $this->model->getVehicleDefaultLoadLocationMapByBeneficiary();
        $vehicleDefaultDistributionZoneMapByBeneficiary = $this->model->getVehicleDefaultDistributionZoneMapByBeneficiary();
        $compressorVehicleMapByBeneficiary = $this->model->getCompressorVehicleMapByBeneficiary();
        $loadLocationsByBeneficiary = $this->model->getLoadLocationsGroupedByBeneficiary(true);
        $distributionZonesByBeneficiary = $this->model->getDistributionZonesGroupedByBeneficiary(true);
        $distributionRouteTariffMap = [];
        try {
            $distributionRouteTariffMap = $this->model->getDistributionRouteTariffMap(true);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][edit_route_tariff_map] ' . $exception->getMessage());
        }
        $primaryRouteKmMap = [];
        try {
            $primaryRouteKmMap = $this->model->getPrimaryRouteKmMap(true);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][edit_primary_route_km_map] ' . $exception->getMessage());
        }

        render('dispecer_curse/edit.php', [
            'pageTitle' => 'Editare cursa',
            'currentPage' => 'dispecer_curse',
            'incompleteConfirmItems' => $incompleteConfirmItems,
            'race' => $race,
            'raceFormData' => $raceFormData,
            'raceFormErrors' => $raceFlash['errors'],
            'expenses' => $this->model->getRaceExpenses($raceId),
            'expenseFormData' => $expenseFormData,
            'expenseFormErrors' => $expenseFlash['errors'],
            'expenseBeingEdited' => $expenseBeingEdited,
            'transportTypes' => self::TRANSPORT_TYPES,
            'expenseTypes' => self::EXPENSE_TYPES,
            'expenseEntryTypes' => $this->expenseEntryTypes(),
            'expenseCategories' => $this->expenseCategories(),
            'vehicles' => $this->model->getVehicleOptions(),
            'vehicleGarageMap' => $vehicleGarageMap,
            'loadLocations' => $this->model->getLoadLocations(true),
            'loadLocationTariffs' => $loadLocationTariffs,
            'vehicleDefaultLoadLocationMap' => $vehicleDefaultLoadLocationMap,
            'vehicleDefaultDistributionZoneMap' => $vehicleDefaultDistributionZoneMap,
            'vehicleDefaultLoadLocationMapByBeneficiary' => $vehicleDefaultLoadLocationMapByBeneficiary,
            'vehicleDefaultDistributionZoneMapByBeneficiary' => $vehicleDefaultDistributionZoneMapByBeneficiary,
            'compressorVehicleMapByBeneficiary' => $compressorVehicleMapByBeneficiary,
            'beneficiaries' => $beneficiaries,
            'activeDriverVehicleIds' => $activeDriverVehicleIds,
            'driversByVehicle' => $driversByVehicle,
            'distributionZones' => $this->model->getDistributionZones(true),
            'loadLocationsByBeneficiary' => $loadLocationsByBeneficiary,
            'distributionZonesByBeneficiary' => $distributionZonesByBeneficiary,
            'zoneTariffs' => $this->model->getDistributionZoneTariffs(true),
            'zoneExtraKmCosts' => $this->model->getDistributionZoneExtraKmCosts(true),
            'distributionRouteTariffMap' => $distributionRouteTariffMap,
            'primaryRouteKmMap' => $primaryRouteKmMap,
            'beneficiaryPricing' => $this->buildBeneficiaryPricingMap($beneficiaries),
            'goodsTypeOptions' => self::GOODS_TYPES,
            'billingStatuses' => self::BILLING_STATUSES,
            'postCreateExpensePrompt' => $postCreateExpensePrompt,
            'maintenancePopupMessages' => $this->consumeMaintenancePopupMessages(),
        ]);
    }

    private function updateAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        $raceId = (int) ($_GET['id'] ?? 0);
        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa nu existÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));

        // O cursa salvata cu un vehicul folosit "doar pentru aceasta cursa" trebuie sa
        // ramana editabila: daca vehiculul nu s-a schimbat, pastram acceptarea existenta.
        $updateInput = $_POST;
        $raceBeforeUpdate = $this->model->getRaceById($raceId);
        if (
            is_array($raceBeforeUpdate)
            && (int) ($updateInput['vehicle_id'] ?? 0) > 0
            && (int) ($updateInput['vehicle_id'] ?? 0) === (int) ($raceBeforeUpdate['vehicle_id'] ?? 0)
        ) {
            $updateInput['vehicle_config_decision'] = 'trip';
        }

        [$data, $errors, $old, $softErrors] = $this->validateRaceInput($updateInput, false, true);
        if ($errors !== []) {
            $this->setFormFlash('race_edit_' . $raceId, $old, $errors + $softErrors);
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
        }

        // Informatii lipsa (ne-blocante): cerem confirmare explicita inainte de salvare.
        $confirmIncomplete = trim((string) ($_POST['confirm_incomplete'] ?? '')) === '1';
        if ($softErrors !== [] && !$confirmIncomplete) {
            $_SESSION['_dispecer_incomplete_confirm_race_edit_' . $raceId] = array_values($softErrors);
            $this->setFormFlash('race_edit_' . $raceId, $old, []);
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
        }

        $approvalDecision = $this->normalizeInactiveApprovalDecision($_POST['inactive_approval_decision'] ?? '');
        $inactiveResources = $this->getInactiveResourcesForRaceData($data);
        $resourcesNeedingDecision = $this->resourcesNeedingInactiveApprovalDecision($inactiveResources, $raceId);
        if ($resourcesNeedingDecision !== [] && $approvalDecision === '') {
            $old['inactive_approval_decision'] = '';
            $errors['inactive_resources'] = $this->buildInactiveApprovalRequiredMessage($resourcesNeedingDecision);
            $this->setFormFlash('race_edit_' . $raceId, $old, $errors);
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
        }

        // Editarea nu este o rescriere oarba: campurile care nu au ajuns in POST
        // (inputuri dezactivate/ascunse/inexistente) pastreaza valoarea stocata, iar
        // valorile financiare se recalculeaza doar cand se schimba un camp de tarifare.
        $data = $this->mergeRaceUpdateData($data, $raceBeforeUpdate, $_POST);

        // Recalcularea comerciala se face DOAR la cererea explicita a operatorului
        // si niciodata pentru o cursa deja facturata. Tariful se rezolva dupa data cursei.
        $explicitRecalculation = trim((string) ($_POST['recalculate_tariff'] ?? '')) === '1'
            && (string) ($raceBeforeUpdate['status_facturare'] ?? '') !== 'facturat';
        if ($explicitRecalculation) {
            $data = $this->applyVersionedPricing($data);
        }

        // Statusul de facturare nu se editeaza din acest formular: se pastreaza
        // valoarea existenta (se schimba doar din Centralizator Facturare).
        $existingRace = $this->model->getRaceById($raceId);
        if (is_array($existingRace)) {
            $data['status_facturare'] = (string) ($existingRace['status_facturare'] ?? self::DEFAULT_BILLING_STATUS);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $duplicateRaceId = $this->model->findDuplicateRaceId($data, $raceId);
            if ($duplicateRaceId !== null) {
                flash_set('warning', $this->buildDuplicateRaceMessage($duplicateRaceId));
                $this->setFormFlash('race_edit_' . $raceId, $old, []);
                redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
            }

            $result = $this->model->updateRaceAndSyncVehicleKm($raceId, $data, $this->currentUserId());
            if ($explicitRecalculation) {
                $this->persistTariffTraceability($raceId, $data);
            }
            $resourcesForTripApproval = $this->resourcesForTripInactiveApprovalCreation($inactiveResources, $approvalDecision);
            if ($resourcesForTripApproval !== []) {
                $createdApprovalIds = $this->inactiveApprovalModel->createForInactiveResources(
                    $resourcesForTripApproval,
                    $raceId,
                    $approvalDecision !== '' ? $approvalDecision : 'pending',
                    $this->currentUserId()
                );
                if ($createdApprovalIds !== [] && $approvalDecision !== '') {
                    flash_set(
                        $approvalDecision === 'approved' ? 'info' : 'warning',
                        $approvalDecision === 'approved'
                            ? 'Utilizarea resurselor inactive a fost aprobata imediat.'
                            : 'Utilizarea resurselor inactive a fost trimisa spre aprobare.'
                    );
                }
            }
            $this->inactiveApprovalModel->rejectPendingNoLongerUsed($raceId, $inactiveResources, $this->currentUserId());
            $this->queueMaintenancePopupAlerts((array) ($result['maintenance_alerts'] ?? []));
            $shouldPromptForExpenses = !is_array($raceBeforeUpdate)
                || (
                    (int) ($raceBeforeUpdate['expense_count'] ?? 0) <= 0
                    && (string) ($raceBeforeUpdate['cheltuieli_status'] ?? 'pending') !== 'not_applicable'
                );
            if ($shouldPromptForExpenses) {
                $this->setPostCreateExpensePrompt($raceId, 'updated');
            } else {
                $this->setPostCreateExpensePrompt(0);
            }
            flash_set('success', 'Cursa a fost actualizatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][update] ' . $exception->getMessage());
            flash_set(
                $this->isDuplicateRacePersistenceError($exception) ? 'warning' : 'danger',
                $this->isDuplicateRacePersistenceError($exception)
                    ? $this->buildDuplicateRaceMessage()
                    : $this->buildPersistenceErrorMessage($exception)
            );
            $this->setFormFlash('race_edit_' . $raceId, $old, []);
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
    }

    /**
     * Reevalueaza valorile comerciale prin serviciul de tarife versionate,
     * folosind DATA DE BUSINESS a cursei (`data_cursa`), nu data curenta.
     *
     * Daca nicio componenta nu se rezolva dintr-o versiune de tarif (adica modulul
     * nu este inca migrat), valorile calculate de motorul existent raman neatinse.
     */
    private function applyVersionedPricing(array $data): array
    {
        if (!class_exists('TransportPricingService')) {
            return $data;
        }

        try {
            $service = new TransportPricingService($this->db);
            $quote = $service->quote([
                'beneficiar_id' => (int) ($data['beneficiar_id'] ?? 0),
                'tip_transport' => (string) ($data['tip_transport'] ?? ''),
                'data_cursa' => (string) ($data['data_cursa'] ?? date('Y-m-d')),
                'vehicle_id' => (int) ($data['vehicle_id'] ?? 0),
                'loc_incarcare_id' => (int) ($data['loc_incarcare_id'] ?? 0),
                'zona_distributie_id' => (int) ($data['zona_distributie_id'] ?? 0),
                'cantitate_incarcata' => (float) ($data['cantitate_incarcata'] ?? 0),
                'km_cursa' => (float) ($data['km_cursa'] ?? 0),
                'km_totali' => (float) ($data['km_totali'] ?? 0),
                'ore_aspirare' => (float) ($data['ore_aspirare'] ?? 0),
                'km_dislocare' => (float) ($data['km_dislocare'] ?? 0),
                'tona_livrata' => (float) ($data['tona_livrata'] ?? 0),
                'tona_aspirata_lichida' => (float) ($data['tona_aspirata_lichida'] ?? 0),
                'tona_aspirata_gazoasa' => (float) ($data['tona_aspirata_gazoasa'] ?? 0),
            ]);

            if (empty($quote['ok'])) {
                return $data;
            }

            $resolvedFromVersion = false;
            foreach ((array) ($quote['components'] ?? []) as $component) {
                if ((string) ($component['source'] ?? '') === 'version') {
                    $resolvedFromVersion = true;
                    break;
                }
            }

            if (!$resolvedFromVersion) {
                return $data;
            }

            $data['pret_tarifare'] = round((float) ($quote['pret_tarifare'] ?? 0), 2);
            $data['total_facturare'] = round((float) ($quote['total_facturare'] ?? 0), 2);
            $data['__tariff_version_id'] = $quote['tariff_version_id'] ?? null;
            $encoded = json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data['__tariff_breakdown'] = is_string($encoded) ? $encoded : null;
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][versioned_pricing] ' . $exception->getMessage());
        }

        return $data;
    }

    /**
     * Persista trasabilitatea tarifului pe cursa, printr-un UPDATE separat si
     * aditiv (coloanele sunt nullable, cursele istorice raman NULL).
     */
    private function persistTariffTraceability(int $raceId, array $data): void
    {
        if ($raceId <= 0 || !array_key_exists('__tariff_breakdown', $data)) {
            return;
        }
        if (!$this->columnExists('curse_dispecer', 'tariff_version_id')) {
            return;
        }

        try {
            $stmt = $this->db->prepare('
                UPDATE curse_dispecer
                SET tariff_version_id = :version_id, tariff_breakdown_json = :breakdown
                WHERE id = :id
            ');
            $versionId = $data['__tariff_version_id'] ?? null;
            if ($versionId !== null && (int) $versionId > 0) {
                $stmt->bindValue(':version_id', (int) $versionId, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':version_id', null, PDO::PARAM_NULL);
            }
            $breakdown = $data['__tariff_breakdown'] ?? null;
            if (is_string($breakdown) && $breakdown !== '') {
                $stmt->bindValue(':breakdown', $breakdown);
            } else {
                $stmt->bindValue(':breakdown', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':id', $raceId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][tariff_traceability] ' . $exception->getMessage());
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
            ');
            $stmt->execute(['t' => $table, 'c' => $column]);
            $cache[$key] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    /**
     * Campurile de tarifare: o modificare a oricaruia dintre ele justifica recalcularea
     * valorilor financiare la editare; altfel valorile stocate se pastreaza.
     */
    private const RACE_PRICING_INPUT_FIELDS = [
        'tip_transport',
        'beneficiar_id',
        'vehicle_id',
        'loc_incarcare_id',
        'zona_distributie_id',
        'km_cursa',
        'km_totali',
        'cantitate_incarcata',
        'ore_aspirare',
        'km_dislocare',
        'tona_livrata',
        'tona_aspirata_lichida',
        'tona_aspirata_gazoasa',
    ];

    /**
     * Imbina datele validate cu inregistrarea existenta la editare.
     *
     * Principii:
     *  - un camp absent din POST (input dezactivat de JS in functie de tip, ascuns sau
     *    inexistent in formular) inseamna "neatins", nu "sters" — valoarea stocata se pastreaza;
     *  - la schimbarea tipului de transport se sterg doar campurile specifice vechiului tip
     *    (regulile din validateRaceInput raman), iar campurile comune (km totali, nr. clienti,
     *    cantitate prelevata) se pastreaza;
     *  - ore_functionare este derivat explicit din ore_aspirare pentru Compresor; pentru
     *    celelalte tipuri valoarea istorica nu se atinge cat timp tipul nu se schimba;
     *  - data_cursa si capacitate_transport (snapshot istoric) nu se rescriu la o simpla salvare;
     *  - pret_tarifare / total_facturare / cost_km_* se recalculeaza doar daca s-a schimbat un
     *    camp de tarifare, si niciodata pentru curse deja facturate.
     */
    private function mergeRaceUpdateData(array $data, ?array $existing, array $post): array
    {
        if (!is_array($existing) || $existing === []) {
            return $data;
        }

        $newType = (string) ($data['tip_transport'] ?? '');
        $oldType = (string) ($existing['tip_transport'] ?? '');
        $typeChanged = $newType !== $oldType;
        $isCompressor = $newType === 'compresor';

        // 1) Campuri comune tuturor tipurilor: lipsa din POST = pastreaza, chiar si la
        //    schimbarea tipului (nu sunt incompatibile cu niciun tip de transport).
        foreach (['km_totali', 'nr_clienti', 'cantitate_prelevata'] as $field) {
            if (!array_key_exists($field, $post)) {
                $data[$field] = $existing[$field] ?? null;
            }
        }

        // 2) Cand tipul NU s-a schimbat, orice camp nepostat pastreaza valoarea stocata
        //    (acopera si valori legacy/importate pe coloane pe care formularul nu le afiseaza
        //    pentru tipul curent). La schimbarea tipului raman regulile de curatare din validare.
        if (!$typeChanged) {
            $preserveWhenNotPosted = [
                'loc_incarcare_id',
                'zona_distributie_id',
                'cantitate_incarcata',
                'km_cursa',
                'ore_aspirare',
                'km_dislocare',
                'tona_livrata',
                'tona_aspirata_lichida',
                'tona_aspirata_gazoasa',
                'loc_plecare',
                'loc_aspirare',
                'loc_livrare',
                'loc_livrare_cursa',
            ];
            foreach ($preserveWhenNotPosted as $field) {
                if (!array_key_exists($field, $post)) {
                    $data[$field] = $existing[$field] ?? null;
                }
            }
        }

        // 3) ore_functionare: relatie explicita cu ore_aspirare.
        //    - Compresor: derivat din ore_aspirare cand aceasta este postata (regula de business
        //      existenta, acum explicita); daca nu este postata, se pastreaza valoarea stocata.
        //    - Alte tipuri, tip neschimbat: valoarea istorica nu se atinge.
        //    - Tip schimbat spre non-compresor: ramane curatarea intentionata din validare.
        if ($isCompressor) {
            if (!array_key_exists('ore_aspirare', $post)) {
                $data['ore_functionare'] = $existing['ore_functionare'] ?? null;
            }
        } elseif (!$typeChanged) {
            $data['ore_functionare'] = $existing['ore_functionare'] ?? null;
        }

        // 4) data_cursa nu are camp in formular: valoarea istorica se pastreaza
        //    (la creare ramane egala cu data_inceput).
        $existingRaceDate = trim((string) ($existing['data_cursa'] ?? ''));
        if ($existingRaceDate !== '') {
            $data['data_cursa'] = $existingRaceDate;
        }

        // 5) capacitate_transport este un snapshot istoric: se re-deriva din vehicul doar
        //    daca vehiculul s-a schimbat sau snapshotul lipseste.
        $vehicleUnchanged = (int) ($data['vehicle_id'] ?? 0) === (int) ($existing['vehicle_id'] ?? 0);
        if ($vehicleUnchanged && ($existing['capacitate_transport'] ?? null) !== null) {
            $data['capacitate_transport'] = $existing['capacitate_transport'];
        }

        // 6) Recalcularea financiara ruleaza doar cand s-a schimbat un camp de tarifare
        //    si cursa nu este deja facturata.
        $canonical = static function ($value): ?string {
            if ($value === null) {
                return null;
            }
            $text = trim((string) $value);
            if ($text === '') {
                return null;
            }
            return is_numeric($text) ? sprintf('%.4F', (float) $text) : $text;
        };

        $pricingChanged = false;
        foreach (self::RACE_PRICING_INPUT_FIELDS as $field) {
            if ($canonical($data[$field] ?? null) !== $canonical($existing[$field] ?? null)) {
                $pricingChanged = true;
                break;
            }
        }

        $isInvoiced = (string) ($existing['status_facturare'] ?? '') === 'facturat';

        // NOU (modul Administrare tarife transport):
        // Editarea unei curse NU mai reevalueaza tacit valorile comerciale.
        // Recalcularea se face doar la cerere explicita a operatorului
        // (`recalculate_tariff=1`) si niciodata pentru o cursa facturata.
        $recalculationRequested = trim((string) ($post['recalculate_tariff'] ?? '')) === '1';

        if ($pricingChanged && !$isInvoiced && !$recalculationRequested) {
            flash_set(
                'info',
                'Datele operationale au fost actualizate. Valorile comerciale au ramas neschimbate; '
                . 'foloseste actiunea "Recalculeaza tariful" daca vrei sa le reevaluezi.'
            );
        }

        if ($isInvoiced || !$recalculationRequested) {
            foreach (['pret_tarifare', 'total_facturare', 'cost_km_primar', 'cost_km_distributie', 'cost_km_mixt', 'cost_km_compresor'] as $field) {
                $data[$field] = round((float) ($existing[$field] ?? 0), 2);
            }
            if ($pricingChanged && $isInvoiced) {
                flash_set('info', 'Cursa este deja facturata: valorile financiare existente au fost pastrate si nu s-au recalculat.');
            }
        }

        return $data;
    }

    private function deleteAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse']));

        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        $raceId = (int) ($_POST['id'] ?? 0);
        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa selectatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ nu existÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        try {
            $this->model->deleteRaceAndSyncVehicleKm($raceId, $this->currentUserId());
            flash_set('success', 'Cursa a fost ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢tearsÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][delete] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢terge cursa selectatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
        }

        $this->redirectToSafeDispecerUrl($returnUrl);
    }

    private function deleteBulkAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse']));

        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }

        $raceIds = [];
        foreach ($rawIds as $rawId) {
            $rawId = trim((string) $rawId);
            if ($rawId === '' || !ctype_digit($rawId)) {
                continue;
            }

            $raceId = (int) $rawId;
            if ($raceId > 0) {
                $raceIds[$raceId] = $raceId;
            }
        }

        if ($raceIds === []) {
            flash_set('warning', 'Selecteaza cel putin o cursa pentru stergere.');
            $this->redirectToSafeDispecerUrl($returnUrl);
        }

        $deletedCount = 0;
        $missingCount = 0;
        $failedIds = [];

        foreach ($raceIds as $raceId) {
            if (!$this->model->existsRace($raceId)) {
                $missingCount++;
                continue;
            }

            try {
                $this->model->deleteRaceAndSyncVehicleKm($raceId, $this->currentUserId());
                $deletedCount++;
            } catch (Throwable $exception) {
                error_log('[DispecerCurseController][delete_bulk][' . $raceId . '] ' . $exception->getMessage());
                $failedIds[] = $raceId;
            }
        }

        if ($deletedCount > 0) {
            $message = $deletedCount === 1
                ? '1 cursa a fost stearsa.'
                : $deletedCount . ' curse au fost sterse.';
            flash_set('success', $message);
        }

        if ($failedIds !== []) {
            $failedPreview = implode(', ', array_slice(array_map('strval', $failedIds), 0, 5));
            $extraSuffix = count($failedIds) > 5 ? '...' : '';
            flash_set('danger', 'Unele curse nu au putut fi sterse (ID: ' . $failedPreview . $extraSuffix . ').');
        }

        if ($missingCount > 0) {
            $message = $missingCount === 1
                ? '1 cursa selectata nu mai exista.'
                : $missingCount . ' curse selectate nu mai exista.';
            flash_set('warning', $message);
        }

        if ($deletedCount === 0 && $failedIds === [] && $missingCount === 0) {
            flash_set('warning', 'Nu s-a putut procesa stergerea selectiei.');
        }

        $this->redirectToSafeDispecerUrl($returnUrl);
    }

    private function deletedRacesAction(): void
    {
        $filters = $this->collectDeletedRaceFilters();
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = $this->collectDeletedRacePerPage();

        $options = [
            'vehicles' => [],
            'drivers' => [],
            'beneficiaries' => [],
            'deleted_by_users' => [],
        ];
        $result = [
            'rows' => [],
            'summary' => [
                'total_deleted' => 0,
                'deleted_this_month' => 0,
                'users_involved' => 0,
                'month_start' => (new DateTimeImmutable('first day of this month'))->format('Y-m-d'),
                'month_end' => (new DateTimeImmutable('last day of this month'))->format('Y-m-d'),
            ],
            'pagination' => [
                'page' => 1,
                'per_page' => $perPage,
                'total_rows' => 0,
                'total_pages' => 1,
            ],
        ];

        try {
            $this->model->ensureDeletedRacesSupport();
            $options = $this->model->getDeletedRaceFilterOptions();
            $result = $this->model->getDeletedRacesPage($filters, $page, $perPage);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][curse_sterse] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        render('dispecer_curse/curse_sterse.php', [
            'pageTitle' => 'Curse șterse',
            'currentPage' => 'dispecer_curse',
            'filters' => $filters,
            'filterOptions' => $options,
            'rows' => $result['rows'],
            'summary' => $result['summary'],
            'pagination' => $result['pagination'],
            'transportTypes' => $this->deletedRaceTransportTypes(),
        ]);
    }

    private function deletedRaceDetailsAction(): void
    {
        $raceId = (int) ($_GET['id'] ?? 0);
        if ($raceId <= 0) {
            $this->sendJson([
                'success' => false,
                'message' => 'Cursa selectată este invalidă.',
            ], 422);
        }

        try {
            $details = $this->model->getDeletedRaceDetails($raceId);
            if ($details === null) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Cursa ștearsă nu a fost găsită.',
                ], 404);
            }

            $this->sendJson([
                'success' => true,
                'details' => $this->buildDeletedRaceDetailsPayload($details),
            ]);
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][curse_sterse_details] ' . $exception->getMessage());
            $this->sendJson([
                'success' => false,
                'message' => 'Detaliile cursei nu au putut fi încărcate.',
            ], 500);
        }
    }

    private function restoreDeletedRaceAction(): void
    {
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        $fallbackUrl = $returnUrl !== '' ? $returnUrl : build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse']));
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.',
                ], 419);
            }

            flash_set('danger', 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.');
            redirect($fallbackUrl);
        }

        $raceId = (int) ($_POST['id'] ?? 0);
        if ($raceId <= 0 || !$this->model->existsDeletedRace($raceId)) {
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Cursa ștearsă nu a fost găsită.',
                ], 404);
            }

            flash_set('warning', 'Cursa ștearsă nu a fost găsită.');
            redirect($fallbackUrl);
        }

        try {
            $this->model->restoreDeletedRaceAndSyncVehicleKm($raceId, $this->currentUserId());
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => true,
                    'message' => 'Cursa a fost restaurată.',
                ]);
            }

            flash_set('success', 'Cursa a fost restaurată.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][restore_cursa] ' . $exception->getMessage());
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => false,
                    'message' => $this->buildPersistenceErrorMessage($exception),
                ], 500);
            }

            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        redirect($fallbackUrl);
    }

    private function permanentlyDeleteDeletedRaceAction(): void
    {
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        $fallbackUrl = $returnUrl !== '' ? $returnUrl : build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'curse_sterse']));
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.',
                ], 419);
            }

            flash_set('danger', 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.');
            redirect($fallbackUrl);
        }

        $raceId = (int) ($_POST['id'] ?? 0);
        if ($raceId <= 0 || !$this->model->existsDeletedRace($raceId)) {
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => false,
                    'message' => 'Cursa ștearsă nu a fost găsită.',
                ], 404);
            }

            flash_set('warning', 'Cursa ștearsă nu a fost găsită.');
            redirect($fallbackUrl);
        }

        try {
            $documents = $this->model->getExpenseDocumentsByRaceId($raceId);
            $this->model->permanentlyDeleteDeletedRace($raceId);
            $this->deleteExpensePhysicalFiles($documents);
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => true,
                    'message' => 'Cursa a fost ștearsă definitiv.',
                ]);
            }

            flash_set('success', 'Cursa a fost ștearsă definitiv.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][delete_cursa_stearsa] ' . $exception->getMessage());
            if ($this->wantsJson()) {
                $this->sendJson([
                    'success' => false,
                    'message' => $this->buildPersistenceErrorMessage($exception),
                ], 500);
            }

            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        redirect($fallbackUrl);
    }

    private function buildDeletedRaceDetailsPayload(array $details): array
    {
        $race = is_array($details['race'] ?? null) ? $details['race'] : [];
        $transportType = (string) ($race['tip_transport'] ?? '');
        $transportLabel = $this->deletedRaceTransportTypes()[$transportType] ?? ($transportType !== '' ? $transportType : '-');
        $raceDate = (string) (($race['data_inceput'] ?? '') !== '' ? $race['data_inceput'] : ($race['data_cursa'] ?? ''));
        $driverName = trim((string) ($race['sofer_nume'] ?? ''));
        $deletedByName = trim((string) ($race['deleted_by_nume'] ?? ''));
        $deletedByRole = trim((string) ($race['deleted_by_rol'] ?? ''));

        $fields = [
            ['label' => 'Cursă', 'value' => '#' . (int) ($race['id'] ?? 0)],
            ['label' => 'Nr. înmatriculare', 'value' => trim((string) ($race['nr_inmatriculare'] ?? '')) ?: '-'],
            ['label' => 'Șofer', 'value' => $driverName !== '' ? $driverName : '-'],
            ['label' => 'Tip transport', 'value' => $transportLabel, 'badge' => $transportType],
            ['label' => 'Beneficiar', 'value' => trim((string) ($race['beneficiar_nume'] ?? '')) ?: '-'],
            ['label' => 'Marfă', 'value' => $this->formatDeletedRaceGoods($race)],
            ['label' => 'Cantitate', 'value' => $this->formatDeletedRaceTons($race['cantitate_incarcata'] ?? null)],
            ['label' => 'Km facturați', 'value' => $this->formatDeletedRaceKm($race['km_cursa'] ?? null)],
            ['label' => 'Km rulaj', 'value' => $this->formatDeletedRaceKm(($race['km_totali'] ?? '') !== '' ? $race['km_totali'] : ($race['km_dislocare'] ?? null))],
            ['label' => 'Data cursei', 'value' => format_date_ro($raceDate)],
            ['label' => 'Rută', 'value' => $this->buildDeletedRaceRouteLabel($race)],
        ];

        $timeline = [];
        foreach ((array) ($details['timeline'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $action = (string) ($event['action'] ?? 'updated');
            $timeline[] = [
                'action' => $action,
                'label' => $this->deletedRaceAuditLabel($action),
                'performed_at' => format_datetime_ro((string) ($event['performed_at'] ?? '')),
                'user' => trim((string) ($event['user_nume'] ?? '')) ?: 'Sistem',
                'role' => trim((string) ($event['user_rol'] ?? '')),
            ];
        }

        return [
            'id' => (int) ($race['id'] ?? 0),
            'title' => '#' . (int) ($race['id'] ?? 0) . ' - ' . (trim((string) ($race['nr_inmatriculare'] ?? '')) ?: '-'),
            'fields' => $fields,
            'deletion' => [
                'deleted_by' => $deletedByName !== '' ? $deletedByName : 'Necunoscut',
                'role' => $deletedByRole !== '' ? $deletedByRole : '-',
                'deleted_at' => format_datetime_ro((string) ($race['deleted_at'] ?? '')),
            ],
            'timeline' => $timeline,
        ];
    }

    private function buildDeletedRaceRouteLabel(array $race): string
    {
        $start = trim((string) ($race['loc_plecare'] ?? ''));
        if ($start === '') {
            $start = trim((string) ($race['loc_incarcare_nume'] ?? ''));
        }
        if ($start === '') {
            $start = trim((string) ($race['loc_aspirare'] ?? ''));
        }

        $end = trim((string) ($race['loc_livrare'] ?? ''));
        if ($end === '') {
            $end = trim((string) ($race['loc_livrare_cursa'] ?? ''));
        }
        if ($end === '') {
            $end = trim((string) ($race['zona_distributie_nume'] ?? ''));
        }

        if ($start !== '' && $end !== '' && mb_strtolower($start) !== mb_strtolower($end)) {
            return $start . ' → ' . $end;
        }

        if ($start !== '') {
            return $start;
        }

        return $end !== '' ? $end : '-';
    }

    private function formatDeletedRaceGoods(array $race): string
    {
        $rawGoods = trim((string) ($race['tip_marfa'] ?? ''));
        if ($rawGoods === '') {
            return '-';
        }

        $labels = [];
        foreach (explode(',', $rawGoods) as $item) {
            $key = trim(strtolower($item));
            if ($key === '') {
                continue;
            }

            $labels[] = self::GOODS_TYPES[$key] ?? $item;
        }

        return $labels !== [] ? implode(', ', $labels) : '-';
    }

    private function formatDeletedRaceKm(mixed $value): string
    {
        if ($value === null || $value === '' || !is_numeric((string) $value)) {
            return '-';
        }

        return number_format((float) $value, 0, ',', '.') . ' km';
    }

    private function formatDeletedRaceTons(mixed $value): string
    {
        if ($value === null || $value === '' || !is_numeric((string) $value)) {
            return '-';
        }

        return format_number_ro((float) $value, 2) . ' t';
    }

    private function deletedRaceAuditLabel(string $action): string
    {
        return match ($action) {
            'created' => 'Creată',
            'updated' => 'Actualizată',
            'deleted' => 'Ștearsă',
            'restored' => 'Restaurată',
            'status_changed' => 'Status modificat',
            default => 'Actualizată',
        };
    }

    private function saveExpenseAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        $raceId = (int) ($_GET['id'] ?? 0);
        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa nu existÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));

        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $existingExpense = null;
        if ($expenseId > 0) {
            $existingExpense = $this->model->getExpenseById($expenseId);
            if ($existingExpense === null || (int) ($existingExpense['cursa_id'] ?? 0) !== $raceId) {
                flash_set('warning', 'Cheltuiala selectatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ nu existÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ pentru aceastÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ cursÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
                redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
            }
        }

        $isRefacturareSubmit = (string) ($_POST['submit_intent'] ?? '') === 'refacturare';
        [$data, $errors, $old] = $this->validateExpenseInput($_POST);
        [$uploadedDocument, $uploadError] = $this->storeUploadedExpenseDocument($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors['document_upload'] = $uploadError;
        }
        [$uploadedRefacturareDocument, $refacturareUploadError] = $this->storeUploadedExpenseDocument($_FILES['refacturare_document_upload'] ?? null);
        if ($refacturareUploadError !== null) {
            $errors['refacturare_document_upload'] = $refacturareUploadError;
        }

        if ($errors !== []) {
            if ($uploadedDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedDocument['file_path']);
            }
            if ($uploadedRefacturareDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedRefacturareDocument['file_path']);
            }

            $this->setFormFlash('expense_' . $raceId, $old, $errors);
            $redirect = ['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId];
            if ($expenseId > 0) {
                $redirect['expense_id'] = $expenseId;
            }
            redirect(build_query_url($redirect));
        }

        $removeExistingDocuments = isset($_POST['sterge_document']) && (string) $_POST['sterge_document'] === '1';
        $removeExistingRefacturareDocument = isset($_POST['sterge_refacturare_document']) && (string) $_POST['sterge_refacturare_document'] === '1';

        try {
            $now = date('Y-m-d H:i:s');
            if ($existingExpense !== null) {
                $data['updated_at'] = $now;
                $this->model->updateExpense($expenseId, $data);
            } else {
                $data['cursa_id'] = $raceId;
                $data['added_by'] = $this->currentUserId();
                $data['created_at'] = $now;
                $data['updated_at'] = $now;
                $expenseId = $this->model->createExpense($data);
            }

            if ($removeExistingDocuments || $uploadedDocument !== null) {
                $this->deleteExpenseDocumentsByExpenseId($expenseId);
            }

            if ($uploadedDocument !== null) {
                $this->model->addExpenseDocument([
                    'cheltuiala_id' => $expenseId,
                    'file_path' => $uploadedDocument['file_path'],
                    'original_name' => $uploadedDocument['original_name'],
                    'mime_type' => $uploadedDocument['mime_type'],
                    'file_size' => $uploadedDocument['file_size'],
                    'created_at' => $now,
                ]);
            }

            $refacturareEnabledForSave = $data['refacturare_tip_cheltuiala'] !== null;
            if (!$refacturareEnabledForSave && $uploadedRefacturareDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedRefacturareDocument['file_path']);
                $uploadedRefacturareDocument = null;
            }
            $existingRefacturareDocumentPath = $existingExpense !== null ? (string) ($existingExpense['refacturare_document_path'] ?? '') : '';
            if ((!$refacturareEnabledForSave || $removeExistingRefacturareDocument || $uploadedRefacturareDocument !== null) && $existingRefacturareDocumentPath !== '') {
                $this->deleteExpensePhysicalFile($existingRefacturareDocumentPath);
            }

            if (!$refacturareEnabledForSave || $removeExistingRefacturareDocument || $uploadedRefacturareDocument !== null) {
                $this->model->updateExpenseRefacturareDocument($expenseId, $uploadedRefacturareDocument);
            }

            // La modificari de cheltuieli, cursa reintra automat in etapa de facturare.
            $this->model->updateRaceBillingStatus($raceId, self::DEFAULT_BILLING_STATUS, $now, $this->currentUserId());
            $this->resetRaceExpenseStatusIfNotApplicable($raceId, $now, true);
            if ($isRefacturareSubmit) {
                flash_set('success', $existingExpense !== null ? 'Refacturarea a fost actualizata.' : 'Refacturarea a fost adaugata.');
            } else {
                flash_set('success', $existingExpense !== null ? 'Cheltuiala a fost actualizata.' : 'Cheltuiala a fost adaugata.');
            }
        } catch (PDOException $exception) {
            if ($uploadedDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedDocument['file_path']);
            }
            if ($uploadedRefacturareDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedRefacturareDocument['file_path']);
            }

            error_log('[DispecerCurseController][save_expense] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva cheltuiala.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
    }

    private function deleteExpenseAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        $raceId = (int) ($_GET['id'] ?? 0);
        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa nu existÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));

        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        if ($expenseId <= 0) {
            flash_set('warning', 'CheltuialÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ invalidÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
        }

        $expense = $this->model->getExpenseById($expenseId);
        if ($expense === null || (int) ($expense['cursa_id'] ?? 0) !== $raceId) {
            flash_set('warning', 'Cheltuiala selectatÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ nu existÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ pentru aceastÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ cursÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
        }

        try {
            $refacturareDocumentPath = (string) ($expense['refacturare_document_path'] ?? '');
            if ($refacturareDocumentPath !== '') {
                $this->deleteExpensePhysicalFile($refacturareDocumentPath);
            }
            $this->deleteExpenseDocumentsByExpenseId($expenseId);
            $this->model->deleteExpense($expenseId);
            $this->resetRaceExpenseStatusIfNotApplicable($raceId, date('Y-m-d H:i:s'));
            flash_set('success', 'Cheltuiala a fost ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢tearsÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢.');
        } catch (PDOException $exception) {
            error_log('[DispecerCurseController][delete_expense] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢terge cheltuiala.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]));
    }

    private function refacturariAction(): void
    {
        $formFlash = $this->consumeFormFlash('refacturare_create');
        $formData = $this->defaultRefacturareFormData();
        if ($formFlash['old'] !== []) {
            $formData = array_merge($formData, $formFlash['old']);
        }

        $expenseEntryTypes = $this->expenseEntryTypes();
        $filters = $this->collectRefacturareFilters($expenseEntryTypes);
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = $this->collectRefacturarePerPage();
        $sort = $this->normalizeRefacturareSort((string) ($_GET['sort'] ?? 'date'));
        $direction = $this->normalizeSortDirection((string) ($_GET['dir'] ?? 'desc'));

        $plateOptions = [];
        $refacturareResult = [
            'rows' => [],
            'summary' => [
                'total_count' => 0,
                'total_amount' => 0.0,
                'pending_count' => 0,
                'pending_amount' => 0.0,
                'invoiced_count' => 0,
                'invoiced_amount' => 0.0,
            ],
            'pagination' => [
                'page' => 1,
                'per_page' => $perPage,
                'total_rows' => 0,
                'total_pages' => 1,
            ],
        ];
        try {
            $plateOptions = $this->model->getRefacturarePlateOptions();
            $refacturareResult = $this->model->getRefacturareHistory($filters, $sort, $direction, $page, $perPage);
        } catch (PDOException $exception) {
            error_log('[DispecerCurseController][refacturari] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        render('dispecer_curse/refacturari.php', [
            'pageTitle' => 'Refacturări curse',
            'currentPage' => 'dispecer_curse',
            'plateOptions' => $plateOptions,
            'filters' => $filters,
            'defaultFilters' => $this->defaultRefacturareFilters(),
            'refacturareRows' => $refacturareResult['rows'],
            'refacturareSummary' => $refacturareResult['summary'],
            'pagination' => $refacturareResult['pagination'],
            'sort' => $sort,
            'direction' => $direction,
            'transportTypes' => self::TRANSPORT_TYPES,
            'expenseTypes' => self::EXPENSE_TYPES,
            'expenseEntryTypes' => $expenseEntryTypes,
            'formData' => $formData,
            'formErrors' => $formFlash['errors'],
        ]);
    }

    private function defaultRefacturareFilters(): array
    {
        $currentMonthStart = new DateTimeImmutable('first day of this month');

        return [
            'data_start' => $currentMonthStart->modify('-1 month')->format('Y-m-01'),
            'data_end' => $currentMonthStart->modify('-1 day')->format('Y-m-d'),
            'nr_inmatriculare' => '',
            'tip_refacturare' => '',
            'status_factura' => '',
            'document' => '',
            'q' => '',
        ];
    }

    private function collectRefacturareFilters(array $allowedTypes): array
    {
        $defaults = $this->defaultRefacturareFilters();

        $startDate = trim((string) ($_GET['data_start'] ?? $defaults['data_start']));
        if (!$this->isValidDate($startDate)) {
            $startDate = $defaults['data_start'];
        }

        $endDate = trim((string) ($_GET['data_end'] ?? $defaults['data_end']));
        if (!$this->isValidDate($endDate)) {
            $endDate = $defaults['data_end'];
        }

        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $plate = trim((string) ($_GET['nr_inmatriculare'] ?? ''));
        if (mb_strlen($plate) > 40) {
            $plate = mb_substr($plate, 0, 40);
        }

        $type = trim((string) ($_GET['tip_refacturare'] ?? ''));
        if ($type !== '' && !array_key_exists($type, $allowedTypes)) {
            $type = '';
        }

        $status = trim((string) ($_GET['status_factura'] ?? ''));
        if (!in_array($status, ['', 'in_asteptare', 'factura_emisa'], true)) {
            $status = '';
        }

        $document = trim((string) ($_GET['document'] ?? ''));
        if (!in_array($document, ['', 'cu_document', 'fara_document'], true)) {
            $document = '';
        }

        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query) > 160) {
            $query = mb_substr($query, 0, 160);
        }

        return [
            'data_start' => $startDate,
            'data_end' => $endDate,
            'nr_inmatriculare' => $plate,
            'tip_refacturare' => $type,
            'status_factura' => $status,
            'document' => $document,
            'q' => $query,
        ];
    }

    private function collectRefacturarePerPage(): int
    {
        $perPage = (int) ($_GET['per_page'] ?? 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }

    private function normalizeRefacturareSort(string $sort): string
    {
        $sort = trim(strtolower($sort));

        return in_array($sort, ['date', 'race', 'type', 'amount', 'status'], true) ? $sort : 'date';
    }

    private function normalizeSortDirection(string $direction): string
    {
        $direction = trim(strtolower($direction));

        return $direction === 'asc' ? 'asc' : 'desc';
    }

    private function storeRefacturareAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari']));
        }

        $raceId = (int) ($_POST['race_id'] ?? 0);
        $returnToEdit = trim((string) ($_POST['return_to'] ?? '')) === 'edit';
        $redirectUrl = $this->buildRefacturareRedirectUrl($raceId, $returnToEdit);

        ensure_csrf_or_redirect($redirectUrl);

        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            $accountingDate = $this->resolveRefacturareAccountingDate($_POST, $returnToEdit);
            $old = $this->defaultRefacturareFormData();
            $old = array_merge($old, [
                'race_id' => $raceId > 0 ? (string) $raceId : '',
                'expense_id' => trim((string) ($_POST['expense_id'] ?? '')),
                'tip_cheltuiala' => trim((string) ($_POST['tip_cheltuiala'] ?? '')),
                'refacturare_enabled' => '1',
                'refacturare_tip_cheltuiala' => trim((string) ($_POST['refacturare_tip_cheltuiala'] ?? '')),
                'refacturare_suma' => trim((string) ($_POST['refacturare_suma'] ?? '')),
                'refacturare_data' => trim((string) ($_POST['refacturare_data'] ?? date('Y-m-d'))),
                'refacturare_observatii' => trim((string) ($_POST['refacturare_observatii'] ?? '')),
                'suma' => trim((string) ($_POST['suma'] ?? '')),
                'data_cheltuiala' => $accountingDate,
                'observatii' => trim((string) ($_POST['observatii'] ?? '')),
            ]);
            $this->setRefacturareFormFlash($raceId, $returnToEdit, $old, [
                'race_id' => 'Selecteaza o cursa valida.',
            ]);
            redirect($redirectUrl);
        }

        $refacturareType = trim((string) ($_POST['refacturare_tip_cheltuiala'] ?? ''));

        $mappedInput = [
            'expense_id' => '',
            'submit_intent' => 'refacturare',
            'tip_cheltuiala' => $refacturareType,
            'refacturare_enabled' => '1',
            'refacturare_tip_cheltuiala' => $refacturareType,
            'refacturare_suma' => trim((string) ($_POST['refacturare_suma'] ?? '')),
            'refacturare_data' => trim((string) ($_POST['refacturare_data'] ?? date('Y-m-d'))),
            'refacturare_observatii' => trim((string) ($_POST['refacturare_observatii'] ?? '')),
            'suma' => '0.00',
            'data_cheltuiala' => $this->resolveRefacturareAccountingDate($_POST, $returnToEdit),
            'observatii' => trim((string) ($_POST['refacturare_observatii'] ?? '')),
            'taxa_acces_bucati' => '',
            'taxa_acces_pret' => '',
            'port_bucati' => '',
            'port_pret' => '',
            'trece_bucati' => '',
            'trece_pret' => '',
            'refacturare_taxa_acces_bucati' => trim((string) ($_POST['refacturare_taxa_acces_bucati'] ?? '')),
            'refacturare_taxa_acces_pret' => trim((string) ($_POST['refacturare_taxa_acces_pret'] ?? '')),
            'refacturare_port_bucati' => trim((string) ($_POST['refacturare_port_bucati'] ?? '')),
            'refacturare_port_pret' => trim((string) ($_POST['refacturare_port_pret'] ?? '')),
            'refacturare_trece_bucati' => trim((string) ($_POST['refacturare_trece_bucati'] ?? '')),
            'refacturare_trece_pret' => trim((string) ($_POST['refacturare_trece_pret'] ?? '')),
        ];

        [$data, $errors, $old] = $this->validateExpenseInput($mappedInput);
        $old['race_id'] = (string) $raceId;
        $old['refacturare_enabled'] = '1';
        if ($returnToEdit) {
            $old = $this->buildEditRefacturareFlashOld($old, $_POST);
        }
        [$uploadedRefacturareDocument, $refacturareUploadError] = $this->storeUploadedExpenseDocument($_FILES['refacturare_document_upload'] ?? null);
        if ($refacturareUploadError !== null) {
            $errors['refacturare_document_upload'] = $refacturareUploadError;
        }

        if ($errors !== []) {
            if ($uploadedRefacturareDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedRefacturareDocument['file_path']);
            }
            $this->setRefacturareFormFlash($raceId, $returnToEdit, $old, $errors);
            redirect($redirectUrl);
        }

        try {
            $now = date('Y-m-d H:i:s');
            $data['cursa_id'] = $raceId;
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $expenseId = $this->model->createExpense($data);

            if ($uploadedRefacturareDocument !== null) {
                $this->model->updateExpenseRefacturareDocument($expenseId, $uploadedRefacturareDocument);
            }

            $this->model->updateRaceBillingStatus($raceId, self::DEFAULT_BILLING_STATUS, $now, $this->currentUserId());
            $this->resetRaceExpenseStatusIfNotApplicable($raceId, $now, true);
            flash_set('success', 'Refacturarea a fost adaugata.');
        } catch (PDOException $exception) {
            if ($uploadedRefacturareDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedRefacturareDocument['file_path']);
            }

            error_log('[DispecerCurseController][store_refacturare] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva refacturarea.');
            $this->setRefacturareFormFlash($raceId, $returnToEdit, $old, []);
        }

        redirect($redirectUrl);
    }

    private function buildRefacturareRedirectUrl(int $raceId, bool $returnToEdit): string
    {
        if ($returnToEdit && $raceId > 0) {
            return build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]);
        }

        $redirectQuery = [
            'page' => 'dispecer_curse',
            'action' => 'refacturari',
        ];
        if ($raceId > 0) {
            $redirectQuery['race_id'] = $raceId;
        }

        return build_query_url($redirectQuery);
    }

    private function setRefacturareFormFlash(int $raceId, bool $returnToEdit, array $old, array $errors): void
    {
        if ($returnToEdit && $raceId > 0) {
            $this->setFormFlash('expense_' . $raceId, $old, $errors);
            return;
        }

        $this->setFormFlash('refacturare_create', $old, $errors);
    }

    private function buildEditRefacturareFlashOld(array $old, array $input): array
    {
        return array_merge($old, [
            'expense_id' => trim((string) ($input['expense_id'] ?? '')),
            'tip_cheltuiala' => trim((string) ($input['tip_cheltuiala'] ?? ($old['tip_cheltuiala'] ?? ''))),
            'suma' => trim((string) ($input['suma'] ?? '')),
            'data_cheltuiala' => trim((string) ($input['data_cheltuiala'] ?? date('Y-m-d'))),
            'observatii' => trim((string) ($input['observatii'] ?? '')),
            'refacturare_enabled' => '1',
        ]);
    }

    private function resolveRefacturareAccountingDate(array $input, bool $returnToEdit): string
    {
        if ($returnToEdit) {
            $date = trim((string) ($input['refacturare_data'] ?? ''));
            return $this->isValidDate($date) ? $date : date('Y-m-d');
        }

        $date = trim((string) ($input['data_cheltuiala'] ?? ''));
        return $date !== '' ? $date : date('Y-m-d');
    }

    private function toggleRefacturareInvoicedAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari']));
        }

        $raceId = (int) ($_POST['race_id'] ?? 0);
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        $redirectQuery = [
            'page' => 'dispecer_curse',
            'action' => 'refacturari',
        ];
        if ($raceId > 0) {
            $redirectQuery['race_id'] = $raceId;
        }
        $redirectUrl = build_query_url($redirectQuery);
        ensure_csrf_or_redirect($redirectUrl);

        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        if ($expenseId <= 0) {
            flash_set('warning', 'Refacturarea selectata este invalida.');
            $this->redirectToSafeDispecerUrl($returnUrl !== '' ? $returnUrl : $redirectUrl);
        }

        $expense = $this->model->getExpenseById($expenseId);
        if ($expense === null) {
            flash_set('warning', 'Refacturarea selectata nu exista.');
            $this->redirectToSafeDispecerUrl($returnUrl !== '' ? $returnUrl : $redirectUrl);
        }

        $expenseRaceId = (int) ($expense['cursa_id'] ?? 0);
        if ($raceId > 0 && $expenseRaceId > 0 && $raceId !== $expenseRaceId) {
            flash_set('warning', 'Refacturarea selectata nu apartine cursei curente.');
            $this->redirectToSafeDispecerUrl($returnUrl !== '' ? $returnUrl : $redirectUrl);
        }

        $refacturareAmount = (float) ($expense['refacturare_suma'] ?? 0);
        if ($refacturareAmount <= 0) {
            flash_set('warning', 'Doar intrarile cu suma de refacturare pot fi marcate ca facturate.');
            $this->redirectToSafeDispecerUrl($returnUrl !== '' ? $returnUrl : $redirectUrl);
        }

        $isInvoiced = (string) ($_POST['is_invoiced'] ?? '0') === '1';
        $invoicedAt = $isInvoiced ? date('Y-m-d H:i:s') : null;

        try {
            $this->model->updateRefacturareInvoicedStatus($expenseId, $isInvoiced, $invoicedAt);
            if ($isInvoiced) {
                flash_set('success', 'Refacturarea a fost marcata ca facturata. Suma a fost mutata in Total Facturare.');
            } else {
                flash_set('success', 'Marcarea facturarii a fost anulata. Suma a revenit in Total Refacturare.');
            }
        } catch (PDOException $exception) {
            error_log('[DispecerCurseController][toggle_refacturare_facturata] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut actualiza statusul facturarii pentru refacturare.');
        }

        if ($raceId <= 0 && $expenseRaceId > 0) {
            $redirectQuery['race_id'] = $expenseRaceId;
            $redirectUrl = build_query_url($redirectQuery);
        }

        $this->redirectToSafeDispecerUrl($returnUrl !== '' ? $returnUrl : $redirectUrl);
    }

    private function configAction(bool $isSandbox = false): void
    {
        require_admin_or_403();

        $locFlash = $this->consumeFormFlash('config_loc');
        $zoneFlash = $this->consumeFormFlash('config_zona');
        $distributionOnlyRouteFlash = $this->consumeFormFlash(
            $this->distributionRouteFlashKey(self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE)
        );
        $primaryDistributionRouteFlash = $this->consumeFormFlash(
            $this->distributionRouteFlashKey(self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE)
        );
        $legacyDistributionRouteFlash = $this->consumeFormFlash('config_distributie_route');
        if ($primaryDistributionRouteFlash['old'] === [] && $primaryDistributionRouteFlash['errors'] === []) {
            $primaryDistributionRouteFlash = $legacyDistributionRouteFlash;
        }
        $primaryRouteFlash = $this->consumeFormFlash('config_primar_route');
        $beneficiaryFlash = $this->consumeFormFlash('config_beneficiar');

        $beneficiaries = [];
        $configVehicles = [];
        $vehicles = [];
        $locationVehicleAssignments = [];
        $zoneVehicleAssignments = [];
        $locations = [];
        $zones = [];
        $distributionRouteRules = [];
        $distributionOnlyRouteRules = [];
        $primaryDistributionRouteRules = [];
        $primaryRouteRules = [];
        $viewBeneficiary = null;
        $editBeneficiary = null;
        $editLocation = null;
        $editZone = null;

        $viewBeneficiaryId = (int) ($_GET['beneficiar_view_id'] ?? 0);
        $editBeneficiaryId = (int) ($_GET['beneficiar_edit_id'] ?? 0);
        $editLocationId = (int) ($_GET['loc_edit_id'] ?? 0);
        $editZoneId = (int) ($_GET['zona_edit_id'] ?? 0);

        $transportTypeOptions = [
            'primar' => 'Primar km',
            'distributie' => 'Distributie',
            'primar_distributie' => 'Primar+Distributie',
            'compresor' => 'Compresor',
        ];

        $defaultBeneficiaryForm = [
            'id' => '',
            'nume' => '',
            'pret_tarifare' => '',
            'tip_transporturi' => ['primar'],
            'suporta_primar' => '1',
            'suporta_distributie' => '0',
            'suporta_primar_distributie' => '0',
            'suporta_compresor' => '0',
            'pret_km' => '',
            'pret_tona' => '',
            'pret_distributie_km' => '',
            'pret_distributie_tona' => '',
            'pret_ora_aspirare' => '',
            'pret_km_dislocare' => '',
            'pret_tona_livrata' => '',
            'pret_tona_aspirata_lichida' => '',
            'pret_tona_aspirata_gazoasa' => '',
            'compresor_vehicle_ids' => [],
            'activ' => '1',
        ];

        try {
            $beneficiaries = $this->model->getTransportBeneficiaries(false);
            $vehicles = $this->model->getVehicleOptions();
            $configVehicles = $this->model->getVehicleOptions(true);

            $viewBeneficiary = $viewBeneficiaryId > 0
                ? $this->model->getTransportBeneficiaryById($viewBeneficiaryId)
                : null;
            if ($viewBeneficiaryId > 0 && $viewBeneficiary === null) {
                flash_set('warning', 'Beneficiarul selectat pentru detalii nu a fost gasit.');
            }

            $editBeneficiary = $editBeneficiaryId > 0
                ? $this->model->getTransportBeneficiaryById($editBeneficiaryId)
                : null;
            if ($editBeneficiaryId > 0 && $editBeneficiary === null) {
                flash_set('warning', 'Beneficiarul selectat pentru editare nu a fost gasit.');
            }

            if ($editBeneficiary !== null) {
                $compressorVehicleIds = $this->model->getVehicleIdsForCompressorBeneficiary((int) ($editBeneficiary['id'] ?? 0));
                $selectedTransportTypes = [];
                if (!empty($editBeneficiary['suporta_primar'])) {
                    $selectedTransportTypes[] = 'primar';
                }
                if (!empty($editBeneficiary['suporta_distributie'])) {
                    $selectedTransportTypes[] = 'distributie';
                }
                if (!empty($editBeneficiary['suporta_primar_distributie'])) {
                    $selectedTransportTypes[] = 'primar_distributie';
                }
                if (!empty($editBeneficiary['suporta_compresor'])) {
                    $selectedTransportTypes[] = 'compresor';
                }

                $defaultBeneficiaryForm = array_merge($defaultBeneficiaryForm, [
                    'id' => (string) ((int) ($editBeneficiary['id'] ?? 0)),
                    'nume' => (string) ($editBeneficiary['nume'] ?? ''),
                    'pret_tarifare' => number_format((float) ($editBeneficiary['pret_tarifare'] ?? 0), 2, '.', ''),
                    'tip_transporturi' => $selectedTransportTypes,
                    'suporta_primar' => !empty($editBeneficiary['suporta_primar']) ? '1' : '0',
                    'suporta_distributie' => !empty($editBeneficiary['suporta_distributie']) ? '1' : '0',
                    'suporta_primar_distributie' => !empty($editBeneficiary['suporta_primar_distributie']) ? '1' : '0',
                    'suporta_compresor' => !empty($editBeneficiary['suporta_compresor']) ? '1' : '0',
                    'pret_km' => number_format((float) ($editBeneficiary['pret_km'] ?? 0), 2, '.', ''),
                    'pret_tona' => number_format((float) ($editBeneficiary['pret_tona'] ?? 0), 2, '.', ''),
                    'pret_distributie_km' => number_format((float) ($editBeneficiary['pret_distributie_km'] ?? 0), 2, '.', ''),
                    'pret_distributie_tona' => number_format((float) ($editBeneficiary['pret_distributie_tona'] ?? 0), 2, '.', ''),
                    'pret_ora_aspirare' => number_format((float) ($editBeneficiary['pret_ora_aspirare'] ?? 0), 2, '.', ''),
                    'pret_km_dislocare' => number_format((float) ($editBeneficiary['pret_km_dislocare'] ?? 0), 2, '.', ''),
                    'pret_tona_livrata' => number_format((float) ($editBeneficiary['pret_tona_livrata'] ?? 0), 2, '.', ''),
                    'pret_tona_aspirata_lichida' => number_format((float) ($editBeneficiary['pret_tona_aspirata_lichida'] ?? 0), 2, '.', ''),
                    'pret_tona_aspirata_gazoasa' => number_format((float) ($editBeneficiary['pret_tona_aspirata_gazoasa'] ?? 0), 2, '.', ''),
                    'compresor_vehicle_ids' => array_values(array_unique(array_map('strval', $compressorVehicleIds))),
                    'activ' => !empty($editBeneficiary['activ']) ? '1' : '0',
                ]);
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        $beneficiaryFormData = array_merge($defaultBeneficiaryForm, $beneficiaryFlash['old']);
        $selectedTransportTypes = $beneficiaryFormData['tip_transporturi'] ?? [];
        if (!is_array($selectedTransportTypes)) {
            $selectedTransportTypes = [];
        }
        $selectedTransportTypes = array_values(array_unique(array_map('strval', $selectedTransportTypes)));
        $transportFocus = trim((string) ($_GET['transport_focus'] ?? ''));
        if ($transportFocus === 'compresor' && !in_array('compresor', $selectedTransportTypes, true)) {
            $selectedTransportTypes[] = 'compresor';
        }
        $beneficiaryFormData['tip_transporturi'] = $selectedTransportTypes;
        $beneficiaryFormData['compresor_vehicle_ids'] = is_array($beneficiaryFormData['compresor_vehicle_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $beneficiaryFormData['compresor_vehicle_ids'])))
            : [];

        $distributionBeneficiaryId = (int) ($beneficiaryFormData['id'] ?? 0);
        // Rutele Primar pe 4 puncte (garaj plecare -> incarcare -> descarcare -> garaj intoarcere)
        // se activeaza per beneficiar, nu global.
        $activeTariff = $this->buildActiveTariffResolver($distributionBeneficiaryId);
        $primaryRouteExtendedPoints = false;
        if ($distributionBeneficiaryId > 0) {
            $extendedPointsBeneficiary = $this->model->getTransportBeneficiaryById($distributionBeneficiaryId);
            $primaryRouteExtendedPoints = $extendedPointsBeneficiary !== null
                && !empty($extendedPointsBeneficiary['rute_primar_puncte_extinse']);
        }
        $distributionEnabled = in_array('distributie', $selectedTransportTypes, true);
        $primaryEnabled = in_array('primar', $selectedTransportTypes, true);
        $primaryDistributionEnabled = in_array('primar_distributie', $selectedTransportTypes, true);

        try {
            if ($distributionBeneficiaryId > 0) {
                if ($primaryEnabled || $primaryDistributionEnabled) {
                    try {
                        $this->syncPrimaryRouteBidirectionalCatalog($distributionBeneficiaryId);
                    } catch (Throwable $exception) {
                        error_log('[DispecerCurseController][config_primary_bidirectional_sync] ' . $exception->getMessage());
                    }
                }

                $locations = $this->model->getLoadLocations(false, $distributionBeneficiaryId);
                $zones = $this->model->getDistributionZones(false, $distributionBeneficiaryId);
                $distributionRouteRules = $this->model->getDistributionRouteRules(false, $distributionBeneficiaryId);
                $distributionOnlyRouteRules = $this->model->getDistributionRouteRules(
                    false,
                    $distributionBeneficiaryId,
                    self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
                );
                $primaryDistributionRouteRules = $this->model->getDistributionRouteRules(
                    false,
                    $distributionBeneficiaryId,
                    self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
                );
                $primaryRouteRules = $this->model->getPrimaryRouteRules(false, $distributionBeneficiaryId);

                // Preturile afisate aici trebuie sa fie cele active din Administrare tarife.
                $routeTariffColumns = [
                    'tarif_tona' => 'tarif_tona',
                    'cost_extra_km' => 'cost_extra_km',
                    'cost_cursa' => 'cost_cursa',
                ];
                $distributionOnlyRouteRules = $this->applyActiveTariffsToRouteRules(
                    $distributionOnlyRouteRules,
                    $activeTariff,
                    $routeTariffColumns
                );
                $primaryDistributionRouteRules = $this->applyActiveTariffsToRouteRules(
                    $primaryDistributionRouteRules,
                    $activeTariff,
                    $routeTariffColumns
                );
                $primaryRouteRules = $this->applyActiveTariffsToRouteRules(
                    $primaryRouteRules,
                    $activeTariff,
                    ['cost_cursa' => 'cost_cursa']
                );
                $vehicleDefaultLoadLocationMap = $this->model->getVehicleDefaultLoadLocationMap($distributionBeneficiaryId);
                $vehicleDefaultDistributionZoneMap = $this->model->getVehicleDefaultDistributionZoneMap($distributionBeneficiaryId);
                $locationVehicleAssignments = $this->buildLocationVehicleAssignments($vehicles, $vehicleDefaultLoadLocationMap);
                $zoneVehicleAssignments = $this->buildLocationVehicleAssignments($vehicles, $vehicleDefaultDistributionZoneMap);

                $editLocation = $editLocationId > 0
                    ? $this->model->getLoadLocationById($editLocationId)
                    : null;
                if ($editLocationId > 0 && ($editLocation === null || (int) ($editLocation['beneficiar_id'] ?? 0) !== $distributionBeneficiaryId)) {
                    $editLocation = null;
                    flash_set('warning', 'Locatia selectata pentru editare nu apartine beneficiarului curent.');
                }

                $editZone = $editZoneId > 0
                    ? $this->model->getDistributionZoneById($editZoneId)
                    : null;
                if ($editZoneId > 0 && ($editZone === null || (int) ($editZone['beneficiar_id'] ?? 0) !== $distributionBeneficiaryId)) {
                    $editZone = null;
                    flash_set('warning', 'Zona selectata pentru editare nu apartine beneficiarului curent.');
                }
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_distribution_scope] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-au putut incarca setarile de distributie pentru beneficiarul selectat.');
        }

        $defaultLocForm = [
            'id' => '',
            'beneficiar_id' => $distributionBeneficiaryId > 0 ? (string) $distributionBeneficiaryId : '',
            'nume' => '',
            'tarif' => '',
            'vehicle_ids' => [],
            'activ' => '1',
        ];
        if ($editLocation !== null) {
            $locationIdForEdit = (int) ($editLocation['id'] ?? 0);
            $mappedVehicleIds = [];
            if ($locationIdForEdit > 0 && isset($locationVehicleAssignments[$locationIdForEdit])) {
                foreach ((array) $locationVehicleAssignments[$locationIdForEdit] as $assignment) {
                    $vehicleId = (int) ($assignment['vehicle_id'] ?? 0);
                    if ($vehicleId > 0) {
                        $mappedVehicleIds[] = (string) $vehicleId;
                    }
                }
            }
            $defaultLocForm = array_merge($defaultLocForm, [
                'id' => (string) $locationIdForEdit,
                'beneficiar_id' => (string) ((int) ($editLocation['beneficiar_id'] ?? $distributionBeneficiaryId)),
                'nume' => (string) ($editLocation['nume'] ?? ''),
                'tarif' => number_format((float) ($editLocation['tarif'] ?? 0), 2, '.', ''),
                'vehicle_ids' => array_values(array_unique($mappedVehicleIds)),
                'activ' => !empty($editLocation['activ']) ? '1' : '0',
            ]);
        }

        $defaultZoneForm = [
            'id' => '',
            'beneficiar_id' => $distributionBeneficiaryId > 0 ? (string) $distributionBeneficiaryId : '',
            'nume' => '',
            'tarif_distributie' => '',
            'cost_extra_km' => '',
            'ruta_tarif_tona' => '',
            'ruta_cost_extra_km' => '',
            'ruta_km_tarifare' => '',
            'vehicle_ids' => [],
            'activ' => '1',
        ];
        if ($editZone !== null) {
            $zoneIdForEdit = (int) ($editZone['id'] ?? 0);
            $mappedVehicleIds = [];
            if ($zoneIdForEdit > 0 && isset($zoneVehicleAssignments[$zoneIdForEdit])) {
                foreach ((array) $zoneVehicleAssignments[$zoneIdForEdit] as $assignment) {
                    $vehicleId = (int) ($assignment['vehicle_id'] ?? 0);
                    if ($vehicleId > 0) {
                        $mappedVehicleIds[] = (string) $vehicleId;
                    }
                }
            }
            $defaultZoneForm = array_merge($defaultZoneForm, [
                'id' => (string) $zoneIdForEdit,
                'beneficiar_id' => (string) ((int) ($editZone['beneficiar_id'] ?? $distributionBeneficiaryId)),
                'nume' => (string) ($editZone['nume'] ?? ''),
                'tarif_distributie' => number_format((float) ($editZone['tarif_distributie'] ?? 0), 2, '.', ''),
                'cost_extra_km' => number_format((float) ($editZone['cost_extra_km'] ?? 0), 2, '.', ''),
                'vehicle_ids' => array_values(array_unique($mappedVehicleIds)),
                'activ' => !empty($editZone['activ']) ? '1' : '0',
            ]);
        }

        if ($editLocation !== null && $editZone !== null && $distributionBeneficiaryId > 0) {
            $routeRule = $this->model->getDistributionRouteRuleForBeneficiary(
                $distributionBeneficiaryId,
                (int) ($editLocation['id'] ?? 0),
                (int) ($editZone['id'] ?? 0),
                false
            );
            if ($routeRule !== null) {
                $defaultZoneForm['ruta_tarif_tona'] = number_format((float) ($routeRule['tarif_tona'] ?? 0), 2, '.', '');
                $defaultZoneForm['ruta_cost_extra_km'] = number_format((float) ($routeRule['cost_extra_km'] ?? 0), 2, '.', '');
                $defaultZoneForm['ruta_km_tarifare'] = (string) ((int) max(0, (int) ($routeRule['km_tarifare'] ?? 0)));
            }

        }

        $locFormData = array_merge($defaultLocForm, $locFlash['old']);
        $zoneFormData = array_merge($defaultZoneForm, $zoneFlash['old']);
        $locFormData['vehicle_ids'] = is_array($locFormData['vehicle_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $locFormData['vehicle_ids'])))
            : [];
        $zoneFormData['vehicle_ids'] = is_array($zoneFormData['vehicle_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $zoneFormData['vehicle_ids'])))
            : [];
        $locFormMode = trim((string) ($locFormData['id'] ?? '')) !== '' ? 'edit' : 'create';
        $zoneFormMode = trim((string) ($zoneFormData['id'] ?? '')) !== '' ? 'edit' : 'create';

        $buildDistributionRouteDefaultForm = function (string $routeScope) use ($distributionBeneficiaryId, $editLocation, $editZone): array {
            $defaultForm = [
                'route_id' => '',
                'route_scope' => $routeScope,
                'loc_id' => '',
                'zona_id' => '',
                'tarif_mod' => self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH,
                'tarif_tona' => '',
                'cost_extra_km' => '',
                'km_tarifare' => '',
                'cost_cursa' => '',
                'aplica_cost_cursa' => '0',
                'vehicle_ids' => [],
                'activ' => '1',
            ];

            $routeEditParamName = $routeScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
                ? 'route_distributie_edit_id'
                : 'route_primar_distributie_edit_id';
            $routeEditId = (int) ($_GET[$routeEditParamName] ?? 0);
            if ($routeEditId <= 0 && $routeScope === self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE) {
                $routeEditId = (int) ($_GET['route_edit_id'] ?? 0);
            }

            if ($editLocation !== null) {
                $defaultForm['loc_id'] = (string) ((int) ($editLocation['id'] ?? 0));
            }
            if ($editZone !== null) {
                $defaultForm['zona_id'] = (string) ((int) ($editZone['id'] ?? 0));
            }

            if ($editLocation !== null && $editZone !== null && $distributionBeneficiaryId > 0) {
                $prefillRouteRule = $this->model->getDistributionRouteRuleForBeneficiary(
                    $distributionBeneficiaryId,
                    (int) ($editLocation['id'] ?? 0),
                    (int) ($editZone['id'] ?? 0),
                    false,
                    null,
                    $routeScope
                );
                if ($prefillRouteRule !== null) {
                    $defaultForm['tarif_mod'] = $this->normalizeDistributionRouteTariffModeInput((string) ($prefillRouteRule['tarif_mod'] ?? ''));
                    $defaultForm['tarif_tona'] = number_format((float) ($prefillRouteRule['tarif_tona'] ?? 0), 2, '.', '');
                    $defaultForm['cost_extra_km'] = number_format((float) ($prefillRouteRule['cost_extra_km'] ?? 0), 2, '.', '');
                    $defaultForm['km_tarifare'] = (string) ((int) max(0, (int) ($prefillRouteRule['km_tarifare'] ?? 0)));
                    $defaultForm['cost_cursa'] = number_format((float) ($prefillRouteRule['cost_cursa'] ?? 0), 2, '.', '');
                    $defaultForm['aplica_cost_cursa'] = !empty($prefillRouteRule['aplica_cost_cursa']) ? '1' : '0';
                    $routeVehicleIdsRaw = trim((string) ($prefillRouteRule['vehicle_ids'] ?? ''));
                    if ($routeVehicleIdsRaw !== '') {
                        $routeVehicleIds = [];
                        foreach (explode(',', $routeVehicleIdsRaw) as $routeVehicleIdRaw) {
                            $routeVehicleIdRaw = trim($routeVehicleIdRaw);
                            if ($routeVehicleIdRaw === '' || !ctype_digit($routeVehicleIdRaw)) {
                                continue;
                            }

                            $routeVehicleId = (int) $routeVehicleIdRaw;
                            if ($routeVehicleId > 0) {
                                $routeVehicleIds[] = (string) $routeVehicleId;
                            }
                        }
                        $defaultForm['vehicle_ids'] = array_values(array_unique($routeVehicleIds));
                    }
                }
            }

            if ($routeEditId > 0 && $distributionBeneficiaryId > 0) {
                $routeEditRule = $this->model->getDistributionRouteRuleById($routeEditId);
                if (
                    $routeEditRule !== null
                    && (int) ($routeEditRule['beneficiar_id'] ?? 0) === $distributionBeneficiaryId
                    && $this->normalizeDistributionRouteScopeInput((string) ($routeEditRule['transport_scope'] ?? ''), $routeScope) === $routeScope
                ) {
                    $defaultForm['route_id'] = (string) ((int) ($routeEditRule['id'] ?? 0));
                    $defaultForm['loc_id'] = (string) ((int) ($routeEditRule['loc_incarcare_id'] ?? 0));
                    $defaultForm['zona_id'] = (string) ((int) ($routeEditRule['zona_distributie_id'] ?? 0));
                    $defaultForm['tarif_mod'] = $this->normalizeDistributionRouteTariffModeInput((string) ($routeEditRule['tarif_mod'] ?? ''));
                    $defaultForm['tarif_tona'] = number_format((float) ($routeEditRule['tarif_tona'] ?? 0), 2, '.', '');
                    $defaultForm['cost_extra_km'] = number_format((float) ($routeEditRule['cost_extra_km'] ?? 0), 2, '.', '');
                    $defaultForm['km_tarifare'] = (string) ((int) max(0, (int) ($routeEditRule['km_tarifare'] ?? 0)));
                    $defaultForm['cost_cursa'] = number_format((float) ($routeEditRule['cost_cursa'] ?? 0), 2, '.', '');
                    $defaultForm['aplica_cost_cursa'] = !empty($routeEditRule['aplica_cost_cursa']) ? '1' : '0';
                    $defaultForm['activ'] = !empty($routeEditRule['activ']) ? '1' : '0';
                    $defaultForm['route_scope'] = $routeScope;
                    $routeVehicleIdsRaw = trim((string) ($routeEditRule['vehicle_ids'] ?? ''));
                    if ($routeVehicleIdsRaw !== '') {
                        $routeVehicleIds = [];
                        foreach (explode(',', $routeVehicleIdsRaw) as $routeVehicleIdRaw) {
                            $routeVehicleIdRaw = trim($routeVehicleIdRaw);
                            if ($routeVehicleIdRaw === '' || !ctype_digit($routeVehicleIdRaw)) {
                                continue;
                            }

                            $routeVehicleId = (int) $routeVehicleIdRaw;
                            if ($routeVehicleId > 0) {
                                $routeVehicleIds[] = (string) $routeVehicleId;
                            }
                        }
                        $defaultForm['vehicle_ids'] = array_values(array_unique($routeVehicleIds));
                    } else {
                        $defaultForm['vehicle_ids'] = [];
                    }
                }
            }

            return $defaultForm;
        };

        $distributionOnlyRouteFormData = array_merge(
            $buildDistributionRouteDefaultForm(self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE),
            $distributionOnlyRouteFlash['old']
        );
        $distributionOnlyRouteFormData['route_scope'] = $this->normalizeDistributionRouteScopeInput(
            (string) ($distributionOnlyRouteFormData['route_scope'] ?? ''),
            self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
        );
        $distributionOnlyRouteFormData['tarif_mod'] = $this->normalizeDistributionRouteTariffModeInput(
            (string) ($distributionOnlyRouteFormData['tarif_mod'] ?? '')
        );
        $distributionOnlyRouteFormData['vehicle_ids'] = is_array($distributionOnlyRouteFormData['vehicle_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $distributionOnlyRouteFormData['vehicle_ids'])))
            : [];
        $distributionOnlyRouteFormMode = trim((string) ($distributionOnlyRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create';

        $primaryDistributionRouteFormData = array_merge(
            $buildDistributionRouteDefaultForm(self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE),
            $primaryDistributionRouteFlash['old']
        );
        $primaryDistributionRouteFormData['route_scope'] = $this->normalizeDistributionRouteScopeInput(
            (string) ($primaryDistributionRouteFormData['route_scope'] ?? ''),
            self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
        );
        $primaryDistributionRouteFormData['tarif_mod'] = self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH;
        $primaryDistributionRouteFormData['vehicle_ids'] = is_array($primaryDistributionRouteFormData['vehicle_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $primaryDistributionRouteFormData['vehicle_ids'])))
            : [];
        $primaryDistributionRouteFormMode = trim((string) ($primaryDistributionRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create';

        $defaultPrimaryRouteForm = [
            'route_id' => '',
            'loc_id' => '',
            'zona_id' => '',
            'garaj_plecare' => '',
            'garaj_intoarcere' => [],
            'km_tarifare' => '',
            'cost_cursa' => '',
            'aplica_cost_cursa' => '0',
            'vehicle_ids' => [],
            'km_agreati_manual' => '0',
            'activ' => '1',
        ];
        $primaryRouteEditId = (int) ($_GET['route_primar_edit_id'] ?? 0);
        if ($primaryRouteEditId > 0 && $distributionBeneficiaryId > 0) {
            $primaryRouteEditRule = $this->model->getPrimaryRouteRuleById($primaryRouteEditId, $distributionBeneficiaryId);
            if ($primaryRouteEditRule !== null) {
                $defaultPrimaryRouteForm = [
                    'route_id' => (string) ((int) ($primaryRouteEditRule['id'] ?? 0)),
                    'loc_id' => (string) ((int) ($primaryRouteEditRule['loc_incarcare_id'] ?? 0)),
                    'zona_id' => (string) ((int) ($primaryRouteEditRule['zona_distributie_id'] ?? 0)),
                    'garaj_plecare' => (string) ($primaryRouteEditRule['garaj_plecare'] ?? ''),
                    'garaj_intoarcere' => $this->model->normalizeRouteReturnPoints($primaryRouteEditRule['garaj_intoarcere'] ?? ''),
                    'km_tarifare' => !empty($primaryRouteEditRule['km_agreati_manual'])
                        ? ''
                        : (string) ((int) ($primaryRouteEditRule['km_tarifare'] ?? 0)),
                    'cost_cursa' => number_format((float) ($primaryRouteEditRule['cost_cursa'] ?? 0), 2, '.', ''),
                    'aplica_cost_cursa' => !empty($primaryRouteEditRule['aplica_cost_cursa']) ? '1' : '0',
                    'vehicle_ids' => array_map(
                        'strval',
                        $this->parseDistributionRouteVehicleIds((string) ($primaryRouteEditRule['vehicle_ids'] ?? ''))
                    ),
                    'km_agreati_manual' => !empty($primaryRouteEditRule['km_agreati_manual']) ? '1' : '0',
                    'activ' => !empty($primaryRouteEditRule['activ']) ? '1' : '0',
                ];
            }
        }
        $primaryRouteFormData = array_merge($defaultPrimaryRouteForm, $primaryRouteFlash['old']);
        $primaryRouteFormData['garaj_intoarcere'] = $this->model->normalizeRouteReturnPoints(
            $primaryRouteFormData['garaj_intoarcere'] ?? []
        );
        $primaryRouteFormData['vehicle_ids'] = is_array($primaryRouteFormData['vehicle_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $primaryRouteFormData['vehicle_ids'])))
            : [];
        $primaryRouteFormMode = trim((string) ($primaryRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create';

        render($isSandbox ? 'dispecer_curse/config_v2.php' : 'dispecer_curse/config.php', [
            'pageTitle' => $isSandbox ? 'Configurare transport (Sandbox)' : 'Configurare transport',
            'currentPage' => 'dispecer_curse',
            'locations' => $locations,
            'zones' => $zones,
            'distributionRouteRules' => $distributionRouteRules,
            'distributionOnlyRouteRules' => $distributionOnlyRouteRules,
            'primaryDistributionRouteRules' => $primaryDistributionRouteRules,
            'primaryRouteRules' => $primaryRouteRules,
            'beneficiaries' => $beneficiaries,
            'vehicles' => $configVehicles,
            'locationVehicleAssignments' => $locationVehicleAssignments,
            'zoneVehicleAssignments' => $zoneVehicleAssignments,
            'viewBeneficiary' => $viewBeneficiary,
            'beneficiaryFormMode' => trim((string) ($beneficiaryFormData['id'] ?? '')) !== '' ? 'edit' : 'create',
            'transportTypeOptions' => $transportTypeOptions,
            'locFormData' => $locFormData,
            'locFormErrors' => $locFlash['errors'],
            'locFormMode' => $locFormMode,
            'zoneFormData' => $zoneFormData,
            'zoneFormErrors' => $zoneFlash['errors'],
            'zoneFormMode' => $zoneFormMode,
            'distributionOnlyRouteFormData' => $distributionOnlyRouteFormData,
            'distributionOnlyRouteFormErrors' => $distributionOnlyRouteFlash['errors'],
            'distributionOnlyRouteFormMode' => $distributionOnlyRouteFormMode,
            'distributionRouteTariffModeOptions' => self::DISTRIBUTION_ROUTE_TARIFF_MODE_LABELS,
            'primaryDistributionRouteFormData' => $primaryDistributionRouteFormData,
            'primaryDistributionRouteFormErrors' => $primaryDistributionRouteFlash['errors'],
            'primaryDistributionRouteFormMode' => $primaryDistributionRouteFormMode,
            'primaryRouteFormData' => $primaryRouteFormData,
            'primaryRouteFormErrors' => $primaryRouteFlash['errors'],
            'primaryRouteFormMode' => $primaryRouteFormMode,
            'beneficiaryFormData' => $beneficiaryFormData,
            'beneficiaryFormErrors' => $beneficiaryFlash['errors'],
            'distributionBeneficiaryId' => $distributionBeneficiaryId,
            'distributionEnabled' => $distributionEnabled,
            'primaryRouteExtendedPoints' => $primaryRouteExtendedPoints,
            'primaryRoutePointOptions' => $primaryRouteExtendedPoints
                ? $this->model->getPrimaryRoutePointOptions($distributionBeneficiaryId)
                : [],
        ]);
    }
    /**
     * Preturile rutelor se administreaza versionat in "Administrare tarife transport",
     * deci coloanele din configurare_rute_* raman pe valoarea veche (deseori 0). Pentru
     * afisarea read-only din Configurare transport folosim versiunea ACTIVA, cu fallback
     * pe coloana, ca sa nu arate 0 acolo unde exista de fapt un tarif.
     */
    private function buildActiveTariffResolver(int $beneficiaryId): callable
    {
        $activeByKey = [];

        if ($beneficiaryId > 0 && class_exists('TransportTariffModel')) {
            try {
                $tariffModel = new TransportTariffModel($this->db);
                foreach ($tariffModel->getVersionsForBeneficiary($beneficiaryId) as $version) {
                    if ((string) ($version['status'] ?? '') !== 'active') {
                        continue;
                    }
                    $componentKey = (string) ($version['component_key'] ?? '');
                    if ($componentKey === '') {
                        continue;
                    }
                    $activeByKey[$componentKey . '|' . (int) ($version['route_ref_id'] ?? 0)] = (float) ($version['value'] ?? 0);
                }
            } catch (Throwable $exception) {
                error_log('[DispecerCurseController][active_tariffs] ' . $exception->getMessage());
            }
        }

        return static function (string $componentKey, int $routeRefId, $fallback) use ($activeByKey) {
            $mapKey = $componentKey . '|' . $routeRefId;

            return array_key_exists($mapKey, $activeByKey) ? $activeByKey[$mapKey] : $fallback;
        };
    }

    /**
     * Pretul de baza al rutei ramane cel configurat aici; o schimbare programata din
     * "Administrare tarife" are insa prioritate la facturare cat timp e activa. Nu
     * suprascriem valoarea afisata (altfel pretul tastat ar parea ca se pierde), ci o
     * marcam, ca sa se vada cand baza nu e ce se aplica de fapt.
     */
    private function applyActiveTariffsToRouteRules(array $rules, callable $activeTariff, array $componentColumns): array
    {
        foreach ($rules as $index => $rule) {
            $routeId = (int) ($rule['id'] ?? 0);
            if ($routeId <= 0) {
                continue;
            }

            $overrides = [];
            foreach ($componentColumns as $componentKey => $column) {
                $baseValue = (float) ($rule[$column] ?? 0);
                $effectiveValue = (float) $activeTariff($componentKey, $routeId, $baseValue);
                if (abs($effectiveValue - $baseValue) >= 0.005) {
                    $overrides[$column] = $effectiveValue;
                }
            }
            $rules[$index]['tarif_overrides'] = $overrides;
        }

        return $rules;
    }

    private function configStoreDistributionAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $routeScope = $this->normalizeDistributionRouteScopeInput(
            (string) ($_POST['route_scope'] ?? ''),
            self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
        );
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Selecteaza un beneficiar valid pentru configurarea distributiei.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
        if (
            $beneficiary === null
            || (
                $routeScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
                && empty($beneficiary['suporta_distributie'])
            )
            || (
                $routeScope === self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
                && empty($beneficiary['suporta_primar_distributie'])
            )
        ) {
            flash_set('warning', $routeScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
                ? 'Beneficiarul selectat nu este configurat pentru transport Distributie.'
                : 'Beneficiarul selectat nu este configurat pentru transport Primar+Distributie.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $panelAction = trim((string) ($_POST['panel_action'] ?? 'add_route'));
        if ($panelAction !== 'add_route') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $routeFlashKey = $this->distributionRouteFlashKey($routeScope);
        $routeEditId = (int) ($_POST['route_id'] ?? 0);
        $locationId = (int) ($_POST['route_loc_id'] ?? ($_POST['loc_id'] ?? 0));
        $zoneId = (int) ($_POST['route_zona_id'] ?? ($_POST['zona_id'] ?? 0));
        $routeTariffMode = $routeScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
            ? $this->normalizeDistributionRouteTariffModeInput((string) ($_POST['route_tarif_mod'] ?? ''))
            : self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH;
        $routeTariffRaw = trim((string) ($_POST['route_tarif_tona'] ?? ($_POST['ruta_tarif_tona'] ?? '')));
        $routeExtraKmCostRaw = trim((string) ($_POST['route_cost_extra_km'] ?? ($_POST['ruta_cost_extra_km'] ?? '')));
        $routeKmTariffRaw = trim((string) ($_POST['route_km_tarifare'] ?? ($_POST['ruta_km_tarifare'] ?? '')));
        $routeRideCostRaw = trim((string) ($_POST['route_cost_cursa'] ?? ''));
        $routeApplyRideCost = isset($_POST['route_aplica_cost_cursa']) ? (string) $_POST['route_aplica_cost_cursa'] === '1' : false;
        if ($routeScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE) {
            $routeKmTariffRaw = '0';
            $routeRideCostRaw = '';
            $routeApplyRideCost = false;
        }
        $routeUsesTonTariff = in_array($routeTariffMode, [
            self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH,
            self::DISTRIBUTION_ROUTE_TARIFF_MODE_TON,
        ], true);
        $routeUsesKmTariff = in_array($routeTariffMode, [
            self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH,
            self::DISTRIBUTION_ROUTE_TARIFF_MODE_KM,
        ], true);
        if (!$routeUsesTonTariff) {
            $routeTariffRaw = '0';
        }
        if (!$routeUsesKmTariff) {
            $routeExtraKmCostRaw = '0';
        }
        $routeVehicleIdsInput = isset($_POST['route_vehicle_ids']) && is_array($_POST['route_vehicle_ids'])
            ? $_POST['route_vehicle_ids']
            : [];
        if ($routeVehicleIdsInput === []) {
            $routeVehicleIdsInput = isset($_POST['loc_vehicle_ids']) && is_array($_POST['loc_vehicle_ids'])
                ? $_POST['loc_vehicle_ids']
                : [];
            if (isset($_POST['zona_vehicle_ids']) && is_array($_POST['zona_vehicle_ids'])) {
                $routeVehicleIdsInput = array_merge($routeVehicleIdsInput, $_POST['zona_vehicle_ids']);
            }
        }
        $routeActive = isset($_POST['route_activ']) ? (string) $_POST['route_activ'] === '1' : true;

        $errors = [];
        if ($locationId <= 0) {
            $errors['loc_id'] = 'Selecteaza locul de incarcare.';
        } elseif (!$this->model->existsLoadLocationForBeneficiary($locationId, $beneficiaryId)) {
            $errors['loc_id'] = 'Locul de incarcare selectat nu apartine beneficiarului curent.';
        }

        if ($zoneId <= 0) {
            $errors['zona_id'] = 'Selecteaza zona de descarcare.';
        } elseif (!$this->model->existsDistributionZoneForBeneficiary($zoneId, $beneficiaryId)) {
            $errors['zona_id'] = 'Zona de descarcare selectata nu apartine beneficiarului curent.';
        }

        // Preturile pe ruta se administreaza din "Administrare tarife transport", deci
        // campurile de aici sunt read-only si vin GOALE cand se adauga o ruta noua.
        // Se accepta ca 0 si se completeaza dupa aceea in modulul de tarife; altfel
        // ruta nu ar putea fi creata deloc. Doar valorile chiar invalide dau eroare.
        $routeTariff = $routeTariffRaw === '' ? 0.0 : $this->normalizeDecimal($routeTariffRaw);
        if ($routeUsesTonTariff && ($routeTariff === null || $routeTariff < 0)) {
            $errors['tarif_tona'] = 'Pretul pe tona este invalid.';
        } elseif (!$routeUsesTonTariff) {
            $routeTariff = 0.0;
        }

        $routeExtraKmCost = $routeExtraKmCostRaw === '' ? 0.0 : $this->normalizeDecimal($routeExtraKmCostRaw);
        if ($routeUsesKmTariff && ($routeExtraKmCost === null || $routeExtraKmCost < 0)) {
            $errors['cost_extra_km'] = 'Pretul pe km este invalid.';
        } elseif (!$routeUsesKmTariff) {
            $routeExtraKmCost = 0.0;
        }

        $routeKmTariff = 0;
        if ($routeScope === self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE) {
            if ($routeKmTariffRaw === '' || !ctype_digit($routeKmTariffRaw)) {
                $errors['km_tarifare'] = 'Km agreati este invalid.';
            } else {
                $routeKmTariff = (int) $routeKmTariffRaw;
                if ($routeKmTariff <= 0) {
                    $errors['km_tarifare'] = 'Km agreati trebuie sa fie mai mare ca 0.';
                }
            }
        } elseif ($routeKmTariffRaw !== '' && ctype_digit($routeKmTariffRaw)) {
            $routeKmTariff = (int) $routeKmTariffRaw;
        }

        $routeRideCost = 0.0;
        if ($routeRideCostRaw !== '') {
            $parsedRideCost = $this->normalizeDecimal($routeRideCostRaw);
            if ($parsedRideCost === null || $parsedRideCost < 0) {
                $errors['cost_cursa'] = 'Costul de cursa este invalid.';
            } else {
                $routeRideCost = (float) $parsedRideCost;
            }
        }
        if ($routeApplyRideCost && $routeRideCost <= 0) {
            $errors['cost_cursa'] = 'Completeaza Cost cursa cu o valoare mai mare ca 0 pentru a activa regula pe ruta.';
        }

        $routeVehicleIds = [];
        $invalidVehicleSelection = false;
        foreach ($routeVehicleIdsInput as $vehicleIdRaw) {
            $vehicleIdRaw = trim((string) $vehicleIdRaw);
            if ($vehicleIdRaw === '') {
                continue;
            }
            if (!ctype_digit($vehicleIdRaw)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $routeVehicleIds[] = $vehicleId;
        }

        $routeVehicleIds = array_values(array_unique($routeVehicleIds));
        if ($routeVehicleIds === []) {
            $errors['vehicle_ids'] = 'Selecteaza cel putin un vehicul.';
        } elseif ($invalidVehicleSelection) {
            $errors['vehicle_ids'] = 'Selecteaza doar vehicule active valide.';
        }

        if ($routeEditId > 0) {
            $existingRoute = $this->model->getDistributionRouteRuleById($routeEditId, $beneficiaryId);
            if ($existingRoute === null) {
                $errors['route_id'] = 'Configuratia selectata pentru editare nu mai exista.';
            } elseif (
                $this->normalizeDistributionRouteScopeInput(
                    (string) ($existingRoute['transport_scope'] ?? ''),
                    self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
                ) !== $routeScope
            ) {
                $errors['route_id'] = 'Configuratia selectata apartine altui panel de tarifare.';
            }
        }

        $old = [
            'route_id' => $routeEditId > 0 ? (string) $routeEditId : '',
            'route_scope' => $routeScope,
            'loc_id' => $locationId > 0 ? (string) $locationId : '',
            'zona_id' => $zoneId > 0 ? (string) $zoneId : '',
            'tarif_mod' => $routeTariffMode,
            'tarif_tona' => $routeTariffRaw,
            'cost_extra_km' => $routeExtraKmCostRaw,
            'km_tarifare' => $routeKmTariffRaw,
            'cost_cursa' => $routeRideCostRaw,
            'aplica_cost_cursa' => $routeApplyRideCost ? '1' : '0',
            'vehicle_ids' => array_map('strval', $routeVehicleIds),
            'activ' => $routeActive ? '1' : '0',
        ];

        if ($errors !== []) {
            $this->setFormFlash($routeFlashKey, $old, $errors);
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        try {
            if ($routeEditId > 0) {
                $updated = $this->model->updateDistributionRouteRuleById(
                    $routeEditId,
                    $beneficiaryId,
                    $locationId,
                    $zoneId,
                    (float) $routeTariff,
                    (float) $routeExtraKmCost,
                    (int) ($routeKmTariff ?? 0),
                    $routeActive,
                    $routeVehicleIds,
                    (float) $routeRideCost,
                    $routeApplyRideCost,
                    $routeScope,
                    $routeTariffMode
                );
                if (!$updated) {
                    throw new RuntimeException('Nu s-a putut actualiza configuratia de ruta.');
                }
                flash_set('success', 'Configuratia de ruta a fost actualizata.');
            } else {
                $this->model->saveDistributionRouteRule(
                    $beneficiaryId,
                    $locationId,
                    $zoneId,
                    (float) $routeTariff,
                    (float) $routeExtraKmCost,
                    (int) ($routeKmTariff ?? 0),
                    $routeActive,
                    $routeVehicleIds,
                    (float) $routeRideCost,
                    $routeApplyRideCost,
                    $routeScope,
                    $routeTariffMode
                );
                flash_set('success', 'Configuratia de ruta a fost salvata.');
            }
        } catch (Throwable $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $message = strtolower($exception->getMessage());
            if (
                $sqlState === '23000'
                || str_contains($message, 'duplicate')
                || str_contains($message, 'uk_config_rute_beneficiar_loc_zona')
                || str_contains($message, 'uk_config_rute_beneficiar_loc_zona_scope')
            ) {
                $errors = ['zona_id' => 'Exista deja o configuratie pentru aceasta combinatie Loc incarcare -> Zona descarcare.'];
                $this->setFormFlash($routeFlashKey, $old, $errors);
                flash_set('warning', 'Combinatia selectata exista deja. Editeaza configuratia existenta sau alege alta combinatie.');
                redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
            }

            error_log('[DispecerCurseController][config_store_distributie] ' . $exception->getMessage());
            $this->setFormFlash($routeFlashKey, $old, []);
            flash_set('danger', $routeEditId > 0 ? 'Nu s-a putut actualiza configuratia de ruta.' : 'Nu s-a putut salva configuratia de ruta.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configStorePrimaryRouteAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Selecteaza un beneficiar valid pentru configurarea Primar.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
        if ($beneficiary === null || empty($beneficiary['suporta_primar'])) {
            flash_set('warning', 'Beneficiarul selectat nu este configurat pentru transport Primar.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $routeEditId = (int) ($_POST['route_primar_id'] ?? 0);
        $locationId = (int) ($_POST['route_primar_loc_id'] ?? 0);
        $zoneId = (int) ($_POST['route_primar_zona_id'] ?? 0);
        // Rutele pe 4 puncte sunt disponibile doar beneficiarilor marcati explicit;
        // pentru restul, garajele trimise in POST sunt ignorate.
        $extendedPointsEnabled = !empty($beneficiary['rute_primar_puncte_extinse']);
        $departureGarage = $extendedPointsEnabled ? trim((string) ($_POST['route_primar_garaj_plecare'] ?? '')) : '';
        // O ruta poate avea mai multe locuri de intoarcere, cu acelasi km si pret.
        $returnPointsInput = $_POST['route_primar_garaj_intoarcere'] ?? [];
        $returnPoints = $extendedPointsEnabled
            ? $this->model->normalizeRouteReturnPoints(is_array($returnPointsInput) ? $returnPointsInput : [$returnPointsInput])
            : [];
        $kmTariffRaw = trim((string) ($_POST['route_primar_km_tarifare'] ?? ''));
        $routeRideCostRaw = trim((string) ($_POST['route_primar_cost_cursa'] ?? ''));
        $routeApplyRideCost = isset($_POST['route_primar_aplica_cost_cursa'])
            && (string) $_POST['route_primar_aplica_cost_cursa'] === '1';
        $routeVehicleIdsInput = isset($_POST['route_primar_vehicle_ids']) && is_array($_POST['route_primar_vehicle_ids'])
            ? $_POST['route_primar_vehicle_ids']
            : [];
        $manualAgreedKm = isset($_POST['route_primar_km_agreati_manual'])
            && (string) $_POST['route_primar_km_agreati_manual'] === '1';
        $routeActive = isset($_POST['route_primar_activ']) ? (string) $_POST['route_primar_activ'] === '1' : true;

        $errors = [];
        if ($locationId <= 0) {
            $errors['loc_id'] = 'Selecteaza locul de incarcare.';
        } elseif (!$this->model->existsLoadLocationForBeneficiary($locationId, $beneficiaryId)) {
            $errors['loc_id'] = 'Locul de incarcare selectat nu apartine beneficiarului curent.';
        }

        if ($zoneId <= 0) {
            $errors['zona_id'] = 'Selecteaza zona de descarcare.';
        } elseif (!$this->model->existsDistributionZoneForBeneficiary($zoneId, $beneficiaryId)) {
            $errors['zona_id'] = 'Zona de descarcare selectata nu apartine beneficiarului curent.';
        }

        if ($extendedPointsEnabled) {
            $availablePoints = $this->model->getPrimaryRoutePointOptions($beneficiaryId);
            if ($departureGarage === '') {
                $errors['garaj_plecare'] = 'Selecteaza locul de plecare.';
            } elseif (!in_array($departureGarage, $availablePoints, true)) {
                $errors['garaj_plecare'] = 'Locul de plecare selectat nu mai exista in garaje sau in catalogul beneficiarului.';
            }

            if ($returnPoints === []) {
                $errors['garaj_intoarcere'] = 'Selecteaza cel putin un loc de intoarcere.';
            } else {
                $unknownReturnPoints = array_values(array_diff($returnPoints, $availablePoints));
                if ($unknownReturnPoints !== []) {
                    $errors['garaj_intoarcere'] = 'Locuri de intoarcere care nu mai exista in garaje sau in catalogul beneficiarului: '
                        . implode(', ', $unknownReturnPoints) . '.';
                }
            }
        }

        $kmTariffFloat = $manualAgreedKm ? 0.0 : $this->normalizeDecimal($kmTariffRaw);
        if (!$manualAgreedKm && ($kmTariffRaw === '' || $kmTariffFloat === null || $kmTariffFloat <= 0)) {
            $errors['km_tarifare'] = 'Km tarifare este invalid.';
        }
        $kmTariff = $kmTariffFloat !== null ? (int) round($kmTariffFloat) : 0;
        if ($manualAgreedKm) {
            $kmTariffRaw = '';
            $kmTariff = 0;
        }

        $routeRideCost = 0.0;
        if ($routeRideCostRaw !== '') {
            $parsedRideCost = $this->normalizeDecimal($routeRideCostRaw);
            if ($parsedRideCost === null || $parsedRideCost < 0) {
                $errors['cost_cursa'] = 'Costul de cursa este invalid.';
            } else {
                $routeRideCost = (float) $parsedRideCost;
            }
        }
        if ($routeApplyRideCost && $routeRideCost <= 0) {
            $errors['cost_cursa'] = 'Completeaza Cost cursa cu o valoare mai mare ca 0 pentru a activa regula pe ruta.';
        }

        $routeVehicleIds = [];
        $invalidVehicleSelection = false;
        foreach ($routeVehicleIdsInput as $vehicleIdRaw) {
            $vehicleIdRaw = trim((string) $vehicleIdRaw);
            if ($vehicleIdRaw === '') {
                continue;
            }
            if (!ctype_digit($vehicleIdRaw)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $routeVehicleIds[] = $vehicleId;
        }
        $routeVehicleIds = array_values(array_unique($routeVehicleIds));
        if ($routeVehicleIds === []) {
            $errors['vehicle_ids'] = 'Selecteaza cel putin un vehicul.';
        } elseif ($invalidVehicleSelection) {
            $errors['vehicle_ids'] = 'Selecteaza doar vehicule active valide.';
        }

        if ($routeEditId > 0) {
            $existingRoute = $this->model->getPrimaryRouteRuleById($routeEditId, $beneficiaryId);
            if ($existingRoute === null) {
                $errors['route_id'] = 'Configuratia Primar selectata pentru editare nu mai exista.';
            }
        }

        // Aceeasi pereche Loc-Zona poate exista pe mai multe reguli (ex. km diferiti
        // pe garaje), dar vehiculele NU au voie sa se suprapuna intre reguli — altfel
        // potrivirea rutei la crearea cursei ar fi ambigua.
        if ($errors === [] && $locationId > 0 && $zoneId > 0 && $routeVehicleIds !== []) {
            foreach ($this->model->getPrimaryRouteRules(false, $beneficiaryId) as $pairRule) {
                $pairRuleId = (int) ($pairRule['id'] ?? 0);
                if ($pairRuleId <= 0 || $pairRuleId === $routeEditId) {
                    continue;
                }
                if ((int) ($pairRule['loc_incarcare_id'] ?? 0) !== $locationId || (int) ($pairRule['zona_distributie_id'] ?? 0) !== $zoneId) {
                    continue;
                }
                // Doua rute pe aceeasi pereche pot folosi ACELEASI vehicule daca difera
                // capetele de traseu (ex. masina se intoarce la garaj vs. ramane parcata
                // la descarcare, cu alt pret). Restrictia de vehicule ramane doar intre
                // rutele cu exact aceleasi capete.
                $pairRuleReturnPoints = $this->model->normalizeRouteReturnPoints($pairRule['garaj_intoarcere'] ?? '');
                if (
                    trim((string) ($pairRule['garaj_plecare'] ?? '')) !== $departureGarage
                    || array_intersect($pairRuleReturnPoints, $returnPoints) === []
                ) {
                    continue;
                }

                $pairRuleVehicleIds = array_filter(array_map('intval', explode(',', (string) ($pairRule['vehicle_ids'] ?? ''))));
                if ($pairRuleVehicleIds === []) {
                    $errors['vehicle_ids'] = 'Exista deja o configuratie cu aceleasi capete de traseu si fara restrictie de vehicule (acopera toate vehiculele). Editeaza-o pe aceea sau limiteaza-i vehiculele mai intai.';
                    break;
                }

                $overlappingVehicleIds = array_values(array_intersect($routeVehicleIds, $pairRuleVehicleIds));
                if ($overlappingVehicleIds !== []) {
                    $errors['vehicle_ids'] = 'Vehiculele selectate se suprapun cu o alta configuratie existenta pe aceeasi combinatie Loc ↔ Zona si cu aceleasi capete de traseu. Schimba locul de plecare / intoarcere sau vehiculele.';
                    break;
                }
            }
        }

        $old = [
            'route_id' => $routeEditId > 0 ? (string) $routeEditId : '',
            'loc_id' => $locationId > 0 ? (string) $locationId : '',
            'zona_id' => $zoneId > 0 ? (string) $zoneId : '',
            'garaj_plecare' => $departureGarage,
            'garaj_intoarcere' => $returnPoints,
            'km_tarifare' => $kmTariffRaw,
            'cost_cursa' => $routeRideCostRaw,
            'aplica_cost_cursa' => $routeApplyRideCost ? '1' : '0',
            'vehicle_ids' => array_map('strval', $routeVehicleIds),
            'km_agreati_manual' => $manualAgreedKm ? '1' : '0',
            'activ' => $routeActive ? '1' : '0',
        ];

        if ($errors !== []) {
            $this->setFormFlash('config_primar_route', $old, $errors);
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        try {
            if ($routeEditId > 0) {
                $updated = $this->model->updatePrimaryRouteRuleById(
                    $routeEditId,
                    $beneficiaryId,
                    $locationId,
                    $zoneId,
                    $kmTariff,
                    $routeVehicleIds,
                    $manualAgreedKm,
                    $routeActive,
                    $routeRideCost,
                    $routeApplyRideCost,
                    $departureGarage !== '' ? $departureGarage : null,
                    $returnPoints
                );
                if (!$updated) {
                    throw new RuntimeException('Nu s-a putut actualiza configuratia Primar.');
                }
                try {
                    $this->ensurePrimaryRouteBidirectionalCatalogForPair($beneficiaryId, $locationId, $zoneId);
                } catch (Throwable $exception) {
                    error_log('[DispecerCurseController][config_store_primar_route_bidirectional_sync] ' . $exception->getMessage());
                }
                flash_set('success', 'Configuratia Primar a fost actualizata.');
            } else {
                $this->model->savePrimaryRouteRule(
                    $beneficiaryId,
                    $locationId,
                    $zoneId,
                    $kmTariff,
                    $routeVehicleIds,
                    $manualAgreedKm,
                    $routeActive,
                    $routeRideCost,
                    $routeApplyRideCost,
                    $departureGarage !== '' ? $departureGarage : null,
                    $returnPoints
                );
                try {
                    $this->ensurePrimaryRouteBidirectionalCatalogForPair($beneficiaryId, $locationId, $zoneId);
                } catch (Throwable $exception) {
                    error_log('[DispecerCurseController][config_store_primar_route_bidirectional_sync] ' . $exception->getMessage());
                }
                flash_set('success', 'Configuratia Primar a fost salvata.');
            }
        } catch (Throwable $exception) {
            $sqlState = strtoupper((string) $exception->getCode());
            $message = strtolower($exception->getMessage());
            if (
                $sqlState === '23000'
                || str_contains($message, 'duplicate')
                || str_contains($message, 'uk_config_rute_primar_beneficiar_loc_zona')
            ) {
                $errors = ['zona_id' => 'Exista deja o configuratie Primar pentru aceasta combinatie Loc ↔ Zona.'];
                $this->setFormFlash('config_primar_route', $old, $errors);
                flash_set('warning', 'Combinatia Primar selectata exista deja. Editeaza configuratia existenta sau alege alta combinatie.');
                redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
            }

            error_log('[DispecerCurseController][config_store_primar_route] ' . $exception->getMessage());
            $this->setFormFlash('config_primar_route', $old, []);
            flash_set('danger', $routeEditId > 0 ? 'Nu s-a putut actualiza configuratia Primar.' : 'Nu s-a putut salva configuratia Primar.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configDeletePrimaryRouteAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $routeId = (int) ($_POST['id'] ?? 0);
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Beneficiar invalid pentru stergere configuratie Primar.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        if ($routeId <= 0) {
            flash_set('warning', 'Configuratia Primar selectata este invalida.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        try {
            $this->model->deletePrimaryRouteRule($routeId, $beneficiaryId);
            flash_set('success', 'Configuratia Primar a fost stearsa.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_delete_primar_route] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge configuratia Primar.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configDeleteDistributionRouteAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $routeId = (int) ($_POST['id'] ?? 0);
        $routeScope = $this->normalizeDistributionRouteScopeInput(
            (string) ($_POST['route_scope'] ?? ''),
            self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
        );
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Beneficiar invalid pentru stergere configuratie.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        if ($routeId <= 0) {
            flash_set('warning', 'Configuratia de ruta selectata este invalida.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        try {
            $existingRoute = $this->model->getDistributionRouteRuleById($routeId);
            if ($existingRoute === null || (int) ($existingRoute['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                flash_set('warning', 'Configuratia selectata nu exista pentru beneficiarul curent.');
                redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
            }

            $existingScope = $this->normalizeDistributionRouteScopeInput(
                (string) ($existingRoute['transport_scope'] ?? ''),
                self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
            );
            if ($existingScope !== $routeScope) {
                flash_set('warning', 'Configuratia selectata apartine altui panel de tarifare.');
                redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
            }

            $this->model->deleteDistributionRouteRule($routeId);
            flash_set('success', 'Configuratia de ruta a fost stearsa.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_delete_ruta] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge configuratia de ruta.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configStoreCatalogAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Selecteaza un beneficiar valid pentru configurarea catalogului de distributie.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
        if (
            $beneficiary === null
            || (
                empty($beneficiary['suporta_distributie'])
                && empty($beneficiary['suporta_primar'])
                && empty($beneficiary['suporta_primar_distributie'])
            )
        ) {
            flash_set('warning', 'Beneficiarul selectat nu este configurat pentru transport Primar, Distributie sau Primar+Distributie.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $locationId = (int) ($_POST['loc_id'] ?? 0);
        $locationName = trim((string) ($_POST['loc_nume'] ?? ''));
        $locationTariffRaw = trim((string) ($_POST['loc_tarif'] ?? ''));
        $hasLocationVehicleIdsField = isset($_POST['loc_vehicle_ids']) && is_array($_POST['loc_vehicle_ids']);
        $locationVehicleIdsInput = $hasLocationVehicleIdsField
            ? $_POST['loc_vehicle_ids']
            : [];
        $hasLocationActiveField = array_key_exists('loc_activ', $_POST);
        $locationActive = $hasLocationActiveField
            ? ((string) ($_POST['loc_activ'] ?? '') === '1')
            : true;
        $hasLocationTariffField = array_key_exists('loc_tarif', $_POST);

        $zoneId = (int) ($_POST['zona_id'] ?? 0);
        $zoneName = trim((string) ($_POST['zona_nume'] ?? ''));
        $zoneTariffRaw = trim((string) ($_POST['zona_tarif_distributie'] ?? ''));
        $zoneExtraKmCostRaw = trim((string) ($_POST['zona_cost_extra_km'] ?? ''));
        $hasZoneVehicleIdsField = isset($_POST['zona_vehicle_ids']) && is_array($_POST['zona_vehicle_ids']);
        $zoneVehicleIdsInput = $hasZoneVehicleIdsField
            ? $_POST['zona_vehicle_ids']
            : [];
        $hasZoneActiveField = array_key_exists('zona_activ', $_POST);
        $zoneActive = $hasZoneActiveField
            ? ((string) ($_POST['zona_activ'] ?? '') === '1')
            : true;
        $hasZoneTariffField = array_key_exists('zona_tarif_distributie', $_POST);
        $hasZoneExtraKmCostField = array_key_exists('zona_cost_extra_km', $_POST);

        $shouldProcessLocation = $locationId > 0
            || $locationName !== '';
        $shouldProcessZone = $zoneId > 0
            || $zoneName !== '';

        if (!$shouldProcessLocation && !$shouldProcessZone) {
            flash_set('warning', 'Completeaza cel putin un loc incarcare sau o zona descarcare pentru salvare.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $locationErrors = [];
        $zoneErrors = [];
        $locationVehicleIds = [];
        $zoneVehicleIds = [];
        $locationTariff = 0.0;
        $zoneTariff = 0.0;
        $zoneExtraKmCost = 0.0;
        $existingLocation = null;
        $existingZone = null;

        if ($locationId > 0) {
            $existingLocation = $this->model->getLoadLocationById($locationId);
            if ($existingLocation === null || (int) ($existingLocation['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                $locationErrors['id'] = 'Locatia selectata pentru actualizare nu exista.';
            }
        }

        if ($zoneId > 0) {
            $existingZone = $this->model->getDistributionZoneById($zoneId);
            if ($existingZone === null || (int) ($existingZone['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                $zoneErrors['id'] = 'Zona selectata pentru actualizare nu exista.';
            }
        }

        if ($shouldProcessLocation) {
            if ($locationName === '') {
                $locationErrors['nume'] = 'Numele locatiei este obligatoriu.';
            } elseif (mb_strlen($locationName) > 120) {
                $locationErrors['nume'] = 'Numele locatiei este prea lung (maxim 120 caractere).';
            }

            if ($hasLocationTariffField && $locationTariffRaw !== '') {
                $parsedLocationTariff = $this->normalizeDecimal($locationTariffRaw);
                if ($parsedLocationTariff === null || $parsedLocationTariff < 0) {
                    $locationErrors['tarif'] = 'Tariful este invalid.';
                } else {
                    $locationTariff = (float) $parsedLocationTariff;
                }
            } elseif ($existingLocation !== null) {
                $locationTariff = max(0.0, (float) ($existingLocation['tarif'] ?? 0));
            }

            if (!$hasLocationActiveField && $existingLocation !== null) {
                $locationActive = !empty($existingLocation['activ']);
            }

            if ($hasLocationVehicleIdsField) {
                $invalidVehicleSelection = false;
                foreach ($locationVehicleIdsInput as $vehicleIdRaw) {
                    $vehicleIdRaw = trim((string) $vehicleIdRaw);
                    if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                        $invalidVehicleSelection = true;
                        continue;
                    }

                    $vehicleId = (int) $vehicleIdRaw;
                    if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                        $invalidVehicleSelection = true;
                        continue;
                    }

                    $locationVehicleIds[] = $vehicleId;
                }

                $locationVehicleIds = array_values(array_unique($locationVehicleIds));
                if ($invalidVehicleSelection) {
                    $locationErrors['vehicle_ids'] = 'Selecteaza doar vehicule active valide pentru alocarea implicita.';
                }
            }
        }

        if ($shouldProcessZone) {
            if ($zoneName === '') {
                $zoneErrors['nume'] = 'Numele zonei este obligatoriu.';
            } elseif (mb_strlen($zoneName) > 120) {
                $zoneErrors['nume'] = 'Numele zonei este prea lung (maxim 120 caractere).';
            }

            if ($hasZoneTariffField && $zoneTariffRaw !== '') {
                $parsedZoneTariff = $this->normalizeDecimal($zoneTariffRaw);
                if ($parsedZoneTariff === null || $parsedZoneTariff < 0) {
                    $zoneErrors['tarif_distributie'] = 'Tariful de distributie este invalid.';
                } else {
                    $zoneTariff = (float) $parsedZoneTariff;
                }
            } elseif ($existingZone !== null) {
                $zoneTariff = max(0.0, (float) ($existingZone['tarif_distributie'] ?? 0));
            }

            if ($hasZoneExtraKmCostField && $zoneExtraKmCostRaw !== '') {
                $parsedZoneExtraKmCost = $this->normalizeDecimal($zoneExtraKmCostRaw);
                if ($parsedZoneExtraKmCost === null || $parsedZoneExtraKmCost < 0) {
                    $zoneErrors['cost_extra_km'] = 'Costul extra per km este invalid.';
                } else {
                    $zoneExtraKmCost = (float) $parsedZoneExtraKmCost;
                }
            } elseif ($existingZone !== null) {
                $zoneExtraKmCost = max(0.0, (float) ($existingZone['cost_extra_km'] ?? 0));
            }

            if (!$hasZoneActiveField && $existingZone !== null) {
                $zoneActive = !empty($existingZone['activ']);
            }

            if ($hasZoneVehicleIdsField) {
                $invalidVehicleSelection = false;
                foreach ($zoneVehicleIdsInput as $vehicleIdRaw) {
                    $vehicleIdRaw = trim((string) $vehicleIdRaw);
                    if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                        $invalidVehicleSelection = true;
                        continue;
                    }

                    $vehicleId = (int) $vehicleIdRaw;
                    if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                        $invalidVehicleSelection = true;
                        continue;
                    }

                    $zoneVehicleIds[] = $vehicleId;
                }

                $zoneVehicleIds = array_values(array_unique($zoneVehicleIds));
                if ($invalidVehicleSelection) {
                    $zoneErrors['vehicle_ids'] = 'Selecteaza doar vehicule active valide pentru alocarea implicita.';
                }
            }
        }

        if ($locationErrors !== [] || $zoneErrors !== []) {
            $this->setFormFlash('config_loc', [
                'id' => $locationId > 0 ? (string) $locationId : '',
                'beneficiar_id' => (string) $beneficiaryId,
                'nume' => $locationName,
                'tarif' => $locationTariffRaw,
                'vehicle_ids' => array_map('strval', $locationVehicleIds),
                'activ' => $locationActive ? '1' : '0',
            ], $locationErrors);
            $this->setFormFlash('config_zona', [
                'id' => $zoneId > 0 ? (string) $zoneId : '',
                'beneficiar_id' => (string) $beneficiaryId,
                'nume' => $zoneName,
                'tarif_distributie' => $zoneTariffRaw,
                'cost_extra_km' => $zoneExtraKmCostRaw,
                'vehicle_ids' => array_map('strval', $zoneVehicleIds),
                'activ' => $zoneActive ? '1' : '0',
            ], $zoneErrors);

            $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId];
            if ($locationId > 0) {
                $redirectQuery['loc_edit_id'] = $locationId;
            }
            if ($zoneId > 0) {
                $redirectQuery['zona_edit_id'] = $zoneId;
            }
            redirect(build_query_url($redirectQuery));
        }

        try {
            $savedLocation = false;
            $savedZone = false;
            $updatedLocation = false;
            $updatedZone = false;

            if ($shouldProcessLocation) {
                if ($locationId > 0) {
                    $this->model->updateLoadLocation(
                        $locationId,
                        $beneficiaryId,
                        $locationName,
                        $locationTariff,
                        $locationActive
                    );
                    $updatedLocation = true;
                } else {
                    $locationId = $this->model->createLoadLocation(
                        $beneficiaryId,
                        $locationName,
                        $locationTariff,
                        $locationActive
                    );
                    $savedLocation = true;
                }

                if ($locationId > 0 && $hasLocationVehicleIdsField) {
                    $this->model->syncLoadLocationVehicleAssignments($locationId, $locationVehicleIds, $beneficiaryId);
                }
            }

            if ($shouldProcessZone) {
                if ($zoneId > 0) {
                    $this->model->updateDistributionZone(
                        $zoneId,
                        $beneficiaryId,
                        $zoneName,
                        $zoneTariff,
                        $zoneExtraKmCost,
                        $zoneActive
                    );
                    $updatedZone = true;
                } else {
                    $zoneId = $this->model->createDistributionZone(
                        $beneficiaryId,
                        $zoneName,
                        $zoneTariff,
                        $zoneExtraKmCost,
                        $zoneActive
                    );
                    $savedZone = true;
                }

                if ($zoneId > 0 && $hasZoneVehicleIdsField) {
                    $this->model->syncDistributionZoneVehicleAssignments($zoneId, $zoneVehicleIds, $beneficiaryId);
                }
            }

            if ($shouldProcessLocation && $shouldProcessZone) {
                if ($updatedLocation || $updatedZone) {
                    flash_set('success', 'Catalogul de distributie a fost actualizat.');
                } elseif ($savedLocation || $savedZone) {
                    flash_set('success', 'Catalogul de distributie a fost salvat.');
                } else {
                    flash_set('success', 'Catalogul de distributie a fost procesat.');
                }
            } elseif ($shouldProcessLocation) {
                flash_set('success', $updatedLocation
                    ? 'Locul de incarcare a fost actualizat.'
                    : 'Locul de incarcare a fost salvat.');
            } elseif ($shouldProcessZone) {
                flash_set('success', $updatedZone
                    ? 'Zona de distributie a fost actualizata.'
                    : 'Zona de distributie a fost salvata.');
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_store_catalog] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva catalogul de distributie.');

            $this->setFormFlash('config_loc', [
                'id' => $locationId > 0 ? (string) $locationId : '',
                'beneficiar_id' => (string) $beneficiaryId,
                'nume' => $locationName,
                'tarif' => $locationTariffRaw,
                'vehicle_ids' => array_map('strval', $locationVehicleIds),
                'activ' => $locationActive ? '1' : '0',
            ], []);
            $this->setFormFlash('config_zona', [
                'id' => $zoneId > 0 ? (string) $zoneId : '',
                'beneficiar_id' => (string) $beneficiaryId,
                'nume' => $zoneName,
                'tarif_distributie' => $zoneTariffRaw,
                'cost_extra_km' => $zoneExtraKmCostRaw,
                'vehicle_ids' => array_map('strval', $zoneVehicleIds),
                'activ' => $zoneActive ? '1' : '0',
            ], []);
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configStoreLocationAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Selecteaza un beneficiar valid pentru configurarea locului de incarcare.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }
        $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
        if (
            $beneficiary === null
            || (
                empty($beneficiary['suporta_distributie'])
                && empty($beneficiary['suporta_primar'])
                && empty($beneficiary['suporta_primar_distributie'])
            )
        ) {
            flash_set('warning', 'Beneficiarul selectat nu este configurat pentru transport Primar, Distributie sau Primar+Distributie.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $locationId = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['nume'] ?? ''));
        $tariffRaw = trim((string) ($_POST['tarif'] ?? ''));
        $vehicleIdsInput = isset($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids'])
            ? $_POST['vehicle_ids']
            : [];
        $active = isset($_POST['activ']) && (string) $_POST['activ'] === '1';
        $errors = [];
        $vehicleIds = [];
        $invalidVehicleSelection = false;

        if ($name === '') {
            $errors['nume'] = 'Numele locatiei este obligatoriu.';
        } elseif (mb_strlen($name) > 120) {
            $errors['nume'] = 'Numele locatiei este prea lung (maxim 120 caractere).';
        }

        $tariff = $this->normalizeDecimal($tariffRaw);
        if ($tariff === null || $tariff < 0) {
            $errors['tarif'] = 'Tariful este invalid.';
        }

        foreach ($vehicleIdsInput as $vehicleIdRaw) {
            $vehicleIdRaw = trim((string) $vehicleIdRaw);
            if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $vehicleIds[] = $vehicleId;
        }

        $vehicleIds = array_values(array_unique($vehicleIds));
        if ($invalidVehicleSelection) {
            $errors['vehicle_ids'] = 'Selecteaza doar vehicule active valide pentru alocarea implicita.';
        }

        if ($locationId > 0 && !$this->model->existsLoadLocationForBeneficiary($locationId, $beneficiaryId)) {
            $errors['id'] = 'Locatia selectata pentru actualizare nu exista.';
        }

        if ($errors !== []) {
            $this->setFormFlash('config_loc', [
                'id' => $locationId > 0 ? (string) $locationId : '',
                'beneficiar_id' => (string) $beneficiaryId,
                'nume' => $name,
                'tarif' => $tariffRaw,
                'vehicle_ids' => array_map('strval', $vehicleIds),
                'activ' => $active ? '1' : '0',
            ], $errors);
            $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId];
            if ($locationId > 0) {
                $redirectQuery['loc_edit_id'] = $locationId;
            }
            redirect(build_query_url($redirectQuery));
        }

        try {
            $persistedLocationId = $locationId;
            if ($locationId > 0) {
                $this->model->updateLoadLocation($locationId, $beneficiaryId, $name, (float) $tariff, $active);
                flash_set('success', 'Locul de incarcare a fost actualizat.');
            } else {
                $persistedLocationId = $this->model->createLoadLocation($beneficiaryId, $name, (float) $tariff, $active);
                flash_set('success', 'Locul de incarcare a fost salvat.');
            }

            if ($persistedLocationId > 0) {
                $this->model->syncLoadLocationVehicleAssignments($persistedLocationId, $vehicleIds, $beneficiaryId);
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_store_loc] ' . $exception->getMessage());
            flash_set('danger', $locationId > 0
                ? 'Nu s-a putut actualiza locul de incarcare.'
                : 'Nu s-a putut salva locul de incarcare.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configDeleteLocationAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Loc de incarcare invalid.');
            $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config'];
            if ($beneficiaryId > 0) {
                $redirectQuery['beneficiar_edit_id'] = $beneficiaryId;
            }
            redirect(build_query_url($redirectQuery));
        }
        if ($beneficiaryId > 0 && !$this->model->existsLoadLocationForBeneficiary($id, $beneficiaryId)) {
            flash_set('warning', 'Locul de incarcare selectat nu apartine beneficiarului curent.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        try {
            $this->model->deleteLoadLocation($id);
            flash_set('success', 'Locul de incarcare a fost sters.');
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_delete_loc] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge locul de incarcare. Verifica daca este folosit in curse.');
        }

        $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config'];
        if ($beneficiaryId > 0) {
            $redirectQuery['beneficiar_edit_id'] = $beneficiaryId;
        }
        redirect(build_query_url($redirectQuery));
    }

    private function configStoreZoneAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0 || !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            flash_set('warning', 'Selecteaza un beneficiar valid pentru configurarea zonei.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }
        $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
        if (
            $beneficiary === null
            || (
                empty($beneficiary['suporta_distributie'])
                && empty($beneficiary['suporta_primar'])
                && empty($beneficiary['suporta_primar_distributie'])
            )
        ) {
            flash_set('warning', 'Beneficiarul selectat nu este configurat pentru transport Primar, Distributie sau Primar+Distributie.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        $zoneId = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['nume'] ?? ''));
        $tariffRaw = trim((string) ($_POST['tarif_distributie'] ?? ''));
        $extraKmCostRaw = trim((string) ($_POST['cost_extra_km'] ?? ''));
        $vehicleIdsInput = isset($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids'])
            ? $_POST['vehicle_ids']
            : [];
        $active = isset($_POST['activ']) && (string) $_POST['activ'] === '1';

        $errors = [];
        $vehicleIds = [];
        $invalidVehicleSelection = false;
        if ($name === '') {
            $errors['nume'] = 'Numele zonei este obligatoriu.';
        } elseif (mb_strlen($name) > 120) {
            $errors['nume'] = 'Numele zonei este prea lung (maxim 120 caractere).';
        }

        $tariff = $this->normalizeDecimal($tariffRaw);
        if ($tariff === null || $tariff < 0) {
            $errors['tarif_distributie'] = 'Tariful de distributie este invalid.';
        }

        $extraKmCost = $extraKmCostRaw === '' ? 0.0 : $this->normalizeDecimal($extraKmCostRaw);
        if ($extraKmCost === null || $extraKmCost < 0) {
            $errors['cost_extra_km'] = 'Costul extra per km este invalid.';
        }

        foreach ($vehicleIdsInput as $vehicleIdRaw) {
            $vehicleIdRaw = trim((string) $vehicleIdRaw);
            if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                $invalidVehicleSelection = true;
                continue;
            }

            $vehicleIds[] = $vehicleId;
        }

        $vehicleIds = array_values(array_unique($vehicleIds));
        if ($invalidVehicleSelection) {
            $errors['vehicle_ids'] = 'Selecteaza doar vehicule active valide pentru alocarea implicita.';
        }

        if ($zoneId > 0 && !$this->model->existsDistributionZoneForBeneficiary($zoneId, $beneficiaryId)) {
            $errors['id'] = 'Zona selectata pentru actualizare nu exista.';
        }

        if ($errors !== []) {
            $this->setFormFlash('config_zona', [
                'id' => $zoneId > 0 ? (string) $zoneId : '',
                'beneficiar_id' => (string) $beneficiaryId,
                'nume' => $name,
                'tarif_distributie' => $tariffRaw,
                'cost_extra_km' => $extraKmCostRaw,
                'vehicle_ids' => array_map('strval', $vehicleIds),
                'activ' => $active ? '1' : '0',
            ], $errors);
            $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId];
            if ($zoneId > 0) {
                $redirectQuery['zona_edit_id'] = $zoneId;
            }
            redirect(build_query_url($redirectQuery));
        }

        try {
            $persistedZoneId = $zoneId;
            if ($zoneId > 0) {
                $this->model->updateDistributionZone($zoneId, $beneficiaryId, $name, (float) $tariff, (float) $extraKmCost, $active);
                flash_set('success', 'Zona de distributie a fost actualizata.');
            } else {
                $persistedZoneId = $this->model->createDistributionZone($beneficiaryId, $name, (float) $tariff, (float) $extraKmCost, $active);
                flash_set('success', 'Zona de distributie a fost salvata.');
            }

            if ($persistedZoneId > 0) {
                $this->model->syncDistributionZoneVehicleAssignments($persistedZoneId, $vehicleIds, $beneficiaryId);
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_store_zona] ' . $exception->getMessage());
            flash_set('danger', $zoneId > 0
                ? 'Nu s-a putut actualiza zona de distributie.'
                : 'Nu s-a putut salva zona de distributie.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
    }

    private function configDeleteZoneAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Zona invalida.');
            $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config'];
            if ($beneficiaryId > 0) {
                $redirectQuery['beneficiar_edit_id'] = $beneficiaryId;
            }
            redirect(build_query_url($redirectQuery));
        }
        if ($beneficiaryId > 0 && !$this->model->existsDistributionZoneForBeneficiary($id, $beneficiaryId)) {
            flash_set('warning', 'Zona selectata nu apartine beneficiarului curent.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]));
        }

        try {
            $this->model->deleteDistributionZone($id);
            flash_set('success', 'Zona de distributie a fost stearsa.');
        } catch (PDOException $exception) {
            error_log('[DispecerCurseController][config_delete_zona] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge zona de distributie. Verifica logurile aplicatiei.');
        }

        $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config'];
        if ($beneficiaryId > 0) {
            $redirectQuery['beneficiar_edit_id'] = $beneficiaryId;
        }
        redirect(build_query_url($redirectQuery));
    }

    private function configStoreBeneficiaryAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $beneficiaryId = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['nume'] ?? ''));
        $baseRateRaw = trim((string) ($_POST['pret_tarifare'] ?? ''));
        $pricePerKmRaw = trim((string) ($_POST['pret_km'] ?? ''));
        $pricePerTonRaw = trim((string) ($_POST['pret_tona'] ?? ''));
        $distributionPricePerKmRaw = trim((string) ($_POST['pret_distributie_km'] ?? ''));
        $distributionPricePerTonRaw = trim((string) ($_POST['pret_distributie_tona'] ?? ''));
        $pricePerHourSuctionRaw = trim((string) ($_POST['pret_ora_aspirare'] ?? ''));
        $pricePerKmRelocationRaw = trim((string) ($_POST['pret_km_dislocare'] ?? ''));
        $pricePerDeliveredTonRaw = trim((string) ($_POST['pret_tona_livrata'] ?? ''));
        $pricePerSuctionLiquidTonRaw = trim((string) ($_POST['pret_tona_aspirata_lichida'] ?? ''));
        $pricePerSuctionGasTonRaw = trim((string) ($_POST['pret_tona_aspirata_gazoasa'] ?? ''));
        $compressorVehicleIdsInput = isset($_POST['compresor_vehicle_ids']) && is_array($_POST['compresor_vehicle_ids'])
            ? $_POST['compresor_vehicle_ids']
            : [];
        $selectedTransportTypesRaw = isset($_POST['tip_transporturi']) && is_array($_POST['tip_transporturi'])
            ? $_POST['tip_transporturi']
            : [];
        $selectedTransportTypes = [];
        foreach (['primar', 'distributie', 'primar_distributie', 'compresor'] as $allowedTransportType) {
            if (in_array($allowedTransportType, $selectedTransportTypesRaw, true)) {
                $selectedTransportTypes[] = $allowedTransportType;
            }
        }

        $supportsPrimary = in_array('primar', $selectedTransportTypes, true);
        $supportsDistribution = in_array('distributie', $selectedTransportTypes, true);
        $supportsPrimaryDistribution = in_array('primar_distributie', $selectedTransportTypes, true);
        $supportsCompressor = in_array('compresor', $selectedTransportTypes, true);
        $active = isset($_POST['activ']) && (string) $_POST['activ'] === '1';
        $errors = [];

        if ($name === '') {
            $errors['nume'] = 'Numele beneficiarului este obligatoriu.';
        } elseif (mb_strlen($name) > 150) {
            $errors['nume'] = 'Numele beneficiarului este prea lung (maxim 150 caractere).';
        }

        if ($beneficiaryId > 0 && !$this->model->existsTransportBeneficiary($beneficiaryId)) {
            $errors['id'] = 'Beneficiarul selectat pentru actualizare nu exista.';
        }

        if (!$supportsPrimary && !$supportsDistribution && !$supportsPrimaryDistribution && !$supportsCompressor) {
            $errors['tip_transport'] = 'Selecteaza cel putin un tip de transport.';
        }

        $baseRate = $baseRateRaw === '' ? 0.0 : $this->normalizeDecimal($baseRateRaw);
        if ($baseRate === null || $baseRate < 0) {
            $errors['pret_tarifare'] = 'Pretul de cursa este invalid.';
        }

        $pricePerKm = $pricePerKmRaw === '' ? 0.0 : $this->normalizeDecimal($pricePerKmRaw);
        if ($pricePerKm === null || $pricePerKm < 0) {
            $errors['pret_km'] = 'Pretul per km este invalid.';
        }

        $pricePerTon = $pricePerTonRaw === '' ? 0.0 : $this->normalizeDecimal($pricePerTonRaw);
        if ($pricePerTon === null || $pricePerTon < 0) {
            $errors['pret_tona'] = 'Pretul per tona este invalid.';
        }

        $distributionPricePerKm = $distributionPricePerKmRaw === '' ? 0.0 : $this->normalizeDecimal($distributionPricePerKmRaw);
        if ($distributionPricePerKm === null || $distributionPricePerKm < 0) {
            $errors['pret_distributie_km'] = 'Pretul/km pentru distributie este invalid.';
        }

        $distributionPricePerTon = $distributionPricePerTonRaw === '' ? 0.0 : $this->normalizeDecimal($distributionPricePerTonRaw);
        if ($distributionPricePerTon === null || $distributionPricePerTon < 0) {
            $errors['pret_distributie_tona'] = 'Pretul/tona pentru distributie este invalid.';
        }

        $pricePerHourSuction = 0.0;
        if ($pricePerHourSuctionRaw !== '') {
            $normalizedPricePerHourSuction = $this->normalizeDecimal($pricePerHourSuctionRaw);
            if ($normalizedPricePerHourSuction === null || $normalizedPricePerHourSuction < 0) {
                $errors['pret_ora_aspirare'] = 'Pretul per ora aspirare este invalid.';
            } else {
                $pricePerHourSuction = (float) $normalizedPricePerHourSuction;
            }
        }

        $pricePerKmRelocation = 0.0;
        if ($pricePerKmRelocationRaw !== '') {
            $normalizedPricePerKmRelocation = $this->normalizeDecimal($pricePerKmRelocationRaw);
            if ($normalizedPricePerKmRelocation === null || $normalizedPricePerKmRelocation < 0) {
                $errors['pret_km_dislocare'] = 'Pretul per km dislocare este invalid.';
            } else {
                $pricePerKmRelocation = (float) $normalizedPricePerKmRelocation;
            }
        }

        $pricePerDeliveredTon = 0.0;
        if ($pricePerDeliveredTonRaw !== '') {
            $normalizedPricePerDeliveredTon = $this->normalizeDecimal($pricePerDeliveredTonRaw);
            if ($normalizedPricePerDeliveredTon === null || $normalizedPricePerDeliveredTon < 0) {
                $errors['pret_tona_livrata'] = 'Pretul per tona livrata este invalid.';
            } else {
                $pricePerDeliveredTon = (float) $normalizedPricePerDeliveredTon;
            }
        }

        $pricePerSuctionLiquidTon = 0.0;
        if ($pricePerSuctionLiquidTonRaw !== '') {
            $normalizedPricePerSuctionLiquidTon = $this->normalizeDecimal($pricePerSuctionLiquidTonRaw);
            if ($normalizedPricePerSuctionLiquidTon === null || $normalizedPricePerSuctionLiquidTon < 0) {
                $errors['pret_tona_aspirata_lichida'] = 'Pretul per tona aspirata lichida este invalid.';
            } else {
                $pricePerSuctionLiquidTon = (float) $normalizedPricePerSuctionLiquidTon;
            }
        }

        $pricePerSuctionGasTon = 0.0;
        if ($pricePerSuctionGasTonRaw !== '') {
            $normalizedPricePerSuctionGasTon = $this->normalizeDecimal($pricePerSuctionGasTonRaw);
            if ($normalizedPricePerSuctionGasTon === null || $normalizedPricePerSuctionGasTon < 0) {
                $errors['pret_tona_aspirata_gazoasa'] = 'Pretul per tona aspirata gazoasa este invalid.';
            } else {
                $pricePerSuctionGasTon = (float) $normalizedPricePerSuctionGasTon;
            }
        }

        $compressorVehicleIds = [];
        $invalidCompressorVehicleSelection = false;
        foreach ($compressorVehicleIdsInput as $vehicleIdRaw) {
            $vehicleIdRaw = trim((string) $vehicleIdRaw);
            if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                $invalidCompressorVehicleSelection = true;
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId <= 0 || !$this->model->existsActiveVehicle($vehicleId)) {
                $invalidCompressorVehicleSelection = true;
                continue;
            }

            $compressorVehicleIds[] = $vehicleId;
        }
        $compressorVehicleIds = array_values(array_unique($compressorVehicleIds));
        if ($invalidCompressorVehicleSelection) {
            $errors['compresor_vehicle_ids'] = 'Selecteaza doar vehicule active valide pentru Compresor.';
        }

        if ($errors !== []) {
            $this->setFormFlash('config_beneficiar', [
                'id' => $beneficiaryId > 0 ? (string) $beneficiaryId : '',
                'nume' => $name,
                'pret_tarifare' => $baseRateRaw,
                'pret_km' => $pricePerKmRaw,
                'pret_tona' => $pricePerTonRaw,
                'pret_distributie_km' => $distributionPricePerKmRaw,
                'pret_distributie_tona' => $distributionPricePerTonRaw,
                'pret_ora_aspirare' => $pricePerHourSuctionRaw,
                'pret_km_dislocare' => $pricePerKmRelocationRaw,
                'pret_tona_livrata' => $pricePerDeliveredTonRaw,
                'pret_tona_aspirata_lichida' => $pricePerSuctionLiquidTonRaw,
                'pret_tona_aspirata_gazoasa' => $pricePerSuctionGasTonRaw,
                'compresor_vehicle_ids' => array_map('strval', $compressorVehicleIds),
                'tip_transporturi' => $selectedTransportTypes,
                'suporta_primar' => $supportsPrimary ? '1' : '0',
                'suporta_distributie' => $supportsDistribution ? '1' : '0',
                'suporta_primar_distributie' => $supportsPrimaryDistribution ? '1' : '0',
                'suporta_compresor' => $supportsCompressor ? '1' : '0',
                'activ' => $active ? '1' : '0',
            ], $errors);
            $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config'];
            if ($beneficiaryId > 0) {
                $redirectQuery['beneficiar_edit_id'] = $beneficiaryId;
            }
            redirect(build_query_url($redirectQuery));
        }

        try {
            $persistedBeneficiaryId = $beneficiaryId;
            if ($beneficiaryId > 0) {
                $this->model->updateTransportBeneficiary(
                    $beneficiaryId,
                    $name,
                    '',
                    (float) $baseRate,
                    $supportsPrimary,
                    $supportsDistribution,
                    $supportsPrimaryDistribution,
                    $supportsCompressor,
                    (float) $pricePerKm,
                    (float) $pricePerTon,
                    (float) $distributionPricePerKm,
                    (float) $distributionPricePerTon,
                    (float) $pricePerHourSuction,
                    (float) $pricePerKmRelocation,
                    (float) $pricePerDeliveredTon,
                    (float) $pricePerSuctionLiquidTon,
                    (float) $pricePerSuctionGasTon,
                    $active
                );
                flash_set('success', 'Beneficiarul de transport a fost actualizat.');
            } else {
                $persistedBeneficiaryId = $this->model->createTransportBeneficiary(
                    $name,
                    '',
                    (float) $baseRate,
                    $supportsPrimary,
                    $supportsDistribution,
                    $supportsPrimaryDistribution,
                    $supportsCompressor,
                    (float) $pricePerKm,
                    (float) $pricePerTon,
                    (float) $distributionPricePerKm,
                    (float) $distributionPricePerTon,
                    (float) $pricePerHourSuction,
                    (float) $pricePerKmRelocation,
                    (float) $pricePerDeliveredTon,
                    (float) $pricePerSuctionLiquidTon,
                    (float) $pricePerSuctionGasTon,
                    $active
                );
                flash_set('success', 'Beneficiarul de transport a fost salvat.');
            }
            $beneficiaryId = $persistedBeneficiaryId;

            if ($beneficiaryId > 0) {
                $this->model->syncCompressorVehicleAssignmentsForBeneficiary(
                    $beneficiaryId,
                    $supportsCompressor ? $compressorVehicleIds : []
                );
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][config_store_beneficiar] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva beneficiarul de transport.');
        }

        $redirectQuery = ['page' => 'dispecer_curse', 'action' => 'config'];
        if ($beneficiaryId > 0) {
            $redirectQuery['beneficiar_edit_id'] = $beneficiaryId;
        }
        redirect(build_query_url($redirectQuery));
    }

    private function configDeleteBeneficiaryAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Beneficiar invalid.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        try {
            $this->model->deleteTransportBeneficiary($id);
            flash_set('success', 'Beneficiarul de transport a fost sters.');
        } catch (PDOException $exception) {
            error_log('[DispecerCurseController][config_delete_beneficiar] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge beneficiarul. Verifica daca este folosit in curse.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
    }

    private function configDeleteBeneficiariesAction(): void
    {
        require_admin_or_403();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));

        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            $rawId = trim((string) $rawId);
            if ($rawId === '' || !ctype_digit($rawId)) {
                continue;
            }

            $id = (int) $rawId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            flash_set('warning', 'Selecteaza cel putin un beneficiar pentru stergere.');
            redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
        }

        $deletedCount = 0;
        $missingCount = 0;
        $failedIds = [];

        foreach ($ids as $id) {
            if (!$this->model->existsTransportBeneficiary($id)) {
                $missingCount++;
                continue;
            }

            try {
                $this->model->deleteTransportBeneficiary($id);
                $deletedCount++;
            } catch (PDOException $exception) {
                error_log('[DispecerCurseController][config_delete_beneficiari][' . $id . '] ' . $exception->getMessage());
                $failedIds[] = $id;
            }
        }

        if ($deletedCount > 0) {
            $message = $deletedCount === 1
                ? '1 beneficiar de transport a fost sters.'
                : $deletedCount . ' beneficiari de transport au fost stersi.';
            flash_set('success', $message);
        }

        if ($failedIds !== []) {
            $failedPreview = implode(', ', array_slice(array_map('strval', $failedIds), 0, 5));
            $extraSuffix = count($failedIds) > 5 ? '...' : '';
            flash_set('danger', 'Unele reguli nu au putut fi sterse (ID: ' . $failedPreview . $extraSuffix . '). Verifica daca sunt folosite in curse.');
        }

        if ($missingCount > 0) {
            $message = $missingCount === 1
                ? '1 regula selectata nu mai exista.'
                : $missingCount . ' reguli selectate nu mai exista.';
            flash_set('warning', $message);
        }

        if ($deletedCount === 0 && $failedIds === [] && $missingCount === 0) {
            flash_set('warning', 'Nu s-a putut procesa stergerea selectiei.');
        }

        redirect(build_query_url(['page' => 'dispecer_curse', 'action' => 'config']));
    }

    private function collectFilters(): array
    {
        return [
            'tip_transport' => trim((string) ($_GET['tip_transport'] ?? '')),
            'vehicle_id' => trim((string) ($_GET['vehicle_id'] ?? '')),
            'loc_incarcare_id' => trim((string) ($_GET['loc_incarcare_id'] ?? '')),
            'beneficiar_id' => trim((string) ($_GET['beneficiar_id'] ?? '')),
            'zona_distributie_id' => trim((string) ($_GET['zona_distributie_id'] ?? '')),
            'data_start' => trim((string) ($_GET['data_start'] ?? '')),
            'data_end' => trim((string) ($_GET['data_end'] ?? '')),
        ];
    }

    private function collectDeletedRaceFilters(): array
    {
        $transportTypes = $this->deletedRaceTransportTypes();

        $filters = [
            'vehicle_id' => $this->normalizePositiveIntFilter((string) ($_GET['vehicle_id'] ?? '')),
            'tip_transport' => trim((string) ($_GET['tip_transport'] ?? '')),
            'driver_id' => $this->normalizePositiveIntFilter((string) ($_GET['driver_id'] ?? '')),
            'beneficiar_id' => $this->normalizePositiveIntFilter((string) ($_GET['beneficiar_id'] ?? '')),
            'deleted_by' => $this->normalizePositiveIntFilter((string) ($_GET['deleted_by'] ?? '')),
            'data_cursa_start' => trim((string) ($_GET['data_cursa_start'] ?? '')),
            'data_cursa_end' => trim((string) ($_GET['data_cursa_end'] ?? '')),
            'deleted_start' => trim((string) ($_GET['deleted_start'] ?? '')),
            'deleted_end' => trim((string) ($_GET['deleted_end'] ?? '')),
        ];

        if ($filters['tip_transport'] !== '' && !array_key_exists($filters['tip_transport'], $transportTypes)) {
            $filters['tip_transport'] = '';
        }

        foreach (['data_cursa_start', 'data_cursa_end', 'deleted_start', 'deleted_end'] as $dateKey) {
            if ($filters[$dateKey] !== '' && !$this->isValidDate($filters[$dateKey])) {
                $filters[$dateKey] = '';
            }
        }

        if ($filters['data_cursa_start'] !== '' && $filters['data_cursa_end'] !== '' && $filters['data_cursa_end'] < $filters['data_cursa_start']) {
            [$filters['data_cursa_start'], $filters['data_cursa_end']] = [$filters['data_cursa_end'], $filters['data_cursa_start']];
        }

        if ($filters['deleted_start'] !== '' && $filters['deleted_end'] !== '' && $filters['deleted_end'] < $filters['deleted_start']) {
            [$filters['deleted_start'], $filters['deleted_end']] = [$filters['deleted_end'], $filters['deleted_start']];
        }

        return $filters;
    }

    private function collectDeletedRacePerPage(): int
    {
        $perPage = (int) ($_GET['per_page'] ?? 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }

    private function normalizePositiveIntFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return '';
        }

        $number = (int) $value;

        return $number > 0 ? (string) $number : '';
    }

    private function defaultRaceFormData(): array
    {
        return [
            'parent_cursa_id' => '',
            'vehicle_id' => '',
            'driver_id' => '',
            'tip_transport' => '',
            'data_incarcare' => '',
            'data_inceput' => date('Y-m-d'),
            'data_sfarsit' => date('Y-m-d'),
            'ora_inceput' => '',
            'ora_sfarsit' => '',
            'durata_cursa_minute' => '',
            'capacitate_transport' => '',
            'loc_incarcare_id' => '',
            'loc_plecare' => '',
            'loc_aspirare' => '',
            'loc_livrare' => '',
            'loc_livrare_cursa' => '',
            'beneficiar_id' => '',
            'tip_marfa' => [],
            'cantitate_incarcata' => '',
            'cantitate_prelevata' => '',
            'tona_aspirata_lichida' => '',
            'tona_aspirata_gazoasa' => '',
            'nr_clienti' => '',
            'km_cursa' => '',
            'ore_functionare' => '',
            'km_totali' => '',
            'ore_aspirare' => '',
            'km_dislocare' => '',
            'tona_livrata' => '',
            'zona_distributie_id' => '',
            'status_facturare' => self::DEFAULT_BILLING_STATUS,
            'cost_km_primar' => '0',
            'cost_km_distributie' => '0',
            'cost_km_mixt' => '0',
            'cost_km_compresor' => '0',
            'observatii' => '',
        ];
    }

    private function defaultExpenseFormData(): array
    {
        return [
            'expense_id' => '',
            'tip_cheltuiala' => '',
            'categorie_id' => '',
            'refacturare_enabled' => '0',
            'refacturare_tip_cheltuiala' => '',
            'refacturare_suma' => '',
            'refacturare_data' => date('Y-m-d'),
            'refacturare_observatii' => '',
            'suma' => '',
            'data_cheltuiala' => date('Y-m-d'),
            'observatii' => '',
            'taxa_acces_bucati' => '',
            'taxa_acces_pret' => '',
            'port_bucati' => '',
            'port_pret' => '',
            'trece_bucati' => '',
            'trece_pret' => '',
            'refacturare_taxa_acces_bucati' => '',
            'refacturare_taxa_acces_pret' => '',
            'refacturare_port_bucati' => '',
            'refacturare_port_pret' => '',
            'refacturare_trece_bucati' => '',
            'refacturare_trece_pret' => '',
        ];
    }

    private function defaultRefacturareFormData(): array
    {
        $base = $this->defaultExpenseFormData();
        $base['race_id'] = '';
        $base['refacturare_enabled'] = '1';
        $base['tip_cheltuiala'] = '';
        $base['refacturare_tip_cheltuiala'] = '';
        $base['suma'] = '0.00';
        $base['observatii'] = '';

        return $base;
    }

    private function defaultCreateRaceExpenseFormData(): array
    {
        $base = $this->defaultExpenseFormData();
        $defaultItem = [
            'tip_cheltuiala' => (string) ($base['tip_cheltuiala'] ?? ''),
            'categorie_id' => (string) ($base['categorie_id'] ?? ''),
            'refacturare_enabled' => (string) ($base['refacturare_enabled'] ?? '0'),
            'refacturare_tip_cheltuiala' => (string) ($base['refacturare_tip_cheltuiala'] ?? ''),
            'refacturare_suma' => (string) ($base['refacturare_suma'] ?? ''),
            'refacturare_data' => (string) ($base['refacturare_data'] ?? date('Y-m-d')),
            'refacturare_observatii' => (string) ($base['refacturare_observatii'] ?? ''),
            'suma' => (string) ($base['suma'] ?? ''),
            'data_cheltuiala' => (string) ($base['data_cheltuiala'] ?? date('Y-m-d')),
            'observatii' => (string) ($base['observatii'] ?? ''),
            'taxa_acces_bucati' => (string) ($base['taxa_acces_bucati'] ?? ''),
            'taxa_acces_pret' => (string) ($base['taxa_acces_pret'] ?? ''),
            'port_bucati' => (string) ($base['port_bucati'] ?? ''),
            'port_pret' => (string) ($base['port_pret'] ?? ''),
            'trece_bucati' => (string) ($base['trece_bucati'] ?? ''),
            'trece_pret' => (string) ($base['trece_pret'] ?? ''),
            'refacturare_taxa_acces_bucati' => (string) ($base['refacturare_taxa_acces_bucati'] ?? ''),
            'refacturare_taxa_acces_pret' => (string) ($base['refacturare_taxa_acces_pret'] ?? ''),
            'refacturare_port_bucati' => (string) ($base['refacturare_port_bucati'] ?? ''),
            'refacturare_port_pret' => (string) ($base['refacturare_port_pret'] ?? ''),
            'refacturare_trece_bucati' => (string) ($base['refacturare_trece_bucati'] ?? ''),
            'refacturare_trece_pret' => (string) ($base['refacturare_trece_pret'] ?? ''),
        ];

        return [
            'enabled' => '0',
            'items' => [$defaultItem],
        ];
    }

    private function extractCreateRaceExpenseOldValues(array $old): array
    {
        $default = $this->defaultCreateRaceExpenseFormData();
        $values = [
            'enabled' => array_key_exists('create_expense_enabled', $old)
                ? (string) $old['create_expense_enabled']
                : (string) ($default['enabled'] ?? '0'),
            'items' => [],
        ];

        $rawItems = $old['create_expense_items'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $rawItem) {
                if (!is_array($rawItem)) {
                    continue;
                }

                $values['items'][] = [
                    'tip_cheltuiala' => trim((string) ($rawItem['tip_cheltuiala'] ?? '')),
                    'categorie_id' => trim((string) ($rawItem['categorie_id'] ?? '')),
                    'refacturare_enabled' => isset($rawItem['refacturare_enabled']) && (string) $rawItem['refacturare_enabled'] === '1' ? '1' : '0',
                    'refacturare_tip_cheltuiala' => trim((string) ($rawItem['refacturare_tip_cheltuiala'] ?? '')),
                    'refacturare_suma' => trim((string) ($rawItem['refacturare_suma'] ?? '')),
                    'refacturare_data' => trim((string) ($rawItem['refacturare_data'] ?? date('Y-m-d'))),
                    'refacturare_observatii' => trim((string) ($rawItem['refacturare_observatii'] ?? '')),
                    'suma' => trim((string) ($rawItem['suma'] ?? '')),
                    'data_cheltuiala' => trim((string) ($rawItem['data_cheltuiala'] ?? date('Y-m-d'))),
                    'observatii' => trim((string) ($rawItem['observatii'] ?? '')),
                    'taxa_acces_bucati' => trim((string) ($rawItem['taxa_acces_bucati'] ?? '')),
                    'taxa_acces_pret' => trim((string) ($rawItem['taxa_acces_pret'] ?? '')),
                    'port_bucati' => trim((string) ($rawItem['port_bucati'] ?? '')),
                    'port_pret' => trim((string) ($rawItem['port_pret'] ?? '')),
                    'trece_bucati' => trim((string) ($rawItem['trece_bucati'] ?? '')),
                    'trece_pret' => trim((string) ($rawItem['trece_pret'] ?? '')),
                    'refacturare_taxa_acces_bucati' => trim((string) ($rawItem['refacturare_taxa_acces_bucati'] ?? '')),
                    'refacturare_taxa_acces_pret' => trim((string) ($rawItem['refacturare_taxa_acces_pret'] ?? '')),
                    'refacturare_port_bucati' => trim((string) ($rawItem['refacturare_port_bucati'] ?? '')),
                    'refacturare_port_pret' => trim((string) ($rawItem['refacturare_port_pret'] ?? '')),
                    'refacturare_trece_bucati' => trim((string) ($rawItem['refacturare_trece_bucati'] ?? '')),
                    'refacturare_trece_pret' => trim((string) ($rawItem['refacturare_trece_pret'] ?? '')),
                ];
            }
        }

        if ($values['items'] === []) {
            $values['items'] = is_array($default['items'] ?? null) ? $default['items'] : [];
        }

        return $values;
    }

    private function extractCreateRaceExpenseErrors(array $errors): array
    {
        $mapped = [];
        foreach ($errors as $key => $message) {
            $key = (string) $key;
            if (!preg_match('/^create_expense_items\.(\d+)\.([a-z_]+)$/', $key, $matches)) {
                continue;
            }

            $index = (int) ($matches[1] ?? 0);
            $field = (string) ($matches[2] ?? '');
            if ($field === '') {
                continue;
            }

            if (!isset($mapped[$index]) || !is_array($mapped[$index])) {
                $mapped[$index] = [];
            }
            $mapped[$index][$field] = (string) $message;
        }

        ksort($mapped);

        return $mapped;
    }

    private function validateCreateRaceExpenseInput(array $input): array
    {
        $enabled = isset($input['create_expense_enabled']) && (string) $input['create_expense_enabled'] === '1';
        $rawItems = $input['create_expense_items'] ?? [];
        if (!is_array($rawItems)) {
            $rawItems = [];
        }

        $defaultItem = $this->defaultCreateRaceExpenseFormData()['items'][0] ?? [];
        if (!is_array($defaultItem)) {
            $defaultItem = [];
        }

        $validExpenses = [];
        $prefixedErrors = [];
        $oldItems = [];

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $mappedInput = [
                'expense_id' => '',
                'tip_cheltuiala' => trim((string) ($rawItem['tip_cheltuiala'] ?? '')),
                'categorie_id' => trim((string) ($rawItem['categorie_id'] ?? '')),
                'refacturare_enabled' => isset($rawItem['refacturare_enabled']) && (string) $rawItem['refacturare_enabled'] === '1' ? '1' : '0',
                'refacturare_tip_cheltuiala' => trim((string) ($rawItem['refacturare_tip_cheltuiala'] ?? '')),
                'refacturare_suma' => trim((string) ($rawItem['refacturare_suma'] ?? '')),
                'refacturare_data' => trim((string) ($rawItem['refacturare_data'] ?? date('Y-m-d'))),
                'refacturare_observatii' => trim((string) ($rawItem['refacturare_observatii'] ?? '')),
                'suma' => trim((string) ($rawItem['suma'] ?? '')),
                'data_cheltuiala' => trim((string) ($rawItem['data_cheltuiala'] ?? date('Y-m-d'))),
                'observatii' => trim((string) ($rawItem['observatii'] ?? '')),
                'taxa_acces_bucati' => trim((string) ($rawItem['taxa_acces_bucati'] ?? '')),
                'taxa_acces_pret' => trim((string) ($rawItem['taxa_acces_pret'] ?? '')),
                'port_bucati' => trim((string) ($rawItem['port_bucati'] ?? '')),
                'port_pret' => trim((string) ($rawItem['port_pret'] ?? '')),
                'trece_bucati' => trim((string) ($rawItem['trece_bucati'] ?? '')),
                'trece_pret' => trim((string) ($rawItem['trece_pret'] ?? '')),
                'refacturare_taxa_acces_bucati' => trim((string) ($rawItem['refacturare_taxa_acces_bucati'] ?? '')),
                'refacturare_taxa_acces_pret' => trim((string) ($rawItem['refacturare_taxa_acces_pret'] ?? '')),
                'refacturare_port_bucati' => trim((string) ($rawItem['refacturare_port_bucati'] ?? '')),
                'refacturare_port_pret' => trim((string) ($rawItem['refacturare_port_pret'] ?? '')),
                'refacturare_trece_bucati' => trim((string) ($rawItem['refacturare_trece_bucati'] ?? '')),
                'refacturare_trece_pret' => trim((string) ($rawItem['refacturare_trece_pret'] ?? '')),
            ];

            $hasMeaningfulInput = $mappedInput['suma'] !== ''
                || $mappedInput['observatii'] !== ''
                || $mappedInput['taxa_acces_bucati'] !== ''
                || $mappedInput['taxa_acces_pret'] !== ''
                || $mappedInput['port_bucati'] !== ''
                || $mappedInput['port_pret'] !== ''
                || $mappedInput['trece_bucati'] !== ''
                || $mappedInput['trece_pret'] !== ''
                || $mappedInput['refacturare_enabled'] === '1'
                || $mappedInput['refacturare_suma'] !== ''
                || $mappedInput['refacturare_observatii'] !== ''
                || $mappedInput['refacturare_taxa_acces_bucati'] !== ''
                || $mappedInput['refacturare_taxa_acces_pret'] !== ''
                || $mappedInput['refacturare_port_bucati'] !== ''
                || $mappedInput['refacturare_port_pret'] !== ''
                || $mappedInput['refacturare_trece_bucati'] !== ''
                || $mappedInput['refacturare_trece_pret'] !== ''
                || $mappedInput['tip_cheltuiala'] !== ''
                || $mappedInput['categorie_id'] !== '';

            if (!$hasMeaningfulInput) {
                continue;
            }

            [$expenseData, $expenseErrors, $expenseOld] = $this->validateExpenseInput($mappedInput);
            $normalizedOldItem = [
                'tip_cheltuiala' => (string) ($expenseOld['tip_cheltuiala'] ?? $mappedInput['tip_cheltuiala']),
                'categorie_id' => (string) ($expenseOld['categorie_id'] ?? $mappedInput['categorie_id']),
                'refacturare_enabled' => (string) ($expenseOld['refacturare_enabled'] ?? $mappedInput['refacturare_enabled']),
                'refacturare_tip_cheltuiala' => (string) ($expenseOld['refacturare_tip_cheltuiala'] ?? $mappedInput['refacturare_tip_cheltuiala']),
                'suma' => (string) ($expenseOld['suma'] ?? $mappedInput['suma']),
                'data_cheltuiala' => (string) ($expenseOld['data_cheltuiala'] ?? $mappedInput['data_cheltuiala']),
                'observatii' => (string) ($expenseOld['observatii'] ?? $mappedInput['observatii']),
                'refacturare_suma' => (string) ($expenseOld['refacturare_suma'] ?? $mappedInput['refacturare_suma']),
                'refacturare_data' => (string) ($expenseOld['refacturare_data'] ?? $mappedInput['refacturare_data']),
                'refacturare_observatii' => (string) ($expenseOld['refacturare_observatii'] ?? $mappedInput['refacturare_observatii']),
                'taxa_acces_bucati' => (string) ($expenseOld['taxa_acces_bucati'] ?? $mappedInput['taxa_acces_bucati']),
                'taxa_acces_pret' => (string) ($expenseOld['taxa_acces_pret'] ?? $mappedInput['taxa_acces_pret']),
                'port_bucati' => (string) ($expenseOld['port_bucati'] ?? $mappedInput['port_bucati']),
                'port_pret' => (string) ($expenseOld['port_pret'] ?? $mappedInput['port_pret']),
                'trece_bucati' => (string) ($expenseOld['trece_bucati'] ?? $mappedInput['trece_bucati']),
                'trece_pret' => (string) ($expenseOld['trece_pret'] ?? $mappedInput['trece_pret']),
                'refacturare_taxa_acces_bucati' => (string) ($expenseOld['refacturare_taxa_acces_bucati'] ?? $mappedInput['refacturare_taxa_acces_bucati']),
                'refacturare_taxa_acces_pret' => (string) ($expenseOld['refacturare_taxa_acces_pret'] ?? $mappedInput['refacturare_taxa_acces_pret']),
                'refacturare_port_bucati' => (string) ($expenseOld['refacturare_port_bucati'] ?? $mappedInput['refacturare_port_bucati']),
                'refacturare_port_pret' => (string) ($expenseOld['refacturare_port_pret'] ?? $mappedInput['refacturare_port_pret']),
                'refacturare_trece_bucati' => (string) ($expenseOld['refacturare_trece_bucati'] ?? $mappedInput['refacturare_trece_bucati']),
                'refacturare_trece_pret' => (string) ($expenseOld['refacturare_trece_pret'] ?? $mappedInput['refacturare_trece_pret']),
            ];

            $itemIndex = count($oldItems);
            $oldItems[] = $normalizedOldItem;

            foreach ($expenseErrors as $field => $message) {
                $prefixedErrors['create_expense_items.' . $itemIndex . '.' . $field] = (string) $message;
            }

            if ($expenseErrors === [] && is_array($expenseData) && $expenseData !== []) {
                $validExpenses[] = $expenseData;
            }
        }

        if ($oldItems === []) {
            $oldItems[] = array_merge([
                'tip_cheltuiala' => '',
                'refacturare_enabled' => '0',
                'refacturare_tip_cheltuiala' => '',
                'refacturare_suma' => '',
                'refacturare_data' => date('Y-m-d'),
                'refacturare_observatii' => '',
                'suma' => '',
                'data_cheltuiala' => date('Y-m-d'),
                'observatii' => '',
                'taxa_acces_bucati' => '',
                'taxa_acces_pret' => '',
                'port_bucati' => '',
                'port_pret' => '',
                'trece_bucati' => '',
                'trece_pret' => '',
                'refacturare_taxa_acces_bucati' => '',
                'refacturare_taxa_acces_pret' => '',
                'refacturare_port_bucati' => '',
                'refacturare_port_pret' => '',
                'refacturare_trece_bucati' => '',
                'refacturare_trece_pret' => '',
            ], $defaultItem);
        }

        $old = [
            'create_expense_enabled' => $enabled ? '1' : '0',
            'create_expense_items' => $oldItems,
        ];

        if (!$enabled) {
            return [[], [], $old];
        }

        if ($prefixedErrors !== []) {
            return [[], $prefixedErrors, $old];
        }

        return [$validExpenses, [], $old];
    }

    private function validateRaceInput(
        array $input,
        bool $requireActiveVehicle = false,
        bool $allowInactiveBeneficiary = false
    ): array
    {
        $errors = [];
        // Campuri necompletate: nu blocheaza salvarea, dar cer confirmare explicita
        // (popup "Salvezi fara aceste informatii?"); raman vizibile in meniul
        // "curse cu informatii lipsa" pana sunt completate.
        $softErrors = [];

        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        if ($vehicleId <= 0) {
            $errors['vehicle_id'] = 'Selecteaza un vehicul valid.';
        } elseif ($requireActiveVehicle && !$this->model->existsActiveVehicle($vehicleId)) {
            $errors['vehicle_id'] = 'Selecteaza un vehicul activ valid.';
        } elseif (!$this->model->existsVehicle($vehicleId)) {
            $errors['vehicle_id'] = 'Selecteaza un vehicul valid.';
        }
        $vehicleTransportCapacity = $vehicleId > 0
            ? $this->model->getVehicleTransportCapacity($vehicleId)
            : null;

        $transportType = trim((string) ($input['tip_transport'] ?? ''));
        if (!array_key_exists($transportType, self::TRANSPORT_TYPES)) {
            $errors['tip_transport'] = 'Tipul de transport este invalid.';
        }
        $isPrimaryKmTransport = $this->isPrimaryKmTransportType($transportType);
        $isPrimaryTonTransport = $this->isPrimaryTonTransportType($transportType);
        $isPrimaryTransport = $isPrimaryKmTransport || $isPrimaryTonTransport;
        $isDistributionTransport = $this->isDistributionTransportType($transportType);
        $isDistributionWithKmTransport = $this->isDistributionWithKmTransportType($transportType);
        $isCompressorTransport = $transportType === 'compresor';

        $loadingDateRaw = trim((string) ($input['data_incarcare'] ?? ''));
        $loadingDateNormalized = $loadingDateRaw !== '' ? $this->normalizeRaceDate($loadingDateRaw) : '';
        $loadingDate = $loadingDateNormalized ?? $loadingDateRaw;
        if ($loadingDateRaw !== '' && $loadingDateNormalized === null) {
            $errors['data_incarcare'] = 'Data de incarcare este invalida.';
        }

        $startDateRaw = trim((string) ($input['data_inceput'] ?? ($input['data_cursa'] ?? '')));
        $startDateNormalized = $this->normalizeRaceDate($startDateRaw);
        $startDate = $startDateNormalized ?? $startDateRaw;
        if ($startDateNormalized === null) {
            $errors['data_inceput'] = 'Data de inceput este invalida.';
        }

        $endDateRaw = trim((string) ($input['data_sfarsit'] ?? $startDateRaw));
        $endDateNormalized = $this->normalizeRaceDate($endDateRaw);
        $endDate = $endDateNormalized ?? $endDateRaw;
        if ($endDateNormalized === null) {
            $errors['data_sfarsit'] = 'Data de sfarsit este invalida.';
        }

        if ($startDateNormalized !== null && $endDateNormalized !== null && $endDate < $startDate) {
            $errors['data_sfarsit'] = 'Data de sfarsit trebuie sa fie dupa sau egala cu data de inceput.';
        }

        $startTimeRaw = trim((string) ($input['ora_inceput'] ?? ''));
        $endTimeRaw = trim((string) ($input['ora_sfarsit'] ?? ''));
        $startTime = $startTimeRaw === '' ? null : $this->normalizeTime($startTimeRaw);
        $endTime = $endTimeRaw === '' ? null : $this->normalizeTime($endTimeRaw);
        $durationMinutes = null;

        if ($startTimeRaw !== '' && $startTime === null) {
            $errors['ora_inceput'] = 'Ora de inceput este invalida.';
        }
        if ($endTimeRaw !== '' && $endTime === null) {
            $errors['ora_sfarsit'] = 'Ora de sfarsit este invalida.';
        }

        // Operatorul poate salva cursa fara ora de sfarsit (cursa in desfasurare).
        // Daca ora de sfarsit este completata, ora de inceput devine obligatorie.
        if ($endTimeRaw !== '' && $startTimeRaw === '') {
            $errors['ora_inceput'] = 'Completeaza ora de inceput daca setezi ora de sfarsit.';
        }

        if (
            $startTime !== null
            && $endTime !== null
            && $this->isValidDate($startDate)
            && $this->isValidDate($endDate)
            && !isset($errors['ora_inceput'])
            && !isset($errors['ora_sfarsit'])
        ) {
            $durationMinutes = $this->computeRaceDurationMinutes($startDate, $startTime, $endDate, $endTime);
            if ($durationMinutes === null) {
                $errors['ora_sfarsit'] = 'Ora de sfarsit trebuie sa fie dupa ora de inceput.';
            }
        }

        $departureLocationRaw = trim((string) ($input['loc_plecare'] ?? ''));
        $suctionLocationRaw = trim((string) ($input['loc_aspirare'] ?? ''));
        $deliveryLocationRaw = trim((string) ($input['loc_livrare'] ?? ''));
        $routeDeliveryLocationRaw = trim((string) ($input['loc_livrare_cursa'] ?? ''));

        if ($isCompressorTransport) {
            if ($departureLocationRaw === '') {
                $errors['loc_plecare'] = 'Completeaza Loc plecare.';
            }
            if ($suctionLocationRaw === '') {
                $errors['loc_aspirare'] = 'Completeaza Loc aspirare.';
            }
            if ($deliveryLocationRaw === '') {
                $errors['loc_livrare'] = 'Completeaza Loc livrare.';
            }
            if ($routeDeliveryLocationRaw === '') {
                $errors['loc_livrare_cursa'] = 'Completeaza Loc inchidere cursa.';
            }
        }

        foreach ([
            'loc_plecare' => $departureLocationRaw,
            'loc_aspirare' => $suctionLocationRaw,
            'loc_livrare' => $deliveryLocationRaw,
            'loc_livrare_cursa' => $routeDeliveryLocationRaw,
        ] as $fieldKey => $fieldValue) {
            if ($fieldValue !== '' && mb_strlen($fieldValue) > 255) {
                $errors[$fieldKey] = 'Campul este prea lung (maxim 255 caractere).';
            }
        }

        $loadLocationIdRaw = trim((string) ($input['loc_incarcare_id'] ?? ''));
        $loadLocationId = $loadLocationIdRaw === '' ? null : (int) $loadLocationIdRaw;
        $loadLocation = null;
        $loadLocationTariff = 0.0;
        if ($isCompressorTransport) {
            $loadLocationIdRaw = '';
            $loadLocationId = null;
        } elseif ($loadLocationId === null || $loadLocationId <= 0) {
            $loadLocationId = null;
            $softErrors['loc_incarcare_id'] = 'Locul de incarcare nu este selectat.';
        } elseif (!$this->model->existsLoadLocation($loadLocationId)) {
            $errors['loc_incarcare_id'] = 'Selecteaza un loc de incarcare valid.';
        } else {
            $loadLocation = $this->model->getLoadLocationById($loadLocationId);
            if ($loadLocation === null) {
                $errors['loc_incarcare_id'] = 'Locul de incarcare selectat nu exista.';
            } else {
                $loadLocationTariff = max(0, (float) ($loadLocation['tarif'] ?? 0));
            }
        }

        $beneficiaryIdRaw = trim((string) ($input['beneficiar_id'] ?? ''));
        $beneficiaryId = $beneficiaryIdRaw === '' ? null : (int) $beneficiaryIdRaw;
        $beneficiary = null;
        if ($beneficiaryId === null || $beneficiaryId <= 0) {
            $beneficiaryId = null;
            $softErrors['beneficiar_id'] = 'Beneficiarul de transport nu este selectat.';
        } else {
            $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
            if ($beneficiary === null) {
                $errors['beneficiar_id'] = 'Beneficiarul selectat nu este disponibil.';
            } elseif (empty($beneficiary['activ']) && !$allowInactiveBeneficiary) {
                $errors['beneficiar_id'] = 'Beneficiarul selectat nu este disponibil.';
            }
        }

        if (
            $beneficiaryId !== null
            && $beneficiaryId > 0
            && $vehicleId > 0
            && !isset($errors['beneficiar_id'])
            && !isset($errors['vehicle_id'])
            && !$this->isVehicleAllowedForBeneficiaryAndTransport($beneficiaryId, $vehicleId, $transportType, $beneficiary)
            // Un vehicul neconfigurat pe ruta este acceptat daca adminul a luat o decizie
            // explicita in formular (doar aceasta cursa / adaugare permanenta pe ruta).
            && !in_array(trim((string) ($input['vehicle_config_decision'] ?? '')), ['trip', 'permanent'], true)
        ) {
            $errors['vehicle_id'] = 'Vehiculul selectat nu este configurat pentru beneficiarul si tipul de transport ales.';
        }

        $driverIdRaw = trim((string) ($input['driver_id'] ?? ''));
        $driverId = $driverIdRaw === '' ? null : (int) $driverIdRaw;
        if ($driverId === null || $driverId <= 0) {
            $driverId = null;
            $softErrors['driver_id'] = 'Soferul nu este selectat.';
        } else {
            $driver = $this->model->getDriverById($driverId);
            if ($driver === null) {
                $errors['driver_id'] = 'Soferul selectat nu exista.';
            }
            // Soferul poate fi oricare sofer existent, chiar daca nu este asignat
            // vehiculului ales — folosirea este valabila doar pentru aceasta cursa si nu
            // modifica asocierile permanente sofer-vehicul.
        }

        $clientsRaw = trim((string) ($input['nr_clienti'] ?? ''));
        $clients = $clientsRaw === '' ? null : (int) $clientsRaw;
        if ($clients !== null && $clients < 0) {
            $errors['nr_clienti'] = 'Numarul de clienti nu poate fi negativ.';
        }

        $kmRaw = trim((string) ($input['km_cursa'] ?? ''));
        $km = $kmRaw === '' ? null : (int) $kmRaw;
        if ($transportType === 'compresor') {
            // Pentru Compresor ignoram complet km_cursa; calculul foloseste km_dislocare.
            $km = null;
            $kmRaw = '';
        }
        if ($km !== null && $km < 0) {
            $errors['km_cursa'] = 'Km efectuati nu poate fi negativ.';
        }

        $kmTotalRaw = trim((string) ($input['km_totali'] ?? ''));
        $kmTotal = $kmTotalRaw === '' ? null : (int) $kmTotalRaw;
        if ($kmTotal !== null && $kmTotal < 0) {
            $errors['km_totali'] = 'Km totali nu poate fi negativ.';
        }

        $qtyRaw = trim((string) ($input['cantitate_incarcata'] ?? ''));
        $qty = $qtyRaw === '' ? null : $this->normalizeDecimal($qtyRaw);
        if ($qtyRaw !== '' && ($qty === null || $qty < 0)) {
            $errors['cantitate_incarcata'] = 'Cantitatea incarcata este invalida.';
        }
        $qtyForTonPricing = $qty;

        $prelevataQtyRaw = trim((string) ($input['cantitate_prelevata'] ?? ''));
        $prelevataQty = $prelevataQtyRaw === '' ? null : $this->normalizeDecimal($prelevataQtyRaw);
        if ($prelevataQtyRaw !== '' && ($prelevataQty === null || $prelevataQty < 0)) {
            $errors['cantitate_prelevata'] = 'Cantitatea prelevata este invalida.';
        }

        $hoursRaw = trim((string) ($input['ore_aspirare'] ?? ''));
        $hours = $hoursRaw === '' ? null : $this->normalizeOperatingHours($hoursRaw);
        if ($hoursRaw !== '' && ($hours === null || $hours < 0)) {
            $errors['ore_aspirare'] = 'Ore aspirare este invalid (ex: 2 sau 2h).';
        }
        $operatingHoursRaw = $hoursRaw;
        $operatingHours = $hours;

        $relocationKmRaw = trim((string) ($input['km_dislocare'] ?? ''));
        $relocationKm = $relocationKmRaw === '' ? null : $this->normalizeDecimal($relocationKmRaw);
        if ($relocationKmRaw !== '' && ($relocationKm === null || $relocationKm < 0)) {
            $errors['km_dislocare'] = 'Km dislocare este invalid.';
        }

        $deliveredTonRaw = trim((string) ($input['tona_livrata'] ?? ''));
        $deliveredTon = $deliveredTonRaw === '' ? null : $this->normalizeDecimal($deliveredTonRaw);
        if ($deliveredTonRaw !== '' && ($deliveredTon === null || $deliveredTon < 0)) {
            $errors['tona_livrata'] = 'Tona livrata este invalida.';
        }
        $deliveredTonForPricing = $this->normalizeTonInputToKgForPricing($deliveredTon, $vehicleTransportCapacity);

        $liquidSuctionTonRaw = trim((string) ($input['tona_aspirata_lichida'] ?? ''));
        $liquidSuctionTon = $liquidSuctionTonRaw === '' ? null : $this->normalizeDecimal($liquidSuctionTonRaw);
        if ($liquidSuctionTonRaw !== '' && ($liquidSuctionTon === null || $liquidSuctionTon < 0)) {
            $errors['tona_aspirata_lichida'] = 'Tona aspirata lichida este invalida.';
        }

        $gasSuctionTonRaw = trim((string) ($input['tona_aspirata_gazoasa'] ?? ''));
        $gasSuctionTon = $gasSuctionTonRaw === '' ? null : $this->normalizeDecimal($gasSuctionTonRaw);
        if ($gasSuctionTonRaw !== '' && ($gasSuctionTon === null || $gasSuctionTon < 0)) {
            $errors['tona_aspirata_gazoasa'] = 'Tona aspirata gazoasa este invalida.';
        }

        $zoneIdRaw = trim((string) ($input['zona_distributie_id'] ?? ''));
        $zoneId = $zoneIdRaw === '' ? null : (int) $zoneIdRaw;
        $zone = null;
        $zoneTariff = 0.0;
        $zoneExtraKmCost = 0.0;
        if ($zoneId !== null) {
            if ($zoneId <= 0 || !$this->model->existsDistributionZone($zoneId)) {
                $errors['zona_distributie_id'] = 'Zona de distributie selectata este invalida.';
            } else {
                $zone = $this->model->getDistributionZoneById($zoneId);
                if ($zone === null) {
                    $errors['zona_distributie_id'] = 'Zona de distributie selectata nu exista.';
                } else {
                    $zoneTariff = max(0, (float) ($zone['tarif_distributie'] ?? 0));
                    $zoneExtraKmCost = max(0, (float) ($zone['cost_extra_km'] ?? 0));
                }
            }
        }

        $routeTariffPerTon = 0.0;
        $routeExtraKmCost = 0.0;
        $routeRideCost = 0.0;
        $routeApplyRideCost = false;
        $distributionRouteTariffMode = self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH;
        $distributionRouteKmTariff = null;
        $hasMatchedDistributionRouteRule = false;
        $primaryRouteKmTariff = null;
        $primaryRouteUsesManualAgreedKm = false;
        $primaryRouteRideCost = 0.0;
        $primaryRouteApplyRideCost = false;
        $primaryRouteDepartureGarage = '';
        $primaryRouteReturnGarage = '';
        $hasMatchedPrimaryRouteRule = false;
        $distributionRouteScope = [
            'has_active_rules' => false,
            'has_vehicle_scoped_rules' => false,
            'scoped_rules' => [],
            'scoped_rule_map' => [],
        ];
        $distributionRouteTransportScope = $this->resolveDistributionRouteScopeFromTransportType($transportType);
        if ($isDistributionTransport && $beneficiaryId !== null && $beneficiaryId > 0) {
            try {
                $distributionRouteScope = $this->resolveDistributionRouteScopeForVehicle(
                    $beneficiaryId,
                    $vehicleId,
                    $distributionRouteTransportScope
                );
            } catch (Throwable $exception) {
                error_log('[DispecerCurseController][route_scope_lookup] ' . $exception->getMessage());
            }
        }

        if ($isPrimaryTransport) {
            if ($zoneId === null || $zoneId <= 0) {
                $softErrors['zona_distributie_id'] = 'Locul de descarcare nu este selectat.';
            }

            if ($beneficiaryId !== null && $beneficiaryId > 0) {
                if ($loadLocation !== null && (int) ($loadLocation['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                    $errors['loc_incarcare_id'] = 'Pentru Primar, selecteaza un loc de incarcare configurat pentru beneficiarul ales.';
                }

                if ($zone !== null && (int) ($zone['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                    $errors['zona_distributie_id'] = 'Pentru Primar, selecteaza o zona configurata pentru beneficiarul ales.';
                }
            }

            if (
                $beneficiaryId !== null && $beneficiaryId > 0
                && $loadLocationId !== null && $loadLocationId > 0
                && $zoneId !== null && $zoneId > 0
            ) {
                try {
                    $primaryRouteRule = $this->resolvePrimaryRouteRuleForBeneficiaryBidirectional(
                        $beneficiaryId,
                        $loadLocationId,
                        $zoneId,
                        $loadLocation,
                        $zone,
                        $vehicleId,
                        trim((string) ($input['loc_intoarcere'] ?? ''))
                    );
                    if ($primaryRouteRule !== null) {
                        // Rute pe 4 puncte: punctele de plecare/intoarcere vin din configurare,
                        // nu din formular, ca sa nu poata devia cursa de la ruta configurata.
                        $primaryRouteDepartureGarage = trim((string) ($primaryRouteRule['garaj_plecare'] ?? ''));
                        $primaryRouteReturnGarage = trim((string) ($primaryRouteRule['garaj_intoarcere'] ?? ''));
                        $primaryRouteKmTariff = max(0, (int) ($primaryRouteRule['km_tarifare'] ?? 0));
                        $primaryRouteUsesManualAgreedKm = !empty($primaryRouteRule['km_agreati_manual']);
                        $primaryRouteRideCost = max(0, (float) ($primaryRouteRule['cost_cursa'] ?? 0));
                        $primaryRouteApplyRideCost = !empty($primaryRouteRule['aplica_cost_cursa']) && $primaryRouteRideCost > 0;
                        $hasMatchedPrimaryRouteRule = true;
                    }
                } catch (Throwable $exception) {
                    error_log('[DispecerCurseController][primary_route_rule_lookup] ' . $exception->getMessage());
                }
            }

            if (!$hasMatchedPrimaryRouteRule) {
                if ($loadLocationId !== null && $loadLocationId > 0 && $zoneId !== null && $zoneId > 0) {
                    $softErrors['zona_distributie_id'] = 'Combinatia selectata Loc ↔ Zona nu este configurata in Setari Primar pentru beneficiarul ales.';
                }
            } elseif ($primaryRouteUsesManualAgreedKm) {
                if ($km === null || $km <= 0) {
                    $softErrors['km_cursa'] = 'Km agreati nu sunt completati pentru ruta Primar selectata.';
                }
            } elseif ($primaryRouteKmTariff === null || $primaryRouteKmTariff <= 0) {
                $softErrors['km_cursa'] = 'Km efectuati nu sunt configurati in Setari Primar pentru combinatia selectata.';
            } else {
                // Pentru Primar, km efectuati vine din configuratia de ruta.
                $km = $primaryRouteKmTariff;
                $kmRaw = (string) $primaryRouteKmTariff;
            }
        }

        $goodsTypeValuesRaw = isset($input['tip_marfa']) && is_array($input['tip_marfa'])
            ? $input['tip_marfa']
            : [$input['tip_marfa'] ?? ''];
        $goodsTypeValues = [];
        $invalidGoodsTypeSelected = false;
        foreach ($goodsTypeValuesRaw as $goodsTypeValueRaw) {
            $goodsTypeKey = $this->normalizeGoodsType((string) $goodsTypeValueRaw);
            if ($goodsTypeKey === '') {
                if (trim((string) $goodsTypeValueRaw) !== '') {
                    $invalidGoodsTypeSelected = true;
                }
                continue;
            }
            $goodsTypeValues[$goodsTypeKey] = $goodsTypeKey;
        }
        $goodsTypeValues = array_values($goodsTypeValues);
        if ($goodsTypeValues === []) {
            $softErrors['tip_marfa'] = 'Tipul de marfa nu este selectat.';
        } elseif ($invalidGoodsTypeSelected) {
            $errors['tip_marfa'] = 'Selecteaza doar tipuri de marfa valide.';
        }

        // Statusul de facturare nu se mai alege din formular. Cursele noi intra
        // automat "in curs de facturare"; se schimba doar din Centralizator Facturare.
        $billingStatus = $this->normalizeBillingStatus(trim((string) ($input['status_facturare'] ?? '')));
        if ($billingStatus === '') {
            $billingStatus = self::DEFAULT_BILLING_STATUS;
        }

        if ($isPrimaryKmTransport && ($km === null || $km <= 0) && !isset($errors['km_cursa']) && !isset($softErrors['km_cursa'])) {
            $softErrors['km_cursa'] = 'Km agreati (tarifare) nu sunt completati.';
        }

        if ($isPrimaryTonTransport && ($qtyForTonPricing === null || $qtyForTonPricing <= 0)) {
            $softErrors['cantitate_incarcata'] = 'Cantitatea incarcata nu este completata (necesara facturarii pe tone).';
        }

        if ($isDistributionTransport) {
            if ($qtyForTonPricing === null || $qtyForTonPricing <= 0) {
                $softErrors['cantitate_incarcata'] = 'Cantitatea incarcata nu este completata (necesara facturarii distributiei).';
            }
            if ($zoneId === null || $zoneId <= 0) {
                $softErrors['zona_distributie_id'] = 'Zona de distributie nu este selectata.';
            }

            if ($beneficiaryId !== null && $beneficiaryId > 0) {
                if ($loadLocation !== null && (int) ($loadLocation['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                    $errors['loc_incarcare_id'] = 'Pentru distributie, selecteaza un loc de incarcare configurat pentru beneficiarul ales.';
                }

                if ($zone !== null && (int) ($zone['beneficiar_id'] ?? 0) !== $beneficiaryId) {
                    $errors['zona_distributie_id'] = 'Zona de distributie selectata nu este configurata pentru beneficiarul ales.';
                }
            }

            if (!empty($distributionRouteScope['has_active_rules'])) {
                $scopedRouteMap = is_array($distributionRouteScope['scoped_rule_map'] ?? null)
                    ? $distributionRouteScope['scoped_rule_map']
                    : [];

                if ($loadLocationId !== null && $loadLocationId > 0 && $zoneId !== null && $zoneId > 0) {
                    $selectedRouteRule = $this->resolveDistributionRouteRuleFromScopedMap(
                        $scopedRouteMap,
                        $loadLocationId,
                        $zoneId,
                        $loadLocation,
                        $zone
                    );
                    if ($selectedRouteRule !== null) {
                        $distributionRouteTariffMode = $this->normalizeDistributionRouteTariffModeInput((string) ($selectedRouteRule['tarif_mod'] ?? ''));
                        $routeTariffPerTon = max(0, (float) ($selectedRouteRule['tarif_tona'] ?? 0));
                        $routeExtraKmCost = max(0, (float) ($selectedRouteRule['cost_extra_km'] ?? 0));
                        $routeRideCost = max(0, (float) ($selectedRouteRule['cost_cursa'] ?? 0));
                        $routeApplyRideCost = !empty($selectedRouteRule['aplica_cost_cursa']) && $routeRideCost > 0;
                        $distributionRouteKmTariff = max(0, (int) ($selectedRouteRule['km_tarifare'] ?? 0));
                        $hasMatchedDistributionRouteRule = true;
                    }
                }
            } elseif (
                $beneficiaryId !== null && $beneficiaryId > 0
                && $loadLocationId !== null && $loadLocationId > 0
                && $zoneId !== null && $zoneId > 0
            ) {
                try {
                    $routeRule = $this->resolveDistributionRouteRuleForBeneficiaryBidirectional(
                        $beneficiaryId,
                        $loadLocationId,
                        $zoneId,
                        $vehicleId,
                        $distributionRouteTransportScope
                    );
                    if ($routeRule !== null) {
                        $distributionRouteTariffMode = $this->normalizeDistributionRouteTariffModeInput((string) ($routeRule['tarif_mod'] ?? ''));
                        $routeTariffPerTon = max(0, (float) ($routeRule['tarif_tona'] ?? 0));
                        $routeExtraKmCost = max(0, (float) ($routeRule['cost_extra_km'] ?? 0));
                        $routeRideCost = max(0, (float) ($routeRule['cost_cursa'] ?? 0));
                        $routeApplyRideCost = !empty($routeRule['aplica_cost_cursa']) && $routeRideCost > 0;
                        $distributionRouteKmTariff = max(0, (int) ($routeRule['km_tarifare'] ?? 0));
                        $hasMatchedDistributionRouteRule = true;
                    }
                } catch (Throwable $exception) {
                    error_log('[DispecerCurseController][route_rule_lookup] ' . $exception->getMessage());
                }
            }

            if ($beneficiaryId !== null && $beneficiaryId > 0 && !empty($distributionRouteScope['has_vehicle_scoped_rules'])) {
                $scopedRules = is_array($distributionRouteScope['scoped_rules'] ?? null)
                    ? $distributionRouteScope['scoped_rules']
                    : [];

                if ($scopedRules === []) {
                    $softErrors['zona_distributie_id'] = 'Pentru vehiculul selectat nu exista perechi de ruta configurate (Loc ↔ Zona).';
                } elseif (
                    $loadLocationId !== null && $loadLocationId > 0
                    && $zoneId !== null && $zoneId > 0
                    && !$hasMatchedDistributionRouteRule
                ) {
                    $softErrors['zona_distributie_id'] = 'Combinatia selectata Loc ↔ Zona nu este configurata pentru vehiculul ales.';
                }
            }
        }

        if (
            $isDistributionWithKmTransport
            && $hasMatchedDistributionRouteRule
            && ($km === null || $km <= 0)
            && $distributionRouteKmTariff !== null
            && $distributionRouteKmTariff > 0
        ) {
            $km = $distributionRouteKmTariff;
            $kmRaw = (string) $distributionRouteKmTariff;
        }

        // Pentru Compresor nu mai fortam campuri obligatorii de cantitate/ore/km/tona.
        // Daca exista tarife configurate, operatorul poate folosi orice combinatie.

        $hasCompressorLiquidSuctionPricing = false;
        $hasCompressorGasSuctionPricing = false;
        $price = 0.0;
        if ($beneficiary !== null && $errors === []) {
            if ($isPrimaryTransport) {
                $pricePerKm = $this->resolveBeneficiaryRate($beneficiary, 'primar', false);
                $pricePerTon = $this->resolveBeneficiaryRate($beneficiary, 'primar', true);

                if ($pricePerKm <= 0 && $pricePerTon <= 0 && !$primaryRouteApplyRideCost) {
                    $softErrors['beneficiar_id'] = 'Beneficiarul selectat nu are tarife valide pentru transport primar.';
                } else {
                    $price = $primaryRouteApplyRideCost
                        ? $primaryRouteRideCost
                        : (
                            $isPrimaryTonTransport
                                ? ($pricePerTon > 0 ? $pricePerTon : $pricePerKm)
                                : ($pricePerKm > 0 ? $pricePerKm : $pricePerTon)
                        );
                }
            } elseif ($isDistributionTransport) {
                if (!$this->beneficiarySupportsDistributionTransport($beneficiary, $transportType)) {
                    $errors['beneficiar_id'] = $transportType === 'distributie'
                        ? 'Beneficiarul selectat nu este configurat pentru transport distributie.'
                        : 'Beneficiarul selectat nu este configurat pentru transport Primar+Distributie.';
                } else {
                    $beneficiaryDistributionPerTon = max(0, (float) ($beneficiary['pret_distributie_tona'] ?? 0));
                    $beneficiaryDistributionPerKm = max(0, (float) ($beneficiary['pret_distributie_km'] ?? 0));
                    $isSameDistributionRoute = $this->isSameDistributionRoute($loadLocation, $zone);
                    $routeUsesTonTariff = $this->distributionRouteUsesTonTariff($distributionRouteTariffMode);
                    $routeUsesKmTariff = $this->distributionRouteUsesKmTariff($distributionRouteTariffMode);
                    $effectiveTonRate = $routeUsesTonTariff
                        ? (
                            $routeTariffPerTon > 0
                                ? $routeTariffPerTon
                                : $this->resolveDistributionTonRate(
                                    $loadLocationTariff,
                                    $zoneTariff,
                                    $beneficiaryDistributionPerTon,
                                    $isSameDistributionRoute
                                )
                        )
                        : 0.0;
                    $effectiveKmRate = $routeUsesKmTariff
                        ? ($routeExtraKmCost > 0 ? $routeExtraKmCost : ($zoneExtraKmCost > 0 ? $zoneExtraKmCost : $beneficiaryDistributionPerKm))
                        : 0.0;
                    if ($effectiveTonRate <= 0 && $effectiveKmRate <= 0 && !$routeApplyRideCost) {
                        $softErrors['zona_distributie_id'] = 'Nu exista un tarif valid pentru distributie (Loc incarcare, Zona sau Cost extra km).';
                    } else {
                        // In pret_tarifare stocam componenta de baza pentru distributie.
                        $price = $routeApplyRideCost
                            ? $routeRideCost
                            : ($effectiveTonRate > 0 ? $effectiveTonRate : $effectiveKmRate);
                    }
                }
            } else {
                if (empty($beneficiary['suporta_compresor'])) {
                    $errors['beneficiar_id'] = 'Beneficiarul selectat nu este configurat pentru transport Compresor.';
                } else {
                    $compressorRates = $this->resolveCompressorRates($beneficiary);
                    $hasCompressorLiquidSuctionPricing = $compressorRates['pret_tona_aspirata_lichida'] > 0;
                    $hasCompressorGasSuctionPricing = $compressorRates['pret_tona_aspirata_gazoasa'] > 0;
                    if (
                        $compressorRates['pret_ora_aspirare'] <= 0
                        && $compressorRates['pret_km_dislocare'] <= 0
                        && $compressorRates['pret_tona_livrata'] <= 0
                        && $compressorRates['pret_tona_aspirata_lichida'] <= 0
                        && $compressorRates['pret_tona_aspirata_gazoasa'] <= 0
                    ) {
                        $softErrors['beneficiar_id'] = 'Beneficiarul selectat nu are tarife valide pentru transport Compresor.';
                    } else {
                        $price = $compressorRates['pret_ora_aspirare'] > 0
                            ? $compressorRates['pret_ora_aspirare']
                            : (
                                $compressorRates['pret_km_dislocare'] > 0
                                    ? $compressorRates['pret_km_dislocare']
                                    : (
                                        $compressorRates['pret_tona_livrata'] > 0
                                            ? $compressorRates['pret_tona_livrata']
                                            : (
                                                $compressorRates['pret_tona_aspirata_lichida'] > 0
                                                    ? $compressorRates['pret_tona_aspirata_lichida']
                                                    : $compressorRates['pret_tona_aspirata_gazoasa']
                                            )
                                    )
                            );
                    }
                }
            }
        }

        $total = 0.0;
        $costKmPrimar = 0.0;
        $costKmDistributie = 0.0;
        $costKmMixt = 0.0;
        $costKmCompresor = 0.0;
        $effectiveDistributionTonRate = 0.0;
        $effectiveDistributionKmRate = 0.0;
        if ($errors === []) {
            if ($isPrimaryTransport) {
                $pricePerKm = $this->resolveBeneficiaryRate($beneficiary ?? [], 'primar', false);
                $pricePerTon = $this->resolveBeneficiaryRate($beneficiary ?? [], 'primar', true);
                if ($primaryRouteApplyRideCost) {
                    $total = $primaryRouteRideCost;
                } elseif ($isPrimaryTonTransport) {
                    $tonComponent = ($qtyForTonPricing !== null && $qtyForTonPricing > 0)
                        ? ((float) $qtyForTonPricing * $pricePerTon)
                        : 0.0;
                    $total = $tonComponent;
                } else {
                    $kmComponent = ($km !== null && $km > 0) ? ((float) $km * $pricePerKm) : 0.0;
                    $total = $kmComponent;
                }
            } elseif ($isDistributionTransport) {
                $beneficiaryDistributionPerTon = max(0, (float) (($beneficiary ?? [])['pret_distributie_tona'] ?? 0));
                $beneficiaryDistributionPerKm = max(0, (float) (($beneficiary ?? [])['pret_distributie_km'] ?? 0));
                $isSameDistributionRoute = $this->isSameDistributionRoute($loadLocation, $zone);
                $routeUsesTonTariff = $this->distributionRouteUsesTonTariff($distributionRouteTariffMode);
                $routeUsesKmTariff = $this->distributionRouteUsesKmTariff($distributionRouteTariffMode);
                $effectiveTonRate = $routeUsesTonTariff
                    ? (
                        $routeTariffPerTon > 0
                            ? $routeTariffPerTon
                            : $this->resolveDistributionTonRate(
                                $loadLocationTariff,
                                $zoneTariff,
                                $beneficiaryDistributionPerTon,
                                $isSameDistributionRoute
                            )
                    )
                    : 0.0;
                $effectiveKmRate = $routeUsesKmTariff
                    ? ($routeExtraKmCost > 0 ? $routeExtraKmCost : ($zoneExtraKmCost > 0 ? $zoneExtraKmCost : $beneficiaryDistributionPerKm))
                    : 0.0;
                $effectiveDistributionTonRate = max(0, (float) $effectiveTonRate);
                $effectiveDistributionKmRate = max(0, (float) $effectiveKmRate);
                $fixedRideComponent = $routeApplyRideCost ? $routeRideCost : 0.0;
                $tonComponent = $routeApplyRideCost
                    ? 0.0
                    : (float) ((float) ($qtyForTonPricing ?? 0) * $effectiveTonRate);
                $shouldApplyDistributionKmComponent = false;
                if (!$routeApplyRideCost) {
                    if ($transportType === 'distributie') {
                        // Distributie simpla foloseste Pret/km (optional) din setarile distributiei.
                        $shouldApplyDistributionKmComponent = $effectiveKmRate > 0;
                    } elseif ($isDistributionWithKmTransport) {
                        $shouldApplyDistributionKmComponent = true;
                    }
                }
                $extraKmComponent = $shouldApplyDistributionKmComponent
                    ? (float) ((float) ($km ?? 0) * $effectiveKmRate)
                    : 0.0;
                $total = $fixedRideComponent + $tonComponent + $extraKmComponent;
            } else {
                $compressorRates = $this->resolveCompressorRates($beneficiary ?? []);
                $hasCompressorLiquidSuctionPricing = $compressorRates['pret_tona_aspirata_lichida'] > 0;
                $hasCompressorGasSuctionPricing = $compressorRates['pret_tona_aspirata_gazoasa'] > 0;
                $hourComponent = (float) ($hours ?? 0) * $compressorRates['pret_ora_aspirare'];
                $relocationKmComponent = (float) ($relocationKm ?? 0) * $compressorRates['pret_km_dislocare'];
                $deliveredTonComponent = (float) ($deliveredTonForPricing ?? 0) * $compressorRates['pret_tona_livrata'];
                $liquidSuctionTonComponent = $hasCompressorLiquidSuctionPricing
                    ? ((float) ($liquidSuctionTon ?? 0) * $compressorRates['pret_tona_aspirata_lichida'])
                    : 0.0;
                $gasSuctionTonComponent = $hasCompressorGasSuctionPricing
                    ? ((float) ($gasSuctionTon ?? 0) * $compressorRates['pret_tona_aspirata_gazoasa'])
                    : 0.0;
                $total = $hourComponent + $relocationKmComponent + $deliveredTonComponent + $liquidSuctionTonComponent + $gasSuctionTonComponent;
            }

            $includesPrimarySegment = $isPrimaryTransport || $isDistributionWithKmTransport;
            $includesDistributionSegment = $isDistributionTransport;
            $kmPrimar = 0.0;
            $kmDistributie = 0.0;
            if ($transportType === 'primar_distributie') {
                $kmPrimar = max(0.0, (float) ($km ?? 0));
                $kmDistributie = max(0.0, max(0.0, (float) ($kmTotal ?? 0)) - $kmPrimar);
            } elseif ($transportType === 'distributie') {
                // Pentru Distributie simpla, cost/km distributie foloseste direct Km efectuati.
                $kmDistributie = max(0.0, (float) ($km ?? 0));
            } elseif ($isPrimaryTransport) {
                $kmPrimar = max(0.0, (float) ($km ?? 0));
            }
            $primaryPerKmRate = 0.0;
            if ($includesPrimarySegment) {
                $primaryPerKmRate = $transportType === 'primar_distributie'
                    ? $effectiveDistributionKmRate
                    : max(0.0, (float) $this->resolveBeneficiaryRate($beneficiary ?? [], 'primar', false));
            }
            $distributionPerTonRate = $includesDistributionSegment ? $effectiveDistributionTonRate : 0.0;
            $totalPrimar = $includesPrimarySegment
                ? (
                    $isPrimaryTransport && $primaryRouteApplyRideCost
                        ? $primaryRouteRideCost
                        : ($kmPrimar * $primaryPerKmRate)
                )
                : 0.0;
            $distributionFixedRideCost = ($includesDistributionSegment && $routeApplyRideCost)
                ? max(0.0, (float) $routeRideCost)
                : 0.0;
            $totalDistributie = 0.0;
            if ($includesDistributionSegment) {
                if ($transportType === 'distributie') {
                    // Pentru Distributie simpla folosim totalul complet (tona + componenta km optionala).
                    $totalDistributie = $total;
                } else {
                    $totalDistributie = $distributionFixedRideCost > 0
                        ? $distributionFixedRideCost
                        : ((float) ($qtyForTonPricing ?? 0) * $distributionPerTonRate);
                }
            }

            if ($includesPrimarySegment && $kmPrimar > 0) {
                $costKmPrimar = $totalPrimar / $kmPrimar;
            }
            if ($transportType === 'primar_distributie') {
                // Primar+Distributie foloseste doar valoarea componentei de distributie.
                if ($kmDistributie > 0) {
                    $costKmDistributie = $totalDistributie / $kmDistributie;
                }
            } elseif ($includesDistributionSegment && $kmDistributie > 0) {
                $costKmDistributie = $totalDistributie / $kmDistributie;
            }

            if ($transportType === 'primar_distributie' || $transportType === 'mixt') {
                // Regula stabilita: Cost/km Mixt = Total facturare / Km efectuati.
                $kmTotaliMixt = max(0.0, (float) ($kmTotal ?? 0));
                if ($kmTotaliMixt > 0) {
                    $costKmMixt = $total / $kmTotaliMixt;
                }
            } elseif ($includesPrimarySegment && !$includesDistributionSegment) {
                $costKmMixt = $costKmPrimar;
            } elseif (!$includesPrimarySegment && $includesDistributionSegment) {
                $costKmMixt = $costKmDistributie;
            }

            if ($transportType === 'compresor') {
                $kmCompresor = max(0.0, (float) ($relocationKm ?? 0));
                if ($kmCompresor > 0) {
                    $costKmCompresor = $total / $kmCompresor;
                }
            }

            $costKmPrimar = round($costKmPrimar, 2);
            $costKmDistributie = round($costKmDistributie, 2);
            $costKmMixt = round($costKmMixt, 2);
            $costKmCompresor = round($costKmCompresor, 2);
        }

        $observations = trim((string) ($input['observatii'] ?? ''));
        if ($observations !== '' && mb_strlen($observations) > 5000) {
            $errors['observatii'] = 'Observatiile sunt prea lungi.';
        }

        $old = [
            'vehicle_id' => $vehicleId > 0 ? (string) $vehicleId : '',
            'driver_id' => $driverId !== null && $driverId > 0 ? (string) $driverId : '',
            'tip_transport' => $transportType,
            'data_incarcare' => $loadingDate,
            'data_inceput' => $startDate,
            'data_sfarsit' => $endDate,
            'ora_inceput' => $startTimeRaw,
            'ora_sfarsit' => $endTimeRaw,
            'durata_cursa_minute' => $durationMinutes !== null ? (string) $durationMinutes : '',
            'capacitate_transport' => $vehicleTransportCapacity !== null ? (string) $vehicleTransportCapacity : '',
            'loc_incarcare_id' => $loadLocationId !== null ? (string) $loadLocationId : '',
            'loc_plecare' => $departureLocationRaw,
            'loc_intoarcere' => trim((string) ($input['loc_intoarcere'] ?? '')),
            'loc_aspirare' => $suctionLocationRaw,
            'loc_livrare' => $deliveryLocationRaw,
            'loc_livrare_cursa' => $routeDeliveryLocationRaw,
            'beneficiar_id' => $beneficiaryId !== null ? (string) $beneficiaryId : '',
            'tip_marfa' => $goodsTypeValues,
            'cantitate_incarcata' => $qtyRaw,
            'cantitate_prelevata' => $prelevataQtyRaw,
            'tona_aspirata_lichida' => $liquidSuctionTonRaw,
            'tona_aspirata_gazoasa' => $gasSuctionTonRaw,
            'nr_clienti' => $clientsRaw,
            'km_cursa' => $kmRaw,
            'ore_functionare' => $operatingHoursRaw,
            'km_totali' => $kmTotalRaw,
            'ore_aspirare' => $hoursRaw,
            'km_dislocare' => $relocationKmRaw,
            'tona_livrata' => $deliveredTonRaw,
            'zona_distributie_id' => $zoneId !== null ? (string) $zoneId : '',
            'status_facturare' => $billingStatus,
            'observatii' => $observations,
        ];

        if ($errors !== []) {
            return [[], $errors, $old, $softErrors];
        }

        $data = [
            'vehicle_id' => $vehicleId,
            'driver_id' => $driverId,
            'tip_transport' => $transportType,
            'data_cursa' => $startDate,
            'data_incarcare' => $loadingDate !== '' ? $loadingDate : null,
            'data_inceput' => $startDate,
            'data_sfarsit' => $endDate,
            'ora_inceput' => $startTime,
            'ora_sfarsit' => $endTime,
            'durata_cursa_minute' => $durationMinutes,
            'capacitate_transport' => $vehicleTransportCapacity,
            'loc_incarcare_id' => $loadLocationId,
            'loc_plecare' => $isCompressorTransport
                ? ($departureLocationRaw !== '' ? $departureLocationRaw : null)
                : ($primaryRouteDepartureGarage !== '' ? $primaryRouteDepartureGarage : null),
            'loc_intoarcere' => $isCompressorTransport
                ? null
                : ($primaryRouteReturnGarage !== '' ? $primaryRouteReturnGarage : null),
            'loc_aspirare' => $isCompressorTransport ? ($suctionLocationRaw !== '' ? $suctionLocationRaw : null) : null,
            'loc_livrare' => $isCompressorTransport ? ($deliveryLocationRaw !== '' ? $deliveryLocationRaw : null) : null,
            'loc_livrare_cursa' => $isCompressorTransport ? ($routeDeliveryLocationRaw !== '' ? $routeDeliveryLocationRaw : null) : null,
            'beneficiar_id' => $beneficiaryId,
            'tip_marfa' => implode(',', $goodsTypeValues),
            'cantitate_incarcata' => $qty,
            'cantitate_prelevata' => $transportType === 'compresor' ? $prelevataQty : null,
            'tona_aspirata_lichida' => $transportType === 'compresor' ? $liquidSuctionTon : null,
            'tona_aspirata_gazoasa' => $transportType === 'compresor' ? $gasSuctionTon : null,
            'nr_clienti' => $clients,
            'km_cursa' => $transportType === 'compresor' ? null : $km,
            'ore_functionare' => $transportType === 'compresor' ? $operatingHours : null,
            'km_totali' => $kmTotal,
            'ore_aspirare' => $transportType === 'compresor' ? $hours : null,
            'km_dislocare' => $transportType === 'compresor' ? $relocationKm : null,
            'tona_livrata' => $transportType === 'compresor' ? $deliveredTon : null,
            'zona_distributie_id' => $zoneId,
            'status_facturare' => $billingStatus,
            'pret_tarifare' => round($price, 2),
            'total_facturare' => round($total, 2),
            'cost_km_primar' => $costKmPrimar,
            'cost_km_distributie' => $costKmDistributie,
            'cost_km_mixt' => $costKmMixt,
            'cost_km_compresor' => $costKmCompresor,
            'observatii' => $observations !== '' ? $observations : null,
        ];

        return [$data, [], $old, $softErrors];
    }

    private function isDistributionTransportType(string $transportType): bool
    {
        return $transportType === 'distributie' || $transportType === 'primar_distributie';
    }

    private function resolveDistributionRouteScopeFromTransportType(string $transportType): ?string
    {
        $normalizedTransportType = trim(strtolower($transportType));

        if ($normalizedTransportType === 'distributie') {
            return self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE;
        }
        if ($normalizedTransportType === 'primar_distributie' || $normalizedTransportType === 'mixt') {
            return self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE;
        }

        return null;
    }

    private function beneficiarySupportsDistributionTransport(array $beneficiary, string $transportType): bool
    {
        $routeScope = $this->resolveDistributionRouteScopeFromTransportType($transportType);
        if ($routeScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE) {
            return !empty($beneficiary['suporta_distributie']);
        }
        if ($routeScope === self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE) {
            return !empty($beneficiary['suporta_primar_distributie']);
        }

        return false;
    }

    private function normalizeDistributionRouteScopeInput(
        string $scope,
        string $fallback = self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
    ): string {
        $normalizedScope = trim(strtolower($scope));
        if (
            $normalizedScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
            || $normalizedScope === self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
        ) {
            return $normalizedScope;
        }

        return $fallback === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
            ? self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
            : self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE;
    }

    private function normalizeDistributionRouteTariffModeInput(string $mode): string
    {
        $normalizedMode = trim(strtolower($mode));
        if (array_key_exists($normalizedMode, self::DISTRIBUTION_ROUTE_TARIFF_MODE_LABELS)) {
            return $normalizedMode;
        }

        return self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH;
    }

    private function distributionRouteUsesTonTariff(string $mode): bool
    {
        $normalizedMode = $this->normalizeDistributionRouteTariffModeInput($mode);

        return $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH
            || $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_TON;
    }

    private function distributionRouteUsesKmTariff(string $mode): bool
    {
        $normalizedMode = $this->normalizeDistributionRouteTariffModeInput($mode);

        return $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH
            || $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_KM;
    }

    private function distributionRouteFlashKey(string $scope): string
    {
        return $this->normalizeDistributionRouteScopeInput($scope, self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE)
            === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
            ? 'config_distributie_route_distributie'
            : 'config_distributie_route_primar_distributie';
    }

    private function syncPrimaryRouteBidirectionalCatalog(int $beneficiaryId): void
    {
        if ($beneficiaryId <= 0) {
            return;
        }

        $primaryRules = $this->model->getPrimaryRouteRules(false, $beneficiaryId);
        foreach ($primaryRules as $primaryRule) {
            if (!is_array($primaryRule)) {
                continue;
            }

            $locationName = trim((string) ($primaryRule['loc_nume'] ?? ''));
            $zoneName = trim((string) ($primaryRule['zona_nume'] ?? ''));
            $this->syncPrimaryBidirectionalCatalogNames($beneficiaryId, $locationName, $zoneName);
        }
    }

    private function ensurePrimaryRouteBidirectionalCatalogForPair(int $beneficiaryId, int $locationId, int $zoneId): void
    {
        if ($beneficiaryId <= 0 || $locationId <= 0 || $zoneId <= 0) {
            return;
        }

        $location = $this->model->getLoadLocationById($locationId);
        $zone = $this->model->getDistributionZoneById($zoneId);
        if ($location === null || $zone === null) {
            return;
        }

        if (
            (int) ($location['beneficiar_id'] ?? 0) !== $beneficiaryId
            || (int) ($zone['beneficiar_id'] ?? 0) !== $beneficiaryId
        ) {
            return;
        }

        $locationName = trim((string) ($location['nume'] ?? ''));
        $zoneName = trim((string) ($zone['nume'] ?? ''));
        $this->syncPrimaryBidirectionalCatalogNames($beneficiaryId, $locationName, $zoneName);
    }

    private function syncPrimaryBidirectionalCatalogNames(int $beneficiaryId, string $locationName, string $zoneName): void
    {
        if ($beneficiaryId <= 0) {
            return;
        }

        $locationName = trim($locationName);
        $zoneName = trim($zoneName);

        if ($zoneName !== '') {
            $reverseLoadLocation = $this->model->getLoadLocationByNameForBeneficiary($beneficiaryId, $zoneName);
            if ($reverseLoadLocation === null) {
                $this->model->createLoadLocation($beneficiaryId, $zoneName, 0.0, true);
            }
        }

        if ($locationName !== '') {
            $reverseZone = $this->model->getDistributionZoneByNameForBeneficiary($beneficiaryId, $locationName);
            if ($reverseZone === null) {
                $this->model->createDistributionZone($beneficiaryId, $locationName, 0.0, 0.0, true);
            }
        }
    }

    private function isDistributionWithKmTransportType(string $transportType): bool
    {
        return $transportType === 'primar_distributie';
    }

    private function isPrimaryKmTransportType(string $transportType): bool
    {
        return $transportType === 'primar';
    }

    private function isPrimaryTonTransportType(string $transportType): bool
    {
        return $transportType === 'primar_tona';
    }

    private function validateExpenseInput(array $input): array
    {
        $errors = [];
        $submitIntent = trim((string) ($input['submit_intent'] ?? 'expense'));
        $isRefacturareOnlySubmit = $submitIntent === 'refacturare' && (int) ($input['expense_id'] ?? 0) <= 0;

        $categorySelection = trim((string) ($input['categorie_id'] ?? ''));
        $typeInput = trim((string) ($input['tip_cheltuiala'] ?? ''));
        if ($categorySelection === '') {
            $categorySelection = $typeInput;
        }

        $expenseCategory = null;
        $categoryId = null;
        $type = $typeInput;
        if ($categorySelection === '') {
            $errors['tip_cheltuiala'] = 'Selecteaza tipul cheltuielii.';
            $errors['categorie_id'] = 'Selecteaza tipul cheltuielii.';
        } else {
            $expenseCategory = $this->model->resolveExpenseCategorySelection($categorySelection, true);
            if ($expenseCategory !== null) {
                $categoryId = (int) ($expenseCategory['id'] ?? 0);
                $type = $this->model->legacyExpenseTypeForCategory($expenseCategory);
            }
        }

        if ($type === self::FUEL_EXPENSE_TYPE) {
            $errors['tip_cheltuiala'] = 'Motorina se adauga separat din modulul Alimentari.';
            $errors['categorie_id'] = 'Motorina se adauga separat din modulul Alimentari.';
        } elseif (!array_key_exists($type, self::EXPENSE_TYPES)) {
            $errors['tip_cheltuiala'] = 'Tipul cheltuielii este invalid.';
            $errors['categorie_id'] = 'Tipul cheltuielii este invalid.';
        } elseif ($expenseCategory === null && $type !== '') {
            $expenseCategory = $this->model->resolveExpenseCategorySelection($type, true);
            if ($expenseCategory === null) {
                $errors['tip_cheltuiala'] = 'Tipul cheltuielii este invalid sau inactiv.';
                $errors['categorie_id'] = 'Tipul cheltuielii este invalid sau inactiv.';
            } else {
                $categoryId = (int) ($expenseCategory['id'] ?? 0);
                $type = $this->model->legacyExpenseTypeForCategory($expenseCategory);
            }
        }

        $refacturareEnabled = isset($input['refacturare_enabled']) && (string) $input['refacturare_enabled'] === '1';
        $refacturareType = trim((string) ($input['refacturare_tip_cheltuiala'] ?? ''));
        if ($refacturareEnabled && $refacturareType === '') {
            $errors['refacturare_tip_cheltuiala'] = 'Selecteaza tipul pentru refacturare.';
        } elseif ($refacturareEnabled && $refacturareType === self::FUEL_EXPENSE_TYPE) {
            $errors['refacturare_tip_cheltuiala'] = 'Motorina se adauga separat din modulul Alimentari.';
        } elseif ($refacturareEnabled && !array_key_exists($refacturareType, self::EXPENSE_TYPES)) {
            $errors['refacturare_tip_cheltuiala'] = 'Tipul pentru refacturare este invalid.';
        }
        if (!$refacturareEnabled) {
            $refacturareType = '';
        }
        if ($isRefacturareOnlySubmit && !$refacturareEnabled) {
            $errors['refacturare_suma'] = 'Bifeaza Refacturare pentru a adauga refacturarea.';
        }

        $amountRaw = trim((string) ($input['suma'] ?? ''));
        $amount = $this->normalizeDecimal($amountRaw);

        $expenseDate = trim((string) ($input['data_cheltuiala'] ?? ''));
        if (!$this->isValidDate($expenseDate)) {
            $errors['data_cheltuiala'] = 'Data cheltuielii este invalida.';
        }

        $observations = trim((string) ($input['observatii'] ?? ''));
        $roadTaxInputRaw = [
            'taxa_acces_bucati' => trim((string) ($input['taxa_acces_bucati'] ?? '')),
            'taxa_acces_pret' => trim((string) ($input['taxa_acces_pret'] ?? '')),
            'port_bucati' => trim((string) ($input['port_bucati'] ?? '')),
            'port_pret' => trim((string) ($input['port_pret'] ?? '')),
            'trece_bucati' => trim((string) ($input['trece_bucati'] ?? '')),
            'trece_pret' => trim((string) ($input['trece_pret'] ?? '')),
        ];

        $roadTaxDetails = [];
        $roadTaxTotal = 0.0;
        $roadTaxLineCount = 0;
        $roadTaxRows = [
            'taxa_acces' => ['qty' => 'taxa_acces_bucati', 'price' => 'taxa_acces_pret', 'label' => 'Taxa acces'],
            'port' => ['qty' => 'port_bucati', 'price' => 'port_pret', 'label' => 'Port'],
            'trece' => ['qty' => 'trece_bucati', 'price' => 'trece_pret', 'label' => 'Trece'],
        ];

        foreach ($roadTaxRows as $rowKey => $rowConfig) {
            $qtyRaw = $roadTaxInputRaw[$rowConfig['qty']];
            $priceRaw = $roadTaxInputRaw[$rowConfig['price']];
            $qty = $qtyRaw === '' ? null : $this->normalizeDecimal($qtyRaw);
            $price = $priceRaw === '' ? null : $this->normalizeDecimal($priceRaw);

            if ($qtyRaw !== '' && ($qty === null || $qty <= 0)) {
                $errors[$rowConfig['qty']] = $rowConfig['label'] . ': completeaza un numar de bucati valid (> 0).';
            }

            if ($priceRaw !== '' && ($price === null || $price <= 0)) {
                $errors[$rowConfig['price']] = $rowConfig['label'] . ': completeaza un pret valid (> 0).';
            }

            if (($qtyRaw !== '' && $priceRaw === '') || ($qtyRaw === '' && $priceRaw !== '')) {
                if ($qtyRaw === '') {
                    $errors[$rowConfig['qty']] = $rowConfig['label'] . ': completeaza numarul de bucati.';
                }
                if ($priceRaw === '') {
                    $errors[$rowConfig['price']] = $rowConfig['label'] . ': completeaza pretul.';
                }
            }

            if ($qty !== null && $qty > 0 && $price !== null && $price > 0) {
                $lineTotal = round($qty * $price, 2);
                $roadTaxDetails[$rowKey] = [
                    'bucati' => round($qty, 2),
                    'pret' => round($price, 2),
                    'total' => $lineTotal,
                ];
                $roadTaxTotal += $lineTotal;
                $roadTaxLineCount++;
            }
        }

        if ($type === 'taxe_drum' && !$isRefacturareOnlySubmit) {
            if ($roadTaxLineCount === 0) {
                $errors['suma'] = 'Pentru Taxe drum completeaza cel putin o linie (bucati si pret).';
            }
            $amount = $roadTaxTotal;
            $amountRaw = $roadTaxTotal > 0 ? number_format($roadTaxTotal, 2, '.', '') : '';
        }

        $refacturareRoadTaxInputRaw = [
            'refacturare_taxa_acces_bucati' => trim((string) ($input['refacturare_taxa_acces_bucati'] ?? '')),
            'refacturare_taxa_acces_pret' => trim((string) ($input['refacturare_taxa_acces_pret'] ?? '')),
            'refacturare_port_bucati' => trim((string) ($input['refacturare_port_bucati'] ?? '')),
            'refacturare_port_pret' => trim((string) ($input['refacturare_port_pret'] ?? '')),
            'refacturare_trece_bucati' => trim((string) ($input['refacturare_trece_bucati'] ?? '')),
            'refacturare_trece_pret' => trim((string) ($input['refacturare_trece_pret'] ?? '')),
        ];
        $refacturareRoadTaxDetails = [];
        $refacturareRoadTaxTotal = 0.0;
        $refacturareRoadTaxLineCount = 0;

        foreach ($roadTaxRows as $rowKey => $rowConfig) {
            $qtyField = 'refacturare_' . $rowConfig['qty'];
            $priceField = 'refacturare_' . $rowConfig['price'];
            $qtyRaw = $refacturareRoadTaxInputRaw[$qtyField];
            $priceRaw = $refacturareRoadTaxInputRaw[$priceField];
            $qty = $qtyRaw === '' ? null : $this->normalizeDecimal($qtyRaw);
            $price = $priceRaw === '' ? null : $this->normalizeDecimal($priceRaw);
            $label = 'Refacturare ' . $rowConfig['label'];

            if ($qtyRaw !== '' && ($qty === null || $qty <= 0)) {
                $errors[$qtyField] = $label . ': completeaza un numar de bucati valid (> 0).';
            }

            if ($priceRaw !== '' && ($price === null || $price <= 0)) {
                $errors[$priceField] = $label . ': completeaza un pret valid (> 0).';
            }

            if (($qtyRaw !== '' && $priceRaw === '') || ($qtyRaw === '' && $priceRaw !== '')) {
                if ($qtyRaw === '') {
                    $errors[$qtyField] = $label . ': completeaza numarul de bucati.';
                }
                if ($priceRaw === '') {
                    $errors[$priceField] = $label . ': completeaza pretul.';
                }
            }

            if ($qty !== null && $qty > 0 && $price !== null && $price > 0) {
                $lineTotal = round($qty * $price, 2);
                $refacturareRoadTaxDetails[$rowKey] = [
                    'bucati' => round($qty, 2),
                    'pret' => round($price, 2),
                    'total' => $lineTotal,
                ];
                $refacturareRoadTaxTotal += $lineTotal;
                $refacturareRoadTaxLineCount++;
            }
        }

        if ($refacturareEnabled && $refacturareType === 'taxe_drum' && $refacturareRoadTaxLineCount === 0) {
            $errors['refacturare_tip_cheltuiala'] = 'Pentru Refacturare Taxe drum completeaza cel putin o linie (bucati si pret).';
        }
        if (!$refacturareEnabled || $refacturareType !== 'taxe_drum') {
            $refacturareRoadTaxDetails = [];
        }

        $refacturareDetailsJson = null;
        if ($refacturareRoadTaxDetails !== []) {
            $encodedRefacturareDetails = json_encode($refacturareRoadTaxDetails, JSON_UNESCAPED_UNICODE);
            if (is_string($encodedRefacturareDetails) && $encodedRefacturareDetails !== '') {
                $refacturareDetailsJson = $encodedRefacturareDetails;
            }
        }

        $refacturareAmountRaw = trim((string) ($input['refacturare_suma'] ?? ''));
        $refacturareAmount = $refacturareAmountRaw === '' ? null : $this->normalizeDecimal($refacturareAmountRaw);
        if ($refacturareEnabled && $refacturareType === 'taxe_drum') {
            $refacturareAmount = $refacturareRoadTaxTotal;
            $refacturareAmountRaw = $refacturareRoadTaxTotal > 0 ? number_format($refacturareRoadTaxTotal, 2, '.', '') : '';
        }
        if ($refacturareEnabled && ($refacturareAmount === null || $refacturareAmount <= 0)) {
            $errors['refacturare_suma'] = 'Suma Refacturare trebuie sa fie mai mare decat 0.';
        }

        $refacturareDate = trim((string) ($input['refacturare_data'] ?? ''));
        if ($refacturareEnabled && !$this->isValidDate($refacturareDate)) {
            $errors['refacturare_data'] = 'Data Refacturare este invalida.';
        }

        $refacturareObservations = trim((string) ($input['refacturare_observatii'] ?? ''));
        if ($refacturareEnabled && $refacturareObservations !== '' && mb_strlen($refacturareObservations) > 5000) {
            $errors['refacturare_observatii'] = 'Observatiile Refacturare sunt prea lungi.';
        }

        if (!$refacturareEnabled) {
            $refacturareAmount = null;
            $refacturareAmountRaw = '';
            $refacturareDate = '';
            $refacturareObservations = '';
        }

        if ($isRefacturareOnlySubmit) {
            $amount = 0.0;
            $amountRaw = '0.00';
            if ($refacturareType !== '') {
                $type = $refacturareType;
            }
        }

        if (!$isRefacturareOnlySubmit && ($amount === null || $amount <= 0) && !isset($errors['suma'])) {
            $errors['suma'] = 'Suma trebuie sa fie mai mare decat 0.';
        }

        $storedObservations = $this->buildExpenseObservationsWithRoadTaxDetails(
            $observations,
            $type === 'taxe_drum' ? $roadTaxDetails : []
        );
        if ($storedObservations !== null && mb_strlen($storedObservations) > 5000) {
            $errors['observatii'] = 'Observatiile sunt prea lungi.';
        }

        $old = [
            'expense_id' => trim((string) ($input['expense_id'] ?? '')),
            'tip_cheltuiala' => $type,
            'categorie_id' => $categoryId !== null ? (string) $categoryId : $categorySelection,
            'refacturare_enabled' => $refacturareEnabled ? '1' : '0',
            'refacturare_tip_cheltuiala' => $refacturareType,
            'refacturare_suma' => $refacturareAmountRaw,
            'refacturare_data' => $refacturareDate !== '' ? $refacturareDate : date('Y-m-d'),
            'refacturare_observatii' => $refacturareObservations,
            'suma' => $amountRaw,
            'data_cheltuiala' => $expenseDate,
            'observatii' => $observations,
            'taxa_acces_bucati' => $roadTaxInputRaw['taxa_acces_bucati'],
            'taxa_acces_pret' => $roadTaxInputRaw['taxa_acces_pret'],
            'port_bucati' => $roadTaxInputRaw['port_bucati'],
            'port_pret' => $roadTaxInputRaw['port_pret'],
            'trece_bucati' => $roadTaxInputRaw['trece_bucati'],
            'trece_pret' => $roadTaxInputRaw['trece_pret'],
            'refacturare_taxa_acces_bucati' => $refacturareRoadTaxInputRaw['refacturare_taxa_acces_bucati'],
            'refacturare_taxa_acces_pret' => $refacturareRoadTaxInputRaw['refacturare_taxa_acces_pret'],
            'refacturare_port_bucati' => $refacturareRoadTaxInputRaw['refacturare_port_bucati'],
            'refacturare_port_pret' => $refacturareRoadTaxInputRaw['refacturare_port_pret'],
            'refacturare_trece_bucati' => $refacturareRoadTaxInputRaw['refacturare_trece_bucati'],
            'refacturare_trece_pret' => $refacturareRoadTaxInputRaw['refacturare_trece_pret'],
        ];

        if ($errors !== []) {
            return [[], $errors, $old];
        }

        return [[
            'tip_cheltuiala' => $type,
            'categorie_id' => $categoryId,
            'refacturare_tip_cheltuiala' => $refacturareType !== '' ? $refacturareType : null,
            'refacturare_detalii' => $refacturareDetailsJson,
            'refacturare_suma' => $refacturareAmount !== null ? round((float) $refacturareAmount, 2) : null,
            'refacturare_data' => $refacturareDate !== '' ? $refacturareDate : null,
            'refacturare_observatii' => $refacturareObservations !== '' ? $refacturareObservations : null,
            'suma' => round((float) $amount, 2),
            'data_cheltuiala' => $expenseDate,
            'observatii' => $storedObservations,
        ], [], $old];
    }

    private function splitExpenseObservationsAndRoadTaxDetails(string $storedObservations): array
    {
        $storedObservations = trim($storedObservations);
        if ($storedObservations === '') {
            return [
                'plain_observations' => '',
                'road_tax_details' => [],
            ];
        }

        $markerPos = strrpos($storedObservations, self::ROAD_TAX_DETAILS_MARKER);
        if ($markerPos === false) {
            return [
                'plain_observations' => $storedObservations,
                'road_tax_details' => [],
            ];
        }

        $plainObservations = trim(substr($storedObservations, 0, $markerPos));
        $jsonPayload = trim(substr($storedObservations, $markerPos + strlen(self::ROAD_TAX_DETAILS_MARKER)));
        if ($jsonPayload === '') {
            return [
                'plain_observations' => $plainObservations,
                'road_tax_details' => [],
            ];
        }

        $decoded = json_decode($jsonPayload, true);
        if (!is_array($decoded)) {
            return [
                'plain_observations' => $storedObservations,
                'road_tax_details' => [],
            ];
        }

        return [
            'plain_observations' => $plainObservations,
            'road_tax_details' => $this->normalizeRoadTaxDetailsPayload($decoded),
        ];
    }

    private function buildExpenseObservationsWithRoadTaxDetails(string $plainObservations, array $roadTaxDetails): ?string
    {
        $plainObservations = trim($plainObservations);
        if ($roadTaxDetails === []) {
            return $plainObservations !== '' ? $plainObservations : null;
        }

        $payload = json_encode($roadTaxDetails, JSON_UNESCAPED_UNICODE);
        if (!is_string($payload) || $payload === '') {
            return $plainObservations !== '' ? $plainObservations : null;
        }

        $value = self::ROAD_TAX_DETAILS_MARKER . $payload;
        if ($plainObservations !== '') {
            $value = $plainObservations . "\n\n" . $value;
        }

        return $value;
    }

    private function normalizeRoadTaxDetailsPayload(array $payload): array
    {
        $result = [];
        $allowedRows = ['taxa_acces', 'port', 'trece'];
        foreach ($allowedRows as $rowKey) {
            $rowData = $payload[$rowKey] ?? null;
            if (!is_array($rowData)) {
                continue;
            }

            $qty = isset($rowData['bucati']) && is_numeric((string) $rowData['bucati'])
                ? (float) $rowData['bucati']
                : null;
            $price = isset($rowData['pret']) && is_numeric((string) $rowData['pret'])
                ? (float) $rowData['pret']
                : null;
            if ($qty === null || $price === null || $qty <= 0 || $price <= 0) {
                continue;
            }

            $lineTotal = round($qty * $price, 2);
            $result[$rowKey] = [
                'bucati' => round($qty, 2),
                'pret' => round($price, 2),
                'total' => $lineTotal,
            ];
        }

        return $result;
    }

    private function buildRoadTaxFormValuesFromDetails(array $details, string $fieldPrefix = ''): array
    {
        $values = [
            $fieldPrefix . 'taxa_acces_bucati' => '',
            $fieldPrefix . 'taxa_acces_pret' => '',
            $fieldPrefix . 'port_bucati' => '',
            $fieldPrefix . 'port_pret' => '',
            $fieldPrefix . 'trece_bucati' => '',
            $fieldPrefix . 'trece_pret' => '',
        ];

        $mapping = [
            'taxa_acces' => ['qty' => $fieldPrefix . 'taxa_acces_bucati', 'price' => $fieldPrefix . 'taxa_acces_pret'],
            'port' => ['qty' => $fieldPrefix . 'port_bucati', 'price' => $fieldPrefix . 'port_pret'],
            'trece' => ['qty' => $fieldPrefix . 'trece_bucati', 'price' => $fieldPrefix . 'trece_pret'],
        ];

        foreach ($mapping as $rowKey => $fields) {
            $rowData = $details[$rowKey] ?? null;
            if (!is_array($rowData)) {
                continue;
            }

            if (isset($rowData['bucati']) && is_numeric((string) $rowData['bucati'])) {
                $values[$fields['qty']] = $this->formatExpenseNumericInput((float) $rowData['bucati']);
            }

            if (isset($rowData['pret']) && is_numeric((string) $rowData['pret'])) {
                $values[$fields['price']] = $this->formatExpenseNumericInput((float) $rowData['pret']);
            }
        }

        return $values;
    }

    private function formatExpenseNumericInput(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function normalizeBillingStatus(string $value): string
    {
        $key = trim(strtolower($value));
        if ($key === '' || !array_key_exists($key, self::BILLING_STATUSES)) {
            return '';
        }

        return $key;
    }

    private function updateStatusAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse']));

        $raceId = (int) ($_POST['id'] ?? 0);
        $billingStatus = $this->normalizeBillingStatus((string) ($_POST['status_facturare'] ?? ''));
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));

        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa selectata nu exista.');
            $this->redirectToSafeDispecerUrl($returnUrl);
        }

        if ($billingStatus === '') {
            flash_set('warning', 'Statusul de facturare selectat este invalid.');
            $this->redirectToSafeDispecerUrl($returnUrl);
        }

        try {
            $updated = $this->model->updateRaceBillingStatus($raceId, $billingStatus, date('Y-m-d H:i:s'), $this->currentUserId());
            if ($updated) {
                flash_set('success', 'Statusul cursei a fost actualizat.');
            } else {
                flash_set('warning', 'Statusul cursei nu a putut fi actualizat.');
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][update_status] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        $this->redirectToSafeDispecerUrl($returnUrl);
    }

    private function updateExpenseStatusAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'dispecer_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'dispecer_curse']));

        $raceId = (int) ($_POST['id'] ?? 0);
        $choice = trim((string) ($_POST['cheltuieli_choice'] ?? ''));
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        $expenseStatus = $choice === 'not_applicable' ? 'not_applicable' : 'pending';

        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa selectata nu exista.');
            $this->redirectToSafeDispecerUrl($returnUrl);
        }

        try {
            $updated = $this->model->updateRaceExpenseStatus($raceId, $expenseStatus, date('Y-m-d H:i:s'), $this->currentUserId());
            if ($updated) {
                if ($expenseStatus === 'not_applicable') {
                    flash_set('success', 'Cursa nu va mai aparea la lipsa cheltuieli.');
                } else {
                    flash_set('success', 'Cursa ramane in atentii pentru cheltuieli.');
                }
            } else {
                flash_set('warning', 'Alegerea pentru cheltuieli nu a putut fi salvata.');
            }
        } catch (Throwable $exception) {
            error_log('[DispecerCurseController][update_expense_status] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        $this->redirectToSafeDispecerUrl($returnUrl);
    }

    private function resetRaceExpenseStatusIfNotApplicable(int $raceId, string $updatedAt, bool $requireExpense = false): void
    {
        if ($raceId <= 0) {
            return;
        }

        $race = $this->model->getRaceById($raceId);
        if ($race === null) {
            return;
        }

        if ($requireExpense && (int) ($race['expense_count'] ?? 0) <= 0) {
            return;
        }

        if ((string) ($race['cheltuieli_status'] ?? 'pending') !== 'not_applicable') {
            return;
        }

        $this->model->updateRaceExpenseStatus($raceId, 'pending', $updatedAt, $this->currentUserId());
    }

    private function redirectToSafeDispecerUrl(string $returnUrl): void
    {
        if ($returnUrl !== '') {
            $parsed = parse_url($returnUrl);
            $path = (string) ($parsed['path'] ?? '');
            $query = (string) ($parsed['query'] ?? '');
            $isIndexPath = $path === ''
                || $path === 'index.php'
                || str_ends_with($path, '/index.php');
            if ($isIndexPath && str_contains($query, 'page=dispecer_curse')) {
                redirect($returnUrl);
            }
        }

        redirect(build_query_url(['page' => 'dispecer_curse']));
    }

    private function wantsJson(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private function sendJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function positiveIntFromInput(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $intValue = (int) $raw;
        return $intValue > 0 ? $intValue : null;
    }

    private function normalizeInactiveApprovalDecision(mixed $value): string
    {
        if (!$this->canReviewInactiveApprovals()) {
            return '';
        }

        if (!is_scalar($value)) {
            return '';
        }

        $decision = strtolower(trim((string) $value));
        return in_array($decision, ['approved', 'pending'], true) ? $decision : '';
    }

    private function getInactiveResourcesForRaceData(array $data): array
    {
        $vehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : 0;
        $driverId = isset($data['driver_id']) ? (int) $data['driver_id'] : 0;
        $status = $this->inactiveStatusService->getResourcesStatus(
            $vehicleId > 0 ? $vehicleId : null,
            $driverId > 0 ? $driverId : null
        );

        return is_array($status['inactive_resources'] ?? null) ? $status['inactive_resources'] : [];
    }

    private function resourcesNeedingInactiveApprovalDecision(array $resources, ?int $tripId): array
    {
        $needsDecision = [];

        foreach ($resources as $resource) {
            if (empty($resource['is_inactive'])) {
                continue;
            }

            $resourceType = $this->approvalResourceTypeForInactiveResource($resource);
            $resourceId = (int) ($resource['resource_id'] ?? 0);
            if ($resourceType === '' || $resourceId <= 0) {
                continue;
            }

            $existingStatus = null;
            if ($tripId !== null && $tripId > 0) {
                $existingStatus = $this->inactiveApprovalModel
                    ->getExistingOpenStatusForResourceTrip($resourceType, $resourceId, $tripId);
            }

            if ($existingStatus === null && !$this->canReviewInactiveApprovals()) {
                $pendingApproval = $this->pendingRequesterApprovalForInactiveResource($resource);
                if ($pendingApproval !== null) {
                    $existingStatus = 'pending';
                }
            }

            if (!in_array($existingStatus, ['pending', 'approved'], true)) {
                $needsDecision[] = $resource;
            }
        }

        return $needsDecision;
    }

    private function resourcesForTripInactiveApprovalCreation(array $resources, string $approvalDecision): array
    {
        if ($resources === []) {
            return [];
        }

        if ($this->canReviewInactiveApprovals() || $approvalDecision !== '') {
            return $resources;
        }

        $filtered = [];
        foreach ($resources as $resource) {
            if ($this->pendingRequesterApprovalForInactiveResource($resource) === null) {
                $filtered[] = $resource;
            }
        }

        return $filtered;
    }

    private function pendingRequesterApprovalForInactiveResource(array $resource): ?array
    {
        $userId = (int) ($this->currentUserId() ?? 0);
        $resourceType = $this->approvalResourceTypeForInactiveResource($resource);
        $resourceId = (int) ($resource['resource_id'] ?? 0);
        if ($userId <= 0 || $resourceType === '' || $resourceId <= 0) {
            return null;
        }

        return $this->inactiveApprovalModel->getPendingForRequesterResourceContext(
            $userId,
            $resourceType,
            $resourceId,
            (string) ($resource['usage_context'] ?? 'Dispecer curse')
        );
    }

    private function inactiveApprovalModalPayload(array $approval, ?array $resource = null): array
    {
        $resourceType = strtolower(trim((string) ($resource['resource_type'] ?? $approval['resource_type'] ?? '')));
        $resourceLabel = trim((string) ($approval['resource_label'] ?? ''));
        if ($resourceLabel === '') {
            $resourceLabel = trim((string) ($approval['current_vehicle_label'] ?? $approval['current_driver_label'] ?? ''));
        }
        if ($resourceLabel === '' && $resource !== null) {
            $resourceLabel = trim((string) ($resource['resource_label'] ?? ''));
        }

        $requestedByName = trim((string) ($approval['requested_by_name'] ?? ''));
        if ($requestedByName === '') {
            $requestedByName = $this->currentUserName();
        }

        $affectedDocuments = [];
        if (is_array($approval['affected_document_names'] ?? null)) {
            $affectedDocuments = array_values(array_filter(array_map('strval', $approval['affected_document_names'])));
        }
        if ($affectedDocuments === [] && $resource !== null && is_array($resource['affected_document_names'] ?? null)) {
            $affectedDocuments = array_values(array_filter(array_map('strval', $resource['affected_document_names'])));
        }

        $status = strtolower(trim((string) ($approval['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        return [
            'id' => (int) ($approval['id'] ?? 0),
            'status' => $status,
            'status_label' => $this->inactiveApprovalStatusLabel($status),
            'can_cancel' => $status === 'pending',
            'resource_type' => (string) ($approval['resource_type'] ?? ''),
            'resource_type_label' => $this->inactiveApprovalTypeLabel($resourceType !== '' ? $resourceType : (string) ($approval['resource_type'] ?? '')),
            'resource_label' => $resourceLabel,
            'inactive_reason' => (string) ($approval['inactive_reason'] ?? ($resource['reason_key'] ?? '')),
            'inactive_reason_label' => (string) ($approval['inactive_reason_label'] ?? ($resource['reason_label'] ?? 'Alt motiv')),
            'inactive_since' => (string) ($approval['inactive_since'] ?? ($resource['inactive_since'] ?? '')),
            'usage_context' => (string) ($approval['usage_context'] ?? ($resource['usage_context'] ?? 'Dispecer curse')),
            'requested_at' => (string) ($approval['requested_at'] ?? ''),
            'requested_by_name' => $requestedByName,
            'affected_document_names' => $affectedDocuments,
            'documents' => is_array($approval['documents'] ?? null) ? $approval['documents'] : [],
        ];
    }

    private function inactiveApprovalStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Aprobata',
            'rejected' => 'Respinsa',
            default => 'In asteptare',
        };
    }

    private function inactiveApprovalTypeLabel(string $resourceType): string
    {
        return match (strtolower(trim($resourceType))) {
            'driver' => 'Sofer',
            'repair' => 'Reparatie',
            default => 'Vehicul',
        };
    }

    private function buildInactiveApprovalRequiredMessage(array $resources): string
    {
        $labels = [];
        foreach ($resources as $resource) {
            $resourceLabel = trim((string) ($resource['resource_label'] ?? ''));
            $reasonLabel = trim((string) ($resource['reason_label'] ?? ''));
            if ($resourceLabel === '') {
                continue;
            }

            $labels[] = $reasonLabel !== '' ? ($resourceLabel . ' - ' . $reasonLabel) : $resourceLabel;
        }

        $suffix = $labels !== [] ? (': ' . implode('; ', $labels)) : '.';

        return 'Utilizarea resurselor inactive necesita alegerea unei aprobari' . $suffix;
    }

    private function approvalResourceTypeForInactiveResource(array $resource): string
    {
        $approvalType = strtolower(trim((string) ($resource['approval_resource_type'] ?? '')));
        if (in_array($approvalType, ['vehicle', 'driver', 'repair'], true)) {
            return $approvalType;
        }

        $resourceType = strtolower(trim((string) ($resource['resource_type'] ?? '')));
        $reasonKey = strtolower(trim((string) ($resource['reason_key'] ?? '')));
        if ($resourceType === 'vehicle' && $reasonKey === 'repair') {
            return 'repair';
        }

        return in_array($resourceType, ['vehicle', 'driver'], true) ? $resourceType : '';
    }

    private function buildBeneficiaryPricingMap(array $beneficiaries): array
    {
        $map = [];

        foreach ($beneficiaries as $beneficiary) {
            $id = (int) ($beneficiary['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $map[$id] = [
                'pret_tarifare' => (float) ($beneficiary['pret_tarifare'] ?? 0),
                'pret_km' => (float) ($beneficiary['pret_km'] ?? 0),
                'pret_tona' => (float) ($beneficiary['pret_tona'] ?? 0),
                'pret_distributie_km' => (float) ($beneficiary['pret_distributie_km'] ?? 0),
                'pret_distributie_tona' => (float) ($beneficiary['pret_distributie_tona'] ?? 0),
                'pret_ora_aspirare' => (float) ($beneficiary['pret_ora_aspirare'] ?? 0),
                'pret_km_dislocare' => (float) ($beneficiary['pret_km_dislocare'] ?? 0),
                'pret_tona_livrata' => (float) ($beneficiary['pret_tona_livrata'] ?? 0),
                'pret_tona_aspirata_lichida' => (float) ($beneficiary['pret_tona_aspirata_lichida'] ?? 0),
            'pret_tona_aspirata_gazoasa' => (float) ($beneficiary['pret_tona_aspirata_gazoasa'] ?? 0),
            'suporta_primar' => !empty($beneficiary['suporta_primar']),
            'suporta_distributie' => !empty($beneficiary['suporta_distributie']),
            'suporta_primar_distributie' => !empty($beneficiary['suporta_primar_distributie']),
            'suporta_compresor' => !empty($beneficiary['suporta_compresor']),
        ];
        }

        return $map;
    }

    private function normalizeGoodsType(string $value): string
    {
        $key = trim(strtolower($value));
        if ($key === '' || !array_key_exists($key, self::GOODS_TYPES)) {
            return '';
        }

        return $key;
    }

    private function normalizeGoodsTypeSelection(mixed $value): array
    {
        $rawValues = [];

        if (is_array($value)) {
            $rawValues = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $rawValues = explode(',', $value);
        } elseif ($value !== null && $value !== '') {
            $rawValues = [(string) $value];
        }

        $normalized = [];
        foreach ($rawValues as $rawValue) {
            $goodsTypeKey = $this->normalizeGoodsType((string) $rawValue);
            if ($goodsTypeKey === '') {
                continue;
            }
            $normalized[$goodsTypeKey] = $goodsTypeKey;
        }

        return array_values($normalized);
    }

    private function buildLocationVehicleAssignments(array $vehicles, array $vehicleToLocationMap): array
    {
        $assignments = [];

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            if ($vehicleId <= 0 || !isset($vehicleToLocationMap[$vehicleId])) {
                continue;
            }

            $locationId = (int) $vehicleToLocationMap[$vehicleId];
            if ($locationId <= 0) {
                continue;
            }

            $plate = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
            $brand = trim((string) ($vehicle['marca'] ?? ''));
            $model = trim((string) ($vehicle['model'] ?? ''));

            $label = $plate !== '' ? $plate : ('Vehicul #' . $vehicleId);
            $brandModel = trim($brand . ' ' . $model);
            if ($brandModel !== '') {
                $label .= ' - ' . $brandModel;
            }

            $assignments[$locationId][] = [
                'vehicle_id' => $vehicleId,
                'label' => $label,
            ];
        }

        foreach ($assignments as &$items) {
            usort($items, static function (array $a, array $b): int {
                return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });
        }
        unset($items);

        return $assignments;
    }

    private function resolveBeneficiaryRate(array $beneficiary, string $transportType, bool $preferTonRate = false): float
    {
        $supportsPrimary = !empty($beneficiary['suporta_primar']);
        $supportsDistribution = !empty($beneficiary['suporta_distributie']);
        $supportsPrimaryDistribution = !empty($beneficiary['suporta_primar_distributie']);
        $supportsCompressor = !empty($beneficiary['suporta_compresor']);
        $baseRate = max(0, (float) ($beneficiary['pret_tarifare'] ?? 0));
        $pricePerKm = max(0, (float) ($beneficiary['pret_km'] ?? 0));
        $pricePerTon = max(0, (float) ($beneficiary['pret_tona'] ?? 0));
        $distributionPerTon = max(0, (float) ($beneficiary['pret_distributie_tona'] ?? 0));

        if ($transportType === 'primar' || $transportType === 'primar_tona') {
            if (!$supportsPrimary) {
                return 0.0;
            }

            if ($preferTonRate) {
                return $pricePerTon > 0 ? $pricePerTon : $baseRate;
            }

            return $pricePerKm > 0 ? $pricePerKm : $baseRate;
        }

        if ($this->isDistributionTransportType($transportType)) {
            if (
                ($transportType === 'distributie' && !$supportsDistribution)
                || ($transportType !== 'distributie' && !$supportsPrimaryDistribution)
            ) {
                return 0.0;
            }

            if ($distributionPerTon > 0) {
                return $distributionPerTon;
            }

            return $pricePerTon > 0 ? $pricePerTon : $baseRate;
        }

        if ($transportType === 'compresor') {
            if (!$supportsCompressor) {
                return 0.0;
            }

            return $pricePerTon;
        }

        return 0.0;
    }

    private function resolveCompressorRates(array $beneficiary): array
    {
        $pricePerHourSuction = max(0, (float) ($beneficiary['pret_ora_aspirare'] ?? 0));
        $pricePerKmRelocation = max(0, (float) ($beneficiary['pret_km_dislocare'] ?? 0));
        $pricePerDeliveredTon = max(0, (float) ($beneficiary['pret_tona_livrata'] ?? 0));
        $pricePerSuctionLiquidTon = max(0, (float) ($beneficiary['pret_tona_aspirata_lichida'] ?? 0));
        $pricePerSuctionGasTon = max(0, (float) ($beneficiary['pret_tona_aspirata_gazoasa'] ?? 0));

        return [
            'pret_km' => 0.0,
            'pret_tona' => 0.0,
            'pret_ora_aspirare' => $pricePerHourSuction,
            'pret_km_dislocare' => $pricePerKmRelocation,
            'pret_tona_livrata' => $pricePerDeliveredTon,
            'pret_tona_aspirata_lichida' => $pricePerSuctionLiquidTon,
            'pret_tona_aspirata_gazoasa' => $pricePerSuctionGasTon,
        ];
    }

    private function isVehicleAllowedForBeneficiaryAndTransport(
        int $beneficiaryId,
        int $vehicleId,
        string $transportType,
        ?array $beneficiary = null
    ): bool {
        if ($beneficiaryId <= 0 || $vehicleId <= 0) {
            return false;
        }

        if ($beneficiary === null) {
            $beneficiary = $this->model->getTransportBeneficiaryById($beneficiaryId);
        }
        if ($beneficiary === null || empty($beneficiary['activ'])) {
            return false;
        }

        if ($this->isPrimaryKmTransportType($transportType) || $this->isPrimaryTonTransportType($transportType)) {
            if (empty($beneficiary['suporta_primar'])) {
                return false;
            }
        } elseif ($this->isDistributionTransportType($transportType)) {
            if (!$this->beneficiarySupportsDistributionTransport($beneficiary, $transportType)) {
                return false;
            }
        } elseif ($transportType === 'compresor') {
            if (empty($beneficiary['suporta_compresor'])) {
                return false;
            }
        } else {
            return false;
        }

        if ($this->isPrimaryKmTransportType($transportType) || $this->isPrimaryTonTransportType($transportType)) {
            $allowedVehicleIds = $this->collectPrimaryVehicleIdsForBeneficiary($beneficiaryId);
        } elseif ($this->isDistributionTransportType($transportType)) {
            $distributionRouteScope = $this->resolveDistributionRouteScopeFromTransportType($transportType);
            $distributionVehicleScope = $this->collectDistributionVehicleScopeForBeneficiary(
                $beneficiaryId,
                $distributionRouteScope
            );
            $allowedVehicleIds = [];
            foreach ($distributionVehicleScope['vehicle_ids'] as $distributionVehicleId) {
                $distributionVehicleId = (int) $distributionVehicleId;
                if ($distributionVehicleId > 0) {
                    $allowedVehicleIds[$distributionVehicleId] = $distributionVehicleId;
                }
            }
        } else {
            $allowedVehicleIds = $this->collectCompressorVehicleIdsForBeneficiary($beneficiaryId);
        }

        if ($allowedVehicleIds === []) {
            return false;
        }

        return array_key_exists($vehicleId, $allowedVehicleIds);
    }

    private function collectPrimaryVehicleIdsForBeneficiary(int $beneficiaryId): array
    {
        if ($beneficiaryId <= 0) {
            return [];
        }

        $ids = [];
        foreach ($this->model->getPrimaryRouteRules(true, $beneficiaryId) as $rule) {
            foreach ($this->parseDistributionRouteVehicleIds((string) ($rule['vehicle_ids'] ?? '')) as $vehicleId) {
                if ($vehicleId > 0) {
                    $ids[$vehicleId] = $vehicleId;
                }
            }
        }

        return $ids;
    }

    private function collectConfiguredVehicleIdsForBeneficiary(int $beneficiaryId): array
    {
        if ($beneficiaryId <= 0) {
            return [];
        }

        $ids = [];
        $loadMapByBeneficiary = $this->model->getVehicleDefaultLoadLocationMapByBeneficiary();
        $zoneMapByBeneficiary = $this->model->getVehicleDefaultDistributionZoneMapByBeneficiary();

        if (isset($loadMapByBeneficiary[$beneficiaryId]) && is_array($loadMapByBeneficiary[$beneficiaryId])) {
            foreach (array_keys($loadMapByBeneficiary[$beneficiaryId]) as $vehicleIdRaw) {
                $normalizedVehicleId = (int) $vehicleIdRaw;
                if ($normalizedVehicleId > 0) {
                    $ids[$normalizedVehicleId] = $normalizedVehicleId;
                }
            }
        }

        if (isset($zoneMapByBeneficiary[$beneficiaryId]) && is_array($zoneMapByBeneficiary[$beneficiaryId])) {
            foreach (array_keys($zoneMapByBeneficiary[$beneficiaryId]) as $vehicleIdRaw) {
                $normalizedVehicleId = (int) $vehicleIdRaw;
                if ($normalizedVehicleId > 0) {
                    $ids[$normalizedVehicleId] = $normalizedVehicleId;
                }
            }
        }

        return $ids;
    }

    private function collectCompressorVehicleIdsForBeneficiary(int $beneficiaryId): array
    {
        if ($beneficiaryId <= 0) {
            return [];
        }

        $ids = [];
        foreach ($this->model->getVehicleIdsForCompressorBeneficiary($beneficiaryId) as $vehicleId) {
            $vehicleId = (int) $vehicleId;
            if ($vehicleId > 0) {
                $ids[$vehicleId] = $vehicleId;
            }
        }

        return $ids;
    }

    private function collectDistributionVehicleScopeForBeneficiary(int $beneficiaryId, ?string $transportScope = null): array
    {
        $scope = [
            'has_scoped_rules' => false,
            'vehicle_ids' => [],
        ];
        if ($beneficiaryId <= 0) {
            return $scope;
        }

        $ids = [];
        $rules = $this->model->getDistributionRouteRules(true, $beneficiaryId, $transportScope);
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $routeVehicleIds = $this->parseDistributionRouteVehicleIds((string) ($rule['vehicle_ids'] ?? ''));
            if ($routeVehicleIds === []) {
                continue;
            }

            $scope['has_scoped_rules'] = true;
            foreach ($routeVehicleIds as $routeVehicleId) {
                if ($routeVehicleId > 0) {
                    $ids[$routeVehicleId] = $routeVehicleId;
                }
            }
        }

        $scope['vehicle_ids'] = array_values($ids);

        return $scope;
    }

    private function resolveDistributionRouteScopeForVehicle(int $beneficiaryId, int $vehicleId, ?string $transportScope = null): array
    {
        $scope = [
            'has_active_rules' => false,
            'has_vehicle_scoped_rules' => false,
            'scoped_rules' => [],
            'scoped_rule_map' => [],
        ];

        if ($beneficiaryId <= 0) {
            return $scope;
        }

        $rules = $this->model->getDistributionRouteRules(true, $beneficiaryId, $transportScope);
        if ($rules === []) {
            return $scope;
        }

        $normalizedRules = [];
        $hasVehicleScopedRules = false;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $locationId = (int) ($rule['loc_incarcare_id'] ?? 0);
            $zoneId = (int) ($rule['zona_distributie_id'] ?? 0);
            if ($locationId <= 0 || $zoneId <= 0) {
                continue;
            }

            $routeVehicleIds = $this->parseDistributionRouteVehicleIds((string) ($rule['vehicle_ids'] ?? ''));
            if ($routeVehicleIds !== []) {
                $hasVehicleScopedRules = true;
            }

            $normalizedRules[] = [
                'loc_incarcare_id' => $locationId,
                'zona_distributie_id' => $zoneId,
                'tarif_mod' => $this->normalizeDistributionRouteTariffModeInput((string) ($rule['tarif_mod'] ?? '')),
                'tarif_tona' => max(0, (float) ($rule['tarif_tona'] ?? 0)),
                'cost_extra_km' => max(0, (float) ($rule['cost_extra_km'] ?? 0)),
                'km_tarifare' => max(0, (int) ($rule['km_tarifare'] ?? 0)),
                'cost_cursa' => max(0, (float) ($rule['cost_cursa'] ?? 0)),
                'aplica_cost_cursa' => !empty($rule['aplica_cost_cursa']),
                'vehicle_ids' => $routeVehicleIds,
                'loc_nume' => trim((string) ($rule['loc_nume'] ?? '')),
                'zona_nume' => trim((string) ($rule['zona_nume'] ?? '')),
                'transport_scope' => $this->normalizeDistributionRouteScopeInput(
                    (string) ($rule['transport_scope'] ?? ''),
                    self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
                ),
            ];
        }

        if ($normalizedRules === []) {
            return $scope;
        }

        $scope['has_active_rules'] = true;
        $scope['has_vehicle_scoped_rules'] = $hasVehicleScopedRules;

        foreach ($normalizedRules as $normalizedRule) {
            if ($hasVehicleScopedRules) {
                if ($vehicleId <= 0) {
                    continue;
                }
                if (!in_array($vehicleId, $normalizedRule['vehicle_ids'], true)) {
                    continue;
                }
            }

            $scope['scoped_rules'][] = $normalizedRule;
            $pairKey = $this->buildDistributionRoutePairKey(
                (int) $normalizedRule['loc_incarcare_id'],
                (int) $normalizedRule['zona_distributie_id']
            );
            if (!array_key_exists($pairKey, $scope['scoped_rule_map'])) {
                $scope['scoped_rule_map'][$pairKey] = $normalizedRule;
            }
        }

        return $scope;
    }

    private function parseDistributionRouteVehicleIds(string $vehicleIdsRaw): array
    {
        $vehicleIdsRaw = trim($vehicleIdsRaw);
        if ($vehicleIdsRaw === '') {
            return [];
        }

        $normalized = [];
        foreach (explode(',', $vehicleIdsRaw) as $vehicleIdRaw) {
            $vehicleIdRaw = trim($vehicleIdRaw);
            if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId > 0) {
                $normalized[$vehicleId] = $vehicleId;
            }
        }

        return array_values($normalized);
    }

    private function resolveDistributionRouteRuleFromScopedMap(
        array $scopedRouteMap,
        int $locationId,
        int $zoneId,
        ?array $loadLocation = null,
        ?array $zone = null
    ): ?array
    {
        $directRule = $this->resolveDistributionRouteRuleByIdPairFromScopedMap($scopedRouteMap, $locationId, $zoneId);
        if ($directRule !== null) {
            return $directRule;
        }

        $selectedLocationName = $this->normalizeDistributionPointName((string) (($loadLocation ?? [])['nume'] ?? ''));
        $selectedZoneName = $this->normalizeDistributionPointName((string) (($zone ?? [])['nume'] ?? ''));
        if ($selectedLocationName === '' || $selectedZoneName === '') {
            return null;
        }

        $directMatch = $this->resolveDistributionRouteRuleByNamePairFromScopedMap(
            $scopedRouteMap,
            $selectedLocationName,
            $selectedZoneName
        );
        if ($directMatch !== null) {
            return $directMatch;
        }

        if ($selectedLocationName !== $selectedZoneName) {
            return $this->resolveDistributionRouteRuleByNamePairFromScopedMap(
                $scopedRouteMap,
                $selectedZoneName,
                $selectedLocationName
            );
        }

        return null;
    }

    private function resolveDistributionRouteRuleByIdPairFromScopedMap(array $scopedRouteMap, int $locationId, int $zoneId): ?array
    {
        if ($locationId <= 0 || $zoneId <= 0) {
            return null;
        }

        $directKey = $this->buildDistributionRoutePairKey($locationId, $zoneId);
        if (array_key_exists($directKey, $scopedRouteMap) && is_array($scopedRouteMap[$directKey])) {
            return $scopedRouteMap[$directKey];
        }

        if ($locationId !== $zoneId) {
            $reverseKey = $this->buildDistributionRoutePairKey($zoneId, $locationId);
            if (array_key_exists($reverseKey, $scopedRouteMap) && is_array($scopedRouteMap[$reverseKey])) {
                return $scopedRouteMap[$reverseKey];
            }
        }

        return null;
    }

    private function resolveDistributionRouteRuleByNamePairFromScopedMap(
        array $scopedRouteMap,
        string $locationName,
        string $zoneName
    ): ?array {
        foreach ($scopedRouteMap as $candidateRule) {
            if (!is_array($candidateRule)) {
                continue;
            }

            $candidateLocationName = $this->normalizeDistributionPointName((string) ($candidateRule['loc_nume'] ?? ''));
            $candidateZoneName = $this->normalizeDistributionPointName((string) ($candidateRule['zona_nume'] ?? ''));
            if ($candidateLocationName === '' || $candidateZoneName === '') {
                continue;
            }

            if ($candidateLocationName === $locationName && $candidateZoneName === $zoneName) {
                return $candidateRule;
            }
        }

        return null;
    }

    private function resolveDistributionRouteRuleForBeneficiaryBidirectional(
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        ?int $vehicleId = null,
        ?string $transportScope = null
    ): ?array {
        $directRule = $this->model->getDistributionRouteRuleForBeneficiary(
            $beneficiaryId,
            $locationId,
            $zoneId,
            true,
            $vehicleId,
            $transportScope
        );
        if ($directRule !== null) {
            return $directRule;
        }

        if ($locationId !== $zoneId) {
            $reverseRule = $this->model->getDistributionRouteRuleForBeneficiary(
                $beneficiaryId,
                $zoneId,
                $locationId,
                true,
                $vehicleId,
                $transportScope
            );
            if ($reverseRule !== null) {
                return $reverseRule;
            }
        }

        return null;
    }

    private function resolvePrimaryRouteRuleForBeneficiaryBidirectional(
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        ?array $loadLocation = null,
        ?array $zone = null,
        ?int $vehicleId = null,
        ?string $returnPoint = null
    ): ?array {
        $directRule = $this->model->getPrimaryRouteRuleForBeneficiary(
            $beneficiaryId,
            $locationId,
            $zoneId,
            true,
            $vehicleId,
            $returnPoint
        );
        if ($directRule !== null) {
            return $directRule;
        }

        if ($locationId !== $zoneId) {
            $reverseRule = $this->model->getPrimaryRouteRuleForBeneficiary(
                $beneficiaryId,
                $zoneId,
                $locationId,
                true,
                $vehicleId,
                $returnPoint
            );
            if ($reverseRule !== null) {
                return $reverseRule;
            }
        }

        $selectedLocationName = $this->normalizeDistributionPointName((string) ($loadLocation['nume'] ?? ''));
        $selectedZoneName = $this->normalizeDistributionPointName((string) ($zone['nume'] ?? ''));
        if ($selectedLocationName === '' || $selectedZoneName === '') {
            return null;
        }

        $primaryRules = $this->model->getPrimaryRouteRules(true, $beneficiaryId);
        if ($primaryRules === []) {
            return null;
        }

        $resolveByNamePair = function (array $rules, string $expectedLocationName, string $expectedZoneName) use ($vehicleId): ?array {
            $matchedRules = [];
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $ruleLocationName = $this->normalizeDistributionPointName((string) ($rule['loc_nume'] ?? ''));
                $ruleZoneName = $this->normalizeDistributionPointName((string) ($rule['zona_nume'] ?? ''));
                if ($ruleLocationName === '' || $ruleZoneName === '') {
                    continue;
                }

                if ($ruleLocationName === $expectedLocationName && $ruleZoneName === $expectedZoneName) {
                    $matchedRules[] = $rule;
                }
            }

            if ($matchedRules === []) {
                return null;
            }

            // Aceeasi preferinta ca la potrivirea pe id-uri: regula care contine
            // vehiculul > regula fara restrictie > fallback doar la regula unica.
            if ($vehicleId !== null && $vehicleId > 0) {
                foreach ($matchedRules as $matchedRule) {
                    $matchedVehicleIds = array_filter(array_map('intval', explode(',', (string) ($matchedRule['vehicle_ids'] ?? ''))));
                    if (in_array($vehicleId, $matchedVehicleIds, true)) {
                        return $matchedRule;
                    }
                }
                foreach ($matchedRules as $matchedRule) {
                    if (trim((string) ($matchedRule['vehicle_ids'] ?? '')) === '') {
                        return $matchedRule;
                    }
                }

                return count($matchedRules) === 1 ? $matchedRules[0] : null;
            }

            return $matchedRules[0];
        };

        $directNameRule = $resolveByNamePair($primaryRules, $selectedLocationName, $selectedZoneName);
        if ($directNameRule !== null) {
            return $directNameRule;
        }

        if ($selectedLocationName !== $selectedZoneName) {
            $reverseNameRule = $resolveByNamePair($primaryRules, $selectedZoneName, $selectedLocationName);
            if ($reverseNameRule !== null) {
                return $reverseNameRule;
            }
        }

        return null;
    }

    private function buildDistributionRoutePairKey(int $locationId, int $zoneId): string
    {
        return $locationId . '|' . $zoneId;
    }

    private function resolveDistributionTemplateRouteRule(array $scopedRoutes, bool $isSameDistributionRoute): ?array
    {
        foreach ($scopedRoutes as $routeRule) {
            if (!is_array($routeRule)) {
                continue;
            }

            $locationName = $this->normalizeDistributionPointName((string) ($routeRule['loc_nume'] ?? ''));
            $zoneName = $this->normalizeDistributionPointName((string) ($routeRule['zona_nume'] ?? ''));
            if ($locationName === '' || $zoneName === '') {
                continue;
            }

            $isSameRule = $locationName === $zoneName;
            if ($isSameRule !== $isSameDistributionRoute) {
                continue;
            }

            return $routeRule;
        }

        return null;
    }

    private function storeUploadedExpenseDocument(?array $file): array
    {
        if ($file === null || !is_array($file)) {
            return [null, null];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ($error !== UPLOAD_ERR_OK) {
            return [null, $this->uploadErrorMessage($error)];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [null, 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â®ncÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢rcat nu este valid.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return [null, 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul depÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢eÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢te limita maximÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ de 5 MB.'];
        }

        $originalName = $this->sanitizeUploadedFileName((string) ($file['name'] ?? 'document'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

        if (!in_array($extension, $allowedExtensions, true)) {
            return [null, 'Tipul fiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierului nu este permis.'];
        }

        $mimeType = (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpName) ?: '');
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/octet-stream',
            'application/zip',
        ];

        if ($mimeType !== '' && !in_array($mimeType, $allowedMimeTypes, true)) {
            return [null, 'Tipul MIME al fiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierului nu este permis.'];
        }

        $uploadDir = BASE_PATH . '/uploads/curse_cheltuieli';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [null, 'Nu s-a putut crea folderul de upload pentru cheltuieli.'];
        }

        try {
            $storedName = 'cheltuiala_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            $storedName = 'cheltuiala_' . date('Ymd_His') . '_' . uniqid('', true);
        }

        if ($extension !== '') {
            $storedName .= '.' . $extension;
        }

        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $destination)) {
            return [null, 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul nu a putut fi salvat pe server.'];
        }

        return [[
            'file_path' => $storedName,
            'original_name' => $originalName,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'file_size' => $size,
        ], null];
    }

    private function deleteRaceFiles(int $raceId): void
    {
        $this->deleteExpensePhysicalFiles($this->model->getExpenseDocumentsByRaceId($raceId));
    }

    private function deleteExpensePhysicalFiles(array $documents): void
    {
        foreach ($documents as $document) {
            $filePath = (string) ($document['file_path'] ?? '');
            if ($filePath === '') {
                continue;
            }
            $this->deleteExpensePhysicalFile($filePath);
        }
    }

    private function deleteExpenseDocumentsByExpenseId(int $expenseId): void
    {
        $documents = $this->model->getExpenseDocumentsByExpenseId($expenseId);
        foreach ($documents as $document) {
            $filePath = (string) ($document['file_path'] ?? '');
            if ($filePath === '') {
                continue;
            }
            $this->deleteExpensePhysicalFile($filePath);
        }

        $this->model->clearExpenseDocuments($expenseId);
    }

    private function deleteExpensePhysicalFile(string $storedFile): void
    {
        $storedFile = basename(trim($storedFile));
        if ($storedFile === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/curse_cheltuieli/' . $storedFile;
        if (is_file($path)) {
            @unlink($path);
        }
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

    private function uploadErrorMessage(int $uploadError): string
    {
        return match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul este prea mare.',
            UPLOAD_ERR_PARTIAL => 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul a fost ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â®ncÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢rcat parÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âºial.',
            UPLOAD_ERR_NO_TMP_DIR => 'LipseÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢te folderul temporar pentru upload.',
            UPLOAD_ERR_CANT_WRITE => 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul nu a putut fi scris pe disc.',
            UPLOAD_ERR_EXTENSION => 'Upload-ul a fost blocat de configuraÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âºia serverului.',
            default => 'FiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â¹ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ierul nu a putut fi ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â®ncÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢rcat.',
        };
    }

    private function isValidDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }

        $dateTime = DateTime::createFromFormat('Y-m-d', $date);

        return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
    }

    private function normalizeRaceDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $date, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        return null;
    }

    private function normalizeTime(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            $time .= ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        $timeValue = DateTime::createFromFormat('H:i:s', $time);
        if ($timeValue === false) {
            return null;
        }

        return $timeValue->format('H:i:s') === $time ? $time : null;
    }

    private function computeRaceDurationMinutes(string $startDate, string $startTime, string $endDate, string $endTime): ?int
    {
        $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $startDate . ' ' . $startTime);
        $endDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $endDate . ' ' . $endTime);
        if ($startDateTime === false || $endDateTime === false) {
            return null;
        }

        $startTimestamp = $startDateTime->getTimestamp();
        $endTimestamp = $endDateTime->getTimestamp();
        if ($endTimestamp < $startTimestamp) {
            return null;
        }

        return (int) floor(($endTimestamp - $startTimestamp) / 60);
    }

    private function resolveDistributionTonRate(
        float $loadLocationTariff,
        float $zoneTariff,
        float $beneficiaryDistributionPerTon,
        bool $isSameDistributionRoute
    ): float {
        if ($isSameDistributionRoute) {
            if ($loadLocationTariff > 0) {
                return $loadLocationTariff;
            }

            if ($zoneTariff > 0) {
                return $zoneTariff;
            }
        } else {
            if ($zoneTariff > 0) {
                return $zoneTariff;
            }

            if ($loadLocationTariff > 0) {
                return $loadLocationTariff;
            }
        }

        return $beneficiaryDistributionPerTon > 0 ? $beneficiaryDistributionPerTon : 0.0;
    }

    private function isSameDistributionRoute(?array $loadLocation, ?array $zone): bool
    {
        $loadLocationName = $this->normalizeDistributionPointName((string) ($loadLocation['nume'] ?? ''));
        $distributionZoneName = $this->normalizeDistributionPointName((string) ($zone['nume'] ?? ''));

        return $loadLocationName !== ''
            && $distributionZoneName !== ''
            && $loadLocationName === $distributionZoneName;
    }

    private function normalizeDistributionPointName(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $transliterated = function_exists('iconv')
            ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized)
            : false;
        if (is_string($transliterated) && $transliterated !== '') {
            $normalized = strtolower($transliterated);
        }
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return trim((string) $normalized);
    }

    private function normalizeDecimal(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $value);
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function normalizeOperatingHours(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*(?:h|hr|hrs|ora|ore|hours?)?$/iu', $value, $matches) !== 1) {
            return null;
        }

        $normalized = str_replace(',', '.', (string) ($matches[1] ?? ''));
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * Normalizeaza cantitatea pentru calcule pe unitatea introdusa de operator.
     * Valorile sunt folosite direct (fara conversie automata tone -> kg).
     */
    private function normalizeTonInputToKgForPricing(?float $value, ?float $vehicleCapacityTon = null): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = (float) $value;
        return $normalized;
    }

    private function setFormFlash(string $key, array $old, array $errors): void
    {
        $_SESSION['_dispecer_form_' . $key] = [
            'old' => $old,
            'errors' => $errors,
        ];
    }

    private function consumeFormFlash(string $key): array
    {
        $sessionKey = '_dispecer_form_' . $key;
        $flash = $_SESSION[$sessionKey] ?? null;
        unset($_SESSION[$sessionKey]);

        if (!is_array($flash)) {
            return [
                'old' => [],
                'errors' => [],
            ];
        }

        return [
            'old' => is_array($flash['old'] ?? null) ? $flash['old'] : [],
            'errors' => is_array($flash['errors'] ?? null) ? $flash['errors'] : [],
        ];
    }

    private function setPostCreateExpensePrompt(int $raceId, string $mode = 'created'): void
    {
        $sessionKey = '_dispecer_post_create_expense_prompt';
        if ($raceId <= 0) {
            unset($_SESSION[$sessionKey]);
            return;
        }

        $normalizedMode = strtolower(trim($mode));
        if (!in_array($normalizedMode, ['created', 'updated'], true)) {
            $normalizedMode = 'created';
        }

        $_SESSION[$sessionKey] = [
            'race_id' => $raceId,
            'mode' => $normalizedMode,
        ];
    }

    private function consumePostCreateExpensePrompt(): ?array
    {
        $sessionKey = '_dispecer_post_create_expense_prompt';
        $prompt = $_SESSION[$sessionKey] ?? null;
        unset($_SESSION[$sessionKey]);

        if (!is_array($prompt)) {
            return null;
        }

        $raceId = (int) ($prompt['race_id'] ?? 0);
        if ($raceId <= 0) {
            return null;
        }

        return [
            'race_id' => $raceId,
            'mode' => (string) (($prompt['mode'] ?? '') === 'updated' ? 'updated' : 'created'),
        ];
    }

    private function queueMaintenancePopupAlerts(array $alerts): void
    {
        if ($alerts === [] || !is_admin()) {
            return;
        }

        $messages = [];
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $plate = trim((string) ($alert['nr_inmatriculare'] ?? ''));
            if ($plate === '') {
                continue;
            }

            $messages[] = sprintf(
                'Vehiculul %s a ajuns la 0 km pana la revizie. Este necesara programarea mentenantei.',
                $plate
            );
        }

        if ($messages === []) {
            return;
        }

        $existing = $_SESSION['_maintenance_popup_messages'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $_SESSION['_maintenance_popup_messages'] = array_values(array_unique(array_merge($existing, $messages)));
    }

    private function consumeMaintenancePopupMessages(): array
    {
        if (!is_admin()) {
            unset($_SESSION['_maintenance_popup_messages']);
            return [];
        }

        $messages = $_SESSION['_maintenance_popup_messages'] ?? [];
        unset($_SESSION['_maintenance_popup_messages']);

        if (!is_array($messages)) {
            return [];
        }

        $clean = [];
        foreach ($messages as $message) {
            $text = trim((string) $message);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return array_values(array_unique($clean));
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

    private function currentUserName(): string
    {
        $user = current_user() ?? [];
        $name = trim((string) ($user['nume'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($user['email'] ?? ''));
        return $email !== '' ? $email : 'Utilizator';
    }

    private function canReviewInactiveApprovals(): bool
    {
        if (function_exists('can')) {
            return can('inactive_approvals', 'review');
        }

        return function_exists('is_admin') && is_admin();
    }

    private function buildDuplicateRaceMessage(?int $duplicateRaceId = null): string
    {
        $suffix = $duplicateRaceId !== null && $duplicateRaceId > 0
            ? ' (ID ' . $duplicateRaceId . ')'
            : '';

        return 'Exista deja o cursa salvata cu aceleasi detalii' . $suffix . '. Verifica lista inainte sa o adaugi din nou.';
    }

    private function isDuplicateRacePersistenceError(Throwable $exception): bool
    {
        $sqlState = strtoupper((string) $exception->getCode());
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            && (
                str_contains($message, 'uk_curse_dispecer_duplicate_key')
                || str_contains($message, 'duplicate_key')
            );
    }

    private function buildPersistenceErrorMessage(Throwable $exception): string
    {
        $sqlState = strtoupper((string) $exception->getCode());
        $message = strtolower($exception->getMessage());

        if ($this->isDuplicateRacePersistenceError($exception)) {
            return $this->buildDuplicateRaceMessage();
        }

        if ($sqlState === '42S22' && (str_contains($message, 'km_bord') || str_contains($message, 'km_revizie'))) {
            return 'Structura bazei de date pentru campurile Km bord / Km revizie nu este actualizata. Ruleaza scripturile database/update_vehicle_camion_km.sql si database/update_vehicle_km_revizie.sql, apoi incearca din nou.';
        }

        if ($sqlState === '42S02'
            || $sqlState === '42S22'
            || str_contains($message, 'curse_dispecer')
            || str_contains($message, 'configurare_locuri_incarcare')
            || str_contains($message, 'configurare_locuri_incarcare_vehicule')
            || str_contains($message, 'configurare_zone_distributie')
            || str_contains($message, 'configurare_zone_distributie_vehicule')
            || str_contains($message, 'configurare_compresor_vehicule')
            || str_contains($message, 'configurare_rute_distributie')
            || str_contains($message, 'configurare_rute_primar')
            || str_contains($message, 'configurare_beneficiari_transport')
            || str_contains($message, 'suporta_compresor')
            || str_contains($message, 'pret_ora_aspirare')
            || str_contains($message, 'pret_km_dislocare')
            || str_contains($message, 'pret_tona_livrata')
            || str_contains($message, 'pret_tona_aspirata_lichida')
            || str_contains($message, 'pret_tona_aspirata_gazoasa')
            || str_contains($message, 'beneficiar_id')
            || str_contains($message, 'data_incarcare')
            || str_contains($message, 'loc_incarcare_id')
            || str_contains($message, 'loc_plecare')
            || str_contains($message, 'loc_aspirare')
            || str_contains($message, 'loc_livrare')
            || str_contains($message, 'loc_livrare_cursa')
            || str_contains($message, 'cost_extra_km')
            || str_contains($message, 'km_tarifare')
            || str_contains($message, 'cost_cursa')
            || str_contains($message, 'aplica_cost_cursa')
            || str_contains($message, 'vehicle_ids')
            || str_contains($message, 'capacitate_transport')
            || str_contains($message, 'cantitate_prelevata')
            || str_contains($message, 'tona_aspirata_lichida')
            || str_contains($message, 'tona_aspirata_gazoasa')
            || str_contains($message, 'ora_inceput')
            || str_contains($message, 'ora_sfarsit')
            || str_contains($message, 'durata_cursa_minute')
            || str_contains($message, 'driver_id')
            || str_contains($message, 'fk_curse_driver')
            || str_contains($message, 'created_by')
            || str_contains($message, 'fk_curse_created_by')
            || str_contains($message, 'soferi')
            || str_contains($message, 'utilizatori')
            || str_contains($message, 'km_totali')
            || str_contains($message, 'ore_functionare')
            || str_contains($message, 'cost_km_primar')
            || str_contains($message, 'cost_km_distributie')
            || str_contains($message, 'cost_km_mixt')
            || str_contains($message, 'cost_km_compresor')
            || str_contains($message, 'duplicate_key')
            || str_contains($message, 'tarif')) {
            return 'Structura bazei de date pentru Dispecer curse nu este actualizata. Ruleaza scripturile database/update_dispecer_curse_module.sql, database/update_dispecer_locuri_tarif.sql, database/update_dispecer_beneficiar_compresor.sql, database/update_dispecer_vehicle_default_assignments.sql, database/update_dispecer_curse_capacitate_transport.sql, database/update_dispecer_primar_routes.sql, database/update_dispecer_curse_cantitate_prelevata.sql, database/update_dispecer_curse_driver_id.sql, database/update_dispecer_curse_schedule.sql, database/update_dispecer_curse_data_incarcare.sql, database/update_dispecer_curse_ore_functionare.sql, database/update_dispecer_compresor_aspirare_split.sql, database/update_dispecer_compresor_locatii_text.sql, database/update_dispecer_distribution_route_km.sql, database/update_dispecer_curse_cost_km.sql si database/update_dispecer_curse_duplicate_guard.sql, apoi incearca din nou.';
        }

        return 'A aparut o eroare la salvare. Te rugam sa reincerci.';
    }
}
