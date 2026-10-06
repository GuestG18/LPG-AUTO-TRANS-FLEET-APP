<?php
declare(strict_types=1);

/**
 * Trimite o factura din Registrul de piese (?page=ocr_piese) in Reparatii Auto.
 *
 * Pentru fiecare articol netrimis inca:
 *  - montat pe vehicul (piesa sau manopera) -> intra intr-o interventie (tabela `mentenanta`)
 *    a vehiculului, una per vehicul si trimitere, cu costul piese / manopera;
 *    piesa cu componenta aleasa primeste si montarea (mentenanta_piese_utilizari) pe
 *    piesa-componenta din stoc (ex. SUS-001 Amortizoare), din care pagina Reparatii Auto
 *    calculeaza uzura componentei (km / data montarii);
 *  - trimis in stoc -> cantitatea se adauga la piesa-componenta din stoc (mentenanta_piese),
 *    cu pretul si furnizorul de pe factura.
 * Articolele care nu se pot trimite (fara vehicul / fara componenta pentru stoc) raman
 * netrimise si se raporteaza; dupa corectare se trimit la urmatoarea apasare.
 */
class OcrPartsMaintenanceSyncService
{
    /** $maintenance se poate da gata construit: constructorul lui ruleaza DDL (COMMIT implicit). */
    public function __construct(private PDO $db, private ?MaintenanceModel $maintenance = null)
    {
    }

