<?php
declare(strict_types=1);

/**
 * Revizii V2 — logica pura (fara BD): secventa X / Y calculata din km, evaluarea criteriilor
 * de mentenanta (km / ore motor / calendar) si starea de urgenta.
 *
 * Reguli (regula finala a utilizatorului, 2026-11-13):
 *  - NU exista un ciclu fix: fiecare model are un interval km pentru Revizia X si un prag /
 *    interval recurent km pentru Revizia Y; numarul de X dintre doua Y rezulta din km;
 *  - X se programeaza la intervalul sau; Y devine scadenta la pragul ei, oricate X au fost;
 *  - cand X si Y coincid (in toleranta), se genereaza O SINGURA revizie Y (operatiunile X + Y);
 *  - cand Y cade intre doua X, Y include operatiunile X, iar X-urile urmatoare se recalculeaza
 *    dupa politica modelului: „reset” (de la Y) sau „grila” (grila initiala de km);
 *  - dupa Y, urmatoarea Y = km la executia Y + intervalul recurent;
 *  - doar reviziile finalizate complet muta referintele; ciornele / programarile / reviziile
 *    partiale nu;
 *  - un criteriu fara date este „nemasurat”, niciodata 0; urgenta e separata de fluxul de lucru.
 */
final class ReviziiV2Engine
{
    public const ST_NOT_CONFIGURED = 'neconfigurat';
    public const ST_NO_DATA = 'fara_date';
    public const ST_OK = 'in_termen';
    public const ST_SOON = 'se_apropie';
    public const ST_DUE = 'scadent';
    public const ST_OVERDUE = 'depasit';

    /** Severitatea pentru agregare (cea mai critica stare castiga). */
    public const SEVERITY = [
        self::ST_NOT_CONFIGURED => 0,
        self::ST_OK => 1,
        self::ST_NO_DATA => 2,
        self::ST_SOON => 3,
        self::ST_DUE => 4,
        self::ST_OVERDUE => 5,
    ];

    public const STATUS_LABELS = [
        self::ST_NOT_CONFIGURED => 'Neconfigurat',
        self::ST_NO_DATA => 'Date insuficiente',
        self::ST_OK => 'În termen',
        self::ST_SOON => 'Se apropie',
        self::ST_DUE => 'Scadent',
        self::ST_OVERDUE => 'Depășit',
    ];

    /** Pragul implicit de avertizare cand planul nu are prag: 10% din interval. */
    public const DEFAULT_WARN_RATIO = 0.10;

    /** Politicile de recalculare a lui X dupa o revizie Y (sau dupa orice revizie). */
    public const X_POLICIES = [
        'reset' => 'De la ultima revizie (X sau Y) + intervalul X',
        'grila' => 'Pe grila inițială de km (Y nu mută grila)',
    ];

    /** Cate evenimente se proiecteaza cel mult (pana la urmatoarea Y inclusiv). */
    private const MAX_PROJECTED = 12;

