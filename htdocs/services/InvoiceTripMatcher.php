<?php
declare(strict_types=1);

/**
 * Asocierea automata a unei facturi (pagina "Facturi") la o cursa din Dispecer curse.
 *
 * Reguli:
 *   - cursa candidata: nestearsa, cu
 *       data_inceput - toleranta_inainte <= data_document <= data_sfarsit + toleranta_dupa
 *     (tolerantele sunt pe tip; implicit 0, la Cazare +1 zi dupa: factura de hotel
 *     poate purta data plecarii, ziua de dupa sfarsitul cursei);
 *   - Cazare / Diurna:  soferul e OBLIGATORIU; numarul de inmatriculare, daca exista,
 *                       doar restrange lista de candidati;
 *   - restul tipurilor: numarul de inmatriculare e OBLIGATORIU; soferul doar restrange;
 *   - soferul / vehiculul se verifica pe segmentul cursei activ la data documentului
 *     (curse_segmente), fiindca pe o cursa reluata se pot schimba;
 *   - 1 candidat -> asociata_auto, 2+ -> de_verificat (cu lista de candidati),
 *     0 -> neasociata; camp obligatoriu lipsa / negasit -> de_verificat cu motiv.
 *
 * Nucleul (decide) e pur: primeste factura si cursele deja incarcate, deci se poate
 * testa fara baza de date (scripts/test_invoice_matcher.php).
 */
class InvoiceTripMatcher
{
    /** Tolerante in zile: [inainte de data_inceput, dupa data_sfarsit]. */
    public const TOLERANCES = [
        'default' => ['before' => 0, 'after' => 0],
        'cazare' => ['before' => 0, 'after' => 1],
    ];

    /** Fereastra in care se cauta candidatii pentru alegerea manuala. */
    public const MANUAL_PICK_WINDOW_DAYS = 7;

    private ?PDO $db;

    /** @var array<string, array{before: int, after: int}> */
    private array $tolerances;

    /** @var array<string, int>|null nr. inmatriculare normalizat => vehicle_id */
    private ?array $vehicleIndex = null;

    /** @var array<string, int>|null nume normalizat => sofer_id */
    private ?array $driverIndex = null;

    /**
     * @param array<string, array{before?: int, after?: int}> $toleranceOverrides
     */
    public function __construct(?PDO $db = null, array $toleranceOverrides = [])
    {
        $this->db = $db;
        $this->tolerances = self::TOLERANCES;
        foreach ($toleranceOverrides as $type => $override) {
            $base = $this->tolerances[$type] ?? $this->tolerances['default'];
            $this->tolerances[$type] = [
                'before' => max(0, (int) ($override['before'] ?? $base['before'])),
                'after' => max(0, (int) ($override['after'] ?? $base['after'])),
            ];
        }
    }

    /** @return array{before: int, after: int} */
    public function toleranceFor(string $type): array
    {
        return $this->tolerances[$type] ?? $this->tolerances['default'];
    }

    // -------------------------------------------------------------------------
    // Normalizari (publice: folosite si la afisare / deduplicare)
    // -------------------------------------------------------------------------

    /** "b-123 abc" / "B 123 ABC" / "B123ABC" -> "B123ABC". */
    public static function normalizePlate(?string $plate): string
    {
        $plate = strtoupper(trim((string) $plate));

        return preg_replace('/[^A-Z0-9]+/', '', $plate) ?? '';
    }

