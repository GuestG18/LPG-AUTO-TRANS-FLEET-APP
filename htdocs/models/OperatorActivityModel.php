<?php
declare(strict_types=1);

/**
 * Activitatea operatorilor in Dispecer curse, pe zi: cate curse au adaugat
 * (deschise) si cate au inchis. O cursa este "inchisa" doar cand nu mai are
 * informatii lipsa (RaceCompletenessService) — aceleasi reguli ca panoul
 * "curse cu informatii lipsa".
 *
 * Momentul inchiderii nu exista in curse_dispecer, asa ca il consemnam in
 * curse_inchidere_operator la reconciliere: prima data cand o cursa este gasita
 * completa, se noteaza ultima activitate pe ea (audit cursa / cheltuieli) si
 * operatorul care a facut-o. Randul ramane inghetat la editari ulterioare si
 * se sterge daca cursa redevine incompleta (redeschisa).
 */
class OperatorActivityModel extends BaseModel
{
    private const TRANSPORT_TYPES = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar+Distributie',
        'compresor' => 'Compresor',
    ];

    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS curse_inchidere_operator (
                cursa_id INT UNSIGNED NOT NULL PRIMARY KEY,
                inchisa_la DATETIME NOT NULL,
                inchisa_de INT UNSIGNED NULL,
                detectata_la DATETIME NOT NULL,
                INDEX idx_inchidere_data (inchisa_la),
                INDEX idx_inchidere_user (inchisa_de),
                CONSTRAINT fk_inchidere_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
                CONSTRAINT fk_inchidere_user FOREIGN KEY (inchisa_de) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->schemaEnsured = true;
    }

    /**
     * Sincronizeaza curse_inchidere_operator cu starea curenta a curselor.
     * Returneaza [id cursa => lista informatii lipsa] pentru cursele incomplete.
     */
    public function reconcile(DispecerCurseModel $raceModel): array
    {
        $this->ensureSchema();

        $incomplete = [];
        $completeIds = [];
        foreach ($raceModel->getActiveRacesForCompleteness() as $race) {
            $raceId = (int) ($race['id'] ?? 0);
            if ($raceId <= 0) {
                continue;
            }
            $missing = RaceCompletenessService::missingInformation($race);
            if ($missing === []) {
                $completeIds[$raceId] = true;
            } else {
                $incomplete[$raceId] = $missing;
            }
        }

        $trackedIds = array_map('intval', $this->db->query('SELECT cursa_id FROM curse_inchidere_operator')->fetchAll(PDO::FETCH_COLUMN));
        $trackedSet = array_fill_keys($trackedIds, true);

        // Redeschise: erau inchise, acum le lipsesc din nou informatii.
        $reopened = array_values(array_filter($trackedIds, static fn(int $id): bool => isset($incomplete[$id])));
        if ($reopened !== []) {
            $placeholders = implode(',', array_fill(0, count($reopened), '?'));
            $this->db->prepare("DELETE FROM curse_inchidere_operator WHERE cursa_id IN ({$placeholders})")->execute($reopened);
        }

        $newlyClosed = array_values(array_filter(array_keys($completeIds), static fn(int $id): bool => !isset($trackedSet[$id])));
        if ($newlyClosed !== []) {
            $this->insertClosures($newlyClosed);
        }

        return $incomplete;
    }

    /**
     * Pentru fiecare cursa proaspat gasita completa: ultima activitate (audit cursa
     * sau cheltuiala) si utilizatorul care a facut-o. Fara activitate, creatorul cursei.
     */
    private function insertClosures(array $raceIds): void
    {
        foreach (array_chunk($raceIds, 200) as $chunk) {
            $inAudit = implode(',', array_fill(0, count($chunk), '?'));
            $inExpense = $inAudit;
            $inRace = $inAudit;

            $sql = "
                SELECT c.id, c.created_at, c.created_by, ev.event_at, ev.event_by
                FROM curse_dispecer c
                LEFT JOIN (
                    SELECT ranked.cursa_id, ranked.event_at, ranked.event_by
                    FROM (
                        SELECT
                            e.cursa_id,
                            e.event_at,
                            e.event_by,
                            ROW_NUMBER() OVER (PARTITION BY e.cursa_id ORDER BY e.event_at DESC) AS rn
                        FROM (
                            SELECT a.cursa_id, a.performed_at AS event_at, a.performed_by AS event_by
                            FROM cursa_audit_log a
                            WHERE a.cursa_id IN ({$inAudit})
                              AND a.performed_by IS NOT NULL
                              -- Schimbarea statusului de facturare nu inchide cursa; doar
                              -- editarea ei sau marcarea cheltuielilor (cheltuieli_status).
                              AND (
                                  a.action IN ('created', 'updated', 'restored')
                                  OR (a.action = 'status_changed' AND a.details_json LIKE '%cheltuieli_status%')
                              )
                            UNION ALL
                            SELECT ch.cursa_id, GREATEST(ch.created_at, ch.updated_at) AS event_at, ch.added_by AS event_by
                            FROM curse_cheltuieli ch
                            WHERE ch.cursa_id IN ({$inExpense})
                              AND ch.added_by IS NOT NULL
                        ) e
                    ) ranked
                    WHERE ranked.rn = 1
                ) ev ON ev.cursa_id = c.id
                WHERE c.id IN ({$inRace})
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge($chunk, $chunk, $chunk));

            $insert = $this->db->prepare("
                INSERT IGNORE INTO curse_inchidere_operator (cursa_id, inchisa_la, inchisa_de, detectata_la)
                VALUES (?, ?, ?, ?)
            ");
            $now = date('Y-m-d H:i:s');
            foreach ($stmt->fetchAll() as $row) {
                $closedAt = (string) ($row['event_at'] ?? '');
                $closedBy = $row['event_by'] ?? null;
                if ($closedAt === '') {
                    $closedAt = (string) ($row['created_at'] ?? $now);
                    $closedBy = $row['created_by'] ?? null;
                }
                $insert->execute([
                    (int) $row['id'],
                    $closedAt,
                    $closedBy !== null && (int) $closedBy > 0 ? (int) $closedBy : null,
                    $now,
                ]);
            }
        }
    }

    /**
     * Activitatea pe o zi, grupata pe operator.
     * $incomplete = rezultatul reconcile() (curse incomplete si ce le lipseste).
     */
    public function getDailyActivity(string $date, array $incomplete): array
    {
        $this->ensureSchema();

        $dayStart = $date . ' 00:00:00';
        $dayEnd = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';

        $operators = [];
        $ensureOperator = static function (array &$operators, int $userId, string $name): void {
            if (!isset($operators[$userId])) {
                $operators[$userId] = [
                    'user_id' => $userId,
                    'name' => $name !== '' ? $name : 'Utilizator necunoscut',
                    'opened' => 0,
                    'closed' => 0,
                    'still_open' => 0,
                    'last_activity' => '',
                    'open_races' => [],
                    'closed_races' => [],
                ];
            }
        };
        $touch = static function (array &$operator, string $at): void {
            if ($at > $operator['last_activity']) {
                $operator['last_activity'] = $at;
            }
        };

        $openedStmt = $this->db->prepare("
            SELECT c.id, c.tip_transport, c.created_at, c.created_by,
                   COALESCE(v.nr_inmatriculare, '') AS nr_inmatriculare,
                   COALESCE(u.nume, '') AS user_name
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN utilizatori u ON u.id = c.created_by
            WHERE c.deleted_at IS NULL
              AND c.created_at >= :day_start
              AND c.created_at < :day_end
            ORDER BY c.created_at DESC
        ");
        $openedStmt->execute(['day_start' => $dayStart, 'day_end' => $dayEnd]);
        foreach ($openedStmt->fetchAll() as $row) {
            $userId = (int) ($row['created_by'] ?? 0);
            $ensureOperator($operators, $userId, (string) $row['user_name']);
            $operator = &$operators[$userId];
            $operator['opened']++;
            $touch($operator, (string) $row['created_at']);

            $raceId = (int) $row['id'];
            if (isset($incomplete[$raceId])) {
                $operator['still_open']++;
                $operator['open_races'][] = $this->raceSummary($row, (string) $row['created_at'], $incomplete[$raceId]);
            }
            unset($operator);
        }

        $closedStmt = $this->db->prepare("
            SELECT c.id, c.tip_transport, i.inchisa_la, i.inchisa_de,
                   COALESCE(v.nr_inmatriculare, '') AS nr_inmatriculare,
                   COALESCE(u.nume, '') AS user_name
            FROM curse_inchidere_operator i
            INNER JOIN curse_dispecer c ON c.id = i.cursa_id AND c.deleted_at IS NULL
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN utilizatori u ON u.id = i.inchisa_de
            WHERE i.inchisa_la >= :day_start
              AND i.inchisa_la < :day_end
            ORDER BY i.inchisa_la DESC
        ");
        $closedStmt->execute(['day_start' => $dayStart, 'day_end' => $dayEnd]);
        foreach ($closedStmt->fetchAll() as $row) {
            $userId = (int) ($row['inchisa_de'] ?? 0);
            $ensureOperator($operators, $userId, (string) $row['user_name']);
            $operator = &$operators[$userId];
            $operator['closed']++;
            $touch($operator, (string) $row['inchisa_la']);
            $operator['closed_races'][] = $this->raceSummary($row, (string) $row['inchisa_la'], []);
            unset($operator);
        }

        $operators = array_values($operators);
        usort($operators, static function (array $a, array $b): int {
            return [$b['opened'] + $b['closed'], $a['name']] <=> [$a['opened'] + $a['closed'], $b['name']];
        });

        return [
            'date' => $date,
            'is_today' => $date === date('Y-m-d'),
            'generated_at' => date('Y-m-d H:i:s'),
            'totals' => [
                'opened' => array_sum(array_column($operators, 'opened')),
                'closed' => array_sum(array_column($operators, 'closed')),
                'still_open' => array_sum(array_column($operators, 'still_open')),
                // Toate cursele active care inca au informatii lipsa, indiferent de zi.
                'open_overall' => count($incomplete),
            ],
            'operators' => $operators,
        ];
    }

    private function raceSummary(array $row, string $at, array $missing): array
    {
        $raceId = (int) $row['id'];
        $type = (string) ($row['tip_transport'] ?? '');

        return [
            'id' => $raceId,
            'plate' => (string) ($row['nr_inmatriculare'] ?? ''),
            'type' => self::TRANSPORT_TYPES[$type] ?? $type,
            'time' => $at !== '' ? date('H:i', strtotime($at)) : '',
            'missing_count' => count($missing),
            'missing_labels' => array_slice(array_map(static fn(array $item): string => (string) ($item['label'] ?? ''), $missing), 0, 4),
            'url' => build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]),
        ];
    }
}
