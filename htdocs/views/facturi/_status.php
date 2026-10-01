<?php
/**
 * Eticheta de status a unei facturi, comuna listei si detaliului.
 * Defineste $invoiceStatusBadge(string $status): string (HTML).
 */
$invoiceStatusBadge = static function (string $status): string {
    [$class, $icon] = match ($status) {
        'asociata_auto' => ['bg-success-subtle text-success-emphasis', 'bi-link-45deg'],
        'asociata_manual' => ['bg-success-subtle text-success-emphasis', 'bi-hand-index'],
        'de_verificat' => ['bg-warning-subtle text-warning-emphasis', 'bi-question-diamond'],
        'in_procesare' => ['bg-info-subtle text-info-emphasis', 'bi-hourglass-split'],
        'respinsa' => ['bg-danger-subtle text-danger-emphasis', 'bi-x-octagon'],
        default => ['bg-secondary-subtle text-secondary-emphasis', 'bi-dash-circle'],
    };

    return '<span class="badge ' . $class . '"><i class="bi ' . $icon . '" aria-hidden="true"></i> '
        . e(InvoiceModel::STATUSES[$status] ?? $status) . '</span>';
};
