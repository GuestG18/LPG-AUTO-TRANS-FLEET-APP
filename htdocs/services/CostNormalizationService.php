<?php
declare(strict_types=1);

/**
 * Primitivele de normalizare la LEI/KM — implementarea 1:1 a specificației
 * de formule §P din ANALIZA_SURSE_COST_KM.md (extrase și validate numeric
 * din workbook-ul original "costuri masini 24.06.2022.xls").
 *
 * Matematică pură, fără DB. Motorul de calcul și testele de validare
 * (scripts/test_cost_operational_km.php) folosesc aceleași funcții, deci
 * dashboard-ul și modelul de referință nu pot diverge.
 *
 * Precizie: nu se rotunjește niciun rezultat intermediar (comportamentul
 * Excel) — rotunjirea se face doar la afișare.
 */
class CostNormalizationService
{
    /** anual lei -> lei/km:  cost_anual / (km_lună × 12)   [FIXE!C31] */
    public static function annualToPerKm(float $annualLei, float $kmPerMonth): float
    {
        if ($kmPerMonth <= 0) {
            throw new InvalidArgumentException('km/lună trebuie să fie > 0');
        }
        return $annualLei / ($kmPerMonth * 12.0);
    }

    /** lunar lei -> lei/km:  cost_lunar / km_lună */
    public static function monthlyToPerKm(float $monthlyLei, float $kmPerMonth): float
    {
        if ($kmPerMonth <= 0) {
            throw new InvalidArgumentException('km/lună trebuie să fie > 0');
        }
        return $monthlyLei / $kmPerMonth;
    }

    /** lei per 100.000 km -> lei/km   [VARIABILE r5-r7: /100000] */
    public static function per100kToPerKm(float $per100k): float
    {
        return $per100k / 100000.0;
    }

    /**
     * Combustibil: preț brut (cu TVA) -> net × litri/km   [VARIABILE r11: B1/(1+TVA) × l/km]
     * Cota TVA este parametru — 19% în Excel-ul din 2022, 21% pe datele CardOil live.
     */
    public static function fuelPerKm(float $grossPricePerLiter, float $vatPercent, float $litersPerKm): float
    {
        if ($vatPercent < 0) {
            throw new InvalidArgumentException('Cota TVA nu poate fi negativă');
        }
        return $grossPricePerLiter / (1.0 + $vatPercent / 100.0) * $litersPerKm;
    }

    /** Scoate TVA dintr-o sumă brută (o singură dată, la stratul de normalizare). */
    public static function netFromGross(float $grossValue, float $vatPercent): float
    {
        if ($vatPercent < 0) {
            throw new InvalidArgumentException('Cota TVA nu poate fi negativă');
        }
        return $grossValue / (1.0 + $vatPercent / 100.0);
    }

    /** Valoare în EUR amortizată -> lei/an   [FIXE r27: C14/3×A1, C11/6×A1, C18/3×A1] */
    public static function amortizedEurToAnnualLei(float $valueEur, float $years, float $eurRon): float
    {
        if ($years <= 0) {
            throw new InvalidArgumentException('Anii de amortizare trebuie să fie > 0');
        }
        return $valueEur / $years * $eurRon;
    }

    /** Diurnă: lei/zi × zile/lună -> lei/km   [VARIABILE r8/km_lună] */
    public static function perDiemPerKm(float $ratePerDay, float $daysPerMonth, float $kmPerMonth): float
    {
        if ($kmPerMonth <= 0) {
            throw new InvalidArgumentException('km/lună trebuie să fie > 0');
        }
        return $ratePerDay * $daysPerMonth / $kmPerMonth;
    }

    /** lei/km -> EUR/km   [FIXE!C32, D36: /A1] */
    public static function leiToEurPerKm(float $leiPerKm, float $eurRon): float
    {
        if ($eurRon <= 0) {
            throw new InvalidArgumentException('Cursul EUR/RON trebuie să fie > 0');
        }
        return $leiPerKm / $eurRon;
    }

    /**
     * Modelul de referință complet pentru o categorie (§P.1–P.4) — folosit de
     * testul de acceptanță pentru a reproduce exact valorile Excel validate.
     *
     * @param array $p parametrii categoriei:
     *   annual_lei_costs: float[]         — costuri anuale simple în lei
     *   phone_monthly: float              — telefon lei/lună
     *   salary_monthly: float             — salariu de bază lei/lună (0 pt. semiremorcă)
     *   salary_multiplier: float          — multiplicator cost angajator (1,75)
     *   management_monthly: float         — cost birou lei/lună
     *   management_vehicles: int          — numărul de vehicule pentru alocare (15)
     *   metrology_eur, adr_kit_eur, depreciation_eur: float — valori în EUR
     *   metrology_years, adr_kit_years, depreciation_years: float
     *   eur_ron: float
     * @return float cost fix ANUAL în lei
     */
    public static function referenceFixedAnnual(array $p): float
    {
        $total = array_sum($p['annual_lei_costs'] ?? []);
        $total += ($p['phone_monthly'] ?? 0.0) * 12.0;
        $total += ($p['salary_monthly'] ?? 0.0) * ($p['salary_multiplier'] ?? 1.0) * 12.0;
        $mgmtVehicles = (int) ($p['management_vehicles'] ?? 0);
        if ($mgmtVehicles > 0) {
            $total += ($p['management_monthly'] ?? 0.0) * 12.0 / $mgmtVehicles;
        }
        $eur = (float) ($p['eur_ron'] ?? 0.0);
        foreach ([['metrology_eur', 'metrology_years'], ['adr_kit_eur', 'adr_kit_years'], ['depreciation_eur', 'depreciation_years']] as [$vk, $yk]) {
            $v = (float) ($p[$vk] ?? 0.0);
            $y = (float) ($p[$yk] ?? 0.0);
            if ($v > 0 && $y > 0) {
                $total += self::amortizedEurToAnnualLei($v, $y, $eur);
            }
        }
        return $total;
    }

    /**
     * Cost variabil lei/km pentru o categorie (§P.3).
     *
     * @param array $p diesel_gross, adblue_gross, vat_percent, diesel_l_per_km,
     *                 adblue_l_per_km, service_100k, repairs_100k, tires_100k,
     *                 per_diem_day, per_diem_days, km_per_month
     */
    public static function referenceVariablePerKm(array $p): float
    {
        $v = self::fuelPerKm((float) ($p['diesel_gross'] ?? 0), (float) ($p['vat_percent'] ?? 0), (float) ($p['diesel_l_per_km'] ?? 0));
        $v += self::fuelPerKm((float) ($p['adblue_gross'] ?? 0), (float) ($p['vat_percent'] ?? 0), (float) ($p['adblue_l_per_km'] ?? 0));
        $v += self::per100kToPerKm((float) ($p['service_100k'] ?? 0));
        $v += self::per100kToPerKm((float) ($p['repairs_100k'] ?? 0));
        $v += self::per100kToPerKm((float) ($p['tires_100k'] ?? 0));
        $perDiemDays = (float) ($p['per_diem_days'] ?? 0);
        if ($perDiemDays > 0) {
            $v += self::perDiemPerKm((float) ($p['per_diem_day'] ?? 0), $perDiemDays, (float) ($p['km_per_month'] ?? 0));
        }
        return $v;
    }
}
