<?php
declare(strict_types=1);

/**
 * Formatarile si helper-ele cardurilor KPI din Centralizator facturare (_kpi_cards.php).
 * Comune paginii Centralizator si cardului din Tablou de bord.
 */
$fmt = static function (float|int|string|null $value, int $decimals = 2): string {
    $number = is_numeric($value) ? (float) $value : 0.0;
    return number_format($number, $decimals, ',', '.');
};
$fmtSmart = static function (float|int|string|null $value, int $maxDecimals = 2) use ($fmt): string {
    $number = is_numeric($value) ? (float) $value : 0.0;
    $decimals = abs($number - round($number)) < 0.0001 ? 0 : $maxDecimals;
    return $fmt($number, $decimals);
};
$fmtMoneyKpi = static fn (float|int|string|null $value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 0) . ' RON';
$fmtPercent = static fn (float|int|string|null $value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 2) . '%';

$comparisonMarkup = static function (?array $comparison): string {
    if ($comparison === null) {
        return '<span class="cf-compare-label">vs. luna anterioară</span>';
    }
    $direction = (string) ($comparison['direction'] ?? 'up');
    $class = $direction === 'down' ? 'is-down' : 'is-up';
    $icon = $direction === 'down' ? 'bi-arrow-down' : 'bi-arrow-up-right';
    $percent = number_format((float) ($comparison['percent'] ?? 0), 2, ',', '.') . '%';
    return '<span class="cf-compare-label">vs. luna anterioară</span><strong class="cf-compare ' . e($class) . '">' . e($percent) . ' <i class="bi ' . e($icon) . '" aria-hidden="true"></i></strong>';
};

/* Variatia compacta pe un rand din defalcarea unui card; fara luna anterioara nu afisam nimic. */
$breakdownChange = static function (?array $comparison): string {
    if ($comparison === null) {
        return '';
    }
    $isDown = (string) ($comparison['direction'] ?? 'up') === 'down';
    $percent = number_format((float) ($comparison['percent'] ?? 0), 2, ',', '.') . '%';
    return '<span class="cf-kpi-bd-change ' . ($isDown ? 'is-down' : 'is-up') . '" title="vs. luna anterioară">'
        . '<i class="bi ' . ($isDown ? 'bi-arrow-down' : 'bi-arrow-up-right') . '" aria-hidden="true"></i> ' . e($percent)
        . '</span>';
};

$kpiValue = static function (array $card) use ($fmtMoneyKpi, $fmtSmart): string {
    $unit = (string) ($card['unit'] ?? '');
    $value = $card['value'] ?? 0;
    if ($unit === 'RON') {
        return $fmtMoneyKpi($value);
    }
    return $fmtSmart($value, $unit === 'tone' ? 2 : 0) . ($unit !== '' ? ' ' . $unit : '');
};
