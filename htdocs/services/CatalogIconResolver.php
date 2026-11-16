<?php
declare(strict_types=1);

/**
 * Iconitele din Catalog componente, rezolvate dupa ierarhie (o singura sursa: tabelele
 * mentenanta_auto_ramuri / _subcategorii / _categorii / _componente prin AutoComponentCatalogService).
 *
 * Componentele nu au iconita proprie in catalog: iconita vine din categoria lor (grupul de
 * componente), apoi din subcategorie, apoi din ramura principala; „bi-circle” = iconita
 * nesetata (valoarea implicita a catalogului), deci se urca un nivel. Fara potrivire → DEFAULT.
 * Nu se compara denumiri: legatura e prin cheia componentei (cat-nr) sau id-ul categoriei.
 */
final class CatalogIconResolver
{
    public const DEFAULT_ICON = 'bi-tools';
    private const UNSET = ['', 'bi-circle'];

    /** @var array<string, mixed>|null */
    private ?array $tree = null;

    public function __construct(private ?PDO $db = null)
    {
    }

    /** @return array<string, mixed> */
    private function tree(): array
    {
        if ($this->tree !== null) {
            return $this->tree;
        }
        $taxonomy = AutoComponentCatalogService::taxonomy($this->db);
        $components = [];
        try {
            foreach ((new AutoComponentCatalogService($this->db))->components(true) as $key => $component) {
                $components[(string) $key] = (int) $component['category_id'];
            }
        } catch (Throwable) {
            $components = [];
        }

        return $this->tree = [
            'components' => $components,
            'categories' => $taxonomy['categories'],
            'subcategories' => $taxonomy['subcategories'],
            'primaries' => $taxonomy['primaries'],
        ];
    }

    /**
     * @return array{icon: string, level: string, category_id: ?int, category: ?string, subcategory: ?string, primary: ?string}
     *   level = component | category | subcategory | primary | default (de unde vine iconita)
     */
    public function resolve(?string $componentKey, ?int $categoryId = null, ?string $vehicleType = null): array
    {
        $tree = $this->tree();
        $level = 'category';
        if ($componentKey !== null && $componentKey !== '' && isset($tree['components'][$componentKey])) {
            $categoryId = $tree['components'][$componentKey];
            $level = 'component';
        }
        $result = ['icon' => self::DEFAULT_ICON, 'level' => 'default', 'category_id' => null, 'category' => null, 'subcategory' => null, 'primary' => null];
        $category = $categoryId !== null ? ($tree['categories'][$categoryId] ?? null) : null;
        if ($category === null) {
            return $result;
        }
        $result['category_id'] = (int) $category['id'];
        $result['category'] = (string) $category['name'];
        $sub = $tree['subcategories'][(string) $category['subcategory']] ?? null;
        $primary = $sub !== null ? $this->primaryFor((string) $sub['key'], $vehicleType) : null;
        $result['subcategory'] = $sub !== null ? (string) $sub['label'] : null;
        $result['primary'] = $primary !== null ? (string) $primary['label'] : null;

        foreach ([[$category, $level], [$sub, 'subcategory'], [$primary, 'primary']] as [$node, $nodeLevel]) {
            if ($node !== null && !in_array((string) ($node['icon'] ?? ''), self::UNSET, true)) {
                $result['icon'] = (string) $node['icon'];
                $result['level'] = $nodeLevel;

                return $result;
            }
        }

        return $result;
    }

    /** Ramura principala a unei subcategorii (poate sta in mai multe ramuri: prima activa, preferand tipul vehiculului). */
    private function primaryFor(string $subKey, ?string $vehicleType): ?array
    {
        $fallback = null;
        foreach ($this->tree()['primaries'] as $primary) {
            if (empty($primary['active']) || !in_array($subKey, (array) $primary['subcategories'], true)) {
                continue;
            }
            if ($vehicleType === null || in_array($vehicleType, (array) $primary['vehicle_types'], true)) {
                return $primary;
            }
            $fallback ??= $primary;
        }

        return $fallback;
    }

    /**
     * Categoriile active (grupuri de componente) cu componentele lor, pentru selectoare.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pickerTree(): array
    {
        $tree = $this->tree();
        $componentsByCategory = [];
        try {
            foreach ((new AutoComponentCatalogService($this->db))->components() as $key => $component) {
                $componentsByCategory[(int) $component['category_id']][] = ['key' => (string) $key, 'name' => (string) $component['name'], 'code' => (string) $component['code']];
            }
        } catch (Throwable) {
            $componentsByCategory = [];
        }
        $result = [];
        $seen = [];
        foreach ($tree['primaries'] as $primary) {
            if (empty($primary['active'])) {
                continue;
            }
            foreach ((array) $primary['subcategories'] as $subKey) {
                $sub = $tree['subcategories'][$subKey] ?? null;
                if ($sub === null || empty($sub['active'])) {
                    continue;
                }
                foreach ((array) $sub['categories'] as $categoryId) {
                    $category = $tree['categories'][(int) $categoryId] ?? null;
                    if ($category === null || empty($category['active']) || isset($seen[(int) $categoryId])) {
                        continue;
                    }
                    $seen[(int) $categoryId] = true;
                    $result[] = [
                        'id' => (int) $categoryId,
                        'name' => (string) $category['name'],
                        'icon' => $this->resolve(null, (int) $categoryId)['icon'],
                        'path' => $primary['label'] . ' › ' . $sub['label'],
                        'components' => $componentsByCategory[(int) $categoryId] ?? [],
                    ];
                }
            }
        }

        return $result;
    }
}
