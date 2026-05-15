<?php
declare(strict_types=1);

class UserModel extends BaseModel
{
    private ?array $authTableConfig = null;

    public function findByEmail(string $email): ?array
    {
        $sql = 'SELECT * FROM utilizatori WHERE email = :email LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAuthUserByEmail(string $email): ?array
    {
        $config = $this->resolveAuthTableConfig();
        $sql = sprintf(
            'SELECT %s AS id, %s AS nume, email, %s AS parola, %s AS rol, %s AS status
             FROM %s
             WHERE LOWER(email) = LOWER(:email)
             LIMIT 1',
            $config['id_column'],
            $config['name_expression'],
            $config['password_column'],
            $config['role_expression'],
            $config['status_expression'],
            $config['table']
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => trim($email)]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findAuthUserById(int $id): ?array
    {
        $config = $this->resolveAuthTableConfig();
        $sql = sprintf(
            'SELECT %s AS id, %s AS nume, email, %s AS parola, %s AS rol, %s AS status
             FROM %s
             WHERE %s = :id
             LIMIT 1',
            $config['id_column'],
            $config['name_expression'],
            $config['password_column'],
            $config['role_expression'],
            $config['status_expression'],
            $config['table'],
            $config['id_column']
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT * FROM utilizatori WHERE id = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countActiveAdminsExcept(?int $exceptId = null): int
    {
        $sql = "SELECT COUNT(*) FROM utilizatori WHERE rol = 'admin' AND status = 'activ'";
        $params = [];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $params[':except_id'] = $exceptId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function existsValue(string $table, string $column, mixed $value, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :value';
        $params = [':value' => $value];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function updateProfile(int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }

        $setParts = [];
        $params = [':id' => $id];

        foreach ($data as $column => $value) {
            $placeholder = ':c_' . $column;
            $setParts[] = $column . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $sql = 'UPDATE utilizatori SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    private function resolveAuthTableConfig(): array
    {
        if ($this->authTableConfig !== null) {
            return $this->authTableConfig;
        }

        if ($this->tableExists('users')) {
            $table = 'users';
        } else {
            $table = 'utilizatori';
        }

        $idColumn = 'id';
        $passwordColumn = $this->firstExistingColumn($table, ['password', 'parola']);
        $nameExpression = $this->firstExistingColumn($table, ['name', 'nume', 'email']);
        $roleColumn = $this->firstExistingColumn($table, ['role', 'rol']);
        $statusColumn = $this->firstExistingColumn($table, ['status']);

        $roleExpression = $roleColumn !== null ? $roleColumn : "'utilizator'";
        $statusExpression = $statusColumn !== null ? $statusColumn : "'activ'";

        $this->authTableConfig = [
            'table' => $table,
            'id_column' => $idColumn,
            'password_column' => $passwordColumn !== null ? $passwordColumn : "''",
            'name_expression' => $nameExpression,
            'role_expression' => $roleExpression,
            'status_expression' => $statusExpression,
        ];

        return $this->authTableConfig;
    }

    private function tableExists(string $table): bool
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $sql = 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
