<?php
declare(strict_types=1);

/**
 * Componentele montate pe un loc al vehiculului (exploratorul din Stoc Anvelope, etapa 2).
 *
 * - Montarile sunt randurile existente din mentenanta_piese_utilizari, cu locul completat
 *   (component_key, locatie, axa, parte). O montare noua reseteaza uzura din Reparatii Auto
 *   exact ca orice alta montare (AutoComponentStateService citeste ultima montare a piesei).
 * - Din stoc: piesa se blocheaza (FOR UPDATE), cantitatea se verifica si scade; montaj direct
 *   (piesa adusa de service): stocul nu se schimba — ca in Interventii si Registrul de piese.
 * - Inlocuirea inchide montarea veche (demontat_la, motiv „inlocuire”, inlocuita_de_id) in
 *   aceeasi tranzactie cu montarea noua. Demontarea doar inchide montarea; „retur in stoc”
 *   readuce cantitatea in stoc. Nimic nu se sterge.
 * - Atributele axelor (vehicule_axe_atribute) si inspectiile (vehicule_componente_inspectii).
 *
 * Schema: database/migrations/2026_11_04_000001_componente_vehicul_locatii.sql, aplicata si aici
 * (ensureSchema), in afara oricarei tranzactii (DDL face COMMIT implicit).
 */
