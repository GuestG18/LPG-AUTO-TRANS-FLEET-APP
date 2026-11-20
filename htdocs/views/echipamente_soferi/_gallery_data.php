<?php
/**
 * Galeria pe categorii (comună Catalog + Echipamente șoferi / TESA): datele + scriptul.
 * Clic pe un punct / o bulă de pe siluetă -> panou cu toate produsele categoriei.
 *
 * Așteaptă: $categoryGallery (DriverEquipmentModel::buildCategoryGallery). Se include o
 * singură dată pe pagină.
 */

if (!empty($galleryIncluded)) {
    return;
}
$galleryIncluded = true;

$galleryPhotos = new EquipmentPhotoService();
$galleryJs = [];
foreach (is_array($categoryGallery ?? null) ? $categoryGallery : [] as $key => $category) {
    $galleryJs[(string) $key] = [
        'name' => $category['name'],
        'icon' => $category['icon'],
        'items' => array_map(static fn(array $item): array => [
            'id' => $item['id'],
            'nume' => $item['denumire'],
            'url' => $galleryPhotos->publicUrl(is_string($item['imagine']) ? $item['imagine'] : null),
            'pret' => $item['pret'],
            'stoc' => $item['stoc'],
            'dest' => $item['destinatie'],
            'activ' => $item['activ'],
            'marime' => $item['marime'],
        ], $category['items']),
    ];
}
?>
<link rel="stylesheet" href="<?= e(url('assets/css/echipamente-galerie.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/echipamente-galerie.css'))) ?>">
<script type="application/json" id="des-gallery-data"><?= json_encode($galleryJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(url('assets/js/echipamente-galerie.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/echipamente-galerie.js'))) ?>"></script>
