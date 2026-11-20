<?php
declare(strict_types=1);

require_once __DIR__ . '/FinancialFoundationSchema.php';

/**
 * Contract de citire pentru „Istoric Financiar Vehicul” (si sursa pentru „Rentabilitate Operationala”).
 *
 * Reguli de sursa (vezi IMPLEMENTARE_FINANCIARA_FLEET.md, „Surse de adevar”):
 * - `mentenanta` = singurul registru al costurilor de lucrari pe vehicul (reparatie / mentenanta / investitie),
 *   cu natura costului si tratamentul contabil. Randurile „Anvelopa - %” (cost 0) se ignora.
 * - Stocul este inventar, nu cheltuiala: receptiile NU apar; consumul apare prin `mentenanta`.
 *   Montarile fara rand de mentenanta (schema vehiculului, Revizii V2 — piese) apar din
 *   `mentenanta_piese_utilizari` DOAR daca maintenance_id IS NULL si sursa nu este transfer.
 * - Revizii V2: costul lucrarii (revizii_v2_lucrari.cost_total) — fara piesele deja numarate ca utilizari.
 * - Cheltuieli: suma alocata vehiculului, exceptand cele preluate in dosarul de achizitie.
 * - Achizitie: liniile dosarului (neincluse in alte linii), pe vehiculul legat.
 * - Anvelope: pretul de achizitie, o singura data per anvelopa (prima alocare pe vehicul).
 * - Leasing: ratele (finantare, nu cost operational).
 * - NU se citesc niciodata: supplier_invoices / invoice_vehicle_repairs / invoice_repair_parts (oglinda
 *   derivata din mentenanta) si ocr_piese_registru (registru paralel).
 * Fiecare rand are (sursa, sursa_id) unic; rezultatul se deduplica pe aceasta cheie.
 */
