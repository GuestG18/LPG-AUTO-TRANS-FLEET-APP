<?php
declare(strict_types=1);

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Lipeste mai multe PDF-uri intr-unul singur, pagina cu pagina, cu marimea si orientarea
 * fiecarei pagini (FPDI). Folosit la eToll: factura CC-P + anexa TA_10 + desfasuratorul
 * sesiunii devin documentul unic al facturii din pagina Facturi.
 */
final class EtollPdfMerger
{
    /** @param array<int, string> $pdfs continutul fiecarui PDF, in ordinea dorita */
    public static function merge(array $pdfs): string
    {
        $pdfs = array_values(array_filter($pdfs, static fn(string $pdf): bool => $pdf !== ''));
        if ($pdfs === []) {
            throw new InvalidArgumentException('Niciun PDF de lipit.');
        }
        if (count($pdfs) === 1) {
            return $pdfs[0];
        }
        if (!class_exists(Fpdi::class)) {
            throw new RuntimeException('Lipseste setasign/fpdi (ruleaza composer install).');
        }

        $out = new Fpdi();
        $out->SetAutoPageBreak(false);
        foreach ($pdfs as $pdf) {
            $pages = $out->setSourceFile(StreamReader::createByString($pdf));
            for ($page = 1; $page <= $pages; $page++) {
                $template = $out->importPage($page);
                $size = $out->getTemplateSize($template);
                $out->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $out->useTemplate($template);
            }
        }

        return $out->Output('S');
    }
}