class VehicleComponentModel extends BaseModel
{
    public const UNMOUNT_REASONS = ['uzura' => 'Uzură', 'defect' => 'Defect', 'retur_stoc' => 'Retur în stoc', 'altul' => 'Alt motiv', 'inlocuire' => 'Înlocuire'];
    public const INSPECTION_RESULTS = ['ok' => 'OK', 'atentie' => 'Atenție', 'inlocuire' => 'Necesită înlocuire'];
    public const SUSPENSION_TYPES = ['pneumatica' => 'Pneumatică', 'arcuri' => 'Arcuri (foi)', 'mixta' => 'Mixtă'];
    public const BRAKE_TYPES = ['disc' => 'Disc', 'tambur' => 'Tambur'];

    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $hasColumn = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mentenanta_piese_utilizari' AND COLUMN_NAME = 'locatie'"
        )->fetchColumn() > 0;
        $tables = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('vehicule_axe_atribute', 'vehicule_componente_inspectii')"
        )->fetchColumn();
        if (!$hasColumn || $tables < 2) {
            $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_11_04_000001_componente_vehicul_locatii.sql');
            $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if ($hasColumn && str_starts_with($statement, 'ALTER TABLE `mentenanta_piese_utilizari`')) {
                    continue;
                }
                $this->db->exec($statement);
            }
        }
        $hasAxleKind = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_axe_atribute' AND COLUMN_NAME = 'tip_axa'"
        )->fetchColumn() > 0;
        if (!$hasAxleKind) {
            $this->db->exec('ALTER TABLE vehicule_axe_atribute ADD COLUMN tip_axa VARCHAR(12) NULL AFTER axle_no');
        }
        self::$schemaReady = true;
    }

    /** @return array<int,array<int,array>> vehicul -> axa -> atribute */
    public function axleAttributes(array $vehicleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if ($ids === []) {
            return [];
        }
        $result = [];
        foreach ($this->db->query('SELECT * FROM vehicule_axe_atribute WHERE vehicle_id IN (' . implode(',', $ids) . ')')->fetchAll() as $row) {
            $result[(int) $row['vehicle_id']][(int) $row['axle_no']] = [
                'liftable' => $row['ridicabila'] === null ? null : (bool) $row['ridicabila'],
                'load_bearing' => $row['portanta'] === null ? null : (bool) $row['portanta'],
                'suspension' => $row['tip_suspensie'],
                'brakes' => $row['tip_franare'],
                'notes' => (string) ($row['observatii'] ?? ''),
            ];
        }
        return $result;
    }

    public function saveAxleAttributes(int $vehicleId, int $axleNo, array $data, ?int $userId): void
    {
        $exists = $this->db->prepare('SELECT COUNT(*) FROM vehicule_anvelope_pozitii WHERE vehicle_id = :vehicle_id AND axle_no = :axle_no AND is_active = 1');
        $exists->execute([':vehicle_id' => $vehicleId, ':axle_no' => $axleNo]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new InvalidArgumentException('Axa nu există în configurația vehiculului.');
        }
        // Ridicabila / Portanta vin din tipul axei, ales in fisa vehiculului (VehicleAxleTypeService).
        $suspension = (string) ($data['tip_suspensie'] ?? '');
        $brakes = (string) ($data['tip_franare'] ?? '');
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO vehicule_axe_atribute (vehicle_id, axle_no, tip_suspensie, tip_franare, observatii, updated_by, created_at, updated_at)
             VALUES (:vehicle_id, :axle_no, :tip_suspensie, :tip_franare, :observatii, :updated_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE tip_suspensie = VALUES(tip_suspensie),
                tip_franare = VALUES(tip_franare), observatii = VALUES(observatii), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        )->execute([
            ':vehicle_id' => $vehicleId,
            ':axle_no' => $axleNo,
            ':tip_suspensie' => isset(self::SUSPENSION_TYPES[$suspension]) ? $suspension : null,
            ':tip_franare' => isset(self::BRAKE_TYPES[$brakes]) ? $brakes : null,
            ':observatii' => mb_substr(trim((string) ($data['observatii'] ?? '')), 0, 255) ?: null,
            ':updated_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /** Ultima inspectie pe fiecare (categorie, loc): @return array<int,array<string,array>> vehicul -> "categorie|loc" -> rand */
    public function latestInspections(array $vehicleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT i.* FROM vehicule_componente_inspectii i
             INNER JOIN (
                SELECT vehicle_id, categorie, locatie, MAX(CONCAT(data_inspectie, LPAD(id, 10, "0"))) AS last_key
                FROM vehicule_componente_inspectii WHERE vehicle_id IN (' . implode(',', $ids) . ')
                GROUP BY vehicle_id, categorie, locatie
             ) last ON last.vehicle_id = i.vehicle_id AND last.categorie = i.categorie AND last.locatie = i.locatie
                   AND last.last_key = CONCAT(i.data_inspectie, LPAD(i.id, 10, "0"))'
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][$row['categorie'] . '|' . $row['locatie']] = $row;
        }
        return $result;
    }

    public function inspect(int $vehicleId, string $category, string $location, ?string $componentKey, string $date, ?int $km, string $result, string $notes, ?int $userId): void
    {
        if (!isset(self::INSPECTION_RESULTS[$result])) {
            throw new InvalidArgumentException('Rezultatul inspecției nu este valid.');
        }
        $this->assertDate($date, 'Data inspecției');
        $this->db->prepare(
            'INSERT INTO vehicule_componente_inspectii (vehicle_id, categorie, component_key, locatie, data_inspectie, km, rezultat, observatii, created_by, created_at)
             VALUES (:vehicle_id, :categorie, :component_key, :locatie, :data_inspectie, :km, :rezultat, :observatii, :created_by, :created_at)'
        )->execute([
            ':vehicle_id' => $vehicleId,
            ':categorie' => $category,
            ':component_key' => $componentKey !== '' ? $componentKey : null,
            ':locatie' => $location,
            ':data_inspectie' => $date,
            ':km' => $km,
            ':rezultat' => $result,
            ':observatii' => trim($notes) !== '' ? trim($notes) : null,
            ':created_by' => $userId,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Montarile si inspectiile unui loc: active (nedemontate) + istoric. */
    public function locationRecords(int $vehicleId, string $category, string $location, array $componentKeys): array
    {
        $mounts = [];
        if ($componentKeys !== []) {
            $placeholders = [];
            $params = [':vehicle_id' => $vehicleId, ':locatie' => $location];
            foreach (array_values($componentKeys) as $index => $key) {
                $placeholders[] = ':k' . $index;
                $params[':k' . $index] = $key;
            }
            $stmt = $this->db->prepare(
                'SELECT u.*, p.denumire AS part_name, p.cod_piesa, p.unitate_masura
                 FROM mentenanta_piese_utilizari u
                 INNER JOIN mentenanta_piese p ON p.id = u.part_id
                 WHERE u.vehicle_id = :vehicle_id AND u.locatie = :locatie AND u.component_key IN (' . implode(',', $placeholders) . ')
                 ORDER BY u.data_montare DESC, u.id DESC LIMIT 100'
            );
            $stmt->execute($params);
            $mounts = $stmt->fetchAll();
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM vehicule_componente_inspectii
             WHERE vehicle_id = :vehicle_id AND categorie = :categorie AND locatie = :locatie
             ORDER BY data_inspectie DESC, id DESC LIMIT 50'
        );
        $stmt->execute([':vehicle_id' => $vehicleId, ':categorie' => $category, ':locatie' => $location]);

        return [
            'active' => array_values(array_filter($mounts, static fn (array $row): bool => $row['demontat_la'] === null)),
            'mounts' => $mounts,
            'inspections' => $stmt->fetchAll(),
        ];
    }

    /**
     * Montare pe loc (sau inlocuire, cu $replaceUsageId). Totul intr-o tranzactie.
     *
     * @param array{vehicle_id:int,part_id:int,component_key:string,location:string,axle:?int,side:?string,source:string,quantity:float,date:string,km:?int,notes:string} $data
     * @return int id-ul montarii noi
     */
    public function mount(array $data, ?int $replaceUsageId, ?int $userId, string $mountedBy): int
    {
        $this->assertDate((string) $data['date'], 'Data montării');
        $quantity = (float) $data['quantity'];
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Cantitatea trebuie să fie mai mare decât 0.');
        }
        $fromStock = $data['source'] === 'stoc';

        $this->db->beginTransaction();
        try {
            $partStmt = $this->db->prepare('SELECT * FROM mentenanta_piese WHERE id = :id AND arhivat_la IS NULL FOR UPDATE');
            $partStmt->execute([':id' => (int) $data['part_id']]);
            $part = $partStmt->fetch();
            if (!$part) {
                throw new RuntimeException('Piesa componentei nu există în stoc (sincronizează catalogul din Reparații Auto).');
            }
            if ($fromStock) {
                if ((string) ($part['mod_utilizare'] ?? 'stoc') !== 'stoc' || (string) ($part['status_piesa'] ?? 'activa') !== 'activa') {
                    throw new RuntimeException('Piesa nu este activă în stoc; folosește montajul direct.');
                }
                if ((float) $part['stoc_curent'] < $quantity) {
                    throw new RuntimeException('Stoc insuficient: în stoc sunt ' . rtrim(rtrim(number_format((float) $part['stoc_curent'], 2, ',', '.'), '0'), ',') . ' ' . (string) ($part['unitate_masura'] ?? 'buc') . '.');
                }
            }

            $old = null;
            if ($replaceUsageId !== null) {
                $oldStmt = $this->db->prepare('SELECT * FROM mentenanta_piese_utilizari WHERE id = :id FOR UPDATE');
                $oldStmt->execute([':id' => $replaceUsageId]);
                $old = $oldStmt->fetch();
                if (!$old || (int) $old['vehicle_id'] !== (int) $data['vehicle_id'] || $old['demontat_la'] !== null || (string) $old['locatie'] !== (string) $data['location']) {
                    throw new RuntimeException('Montarea de înlocuit nu mai este activă pe această poziție. Reîncarcă panoul.');
                }
                if ((string) $data['date'] < (string) $old['data_montare']) {
                    throw new InvalidArgumentException('Data înlocuirii nu poate fi înaintea montării anterioare (' . date('d.m.Y', strtotime((string) $old['data_montare'])) . ').');
                }
            }

            if ($fromStock) {
                $this->db->prepare('UPDATE mentenanta_piese SET stoc_curent = stoc_curent - :quantity, updated_at = :updated_at WHERE id = :id')
                    ->execute([':quantity' => $quantity, ':updated_at' => date('Y-m-d H:i:s'), ':id' => (int) $part['id']]);
            }
            $this->db->prepare(
                'INSERT INTO mentenanta_piese_utilizari
                    (part_id, vehicle_id, cantitate, cost_unitar, data_montare, km_montare, montata_de, observatii, direct_mount,
                     component_key, locatie, axle_no, parte, created_by, created_at)
                 VALUES
                    (:part_id, :vehicle_id, :cantitate, :cost_unitar, :data_montare, :km_montare, :montata_de, :observatii, :direct_mount,
                     :component_key, :locatie, :axle_no, :parte, :created_by, :created_at)'
            )->execute([
                ':part_id' => (int) $part['id'],
                ':vehicle_id' => (int) $data['vehicle_id'],
                ':cantitate' => $quantity,
                ':cost_unitar' => $fromStock ? max(0, (float) ($part['pret_achizitie'] ?? 0)) : 0,
                ':data_montare' => (string) $data['date'],
                ':km_montare' => $data['km'],
                ':montata_de' => $mountedBy !== '' ? $mountedBy : null,
                ':observatii' => trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                ':direct_mount' => $fromStock ? 0 : 1,
                ':component_key' => (string) $data['component_key'],
                ':locatie' => (string) $data['location'],
                ':axle_no' => $data['axle'],
                ':parte' => $data['side'],
                ':created_by' => $userId,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            $newId = (int) $this->db->lastInsertId();

            if ($old !== null) {
                $this->db->prepare(
                    'UPDATE mentenanta_piese_utilizari
                     SET demontat_la = :data, km_demontare = :km, motiv_demontare = "inlocuire", inlocuita_de_id = :new_id
                     WHERE id = :id'
                )->execute([':data' => (string) $data['date'], ':km' => $data['km'], ':new_id' => $newId, ':id' => (int) $old['id']]);
            }

            $this->db->commit();
            return $newId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function unmount(int $usageId, int $vehicleId, string $date, ?int $km, string $reason, string $notes): void
    {
        $this->assertDate($date, 'Data demontării');
        if (!isset(self::UNMOUNT_REASONS[$reason]) || $reason === 'inlocuire') {
            throw new InvalidArgumentException('Motivul demontării nu este valid.');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM mentenanta_piese_utilizari WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $usageId]);
            $usage = $stmt->fetch();
            if (!$usage || (int) $usage['vehicle_id'] !== $vehicleId || $usage['demontat_la'] !== null || $usage['locatie'] === null) {
                throw new RuntimeException('Montarea nu mai este activă. Reîncarcă panoul.');
            }
            if ($date < (string) $usage['data_montare']) {
                throw new InvalidArgumentException('Data demontării nu poate fi înaintea montării (' . date('d.m.Y', strtotime((string) $usage['data_montare'])) . ').');
            }
            $this->db->prepare(
                'UPDATE mentenanta_piese_utilizari SET demontat_la = :data, km_demontare = :km, motiv_demontare = :motiv, observatii_demontare = :obs WHERE id = :id'
            )->execute([':data' => $date, ':km' => $km, ':motiv' => $reason, ':obs' => trim($notes) !== '' ? trim($notes) : null, ':id' => $usageId]);
            if ($reason === 'retur_stoc') {
                $this->db->prepare('UPDATE mentenanta_piese SET stoc_curent = stoc_curent + :quantity, updated_at = :updated_at WHERE id = :id')
                    ->execute([':quantity' => (float) $usage['cantitate'], ':updated_at' => date('Y-m-d H:i:s'), ':id' => (int) $usage['part_id']]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function assertDate(string $date, string $label): void
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException($label . ' nu este validă.');
        }
        if ($date > date('Y-m-d')) {
            throw new InvalidArgumentException($label . ' nu poate fi în viitor.');
        }
    }
}
