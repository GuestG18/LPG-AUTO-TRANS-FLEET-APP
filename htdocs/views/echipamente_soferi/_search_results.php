<?php
/**
 * Rezultatele căutării, ORIENTATE PE OBIECT.
 *
 * Un rând = un articol care se potrivește, cu deținătorul ca simplă coloană.
 * Nu se afișează și restul echipamentelor deținătorului: căutarea trebuie să
 * izoleze exact obiectele găsite.
 *
 * Partiala este scrisă generic, pe câmpul normalizat `detinator`, ca ecranul de
 * echipamente TESA să o poată refolosi fără modificări.
 *
 * Așteaptă: $searchResults (din DriverEquipmentModel::getSearchResults),
 * $searchPageUrl (closure(int $page): string), plus helperii din index.php
 * ($money, $dash, $itemIcon, $itemDataAttributes, $canManage).
 */

$rows = is_array($searchResults['rows'] ?? null) ? $searchResults['rows'] : [];
$total = (int) ($searchResults['total'] ?? 0);
$page = (int) ($searchResults['page'] ?? 1);
$perPage = (int) ($searchResults['per_page'] ?? 25);
$totalPages = (int) ($searchResults['total_pages'] ?? 1);
$fromRow = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$toRow = min($total, $page * $perPage);
?>
<div class="des-card">
    <div class="des-table-wrap">
        <table class="des-table des-table-results">
            <thead>
                <tr>
                    <th>Echipament</th>
                    <th>Categorie</th>
                    <th>Deținător</th>
                    <th class="des-col-num">Cant.</th>
                    <th>Mărime</th>
                    <th>Identificare</th>
                    <th>Data predării</th>
                    <th>Stare / Termen</th>
                    <th>Valoare</th>
                    <th class="des-col-actions">Acțiuni</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="des-empty">Niciun echipament nu corespunde căutării.</td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $item): ?>
                <?php
                $owner = is_array($item['detinator'] ?? null) ? $item['detinator'] : [];
                $tone = (string) $item['stare_termen']['tone'];
                $rowClass = $tone === 'danger' ? 'is-critical' : ($tone === 'warning' ? 'is-flagged' : '');

                // Meniul de acțiuni este cel din panoul șoferului: aceleași
                // operațiuni, fără să fie nevoie de deschiderea rândului.
                $driver = [
                    'id' => (int) ($owner['id'] ?? 0),
                    'nume' => (string) ($owner['nume'] ?? ''),
                ];
                $cost = $item['cost_lunar'] !== null && (float) $item['cost_lunar'] > 0
                    ? $money($item['cost_lunar']) . ' / lună'
                    : $money($item['valoare_totala']);
                ?>
                <tr class="<?= e($rowClass) ?>">
                    <td class="des-item-name">
                        <span class="des-item-icon <?= (string) $item['grupa'] === 'comunicatii' ? 'is-blue' : '' ?>">
                            <i class="bi <?= e($itemIcon($item)) ?>" aria-hidden="true"></i>
                        </span><?= e((string) $item['denumire']) ?>
                    </td>
                    <td>
                        <?= e((string) $item['categorie']) ?>
                        <span class="des-note"><?= (string) $item['grupa'] === 'comunicatii' ? 'comunicații' : 'echipament fizic' ?></span>
                    </td>
                    <td>
                        <div class="des-driver">
                            <span class="des-avatar"><?= e((string) ($owner['initiale'] ?? '?')) ?></span>
                            <span>
                                <span class="des-driver-name"><?= e((string) ($owner['nume'] ?? '')) ?></span>
                                <span class="des-driver-meta"><?= e((string) ($owner['tip_label'] ?? 'Șofer')) ?></span>
                            </span>
                        </div>
                    </td>
                    <td class="des-col-num"><?= e((string) $item['cantitate']) ?></td>
                    <td><?= e($dash($item['marime'])) ?></td>
                    <td>
                        <?= e($dash($item['identificator'])) ?>
                        <?php if (trim((string) ($item['operator'] ?? '')) !== ''): ?>
                            <span class="des-note"><?= e((string) $item['operator']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_date_ro((string) $item['data_predarii'])) ?></td>
                    <td>
                        <span class="des-status is-<?= e($tone) ?>"><?= e((string) $item['stare_termen']['label']) ?></span>
                        <?php if ((string) $item['stare_termen']['note'] !== ''): ?>
                            <span class="des-note"><?= e((string) $item['stare_termen']['note']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="des-col-money"><?= e($cost) ?></td>
                    <td class="des-col-actions">
                        <?php include __DIR__ . '/_item_menu.php'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > 0): ?>
        <div class="des-table-footer">
            <span class="des-results-info">
                Afișare <?= e((string) $fromRow) ?>–<?= e((string) $toRow) ?> din <?= e((string) $total) ?> echipamente
            </span>
            <?php if ($totalPages > 1): ?>
                <nav class="des-pagination" aria-label="Paginare rezultate">
                    <a class="des-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= e($searchPageUrl(max(1, $page - 1))) ?>" aria-label="Pagina anterioară">&lsaquo;</a>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $startPage + 4);
                    $startPage = max(1, $endPage - 4);
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a class="des-page-btn <?= $i === $page ? 'is-active' : '' ?>" href="<?= e($searchPageUrl($i)) ?>"><?= e((string) $i) ?></a>
                    <?php endfor; ?>
                    <a class="des-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($searchPageUrl(min($totalPages, $page + 1))) ?>" aria-label="Pagina următoare">&rsaquo;</a>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
