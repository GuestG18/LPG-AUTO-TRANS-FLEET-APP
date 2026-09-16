<?php
declare(strict_types=1);

/**
 * Regulile de "cursa completa" din Dispecer curse, intr-un singur loc: le folosesc
 * atat panoul "curse cu informatii lipsa" cat si urmarirea activitatii operatorilor
 * (o cursa se considera inchisa doar cand lista de informatii lipsa este goala).
 */
final class RaceCompletenessService
{
    /**
     * Detecteaza informatiile lipsa ale unei curse, per tip de transport.
     *
     * Reguli NULL/0: doar NULL / sirul gol inseamna "lipsa"; un 0 numeric legitim NU este
     * raportat ca lipsa. Exceptie: cantitatea 0 pe tipurile facturate la tona este o
     * problema de validare (salvarea ar fi respinsa) si este raportata drept critica.
     *
     * Fiecare element: field, label, severity (critical|important|minor), explanation,
     * focus (cheia deep-link pentru pagina de editare; '' = fara camp dedicat).
     */
    public static function missingInformation(array $race): array
    {
        $isMissing = static function ($value): bool {
            return $value === null || trim((string) $value) === '';
        };
        $isZero = static function ($value): bool {
            $text = trim((string) $value);
            return $text !== '' && is_numeric($text) && abs((float) $text) < 0.005;
        };

        $type = (string) ($race['tip_transport'] ?? '');
        $items = [];
        $add = static function (string $field, string $label, string $severity, string $explanation, string $focus = '') use (&$items): void {
            $items[] = [
                'field' => $field,
                'label' => $label,
                'severity' => $severity,
                'explanation' => $explanation,
                'focus' => $focus,
            ];
        };

        // --- Comune tuturor tipurilor: resurse, cronometrare si documente ---
        $driverId = (int) ($race['driver_id'] ?? 0);
        if ($driverId <= 0 && trim((string) ($race['sofer_nume'] ?? '')) === '') {
            $add('driver_id', 'Șofer neasignat', 'critical', 'Cursa nu are șofer — asignează șoferul pentru pontaj și raportare.', 'driver');
        }
        $beneficiaryId = (int) ($race['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0 && trim((string) ($race['beneficiar_nume'] ?? '')) === '') {
            $add('beneficiar_id', 'Beneficiar transport', 'critical', 'Cursa nu are beneficiar — fără el nu se poate factura.', 'beneficiary');
        }
        if ($isMissing($race['tip_marfa'] ?? null)) {
            $add('tip_marfa', 'Tip marfă', 'important', 'Selectează tipul de marfă pentru documentele de transport.', 'goods');
        }
        if ($isMissing($race['ora_inceput'] ?? null)) {
            $add('ora_inceput', 'Ora de început', 'critical', 'Completează ora de început — fără ea nu se poate seta ora finală și durata cursei.', 'start_time');
        }
        if ($isMissing($race['ora_sfarsit'] ?? null)) {
            $add('ora_sfarsit', 'Ora finală a cursei', 'critical', 'Setează ora de finalizare pentru raportare corectă.', 'end_time');
        }
        if ($isMissing($race['data_incarcare'] ?? null)) {
            $add('data_incarcare', 'Data încărcare', 'minor', 'Selectează data încărcării pentru completarea documentelor.', 'loading_date');
        }
        if ($type !== 'compresor' && $isMissing($race['loc_incarcare_id'] ?? null)) {
            $add('loc_incarcare_id', 'Loc încărcare', 'important', 'Selectează locul de încărcare — este folosit la potrivirea rutelor și în documente.', 'loading_location');
        }

        $hasPricingGap = false;

        // --- Reguli per tip de transport ---
        if ($type === 'primar' || $type === 'primar_tona') {
            if ($isMissing($race['km_totali'] ?? null)) {
                $add('km_totali', 'Km efectuați', 'important', 'Completează km efectuați — sunt folosiți la sincronizarea bordului și la mentenanță.', 'km_total');
            }
            if ($type === 'primar' && ($isMissing($race['km_cursa'] ?? null) || $isZero($race['km_cursa'] ?? null))) {
                $add('km_cursa', 'Km agreați (tarifare)', 'critical', 'Km agreați lipsesc — totalul de facturare nu se poate calcula.', 'km');
                $hasPricingGap = true;
            }
            if ($type === 'primar_tona') {
                $quantity = $race['cantitate_incarcata'] ?? null;
                if ($isMissing($quantity)) {
                    $add('cantitate_incarcata', 'Cantitate încărcată', 'critical', 'Cantitatea încărcată este necesară pentru facturarea pe tone.', 'quantity');
                    $hasPricingGap = true;
                } elseif ($isZero($quantity)) {
                    $add('cantitate_incarcata', 'Cantitate încărcată (valoare invalidă: 0)', 'critical', 'Valoarea 0 nu este acceptată la facturarea pe tone — corectează cantitatea.', 'quantity');
                    $hasPricingGap = true;
                }
            }
            if ($type === 'primar' && $isMissing($race['cantitate_incarcata'] ?? null)) {
                $add('cantitate_incarcata', 'Cantitate încărcată', 'minor', 'Completează cantitatea încărcată pentru raportarea operațională.', 'quantity');
            }
            if ($isMissing($race['zona_distributie_id'] ?? null)) {
                $add('zona_distributie_id', 'Loc descărcare', 'important', 'Selectează locul de descărcare — perechea Loc ↔ Zonă valideză ruta din Setări Primar.', 'distribution_zone');
            }
        } elseif ($type === 'distributie' || $type === 'primar_distributie') {
            $quantity = $race['cantitate_incarcata'] ?? null;
            if ($isMissing($quantity)) {
                $add('cantitate_incarcata', 'Cantitate încărcată', 'critical', 'Cantitatea încărcată este necesară pentru facturarea distribuției.', 'quantity');
                $hasPricingGap = true;
            } elseif ($isZero($quantity)) {
                $add('cantitate_incarcata', 'Cantitate încărcată (valoare invalidă: 0)', 'critical', 'Valoarea 0 nu este acceptată la facturarea pe tone — corectează cantitatea.', 'quantity');
                $hasPricingGap = true;
            }
            if ($isMissing($race['zona_distributie_id'] ?? null)) {
                $add('zona_distributie_id', 'Zona distribuție', 'important', 'Selectează zona de distribuție — determină tariful aplicat.', 'distribution_zone');
                $hasPricingGap = true;
            }
            if ($isMissing($race['nr_clienti'] ?? null)) {
                $add('nr_clienti', 'Nr. clienți', 'important', 'Completează numărul de clienți pentru raportarea distribuției.', 'clients');
            }
            if ($type === 'distributie' && $isMissing($race['km_cursa'] ?? null)) {
                $add('km_cursa', 'Km efectuați', 'important', 'Completează km efectuați — pot intra în componenta de tarif pe km.', 'km');
            }
            if ($type === 'primar_distributie') {
                if ($isMissing($race['km_totali'] ?? null)) {
                    $add('km_totali', 'Km efectuați (totali)', 'important', 'Fără km efectuați, Cost/km distribuție și Cost/km mixt rămân 0.', 'km_total');
                    if ($isZero($race['cost_km_mixt'] ?? null)) {
                        $add('cost_km_mixt', 'Cost/km mixt = 0', 'important', 'Valoarea 0 este cauzată de lipsa km efectuați — se corectează completându-i.', 'km_total');
                    }
                }
            }
        } elseif ($type === 'compresor') {
            $compressorLocationDefinitions = [
                ['loc_plecare', 'Loc plecare', 'departure_location'],
                ['loc_aspirare', 'Loc aspirare', 'suction_location'],
                ['loc_livrare', 'Loc livrare', 'delivery_location'],
                ['loc_livrare_cursa', 'Loc închidere cursă', 'closing_location'],
            ];
            foreach ($compressorLocationDefinitions as [$locationField, $locationLabel, $locationFocus]) {
                if ($isMissing($race[$locationField] ?? null)) {
                    $add($locationField, $locationLabel, 'important', 'Completează ' . mb_strtolower($locationLabel) . ' pentru traseul cursei de compresor.', $locationFocus);
                }
            }
            $metricDefinitions = [
                ['ore_aspirare', 'Ore aspirare', 'Completează orele de aspirare — componentă de facturare și de mentenanță.', 'aspiration_hours'],
                ['km_dislocare', 'Km efectuați (dislocare)', 'Completează km de dislocare — componentă de facturare.', 'displacement_km'],
                ['tona_livrata', 'Cantitate livrată', 'Completează cantitatea livrată — componentă de facturare.', 'delivered_quantity'],
                ['tona_aspirata_lichida', 'Tona lichidă aspirată', 'Completează tona lichidă aspirată pentru facturare și raportare.', 'liquid_tons'],
                ['tona_aspirata_gazoasa', 'Tona gazoasă aspirată', 'Completează tona gazoasă aspirată pentru facturare și raportare.', 'gas_tons'],
            ];
            foreach ($metricDefinitions as [$field, $label, $explanation, $focus]) {
                if ($isMissing($race[$field] ?? null)) {
                    $add($field, $label, 'important', $explanation, $focus);
                    $hasPricingGap = true;
                }
            }
            if ($isMissing($race['cantitate_prelevata'] ?? null)) {
                $add('cantitate_prelevata', 'Cantitate prelevată', 'minor', 'Cantitatea prelevată lipsește din raportarea operațională.', '');
            }
        }

        // --- Simptom financiar: total 0 cauzat de date de tarifare lipsa ---
        if ($hasPricingGap && $isZero($race['total_facturare'] ?? null)) {
            $add('total_facturare', 'Total facturare = 0,00 lei', 'critical', 'Totalul este 0 pentru că lipsesc date de tarifare — completează câmpurile marcate.', '');
        }

        // --- Cheltuieli neasociate (pastreaza acoperirea popup-ului existent) ---
        $expenseCount = (int) ($race['expense_count'] ?? 0);
        $expenseStatus = (string) ($race['cheltuieli_status'] ?? 'pending');
        if ($expenseCount === 0 && $expenseStatus !== 'not_applicable') {
            $add('cheltuieli', 'Cheltuieli neasociate', 'minor', 'Adaugă cheltuielile cursei sau marchează-le ca nefiind aplicabile.', 'expenses');
        }

        return $items;
    }

    public static function isComplete(array $race): bool
    {
        return self::missingInformation($race) === [];
    }
}
