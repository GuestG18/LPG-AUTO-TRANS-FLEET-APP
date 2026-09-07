<?php
/**
 * Bara de context a căutării: ce s-a găsit, cum se citește și cum se renunță.
 * Se include cu $search, $searchCount, $searchLabel și $searchResetUrl;
 * opțional $searchExtra (detaliu suplimentar), $searchExpandable și, pentru
 * ecranele cu două citiri ale rezultatului, $searchViews + $searchViewUrl.
 *
 * Rostul ei: pe ecranele unde indicatorii din antet rămân la nivel de flotă,
 * utilizatorul trebuie să vadă limpede că lista de dedesubt e restrânsă.
 */

$search = trim((string) ($search ?? ''));
if ($search === '') {
    return;
}

$searchCount = (int) ($searchCount ?? 0);
$searchLabel = (string) ($searchLabel ?? 'rezultate');
$searchExtra = trim((string) ($searchExtra ?? ''));
// Doar listele cu rânduri colapsabile se desfășoară singure la căutare.
$searchExpandable = (bool) ($searchExpandable ?? true);
$searchViews = is_array($searchViews ?? null) ? $searchViews : [];
?>
<div class="des-search-note <?= $searchCount === 0 ? 'is-empty' : '' ?>">
    <i class="bi <?= $searchCount === 0 ? 'bi-search-heart' : 'bi-funnel' ?>" aria-hidden="true"></i>
    <span>
        <?php if ($searchCount === 0): ?>
            Niciun rezultat pentru <strong>„<?= e($search) ?>”</strong>.
        <?php else: ?>
            <strong><?= e((string) $searchCount) ?></strong> <?= e($searchLabel) ?>
            pentru <strong>„<?= e($search) ?>”</strong><?php
                echo $searchExtra !== '' ? ' • ' . e($searchExtra) : '';
                echo $searchExpandable ? ' — rezultatele sunt deja desfășurate mai jos.' : '.';
            ?>
        <?php endif; ?>
    </span>

    <?php if ($searchViews !== [] && isset($searchViewUrl)): ?>
        <?php // Implicit rezultatele sunt pe obiecte; gruparea pe deținători rămâne la un clic. ?>
        <div class="des-view-toggle" role="group" aria-label="Mod de afișare a rezultatelor">
            <span class="des-view-toggle-label">Afișare:</span>
            <?php foreach ($searchViews as $key => $view): ?>
                <a class="des-view-btn <?= (string) ($searchActiveView ?? 'echipamente') === (string) $key ? 'is-active' : '' ?>"
                   href="<?= e($searchViewUrl((string) $key)) ?>">
                    <i class="bi <?= e((string) $view['icon']) ?>" aria-hidden="true"></i><?= e((string) $view['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <a class="des-btn des-btn-sm" href="<?= e((string) ($searchResetUrl ?? '#')) ?>">
        <i class="bi bi-x-lg" aria-hidden="true"></i>Renunță la căutare
    </a>
</div>
