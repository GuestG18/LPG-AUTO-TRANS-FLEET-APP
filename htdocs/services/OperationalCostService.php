<?php
declare(strict_types=1);

/**
 * Motorul de calcul "Cost operațional / km".
 *
 * Pipeline (§48 din specificație):
 *   SURSE FLEET -> ELEMENTE FINANCIARE -> FIX/VARIABIL -> ALOCARE PE SCOP
 *   -> KM REALI -> LEI/KM -> COST vs VENIT -> REZULTAT -> BREAK-EVEN.
 *
 * Separarea straturilor:
 *   - OperationalCostModel  = rezolvarea surselor (SQL, read-only pe tranzacții)
 *   - OperationalCostService= normalizare + alocare + agregare + trasabilitate
 *   - CostBreakEvenService  = break-even + simulare (matematică pură, fără DB)
 *
 * Reguli respectate:
 *   - niciun cost nu se împarte la km-ii altui scop;
 *   - LIPSĂ nu devine 0 în tăcere — fiecare element poartă un status;
 *   - semiremorca cuplată NU dublează km: costurile ei se atașează tractorului
 *     (ansamblu), km-ul rămâne cel al tractorului (modelul Excel validat §B.5);
 *   - de-TVA-izarea se aplică O SINGURĂ DATĂ, la stratul de normalizare
 *     (doar sursele marcate 'brut' — azi combustibilul, cota reală 21%).
 */
class OperationalCostService
{
    private OperationalCostModel $model;

    /** Stare internă după compute() — refolosită de details/trasabilitate. */
    private array $units = [];
    private array $companyRows = [];
    private array $elementStatus = [];
    private array $settings = [];
    private array $period = [];

    public function __construct(PDO $db)
    {
        $this->model = new OperationalCostModel($db);
    }

    public function model(): OperationalCostModel
    {
        return $this->model;
    }

    // ==================================================================
    // API public
    // ==================================================================

    /**
     * Calculează întreaga pagină pentru filtrele date.
     *
     * @param array{period?:string,beneficiar_id?:int,categorie?:string,vehicle_id?:int,driver_id?:int,km_source?:string} $filters
     */
    public function compute(array $filters): array
    {
        $period = $this->resolvePeriod((string) ($filters['period'] ?? ''));
        $this->period = $period;
        $settings = $this->model->getSettings();
        $this->settings = $settings;
        $kmSource = ($filters['km_source'] ?? '') === 'curse_facturati' ? 'curse_facturati' : (($settings['km_source'] ?? 'curse_reali') === 'curse_facturati' ? 'curse_facturati' : 'curse_reali');
        $kmField = $kmSource === 'curse_facturati' ? 'km_facturat' : 'km_real';

        $this->buildUnits($period, $settings, $kmField);

        $benefFilter = (int) ($filters['beneficiar_id'] ?? 0);
        $catFilter = (string) ($filters['categorie'] ?? '');
        $vehFilter = (int) ($filters['vehicle_id'] ?? 0);
        $drvFilter = (int) ($filters['driver_id'] ?? 0);

        $matrix = $this->model->getActivityMatrix($period['start'], $period['end']);

        // --- scoping: care unități + ce fracțiune de km intră în analiză ---
        $scopedUnits = $this->units;
        if ($catFilter !== '') {
            $scopedUnits = array_filter($scopedUnits, fn($u) => $u['category'] === $catFilter);
        }
        if ($vehFilter > 0) {
            $scopedUnits = array_filter($scopedUnits, fn($u) => $u['vehicle_id'] === $vehFilter);
        }
        $kmShareByVehicle = null;
        if ($drvFilter > 0 || $benefFilter > 0) {
            // scope prin activitate: km-ul relevant este DOAR cel al șoferului/beneficiarului
            $kmByVehicle = [];
            foreach ($matrix as $row) {
                if ($drvFilter > 0 && (int) $row['driver_id'] !== $drvFilter) {
                    continue;
                }
                if ($benefFilter > 0 && (int) $row['beneficiar_id'] !== $benefFilter) {
                    continue;
                }
                $vid = (int) $row['vehicle_id'];
                $kmByVehicle[$vid] = ($kmByVehicle[$vid] ?? 0) + (int) $row[$kmField];
            }
            $scopedUnits = array_filter($scopedUnits, fn($u) => isset($kmByVehicle[$u['vehicle_id']]));
            $kmShareByVehicle = $kmByVehicle;
        }

        // --- agregate ---
        $categories = $this->aggregateCategories($scopedUnits);
        $overall = $this->aggregateOverall($scopedUnits, $benefFilter, $drvFilter, $matrix, $kmField);
        $composition = $this->buildComposition($scopedUnits, $overall, $kmShareByVehicle);
        $vehicles = $this->buildVehicleRows($scopedUnits);
        $drivers = $this->buildDriverRows($matrix, $kmField, $benefFilter, $catFilter, $vehFilter, $drvFilter);
        $beneficiaries = $this->buildBeneficiaryRows($matrix, $kmField, $drvFilter, $catFilter, $vehFilter, $benefFilter);

        $breakEven = CostBreakEvenService::compute(
            $overall['fixed_total'],
            $overall['variable_total'],
            $overall['km'],
            $overall['trips'],
            $overall['revenue']
        );

        return [
            'period' => $period,
            'km_source' => $kmSource,
            'settings' => [
                'eur_ron_rate' => (float) ($settings['eur_ron_rate'] ?: 0),
                'salariu_multiplicator' => (float) ($settings['salariu_multiplicator'] ?: 0),
                'tva_carburant_fallback' => (float) ($settings['tva_carburant_fallback'] ?: 0),
                'management_alocare' => (string) $settings['management_alocare'],
                'diurna_tarif_zi' => (string) $settings['diurna_tarif_zi'],
                'km_source' => $kmSource,
            ],
            'schema_ready' => $this->model->schemaReady(),
            'summary' => $overall,
            'breakeven' => $breakEven,
            'categories' => $categories,
            'composition' => $composition,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'beneficiaries' => $beneficiaries,
            'elements' => array_values($this->elementStatus),
            'company_rows' => $this->companyRows,
        ];
    }

    /**
     * Trasabilitate: componentele exacte care produc rezultatul unui scop.
     * Recalculează același model, apoi extrage descompunerea.
     */
    public function computeDetails(array $filters, string $scope, $id): array
    {
        $this->compute($filters);
        $rows = [];

        if ($scope === 'vehicle') {
            $unit = $this->units[(int) $id] ?? null;
            if ($unit === null) {
                return ['rows' => [], 'label' => ''];
            }
            return ['rows' => $this->unitDetailRows($unit), 'label' => $unit['label'], 'km' => $unit['km']];
        }

        if ($scope === 'category') {
            $km = 0;
            $label = (string) $id;
            $byElement = [];
            foreach ($this->units as $unit) {
                if ($unit['category'] !== $id) {
                    continue;
                }
                $label = $unit['category_label'];
                $km += $unit['km'];
                foreach ($unit['elements'] as $code => $info) {
                    if (!isset($byElement[$code])) {
                        $byElement[$code] = $info;
                        $byElement[$code]['value'] = 0.0;
                        $byElement[$code]['detail'] = [];
                    }
                    $byElement[$code]['value'] += $info['value'];
                    foreach ($info['detail'] as $d) {
                        $byElement[$code]['detail'][] = $d;
                    }
                }
            }
            foreach ($byElement as $code => $info) {
                $rows[] = $this->detailRow($info, $km);
            }
            return ['rows' => $rows, 'label' => $label, 'km' => $km];
        }

        return ['rows' => [], 'label' => ''];
    }

