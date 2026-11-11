<?php
declare(strict_types=1);

/**
 * Asocierea unei facturi eToll (sursa 'etoll', o sesiune de taxare per km) la cursa.
 *
 * Spre deosebire de InvoiceTripMatcher (care pleaca de la data documentului si accepta
 * "singura cursa din ziua aceea"), aici stim exact camionul si momentul:
 *   - camionul = numarul de pe sesiune, pe cursa (curse_dispecer.vehicle_id) sau pe un
 *     segment al ei (curse_segmente.vehicle_id). Nu se cauta niciodata pe alt camion;
 *   - momentul = inchiderea sesiunii (etoll_sesiuni.inchisa_la, ora Romaniei), care
 *     trebuie sa fie intre inceputul si sfarsitul cursei. Ora lipsa pe cursa = toata ziua
 *     (inceput 00:00, sfarsit 23:59:59).
 *
 * Rezultat:
 *   1 cursa care contine momentul             -> asociata_auto
 *   2+ curse care contin momentul             -> de_verificat (candidatii)
 *   0 curse, 1 Km service care il contine     -> asociata_auto pe Km service (service_km_id)
 *   0, dar curse / Km service in acea zi      -> de_verificat (ora iese din orele lor)
 *   nimic                                     -> neasociata (se reincearca la rematch)
 *
 * Km service (vehicule_service_km): drumul camionului la / de la service. Plecare data+ora,
 * intoarcere data+ora (ora lipsa = 00:00 / 23:59:59; intoarcere lipsa = inca in service, deschis).
 * Cursa are prioritate: o sesiune din intervalul unei curse ramane pe cursa.
 *
 * Nucleul (decide) e pur, testat in scripts/test_etoll_matcher.php.
 */
