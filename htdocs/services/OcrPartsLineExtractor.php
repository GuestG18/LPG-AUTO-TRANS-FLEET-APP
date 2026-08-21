<?php
declare(strict_types=1);

/**
 * Extractor EXPERIMENTAL de linii de articole (piese auto) din textul OCR al
 * unei facturi. Folosit doar de trackerul ?page=ocr_piese.
 *
 * Propune randuri {denumire, cantitate, um, pret_unitar, valoare} pe care
 * utilizatorul le corecteaza intr-un formular editabil inainte de salvare.
 * Nu ghicim agresiv: liniile incerte sunt marcate cu verificat=false.
 */
class OcrPartsLineExtractor
{
    private const NUMBER_PATTERN = '/\d{1,3}(?:\.\d{3})+,\d{1,2}|\d+,\d{1,2}|\d{1,3}(?:,\d{3})+\.\d{1,2}|\d+\.\d{1,2}|\d+/u';

    private const UNIT_TOKENS = ['buc', 'buc.', 'bucata', 'bucati', 'set', 'seturi', 'l', 'litri', 'litru',
        'kg', 'g', 'ml', 'm', 'ma', 'per', 'pereche', 'perechi', 'cutie', 'cutii', 'bax', 'rola', 'role', 'kit'];

    // Linii care sigur NU sunt articole: antete de tabel, totaluri, date fiscale.
    private const SKIP_PATTERNS = [
        '/^(nr\.?\s*)?crt\.?$/iu',
        '/denumirea?\s+(produs|serviciil|articol)/iu',
        '/^u\.?m\.?$/iu',
        '/cantitate/iu',
        '/pret\s*unitar/iu',
        '/^valoarea?\b/iu',
        '/^(sub)?total\b/iu',
        '/total\s+(de\s+plata|tva|general|factura|fara)/iu',
        '/^tva\b|\btva\s*\d{1,2}\s*%/iu',
        '/^(c\.?u\.?i\.?|c\.?i\.?f\.?|cod\s+fiscal|reg\.?\s*com|j\d{1,2}\/)/iu',
        '/^(factura|seria|data|scadent|furnizor|client|cumparator|banca|iban|cont|capital|adresa|telefon|email|delegat|semnatura|stampila|aviz)/iu',
        '/^\d+$/u',
        // Date de contact / sediu / banca strecurate in zona de antet a facturii.
        '/^[:;,]/u',
        '/\b(tel|fax|mobil|e-?mail|www\.)\b/iu',
        '/\bRO\d{2}[A-Z]{4}[A-Z0-9]{4,}\b/u',          // IBAN
        '/\b(jud(et)?\.?|str(ada)?\.?|sos(eaua)?\.?|b(uleva)?rdul|com(una)?\.\s|sat\s|cod\s+postal|sector\s?\d)\b/iu',
        '/\b(sediul?|punct\s+de\s+lucru|capital\s+social|trezoreri|inmatriculat|autorizat)\b/iu',
    ];

    // Valorile monetare de pe o linie de articol trebuie sa fie plauzibile;
    // peste acest prag e aproape sigur un cont bancar / cod / CNP citit gresit.
    private const MAX_PLAUSIBLE_AMOUNT = 10000000.0;