    /** Expune starea internă pentru testele de reconciliere. */
    public function internalUnits(): array
    {
        return $this->units;
    }

    // ==================================================================
    // Construirea unităților operaționale + rezolvarea elementelor
    // ==================================================================

    private function resolvePeriod(string $raw): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
            $raw = date('Y-m');
        }
        $start = $raw . '-01';
        $end = date('Y-m-t', strtotime($start));
        $months = ['01'=>'Ianuarie','02'=>'Februarie','03'=>'Martie','04'=>'Aprilie','05'=>'Mai','06'=>'Iunie','07'=>'Iulie','08'=>'August','09'=>'Septembrie','10'=>'Octombrie','11'=>'Noiembrie','12'=>'Decembrie'];
        return [
            'key' => $raw,
            'start' => $start,
            'end' => $end,
            'label' => ($months[substr($raw, 5, 2)] ?? '') . ' ' . substr($raw, 0, 4),
            'days' => (int) date('t', strtotime($start)),
        ];
    }

    /**
     * Construiește unitățile operaționale (ansamblu tractor+semi, camioane,
     * semiremorci necuplate) și le populează cu toate elementele financiare.
     */
    private function buildUnits(array $period, array $settings, string $kmField): void
    {
        $vehicles = $this->model->getHeavyVehicles();
        $couplings = $this->model->getActiveCouplings();
        $activity = $this->model->getActivityByVehicle($period['start'], $period['end']);
        $elements = $this->model->getElements(true);
        $eur = (float) ($settings['eur_ron_rate'] ?: 0);
        $multSalarial = (float) ($settings['salariu_multiplicator'] ?: 0);
        $vatFallback = (float) ($settings['tva_carburant_fallback'] ?: 21.0);

        // vehicul -> unitatea care îi poartă costurile (semiremorca cuplată -> tractor)
        $carrier = [];
        $coupledSemis = [];
        foreach ($couplings as $tractorId => $semiId) {
            if (isset($vehicles[$tractorId]) && isset($vehicles[$semiId])) {
                $carrier[$semiId] = $tractorId;
                $coupledSemis[$tractorId] = $semiId;
            }
        }

        $units = [];
        foreach ($vehicles as $vid => $v) {
            $isSemi = in_array($v['tip_vehicul'], ['semiremorca', 'semiremorca_primar', 'semiremorca_distributie'], true);
            if ($isSemi && isset($carrier[$vid])) {
                continue; // costurile intră în unitatea tractorului
            }
            [$catCode, $catLabel] = $this->categorize($v, isset($coupledSemis[$vid]), $isSemi);
            $act = $activity[$vid] ?? null;
            $semiId = $coupledSemis[$vid] ?? null;
            $units[$vid] = [
                'vehicle_id' => $vid,
                'label' => (string) $v['nr_inmatriculare'],
                'vehicle_status' => (string) $v['status'],
                'tip_vehicul' => (string) $v['tip_vehicul'],
                'capacitate' => $v['capacitate_transport'] !== null ? (float) $v['capacitate_transport'] : null,
                'semi_id' => $semiId,
                'semi_label' => $semiId !== null ? (string) $vehicles[$semiId]['nr_inmatriculare'] : null,
                'category' => $catCode,
                'category_label' => $catLabel,
                'km' => $act !== null ? (int) $act[$kmField] : 0,
                'km_real' => $act !== null ? (int) $act['km_real'] : 0,
                'km_facturat' => $act !== null ? (int) $act['km_facturat'] : 0,
                'trips' => $act !== null ? (int) $act['curse'] : 0,
                'revenue' => $act !== null ? (float) $act['venit'] : 0.0,
                'fixed_total' => 0.0,
                'variable_total' => 0.0,
                'elements' => [],   // cod => {value, tip, label, status, detail[]}
                'personnel_total' => 0.0,
                'quality' => [],
            ];
        }
        $this->units = $units;
        $this->companyRows = [];
        $this->elementStatus = [];

        // pregătește sursele partajate o singură dată (evită N interogări — §37)
        $drivers = $this->model->getActiveDriversWithSalary($period['end']);
        $driverDocs = $this->model->getDriverDocumentAnnualCosts();
        $fuelDiesel = $this->model->getFuelPeriodCosts($period['start'], $period['end'], 'motorina', $vatFallback);
        $fuelAdblue = $this->model->getFuelPeriodCosts($period['start'], $period['end'], 'adblue', $vatFallback);
        $regKey = static fn(string $reg): string => str_replace(' ', '', strtoupper($reg));

        foreach ($elements as $el) {
            $code = (string) $el['cod'];
            $isFix = $el['tip'] === 'fix';
            $status = ['cod' => $code, 'nume' => (string) $el['nume'], 'tip' => (string) $el['tip'],
                       'clasa_sursa' => (string) $el['clasa_sursa'], 'sursa' => (string) $el['sursa_referinta'],
                       'periodicitate' => (string) $el['periodicitate'], 'alocare' => (string) $el['alocare'],
                       'scop' => (string) $el['scop'], 'id' => (int) $el['id'],
                       'valoare_config' => $el['valoare_config'] !== null ? (float) $el['valoare_config'] : null,
                       'valoare_moneda' => (string) $el['valoare_moneda'],
                       'amortizare_ani' => $el['amortizare_ani'] !== null ? (float) $el['amortizare_ani'] : null,
                       'regim_tva' => (string) $el['regim_tva'],
                       'observatii' => (string) ($el['observatii'] ?? ''),
                       'activ' => (int) $el['activ'] === 1,
                       'total' => 0.0, 'quality' => 'lipsa', 'quality_note' => ''];

            $applied = $this->resolveElement($el, $period, $settings, $eur, $multSalarial,
                $drivers, $driverDocs, $fuelDiesel, $fuelAdblue, $regKey, $carrier, $status);

            $status['total'] = $applied;
            $this->elementStatus[$code] = $status;
        }

        // totaluri per unitate
        foreach ($this->units as &$unit) {
            $fix = 0.0;
            $var = 0.0;
            foreach ($unit['elements'] as $info) {
                if ($info['tip'] === 'fix') {
                    $fix += $info['value'];
                } else {
                    $var += $info['value'];
                }
            }
            $unit['fixed_total'] = $fix;
            $unit['variable_total'] = $var;
        }
        unset($unit);

        // vehiculele inactive fără activitate și fără costuri în perioadă sunt zgomot istoric
        $this->units = array_filter(
            $this->units,
            fn($u) => $u['vehicle_status'] === 'activ' || $u['km'] > 0 || $u['revenue'] > 0 || ($u['fixed_total'] + $u['variable_total']) > 0
        );
    }

    /**
     * Rezolvă un element financiar și distribuie valorile pe unități.
     * Returnează totalul alocat (lei, perioadă).
     */
    private function resolveElement(array $el, array $period, array $settings, float $eur, float $multSalarial,
        array $drivers, array $driverDocs, array $fuelDiesel, array $fuelAdblue, callable $regKey,
        array $carrier, array &$status): float
    {
        $code = (string) $el['cod'];
        $source = (string) $el['sursa_referinta'];
        $total = 0.0;

        $push = function (int $unitVehicleId, float $value, array $detail = [], ?int $driverId = null) use ($el, $code, &$total): void {
            if (!isset($this->units[$unitVehicleId])) {
                return;
            }
            $u = &$this->units[$unitVehicleId];
            if (!isset($u['elements'][$code])) {
                $u['elements'][$code] = [
                    'value' => 0.0, 'tip' => (string) $el['tip'], 'label' => (string) $el['nume'],
                    'cod' => $code, 'clasa_sursa' => (string) $el['clasa_sursa'], 'detail' => [],
                ];
            }
            $u['elements'][$code]['value'] += $value;
            if ($detail !== []) {
                $u['elements'][$code]['detail'][] = $detail;
            }
            if ($driverId !== null || in_array($code, ['salariu_soferi', 'analize_soferi', 'ssm_su', 'cursuri_soferi', 'diurna'], true)) {
                $u['personnel_total'] += $value;
            }
            $total += $value;
        };

        $pushCompany = function (float $value, string $note) use ($el, $code, &$total): void {
            $this->companyRows[] = [
                'cod' => $code, 'nume' => (string) $el['nume'], 'tip' => (string) $el['tip'],
                'value' => $value, 'note' => $note,
            ];
            $total += $value;
        };

        switch ($source) {
            case 'documente_vehicule':
                $docType = (string) ($el['sursa_filtru'] ?? '');
                $rows = $docType !== '' ? $this->model->getVehicleDocumentAnnualCosts($docType) : [];
                $nonZero = 0;
                foreach ($rows as $vid => $row) {
                    $unitId = $carrier[$vid] ?? $vid;
                    if ($row['annual'] <= 0) {
                        continue;
                    }
                    $nonZero++;
                    $push($unitId, $row['annual'] / 12.0, [
                        'sursa' => 'Config documente (' . $row['source'] . ')',
                        'brut' => $row['cost'] . ' lei / ' . $row['validity_days'] . ' zile',
                        'normalizare' => 'cost × 365 / validity_days / 12',
                        'valoare' => $row['annual'] / 12.0,
                        'vehicul' => $vid !== $unitId ? 'semiremorcă cuplată' : null,
                    ]);
                }
                $status['quality'] = $nonZero > 0 ? ($nonZero >= count($rows) ? 'complet' : 'partial') : 'lipsa';
                $status['quality_note'] = $nonZero > 0
                    ? $nonZero . ' vehicule cu cost configurat'
                    : 'Toate costurile de tip "' . $docType . '" sunt 0 în configurare (tabel populat, valori lipsă)';
                break;

            case 'autorizatii_vehicule':
                $rows = $this->model->getAuthorizationPeriodCosts($period['start'], $period['end']);
                foreach ($rows as $vid => $row) {
                    $unitId = $carrier[$vid] ?? $vid;
                    $push($unitId, $row['period_value'], [
                        'sursa' => 'Autorizații zone (' . $row['items'] . ')',
                        'normalizare' => 'cost / zile autorizație × zile în perioadă',
                        'valoare' => $row['period_value'],
                    ]);
                }
                $status['quality'] = $rows !== [] ? 'complet' : 'lipsa';
                $status['quality_note'] = $rows !== [] ? count($rows) . ' vehicule cu autorizații în perioadă' : 'Nicio autorizație cu cost în perioadă';
                break;

            case 'dotari_vehicule':
                $rows = $this->model->getEquipmentMonthlyCosts();
                $nonZero = 0;
                foreach ($rows as $vid => $row) {
                    if ($row['monthly'] <= 0) {
                        continue;
                    }
                    $nonZero++;
                    $unitId = $carrier[$vid] ?? $vid;
                    $push($unitId, $row['monthly'], [
                        'sursa' => 'Inventar dotări (' . $row['items'] . ' dotări)',
                        'normalizare' => 'cost / interval inspecție (luni)',
                        'valoare' => $row['monthly'],
                    ]);
                }
                $status['quality'] = $nonZero > 0 ? 'partial' : 'lipsa';
                $status['quality_note'] = $nonZero > 0 ? $nonZero . ' vehicule cu dotări alocate' : 'Catalogul are costuri, dar alocările pe vehicule lipsesc';
                break;

            case 'salarii_soferi':
                $withSalary = 0;
                foreach ($drivers as $drv) {
                    $sal = $drv['salariu_luna'];
                    if ($sal === null || $sal <= 0) {
                        continue;
                    }
                    $withSalary++;
                    $value = $sal * ($multSalarial > 0 ? $multSalarial : 1.0);
                    $unitId = $drv['vehicle_id'] !== null ? ($carrier[$drv['vehicle_id']] ?? $drv['vehicle_id']) : null;
                    $detail = [
                        'sursa' => 'soferi.salariu / salary_history — ' . $drv['nume'],
                        'brut' => number_format($sal, 2, ',', '.') . ' lei/lună',
                        'normalizare' => 'salariu × ' . $multSalarial . ' (multiplicator angajator)',
                        'valoare' => $value,
                    ];
                    if ($unitId !== null && isset($this->units[$unitId])) {
                        $push($unitId, $value, $detail, $drv['id']);
                    } else {
                        $pushCompany($value, 'Șofer fără vehicul asociat: ' . $drv['nume']);
                    }
                }
                $status['quality'] = $withSalary > 0 ? 'complet' : 'lipsa';
                $status['quality_note'] = $withSalary . ' șoferi activi cu salariu; multiplicator angajator ' . $multSalarial . ' (config)';
                break;

            case 'documente_soferi':
                $found = 0;
                foreach ($driverDocs as $driverId => $row) {
                    if (!isset($drivers[$driverId])) {
                        continue;
                    }
                    $found++;
                    $monthly = $row['annual'] / 12.0;
                    $vidAssigned = $drivers[$driverId]['vehicle_id'];
                    $unitId = $vidAssigned !== null ? ($carrier[$vidAssigned] ?? $vidAssigned) : null;
                    $detail = [
                        'sursa' => 'Config documente șoferi — ' . $drivers[$driverId]['nume'],
                        'normalizare' => 'Σ(cost × 365 / validity) / 12',
                        'valoare' => $monthly,
                    ];
                    if ($unitId !== null && isset($this->units[$unitId])) {
                        $push($unitId, $monthly, $detail, $driverId);
                    } else {
                        $pushCompany($monthly, 'Șofer fără vehicul: ' . $drivers[$driverId]['nume']);
                    }
                }
                $status['quality'] = $found > 0 ? 'partial' : 'lipsa';
                $status['quality_note'] = $found > 0 ? $found . ' șoferi cu costuri documente' : 'configurare_costuri_documente_soferi este goală (0 rânduri)';
                break;

            case 'management_office':
                $mgmt = $this->model->getManagementMonthlyCost($period['start'], $period['end']);
                if ($mgmt['total'] > 0) {
                    $mode = (string) ($settings['management_alocare'] ?? 'vehicule_active');
                    $unitIds = array_keys(array_filter($this->units, fn($u) => $u['vehicle_status'] === 'activ' && ($u['tip_vehicul'] === 'cap_tractor' || $u['tip_vehicul'] === 'camion')));
                    if ($mode === 'km') {
                        $kmTotal = 0;
                        foreach ($unitIds as $uid) {
                            $kmTotal += $this->units[$uid]['km'];
                        }
                        foreach ($unitIds as $uid) {
                            if ($kmTotal <= 0) {
                                break;
                            }
                            $share = $mgmt['total'] * $this->units[$uid]['km'] / $kmTotal;
                            if ($share <= 0) {
                                continue;
                            }
                            $push($uid, $share, [
                                'sursa' => 'Cheltuieli birou+administrative+salarii birou',
                                'normalizare' => 'alocare proporțională cu km (' . $this->units[$uid]['km'] . ' km)',
                                'valoare' => $share,
                            ]);
                        }
                        if ($kmTotal <= 0) {
                            $pushCompany($mgmt['total'], 'Nealocabil: 0 km în perioadă');
                        }
                    } else {
                        $n = count($unitIds);
                        foreach ($unitIds as $uid) {
                            $share = $n > 0 ? $mgmt['total'] / $n : 0.0;
                            $push($uid, $share, [
                                'sursa' => 'Cheltuieli birou+administrative+salarii birou',
                                'normalizare' => 'total lunar ' . number_format($mgmt['total'], 2, ',', '.') . ' lei / ' . $n . ' vehicule active',
                                'valoare' => $share,
                            ]);
                        }
                    }
                    $status['quality'] = $mgmt['has_rows'] ? ($mgmt['office_net'] + $mgmt['admin_net'] > 0 ? 'complet' : 'partial') : 'partial';
                    $status['quality_note'] = sprintf(
                        'Birou net: %.2f · Administrative net: %.2f · Salarii birou (automat): %.2f',
                        $mgmt['office_net'], $mgmt['admin_net'], $mgmt['office_salaries']
                    );
                } else {
                    $status['quality'] = 'lipsa';
                    $status['quality_note'] = 'Modulele Cheltuieli Birou / Administrative nu au înregistrări în perioadă';
                }
                break;

            case 'carburant':
            case 'adblue':
                $rows = $source === 'carburant' ? $fuelDiesel : $fuelAdblue;
                $matched = 0;
                $unmatchedValue = 0.0;
                $byKey = [];
                foreach ($this->units as $uid => $u) {
                    $byKey[$regKey($u['label'])] = $uid;
                }
                foreach ($rows as $key => $row) {
                    $uid = $byKey[$key] ?? null;
                    if ($uid === null) {
                        $unmatchedValue += $row['net'];
                        continue;
                    }
                    $matched++;
                    $push($uid, $row['net'], [
                        'sursa' => 'CardOil API (' . $row['fillups'] . ' alimentări, ' . number_format($row['litri'], 1, ',', '.') . ' l)',
                        'brut' => number_format($row['brut'], 2, ',', '.') . ' lei cu TVA',
                        'normalizare' => 'de-TVA cu cota reală per rând (raw_payload.cota_tva)',
                        'valoare' => $row['net'],
                    ]);
                }
                if ($unmatchedValue > 0) {
                    $pushCompany($unmatchedValue, 'Alimentări pe numere neasociate flotei grele active');
                }
                $status['quality'] = $matched > 0 ? 'complet' : 'lipsa';
                $status['quality_note'] = $matched . ' vehicule cu alimentări în perioadă (source_type=api, net de TVA)';
                break;

            case 'mentenanta_intretinere':
            case 'mentenanta_reparatii':
                $rt = $source === 'mentenanta_intretinere' ? 'intretinere' : 'reparatie';
                $rows = $this->model->getMaintenancePeriodCosts($period['start'], $period['end'], $rt);
                foreach ($rows as $vid => $row) {
                    if ($row['total'] <= 0) {
                        continue;
                    }
                    $unitId = $carrier[$vid] ?? $vid;
                    $push($unitId, $row['total'], [
                        'sursa' => 'Mentenanță (' . $row['items'] . ' intervenții, record_type=' . $rt . ')',
                        'normalizare' => 'sumă evenimente în perioadă (exclus "Anvelopa - %", anulate)',
                        'valoare' => $row['total'],
                    ]);
                }
                $status['quality'] = $rows !== [] ? 'partial' : 'lipsa';
                $status['quality_note'] = $rows !== []
                    ? count($rows) . ' vehicule cu intervenții în perioadă (istoric încă subțire — km_interventie nepopulat)'
                    : 'Nicio intervenție în perioadă';
                break;

            case 'ocr_piese':
                $rows = $this->model->getOcrPartsPeriodCosts($period['start'], $period['end']);
                foreach ($rows as $vid => $row) {
                    if ($row['total'] <= 0) {
                        continue;
                    }
                    $unitId = $carrier[$vid] ?? $vid;
                    $push($unitId, $row['total'], [
                        'sursa' => 'Registru piese OCR (' . $row['items'] . ' rânduri)',
                        'normalizare' => 'sumă pret + pret_manopera în perioadă',
                        'valoare' => $row['total'],
                    ]);
                }
                $status['quality'] = $rows !== [] ? 'partial' : 'lipsa';
                $status['quality_note'] = 'Atenție: ledger paralel cu mentenanța — verificați dublarea înainte de activare';
                break;

            case 'anvelope':
                $rows = $this->model->getTireRatePerKm($period['start'], $period['end']);
                $priced = 0;
                $unpriced = 0;
                foreach ($rows as $vid => $row) {
                    $priced += $row['tires_priced'];
                    $unpriced += $row['tires_unpriced'];
                    if ($row['rate_per_km'] <= 0) {
                        continue;
                    }
                    $unitId = $carrier[$vid] ?? $vid;
                    $km = $this->units[$unitId]['km'] ?? 0;
                    if ($km <= 0) {
                        continue;
                    }
                    $value = $row['rate_per_km'] * $km;
                    $push($unitId, $value, [
                        'sursa' => 'Anvelope montate (' . $row['tires_priced'] . ' cu preț)',
                        'normalizare' => 'Σ(preț achiziție / durată viață km) × km perioadă',
                        'valoare' => $value,
                    ]);
                }
                if ($priced > 0) {
                    $status['quality'] = $unpriced > 0 ? 'partial' : 'complet';
                    $status['quality_note'] = $priced . ' anvelope cu preț, ' . $unpriced . ' fără preț de achiziție';
                } else {
                    $status['quality'] = 'lipsa';
                    $status['quality_note'] = 'anvelope.purchase_price este NULL pe toate anvelopele montate — cost necunoscut (NU 0)';
                }
                break;

            case 'diurna':
            case 'taxe_drum_curse':
                $tip = $source === 'diurna' ? 'diurna' : 'taxe_drum';
                $rows = $this->model->getCourseExpensesByType($period['start'], $period['end'], $tip);
                foreach ($rows as $row) {
                    if ((float) $row['total'] <= 0) {
                        continue;
                    }
                    $vid = (int) $row['vehicle_id'];
                    $unitId = $carrier[$vid] ?? $vid;
                    $driverId = $row['driver_id'] !== null ? (int) $row['driver_id'] : null;
                    $push($unitId, (float) $row['total'], [
                        'sursa' => 'Cheltuieli cursă (' . $row['items'] . ' înregistrări, tip=' . $tip . ')',
                        'normalizare' => 'sumă realizată în perioadă (net de refacturări facturate)',
                        'valoare' => (float) $row['total'],
                        'driver_id' => $driverId,
                    ], $source === 'diurna' ? $driverId : null);
                }
                $status['quality'] = $rows !== [] ? 'partial' : 'lipsa';
                $status['quality_note'] = $rows !== [] ? count($rows) . ' grupuri cursă cu sume realizate' : 'Nicio cheltuială de tip ' . $tip . ' în perioadă';
                break;

            case 'manual':
            default:
                $value = $el['valoare_config'] !== null ? (float) $el['valoare_config'] : null;
                if ($value === null || $value == 0.0) {
                    $status['quality'] = 'lipsa';
                    $status['quality_note'] = 'Element fără sursă în aplicație și fără valoare configurată — NU este tratat ca 0';
                    break;
                }
                $monthly = $this->normalizeManualToMonthly($el, $value, $eur);
                $scope = (string) $el['scop'];
                if ($scope === 'company') {
                    $unitIds = array_keys(array_filter($this->units, fn($u) => $u['vehicle_status'] === 'activ' && ($u['tip_vehicul'] === 'cap_tractor' || $u['tip_vehicul'] === 'camion')));
                    $n = count($unitIds);
                    foreach ($unitIds as $uid) {
                        $push($uid, $n > 0 ? $monthly / $n : 0.0, [
                            'sursa' => 'Valoare configurată (firm-level)',
                            'normalizare' => $this->manualFormulaLabel($el, $eur) . ' / ' . $n . ' vehicule',
                            'valoare' => $n > 0 ? $monthly / $n : 0.0,
                        ]);
                    }
                } elseif ($scope === 'driver') {
                    foreach ($drivers as $drv) {
                        $unitId = $drv['vehicle_id'] !== null ? ($carrier[$drv['vehicle_id']] ?? $drv['vehicle_id']) : null;
                        $detail = [
                            'sursa' => 'Valoare configurată per șofer — ' . $drv['nume'],
                            'normalizare' => $this->manualFormulaLabel($el, $eur),
                            'valoare' => $monthly,
                        ];
                        if ($unitId !== null && isset($this->units[$unitId])) {
                            $push($unitId, $monthly, $detail, $drv['id']);
                        } else {
                            $pushCompany($monthly, 'Șofer fără vehicul: ' . $drv['nume']);
                        }
                    }
                } else {
                    // scope vehicle: aplică fiecărei unități compatibile cu tipuri_vehicul
                    $types = $el['tipuri_vehicul'] !== null && $el['tipuri_vehicul'] !== ''
                        ? array_map('trim', explode(',', (string) $el['tipuri_vehicul']))
                        : null;
                    foreach ($this->units as $uid => $u) {
                        if ($u['vehicle_status'] !== 'activ') {
                            continue; // costurile de stare nu se aplică vehiculelor scoase din uz
                        }
                        $unitTypes = [$u['tip_vehicul']];
                        if ($u['semi_id'] !== null) {
                            $unitTypes[] = 'semiremorca_primar';
                            $unitTypes[] = 'semiremorca_distributie';
                        }
                        if ($types !== null && array_intersect($types, $unitTypes) === []) {
                            continue;
                        }
                        if ((string) $el['periodicitate'] === 'per_km' || (string) $el['periodicitate'] === 'per_100000km') {
                            $km = $u['km'];
                            if ($km <= 0) {
                                continue;
                            }
                            $rate = (string) $el['periodicitate'] === 'per_100000km' ? $value / 100000.0 : $value;
                            $rate *= ($el['valoare_moneda'] === 'EUR' ? $eur : 1.0);
                            $push($uid, $rate * $km, [
                                'sursa' => 'Valoare configurată',
                                'normalizare' => $this->manualFormulaLabel($el, $eur) . ' × ' . $km . ' km',
                                'valoare' => $rate * $km,
                            ]);
                        } else {
                            $push($uid, $monthly, [
                                'sursa' => 'Valoare configurată',
                                'normalizare' => $this->manualFormulaLabel($el, $eur),
                                'valoare' => $monthly,
                            ]);
                        }
                    }
                }
                $status['quality'] = 'complet';
                $status['quality_note'] = 'Valoare configurată manual: ' . number_format($value, 2, ',', '.') . ' ' . $el['valoare_moneda'] . ' (' . $el['periodicitate'] . ')';
                break;
        }

        return $total;
    }

    /** Normalizarea valorilor manuale la lei/lună (§6 din specificație + §P din analiză). */
    private function normalizeManualToMonthly(array $el, float $value, float $eur): float
    {
        $lei = $value * ($el['valoare_moneda'] === 'EUR' ? ($eur > 0 ? $eur : 0.0) : 1.0);
        $ani = $el['amortizare_ani'] !== null ? (float) $el['amortizare_ani'] : null;
        if ($ani !== null && $ani > 0) {
            return $lei / $ani / 12.0;
        }
        return match ((string) $el['periodicitate']) {
            'lunar' => $lei,
            'anual' => $lei / 12.0,
            'per_zi' => $lei * 30.0,
            default => $lei / 12.0,
        };
    }

    private function manualFormulaLabel(array $el, float $eur): string
    {
        $parts = [];
        $parts[] = number_format((float) $el['valoare_config'], 2, ',', '.') . ' ' . $el['valoare_moneda'];
        if ($el['valoare_moneda'] === 'EUR') {
            $parts[] = '× curs ' . number_format($eur, 2, ',', '.');
        }
        if ($el['amortizare_ani'] !== null && (float) $el['amortizare_ani'] > 0) {
            $parts[] = '/ ' . rtrim(rtrim(number_format((float) $el['amortizare_ani'], 2, '.', ''), '0'), '.') . ' ani / 12';
        } elseif ((string) $el['periodicitate'] === 'anual') {
            $parts[] = '/ 12 luni';
        } elseif ((string) $el['periodicitate'] === 'per_100000km') {
            $parts[] = '/ 100.000 km';
        }
        return implode(' ', $parts);
    }

    /** Clasificarea dinamică pe categorii operaționale (§F din analiză). */
    private function categorize(array $vehicle, bool $hasCoupledSemi, bool $isSemi): array
    {
        $tip = (string) $vehicle['tip_vehicul'];
        if ($tip === 'cap_tractor') {
            return ['ct_sr', 'Cap tractor + Semiremorcă'];
        }
        if ($isSemi) {
            return ['semi_necuplata', 'Semiremorci necuplate'];
        }
        $cap = $vehicle['capacitate_transport'] !== null ? (float) $vehicle['capacitate_transport'] : 0.0;
        if ($cap <= 0.0) {
            return ['camion_necunoscut', 'Camioane fără capacitate'];
        }
        if ($cap <= 7.0) {
            return ['c7', '7 TO'];
        }
        if ($cap <= 10.0) {
            return ['c10', '10 TO'];
        }
        return ['c13', '13 TO'];
    }

    // ==================================================================
    // Agregări
    // ==================================================================

    private function aggregateCategories(array $units): array
    {
        $cats = [];
        $grandTotal = 0.0;
        foreach ($units as $u) {
            $grandTotal += $u['fixed_total'] + $u['variable_total'];
        }
        foreach ($units as $u) {
            $c = $u['category'];
            if (!isset($cats[$c])) {
                $cats[$c] = [
                    'code' => $c, 'label' => $u['category_label'], 'vehicles' => 0,
                    'fixed_total' => 0.0, 'variable_total' => 0.0, 'total' => 0.0,
                    'km' => 0, 'trips' => 0, 'revenue' => 0.0,
                ];
            }
            $cats[$c]['vehicles']++;
            $cats[$c]['fixed_total'] += $u['fixed_total'];
            $cats[$c]['variable_total'] += $u['variable_total'];
            $cats[$c]['total'] += $u['fixed_total'] + $u['variable_total'];
            $cats[$c]['km'] += $u['km'];
            $cats[$c]['trips'] += $u['trips'];
            $cats[$c]['revenue'] += $u['revenue'];
        }
        foreach ($cats as &$c) {
            $c['fixed_per_km'] = $c['km'] > 0 ? $c['fixed_total'] / $c['km'] : null;
            $c['variable_per_km'] = $c['km'] > 0 ? $c['variable_total'] / $c['km'] : null;
            $c['total_per_km'] = $c['km'] > 0 ? $c['total'] / $c['km'] : null;
            $c['revenue_per_km'] = $c['km'] > 0 ? $c['revenue'] / $c['km'] : null;
            $c['share_pct'] = $grandTotal > 0 ? $c['total'] / $grandTotal * 100.0 : 0.0;
        }
        unset($c);
        $order = ['ct_sr' => 1, 'c7' => 2, 'c10' => 3, 'c13' => 4, 'camion_necunoscut' => 8, 'semi_necuplata' => 9];
        usort($cats, fn($a, $b) => ($order[$a['code']] ?? 5) <=> ($order[$b['code']] ?? 5));
        return array_values($cats);
    }

    private function aggregateOverall(array $units, int $benefFilter, int $drvFilter, array $matrix, string $kmField): array
    {
        $fixed = 0.0;
        $variable = 0.0;
        $km = 0;
        $kmBilled = 0;
        $trips = 0;
        $revenue = 0.0;

        if ($benefFilter > 0 || $drvFilter > 0) {
            // scop pe activitate: costul alocat = km scop × rata unității; venitul = direct din curse
            foreach ($matrix as $row) {
                if ($benefFilter > 0 && (int) $row['beneficiar_id'] !== $benefFilter) {
                    continue;
                }
                if ($drvFilter > 0 && (int) $row['driver_id'] !== $drvFilter) {
                    continue;
                }
                $unit = $this->units[(int) $row['vehicle_id']] ?? null;
                if ($unit === null || !isset($units[(int) $row['vehicle_id']])) {
                    continue;
                }
                $rowKm = (int) $row[$kmField];
                $km += $rowKm;
                $kmBilled += (int) $row['km_facturat'];
                $trips += (int) $row['curse'];
                $revenue += (float) $row['venit'];
                if ($unit['km'] > 0) {
                    $fixed += $unit['fixed_total'] / $unit['km'] * $rowKm;
                    $variable += $unit['variable_total'] / $unit['km'] * $rowKm;
                }
            }
        } else {
            foreach ($units as $u) {
                $fixed += $u['fixed_total'];
                $variable += $u['variable_total'];
                $km += $u['km'];
                $kmBilled += $u['km_facturat'];
                $trips += $u['trips'];
                $revenue += $u['revenue'];
            }
            // costurile nealocabile pe vehicule intră DOAR în totalul flotei (documentat)
            foreach ($this->companyRows as $row) {
                if ($row['tip'] === 'fix') {
                    $fixed += $row['value'];
                } else {
                    $variable += $row['value'];
                }
            }
        }

        $total = $fixed + $variable;
        $missing = [];
        $quality = 'complet';
        foreach ($this->elementStatus as $st) {
            if (!$st['activ']) {
                continue;
            }
            if ($st['quality'] === 'lipsa') {
                $missing[] = ['cod' => $st['cod'], 'nume' => $st['nume'], 'nota' => $st['quality_note']];
                $quality = 'partial';
            } elseif ($st['quality'] === 'partial' && $quality === 'complet') {
                $quality = 'partial';
            }
        }
        if ($km <= 0) {
            $quality = 'lipsa';
        }

        return [
            'fixed_total' => $fixed,
            'variable_total' => $variable,
            'total' => $total,
            'fixed_pct' => $total > 0 ? $fixed / $total * 100.0 : 0.0,
            'variable_pct' => $total > 0 ? $variable / $total * 100.0 : 0.0,
            'km' => $km,
            'km_billed' => $kmBilled,
            'trips' => $trips,
            'revenue' => $revenue,
            'revenue_per_km' => $km > 0 ? $revenue / $km : null,
            'revenue_per_trip' => $trips > 0 ? $revenue / $trips : null,
            'cost_per_km' => $km > 0 ? $total / $km : null,
            'fixed_per_km' => $km > 0 ? $fixed / $km : null,
            'variable_per_km' => $km > 0 ? $variable / $km : null,
            'result_total' => $revenue - $total,
            'result_per_km' => $km > 0 ? ($revenue - $total) / $km : null,
            'quality' => $quality,
            'missing' => $missing,
        ];
    }

    private function buildComposition(array $units, array $overall, ?array $kmShareByVehicle = null): array
    {
        $byElement = [];
        foreach ($units as $u) {
            // pe scop de activitate (șofer/beneficiar), fiecare element se scalează
            // cu fracțiunea de km a scopului pe acea unitate — același numitor peste tot
            $factor = 1.0;
            if ($kmShareByVehicle !== null) {
                $factor = $u['km'] > 0 ? min(1.0, ($kmShareByVehicle[$u['vehicle_id']] ?? 0) / $u['km']) : 0.0;
            }
            foreach ($u['elements'] as $code => $info) {
                if (!isset($byElement[$code])) {
                    $byElement[$code] = ['cod' => $code, 'label' => $info['label'], 'tip' => $info['tip'], 'total' => 0.0];
                }
                $byElement[$code]['total'] += $info['value'] * $factor;
            }
        }
        $km = (int) $overall['km'];
        $grand = 0.0;
        foreach ($byElement as $e) {
            $grand += $e['total'];
        }
        $out = [];
        foreach ($byElement as $e) {
            if ($e['total'] <= 0) {
                continue;
            }
            $out[] = [
                'cod' => $e['cod'],
                'label' => $e['label'],
                'tip' => $e['tip'],
                'total' => $e['total'],
                'per_km' => $km > 0 ? $e['total'] / $km : null,
                'share_pct' => $grand > 0 ? $e['total'] / $grand * 100.0 : 0.0,
            ];
        }
        usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    private function buildVehicleRows(array $units): array
    {
        $rows = [];
        foreach ($units as $u) {
            $total = $u['fixed_total'] + $u['variable_total'];
            $rows[] = [
                'vehicle_id' => $u['vehicle_id'],
                'label' => $u['label'] . ($u['semi_label'] !== null ? ' + ' . $u['semi_label'] : ''),
                'category' => $u['category'],
                'category_label' => $u['category_label'],
                'km' => $u['km'],
                'trips' => $u['trips'],
                'fixed_per_km' => $u['km'] > 0 ? $u['fixed_total'] / $u['km'] : null,
                'variable_per_km' => $u['km'] > 0 ? $u['variable_total'] / $u['km'] : null,
                'total_per_km' => $u['km'] > 0 ? $total / $u['km'] : null,
                'total_cost' => $total,
                'revenue' => $u['revenue'],
                'revenue_per_km' => $u['km'] > 0 ? $u['revenue'] / $u['km'] : null,
                'result_per_km' => $u['km'] > 0 ? ($u['revenue'] - $total) / $u['km'] : null,
                'result_total' => $u['revenue'] - $total,
                'vehicle_status' => $u['vehicle_status'],
                'status' => $u['km'] > 0 ? ($u['revenue'] - $total >= 0 ? 'activ' : 'pierdere') : ($total > 0 ? 'fara_activitate' : 'inactiv'),
            ];
        }
        usort($rows, fn($a, $b) => $b['km'] <=> $a['km']);
        return $rows;
    }

    private function buildDriverRows(array $matrix, string $kmField, int $benefFilter, string $catFilter, int $vehFilter, int $drvFilter): array
    {
        $drivers = $this->model->getActiveDriversWithSalary($this->period['end']);
        $names = [];
        foreach ($drivers as $id => $d) {
            $names[$id] = $d['nume'];
        }

        $agg = [];
        foreach ($matrix as $row) {
            if ($row['driver_id'] === null) {
                continue;
            }
            if ($benefFilter > 0 && (int) $row['beneficiar_id'] !== $benefFilter) {
                continue;
            }
            $vid = (int) $row['vehicle_id'];
            $unit = $this->units[$vid] ?? null;
            if ($unit === null) {
                continue;
            }
            if ($catFilter !== '' && $unit['category'] !== $catFilter) {
                continue;
            }
            if ($vehFilter > 0 && $vid !== $vehFilter) {
                continue;
            }
            $did = (int) $row['driver_id'];
            if ($drvFilter > 0 && $did !== $drvFilter) {
                continue;
            }
            if (!isset($agg[$did])) {
                $agg[$did] = ['driver_id' => $did, 'name' => $names[$did] ?? ('Șofer #' . $did),
                              'km' => 0, 'trips' => 0, 'revenue' => 0.0, 'other_cost' => 0.0, 'vehicles' => []];
            }
            $rowKm = (int) $row[$kmField];
            $agg[$did]['km'] += $rowKm;
            $agg[$did]['trips'] += (int) $row['curse'];
            $agg[$did]['revenue'] += (float) $row['venit'];
            $agg[$did]['vehicles'][$vid] = true;
            // costuri ne-personale alocate BY_KM din rata unității
            if ($unit['km'] > 0) {
                $nonPersonnelRate = ($unit['fixed_total'] + $unit['variable_total'] - $unit['personnel_total']) / $unit['km'];
                $agg[$did]['other_cost'] += $nonPersonnelRate * $rowKm;
            }
        }

        // costul de personal DIRECT per șofer (salariu×mult + documente + diurnă + elemente manuale per șofer)
        $personnel = $this->personnelCostByDriver($drivers);

        $rows = [];
        foreach ($drivers as $did => $drv) {
            $act = $agg[$did] ?? null;
            $pers = $personnel[$did] ?? ['total' => 0.0, 'components' => []];
            if ($act === null && $pers['total'] <= 0) {
                continue;
            }
            if ($drvFilter > 0 && $did !== $drvFilter) {
                continue;
            }
            if (($benefFilter > 0 || $catFilter !== '' || $vehFilter > 0) && $act === null) {
                continue;
            }
            $km = $act['km'] ?? 0;
            $trips = $act['trips'] ?? 0;
            $revenue = $act['revenue'] ?? 0.0;
            $other = $act['other_cost'] ?? 0.0;
            $totalCost = $pers['total'] + $other;
            $rows[] = [
                'driver_id' => $did,
                'name' => $drv['nume'],
                'km' => $km,
                'trips' => $trips,
                'personnel_cost' => $pers['total'],
                'personnel_per_km' => $km > 0 ? $pers['total'] / $km : null,
                'other_cost' => $other,
                'total_cost' => $totalCost,
                'total_per_km' => $km > 0 ? $totalCost / $km : null,
                'revenue' => $revenue,
                'revenue_per_km' => $km > 0 ? $revenue / $km : null,
                'result_per_km' => $km > 0 ? ($revenue - $totalCost) / $km : null,
                'result_total' => $revenue - $totalCost,
                'status' => $km > 0 ? (($revenue - $totalCost) >= 0 ? 'ok' : (($revenue - $totalCost) / $km < -2 ? 'critic' : 'atentie')) : 'fara_activitate',
                'components' => $pers['components'],
            ];
        }
        usort($rows, fn($a, $b) => $b['km'] <=> $a['km']);
        return $rows;
    }

    /** @return array<int,array{total:float,components:array}> */
    private function personnelCostByDriver(array $drivers): array
    {
        $mult = (float) ($this->settings['salariu_multiplicator'] ?: 0);
        $driverDocs = $this->model->getDriverDocumentAnnualCosts();
        $diurna = $this->model->getCourseExpensesByType($this->period['start'], $this->period['end'], 'diurna');
        $manualDriverElements = array_filter(
            $this->elementStatus,
            fn($st) => $st['activ'] && $st['sursa'] === 'manual' && $st['scop'] === 'driver' && $st['valoare_config'] !== null && $st['valoare_config'] > 0
        );
        $eur = (float) ($this->settings['eur_ron_rate'] ?: 0);

        $out = [];
        $salariuActive = isset($this->elementStatus['salariu_soferi']) && $this->elementStatus['salariu_soferi']['activ'];
        $analizeActive = isset($this->elementStatus['analize_soferi']) && $this->elementStatus['analize_soferi']['activ'];
        $diurnaActive = isset($this->elementStatus['diurna']) && $this->elementStatus['diurna']['activ'];

        foreach ($drivers as $did => $drv) {
            $components = [];
            $total = 0.0;
            if ($salariuActive && $drv['salariu_luna'] !== null && $drv['salariu_luna'] > 0) {
                $v = $drv['salariu_luna'] * ($mult > 0 ? $mult : 1.0);
                $components[] = ['label' => 'Salariu (× ' . $mult . ')', 'value' => $v];
                $total += $v;
            }
            if ($analizeActive && isset($driverDocs[$did])) {
                $v = $driverDocs[$did]['annual'] / 12.0;
                $components[] = ['label' => 'Documente / avize șofer', 'value' => $v];
                $total += $v;
            }
            foreach ($manualDriverElements as $st) {
                $lei = (float) $st['valoare_config'] * ($st['valoare_moneda'] === 'EUR' ? $eur : 1.0);
                $ani = $st['amortizare_ani'];
                $v = $ani !== null && $ani > 0 ? $lei / $ani / 12.0 : ($st['periodicitate'] === 'lunar' ? $lei : $lei / 12.0);
                $components[] = ['label' => $st['nume'], 'value' => $v];
                $total += $v;
            }
            $out[$did] = ['total' => $total, 'components' => $components];
        }
        if ($diurnaActive) {
            foreach ($diurna as $row) {
                if ($row['driver_id'] === null || (float) $row['total'] <= 0) {
                    continue;
                }
                $did = (int) $row['driver_id'];
                if (!isset($out[$did])) {
                    $out[$did] = ['total' => 0.0, 'components' => []];
                }
                $out[$did]['total'] += (float) $row['total'];
                $out[$did]['components'][] = ['label' => 'Diurnă realizată', 'value' => (float) $row['total']];
            }
        }
        return $out;
    }

    private function buildBeneficiaryRows(array $matrix, string $kmField, int $drvFilter, string $catFilter, int $vehFilter, int $benefFilter): array
    {
        $names = $this->model->getBeneficiaries();
        $agg = [];
        foreach ($matrix as $row) {
            if ($row['beneficiar_id'] === null) {
                continue;
            }
            if ($drvFilter > 0 && (int) $row['driver_id'] !== $drvFilter) {
                continue;
            }
            $vid = (int) $row['vehicle_id'];
            $unit = $this->units[$vid] ?? null;
            if ($unit === null) {
                continue;
            }
            if ($catFilter !== '' && $unit['category'] !== $catFilter) {
                continue;
            }
            if ($vehFilter > 0 && $vid !== $vehFilter) {
                continue;
            }
            $bid = (int) $row['beneficiar_id'];
            if ($benefFilter > 0 && $bid !== $benefFilter) {
                continue;
            }
            if (!isset($agg[$bid])) {
                $agg[$bid] = ['beneficiar_id' => $bid, 'name' => $names[$bid] ?? ('Beneficiar #' . $bid),
                              'km' => 0, 'km_billed' => 0, 'trips' => 0, 'revenue' => 0.0,
                              'fixed_cost' => 0.0, 'variable_cost' => 0.0, 'vehicles' => [], 'types' => []];
            }
            $rowKm = (int) $row[$kmField];
            $agg[$bid]['km'] += $rowKm;
            $agg[$bid]['km_billed'] += (int) $row['km_facturat'];
            $agg[$bid]['trips'] += (int) $row['curse'];
            $agg[$bid]['revenue'] += (float) $row['venit'];
            $agg[$bid]['vehicles'][$vid] = true;
            $agg[$bid]['types'][(string) $row['tip_transport']] = true;
            if ($unit['km'] > 0) {
                $agg[$bid]['fixed_cost'] += $unit['fixed_total'] / $unit['km'] * $rowKm;
                $agg[$bid]['variable_cost'] += $unit['variable_total'] / $unit['km'] * $rowKm;
            }
        }
        $rows = [];
        foreach ($agg as $b) {
            $total = $b['fixed_cost'] + $b['variable_cost'];
            $rows[] = [
                'beneficiar_id' => $b['beneficiar_id'],
                'name' => $b['name'],
                'trips' => $b['trips'],
                'km' => $b['km'],
                'km_billed' => $b['km_billed'],
                'vehicles' => count($b['vehicles']),
                'types' => array_keys($b['types']),
                'fixed_per_km' => $b['km'] > 0 ? $b['fixed_cost'] / $b['km'] : null,
                'variable_per_km' => $b['km'] > 0 ? $b['variable_cost'] / $b['km'] : null,
                'total_per_km' => $b['km'] > 0 ? $total / $b['km'] : null,
                'total_cost' => $total,
                'revenue' => $b['revenue'],
                'revenue_per_km' => $b['km'] > 0 ? $b['revenue'] / $b['km'] : null,
                'result_total' => $b['revenue'] - $total,
                'result_per_km' => $b['km'] > 0 ? ($b['revenue'] - $total) / $b['km'] : null,
                'breakeven' => $b['revenue'] >= $total ? 'acopera' : 'nu_acopera',
            ];
        }
        usort($rows, fn($a, $b) => $b['km'] <=> $a['km']);
        return $rows;
    }

    private function unitDetailRows(array $unit): array
    {
        $rows = [];
        foreach ($unit['elements'] as $info) {
            $rows[] = $this->detailRow($info, $unit['km']);
        }
        return $rows;
    }

    private function detailRow(array $info, int $km): array
    {
        return [
            'cod' => $info['cod'],
            'label' => $info['label'],
            'tip' => $info['tip'],
            'clasa_sursa' => $info['clasa_sursa'],
            'value' => $info['value'],
            'per_km' => $km > 0 ? $info['value'] / $km : null,
            'km' => $km,
            'detail' => $info['detail'],
        ];
    }
}