    /**
     * Programarea pe km a reviziilor: urmatoarea revizie (X sau Y), tinta ei si secventa reala
     * (efectuate de la ultima Y + proiectate pana la urmatoarea Y inclusiv).
     *
     * @param array<string, mixed> $p current_km, x_interval_km, x_base_km (km ultimei revizii complete),
     *        x_origin_km (originea grilei), y_interval_km (recurent), y_threshold_km (prima Y, absolut),
     *        y_base_km (km ultimei Y), tolerance_km, policy (reset|grila), x_done_since_y, done[]
     * @return array<string, mixed>
     */
    public static function schedule(array $p): array
    {
        $xInterval = self::posInt($p['x_interval_km'] ?? null);
        $yInterval = self::posInt($p['y_interval_km'] ?? null);
        $yThreshold = self::posInt($p['y_threshold_km'] ?? null);
        $policy = ($p['policy'] ?? 'reset') === 'grila' ? 'grila' : 'reset';
        $tolerance = isset($p['tolerance_km']) && $p['tolerance_km'] !== null && $p['tolerance_km'] !== ''
            ? max(0, (int) $p['tolerance_km'])
            : ($xInterval !== null ? (int) round($xInterval * self::DEFAULT_WARN_RATIO) : 0);
        $xBase = isset($p['x_base_km']) && $p['x_base_km'] !== null ? (float) $p['x_base_km'] : null;
        $origin = isset($p['x_origin_km']) && $p['x_origin_km'] !== null ? (float) $p['x_origin_km'] : $xBase;
        $yBase = isset($p['y_base_km']) && $p['y_base_km'] !== null ? (float) $p['y_base_km'] : null;
        $xDone = max(0, (int) ($p['x_done_since_y'] ?? 0));
        $notes = [];

        $nextX = static function (?float $after) use ($xInterval, $policy, $origin): ?float {
            if ($xInterval === null || $after === null) {
                return null;
            }
            if ($policy === 'grila' && $origin !== null) {
                // Urmatorul punct al grilei initiale, sarind punctele mai apropiate de jumatate de interval.
                $k = (int) floor(($after + $xInterval / 2 - $origin) / $xInterval) + 1;
                return $origin + max(1, $k) * $xInterval;
            }

            return $after + $xInterval;
        };
        $xTarget = $nextX($xBase);
        $yTarget = null;
        if ($yBase !== null && $yInterval !== null) {
            $yTarget = $yBase + $yInterval;
        } elseif ($yBase === null && $yThreshold !== null) {
            $yTarget = (float) $yThreshold;
        } elseif ($yBase === null && $yInterval !== null) {
            $notes[] = 'Lipsește referința ultimei Revizii Y (km): ținta Y nu se poate calcula.';
        }
        if ($xInterval === null) {
            $notes[] = 'Planul nu are interval km pentru Revizia X.';
        } elseif ($xBase === null) {
            $notes[] = 'Lipsește referința ultimei revizii (km): ținta X nu se poate calcula.';
        }
        if ($yInterval === null && $yThreshold === null) {
            $notes[] = 'Planul nu are prag / interval km pentru Revizia Y: se programează doar revizii X.';
        }

        // Y castiga cand e inaintea lui X sau coincide cu el (in toleranta) — o singura revizie.
        $decide = static function (?float $x, ?float $y) use ($tolerance): ?array {
            if ($y !== null && ($x === null || $y <= $x + $tolerance)) {
                return ['type' => 'Y', 'km' => $x !== null ? min($x, $y) : $y,
                    'merged' => $x !== null && abs($y - $x) <= $tolerance, 'between' => $x !== null && $y < $x - $tolerance];
            }
            if ($x !== null) {
                return ['type' => 'X', 'km' => $x, 'merged' => false, 'between' => false];
            }

            return null;
        };
        $next = $decide($xTarget, $yTarget);

        // Proiectia: pana la urmatoarea Y inclusiv (sau cateva X daca Y nu e configurata).
        $planned = [];
        $x = $xTarget;
        $count = $xDone;
        for ($i = 0; $i < self::MAX_PROJECTED; $i++) {
            $event = $decide($x, $yTarget);
            if ($event === null) {
                break;
            }
            $planned[] = ['type' => $event['type'], 'label' => self::revisionLabel($event['type'], $count + 1), 'km' => round($event['km']),
                'state' => $i === 0 ? 'next' : 'planned', 'merged' => $event['merged'], 'date' => null];
            if ($event['type'] === 'Y') {
                break;
            }
            $count++;
            $x = $nextX($event['km']);
            if ($yTarget === null && $i >= 3) {
                break;
            }
        }
        $hasY = $planned !== [] && end($planned)['type'] === 'Y';
        $done = array_map(static fn (array $e): array => $e + ['state' => 'done'], array_values($p['done'] ?? []));

        return [
            'type' => $next['type'] ?? null,
            'label' => $next === null ? null : self::revisionLabel($next['type'], $xDone + 1),
            'x_number' => $next !== null && $next['type'] === 'X' ? $xDone + 1 : 0,
            'km_target' => $next !== null ? round($next['km']) : null,
            'x_target' => $xTarget !== null ? round($xTarget) : null,
            'y_target' => $yTarget !== null ? round($yTarget) : null,
            'merged' => $next['merged'] ?? false,
            'y_between' => $next['between'] ?? false,
            'x_done_since_y' => $xDone,
            'x_before_y' => $hasY ? $xDone + count($planned) - 1 : null,
            'tolerance_km' => $tolerance,
            'policy' => $policy,
            'sequence' => array_merge($done, $planned),
            'notes' => $notes,
        ];
    }

    /** Eticheta unei revizii: Y sau X{numarul de la ultima Y}. */
    public static function revisionLabel(string $type, int $xNumber): string
    {
        return $type === 'Y' ? 'Y' : 'X' . max(1, $xNumber);
    }

