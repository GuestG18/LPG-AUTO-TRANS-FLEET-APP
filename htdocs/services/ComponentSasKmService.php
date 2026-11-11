<?php
declare(strict_types=1);

/**
 * Km bord al unui vehicul la un moment dat, din SAS Fleet: pentru campul „Km bord” din formularul de
 * montare a unei componente (inspectorul din schema vehiculului).
 *
 * Sursa: raportul web SAS „Foaie de parcurs cu distanțe din CAN” (SasCanKmService), care are pe
 * fiecare tronson atat odometrul CAN (bordul) cat si indexul GPS. CAN are prioritate (e cel apropiat
 * de bord); vehiculele fara CAN folosesc indexul GPS (necalibrat pe bord).
 *
 * La un moment in care vehiculul stationa, valoarea e exacta (indexul de la ultima oprire). In mers,
 * odometrul e intre pornirea si oprirea tronsonului: se intoarce valoarea de la pornire, cu intervalul.
 * Semiremorcile nu au odometru in SAS.
 */
class ComponentSasKmService
{
    /** Cat de departe in urma se cauta ultima oprire inaintea momentului. */
    private const LOOKBACK_DAYS = 7;

    private PDO $db;
    private ?SasCanKmService $can;

    public function __construct(PDO $db, ?SasCanKmService $can = null)
    {
        $this->db = $db;
        $this->can = $can;
    }

    /**
     * @return array{ok: bool, km?: int, source?: string, source_label?: string, moving?: bool, range?: ?array{0:int,1:int}, note?: string, message?: string}
     */
    public function kmAt(int $vehicleId, string $moment): array
    {
        $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', trim($moment));
        if ($at === false) {
            return ['ok' => false, 'message' => 'Alege data și ora montării.'];
        }
        $now = new DateTimeImmutable();
        if ($at > $now) {
            return ['ok' => false, 'message' => 'Momentul montării este în viitor.'];
        }

        $stmt = $this->db->prepare('SELECT tip_vehicul FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);
        $type = $stmt->fetchColumn();
        if ($type === false) {
            return ['ok' => false, 'message' => 'Vehiculul nu există.'];
        }
        $carId = TrailerSasKmService::sasCarIds($this->db)[$vehicleId] ?? 0;
        if ($carId <= 0) {
            return ['ok' => false, 'message' => str_starts_with((string) $type, 'semiremorca')
                ? 'Semiremorca nu are odometru în SAS: completează km manual.'
                : 'Vehiculul nu este legat la SAS Fleet: completează km manual.'];
        }

        $from = $at->modify('-' . self::LOOKBACK_DAYS . ' days');
        $to = min($at->modify('+1 day'), $now);
        $report = ($this->can ?? new SasCanKmService())->travelSheet($carId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'));

        $result = self::pick($report['segments'] ?? [], $at->format('Y-m-d H:i:s'));
        if ($result === null) {
            return ['ok' => false, 'message' => 'SAS nu are deplasări în jurul acestui moment (' . self::LOOKBACK_DAYS . ' zile înainte).'];
        }

        return ['ok' => true] + $result;
    }

    /**
     * Odometrul la $moment din tronsoanele raportului (sortate cronologic). Functie pura.
     *
     * @param list<array<string, mixed>> $segments
     * @return array{km: int, source: string, source_label: string, moving: bool, range: ?array{0:int,1:int}, note: string}|null
     */
    public static function pick(array $segments, string $moment): ?array
    {
        $before = null;
        $during = null;
        $after = null;
        foreach ($segments as $segment) {
            $start = (string) ($segment['start'] ?? '');
            $end = (string) ($segment['end'] ?? '');
            if ($start === '' || $end === '') {
                continue;
            }
            if ($end <= $moment) {
                $before = $segment;
            } elseif ($start <= $moment) {
                $during = $segment;
            } elseif ($after === null) {
                $after = $segment;
            }
        }

        foreach (['can', 'gps'] as $source) {
            $startOf = static function (array $segment) use ($source): ?float {
                if ($source === 'can') {
                    return self::positive($segment['can_index_start_km'] ?? null);
                }
                $end = self::positive($segment['gps_index_end_km'] ?? null);
                return $end !== null && isset($segment['gps_km']) ? $end - (float) $segment['gps_km'] : null;
            };
            $endOf = static fn (array $segment): ?float => self::positive($segment[$source === 'can' ? 'can_index_end_km' : 'gps_index_end_km'] ?? null);
            $label = $source === 'can' ? 'SAS · odometru CAN (bord)' : 'SAS · index GPS (fără CAN)';

            if ($during !== null) {
                $low = $startOf($during);
                $high = $endOf($during);
                if ($low !== null) {
                    return [
                        'km' => (int) round($low),
                        'source' => $source,
                        'source_label' => $label,
                        'moving' => true,
                        'range' => $high !== null ? [(int) round($low), (int) round($high)] : null,
                        'note' => 'Vehiculul era în mers (' . substr((string) $during['start'], 11, 5) . '–' . substr((string) $during['end'], 11, 5) . '): km de la pornire' . ($high !== null ? ', la oprire ' . number_format($high, 0, ',', '.') . ' km' : '') . '.',
                    ];
                }
            }
            if ($before !== null && ($km = $endOf($before)) !== null) {
                return ['km' => (int) round($km), 'source' => $source, 'source_label' => $label, 'moving' => false, 'range' => null,
                    'note' => 'Staționa din ' . date('d.m.Y H:i', (int) strtotime((string) $before['end'])) . '.'];
            }
            if ($after !== null && ($km = $startOf($after)) !== null) {
                return ['km' => (int) round($km), 'source' => $source, 'source_label' => $label, 'moving' => false, 'range' => null,
                    'note' => 'Staționa până la ' . date('d.m.Y H:i', (int) strtotime((string) $after['start'])) . '.'];
            }
        }

        return null;
    }

    private static function positive(mixed $value): ?float
    {
        return $value !== null && (float) $value > 0 ? (float) $value : null;
    }
}
