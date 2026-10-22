<?php
declare(strict_types=1);

/**
 * SANDBOX - Precompletare cursa din GPS (SAS Fleet API).
 *
 * Primeste vehiculul local + data/ora de inceput si construieste sugestiile
 * pentru campurile din formularul Dispecer curse pe baza foii de parcurs SAS:
 *  - data incarcare (prima oprire intr-un POI de incarcare)
 *  - data + ora sfarsit (ultima miscare, daca cursa pare incheiata)
 *  - km cursa (suma segmentelor) si km totali (delta odometru)
 *  - sugestie loc incarcare (potrivire nume POI <-> configurare_locuri_incarcare)
 *
 * Serviciul este doar citire (SAS + DB) si nu modifica nicio cursa.
 * Reutilizeaza cache-ul de sesiune SAS din storage/cache (acelasi fisier ca
 * FleetLivePositionService, ca sa nu faca login-uri suplimentare).
 */
class SasTripPrefillService
{
    private const POIS_TTL_SECONDS = 600;
    private const CARS_TTL_SECONDS = 600;
    // Peste acest prag de stationare dupa ultima miscare, cursa e considerata incheiata.
    private const TRIP_FINISHED_PARK_MINUTES = 120;
    // Sub aceasta distanta un segment e considerat "fara miscare" (zgomot GPS / manevre).
    private const MIN_MOVING_SEGMENT_KM = 0.5;
    // O cursa se inchide la revenirea in punctul de plecare, dar numai dupa minim atatia km
    // (altfel manevrele locale din jurul garajului ar inchide cursa imediat).
    private const MIN_TRIP_KM = 10.0;
    // Doua pozitii aflate sub aceasta raza sunt considerate aceeasi locatie (plecare = sosire).
    private const SAME_LOCATION_RADIUS_KM = 0.5;
    // Fereastra maxima interogata o singura data din SAS.
    private const MAX_WINDOW_DAYS = 7;
    // Cursa se poate inchide si intr-o parcare de beneficiar (POI SAS), dupa incarcare,
    // daca vehiculul a mers minim atatia km de la incarcare si stationeaza acolo minim atat.
    private const DELIVERY_PARK_MIN_KM_AFTER_LOAD = 10.0;
    public const DELIVERY_PARK_DEFAULT_MINUTES = 60;
    // Dupa incarcare si livrare, sosirea la un punct de incarcare (cantar/terminal) inchide
    // cursa: camionul vine sa incarce pentru cursa urmatoare. Pragul de km dupa incarcare
    // exclude manevrele din jurul cantarului unde tocmai a incarcat.
    private const NEXT_LOAD_MIN_KM_AFTER_LOAD = 50.0;
    // Sosirea la max atat de un cantar/terminal (ex. parcarea de asteptare de langa cantar)
    // conteaza ca sosire la punctul de incarcare.
    private const NEXT_LOAD_RADIUS_KM = 0.5;
    // Livrarea = stationare de minim atat intr-un POI SAS de client (dupa NEXT_LOAD_MIN_KM_AFTER_LOAD
    // km de la incarcare), la peste DELIVERY_TERMINAL_EXCLUSION_KM de orice cantar si care nu e
    // benzinarie / parcare / garaj. Pauzele pe drum si asteptarea la rafinarie nu sunt livrari.
    // Fara livrare, sosirea la cantar e incarcarea cursei curente, nu inceputul celei urmatoare.
    private const DELIVERY_STOP_MIN_MINUTES = 30;
    private const DELIVERY_TERMINAL_EXCLUSION_KM = 2.0;
    // Un punct GPS fara POI, la max atat de marginea unui POI SAS, e legat de acel POI.
    private const POI_SNAP_KM = 0.1;
    // Dupa sosire, mutarile din aceeasi curte la pauze sub atat (asteptare, cantar, descarcare)
    // tin de cursa; o stationare mai lunga = vehiculul a fost parcat, cursa s-a terminat.
    private const YARD_MAX_GAP_MINUTES = 180;
    // Plecarea = prima miscare dupa ultima stationare de minim atat in curtea de plecare
    // (raza START_YARD_RADIUS_KM in jurul punctului de plecare).
    private const START_LONG_PARK_MINUTES = 180;
    private const START_YARD_RADIUS_KM = 1.0;
    // Perioada maxima pentru detectarea curselor in lant (sandbox) si cache-ul lunii curente.
    private const MAX_RANGE_DAYS = 200;
    private const SHEET_TTL_SECONDS = 600;

    private PDO $db;
    private SasFleetClient $client;
    /** @var array<int, array{0: float, 1: float, 2: float, 3: float}> locationId => [latMin, latMax, lngMin, lngMax] */
    private array $poiBoxes = [];
    /** @var array<int, string> locationId => nume */
    private array $poiNames = [];
    private string $cacheDir;

    public function __construct(PDO $db, ?SasFleetClient $client = null)
    {
        $this->db = $db;
        $this->client = $client ?? new SasFleetClient();
        $this->cacheDir = dirname(BASE_PATH) . '/storage/cache';
    }

    public function credentialsAvailable(): bool
    {
        return $this->client->credentialsAvailable();
    }

    /**
     * Vehiculele locale care au corespondent in SAS (dupa numar de inmatriculare
     * normalizat) si au garaj, pentru dropdown-ul din sandbox. Un cap tractor cuplat
     * (vehicule_cuplaje activ) are garajul semiremorcii; altfel garajul propriu.
     *
     * @return array<int, array{id: int, nr_inmatriculare: string, label: string, sas_car_id: int, driver: ?string, garaj: string, garaj_din_semiremorca: ?string}>
     */
    public function getVehicleOptions(): array
    {
        $this->restoreSasSession();
        $sasCars = $this->getSasCars();
        $this->persistSasSession();

        $sasByPlate = [];
        foreach ($sasCars as $car) {
            $plate = $this->plateKey((string) ($car['licensePlate'] ?? ''));
            if ($plate !== '') {
                $sasByPlate[$plate] = $car;
            }
        }

        $options = [];
        $statement = $this->db->query(
            "SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul,
                    TRIM(v.garaj) AS garaj, TRIM(s.garaj) AS garaj_semiremorca, s.nr_inmatriculare AS semiremorca_nr
             FROM vehicule v
             LEFT JOIN vehicule_cuplaje vc ON vc.id = (
                 SELECT MAX(c.id) FROM vehicule_cuplaje c WHERE c.activ = 1 AND c.tractor_id = v.id
             )
             LEFT JOIN vehicule s ON s.id = vc.semiremorca_id
             WHERE v.nr_inmatriculare <> 'STOC-ANVELOPE'
             ORDER BY v.nr_inmatriculare"
        );
        foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $vehicle) {
            $sasCar = $sasByPlate[$this->plateKey((string) $vehicle['nr_inmatriculare'])] ?? null;
            if ($sasCar === null || !empty($sasCar['disabled'])) {
                continue;
            }
            $trailerGarage = trim((string) ($vehicle['garaj_semiremorca'] ?? ''));
            $fromTrailer = ($vehicle['tip_vehicul'] ?? '') === 'cap_tractor' && $trailerGarage !== '';
            $garage = $fromTrailer ? $trailerGarage : trim((string) ($vehicle['garaj'] ?? ''));
            if ($garage === '') {
                continue;
            }
            $options[] = [
                'id' => (int) $vehicle['id'],
                'nr_inmatriculare' => (string) $vehicle['nr_inmatriculare'],
                'label' => trim((string) $vehicle['nr_inmatriculare'] . ' — ' . trim((string) $vehicle['marca'] . ' ' . (string) $vehicle['model'])),
                'sas_car_id' => (int) ($sasCar['carId'] ?? 0),
                'driver' => isset($sasCar['driver']) && trim((string) $sasCar['driver']) !== '' ? (string) $sasCar['driver'] : null,
                'garaj' => $garage,
                'garaj_din_semiremorca' => $fromTrailer ? (string) $vehicle['semiremorca_nr'] : null,
            ];
        }
        usort($options, static fn (array $a, array $b) => [mb_strtoupper($a['garaj']), $a['nr_inmatriculare']]
            <=> [mb_strtoupper($b['garaj']), $b['nr_inmatriculare']]);

