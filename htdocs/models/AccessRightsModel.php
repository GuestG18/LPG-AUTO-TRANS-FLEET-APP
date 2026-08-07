<?php
declare(strict_types=1);

/**
 * Stocarea drepturilor de acces per-utilizator + sabloane de rol.
 *
 * Tabele (create automat la prima folosire, urmand conventia app-ului):
 *   - access_permissions          : (user_id, page_key, action_key) acordate explicit
 *   - access_user_state           : marcheaza utilizatorii "configurati" (customized_at)
 *   - access_templates            : sabloane de rol reutilizabile
 *   - access_template_permissions : permisiunile fiecarui sablon
 *
 * Semantica:
 *   - Un utilizator "neconfigurat" (fara rand in access_user_state) foloseste
 *     comportamentul legacy bazat pe rol -> nimic nu se schimba pana cand
 *     adminul nu salveaza explicit drepturile lui.
 *   - Odata configurat, accesul e guvernat STRICT de randurile din access_permissions
 *     (zero randuri = niciun acces), cu exceptia adminului care are mereu acces.
 */
class AccessRightsModel extends BaseModel
{
    private static bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS access_permissions (
                user_id INT UNSIGNED NOT NULL,
                page_key VARCHAR(64) NOT NULL,
                action_key VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, page_key, action_key),
                INDEX idx_access_permissions_user (user_id),
                CONSTRAINT fk_access_permissions_user FOREIGN KEY (user_id)
                    REFERENCES utilizatori(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS access_user_state (
                user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                customized_at DATETIME NOT NULL,
                customized_by INT UNSIGNED NULL,
                CONSTRAINT fk_access_user_state_user FOREIGN KEY (user_id)
                    REFERENCES utilizatori(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS access_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL UNIQUE,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS access_template_permissions (
                template_id INT UNSIGNED NOT NULL,
                page_key VARCHAR(64) NOT NULL,
                action_key VARCHAR(64) NOT NULL,
                PRIMARY KEY (template_id, page_key, action_key),
                CONSTRAINT fk_access_template_permissions_template FOREIGN KEY (template_id)
                    REFERENCES access_templates(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaEnsured = true;
    }

    /**
     * @return array<string,bool> lista id-urilor de utilizatori configurati (customizati)
     */
    public function getConfiguredUserIds(): array
    {
        $this->ensureSchema();
        $ids = [];
        foreach ($this->db->query('SELECT user_id FROM access_user_state')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(string) (int) $id] = true;
        }

        return $ids;
    }

    public function isConfigured(int $userId): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT 1 FROM access_user_state WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<string,array<string,bool>> [page_key][action_key] => true
     */
    public function getUserPermissions(int $userId): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT page_key, action_key FROM access_permissions WHERE user_id = ?');
        $stmt->execute([$userId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['page_key']][(string) $row['action_key']] = true;
        }

        return $map;
    }

    /**
     * Inlocuieste complet drepturile unui utilizator si il marcheaza drept configurat.
     *
     * @param array<string,array<int,string>> $granted [page_key => [action_key, ...]]
     */
    public function saveUserPermissions(int $userId, array $granted, ?int $actorId = null): void
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM access_permissions WHERE user_id = ?');
            $del->execute([$userId]);

            $ins = $this->db->prepare(
                'INSERT INTO access_permissions (user_id, page_key, action_key, created_at) VALUES (?, ?, ?, ?)'
            );
            foreach ($granted as $pageKey => $actions) {
                foreach ((array) $actions as $actionKey) {
                    $actionKey = trim((string) $actionKey);
                    if ($actionKey === '') {
                        continue;
                    }
                    $ins->execute([$userId, (string) $pageKey, $actionKey, $now]);
                }
            }

            $state = $this->db->prepare(
                'INSERT INTO access_user_state (user_id, customized_at, customized_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE customized_at = VALUES(customized_at), customized_by = VALUES(customized_by)'
            );
            $state->execute([$userId, $now, $actorId]);

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /**
     * Readuce utilizatorul la comportamentul implicit bazat pe rol (sterge configurarea).
     */
    public function resetUser(int $userId): void
    {
        $this->ensureSchema();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM access_permissions WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM access_user_state WHERE user_id = ?')->execute([$userId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUsers(): array
    {
        $this->ensureSchema();
        $sql = 'SELECT u.id, u.nume, u.email, u.rol, u.status,
                       (s.user_id IS NOT NULL) AS is_configured
                FROM utilizatori u
                LEFT JOIN access_user_state s ON s.user_id = u.id
                ORDER BY (u.rol = "admin") DESC, u.nume ASC';

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findUser(int $userId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT id, nume, email, rol, status FROM utilizatori WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // ----------------------------------------------------------------- Templates

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listTemplates(): array
    {
        $this->ensureSchema();

        return $this->db->query('SELECT id, name, slug, is_system FROM access_templates ORDER BY is_system DESC, name ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,array<string,bool>> [page_key][action_key] => true
     */
    public function getTemplatePermissions(int $templateId): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT page_key, action_key FROM access_template_permissions WHERE template_id = ?');
        $stmt->execute([$templateId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['page_key']][(string) $row['action_key']] = true;
        }

        return $map;
    }

    /**
     * Creeaza sau actualizeaza un sablon.
     *
     * @param array<string,array<int,string>> $granted
     */
    public function saveTemplate(string $name, array $granted, ?int $templateId = null): int
    {
        $this->ensureSchema();
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Numele sablonului este obligatoriu.');
        }
        $slug = $this->slugify($name);
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            if ($templateId !== null && $templateId > 0) {
                $stmt = $this->db->prepare('UPDATE access_templates SET name = ?, slug = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$name, $slug, $now, $templateId]);
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO access_templates (name, slug, is_system, created_at, updated_at) VALUES (?, ?, 0, ?, ?)'
                );
                $stmt->execute([$name, $slug, $now, $now]);
                $templateId = (int) $this->db->lastInsertId();
            }

            $this->db->prepare('DELETE FROM access_template_permissions WHERE template_id = ?')->execute([$templateId]);
            $ins = $this->db->prepare(
                'INSERT INTO access_template_permissions (template_id, page_key, action_key) VALUES (?, ?, ?)'
            );
            foreach ($granted as $pageKey => $actions) {
                foreach ((array) $actions as $actionKey) {
                    $actionKey = trim((string) $actionKey);
                    if ($actionKey === '') {
                        continue;
                    }
                    $ins->execute([$templateId, (string) $pageKey, $actionKey]);
                }
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return (int) $templateId;
    }

    /**
     * Creeaza un sablon de sistem daca slug-ul nu exista deja (idempotent).
     *
     * @param array<string,array<int,string>> $granted
     */
    public function ensureSystemTemplate(string $name, string $slug, array $granted): void
    {
        $this->ensureSchema();
        $check = $this->db->prepare('SELECT 1 FROM access_templates WHERE slug = ? LIMIT 1');
        $check->execute([$slug]);
        if ($check->fetchColumn()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO access_templates (name, slug, is_system, created_at, updated_at) VALUES (?, ?, 1, ?, ?)'
            );
            $stmt->execute([$name, $slug, $now, $now]);
            $templateId = (int) $this->db->lastInsertId();

            $ins = $this->db->prepare(
                'INSERT INTO access_template_permissions (template_id, page_key, action_key) VALUES (?, ?, ?)'
            );
            foreach ($granted as $pageKey => $actions) {
                foreach ((array) $actions as $actionKey) {
                    $ins->execute([$templateId, (string) $pageKey, (string) $actionKey]);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function deleteTemplate(int $templateId): void
    {
        $this->ensureSchema();
        $this->db->prepare('DELETE FROM access_templates WHERE id = ? AND is_system = 0')->execute([$templateId]);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? substr($value, 0, 80) : 'sablon-' . substr(md5($value . microtime()), 0, 8);
    }
}
