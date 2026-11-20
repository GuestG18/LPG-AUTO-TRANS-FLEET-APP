<?php
declare(strict_types=1);

require_once __DIR__ . '/FinancialFoundationSchema.php';

/**
 * Stoc Piese: un singur loc pentru intrari (receptii cu costul original) si consum (FIFO pe receptii).
 *
 * Invariant: pentru o piesa cu mod_utilizare = 'stoc', stoc_curent = SUM(receptii.cantitate_ramasa).
 *   - receive(): receptie noua + stoc_curent creste; pretul de catalog (pret_achizitie) devine doar
 *     „ultimul pret”, nu evaluarea receptiilor anterioare.
 *   - consume(): piesa blocata FOR UPDATE, stoc verificat (fara stoc negativ), receptiile consumate FIFO
 *     si legate de montare (mentenanta_piese_consumuri); costul montarii = costul receptiilor.
 *   - recordDirect(): achizitie directa / piesa adusa de atelier: NU atinge stocul.
 *   - transfer(): componenta trece pe alt vehicul cu costul ei istoric; nicio achizitie noua.
 *   - returnToStock() / reverse(): cantitatea revine pe receptiile ei originale (acelasi cost).
 * Idempotenta: idempotency_key unic pe montari si receptii; un retry intoarce randul existent.
 * Se alatura tranzactiei apelantului (nu ruleaza DDL).
 */
final class PartsInventoryService
{
    private const EPS = 0.0001;

    public function __construct(private PDO $db)
    {
    }

    public function ready(): bool
    {
        return FinancialFoundationSchema::isReady($this->db);
    }