    /**
     * @return array{interventii:int, montari:int, stoc:int, trimise:int, sarite:array<int,string>}
     */
    public function send(int $eventId, ?int $userId = null): array
    {
        // Schema Reparatii (DDL) si piesele-componenta din stoc, inainte de tranzactie.
        $maintenance = $this->maintenance ??= new MaintenanceModel($this->db);
        $catalog = new AutoComponentCatalogService();
        $maintenance->syncAutoComponentsToStock($catalog->categories());
        // Si componentele eliminate intre timp: articolele le pot referi inca.
        $components = $catalog->components(true);
        $partIndex = $maintenance->getAutoComponentPartIndex();

        $stmt = $this->db->prepare(
            'SELECT r.id, r.data_interventie, r.furnizor, r.document, f.id AS factura_id, f.numar_factura
             FROM ocr_reparatii r LEFT JOIN ocr_piese_facturi f ON f.id = r.factura_id
             WHERE r.id = :id'
        );
        $stmt->execute([':id' => $eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event === false) {
            throw new InvalidArgumentException('Factura nu mai există.');
        }
        $invoiceDate = (string) ($event['data_interventie'] ?? '');
        if ($invoiceDate === '') {
            throw new InvalidArgumentException('Completează data facturii înainte de trimiterea în Reparații.');
        }

        $itemsStmt = $this->db->prepare(
            'SELECT * FROM ocr_reparatii_articole WHERE reparatie_id = :id AND mentenanta_trimis_la IS NULL ORDER BY id'
        );
        $itemsStmt->execute([':id' => $eventId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $summary = ['interventii' => 0, 'montari' => 0, 'stoc' => 0, 'trimise' => 0, 'sarite' => []];
        $supplier = trim((string) ($event['furnizor'] ?? ''));
        $invoiceLabel = trim('Factura ' . ($event['numar_factura'] ?? '')) . ($supplier !== '' ? ' — ' . $supplier : '');
        $sourceNote = 'Din Registru piese (' . $invoiceLabel . ', registru #' . $eventId . ')';

        $byVehicle = [];
        $toStock = [];
        foreach ($items as $item) {
            $name = (string) $item['denumire'];
            if ($item['tip'] === 'piesa' && $item['destinatie'] === 'stoc') {
                if (empty($item['auto_component_key'])) {
                    $summary['sarite'][] = $name . ': alege componenta, ca să știm în ce piesă din stoc intră.';
                    continue;
                }
                $toStock[] = $item;
                continue;
            }
            if (empty($item['vehicle_id'])) {
                $summary['sarite'][] = $name . ': alege vehiculul.';
                continue;
            }
            $byVehicle[(int) $item['vehicle_id']][] = $item;
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $now = date('Y-m-d H:i:s');
            $markSent = $this->db->prepare(
                'UPDATE ocr_reparatii_articole
                 SET mentenanta_id = :m, mentenanta_utilizare_id = :u, mentenanta_piesa_id = :p,
                     mentenanta_stoc_cant = :q, mentenanta_trimis_la = :now
                 WHERE id = :id'
            );

            foreach ($toStock as $item) {
                $part = $this->stockPartFor($partIndex, $components, (string) $item['auto_component_key']);
                if ($part === null) {
                    $summary['sarite'][] = $item['denumire'] . ': piesa-componentă nu există în stoc.';
                    continue;
                }
                $this->db->prepare(
                    'UPDATE mentenanta_piese
                     SET stoc_curent = stoc_curent + :qty, pret_achizitie = :price,
                         furnizor = COALESCE(:supplier, furnizor), updated_at = :now
                     WHERE id = :id'
                )->execute([
                    ':qty' => (float) $item['cantitate'],
                    ':price' => (float) $item['pret_unitar'],
                    ':supplier' => $supplier !== '' ? mb_substr($supplier, 0, 190) : null,
                    ':now' => $now,
                    ':id' => (int) $part['id'],
                ]);
                $markSent->execute([':m' => null, ':u' => null, ':p' => (int) $part['id'], ':q' => (float) $item['cantitate'], ':now' => $now, ':id' => (int) $item['id']]);
                $summary['stoc']++;
                $summary['trimise']++;
            }

            foreach ($byVehicle as $vehicleId => $vehicleItems) {
                $partsCost = 0.0;
                $laborCost = 0.0;
                $km = null;
                $lines = [];
                $categories = [];
                $maintenanceOnly = true;
                foreach ($vehicleItems as $item) {
                    $lineCost = round((float) $item['cantitate'] * (float) $item['pret_unitar'], 2);
                    if ($item['tip'] === 'manopera') {
                        $laborCost += $lineCost;
                    } else {
                        $partsCost += $lineCost;
                    }
                    if ($item['km_bord'] !== null) {
                        $km = max((int) $km, (int) $item['km_bord']);
                    }
                    $component = $components[(string) ($item['auto_component_key'] ?? '')] ?? null;
                    if ($component !== null) {
                        $categories[$component['category']] = ($categories[$component['category']] ?? 0) + 1;
                    }
                    $lines[] = $item['denumire'] . ' × ' . rtrim(rtrim(number_format((float) $item['cantitate'], 2, '.', ''), '0'), '.')
                        . ($component !== null ? ' (' . $component['category'] . ' › ' . $component['name'] . ')' : '');
                    if ($item['tip_lucrare'] !== 'intretinere') {
                        $maintenanceOnly = false;
                    }
                }
                arsort($categories);

                $this->db->prepare(
                    "INSERT INTO mentenanta
                        (vehicle_id, tip_interventie, record_type, centru_cost, descriere, status_interventie,
                         data_interventie, km_interventie, cost, cost_manopera, cost_piese,
                         atelier, furnizor_piesa, piese_utilizate, observatii, created_at, updated_at)
                     VALUES
                        (:vehicle_id, :tip, :record_type, :centru, :descriere, 'finalizata',
                         :data, :km, :cost, :manopera, :piese,
                         :atelier, :furnizor, :piese_utilizate, :observatii, :created_at, :updated_at)"
                )->execute([
                    ':vehicle_id' => $vehicleId,
                    ':tip' => mb_substr($invoiceLabel !== 'Factura' ? $invoiceLabel : 'Piese și manoperă din factură', 0, 190),
                    // Revizii / consumabile = intretinere; restul = reparatie (apare si la facturile de reparatii).
                    ':record_type' => $maintenanceOnly ? 'intretinere' : 'reparatie',
                    ':centru' => $categories !== [] ? (string) array_key_first($categories) : 'Altele',
                    ':descriere' => mb_substr(implode('; ', $lines), 0, 2000),
                    ':data' => $invoiceDate,
                    ':km' => $km,
                    ':cost' => round($partsCost + $laborCost, 2),
                    ':manopera' => round($laborCost, 2),
                    ':piese' => round($partsCost, 2),
                    ':atelier' => $supplier !== '' ? mb_substr($supplier, 0, 190) : null,
                    ':furnizor' => $supplier !== '' ? mb_substr($supplier, 0, 190) : null,
                    ':piese_utilizate' => mb_substr(implode(', ', array_map(
                        static fn (array $i): string => (string) $i['denumire'],
                        array_filter($vehicleItems, static fn (array $i): bool => $i['tip'] === 'piesa')
                    )), 0, 2000) ?: null,
                    ':observatii' => $sourceNote,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $maintenanceId = (int) $this->db->lastInsertId();
                $summary['interventii']++;

                foreach ($vehicleItems as $item) {
                    $usageId = null;
                    $partId = null;
                    if ($item['tip'] === 'piesa' && !empty($item['auto_component_key'])) {
                        $part = $this->stockPartFor($partIndex, $components, (string) $item['auto_component_key']);
                        if ($part !== null) {
                            $partId = (int) $part['id'];
                            // Montare directa de pe factura: stocul nu scade (piesa n-a trecut prin depozit).
                            $this->db->prepare(
                                'INSERT INTO mentenanta_piese_utilizari
                                    (part_id, maintenance_id, scheduled_intervention_id, vehicle_id, cantitate,
                                     cost_unitar, data_montare, km_montare, montata_de, observatii, direct_mount, created_at)
                                 VALUES (:part, :maintenance, NULL, :vehicle, :qty, :cost, :data, :km, :by, :notes, 1, :created_at)'
                            )->execute([
                                ':part' => $partId,
                                ':maintenance' => $maintenanceId,
                                ':vehicle' => $vehicleId,
                                ':qty' => (float) $item['cantitate'],
                                ':cost' => (float) $item['pret_unitar'],
                                ':data' => $item['data_referinta'] ?: $invoiceDate,
                                ':km' => $item['km_bord'] !== null ? (int) $item['km_bord'] : null,
                                ':by' => $supplier !== '' ? mb_substr($supplier, 0, 120) : null,
                                ':notes' => mb_substr($item['denumire'] . ' — ' . $sourceNote, 0, 500),
                                ':created_at' => $now,
                            ]);
                            $usageId = (int) $this->db->lastInsertId();
                            $summary['montari']++;
                        }
                    }
                    $markSent->execute([':m' => $maintenanceId, ':u' => $usageId, ':p' => $partId, ':q' => null, ':now' => $now, ':id' => (int) $item['id']]);
                    $summary['trimise']++;
                }
            }

            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $summary;
    }

    /**
     * Piesa din stoc care reprezinta componenta (creata de Reparatii Auto din catalog),
     * gasita ca acolo: categorie + denumire, apoi doar denumire.
     *
     * @param array{by_name:array<string,array>,by_category_name:array<string,array>} $partIndex
     * @param array<string,array<string,mixed>> $components
     * @return array<string,mixed>|null
     */
    private function stockPartFor(array $partIndex, array $components, string $componentKey): ?array
    {
        $component = $components[$componentKey] ?? null;
        if ($component === null) {
            return null;
        }
        $key = static fn (string $value): string => preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
        $nameKey = $key((string) $component['name']);

        return $partIndex['by_category_name'][$key((string) $component['category']) . '|' . $nameKey]
            ?? $partIndex['by_name'][$nameKey]
            ?? null;
    }
}
