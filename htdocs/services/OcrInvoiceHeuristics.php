<?php
declare(strict_types=1);

/**
 * Parsare EXPERIMENTALA de campuri de factura din textul OCR - doar pentru
 * sandbox-ul ?page=dev_ocr_test. NU este parsare de productie: scopul este sa
 * vedem unde greseste OCR-ul si unde gresesc regulile noastre, separat.
 *
 * Orice camp care nu poate fi identificat cu incredere ramane null
 * ("Nedetectat" in UI) - nu ghicim.
 */
class OcrInvoiceHeuristics
{
    private const MONEY_PATTERN = '/\b\d{1,3}(?:[.\s]\d{3})*,\d{2}\b|\b\d{1,3}(?:,\d{3})*\.\d{2}\b|\b\d+[.,]\d{2}\b/u';
    private const DATE_PATTERN = '/\b(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4}|\d{2})\b|\b(\d{4})-(\d{2})-(\d{2})\b/u';

    /**
     * @return array{fields: array<string,?string>, debug: array<string,mixed>}
     */
    public static function analyze(string $parsedText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $parsedText) ?: [];
        $lines = array_map(static fn (string $line): string => trim($line), $lines);
        $nonEmptyLines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        $dates = self::findDates($parsedText);
        $cuiCandidates = self::findCuiCandidates($parsedText);
        $moneyValues = self::findMoneyValues($parsedText);

        $fields = [
            'numar_factura' => self::detectInvoiceNumber($nonEmptyLines),
            'data_facturii' => self::detectDateNearKeyword($nonEmptyLines, ['data facturii', 'data emiterii', 'data emitere', 'data factura', 'din data'], $dates),
            'data_scadentei' => self::detectDateNearKeyword($nonEmptyLines, ['scadent', 'termen de plata', 'termen plata', 'data platii'], []),
            'furnizor' => self::detectSupplier($nonEmptyLines),
            'cui' => $cuiCandidates[0] ?? null,
            'subtotal' => self::detectAmountNearKeyword($nonEmptyLines, ['subtotal', 'baza de impozitare', 'valoare fara tva', 'total fara tva', 'valoare neta']),
            'tva' => self::detectAmountNearKeyword($nonEmptyLines, ['total tva', 'valoare tva', 'tva']),
            'total' => self::detectTotal($nonEmptyLines),
            'moneda' => self::detectCurrency($parsedText),
        ];

