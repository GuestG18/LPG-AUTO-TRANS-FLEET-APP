<?php
declare(strict_types=1);

class ModuleModel extends BaseModel
{
    private function normalizeVehiclePlateToken(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        $value = str_replace([' ', '-', '.', '/'], '', $value);

        return $value;
    }

    private function parseVehiclePlateSearchTokens(string $search): array
    {
        $parts = preg_split('/[,\;\|\r\n]+/u', $search) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $token = $this->normalizeVehiclePlateToken((string) $part);
            if ($token === '') {
                continue;
            }
            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    private function shouldUseVehiclePlateSearch(array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        // Consideram cautare de numar daca toate token-urile contin cifre.
        foreach ($tokens as $token) {
            if (preg_match('/\d/u', (string) $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function fromSql(array $module): string
    {
        $sql = ' FROM ' . $module['table'] . ' t';

        foreach ($module['joins'] ?? [] as $join) {
            $joinType = strtoupper($join['type'] ?? 'LEFT');
            if (!in_array($joinType, ['LEFT', 'RIGHT', 'INNER'], true)) {
                $joinType = 'LEFT';
            }

            $sql .= ' ' . $joinType . ' JOIN ' . $join['table'] . ' ON ' . $join['on'];
        }

        return $sql;
    }

    private function whereSql(array $module, string $search, array $filters): array
    {
        $conditions = [];
        $params = [];

        $baseConditions = $module['base_conditions'] ?? [];
        if (is_string($baseConditions)) {
            $baseConditions = [trim($baseConditions)];
        }
        if (is_array($baseConditions)) {
            foreach ($baseConditions as $baseCondition) {
                if (!is_string($baseCondition)) {
                    continue;
                }
                $baseCondition = trim($baseCondition);
                if ($baseCondition === '') {
                    continue;
                }
                $conditions[] = $baseCondition;
            }
        }

        if ($search !== '' && !empty($module['search_fields'])) {
            $searchMode = (string) ($module['search_mode'] ?? '');
            if ($searchMode === 'vehicle_plate') {
                $plateTokens = $this->parseVehiclePlateSearchTokens($search);
                if ($this->shouldUseVehiclePlateSearch($plateTokens)) {
                    $plateSearchParts = [];
                    foreach ($plateTokens as $index => $plateToken) {
                        $placeholder = ':search_plate_' . $index;
                        $plateSearchParts[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(t.nr_inmatriculare, '')), ' ', ''), '-', ''), '.', ''), '/', '') LIKE " . $placeholder;
                        $params[$placeholder] = '%' . $plateToken . '%';
                    }

                    if ($plateSearchParts !== []) {
                        $conditions[] = '(' . implode(' OR ', $plateSearchParts) . ')';
                    }
                } else {
                    $searchParts = [];

                    foreach ($module['search_fields'] as $index => $fieldExpression) {
                        $placeholder = ':search_' . $index;
                        $searchParts[] = $fieldExpression . ' LIKE ' . $placeholder;
                        $params[$placeholder] = '%' . $search . '%';
                    }

                    if ($searchParts !== []) {
                        $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
                    }
                }
            } else {
                $searchParts = [];

                foreach ($module['search_fields'] as $index => $fieldExpression) {
                    $placeholder = ':search_' . $index;
                    $searchParts[] = $fieldExpression . ' LIKE ' . $placeholder;
                    $params[$placeholder] = '%' . $search . '%';
                }

                if ($searchParts !== []) {
                    $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
                }
            }
        }

        foreach (($module['filters'] ?? []) as $filterKey => $meta) {
            if (!array_key_exists($filterKey, $filters)) {
                continue;
            }

            $value = $filters[$filterKey];
            if (is_array($value)) {
                $normalizedValues = [];
                foreach ($value as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }
                    $normalizedValue = trim((string) $item);
                    if ($normalizedValue === '') {
                        continue;
                    }
                    $normalizedValues[$normalizedValue] = $normalizedValue;
                }
                $normalizedValues = array_values($normalizedValues);

                if ($normalizedValues === []) {
                    continue;
                }

                $isMultiSelectFilter = (($meta['type'] ?? '') === 'multiselect') || !empty($meta['allow_multiple']);
                if ($isMultiSelectFilter) {
                    $column = $meta['column'] ?? ('t.' . $filterKey);
                    $operator = strtoupper((string) ($meta['operator'] ?? '='));
                    $inOperator = $operator === '!=' ? 'NOT IN' : 'IN';
                    $placeholders = [];

                    foreach ($normalizedValues as $index => $normalizedValue) {
                        $placeholder = ':filter_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $filterKey) . '_' . $index;
                        $placeholders[] = $placeholder;
                        $params[$placeholder] = $normalizedValue;
                    }

                    if ($placeholders !== []) {
                        $conditions[] = $column . ' ' . $inOperator . ' (' . implode(', ', $placeholders) . ')';
                    }
                    continue;
                }

                $value = $normalizedValues[0];
            }

            if ($value === '' || $value === null) {
                continue;
            }

            if (isset($meta['custom_conditions']) && is_array($meta['custom_conditions'])) {
                $customCondition = $meta['custom_conditions'][(string) $value] ?? null;

                if (is_array($customCondition) && !empty($customCondition['sql'])) {
                    $conditions[] = $customCondition['sql'];

                    foreach (($customCondition['params'] ?? []) as $paramKey => $paramValue) {
                        $params[$paramKey] = $paramValue;
                    }
                }

                continue;
            }

            $operator = strtoupper($meta['operator'] ?? '=');
            if (!in_array($operator, ['=', '!=', '>=', '<=', '>', '<'], true)) {
                $operator = '=';
            }

            $column = $meta['column'] ?? ('t.' . $filterKey);
            $placeholder = ':filter_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $filterKey);

            $conditions[] = $column . ' ' . $operator . ' ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return ['where' => $where, 'params' => $params];
    }

    private function bindAll(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => $value) {
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }

    public function getPaginated(array $module, string $search, array $filters, int $page, int $perPage): array
    {
        $from = $this->fromSql($module);
        $whereData = $this->whereSql($module, $search, $filters);

        $countSql = 'SELECT COUNT(*)' . $from . $whereData['where'];
        $countStmt = $this->db->prepare($countSql);
        $this->bindAll($countStmt, $whereData['params']);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max($page, 1), $totalPages);

        $offset = ($page - 1) * $perPage;
        $select = $module['select'] ?? 't.*';
        $orderBy = $module['default_order'] ?? 't.id DESC';

        $dataSql = 'SELECT ' . $select . $from . $whereData['where'] . ' ORDER BY ' . $orderBy . ' LIMIT :limit_rows OFFSET :offset_rows';
        $dataStmt = $this->db->prepare($dataSql);
        $this->bindAll($dataStmt, $whereData['params']);
        $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
        ];
    }

    public function getAll(array $module, string $search, array $filters): array
    {
        $from = $this->fromSql($module);
        $whereData = $this->whereSql($module, $search, $filters);
        $select = $module['select'] ?? 't.*';
        $orderBy = $module['default_order'] ?? 't.id DESC';

        $sql = 'SELECT ' . $select . $from . $whereData['where'] . ' ORDER BY ' . $orderBy;
        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $whereData['params']);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findById(array $module, int $id): ?array
    {
        $from = $this->fromSql($module);
        $select = $module['select'] ?? 't.*';

        $sql = 'SELECT ' . $select . $from . ' WHERE t.id = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertRecord(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Datele pentru insert nu pot fi goale.');
        }

        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);

        foreach ($data as $column => $value) {
            if ($value === null) {
                $stmt->bindValue(':' . $column, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $column, $value);
            }
        }

        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateRecord(string $table, int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }

        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = $column . ' = :' . $column;
        }

        $sql = sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $setParts));
        $stmt = $this->db->prepare($sql);

        foreach ($data as $column => $value) {
            if ($value === null) {
                $stmt->bindValue(':' . $column, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':' . $column, $value);
            }
        }

        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteRecord(string $table, int $id): bool
    {
        $sql = sprintf('DELETE FROM %s WHERE id = :id', $table);
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
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

    public function existsId(string $table, int $id): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function isDriverAssignedToVehicle(int $driverId, int $vehicleId, bool $onlyActive = true): bool
    {
        if ($driverId <= 0 || $vehicleId <= 0) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM soferi WHERE id = :driver_id AND vehicle_id = :vehicle_id';
        if ($onlyActive) {
            $sql .= " AND status = 'activ'";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function isVehicleActive(int $vehicleId): bool
    {
        if ($vehicleId <= 0) {
            return false;
        }

        $sql = "SELECT COUNT(*) FROM vehicule WHERE id = :vehicle_id AND status = 'activ'";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function isVehicleEligibleForRefuel(int $vehicleId): bool
    {
        if ($vehicleId <= 0) {
            return false;
        }

        $sql = "
            SELECT COUNT(*)
            FROM vehicule
            WHERE id = :vehicle_id
              AND status = 'activ'
              AND tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function getSelectOptions(array $source): array
    {
        $table = $source['table'];
        $valueExpr = $source['value'];
        $labelExpr = $source['label'];
        $where = trim((string) ($source['where'] ?? ''));
        $order = $source['order'] ?? '1 ASC';

        $sql = 'SELECT ' . $valueExpr . ' AS value, ' . $labelExpr . ' AS label FROM ' . $table;

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $sql .= ' ORDER BY ' . $order;

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
}
