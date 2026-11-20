<?php
declare(strict_types=1);

/**
 * Natura costului (clasificare operationala) si tratamentul contabil — doua concepte separate.
 *
 * - natura_cost: o stabileste operatorul (reparatie / mentenanta / investitie / echipare_initiala).
 * - tratament_contabil: il confirma contabilitatea (de_confirmat / capitalizabil / cheltuiala).
 *   O investitie NU devine automat capitalizabila: porneste „de_confirmat”.
 * - record_type (intretinere / reparatie) ramane pentru compatibilitate; investitiile se scriu
 *   in continuare ca „reparatie”, natura le deosebeste.
 */
final class CostNature
{
    public const NATURES = [
        'reparatie' => 'Reparație',
        'mentenanta' => 'Mentenanță',
        'investitie' => 'Investiție',
        'echipare_initiala' => 'Echipare inițială',
    ];

    public const TREATMENTS = [
        'de_confirmat' => 'De confirmat',
        'capitalizabil' => 'Capitalizabil',
        'cheltuiala' => 'Cheltuială',
    ];

    /** Naturile care pot deveni capitalizabile (dupa confirmarea contabilitatii). */
    public const CAPITAL_NATURES = ['investitie', 'echipare_initiala'];

    /** Sursa piesei la o lucrare / montare. */
    public const PART_SOURCES = [
        'stoc' => 'Din stoc',
        'achizitie_directa' => 'Achiziție directă',
        'atelier_extern' => 'Furnizată de atelierul extern',
        'transfer_vehicul' => 'Transfer de pe alt vehicul',
    ];

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        return isset(self::NATURES[$value]) ? $value : null;
    }

    public static function normalizeTreatment(?string $value): ?string
    {
        $value = trim((string) $value);
        return isset(self::TREATMENTS[$value]) ? $value : null;
    }

    /** Registrul OCR: reparatie / inlocuire -> reparatie, intretinere -> mentenanta, imbunatatire -> investitie. */
    public static function fromOcrWorkType(?string $workType): string
    {
        return match (trim((string) $workType)) {
            'intretinere' => 'mentenanta',
            'imbunatatire' => 'investitie',
            default => 'reparatie',
        };
    }

    public static function fromRecordType(?string $recordType): string
    {
        return trim((string) $recordType) === 'intretinere' ? 'mentenanta' : 'reparatie';
    }

    /** record_type compatibil: doar mentenanta -> intretinere, restul -> reparatie (ca pana acum). */
    public static function recordTypeFor(string $nature): string
    {
        return $nature === 'mentenanta' ? 'intretinere' : 'reparatie';
    }

    /**
     * Tratamentul initial. Reparatia si mentenanta sunt cheltuiala prin definitie; investitia si
     * echiparea initiala raman „de_confirmat” pana decide contabilitatea.
     */
    public static function defaultTreatment(string $nature): string
    {
        return in_array($nature, self::CAPITAL_NATURES, true) ? 'de_confirmat' : 'cheltuiala';
    }

    public static function isCapitalNature(?string $nature): bool
    {
        return in_array((string) $nature, self::CAPITAL_NATURES, true);
    }

    /** Expresie SQL pentru natura efectiva a unui rand din `mentenanta` (NULL -> derivata din record_type). */
    public static function sqlEffectiveNature(string $alias = 'm'): string
    {
        return "COALESCE($alias.natura_cost, IF($alias.record_type = 'intretinere', 'mentenanta', 'reparatie'))";
    }

    /** Expresie SQL: randul este o investitie capitalizabila CONFIRMATA (exclusa din costul operational). */
    public static function sqlIsCapitalized(string $alias = 'm'): string
    {
        return "($alias.natura_cost IN ('investitie','echipare_initiala') AND $alias.tratament_contabil = 'capitalizabil')";
    }
}
