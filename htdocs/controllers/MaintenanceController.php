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
            case 'save_record':
                $this->saveRecord();
                return;
            case 'delete_record':
                $this->deleteRecord();
                return;
            case 'save_part':
                $this->savePart();
                return;
            case 'save_auto_component_config':
                $this->saveAutoComponentConfig();
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
        render('maintenance/auto.php', [
            'pageTitle' => 'Reparatii Auto',
            'currentPage' => 'mentenanta',
            'catalog' => $this->getAutoCatalogData(),
        ]);
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
        $requestedPrimary = (string) ($_GET['primary_category'] ?? ($requestedVehicleType === 'cap_tractor' ? 'sasiu' : 'rezervor'));
        if ($requestedVehicleType === 'cap_tractor') {
            $requestedPrimary = 'sasiu';
        }
        if (!in_array($requestedPrimary, ['rezervor', 'sasiu'], true)) {
            $requestedPrimary = 'rezervor';
        }
        $requestedSubcategory = (string) ($_GET['subcategory'] ?? ($requestedPrimary === 'sasiu' ? 'sasiu' : 'livrare_gaz'));
        if (!in_array($requestedSubcategory, ['sasiu', 'hidraulic', 'livrare_gaz'], true)) {
            $requestedSubcategory = $requestedPrimary === 'sasiu' ? 'sasiu' : 'livrare_gaz';
        }
        if ($requestedPrimary === 'sasiu' && $requestedSubcategory === 'livrare_gaz') {
            $requestedSubcategory = 'sasiu';
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
                'category_id' => match ($requestedSubcategory) {
                    'sasiu' => 1,
                    'hidraulic' => 10,
                    default => 11,
                },
            ],
            'vehicle_types' => $vehicleOptions,
            'vehicles' => $selectableVehicles,
            'selected_vehicle' => $selectedVehicle,
            'category_groups' => [
                'sasiu' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
                'hidraulic' => [10],
                'livrare_gaz' => [11, 12, 13, 14, 15, 16, 17],
            ],
            'categories' => $categories,
            'tree' => $this->getAutoVehicleTree($requestedVehicleType, $requestedPrimary, $requestedSubcategory),
        ];
    }

    private function getAutoVehicleTree(string $vehicleType, string $primary, string $subcategory): array
    {
        $vehicleType = in_array($vehicleType, ['camion', 'cap_tractor', 'semiremorca', 'ansamblu'], true) ? $vehicleType : 'camion';
        $buildSasiuBranch = function (string $keyPrefix) use ($primary, $subcategory): array {
            return [
                'key' => $keyPrefix . '_sasiu',
                'label' => 'Sasiu',
                'icon' => 'bi-truck-front',
                'expanded' => $primary === 'sasiu',
                'selected' => $primary === 'sasiu',
                'children' => [
                    [
                        'key' => $keyPrefix . '_sasiu_categorii',
                        'label' => 'Sasiu',
                        'icon' => 'bi-truck-front',
                        'meta' => 'Categorii 1-9',
                        'primary' => 'sasiu',
                        'subcategory' => 'sasiu',
                        'active' => $primary === 'sasiu' && $subcategory === 'sasiu',
                    ],
                    [
                        'key' => $keyPrefix . '_sasiu_hidraulic',
                        'label' => 'Hidraulic',
                        'icon' => 'bi-droplet',
                        'meta' => 'Categorie 10',
                        'primary' => 'sasiu',
                        'subcategory' => 'hidraulic',
                        'active' => $primary === 'sasiu' && $subcategory === 'hidraulic',
                    ],
                ],
            ];
        };
        $buildReservor = function (string $keyPrefix) use ($primary, $subcategory): array {
            return [
                'key' => $keyPrefix . '_rezervor',
                'label' => 'Rezervor',
                'icon' => 'bi-droplet-half',
                'expanded' => $primary === 'rezervor',
                'selected' => $primary === 'rezervor',
                'children' => [
                    ['key' => $keyPrefix . '_rezervor_sasiu', 'label' => 'Sasiu', 'icon' => 'bi-truck-front', 'meta' => 'Categorii 1-9', 'primary' => 'rezervor', 'subcategory' => 'sasiu', 'active' => $primary === 'rezervor' && $subcategory === 'sasiu'],
                    ['key' => $keyPrefix . '_rezervor_hidraulic', 'label' => 'Hidraulic', 'icon' => 'bi-droplet', 'meta' => 'Categorie 10', 'primary' => 'rezervor', 'subcategory' => 'hidraulic', 'active' => $primary === 'rezervor' && $subcategory === 'hidraulic'],
                    ['key' => $keyPrefix . '_livrare_gaz', 'label' => 'Livrare Gaz', 'icon' => 'bi-fire', 'meta' => 'Categorii 11-17', 'primary' => 'rezervor', 'subcategory' => 'livrare_gaz', 'active' => $primary === 'rezervor' && $subcategory === 'livrare_gaz'],
                ],
            ];
        };
        $buildVehicleWithReservor = function (string $key, string $label, string $icon) use ($buildReservor, $buildSasiuBranch): array {
            return [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'expanded' => true,
                'children' => [
                    $buildSasiuBranch($key),
                    $buildReservor($key),
                ],
            ];
        };
        $buildCapTractor = function (string $key) use ($buildSasiuBranch): array {
            return [
                'key' => $key,
                'label' => 'Cap tractor',
                'icon' => 'bi-truck-front',
                'expanded' => true,
                'children' => [
                    $buildSasiuBranch($key),
                ],
            ];
        };
        $buildSemiremorca = fn (string $key): array => $buildVehicleWithReservor($key, 'Semiremorca', 'bi-truck-flatbed');

        return match ($vehicleType) {
            'cap_tractor' => [$buildCapTractor('cap_tractor')],
            'semiremorca' => [$buildSemiremorca('semiremorca')],
            'ansamblu' => [[
                'key' => 'ansamblu',
                'label' => 'Ansamblu',
                'icon' => 'bi-diagram-3',
                'expanded' => true,
                'children' => [
                    $buildCapTractor('ansamblu_cap_tractor'),
                    $buildSemiremorca('ansamblu_semiremorca'),
                ],
            ]],
            default => [$buildVehicleWithReservor('camion', 'Camion', 'bi-truck')],
        };
    }

    private function loadAutoCategoriesFromExcel(): array
    {
        $categoryNames = [
            1 => 'Suspensie',
            2 => 'Rulare',
            3 => 'Franare',
            4 => 'Racire',
            5 => 'Electrica',
            6 => 'Motor',
            7 => 'Comfort',
            8 => 'Evacuare',
            9 => 'Directie',
            10 => 'Hidraulic',
            11 => 'Livrare Gaz',
            12 => 'Calculator Livrare',
            13 => 'Imprimare Bon',
            14 => 'Corp Masurator',
            15 => 'Degazor',
            16 => 'Valva Diferentiala',
            17 => 'Rezervor Tank',
        ];
        $icons = [
            1 => 'bi-truck-flatbed',
            2 => 'bi-disc',
            3 => 'bi-record-circle',
            4 => 'bi-thermometer-snow',
            5 => 'bi-lightning-charge',
            6 => 'bi-gear-wide-connected',
            7 => 'bi-sliders',
            8 => 'bi-wind',
            9 => 'bi-sign-turn-right',
            10 => 'bi-droplet-half',
            11 => 'bi-truck',
            12 => 'bi-calculator',
            13 => 'bi-printer-fill',
            14 => 'bi-speedometer2',
            15 => 'bi-filter-circle-fill',
            16 => 'bi-diagram-3-fill',
            17 => 'bi-hdd-fill',
        ];

        $categories = [];
        foreach ($categoryNames as $id => $name) {
            $categories[$id] = [
                'id' => $id,
                'title' => mb_strtoupper($name, 'UTF-8'),
                'name' => $name,
                'count' => 0,
                'icon' => $icons[$id] ?? 'bi-circle',
                'components' => [],
            ];
        }

        $rows = $this->readAutoExcelRows();
        $currentCategory = 0;
        $categoryCounters = [];
        foreach ($rows as $row) {
            $rawCategory = trim((string) ($row['A'] ?? ''));
            if ($rawCategory !== '' && preg_match('/^(\d+)\.?\s*(.+)$/u', $rawCategory, $matches)) {
                $currentCategory = (int) $matches[1];
            }
            if ($currentCategory <= 0 || !isset($categories[$currentCategory])) {
                continue;
            }

            $rawName = trim((string) ($row['B'] ?? ''));
            if ($rawName === '') {
                continue;
            }

            $categoryCounters[$currentCategory] = ($categoryCounters[$currentCategory] ?? 0) + 1;
            $nr = (int) $categoryCounters[$currentCategory];
            $displayName = $this->formatAutoComponentName($rawName);
            $monitoringByVehicle = [
                'camion' => trim((string) ($row['C'] ?? '')),
                'cap_tractor' => trim((string) ($row['D'] ?? '')),
                'semiremorca' => trim((string) ($row['E'] ?? '')),
                'rezervor' => trim((string) ($row['F'] ?? '')),
            ];
            $details = trim((string) ($row['G'] ?? ''));
            $notes = trim((string) ($row['H'] ?? ''));

            $categories[$currentCategory]['components'][] = [
                'id' => $currentCategory . '-' . $nr,
                'nr' => $nr,
                'category_id' => $currentCategory,
                'name' => $displayName,
                'raw_name' => $rawName,
                'code' => $this->buildAutoComponentCode((string) $categories[$currentCategory]['name'], $nr),
                'description' => $details !== ''
                    ? $this->formatAutoSentence($details)
                    : 'Componenta configurabila pentru categoria ' . (string) $categories[$currentCategory]['name'] . '.',
                'details' => $details,
                'notes' => $notes,
                'monitoring_by_vehicle' => $monitoringByVehicle,
                'interval' => '30.000',
                'warning' => '25.000',
                'critical' => '28.000',
                'lifetime' => '35.000',
                'wear' => null,
                'configured' => false,
                'photo_url' => '',
                'photo_original' => '',
                'garantie_piesa' => '',
                'garantie_manopera' => '',
                'warranty_status' => 'red',
                'warranty_label' => 'Fara garantie',
            ];
        }

        foreach ($categories as &$category) {
            $category['count'] = count($category['components']);
        }
        unset($category);

        return $categories;
    }

    private function readAutoExcelRows(): array
    {
        $path = dirname(BASE_PATH) . '/data/componente critice aplicatie.xlsx';
        if (!is_file($path) || !class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $xml = simplexml_load_string($sharedXml);
            if ($xml instanceof SimpleXMLElement) {
                foreach ($xml->si as $item) {
                    $text = '';
                    if (isset($item->t)) {
                        $text = (string) $item->t;
                    } else {
                        foreach ($item->r as $run) {
                            $text .= (string) $run->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }

        $sheet = simplexml_load_string($sheetXml);
        if (!$sheet instanceof SimpleXMLElement) {
            return [];
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowIndex = (int) ($row['r'] ?? 0);
            if ($rowIndex < 2) {
                continue;
            }

            $values = [];
            foreach ($row->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $column = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
                if ($column === '') {
                    continue;
                }
                $values[$column] = $this->excelCellValue($cell, $sharedStrings);
            }
            $rows[] = $values;
        }

        return $rows;
    }

    private function excelCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 's') {
            $index = (int) ($cell->v ?? -1);
            return trim((string) ($sharedStrings[$index] ?? ''));
        }
        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        return trim((string) ($cell->v ?? ''));
    }

    private function formatAutoComponentName(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $replacements = [
            'Abs' => 'ABS',
            'Cpu' => 'CPU',
            'Gpl' => 'GPL',
            'Pto' => 'PTO',
            'Adr' => 'ADR',
            'A/C' => 'A/C',
            'Pg' => 'PG',
            'Cm' => 'CM',
        ];
        return strtr($value, $replacements);
    }

    private function formatAutoSentence(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        if ($value === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8') . '.';
    }

    private function buildAutoComponentCode(string $categoryName, int $nr): string
    {
        $parts = preg_split('/\s+/', mb_strtoupper($categoryName, 'UTF-8')) ?: [];
        $codeParts = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^A-Z0-9]/', '', $part) ?? '';
            if ($part !== '') {
                $codeParts[] = mb_substr($part, 0, 3, 'UTF-8');
            }
        }
        $prefix = implode('-', array_slice($codeParts, 0, 2));
        return ($prefix !== '' ? $prefix : 'CMP') . '-' . str_pad((string) $nr, 3, '0', STR_PAD_LEFT);
    }

    private function findAutoStockPart(array $partIndex, string $categoryName, string $componentName): ?array
    {
        $nameKey = $this->autoLookupKey($componentName);
        if ($nameKey === '') {
            return null;
        }

        $categoryKey = $this->autoLookupKey($categoryName);
        if ($categoryKey !== '' && isset($partIndex['by_category_name'][$categoryKey . '|' . $nameKey])) {
            return $partIndex['by_category_name'][$categoryKey . '|' . $nameKey];
        }

        return $partIndex['by_name'][$nameKey] ?? null;
    }

    private function calculateAutoWearPercent(array $component, int $vehicleKm, array $usageByPart): ?int
    {
        if (empty($component['configured'])) {
            return null;
        }

        $interval = $this->autoNumericValue($component['interval'] ?? null);
        if ($interval <= 0) {
            return null;
        }

        $unit = trim((string) ($component['unit'] ?? 'km'));
        $stockPartId = (int) ($component['stock_part_id'] ?? 0);
        $usage = $stockPartId > 0 && isset($usageByPart[$stockPartId]) && is_array($usageByPart[$stockPartId])
            ? $usageByPart[$stockPartId]
            : [];

        if ($unit === 'km') {
            if ($vehicleKm <= 0) {
                return null;
            }

            $mountedKm = isset($usage['km_montare']) && $usage['km_montare'] !== null
                ? (int) $usage['km_montare']
                : 0;
            $usedKm = $mountedKm > 0 && $vehicleKm >= $mountedKm ? $vehicleKm - $mountedKm : $vehicleKm;

            return max(0, min(100, (int) round(($usedKm / $interval) * 100)));
        }

        if ($unit === 'luni') {
            $start = trim((string) ($usage['data_montare'] ?? ''));
            if ($start === '') {
                $start = trim((string) ($component['config_created_at'] ?? ''));
            }
            if ($start === '') {
                return null;
            }

            try {
                $startDate = new DateTimeImmutable($start);
                $days = max(0, (int) $startDate->diff(new DateTimeImmutable('today'))->format('%a'));
                $months = $days / 30.4375;
                return max(0, min(100, (int) round(($months / $interval) * 100)));
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function autoNumericValue(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        $normalized = str_replace([' ', '.'], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function autoLookupKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/[^a-z0-9]+/iu', '', $value) ?? '';
    }

    private function autoPartImageUrl(string $stored): string
    {
        $stored = basename(str_replace('\\', '/', $stored));
        if ($stored === '') {
            return '';
        }

        return url('uploads/mentenanta_piese/' . rawurlencode($stored));
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
        $scheduledInterventions = [];
        $parts = [];
        $stockKpis = [];
        $stockFilterOptions = ['categories' => [], 'suppliers' => []];
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
            $records = $this->model->getRecords($filters, 'reparatie');
        } elseif ($section === 'stock') {
            $parts = $this->model->getStockParts($filters);
            $stockKpis = $this->model->getStockKpis();
            $stockFilterOptions = $this->model->getStockFilterOptions();
            $scheduledInterventions = $this->model->getScheduledInterventions();
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
        }

        render('maintenance/index.php', [
            'pageTitle' => $section === 'stock' ? 'Stoc Piese' : 'Mentenanță',
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
            'scheduledInterventions' => $scheduledInterventions,
            'parts' => $parts,
            'availableStockParts' => $this->model->getStockParts(['stock_status' => 'in_stock', 'include_tires' => false]),
            'stockKpis' => $stockKpis,
            'stockFilterOptions' => $stockFilterOptions,
            'technicalFormOptions' => $technicalFormOptions,
            'editRecord' => $editRecord,
            'viewRecord' => $viewRecord,
            'editIntervention' => $editIntervention,
        ]);
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

        try {
            $this->model->saveScheduledIntervention([
                'vehicle_id' => $vehicleId,
                'tip_interventie' => (string) ($_POST['tip_interventie'] ?? 'intretinere'),
                'data_programata' => $date,
                'cost_estimat' => $this->decimal($_POST['cost_estimat'] ?? 0),
                'furnizor' => (string) ($_POST['furnizor'] ?? ''),
                'driver_id' => (int) ($_POST['driver_id'] ?? 0),
                'client' => (string) ($_POST['client'] ?? ''),
                'centru_cost' => (string) ($_POST['centru_cost'] ?? ''),
                'descriere' => $description,
                'status_interventie' => (string) ($_POST['status_interventie'] ?? 'programata'),
            ], $id, (int) (current_user()['id'] ?? 0));
            flash_set('success', $id > 0 ? 'Intervenția a fost actualizată.' : 'Intervenția a fost programată.');
        } catch (Throwable $exception) {
            error_log('[MaintenanceController][save_intervention] ' . $exception->getMessage());
            flash_set('danger', 'Intervenția nu a putut fi salvată: ' . $exception->getMessage());
        }
        $this->redirectSection('interventions');
    }

    private function deleteIntervention(): void
    {
        $this->requirePostAndCsrf('interventions');
        try {
            $deleted = $this->model->deleteScheduledIntervention((int) ($_POST['id'] ?? 0));
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
            fputcsv($output, ['Data programată', 'Vehicul', 'Tip', 'Centru cost', 'Descriere', 'Furnizor', 'Cost estimat', 'Status'], ';');
            foreach ($this->model->getScheduledInterventions($filters) as $row) {
                fputcsv($output, [$row['data_programata'], $row['nr_inmatriculare'], $row['tip_interventie'], $row['centru_cost'], $row['descriere'], $row['furnizor'], $row['cost_estimat'], $row['status_interventie']], ';');
            }
        } else {
            $type = $section === 'maintenance' ? 'intretinere' : ($section === 'repairs' ? 'reparatie' : null);
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
        $allowedStatuses = ['programata', 'confirmata', 'in_lucru', 'finalizata', 'anulata'];
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

    private function storeUpload(mixed $file, string $prefix, array $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx']): array
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
        $directory = BASE_PATH . '/uploads/mentenanta_piese';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Directorul pentru documente nu poate fi creat.');
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
            'tire_stock', 'add_tire_stock', 'bulk_tire_stock', 'update_tire_stock', 'delete_tire_stock',
            'create', 'store', 'edit', 'update', 'delete', 'show', 'preview',
        ], true);
    }
}