    /**
     * @param array{data_receptie?:string,cost_unitar?:float|string|null,moneda?:string,curs_schimb?:float|null,cota_tva?:float|null,
     *   tva_unitar?:float|null,furnizor?:string|null,cui_furnizor?:string|null,factura_numar?:string|null,factura_data?:string|null,
     *   ocr_factura_id?:int|null,ocr_articol_id?:int|null,sursa_tip?:string,sursa_id?:int|null,status_evaluare?:string,
     *   idempotency_key?:string|null,observatii?:string|null,update_catalog_price?:bool} $meta
     * @return array{id:int, duplicate:bool}
     */
    public function receive(int $partId, float $quantity, array $meta, ?int $userId = null): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Cantitatea recepționată trebuie să fie pozitivă.');
        }
        return $this->transactional(function () use ($partId, $quantity, $meta, $userId): array {
            $part = $this->lockPart($partId);
            $existing = $this->existingReceipt($meta);
            if ($existing !== null) {
                return ['id' => $existing, 'duplicate' => true];
            }
            $this->reconcile($part);

            $cost = $meta['cost_unitar'] ?? null;
            $cost = $cost === null || $cost === '' ? null : max(0.0, (float) $cost);
            $source = (string) ($meta['sursa_tip'] ?? 'receptie_manuala');
            $status = (string) ($meta['status_evaluare'] ?? ($cost === null ? 'neverificat_istoric' : 'declarat_manual'));
            $this->insertReceipt($partId, $quantity, [
                'data_receptie' => (string) ($meta['data_receptie'] ?? date('Y-m-d')),
                'cost_unitar' => $cost,
                'cost_unitar_referinta' => $cost === null ? $this->nullIfZero((float) ($part['pret_achizitie'] ?? 0)) : null,
                'moneda' => strtoupper(trim((string) ($meta['moneda'] ?? 'RON'))) ?: 'RON',
                'curs_schimb' => $meta['curs_schimb'] ?? null,
                'cota_tva' => $meta['cota_tva'] ?? null,
                'tva_unitar' => $meta['tva_unitar'] ?? null,
                'furnizor' => $meta['furnizor'] ?? null,
                'cui_furnizor' => $meta['cui_furnizor'] ?? null,
                'factura_numar' => $meta['factura_numar'] ?? null,
                'factura_data' => $meta['factura_data'] ?? null,
                'ocr_factura_id' => $meta['ocr_factura_id'] ?? null,
                'ocr_articol_id' => $meta['ocr_articol_id'] ?? null,
                'sursa_tip' => $source,
                'sursa_id' => $meta['sursa_id'] ?? null,
                'status_evaluare' => $status,
                'idempotency_key' => $meta['idempotency_key'] ?? null,
                'observatii' => $meta['observatii'] ?? null,
                'created_by' => $userId,
            ]);
            $receiptId = (int) $this->db->lastInsertId();

            $sets = ['stoc_curent = stoc_curent + :q', 'updated_at = :u'];
            $params = [':q' => $quantity, ':u' => date('Y-m-d H:i:s'), ':id' => $partId];
            if ($cost !== null && ($meta['update_catalog_price'] ?? true) && strtoupper((string) ($meta['moneda'] ?? 'RON')) === 'RON') {
                $sets[] = 'pret_achizitie = :p'; // ultimul pret de catalog; receptiile vechi isi pastreaza costul
                $params[':p'] = $cost;
            }
            $this->db->prepare('UPDATE mentenanta_piese SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

            return ['id' => $receiptId, 'duplicate' => false];
        });
    }

    /**
     * Consum din stoc + randul de montare (mentenanta_piese_utilizari).
     *
     * @param array<string,mixed> $usage vehicle_id, data_montare, [maintenance_id, scheduled_intervention_id, km_montare,
     *        montata_de, observatii, component_key, locatie, axle_no, parte, created_by]
     * @return array{usage_id:int, cost_total:float, cost_unitar:float, verified:bool, duplicate:bool, receptii:list<array{id:int,cantitate:float,cost_unitar:?float}>}
     */
    public function consume(int $partId, float $quantity, array $usage, ?string $idempotencyKey = null): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Cantitatea consumată trebuie să fie pozitivă.');
        }
        return $this->transactional(function () use ($partId, $quantity, $usage, $idempotencyKey): array {
            $part = $this->lockPart($partId);
            $existing = $this->usageByKey($idempotencyKey);
            if ($existing !== null) {
                return $this->usageResult($existing, true);
            }
            if ((string) ($part['mod_utilizare'] ?? 'stoc') !== 'stoc') {
                throw new RuntimeException('Piesa nu este gestionată în stoc; folosește achiziția directă.');
            }
            if ((float) $part['stoc_curent'] + self::EPS < $quantity) {
                throw new RuntimeException(sprintf('Stoc insuficient pentru %s: necesar %s, în stoc %s.',
                    (string) $part['denumire'], $this->fmt($quantity), $this->fmt((float) $part['stoc_curent'])));
            }
            $this->reconcile($part);

            $receipts = $this->db->prepare(
                'SELECT * FROM mentenanta_piese_receptii WHERE part_id = :p AND cantitate_ramasa > 0
                 ORDER BY data_receptie, id FOR UPDATE'
            );
            $receipts->execute([':p' => $partId]);
            $left = $quantity;
            $allocations = [];
            $total = 0.0;
            $verified = true;
            foreach ($receipts->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
                if ($left <= self::EPS) {
                    break;
                }
                $take = min($left, (float) $receipt['cantitate_ramasa']);
                [$unitRon, $known] = $this->receiptUnitCostRon($receipt);
                $verified = $verified && $known;
                $allocations[] = ['id' => (int) $receipt['id'], 'cantitate' => $take, 'cost_unitar' => $known ? $unitRon : null, 'valoare' => $unitRon];
                $total += $take * $unitRon;
                $left -= $take;
            }
            if ($left > self::EPS) {
                throw new RuntimeException('Recepțiile piesei nu acoperă stocul; consumul a fost oprit.');
            }

            $unit = round($total / $quantity, 2);
            $usageId = $this->insertUsage($partId, $quantity, $unit, 'stoc', $usage + [
                'receptie_id' => $allocations[0]['id'] ?? null,
                'valoare_neverificata' => $verified ? 0 : 1,
                'idempotency_key' => $idempotencyKey,
            ]);
            $now = date('Y-m-d H:i:s');
            $insert = $this->db->prepare(
                'INSERT INTO mentenanta_piese_consumuri (usage_id, receptie_id, part_id, cantitate, cost_unitar, created_at)
                 VALUES (:u, :r, :p, :q, :c, :at)'
            );
            $decrement = $this->db->prepare('UPDATE mentenanta_piese_receptii SET cantitate_ramasa = cantitate_ramasa - :q WHERE id = :id');
            foreach ($allocations as $allocation) {
                $insert->execute([':u' => $usageId, ':r' => $allocation['id'], ':p' => $partId, ':q' => $allocation['cantitate'],
                    ':c' => $allocation['valoare'], ':at' => $now]);
                $decrement->execute([':q' => $allocation['cantitate'], ':id' => $allocation['id']]);
            }
            $this->db->prepare('UPDATE mentenanta_piese SET stoc_curent = stoc_curent - :q, updated_at = :u WHERE id = :id')
                ->execute([':q' => $quantity, ':u' => $now, ':id' => $partId]);

            return [
                'usage_id' => $usageId,
                'cost_total' => round($total, 2),
                'cost_unitar' => $unit,
                'verified' => $verified,
                'duplicate' => false,
                'receptii' => array_map(static fn (array $a): array => ['id' => $a['id'], 'cantitate' => $a['cantitate'], 'cost_unitar' => $a['cost_unitar']], $allocations),
            ];
        });
    }

    /**
     * Piesa montata fara sa treaca prin stoc (cumparata direct pentru lucrare sau adusa de atelier).
     *
     * @return array{usage_id:int, duplicate:bool}
     */
    public function recordDirect(int $partId, float $quantity, float $unitCost, string $source, array $usage, ?string $idempotencyKey = null): array
    {
        if (!in_array($source, ['achizitie_directa', 'atelier_extern'], true)) {
            throw new InvalidArgumentException('Sursa montajului direct este invalidă.');
        }
        return $this->transactional(function () use ($partId, $quantity, $unitCost, $source, $usage, $idempotencyKey): array {
            $existing = $this->usageByKey($idempotencyKey);
            if ($existing !== null) {
                return ['usage_id' => (int) $existing['id'], 'duplicate' => true];
            }
            $usageId = $this->insertUsage($partId, $quantity, max(0.0, round($unitCost, 2)), $source, $usage + ['idempotency_key' => $idempotencyKey]);
            return ['usage_id' => $usageId, 'duplicate' => false];
        });
    }

    /**
     * Componenta montata trece direct pe alt vehicul: montarea veche se inchide (motiv „transfer”),
     * cea noua pastreaza costul istoric si receptia originala. Nu se creeaza achizitie sau cost nou.
     *
     * @return array{usage_id:int, duplicate:bool, cost_unitar:float}
     */
    public function transfer(int $sourceUsageId, int $targetVehicleId, array $usage, ?string $idempotencyKey = null): array
    {
        return $this->transactional(function () use ($sourceUsageId, $targetVehicleId, $usage, $idempotencyKey): array {
            $existing = $this->usageByKey($idempotencyKey);
            if ($existing !== null) {
                return ['usage_id' => (int) $existing['id'], 'duplicate' => true, 'cost_unitar' => (float) $existing['cost_unitar']];
            }
            $stmt = $this->db->prepare('SELECT * FROM mentenanta_piese_utilizari WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $sourceUsageId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($source === false || $source['demontat_la'] !== null) {
                throw new RuntimeException('Componenta de transferat nu mai este montată.');
            }
            if ((int) $source['vehicle_id'] === $targetVehicleId) {
                throw new InvalidArgumentException('Componenta este deja pe acest vehicul.');
            }
            $date = (string) ($usage['data_montare'] ?? date('Y-m-d'));
            if ($date < (string) $source['data_montare']) {
                throw new InvalidArgumentException('Data transferului nu poate fi înaintea montării inițiale.');
            }
            $newId = $this->insertUsage((int) $source['part_id'], (float) $source['cantitate'], (float) $source['cost_unitar'], 'transfer_vehicul', [
                'vehicle_id' => $targetVehicleId,
                'receptie_id' => $source['receptie_id'] ?? null,
                'valoare_neverificata' => (int) ($source['valoare_neverificata'] ?? 0),
                'transfer_din_utilizare_id' => $sourceUsageId,
                'component_key' => $usage['component_key'] ?? $source['component_key'],
                'idempotency_key' => $idempotencyKey,
            ] + $usage);
            $this->db->prepare(
                "UPDATE mentenanta_piese_utilizari
                 SET demontat_la = :d, km_demontare = :km, motiv_demontare = 'transfer', inlocuita_de_id = :n,
                     observatii_demontare = :o
                 WHERE id = :id"
            )->execute([
                ':d' => $date,
                ':km' => $usage['km_demontare_sursa'] ?? null,
                ':n' => $newId,
                ':o' => 'Transferată pe vehiculul #' . $targetVehicleId,
                ':id' => $sourceUsageId,
            ]);
            return ['usage_id' => $newId, 'duplicate' => false, 'cost_unitar' => (float) $source['cost_unitar']];
        });
    }

    /**
     * Retur in stoc al unei componente demontate: cantitatea revine pe receptiile ei originale (acelasi cost).
     * O piesa montata direct (fara receptie) intra printr-o receptie „retur_montaj” la costul montarii.
     */
    public function returnToStock(int $usageId, ?int $userId = null): void
    {
        $this->transactional(function () use ($usageId, $userId): void {
            $usage = $this->lockUsage($usageId);
            $this->restoreQuantities($usage, 'Retur în stoc din montarea #' . $usageId, $userId);
        });
    }

    /** Anularea unui consum (montarea se sterge odata cu inregistrarea): stocul revine pe receptii. */
    public function reverse(int $usageId, ?int $userId = null): void
    {
        $this->transactional(function () use ($usageId, $userId): void {
            $usage = $this->lockUsage($usageId);
            if (($usage['sursa_piesa'] ?? null) === 'transfer_vehicul' || (int) $usage['direct_mount'] === 1) {
                return; // nu a scazut stocul
            }
            $this->restoreQuantities($usage, 'Anulare consum (montarea #' . $usageId . ' ștearsă)', $userId);
        });
    }

    /** Valoarea stocului din receptii (cost cunoscut + valoare neverificata separat). */
    public function stockValuation(?int $partId = null): array
    {
        $sql = 'SELECT r.part_id,
                       SUM(r.cantitate_ramasa) AS cantitate,
                       SUM(CASE WHEN r.cost_unitar IS NOT NULL AND (r.moneda = \'RON\' OR r.curs_schimb IS NOT NULL)
                                THEN r.cantitate_ramasa * r.cost_unitar * IF(r.moneda = \'RON\', 1, r.curs_schimb) ELSE 0 END) AS valoare_verificata,
                       SUM(CASE WHEN r.cost_unitar IS NULL OR (r.moneda <> \'RON\' AND r.curs_schimb IS NULL)
                                THEN r.cantitate_ramasa ELSE 0 END) AS cantitate_neevaluata
                  FROM mentenanta_piese_receptii r WHERE r.cantitate_ramasa > 0'
            . ($partId !== null ? ' AND r.part_id = :p' : '') . ' GROUP BY r.part_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($partId !== null ? [':p' => $partId] : []);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['part_id']] = [
                'cantitate' => (float) $row['cantitate'],
                'valoare_verificata' => round((float) $row['valoare_verificata'], 2),
                'cantitate_neevaluata' => (float) $row['cantitate_neevaluata'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ intern

    private function restoreQuantities(array $usage, string $note, ?int $userId): void
    {
        $partId = (int) $usage['part_id'];
        $this->lockPart($partId);
        $consumptions = $this->db->prepare('SELECT * FROM mentenanta_piese_consumuri WHERE usage_id = :u FOR UPDATE');
        $consumptions->execute([':u' => (int) $usage['id']]);
        $rows = $consumptions->fetchAll(PDO::FETCH_ASSOC);
        $restored = 0.0;
        foreach ($rows as $row) {
            $open = (float) $row['cantitate'] - (float) $row['cantitate_returnata'];
            if ($open <= self::EPS) {
                continue;
            }
            $this->db->prepare('UPDATE mentenanta_piese_receptii SET cantitate_ramasa = cantitate_ramasa + :q WHERE id = :id')
                ->execute([':q' => $open, ':id' => (int) $row['receptie_id']]);
            $this->db->prepare('UPDATE mentenanta_piese_consumuri SET cantitate_returnata = cantitate WHERE id = :id')
                ->execute([':id' => (int) $row['id']]);
            $restored += $open;
        }
        if ($rows === []) {
            // Montare fara receptie (directa sau dinaintea registrului): intra la costul montarii.
            $restored = (float) $usage['cantitate'];
            $this->insertReceipt($partId, $restored, [
                'data_receptie' => date('Y-m-d'),
                'cost_unitar' => (int) ($usage['valoare_neverificata'] ?? 0) === 1 ? null : (float) $usage['cost_unitar'],
                'cost_unitar_referinta' => (float) $usage['cost_unitar'] > 0 ? (float) $usage['cost_unitar'] : null,
                'moneda' => 'RON',
                'sursa_tip' => 'retur_montaj',
                'sursa_id' => (int) $usage['id'],
                'status_evaluare' => (int) ($usage['valoare_neverificata'] ?? 0) === 1 || (float) $usage['cost_unitar'] <= 0 ? 'neverificat_istoric' : 'declarat_manual',
                'idempotency_key' => 'retur-usage-' . (int) $usage['id'],
                'observatii' => $note,
                'created_by' => $userId,
            ]);
        }
        if ($restored > self::EPS) {
            $this->db->prepare('UPDATE mentenanta_piese SET stoc_curent = stoc_curent + :q, updated_at = :u WHERE id = :id')
                ->execute([':q' => $restored, ':u' => date('Y-m-d H:i:s'), ':id' => $partId]);
        }
    }

    /**
     * Pastreaza invariantul stoc = receptii cand stocul a fost schimbat pe alta cale (cod vechi):
     * diferenta pozitiva intra ca receptie „ajustare” neverificata; cea negativa se scade FIFO.
     */
    private function reconcile(array $part): void
    {
        if ((string) ($part['mod_utilizare'] ?? 'stoc') !== 'stoc') {
            return;
        }
        $stmt = $this->db->prepare('SELECT id, cantitate_ramasa FROM mentenanta_piese_receptii WHERE part_id = :p AND cantitate_ramasa > 0 ORDER BY data_receptie, id FOR UPDATE');
        $stmt->execute([':p' => (int) $part['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sum = array_sum(array_map(static fn (array $r): float => (float) $r['cantitate_ramasa'], $rows));
        $diff = (float) $part['stoc_curent'] - $sum;
        if ($diff > self::EPS) {
            $this->insertReceipt((int) $part['id'], $diff, [
                'data_receptie' => date('Y-m-d'),
                'cost_unitar' => null,
                'cost_unitar_referinta' => $this->nullIfZero((float) ($part['pret_achizitie'] ?? 0)),
                'moneda' => 'RON',
                'sursa_tip' => 'ajustare',
                'status_evaluare' => 'neverificat_istoric',
                'observatii' => 'Stoc fără recepție (intrare înainte de registrul de recepții); cost nedocumentat',
            ]);
        } elseif ($diff < -self::EPS) {
            $excess = -$diff;
            foreach ($rows as $row) {
                if ($excess <= self::EPS) {
                    break;
                }
                $take = min($excess, (float) $row['cantitate_ramasa']);
                $this->db->prepare(
                    "UPDATE mentenanta_piese_receptii SET cantitate_ramasa = cantitate_ramasa - :q,
                        observatii = LEFT(CONCAT(COALESCE(observatii, ''), ' | ajustare -', :q2, ' (stoc scăzut în afara registrului)'), 500)
                     WHERE id = :id"
                )->execute([':q' => $take, ':q2' => $this->fmt($take), ':id' => (int) $row['id']]);
                $excess -= $take;
            }
        }
    }

    /** @return array{0:float,1:bool} cost unitar in RON + daca e documentat */
    private function receiptUnitCostRon(array $receipt): array
    {
        $cost = $receipt['cost_unitar'];
        if ($cost !== null) {
            if ((string) $receipt['moneda'] === 'RON') {
                return [(float) $cost, true];
            }
            if ($receipt['curs_schimb'] !== null && (float) $receipt['curs_schimb'] > 0) {
                return [(float) $cost * (float) $receipt['curs_schimb'], true];
            }
        }
        // Cost nedocumentat: valoarea de referinta inghetata pe receptie, marcata NEVERIFICATA.
        return [(float) ($receipt['cost_unitar_referinta'] ?? 0), false];
    }

    private function insertReceipt(int $partId, float $quantity, array $data): void
    {
        $this->db->prepare(
            'INSERT INTO mentenanta_piese_receptii
                (part_id, data_receptie, cantitate_receptionata, cantitate_ramasa, cost_unitar, cost_unitar_referinta, moneda,
                 curs_schimb, cota_tva, tva_unitar, furnizor, cui_furnizor, factura_numar, factura_data, ocr_factura_id,
                 ocr_articol_id, sursa_tip, sursa_id, status_evaluare, idempotency_key, observatii, created_by, created_at)
             VALUES (:p, :d, :q, :q2, :c, :cr, :m, :curs, :cota, :tva, :f, :cui, :fn, :fd, :of, :oa, :st, :si, :se, :k, :o, :by, NOW())'
        )->execute([
            ':p' => $partId,
            ':d' => (string) ($data['data_receptie'] ?? date('Y-m-d')),
            ':q' => $quantity,
            ':q2' => $quantity,
            ':c' => $data['cost_unitar'] ?? null,
            ':cr' => $data['cost_unitar_referinta'] ?? null,
            ':m' => (string) ($data['moneda'] ?? 'RON'),
            ':curs' => $data['curs_schimb'] ?? null,
            ':cota' => $data['cota_tva'] ?? null,
            ':tva' => $data['tva_unitar'] ?? null,
            ':f' => $this->text($data['furnizor'] ?? null, 190),
            ':cui' => $this->text($data['cui_furnizor'] ?? null, 20),
            ':fn' => $this->text($data['factura_numar'] ?? null, 120),
            ':fd' => ($data['factura_data'] ?? null) ?: null,
            ':of' => $data['ocr_factura_id'] ?? null,
            ':oa' => $data['ocr_articol_id'] ?? null,
            ':st' => (string) $data['sursa_tip'],
            ':si' => $data['sursa_id'] ?? null,
            ':se' => (string) $data['status_evaluare'],
            ':k' => $this->text($data['idempotency_key'] ?? null, 120),
            ':o' => $this->text($data['observatii'] ?? null, 500),
            ':by' => $data['created_by'] ?? null,
        ]);
    }

    private function insertUsage(int $partId, float $quantity, float $unitCost, string $source, array $u): int
    {
        if (empty($u['vehicle_id'])) {
            throw new InvalidArgumentException('Vehiculul montării lipsește.');
        }
        $this->db->prepare(
            'INSERT INTO mentenanta_piese_utilizari
                (part_id, maintenance_id, scheduled_intervention_id, vehicle_id, cantitate, cost_unitar, data_montare, km_montare,
                 montata_de, observatii, direct_mount, sursa_piesa, receptie_id, valoare_neverificata, transfer_din_utilizare_id,
                 idempotency_key, component_key, locatie, axle_no, parte, created_by, created_at)
             VALUES (:p, :m, :s, :v, :q, :c, :d, :km, :by, :o, :dm, :src, :r, :nv, :t, :k, :ck, :loc, :ax, :side, :cb, NOW())'
        )->execute([
            ':p' => $partId,
            ':m' => $u['maintenance_id'] ?? null,
            ':s' => $u['scheduled_intervention_id'] ?? null,
            ':v' => (int) $u['vehicle_id'],
            ':q' => $quantity,
            ':c' => $unitCost,
            ':d' => (string) ($u['data_montare'] ?? date('Y-m-d')),
            ':km' => isset($u['km_montare']) && $u['km_montare'] !== '' && $u['km_montare'] !== null && (int) $u['km_montare'] > 0 ? (int) $u['km_montare'] : null,
            ':by' => $this->text($u['montata_de'] ?? null, 190),
            ':o' => $this->text($u['observatii'] ?? null, 5000),
            ':dm' => $source === 'stoc' || $source === 'transfer_vehicul' ? 0 : 1,
            ':src' => $source,
            ':r' => $u['receptie_id'] ?? null,
            ':nv' => (int) ($u['valoare_neverificata'] ?? 0),
            ':t' => $u['transfer_din_utilizare_id'] ?? null,
            ':k' => $this->text($u['idempotency_key'] ?? null, 120),
            ':ck' => ($u['component_key'] ?? null) ?: null,
            ':loc' => ($u['locatie'] ?? null) ?: null,
            ':ax' => $u['axle_no'] ?? null,
            ':side' => ($u['parte'] ?? null) ?: null,
            ':cb' => $u['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function usageResult(array $row, bool $duplicate): array
    {
        $stmt = $this->db->prepare('SELECT receptie_id AS id, cantitate, cost_unitar FROM mentenanta_piese_consumuri WHERE usage_id = :u');
        $stmt->execute([':u' => (int) $row['id']]);
        $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;
        foreach ($receipts as $r) {
            $total += (float) $r['cantitate'] * (float) $r['cost_unitar'];
        }
        if ($receipts === []) {
            $total = (float) $row['cantitate'] * (float) $row['cost_unitar'];
        }
        return [
            'usage_id' => (int) $row['id'],
            'cost_total' => round($total, 2),
            'cost_unitar' => (float) $row['cost_unitar'],
            'verified' => (int) ($row['valoare_neverificata'] ?? 0) === 0,
            'duplicate' => $duplicate,
            'receptii' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'cantitate' => (float) $r['cantitate'], 'cost_unitar' => $r['cost_unitar'] !== null ? (float) $r['cost_unitar'] : null], $receipts),
        ];
    }

    private function usageByKey(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_piese_utilizari WHERE idempotency_key = :k');
        $stmt->execute([':k' => $key]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function existingReceipt(array $meta): ?int
    {
        foreach (['idempotency_key', 'ocr_articol_id'] as $field) {
            if (!empty($meta[$field])) {
                $stmt = $this->db->prepare("SELECT id FROM mentenanta_piese_receptii WHERE $field = :v");
                $stmt->execute([':v' => $meta[$field]]);
                $id = $stmt->fetchColumn();
                if ($id !== false) {
                    return (int) $id;
                }
            }
        }
        return null;
    }

    private function lockPart(int $partId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_piese WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $partId]);
        $part = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($part === false) {
            throw new RuntimeException('Piesa nu există în Stoc piese.');
        }
        return $part;
    }

    private function lockUsage(int $usageId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_piese_utilizari WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $usageId]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($usage === false) {
            throw new RuntimeException('Montarea nu mai există.');
        }
        return $usage;
    }

    private function transactional(callable $fn): mixed
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $result = $fn();
            if ($own) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function nullIfZero(float $value): ?float
    {
        return $value > 0 ? $value : null;
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
