<?php
declare(strict_types=1);

/**
 * Mărimile vestimentare ale unui șofer (tab „Mărimi vestimentare” din Detalii șofer).
 *
 * - Articolele vin din catalogul de echipamente: echipamente_catalog cu necesita_marime = 1,
 *   activ, destinat șoferilor (sofer / ambele). Un articol nou cu mărime apare automat.
 * - Catalogul nu are o listă de mărimi per articol (mărimea e text liber pe stoc / alocări),
 *   așa că opțiunile = scala standard a familiei articolului ∪ mărimile deja folosite
 *   pentru acel articol în echipamente_stoc și echipamente_alocari.
 * - O mărime per (șofer, articol) — cheie unică în soferi_marimi_vestimentare.
 *   Mărimile sunt independente de alocări și nu ating istoricul predărilor.
 *
 * Migrare: database/migrations/2026_11_26_000001_soferi_marimi_vestimentare.sql.
 */
class DriverClothingSizeModel extends BaseModel
{
    public const TABLE = 'soferi_marimi_vestimentare';

    /** Ordinea și titlurile secțiunilor (familii de articole). */
    public const SECTIONS = [
        'superior' => 'Îmbrăcăminte — partea superioară',
        'inferior' => 'Îmbrăcăminte — partea inferioară',
        'incaltaminte' => 'Încălțăminte',
        'accesorii' => 'Accesorii',
    ];

    /** Clasificarea pe cuvinte cheie (denumire + categorie, fără diacritice). Prima potrivire câștigă. */
    private const FAMILY_RULES = [
        ['/incalt|bocanc|pantof|ghet|cizm|sandal|papuc|adidas/', 'incaltaminte'],
        ['/pantal|salopet|blugi|fust|short|bermud/', 'inferior'],
        ['/manus/', 'accesorii', 'manusi'],
        ['/accesor|casc|sapc|caciul|fular|ochelar|centur/', 'accesorii'],
        ['/tricou|bluz|hanorac|geac|camas|vest|pulover|polar|jachet|haina|uniform|imbrac|tinut/', 'superior'],
    ];

    /** Ordinea articolelor într-o secțiune (de la stratul interior spre exterior), apoi alfabetic. */
    private const ITEM_ORDER = ['tricou', 'camas', 'bluz', 'polar', 'pulover', 'hanorac', 'vest', 'jachet', 'geac',
        'pantal', 'salopet', 'bocanc', 'ghet', 'pantof', 'sandal', 'manus', 'casc', 'sapc'];

    private const SCALE_LETTERS = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL'];

    private static bool $tableReady = false;

