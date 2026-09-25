<?php
declare(strict_types=1);

/**
 * Categoriile de capacitate ale vehiculelor.
 *
 * REGULA FUNDAMENTALA a acestui modul:
 *   - `vehicule.capacitate_transport`    = capacitatea tehnica REALA (tone).
 *     Singura valoare acceptata in calcule (grad de umplere, supraincarcare).
 *   - `vehicule.categorie_capacitate_id` = eticheta de grupare. Se foloseste
 *     DOAR pentru grupare / filtrare / selectie in masa in interfata.
 *
 * Numele categoriei este text liber ("18.5 TONE", "7+", "Capacitate mare").
 * Numarul din nume NU se interpreteaza niciodata ca fiind capacitatea unui
 * vehicul: doua vehicule din aceeasi categorie pot avea capacitati reale
 * diferite, si asta este exact scopul separarii.
 *
 * Tabelele sunt create si de database/migrations/2026_09_18_000001_categorii_capacitate_vehicule.sql;
 * ensureSchema() le recreeaza la rulare, ca pagina sa mearga si inainte de migrare.
 */
class VehicleCapacityCategoryModel extends BaseModel
{
    /** Tipurile de vehicul pentru care capacitatea reala chiar conteaza. */
    public const CARGO_VEHICLE_TYPES = ['camion', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie'];

    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS vehicule_categorii_capacitate (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nume VARCHAR(80) NOT NULL,
                descriere VARCHAR(255) NULL,
                ordine_afisare INT UNSIGNED NOT NULL DEFAULT 0,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_categorii_capacitate_nume (nume),
                INDEX idx_categorii_capacitate_ordine (activ, ordine_afisare, nume)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS vehicule_capacitate_audit (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                capacitate_veche DECIMAL(10,2) NULL,
                capacitate_noua DECIMAL(10,2) NULL,
                categorie_veche_id INT UNSIGNED NULL,
                categorie_noua_id INT UNSIGNED NULL,
                confirmata_veche TINYINT(1) NULL,
                confirmata_noua TINYINT(1) NULL,
                sursa ENUM('migrare', 'manual') NOT NULL DEFAULT 'manual',
                motiv VARCHAR(255) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_capacitate_audit_vehicul (vehicle_id, created_at),
                INDEX idx_capacitate_audit_sursa (sursa),
                CONSTRAINT fk_capacitate_audit_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
                CONSTRAINT fk_capacitate_audit_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Coloanele de pe `vehicule` / `curse_dispecer` sunt comune mai multor
        // modele, deci stau in BaseModel.
        $this->ensureVehicleCapacityCategorySchema();

        $this->schemaEnsured = true;
    }

    // =================================================================
    // Citire
    // =================================================================

    /**
     * Categoriile, in ordinea de afisare, cu numarul de vehicule asignate.
     *
     * @return list<array<string, mixed>>
     */
    public function getCategories(bool $onlyActive = false): array
    {
        $this->ensureSchema();

        $sql = "
            SELECT
                c.id,
                c.nume,
                c.descriere,
                c.ordine_afisare,
                c.activ,
                c.created_at,
                c.updated_at,
                COALESCE(u.vehicule, 0) AS vehicule,
                COALESCE(u.vehicule_active, 0) AS vehicule_active
            FROM vehicule_categorii_capacitate c
            LEFT JOIN (
                SELECT
                    categorie_capacitate_id AS cid,
                    COUNT(*) AS vehicule,
                    SUM(CASE WHEN status = 'activ' THEN 1 ELSE 0 END) AS vehicule_active
                FROM vehicule
                WHERE categorie_capacitate_id IS NOT NULL
                GROUP BY categorie_capacitate_id
            ) u ON u.cid = c.id
            " . ($onlyActive ? "WHERE c.activ = 1" : "") . "
            ORDER BY c.ordine_afisare ASC, c.nume ASC
        ";

        $rows = $this->db->query($sql)->fetchAll();

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['ordine_afisare'] = (int) $row['ordine_afisare'];
            $row['activ'] = (int) $row['activ'] === 1;
            $row['vehicule'] = (int) $row['vehicule'];
            $row['vehicule_active'] = (int) $row['vehicule_active'];

            return $row;
        }, $rows);
    }