class EtollTripMatcher
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string, mixed> $invoice randul din facturi (InvoiceModel::getById)
     * @return array{status: string, cursa_id: ?int, service_km_id: ?int, candidates: array<int, array<string, mixed>>, reason: string, vehicle_id: ?int, driver_id: ?int}
     */
    public function match(array $invoice): array
    {
        $session = $this->sessionForInvoice($invoice);
        $vehicleId = (int) ($invoice['vehicle_id'] ?? 0) ?: null;
        if ($vehicleId === null && $session !== null) {
            $vehicleId = (new InvoiceTripMatcher($this->db))->resolveVehicleId((string) $session['nr_inmatriculare']);
        }

        if ($session === null) {
            return $this->result('de_verificat', [], 'Sesiunea eToll a facturii nu mai exista in etoll_sesiuni.', $vehicleId);
        }
        if ($vehicleId === null) {
            return $this->result('neasociata', [], 'Nr. auto ' . $session['nr_inmatriculare'] . ' nu exista in Vehicule.', null);
        }

        $moment = (string) $session['inchisa_la'];
        $day = substr($moment, 0, 10);
        $result = self::decide($moment, $this->loadTripsOfVehicle($vehicleId, $day), $this->loadServiceKmOfVehicle($vehicleId, $day));
        $result['vehicle_id'] = $vehicleId;

        return $result;
    }

    /**
     * @param string $moment 'Y-m-d H:i:s' (ora Romaniei)
     * @param array<int, array<string, mixed>> $trips cursele camionului care cuprind ziua momentului
     * @param array<int, array<string, mixed>> $services perioadele Km service ale camionului care cuprind ziua
     * @return array{status: string, cursa_id: ?int, service_km_id: ?int, candidates: array<int, array<string, mixed>>, reason: string, driver_id: ?int}
     */
    public static function decide(string $moment, array $trips, array $services = []): array
    {
        $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $moment);
        if ($at === false) {
            return ['status' => 'de_verificat', 'cursa_id' => null, 'service_km_id' => null, 'candidates' => [], 'reason' => 'Momentul sesiunii eToll lipseste.', 'driver_id' => null];
        }
        $label = $at->format('d.m.Y H:i');

        [$inside, $sameDay] = self::split($at, $trips, [self::class, 'interval']);
        [$serviceInside, $serviceSameDay] = self::split($at, $services, [self::class, 'serviceInterval']);
        $tripCandidates = static fn(array $rows, string $basis): array => array_map(static fn(array $row): array => self::candidate($row, $basis), $rows);
        $serviceCandidates = static fn(array $rows, string $basis): array => array_map(static fn(array $row): array => self::serviceCandidate($row, $basis), $rows);

        if (count($inside) === 1) {
            $trip = $inside[0];

            return [
                'status' => 'asociata_auto',
                'cursa_id' => (int) $trip['id'],
                'service_km_id' => null,
                'candidates' => [self::candidate($trip, 'nr_auto+ora')],
                'reason' => 'eToll: sesiunea inchisa la ' . $label . ' e in intervalul cursei #' . (int) $trip['id'] . '.',
                'driver_id' => (int) ($trip['driver_id'] ?? 0) ?: null,
            ];
        }
        if (count($inside) > 1) {
            return [
                'status' => 'de_verificat',
                'cursa_id' => null,
                'service_km_id' => null,
                'candidates' => array_merge($tripCandidates($inside, 'nr_auto+ora'), $serviceCandidates($serviceInside, 'nr_auto+ora')),
                'reason' => 'eToll: ' . count($inside) . ' curse ale camionului cuprind ' . $label . '. Alege cursa.',
                'driver_id' => null,
            ];
        }
        // Nicio cursa nu cuprinde momentul: drumul la / de la service.
        if (count($serviceInside) === 1) {
            $service = $serviceInside[0];

            return [
                'status' => 'asociata_auto',
                'cursa_id' => null,
                'service_km_id' => (int) $service['id'],
                'candidates' => [self::serviceCandidate($service, 'nr_auto+ora')],
                'reason' => 'eToll: sesiunea inchisa la ' . $label . ' e in perioada Km service #' . (int) $service['id'] . ' (drum la / de la service).',
                'driver_id' => (int) ($service['driver_id'] ?? 0) ?: null,
            ];
        }
        if (count($serviceInside) > 1) {
            return [
                'status' => 'de_verificat',
                'cursa_id' => null,
                'service_km_id' => null,
                'candidates' => $serviceCandidates($serviceInside, 'nr_auto+ora'),
                'reason' => 'eToll: ' . count($serviceInside) . ' perioade Km service ale camionului cuprind ' . $label . '. Alege una.',
                'driver_id' => null,
            ];
        }
        if ($sameDay !== [] || $serviceSameDay !== []) {
            $what = $sameDay !== [] && $serviceSameDay !== [] ? 'curselor si Km service' : ($sameDay !== [] ? 'curselor' : 'perioadei Km service');

            return [
                'status' => 'de_verificat',
                'cursa_id' => null,
                'service_km_id' => null,
                'candidates' => array_merge($tripCandidates($sameDay, 'nr_auto (alta ora)'), $serviceCandidates($serviceSameDay, 'nr_auto (alta ora)')),
                'reason' => 'eToll: sesiunea inchisa la ' . $label . ' iese din orele ' . $what . ' camionului din acea zi.',
                'driver_id' => null,
            ];
        }

        return [
            'status' => 'neasociata',
            'cursa_id' => null,
            'service_km_id' => null,
            'candidates' => [],
            'reason' => 'eToll: nicio cursa si niciun Km service al camionului la ' . $label . '. Se reincearca automat dupa ce se adauga cursa.',
            'driver_id' => null,
        ];
    }

    /**
     * Imparte randurile in: intervalul lor contine momentul / doar ziua momentului e in interval.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private static function split(DateTimeImmutable $at, array $rows, callable $intervalOf): array
    {
        $inside = [];
        $sameDay = [];
        foreach ($rows as $row) {
            [$start, $end] = $intervalOf($row);
            if ($start === null || $end === null) {
                continue;
            }
            if ($at >= $start && $at <= $end) {
                $inside[] = $row;
            } elseif ($at->format('Y-m-d') >= $start->format('Y-m-d') && $at->format('Y-m-d') <= $end->format('Y-m-d')) {
                $sameDay[] = $row;
            }
        }

        return [$inside, $sameDay];
    }

    /**
     * Perioada Km service: plecare -> intoarcere. Ora lipsa = 00:00 / 23:59:59; intoarcerea
     * lipsa = vehiculul e inca in service, deci intervalul ramane deschis.
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private static function serviceInterval(array $row): array
    {
        $startDate = substr(trim((string) ($row['data_plecare'] ?? '')), 0, 10);
        $endDate = substr(trim((string) ($row['data_intoarcere'] ?? '')), 0, 10);
        $startTime = trim((string) ($row['ora_plecare'] ?? '')) ?: '00:00:00';
        $endTime = $endDate !== '' ? (trim((string) ($row['ora_intoarcere'] ?? '')) ?: '23:59:59') : '23:59:59';
        $endDate = $endDate !== '' ? $endDate : '9999-12-31';

        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate . ' ' . substr($startTime . ':00', 0, 8));
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate . ' ' . substr($endTime . ':00', 0, 8));

        return [$start ?: null, $end ?: null];
    }

    /** Candidat Km service, in acelasi format ca o cursa, marcat cu tip = service_km. */
    private static function serviceCandidate(array $row, string $basis): array
    {
        $hours = trim(substr((string) ($row['ora_plecare'] ?? ''), 0, 5) . '-' . substr((string) ($row['ora_intoarcere'] ?? ''), 0, 5), '-');

        return [
            'tip' => 'service_km',
            'id' => (int) $row['id'],
            'data_inceput' => (string) $row['data_plecare'],
            'data_sfarsit' => (string) ($row['data_intoarcere'] ?? ''),
            'nr_inmatriculare' => (string) ($row['nr_inmatriculare'] ?? ''),
            'sofer' => (string) ($row['sofer_nume'] ?? ''),
            'service' => (string) ($row['service_nume'] ?? ''),
            'motiv' => 'Km service, ' . $basis . ($hours !== '' ? ' (ore ' . $hours . ')' : ''),
        ];
    }

    /** @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} */
    private static function interval(array $trip): array
    {
        $startDate = substr(trim((string) ($trip['data_inceput'] ?? '')), 0, 10);
        $endDate = substr(trim((string) ($trip['data_sfarsit'] ?? '')), 0, 10);
        $startTime = trim((string) ($trip['ora_inceput'] ?? '')) ?: '00:00:00';
        $endTime = trim((string) ($trip['ora_sfarsit'] ?? '')) ?: '23:59:59';

        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate . ' ' . substr($startTime . ':00', 0, 8));
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate . ' ' . substr($endTime . ':00', 0, 8));

        return [$start ?: null, $end ?: null];
    }

    /** Acelasi format ca InvoiceTripMatcher (lista de candidati din pagina Facturi). */
    private static function candidate(array $trip, string $basis): array
    {
        $hours = trim(substr((string) ($trip['ora_inceput'] ?? ''), 0, 5) . '-' . substr((string) ($trip['ora_sfarsit'] ?? ''), 0, 5), '-');

        return [
            'id' => (int) $trip['id'],
            'data_inceput' => (string) $trip['data_inceput'],
            'data_sfarsit' => (string) $trip['data_sfarsit'],
            'nr_inmatriculare' => (string) ($trip['nr_inmatriculare'] ?? ''),
            'sofer' => (string) ($trip['sofer_nume'] ?? ''),
            'motiv' => $basis . ($hours !== '' ? ' (ore ' . $hours . ')' : ''),
        ];
    }

    /** @return array<string, mixed>|null */
    private function sessionForInvoice(array $invoice): ?array
    {
        // sursa_key = 'etoll:<session_id>'
        $sessionId = substr((string) ($invoice['sursa_key'] ?? ''), 6);
        if ($sessionId === '') {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM etoll_sesiuni WHERE session_id = :session_id LIMIT 1');
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Cursele nesterse ale camionului (pe cursa sau pe un segment) care cuprind ziua.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadTripsOfVehicle(int $vehicleId, string $date): array
    {
        $stmt = $this->db->prepare("
            SELECT
                c.id,
                c.data_inceput,
                c.ora_inceput,
                c.data_sfarsit,
                c.ora_sfarsit,
                c.vehicle_id,
                c.driver_id,
                v.nr_inmatriculare,
                s.nume AS sofer_nume
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            WHERE c.deleted_at IS NULL
              AND c.data_inceput <= :data_max
              AND c.data_sfarsit >= :data_min
              AND (
                    c.vehicle_id = :vehicle_cursa
                 OR EXISTS (
                        SELECT 1 FROM curse_segmente seg
                        WHERE seg.cursa_id = c.id AND seg.deleted_at IS NULL AND seg.vehicle_id = :vehicle_segment
                    )
              )
            ORDER BY c.data_inceput ASC, c.id ASC
        ");
        $stmt->bindValue(':data_max', $date, PDO::PARAM_STR);
        $stmt->bindValue(':data_min', $date, PDO::PARAM_STR);
        $stmt->bindValue(':vehicle_cursa', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':vehicle_segment', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Perioadele Km service ale camionului care cuprind ziua (si cele inca deschise).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadServiceKmOfVehicle(int $vehicleId, string $date): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT sk.id, sk.data_plecare, sk.ora_plecare, sk.data_intoarcere, sk.ora_intoarcere,
                       sk.driver_id, sk.service_nume, v.nr_inmatriculare, s.nume AS sofer_nume
                FROM vehicule_service_km sk
                LEFT JOIN vehicule v ON v.id = sk.vehicle_id
                LEFT JOIN soferi s ON s.id = sk.driver_id
                WHERE sk.vehicle_id = :vehicle_id
                  AND sk.data_plecare <= :data_max
                  AND (sk.data_intoarcere IS NULL OR sk.data_intoarcere >= :data_min)
                ORDER BY sk.data_plecare ASC, sk.id ASC
            ");
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->bindValue(':data_max', $date, PDO::PARAM_STR);
            $stmt->bindValue(':data_min', $date, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException) {
            return []; // tabela Km service inca necreata
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{status: string, cursa_id: null, service_km_id: null, candidates: array<int, mixed>, reason: string, vehicle_id: ?int, driver_id: null} */
    private function result(string $status, array $candidates, string $reason, ?int $vehicleId): array
    {
        return ['status' => $status, 'cursa_id' => null, 'service_km_id' => null, 'candidates' => $candidates, 'reason' => $reason, 'vehicle_id' => $vehicleId, 'driver_id' => null];
    }
}