    /** Creează tabela pe o bază nemigrată (nu se apelează într-o tranzacție: DDL face COMMIT implicit). */
    public function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `driver_id` INT UNSIGNED NOT NULL,
              `catalog_id` INT UNSIGNED NOT NULL,
              `marime` VARCHAR(20) NOT NULL,
              `updated_by` INT UNSIGNED DEFAULT NULL,
              `created_at` DATETIME NOT NULL,
              `updated_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_sofer_marime_articol` (`driver_id`, `catalog_id`),
              KEY `idx_sofer_marime_catalog` (`catalog_id`),
              CONSTRAINT `fk_sofer_marime_driver` FOREIGN KEY (`driver_id`) REFERENCES `soferi` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_sofer_marime_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `echipamente_catalog` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$tableReady = true;
    }

    /**
     * Secțiunile afișate pentru un șofer: [key, title, items[{catalog_id, denumire, marime, options[]}]].
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDriverSections(int $driverId): array
    {
        $this->ensureTable();
        $sizes = $this->getDriverSizes($driverId);
        $articles = $this->getArticles(array_keys($sizes));
        $used = $this->usedSizes(array_map(static fn (array $a): int => (int) $a['id'], $articles));

        $sections = [];
        foreach ($articles as $article) {
            $id = (int) $article['id'];
            $family = $this->family((string) $article['denumire'], (string) $article['categorie']);
            $key = $family['section'];
            if (!isset($sections[$key])) {
                $sections[$key] = [
                    'key' => $key,
                    'title' => self::SECTIONS[$key] ?? (string) $article['categorie'],
                    'items' => [],
                ];
            }
            $current = $sizes[$id] ?? '';
            $sections[$key]['items'][] = [
                'catalog_id' => $id,
                'denumire' => (string) $article['denumire'],
                'marime' => $current,
                'options' => $this->options($family['scale'], $used[$id] ?? [], $current),
            ];
        }

        $rank = function (string $name): int {
            $plain = $this->plain($name);
            foreach (self::ITEM_ORDER as $index => $needle) {
                if (str_contains($plain, $needle)) {
                    return $index;
                }
            }

            return 999;
        };
        foreach ($sections as &$section) {
            usort($section['items'], static fn (array $a, array $b): int => $rank($a['denumire']) <=> $rank($b['denumire'])
                ?: strcoll($a['denumire'], $b['denumire']));
        }
        unset($section);

        // Familiile cunoscute în ordinea fixă, apoi celelalte categorii alfabetic.
        $order = array_flip(array_keys(self::SECTIONS));
        uasort($sections, static function (array $a, array $b) use ($order): int {
            $ka = $order[$a['key']] ?? 100;
            $kb = $order[$b['key']] ?? 100;

            return $ka <=> $kb ?: strcmp((string) $a['title'], (string) $b['title']);
        });

        return array_values($sections);
    }

    /** @return array<int, string> catalog_id => marime */
    public function getDriverSizes(int $driverId): array
    {
        $this->ensureTable();
        $stmt = $this->db->prepare('SELECT catalog_id, marime FROM ' . self::TABLE . ' WHERE driver_id = :driver_id');
        $stmt->execute(['driver_id' => $driverId]);
        $sizes = [];
        foreach ($stmt->fetchAll() as $row) {
            $sizes[(int) $row['catalog_id']] = (string) $row['marime'];
        }

        return $sizes;
    }

    /**
     * Toate mărimile configurate, pentru sugestia din formularul de predare.
     *
     * @return array<int, array<int, string>> driver_id => [catalog_id => marime]
     */
    public function getAllDriverSizes(): array
    {
        $this->ensureTable();
        $map = [];
        foreach ($this->db->query('SELECT driver_id, catalog_id, marime FROM ' . self::TABLE)->fetchAll() as $row) {
            $map[(int) $row['driver_id']][(int) $row['catalog_id']] = (string) $row['marime'];
        }

        return $map;
    }

    /**
     * Salvează mărimile trimise (catalog_id => marime; '' = nesetat → se șterge).
     * Acceptă doar articole din catalogul cu mărimi și doar mărimi din opțiunile valide.
     *
     * @param array<int|string, mixed> $input
     * @return array{before: array<string, string>, after: array<string, string>, changed: bool}
     */
    public function saveDriverSizes(int $driverId, array $input, ?int $userId): array
    {
        $this->ensureTable();
        $exists = $this->db->prepare('SELECT COUNT(*) FROM soferi WHERE id = :id');
        $exists->execute(['id' => $driverId]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new InvalidArgumentException('Șoferul nu există.');
        }

        $allowed = [];
        $names = [];
        foreach ($this->getDriverSections($driverId) as $section) {
            foreach ($section['items'] as $item) {
                $allowed[(int) $item['catalog_id']] = $item['options'];
                $names[(int) $item['catalog_id']] = (string) $item['denumire'];
            }
        }

        $current = $this->getDriverSizes($driverId);
        $before = [];
        $after = [];
        $upsert = $this->db->prepare('
            INSERT INTO ' . self::TABLE . ' (driver_id, catalog_id, marime, updated_by, created_at, updated_at)
            VALUES (:driver_id, :catalog_id, :marime, :user_id, NOW(), NOW())
            ON DUPLICATE KEY UPDATE marime = VALUES(marime), updated_by = VALUES(updated_by), updated_at = NOW()
        ');
        $delete = $this->db->prepare('DELETE FROM ' . self::TABLE . ' WHERE driver_id = :driver_id AND catalog_id = :catalog_id');

        $this->db->beginTransaction();
        try {
            foreach ($input as $catalogId => $value) {
                $catalogId = (int) $catalogId;
                if (!isset($allowed[$catalogId])) {
                    throw new InvalidArgumentException('Articolul #' . $catalogId . ' nu are mărimi în catalog.');
                }
                $size = mb_substr(trim((string) $value), 0, 20);
                if ($size !== '' && !in_array($size, $allowed[$catalogId], true)) {
                    throw new InvalidArgumentException('Mărimea „' . $size . '” nu este validă pentru ' . $names[$catalogId] . '.');
                }
                $old = $current[$catalogId] ?? '';
                if ($old === $size) {
                    continue;
                }
                $before[$names[$catalogId]] = $old;
                $after[$names[$catalogId]] = $size;
                if ($size === '') {
                    $delete->execute(['driver_id' => $driverId, 'catalog_id' => $catalogId]);
                } else {
                    $upsert->execute(['driver_id' => $driverId, 'catalog_id' => $catalogId, 'marime' => $size, 'user_id' => $userId]);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['before' => $before, 'after' => $after, 'changed' => $after !== []];
    }

    /**
     * Articolele cu mărimi destinate șoferilor (+ cele inactive pe care șoferul are deja o mărime).
     *
     * @param array<int, int> $alsoIds
     * @return array<int, array<string, mixed>>
     */
    private function getArticles(array $alsoIds): array
    {
        $extra = '';
        $params = [];
        if ($alsoIds !== []) {
            $extra = ' OR id IN (' . implode(',', array_fill(0, count($alsoIds), '?')) . ')';
            $params = array_values(array_map('intval', $alsoIds));
        }
        $stmt = $this->db->prepare("
            SELECT id, denumire, categorie
            FROM echipamente_catalog
            WHERE (necesita_marime = 1 AND activ = 1 AND destinatie IN ('sofer', 'ambele'))$extra
            ORDER BY id ASC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Mărimile folosite deja pe stoc și pe alocări, per articol.
     *
     * @param array<int, int> $catalogIds
     * @return array<int, array<int, string>>
     */
    private function usedSizes(array $catalogIds): array
    {
        if ($catalogIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($catalogIds), '?'));
        $stmt = $this->db->prepare("
            SELECT catalog_id, marime FROM echipamente_stoc WHERE marime <> '' AND catalog_id IN ($in)
            UNION
            SELECT catalog_id, marime FROM echipamente_alocari WHERE marime IS NOT NULL AND marime <> '' AND catalog_id IN ($in)
        ");
        $stmt->execute(array_merge($catalogIds, $catalogIds));
        $used = [];
        foreach ($stmt->fetchAll() as $row) {
            $used[(int) $row['catalog_id']][] = trim((string) $row['marime']);
        }

        return $used;
    }

    /** @return array{section: string, scale: array<int, string>} */
    private function family(string $name, string $category): array
    {
        $plain = $this->plain($name . ' ' . $category);
        $namePlain = $this->plain($name);
        // Denumirea are prioritate (un „Bocanci” din categoria „Îmbrăcăminte” rămâne încălțăminte).
        foreach ([$namePlain, $plain] as $text) {
            foreach (self::FAMILY_RULES as $rule) {
                if (preg_match($rule[0], $text)) {
                    $variant = $rule[2] ?? $rule[1];

                    return ['section' => $rule[1], 'scale' => $this->scale($variant)];
                }
            }
        }

        return ['section' => 'cat:' . $this->plain($category), 'scale' => self::SCALE_LETTERS];
    }

    /** @return array<int, string> */
    private function scale(string $variant): array
    {
        return match ($variant) {
            'incaltaminte' => array_map('strval', range(36, 48)),
            'inferior' => array_map('strval', range(40, 64, 2)),
            'manusi' => array_map('strval', range(6, 12)),
            default => self::SCALE_LETTERS,
        };
    }

    /**
     * @param array<int, string> $scale
     * @param array<int, string> $used
     * @return array<int, string>
     */
    private function options(array $scale, array $used, string $current): array
    {
        $extra = array_values(array_diff(array_unique(array_filter(array_merge($used, [$current]), static fn (string $v): bool => $v !== '')), $scale));
        natcasesort($extra);

        return array_values(array_merge($scale, $extra));
    }

    private function plain(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    }
}