    /** Optiunile pentru dropdown-ul din fisa vehiculului: id => nume. */
    public function getCategoryOptions(bool $onlyActive = true): array
    {
        $options = [];
        foreach ($this->getCategories($onlyActive) as $category) {
            $options[(int) $category['id']] = (string) $category['nume'];
        }

        return $options;
    }

    public function findById(int $id): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("SELECT * FROM vehicule_categorii_capacitate WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countVehicles(int $categoryId): int
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM vehicule WHERE categorie_capacitate_id = :id");
        $stmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    // =================================================================
    // Scriere
    // =================================================================

    /** @return array{0: bool, 1: array<string, string>} [succes, erori] */
    public function createCategory(array $input): array
    {
        $this->ensureSchema();

        [$data, $errors] = $this->validate($input, null);
        if ($errors !== []) {
            return [false, $errors];
        }

        $stmt = $this->db->prepare("
            INSERT INTO vehicule_categorii_capacitate (nume, descriere, ordine_afisare, activ, created_at, updated_at)
            VALUES (:nume, :descriere, :ordine, :activ, NOW(), NOW())
        ");
        $stmt->bindValue(':nume', $data['nume']);
        $stmt->bindValue(':descriere', $data['descriere'], $data['descriere'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ordine', $data['ordine_afisare'], PDO::PARAM_INT);
        $stmt->bindValue(':activ', $data['activ'], PDO::PARAM_INT);
        $stmt->execute();

        return [true, []];
    }

    /** @return array{0: bool, 1: array<string, string>} [succes, erori] */
    public function updateCategory(int $id, array $input): array
    {
        $this->ensureSchema();

        if ($this->findById($id) === null) {
            return [false, ['nume' => 'Categoria nu mai exista.']];
        }

        [$data, $errors] = $this->validate($input, $id);
        if ($errors !== []) {
            return [false, $errors];
        }

        // Redenumirea nu atinge `vehicule.categorie_capacitate_id`: legaturile
        // sunt pe id, deci asignarile existente raman valide.
        $stmt = $this->db->prepare("
            UPDATE vehicule_categorii_capacitate
            SET nume = :nume,
                descriere = :descriere,
                ordine_afisare = :ordine,
                activ = :activ,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->bindValue(':nume', $data['nume']);
        $stmt->bindValue(':descriere', $data['descriere'], $data['descriere'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ordine', $data['ordine_afisare'], PDO::PARAM_INT);
        $stmt->bindValue(':activ', $data['activ'], PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return [true, []];
    }

    /**
     * Stergerea este permisa doar daca nu mai exista vehicule pe categorie.
     * Cu `reassignTo` vehiculele sunt mutate intai pe alta categorie; cu
     * `allowDetach` raman fara categorie. In ambele cazuri capacitatea reala
     * a vehiculelor NU este atinsa.
     *
     * @return array{0: bool, 1: string} [succes, mesaj]
     */
    public function deleteCategory(int $id, ?int $reassignTo, ?int $userId, bool $allowDetach = false): array
    {
        $this->ensureSchema();

        $category = $this->findById($id);
        if ($category === null) {
            return [false, 'Categoria nu mai exista.'];
        }

        if ($reassignTo !== null && $reassignTo === $id) {
            return [false, 'Nu poti reasigna vehiculele catre categoria pe care o stergi.'];
        }

        if ($reassignTo !== null && $this->findById($reassignTo) === null) {
            return [false, 'Categoria de destinatie nu exista.'];
        }

        $inUse = $this->countVehicles($id);
        if ($inUse > 0 && $reassignTo === null && !$allowDetach) {
            return [
                false,
                'Categoria are ' . $inUse . ' ' . ($inUse === 1 ? 'vehicul asignat' : 'vehicule asignate')
                    . '. Alege o categorie de destinatie sau scoate intai vehiculele.',
            ];
        }

        // Apelantul poate avea deja o tranzactie deschisa (de exemplu harness-ul de
        // teste, care anuleaza totul la final): atunci nu deschidem alta.
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if ($inUse > 0) {
                $vehicles = $this->vehiclesInCategory($id);
                $move = $this->db->prepare("UPDATE vehicule SET categorie_capacitate_id = :new WHERE categorie_capacitate_id = :old");
                if ($reassignTo === null) {
                    $move->bindValue(':new', null, PDO::PARAM_NULL);
                } else {
                    $move->bindValue(':new', $reassignTo, PDO::PARAM_INT);
                }
                $move->bindValue(':old', $id, PDO::PARAM_INT);
                $move->execute();

                foreach ($vehicles as $vehicle) {
                    // Capacitatea reala NU se schimba la reasignare: se logheaza
                    // identica in ambele coloane, ca sa se vada ca nu a fost atinsa.
                    $this->logChange(
                        (int) $vehicle['id'],
                        $vehicle['capacitate_transport'] === null ? null : (float) $vehicle['capacitate_transport'],
                        $vehicle['capacitate_transport'] === null ? null : (float) $vehicle['capacitate_transport'],
                        $id,
                        $reassignTo,
                        (int) $vehicle['capacitate_transport_confirmata'] === 1,
                        (int) $vehicle['capacitate_transport_confirmata'] === 1,
                        'Reasignare la stergerea categoriei "' . (string) $category['nume'] . '".',
                        $userId
                    );
                }
            }

            $stmt = $this->db->prepare("DELETE FROM vehicule_categorii_capacitate WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [true, 'Categoria a fost stearsa.'];
    }

    /** @return list<array<string, mixed>> */
    private function vehiclesInCategory(int $categoryId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, capacitate_transport, capacitate_transport_confirmata
            FROM vehicule
            WHERE categorie_capacitate_id = :id
        ");
        $stmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(array $input, ?int $ignoreId): array
    {
        $errors = [];

        $name = trim((string) ($input['nume'] ?? ''));
        if ($name === '') {
            $errors['nume'] = 'Numele categoriei este obligatoriu.';
        } elseif (mb_strlen($name) > 80) {
            $errors['nume'] = 'Numele categoriei poate avea maxim 80 de caractere.';
        } elseif ($this->nameExists($name, $ignoreId)) {
            $errors['nume'] = 'Exista deja o categorie cu acest nume.';
        }

        $description = trim((string) ($input['descriere'] ?? ''));
        if (mb_strlen($description) > 255) {
            $errors['descriere'] = 'Descrierea poate avea maxim 255 de caractere.';
        }

        $orderRaw = trim((string) ($input['ordine_afisare'] ?? ''));
        if ($orderRaw !== '' && (!is_numeric($orderRaw) || (int) $orderRaw < 0)) {
            $errors['ordine_afisare'] = 'Ordinea de afisare trebuie sa fie un numar intreg pozitiv.';
        }

        return [
            [
                'nume' => $name,
                'descriere' => $description === '' ? null : $description,
                'ordine_afisare' => $orderRaw === '' ? 0 : (int) $orderRaw,
                'activ' => (string) ($input['activ'] ?? '1') === '1' ? 1 : 0,
            ],
            $errors,
        ];
    }

    private function nameExists(string $name, ?int $ignoreId): bool
    {
        $sql = "SELECT COUNT(*) FROM vehicule_categorii_capacitate WHERE nume = :nume";
        if ($ignoreId !== null) {
            $sql .= " AND id <> :id";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':nume', $name);
        if ($ignoreId !== null) {
            $stmt->bindValue(':id', $ignoreId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    // =================================================================
    // Audit
    // =================================================================

    /**
     * Logheaza o modificare de capacitate reala / categorie / stare de
     * verificare. Se apeleaza din fisa vehiculului si de la reasignari.
     */
    public function logChange(
        int $vehicleId,
        ?float $oldCapacity,
        ?float $newCapacity,
        ?int $oldCategoryId,
        ?int $newCategoryId,
        bool $oldConfirmed,
        bool $newConfirmed,
        ?string $reason,
        ?int $userId
    ): void {
        $this->ensureSchema();

        $stmt = $this->db->prepare("
            INSERT INTO vehicule_capacitate_audit
                (vehicle_id, capacitate_veche, capacitate_noua, categorie_veche_id, categorie_noua_id,
                 confirmata_veche, confirmata_noua, sursa, motiv, user_id, created_at)
            VALUES
                (:vehicle_id, :cap_veche, :cap_noua, :cat_veche, :cat_noua,
                 :conf_veche, :conf_noua, 'manual', :motiv, :user_id, NOW())
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':cap_veche', $oldCapacity, $oldCapacity === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':cap_noua', $newCapacity, $newCapacity === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':cat_veche', $oldCategoryId, $oldCategoryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':cat_noua', $newCategoryId, $newCategoryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':conf_veche', $oldConfirmed ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':conf_noua', $newConfirmed ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':motiv', $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return list<array<string, mixed>> */
    public function getAuditTrail(int $limit = 50): array
    {
        $this->ensureSchema();

        $limit = max(1, min(500, $limit));

        $rows = $this->db->query("
            SELECT
                a.*,
                v.nr_inmatriculare,
                cv.nume AS categorie_veche,
                cn.nume AS categorie_noua,
                u.nume AS utilizator
            FROM vehicule_capacitate_audit a
            INNER JOIN vehicule v ON v.id = a.vehicle_id
            LEFT JOIN vehicule_categorii_capacitate cv ON cv.id = a.categorie_veche_id
            LEFT JOIN vehicule_categorii_capacitate cn ON cn.id = a.categorie_noua_id
            LEFT JOIN utilizatori u ON u.id = a.user_id
            WHERE a.sursa = 'manual'
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT " . $limit . "
        ")->fetchAll();

        return $rows;
    }

    // =================================================================
    // Raportul de verificare a capacitatii reale
    // =================================================================

    /**
     * Vehiculele a caror capacitate reala trebuie verificata de un om.
     *
     * Nu inventam nimic: nu deducem capacitati din numele categoriei si nu
     * corectam automat nimic. Doar aratam ce dovezi exista in curse, ca
     * administratorul sa poata decide:
     *   - `tone_max_inregistrate` = cea mai mare cantitate transportata real,
     *     din snapshot-urile de cursa. Daca depaseste capacitatea stocata,
     *     capacitatea stocata este sigur gresita.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function getVerificationReport(): array
    {
        $this->ensureSchema();

        $cargoTypes = "'" . implode("', '", self::CARGO_VEHICLE_TYPES) . "'";

        $rows = $this->db->query("
            SELECT
                v.id,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                v.status,
                v.capacitate_transport,
                v.capacitate_transport_confirmata,
                v.categorie_capacitate_id,
                c.nume AS categorie,
                a.capacitate_veche AS capacitate_inainte_migrare,
                t.tone_max_inregistrate,
                t.curse
            FROM vehicule v
            LEFT JOIN vehicule_categorii_capacitate c ON c.id = v.categorie_capacitate_id
            LEFT JOIN (
                SELECT vehicle_id, MAX(capacitate_veche) AS capacitate_veche
                FROM vehicule_capacitate_audit
                WHERE sursa = 'migrare'
                GROUP BY vehicle_id
            ) a ON a.vehicle_id = v.id
            LEFT JOIN (
                SELECT
                    cd.vehicle_id,
                    COUNT(*) AS curse,
                    MAX(
                        CASE
                            WHEN cd.cantitate_incarcata IS NULL OR cd.cantitate_incarcata <= 0 THEN 0
                            WHEN cd.capacitate_transport IS NOT NULL
                                 AND cd.capacitate_transport > 0
                                 AND cd.cantitate_incarcata > (cd.capacitate_transport * 3)
                            THEN cd.cantitate_incarcata / 1000
                            WHEN cd.cantitate_incarcata >= 1000 THEN cd.cantitate_incarcata / 1000
                            ELSE cd.cantitate_incarcata
                        END
                    ) AS tone_max_inregistrate
                FROM curse_dispecer cd
                WHERE cd.deleted_at IS NULL
                GROUP BY cd.vehicle_id
            ) t ON t.vehicle_id = v.id
            WHERE v.capacitate_transport_confirmata = 0
              AND v.tip_vehicul IN ({$cargoTypes})
              AND v.nr_inmatriculare <> 'STOC-ANVELOPE'
            ORDER BY v.status ASC, c.ordine_afisare ASC, v.nr_inmatriculare ASC
        ")->fetchAll();

        $summary = [
            'total' => 0,
            'fara_capacitate' => 0,
            'contrazise_de_curse' => 0,
            'doar_de_confirmat' => 0,
        ];

        $result = [];
        foreach ($rows as $row) {
            $capacity = $row['capacitate_transport'] === null ? null : (float) $row['capacitate_transport'];
            $maxTons = $row['tone_max_inregistrate'] === null ? null : (float) $row['tone_max_inregistrate'];

            if ($capacity === null || $capacity <= 0) {
                $priority = 'lipsa';
                $summary['fara_capacitate']++;
            } elseif ($maxTons !== null && $maxTons > $capacity + 0.001) {
                $priority = 'contrazisa';
                $summary['contrazise_de_curse']++;
            } else {
                $priority = 'de_confirmat';
                $summary['doar_de_confirmat']++;
            }
            $summary['total']++;

            $row['id'] = (int) $row['id'];
            $row['capacitate_transport'] = $capacity;
            $row['tone_max_inregistrate'] = $maxTons;
            $row['curse'] = (int) ($row['curse'] ?? 0);
            $row['prioritate'] = $priority;
            $result[] = $row;
        }

        // Prioritatea grea (dovezi contra) sus, apoi lipsa capacitate.
        $weight = ['contrazisa' => 0, 'lipsa' => 1, 'de_confirmat' => 2];
        usort($result, static function (array $a, array $b) use ($weight): int {
            return ($weight[$a['prioritate']] <=> $weight[$b['prioritate']])
                ?: strcmp((string) $a['nr_inmatriculare'], (string) $b['nr_inmatriculare']);
        });

        return ['rows' => $result, 'summary' => $summary];
    }

    /**
     * Cat s-a migrat: cifrele cerute in raportul de migrare.
     *
     * @return array<string, int>
     */
    public function getMigrationReport(): array
    {
        $this->ensureSchema();

        $row = $this->db->query("
            SELECT
                (SELECT COUNT(*) FROM vehicule_capacitate_audit WHERE sursa = 'migrare') AS vehicule_procesate,
                (SELECT COUNT(*) FROM vehicule_categorii_capacitate) AS categorii_create,
                (SELECT COUNT(*) FROM vehicule WHERE categorie_capacitate_id IS NOT NULL) AS vehicule_asignate,
                (SELECT COUNT(*) FROM vehicule WHERE categorie_capacitate_id IS NULL) AS vehicule_fara_categorie,
                (SELECT COUNT(*) FROM vehicule WHERE capacitate_transport_confirmata = 1) AS capacitati_confirmate
        ")->fetch() ?: [];

        return array_map('intval', $row);
    }
}
