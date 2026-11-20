<?php
declare(strict_types=1);

/**
 * Cuplajele cap tractor – semiremorca pe perioade istorice (data_start / data_end din vehicule_cuplaje),
 * nu doar cuplajul activ. Pentru Rentabilitate Operationala: costurile semiremorcii se aloca tractorului
 * cu care a fost cuplata IN perioada analizata. Doar citire.
 */
final class VehicleCouplingPeriodService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Intervalele de cuplare care se suprapun cu [start, end] (date sau datetime), taiate la perioada.
     *
     * @return list<array{tractor_id:int, semiremorca_id:int, de_la:string, pana_la:string, secunde:int}>
     */
    public function couplingsBetween(string $start, string $end, ?int $vehicleId = null): array
    {
        $from = $this->bound($start, false);
        $to = $this->bound($end, true);
        $sql = 'SELECT tractor_id, semiremorca_id, data_start, data_end FROM vehicule_cuplaje
                 WHERE data_start <= :to AND (data_end IS NULL OR data_end >= :from)';
        $params = [':from' => $from, ':to' => $to];
        if ($vehicleId !== null) {
            $sql .= ' AND (tractor_id = :v1 OR semiremorca_id = :v2)';
            $params[':v1'] = $vehicleId;
            $params[':v2'] = $vehicleId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY data_start, id');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $a = max((string) $row['data_start'], $from);
            $b = min($row['data_end'] !== null ? (string) $row['data_end'] : $to, $to);
            if ($b < $a) {
                continue;
            }
            $out[] = [
                'tractor_id' => (int) $row['tractor_id'],
                'semiremorca_id' => (int) $row['semiremorca_id'],
                'de_la' => $a,
                'pana_la' => $b,
                'secunde' => max(0, strtotime($b) - strtotime($a)),
            ];
        }
        return $out;
    }

    /** Tractorul cuplat la semiremorca la un moment dat (null = necuplata). */
    public function tractorAt(int $trailerId, string $moment): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT tractor_id FROM vehicule_cuplaje
              WHERE semiremorca_id = :t AND data_start <= :m1 AND (data_end IS NULL OR data_end > :m2)
              ORDER BY data_start DESC, id DESC LIMIT 1'
        );
        $moment = strlen($moment) === 10 ? $moment . ' 12:00:00' : $moment;
        $stmt->execute([':t' => $trailerId, ':m1' => $moment, ':m2' => $moment]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Ponderea fiecarui tractor in perioada pentru o semiremorca (dupa timpul de cuplare).
     *
     * @return array<int,float> tractor_id => pondere 0..1 (suma <= 1; restul = necuplata)
     */
    public function tractorShares(int $trailerId, string $start, string $end): array
    {
        $total = max(1, strtotime($this->bound($end, true)) - strtotime($this->bound($start, false)));
        $shares = [];
        foreach ($this->couplingsBetween($start, $end, $trailerId) as $row) {
            if ($row['semiremorca_id'] !== $trailerId) {
                continue;
            }
            $shares[$row['tractor_id']] = ($shares[$row['tractor_id']] ?? 0) + $row['secunde'] / $total;
        }
        return array_map(static fn (float $v): float => round(min(1.0, $v), 6), $shares);
    }

    private function bound(string $value, bool $end): string
    {
        return strlen($value) === 10 ? $value . ($end ? ' 23:59:59' : ' 00:00:00') : $value;
    }
}
