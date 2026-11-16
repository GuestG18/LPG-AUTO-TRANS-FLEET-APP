<?php
declare(strict_types=1);

/**
 * Formatarile si nota diurnelor folosite de cardurile KPI (_kpi_cards.php).
 * Comune paginii Istoric activitati sofer si Tabloului de bord (cardul „Activitate șoferi”).
 */
$fmtNumber = static function (mixed $value, int $decimals = 2): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return format_number_ro((float) $value, $decimals);
};
$fmtMoney = static function (mixed $value) use ($fmtNumber): string {
    return $fmtNumber((float) $value, 2) . ' lei';
};
$fmtDuration = static function (mixed $minutes): string {
    $minutes = max(0, (int) $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $hours . 'h ' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT) . 'm';
};
$fmtDate = static fn (mixed $value): string => format_date_ro((string) ($value ?? ''));
// Nota diurnelor: valoarea in lei dupa regula soferului din Contabilitate Personal.
$diurnaNoteFor = static function (array $k) use ($fmtMoney): string {
    $policy = (array) ($k['diurna_policy'] ?? []);
    if (($policy['status'] ?? '') === DriverDiurnaModel::STATUS_NONE && (int) ($k['diurne'] ?? 0) === 0) {
        return 'nu primeste diurna';
    }
    $parts = [];
    if (!isset($k['diurne_value'])) {
        return 'din ' . (int) ($k['diurne_trips'] ?? 0) . ' curse';
    }
    if ((float) ($k['diurne_value'] ?? 0) > 0) {
        $parts[] = $fmtMoney($k['diurne_value']);
    }
    if ((int) ($k['diurne_unvalued'] ?? 0) > 0) {
        $parts[] = (int) $k['diurne_unvalued'] . ' fara valoare / zi';
    }
    if ((int) ($k['diurne_missing'] ?? 0) > 0) {
        $parts[] = (int) $k['diurne_missing'] . ' curse fara ora';
    }

    return $parts === [] ? 'din ' . (int) ($k['diurne_trips'] ?? 0) . ' curse' : implode(' · ', $parts);
};
