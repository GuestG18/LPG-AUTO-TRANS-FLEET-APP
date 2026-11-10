<?php
declare(strict_types=1);

/**
 * Datele panoului unei categorii de componente pe un vehicul (exploratorul din Stoc Anvelope).
 * Trei lucruri tinute separat, toate din tabelele existente:
 *  - Montat / configurat pe vehicul: mentenanta_auto_configurari + ultima montare din
 *    mentenanta_piese_utilizari, uzura prin AutoComponentStateService (ca in Reparatii Auto);
 *  - Inventar: piesa din stoc a componentei (mentenanta_piese), cantitati si garantii;
 *  - Istoric: montari, lucrari din interventii, articole din registrul de piese.
 * Nimic nu se scrie; actiunile duc la fluxurile existente (Reparatii Auto, Interventii, Stoc piese).
 */
final class VehicleComponentPanelService
{
    private PDO $db;
    private MaintenanceModel $maintenance;
    private VehicleComponentModel $components;

    public function __construct(PDO $db, MaintenanceModel $maintenance, VehicleComponentModel $components)
    {
        $this->db = $db;
        $this->maintenance = $maintenance;
        $this->components = $components;
    }

    /**
     * @param array $vehicle randul din vehicule (id, nr_inmatriculare, tip_vehicul, km_bord...)
     * @param array<string,array> $catalog AutoComponentCatalogService::components()
     */
    public function build(array $vehicle, string $category, string $location, array $catalog): array
    {
        $vehicleId = (int) $vehicle['id'];
        $catalogMap = VehicleComponentRegistry::catalogByCategory($catalog);
        $keys = $catalogMap[$category] ?? [];
        $configs = $this->maintenance->getAutoComponentConfigs($vehicleId);
        $usage = $this->maintenance->getAutoPartUsageForVehicle($vehicleId);
        $partIndex = $this->maintenance->getAutoComponentPartIndex();
        $vehicleKm = is_numeric((string) ($vehicle['km_bord'] ?? null)) ? (int) $vehicle['km_bord'] : 0;

        $components = [];
        $partIds = [];
        foreach ($keys as $key) {
            $item = $catalog[$key] ?? null;
            if ($item === null) {
                continue;
            }
            $part = AutoComponentStateService::findStockPart($partIndex, (string) $item['category'], (string) $item['name']);
            $config = $configs[$key] ?? null;
            $stockPartId = (int) ($config['stock_part_id'] ?? 0) ?: (int) ($part['id'] ?? 0);
            if ($stockPartId > 0) {
                $partIds[$stockPartId] = $key;
            }
            $state = [
                'configured' => is_array($config),
                'inherited' => !empty($config['inherited']),
                'interval' => $config['interval_value'] ?? null,
                'unit' => (string) ($config['unit'] ?? 'km'),
                'stock_part_id' => $stockPartId,
                'config_created_at' => (string) ($config['created_at'] ?? ''),
            ];
            $wear = AutoComponentStateService::wearPercent($state, $vehicleKm, $usage);
            $lastMount = $stockPartId > 0 ? ($usage[$stockPartId] ?? null) : null;
            $components[] = [
                'key' => $key,
                'name' => (string) $item['name'],
                'code' => (string) (($config['component_code'] ?? '') ?: ($part['cod_piesa'] ?? '') ?: $item['code']),
                'catalog_category' => (string) $item['category'],
                'catalog_category_id' => (int) $item['category_id'],
                'configured' => is_array($config),
                'monitoring' => (string) ($config['monitoring_type'] ?? ''),
                'interval' => (string) ($config['interval_value'] ?? ''),
                'warning' => (string) ($config['warning_value'] ?? ''),
                'critical' => (string) ($config['critical_value'] ?? ''),
                'unit' => (string) ($config['unit'] ?? ''),
                'wear' => $wear,
                'wear_tone' => $wear === null ? 'empty' : ($wear >= AutoComponentStateService::WEAR_ATTENTION_PERCENT ? 'warn' : 'ok'),
                'last_mount_date' => (string) ($lastMount['data_montare'] ?? ''),
                'last_mount_km' => isset($lastMount['km_montare']) ? (int) $lastMount['km_montare'] : null,
                'stock' => $part === null ? null : [
                    'id' => (int) $part['id'],
                    'code' => (string) ($part['cod_piesa'] ?? ''),
                    'name' => (string) ($part['denumire'] ?? ''),
                    'quantity' => (float) ($part['stoc_curent'] ?? 0),
                    'minimum' => (float) ($part['stoc_minim'] ?? 0),
                    'unit' => (string) ($part['unitate_masura'] ?? 'buc'),
                    'supplier' => (string) ($part['furnizor'] ?? ''),
                    'producer' => (string) ($part['producator'] ?? ''),
                    'location' => (string) ($part['locatie_depozit'] ?? ''),
                    'warranty' => (string) ($part['warranty_label'] ?? ''),
                ],
            ];
        }

        // Locul ales: ce e montat acum acolo, montarile / demontarile si inspectiile lui.
        $locationRecords = $this->components->locationRecords($vehicleId, $category, $location, $keys);
        $axleNo = preg_match('/^A(\d+)/', $location, $axleMatch) === 1 ? (int) $axleMatch[1] : null;

        return [
            'category' => $category,
            'location' => $location,
            'components' => $components,
            'location_records' => $locationRecords,
            'axle_no' => $axleNo,
            'axle_attrs' => $axleNo !== null ? ($this->components->axleAttributes([$vehicleId])[$vehicleId][$axleNo] ?? null) : null,
            'vehicle_km' => $vehicleKm > 0 ? $vehicleKm : null,
            'history' => $this->history($vehicleId, $keys, $partIds, $catalog, $location),
        ];
    }