final class VehicleFinancialHistoryService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @return list<array{sursa:string,sursa_id:int,data:?string,categorie:string,descriere:string,suma:float,
     *   natura_cost:?string,tratament_contabil:?string,proiect_investitie_id:?int,clasa:string}>
     */
    public function forVehicle(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        if (!FinancialFoundationSchema::isReady($this->db)) {
            throw new RuntimeException('Schema financiară nu este aplicată.');
        }
        $from ??= '1900-01-01';
        $to ??= '2999-12-31';
        $rows = [];
        $push = static function (array $row) use (&$rows): void {
            $key = $row['sursa'] . ':' . $row['sursa_id'];
            if (!isset($rows[$key])) {
                $rows[$key] = $row + ['natura_cost' => null, 'tratament_contabil' => null, 'proiect_investitie_id' => null];
            }
        };

        // 1. Lucrari (registrul de costuri pe vehicul)
        foreach ($this->fetch(
            "SELECT id, data_interventie, tip_interventie, cost, natura_cost, tratament_contabil, proiect_investitie_id, sursa_tip
               FROM mentenanta
              WHERE vehicle_id = :v AND data_interventie BETWEEN :f AND :t
                AND tip_interventie NOT LIKE 'Anvelopa - %'
                AND (status_interventie IS NULL OR status_interventie <> 'anulata')",
            $vehicleId, $from, $to
        ) as $m) {
            $nature = $m['natura_cost'] ?? 'reparatie';
            $push([
                'sursa' => 'mentenanta', 'sursa_id' => (int) $m['id'], 'data' => $m['data_interventie'],
                'categorie' => $nature, 'descriere' => (string) $m['tip_interventie'], 'suma' => (float) $m['cost'],
                'natura_cost' => $m['natura_cost'], 'tratament_contabil' => $m['tratament_contabil'],
                'proiect_investitie_id' => $m['proiect_investitie_id'] !== null ? (int) $m['proiect_investitie_id'] : null,
                'clasa' => ($m['tratament_contabil'] === 'capitalizabil' && in_array($nature, ['investitie', 'echipare_initiala'], true)) ? 'capital' : 'operational',
            ]);
        }

        // 2. Montari fara rand de mentenanta (altfel costul e deja in mentenanta)
        foreach ($this->fetch(
            "SELECT u.id, u.data_montare, u.cantitate, u.cost_unitar, u.valoare_neverificata, p.denumire
               FROM mentenanta_piese_utilizari u JOIN mentenanta_piese p ON p.id = u.part_id
              WHERE u.vehicle_id = :v AND u.data_montare BETWEEN :f AND :t
                AND u.maintenance_id IS NULL
                AND (u.sursa_piesa IS NULL OR u.sursa_piesa <> 'transfer_vehicul')
                AND NOT EXISTS (SELECT 1 FROM revizii_v2_lucrari_operatii o WHERE o.piesa_utilizare_id = u.id)",
            $vehicleId, $from, $to
        ) as $u) {
            $push([
                'sursa' => 'piesa_montata', 'sursa_id' => (int) $u['id'], 'data' => $u['data_montare'],
                'categorie' => 'piese', 'descriere' => (string) $u['denumire'] . ((int) $u['valoare_neverificata'] === 1 ? ' (valoare neverificată)' : ''),
                'suma' => round((float) $u['cantitate'] * (float) $u['cost_unitar'], 2), 'clasa' => 'operational',
            ]);
        }

        // 3. Revizii V2
        foreach ($this->fetch(
            "SELECT id, DATE(data_executie) AS d, tip, cost_total FROM revizii_v2_lucrari
              WHERE vehicle_id = :v AND status IN ('finalizata','partiala') AND DATE(data_executie) BETWEEN :f AND :t",
            $vehicleId, $from, $to
        ) as $r) {
            $push([
                'sursa' => 'revizie_v2', 'sursa_id' => (int) $r['id'], 'data' => $r['d'], 'categorie' => 'mentenanta',
                'descriere' => 'Revizie ' . $r['tip'], 'suma' => (float) $r['cost_total'], 'natura_cost' => 'mentenanta', 'clasa' => 'operational',
            ]);
        }

        // 4. Cheltuieli alocate vehiculului (fara cele preluate in dosarul de achizitie)
        foreach ($this->fetch(
            "SELECT a.id, c.id AS cheltuiala_id, c.data_cheltuiala, c.descriere, t.nume AS tip, a.suma, c.proiect_investitie_id, c.natura_cost
               FROM cheltuieli_alocari a
               JOIN cheltuieli c ON c.id = a.cheltuiala_id
               LEFT JOIN cheltuieli_tipuri t ON t.id = c.tip_id
              WHERE a.tip_alocare = 'vehicul' AND a.vehicul_id = :v AND c.data_cheltuiala BETWEEN :f AND :t
                AND NOT EXISTS (SELECT 1 FROM achizitii_vehicule_cheltuieli x WHERE x.cheltuiala_id = c.id AND x.anulat_la IS NULL)",
            $vehicleId, $from, $to
        ) as $c) {
            $push([
                'sursa' => 'cheltuiala_alocare', 'sursa_id' => (int) $c['id'], 'data' => $c['data_cheltuiala'],
                'categorie' => 'cheltuiala', 'descriere' => trim((string) $c['tip'] . ' ' . (string) $c['descriere']), 'suma' => (float) $c['suma'],
                'natura_cost' => $c['natura_cost'], 'proiect_investitie_id' => $c['proiect_investitie_id'] !== null ? (int) $c['proiect_investitie_id'] : null,
                'clasa' => 'operational', 'cheltuiala_id' => (int) $c['cheltuiala_id'],
            ]);
        }

        // 5. Dosarul de achizitie
        foreach ($this->fetch(
            "SELECT l.id, COALESCE(l.data_tranzactie, a.data_factura, a.data_contract) AS d, l.categorie, l.descriere, l.suma_neta,
                    l.moneda, l.curs_schimb, l.clasificare, l.tratament_contabil, a.cod_dosar
               FROM achizitii_vehicule_cheltuieli l JOIN achizitii_vehicule a ON a.id = l.achizitie_id
              WHERE a.vehicle_id = :v AND a.status <> 'anulat' AND l.inclus_in_linie_id IS NULL AND l.anulat_la IS NULL
                AND COALESCE(l.data_tranzactie, a.data_factura, a.data_contract, :f2) BETWEEN :f AND :t",
            $vehicleId, $from, $to, true
        ) as $l) {
            $factor = $l['moneda'] === 'RON' ? 1.0 : ($l['curs_schimb'] !== null ? (float) $l['curs_schimb'] : null);
            $push([
                'sursa' => 'achizitie_cheltuiala', 'sursa_id' => (int) $l['id'], 'data' => $l['d'],
                'categorie' => 'achizitie:' . $l['categorie'], 'descriere' => $l['cod_dosar'] . ' — ' . $l['descriere'],
                'suma' => $factor !== null ? round((float) $l['suma_neta'] * $factor, 2) : 0.0,
                'tratament_contabil' => $l['tratament_contabil'],
                'clasa' => $l['clasificare'] === 'tva' ? 'tva' : 'achizitie',
                'curs_lipsa' => $factor === null,
            ]);
        }

        // 6. Anvelope: pretul o singura data, pe vehiculul primei alocari
        foreach ($this->fetch(
            "SELECT t.id, COALESCE(t.purchase_date, DATE(first.data_start)) AS d, t.brand, t.model, t.purchase_price
               FROM anvelope t
               JOIN (SELECT tire_id, MIN(id) AS first_id FROM anvelope_alocari GROUP BY tire_id) fa ON fa.tire_id = t.id
               JOIN anvelope_alocari first ON first.id = fa.first_id
              WHERE first.vehicle_id = :v AND t.purchase_price IS NOT NULL AND t.purchase_price > 0
                AND COALESCE(t.purchase_date, DATE(first.data_start)) BETWEEN :f AND :t",
            $vehicleId, $from, $to
        ) as $t) {
            $push([
                'sursa' => 'anvelopa', 'sursa_id' => (int) $t['id'], 'data' => $t['d'], 'categorie' => 'anvelope',
                'descriere' => trim($t['brand'] . ' ' . $t['model']), 'suma' => (float) $t['purchase_price'], 'clasa' => 'operational',
            ]);
        }

        // 7. Leasing (finantare)
        foreach ($this->fetch(
            "SELECT i.id, i.due_date, i.amount, i.currency, c.contract_number
               FROM leasing_installments i JOIN leasing_contracts c ON c.id = i.contract_id
              WHERE c.vehicle_id = :v AND i.due_date BETWEEN :f AND :t",
            $vehicleId, $from, $to
        ) as $i) {
            $push([
                'sursa' => 'leasing_rata', 'sursa_id' => (int) $i['id'], 'data' => $i['due_date'], 'categorie' => 'finantare',
                'descriere' => 'Rată leasing ' . $i['contract_number'] . ($i['currency'] !== 'RON' ? ' (' . $i['currency'] . ')' : ''),
                'suma' => (float) $i['amount'], 'clasa' => 'finantare',
            ]);
        }

        $out = array_values($rows);
        usort($out, static fn (array $a, array $b): int => [(string) $a['data'], $a['sursa'], $a['sursa_id']] <=> [(string) $b['data'], $b['sursa'], $b['sursa_id']]);
        return $out;
    }

    /** Totaluri pe clasa (operational / capital / achizitie / tva / finantare), fara dublari. */
    public function totals(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        $totals = [];
        foreach ($this->forVehicle($vehicleId, $from, $to) as $row) {
            $totals[$row['clasa']] = round(($totals[$row['clasa']] ?? 0) + $row['suma'], 2);
        }
        return $totals;
    }

    private function fetch(string $sql, int $vehicleId, string $from, string $to, bool $withF2 = false): array
    {
        $stmt = $this->db->prepare($sql);
        $params = [':v' => $vehicleId, ':f' => $from, ':t' => $to];
        if ($withF2) {
            $params[':f2'] = $from;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