        return [
            'fields' => $fields,
            'debug' => [
                'caractere' => mb_strlen($parsedText),
                'linii' => count($nonEmptyLines),
                'date_gasite' => $dates,
                'cui_gasite' => $cuiCandidates,
                'valori_monetare' => array_slice($moneyValues, 0, 30),
            ],
        ];
    }

    /** @return string[] date valide, in ordinea aparitiei, normalizate d.m.Y */
    private static function findDates(string $text): array
    {
        if (!preg_match_all(self::DATE_PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $found = [];
        foreach ($matches as $match) {
            if (isset($match[4]) && $match[4] !== '') {
                // Format ISO yyyy-mm-dd.
                [$year, $month, $day] = [(int) $match[4], (int) $match[5], (int) $match[6]];
            } else {
                [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
                if ($year < 100) {
                    $year += 2000;
                }
            }

            if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) {
                continue;
            }

            $formatted = sprintf('%02d.%02d.%04d', $day, $month, $year);
            if (!in_array($formatted, $found, true)) {
                $found[] = $formatted;
            }
        }

        return $found;
    }

    /** @return string[] candidati CUI/CIF romanesti, cei cu context de cuvant-cheie primii */
    private static function findCuiCandidates(string $text): array
    {
        $withKeyword = [];
        $bare = [];

        // CUI precedat explicit de CUI/CIF/Cod fiscal - incredere mare.
        if (preg_match_all('/(?:C\.?U\.?I\.?|C\.?I\.?F\.?|Cod\s+fiscal)[\s.:]*?(RO\s?\d{2,10}|\d{2,10})/iu', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $normalized = strtoupper(str_replace(' ', '', $candidate));
                if (!in_array($normalized, $withKeyword, true)) {
                    $withKeyword[] = $normalized;
                }
            }
        }

        // Token-uri RO + cifre fara cuvant-cheie - incredere mai mica, doar pentru debug.
        if (preg_match_all('/\bRO\s?\d{2,10}\b/u', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $normalized = strtoupper(str_replace(' ', '', $candidate));
                if (!in_array($normalized, $withKeyword, true) && !in_array($normalized, $bare, true)) {
                    $bare[] = $normalized;
                }
            }
        }

        return array_merge($withKeyword, $bare);
    }

    /** @return string[] */
    private static function findMoneyValues(string $text): array
    {
        if (!preg_match_all(self::MONEY_PATTERN, $text, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    /** @param string[] $lines */
    private static function detectInvoiceNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            // "Factura (fiscala) nr. XYZ", "Invoice no. XYZ", "Seria ABC nr. 123".
            if (preg_match('/(?:factur[aă]\s*(?:fiscal[aă])?|invoice)\s*(?:nr|num[aă]r|no|#)\.?\s*[:.]?\s*([A-Z0-9][A-Z0-9\-\/ ]{0,19})/iu', $line, $match)) {
                $value = trim($match[1]);
                if ($value !== '') {
                    return $value;
                }
            }
            if (preg_match('/seria?\s+([A-Z]{1,6})\s*,?\s*(?:nr|num[aă]r)\.?\s*[:.]?\s*(\d{1,12})/iu', $line, $match)) {
                return trim($match[1]) . ' ' . trim($match[2]);
            }
        }

        return null;
    }

    /**
     * Cauta o data pe aceeasi linie (sau imediat urmatoare) cu unul din cuvintele-cheie.
     * Pentru data facturii, daca nu exista cuvant-cheie dar documentul are o singura
     * data, o folosim pe aceea; altfel ramane Nedetectat.
     *
     * @param string[] $lines
     * @param string[] $keywords
     * @param string[] $allDates
     */
    private static function detectDateNearKeyword(array $lines, array $keywords, array $allDates): ?string
    {
        $lineCount = count($lines);
        foreach ($lines as $index => $line) {
            $lowered = mb_strtolower($line);
            foreach ($keywords as $keyword) {
                if (!str_contains($lowered, $keyword)) {
                    continue;
                }
                $sameLine = self::findDates($line);
                if ($sameLine !== []) {
                    return $sameLine[0];
                }
                // OCR-ul rupe des eticheta de valoare: verificam si linia urmatoare.
                if ($index + 1 < $lineCount) {
                    $nextLine = self::findDates($lines[$index + 1]);
                    if ($nextLine !== []) {
                        return $nextLine[0];
                    }
                }
            }
        }

        return count($allDates) === 1 ? $allDates[0] : null;
    }

    /** @param string[] $lines */
    private static function detectSupplier(array $lines): ?string
    {
        $lineCount = count($lines);
        foreach ($lines as $index => $line) {
            $lowered = mb_strtolower($line);
            if (preg_match('/^furnizor\b/u', $lowered)) {
                $value = trim((string) preg_replace('/^furnizor\s*[:\-]?\s*/iu', '', $line));
                if ($value !== '' && !self::looksLikeLabel($value)) {
                    return $value;
                }
                // OCR-ul pune des eticheta si valoarea pe linii diferite; sarim
                // peste alte etichete (Adresa, CUI...) pana la un nume real.
                for ($next = $index + 1; $next < min($index + 4, $lineCount); $next++) {
                    if (!self::looksLikeLabel($lines[$next])) {
                        return $lines[$next];
                    }
                }
            }
        }

        // Fallback: prima linie care arata ca un nume de firma romaneasca.
        foreach (array_slice($lines, 0, 12) as $line) {
            if (preg_match('/\b(S\.?R\.?L\.?|S\.?A\.?|P\.?F\.?A\.?|S\.?C\.?S\.?)\b\.?$|^S\.?C\.?\s/iu', $line)
                && mb_strlen($line) >= 5 && mb_strlen($line) <= 80) {
                return $line;
            }
        }

        return null;
    }

    /** Linia e o eticheta de formular (Adresa, CUI, Reg. com...), nu un nume de firma? */
    private static function looksLikeLabel(string $line): bool
    {
        return (bool) preg_match(
            '/^(adresa|sediul?|c\.?u\.?i\.?|c\.?i\.?f\.?|cod\s+fiscal|reg\.?\s*com|nr\.?\s*reg|banca|iban|cont|capital|telefon|tel\b|fax|e-?mail|punct\s+de\s+lucru|judet|localitate)\b/iu',
            trim($line)
        ) || mb_strlen(trim($line)) < 4;
    }

    /**
     * @param string[] $lines
     * @param string[] $keywords cuvinte-cheie in ordinea prioritatii
     */
    private static function detectAmountNearKeyword(array $lines, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            foreach ($lines as $line) {
                $lowered = mb_strtolower($line);
                if (!str_contains($lowered, $keyword)) {
                    continue;
                }
                if (preg_match_all(self::MONEY_PATTERN, $line, $matches) && $matches[0] !== []) {
                    // Ultima valoare de pe linie e de regula totalul coloanei.
                    return end($matches[0]);
                }
            }
        }

        return null;
    }

    /** @param string[] $lines */
    private static function detectTotal(array $lines): ?string
    {
        $priorityKeywords = ['total de plata', 'total plata', 'rest de plata', 'total general', 'total factura'];
        $value = self::detectAmountNearKeyword($lines, $priorityKeywords);
        if ($value !== null) {
            return $value;
        }

        // "Total" generic: luam ULTIMA linie cu total, care pe facturi este de
        // regula totalul final (dupa subtotal si TVA).
        $lastMatch = null;
        foreach ($lines as $line) {
            if (!str_contains(mb_strtolower($line), 'total')) {
                continue;
            }
            if (preg_match_all(self::MONEY_PATTERN, $line, $matches) && $matches[0] !== []) {
                $lastMatch = end($matches[0]);
            }
        }

        return $lastMatch;
    }

    private static function detectCurrency(string $text): ?string
    {
        $lowered = mb_strtolower($text);
        if (preg_match('/\b(ron|lei|leu)\b/u', $lowered)) {
            return 'RON';
        }
        if (preg_match('/\beur(o)?\b|€/u', $lowered)) {
            return 'EUR';
        }
        if (preg_match('/\busd\b|\$/u', $lowered)) {
            return 'USD';
        }

        return null;
    }
}
