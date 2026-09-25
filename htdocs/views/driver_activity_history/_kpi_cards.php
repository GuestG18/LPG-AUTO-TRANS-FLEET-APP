<?php
declare(strict_types=1);

/**
 * Cardurile KPI (cu a doua fata: defalcarea pe tip de transport).
 * Folosit la un singur sofer si la comparatie - acolo $kpis sunt totalurile selectiei.
 * Primeste din index.php: $kpis, $dashboard, $canFinancial, $diurneNote si formatarile.
 */
?>
            <?php
            $kpiCards = [
                // Cardul se desface in defalcarea pe tip de transport; fiecare rand deschide acele curse in Desfasurator.
                ['icon' => 'bi-signpost-2', 'tone' => 'blue', 'label' => 'Total curse', 'value' => (string) (int) ($kpis['total_trips'] ?? 0), 'note' => 'curse', 'breakdown' => 'trips'],
                ['icon' => 'bi-speedometer2', 'tone' => 'green', 'label' => 'Total kilometri', 'value' => $fmtNumber($kpis['total_km'] ?? 0, 0) . ' km', 'note' => 'parcursi', 'breakdown' => 'km'],
                ['icon' => 'bi-box-seam', 'tone' => 'purple', 'label' => 'Total tonaj', 'value' => $fmtNumber((float) ($kpis['total_transported_tons'] ?? 0) + (float) ($kpis['total_delivered_tons'] ?? 0), 2) . ' t', 'note' => $fmtNumber($kpis['total_transported_tons'] ?? 0, 2) . ' t transportate · ' . $fmtNumber($kpis['total_delivered_tons'] ?? 0, 2) . ' t livrate', 'breakdown' => 'tons'],
                ['icon' => 'bi-clock-history', 'tone' => 'orange', 'label' => 'Ore de condus', 'value' => $fmtDuration($kpis['driving_minutes'] ?? 0), 'note' => 'timp activ', 'breakdown' => 'minutes'],
                ['icon' => 'bi-fuel-pump', 'tone' => 'blue', 'label' => 'Consum total', 'value' => $fmtNumber($kpis['total_fuel_liters'] ?? 0, 2) . ' L', 'note' => ($kpis['average_consumption'] ?? null) !== null ? $fmtNumber($kpis['average_consumption'], 2) . ' L/100 km' : 'fara medie calculata', 'breakdown' => 'liters'],
                $canFinancial
                    ? ['icon' => 'bi-cash-coin', 'tone' => 'red', 'label' => 'Cost total', 'value' => $fmtMoney($kpis['total_costs'] ?? 0), 'note' => 'carburant, reparatii, curse, salariu, diurne', 'breakdown' => 'costs']
                    : ['icon' => 'bi-cash-coin', 'tone' => 'red', 'label' => 'Cost operational', 'value' => $fmtMoney($kpis['operational_costs'] ?? 0), 'note' => 'carburant, reparatii, curse', 'breakdown' => 'operational'],
            ];
            if ($canFinancial) {
                $kpiCards[] = [
                    'icon' => 'bi-wallet2', 'tone' => 'purple', 'label' => 'Salariu',
                    'value' => $fmtMoney($kpis['salary_cost'] ?? 0),
                    'note' => (int) ($kpis['worked_days'] ?? 0) . ' zile lucrate' . (!empty($kpis['salary_missing']) ? ' · salariu nesetat' : ''),
                    'breakdown' => 'salary',
                ];
            }
            $kpiCards[] = [
                'icon' => 'bi-calendar-check', 'tone' => 'green', 'label' => 'Diurne',
                'value' => (string) (int) ($kpis['diurne'] ?? 0),
                'note' => $diurneNote,
                'breakdown' => 'diurne',
            ];
            if ($canFinancial) {
                $kpiCards[] = [
                    'icon' => 'bi-graph-up-arrow', 'tone' => (float) ($kpis['profit'] ?? 0) < 0 ? 'red' : 'green', 'label' => 'Profit curse',
                    'value' => $fmtMoney($kpis['profit'] ?? 0),
                    'note' => 'valoare ' . $fmtMoney($kpis['trip_value'] ?? 0)
                        . ((float) ($kpis['refacturare_recovered'] ?? 0) > 0 ? ' + refacturat ' . $fmtMoney($kpis['refacturare_recovered']) : ''),
                    'breakdown' => 'profit',
                ];
            } else {
                $kpiCards[] = [
                    'icon' => 'bi-calendar-week', 'tone' => 'purple', 'label' => 'Zile lucrate',
                    'value' => (string) (int) ($kpis['worked_days'] ?? 0),
                    'note' => 'zile acoperite de curse',
                    'breakdown' => 'salary_days',
                ];
            }
            ?>
            <?php
            /*
             * Defalcarea pe tip de transport: vine din acelasi $kpis (deci din aceleasi filtre)
             * ca valoarea de pe card si se randeaza odata cu el. La hover nu se cere nimic
             * de la server, iar dupa o filtrare nu pot ramane valori vechi.
             */
            $breakdownData = (array) ($kpis['breakdown'] ?? []);
            $breakdownLabels = (array) ($breakdownData['labels'] ?? []);
            $breakdownValue = static function (float $value, string $format) use ($fmtNumber, $fmtMoney, $fmtDuration): string {
                return match ($format) {
                    'int' => (string) (int) round($value),
                    'km' => $fmtNumber($value, 0) . ' km',
                    'tons' => $fmtNumber($value, 2) . ' t',
                    'duration' => $fmtDuration($value),
                    'liters' => $fmtNumber($value, 2) . ' L',
                    'money' => $fmtMoney($value),
                    // Zilele lucrate pot fi fractionare (o zi cu curse de doua tipuri se imparte).
                    'days' => round($value, 1) == 1.0
                        ? '1 zi'
                        : (round($value, 1) == round($value) ? (string) (int) round($value) : $fmtNumber($value, 1)) . ' zile',
                    default => $fmtNumber($value, 2),
                };
            };
            /*
             * Randurile cardului "Total curse" deschid cursele acelui tip in Desfasurator
             * curse (lista explicita de id-uri, deci exact ce s-a numarat aici).
             */
            $breakdownTripIds = (array) ($breakdownData['trip_ids'] ?? []);
            $breakdownOwner = empty($isCompare) && is_array($driver ?? null)
                ? 'lui ' . (string) ($driver['nume'] ?? '')
                : 'soferilor comparati';
            $breakdownPeriod = $fmtDate($filters['date_start'] ?? null) . ' - ' . $fmtDate($filters['date_end'] ?? null);
            $breakdownTripsUrl = static function (string $bucket, string $label) use ($breakdownTripIds, $breakdownOwner, $breakdownPeriod): string {
                $ids = array_values(array_filter(array_map('intval', (array) ($breakdownTripIds[$bucket] ?? []))));
                // Fara acces la pagina tinta randul ramane text, nu un link spre 403.
                return $ids === [] || !can_route('dispecer_curse') ? '' : build_query_url([
                    'page' => 'dispecer_curse',
                    'ids' => implode(',', $ids),
                    'ids_label' => 'cursele ' . ($label !== '' ? $label . ' ' : '') . 'ale ' . $breakdownOwner . ', ' . $breakdownPeriod,
                ]);
            };
            /*
             * Randurile cardului "Consum total" deschid Carburanti pe acelasi tip de transport,
             * pe vehiculele alimentate de sofer si pe perioada filtrata (largita cat sa
             * cuprinda si alimentarile legate de curse care cad putin in afara ei).
             */
            $breakdownFuelLinks = (array) ($breakdownData['fuel_links'] ?? []);
            $breakdownFuelUrl = static function (string $bucket) use ($breakdownFuelLinks, $filters): string {
                $link = (array) ($breakdownFuelLinks[$bucket] ?? []);
                $vehicles = array_values(array_filter(array_map('strval', (array) ($link['vehicles'] ?? []))));
                if ($vehicles === [] || !can_route('carburanti')) {
                    return '';
                }
                $from = min((string) ($filters['date_start'] ?? ''), (string) ($link['from'] ?? $filters['date_start'] ?? ''));
                $to = max((string) ($filters['date_end'] ?? ''), (string) ($link['to'] ?? $filters['date_end'] ?? ''));

                return build_query_url([
                    'page' => 'carburanti',
                    'period' => format_date_ro($from) . ' - ' . format_date_ro($to),
                    'vehicles' => $vehicles,
                    'transport_group' => $bucket === '__all' ? '' : $bucket,
                    'fuel_type' => 'motorina',
                ]);
            };
            $rowLinkFor = static function (string $metricKey, string $bucket, string $label) use ($breakdownTripsUrl, $breakdownFuelUrl): array {
                return match ($metricKey) {
                    'trips' => [$breakdownTripsUrl($bucket, $label), 'Deschide cursele ' . $label . ' in Desfasurator curse'],
                    'liters' => [$breakdownFuelUrl($bucket), 'Deschide alimentarile ' . $label . ' in Carburanti'],
                    default => ['', ''],
                };
            };
            /*
             * Cardurile cu mai multe valori pe rand, in coloane aliniate:
             * - Diurne: numarul si valoarea lor (aceeasi valoare ca pe card). Valoarea
             *   lipseste fara drept financiar sau cand soferul nu are diurna stabilita.
             * - Salariu: zilele lucrate pe tipul respectiv si salariul acelor zile.
             */
            $breakdownColumns = [];
            if (isset($kpis['diurne_value'], $breakdownData['metrics']['diurne_value'])) {
                $breakdownColumns['diurne'] = ['diurne', 'diurne_value'];
            }
            if (isset($breakdownData['metrics']['salary_days'])) {
                $breakdownColumns['salary'] = ['salary_days', 'salary'];
            }
            /*
             * Cost total nu se imparte pe tip de transport, ci pe componentele lui - exact
             * termenii din care se aduna valoarea de pe card (total_costs). La Diurne intra
             * doar partea adaugata in cost (diurna deja trecuta pe cursa e in "Curse").
             * Fara drept financiar cardul e "Cost operational": doar primele trei.
             */
            $costComponents = [
                'Carburant' => (float) ($kpis['fuel_cost'] ?? 0),
                'Reparatii' => (float) ($kpis['repair_cost'] ?? 0),
                'Curse' => (float) ($kpis['trip_cost'] ?? 0),
            ];
            $operationalComponents = $costComponents;
            $costComponents['Salarii'] = (float) ($kpis['salary_cost'] ?? 0);
            $costComponents['Diurne'] = (float) ($kpis['diurne_value_in_total'] ?? 0);
            /*
             * Salarii / Diurne duc in Contabilitate personal, unde se stabilesc salariul si
             * diurna soferului. La un singur sofer lista se filtreaza pe el si se deschide
             * fereastra respectiva (open + subject); la comparatie se deschide doar lista.
             */
            $accountancyDriver = empty($isCompare) && is_array($driver ?? null) ? $driver : null;
            $accountancyUrl = static function (string $modal) use ($accountancyDriver): string {
                if (!can_route('contabilitate_personal')) {
                    return '';
                }
                $params = ['page' => 'contabilitate_personal'];
                if ($accountancyDriver !== null) {
                    $params['q'] = (string) ($accountancyDriver['nume'] ?? '');
                    $params['open'] = $modal;
                    $params['subject'] = 'driver-' . (int) ($accountancyDriver['id'] ?? 0);
                }

                return build_query_url($params);
            };
            /*
             * Reparatii -> Mentenanta, sectiunea Reparatii (facturile reparatiilor), pe perioada
             * (largita cat sa cuprinda datele reparatiilor) si pe vehicul, cand toate reparatiile
             * sunt pe acelasi vehicul (pagina filtreaza pe un singur vehicul).
             */
            $repairLink = (array) ($breakdownData['repair_link'] ?? []);
            $repairVehicleIds = array_values(array_filter(array_map('intval', (array) ($repairLink['vehicle_ids'] ?? []))));
            $repairsUrl = $repairVehicleIds === [] || !can_route('mentenanta') ? '' : build_query_url([
                'page' => 'mentenanta',
                'action' => 'repairs',
                'date_from' => min((string) ($filters['date_start'] ?? ''), (string) ($repairLink['from'] ?? $filters['date_start'] ?? '')),
                'date_to' => max((string) ($filters['date_end'] ?? ''), (string) ($repairLink['to'] ?? $filters['date_end'] ?? '')),
                'vehicle_id' => count($repairVehicleIds) === 1 ? $repairVehicleIds[0] : '',
            ]);
            $costComponentLinks = [
                'Reparatii' => [$repairsUrl, 'Deschide reparatiile in Mentenanta'],
                'Carburant' => [$breakdownFuelUrl('__all'), 'Deschide alimentarile in Carburanti'],
                'Curse' => [$breakdownTripsUrl('__all', ''), 'Deschide cursele in Desfasurator curse'],
                'Salarii' => [$accountancyUrl('salary'), 'Deschide salariul in Contabilitate personal'],
                'Diurne' => [$accountancyUrl('diurna'), 'Deschide diurna in Contabilitate personal'],
            ];
            $renderBreakdown = static function (string $metricKey) use ($breakdownData, $breakdownLabels, $breakdownValue, $rowLinkFor, $breakdownColumns, $costComponents, $operationalComponents, $costComponentLinks, $fmtMoney): string {
                if ($metricKey === 'costs' || $metricKey === 'operational') {
                    $rows = '';
                    foreach ($metricKey === 'costs' ? $costComponents : $operationalComponents as $label => $value) {
                        $rowContent = '<span>' . e($label) . '</span><strong>' . e($fmtMoney($value)) . '</strong>';
                        // Cost operational (fara drept financiar) are doar Carburant / Reparatii / Curse.
                        [$rowUrl, $rowTitle] = $costComponentLinks[$label] ?? ['', ''];
                        $rows .= $rowUrl !== ''
                            ? '<a class="is-link" href="' . e($rowUrl) . '" title="' . e($rowTitle) . '">' . $rowContent . '</a>'
                            : '<div>' . $rowContent . '</div>';
                    }

                    return '<div class="driver-history-kpi-breakdown" role="note" aria-label="Componentele costului total">' . $rows . '</div>';
                }
                $metric = (array) ($breakdownData['metrics'][$metricKey] ?? []);
                if ($metric === []) {
                    return '';
                }
                $columns = [];
                foreach ($breakdownColumns[$metricKey] ?? [$metricKey] as $columnKey) {
                    $columns[] = (array) ($breakdownData['metrics'][$columnKey] ?? []);
                }
                $format = (string) ($metric['format'] ?? 'number');
                $rows = '';
                // Etichete scurte: in card e spatiu putin, iar valoarea e ce conteaza.
                $shortLabels = ['primar' => 'Primar', 'distributie' => 'Distributie', 'primar_distributie' => 'P + D', 'compresor' => 'Compresor'];
                foreach ($breakdownLabels as $bucket => $label) {
                    $label = $shortLabels[$bucket] ?? $label;
                    // Toate cele patru tipuri raman afisate, chiar daca valoarea filtrata e 0.
                    if (!array_key_exists($bucket, (array) ($metric['values'] ?? []))) {
                        continue;
                    }
                    $rowContent = '<span>' . e((string) $label) . '</span>';
                    foreach ($columns as $column) {
                        $cellText = $breakdownValue((float) ($column['values'][$bucket] ?? 0), (string) ($column['format'] ?? $format));
                        // Total tonaj: fiecare tip are un singur fel de tone (vezi tripTons in model):
                        // Distributie si P + D livreaza, Primar si Compresor transporta.
                        if ($metricKey === 'tons') {
                            $cellText .= in_array((string) $bucket, ['distributie', 'primar_distributie'], true) ? ' livrate' : ' transportate';
                        }
                        $rowContent .= '<strong>' . e($cellText) . '</strong>';
                    }
                    [$rowUrl, $rowTitle] = $rowLinkFor($metricKey, (string) $bucket, (string) $label);
                    $rows .= $rowUrl !== ''
                        ? '<a class="is-link" href="' . e($rowUrl) . '" title="' . e($rowTitle) . '">' . $rowContent . '</a>'
                        : '<div>' . $rowContent . '</div>';
                }
                // Fara rand "Nealocat": ce nu tine de o cursa (reparatii, salariu,
                // alimentari fara cursa) nu spune nimic despre tipul de transport.
                // Cardurile care nu au nimic de impartit nu se mai desfac.
                if ($rows === '') {
                    return '';
                }

                return '<div class="driver-history-kpi-breakdown' . (count($columns) > 1 ? ' has-secondary' : '') . '" role="note" aria-label="Pe tip de transport">'
                    . $rows
                    . '</div>';
            };
            ?>
            <div class="driver-history-kpi-grid">
            <?php foreach ($kpiCards as $card): ?>
                <?php
                $cardHref = (string) ($card['href'] ?? '');
                // A doua fata a cardului (defalcarea), randata in acelasi container.
                $cardBreakdown = $renderBreakdown((string) ($card['breakdown'] ?? ''));
                $cardClass = 'driver-history-kpi-card' . ($cardBreakdown !== '' ? ' has-breakdown' : '');
                ?>
                <?php if ($cardHref !== ''): ?>
                <a class="<?= e($cardClass) ?> is-link" href="<?= e($cardHref) ?>" title="Deschide aceste curse in Desfasurator curse">
                <?php else: ?>
                <article class="<?= e($cardClass) ?>"<?= $cardBreakdown !== '' ? ' tabindex="0"' : '' ?>>
                <?php endif; ?>
                    <span class="driver-history-kpi-icon is-<?= e((string) $card['tone']) ?>"><i class="bi <?= e((string) $card['icon']) ?>" aria-hidden="true"></i></span>
                    <div>
                        <span><?= e((string) $card['label']) ?></span>
                        <strong><?= e((string) $card['value']) ?></strong>
                        <small><?= e((string) $card['note']) ?><?php if ($cardHref !== ''): ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><?php endif; ?></small>
                    </div>
                    <?= $cardBreakdown ?>
                <?= $cardHref !== '' ? '</a>' : '</article>' ?>
            <?php endforeach; ?>
            </div>
