<?php
declare(strict_types=1);

/**
 * Contextul cardurilor KPI Carburanti (_kpi_cards.php): eticheta perioadei, formatarile si nota
 * consumului mediu. Comun paginii Carburanti si Tabloului de bord.
 *
 * @var array $filters  FuelController::collectFilters()
 * @var array $kpis     FuelModel::getDashboardData()['kpis']
 */
$selectedVehicles = is_array($filters['vehicles'] ?? null) ? array_values(array_filter(array_map('strval', $filters['vehicles']), static fn (string $v): bool => trim($v) !== '')) : [];

// ---------------------------------------------------------------------
// Eticheta afisata in campul unic de perioada: "Iulie 2026" pentru o luna
// intreaga, altfel "01.07.2026 – 15.07.2026".
// ---------------------------------------------------------------------
$monthNamesRo = [1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie', 5 => 'Mai', 6 => 'Iunie',
    7 => 'Iulie', 8 => 'August', 9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie'];
$periodLabel = (string) ($filters['period'] ?? '');
try {
    $periodFromDate = new DateTimeImmutable((string) ($filters['date_from'] ?? ''));
    $periodToDate = new DateTimeImmutable((string) ($filters['date_to'] ?? ''));
    if ($periodFromDate->format('j') === '1'
        && $periodToDate->format('Y-m-d') === $periodFromDate->modify('last day of this month')->format('Y-m-d')) {
        $periodLabel = $monthNamesRo[(int) $periodFromDate->format('n')] . ' ' . $periodFromDate->format('Y');
    } else {
        $periodLabel = $periodFromDate->format('d.m.Y') . ' – ' . $periodToDate->format('d.m.Y');
    }
} catch (Throwable) {
    // pastreaza eticheta implicita din filtre
}

$formatLiters = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' L';
$formatCurrency = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' lei';
$formatPercent = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' %';
$formatKm = static fn (mixed $value): string => (float) $value > 0 ? format_number_ro((float) $value, 0) . ' km' : '-';
$consumptionKmSource = (string) ($kpis['consumption_km_source'] ?? '');
$consumptionDetail = '';
if ((float) ($kpis['consumption_liters'] ?? 0) > 0 && (float) ($kpis['linked_km'] ?? 0) > 0) {
    $consumptionDetail = $formatLiters((float) ($kpis['consumption_liters'] ?? 0))
        . ' / '
        . $formatKm((float) ($kpis['linked_km'] ?? 0));
    if ($consumptionKmSource === 'alimentari') {
        $consumptionDetail .= ' din alimentari';
    } elseif ($consumptionKmSource === 'dispecer') {
        $consumptionDetail .= ' din dispecer';
    }
}