    /**
     * Inspectorul unei componente (o piesa din catalog) pe un loc al vehiculului: aceleasi date ca
     * panoul categoriei (build), restranse la componenta aleasa, plus starea, durata de viata,
     * istoricul si documentele ei. Ce lipseste din baza ramane null ("Nedisponibil" in interfata).
     */
    public function inspector(array $vehicle, string $category, string $location, string $componentKey, array $catalog): ?array
    {
        $panel = $this->build($vehicle, $category, $location, $catalog);
        $current = null;
        foreach ($panel['components'] as $item) {
            if ($item['key'] === $componentKey) {
                $current = $item;
            }
        }
        if ($current === null) {
            return null;
        }
        $vehicleId = (int) $vehicle['id'];
        $vehicleKm = $panel['vehicle_km'];

        // Montarea activa a componentei pe acest loc (din explorator); altfel ultima montare a piesei pe vehicul.
        $locationMounts = array_values(array_filter($panel['location_records']['mounts'], static fn (array $row): bool => (string) $row['component_key'] === $componentKey));
        $activeHere = null;
        foreach ($locationMounts as $row) {
            if ($row['demontat_la'] === null) {
                $activeHere = $row;
                break;
            }
        }
        $mountDate = $activeHere['data_montare'] ?? ($current['last_mount_date'] !== '' ? $current['last_mount_date'] : null);
        $mountKm = $activeHere !== null ? ($activeHere['km_montare'] !== null ? (int) $activeHere['km_montare'] : null) : $current['last_mount_km'];

        // Inspectiile locului: cele ale componentei sau ale intregii categorii (fara componenta).
        $inspections = array_values(array_filter($panel['location_records']['inspections'], static fn (array $row): bool => $row['component_key'] === null || (string) $row['component_key'] === $componentKey));
        $lastInspection = $inspections[0] ?? null;

        // Durata de viata ramasa si urmatoarea revizie: doar din intervalul configurat (Reparatii Auto).
        $interval = AutoComponentStateService::numericValue($current['interval']);
        $remainingPercent = $current['wear'] !== null ? max(0, 100 - (int) $current['wear']) : null;
        $remainingKm = null;
        $nextService = null;
        if ($interval > 0 && $current['unit'] === 'km' && $mountKm !== null) {
            $nextService = ['type' => 'km', 'value' => (int) round($mountKm + $interval)];
            if ($vehicleKm !== null) {
                $remainingKm = (int) round($mountKm + $interval - $vehicleKm);
            }
        } elseif ($interval > 0 && $current['unit'] === 'luni' && $mountDate !== null) {
            $nextService = ['type' => 'date', 'value' => date('Y-m-d', strtotime($mountDate . ' +' . (int) $interval . ' months'))];
        }

        // Starea: ultima inspectie; altfel uzura fata de interval (pragul din Reparatii Auto); altfel necunoscuta.
        if ($lastInspection !== null) {
            $status = ['ok' => 'ok', 'atentie' => 'warn', 'inlocuire' => 'crit'][$lastInspection['rezultat']];
            $statusLabel = ['ok' => 'În stare bună', 'warn' => 'Necesită atenție', 'crit' => 'Necesită înlocuire'][$status];
            $statusSource = 'Inspecție din ' . date('d.m.Y', strtotime((string) $lastInspection['data_inspectie']));
        } elseif ($current['wear'] !== null) {
            $status = $current['wear'] >= 100 ? 'crit' : ($current['wear'] >= AutoComponentStateService::WEAR_ATTENTION_PERCENT ? 'warn' : 'ok');
            $statusLabel = ['ok' => 'În stare bună', 'warn' => 'Necesită atenție', 'crit' => 'Interval depășit'][$status];
            $statusSource = 'Uzură calculată din intervalul configurat';
        } else {
            $status = 'unknown';
            $statusLabel = 'Stare necunoscută';
            $statusSource = $current['configured'] ? 'Fără montare / km pentru calcul' : 'Componenta nu e configurată în Reparații Auto';
        }

        // Documente: fisierele piesei din stoc + documentele interventiilor cu lucrari pe componenta.
        $documents = [];
        if ($current['stock'] !== null) {
            $stmt = $this->db->prepare('SELECT factura_original, factura_stocata, fisa_original, fisa_stocata, imagine_original, imagine_stocata FROM mentenanta_piese WHERE id = :id');
            $stmt->execute([':id' => $current['stock']['id']]);
            $files = $stmt->fetch() ?: [];
            foreach ([['factura', 'Factură piesă'], ['fisa', 'Fișă tehnică'], ['imagine', 'Imagine piesă']] as [$prefix, $label]) {
                $stored = basename(str_replace('\\', '/', (string) ($files[$prefix . '_stocata'] ?? '')));
                if ($stored !== '') {
                    $documents[] = ['type' => $label, 'name' => (string) ($files[$prefix . '_original'] ?? $stored), 'url' => url('uploads/mentenanta_piese/' . rawurlencode($stored)), 'date' => null];
                }
            }
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT d.id, d.tip, d.fisier_original, d.created_at, d.intervention_id
                 FROM mentenanta_interventii_documente d
                 INNER JOIN mentenanta_interventii_lucrari l ON l.intervention_id = d.intervention_id
                 INNER JOIN mentenanta_interventii_programate i ON i.id = d.intervention_id
                 WHERE l.component_key = :component_key AND COALESCE(l.vehicle_id, i.vehicle_id) = :vehicle_id
                 ORDER BY d.created_at DESC LIMIT 30'
            );
            $stmt->execute([':component_key' => $componentKey, ':vehicle_id' => $vehicleId]);
            $types = ['deviz' => 'Deviz', 'factura' => 'Factură', 'comanda' => 'Comandă', 'foto' => 'Fotografie', 'raport' => 'Raport', 'altul' => 'Document'];
            foreach ($stmt->fetchAll() as $row) {
                $documents[] = [
                    'type' => ($types[$row['tip']] ?? 'Document') . ' · intervenția #' . (int) $row['intervention_id'],
                    'name' => (string) $row['fisier_original'],
                    'url' => build_query_url(['page' => 'mentenanta', 'action' => 'intervention_document', 'id' => (int) $row['id']]),
                    'date' => (string) $row['created_at'],
                ];
            }
        } catch (PDOException $exception) {
            error_log('[VehicleComponentPanelService][documente] ' . $exception->getMessage());
        }