    /**
     * @return array<int,array{denumire:string,cantitate:?float,unitate_masura:?string,pret_unitar:?float,valoare:?float,verificat:bool,linie_sursa:string}>
     */
    public static function extract(string $parsedText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $parsedText) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || mb_strlen($line) < 6 || self::shouldSkip($line)) {
                continue;
            }

            $row = self::parseLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function shouldSkip(string $line): bool
    {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O linie candidata are: o parte de text (>= 3 litere) si cel putin 2
     * valori numerice. Numerele de la coada liniei sunt interpretate, in
     * ordinea tipica a facturilor romanesti: [cantitate] [pret_unitar] [valoare].
     */
    private static function parseLine(string $line): ?array
    {
        if (!preg_match_all(self::NUMBER_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $numbers = $matches[0];
        if (count($numbers) < 2) {
            return null;
        }

        // Denumirea = textul dinaintea primului numar "de coloana". Ignoram
        // numerele din interiorul denumirii (ex. "Filtru ulei W 712/52"):
        // cautam primul numar dupa care linia contine DOAR numere/UM/spatii.
        $tailStartIndex = null;
        for ($i = 0; $i < count($numbers); $i++) {
            $offset = (int) $numbers[$i][1];
            $tail = mb_substr($line, self::byteToCharOffset($line, $offset));
            if (self::isNumericTail($tail)) {
                $tailStartIndex = $i;
                break;
            }
        }

        if ($tailStartIndex === null) {
            return null;
        }

        $charOffset = self::byteToCharOffset($line, (int) $numbers[$tailStartIndex][1]);
        $namePart = trim(mb_substr($line, 0, $charOffset));
        $namePart = trim((string) preg_replace('/^\d{1,3}[.)]?\s*/u', '', $namePart)); // scoate nr. crt din fata

        if (mb_strlen(preg_replace('/[^\p{L}]/u', '', $namePart) ?? '') < 3) {
            return null; // fara text real -> nu e o denumire de articol
        }

        $tailNumbers = array_map(
            static fn (array $match): float => self::parseRomanianNumber((string) $match[0]),
            array_slice($numbers, $tailStartIndex)
        );

        $unit = self::detectUnit($line);

        [$quantity, $unitPrice, $value, $verified] = self::mapNumbers($tailNumbers);

        // Sume implauzibile = aproape sigur nu e un articol (cont bancar, cod
        // postal, serie). Aruncam linia in loc sa propunem gunoi.
        foreach ([$quantity, $unitPrice, $value] as $amount) {
            if ($amount !== null && $amount > self::MAX_PLAUSIBLE_AMOUNT) {
                return null;
            }
        }

        return [
            'denumire' => $namePart,
            'cantitate' => $quantity,
            'unitate_masura' => $unit,
            'pret_unitar' => $unitPrice,
            'valoare' => $value,
            'verificat' => $verified,
            'linie_sursa' => $line,
        ];
    }

    /** Coada liniei contine doar numere, unitati de masura, procente si separatori? */
    private static function isNumericTail(string $tail): bool
    {
        $withoutNumbers = preg_replace(self::NUMBER_PATTERN, ' ', $tail) ?? '';
        $tokens = preg_split('/[\s%|]+/u', mb_strtolower($withoutNumbers), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token, '.,;:()-');
            if ($token !== '' && !in_array($token, self::UNIT_TOKENS, true) && $token !== 'lei' && $token !== 'ron') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param float[] $numbers numerele din coada liniei, in ordine
     * @return array{0:?float,1:?float,2:?float,3:bool} [cantitate, pret_unitar, valoare, verificat]
     */
    private static function mapNumbers(array $numbers): array
    {
        $count = count($numbers);

        if ($count === 0) {
            return [null, null, null, false];
        }

        if ($count === 1) {
            // Un singur numar la coada liniei: il propunem drept valoare,
            // cantitatea si pretul raman de completat manual.
            return [null, null, $numbers[0], false];
        }

        if ($count >= 3) {
            // Tipic: ... cantitate, pret unitar, valoare (uneori urmate de TVA).
            // Incercam intai ultimele 3, apoi variante cu TVA la coada.
            for ($drop = 0; $drop <= min(2, $count - 3); $drop++) {
                $slice = array_slice($numbers, 0, $count - $drop);
                $n = count($slice);
                $qty = $slice[$n - 3];
                $price = $slice[$n - 2];
                $value = $slice[$n - 1];
                if (self::quantityLooksSane($qty) && self::approxEquals($qty * $price, $value)) {
                    return [$qty, $price, $value, true];
                }
            }

            // Nicio combinatie nu se verifica: propunem ultimele 3 dar marcam neverificat.
            return [$numbers[$count - 3], $numbers[$count - 2], $numbers[$count - 1], false];
        }

        // Doua numere: presupunem [pret_unitar, valoare] cu cantitate dedusa,
        // sau [cantitate, valoare].
        [$first, $second] = $numbers;
        if ($first > 0 && self::approxEquals($first, $second)) {
            return [1.0, $first, $second, true];
        }
        if (self::quantityLooksSane($first) && $first > 0) {
            return [$first, round($second / $first, 2), $second, false];
        }

        return [null, $first, $second, false];
    }

    private static function quantityLooksSane(float $qty): bool
    {
        return $qty > 0 && $qty <= 10000;
    }

    private static function approxEquals(float $a, float $b): bool
    {
        return abs($a - $b) <= max(0.05, abs($b) * 0.01);
    }

    private static function detectUnit(string $line): ?string
    {
        if (preg_match('/\b(buc(?:\.|at[ai])?|set(?:uri)?|litri|litru|kg|ml|pereche|perechi|cutii|cutie|bax|rol[ae]|kit)\b/iu', $line, $match)) {
            $unit = mb_strtolower($match[1]);
            return match (true) {
                str_starts_with($unit, 'buc') => 'buc',
                str_starts_with($unit, 'set') => 'set',
                str_starts_with($unit, 'litr') => 'l',
                str_starts_with($unit, 'perech') => 'pereche',
                str_starts_with($unit, 'cutii'), str_starts_with($unit, 'cutie') => 'cutie',
                str_starts_with($unit, 'rol') => 'rola',
                default => $unit,
            };
        }
        if (preg_match('/\b\d+(?:[.,]\d+)?\s*[lL]\b/u', $line)) {
            return 'l';
        }

        return null;
    }

    /** "1.234,56" -> 1234.56; "6,45" -> 6.45; "1,234.56" -> 1234.56; "500" -> 500.0 */
    public static function parseRomanianNumber(string $raw): float
    {
        $raw = trim($raw);

        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $raw)) {
            return (float) str_replace(['.', ','], ['', '.'], $raw);
        }
        if (preg_match('/^\d{1,3}(,\d{3})+\.\d{1,2}$/', $raw)) {
            return (float) str_replace(',', '', $raw);
        }
        if (str_contains($raw, ',')) {
            return (float) str_replace(',', '.', str_replace('.', '', $raw));
        }

        return (float) $raw;
    }

    private static function byteToCharOffset(string $line, int $byteOffset): int
    {
        return mb_strlen(substr($line, 0, $byteOffset));
    }
}
