<?php
declare(strict_types=1);

/**
 * Diurna per sofer, stabilita din Contabilitate Personal.
 *
 * Fiecare schimbare (primeste / nu primeste, valoare pe zi) este un rand nou in
 * soferi_diurna_istoric, cu data de la care se aplica. Pentru o cursa conteaza
 * randul valabil la data inceperii ei. Un sofer fara niciun rand este "nesetat":
 * diurnele i se numara, dar nu au valoare in lei.
 *
 * Numarul de diurne al unei curse ramane calculat in helpers.php
 * (dispatcher_diurna_for_interval); aici se decide doar daca soferul le primeste
 * si cat valoreaza.
 */
class DriverDiurnaModel extends BaseModel
{
    public const STATUS_RECEIVES = 'primeste';
    public const STATUS_NONE = 'fara';
    public const STATUS_UNSET = 'nesetat';

    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    /**
     * Istoricul soferilor, grupat pe sofer, de la cel mai nou la cel mai vechi.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getHistoryForDrivers(array $driverIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $driverIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT h.*, u.nume AS created_by_name
            FROM soferi_diurna_istoric h
            LEFT JOIN utilizatori u ON u.id = h.created_by
            WHERE h.driver_id IN ($placeholders)
            ORDER BY h.driver_id ASC, h.data_aplicare DESC, h.id DESC
        ");
        $stmt->execute($ids);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int) $row['driver_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Regula valabila la o data, din istoricul unui sofer (ordonat descrescator).
     *
     * @return array{status: string, rate: ?float, since: ?string}
     */
    public static function policyAt(array $history, ?string $date): array
    {
        $date = $date !== null && $date !== '' ? substr($date, 0, 10) : date('Y-m-d');
        foreach ($history as $row) {
            if ((string) $row['data_aplicare'] <= $date) {
                $receives = (int) $row['primeste_diurna'] === 1;
                return [
                    'status' => $receives ? self::STATUS_RECEIVES : self::STATUS_NONE,
                    'rate' => $receives && $row['valoare_zi'] !== null ? (float) $row['valoare_zi'] : null,
                    'since' => (string) $row['data_aplicare'],
                ];
            }
        }

        return ['status' => self::STATUS_UNSET, 'rate' => null, 'since' => null];
    }

    /** Regula curenta (azi) pentru fiecare sofer cerut. */
    public function getCurrentPolicies(array $driverIds): array
    {
        $policies = [];
        $history = $this->getHistoryForDrivers($driverIds);
        foreach ($driverIds as $driverId) {
            $policies[(int) $driverId] = self::policyAt($history[(int) $driverId] ?? [], null);
        }

        return $policies;
    }

    public function save(int $driverId, bool $receives, ?float $ratePerDay, string $effectiveDate, ?string $notes, ?int $userId): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO soferi_diurna_istoric (driver_id, primeste_diurna, valoare_zi, data_aplicare, observatii, created_by, created_at)
            VALUES (:driver_id, :primeste, :valoare, :data_aplicare, :observatii, :created_by, :created_at)
        ');
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':primeste', $receives ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':valoare', $receives && $ratePerDay !== null ? (string) $ratePerDay : null, $receives && $ratePerDay !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':data_aplicare', $effectiveDate, PDO::PARAM_STR);
        $stmt->bindValue(':observatii', $notes, $notes !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':created_by', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
    }

    /** Eticheta scurta pentru liste: "150,00 lei / zi", "Fara diurna", "Nesetat". */
    public static function label(array $policy): string
    {
        return match ($policy['status']) {
            self::STATUS_NONE => 'Fără diurnă',
            self::STATUS_RECEIVES => $policy['rate'] !== null ? format_number_ro((float) $policy['rate'], 2) . ' lei / zi' : 'Primește (valoare nesetată)',
            default => 'Nesetat',
        };
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS soferi_diurna_istoric (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                driver_id INT UNSIGNED NOT NULL,
                primeste_diurna TINYINT(1) NOT NULL DEFAULT 1,
                valoare_zi DECIMAL(10,2) NULL,
                data_aplicare DATE NOT NULL,
                observatii VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY idx_soferi_diurna_driver (driver_id, data_aplicare),
                CONSTRAINT fk_soferi_diurna_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
                CONSTRAINT fk_soferi_diurna_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$schemaReady = true;
    }
}
