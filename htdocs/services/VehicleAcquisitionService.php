<?php
declare(strict_types=1);

require_once __DIR__ . '/FinancialAuditLog.php';
require_once __DIR__ . '/CostNature.php';
require_once __DIR__ . '/FinancialCategoryTree.php';

/**
 * Dosar Achizitie Vehicul (fundatia de date; interfata ramane de construit).
 *
 * - Dosarul poate exista inainte de inmatriculare (cod_dosar + VIN); vehicle_id se leaga la inregistrare.
 * - Cheltuielile sunt linii categorisite (categorii configurabile, filtrate dupa context); fiecare linie are
 *   sursa, clasificare, tratament contabil (implicit de_confirmat), stare de verificare, TVA, document.
 *   Nicio linie nu se capitalizeaza automat. Clasificarea nu depinde de TVA sau de plati.
 * - Liniile deja incluse in alta linie (ex. factura transportatorului) nu se aduna (inclus_in_linie_id).
 * - Evenimentele (achizitie, transfer proprietate, preluare, sosire, receptie, inmatriculare, adaptare,
 *   punere in functiune) sunt separate: receptia NU este punere in functiune.
 * - Nu se calculeaza valoare de piata; doar sume din tranzactiile introduse.
 */
final class VehicleAcquisitionService
{
    public const VEHICLE_TYPES = ['cap_tractor', 'camion', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie', 'remorca', 'autoutilitara', 'autovehicul', 'altul'];
    public const EVENTS = ['achizitie', 'transfer_proprietate', 'preluare', 'sosire_ro', 'receptie_tehnica', 'inmatriculare', 'adaptare_tehnica', 'punere_in_functiune'];
    public const STATUSES = ['in_analiza', 'contractat', 'in_plata', 'in_transport', 'receptionat', 'inmatriculat', 'in_adaptare', 'pus_in_functiune', 'anulat'];
    private const VERIFICATION = ['estimat', 'confirmat', 'verificat_contabil'];
    private const VAT_DEDUCTIBLE = ['da', 'nu', 'partial', 'de_confirmat'];

    public function __construct(private PDO $db)
    {
    }

    // ------------------------------------------------------------------ dosar

    public function createDossier(array $data, ?int $userId): int
    {
        $type = (string) ($data['tip_vehicul'] ?? '');
        if (!in_array($type, self::VEHICLE_TYPES, true)) {
            throw new InvalidArgumentException('Tipul vehiculului este invalid.');
        }
        $state = (string) ($data['stare'] ?? '');
        if (!in_array($state, ['nou', 'rulat'], true)) {
            throw new InvalidArgumentException('Alege starea vehiculului (nou / rulat).');
        }
        $method = (string) ($data['modalitate_achizitie'] ?? '');
        if (!in_array($method, ['plata_integrala', 'plata_esalonata', 'leasing_financiar', 'leasing_operational', 'credit', 'altul'], true)) {
            throw new InvalidArgumentException('Alege modalitatea de achiziție / finanțare.');
        }
        $vehicleId = !empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;
        $vin = $this->normalizeVin($data['vin'] ?? null);
        if ($vehicleId !== null) {
            $this->assertVehicleFree($vehicleId, null);
            $vin ??= $this->normalizeVin($this->vehicleVin($vehicleId));
        }
        $currency = $this->currency($data['moneda'] ?? 'RON');
        $price = $this->money($data['pret_achizitie'] ?? null);

        return $this->transactional(function () use ($data, $userId, $type, $state, $method, $vehicleId, $vin, $currency, $price): int {
            $year = date('Y');
            $next = (int) $this->db->query("SELECT COUNT(*) + 1 FROM achizitii_vehicule WHERE cod_dosar LIKE 'ACH-$year-%' FOR UPDATE")->fetchColumn();
            do {
                $code = sprintf('ACH-%s-%04d', $year, $next++);
                $exists = $this->db->prepare('SELECT COUNT(*) FROM achizitii_vehicule WHERE cod_dosar = :c');
                $exists->execute([':c' => $code]);
            } while ((int) $exists->fetchColumn() > 0);

            $this->db->prepare(
                'INSERT INTO achizitii_vehicule
                    (cod_dosar, vehicle_id, vin, tip_vehicul, marca, model, an_fabricatie, stare, vanzator_nume, vanzator_cod_fiscal,
                     vanzator_furnizor_id, vanzator_tara, cumparator_entitate, locatie_vehicul, tara_achizitie, tara_inmatriculare,
                     modalitate_achizitie, leasing_contract_id, modalitate_livrare, data_contract, data_factura, data_transfer_proprietate,
                     pret_achizitie, moneda, curs_schimb, curs_data, curs_sursa, regim_tva, status, utilizare_prevazuta, observatii,
                     created_by, updated_by, created_at, updated_at)
                 VALUES (:cod, :v, :vin, :tip, :marca, :model, :an, :stare, :vn, :vcf, :vf, :vt, :cump, :loc, :ta, :ti, :mod, :lc, :ml,
                     :dc, :df, :dtp, :pret, :moneda, :curs, :cd, :cs, :tva, :status, :util, :obs, :cb, :ub, NOW(), NOW())'
            )->execute([
                ':cod' => $code,
                ':v' => $vehicleId,
                ':vin' => $vin,
                ':tip' => $type,
                ':marca' => $this->text($data['marca'] ?? null, 60),
                ':model' => $this->text($data['model'] ?? null, 60),
                ':an' => !empty($data['an_fabricatie']) ? (int) $data['an_fabricatie'] : null,
                ':stare' => $state,
                ':vn' => $this->text($data['vanzator_nume'] ?? null, 190),
                ':vcf' => $this->text($data['vanzator_cod_fiscal'] ?? null, 40),
                ':vf' => !empty($data['vanzator_furnizor_id']) ? (int) $data['vanzator_furnizor_id'] : null,
                ':vt' => $this->country($data['vanzator_tara'] ?? null),
                ':cump' => $this->text($data['cumparator_entitate'] ?? null, 190),
                ':loc' => $this->text($data['locatie_vehicul'] ?? null, 255),
                ':ta' => $this->country($data['tara_achizitie'] ?? null),
                ':ti' => $this->country($data['tara_inmatriculare'] ?? 'RO') ?? 'RO',
                ':mod' => $method,
                ':lc' => !empty($data['leasing_contract_id']) ? (int) $data['leasing_contract_id'] : null,
                ':ml' => in_array($data['modalitate_livrare'] ?? null, ['condus', 'transportator'], true) ? $data['modalitate_livrare'] : null,
                ':dc' => $this->date($data['data_contract'] ?? null),
                ':df' => $this->date($data['data_factura'] ?? null),
                ':dtp' => $this->date($data['data_transfer_proprietate'] ?? null),
                ':pret' => $price,
                ':moneda' => $currency,
                ':curs' => $this->rate($data['curs_schimb'] ?? null),
                ':cd' => $this->date($data['curs_data'] ?? null),
                ':cs' => $this->text($data['curs_sursa'] ?? null, 40),
                ':tva' => in_array($data['regim_tva'] ?? null, ['normal', 'taxare_inversa', 'intracomunitar', 'import', 'regim_marja', 'scutit', 'de_confirmat'], true) ? $data['regim_tva'] : 'de_confirmat',
                ':status' => in_array($data['status'] ?? null, self::STATUSES, true) ? $data['status'] : 'in_analiza',
                ':util' => $this->text($data['utilizare_prevazuta'] ?? null, 255),
                ':obs' => $this->text($data['observatii'] ?? null, 5000),
                ':cb' => $userId,
                ':ub' => $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            FinancialAuditLog::record($this->db, 'achizitie', $id, 'creare', null, null, $code, null, ['vin' => $vin, 'vehicle_id' => $vehicleId], $userId);
            return $id;
        });
    }

    /** Leaga dosarul de vehiculul inmatriculat in flota (VIN-ul trebuie sa corespunda, daca exista amandoua). */
    public function linkVehicle(int $acquisitionId, int $vehicleId, ?int $userId): void
    {
        $this->transactional(function () use ($acquisitionId, $vehicleId, $userId): void {
            $dossier = $this->lockDossier($acquisitionId);
            $this->assertVehicleFree($vehicleId, $acquisitionId);
            $vehicleVin = $this->normalizeVin($this->vehicleVin($vehicleId));
            if ($dossier['vin'] !== null && $vehicleVin !== null && $dossier['vin'] !== $vehicleVin) {
                throw new InvalidArgumentException('VIN-ul vehiculului (' . $vehicleVin . ') diferă de cel din dosar (' . $dossier['vin'] . ').');
            }
            $this->db->prepare('UPDATE achizitii_vehicule SET vehicle_id = :v, vin = COALESCE(vin, :vin), updated_by = :u, updated_at = NOW() WHERE id = :id')
                ->execute([':v' => $vehicleId, ':vin' => $vehicleVin, ':u' => $userId, ':id' => $acquisitionId]);
            FinancialAuditLog::record($this->db, 'achizitie', $acquisitionId, 'legare_vehicul', 'vehicle_id', $dossier['vehicle_id'] !== null ? (string) $dossier['vehicle_id'] : null, (string) $vehicleId, null, null, $userId);
        });
    }

    /** Campurile antetului (fara vehicul / status / cod), cu istoric per camp. */
    public function updateDossier(int $acquisitionId, array $data, ?int $userId): void
    {
        $this->transactional(function () use ($acquisitionId, $data, $userId): void {
            $dossier = $this->lockDossier($acquisitionId);
            $fields = [];
            foreach (['marca' => 60, 'model' => 60, 'vanzator_nume' => 190, 'vanzator_cod_fiscal' => 40, 'cumparator_entitate' => 190,
                'locatie_vehicul' => 255, 'utilizare_prevazuta' => 255, 'curs_sursa' => 40, 'observatii' => 5000] as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $this->text($data[$field], $max);
                }
            }
            foreach (['vanzator_tara', 'tara_achizitie', 'tara_inmatriculare'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $this->country($data[$field]) ?? ($field === 'tara_inmatriculare' ? 'RO' : null);
                }
            }
            foreach (['data_contract', 'data_factura', 'data_transfer_proprietate', 'curs_data'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $this->date($data[$field]);
                }
            }
            if (array_key_exists('vin', $data)) {
                $fields['vin'] = $this->normalizeVin($data['vin']);
            }
            if (array_key_exists('an_fabricatie', $data)) {
                $fields['an_fabricatie'] = !empty($data['an_fabricatie']) ? (int) $data['an_fabricatie'] : null;
            }
            if (array_key_exists('pret_achizitie', $data)) {
                $fields['pret_achizitie'] = $this->money($data['pret_achizitie']);
            }
            if (array_key_exists('moneda', $data)) {
                $fields['moneda'] = $this->currency($data['moneda']);
            }
            if (array_key_exists('curs_schimb', $data)) {
                $fields['curs_schimb'] = $this->rate($data['curs_schimb']);
            }
            if (array_key_exists('regim_tva', $data) && in_array($data['regim_tva'], ['normal', 'taxare_inversa', 'intracomunitar', 'import', 'regim_marja', 'scutit', 'de_confirmat'], true)) {
                $fields['regim_tva'] = $data['regim_tva'];
            }
            if (array_key_exists('stare', $data) && in_array($data['stare'], ['nou', 'rulat'], true)) {
                $fields['stare'] = $data['stare'];
            }
            $sets = [];
            $params = [':id' => $acquisitionId, ':u' => $userId];
            foreach ($fields as $field => $value) {
                if ((string) $dossier[$field] === (string) $value) {
                    continue;
                }
                $sets[] = "`$field` = :$field";
                $params[":$field"] = $value;
                FinancialAuditLog::record($this->db, 'achizitie', $acquisitionId, 'modificare', $field,
                    $dossier[$field] === null ? null : mb_substr((string) $dossier[$field], 0, 255), $value === null ? null : mb_substr((string) $value, 0, 255), null, null, $userId);
            }
            if ($sets !== []) {
                $this->db->prepare('UPDATE achizitii_vehicule SET ' . implode(', ', $sets) . ', updated_by = :u, updated_at = NOW() WHERE id = :id')->execute($params);
            }
        });
    }

    /** Datele unei linii (fara clasificare / tratament, care au fluxul lor), cu istoric per camp. */
    public function updateCost(int $lineId, array $data, ?int $userId): void
    {
        $this->transactional(function () use ($lineId, $data, $userId): void {
            $line = $this->lockLine($lineId);
            $this->assertNotCancelled($line);
            $this->lockDossier((int) $line['achizitie_id']);
            $fields = [];
            if (array_key_exists('financiar_categorie_id', $data) && (string) $data['financiar_categorie_id'] !== '') {
                // Mutarea in alta subcategorie: doar referinta frunzei; tipul si clasificarea raman.
                $leaf = (int) $data['financiar_categorie_id'];
                (new FinancialCategoryTree($this->db))->assertAssignableLeaf($leaf);
                $fields['financiar_categorie_id'] = $leaf;
            }
            foreach (['descriere' => 255, 'furnizor' => 190, 'cui_furnizor' => 20, 'document_tip' => 40, 'document_numar' => 120, 'observatii' => 500] as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $this->text($data[$field], $max);
                }
            }
            if (array_key_exists('data_tranzactie', $data)) {
                $fields['data_tranzactie'] = $this->date($data['data_tranzactie']);
            }
            if (array_key_exists('suma_neta', $data)) {
                $fields['suma_neta'] = $this->money($data['suma_neta']) ?? throw new InvalidArgumentException('Suma este obligatorie.');
            }
            if (array_key_exists('tva', $data)) {
                $fields['tva'] = $this->money($data['tva']);
            }
            if (array_key_exists('moneda', $data)) {
                $fields['moneda'] = $this->currency($data['moneda']);
            }
            if (array_key_exists('curs_schimb', $data)) {
                $fields['curs_schimb'] = ($fields['moneda'] ?? $line['moneda']) === 'RON' ? null : $this->rate($data['curs_schimb']);
            }
            if (array_key_exists('document_fisier', $data)) {
                $fields['document_fisier'] = $this->text($data['document_fisier'], 255);
            }
            $sets = [];
            $params = [':id' => $lineId, ':u' => $userId];
            foreach ($fields as $field => $value) {
                if ((string) $line[$field] === (string) $value) {
                    continue;
                }
                $sets[] = "`$field` = :$field";
                $params[":$field"] = $value;
                FinancialAuditLog::record($this->db, 'achizitie_cheltuiala', $lineId, 'modificare', $field,
                    $line[$field] === null ? null : (string) $line[$field], $value === null ? null : (string) $value, null, null, $userId);
            }
            if ($sets !== []) {
                $this->db->prepare('UPDATE achizitii_vehicule_cheltuieli SET ' . implode(', ', $sets) . ', updated_by = :u, updated_at = NOW() WHERE id = :id')->execute($params);
            }
        });
    }

    public function setStatus(int $acquisitionId, string $status, ?int $userId, string $reason = ''): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Status invalid.');
        }
        $this->transactional(function () use ($acquisitionId, $status, $userId, $reason): void {
            $dossier = $this->lockDossier($acquisitionId);
            if ($status === 'pus_in_functiune' && $this->eventDate($acquisitionId, 'punere_in_functiune') === null) {
                throw new InvalidArgumentException('Înregistrează întâi evenimentul „Punere în funcțiune” (data efectivă).');
            }
            $this->db->prepare('UPDATE achizitii_vehicule SET status = :s, updated_by = :u, updated_at = NOW() WHERE id = :id')
                ->execute([':s' => $status, ':u' => $userId, ':id' => $acquisitionId]);
            FinancialAuditLog::record($this->db, 'achizitie', $acquisitionId, 'status', 'status', (string) $dossier['status'], $status, $reason, null, $userId);
        });
    }

    // ------------------------------------------------------------------ evenimente

    public function recordEvent(int $acquisitionId, string $type, string $date, ?int $userId, ?string $documentRef = null, ?string $notes = null): void
    {
        if (!in_array($type, self::EVENTS, true)) {
            throw new InvalidArgumentException('Tip de eveniment invalid.');
        }
        $date = $this->date($date) ?? throw new InvalidArgumentException('Data evenimentului este invalidă.');
        $this->transactional(function () use ($acquisitionId, $type, $date, $userId, $documentRef, $notes): void {
            $this->lockDossier($acquisitionId);
            if ($type === 'punere_in_functiune') {
                $reception = $this->eventDate($acquisitionId, 'receptie_tehnica');
                if ($reception !== null && $date < $reception) {
                    throw new InvalidArgumentException('Punerea în funcțiune nu poate fi înaintea recepției tehnice.');
                }
            }
            $old = $this->eventDate($acquisitionId, $type);
            $this->db->prepare(
                'INSERT INTO achizitii_vehicule_evenimente (achizitie_id, tip, data_eveniment, document_ref, observatii, created_by, created_at)
                 VALUES (:a, :t, :d, :doc, :o, :u, NOW())
                 ON DUPLICATE KEY UPDATE data_eveniment = VALUES(data_eveniment), document_ref = VALUES(document_ref), observatii = VALUES(observatii)'
            )->execute([':a' => $acquisitionId, ':t' => $type, ':d' => $date, ':doc' => $this->text($documentRef, 255), ':o' => $this->text($notes, 500), ':u' => $userId]);
            FinancialAuditLog::record($this->db, 'achizitie', $acquisitionId, 'eveniment', $type, $old, $date, $notes, null, $userId);
        });
    }

    /** @return array<string,string> tip => data */
    public function events(int $acquisitionId): array
    {
        $stmt = $this->db->prepare('SELECT tip, data_eveniment FROM achizitii_vehicule_evenimente WHERE achizitie_id = :a ORDER BY data_eveniment, id');
        $stmt->execute([':a' => $acquisitionId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'data_eveniment', 'tip');
    }

    // ------------------------------------------------------------------ transport

    /**
     * A. condus pana la destinatie (sofer, numere provizorii, asigurare...) sau B. transportator extern
     * (comanda, pret prin linia de cost, servicii incluse in pret).
     */
    public function saveTransport(int $acquisitionId, array $data, ?int $userId): int
    {
        $mode = (string) ($data['modalitate'] ?? '');
        if (!in_array($mode, ['condus', 'transportator'], true)) {
            throw new InvalidArgumentException('Alege modul de livrare: condus sau transportator.');
        }
        if ($mode === 'transportator' && trim((string) ($data['transportator'] ?? '')) === '' && empty($data['transportator_furnizor_id'])) {
            throw new InvalidArgumentException('Completează transportatorul.');
        }
        if ($mode === 'condus' && empty($data['sofer_id']) && trim((string) ($data['sofer_nume'] ?? '')) === '') {
            throw new InvalidArgumentException('Completează șoferul care aduce vehiculul.');
        }
        $included = array_values(array_filter(array_map('trim', is_array($data['servicii_incluse'] ?? null) ? $data['servicii_incluse'] : explode(',', (string) ($data['servicii_incluse'] ?? '')))));

        return $this->transactional(function () use ($acquisitionId, $data, $userId, $mode, $included): int {
            $this->lockDossier($acquisitionId);
            $existing = $this->db->prepare('SELECT id FROM achizitii_vehicule_transport WHERE achizitie_id = :a ORDER BY id LIMIT 1');
            $existing->execute([':a' => $acquisitionId]);
            $id = (int) ($existing->fetchColumn() ?: 0);
            $values = [
                ':m' => $mode,
                ':sid' => !empty($data['sofer_id']) ? (int) $data['sofer_id'] : null,
                ':sn' => $this->text($data['sofer_nume'] ?? null, 190),
                ':t' => $this->text($data['transportator'] ?? null, 190),
                ':tf' => !empty($data['transportator_furnizor_id']) ? (int) $data['transportator_furnizor_id'] : null,
                ':c' => $this->text($data['comanda_transport'] ?? null, 120),
                ':lp' => $this->text($data['loc_preluare'] ?? null, 255),
                ':ld' => $this->text($data['loc_destinatie'] ?? null, 255),
                ':np' => $this->text($data['numere_provizorii'] ?? null, 60),
                ':as' => $this->text($data['asigurare_tranzit_ref'] ?? null, 120),
                ':si' => $included !== [] ? mb_substr(implode(',', $included), 0, 500) : null,
                ':lc' => !empty($data['linie_cost_id']) ? (int) $data['linie_cost_id'] : null,
                ':dp' => $this->date($data['data_plecare'] ?? null),
                ':ds' => $this->date($data['data_sosire'] ?? null),
                ':lcl' => $this->date($data['livrare_confirmata_la'] ?? null),
                ':lcd' => !empty($data['livrare_confirmata_la']) ? $userId : null,
                ':o' => $this->text($data['observatii'] ?? null, 500),
            ];
            if ($id > 0) {
                $this->db->prepare(
                    'UPDATE achizitii_vehicule_transport SET modalitate = :m, sofer_id = :sid, sofer_nume = :sn, transportator = :t,
                        transportator_furnizor_id = :tf, comanda_transport = :c, loc_preluare = :lp, loc_destinatie = :ld,
                        numere_provizorii = :np, asigurare_tranzit_ref = :as, servicii_incluse = :si, linie_cost_id = :lc,
                        data_plecare = :dp, data_sosire = :ds, livrare_confirmata_la = :lcl, livrare_confirmata_de = :lcd,
                        observatii = :o, updated_at = NOW() WHERE id = :id'
                )->execute($values + [':id' => $id]);
            } else {
                $this->db->prepare(
                    'INSERT INTO achizitii_vehicule_transport (achizitie_id, modalitate, sofer_id, sofer_nume, transportator, transportator_furnizor_id,
                        comanda_transport, loc_preluare, loc_destinatie, numere_provizorii, asigurare_tranzit_ref, servicii_incluse, linie_cost_id,
                        data_plecare, data_sosire, livrare_confirmata_la, livrare_confirmata_de, observatii, created_by, created_at, updated_at)
                     VALUES (:a, :m, :sid, :sn, :t, :tf, :c, :lp, :ld, :np, :as, :si, :lc, :dp, :ds, :lcl, :lcd, :o, :u, NOW(), NOW())'
                )->execute($values + [':a' => $acquisitionId, ':u' => $userId]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->db->prepare('UPDATE achizitii_vehicule SET modalitate_livrare = :m, updated_at = NOW() WHERE id = :id')->execute([':m' => $mode, ':id' => $acquisitionId]);
            FinancialAuditLog::record($this->db, 'achizitie', $acquisitionId, 'transport', 'modalitate', null, $mode, null, ['servicii_incluse' => $included], $userId);
            return $id;
        });
    }

    // ------------------------------------------------------------------ cheltuieli

    /** Categoriile aplicabile dosarului (dupa modul de livrare si regimul de import). */
    public function categories(?int $acquisitionId = null): array
    {
        $rows = $this->db->query('SELECT * FROM achizitii_categorii_cheltuieli WHERE activ = 1 ORDER BY ordine, cod')->fetchAll(PDO::FETCH_ASSOC);
        if ($acquisitionId === null) {
            return $rows;
        }
        $dossier = $this->dossier($acquisitionId);
        $delivery = $dossier['modalitate_livrare'] ?? null;
        $import = ($dossier['regim_tva'] ?? '') === 'import';
        return array_values(array_filter($rows, static function (array $row) use ($delivery, $import): bool {
            if ((int) $row['doar_import'] === 1 && !$import) {
                return false;
            }
            return $row['context_livrare'] === 'oricare' || $delivery === null || $row['context_livrare'] === $delivery;
        }));
    }

    public function addCost(int $acquisitionId, array $data, ?int $userId): int
    {
        $category = (string) ($data['categorie'] ?? '');
        $categoryRow = $this->category($category);
        $leaf = $this->leafFor($data['financiar_categorie_id'] ?? null, $categoryRow);
        $amount = $this->money($data['suma_neta'] ?? null);
        $chelt = !empty($data['cheltuiala_id']) ? (int) $data['cheltuiala_id'] : null;
        if ($amount === null && $chelt === null) {
            throw new InvalidArgumentException('Suma este obligatorie (nu se estimează automat).');
        }
        $classification = (string) ($data['clasificare'] ?? $categoryRow['clasificare_implicita']);
        if (!in_array($classification, ['pret_achizitie', 'cost_direct_atribuibil', 'echipare_initiala', 'cheltuiala_perioada', 'tva'], true)) {
            throw new InvalidArgumentException('Clasificarea financiară este invalidă.');
        }
        $verification = (string) ($data['status_verificare'] ?? 'estimat');
        if (!in_array($verification, self::VERIFICATION, true)) {
            throw new InvalidArgumentException('Starea de verificare este invalidă.');
        }
        $deductible = (string) ($data['tva_deductibil'] ?? 'de_confirmat');
        if (!in_array($deductible, self::VAT_DEDUCTIBLE, true)) {
            throw new InvalidArgumentException('Deductibilitatea TVA este invalidă.');
        }

        return $this->transactional(function () use ($acquisitionId, $data, $userId, $category, $categoryRow, $leaf, $amount, $chelt, $classification, $verification, $deductible): int {
            $dossier = $this->lockDossier($acquisitionId);
            $included = !empty($data['inclus_in_linie_id']) ? (int) $data['inclus_in_linie_id'] : null;
            if ($included !== null) {
                $check = $this->db->prepare('SELECT COUNT(*) FROM achizitii_vehicule_cheltuieli WHERE id = :id AND achizitie_id = :a AND anulat_la IS NULL');
                $check->execute([':id' => $included, ':a' => $acquisitionId]);
                if ((int) $check->fetchColumn() === 0) {
                    throw new InvalidArgumentException('Linia în care e inclusă cheltuiala nu aparține dosarului.');
                }
            } else {
                // Transportator: serviciile deja incluse in pretul lui nu se mai adauga separat.
                $transport = $this->db->prepare("SELECT servicii_incluse, linie_cost_id FROM achizitii_vehicule_transport WHERE achizitie_id = :a AND modalitate = 'transportator' LIMIT 1");
                $transport->execute([':a' => $acquisitionId]);
                $row = $transport->fetch(PDO::FETCH_ASSOC);
                if ($row !== false && in_array($category, array_map('trim', explode(',', (string) $row['servicii_incluse'])), true)) {
                    throw new InvalidArgumentException('„' . $categoryRow['denumire'] . '” este inclusă în prețul transportatorului; adaug-o doar ca linie inclusă (inclus_in_linie_id) sau scoate-o din serviciile incluse.');
                }
            }
            if ($categoryRow['context_livrare'] !== 'oricare' && $dossier['modalitate_livrare'] !== null && $categoryRow['context_livrare'] !== $dossier['modalitate_livrare']) {
                throw new InvalidArgumentException('Categoria „' . $categoryRow['denumire'] . '” nu se aplică livrării „' . $dossier['modalitate_livrare'] . '”.');
            }

            $values = [
                'suma_neta' => $amount,
                'tva' => $this->money($data['tva'] ?? null),
                'moneda' => $this->currency($data['moneda'] ?? 'RON'),
                'furnizor' => $this->text($data['furnizor'] ?? null, 190),
                'cui' => $this->text($data['cui_furnizor'] ?? null, 20),
                'data' => $this->date($data['data_tranzactie'] ?? null),
                'numar' => $this->text($data['document_numar'] ?? null, 120),
            ];
            if ($chelt !== null) {
                // Aceeasi factura din pagina Cheltuieli: se preia, nu se introduce a doua oara.
                $dup = $this->db->prepare('SELECT achizitie_id FROM achizitii_vehicule_cheltuieli WHERE cheltuiala_id = :c AND anulat_la IS NULL LIMIT 1');
                $dup->execute([':c' => $chelt]);
                if ($dup->fetchColumn() !== false) {
                    throw new InvalidArgumentException('Cheltuiala #' . $chelt . ' este deja preluată într-un dosar de achiziție; nu se numără de două ori.');
                }
                $source = $this->db->prepare('SELECT * FROM cheltuieli WHERE id = :id');
                $source->execute([':id' => $chelt]);
                $expense = $source->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Cheltuiala legată nu există.');
                $values['suma_neta'] ??= $expense['valoare_neta'] !== null ? (float) $expense['valoare_neta'] : (float) $expense['valoare'];
                $values['tva'] ??= $expense['tva'] !== null ? (float) $expense['tva'] : null;
                $values['moneda'] = (string) $expense['moneda'];
                $values['furnizor'] ??= $expense['furnizor'];
                $values['cui'] ??= $expense['cui'];
                $values['data'] ??= $expense['data_cheltuiala'];
                $values['numar'] ??= $expense['numar_document'];
            }
            $rate = $this->rate($data['curs_schimb'] ?? null);
            $vatRecoverable = $this->money($data['tva_recuperabil'] ?? null);
            if ($vatRecoverable === null && $values['tva'] !== null) {
                $vatRecoverable = match ($deductible) {
                    'da' => $values['tva'],
                    'nu' => 0.0,
                    default => null, // partial / de_confirmat: necunoscut pana decide contabilitatea
                };
            }

            $this->db->prepare(
                'INSERT INTO achizitii_vehicule_cheltuieli
                    (achizitie_id, categorie, financiar_categorie_id, descriere, furnizor, furnizor_id, cui_furnizor, data_tranzactie, suma_neta, moneda, curs_schimb,
                     cota_tva, tva, tva_deductibil, tva_recuperabil, document_tip, document_numar, document_fisier, cheltuiala_id, clasificare,
                     tratament_contabil, status_verificare, inclus_in_linie_id, sursa_tip, sursa_id, observatii, created_by, updated_by, created_at, updated_at)
                 VALUES (:a, :cat, :leaf, :d, :f, :fid, :cui, :dt, :s, :m, :curs, :cota, :tva, :ded, :rec, :dtip, :dnr, :dfis, :ch, :cl,
                     \'de_confirmat\', :ver, :inc, :st, :sid, :o, :u, :u2, NOW(), NOW())'
            )->execute([
                ':a' => $acquisitionId,
                ':cat' => $category,
                ':leaf' => $leaf,
                ':d' => $this->text($data['descriere'] ?? null, 255) ?? $categoryRow['denumire'],
                ':f' => $values['furnizor'],
                ':fid' => !empty($data['furnizor_id']) ? (int) $data['furnizor_id'] : null,
                ':cui' => $values['cui'],
                ':dt' => $values['data'],
                ':s' => $values['suma_neta'],
                ':m' => $values['moneda'],
                ':curs' => $values['moneda'] === 'RON' ? null : $rate,
                ':cota' => isset($data['cota_tva']) && $data['cota_tva'] !== '' ? (float) $data['cota_tva'] : null,
                ':tva' => $values['tva'],
                ':ded' => $deductible,
                ':rec' => $vatRecoverable,
                ':dtip' => $this->text($data['document_tip'] ?? null, 40),
                ':dnr' => $values['numar'],
                ':dfis' => $this->text($data['document_fisier'] ?? null, 255),
                ':ch' => $chelt,
                ':cl' => $classification,
                ':ver' => $verification,
                ':inc' => $included,
                ':st' => $chelt !== null ? 'cheltuieli' : $this->text($data['sursa_tip'] ?? 'manual', 30),
                ':sid' => $chelt ?? (!empty($data['sursa_id']) ? (int) $data['sursa_id'] : null),
                ':o' => $this->text($data['observatii'] ?? null, 500),
                ':u' => $userId,
                ':u2' => $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            FinancialAuditLog::record($this->db, 'achizitie_cheltuiala', $id, 'creare', 'suma_neta', null, (string) $values['suma_neta'], null,
                ['achizitie_id' => $acquisitionId, 'categorie' => $category, 'financiar_categorie_id' => $leaf, 'clasificare' => $classification], $userId);
            return $id;
        });
    }

    /**
     * Anularea (stornarea) unei linii: ramane in dosar si in istoric, dar iese din toate totalurile
     * (subcategorie, categorie principala, dosar). Nu se sterge nimic.
     */
    public function cancelCost(int $lineId, string $reason, ?int $userId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Motivul anulării este obligatoriu.');
        }
        $this->transactional(function () use ($lineId, $reason, $userId): void {
            $line = $this->lockLine($lineId);
            $this->assertNotCancelled($line);
            $this->lockDossier((int) $line['achizitie_id']);
            $paid = $this->db->prepare('SELECT COUNT(*) FROM achizitii_vehicule_plati WHERE cheltuiala_linie_id = :l');
            $paid->execute([':l' => $lineId]);
            if ((int) $paid->fetchColumn() > 0) {
                throw new InvalidArgumentException('Linia are plăți înregistrate: nu se poate anula.');
            }
            $hosts = $this->db->prepare('SELECT COUNT(*) FROM achizitii_vehicule_cheltuieli WHERE inclus_in_linie_id = :l AND anulat_la IS NULL');
            $hosts->execute([':l' => $lineId]);
            if ((int) $hosts->fetchColumn() > 0) {
                throw new InvalidArgumentException('Alte linii sunt incluse în aceasta: anulează-le întâi.');
            }
            $this->db->prepare('UPDATE achizitii_vehicule_cheltuieli SET anulat_la = NOW(), anulat_de = :u, anulat_motiv = :m, updated_by = :u2, updated_at = NOW() WHERE id = :id')
                ->execute([':u' => $userId, ':m' => mb_substr($reason, 0, 500), ':u2' => $userId, ':id' => $lineId]);
            FinancialAuditLog::record($this->db, 'achizitie_cheltuiala', $lineId, 'anulare', 'anulat_la', null, date('Y-m-d H:i:s'), $reason,
                ['suma_neta' => (float) $line['suma_neta'], 'moneda' => $line['moneda']], $userId);
        });
    }

    public function setCostVerification(int $lineId, string $status, ?int $userId, string $reason = ''): void
    {
        if (!in_array($status, self::VERIFICATION, true)) {
            throw new InvalidArgumentException('Starea de verificare este invalidă.');
        }
        $this->updateLine($lineId, 'status_verificare', $status, $userId, $reason);
    }

    /** Doar deductibilitatea / TVA recuperabil: NU schimba clasificarea sau tratamentul. */
    public function setCostVat(int $lineId, string $deductible, ?float $recoverable, ?int $userId, string $reason = ''): void
    {
        if (!in_array($deductible, self::VAT_DEDUCTIBLE, true)) {
            throw new InvalidArgumentException('Deductibilitatea TVA este invalidă.');
        }
        $this->transactional(function () use ($lineId, $deductible, $recoverable, $userId, $reason): void {
            $row = $this->lockLine($lineId);
            $this->db->prepare('UPDATE achizitii_vehicule_cheltuieli SET tva_deductibil = :d, tva_recuperabil = :r, updated_by = :u, updated_at = NOW() WHERE id = :id')
                ->execute([':d' => $deductible, ':r' => $recoverable, ':u' => $userId, ':id' => $lineId]);
            FinancialAuditLog::record($this->db, 'achizitie_cheltuiala', $lineId, 'tva', 'tva_deductibil', (string) $row['tva_deductibil'], $deductible, $reason, ['tva_recuperabil' => $recoverable], $userId);
        });
    }

    public function addPayment(int $acquisitionId, array $data, ?int $userId): int
    {
        $amount = $this->money($data['suma'] ?? null);
        if ($amount === null || $amount <= 0) {
            throw new InvalidArgumentException('Suma plătită trebuie să fie pozitivă.');
        }
        $date = $this->date($data['data_plata'] ?? null) ?? throw new InvalidArgumentException('Data plății este invalidă.');
        return $this->transactional(function () use ($acquisitionId, $data, $userId, $amount, $date): int {
            $this->lockDossier($acquisitionId);
            $line = !empty($data['cheltuiala_linie_id']) ? (int) $data['cheltuiala_linie_id'] : null;
            if ($line !== null) {
                $lineRow = $this->lockLine($line);
                if ((int) $lineRow['achizitie_id'] !== $acquisitionId) {
                    throw new InvalidArgumentException('Linia de cost nu aparține dosarului.');
                }
                $this->assertNotCancelled($lineRow);
            }
            $currency = $this->currency($data['moneda'] ?? 'RON');
            $this->db->prepare(
                'INSERT INTO achizitii_vehicule_plati (achizitie_id, cheltuiala_linie_id, data_plata, suma, moneda, curs_schimb, metoda, referinta, created_by, created_at)
                 VALUES (:a, :l, :d, :s, :m, :c, :met, :ref, :u, NOW())'
            )->execute([':a' => $acquisitionId, ':l' => $line, ':d' => $date, ':s' => $amount, ':m' => $currency,
                ':c' => $currency === 'RON' ? null : $this->rate($data['curs_schimb'] ?? null), ':met' => $this->text($data['metoda'] ?? null, 40),
                ':ref' => $this->text($data['referinta'] ?? null, 120), ':u' => $userId]);
            $id = (int) $this->db->lastInsertId();
            FinancialAuditLog::record($this->db, 'achizitie', $acquisitionId, 'plata', 'suma', null, (string) $amount, null, ['plata_id' => $id, 'linie' => $line], $userId);
            return $id;
        });
    }

    public function addDocument(int $acquisitionId, string $type, ?string $number, ?string $original, ?string $stored, ?int $userId, ?int $lineId = null, ?int $vehicleDocumentId = null): int
    {
        $type = trim($type) ?: throw new InvalidArgumentException('Tipul documentului este obligatoriu.');
        $this->lockDossierNoTx($acquisitionId);
        $this->db->prepare(
            'INSERT INTO achizitii_vehicule_documente (achizitie_id, tip, numar, fisier_original, fisier_stocat, document_vehicul_id, cheltuiala_linie_id, created_by, created_at)
             VALUES (:a, :t, :n, :fo, :fs, :dv, :l, :u, NOW())'
        )->execute([':a' => $acquisitionId, ':t' => mb_substr($type, 0, 60), ':n' => $this->text($number, 120), ':fo' => $this->text($original, 255),
            ':fs' => $this->text($stored, 255), ':dv' => $vehicleDocumentId, ':l' => $lineId, ':u' => $userId]);
        return (int) $this->db->lastInsertId();
    }

    // ------------------------------------------------------------------ valori calculate

    /**
     * Valori separate, calculate doar din tranzactiile introduse (in RON). Liniile in alta moneda fara
     * curs nu se convertesc: apar in `incomplet`. Liniile „incluse” in alta linie nu se aduna.
     */
    public function summary(int $acquisitionId): array
    {
        $dossier = $this->dossier($acquisitionId);
        $lines = $this->db->prepare('SELECT * FROM achizitii_vehicule_cheltuieli WHERE achizitie_id = :a ORDER BY id');
        $lines->execute([':a' => $acquisitionId]);
        $out = [
            'pret_achizitie' => 0.0,
            'cheltuieli_asociate' => 0.0,
            'cost_initial_capitalizabil' => 0.0,
            'necapitalizabile' => 0.0,
            'tratament_neconfirmat' => 0.0,
            'estimat' => 0.0,
            'confirmat' => 0.0,
            'tva_total' => 0.0,
            'tva_recuperabil' => 0.0,
            'tva_nerecuperabil' => 0.0,
            'tva_neclarificat' => 0.0,
            'total_document' => 0.0,
            'platit' => 0.0,
            'sold_neachitat' => 0.0,
            'incomplet' => [],
            'linii' => 0,
            'linii_incluse' => 0,
            'linii_anulate' => 0,
        ];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            if (($line['anulat_la'] ?? null) !== null) {
                $out['linii_anulate']++;
                continue;
            }
            if ($line['inclus_in_linie_id'] !== null) {
                $out['linii_incluse']++;
                continue;
            }
            $out['linii']++;
            $factor = $this->ronFactor((string) $line['moneda'], $line['curs_schimb']);
            if ($factor === null) {
                $out['incomplet'][] = 'Linia #' . $line['id'] . ' (' . $line['moneda'] . ') fără curs de schimb';
                continue;
            }
            $net = (float) $line['suma_neta'] * $factor;
            $vat = $line['tva'] !== null ? (float) $line['tva'] * $factor : 0.0;
            $isVatLine = $line['clasificare'] === 'tva';
            if ($isVatLine) {
                $vat += $net; // linia „TVA import”: suma ei este TVA
                $net = 0.0;
            }
            $out['tva_total'] += $vat;
            if ($line['tva_recuperabil'] !== null) {
                $recoverable = (float) $line['tva_recuperabil'] * $factor;
                $out['tva_recuperabil'] += $recoverable;
                $out['tva_nerecuperabil'] += max(0.0, $vat - $recoverable);
            } elseif ($line['tva_deductibil'] === 'nu') {
                $out['tva_nerecuperabil'] += $vat;
            } else {
                $out['tva_neclarificat'] += $vat;
            }
            $out['total_document'] += $net + $vat;
            if ($line['status_verificare'] === 'estimat') {
                $out['estimat'] += $net;
            } else {
                $out['confirmat'] += $net;
            }
            if ($isVatLine) {
                continue;
            }
            if ($line['clasificare'] === 'pret_achizitie') {
                $out['pret_achizitie'] += $net;
            } else {
                $out['cheltuieli_asociate'] += $net;
            }
            match ($line['tratament_contabil']) {
                'capitalizabil' => $out['cost_initial_capitalizabil'] += $net,
                'cheltuiala' => $out['necapitalizabile'] += $net,
                default => $out['tratament_neconfirmat'] += $net,
            };
        }
        $payments = $this->db->prepare('SELECT * FROM achizitii_vehicule_plati WHERE achizitie_id = :a');
        $payments->execute([':a' => $acquisitionId]);
        foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $factor = $this->ronFactor((string) $payment['moneda'], $payment['curs_schimb']);
            if ($factor === null) {
                $out['incomplet'][] = 'Plata #' . $payment['id'] . ' (' . $payment['moneda'] . ') fără curs de schimb';
                continue;
            }
            $out['platit'] += (float) $payment['suma'] * $factor;
        }
        $out['sold_neachitat'] = $out['total_document'] - $out['platit'];
        foreach ($out as $key => $value) {
            if (is_float($value)) {
                $out[$key] = round($value, 2);
            }
        }
        $events = $this->events($acquisitionId);
        $out['data_receptie'] = $events['receptie_tehnica'] ?? null;
        $out['data_punere_in_functiune'] = $events['punere_in_functiune'] ?? null;
        $out['pret_contract'] = $dossier['pret_achizitie'] !== null ? (float) $dossier['pret_achizitie'] : null;
        $out['moneda_contract'] = $dossier['moneda'];
        if ($out['tratament_neconfirmat'] > 0) {
            $out['incomplet'][] = 'Tratament contabil neconfirmat pentru ' . number_format($out['tratament_neconfirmat'], 2, ',', '.') . ' lei';
        }
        return $out;
    }

    public function dossier(int $acquisitionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM achizitii_vehicule WHERE id = :id');
        $stmt->execute([':id' => $acquisitionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Dosarul de achiziție nu există.');
    }

    // ------------------------------------------------------------------ intern

    private function updateLine(int $lineId, string $field, string $value, ?int $userId, string $reason): void
    {
        $this->transactional(function () use ($lineId, $field, $value, $userId, $reason): void {
            $row = $this->lockLine($lineId);
            $this->db->prepare("UPDATE achizitii_vehicule_cheltuieli SET `$field` = :v, updated_by = :u, updated_at = NOW() WHERE id = :id")
                ->execute([':v' => $value, ':u' => $userId, ':id' => $lineId]);
            FinancialAuditLog::record($this->db, 'achizitie_cheltuiala', $lineId, $field, $field, (string) $row[$field], $value, $reason, null, $userId);
        });
    }

    private function assertNotCancelled(array $line): void
    {
        if (($line['anulat_la'] ?? null) !== null) {
            throw new InvalidArgumentException('Linia #' . $line['id'] . ' este anulată.');
        }
    }

    /** Subcategoria (frunza) liniei: cea aleasa, altfel subcategoria implicita a tipului de cost. */
    private function leafFor(mixed $requested, array $categoryRow): int
    {
        $tree = new FinancialCategoryTree($this->db);
        $typeLeaf = ($categoryRow['financiar_categorie_id'] ?? null) !== null ? (int) $categoryRow['financiar_categorie_id'] : null;
        $leaf = $requested !== null && (string) $requested !== '' ? (int) $requested : $tree->resolveLeaf(null, $typeLeaf);
        if ($leaf === null) {
            throw new InvalidArgumentException('Nu există subcategorie financiară pentru „' . $categoryRow['denumire'] . '”.');
        }
        $tree->assertAssignableLeaf($leaf);
        return $leaf;
    }

    private function category(string $code): array
    {
        $stmt = $this->db->prepare('SELECT * FROM achizitii_categorii_cheltuieli WHERE cod = :c AND activ = 1');
        $stmt->execute([':c' => $code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Categoria de cheltuială nu există sau este inactivă.');
    }

    private function eventDate(int $acquisitionId, string $type): ?string
    {
        $stmt = $this->db->prepare('SELECT data_eveniment FROM achizitii_vehicule_evenimente WHERE achizitie_id = :a AND tip = :t');
        $stmt->execute([':a' => $acquisitionId, ':t' => $type]);
        $date = $stmt->fetchColumn();
        return $date === false ? null : (string) $date;
    }

    private function assertVehicleFree(int $vehicleId, ?int $exceptId): void
    {
        $exists = $this->db->prepare('SELECT COUNT(*) FROM vehicule WHERE id = :id');
        $exists->execute([':id' => $vehicleId]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new InvalidArgumentException('Vehiculul nu există.');
        }
        $other = $this->db->prepare("SELECT cod_dosar FROM achizitii_vehicule WHERE vehicle_id = :v AND status <> 'anulat' AND id <> :e LIMIT 1");
        $other->execute([':v' => $vehicleId, ':e' => (int) $exceptId]);
        $code = $other->fetchColumn();
        if ($code !== false) {
            throw new InvalidArgumentException('Vehiculul are deja dosarul de achiziție ' . $code . '.');
        }
    }

    private function vehicleVin(int $vehicleId): ?string
    {
        $stmt = $this->db->prepare('SELECT serie_sasiu FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);
        $vin = $stmt->fetchColumn();
        return $vin === false ? null : (string) $vin;
    }

    private function lockDossier(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM achizitii_vehicule WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Dosarul de achiziție nu există.');
        if ($row['status'] === 'anulat') {
            throw new InvalidArgumentException('Dosarul de achiziție este anulat.');
        }
        return $row;
    }

    private function lockDossierNoTx(int $id): void
    {
        $this->dossier($id);
    }

    private function lockLine(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM achizitii_vehicule_cheltuieli WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Linia de cost nu există.');
    }

    private function ronFactor(string $currency, mixed $rate): ?float
    {
        if ($currency === 'RON') {
            return 1.0;
        }
        return $rate !== null && (float) $rate > 0 ? (float) $rate : null;
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

    private function normalizeVin(mixed $vin): ?string
    {
        $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($vin ?? '')) ?? '');
        if ($vin === '') {
            return null;
        }
        if (strlen($vin) > 17) {
            throw new InvalidArgumentException('VIN-ul are cel mult 17 caractere.');
        }
        return $vin;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], (string) $value);
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Sumă invalidă: ' . $value);
        }
        return round((float) $value, 2);
    }

    private function rate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (float) str_replace(',', '.', (string) $value);
        return $value > 0 ? $value : null;
    }

    private function currency(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : 'RON';
    }

    private function country(mixed $value): ?string
    {
        $value = strtoupper(trim((string) ($value ?? '')));
        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        $parsed = DateTime::createFromFormat('Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
