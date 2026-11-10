<?php
declare(strict_types=1);

class MaintenanceController
{
    private PDO $db;
    private MaintenanceModel $model;
    private ModuleController $legacyController;

    public function __construct(PDO $db, array $modules)
    {
        $this->db = $db;
        $this->model = new MaintenanceModel($db);
        $this->legacyController = new ModuleController($db, $modules);
    }

    public function handle(string $action): void
    {
        if ($this->isLegacyAction($action)) {
            $this->legacyController->handle('mentenanta', $action);
            return;
        }

        switch ($action) {
            case 'index':
            case 'overview':
                $this->showSection('overview');
                return;
            case 'interventions':
                $this->showSection('interventions');
                return;
            case 'maintenance':
                $this->showSection('maintenance');
                return;
            case 'repairs':
                $this->showSection('repairs');
                return;
            case 'auto':
                $this->showAutoCatalog();
                return;
            case 'stock':
                $this->showSection('stock');
                return;
            case 'save_intervention':
                $this->saveIntervention();
                return;
            case 'delete_intervention':
                $this->deleteIntervention();
                return;
            case 'intervention_list':
                $this->interventionList();
                return;
            case 'intervention_detail':
                $this->interventionDetail();
                return;
            case 'intervention_vehicle_components':
                $this->interventionVehicleComponents();
                return;
            case 'intervention_update':
                $this->interventionUpdate();
                return;
            case 'intervention_item_save':
            case 'intervention_item_delete':
                $this->interventionItem($action);
                return;
            case 'intervention_finalize':
                $this->interventionFinalize();
                return;
            case 'intervention_document':
                $this->interventionDocument();
                return;
            case 'intervention_document_upload':
            case 'intervention_document_delete':
                $this->interventionDocumentChange($action);
                return;
            case 'save_record':
                $this->saveRecord();
                return;
            case 'delete_record':
                $this->deleteRecord();
                return;
            case 'delete_repair_invoice':
                $this->deleteRepairInvoice();
                return;
            case 'save_part':
                $this->savePart();
                return;
            case 'delete_part':
                $this->deletePart();
                return;
            case 'save_auto_component_config':
                $this->saveAutoComponentConfig();
                return;
            case 'auto_component_add':
            case 'auto_component_rename':
            case 'auto_component_remove':
            case 'auto_component_restore':
            case 'auto_component_type':
                $this->manageAutoComponent($action);
                return;
            case 'auto_taxonomy_manage':
                $this->manageAutoTaxonomy();
                return;
            case 'consumabile_op':
            case 'consumabile_container_save':
            case 'consumabile_product_save':
            case 'consumabile_compat_save':
            case 'consumabile_system_save':
            case 'consumabile_system_event':
            case 'consumabile_event_cancel':
            case 'consumabile_adblue_sync':
            case 'consumabile_alert_close':
                (new ConsumabileController($this->db))->handle($action);
                return;
            case 'consumabile_system_history':
                (new ConsumabileController($this->db))->systemHistory();
            case 'auto_structure_manage':
                $this->manageAutoStructure();
                return;
            case 'export':
            case 'export_v2':
                $this->export();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Acțiune inexistentă',
                    'currentPage' => 'mentenanta',
                ]);
        }
    }

    private function showAutoCatalog(): void
    {
        // Catalogul de componente (adaugare, redenumire, foto, garantie) ramane ecran separat.
        if (in_array((string) ($_GET['view'] ?? ''), ['catalog', 'components'], true) || isset($_GET['category_id'])) {
            render('maintenance/auto.php', [
                'pageTitle' => 'Catalog componente',
                'currentPage' => 'mentenanta',
                'catalog' => $this->getAutoCatalogData(),
            ]);
            return;
        }

        $data = $this->getAutoStructureData();
        render('maintenance/auto_structure.php', [
            'pageTitle' => 'Configurație vehicule',
            'currentPage' => 'mentenanta',
            'structurePage' => $data,
            'vehicleExplorer' => $data['explorer'],
        ]);
    }

    /** Pagina Auto: schema + configuratia unei configuratii generale sau a unui vehicul. */
    private function getAutoStructureData(): array
    {
        $service = new VehicleStructureService($this->db);
        $service->prepare();
        $structures = $service->structures();

        $typeOrder = array_flip(array_keys(VehicleStructureService::VEHICLE_TYPES));
        $vehicles = [];
        foreach ($this->model->getVehicles() as $row) {
            $type = VehicleStructureService::normalizeType((string) ($row['tip_vehicul'] ?? ''));
            if ($type === '' || $service->profile((int) $row['id']) === null) {
                continue;
            }
            $vehicles[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'type' => $type,
                'label' => trim((string) $row['nr_inmatriculare'] . ' - ' . (string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
                'plate' => (string) $row['nr_inmatriculare'],
            ];
        }
        uasort($vehicles, static fn (array $a, array $b): int => [$typeOrder[$a['type']], $a['plate']] <=> [$typeOrder[$b['type']], $b['plate']]);

        $requestedVehicle = max(0, (int) ($_GET['vehicle_id'] ?? 0));
        $selectedVehicle = $vehicles[$requestedVehicle] ?? null;
        $page = [
            'structures' => $structures,
            'vehicles' => array_values($vehicles),
            'can_manage' => function_exists('can') && can('mentenanta', 'auto_catalog'),
            'vehicle' => null,
            'structure' => null,
            'explorer' => null,
            'removed' => [],
            'compatible' => [],
            'partner' => null,
            'open' => (string) ($_GET['deschide'] ?? ''),
        ];

        if ($selectedVehicle !== null) {
            $effective = $service->effective($selectedVehicle['id']);
            if ($effective === null) {
                $selectedVehicle = null;
            }
        }

        if ($selectedVehicle !== null) {
            $profile = $service->profile($selectedVehicle['id']);
            $sections = $service->sections($profile['type'], $effective['systems'], $effective['components']);
            $page['vehicle'] = $selectedVehicle + ['axles' => $profile['axles'], 'assigned' => !empty($effective['structure']['assigned'])];
            $page['structure'] = $effective['structure'];
            $page['axle_columns'] = $service->axleColumns($profile['type'], $effective['axle_kinds'], true);
            $page['removed'] = $service->removedItems($effective);
            $page['compatible'] = array_values(array_filter($structures, static fn (array $item): bool => $item['type'] === $profile['type'] && $item['axles'] === $profile['axles']));
            $page['explorer'] = $this->legacyController->fleetExplorerData($selectedVehicle['id']);
            foreach ($page['explorer']['unit']['members'] ?? [] as $member) {
                if ((int) $member['vehicle_id'] !== $selectedVehicle['id']) {
                    $page['partner'] = ['id' => (int) $member['vehicle_id'], 'plate' => (string) $member['plate'], 'label' => (string) $member['role_label']];
                }
            }
        } else {
            $requested = (int) ($_GET['structura'] ?? 0);
            $ids = array_column($structures, 'id');
            if (!in_array($requested, $ids, true)) {
                $mostUsed = $structures;
                usort($mostUsed, static fn (array $a, array $b): int => $b['vehicles'] <=> $a['vehicles']);
                $requested = (int) ($mostUsed[0]['id'] ?? 0);
            }
            $structure = $requested > 0 ? $service->structure($requested) : null;
            if ($structure === null) {
                return $page + ['sections' => [], 'addable' => [], 'axle_columns' => []];
            }
            foreach ($structures as $item) {
                if ($item['id'] === $structure['id']) {
                    $structure['vehicles'] = $item['vehicles'];
                }
            }
            $components = array_map(static fn (array $component): array => $component + ['origin' => 'general'], $structure['components']);
            $sections = $service->sections($structure['type'], $structure['systems'], $components);
            $page['structure'] = $structure;
            $page['axle_columns'] = $service->axleColumns($structure['type'], $structure['axle_kinds'], false);
            $catalog = $service->catalog();
            $catalogMap = $service->catalogMap();
            $page['explorer'] = [
                'unit' => $service->templateUnit($structure),
                'categories' => VehicleComponentRegistry::clientCategories($catalogMap, $catalog),
            ];
        }

        return $page + $sections;
    }

    /** Modificarile din pagina Auto (configuratii generale si diferentele unui vehicul). */
    private function manageAutoStructure(): void
    {
        $this->requirePostAndCsrf('auto');
        $target = [];
        $service = new VehicleStructureService($this->db);
        try {
            $target = $service->apply($_POST);
            flash_set('success', $target['message']);
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
            $target = ['structura' => (int) ($_POST['structura'] ?? 0), 'vehicle_id' => (int) ($_POST['vehicle_id'] ?? 0)];
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][auto_structure_manage] ' . $exception->getMessage());
            flash_set('danger', 'Modificarea nu a putut fi salvată.');
            $target = ['structura' => (int) ($_POST['structura'] ?? 0), 'vehicle_id' => (int) ($_POST['vehicle_id'] ?? 0)];
        }

        $url = build_query_url([
            'page' => 'mentenanta',
            'action' => 'auto',
            'vehicle_id' => ($target['vehicle_id'] ?? 0) ?: null,
            'structura' => ($target['structura'] ?? 0) ?: null,
            'deschide' => ($target['open'] ?? '') ?: null,
        ]);
        redirect($url . (!empty($target['anchor']) ? '#' . $target['anchor'] : ''));
    }

    private function getAutoCatalogData(): array
    {
        $categories = $this->loadAutoCategoriesFromExcel();
        $this->model->syncAutoComponentsToStock($categories);
        $partIndex = $this->model->getAutoComponentPartIndex();
        $vehicles = $this->model->getVehicles();
        $vehicleOptions = [
            'camion' => 'Camion',
            'cap_tractor' => 'Cap tractor',
            'semiremorca' => 'Semiremorca',
            'ansamblu' => 'Ansamblu',
        ];
        $requestedVehicleType = $this->normalizeAutoVehicleType((string) ($_GET['vehicle_type'] ?? 'camion'));
        if (!isset($vehicleOptions[$requestedVehicleType])) {
            $requestedVehicleType = 'camion';
        }
        // Ramurile / subcategoriile vin din arborele administrat (mentenanta_auto_ramuri ...).
        $allowedPrimaries = AutoComponentCatalogService::primariesForVehicleType($requestedVehicleType);
        $taxonomy = AutoComponentCatalogService::taxonomy($this->db);
        $defaultPrimary = in_array('rezervor', $allowedPrimaries, true) ? 'rezervor' : ($allowedPrimaries[0] ?? 'sasiu');
        $requestedPrimary = (string) ($_GET['primary_category'] ?? $defaultPrimary);
        if (!in_array($requestedPrimary, $allowedPrimaries, true)) {
            $requestedPrimary = $defaultPrimary;
        }
        $primarySubs = array_values(array_filter(
            (array) ($taxonomy['primaries'][$requestedPrimary]['subcategories'] ?? []),
            static fn (string $sub): bool => !empty($taxonomy['subcategories'][$sub]['active'])
        ));
        $defaultSub = in_array('livrare_gaz', $primarySubs, true) ? 'livrare_gaz' : ($primarySubs[0] ?? 'sasiu');
        $requestedSubcategory = (string) ($_GET['subcategory'] ?? $defaultSub);
        if (!in_array($requestedSubcategory, $primarySubs, true)) {
            $requestedSubcategory = $defaultSub;
        }
        $vehicles = array_map(function (array $vehicleRow): array {
            $vehicleRow['auto_type'] = $this->normalizeAutoVehicleType((string) ($vehicleRow['tip_vehicul'] ?? ''));
            $vehicleRow['label'] = trim((string) ($vehicleRow['nr_inmatriculare'] ?? '') . ' - ' . (string) ($vehicleRow['marca'] ?? '') . ' ' . (string) ($vehicleRow['model'] ?? ''));
            return $vehicleRow;
        }, $vehicles);
        $selectableVehicles = array_values(array_filter(
            $vehicles,
            fn (array $vehicleRow): bool => $this->autoVehicleMatchesType($vehicleRow, $requestedVehicleType)
        ));
        $selectedVehicleId = max(0, (int) ($_GET['vehicle_id'] ?? 0));
        $selectedVehicle = null;
        foreach ($selectableVehicles as $vehicleRow) {
            if ($selectedVehicleId > 0 && (int) ($vehicleRow['id'] ?? 0) === $selectedVehicleId) {
                $selectedVehicle = $vehicleRow;
                break;
            }
        }
        if ($selectedVehicle === null && $selectableVehicles !== []) {
            $selectedVehicle = $selectableVehicles[0];
        }
        if ($selectedVehicle !== null) {
            $selectedVehicleId = (int) ($selectedVehicle['id'] ?? 0);
        } else {
            $selectedVehicleId = 0;
        }
        $autoConfigs = $this->model->getAutoComponentConfigs($selectedVehicleId);
        $autoUsage = $this->model->getAutoPartUsageForVehicle($selectedVehicleId);
        $selectedVehicleKm = is_array($selectedVehicle) ? (int) ($selectedVehicle['km_bord'] ?? 0) : 0;

        foreach ($categories as $categoryId => &$category) {
            foreach ($category['components'] as &$component) {
                $stockPart = $this->findAutoStockPart($partIndex, (string) $category['name'], (string) $component['name']);
                if ($stockPart !== null) {
                    $component['stock_part_id'] = (int) ($stockPart['id'] ?? 0);
                    $component['stock_code'] = (string) ($stockPart['cod_piesa'] ?? '');
                    $component['stock_description'] = (string) ($stockPart['descriere'] ?? '');
                    $component['photo_original'] = (string) ($stockPart['imagine_original'] ?? '');
                    $component['photo_url'] = $this->autoPartImageUrl((string) ($stockPart['imagine_stocata'] ?? ''));
                    $component['garantie_piesa'] = (string) ($stockPart['garantie_piesa'] ?? '');
                    $component['garantie_manopera'] = (string) ($stockPart['garantie_manopera'] ?? '');
                    $component['warranty_status'] = (string) ($stockPart['warranty_status'] ?? 'red');
                    $component['warranty_label'] = (string) ($stockPart['warranty_label'] ?? 'Fara garantie');
                    if (!empty($stockPart['interval_km'])) {
                        $component['interval'] = format_number_ro((float) $stockPart['interval_km'], 0);
                    }
                    if (!empty($stockPart['avertizare_km'])) {
                        $component['warning'] = format_number_ro((float) $stockPart['avertizare_km'], 0);
                    }
                }
                $config = $autoConfigs[(string) ($component['id'] ?? '')] ?? null;
                if (is_array($config)) {
                    $component['configured'] = true;
                    $component['inherited'] = !empty($config['inherited']);
                    $component['config_id'] = (int) ($config['id'] ?? 0);
                    $component['stock_part_id'] = (int) ($config['stock_part_id'] ?? ($component['stock_part_id'] ?? 0));
                    $component['code'] = trim((string) ($config['component_code'] ?? '')) !== ''
                        ? (string) $config['component_code']
                        : (string) ($component['code'] ?? '');
                    $component['description'] = trim((string) ($config['description'] ?? '')) !== ''
                        ? (string) $config['description']
                        : (string) ($component['description'] ?? '');
                    $component['monitoring_type'] = (string) ($config['monitoring_type'] ?? '');
                    $component['monitoring_methods'] = is_array($config['monitoring_methods'] ?? null) ? $config['monitoring_methods'] : [];
                    $component['interval'] = (string) ($config['interval_value'] ?? ($component['interval'] ?? ''));
                    $component['warning'] = (string) ($config['warning_value'] ?? ($component['warning'] ?? ''));
                    $component['critical'] = (string) ($config['critical_value'] ?? ($component['critical'] ?? ''));
                    $component['lifetime'] = (string) ($config['lifetime_value'] ?? ($component['lifetime'] ?? ''));
                    $component['unit'] = (string) ($config['unit'] ?? '');
                    $component['notes'] = (string) ($config['notes'] ?? ($component['notes'] ?? ''));
                    $component['repairable'] = (int) ($config['repairable'] ?? 1);
                    $component['repair_resets_lifetime'] = (int) ($config['repair_resets_lifetime'] ?? 0);
                    $component['requires_calibration'] = (int) ($config['requires_calibration'] ?? 0);
                    $component['config_created_at'] = (string) ($config['created_at'] ?? '');
                }
                $component['wear'] = $this->calculateAutoWearPercent($component, $selectedVehicleKm, $autoUsage);
            }
            unset($component);
            $category['count'] = count($category['components']);
        }
        unset($category);

        return [
            'active_path' => [
                'vehicle' => $requestedVehicleType,
                'vehicle_id' => $selectedVehicleId,
                'primary' => $requestedPrimary,
                'subcategory' => $requestedSubcategory,
                'category_id' => (int) ((AutoComponentCatalogService::categoryGroups()[$requestedSubcategory] ?? [])[0] ?? 0),
            ],
            'vehicle_types' => $vehicleOptions,
            'vehicles' => $selectableVehicles,
            'selected_vehicle' => $selectedVehicle,
            'category_groups' => AutoComponentCatalogService::categoryGroups(),
            'structure' => AutoComponentCatalogService::structurePayload(),
            'taxonomy' => $taxonomy,
            'categories' => $categories,
            'tree' => $this->getAutoVehicleTree($requestedVehicleType, $requestedPrimary, $requestedSubcategory),
            // Gestionarea componentelor (adaugare / redenumire / eliminare) cere dreptul auto_catalog.
            'can_manage_components' => function_exists('can') ? can('mentenanta', 'auto_catalog') : false,
            'removed_components' => array_map(
                fn (int $categoryId): array => (new AutoComponentCatalogService($this->db))->removedComponents($categoryId),
                array_combine(array_keys($categories), array_keys($categories))
            ),
        ];
    }

    /**
     * Arborele „Structura vehicul”: tip vehicul → ramuri (din arborele administrat) → subcategorii.
     * Ansamblu = cap tractor + semiremorca.
     */
    private function getAutoVehicleTree(string $vehicleType, string $primary, string $subcategory): array
    {
        $vehicleType = in_array($vehicleType, ['camion', 'cap_tractor', 'semiremorca', 'ansamblu'], true) ? $vehicleType : 'camion';
        $taxonomy = AutoComponentCatalogService::taxonomy($this->db);
        $branches = AutoComponentCatalogService::branchesByVehicleType();
        $categoryMeta = static function (array $ids): string {
            $ids = array_values(array_map('intval', $ids));
            if ($ids === []) {
                return 'Fără categorii';
            }
            if (count($ids) === 1) {
                return 'Categorie ' . $ids[0];
            }
            sort($ids);
            $contiguous = $ids === range($ids[0], $ids[count($ids) - 1]);

            return $contiguous ? 'Categorii ' . $ids[0] . '-' . $ids[count($ids) - 1] : count($ids) . ' categorii';
        };
        $buildVehicle = function (string $key, string $type, string $label, string $icon) use ($branches, $taxonomy, $primary, $subcategory, $categoryMeta): array {
            $children = [];
            foreach ($branches[$type] ?? [] as $branch) {
                $subs = [];
                foreach ($branch['subs'] as $sub) {
                    $node = $taxonomy['subcategories'][$sub] ?? null;
                    if ($node === null) {
                        continue;
                    }
                    $subs[] = [
                        'key' => $key . '_' . $branch['primary'] . '_' . $sub,
                        'label' => (string) $node['label'],
                        'icon' => (string) $node['icon'],
                        'meta' => $categoryMeta((array) $node['categories']),
                        'primary' => $branch['primary'],
                        'subcategory' => $sub,
                        'category_ids' => implode(',', array_map('intval', (array) $node['categories'])),
                        'active' => $primary === $branch['primary'] && $subcategory === $sub,
                    ];
                }
                $children[] = [
                    'key' => $key . '_' . $branch['primary'],
                    'label' => $branch['label'],
                    'icon' => $branch['icon'],
                    'expanded' => $primary === $branch['primary'],
                    'selected' => $primary === $branch['primary'],
                    'children' => $subs,
                ];
            }

            return ['key' => $key, 'label' => $label, 'icon' => $icon, 'expanded' => true, 'children' => $children];
        };

        return match ($vehicleType) {
            'cap_tractor' => [$buildVehicle('cap_tractor', 'cap_tractor', 'Cap tractor', 'bi-truck-front')],
            'semiremorca' => [$buildVehicle('semiremorca', 'semiremorca', 'Semiremorca', 'bi-truck-flatbed')],
            'ansamblu' => [[
                'key' => 'ansamblu',
                'label' => 'Ansamblu',
                'icon' => 'bi-diagram-3',
                'expanded' => true,
                'children' => [
                    $buildVehicle('ansamblu_cap_tractor', 'cap_tractor', 'Cap tractor', 'bi-truck-front'),
                    $buildVehicle('ansamblu_semiremorca', 'semiremorca', 'Semiremorca', 'bi-truck-flatbed'),
                ],
            ]],
            default => [$buildVehicle('camion', 'camion', 'Camion', 'bi-truck')],
        };
    }

    private function loadAutoCategoriesFromExcel(): array
    {
        return (new AutoComponentCatalogService($this->db))->categories();
    }

    private function findAutoStockPart(array $partIndex, string $categoryName, string $componentName): ?array
    {
        return AutoComponentStateService::findStockPart($partIndex, $categoryName, $componentName);
    }

    private function calculateAutoWearPercent(array $component, int $vehicleKm, array $usageByPart): ?int
    {
        return AutoComponentStateService::wearPercent($component, $vehicleKm, $usageByPart);
    }

    private function autoNumericValue(mixed $value): float
    {
        return AutoComponentStateService::numericValue($value);
    }

    private function autoLookupKey(string $value): string
    {
        return AutoComponentStateService::lookupKey($value);
    }

    private function autoPartImageUrl(string $stored): string
    {
        $stored = basename(str_replace('\\', '/', $stored));
        if ($stored === '') {
            return '';
        }

        return url('uploads/mentenanta_piese/' . rawurlencode($stored));
    }

    /**
     * Selectorul din „Adaugă piesă” (Stoc Piese): aceeași cale ca în Reparații Auto
     * (tip vehicul → vehicul → categorie principală → subcategorie → componentă),
     * fiecare componentă legată de piesa ei din stoc.
     */
    private function getStockCatalogPicker(array $vehicles): array
    {
        $categories = $this->loadAutoCategoriesFromExcel();
        $this->model->syncAutoComponentsToStock($categories);
        $partIndex = $this->model->getAutoComponentPartIndex();

        $pickerCategories = [];
        foreach ($categories as $categoryId => $category) {
            $components = [];
            foreach ((array) ($category['components'] ?? []) as $component) {
                $part = $this->findAutoStockPart($partIndex, (string) $category['name'], (string) $component['name']);
                $components[] = [
                    'key' => (string) ($component['id'] ?? ''),
                    'name' => (string) ($component['name'] ?? ''),
                    'code' => (string) ($part['cod_piesa'] ?? ($component['code'] ?? '')),
                    'description' => (string) ($part['descriere'] ?? ($component['description'] ?? '')),
                    'part' => $part === null ? null : [
                        'id' => (int) $part['id'],
                        'stoc_curent' => (float) ($part['stoc_curent'] ?? 0),
                        'stoc_minim' => (float) ($part['stoc_minim'] ?? 0),
                        'pret_achizitie' => (float) ($part['pret_achizitie'] ?? 0),
                        'unitate_masura' => (string) ($part['unitate_masura'] ?? 'buc'),
                        'producator' => (string) ($part['producator'] ?? ''),
                        'cod_oem' => (string) ($part['cod_oem'] ?? ''),
                        'furnizor' => (string) ($part['furnizor'] ?? ''),
                        'locatie_depozit' => (string) ($part['locatie_depozit'] ?? ''),
                        'garantie_piesa' => (string) ($part['garantie_piesa'] ?? ''),
                        'garantie_manopera' => (string) ($part['garantie_manopera'] ?? ''),
                        'interval_km' => $part['interval_km'] ?? null,
                        'interval_luni' => $part['interval_luni'] ?? null,
                        'avertizare_km' => $part['avertizare_km'] ?? null,
                        'avertizare_zile' => $part['avertizare_zile'] ?? null,
                    ],
                ];
            }
            $pickerCategories[(int) $categoryId] = [
                'id' => (int) $categoryId,
                'name' => (string) $category['name'],
                'components' => $components,
            ];
        }

        $pickerVehicles = [];
        foreach ($vehicles as $vehicleRow) {
            $autoType = $this->normalizeAutoVehicleType((string) ($vehicleRow['tip_vehicul'] ?? ''));
            if ($autoType === '') {
                continue;
            }
            $pickerVehicles[] = [
                'id' => (int) $vehicleRow['id'],
                'type' => $autoType,
                'label' => trim((string) ($vehicleRow['nr_inmatriculare'] ?? '') . ' - ' . (string) ($vehicleRow['marca'] ?? '') . ' ' . (string) ($vehicleRow['model'] ?? '')),
                'km' => (int) ($vehicleRow['km_bord'] ?? 0),
            ];
        }

        return [
            'vehicle_types' => ['camion' => 'Camion', 'cap_tractor' => 'Cap tractor', 'semiremorca' => 'Semiremorca', 'ansamblu' => 'Ansamblu'],
            'vehicles' => $pickerVehicles,
            'categories' => $pickerCategories,
        ] + AutoComponentCatalogService::structurePayload();
    }

    private function normalizeAutoVehicleType(string $type): string
    {
        return match ($type) {
            'camion' => 'camion',
            'cap_tractor' => 'cap_tractor',
            'semiremorca', 'semiremorca_primar', 'semiremorca_distributie' => 'semiremorca',
            'ansamblu' => 'ansamblu',
            default => '',
        };
    }

    private function autoVehicleMatchesType(array $vehicleRow, string $requestedType): bool
    {
        $autoType = (string) ($vehicleRow['auto_type'] ?? '');
        if ($requestedType === 'ansamblu') {
            return in_array($autoType, ['cap_tractor', 'semiremorca'], true);
        }

        return $autoType === $requestedType;
    }

    private function showSection(string $section): void
    {
        $filters = $this->collectFilters();
        $vehicles = $this->model->getVehicles();
        $drivers = $this->model->getDrivers();
        $overview = [];
        $records = [];
        $repairInvoices = [];
        $repairInvoiceDetails = [];
        $scheduledInterventions = [];
        $parts = [];
        $stockKpis = [];
        $stockFilterOptions = ['categories' => [], 'suppliers' => []];
        $stockCatalogPicker = null;
        // Stoc Piese → tab Consumabile: datele pieselor nu se mai incarca (alt continut).
        $stockTab = $section === 'stock' && (string) ($_GET['tab'] ?? '') === 'consumabile' ? 'consumabile' : 'piese';
        $consumabile = null;
        $technicalFormOptions = ['categories' => [], 'componentsByCategory' => []];
        if (class_exists('TechnicalHealthModel')) {
            try {
                $technicalFormOptions = (new TechnicalHealthModel($this->db))->getTechnicalFormOptions();
            } catch (Throwable $exception) {
                error_log('[MaintenanceController][technical_options] ' . $exception->getMessage());
            }
        }
        if ($section === 'overview') {
            $overview = $this->model->getOverview($filters);
        } elseif ($section === 'interventions') {
            $scheduledInterventions = $this->model->getScheduledInterventions($filters);
        } elseif ($section === 'maintenance') {
            $records = $this->model->getRecords($filters, 'intretinere');
        } elseif ($section === 'repairs') {
            $repairInvoices = $this->model->getCorrectiveInvoices($filters);
            $repairInvoiceDetails = $this->model->getCorrectiveInvoiceDetails(array_column($repairInvoices, 'id'));
        } elseif ($section === 'stock' && $stockTab === 'consumabile') {
            $consumabile = (new ConsumabileController($this->db))->viewData();
        } elseif ($section === 'stock') {
            $parts = array_map(function (array $part): array {
                $part['image_url'] = $this->autoPartImageUrl((string) ($part['imagine_stocata'] ?? ''));
                return $part;
            }, $this->model->getStockParts(array_merge($filters, ['search' => ''])));
            // Cautarea text se aplica in browser, pe tot ce se vede in rand (inclusiv locatia), pe masura ce tastezi.
            $stockKpis = $this->model->getStockKpis();
            $stockFilterOptions = $this->model->getStockFilterOptions();
            $scheduledInterventions = $this->model->getScheduledInterventions();
            try {
                $stockCatalogPicker = $this->getStockCatalogPicker($vehicles);
            } catch (Throwable $exception) {
                error_log('[MaintenanceController][stock_catalog_picker] ' . $exception->getMessage());
            }
        }

        $editRecord = null;
        $viewRecord = null;
        $editIntervention = null;
        $recordId = (int) ($_GET['edit_id'] ?? 0);
        if ($recordId > 0 && in_array($section, ['maintenance', 'repairs'], true)) {
            $editRecord = $this->model->getRecord($recordId);
        }
        $viewId = (int) ($_GET['view_id'] ?? 0);
        if ($viewId > 0) {
            $viewRecord = $this->model->getRecord($viewId);
        }
        $interventionId = (int) ($_GET['edit_intervention_id'] ?? 0);
        if ($interventionId > 0 && $section === 'interventions') {
            $editIntervention = $this->model->getScheduledIntervention($interventionId);
            if ($editIntervention !== null) {
                $editIntervention['lucrari'] = $this->workflow()->items($interventionId);
            }
        }
        // Formularul de interventie (toate sectiunile in afara de Stoc) si pagina Interventii V2.
        $interventionBoot = null;
        if ($section !== 'stock') {
            $workflow = $this->workflow();
            $interventionBoot = [
                'coordinators' => $workflow->getCoordinators(),
                'catalog' => $workflow->componentCatalog(),
                'can' => $this->interventionPermissions(),
            ];
            if ($section === 'interventions') {
                $listFilters = $this->collectInterventionFilters($_GET);
                $interventionBoot['filters'] = $listFilters;
                $interventionBoot['list'] = $workflow->listInterventions($listFilters, max(1, (int) ($_GET['p'] ?? 1)), (int) ($_GET['per_page'] ?? 10));
                $interventionBoot['open_id'] = max(0, (int) ($_GET['intervention_id'] ?? 0));
            }
        }

        render('maintenance/index.php', [
            'pageTitle' => match ($section) {
                'stock' => 'Stoc Piese',
                'repairs' => 'Reparații corective',
                default => 'Mentenanță',
            },
            'currentPage' => 'mentenanta',
            'section' => $section,
            'filters' => $filters,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'costCenters' => $this->model->getCostCenterNames(),
            'costCenterOptions' => $this->model->getCostCenterOptions(),
            'vehicleTypeLabels' => MaintenanceModel::VEHICLE_TYPE_LABELS,
            'overview' => $overview,
            'records' => $records,
            'repairInvoices' => $repairInvoices,
            'repairInvoiceDetails' => $repairInvoiceDetails,
            'scheduledInterventions' => $scheduledInterventions,
            'parts' => $parts,
            'availableStockParts' => $stockTab === 'consumabile' ? [] : $this->model->getStockParts(['stock_status' => 'in_stock', 'include_tires' => false]),
            'stockTab' => $stockTab,
            'consumabile' => $consumabile,
            'stockKpis' => $stockKpis,
            'stockFilterOptions' => $stockFilterOptions,
            'stockCatalogPicker' => $stockCatalogPicker,
            'technicalFormOptions' => $technicalFormOptions,
            'editRecord' => $editRecord,
            'viewRecord' => $viewRecord,
            'editIntervention' => $editIntervention,
            'interventionBoot' => $interventionBoot,
        ]);
    }

    // ------------------------------------------------------------------ Interventii V2

    private ?InterventionWorkflowModel $workflowModel = null;

    private function workflow(): InterventionWorkflowModel
    {
        return $this->workflowModel ??= new InterventionWorkflowModel($this->db);
    }

    private function interventionPermissions(): array
    {
        return [
            'edit' => can('mentenanta', 'interventions'),
            'technical' => can('mentenanta', 'interventions_technical'),
            'costs' => can('mentenanta', 'interventions_costs'),
            'export' => can('mentenanta', 'export'),
        ];
    }

    private function collectInterventionFilters(array $source): array
    {
        $status = (string) ($source['status'] ?? '');
        $responsible = (string) ($source['responsabil'] ?? '');
        return [
            'search' => mb_substr(trim((string) ($source['search'] ?? '')), 0, 120),
            'vehicle_id' => max(0, (int) ($source['vehicle_id'] ?? 0)),
            'tip' => in_array(($source['tip'] ?? ''), ['intretinere', 'reparatie'], true) ? (string) $source['tip'] : '',
            'status' => isset(InterventionWorkflowModel::STATUS_LABELS[$status]) ? $status : '',
            'date_from' => $this->isDate((string) ($source['date_from'] ?? '')) ? (string) $source['date_from'] : '',
            'date_to' => $this->isDate((string) ($source['date_to'] ?? '')) ? (string) $source['date_to'] : '',
            'responsabil' => $responsible === 'nealocat' ? 'nealocat' : ((int) $responsible > 0 ? (string) (int) $responsible : ''),
            'sursa' => in_array(($source['sursa'] ?? ''), ['km_service', 'manual'], true) ? (string) $source['sursa'] : '',
        ];
    }

    private function interventionList(): void
    {
        $filters = $this->collectInterventionFilters($_GET);
        $this->json(['ok' => true] + $this->workflow()->listInterventions($filters, max(1, (int) ($_GET['p'] ?? 1)), (int) ($_GET['per_page'] ?? 10)));
    }

    private function interventionDetail(): void
    {
        $detail = $this->workflow()->detail((int) ($_GET['id'] ?? 0), can('mentenanta', 'interventions_costs'));
        if ($detail === null) {
            $this->json(['ok' => false, 'message' => 'Intervenția nu mai există.'], 404);
        }
        $this->json(['ok' => true, 'can' => $this->interventionPermissions()] + $detail);
    }

    private function interventionVehicleComponents(): void
    {
        $vehicleId = max(0, (int) ($_GET['vehicle_id'] ?? 0));
        $this->json(['ok' => true, 'configured' => $this->workflow()->configuredComponentKeys($vehicleId)]);
    }

    private function interventionUpdate(): void
    {
        $this->requireJsonPost();
        $id = (int) ($_POST['id'] ?? 0);
        $allowed = ['data_programata', 'ora_programata', 'furnizor', 'prioritate', 'tip_interventie', 'disponibilitate_vehicul',
            'responsabil_user_id', 'diagnostic_tehnic', 'status', 'cost_estimat', 'cost_suplimentar'];
        $fields = array_intersect_key($_POST, array_flip($allowed));
        $canCosts = can('mentenanta', 'interventions_costs');
        if (!$canCosts && (isset($fields['cost_estimat']) || isset($fields['cost_suplimentar']))) {
            $this->json(['ok' => false, 'message' => 'Nu ai dreptul să modifici costurile intervenției.'], 403);
        }
        foreach (['cost_estimat', 'cost_suplimentar'] as $costField) {
            if (isset($fields[$costField])) {
                $fields[$costField] = $this->decimal($fields[$costField]);
            }
        }
        $this->runJson(function () use ($id, $fields, $canCosts): array {
            $changes = $this->workflow()->updateFromPanel($id, $fields, $this->userId(), $canCosts);
            if ($changes !== []) {
                audit_log_write($this->db, 'mentenanta_interventii', $id, 'update', 'Intervenție #' . $id . ' actualizată din panou', null, $changes, $this->userId());
            }
            return ['changed' => array_keys($changes)];
        }, $id);
    }

    private function interventionItem(string $action): void
    {
        $this->requireJsonPost();
        $id = (int) ($_POST['intervention_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $canCosts = can('mentenanta', 'interventions_costs');
        $this->runJson(function () use ($action, $id, $itemId, $canCosts): array {
            $workflow = $this->workflow();
            if ($action === 'intervention_item_delete') {
                $workflow->deleteItem($id, $itemId, $this->userId());
                return [];
            }
            // Mai multe componente din registru intr-o singura cerere ("Selecteaza din registru").
            $batch = json_decode((string) ($_POST['components_json'] ?? ''), true);
            if (is_array($batch) && $batch !== [] && $itemId <= 0) {
                $added = 0;
                foreach (array_slice($batch, 0, 50) as $component) {
                    if (!is_array($component)) {
                        continue;
                    }
                    $workflow->saveItem($id, 0, [
                        'component_key' => (string) ($component['key'] ?? ''),
                        'ramura_principala' => (string) ($component['primary'] ?? ''),
                        'pozitie' => (string) ($component['position'] ?? ''),
                        'operatiune' => (string) ($component['operation'] ?? 'verificare'),
                        'stare_evaluare' => 'suspectata',
                    ], $this->userId(), $canCosts);
                    $added++;
                }
                return ['added' => $added];
            }
            $fields = array_intersect_key($_POST, array_flip([
                'component_key', 'ramura_principala', 'denumire_libera', 'pozitie', 'stare_evaluare', 'operatiune', 'diagnostic',
                'progres', 'rezultat', 'piesa_montata', 'piesa_demontata', 'data_montare', 'km_montare', 'cost_piese',
                'cost_manopera', 'garantie_luni', 'garantie_pana_la', 'observatii',
            ]));
            foreach (['cost_piese', 'cost_manopera'] as $costField) {
                if (isset($fields[$costField])) {
                    $fields[$costField] = $this->decimal($fields[$costField]);
                }
            }
            return ['item_id' => $workflow->saveItem($id, $itemId, $fields, $this->userId(), $canCosts)];
        }, $id);
    }

    private function interventionFinalize(): void
    {
        $this->requireJsonPost();
        $id = (int) ($_POST['id'] ?? 0);
        $outcomes = json_decode((string) ($_POST['outcomes_json'] ?? '[]'), true);
        $outcomes = is_array($outcomes) ? $outcomes : [];
        $normalized = [];
        foreach ($outcomes as $itemId => $outcome) {
            if (is_array($outcome)) {
                foreach (['cost_piese', 'cost_manopera'] as $costField) {
                    if (isset($outcome[$costField])) {
                        $outcome[$costField] = $this->decimal($outcome[$costField]);
                    }
                }
                $normalized[(int) $itemId] = $outcome;
            }
        }
        $options = [
            'data_executie' => (string) ($_POST['data_executie'] ?? ''),
            'km' => (string) ($_POST['km'] ?? ''),
            'creeaza_continuare' => !empty($_POST['creeaza_continuare']),
            'data_continuare' => (string) ($_POST['data_continuare'] ?? ''),
        ];
        $this->runJson(function () use ($id, $normalized, $options): array {
            $result = $this->workflow()->finalize($id, $normalized, $options, $this->userId(), can('mentenanta', 'interventions_costs'), $this->model);
            if (!$result['already']) {
                audit_log_write($this->db, 'mentenanta_interventii', $id, 'finalize', 'Intervenție #' . $id . ' finalizată', null, $result, $this->userId());
            }
            return $result;
        }, $id);
    }

    private function interventionDocument(): void
    {
        $source = ($_GET['sursa'] ?? '') === 'inregistrare' ? 'inregistrare' : 'interventie';
        $file = $this->workflow()->documentFile((int) ($_GET['id'] ?? 0), $source);
        $stored = $file !== null ? basename(str_replace('\\', '/', (string) $file['stored'])) : '';
        $path = $stored !== '' ? BASE_PATH . '/uploads/' . $file['dir'] . '/' . $stored : '';
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            exit('Documentul nu mai există.');
        }
        $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $inline = isset($types[$extension]) && empty($_GET['download']);
        header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $file['original']) . '"');
        readfile($path);
        exit;
    }

    private function interventionDocumentChange(string $action): void
    {
        $this->requireJsonPost();
        $id = (int) ($_POST['intervention_id'] ?? 0);
        $this->runJson(function () use ($action, $id): array {
            $workflow = $this->workflow();
            if ($action === 'intervention_document_delete') {
                $file = $workflow->deleteDocument((int) ($_POST['document_id'] ?? 0), $this->userId());
                if ($file === null) {
                    throw new InvalidArgumentException('Documentul nu mai există.');
                }
                $path = BASE_PATH . '/uploads/mentenanta_interventii/' . basename((string) $file['stored']);
                if (is_file($path)) {
                    @unlink($path);
                }
                return [];
            }
            if ($workflow->getIntervention($id) === null) {
                throw new InvalidArgumentException('Intervenția nu mai există.');
            }
            $upload = $_FILES['document'] ?? null;
            try {
                $stored = $this->storeUpload($upload, 'interventie_' . $id, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'], 'mentenanta_interventii');
            } catch (RuntimeException $exception) {
                throw new InvalidArgumentException($exception->getMessage()); // tip / marime nepermise: mesajul ajunge la utilizator
            }
            if ($stored === []) {
                throw new InvalidArgumentException('Alege fișierul de încărcat.');
            }
            return ['document_id' => $workflow->addDocument($id, (string) ($_POST['tip'] ?? 'altul'), $stored, (int) ($upload['size'] ?? 0), $this->userId())];
        }, $id);
    }

    /** Ruleaza o modificare si raspunde cu detaliile proaspete ale interventiei. */
    private function runJson(callable $operation, int $interventionId): never
    {
        try {
            $result = $operation();
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][interventions_v2] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Modificarea nu a putut fi salvată. Reîncearcă.'], 500);
        }
        $detail = $interventionId > 0 ? $this->workflow()->detail($interventionId, can('mentenanta', 'interventions_costs')) : null;
        $this->json(['ok' => true, 'result' => $result, 'can' => $this->interventionPermissions()] + ($detail ?? []));
    }

    private function requireJsonPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina și reîncearcă.'], 419);
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function userId(): ?int
    {
        $id = (int) (current_user()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function saveIntervention(): void
    {
        $this->requirePostAndCsrf('interventions');
        $id = (int) ($_POST['id'] ?? 0);
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $date = trim((string) ($_POST['data_programata'] ?? ''));
        $description = trim((string) ($_POST['descriere'] ?? ''));
        if ($vehicleId <= 0 || !$this->isDate($date) || $description === '') {
            flash_set('danger', 'Completează vehiculul, data programată și descrierea intervenției.');
            $this->redirectSection('interventions');
        }

        // Formularul V2 trimite cheia de afisare (status); cel vechi trimitea status_interventie salvat.
        $statusKey = trim((string) ($_POST['status'] ?? ''));
        if ($statusKey === '' && isset($_POST['status_interventie'])) {
            $statusKey = InterventionWorkflowModel::statusKey((string) $_POST['status_interventie']);
        }
        $components = json_decode((string) ($_POST['components_json'] ?? '[]'), true);
        $canCosts = can('mentenanta', 'interventions_costs');
        $existing = $id > 0 ? $this->workflow()->getIntervention($id) : null;

        try {
            $savedId = $this->workflow()->saveFromForm([
                'vehicle_id' => $vehicleId,
                'tip_interventie' => (string) ($_POST['tip_interventie'] ?? 'intretinere'),
                'prioritate' => (string) ($_POST['prioritate'] ?? 'normala'),
                'data_programata' => $date,
                'ora_programata' => (string) ($_POST['ora_programata'] ?? ''),
                // Fara dreptul de costuri, costul estimat existent ramane neatins.
                'cost_estimat' => $canCosts ? $this->decimal($_POST['cost_estimat'] ?? 0) : (float) ($existing['cost_estimat'] ?? 0),
                'furnizor' => (string) ($_POST['furnizor'] ?? ''),
                'driver_id' => (int) ($_POST['driver_id'] ?? 0),
                'responsabil_user_id' => (int) ($_POST['responsabil_user_id'] ?? 0),
                'client' => (string) ($_POST['client'] ?? ''),
                'centru_cost' => (string) ($_POST['centru_cost'] ?? ''),
                'descriere' => $description,
                'status' => $statusKey,
            ], $id, is_array($components) ? array_slice(array_values(array_filter($components, 'is_array')), 0, 50) : [], $this->userId());
            audit_log_write($this->db, 'mentenanta_interventii', $savedId, $id > 0 ? 'update' : 'create',
                ($id > 0 ? 'Intervenție actualizată' : 'Intervenție creată') . ' #' . $savedId, $existing, $this->workflow()->getIntervention($savedId), $this->userId());
            flash_set('success', $id > 0 ? 'Intervenția #' . $savedId . ' a fost actualizată.' : 'Intervenția #' . $savedId . ' a fost înregistrată.');
            redirect(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'intervention_id' => $savedId]));
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][save_intervention] ' . $exception->getMessage());
            flash_set('danger', 'Intervenția nu a putut fi salvată: ' . $exception->getMessage());
        }
        redirect(build_query_url(array_filter([
            'page' => 'mentenanta',
            'action' => 'interventions',
            'edit_intervention_id' => $id > 0 ? $id : null,
        ])));
    }

    private function deleteIntervention(): void
    {
        $this->requirePostAndCsrf('interventions');
        try {
            $deletedId = (int) ($_POST['id'] ?? 0);
            $before = $this->workflow()->getIntervention($deletedId);
            $deleted = $this->model->deleteScheduledIntervention($deletedId);
            if ($deleted) {
                audit_log_write($this->db, 'mentenanta_interventii', $deletedId, 'delete', 'Intervenție #' . $deletedId . ' ștearsă', $before, null, $this->userId());
            }
            flash_set($deleted ? 'success' : 'warning', $deleted
                ? 'Intervenția programată a fost ștearsă.'
                : 'Intervenția finalizată nu poate fi ștearsă din planificare. Înregistrarea finală a fost păstrată.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][delete_intervention] ' . $exception->getMessage());
            flash_set('danger', 'Intervenția nu a putut fi ștearsă.');
        }
        $this->redirectSection('interventions');
    }

    private function saveRecord(): void
    {
        $recordType = ($_POST['record_type'] ?? 'intretinere') === 'reparatie' ? 'reparatie' : 'intretinere';
        $section = $recordType === 'reparatie' ? 'repairs' : 'maintenance';
        $this->requirePostAndCsrf($section);

        $id = (int) ($_POST['id'] ?? 0);
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $date = trim((string) ($_POST['data_interventie'] ?? ''));
        $type = trim((string) ($_POST['tip_interventie'] ?? ''));
        if ($vehicleId <= 0 || !$this->isDate($date) || $type === '') {
            flash_set('danger', 'Completează vehiculul, data și tipul intervenției.');
            $this->redirectSection($section);
        }

        $invoice = $this->storeUpload($_FILES['invoice_upload'] ?? null, 'factura');
        try {
            $recordId = $this->model->saveRecord([
                'vehicle_id' => $vehicleId,
                'tip_interventie' => $type,
                'record_type' => $recordType,
                'centru_cost' => (string) ($_POST['centru_cost'] ?? 'Altele'),
                'technical_category_id' => (int) ($_POST['technical_category_id'] ?? 0),
                'technical_component_id' => (int) ($_POST['technical_component_id'] ?? 0),
                'technical_health_percent' => (string) ($_POST['technical_health_percent'] ?? ''),
                'descriere' => (string) ($_POST['descriere'] ?? ''),
                'status_interventie' => (string) ($_POST['status_interventie'] ?? 'finalizata'),
                'data_interventie' => $date,
                'km_interventie' => (string) ($_POST['km_interventie'] ?? ''),
                'cost' => $this->decimal($_POST['cost'] ?? 0),
                'cost_manopera' => $this->decimal($_POST['cost_manopera'] ?? 0),
                'cost_piese' => $this->decimal($_POST['cost_piese'] ?? 0),
                'zile_imobilizare' => $this->decimal($_POST['zile_imobilizare'] ?? 0),
                'atelier' => (string) ($_POST['atelier'] ?? ''),
                'furnizor_piesa' => (string) ($_POST['furnizor_piesa'] ?? ''),
                'piese_utilizate' => (string) ($_POST['piese_utilizate'] ?? ''),
                'fisier_original' => $invoice['original'] ?? '',
                'fisier_stocat' => $invoice['stored'] ?? '',
                'observatii' => (string) ($_POST['observatii'] ?? ''),
                'stock_part_id' => (int) ($_POST['stock_part_id'] ?? 0),
                'stock_part_quantity' => $this->decimal($_POST['stock_part_quantity'] ?? 0),
            ], $id);
            if (class_exists('TechnicalHealthModel')) {
                try {
                    (new TechnicalHealthModel($this->db))->applyMaintenanceRecord([
                        'vehicle_id' => $vehicleId,
                        'technical_category_id' => (int) ($_POST['technical_category_id'] ?? 0),
                        'technical_component_id' => (int) ($_POST['technical_component_id'] ?? 0),
                        'technical_health_percent' => (string) ($_POST['technical_health_percent'] ?? ''),
                        'data_interventie' => $date,
                        'observatii' => (string) ($_POST['observatii'] ?? ''),
                    ], $recordId);
                } catch (Throwable $exception) {
                    error_log('[MaintenanceController][technical_sync] ' . $exception->getMessage());
                }
            }
            flash_set('success', $id > 0 ? 'Înregistrarea a fost actualizată.' : 'Intervenția finală a fost adăugată.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][save_record] ' . $exception->getMessage());
            flash_set('danger', 'Înregistrarea nu a putut fi salvată: ' . $exception->getMessage());
        }
        $this->redirectSection($section);
    }

    private function deleteRecord(): void
    {
        $section = ($_POST['section'] ?? 'maintenance') === 'repairs' ? 'repairs' : 'maintenance';
        $this->requirePostAndCsrf($section);
        try {
            $deleted = $this->model->deleteRecord((int) ($_POST['id'] ?? 0));
            flash_set($deleted ? 'success' : 'warning', $deleted ? 'Înregistrarea a fost ștearsă.' : 'Înregistrarea nu mai există.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][delete_record] ' . $exception->getMessage());
            flash_set('danger', 'Înregistrarea nu a putut fi ștearsă.');
        }
        $this->redirectSection($section);
    }

    private function deleteRepairInvoice(): void
    {
        $this->requirePostAndCsrf('repairs');
        try {
            $deleted = $this->model->deleteCorrectiveInvoice((int) ($_POST['id'] ?? 0));
            flash_set($deleted ? 'success' : 'warning', $deleted ? 'Factura de reparații a fost ștearsă.' : 'Factura nu mai există.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][delete_repair_invoice] ' . $exception->getMessage());
            flash_set('danger', 'Factura nu a putut fi ștearsă.');
        }
        $this->redirectSection('repairs');
    }

    private function deletePart(): void
    {
        $this->requirePostAndCsrf('stock');
        try {
            $deleted = $this->model->archivePart((int) ($_POST['id'] ?? 0));
            flash_set($deleted ? 'success' : 'warning', $deleted ? 'Piesa a fost ștearsă din stoc.' : 'Piesa nu mai există în stoc.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][delete_part] ' . $exception->getMessage());
            flash_set('danger', 'Piesa nu a putut fi ștearsă.');
        }
        $this->redirectSection('stock');
    }

    private function savePart(): void
    {
        $this->requirePostAndCsrf('stock');
        $code = trim((string) ($_POST['cod_piesa'] ?? ''));
        $name = trim((string) ($_POST['denumire'] ?? ''));
        $category = trim((string) ($_POST['categorie'] ?? ''));
        if ($code === '' || $name === '' || $category === '') {
            flash_set('danger', 'Codul, denumirea și categoria piesei sunt obligatorii.');
            $this->redirectSection('stock');
        }

        try {
            $documents = [
                'invoice' => $this->storeUpload($_FILES['invoice_document'] ?? null, 'piesa_factura'),
                'technical' => $this->storeUpload($_FILES['technical_document'] ?? null, 'piesa_fisa'),
                'image' => $this->storeUpload($_FILES['part_image'] ?? null, 'piesa_imagine', ['jpg', 'jpeg', 'png', 'webp']),
            ];
            $this->model->savePart($_POST, $documents, (int) (current_user()['id'] ?? 0));
            flash_set('success', ($_POST['usage_destination'] ?? 'stock') === 'direct'
                ? 'Piesa a fost montată direct și costul a fost atribuit vehiculului selectat.'
                : 'Piesa a fost adăugată în stoc.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][save_part] ' . $exception->getMessage());
            $message = str_contains(strtolower($exception->getMessage()), 'duplicate')
                ? 'Există deja o piesă cu acest cod.'
                : $exception->getMessage();
            flash_set('danger', 'Piesa nu a putut fi salvată: ' . $message);
        }
        $this->redirectSection('stock');
    }

    private function saveAutoComponentConfig(): void
    {
        $this->requirePostAndCsrf('auto');
        try {
            $image = $this->storeUpload($_FILES['component_photo'] ?? null, 'componenta_imagine', ['jpg', 'jpeg', 'png', 'webp']);
            $this->model->saveAutoComponentConfig($_POST, $image);
            flash_set('success', 'Configuratia componentei a fost salvata pentru vehiculul selectat.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][save_auto_component_config] ' . $exception->getMessage());
            flash_set('danger', 'Configuratia nu a putut fi salvata: ' . $exception->getMessage());
        }

        redirect(build_query_url([
            'page' => 'mentenanta',
            'action' => 'auto',
            'vehicle_id' => max(0, (int) ($_POST['vehicle_id'] ?? 0)),
            'vehicle_type' => $this->normalizeAutoVehicleType((string) ($_POST['vehicle_type'] ?? 'camion')),
            'primary_category' => trim((string) ($_POST['primary_category'] ?? 'rezervor')),
            'subcategory' => trim((string) ($_POST['subcategory'] ?? 'livrare_gaz')),
            'view' => 'components',
            'category_id' => max(0, (int) ($_POST['category_id'] ?? 0)),
            'component_id' => trim((string) ($_POST['component_id'] ?? '')),
        ]));
    }

    /**
     * Componentele unei categorii: adaugare / redenumire / eliminare (logica) / restaurare.
     * Componenta noua primeste piesa ei din stoc la urmatoarea incarcare a catalogului.
     */
    private function manageAutoComponent(string $action): void
    {
        $this->requirePostAndCsrf('auto');
        $catalog = new AutoComponentCatalogService($this->db);
        $key = trim((string) ($_POST['component_key'] ?? ''));
        $categoryId = max(0, (int) ($_POST['category_id'] ?? 0));
        $selectComponent = $key;
        try {
            switch ($action) {
                case 'auto_component_add':
                    $added = $catalog->addComponent($categoryId, (string) ($_POST['name'] ?? ''), (string) ($_POST['details'] ?? ''));
                    $this->model->syncAutoComponentsToStock($catalog->categories());
                    $selectComponent = $added['key'];
                    flash_set('success', 'Componenta „' . trim((string) $_POST['name']) . '” a fost adăugată (cod ' . $added['code'] . ').');
                    break;
                case 'auto_component_rename':
                    $catalog->renameComponent($key, (string) ($_POST['name'] ?? ''));
                    flash_set('success', 'Componenta a fost redenumită.');
                    break;
                case 'auto_component_remove':
                    $usage = $catalog->usage($key);
                    $catalog->setActive($key, false);
                    $selectComponent = '';
                    flash_set('success', 'Componenta a fost eliminată din listă.'
                        . ($usage['configurari'] + $usage['montari'] > 0
                            ? ' Istoricul ei (' . $usage['configurari'] . ' configurări, ' . $usage['montari'] . ' montări) rămâne; o poți restaura oricând.'
                            : ''));
                    break;
                case 'auto_component_restore':
                    $catalog->setActive($key, true);
                    flash_set('success', 'Componenta a fost restaurată.');
                    break;
                case 'auto_component_type':
                    $catalog->setItemType($key, trim((string) ($_POST['item_type'] ?? '')));
                    audit_log_write($this->db, 'mentenanta_auto_componente', 0, 'update', 'Tip articol ' . $key . ' → ' . (string) ($_POST['item_type'] ?? ''), null, null, $this->userId());
                    flash_set('success', 'Tipul articolului a fost actualizat.');
                    break;
            }
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][' . $action . '] ' . $exception->getMessage());
            flash_set('danger', 'Modificarea componentei nu a putut fi salvată.');
        }

        redirect(build_query_url([
            'page' => 'mentenanta',
            'action' => 'auto',
            'vehicle_id' => max(0, (int) ($_POST['vehicle_id'] ?? 0)) ?: null,
            'vehicle_type' => $this->normalizeAutoVehicleType((string) ($_POST['vehicle_type'] ?? 'camion')) ?: 'camion',
            'primary_category' => trim((string) ($_POST['primary_category'] ?? 'sasiu')),
            'subcategory' => trim((string) ($_POST['subcategory'] ?? 'sasiu')),
            'view' => 'components',
            'category_id' => $categoryId,
            'component_id' => $selectComponent !== '' ? $selectComponent : null,
        ]));
    }

    /** Gestionarea arborelui (ramuri / subcategorii / categorii) din Catalog componente. */
    private function manageAutoTaxonomy(): void
    {
        $this->requirePostAndCsrf('auto');
        $catalog = new AutoComponentCatalogService($this->db);
        $op = (string) ($_POST['op'] ?? '');
        $level = (string) ($_POST['level'] ?? '');
        $key = trim((string) ($_POST['key'] ?? ''));
        $primary = trim((string) ($_POST['primary_category'] ?? ''));
        $subcategory = trim((string) ($_POST['subcategory'] ?? ''));
        try {
            switch ($op) {
                case 'add_primary':
                    $primary = $catalog->addPrimary((string) ($_POST['name'] ?? ''), (string) ($_POST['icon'] ?? ''), (array) ($_POST['vehicle_types'] ?? []));
                    $subcategory = '';
                    flash_set('success', 'Categoria principală a fost adăugată. Adaugă acum subcategoriile ei.');
                    break;
                case 'add_subcategory':
                    $subcategory = $catalog->addSubcategory((string) ($_POST['name'] ?? ''), (string) ($_POST['icon'] ?? ''), (array) ($_POST['primaries'] ?? []));
                    $wanted = (array) ($_POST['primaries'] ?? []);
                    if (!in_array($primary, $wanted, true)) {
                        $primary = (string) ($wanted[0] ?? $primary);
                    }
                    flash_set('success', 'Subcategoria a fost adăugată.');
                    break;
                case 'add_category':
                    $subcategory = trim((string) ($_POST['subcategory_key'] ?? ''));
                    $id = $catalog->addCategory((string) ($_POST['name'] ?? ''), $subcategory, (string) ($_POST['icon'] ?? ''), (string) ($_POST['item_type'] ?? 'componenta'));
                    $placement = AutoComponentCatalogService::defaultPlacement($id);
                    if ($placement !== null && !AutoComponentCatalogService::isValidPlacement($primary, $subcategory)) {
                        $primary = $placement[0];
                    }
                    flash_set('success', 'Categoria ' . $id . ' a fost adăugată.');
                    break;
                case 'update':
                    $catalog->updateNode($level, $key, $_POST);
                    flash_set('success', 'Modificarea a fost salvată.');
                    break;
                case 'deactivate':
                case 'activate':
                    $catalog->setNodeActive($level, $key, $op === 'activate');
                    flash_set('success', $op === 'activate' ? 'Elementul a fost reactivat.' : 'Elementul a fost dezactivat (istoricul rămâne).');
                    break;
                default:
                    throw new InvalidArgumentException('Operație necunoscută.');
            }
            audit_log_write($this->db, 'mentenanta_auto_categorii', 0, 'update', 'Arbore catalog: ' . $op . ' ' . $level . ' ' . $key . ' ' . trim((string) ($_POST['name'] ?? '')), null, null, $this->userId());
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][auto_taxonomy_manage] ' . $exception->getMessage());
            flash_set('danger', 'Modificarea categoriilor nu a putut fi salvată.');
        }

        redirect(build_query_url([
            'page' => 'mentenanta',
            'action' => 'auto',
            'view' => 'catalog',
            'vehicle_type' => $this->normalizeAutoVehicleType((string) ($_POST['vehicle_type'] ?? 'camion')) ?: 'camion',
            'vehicle_id' => max(0, (int) ($_POST['vehicle_id'] ?? 0)) ?: null,
            'primary_category' => $primary !== '' ? $primary : null,
            'subcategory' => $subcategory !== '' ? $subcategory : null,
        ]));
    }

    private function export(): void
    {
        $section = (string) ($_GET['section'] ?? 'overview');
        $filters = $this->collectFilters();
        $filename = 'mentenanta-' . $section . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Exportul nu poate fi generat.');
        }
        fwrite($output, "\xEF\xBB\xBF");

        if ($section === 'stock') {
            fputcsv($output, ['Cod piesă', 'Denumire', 'Categorie', 'Stoc curent', 'Stoc minim', 'Preț achiziție', 'Valoare stoc', 'Furnizor'], ';');
            foreach ($this->model->getStockParts($filters) as $row) {
                fputcsv($output, [$row['cod_piesa'], $row['denumire'], $row['categorie'], $row['stoc_curent'], $row['stoc_minim'], $row['pret_achizitie'], $row['valoare_stoc'], $row['furnizor']], ';');
            }
        } elseif ($section === 'interventions') {
            // Aceleasi filtre ca tabelul Interventii V2 (cautare, vehicul, tip, status, perioada, responsabil).
            $withCosts = can('mentenanta', 'interventions_costs');
            fputcsv($output, array_merge(
                ['ID', 'Sursă', 'Data sesizării', 'Data programată', 'Ora', 'Vehicul', 'Tip vehicul', 'Tip', 'Prioritate', 'Centru cost',
                    'Descriere', 'Componente', 'Furnizor', 'Responsabil tehnic', 'Șofer'],
                $withCosts ? ['Cost estimat'] : [],
                ['Status']
            ), ';');
            foreach ($this->workflow()->exportRows($this->collectInterventionFilters($_GET)) as $row) {
                fputcsv($output, array_merge([
                    $row['id'], $row['sursa'] === 'km_service' ? 'Km Service' : 'Manual', $row['data_sesizare'], $row['data_programata'],
                    $row['ora_programata'] ?? '', $row['nr_inmatriculare'], $row['vehicul_tip_label'],
                    $row['tip_interventie'] === 'reparatie' ? 'Reparație' : 'Întreținere', $row['prioritate_label'], $row['centru_cost'],
                    $row['descriere'], $row['componente_lista'] ?? '', $row['furnizor'], $row['responsabil_nume'] ?? 'Nealocat', $row['sofer_nume'],
                ], $withCosts ? [$row['cost_estimat']] : [], [$row['status_label']]), ';');
            }
        } elseif ($section === 'repairs') {
            fputcsv($output, ['Data factura', 'Numar factura', 'Furnizor', 'Data scadenta', 'Vehicule', 'Reparatii', 'Piese', 'Cost manopera', 'Cost piese', 'Cost total', 'Status'], ';');
            foreach ($this->model->getCorrectiveInvoices($filters, 500) as $row) {
                fputcsv($output, [
                    $row['invoice_date'],
                    $row['invoice_number'],
                    $row['supplier_label'],
                    $row['due_date'],
                    $row['vehicle_labels'],
                    $row['repair_count'],
                    $row['part_count'],
                    $row['labour_total'],
                    $row['parts_total'],
                    $row['grand_total'],
                    $row['status'],
                ], ';');
            }
        } else {
            $type = $section === 'maintenance' ? 'intretinere' : null;
            fputcsv($output, ['Data', 'Vehicul', 'Tip vehicul', 'Tip intervenție', 'Centru cost', 'Descriere', 'Furnizor', 'Cost', 'Status'], ';');
            foreach ($this->model->getRecords($filters, $type, 500) as $row) {
                fputcsv($output, [$row['data_interventie'], $row['nr_inmatriculare'], $row['tip_vehicul'], $row['tip_interventie'], $row['centru_cost'], $row['descriere'], $row['atelier'], $row['cost'], $row['status_interventie']], ';');
            }
        }
        fclose($output);
        exit;
    }

    private function collectFilters(): array
    {
        $allowedStatuses = ['programata', 'confirmata', 'in_lucru', 'draft', 'in_progress', 'finalizata', 'anulata'];
        $status = trim((string) ($_GET['status'] ?? ''));
        return [
            'date_from' => $this->isDate((string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : '',
            'date_to' => $this->isDate((string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : '',
            'vehicle_id' => max(0, (int) ($_GET['vehicle_id'] ?? 0)),
            'record_type' => in_array(($_GET['record_type'] ?? ''), ['intretinere', 'reparatie'], true) ? (string) $_GET['record_type'] : '',
            'centru_cost' => trim((string) ($_GET['centru_cost'] ?? '')),
            'technical_category_id' => max(0, (int) ($_GET['technical_category_id'] ?? 0)),
            'status' => in_array($status, $allowedStatuses, true) ? $status : '',
            'search' => trim((string) ($_GET['search'] ?? '')),
            'categorie' => trim((string) ($_GET['categorie'] ?? '')),
            'vehicle_type' => trim((string) ($_GET['vehicle_type'] ?? '')),
            'stock_status' => in_array(($_GET['stock_status'] ?? ''), ['in_stock', 'low', 'out'], true) ? (string) $_GET['stock_status'] : '',
            'furnizor' => trim((string) ($_GET['furnizor'] ?? '')),
        ];
    }

    private function storeUpload(mixed $file, string $prefix, array $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], string $folder = 'mentenanta_piese'): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Fișierul nu a putut fi încărcat.');
        }
        if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('Fișierul trebuie să fie mai mic de 8 MB.');
        }
        $original = basename((string) ($file['name'] ?? 'document'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Tipul fișierului nu este permis.');
        }
        $directory = BASE_PATH . '/uploads/' . $folder;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Directorul pentru documente nu poate fi creat.');
        }
        // Documentele interventiilor se servesc doar prin controller (cu verificarea dreptului).
        if ($folder === 'mentenanta_interventii' && !is_file($directory . '/.htaccess')) {
            file_put_contents($directory . '/.htaccess', "Require all denied\n");
        }
        $stored = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
        if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $stored)) {
            throw new RuntimeException('Fișierul nu a putut fi salvat.');
        }
        return ['original' => $original, 'stored' => $stored];
    }

    private function requirePostAndCsrf(string $section): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectSection($section);
        }
        ensure_csrf_or_redirect($this->sectionUrl($section));
    }

    private function redirectSection(string $section): never
    {
        redirect($this->sectionUrl($section));
    }

    private function sectionUrl(string $section): string
    {
        $action = $section === 'overview' ? 'overview' : $section;
        return build_query_url(['page' => 'mentenanta', 'action' => $action]);
    }

    private function decimal(mixed $value): float
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        return is_numeric($value) ? max(0, (float) $value) : 0.0;
    }

    private function isDate(string $value): bool
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isLegacyAction(string $action): bool
    {
        return in_array($action, [
            'tire_stock', 'add_tire_stock', 'bulk_tire_stock', 'update_tire_stock', 'delete_tire_stock', 'bulk_delete_tire_stock', 'bulk_unmount_tire_stock',
            'bulk_mount_tire_stock', 'tire_mount_vehicles', 'tire_mount_layout', 'tire_stock_children', 'tire_stock_selection',
            'tire_fleet_unit', 'tire_km_sas_sync', 'tire_position_candidates', 'tire_fleet_component', 'tire_fleet_component_inspector',
            'component_mount', 'component_unmount', 'component_inspect', 'axle_attributes_save',
            'axis_config', 'update_tire_layout', 'mount_tire', 'unmount_tire', 'move_tire', 'change_tire_status',
            'create', 'store', 'edit', 'update', 'delete', 'show', 'preview',
        ], true);
    }
}
