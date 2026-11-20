<?php
declare(strict_types=1);

require_once __DIR__ . '/CostNature.php';
require_once __DIR__ . '/FinancialAuditLog.php';

/**
 * Schimbarea naturii costului si a tratamentului contabil, cu istoric in financiar_audit.
 * Permisiunea (mentenanta.financial_classification) se verifica in controller; aici doar regulile.
 *
 * Reguli:
 * - capitalizabil doar pentru natura investitie / echipare_initiala si doar cu motiv;
 * - schimbarea naturii catre reparatie / mentenanta anuleaza un tratament capitalizabil (devine de_confirmat);
 * - record_type NU se modifica (compatibilitate); costul/km citeste natura efectiva.
 */
final class FinancialClassificationService
{
    /** entitate => [tabela, are natura proprie] */
    private const ENTITIES = [
        'mentenanta' => ['mentenanta', true],
        'interventie' => ['mentenanta_interventii_programate', true],
        'proiect_investitie' => ['investitii_proiecte', true],
        'achizitie_cheltuiala' => ['achizitii_vehicule_cheltuieli', false],
    ];

    public function __construct(private PDO $db)
    {
    }

    public function setTreatment(string $entity, int $id, string $treatment, ?int $userId, string $reason = ''): void
    {
        [$table] = $this->entity($entity);
        $treatment = CostNature::normalizeTreatment($treatment)
            ?? throw new InvalidArgumentException('Tratament contabil invalid.');
        $reason = trim($reason);

        $this->transactional(function () use ($entity, $table, $id, $treatment, $userId, $reason): void {
            $row = $this->lockRow($table, $id);
            $nature = $entity === 'achizitie_cheltuiala' ? null : (string) ($row['natura_cost'] ?? '');
            if ($treatment === 'capitalizabil') {
                if ($entity === 'achizitie_cheltuiala') {
                    if (in_array((string) $row['clasificare'], ['tva', 'cheltuiala_perioada'], true)) {
                        throw new InvalidArgumentException('TVA-ul și cheltuielile perioadei nu se capitalizează.');
                    }
                } elseif (!CostNature::isCapitalNature($nature)) {
                    throw new InvalidArgumentException('Doar o investiție sau o echipare inițială poate fi capitalizabilă.');
                }
                if ($reason === '') {
                    throw new InvalidArgumentException('Motivul (temeiul contabil) este obligatoriu pentru „capitalizabil”.');
                }
            }
            $old = (string) ($row['tratament_contabil'] ?? '');
            if ($old === $treatment) {
                return;
            }
            $this->db->prepare("UPDATE `$table` SET tratament_contabil = :t WHERE id = :id")->execute([':t' => $treatment, ':id' => $id]);
            FinancialAuditLog::record($this->db, $entity, $id, 'tratament_contabil', 'tratament_contabil', $old, $treatment, $reason, null, $userId);
        });
    }

    public function setNature(string $entity, int $id, string $nature, ?int $userId, string $reason = ''): void
    {
        [$table, $hasNature] = $this->entity($entity);
        if (!$hasNature) {
            throw new InvalidArgumentException('Entitatea nu are natură a costului.');
        }
        $nature = CostNature::normalize($nature) ?? throw new InvalidArgumentException('Natura costului este invalidă.');
        if ($entity === 'proiect_investitie' && !CostNature::isCapitalNature($nature)) {
            throw new InvalidArgumentException('Un proiect de investiții are natura investiție sau echipare inițială.');
        }

        $this->transactional(function () use ($entity, $table, $id, $nature, $userId, $reason): void {
            $row = $this->lockRow($table, $id);
            $old = (string) ($row['natura_cost'] ?? '');
            $sets = ['natura_cost = :n'];
            $params = [':n' => $nature, ':id' => $id];
            if ($entity === 'mentenanta') {
                $sets[] = "natura_cost_origine = 'explicit'";
                $sets[] = 'natura_cost_de_revizuit = 0';
                $sets[] = 'natura_cost_motiv_revizuire = NULL';
            }
            $oldTreatment = (string) ($row['tratament_contabil'] ?? '');
            $newTreatment = $oldTreatment;
            if (!CostNature::isCapitalNature($nature) && $oldTreatment === 'capitalizabil') {
                $newTreatment = 'de_confirmat';
            } elseif ($entity === 'mentenanta' && $old !== $nature && $oldTreatment === 'cheltuiala' && CostNature::isCapitalNature($nature)) {
                // Devine investitie: tratamentul trebuie confirmat de contabilitate.
                $newTreatment = 'de_confirmat';
            }
            if ($newTreatment !== $oldTreatment) {
                $sets[] = 'tratament_contabil = :t';
                $params[':t'] = $newTreatment;
            }
            $this->db->prepare("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            if ($old !== $nature) {
                FinancialAuditLog::record($this->db, $entity, $id, 'natura_cost', 'natura_cost', $old ?: null, $nature, $reason, null, $userId);
            }
            if ($newTreatment !== $oldTreatment) {
                FinancialAuditLog::record($this->db, $entity, $id, 'tratament_contabil', 'tratament_contabil', $oldTreatment, $newTreatment,
                    'Resetat automat la schimbarea naturii costului', null, $userId);
            }
        });
    }

    /** Randurile din `mentenanta` marcate pentru revizuirea clasificarii istorice. */
    public function pendingReview(int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.vehicle_id, v.nr_inmatriculare, m.data_interventie, m.tip_interventie, m.cost,
                    m.record_type, m.natura_cost, m.tratament_contabil, m.natura_cost_motiv_revizuire
               FROM mentenanta m JOIN vehicule v ON v.id = m.vehicle_id
              WHERE m.natura_cost_de_revizuit = 1 OR (m.natura_cost IN (\'investitie\',\'echipare_initiala\') AND m.tratament_contabil = \'de_confirmat\')
              ORDER BY m.data_interventie DESC, m.id DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:bool} */
    private function entity(string $entity): array
    {
        return self::ENTITIES[$entity] ?? throw new InvalidArgumentException('Entitate necunoscută: ' . $entity);
    }

    private function lockRow(string $table, int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `$table` WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('Înregistrarea nu mai există.');
        }
        return $row;
    }

    private function transactional(callable $fn): void
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
