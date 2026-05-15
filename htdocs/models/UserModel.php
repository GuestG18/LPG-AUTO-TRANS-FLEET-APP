<?php
declare(strict_types=1);

class UserModel extends BaseModel
{
    public function findByEmail(string $email): ?array
    {
        $sql = 'SELECT * FROM utilizatori WHERE email = :email LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch();
        return $row ?: null;
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
}
