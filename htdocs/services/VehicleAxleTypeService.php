<?php
declare(strict_types=1);

/**
 * Tipul fiecarei axe (Directie / Tractiune / Portanta / Trailer), ales din fisa vehiculului.
 *
 * Se salveaza in vehicule_axe_atribute.tip_axa; sincronizarea pozitiilor (TireModel) il aplica peste
 * regula formulei, deci ramane valabil la orice resincronizare. Tipul decide ce anvelope se pot monta,
 * asa ca o axa cu anvelope montate nu isi poate schimba tipul pana la demontare.
 */
final class VehicleAxleTypeService
{
    public const KINDS = ['directie' => 'Direcție', 'tractiune' => 'Tracțiune', 'portanta' => 'Portantă', 'trailer' => 'Trailer'];
    public const MOTOR_KINDS = ['directie', 'tractiune', 'portanta'];
    public const TRAILER_KINDS = ['portanta', 'trailer'];
    private const SUPPORTED_TYPES = ['camion', 'cap_tractor', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie'];
    /** Semiremorcile la care axele de la a doua incolo se numesc Trailer. */
    private const TRAILER_NAMED_AXLE_COUNTS = [3, 4, 6];

    private PDO $db;
    private TireModel $tires;

    public function __construct(PDO $db, TireModel $tires)
    {
        $this->db = $db;
        $this->tires = $tires;
        new VehicleComponentModel($db); // creeaza tabela si coloana tip_axa (in afara tranzactiilor)
    }

    public static function isTrailerType(string $vehicleType): bool
    {
        return str_starts_with($vehicleType, 'semiremorca');
    }

    /** @return list<string> tipurile de axa posibile pentru un tip de vehicul */
    public static function kindsFor(string $vehicleType): array
    {
        return self::isTrailerType($vehicleType) ? self::TRAILER_KINDS : self::MOTOR_KINDS;
    }

    /** Tipul unei axe fara alegere salvata. */
    public static function defaultKind(string $vehicleType, int $axleCount, int $axleNo, string $axleType): string
    {
        if (self::isTrailerType($vehicleType)) {
            return $axleNo >= 2 && in_array($axleCount, self::TRAILER_NAMED_AXLE_COUNTS, true) ? 'trailer' : 'portanta';
        }

        return match ($axleType) {
            TireModel::AXLE_TRACTION => 'tractiune',
            TireModel::AXLE_TRAILER => 'portanta',
            default => 'directie',
        };
    }

    /** Eticheta unei axe de semiremorca dupa formula (ex. „3 axe”). */
    public static function trailerAxleLabel(string $layout, int $axleNo): string
    {
        return self::KINDS[self::defaultKind('semiremorca', (int) $layout, $axleNo, TireModel::AXLE_TRAILER)];
    }

    /** Alegerea salvata; la vehiculele cu motor, datele vechi o deduc din Ridicabila / Portanta. */
    private static function kindFromRow(?array $row, string $vehicleType): ?string
    {
        if ($row === null) {
            return null;
        }
        if (isset(self::KINDS[(string) $row['tip_axa']])) {
            return (string) $row['tip_axa'];
        }

        return !self::isTrailerType($vehicleType) && ((string) $row['ridicabila'] === '1' || (string) $row['portanta'] === '1') ? 'portanta' : null;
    }

    private static function rowsFor(PDO $db, int $vehicleId): array
    {
        $stmt = $db->prepare('SELECT axle_no, tip_axa, ridicabila, portanta FROM vehicule_axe_atribute WHERE vehicle_id = :v');
        $stmt->execute([':v' => $vehicleId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['axle_no']] = $row;
        }

        return $rows;
    }

    /**
     * Tipul fiecarei axe a unui vehicul real (fara DDL: se poate apela si din alte module).
     *
     * @param list<array{no:int,type:string}> $layout
     * @return array<int,string> axa -> tip
     */
    public static function resolveKinds(PDO $db, int $vehicleId, string $vehicleType, array $layout): array
    {
        $rows = self::rowsFor($db, $vehicleId);
        $kinds = [];
        foreach ($layout as $axle) {
            $no = (int) $axle['no'];
            $kinds[$no] = self::kindFromRow($rows[$no] ?? null, $vehicleType)
                ?? self::defaultKind($vehicleType, count($layout), $no, (string) $axle['type']);
        }

        return $kinds;
    }

    /** Axele unei formule: numar, roti duble, tipul implicit, optiunile permise. */
    private function axles(string $vehicleType, string $layout): array
    {
        if (!in_array($vehicleType, self::SUPPORTED_TYPES, true)) {
            return [];
        }
        $byAxle = [];
        foreach ($this->tires->describeVehicleLayout($vehicleType, $layout)['positions'] as $position) {
            $no = (int) $position['axle_no'];
            $byAxle[$no] = ['no' => $no, 'dual' => ($byAxle[$no]['dual'] ?? false) || $position['wheel_kind'] === 'dual', 'axle_type' => (string) $position['axle_type']];
        }
        ksort($byAxle);

        $trailer = self::isTrailerType($vehicleType);
        $count = count($byAxle);
        return array_map(static function (array $axle) use ($trailer, $vehicleType, $count): array {
            $locked = !$trailer && $axle['no'] === 1; // prima axa a unui vehicul cu motor e mereu de directie
            return $axle + [
                'default' => self::defaultKind($vehicleType, $count, $axle['no'], $axle['axle_type']),
                'locked' => $locked,
                'options' => $locked ? ['directie'] : self::kindsFor($vehicleType),
            ];
        }, array_values($byAxle));
    }

    /** Pentru formular: tip vehicul -> formula -> axe. */
    public function layoutMap(): array
    {
        $map = [];
        foreach (self::SUPPORTED_TYPES as $type) {
            foreach (array_keys($this->tires->getLayoutOptionsByVehicleType($type)) as $layout) {
                $map[$type][(string) $layout] = $this->axles($type, (string) $layout);
            }
        }

        return $map;
    }

    private function vehicleType(int $vehicleId): string
    {
        $stmt = $this->db->prepare('SELECT tip_vehicul FROM vehicule WHERE id = :v');
        $stmt->execute([':v' => $vehicleId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @return array<int,string> axa -> tip, doar unde exista o alegere */
    public function savedKinds(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }
        $vehicleType = $this->vehicleType($vehicleId);
        $kinds = [];
        foreach (self::rowsFor($this->db, $vehicleId) as $no => $row) {
            $kind = self::kindFromRow($row, $vehicleType);
            if ($kind !== null) {
                $kinds[$no] = $kind;
            }
        }

        return $kinds;
    }

    /** @return array<int,array<int,string>> vehicul -> axa -> eticheta, pentru Configuratie Axe si fisa vehiculului */
    public function labels(): array
    {
        $rows = $this->db->query(
            'SELECT a.vehicle_id, a.axle_no, a.tip_axa, a.ridicabila, a.portanta, v.tip_vehicul
             FROM vehicule_axe_atribute a
             INNER JOIN vehicule v ON v.id = a.vehicle_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $labels = [];
        foreach ($rows as $row) {
            $kind = self::kindFromRow($row, (string) $row['tip_vehicul']);
            if ($kind !== null) {
                $labels[(int) $row['vehicle_id']][(int) $row['axle_no']] = self::KINDS[$kind];
            }
        }

        return $labels;
    }

    /** Tipul curent al fiecarei axe a formulei: cel salvat, altfel cel implicit. */
    private function currentKinds(int $vehicleId, string $vehicleType, array $axles): array
    {
        $rows = $vehicleId > 0 ? self::rowsFor($this->db, $vehicleId) : [];
        $kinds = [];
        foreach ($axles as $axle) {
            $saved = self::kindFromRow($rows[$axle['no']] ?? null, $vehicleType);
            $kinds[$axle['no']] = $saved !== null && in_array($saved, $axle['options'], true) ? $saved : $axle['default'];
        }

        return $kinds;
    }

    /** Alegerile din formular; o valoare lipsa sau nepermisa pastreaza tipul curent. */
    private function submitted(array $axles, array $current, array $post): array
    {
        $posted = is_array($post['tip_axa'] ?? null) ? $post['tip_axa'] : [];
        $kinds = [];
        foreach ($axles as $axle) {
            $value = (string) ($posted[$axle['no']] ?? '');
            $kinds[$axle['no']] = in_array($value, $axle['options'], true) ? $value : $current[$axle['no']];
        }

        return $kinds;
    }

    /** @return string|null mesajul de eroare */
    public function validate(int $vehicleId, string $vehicleType, string $layout, array $post): ?string
    {
        $axles = $this->axles($vehicleType, $layout);
        if ($axles === []) {
            return null;
        }
        $current = $this->currentKinds($vehicleId, $vehicleType, $axles);
        $kinds = $this->submitted($axles, $current, $post);
        if (!self::isTrailerType($vehicleType) && !in_array('tractiune', $kinds, true)) {
            return 'Vehiculul trebuie să aibă cel puțin o axă de tracțiune.';
        }
        if ($vehicleId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT p.axle_no, p.axle_type,
                    (SELECT COUNT(*) FROM anvelope_alocari a WHERE a.position_id = p.id AND a.data_end IS NULL) AS mounted
             FROM vehicule_anvelope_pozitii p
             WHERE p.vehicle_id = :v AND p.is_active = 1'
        );
        $stmt->execute([':v' => $vehicleId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $position) {
            $no = (int) $position['axle_no'];
            if (
                isset($kinds[$no])
                && (int) $position['mounted'] > 0
                && $kinds[$no] !== $current[$no]
                && TireModel::AXLE_KIND_TYPES[$kinds[$no]] !== (string) $position['axle_type']
            ) {
                return 'Axa ' . $no . ' are anvelope montate. Demontează-le înainte de a-i schimba tipul.';
            }
        }

        return null;
    }

    /** Scrie doar alegerile reale; un formular salvat fara modificari nu creeaza randuri. */
    public function save(int $vehicleId, string $vehicleType, string $layout, array $post, ?int $userId): void
    {
        $axles = $this->axles($vehicleType, $layout);
        if ($axles === [] || $vehicleId <= 0) {
            return;
        }
        $rows = self::rowsFor($this->db, $vehicleId);
        $current = $this->currentKinds($vehicleId, $vehicleType, $axles);
        $kinds = $this->submitted($axles, $current, $post);

        $write = $this->db->prepare(
            'INSERT INTO vehicule_axe_atribute (vehicle_id, axle_no, tip_axa, portanta, updated_by, created_at, updated_at)
             VALUES (:vehicle_id, :axle_no, :tip_axa, :portanta, :updated_by, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE tip_axa = VALUES(tip_axa), portanta = VALUES(portanta), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        );
        $changed = false;
        $now = date('Y-m-d H:i:s');
        foreach ($kinds as $no => $kind) {
            $stored = $rows[$no]['tip_axa'] ?? null;
            if ($stored === $kind || ($stored === null && $kind === $current[$no])) {
                continue;
            }
            $write->execute([
                ':vehicle_id' => $vehicleId,
                ':axle_no' => $no,
                ':tip_axa' => $kind,
                ':portanta' => $kind === 'portanta' ? 1 : 0,
                ':updated_by' => $userId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $changed = true;
        }

        // Axele care nu mai exista in formula nu pastreaza un tip.
        $clear = $this->db->prepare('UPDATE vehicule_axe_atribute SET tip_axa = NULL WHERE vehicle_id = :v AND tip_axa IS NOT NULL AND axle_no NOT IN (' . implode(',', array_map('intval', array_keys($kinds))) . ')');
        $clear->execute([':v' => $vehicleId]);

        if ($changed || $clear->rowCount() > 0) {
            $this->tires->syncVehiclePositions($vehicleId, $vehicleType, $layout);
        }
    }
}
