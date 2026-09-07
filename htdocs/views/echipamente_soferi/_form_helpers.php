<?php
/**
 * Helperi comuni formularelor modulului de echipamente (alocări și stoc).
 * Toate formularele trimit POST către controller și păstrează filtrele curente,
 * ca utilizatorul să revină exact în lista din care a pornit.
 */

$formAction = static fn(string $action): string => build_query_url(['page' => 'echipamente_soferi', 'action' => $action]);

/** Filtrele active, propagate prin POST pentru redirectul de după operațiune. */
$keepFilters = static function () use ($filters): void {
    foreach (['luna', 'categorie', 'tip_activ', 'status', 'returnabil', 'locatie', 'tip', 'q'] as $key) {
        $value = trim((string) ($filters[$key] ?? ''));
        if ($value !== '') {
            echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
        }
    }
    // Numele este diferit de "driver_id" pentru că formularul de predare are
    // deja un câmp driver_id — cel al șoferului care primește echipamentul.
    if ((int) ($filters['driver_id'] ?? 0) > 0) {
        echo '<input type="hidden" name="filtru_driver_id" value="' . e((string) $filters['driver_id']) . '">';
    }
};

/**
 * Opțiunile de articol, cu logica proprie citită de JS din data-atribute.
 *
 * $group filtrează pe grupa articolului, iar $destination pe destinația din
 * catalog (șofer / TESA / ambele) — o sugestie, nu un inventar separat: stocul
 * rămâne comun, iar articolele „ambele” apar în ambele formulare.
 */
$catalogOptions = static function (array $items, string $group = '', string $destination = ''): void {
    foreach ($items as $item) {
        if ($group !== '' && (string) $item['grupa'] !== $group) {
            continue;
        }
        if ($destination !== '' && !in_array((string) ($item['destinatie'] ?? 'ambele'), [$destination, 'ambele'], true)) {
            continue;
        }
        echo '<option value="' . e((string) $item['id']) . '"'
            . ' data-grupa="' . e((string) $item['grupa']) . '"'
            . ' data-categorie="' . e((string) $item['categorie']) . '"'
            . ' data-logic="' . e((string) $item['tip_logic']) . '"'
            . ' data-marime="' . ((int) $item['necesita_marime'] === 1 ? '1' : '0') . '"'
            . ' data-identificator="' . ((int) $item['necesita_identificator'] === 1 ? '1' : '0') . '"'
            . ' data-cost="' . e(number_format((float) $item['cost_implicit'], 2, '.', '')) . '"'
            . ' data-stoc="' . e((string) $item['stoc_total']) . '"'
            . ' data-serializat="' . ((int) ($item['serializat'] ?? 0) === 1 ? '1' : '0') . '"'
            . ' data-locatie="' . e((string) $item['locatie_implicita']) . '"'
            . ' data-destinatie="' . e((string) ($item['destinatie'] ?? 'ambele')) . '"'
            . ' data-cost-lunar="' . e($item['cost_lunar'] !== null ? number_format((float) $item['cost_lunar'], 2, '.', '') : '') . '"'
            . '>' . e((string) $item['denumire']) . ' — ' . e((string) $item['categorie']) . '</option>';
    }
};
?>

