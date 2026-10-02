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
 *   - data alege cursele; o singura cursa in acea zi -> asociata, fara alte conditii;
 *   - mai multe curse in acea zi: numarul de inmatriculare si / sau soferul de pe factura
 *     (oricare apare; cazarea are de obicei soferul, service-ul numarul) arata cursa.
 *     Se compara doar cu cine era pe cursele zilei (segmentul activ la data documentului,
 *     curse_segmente), soferul cu potrivire larga (namesMatch). Un camp care nu se
 *     regaseste pe nicio cursa a zilei e ignorat, nu blocheaza;
 *   - cursele care contin data propriu-zisa au prioritate fata de cele prinse prin toleranta;
 *   - 1 candidat ramas -> asociata_auto, 2+ -> de_verificat (cu lista de candidati),
 *     0 -> neasociata; data lipsa -> de_verificat cu motiv.
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
        if (!InvoiceModel::isValidType($type)) {
            return $this->result('de_verificat', [], 'Tip de factura necunoscut: "' . $type . '".');
        }

        $date = $this->parseDate($invoice['data_document'] ?? null);
        if ($date === null) {
            return $this->result('de_verificat', [], 'Lipseste data documentului.');
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

            $matches[] = [
                'trip' => $trip,
                'participants' => $this->participantsOn($trip, $date, $start, $end),
                'strict' => $date >= $start && $date <= $end,
            ];
        }

        if ($matches === []) {
            return $this->result('neasociata', [], 'Nicio cursa in perioada documentului.');
        }

        $notes = [];

        // Toleranta e doar o plasa: daca exista curse care contin data propriu-zisa,
        // cele prinse numai prin toleranta nu mai concureaza.
        $strict = array_values(array_filter($matches, static fn(array $m): bool => $m['strict']));
        if ($strict !== [] && count($strict) < count($matches)) {
            $matches = $strict;
            $notes[] = 'preferate cursele care contin data';
        }

        // Mai multe curse in aceeasi zi: numarul / soferul de pe factura arata cursa,
        // comparate doar cu cine era pe cursele din ziua respectiva.
        $basis = 'data documentului';
        if (count($matches) > 1) {
            foreach ($this->identityFilters($invoice) as [$label, $filter]) {
                $narrowed = array_values(array_filter($matches, static function (array $m) use ($filter): bool {
                    foreach ($m['participants'] as $participant) {
                        if ($filter($participant)) {
                            return true;
                        }
                    }

                    return false;
                }));
                if ($narrowed === []) {
                    $notes[] = $label . ' de pe factura nu apare pe nicio cursa din acea zi';
                    continue;
                }
                if (count($narrowed) < count($matches)) {
                    $matches = $narrowed;
                    $basis = 'data documentului + ' . $label;
                }
            }
        }

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
     * Ce identifica vehiculul / soferul pe factura, in ordinea in care se aplica:
     * numarul (alegerea din lista sau textul citit), apoi soferul.
     *
     * @return array<int, array{0: string, 1: callable(array): bool}>
     */
    private function identityFilters(array $invoice): array
    {
        $filters = [];

        $vehicleId = (int) ($invoice['vehicle_id'] ?? 0) ?: null;
        $plate = self::normalizePlate($invoice['nr_inmatriculare_extras'] ?? null);
        if ($vehicleId !== null || $plate !== '') {
            $filters[] = ['nr. auto', static fn(array $p): bool => ($vehicleId !== null && $p['vehicle_id'] === $vehicleId)
                || ($plate !== '' && self::normalizePlate($p['nr_inmatriculare']) === $plate)];
        }

        $driverId = (int) ($invoice['driver_id'] ?? 0) ?: null;
        $name = (string) ($invoice['sofer_extras'] ?? '');
        if ($driverId !== null || self::normalizePersonName($name) !== '') {
            $filters[] = ['sofer', static fn(array $p): bool => ($driverId !== null && $p['driver_id'] === $driverId)
                || self::namesMatch($name, $p['sofer'])];
        }

        return $filters;
    }

    /**
     * Numele de pe factura se potriveste cu numele din aplicatie daca fiecare cuvant al
     * lui se regaseste in numele din aplicatie, in orice ordine, cu o litera diferenta
     * la cuvintele lungi ("Beznea Christian" ~ "Beznea Cristian-Gheorghe").
     */
    public static function namesMatch(?string $invoiceName, ?string $appName): bool
    {
        $wanted = array_filter(explode(' ', self::normalizePersonName($invoiceName)), static fn(string $w): bool => strlen($w) >= 2);
        $available = array_filter(explode(' ', self::normalizePersonName($appName)), static fn(string $w): bool => $w !== '');
        if ($wanted === [] || $available === []) {
            return false;
        }

        foreach ($wanted as $word) {
            $found = false;
            foreach ($available as $candidate) {
                if ($word === $candidate || (strlen($word) >= 5 && strlen($candidate) >= 5 && levenshtein($word, $candidate) <= 1)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
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