        return [
            'category' => $category,
            'location' => $location,
            'component' => $current,
            'associated' => array_values(array_filter($panel['components'], static fn (array $item): bool => $item['key'] !== $componentKey)),
            'active_here' => $activeHere,
            'mount_date' => $mountDate,
            'mount_km' => $mountKm,
            'location_mounts' => $locationMounts,
            'inspections' => $inspections,
            'last_inspection' => $lastInspection,
            'remaining_percent' => $remainingPercent,
            'remaining_km' => $remainingKm,
            'next_service' => $nextService,
            'status' => $status,
            'status_label' => $statusLabel,
            'status_source' => $statusSource,
            'documents' => $documents,
            'history' => array_values(array_filter($panel['history'], static fn (array $row): bool => $row['component'] === $current['name'])),
            'vehicle_km' => $vehicleKm,
            'axle_attrs' => $panel['axle_attrs'],
        ];
    }

    /** Eticheta unui loc din schema: A2-L = „Axa 2 stânga”, A1 = „Axa 1”, vehicul / fata / spate. */
    public static function locationLabel(string $location): string
    {
        if (preg_match('/^A(\d+)(?:-([LR]))?$/', $location, $m) === 1) {
            return 'Axa ' . $m[1] . (isset($m[2]) && $m[2] !== '' ? ($m[2] === 'L' ? ' stânga' : ' dreapta') : '');
        }
        return ['vehicul' => 'vehicul', 'caseta' => 'casetă direcție', 'fata' => 'față', 'spate' => 'spate'][$location] ?? $location;
    }

    /** Istoricul categoriei pe vehicul, cele mai noi primele; marcheaza ce priveste locul ales. */
    private function history(int $vehicleId, array $keys, array $partIds, array $catalog, string $location): array
    {
        $rows = [];
        $nameOf = static fn (string $key): string => (string) ($catalog[$key]['name'] ?? $key);
        $locationPattern = preg_match('/^A(\d+)(?:-([LR]))?$/', $location, $m) === 1
            ? '/\b(a|axa|axă)\s*' . $m[1] . '\b' . (isset($m[2]) && $m[2] !== '' ? '.*\b(' . ($m[2] === 'L' ? 'st|stg|stanga|stânga|l' : 'dr|dreapta|r') . ')\b' : '') . '/iu'
            : null;

        if ($partIds !== []) {
            $in = implode(',', array_map('intval', array_keys($partIds)));
            $stmt = $this->db->prepare(
                "SELECT u.id, u.part_id, u.data_montare, u.km_montare, u.cantitate, u.montata_de, u.observatii, u.direct_mount, u.scheduled_intervention_id,
                        u.locatie, u.demontat_la, u.motiv_demontare
                   FROM mentenanta_piese_utilizari u
                  WHERE u.vehicle_id = :vehicle_id AND u.part_id IN ($in)
                  ORDER BY u.data_montare DESC, u.id DESC LIMIT 60"
            );
            $stmt->execute([':vehicle_id' => $vehicleId]);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = [
                    'date' => (string) $row['data_montare'],
                    'kind' => 'Montare piesă' . ($row['locatie'] !== null ? ' · ' . self::locationLabel((string) $row['locatie']) : ''),
                    'component' => $nameOf((string) ($partIds[(int) $row['part_id']] ?? '')),
                    'detail' => trim(implode(' · ', array_filter([
                        $row['demontat_la'] !== null ? 'demontată ' . date('d.m.Y', strtotime((string) $row['demontat_la'])) . ' (' . (VehicleComponentModel::UNMOUNT_REASONS[(string) $row['motiv_demontare']] ?? (string) $row['motiv_demontare']) . ')' : '',
                        (int) $row['direct_mount'] === 1 ? 'montaj direct' : 'din stoc',
                        $row['km_montare'] !== null ? number_format((float) $row['km_montare'], 0, ',', '.') . ' km' : '',
                        (float) $row['cantitate'] !== 1.0 ? 'cant. ' . rtrim(rtrim(number_format((float) $row['cantitate'], 2, ',', '.'), '0'), ',') : '',
                        (string) ($row['montata_de'] ?? ''),
                        (string) ($row['observatii'] ?? ''),
                    ]))),
                    'link' => (int) ($row['scheduled_intervention_id'] ?? 0) > 0 ? ['intervention', (int) $row['scheduled_intervention_id']] : null,
                    'here' => $row['locatie'] !== null && (string) $row['locatie'] === $location,
                ];
            }
        }

        if ($keys !== []) {
            $placeholders = [];
            $params = [':vehicle_id' => $vehicleId];
            foreach (array_values($keys) as $index => $key) {
                $placeholders[] = ':k' . $index;
                $params[':k' . $index] = $key;
            }
            $in = implode(',', $placeholders);
            $operations = ['verificare' => 'Verificare', 'diagnosticare' => 'Diagnosticare', 'reglare' => 'Reglare', 'reparatie' => 'Reparație', 'inlocuire' => 'Înlocuire'];
            $results = ['executata' => 'executată', 'amanata' => 'amânată', 'respinsa' => 'respinsă', 'nenecesara' => 'nenecesară'];
            try {
                $stmt = $this->db->prepare(
                    "SELECT l.component_key, l.operatiune, l.stare_evaluare, l.progres, l.rezultat, l.pozitie, l.piesa_montata, l.diagnostic,
                            i.id AS intervention_id, COALESCE(l.data_montare, i.data_executie, i.data_programata, DATE(l.created_at)) AS event_date
                       FROM mentenanta_interventii_lucrari l
                       INNER JOIN mentenanta_interventii_programate i ON i.id = l.intervention_id
                      WHERE l.component_key IN ($in) AND COALESCE(l.vehicle_id, i.vehicle_id) = :vehicle_id
                      ORDER BY event_date DESC, l.id DESC LIMIT 60"
                );
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $row) {
                    $position = trim((string) ($row['pozitie'] ?? ''));
                    $rows[] = [
                        'date' => (string) $row['event_date'],
                        'kind' => 'Intervenție #' . (int) $row['intervention_id'] . ' · ' . ($operations[(string) $row['operatiune']] ?? (string) $row['operatiune']),
                        'component' => $nameOf((string) $row['component_key']),
                        'detail' => trim(implode(' · ', array_filter([
                            $position !== '' ? 'Poziție: ' . $position : '',
                            $row['rezultat'] !== null ? 'Rezultat: ' . ($results[(string) $row['rezultat']] ?? (string) $row['rezultat']) : 'Evaluare: ' . (string) $row['stare_evaluare'],
                            (string) ($row['piesa_montata'] ?? '') !== '' ? 'Montat: ' . $row['piesa_montata'] : '',
                        ]))),
                        'link' => ['intervention', (int) $row['intervention_id']],
                        'here' => $locationPattern !== null && $position !== '' && preg_match($locationPattern, $position) === 1,
                    ];
                }
            } catch (PDOException $exception) {
                error_log('[VehicleComponentPanelService][lucrari] ' . $exception->getMessage());
            }

            try {
                $stmt = $this->db->prepare(
                    "SELECT a.auto_component_key, a.tip, a.denumire, a.cantitate, a.pret_unitar, a.data_referinta, a.km_bord, a.reparatie_id, DATE(a.created_at) AS created_day
                       FROM ocr_reparatii_articole a
                      WHERE a.auto_component_key IN ($in) AND a.vehicle_id = :vehicle_id
                      ORDER BY COALESCE(a.data_referinta, a.created_at) DESC, a.id DESC LIMIT 60"
                );
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $row) {
                    $rows[] = [
                        'date' => (string) ($row['data_referinta'] ?: $row['created_day']),
                        'kind' => 'Factură (registru piese) · ' . ((string) $row['tip'] === 'manopera' ? 'manoperă' : 'piesă'),
                        'component' => $nameOf((string) $row['auto_component_key']),
                        'detail' => trim(implode(' · ', array_filter([
                            (string) $row['denumire'],
                            $row['km_bord'] !== null ? number_format((float) $row['km_bord'], 0, ',', '.') . ' km' : '',
                            number_format((float) $row['cantitate'] * (float) $row['pret_unitar'], 2, ',', '.') . ' lei',
                        ]))),
                        'link' => ['ocr', (int) $row['reparatie_id']],
                        'here' => false,
                    ];
                }
            } catch (PDOException $exception) {
                error_log('[VehicleComponentPanelService][registru] ' . $exception->getMessage());
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return array_slice($rows, 0, 80);
    }
}
