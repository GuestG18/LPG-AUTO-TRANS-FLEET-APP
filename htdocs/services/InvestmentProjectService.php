<?php
declare(strict_types=1);

require_once __DIR__ . '/FinancialAuditLog.php';
require_once __DIR__ . '/CostNature.php';

/**
 * Proiecte de investitii (ex. „Adaptare semiremorca pentru GPL”): antet usor peste tranzactiile existente.
 *
 * - Proiectul apartine vehiculului FIZIC pe care se monteaza echipamentul (semiremorca A ramane A, oricum
 *   s-ar cupla). vehicule_cuplaje nu se modifica si nu muta proprietatea financiara.
 * - Costul real NU se introduce manual: se agrega din sursele legate (mentenanta, cheltuieli), deduplicat
 *   pe (sursa, sursa_id). Interventiile / facturile OCR legate dar netrimise apar separat, ca neinregistrate.
 * - Bugetul planificat ramane editabil independent.
 * - Pregatirea pentru GPL are stadii separate; un proiect finalizat financiar NU inseamna vehicul apt legal.
 */
final class InvestmentProjectService
{
    public const CATEGORIES = [
        'adaptare_gpl' => 'Adaptare GPL',
        'echipare_initiala' => 'Echipare inițială',
        'modernizare' => 'Modernizare',
        'extindere_capacitate' => 'Extindere capacitate',
        'alte_investitii' => 'Alte investiții',
    ];
    public const STATUSES = ['planificat', 'in_executie', 'finalizat', 'anulat'];
    public const STAGES = [
        'lucrari_finalizate' => 'Lucrări finalizate',
        'echipamente_montate' => 'Echipamente montate',
        'inspectie_tehnica' => 'Inspecție tehnică efectuată',
        'documente_disponibile' => 'Documente obligatorii disponibile',
        'aprobat_activitate' => 'Aprobat pentru activitatea vizată',
        'pus_in_functiune' => 'Pus în funcțiune',
    ];
    /** Legaturi permise: entitate => [tabela, coloana vehicul (pentru verificarea proprietarului)] */
    private const LINKS = [
        'interventie' => ['mentenanta_interventii_programate', 'vehicle_id'],
        'mentenanta' => ['mentenanta', 'vehicle_id'],
        'ocr_reparatie' => ['ocr_reparatii', 'vehicle_id'],
        'cheltuiala' => ['cheltuieli', null],
        'document' => ['documente', 'vehicle_id'],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function create(array $data, ?int $userId): int
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $exists = $this->db->prepare('SELECT COUNT(*) FROM vehicule WHERE id = :id');
        $exists->execute([':id' => $vehicleId]);
        if ($vehicleId <= 0 || (int) $exists->fetchColumn() === 0) {
            throw new InvalidArgumentException('Alege vehiculul fizic pe care se face investiția.');
        }
        $name = trim((string) ($data['denumire'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Denumirea proiectului este obligatorie.');
        }
        $category = (string) ($data['categorie'] ?? '');
        if (!isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Categoria proiectului este invalidă.');
        }
        $nature = CostNature::normalize($data['natura_cost'] ?? null) ?? ($category === 'echipare_initiala' ? 'echipare_initiala' : 'investitie');
        if (!CostNature::isCapitalNature($nature)) {
            throw new InvalidArgumentException('Natura unui proiect este investiție sau echipare inițială.');
        }
        $budget = isset($data['buget_planificat']) && $data['buget_planificat'] !== '' ? round((float) $data['buget_planificat'], 2) : null;

        $this->db->prepare(
            'INSERT INTO investitii_proiecte (vehicle_id, denumire, categorie, natura_cost, tratament_contabil, buget_planificat, moneda_buget,
                data_start, status, responsabil_user_id, achizitie_id, observatii, created_by, updated_by, created_at, updated_at)
             VALUES (:v, :d, :c, :n, \'de_confirmat\', :b, :m, :ds, :s, :r, :a, :o, :u, :u2, NOW(), NOW())'
        )->execute([
            ':v' => $vehicleId,
            ':d' => mb_substr($name, 0, 190),
            ':c' => $category,
            ':n' => $nature,
            ':b' => $budget,
            ':m' => preg_match('/^[A-Z]{3}$/', (string) ($data['moneda_buget'] ?? '')) === 1 ? $data['moneda_buget'] : 'RON',
            ':ds' => $this->date($data['data_start'] ?? null),
            ':s' => in_array($data['status'] ?? null, self::STATUSES, true) ? $data['status'] : 'planificat',
            ':r' => !empty($data['responsabil_user_id']) ? (int) $data['responsabil_user_id'] : null,
            ':a' => !empty($data['achizitie_id']) ? (int) $data['achizitie_id'] : null,
            ':o' => trim((string) ($data['observatii'] ?? '')) ?: null,
            ':u' => $userId,
            ':u2' => $userId,
        ]);
        $id = (int) $this->db->lastInsertId();
        FinancialAuditLog::record($this->db, 'proiect_investitie', $id, 'creare', null, null, $name, null, ['vehicle_id' => $vehicleId, 'buget' => $budget], $userId);
        return $id;
    }

    /** Buget, status, responsabil, date, observatii. Vehiculul NU se schimba (proprietatea fizica). */
    public function update(int $projectId, array $data, ?int $userId): void
    {
        $this->transactional(function () use ($projectId, $data, $userId): void {
            $project = $this->lock($projectId);
            if (array_key_exists('vehicle_id', $data) && (int) $data['vehicle_id'] !== (int) $project['vehicle_id']) {
                throw new InvalidArgumentException('Vehiculul proiectului nu se schimbă (investiția rămâne pe activul fizic).');
            }
            $fields = [];
            if (array_key_exists('buget_planificat', $data)) {
                $fields['buget_planificat'] = $data['buget_planificat'] === '' || $data['buget_planificat'] === null ? null : round((float) $data['buget_planificat'], 2);
            }
            if (array_key_exists('status', $data)) {
                if (!in_array($data['status'], self::STATUSES, true)) {
                    throw new InvalidArgumentException('Status invalid.');
                }
                $fields['status'] = $data['status'];
            }
            foreach (['data_start', 'data_punere_in_functiune'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $this->date($data[$field]);
                }
            }
            if (array_key_exists('responsabil_user_id', $data)) {
                $fields['responsabil_user_id'] = !empty($data['responsabil_user_id']) ? (int) $data['responsabil_user_id'] : null;
            }
            foreach (['denumire', 'observatii'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = trim((string) $data[$field]) ?: null;
                }
            }
            if (($fields['denumire'] ?? 'x') === null) {
                throw new InvalidArgumentException('Denumirea proiectului este obligatorie.');
            }
            $sets = [];
            $params = [':id' => $projectId, ':u' => $userId];
            foreach ($fields as $field => $value) {
                $old = $project[$field];
                if ((string) $old === (string) $value) {
                    continue;
                }
                $sets[] = "$field = :$field";
                $params[":$field"] = $value;
                FinancialAuditLog::record($this->db, 'proiect_investitie', $projectId, 'modificare', $field, $old === null ? null : (string) $old, $value === null ? null : (string) $value, null, null, $userId);
            }
            if ($sets !== []) {
                $this->db->prepare('UPDATE investitii_proiecte SET ' . implode(', ', $sets) . ', updated_by = :u, updated_at = NOW() WHERE id = :id')->execute($params);
            }
        });
    }

    /** Durata utila se completeaza doar cand o aproba contabilitatea (cine + cand, in istoric). */
    public function approveUsefulLife(int $projectId, int $months, ?int $userId, string $reason): void
    {
        if ($months <= 0 || $months > 600) {
            throw new InvalidArgumentException('Durata utilă (luni) este invalidă.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Temeiul aprobării duratei utile este obligatoriu.');
        }
        $this->transactional(function () use ($projectId, $months, $userId, $reason): void {
            $project = $this->lock($projectId);
            $this->db->prepare('UPDATE investitii_proiecte SET durata_utila_luni = :m, durata_utila_aprobata_de = :u, durata_utila_aprobata_la = NOW(), updated_at = NOW() WHERE id = :id')
                ->execute([':m' => $months, ':u' => $userId, ':id' => $projectId]);
            FinancialAuditLog::record($this->db, 'proiect_investitie', $projectId, 'durata_utila', 'durata_utila_luni', $project['durata_utila_luni'] !== null ? (string) $project['durata_utila_luni'] : null, (string) $months, $reason, null, $userId);
        });
    }

    /**
     * Leaga o sursa existenta de proiect. Sursa trebuie sa fie pe acelasi vehicul fizic (cheltuiala: alocata
     * vehiculului proiectului). Interventia propaga proiectul randurilor ei de mentenanta de natura investitie.
     */
    public function link(int $projectId, string $entity, int $entityId, ?int $userId): void
    {
        [$table, $vehicleColumn] = self::LINKS[$entity] ?? throw new InvalidArgumentException('Sursă necunoscută: ' . $entity);
        $this->transactional(function () use ($projectId, $entity, $entityId, $userId, $table, $vehicleColumn): void {
            $project = $this->lock($projectId);
            $stmt = $this->db->prepare("SELECT * FROM `$table` WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $entityId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Sursa nu există.');
            if ($vehicleColumn !== null && (int) $row[$vehicleColumn] !== (int) $project['vehicle_id']) {
                throw new InvalidArgumentException('Sursa aparține altui vehicul decât proiectul (proiectul rămâne pe activul fizic).');
            }
            if ($entity === 'cheltuiala') {
                $alloc = $this->db->prepare("SELECT COUNT(*) FROM cheltuieli_alocari WHERE cheltuiala_id = :c AND tip_alocare = 'vehicul' AND vehicul_id = :v");
                $alloc->execute([':c' => $entityId, ':v' => (int) $project['vehicle_id']]);
                if ((int) $alloc->fetchColumn() === 0) {
                    throw new InvalidArgumentException('Cheltuiala nu este alocată vehiculului proiectului.');
                }
                $acq = $this->db->prepare('SELECT COUNT(*) FROM achizitii_vehicule_cheltuieli WHERE cheltuiala_id = :c AND anulat_la IS NULL');
                $acq->execute([':c' => $entityId]);
                if ((int) $acq->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Cheltuiala este deja în dosarul de achiziție; nu se numără de două ori.');
                }
            }
            $old = $row['proiect_investitie_id'] ?? null;
            if ($old !== null && (int) $old !== $projectId) {
                throw new InvalidArgumentException('Sursa este deja legată de proiectul #' . $old . '.');
            }
            $this->db->prepare("UPDATE `$table` SET proiect_investitie_id = :p WHERE id = :id")->execute([':p' => $projectId, ':id' => $entityId]);
            if ($entity === 'interventie') {
                $this->db->prepare(
                    "UPDATE mentenanta SET proiect_investitie_id = :p
                      WHERE source_intervention_id = :i AND proiect_investitie_id IS NULL AND natura_cost IN ('investitie','echipare_initiala')"
                )->execute([':p' => $projectId, ':i' => $entityId]);
            }
            if ($entity === 'ocr_reparatie') {
                $this->db->prepare(
                    "UPDATE mentenanta SET proiect_investitie_id = :p
                      WHERE sursa_tip = 'ocr_reparatie' AND sursa_id = :i AND proiect_investitie_id IS NULL AND natura_cost IN ('investitie','echipare_initiala')"
                )->execute([':p' => $projectId, ':i' => $entityId]);
            }
            FinancialAuditLog::record($this->db, 'proiect_investitie', $projectId, 'legare', $entity, null, (string) $entityId, null, null, $userId);
        });
    }

    public function unlink(int $projectId, string $entity, int $entityId, ?int $userId, string $reason = ''): void
    {
        [$table] = self::LINKS[$entity] ?? throw new InvalidArgumentException('Sursă necunoscută: ' . $entity);
        $this->transactional(function () use ($projectId, $entity, $entityId, $userId, $table, $reason): void {
            $this->lock($projectId);
            $this->db->prepare("UPDATE `$table` SET proiect_investitie_id = NULL WHERE id = :id AND proiect_investitie_id = :p")->execute([':id' => $entityId, ':p' => $projectId]);
            FinancialAuditLog::record($this->db, 'proiect_investitie', $projectId, 'dezlegare', $entity, (string) $entityId, null, $reason, null, $userId);
        });
    }

    /**
     * Totalul real din surse, deduplicat pe (sursa, sursa_id):
     * - mentenanta legata (piese / manopera / alte), inclusiv randurile din interventii si OCR deja trimise;
     * - cheltuieli legate: suma alocata vehiculului (neta daca exista; altfel bruta, marcat);
     * - interventiile / facturile OCR legate dar inca neinregistrate: separat, NU in total.
     */
    public function totals(int $projectId): array
    {
        $project = $this->project($projectId);
        $rows = [];
        $add = static function (string $source, int $id, string $date, string $kind, float $amount, array $extra = []) use (&$rows): void {
            $rows[$source . ':' . $id] ??= ['sursa' => $source, 'sursa_id' => $id, 'data' => $date, 'tip' => $kind, 'suma' => round($amount, 2)] + $extra;
        };
        $maintenance = $this->db->prepare(
            "SELECT id, data_interventie, cost, cost_piese, cost_manopera, natura_cost, tratament_contabil, sursa_tip, sursa_id, source_intervention_id
               FROM mentenanta WHERE proiect_investitie_id = :p AND (status_interventie IS NULL OR status_interventie <> 'anulata')"
        );
        $maintenance->execute([':p' => $projectId]);
        $parts = 0.0;
        $labor = 0.0;
        $other = 0.0;
        $sentOcr = [];
        $finalized = [];
        foreach ($maintenance->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $add('mentenanta', (int) $m['id'], (string) $m['data_interventie'], 'lucrare', (float) $m['cost'],
                ['natura_cost' => $m['natura_cost'], 'tratament_contabil' => $m['tratament_contabil']]);
            $parts += (float) $m['cost_piese'];
            $labor += (float) $m['cost_manopera'];
            $other += max(0.0, (float) $m['cost'] - (float) $m['cost_piese'] - (float) $m['cost_manopera']);
            if ($m['sursa_tip'] === 'ocr_reparatie') {
                $sentOcr[(int) $m['sursa_id']] = true;
            }
            if ($m['source_intervention_id'] !== null) {
                $finalized[(int) $m['source_intervention_id']] = true;
            }
        }
        $expenses = $this->db->prepare(
            "SELECT c.id, c.data_cheltuiala, c.valoare, c.valoare_neta, c.tva, t.nume AS tip_nume, a.suma AS alocat
               FROM cheltuieli c
               JOIN cheltuieli_alocari a ON a.cheltuiala_id = c.id AND a.tip_alocare = 'vehicul' AND a.vehicul_id = :v
               LEFT JOIN cheltuieli_tipuri t ON t.id = c.tip_id
              WHERE c.proiect_investitie_id = :p
                AND NOT EXISTS (SELECT 1 FROM achizitii_vehicule_cheltuieli ac WHERE ac.cheltuiala_id = c.id AND ac.anulat_la IS NULL)"
        );
        $expenses->execute([':p' => $projectId, ':v' => (int) $project['vehicle_id']]);
        $services = 0.0;
        $warnings = [];
        foreach ($expenses->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $gross = (float) $e['valoare'];
            $allocated = (float) $e['alocat'];
            // Suma neta proportionala cu alocarea vehiculului; fara net => bruta, semnalat.
            $amount = $e['valoare_neta'] !== null && $gross > 0 ? $allocated * (float) $e['valoare_neta'] / $gross : $allocated;
            if ($e['valoare_neta'] === null) {
                $warnings[] = 'Cheltuiala #' . $e['id'] . ' fără valoare netă: inclusă cu TVA.';
            }
            if (preg_match('/repara|piese|anvelop/i', (string) $e['tip_nume'])) {
                $warnings[] = 'Cheltuiala #' . $e['id'] . ' (' . $e['tip_nume'] . ') poate dubla o înregistrare din Reparații: verificați că factura nu e și în mentenanță.';
            }
            $add('cheltuiala', (int) $e['id'], (string) $e['data_cheltuiala'], 'serviciu_extern', $amount);
            $services += round($amount, 2);
        }

        $pending = [];
        $interventions = $this->db->prepare('SELECT id, status_interventie, cost_estimat FROM mentenanta_interventii_programate WHERE proiect_investitie_id = :p');
        $interventions->execute([':p' => $projectId]);
        foreach ($interventions->fetchAll(PDO::FETCH_ASSOC) as $i) {
            if (!isset($finalized[(int) $i['id']]) && $i['status_interventie'] !== 'anulata') {
                $pending[] = ['sursa' => 'interventie', 'sursa_id' => (int) $i['id'], 'status' => $i['status_interventie'], 'cost_estimat' => (float) $i['cost_estimat']];
            }
        }
        $ocr = $this->db->prepare('SELECT id FROM ocr_reparatii WHERE proiect_investitie_id = :p');
        $ocr->execute([':p' => $projectId]);
        foreach ($ocr->fetchAll(PDO::FETCH_COLUMN) as $ocrId) {
            if (!isset($sentOcr[(int) $ocrId])) {
                $pending[] = ['sursa' => 'ocr_reparatie', 'sursa_id' => (int) $ocrId, 'status' => 'netrimisa_in_reparatii', 'cost_estimat' => null];
            }
        }
        $documents = $this->db->prepare('SELECT id, tip_document, numar_document, data_expirare FROM documente WHERE proiect_investitie_id = :p ORDER BY id');
        $documents->execute([':p' => $projectId]);

        $actual = round(array_sum(array_column($rows, 'suma')), 2);
        $budget = $project['buget_planificat'] !== null ? (float) $project['buget_planificat'] : null;
        return [
            'proiect_id' => $projectId,
            'vehicle_id' => (int) $project['vehicle_id'],
            'buget_planificat' => $budget,
            'cost_real' => $actual,
            'diferenta_buget' => $budget !== null ? round($budget - $actual, 2) : null,
            'piese' => round($parts, 2),
            'manopera' => round($labor, 2),
            'alte_costuri_lucrari' => round($other, 2),
            'servicii_externe' => round($services, 2),
            'surse' => array_values($rows),
            'neinregistrate' => $pending,
            'documente' => $documents->fetchAll(PDO::FETCH_ASSOC),
            'avertismente' => $warnings,
        ];
    }

    public function confirmStage(int $projectId, string $stage, string $date, ?int $userId, ?int $documentId = null, ?string $notes = null): void
    {
        if (!isset(self::STAGES[$stage])) {
            throw new InvalidArgumentException('Stadiu invalid.');
        }
        $date = $this->date($date) ?? throw new InvalidArgumentException('Data stadiului este invalidă.');
        $this->transactional(function () use ($projectId, $stage, $date, $userId, $documentId, $notes): void {
            $project = $this->lock($projectId);
            if ($documentId !== null) {
                $doc = $this->db->prepare('SELECT vehicle_id FROM documente WHERE id = :id');
                $doc->execute([':id' => $documentId]);
                if ((int) $doc->fetchColumn() !== (int) $project['vehicle_id']) {
                    throw new InvalidArgumentException('Documentul aparține altui vehicul.');
                }
            }
            if ($stage === 'pus_in_functiune') {
                $this->db->prepare('UPDATE investitii_proiecte SET data_punere_in_functiune = :d, updated_at = NOW() WHERE id = :id')->execute([':d' => $date, ':id' => $projectId]);
            }
            $this->db->prepare(
                'INSERT INTO investitii_proiecte_stadii (proiect_id, stadiu, confirmat_la, confirmat_de, document_id, observatii, created_at)
                 VALUES (:p, :s, :d, :u, :doc, :o, NOW())
                 ON DUPLICATE KEY UPDATE confirmat_la = VALUES(confirmat_la), confirmat_de = VALUES(confirmat_de), document_id = VALUES(document_id), observatii = VALUES(observatii)'
            )->execute([':p' => $projectId, ':s' => $stage, ':d' => $date, ':u' => $userId, ':doc' => $documentId, ':o' => $notes !== null ? mb_substr($notes, 0, 500) : null]);
            FinancialAuditLog::record($this->db, 'proiect_investitie', $projectId, 'stadiu', $stage, null, $date, $notes, ['document_id' => $documentId], $userId);
        });
    }

    /**
     * Pregatirea pentru GPL. Documentele obligatorii se citesc din pagina Documente (vehiculul proiectului),
     * cu valabilitatea lor. `apt_legal` = aprobat pentru activitate + toate documentele cerute valabile;
     * statusul financiar „finalizat” nu intra in calcul.
     *
     * @param list<string> $requiredDocumentTypes ex. ['ADR', 'ORGANISM NOTIFICAT', 'METROLOGIE']
     */
    public function readiness(int $projectId, array $requiredDocumentTypes, ?string $onDate = null): array
    {
        $project = $this->project($projectId);
        $onDate ??= date('Y-m-d');
        $stmt = $this->db->prepare('SELECT stadiu, confirmat_la, document_id FROM investitii_proiecte_stadii WHERE proiect_id = :p');
        $stmt->execute([':p' => $projectId]);
        $confirmed = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'stadiu');
        $stages = [];
        foreach (self::STAGES as $key => $label) {
            $stages[$key] = ['eticheta' => $label, 'confirmat_la' => $confirmed[$key]['confirmat_la'] ?? null];
        }
        $documents = [];
        $allValid = true;
        foreach ($requiredDocumentTypes as $type) {
            $doc = $this->db->prepare(
                'SELECT id, numar_document, data_expirare FROM documente
                  WHERE vehicle_id = :v AND UPPER(TRIM(tip_document)) = UPPER(TRIM(:t))
                  ORDER BY (data_expirare IS NULL) DESC, data_expirare DESC, id DESC LIMIT 1'
            );
            $doc->execute([':v' => (int) $project['vehicle_id'], ':t' => $type]);
            $row = $doc->fetch(PDO::FETCH_ASSOC) ?: null;
            $valid = $row !== null && ($row['data_expirare'] === null || (string) $row['data_expirare'] >= $onDate);
            $allValid = $allValid && $valid;
            $documents[$type] = ['document_id' => $row !== null ? (int) $row['id'] : null, 'data_expirare' => $row['data_expirare'] ?? null,
                'stare' => $row === null ? 'lipsa' : ($valid ? 'valabil' : 'expirat')];
        }
        $approved = $stages['aprobat_activitate']['confirmat_la'] !== null;
        return [
            'proiect_id' => $projectId,
            'vehicle_id' => (int) $project['vehicle_id'],
            'status_financiar' => $project['status'],
            'stadii' => $stages,
            'documente' => $documents,
            'documente_valabile' => $allValid,
            'apt_legal' => $approved && $allValid,
            'pus_in_functiune' => $stages['pus_in_functiune']['confirmat_la'] !== null,
        ];
    }

    public function project(int $projectId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM investitii_proiecte WHERE id = :id');
        $stmt->execute([':id' => $projectId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Proiectul nu există.');
    }

    /** @return list<array<string,mixed>> proiectele vehiculului fizic */
    public function forVehicle(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM investitii_proiecte WHERE vehicle_id = :v ORDER BY COALESCE(data_start, created_at), id');
        $stmt->execute([':v' => $vehicleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function lock(int $projectId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM investitii_proiecte WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $projectId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Proiectul nu există.');
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

    private function date(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        $parsed = DateTime::createFromFormat('Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