        return $options;
    }

    /**
     * POI-urile SAS pentru alegerea parcarilor de beneficiar din sandbox.
     * Implicit bifate: cele cu "parcare" / "garaj" in nume.
     *
     * @return array<int, array{id: int, name: string, default_parking: bool}>
     */
    public function getPoiOptions(): array
    {
        $this->restoreSasSession();
        $pois = $this->getPois();
        $this->persistSasSession();

        $options = [];
        foreach ($pois as $id => $name) {
            $options[] = ['id' => (int) $id, 'name' => $name, 'default_parking' => $this->looksLikeParkingPoi($name)];
        }
        usort($options, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * Construieste sugestiile de precompletare pentru o cursa.
     *
     * @param int $localVehicleId id din tabela vehicule
     * @param string $dateStart data de inceput selectata de dispecer (Y-m-d)
     * @param string|null $timeStart ora de inceput (H:i) sau null pentru autodetectie
     * @param int[]|null $parkingPoiIds POI-uri SAS considerate parcari de beneficiar (null = implicite dupa nume)
     * @param int|null $deliveryParkMinutes stationarea minima in parcarea de beneficiar ca sa inchida cursa
     */
    public function prefill(
        int $localVehicleId,
        string $dateStart,
        ?string $timeStart,
        ?array $parkingPoiIds = null,
        ?int $deliveryParkMinutes = null
    ): array {
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $dateStart);
        if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $dateStart) {
            throw new InvalidArgumentException('Data de inceput invalida (format asteptat: Y-m-d).');
        }
        if ($timeStart !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeStart)) {
            throw new InvalidArgumentException('Ora de inceput invalida (format asteptat: HH:MM).');
        }

        $vehicle = $this->getLocalVehicle($localVehicleId);
        if ($vehicle === null) {
            throw new InvalidArgumentException('Vehiculul selectat nu exista.');
        }

        $this->restoreSasSession();
        $carId = $this->resolveSasCarId((string) $vehicle['nr_inmatriculare']);
        if ($carId === 0) {
            throw new InvalidArgumentException('Vehiculul ' . $vehicle['nr_inmatriculare'] . ' nu are corespondent in SAS.');
        }

        $now = new DateTimeImmutable('now');
        $windowStart = $day->setTime(0, 0, 0);
        if ($timeStart !== null) {
            [$hour, $minute] = array_map('intval', explode(':', $timeStart));
            $windowStart = $day->setTime($hour, $minute, 0);
        }

        $warnings = [];
        $windowEnd = $now;
        $maxEnd = $windowStart->modify('+' . self::MAX_WINDOW_DAYS . ' days');
        if ($windowEnd > $maxEnd) {
            $windowEnd = $maxEnd;
            $warnings[] = 'Fereastra interogata a fost limitata la ' . self::MAX_WINDOW_DAYS . ' zile de la data de inceput.';
        }
        if ($windowStart > $now) {
            throw new InvalidArgumentException('Data de inceput este in viitor - nu exista date GPS.');
        }

        $sheet = $this->client->getTravelSheet(
            $carId,
            $windowStart->format('Y-m-d\TH:i:s'),
            $windowEnd->format('Y-m-d\TH:i:s')
        );
        $pois = $this->getPois();
        $this->persistSasSession();

        $segments = $this->normalizeSegments(is_array($sheet['segments'] ?? null) ? $sheet['segments'] : [], $pois);
        $parkingSet = $this->resolveParkingSet($parkingPoiIds, $pois);
        $minDeliveryPark = max(0, $deliveryParkMinutes ?? self::DELIVERY_PARK_DEFAULT_MINUTES);

        $analysis = $this->analyzeTrip($segments, $windowEnd, $parkingSet, $minDeliveryPark);
        $suggestions = ['data_inceput' => $dateStart, 'ora_inceput' => $timeStart] + $analysis['suggestions'];
        // Ora de inceput: prima miscare reala, daca dispecerul nu a fixat-o deja.
        if ($timeStart === null && $analysis['start_movement'] !== null) {
            $suggestions['ora_inceput'] = substr($analysis['start_movement'], 11, 5);
        }
        $warnings = array_merge($warnings, $analysis['warnings']);

        return $this->buildResult($vehicle, $carId, $sheet, $analysis['segments'], $suggestions, $warnings, $windowStart, $windowEnd, $analysis['close_reason']);
    }

    /**
     * Regulile de inchidere aplicate pe segmentele unei singure curse, de la primul
     * segment din $segments (= punctul de plecare). Folosit si de prefill() si de
     * detectTripsInRange() (care inlantuie cursele pe o perioada).
     *
     * @param array<int, array<string, mixed>> $segments segmente normalizate, in ordine
     * @param array<int, bool> $parkingSet POI-uri SAS considerate parcari de beneficiar
     * @return array{suggestions: array<string, mixed>, warnings: string[], segments: array<int, array<string, mixed>>,
     *               close_reason: ?string, last_idx: ?int, start_movement: ?string}
     */
    private function analyzeTrip(array $segments, DateTimeImmutable $windowEnd, array $parkingSet, int $minDeliveryPark): array
    {
        $moving = array_values(array_filter($segments, static fn (array $s) => $s['distance_km'] >= self::MIN_MOVING_SEGMENT_KM));
        $warnings = [];
        $suggestions = [
            'data_incarcare' => null,
            'data_sfarsit' => null,
            'ora_sfarsit' => null,
            'km_cursa' => null,
            'km_totali' => null,
            'loc_incarcare' => null,
            'trip_finished' => false,
            'loc_sfarsit' => null,
            'stationare_sfarsit_min' => null,
            'ultima_miscare_curte' => null,
        ];

        if ($moving === []) {
            $warnings[] = 'GPS-ul nu arata nicio miscare pentru acest vehicul in fereastra selectata.';
            return [
                'suggestions' => $suggestions,
                'warnings' => $warnings,
                'segments' => $segments,
                'close_reason' => null,
                'last_idx' => null,
                'start_movement' => null,
            ];
        }

        $first = $moving[0];

        // Cursa = bucla: pleaca dintr-o locatie (ancora = pozitia de la inceputul
        // ferestrei) si se inchide la prima revenire in aceeasi locatie, dupa
        // minim MIN_TRIP_KM. Tot ce urmeaza dupa revenire apartine cursei urmatoare.
        $anchor = [
            'poi_id' => $segments[0]['start_poi_id'],
            'lat' => $segments[0]['lat_start'],
            'lng' => $segments[0]['lng_start'],
        ];

        $departIdx = null;
        foreach ($segments as $idx => $segment) {
            if ($segment['distance_km'] >= self::MIN_MOVING_SEGMENT_KM) {
                $departIdx = $idx;
                break;
            }
        }

        // Inchiderea cursei - prima regula indeplinita:
        //  1. revenire in punctul de plecare (dupa minim MIN_TRIP_KM);
        //  2. dupa incarcare, sosire intr-o parcare de beneficiar, la minim
        //     DELIVERY_PARK_MIN_KM_AFTER_LOAD km de la incarcare, cu stationare >= $minDeliveryPark;
        //  3. dupa incarcare si livrare (stationare >= DELIVERY_STOP_MIN_MINUTES la min.
        //     NEXT_LOAD_MIN_KM_AFTER_LOAD km de incarcare), sosire la un punct de incarcare
        //     (cantar/terminal) = vine sa incarce pentru cursa urmatoare.
        $returnIdx = null;
        $tripCloseReason = null;
        // Incarcarea poate fi facuta inainte de prima miscare reala (vehiculul sta in
        // parcarea / la cantarul de incarcare la inceputul ferestrei): o cautam de la
        // primul segment, inclusiv punctul de plecare.
        $loadIdx = null;
        if ($segments[0]['start_poi'] !== null && $this->looksLikeLoadingPoi($segments[0]['start_poi'])) {
            $loadIdx = -1;
        }
        foreach ($segments as $idx => $segment) {
            if ($loadIdx !== null || $departIdx === null || $idx >= $departIdx) {
                break;
            }
            if ($segment['end_poi'] !== null && $this->looksLikeLoadingPoi($segment['end_poi'])) {
                $loadIdx = $idx;
            }
        }
        $kmSinceLoad = 0.0;
        $deliveryDone = false;
        $cumulativeKm = 0.0;
        foreach ($segments as $idx => $segment) {
            if ($departIdx === null || $idx < $departIdx) {
                continue;
            }
            $cumulativeKm += $segment['distance_km'];
            if ($loadIdx !== null) {
                $kmSinceLoad += $segment['distance_km'];
            }
            $isMoving = $segment['distance_km'] >= self::MIN_MOVING_SEGMENT_KM;

            if (
                $cumulativeKm >= self::MIN_TRIP_KM
                && $isMoving
                && $this->isSameLocation($segment['end_poi_id'], $segment['lat_end'], $segment['lng_end'], $anchor)
            ) {
                $returnIdx = $idx;
                $tripCloseReason = 'return_to_start';
                break;
            }

            if (
                $loadIdx !== null
                && $isMoving
                && $kmSinceLoad >= self::DELIVERY_PARK_MIN_KM_AFTER_LOAD
                && $segment['end_poi_id'] !== null
                && isset($parkingSet[$segment['end_poi_id']])
            ) {
                $parked = $this->parkedMinutesAfter($segments, $idx, $windowEnd);
                if ($parked !== null && $parked >= $minDeliveryPark) {
                    $returnIdx = $idx;
                    $tripCloseReason = 'delivery_parking';
                    break;
                }
            }

            if (
                $loadIdx !== null
                && $isMoving
                && $deliveryDone
                && $this->isAtLoadingTerminal($segment)
            ) {
                $returnIdx = $idx;
                $tripCloseReason = 'next_loading';
                break;
            }

            if (
                !$deliveryDone
                && $loadIdx !== null
                && $isMoving
                && $kmSinceLoad >= self::NEXT_LOAD_MIN_KM_AFTER_LOAD
                && $this->isCustomerDeliveryStop($segment)
                && ($this->parkedMinutesAfter($segments, $idx, $windowEnd) ?? 0) >= self::DELIVERY_STOP_MIN_MINUTES
            ) {
                $deliveryDone = true;
            }

            if ($loadIdx === null && $segment['end_poi'] !== null && $this->looksLikeLoadingPoi($segment['end_poi'])) {
                $loadIdx = $idx;
            }
        }

        $lastTripIdx = $returnIdx;

        if ($returnIdx !== null) {
            // Cursa inchisa prin revenire la plecare, parcarea beneficiarului sau urmatoarea incarcare.
            // Sfarsitul = sosirea (ca la majoritatea curselor din Dispecer). Asteptarea, cantarirea
            // si descarcarea din aceeasi curte raman in cursa (cursa urmatoare pleaca dupa ele), iar
            // ultima miscare din curte e doar informativa (ex. #166: sosire 17:00, curte pana 22:54).
            $lastTripIdx = $this->extendOverYardMoves($segments, $returnIdx);
            $suggestions['trip_finished'] = true;
            $suggestions['data_sfarsit'] = substr((string) $segments[$returnIdx]['date_end'], 0, 10);
            $suggestions['ora_sfarsit'] = substr((string) $segments[$returnIdx]['date_end'], 11, 5);
            $suggestions['loc_sfarsit'] = (string) $segments[$returnIdx]['to'];
            $suggestions['stationare_sfarsit_min'] = $this->parkedMinutesAfter($segments, $lastTripIdx, $windowEnd);
            if ($lastTripIdx > $returnIdx) {
                $suggestions['ultima_miscare_curte'] = str_replace('T', ' ', substr((string) $segments[$lastTripIdx]['date_end'], 0, 16));
            }
        } else {
            // Fallback: vehiculul nu a revenit la plecare in fereastra interogata;
            // sfarsitul se estimeaza dupa ultima miscare urmata de stationare lunga
            // (masurata pana la capatul ferestrei, care e "acum" pentru o cursa curenta).
            $last = $moving[count($moving) - 1];
            $lastTripIdx = count($segments) - 1;
            $lastEnd = $this->parseSasDate((string) $last['date_end']);
            $parkedMinutes = $lastEnd !== null
                ? (int) floor(($windowEnd->getTimestamp() - $lastEnd->getTimestamp()) / 60)
                : null;

            if ($parkedMinutes !== null && $parkedMinutes >= self::TRIP_FINISHED_PARK_MINUTES) {
                $tripCloseReason = 'long_park';
                $suggestions['trip_finished'] = true;
                $suggestions['data_sfarsit'] = substr((string) $last['date_end'], 0, 10);
                $suggestions['ora_sfarsit'] = substr((string) $last['date_end'], 11, 5);
                $suggestions['loc_sfarsit'] = (string) $last['to'];
                $suggestions['stationare_sfarsit_min'] = $parkedMinutes;
                $warnings[] = 'Vehiculul nu a revenit in punctul de plecare in fereastra interogata; '
                    . 'sfarsitul a fost estimat dupa ultima miscare urmata de stationare lunga - verifica manual.';
            } else {
                $warnings[] = 'Cursa pare inca in desfasurare (ultima miscare acum '
                    . ($parkedMinutes !== null ? $parkedMinutes . ' minute' : 'necunoscut')
                    . ') - data si ora de sfarsit nu au fost propuse.';
            }
        }

        // Segmentele de dupa inchiderea cursei sunt marcate ca apartinand cursei urmatoare.
        if ($returnIdx !== null) {
            foreach ($segments as $idx => $segment) {
                $segments[$idx]['after_trip'] = $idx > $lastTripIdx;
            }
        }

        // Data incarcare: prima oprire intr-un POI de incarcare, DOAR in interiorul cursei;
        // fallback: prima oprire in orice POI cunoscut din cursa.
        $loadingStop = null;
        foreach ($segments as $idx => $segment) {
            if ($lastTripIdx !== null && $idx > $lastTripIdx) {
                break;
            }
            if ($segment['end_poi'] === null) {
                continue;
            }
            if ($this->looksLikeLoadingPoi($segment['end_poi'])) {
                $loadingStop = $segment;
                break;
            }
            if ($loadingStop === null) {
                $loadingStop = $segment;
            }
        }
        if ($loadingStop !== null) {
            $suggestions['data_incarcare'] = substr((string) $loadingStop['date_end'], 0, 10);
            $suggestions['loc_incarcare'] = $this->matchLoadLocation((string) $loadingStop['end_poi']);
        }

        // Plecarea: dupa ultima stationare lunga din curtea de plecare (zilele de asteptare
        // cu mici mutari in curte nu intra in cursa). Km se numara de la plecare.
        $startIdx = $this->findDepartureIdx($segments, $anchor, $departIdx ?? 0);

        // Km cursa: suma distantelor segmentelor din cursa; km totali: delta odometru.
        $sumKm = 0.0;
        $lastKmIndex = null;
        foreach ($segments as $idx => $segment) {
            if ($lastTripIdx !== null && $idx > $lastTripIdx) {
                break;
            }
            if ($idx < $startIdx) {
                continue;
            }
            $sumKm += $segment['distance_km'];
            if ($segment['km_index'] !== null) {
                $lastKmIndex = (float) $segment['km_index'];
            }
        }
        $suggestions['km_cursa'] = (int) round($sumKm);

        $kmIndexStart = $first['km_index'] !== null ? (float) $first['km_index'] - $first['distance_km'] : null;
        if ($kmIndexStart !== null && $lastKmIndex !== null && $lastKmIndex >= $kmIndexStart) {
            $suggestions['km_totali'] = (int) round($lastKmIndex - $kmIndexStart);
        } else {
            $suggestions['km_totali'] = $suggestions['km_cursa'];
        }

        return [
            'suggestions' => $suggestions,
            'warnings' => $warnings,
            'segments' => $segments,
            'close_reason' => $tripCloseReason,
            'last_idx' => $lastTripIdx,
            'start_movement' => str_replace('T', ' ', substr((string) $segments[$startIdx]['date_start'], 0, 16)),
        ];
    }

    /**
     * Indexul segmentului cu care incepe cursa: primul dupa ultima stationare de minim
     * START_LONG_PARK_MINUTES petrecuta in curtea de plecare (la max START_YARD_RADIUS_KM de
     * ancora), inainte ca vehiculul sa iasa din curte. Fara stationare lunga: prima miscare reala.
     * Ex. #645: parcat 13h30 pana la 24.09 13:29 (merge la cantar, apoi pleaca la 15:23) -> 13:29.
     *
     * @param array<int, array<string, mixed>> $segments
     */
    private function findDepartureIdx(array $segments, array $anchor, int $firstMovingIdx): int
    {
        $startIdx = $firstMovingIdx;
        for ($idx = 1, $count = count($segments); $idx < $count; $idx++) {
            $previous = $segments[$idx - 1];
            $inYard = $previous['lat_end'] !== null && is_numeric($anchor['lat'])
                ? $this->haversineKm((float) $previous['lat_end'], (float) $previous['lng_end'], (float) $anchor['lat'], (float) $anchor['lng']) <= self::START_YARD_RADIUS_KM
                : $this->isSameLocation($previous['end_poi_id'], null, null, $anchor);
            if (!$inYard) {
                break; // a iesit din curte: plecarea a avut loc deja
            }
            $previousEnd = $this->parseSasDate((string) $previous['date_end']);
            $start = $this->parseSasDate((string) $segments[$idx]['date_start']);
            if ($previousEnd !== null && $start !== null
                && ($start->getTimestamp() - $previousEnd->getTimestamp()) / 60 >= self::START_LONG_PARK_MINUTES
            ) {
                $startIdx = $idx;
            }
        }

        return $startIdx;
    }

    /**
     * Inlantuie cursele detectate din GPS pe o perioada: fiecare cursa pleaca de unde
     * s-a inchis precedenta (aceleasi reguli ca prefill(), fereastra de max 7 zile per
     * cursa), apoi le alatura cursele reale din Dispecer curse pentru comparatie.
     *
     * @param int[]|null $parkingPoiIds
     */
    public function detectTripsInRange(
        int $localVehicleId,
        string $dateFrom,
        string $dateTo,
        ?array $parkingPoiIds = null,
        ?int $deliveryParkMinutes = null
    ): array {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $from > $to) {
            throw new InvalidArgumentException('Perioada invalida (format asteptat: Y-m-d, de la <= pana la).');
        }
        $now = new DateTimeImmutable('now');
        $rangeEnd = min($to->setTime(23, 59, 59), $now);
        if ($from > $now) {
            throw new InvalidArgumentException('Perioada este in viitor - nu exista date GPS.');
        }
        if ((int) $from->diff($rangeEnd)->days > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Perioada maxima este de ' . self::MAX_RANGE_DAYS . ' zile.');
        }

        $vehicle = $this->getLocalVehicle($localVehicleId);
        if ($vehicle === null) {
            throw new InvalidArgumentException('Vehiculul selectat nu exista.');
        }
        $this->restoreSasSession();
        $carId = $this->resolveSasCarId((string) $vehicle['nr_inmatriculare']);
        if ($carId === 0) {
            throw new InvalidArgumentException('Vehiculul ' . $vehicle['nr_inmatriculare'] . ' nu are corespondent in SAS.');
        }

        $rawSegments = $this->fetchSegmentsByMonth($carId, $from, $rangeEnd, $now);
        $pois = $this->getPois();
        $this->persistSasSession();

        $segments = $this->normalizeSegments($rawSegments, $pois);
        $parkingSet = $this->resolveParkingSet($parkingPoiIds, $pois);
        $minDeliveryPark = max(0, $deliveryParkMinutes ?? self::DELIVERY_PARK_DEFAULT_MINUTES);

        $trips = [];
        $cursor = 0;
        $count = count($segments);
        while ($cursor < $count) {
            // Fereastra cursei: ca in prefill, max MAX_WINDOW_DAYS de la plecare.
            $sliceStart = $this->parseSasDate((string) $segments[$cursor]['date_start']) ?? $now;
            $windowEnd = min($sliceStart->modify('+' . self::MAX_WINDOW_DAYS . ' days'), $rangeEnd);
            $slice = [];
            for ($i = $cursor; $i < $count; $i++) {
                $segmentStart = $this->parseSasDate((string) $segments[$i]['date_start']);
                if ($i > $cursor && $segmentStart !== null && $segmentStart > $windowEnd) {
                    break;
                }
                $slice[] = $segments[$i];
            }

            $analysis = $this->analyzeTrip($slice, $windowEnd, $parkingSet, $minDeliveryPark);
            if ($analysis['last_idx'] === null) {
                // Fara miscare in toata fereastra: perioada de stationare, nu e cursa.
                $cursor += count($slice);
                continue;
            }

            $tripSegments = array_slice($analysis['segments'], 0, $analysis['last_idx'] + 1);
            $trips[] = $this->summarizeTrip($tripSegments, $analysis, $windowEnd < $rangeEnd || $windowEnd < $now);
            $cursor += $analysis['last_idx'] + 1;
        }

        $realTrips = $this->getRealTrips($localVehicleId, $from, $rangeEnd);
        $usedRealIds = [];
        foreach ($trips as $index => $trip) {
            $trips[$index]['real'] = null;
            $trips[$index]['real_repeated'] = false;
            if ($trip['is_manoeuvre']) {
                continue;
            }
            $match = $this->bestOverlappingTrip($trip['start'], $trip['end'] ?? $rangeEnd->format('Y-m-d H:i'), $realTrips);
            $trips[$index]['real'] = $match;
            $trips[$index]['real_repeated'] = $match !== null && isset($usedRealIds[$match['id']]);
            if ($match !== null) {
                $usedRealIds[$match['id']] = true;
            }
        }
        $unmatchedReal = array_values(array_filter($realTrips, static fn (array $real) => !isset($usedRealIds[$real['id']])));

        $gpsKm = array_sum(array_map(static fn (array $trip) => (int) $trip['km'], $trips));
        $realKm = array_sum(array_map(static fn (array $real) => (int) ($real['km_totali'] ?? 0), $realTrips));

        return [
            'vehicle' => [
                'id' => (int) $vehicle['id'],
                'nr_inmatriculare' => (string) $vehicle['nr_inmatriculare'],
                'sas_car_id' => $carId,
            ],
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $rangeEnd->format('Y-m-d H:i')],
            'trips' => $trips,
            'unmatched_real' => $unmatchedReal,
            'totals' => [
                'gps_trips' => count(array_filter($trips, static fn (array $trip) => !$trip['is_manoeuvre'])),
                'gps_km' => $gpsKm,
                'segments_km' => (int) round(array_sum(array_column($segments, 'distance_km'))),
                'real_trips' => count($realTrips),
                'real_km_totali' => $realKm,
                'matched' => count($usedRealIds),
            ],
        ];
    }

    /**
     * Rezumatul unei curse detectate, pentru tabelul din sandbox.
     *
     * @param array<int, array<string, mixed>> $tripSegments segmentele cursei (pana la inchidere)
     */
    private function summarizeTrip(array $tripSegments, array $analysis, bool $windowClosedInPast): array
    {
        $s = $analysis['suggestions'];
        $stops = [];
        foreach ($tripSegments as $segment) {
            if ($segment['distance_km'] >= self::MIN_MOVING_SEGMENT_KM) {
                $stops[] = [
                    'at' => str_replace('T', ' ', substr((string) $segment['date_end'], 0, 16)),
                    'place' => (string) $segment['to'],
                    'poi' => $segment['end_poi'] !== null,
                    'km' => round((float) $segment['distance_km'], 1),
                ];
            }
        }

        $end = $s['data_sfarsit'] !== null ? $s['data_sfarsit'] . ' ' . $s['ora_sfarsit'] : null;
        $reason = $analysis['close_reason'];
        if ($reason === null && $windowClosedInPast) {
            // Fereastra de 7 zile s-a terminat in trecut fara inchidere: cursa e taiata la capat.
            $reason = 'window_cut';
            $lastSegment = $tripSegments[count($tripSegments) - 1];
            $end = str_replace('T', ' ', substr((string) $lastSegment['date_end'], 0, 16));
        }
        $startDate = $this->parseSasDate(str_replace(' ', 'T', (string) $analysis['start_movement']) . ':00');
        $endDate = $end !== null ? $this->parseSasDate(str_replace(' ', 'T', $end) . ':00') : null;

        return [
            'start' => $analysis['start_movement'],
            'end' => $end,
            'duration_minutes' => $startDate !== null && $endDate !== null
                ? max(0, (int) floor(($endDate->getTimestamp() - $startDate->getTimestamp()) / 60))
                : null,
            'from' => (string) $tripSegments[0]['from'],
            'yard_end' => $s['ultima_miscare_curte'] ?? null,
            'end_place' => $s['loc_sfarsit'],
            'end_park_minutes' => $s['stationare_sfarsit_min'],
            'data_incarcare' => $s['data_incarcare'],
            'loc_incarcare' => $s['loc_incarcare']['nume'] ?? null,
            'km' => (int) $s['km_totali'],
            // Sub MIN_TRIP_KM e o manevra (mutare in parcare / la cantar), nu o cursa.
            'is_manoeuvre' => (int) $s['km_totali'] < self::MIN_TRIP_KM,
            'close_reason' => $reason,
            'stops' => $stops,
        ];
    }

    /**
     * Segmentele SAS brute pe perioada, cate o interogare pe luna calendaristica.
     * Lunile incheiate se pastreaza pe disc (nu se mai schimba); luna curenta se
     * reinterogheaza dupa SHEET_TTL_SECONDS.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchSegmentsByMonth(int $carId, DateTimeImmutable $from, DateTimeImmutable $rangeEnd, DateTimeImmutable $now): array
    {
        $segments = [];
        $seen = [];
        $chunkStart = $from->setTime(0, 0, 0);
        while ($chunkStart <= $rangeEnd) {
            $chunkEnd = min($chunkStart->modify('last day of this month')->setTime(23, 59, 59), $rangeEnd);
            $includesNow = $chunkEnd >= $now->setTime(0, 0, 0);
            $cacheFile = $this->cacheDir . '/sas_prefill_sheet_' . $carId . '_' . $chunkStart->format('Ymd') . '_' . $chunkEnd->format('Ymd') . '.json';
            $cache = $this->readJson($cacheFile);
            $fresh = $cache !== null && is_array($cache['segments'] ?? null)
                && (!$includesNow || (time() - (int) ($cache['fetched_at_ts'] ?? 0)) < self::SHEET_TTL_SECONDS);

            if ($fresh) {
                $chunkSegments = $cache['segments'];
            } else {
                $sheet = $this->client->getTravelSheet(
                    $carId,
                    $chunkStart->format('Y-m-d\TH:i:s'),
                    $chunkEnd->format('Y-m-d\TH:i:s'),
                    !$includesNow
                );
                $chunkSegments = array_values(array_filter((array) ($sheet['segments'] ?? []), 'is_array'));
                $this->writeJson($cacheFile, ['segments' => $chunkSegments, 'fetched_at_ts' => time()]);
            }

            foreach ($chunkSegments as $segment) {
                // Un segment peste miezul noptii de la capat de luna poate veni in ambele bucati.
                $key = (string) ($segment['dateStart'] ?? '') . '|' . (string) ($segment['dateEnd'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $segments[] = $segment;
                }
            }
            $chunkStart = $chunkStart->modify('first day of next month')->setTime(0, 0, 0);
        }
        usort($segments, static fn (array $a, array $b) => strcmp((string) ($a['dateStart'] ?? ''), (string) ($b['dateStart'] ?? '')));

        return $segments;
    }

    /**
     * Cursele reale (Dispecer curse) ale vehiculului care se suprapun cu perioada.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRealTrips(int $vehicleId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statement = $this->db->prepare(
            "SELECT c.id, c.data_incarcare, c.data_inceput, c.ora_inceput, c.data_sfarsit, c.ora_sfarsit,
                    c.km_cursa, c.km_totali, c.tip_transport, c.tip_marfa, c.cantitate_incarcata,
                    s.nume AS sofer_nume, li.nume AS loc_incarcare_nume, bt.nume AS beneficiar_nume
             FROM curse_dispecer c
             LEFT JOIN soferi s ON s.id = c.driver_id
             LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
             LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
             WHERE c.vehicle_id = :vehicle_id
               AND c.deleted_at IS NULL
               AND c.parent_cursa_id IS NULL
               AND c.data_inceput <= :to_date
               AND COALESCE(c.data_sfarsit, c.data_inceput) >= :from_date
             ORDER BY c.data_inceput, c.ora_inceput, c.id"
        );
        $statement->execute([
            ':vehicle_id' => $vehicleId,
            ':to_date' => $to->format('Y-m-d'),
            ':from_date' => $from->format('Y-m-d'),
        ]);

        $trips = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start = trim((string) $row['data_inceput'] . ' ' . substr((string) ($row['ora_inceput'] ?? '00:00'), 0, 5));
            $end = $row['data_sfarsit'] !== null
                ? trim((string) $row['data_sfarsit'] . ' ' . substr((string) ($row['ora_sfarsit'] ?? '23:59'), 0, 5))
                : null;
            $trips[] = [
                'id' => (int) $row['id'],
                'start' => $start,
                'end' => $end,
                'data_incarcare' => $row['data_incarcare'],
                'km_cursa' => $row['km_cursa'] !== null ? (int) $row['km_cursa'] : null,
                'km_totali' => $row['km_totali'] !== null ? (int) $row['km_totali'] : null,
                'tip_transport' => $row['tip_transport'],
                'tip_marfa' => $row['tip_marfa'],
                'cantitate' => $row['cantitate_incarcata'],
                'sofer' => $row['sofer_nume'],
                'loc_incarcare' => $row['loc_incarcare_nume'],
                'beneficiar' => $row['beneficiar_nume'],
            ];
        }

        return $trips;
    }

    /**
     * Cursa reala care se suprapune cel mai mult cu intervalul GPS (sau null).
     * O cursa reala fara sfarsit e considerata deschisa o zi.
     *
     * @param array<int, array<string, mixed>> $realTrips
     */
    private function bestOverlappingTrip(?string $start, string $end, array $realTrips): ?array
    {
        $gpsStart = $start !== null ? strtotime($start) : false;
        $gpsEnd = strtotime($end);
        if ($gpsStart === false || $gpsEnd === false) {
            return null;
        }

        $best = null;
        $bestOverlap = 0;
        foreach ($realTrips as $real) {
            $realStart = strtotime((string) $real['start']);
            $realEnd = $real['end'] !== null ? strtotime((string) $real['end']) : ($realStart !== false ? $realStart + 86400 : false);
            if ($realStart === false || $realEnd === false) {
                continue;
            }
            $overlap = min($gpsEnd, $realEnd) - max($gpsStart, $realStart);
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $real;
            }
        }

        return $best;
    }

    /**
     * Parcarile de beneficiar: lista aleasa in sandbox sau, implicit, POI-urile cu
     * "parcare"/"garaj" in nume.
     *
     * @param int[]|null $parkingPoiIds
     * @param array<int, string> $pois
     * @return array<int, bool>
     */
    private function resolveParkingSet(?array $parkingPoiIds, array $pois): array
    {
        if ($parkingPoiIds === null) {
            $parkingPoiIds = [];
            foreach ($pois as $poiId => $poiName) {
                if ($this->looksLikeParkingPoi($poiName)) {
                    $parkingPoiIds[] = (int) $poiId;
                }
            }
        }

        return array_fill_keys(array_map('intval', $parkingPoiIds), true);
    }

    /** @param array<int, array<string, mixed>> $segments */
    private function buildResult(
        array $vehicle,
        int $carId,
        array $sheet,
        array $segments,
        array $suggestions,
        array $warnings,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?string $tripCloseReason
    ): array {
        return [
            'trip_close_reason' => $tripCloseReason,
            'vehicle' => [
                'id' => (int) $vehicle['id'],
                'nr_inmatriculare' => (string) $vehicle['nr_inmatriculare'],
                'sas_car_id' => $carId,
            ],
            'window' => [
                'start' => $windowStart->format('Y-m-d H:i'),
                'end' => $windowEnd->format('Y-m-d H:i'),
            ],
            'sas_summary' => [
                'total_distance_km' => is_numeric($sheet['totalDistance'] ?? null) ? round((float) $sheet['totalDistance'], 1) : null,
                'average_speed' => is_numeric($sheet['averageSpeed'] ?? null) ? round((float) $sheet['averageSpeed'], 1) : null,
                'work_hours' => is_numeric($sheet['workTimeSpanInSeconds'] ?? null) ? round(((float) $sheet['workTimeSpanInSeconds']) / 3600, 1) : null,
                'idle_hours' => is_numeric($sheet['idleTimeSpanInSeconds'] ?? null) ? round(((float) $sheet['idleTimeSpanInSeconds']) / 3600, 1) : null,
                'can_fuel_used_l' => is_numeric($sheet['CANFuelUsed'] ?? null) ? round((float) $sheet['CANFuelUsed'], 1) : null,
            ],
            'suggestions' => $suggestions,
            'segments' => $segments,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawSegments
     * @param array<int, string> $pois locationId => nume
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSegments(array $rawSegments, array $pois): array
    {
        $segments = [];
        foreach ($rawSegments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $startLocation = $segment['startLocationId'] ?? null;
            $endLocation = $segment['endLocationId'] ?? null;
            // Dreptunghiurile POI din SAS sunt mici (zeci de metri): un camion oprit la cativa
            // metri de margine ramane fara POI. Il legam de POI-ul aflat la max POI_SNAP_KM.
            if (!is_numeric($startLocation)) {
                $startLocation = $this->nearestPoi(
                    is_numeric($segment['latitudeStart'] ?? null) ? (float) $segment['latitudeStart'] : null,
                    is_numeric($segment['longitudeStart'] ?? null) ? (float) $segment['longitudeStart'] : null,
                    self::POI_SNAP_KM
                );
            }
            if (!is_numeric($endLocation)) {
                $endLocation = $this->nearestPoi(
                    is_numeric($segment['latitudeEnd'] ?? null) ? (float) $segment['latitudeEnd'] : null,
                    is_numeric($segment['longitudeEnd'] ?? null) ? (float) $segment['longitudeEnd'] : null,
                    self::POI_SNAP_KM
                );
            }
            $segments[] = [
                'index' => (int) ($segment['indexId'] ?? 0),
                'date_start' => (string) ($segment['dateStart'] ?? ''),
                'date_end' => (string) ($segment['dateEnd'] ?? ''),
                'distance_km' => is_numeric($segment['distance'] ?? null) ? round((float) $segment['distance'], 2) : 0.0,
                'km_index' => is_numeric($segment['kmIndex'] ?? null) ? (float) $segment['kmIndex'] : null,
                'from' => $this->describePlace($segment, 'Start', is_numeric($startLocation) ? ($pois[(int) $startLocation] ?? null) : null),
                'to' => $this->describePlace($segment, 'End', is_numeric($endLocation) ? ($pois[(int) $endLocation] ?? null) : null),
                'start_poi' => is_numeric($startLocation) ? ($pois[(int) $startLocation] ?? null) : null,
                'end_poi' => is_numeric($endLocation) ? ($pois[(int) $endLocation] ?? null) : null,
                'start_poi_id' => is_numeric($startLocation) ? (int) $startLocation : null,
                'end_poi_id' => is_numeric($endLocation) ? (int) $endLocation : null,
                'lat_start' => is_numeric($segment['latitudeStart'] ?? null) ? (float) $segment['latitudeStart'] : null,
                'lng_start' => is_numeric($segment['longitudeStart'] ?? null) ? (float) $segment['longitudeStart'] : null,
                'lat_end' => is_numeric($segment['latitudeEnd'] ?? null) ? (float) $segment['latitudeEnd'] : null,
                'lng_end' => is_numeric($segment['longitudeEnd'] ?? null) ? (float) $segment['longitudeEnd'] : null,
                'park_before_minutes' => is_numeric($segment['parkTimeSeconds'] ?? null) ? (int) round(((float) $segment['parkTimeSeconds']) / 60) : null,
                'idle_engine_minutes' => is_numeric($segment['idleEngineSeconds'] ?? null) ? (int) round(((float) $segment['idleEngineSeconds']) / 60) : null,
                'after_trip' => false,
            ];
        }

        return $segments;
    }

    private function describePlace(array $segment, string $suffix, ?string $poiName): string
    {
        if ($poiName !== null && $poiName !== '') {
            return $poiName;
        }

        $parts = [];
        foreach (['city' . $suffix, 'county' . $suffix] as $key) {
            $value = trim((string) ($segment[$key] ?? ''));
            if ($value !== '' && $value !== '-') {
                $parts[] = $value;
            }
        }
        $address = trim((string) ($segment['address' . $suffix] ?? ''));
        if ($address !== '' && $address !== '-') {
            $parts[] = $address;
        }

        return $parts !== [] ? implode(', ', $parts) : 'necunoscut';
    }

    /**
     * POI-urile SAS al caror nume sugereaza un punct de incarcare.
     * Numele reale contin "Cantar"/"incarcare"/terminale; euristica acopera si
     * potrivirea cu locurile de incarcare configurate local.
     */
    private function looksLikeLoadingPoi(string $poiName): bool
    {
        return $this->looksLikeLoadingTerminal($poiName) || $this->matchLoadLocation($poiName) !== null;
    }

    /**
     * Oprire intr-un POI SAS de client: nu benzinarie / parcare / garaj si nu langa un cantar
     * (rafinaria / terminalul unde se incarca are de obicei mai multe POI-uri in jur).
     */
    private function isCustomerDeliveryStop(array $segment): bool
    {
        if ($segment['end_poi'] === null) {
            return false;
        }
        $name = $this->normalizeText((string) $segment['end_poi']);
        foreach (['benzinarie', 'parcare', 'garaj'] as $keyword) {
            if (str_contains($name, $keyword)) {
                return false;
            }
        }

        return $this->nearestPoi(
            $segment['lat_end'],
            $segment['lng_end'],
            self::DELIVERY_TERMINAL_EXCLUSION_KM,
            fn (string $poiName): bool => $this->looksLikeLoadingTerminal($poiName)
        ) === null;
    }

    /**
     * Segmentul se termina la un punct de incarcare: in POI-ul unui cantar/terminal sau
     * la max NEXT_LOAD_RADIUS_KM de el (parcarea de asteptare de langa cantar).
     */
    private function isAtLoadingTerminal(array $segment): bool
    {
        if ($segment['end_poi'] !== null && $this->looksLikeLoadingTerminal((string) $segment['end_poi'])) {
            return true;
        }

        return $this->nearestPoi(
            $segment['lat_end'],
            $segment['lng_end'],
            self::NEXT_LOAD_RADIUS_KM,
            fn (string $name): bool => $this->looksLikeLoadingTerminal($name)
        ) !== null;
    }

    /**
     * Strict: doar POI-urile al caror nume spune ca sunt puncte de incarcare (cantar /
     * incarcare / terminal). Potrivirea cu locurile configurate nu ajunge aici: "BGR Lugoj"
     * se potriveste cu locul "Lugoj", dar pentru ButanGas e punct de livrare.
     */
    private function looksLikeLoadingTerminal(string $poiName): bool
    {
        $normalized = $this->normalizeText($poiName);
        foreach (['incarcare', 'cantar', 'terminal'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Potriveste numele unui POI SAS cu un loc de incarcare configurat local
     * (configurare_locuri_incarcare). Potrivire pe incluziune de text normalizat,
     * in ambele directii ("Vixon Gas Giurgiu" <-> "Giurgiu").
     *
     * @return array{id: int, nume: string}|null
     */
    private function matchLoadLocation(string $poiName): ?array
    {
        $poi = $this->normalizeText($poiName);
        if ($poi === '') {
            return null;
        }

        foreach ($this->getLoadLocations() as $location) {
            $local = $this->normalizeText((string) $location['nume']);
            if ($local === '') {
                continue;
            }
            if (str_contains($poi, $local) || str_contains($local, $poi)) {
                return ['id' => (int) $location['id'], 'nume' => (string) $location['nume']];
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function getLoadLocations(): array
    {
        static $locations = null;
        if ($locations === null) {
            $statement = $this->db->query('SELECT id, nume FROM configurare_locuri_incarcare WHERE activ = 1');
            $locations = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        return $locations;
    }

    private function getLocalVehicle(int $vehicleId): ?array
    {
        $statement = $this->db->prepare('SELECT id, nr_inmatriculare FROM vehicule WHERE id = :id');
        $statement->execute([':id' => $vehicleId]);
        $vehicle = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($vehicle) ? $vehicle : null;
    }

    private function resolveSasCarId(string $plate): int
    {
        $needle = $this->plateKey($plate);
        foreach ($this->getSasCars() as $car) {
            if ($this->plateKey((string) ($car['licensePlate'] ?? '')) === $needle) {
                return (int) ($car['carId'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * POI-urile SAS (locationId => nume), cu cache pe disc: lista se schimba rar,
     * iar interogarea are rate-limit pe minut.
     *
     * @return array<int, string>
     */
    private function getPois(): array
    {
        $cacheFile = $this->cacheDir . '/sas_pois.json';
        $cache = $this->readJson($cacheFile);
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        if (
            $cache !== null
            && (time() - $fetchedAt) < self::POIS_TTL_SECONDS
            && is_array($cache['pois'] ?? null)
            && is_array($cache['boxes'] ?? null)
        ) {
            $this->poiBoxes = $cache['boxes'];
            $this->poiNames = array_map('strval', $cache['pois']);
            return $this->poiNames;
        }

        $companyName = $this->getCompanyName();
        $pois = [];
        $boxes = [];
        if ($companyName !== '') {
            foreach ($this->client->findPois($companyName, '') as $poi) {
                $locationId = (int) ($poi['locationId'] ?? 0);
                $name = trim((string) ($poi['name'] ?? ''));
                if ($locationId > 0 && $name !== '') {
                    $pois[$locationId] = $name;
                    $box = [
                        $poi['latitudeMinInDegrees'] ?? null,
                        $poi['latitudeMaxInDegrees'] ?? null,
                        $poi['longitudeMinInDegrees'] ?? null,
                        $poi['longitudeMaxInDegrees'] ?? null,
                    ];
                    if (count(array_filter($box, 'is_numeric')) === 4) {
                        $boxes[$locationId] = array_map('floatval', $box);
                    }
                }
            }
        }

        $this->writeJson($cacheFile, ['pois' => $pois, 'boxes' => $boxes, 'fetched_at_ts' => time()]);
        $this->poiBoxes = $boxes;
        $this->poiNames = $pois;

        return $pois;
    }

    /**
     * POI-ul cel mai apropiat (dupa distanta pana la dreptunghiul lui) aflat la cel mult
     * $maxKm de punct, optional doar dintre POI-urile al caror nume e acceptat de $filter.
     */
    private function nearestPoi(?float $lat, ?float $lng, float $maxKm, ?callable $filter = null): ?int
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $bestId = null;
        $bestKm = $maxKm;
        foreach ($this->poiBoxes as $poiId => [$latMin, $latMax, $lngMin, $lngMax]) {
            if ($filter !== null && !$filter((string) ($this->poiNames[$poiId] ?? ''))) {
                continue;
            }
            $km = $this->haversineKm($lat, $lng, max($latMin, min($lat, $latMax)), max($lngMin, min($lng, $lngMax)));
            if ($km <= $bestKm) {
                $bestKm = $km;
                $bestId = (int) $poiId;
            }
        }

        return $bestId;
    }

    private function getCompanyName(): string
    {
        $info = $this->getSasInfo();
        $companies = is_array($info['companies'] ?? null) ? $info['companies'] : [];
        $first = is_array($companies[0] ?? null) ? $companies[0] : [];

        return trim((string) ($first['name'] ?? ''));
    }

    /** Raspunsul complet /api/info, cu acelasi cache pe disc ca FleetLivePositionService. */
    private function getSasInfo(): array
    {
        $cache = $this->readJson($this->cacheDir . '/sas_cars.json');
        $fetchedAt = (int) ($cache['fetched_at_ts'] ?? 0);
        if ($cache !== null && (time() - $fetchedAt) < self::CARS_TTL_SECONDS && is_array($cache['info'] ?? null)) {
            return $cache['info'];
        }

        $info = $this->client->getCompanyInfo();
        $this->writeJson($this->cacheDir . '/sas_cars.json', [
            'info' => $info,
            'fetched_at_ts' => time(),
        ]);

        return $info;
    }

    /** @return array<int, array<string, mixed>> */
    private function getSasCars(): array
    {
        $info = $this->getSasInfo();
        $cars = $info['cars'] ?? [];

        return is_array($cars) ? array_values(array_filter($cars, 'is_array')) : [];
    }

    private function restoreSasSession(): void
    {
        $session = $this->readJson($this->cacheDir . '/sas_session.json');
        if (is_array($session)) {
            $this->client->restoreState($session);
        }
    }

    private function persistSasSession(): void
    {
        $this->writeJson($this->cacheDir . '/sas_session.json', $this->client->exportState());
    }

    /**
     * Aceeasi locatie = acelasi POI SAS sau coordonate la sub SAME_LOCATION_RADIUS_KM
     * de ancora (punctul de plecare al cursei).
     */
    private function isSameLocation(?int $poiId, ?float $lat, ?float $lng, array $anchor): bool
    {
        if ($poiId !== null && $anchor['poi_id'] !== null && $poiId === (int) $anchor['poi_id']) {
            return true;
        }

        if ($lat === null || $lng === null || !is_numeric($anchor['lat']) || !is_numeric($anchor['lng'])) {
            return false;
        }

        return $this->haversineKm($lat, $lng, (float) $anchor['lat'], (float) $anchor['lng']) <= self::SAME_LOCATION_RADIUS_KM;
    }

    /** Distanta in km intre doua coordonate GPS (formula haversine). */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Dupa sosire, segmentele urmatoare raman in cursa cat timp sunt mutari in aceeasi curte
     * (sub MIN_MOVING_SEGMENT_KM, la max SAME_LOCATION_RADIUS_KM de punctul de sosire) cu
     * pauze sub YARD_MAX_GAP_MINUTES. Returneaza indexul ultimului segment al cursei.
     *
     * @param array<int, array<string, mixed>> $segments
     */
    private function extendOverYardMoves(array $segments, int $arrivalIdx): int
    {
        $arrival = [
            'poi_id' => $segments[$arrivalIdx]['end_poi_id'],
            'lat' => $segments[$arrivalIdx]['lat_end'],
            'lng' => $segments[$arrivalIdx]['lng_end'],
        ];
        $lastIdx = $arrivalIdx;
        for ($next = $arrivalIdx + 1, $count = count($segments); $next < $count; $next++) {
            $segment = $segments[$next];
            $previousEnd = $this->parseSasDate((string) $segments[$lastIdx]['date_end']);
            $start = $this->parseSasDate((string) $segment['date_start']);
            $gapMinutes = $previousEnd !== null && $start !== null
                ? (int) floor(($start->getTimestamp() - $previousEnd->getTimestamp()) / 60)
                : PHP_INT_MAX;
            // Acelasi POI sau la max 500 m (POI-uri vecine: Parcare ButanGas <-> BGR Contesti Cantar).
            $inYard = $this->isSameLocation($segment['end_poi_id'], $segment['lat_end'], $segment['lng_end'], $arrival);
            if (
                $segment['distance_km'] >= self::MIN_MOVING_SEGMENT_KM
                || $gapMinutes >= self::YARD_MAX_GAP_MINUTES
                || !$inYard
            ) {
                break;
            }
            $lastIdx = $next;
        }

        return $lastIdx;
    }

    /**
     * Minutele de stationare dupa segmentul $idx: pana la urmatoarea miscare reala
     * (segmentele mici din aceeasi parcare nu intrerup stationarea) sau pana acum.
     *
     * @param array<int, array<string, mixed>> $segments
     */
    private function parkedMinutesAfter(array $segments, int $idx, DateTimeImmutable $now): ?int
    {
        $end = $this->parseSasDate((string) ($segments[$idx]['date_end'] ?? ''));
        if ($end === null) {
            return null;
        }

        $resume = $now;
        for ($next = $idx + 1, $count = count($segments); $next < $count; $next++) {
            if ($segments[$next]['distance_km'] >= self::MIN_MOVING_SEGMENT_KM) {
                $resume = $this->parseSasDate((string) $segments[$next]['date_start']) ?? $now;
                break;
            }
        }

        return max(0, (int) floor(($resume->getTimestamp() - $end->getTimestamp()) / 60));
    }

    private function parseSasDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function looksLikeParkingPoi(string $poiName): bool
    {
        $normalized = $this->normalizeText($poiName);

        return str_contains($normalized, 'parcare') || str_contains($normalized, 'garaj');
    }

    private function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? $plate);
    }

    private function normalizeText(string $text): string
    {
        $text = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text) : $text;

        return strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $file, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
