<?php
declare(strict_types=1);

/**
 * „Facturi” în Tablou de bord: cardurile de sumar din pagina Facturi (views/facturi/_summary_cards.php).
 * Fără reasocierea automată pe care pagina o face la deschidere: cifrele sunt starea curentă din baza de date.
 *
 * @var array $summary       InvoiceModel::getSummary()
 * @var array $invoiceQuery  parametrii paginii Facturi (perioadă, vehicul)
 */
$money = static fn(mixed $value, string $currency = 'RON'): string => $value === null || $value === ''
    ? '—'
    : format_number_ro((float) $value, 2) . ' ' . ($currency === 'RON' ? 'lei' : $currency);
$statusUrl = static fn(string $status): string => build_query_url(array_merge($invoiceQuery, ['status' => $status]));
?>
<article class="dashboard-metric-card dash-source-card dash-invoices-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-file-earmark-ruled"></i></span>
            <h2>Facturi</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e(build_query_url($invoiceQuery)) ?>"><span>Deschide Facturi</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <p class="das-caption">Toate tipurile · după data documentului</p>
    <?php require BASE_PATH . '/views/facturi/_summary_cards.php'; ?>
</article>
