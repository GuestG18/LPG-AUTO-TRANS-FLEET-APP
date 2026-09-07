<?php
declare(strict_types=1);

/**
 * Model pentru pagina "Cazare".
 *
 * Cazarea se introduce independent de cursa (Data / Sofer / Total / Total cu TVA),
 * iar asocierea cu cursa se face automat: se cauta cursa soferului a carei perioada
 * (data_inceput .. data_sfarsit) contine data cazarii.
 *
 *   0 curse  -> 'neasociat' (raman in asteptare si se reincearca la fiecare pas de reasociere)
 *   1 cursa  -> 'asociat'
 *   2+ curse -> 'ambiguu'   (utilizatorul alege cursa din lista de candidati)
 *
 * Cand o cazare are cursa, modulul intretine un rand-oglinda in `curse_cheltuieli`
 * (categoria "Cazare", suma = total cu TVA). Astfel cazarea intra automat in toate
 * rapoartele existente pe cheltuieli de cursa, fara sa modificam zecile de interogari
 * care citesc deja `curse_cheltuieli`. Randul-oglinda apartine exclusiv acestui modul.
 */
class AccommodationExpenseModel extends BaseModel
{
    public const CATEGORY_NAME = 'Cazare';

    /** tip_cheltuiala din ENUM-ul legacy pe care il primeste randul-oglinda. */
    private const MIRROR_LEGACY_TYPE = 'alte';

    public const STATUSES = [
        'asociat' => 'Asociat',
        'neasociat' => 'Neasociat',
        'ambiguu' => 'Ambiguu',
    ];

