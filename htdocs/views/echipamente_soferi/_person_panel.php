<?php
/**
 * Panoul extins al unui deținător — COMPONENTĂ COMUNĂ șoferilor și TESA.
 *
 * Regula de layout, valabilă pe ambele pagini: secțiunile de echipament se
 * afișează una sub alta, fiecare pe toată lățimea. Nu există layout pe două sau
 * trei coloane, pentru că numărul de categorii poate crește oricând; o grupă
 * nouă în catalog produce automat încă o secțiune la coada listei.
 *
 * Structura:
 *   ExpandedPersonEquipment
 *       ├── CategorySection
 *       ├── CategorySection
 *       └── CategorySection
 *
 * Așteaptă: $driver (rând din DriverEquipmentModel::getOwnerRows, cu cheia
 * `sectiuni`), $canManage și helperii $money / $dash / $itemDataAttributes.
 */

$sections = is_array($driver['sectiuni'] ?? null) ? $driver['sectiuni'] : [];
$panelKey = (string) ($driver['detinator_tip'] ?? 'sofer') . '-' . (int) $driver['id'];

/** Iconița articolului, comună celor două pagini. */
$panelItemIcon = static function (array $item): string {
    $name = mb_strtolower((string) ($item['denumire'] ?? ''));

    return match (true) {
        str_contains($name, 'sim') => 'bi-sim',
        str_contains($name, 'telefon') => 'bi-phone',
        str_contains($name, 'tablet') => 'bi-tablet',
        str_contains($name, 'laptop') => 'bi-laptop',
        str_contains($name, 'monitor') => 'bi-display',
        str_contains($name, 'tastatur') => 'bi-keyboard',
        str_contains($name, 'mouse') => 'bi-mouse',
        str_contains($name, 'docking') => 'bi-hdd-stack',
        str_contains($name, 'headset') => 'bi-headset',
        str_contains($name, 'imprimant'), str_contains($name, 'printer') => 'bi-printer',
        str_contains($name, 'badge') => 'bi-person-badge-fill',
        str_contains($name, 'chei') => 'bi-key',
        str_contains($name, 'token') => 'bi-shield-lock',
        str_contains($name, 'card') => 'bi-credit-card-2-front',
        str_contains($name, 'radio'), str_contains($name, 'stație'), str_contains($name, 'statie') => 'bi-broadcast-pin',
        str_contains($name, 'bocanc') => 'bi-boombox',
        str_contains($name, 'cască'), str_contains($name, 'casca') => 'bi-shield',
        str_contains($name, 'vestă'), str_contains($name, 'vesta') => 'bi-vignette',
        str_contains($name, 'trusă'), str_contains($name, 'trusa'), str_contains($name, 'scule') => 'bi-tools',
        str_contains($name, 'geacă'), str_contains($name, 'geaca'), str_contains($name, 'pantaloni') => 'bi-bag',
        default => (string) ($item['grupa'] ?? 'fizic') === 'comunicatii' ? 'bi-broadcast' : 'bi-bag',
    };
};
?>
<div class="des-panel">
    <div class="des-panel-head">
        <div class="des-panel-title">
            <i class="bi <?= (string) ($driver['detinator_tip'] ?? 'sofer') === 'tesa' ? 'bi-briefcase' : 'bi-person-badge' ?>" aria-hidden="true"></i>
            <strong><?= e((string) $driver['nume']) ?></strong>
            <span>— <?= e((string) $driver['obiecte_active']) ?> obiecte active</span>
        </div>
        <div class="des-chips">
            <?php // Un contor per secțiune existentă, în aceeași ordine ca panourile. ?>
            <?php foreach ($sections as $index => $section): ?>
                <span class="des-pill <?= ['is-blue', 'is-green', 'is-amber', 'is-muted'][$index % 4] ?>">
                    <?= e((string) $section['total']) ?> <?= e(mb_strtolower((string) $section['titlu'])) ?>
                </span>
            <?php endforeach; ?>
            <span class="des-pill <?= (int) $driver['de_returnat'] > 0 ? 'is-red' : 'is-muted' ?>"><?= e((string) $driver['de_returnat']) ?> de returnat</span>
            <?php if ($canManage): ?>
                <button class="des-btn des-btn-primary des-btn-sm" type="button" data-des-open="handover"
                        data-des-sofer-id="<?= e((string) $driver['id']) ?>" data-des-sofer="<?= e((string) $driver['nume']) ?>">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă echipament
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php // O singură coloană: fiecare secțiune ocupă toată lățimea disponibilă. ?>
    <div class="des-sections">
        <?php if ($sections === []): ?>
            <div class="des-subcard">
                <p class="des-empty mb-0">Nicio alocare pentru această persoană.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($sections as $section): ?>
            <?php
            $sectionId = 'des-sec-' . e($panelKey) . '-' . e((string) $section['cheie']);
            $itemIcon = $panelItemIcon;
            include __DIR__ . '/_person_section.php';
            ?>
        <?php endforeach; ?>
    </div>
</div>
