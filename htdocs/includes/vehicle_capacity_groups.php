<?php
declare(strict_types=1);

/**
 * Gruparea vehiculelor pe CATEGORIE de capacitate, pentru selectoarele din
 * aplicatie (Configurare transport, Carburanti, orice alt dropdown de vehicule).
 *
 * De ce exista acest fisier: pana acum fiecare pagina isi construia singura
 * grupele, direct din `capacitate_transport`. Doua efecte proaste:
 *   1. capacitatea tehnica reala era folosita drept eticheta de grupare, deci
 *      trebuia falsificata ca sa iasa grupe frumoase;
 *   2. aceeasi logica exista in trei-patru copii usor diferite.
 *
 * Acum gruparea se face DOAR pe `categorie_capacitate` (eticheta), iar
 * capacitatea reala ramane doar ceva ce se afiseaza langa vehicul si se
 * foloseste in calcule. Selectia intoarce mereu ID-uri de vehicul.
 */

if (!function_exists('vehicle_capacity_group_key')) {
    /**
     * Cheia de grup a unui vehicul: id-ul categoriei, sau 'fara' cand vehiculul
     * nu are categorie asignata (cerinta §8.9: astfel de vehicule nu dispar).
     */
    function vehicle_capacity_group_key(array $vehicle): string
    {
        $categoryId = (int) ($vehicle['categorie_capacitate_id'] ?? 0);

        return $categoryId > 0 ? 'cat_' . $categoryId : 'fara';
    }
}

if (!function_exists('vehicle_capacity_format_tons')) {
    /** "18.50" -> "18,5 t"; null / 0 -> null. Doar pentru afisare. */
    function vehicle_capacity_format_tons(mixed $capacity): ?string
    {
        if ($capacity === null || $capacity === '') {
            return null;
        }

        $value = (float) $capacity;
        if ($value <= 0) {
            return null;
        }

        $text = rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

        return $text . ' t';
    }
}

if (!function_exists('build_vehicle_capacity_groups')) {
    /**
     * Construieste grupele pentru dropdown.
     *
     * @param iterable<array<string, mixed>> $vehicles randuri cu cel putin `id`;
     *        optional `categorie_capacitate_id`, `categorie_capacitate`,
     *        `categorie_capacitate_ordine`, `capacitate_transport`.
     * @param array<string, mixed> $options
     *        - id_key            : cheia folosita ca valoare de selectie (implicit 'id')
     *        - fallback_label    : eticheta grupei fara categorie
     * @return list<array{key: string, label: string, order: int, vehicles: list<array<string, mixed>>}>
     */
    function build_vehicle_capacity_groups(iterable $vehicles, array $options = []): array
    {
        $idKey = (string) ($options['id_key'] ?? 'id');
        $fallbackLabel = (string) ($options['fallback_label'] ?? 'Fara categorie');

        $groups = [];

        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }

            $identifier = $vehicle[$idKey] ?? null;
            if ($identifier === null || trim((string) $identifier) === '' || (string) $identifier === '0') {
                continue;
            }

            $key = vehicle_capacity_group_key($vehicle);
            if (!isset($groups[$key])) {
                $categoryName = trim((string) ($vehicle['categorie_capacitate'] ?? ''));
                $isUncategorised = $key === 'fara';
                $groups[$key] = [
                    'key' => $key,
                    'label' => $isUncategorised || $categoryName === '' ? $fallbackLabel : $categoryName,
                    // Grupa "fara categorie" mereu la coada.
                    'order' => $isUncategorised
                        ? PHP_INT_MAX
                        : (int) ($vehicle['categorie_capacitate_ordine'] ?? 0),
                    'vehicles' => [],
                ];
            }

            $groups[$key]['vehicles'][] = $vehicle;
        }

        uasort($groups, static function (array $a, array $b): int {
            return ($a['order'] <=> $b['order']) ?: strcmp($a['label'], $b['label']);
        });

        return array_values($groups);
    }
}

if (!function_exists('vehicle_capacity_group_search_text')) {
    /**
     * Textul dupa care cauta filtrul din dropdown pentru un vehicul: numarul de
     * inmatriculare, marca/modelul si numele categoriei (cerinta §8.5 + §8.6).
     */
    function vehicle_capacity_group_search_text(array $vehicle, string $groupLabel): string
    {
        $parts = [
            (string) ($vehicle['nr_inmatriculare'] ?? ''),
            (string) ($vehicle['vehicle_registration'] ?? ''),
            (string) ($vehicle['marca'] ?? ''),
            (string) ($vehicle['model'] ?? ''),
            $groupLabel,
        ];

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts))) ?? ''));
    }
}
