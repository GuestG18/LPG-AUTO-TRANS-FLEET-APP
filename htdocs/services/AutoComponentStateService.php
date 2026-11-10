<?php
declare(strict_types=1);

/**
 * Starea unei componente din catalogul Reparatii Auto pe un vehicul: piesa din stoc a
 * componentei si uzura (din configurarea pe vehicul + ultima montare). Aceleasi reguli pentru
 * pagina Reparatii Auto (MaintenanceController) si pentru exploratorul de componente din
 * Stoc Anvelope; nimic nu se calculeaza in alta parte.
 */
final class AutoComponentStateService
{
    /** De la aceasta uzura (%) componenta e marcata „atentie” (portocaliu), ca in Reparatii Auto. */
    public const WEAR_ATTENTION_PERCENT = 60;

    /** Cheia de cautare a unui nume (litere si cifre, fara spatii / semne). */
    public static function lookupKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/[^a-z0-9]+/iu', '', $value) ?? '';
    }

    /** Numar scris cu format romanesc ("12.500", "1,5"); 0 cand nu e numeric. */
    public static function numericValue(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        $normalized = str_replace([' ', '.'], '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    /**
     * Piesa din stoc a unei componente: dupa categorie + nume, altfel doar dupa nume.
     *
     * @param array{by_name:array, by_category_name:array} $partIndex MaintenanceModel::getAutoComponentPartIndex()
     */
    public static function findStockPart(array $partIndex, string $categoryName, string $componentName): ?array
    {
        $nameKey = self::lookupKey($componentName);
        if ($nameKey === '') {
            return null;
        }

        $categoryKey = self::lookupKey($categoryName);
        if ($categoryKey !== '' && isset($partIndex['by_category_name'][$categoryKey . '|' . $nameKey])) {
            return $partIndex['by_category_name'][$categoryKey . '|' . $nameKey];
        }

        return $partIndex['by_name'][$nameKey] ?? null;
    }

    /**
     * Uzura (%) a unei componente configurate pe vehicul: km sau luni de la ultima montare a
     * piesei ei, raportat la interval. null = neconfigurata / fara interval / fara date.
     *
     * @param array $component campurile configured, interval, unit, stock_part_id, config_created_at
     * @param array<int,array> $usageByPart MaintenanceModel::getAutoPartUsageForVehicle()
     */
    public static function wearPercent(array $component, int $vehicleKm, array $usageByPart): ?int
    {
        if (empty($component['configured'])) {
            return null;
        }

        $interval = self::numericValue($component['interval'] ?? null);
        if ($interval <= 0) {
            return null;
        }

        $unit = trim((string) ($component['unit'] ?? 'km'));
        $stockPartId = (int) ($component['stock_part_id'] ?? 0);
        $usage = $stockPartId > 0 && isset($usageByPart[$stockPartId]) && is_array($usageByPart[$stockPartId])
            ? $usageByPart[$stockPartId]
            : [];

        // Intervalul mostenit din configuratia generala nu spune cand a fost montata piesa: fara o
        // montare reala nu exista uzura (altfel toata flota ar aparea uzata la 100%).
        if (!empty($component['inherited']) && $usage === []) {
            return null;
        }

        if ($unit === 'km') {
            if ($vehicleKm <= 0) {
                return null;
            }

            $mountedKm = isset($usage['km_montare']) && $usage['km_montare'] !== null
                ? (int) $usage['km_montare']
                : 0;
            $usedKm = $mountedKm > 0 && $vehicleKm >= $mountedKm ? $vehicleKm - $mountedKm : $vehicleKm;

            return max(0, min(100, (int) round(($usedKm / $interval) * 100)));
        }

        if ($unit === 'luni') {
            $start = trim((string) ($usage['data_montare'] ?? ''));
            if ($start === '') {
                $start = trim((string) ($component['config_created_at'] ?? ''));
            }
            if ($start === '') {
                return null;
            }

            try {
                $startDate = new DateTimeImmutable($start);
                $days = max(0, (int) $startDate->diff(new DateTimeImmutable('today'))->format('%a'));
                $months = $days / 30.4375;
                return max(0, min(100, (int) round(($months / $interval) * 100)));
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