    private ?int $categoryIdCache = null;

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Creeaza / completeaza schema modulului. Ruleaza o singura data per request,
     * la fel ca celelalte module care se auto-migreaza.
     */
    public function ensureSchema(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cheltuieli_cazare (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                data DATE NOT NULL,
                sofer_id INT UNSIGNED NOT NULL,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_cu_tva DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                cursa_id INT UNSIGNED NULL,
                status ENUM('asociat', 'neasociat', 'ambiguu') NOT NULL DEFAULT 'neasociat',
                asociere_manuala TINYINT(1) NOT NULL DEFAULT 0,
                observatii TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_cheltuieli_cazare_data (data),
                INDEX idx_cheltuieli_cazare_sofer (sofer_id),
                INDEX idx_cheltuieli_cazare_cursa (cursa_id),
                INDEX idx_cheltuieli_cazare_status (status),
                INDEX idx_cheltuieli_cazare_sofer_data (sofer_id, data),
                INDEX idx_cheltuieli_cazare_created_by (created_by),
                CONSTRAINT fk_cheltuieli_cazare_sofer FOREIGN KEY (sofer_id) REFERENCES soferi(id) ON DELETE RESTRICT,
                CONSTRAINT fk_cheltuieli_cazare_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE SET NULL,
                CONSTRAINT fk_cheltuieli_cazare_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureMirrorColumn();
        $this->ensureCategory();

        $ensured = true;
    }

    private function ensureMirrorColumn(): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_cheltuieli'
              AND COLUMN_NAME = 'cazare_id'
        ");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec('ALTER TABLE curse_cheltuieli ADD COLUMN cazare_id INT UNSIGNED NULL AFTER categorie_id');
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_cheltuieli'
              AND INDEX_NAME = 'uk_curse_cheltuieli_cazare'
        ");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec('ALTER TABLE curse_cheltuieli ADD UNIQUE KEY uk_curse_cheltuieli_cazare (cazare_id)');
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_cheltuieli'
              AND CONSTRAINT_NAME = 'fk_curse_cheltuieli_cazare'
        ");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec("
                ALTER TABLE curse_cheltuieli
                ADD CONSTRAINT fk_curse_cheltuieli_cazare
                FOREIGN KEY (cazare_id) REFERENCES cheltuieli_cazare(id) ON DELETE CASCADE
            ");
        }
    }

    private function ensureCategory(): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at) VALUES (:nume, :descriere, 1, NULL, :created_at, :updated_at)');
        $now = date('Y-m-d H:i:s');
        $stmt->bindValue(':nume', self::CATEGORY_NAME, PDO::PARAM_STR);
        $stmt->bindValue(':descriere', 'Cheltuieli de cazare sofer. Se introduc pe pagina Cazare si se asociaza automat la cursa.', PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** Id-ul categoriei "Cazare" din catalogul de cheltuieli curse. */
    public function getCategoryId(): ?int
    {
        if ($this->categoryIdCache !== null) {
            return $this->categoryIdCache;
        }

        $stmt = $this->db->prepare('SELECT id FROM categorii_cheltuieli_curse WHERE nume = :nume LIMIT 1');
        $stmt->bindValue(':nume', self::CATEGORY_NAME, PDO::PARAM_STR);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $this->categoryIdCache = (int) $id;

        return $this->categoryIdCache;
    }

    // -------------------------------------------------------------------------
    // Motorul de asociere
    // -------------------------------------------------------------------------

    /**
     * Cursele soferului a caror perioada acopera data data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findMatchingRaces(int $driverId, string $date): array
    {
        $stmt = $this->db->prepare("
            SELECT
                c.id,
                c.data_cursa,
                c.data_inceput,
                c.data_sfarsit,
                c.tip_transport,
                v.nr_inmatriculare,
                b.nume AS beneficiar
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            WHERE c.deleted_at IS NULL
              AND c.driver_id = :driver_id
              AND :data BETWEEN c.data_inceput AND c.data_sfarsit
            ORDER BY c.data_inceput ASC, c.id ASC
        ");
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':data', $date, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Rezolva asocierea pentru o cazare si scrie rezultatul (inclusiv randul-oglinda).
     *
     * @return array{status: string, cursa_id: ?int, candidates: array<int, array<string, mixed>>}
     */
    public function resolveAssociation(int $id): array
    {
        $row = $this->getById($id);
        if ($row === null) {
            return ['status' => 'neasociat', 'cursa_id' => null, 'candidates' => []];
        }

        // Asocierea manuala are prioritate: nu o suprascriem automat cat timp
        // cursa aleasa exista si nu a fost stearsa.
        if ((int) $row['asociere_manuala'] === 1 && $row['cursa_id'] !== null && $this->raceIsActive((int) $row['cursa_id'])) {
            $this->applyAssociation($id, 'asociat', (int) $row['cursa_id'], true);

            return ['status' => 'asociat', 'cursa_id' => (int) $row['cursa_id'], 'candidates' => []];
        }

        $candidates = $this->findMatchingRaces((int) $row['sofer_id'], (string) $row['data']);
        $count = count($candidates);

        if ($count === 1) {
            $raceId = (int) $candidates[0]['id'];
            $this->applyAssociation($id, 'asociat', $raceId, false);

            return ['status' => 'asociat', 'cursa_id' => $raceId, 'candidates' => $candidates];
        }

        $status = $count === 0 ? 'neasociat' : 'ambiguu';
        $this->applyAssociation($id, $status, null, false);

        return ['status' => $status, 'cursa_id' => null, 'candidates' => $candidates];
    }

    /**
     * Reruleaza asocierea pentru toate cazarile care nu sunt inca legate ferm de o cursa,
     * plus cele al caror cursa asociata a disparut intre timp (stearsa).
     *
     * @return array{procesate: int, asociate: int}
     */
    public function rematchPending(): array
    {
        $stmt = $this->db->query("
            SELECT z.id
            FROM cheltuieli_cazare z
            LEFT JOIN curse_dispecer c ON c.id = z.cursa_id
            WHERE z.status <> 'asociat'
               OR z.cursa_id IS NULL
               OR c.id IS NULL
               OR c.deleted_at IS NOT NULL
        ");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $associated = 0;
        foreach ($ids as $pendingId) {
            $result = $this->resolveAssociation((int) $pendingId);
            if ($result['status'] === 'asociat') {
                $associated++;
            }
        }

        return ['procesate' => count($ids), 'asociate' => $associated];
    }

    private function raceIsActive(int $raceId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM curse_dispecer WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->bindValue(':id', $raceId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** Scrie statusul + cursa pe cazare si sincronizeaza randul-oglinda. */
    private function applyAssociation(int $id, string $status, ?int $raceId, bool $manual): void
    {
        $stmt = $this->db->prepare("
            UPDATE cheltuieli_cazare
            SET cursa_id = :cursa_id,
                status = :status,
                asociere_manuala = :manual,
                updated_at = :updated_at
            WHERE id = :id
        ");
        if ($raceId === null) {
            $stmt->bindValue(':cursa_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':manual', $manual ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->syncMirrorExpense($id);
    }

    /** Leaga manual o cazare de o cursa aleasa de utilizator. */
    public function linkManually(int $id, int $raceId): bool
    {
        $row = $this->getById($id);
        if ($row === null || !$this->raceIsActive($raceId)) {
            return false;
        }

        $this->applyAssociation($id, 'asociat', $raceId, true);

        return true;
    }

    /** Rupe asocierea si readuce cazarea in fluxul automat. */
    public function unlink(int $id): bool
    {
        if ($this->getById($id) === null) {
            return false;
        }

        $this->applyAssociation($id, 'neasociat', null, false);
        $this->resolveAssociation($id);

        return true;
    }

    // -------------------------------------------------------------------------
    // Randul-oglinda din curse_cheltuieli
    // -------------------------------------------------------------------------

    /**
     * Aduce randul din `curse_cheltuieli` in sincron cu cazarea:
     * il creeaza, il actualizeaza sau il sterge daca nu mai exista asociere.
     */
    private function syncMirrorExpense(int $id): void
    {
        $row = $this->getById($id);
        if ($row === null) {
            return;
        }

        $raceId = $row['cursa_id'] !== null ? (int) $row['cursa_id'] : 0;
        $categoryId = $this->getCategoryId();

        if ($raceId <= 0 || $categoryId === null) {
            $this->deleteMirrorExpense($id);

            return;
        }

        $now = date('Y-m-d H:i:s');
        // Costul care intra in rapoarte este totalul CU TVA.
        $amount = round((float) $row['total_cu_tva'], 2);
        $notes = $this->buildMirrorNotes($row);

        $existingId = $this->findMirrorExpenseId($id);

        if ($existingId === null) {
            $stmt = $this->db->prepare("
                INSERT INTO curse_cheltuieli (
                    cursa_id, tip_cheltuiala, categorie_id, cazare_id,
                    suma, data_cheltuiala, observatii, added_by, created_at, updated_at
                ) VALUES (
                    :cursa_id, :tip_cheltuiala, :categorie_id, :cazare_id,
                    :suma, :data_cheltuiala, :observatii, :added_by, :created_at, :updated_at
                )
            ");
            $stmt->bindValue(':tip_cheltuiala', self::MIRROR_LEGACY_TYPE, PDO::PARAM_STR);
            $stmt->bindValue(':categorie_id', $categoryId, PDO::PARAM_INT);
            $stmt->bindValue(':cazare_id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
            $stmt->bindValue(':suma', number_format($amount, 2, '.', ''), PDO::PARAM_STR);
            $stmt->bindValue(':data_cheltuiala', (string) $row['data'], PDO::PARAM_STR);
            $stmt->bindValue(':observatii', $notes, PDO::PARAM_STR);
            if ($row['created_by'] === null) {
                $stmt->bindValue(':added_by', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':added_by', (int) $row['created_by'], PDO::PARAM_INT);
            }
            $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $stmt->execute();

            return;
        }

        $stmt = $this->db->prepare("
            UPDATE curse_cheltuieli
            SET cursa_id = :cursa_id,
                categorie_id = :categorie_id,
                suma = :suma,
                data_cheltuiala = :data_cheltuiala,
                observatii = :observatii,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
        $stmt->bindValue(':categorie_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':suma', number_format($amount, 2, '.', ''), PDO::PARAM_STR);
        $stmt->bindValue(':data_cheltuiala', (string) $row['data'], PDO::PARAM_STR);
        $stmt->bindValue(':observatii', $notes, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', $existingId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function buildMirrorNotes(array $row): string
    {
        $parts = ['Cazare ' . trim((string) ($row['sofer_nume'] ?? ''))];
        $parts[] = 'fara TVA: ' . number_format((float) $row['total'], 2, ',', '.') . ' lei';

        $ownNotes = trim((string) ($row['observatii'] ?? ''));
        if ($ownNotes !== '') {
            $parts[] = $ownNotes;
        }

        return mb_substr(implode(' | ', $parts), 0, 5000);
    }

    private function findMirrorExpenseId(int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM curse_cheltuieli WHERE cazare_id = :cazare_id LIMIT 1');
        $stmt->bindValue(':cazare_id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $existingId = $stmt->fetchColumn();

        return $existingId === false ? null : (int) $existingId;
    }

    private function deleteMirrorExpense(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM curse_cheltuieli WHERE cazare_id = :cazare_id');
        $stmt->bindValue(':cazare_id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * @param array{data: string, sofer_id: int, total: float, total_cu_tva: float, observatii: ?string, created_by: ?int} $data
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO cheltuieli_cazare (
                data, sofer_id, total, total_cu_tva, cursa_id, status,
                asociere_manuala, observatii, created_by, created_at, updated_at
            ) VALUES (
                :data, :sofer_id, :total, :total_cu_tva, NULL, 'neasociat',
                0, :observatii, :created_by, :created_at, :updated_at
            )
        ");
        $stmt->bindValue(':data', $data['data'], PDO::PARAM_STR);
        $stmt->bindValue(':sofer_id', $data['sofer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':total', number_format((float) $data['total'], 2, '.', ''), PDO::PARAM_STR);
        $stmt->bindValue(':total_cu_tva', number_format((float) $data['total_cu_tva'], 2, '.', ''), PDO::PARAM_STR);
        $this->bindNullableText($stmt, ':observatii', $data['observatii'] ?? null);
        if (($data['created_by'] ?? null) === null) {
            $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':created_by', (int) $data['created_by'], PDO::PARAM_INT);
        }
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->execute();

        $id = (int) $this->db->lastInsertId();
        $this->resolveAssociation($id);

        return $id;
    }

    /**
     * @param array{data: string, sofer_id: int, total: float, total_cu_tva: float, observatii: ?string} $data
     */
    public function update(int $id, array $data): bool
    {
        $current = $this->getById($id);
        if ($current === null) {
            return false;
        }

        // Daca s-a schimbat soferul sau data, asocierea manuala nu mai este valida:
        // premisele pe care s-a facut alegerea s-au schimbat.
        $keyChanged = (int) $current['sofer_id'] !== (int) $data['sofer_id']
            || (string) $current['data'] !== (string) $data['data'];

        $stmt = $this->db->prepare("
            UPDATE cheltuieli_cazare
            SET data = :data,
                sofer_id = :sofer_id,
                total = :total,
                total_cu_tva = :total_cu_tva,
                observatii = :observatii,
                asociere_manuala = :asociere_manuala,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':data', $data['data'], PDO::PARAM_STR);
        $stmt->bindValue(':sofer_id', $data['sofer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':total', number_format((float) $data['total'], 2, '.', ''), PDO::PARAM_STR);
        $stmt->bindValue(':total_cu_tva', number_format((float) $data['total_cu_tva'], 2, '.', ''), PDO::PARAM_STR);
        $this->bindNullableText($stmt, ':observatii', $data['observatii'] ?? null);
        $stmt->bindValue(':asociere_manuala', $keyChanged ? 0 : (int) $current['asociere_manuala'], PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->resolveAssociation($id);

        return true;
    }

    public function delete(int $id): bool
    {
        // Randul-oglinda pleaca prin FK ON DELETE CASCADE, dar il stergem explicit
        // ca sa nu depindem de migrarea constrangerii pe instalari mai vechi.
        $this->deleteMirrorExpense($id);

        $stmt = $this->db->prepare('DELETE FROM cheltuieli_cazare WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                z.*,
                s.nume AS sofer_nume,
                c.data_inceput,
                c.data_sfarsit,
                c.data_cursa,
                c.tip_transport,
                v.nr_inmatriculare
            FROM cheltuieli_cazare z
            INNER JOIN soferi s ON s.id = z.sofer_id
            LEFT JOIN curse_dispecer c ON c.id = z.cursa_id AND c.deleted_at IS NULL
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            WHERE z.id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // -------------------------------------------------------------------------
    // Listare
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, page: int, per_page: int, total_rows: int, total_pages: int}
     */
    public function getPaginated(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM cheltuieli_cazare z INNER JOIN soferi s ON s.id = z.sofer_id ' . $where);
        $this->bindFilters($countStmt, $params);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("
            SELECT
                z.*,
                s.nume AS sofer_nume,
                c.data_inceput,
                c.data_sfarsit,
                c.data_cursa,
                c.tip_transport,
                v.nr_inmatriculare,
                b.nume AS beneficiar,
                u.nume AS creat_de
            FROM cheltuieli_cazare z
            INNER JOIN soferi s ON s.id = z.sofer_id
            LEFT JOIN curse_dispecer c ON c.id = z.cursa_id AND c.deleted_at IS NULL
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            LEFT JOIN utilizatori u ON u.id = z.created_by
            " . $where . "
            ORDER BY z.data DESC, z.id DESC
            LIMIT :lim OFFSET :off
        ");
        $this->bindFilters($stmt, $params);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pentru randurile ambigue afisam lista de curse candidate direct in tabel.
        foreach ($rows as $index => $row) {
            $rows[$index]['candidati'] = (string) $row['status'] === 'ambiguu'
                ? $this->findMatchingRaces((int) $row['sofer_id'], (string) $row['data'])
                : [];
        }

        return [
            'rows' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{total: float, total_cu_tva: float, count: int, asociate: int, neasociate: int, ambigue: int}
     */
    public function getSummary(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS count_total,
                COALESCE(SUM(z.total), 0) AS suma_total,
                COALESCE(SUM(z.total_cu_tva), 0) AS suma_cu_tva,
                COALESCE(SUM(z.status = 'asociat'), 0) AS nr_asociate,
                COALESCE(SUM(z.status = 'neasociat'), 0) AS nr_neasociate,
                COALESCE(SUM(z.status = 'ambiguu'), 0) AS nr_ambigue
            FROM cheltuieli_cazare z
            INNER JOIN soferi s ON s.id = z.sofer_id
            " . $where);
        $this->bindFilters($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count' => (int) ($row['count_total'] ?? 0),
            'total' => (float) ($row['suma_total'] ?? 0),
            'total_cu_tva' => (float) ($row['suma_cu_tva'] ?? 0),
            'asociate' => (int) ($row['nr_asociate'] ?? 0),
            'neasociate' => (int) ($row['nr_neasociate'] ?? 0),
            'ambigue' => (int) ($row['nr_ambigue'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getExportRows(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT
                z.data,
                s.nume AS sofer_nume,
                z.total,
                z.total_cu_tva,
                z.status,
                z.cursa_id,
                c.data_inceput,
                c.data_sfarsit,
                v.nr_inmatriculare,
                z.observatii
            FROM cheltuieli_cazare z
            INNER JOIN soferi s ON s.id = z.sofer_id
            LEFT JOIN curse_dispecer c ON c.id = z.cursa_id AND c.deleted_at IS NULL
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            " . $where . '
            ORDER BY z.data DESC, z.id DESC');
        $this->bindFilters($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, array{0: mixed, 1: int}>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['data_start'])) {
            $conditions[] = 'z.data >= :data_start';
            $params[':data_start'] = [$filters['data_start'], PDO::PARAM_STR];
        }

        if (!empty($filters['data_end'])) {
            $conditions[] = 'z.data <= :data_end';
            $params[':data_end'] = [$filters['data_end'], PDO::PARAM_STR];
        }

        if (!empty($filters['sofer_id'])) {
            $conditions[] = 'z.sofer_id = :sofer_id';
            $params[':sofer_id'] = [(int) $filters['sofer_id'], PDO::PARAM_INT];
        }

        if (!empty($filters['status']) && array_key_exists((string) $filters['status'], self::STATUSES)) {
            $conditions[] = 'z.status = :status';
            $params[':status'] = [(string) $filters['status'], PDO::PARAM_STR];
        }

        if (!empty($filters['q'])) {
            $conditions[] = '(s.nume LIKE :q_nume OR z.observatii LIKE :q_obs)';
            // EMULATE_PREPARES=false: acelasi placeholder nu poate aparea de doua ori.
            $params[':q_nume'] = ['%' . $filters['q'] . '%', PDO::PARAM_STR];
            $params[':q_obs'] = ['%' . $filters['q'] . '%', PDO::PARAM_STR];
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /**
     * @param array<string, array{0: mixed, 1: int}> $params
     */
    private function bindFilters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => [$value, $type]) {
            $stmt->bindValue($placeholder, $value, $type);
        }
    }

    // -------------------------------------------------------------------------
    // Optiuni pentru formular
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function getDrivers(): array
    {
        $stmt = $this->db->query("
            SELECT id, nume, status
            FROM soferi
            WHERE employment_status <> 'terminated'
            ORDER BY status = 'activ' DESC, nume ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function bindNullableText(PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        $value = $value !== null ? trim($value) : null;

        if ($value === null || $value === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);

            return;
        }

        $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
    }
}