    /**
     * Aceeasi normalizare de nume ca la importul de cazari
     * (CazariSheetImportService::normalizeName): litere mici, fara diacritice,
     * doar litere si spatii simple.
     */
    public static function normalizePersonName(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($transliterated !== false) {
            $name = $transliterated;
        }
        $name = preg_replace('/[^a-z ]+/', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    // -------------------------------------------------------------------------
    // Nucleul pur
    // -------------------------------------------------------------------------

    /**
     * @param array{tip: string, data_document: ?string, vehicle_id?: ?int, driver_id?: ?int, nr_inmatriculare_extras?: ?string, sofer_extras?: ?string} $invoice
     * @param array<int, array<string, mixed>> $trips cursele, fiecare cu cheia 'segments' (poate lipsi / fi goala)
     * @return array{status: string, cursa_id: ?int, candidates: array<int, array<string, mixed>>, reason: string}
     */
    public function decide(array $invoice, array $trips): array
    {
        $type = (string) ($invoice['tip'] ?? '');
        $required = InvoiceModel::requiredMatchField($type);
        if ($required === null) {
            return $this->result('de_verificat', [], 'Tip de factura necunoscut: "' . $type . '".');
        }

        $date = $this->parseDate($invoice['data_document'] ?? null);
        if ($date === null) {
            return $this->result('de_verificat', [], 'Lipseste data documentului.');
        }

        $vehicleId = (int) ($invoice['vehicle_id'] ?? 0) ?: null;
        $driverId = (int) ($invoice['driver_id'] ?? 0) ?: null;
        $plateRaw = trim((string) ($invoice['nr_inmatriculare_extras'] ?? ''));
        $driverRaw = trim((string) ($invoice['sofer_extras'] ?? ''));

        if ($required === 'sofer' && $driverId === null) {
            return $this->result('de_verificat', [], $driverRaw !== ''
                ? 'Soferul "' . $driverRaw . '" nu a fost gasit in lista de soferi.'
                : 'Lipseste soferul (obligatoriu pentru ' . $this->typeLabel($type) . ').');
        }
        if ($required === 'vehicul' && $vehicleId === null) {
            return $this->result('de_verificat', [], $plateRaw !== ''
                ? 'Numarul "' . $plateRaw . '" nu a fost gasit in lista de vehicule.'
                : 'Lipseste numarul de inmatriculare (obligatoriu pentru ' . $this->typeLabel($type) . ').');
        }

        $tolerance = $this->toleranceFor($type);
        $matches = [];

        foreach ($trips as $trip) {
            $start = $this->parseDate($trip['data_inceput'] ?? null);
            $end = $this->parseDate($trip['data_sfarsit'] ?? null);
            if ($start === null || $end === null) {
                continue;
            }

            $windowStart = $start->modify('-' . $tolerance['before'] . ' days');
            $windowEnd = $end->modify('+' . $tolerance['after'] . ' days');
            if ($date < $windowStart || $date > $windowEnd) {
                continue;
            }

            $participants = $this->participantsOn($trip, $date, $start, $end);
            $requiredOk = false;
            foreach ($participants as $participant) {
                if ($required === 'sofer' ? $participant['driver_id'] === $driverId : $participant['vehicle_id'] === $vehicleId) {
                    $requiredOk = true;
                    break;
                }
            }
            if (!$requiredOk) {
                continue;
            }

            $matches[] = [
                'trip' => $trip,
                'participants' => $participants,
                'strict' => $date >= $start && $date <= $end,
            ];
        }

        if ($matches === []) {
            return $this->result('neasociata', [], $required === 'sofer'
                ? 'Nicio cursa a soferului in perioada documentului.'
                : 'Nicio cursa a vehiculului in perioada documentului.');
        }

        $notes = [];

        // Toleranta e doar o plasa: daca exista curse care contin data propriu-zisa,
        // cele prinse numai prin toleranta nu mai concureaza.
        $strict = array_values(array_filter($matches, static fn(array $m): bool => $m['strict']));
        if ($strict !== [] && count($strict) < count($matches)) {
            $matches = $strict;
            $notes[] = 'preferate cursele care contin data';
        }

        // Campul optional restrange lista doar daca lasa macar un candidat.
        $optionalId = $required === 'sofer' ? $vehicleId : $driverId;
        if ($optionalId !== null && count($matches) > 1) {
            $optionalKey = $required === 'sofer' ? 'vehicle_id' : 'driver_id';
            $narrowed = array_values(array_filter($matches, static function (array $m) use ($optionalKey, $optionalId): bool {
                foreach ($m['participants'] as $participant) {
                    if ($participant[$optionalKey] === $optionalId) {
                        return true;
                    }
                }

                return false;
            }));
            if ($narrowed !== []) {
                $matches = $narrowed;
                $notes[] = $required === 'sofer' ? 'restrans dupa vehicul' : 'restrans dupa sofer';
            }
        }

        $basis = $required === 'sofer' ? 'sofer + perioada' : 'vehicul + perioada';
        $suffix = $notes === [] ? '' : ' (' . implode(', ', $notes) . ')';
        $candidates = array_map(fn(array $m): array => $this->candidateSummary($m, $basis), $matches);

        if (count($matches) === 1) {
            return [
                'status' => 'asociata_auto',
                'cursa_id' => (int) $matches[0]['trip']['id'],
                'candidates' => $candidates,
                'reason' => 'Potrivire unica: ' . $basis . $suffix . '.',
            ];
        }

        return $this->result('de_verificat', $candidates, count($matches) . ' curse posibile: ' . $basis . $suffix . '. Alege cursa.');
    }

    /**
     * Cine conducea / ce vehicul era pe cursa la data documentului.
     *
     * Fara segmente: vehiculul si soferul cursei. Cu segmente: segmentul (sau, in ziua
     * de predare, segmentele) al carui interval contine data; o data din zona de
     * toleranta, inainte / dupa cursa, ia primul / ultimul segment.
     *
     * @return array<int, array{vehicle_id: ?int, driver_id: ?int, nr_inmatriculare: string, sofer: string}>
     */
    private function participantsOn(array $trip, DateTimeImmutable $date, DateTimeImmutable $tripStart, DateTimeImmutable $tripEnd): array
    {
        $segments = array_values(array_filter(
            is_array($trip['segments'] ?? null) ? $trip['segments'] : [],
            static fn($segment): bool => is_array($segment)
        ));

        if ($segments === []) {
            return [$this->participant($trip)];
        }

        usort($segments, static fn(array $a, array $b): int => ((int) ($a['ordine'] ?? 0)) <=> ((int) ($b['ordine'] ?? 0)));

        if ($date < $tripStart) {
            return [$this->participant($segments[0])];
        }
        if ($date > $tripEnd) {
            return [$this->participant($segments[count($segments) - 1])];
        }

        $active = [];
        foreach ($segments as $segment) {
            $segStart = $this->parseDate($segment['data_inceput'] ?? null) ?? $tripStart;
            $segEnd = $this->parseDate($segment['data_sfarsit'] ?? null) ?? $tripEnd;
            if ($date >= $segStart && $date <= $segEnd) {
                $active[] = $this->participant($segment);
            }
        }

        // Data cade intr-o pauza intre segmente: oricare dintre ele poate fi corect.
        return $active !== [] ? $active : array_map(fn(array $segment): array => $this->participant($segment), $segments);
    }

    /** @return array{vehicle_id: ?int, driver_id: ?int, nr_inmatriculare: string, sofer: string} */
    private function participant(array $row): array
    {
        return [
            'vehicle_id' => (int) ($row['vehicle_id'] ?? 0) ?: null,
            'driver_id' => (int) ($row['driver_id'] ?? 0) ?: null,
            'nr_inmatriculare' => (string) ($row['nr_inmatriculare'] ?? ''),
            'sofer' => (string) ($row['sofer_nume'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function candidateSummary(array $match, string $basis): array
    {
        $trip = $match['trip'];
        $plates = array_values(array_unique(array_filter(array_column($match['participants'], 'nr_inmatriculare'))));
        $drivers = array_values(array_unique(array_filter(array_column($match['participants'], 'sofer'))));

        return [
            'id' => (int) $trip['id'],
            'data_inceput' => (string) $trip['data_inceput'],
            'data_sfarsit' => (string) $trip['data_sfarsit'],
            'nr_inmatriculare' => implode(', ', $plates),
            'sofer' => implode(', ', $drivers),
            'motiv' => $basis . ($match['strict'] ? '' : ' (prin toleranta)'),
        ];
    }

    /** @return array{status: string, cursa_id: null, candidates: array<int, array<string, mixed>>, reason: string} */
    private function result(string $status, array $candidates, string $reason): array
    {
        return ['status' => $status, 'cursa_id' => null, 'candidates' => $candidates, 'reason' => $reason];
    }

    private function typeLabel(string $type): string
    {
        return InvoiceModel::TYPES[$type]['label'] ?? $type;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date === false ? null : $date;
    }

    // -------------------------------------------------------------------------
    // Partea cu baza de date
    // -------------------------------------------------------------------------

    /**
     * Rezolva vehiculul / soferul din textul extras (daca nu sunt deja date),
     * incarca cursele candidate si aplica decide().
     *
     * @param array<string, mixed> $invoice
     * @return array{status: string, cursa_id: ?int, candidates: array<int, array<string, mixed>>, reason: string, vehicle_id: ?int, driver_id: ?int}
     */
    public function match(array $invoice): array
    {
        $vehicleId = (int) ($invoice['vehicle_id'] ?? 0) ?: $this->resolveVehicleId($invoice['nr_inmatriculare_extras'] ?? null);
        $driverId = (int) ($invoice['driver_id'] ?? 0) ?: $this->resolveDriverId($invoice['sofer_extras'] ?? null);

        $invoice['vehicle_id'] = $vehicleId;
        $invoice['driver_id'] = $driverId;

        $trips = [];
        $date = $this->parseDate($invoice['data_document'] ?? null);
        if ($date !== null) {
            $tolerance = $this->toleranceFor((string) ($invoice['tip'] ?? ''));
            $trips = $this->loadCandidateTrips($date->format('Y-m-d'), $tolerance['before'], $tolerance['after']);
        }

        $result = $this->decide($invoice, $trips);
        $result['vehicle_id'] = $vehicleId;
        $result['driver_id'] = $driverId;

        return $result;
    }

    public function resolveVehicleId(?string $plate): ?int
    {
        $normalized = self::normalizePlate($plate);
        if ($normalized === '') {
            return null;
        }

        if ($this->vehicleIndex === null) {
            $this->vehicleIndex = [];
            $stmt = $this->requireDb()->query("SELECT id, nr_inmatriculare FROM vehicule ORDER BY status = 'activ' DESC, id ASC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $vehicle) {
                $key = self::normalizePlate((string) $vehicle['nr_inmatriculare']);
                if ($key !== '' && !isset($this->vehicleIndex[$key])) {
                    $this->vehicleIndex[$key] = (int) $vehicle['id'];
                }
            }
        }

        return $this->vehicleIndex[$normalized] ?? null;
    }

    /** Aceleasi reguli ca la importul de cazari, inclusiv ordinea inversata a numelui. */
    public function resolveDriverId(?string $name): ?int
    {
        $normalized = self::normalizePersonName($name);
        if ($normalized === '') {
            return null;
        }

        if ($this->driverIndex === null) {
            $this->driverIndex = [];
            // Aceeasi lista ca la Cazare (AccommodationExpenseModel::getDrivers): fara fostii angajati.
            $stmt = $this->requireDb()->query("
                SELECT id, nume
                FROM soferi
                WHERE employment_status <> 'terminated'
                ORDER BY status = 'activ' DESC, nume ASC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $driver) {
                $key = self::normalizePersonName((string) $driver['nume']);
                if ($key !== '' && !isset($this->driverIndex[$key])) {
                    $this->driverIndex[$key] = (int) $driver['id'];
                }
            }
        }

        if (isset($this->driverIndex[$normalized])) {
            return $this->driverIndex[$normalized];
        }

        $reversed = implode(' ', array_reverse(explode(' ', $normalized)));

        return $this->driverIndex[$reversed] ?? null;
    }

    /**
     * Cursele nesterse a caror perioada (largita cu tolerantele) contine data,
     * fiecare cu segmentele ei nesterse.
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadCandidateTrips(string $date, int $before, int $after): array
    {
        $db = $this->requireDb();

        // EMULATE_PREPARES=false: fiecare placeholder apare o singura data.
        $stmt = $db->prepare("
            SELECT
                c.id,
                c.data_cursa,
                c.data_inceput,
                c.data_sfarsit,
                c.tip_transport,
                c.vehicle_id,
                c.driver_id,
                v.nr_inmatriculare,
                s.nume AS sofer_nume,
                b.nume AS beneficiar
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            WHERE c.deleted_at IS NULL
              AND c.data_inceput <= DATE_ADD(:data_inceput_max, INTERVAL :before_days DAY)
              AND c.data_sfarsit >= DATE_SUB(:data_sfarsit_min, INTERVAL :after_days DAY)
            ORDER BY c.data_inceput ASC, c.id ASC
        ");
        $stmt->bindValue(':data_inceput_max', $date, PDO::PARAM_STR);
        $stmt->bindValue(':before_days', max(0, $before), PDO::PARAM_INT);
        $stmt->bindValue(':data_sfarsit_min', $date, PDO::PARAM_STR);
        $stmt->bindValue(':after_days', max(0, $after), PDO::PARAM_INT);
        $stmt->execute();
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->attachSegments($trips);
    }

    /**
     * Cursele active care se suprapun cu un interval (pentru alegerea manuala).
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadTripsAround(string $date, int $days = self::MANUAL_PICK_WINDOW_DAYS): array
    {
        return $this->loadCandidateTrips($date, $days, $days);
    }

    /**
     * @param array<int, array<string, mixed>> $trips
     * @return array<int, array<string, mixed>>
     */
    private function attachSegments(array $trips): array
    {
        if ($trips === []) {
            return [];
        }

        $placeholders = [];
        foreach (array_values($trips) as $index => $trip) {
            $placeholders[':cursa' . $index] = (int) $trip['id'];
        }

        $segmentsByTrip = [];
        try {
            $stmt = $this->requireDb()->prepare('
                SELECT seg.cursa_id, seg.ordine, seg.vehicle_id, seg.driver_id, seg.data_inceput, seg.data_sfarsit,
                       v.nr_inmatriculare, s.nume AS sofer_nume
                FROM curse_segmente seg
                LEFT JOIN vehicule v ON v.id = seg.vehicle_id
                LEFT JOIN soferi s ON s.id = seg.driver_id
                WHERE seg.deleted_at IS NULL
                  AND seg.cursa_id IN (' . implode(', ', array_keys($placeholders)) . ')
                ORDER BY seg.cursa_id ASC, seg.ordine ASC
            ');
            foreach ($placeholders as $placeholder => $value) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $segment) {
                $segmentsByTrip[(int) $segment['cursa_id']][] = $segment;
            }
        } catch (PDOException $exception) {
            // Baza fara curse_segmente (instalare veche): cursele raman fara segmente.
            error_log('[InvoiceTripMatcher][segments] ' . $exception->getMessage());
        }

        foreach ($trips as $index => $trip) {
            $trips[$index]['segments'] = $segmentsByTrip[(int) $trip['id']] ?? [];
        }

        return $trips;
    }

    private function requireDb(): PDO
    {
        if ($this->db === null) {
            throw new LogicException('InvoiceTripMatcher: operatia cere conexiune la baza de date.');
        }

        return $this->db;
    }
}
