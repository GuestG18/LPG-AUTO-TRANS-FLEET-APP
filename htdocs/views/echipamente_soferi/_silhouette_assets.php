<?php
/**
 * Siluetele (Șofer / TESA × Masculin / Feminin) și punctele lor de pe corp,
 * comune pentru Catalog echipamente și cardul „Valoare echipamente alocate”.
 *
 * Dacă în assets/img/echipamente există {rol}-{variantă}.webp sau .png, acestea
 * au prioritate față de SVG-urile generate. Punctele de pe corp se pot ajusta
 * per imagine în anchors.json: { "sofer-m": { "cap": [50, 5], ... }, ... }.
 * Imaginile casetelor: categorii/{slug}.webp|png.
 *
 * Definește: $findAsset(string): ?string, $img(rol, variantă): string,
 *            $anchorFor(rol, variantă, zonă): array{float, float}, $silhouetteAnchors (toate).
 */

$imgDir = BASE_PATH . '/assets/img/echipamente/';
$findAsset = static function (string $relative) use ($imgDir): ?string {
    foreach (['webp', 'png', 'svg'] as $ext) {
        $file = $imgDir . $relative . '.' . $ext;
        if (is_file($file)) {
            return url('assets/img/echipamente/' . $relative . '.' . $ext . '?v=' . (string) filemtime($file));
        }
    }

    return null;
};
$img = static fn(string $role, string $variant): string => (string) $findAsset($role . '-' . $variant);
$customAnchors = [];
if (is_file($imgDir . 'anchors.json')) {
    $decoded = json_decode((string) file_get_contents($imgDir . 'anchors.json'), true);
    $customAnchors = is_array($decoded) ? $decoded : [];
}
$anchorFor = static function (string $role, string $variant, string $zone) use ($customAnchors): array {
    $custom = $customAnchors[$role . '-' . $variant][$zone] ?? null;
    if (is_array($custom) && count($custom) === 2) {
        return [(float) $custom[0], (float) $custom[1]];
    }
    $defaults = DriverEquipmentModel::SILHOUETTE_ANCHORS[$role] ?? DriverEquipmentModel::SILHOUETTE_ANCHORS['sofer'];

    return $defaults[$zone] ?? $defaults['alte'];
};
$silhouetteAnchors = [];
foreach (['sofer', 'tesa'] as $silRole) {
    foreach (['m', 'f'] as $silVariant) {
        foreach (array_keys(DriverEquipmentModel::SILHOUETTE_ANCHORS['sofer']) as $silZone) {
            $silhouetteAnchors[$silRole . '-' . $silVariant][$silZone] = $anchorFor($silRole, $silVariant, $silZone);
        }
    }
}