    /**
     * Evalueaza criteriile unei reguli fata de uzura masurata de la referinta.
     *
     * @param array{interval_km?:?int, interval_ore?:?int, interval_luni?:?int, logica?:string, coef_ralanti_km?:?float} $rule
     * @param array{km_since?:?float, ore_since?:?float, idle_since?:?float, baseline_date?:?string, km_partial?:bool, ore_partial?:bool} $usage
     * @param array{prag_km?:?int, prag_ore?:?int, prag_zile?:?int} $thresholds
     * @return array<string, mixed>
     */
    public static function evaluate(array $rule, array $usage, array $thresholds, int $nowTs): array
    {
        $criteria = [];
        $kmInterval = self::posInt($rule['interval_km'] ?? null);
        $hourInterval = self::posInt($rule['interval_ore'] ?? null);
        $monthInterval = self::posInt($rule['interval_luni'] ?? null);
        $logic = ($rule['logica'] ?? 'or') === 'and' ? 'and' : 'or';
        $notes = [];

        if ($kmInterval !== null) {
            $used = isset($usage['km_since']) && $usage['km_since'] !== null ? max(0.0, (float) $usage['km_since']) : null;
            $coef = isset($rule['coef_ralanti_km']) && $rule['coef_ralanti_km'] !== null ? (float) $rule['coef_ralanti_km'] : 0.0;
            if ($used !== null && $coef > 0) {
                if (isset($usage['idle_since']) && $usage['idle_since'] !== null) {
                    $extra = (float) $usage['idle_since'] * $coef;
                    $notes[] = sprintf('Km echivalenți (estimare): %s km + %s h ralanti × %s', self::fmt($used), self::fmt((float) $usage['idle_since'], 1), self::fmt($coef, 2));
                    $used += $extra;
                } else {
                    $notes[] = 'Km echivalenți activați, dar orele de ralanti nu sunt măsurate: s-au folosit doar km.';
                }
            }
            $criteria[] = self::criterion('km', $kmInterval, $used, self::posInt($thresholds['prag_km'] ?? null), 'km', !empty($usage['km_partial']));
        }
        if ($hourInterval !== null) {
            $used = isset($usage['ore_since']) && $usage['ore_since'] !== null ? max(0.0, (float) $usage['ore_since']) : null;
            $criteria[] = self::criterion('ore', $hourInterval, $used, self::posInt($thresholds['prag_ore'] ?? null), 'h', !empty($usage['ore_partial']));
        }
        if ($monthInterval !== null) {
            $baseline = $usage['baseline_date'] ?? null;
            $criteria[] = self::calendarCriterion($monthInterval, $baseline !== null && $baseline !== '' ? (string) $baseline : null, self::posInt($thresholds['prag_zile'] ?? null), $nowTs);
        }

        if ($criteria === []) {
            return ['status' => self::ST_NOT_CONFIGURED, 'criteria' => [], 'logica' => $logic, 'pct' => null, 'dominant' => null, 'notes' => $notes,
                'km_ramasi' => null, 'ore_ramase' => null, 'zile_ramase' => null, 'scadenta_data' => null];
        }

        $measured = array_values(array_filter($criteria, static fn (array $c): bool => $c['measured']));
        $status = self::ST_NO_DATA;
        $dominant = null;
        if ($measured !== []) {
            if ($logic === 'or') {
                // Prima limita atinsa decide: starea cea mai critica, la egalitate procentul cel mai mare.
                usort($measured, static fn (array $a, array $b): int => [self::SEVERITY[$b['status']], $b['pct']] <=> [self::SEVERITY[$a['status']], $a['pct']]);
                $dominant = $measured[0];
                $status = $dominant['status'];
            } else {
                // AND: scadent doar cand TOATE limitele sunt atinse.
                usort($measured, static fn (array $a, array $b): int => [self::SEVERITY[$a['status']], $a['pct']] <=> [self::SEVERITY[$b['status']], $b['pct']]);
                $dominant = $measured[0];
                $status = $dominant['status'];
                if (count($measured) < count($criteria) && self::SEVERITY[$status] >= self::SEVERITY[self::ST_DUE]) {
                    $status = self::ST_NO_DATA; // nu se poate confirma fara toate criteriile
                    $notes[] = 'Logică ȘI: limitele măsurate sunt atinse, dar unele criterii nu au date.';
                }
            }
            if (count($measured) < count($criteria) && $logic === 'or') {
                $notes[] = 'Unele criterii nu au date: starea se bazează doar pe cele măsurate.';
            }
        }

        $find = static function (string $type) use ($criteria): ?array {
            foreach ($criteria as $c) {
                if ($c['tip'] === $type) {
                    return $c;
                }
            }
            return null;
        };
        $km = $find('km');
        $hours = $find('ore');
        $cal = $find('calendar');

        return [
            'status' => $status,
            'criteria' => $criteria,
            'logica' => $logic,
            'pct' => $dominant['pct'] ?? null,
            'dominant' => $dominant['tip'] ?? null,
            'notes' => $notes,
            'km_ramasi' => $km !== null && $km['measured'] ? $km['remaining'] : null,
            'ore_ramase' => $hours !== null && $hours['measured'] ? $hours['remaining'] : null,
            'zile_ramase' => $cal !== null && $cal['measured'] ? $cal['remaining'] : null,
            'scadenta_data' => $cal['due_date'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function criterion(string $type, int $interval, ?float $used, ?int $warn, string $unit, bool $partial): array
    {
        $warn = self::capWarn($warn ?? (int) round($interval * self::DEFAULT_WARN_RATIO), $interval);
        if ($used === null) {
            return ['tip' => $type, 'interval' => $interval, 'used' => null, 'remaining' => null, 'pct' => null,
                'unit' => $unit, 'measured' => false, 'status' => self::ST_NO_DATA, 'prag' => $warn, 'partial' => false, 'due_date' => null];
        }
        $remaining = $interval - $used;

        return ['tip' => $type, 'interval' => $interval, 'used' => round($used, 1), 'remaining' => round($remaining, 1),
            'pct' => round($used / $interval * 100, 1), 'unit' => $unit, 'measured' => true,
            'status' => self::stateFor($remaining, $warn), 'prag' => $warn, 'partial' => $partial, 'due_date' => null];
    }

    /** @return array<string, mixed> */
    private static function calendarCriterion(int $months, ?string $baseline, ?int $warnDays, int $nowTs): array
    {
        if ($baseline === null || strtotime($baseline) === false) {
            return ['tip' => 'calendar', 'interval' => $months, 'used' => null, 'remaining' => null, 'pct' => null,
                'unit' => 'luni', 'measured' => false, 'status' => self::ST_NO_DATA, 'prag' => $warnDays, 'partial' => false, 'due_date' => null];
        }
        $start = new DateTimeImmutable(substr($baseline, 0, 10));
        $due = self::addMonths($start, $months);
        $today = (new DateTimeImmutable('@' . $nowTs))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
        $totalDays = max(1, (int) $start->diff($due)->days);
        $elapsed = (int) $start->diff($today)->format('%r%a');
        $remaining = (int) $today->diff($due)->format('%r%a');
        $warn = self::capWarn($warnDays ?? max(1, (int) round($totalDays * self::DEFAULT_WARN_RATIO)), $totalDays);

        return ['tip' => 'calendar', 'interval' => $months, 'used' => $elapsed, 'remaining' => $remaining,
            'pct' => round(max(0, $elapsed) / $totalDays * 100, 1), 'unit' => 'zile', 'measured' => true,
            'status' => self::stateFor((float) $remaining, $warn), 'prag' => $warn, 'partial' => false, 'due_date' => $due->format('Y-m-d')];
    }

    /** 15.01.2026 + 12 luni = 15.01.2027; 31.01 + 1 luna = 28/29.02 (fara depasire in luna urmatoare). */
    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $day = (int) $date->format('d');
        $first = $date->modify('first day of this month')->modify('+' . $months . ' months');
        $lastDay = (int) $first->format('t');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min($day, $lastDay));
    }

    /**
     * Pragul de avertizare nu poate depasi jumatate din interval: un prag global (ex. 30 zile)
     * pe un interval scurt (gresare lunara) ar tine operatiunea mereu „se apropie”.
     */
    public static function capWarn(int $warn, int $interval): int
    {
        return max(0, min($warn, (int) floor($interval / 2)));
    }

    /**
     * Starea unui criteriu dupa rest: > prag = in termen; 0..prag = se apropie;
     * depasit cu cel mult pragul = scadent; mai mult = depasit.
     */
    public static function stateFor(float $remaining, int $warn): string
    {
        if ($remaining > $warn) {
            return self::ST_OK;
        }
        if ($remaining > 0) {
            return self::ST_SOON;
        }

        return -$remaining <= max(0, $warn) ? self::ST_DUE : self::ST_OVERDUE;
    }

    /** @param array<int, string> $statuses */
    public static function worst(array $statuses): string
    {
        $worst = self::ST_NOT_CONFIGURED;
        foreach ($statuses as $status) {
            if ((self::SEVERITY[$status] ?? 0) > self::SEVERITY[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public static function isUrgent(string $status): bool
    {
        return in_array($status, [self::ST_DUE, self::ST_OVERDUE], true);
    }

    /** Tipurile de executie care reseteaza referinta operatiunii (completarea / inspectia simpla nu). */
    public static function resetsBaseline(string $executionType): bool
    {
        return in_array($executionType, ['inlocuire', 'gresare', 'curatare', 'reglaj'], true);
    }

    /**
     * Rata de completare (ex. ulei motor): L / 1000 km si L / 100 ore motor, doar din
     * cantitati inregistrate si km / ore masurate.
     *
     * @return array{per_1000km:?float, per_100h:?float}
     */
    public static function topUpRate(float $quantity, ?float $km, ?float $hours): array
    {
        return [
            'per_1000km' => $quantity > 0 && $km !== null && $km >= 100 ? round($quantity / $km * 1000, 2) : null,
            'per_100h' => $quantity > 0 && $hours !== null && $hours >= 10 ? round($quantity / $hours * 100, 2) : null,
        ];
    }

    private static function posInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    public static function fmt(float|int|null $value, int $decimals = 0): string
    {
        return $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    }
}
