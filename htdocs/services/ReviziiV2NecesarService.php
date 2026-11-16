<?php
declare(strict_types=1);

/**
 * Necesar piese & consumabile pentru o revizie (Revizii V2).
 *
 * Liniile vin din ciorna lucrarii (ReviziiV2Service::draftWorkOrder: operatiunile X, + Y la Revizia Y,
 * + cele scadente din afara reviziei), deci din planul si sabloanele configurate, nu din estimari.
 *  - fluide: produsul regulii din plan, altfel produsul sistemului de fluide al vehiculului;
 *    disponibil = recipientele active cu stoc (nu expirate);
 *  - piese: componenta din Catalog componente (cod) → piesa din Stoc Piese (mentenanta_piese.cod_piesa).
 * „Nerezervat” = stocul minus cantitatile din alte cereri deschise care nu sunt inca consumate / anulate.
 *
 * Cererea NU scade stocul: scaderea ramane la finalizarea lucrarii (miscare de stoc confirmata).
 * Costuri separate: estimat (cantitate × pret achizitie cunoscut), angajat (linii comandate / pregatite).
 */
final class ReviziiV2NecesarService
{
    public const STATUSES = [
        'deschis' => 'Deschisă', 'in_pregatire' => 'În pregătire', 'comandat' => 'Comandată',
        'pregatit' => 'Pregătită', 'inchis' => 'Închisă', 'anulat' => 'Anulată',
    ];
    public const LINE_STATUSES = [
        'de_verificat' => 'De verificat', 'disponibil' => 'Disponibil în stoc', 'lipsa' => 'Lipsă stoc',
        'comandat' => 'Comandat', 'pregatit' => 'Pregătit', 'anulat' => 'Anulat',
    ];
    private const OPEN = ['deschis', 'in_pregatire', 'comandat', 'pregatit'];

    public function __construct(private PDO $db, private ReviziiV2Service $service)
    {
        $this->service->model();
    }

    // ================================================================= calcul

    /**
     * Necesarul calculat (fara salvare) pentru urmatoarea revizie a vehiculului.
     *
     * @return array<string, mixed>
     */
    public function preview(int $vehicleId, ?int $excludeRequestId = null): array
    {
        $draft = $this->service->draftWorkOrder($vehicleId);
        $fluids = [];
        foreach ($this->service->vehicleFluidSystems($vehicleId) as $system) {
            $fluids[(string) $system['sistem']] = $system;
        }
        $catalog = (new AutoComponentCatalogService($this->db))->components(true);
        $icons = $this->service->operationIcons();
        $reserved = $this->reserved($excludeRequestId);
        $lines = [];
        foreach ($draft['items'] as $item) {
            if (($item['status'] ?? '') === 'na') {
                continue;
            }
            $operation = $this->service->operationRow((int) $item['operatie_id']) ?? [];
            $key = (string) ($operation['component_key'] ?? '');
            $line = [
                'operatie_id' => (int) $item['operatie_id'],
                'sectiune' => (string) $item['sectiune'],
                'componenta' => (string) $item['componenta'],
                'operatie' => (string) $item['operatie'],
                'tip_operatie' => (string) $item['tip_operatie'],
                'urgenta' => $item['urgenta'] ?? null,
                'component_key' => $key !== '' ? $key : null,
                'icon' => $icons[(int) $item['operatie_id']]['icon'] ?? CatalogIconResolver::DEFAULT_ICON,
                'produs_id' => null, 'piesa_id' => null, 'sursa_piesa' => null, 'referinta' => null, 'produs' => null, 'compatibile' => [],
                'cantitate' => $item['cantitate_estimata'] !== null ? (float) $item['cantitate_estimata'] : null,
                'um' => $item['um'] ?? null,
                'stoc' => null, 'rezervat' => 0.0, 'disponibil' => null, 'lipsa' => null, 'pret_unitar' => null,
                'status' => 'de_verificat', 'nota' => null,
            ];
            $productId = $item['produs_id'] !== null ? (int) $item['produs_id'] : null;
            $system = $item['sistem'] !== null ? ($fluids[(string) $item['sistem']] ?? null) : null;
            if ($productId === null && $system !== null && $system['produs_id'] !== null) {
                $productId = (int) $system['produs_id'];
            }
            if ($line['cantitate'] === null && $system !== null && $system['capacitate_nominala'] !== null) {
                $line['cantitate'] = (float) $system['capacitate_nominala'];
                $line['um'] = $system['um'] ?: 'L';
                $line['nota'] = 'Cantitate = capacitatea nominală a sistemului (plan fără cantitate).';
            }
            if ($productId !== null) {
                $product = $this->product($productId);
                if ($product !== null) {
                    $line['produs_id'] = $productId;
                    $line['produs'] = $product;
                    $line['referinta'] = trim($product['denumire'] . ' ' . ($product['vascozitate'] ?? ''));
                    $line['um'] = $line['um'] ?? $product['um'];
                    $line['stoc'] = $this->productStock($productId);
                    $line['rezervat'] = $reserved['p' . $productId] ?? 0.0;
                    $line['compatibile'] = $this->compatibleProducts($productId, (string) ($product['tip_fluid'] ?? ''));
                }
            } elseif ($item['sistem'] !== null) {
                $line['nota'] = 'Produsul nu e definit în plan și nici pe sistemul de fluide al vehiculului (Consumabile).';
            }
            // Piesa: legatura explicita din plan / exceptia vehiculului, altfel piesa componentei din catalog (dupa cod).
            $part = null;
            $partSource = null;
            if ($line['produs_id'] === null && !empty($item['piesa_id'])) {
                $part = $this->partById((int) $item['piesa_id']);
                $partSource = 'plan';
            }
            if ($line['produs_id'] === null && $part === null && $key !== '' && isset($catalog[$key])) {
                $part = $this->partByCode((string) $catalog[$key]['code']);
                $partSource = $part !== null ? 'catalog' : null;
            }
            if ($part !== null) {
                $line['piesa_id'] = (int) $part['id'];
                $line['sursa_piesa'] = $partSource;
                $line['referinta'] = $part['cod_piesa'] . ' · ' . $part['denumire'] . ($part['cod_oem'] ? ' (OEM ' . $part['cod_oem'] . ')' : '');
                $line['um'] = $line['um'] ?? $part['unitate_masura'];
                $line['stoc'] = (float) $part['stoc_curent'];
                $line['rezervat'] = $reserved['s' . $part['id']] ?? 0.0;
                $line['pret_unitar'] = (float) $part['pret_achizitie'] > 0 ? (float) $part['pret_achizitie'] : null;
                if ($line['cantitate'] === null) {
                    $line['cantitate'] = 1.0;
                    $line['nota'] = 'Cantitate implicită 1 (planul nu definește cantitatea).';
                }
            }
            if ($line['produs_id'] === null && $line['piesa_id'] === null && $line['nota'] === null) {
                $line['nota'] = $key === ''
                    ? 'Nicio piesă din Stoc Piese legată: alege-o în planul de mentenanță (sau ca excepție pe vehicul).'
                    : 'Componenta din catalog nu are piesă în Stoc Piese: alege piesa în planul de mentenanță.';
            }
            if ($line['stoc'] !== null) {
                $line['disponibil'] = max(0.0, round($line['stoc'] - $line['rezervat'], 3));
                if ($line['cantitate'] !== null) {
                    $line['lipsa'] = max(0.0, round($line['cantitate'] - $line['disponibil'], 3));
                    $line['status'] = $line['lipsa'] > 0 ? 'lipsa' : 'disponibil';
                }
            }
            $lines[] = $line;
        }
        $vehicle = $this->vehicleRow($vehicleId);

        return [
            'vehicle_id' => $vehicleId,
            'nr' => $vehicle['nr_inmatriculare'] ?? '',
            'model' => trim(($vehicle['marca'] ?? '') . ' ' . ($vehicle['model'] ?? '')),
            'tip' => $draft['tip'],
            'eticheta' => $draft['label'],
            'km_tinta' => $draft['km_tinta'],
            'plan_id' => $draft['plan_id'],
            'linii' => $lines,
            'costuri' => self::costs($lines),
        ];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private static function costs(array $lines): array
    {
        $estimated = 0.0;
        $committed = 0.0;
        $unknown = 0;
        foreach ($lines as $line) {
            $price = $line['pret_unitar'] !== null ? (float) $line['pret_unitar'] : null;
            $qty = $line['cantitate'] !== null ? (float) $line['cantitate'] : null;
            if ($price === null || $qty === null) {
                $unknown++;
                continue;
            }
            $estimated += $price * $qty;
            if (in_array((string) $line['status'], ['comandat', 'pregatit'], true)) {
                $committed += $price * $qty;
            }
        }

        return ['estimat' => round($estimated, 2), 'angajat' => round($committed, 2), 'fara_pret' => $unknown];
    }

    /** @return array<string, float> rezervari din cererile deschise: p{produs} / s{piesa} */
    private function reserved(?int $excludeRequestId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.produs_id, l.piesa_id, SUM(COALESCE(l.cantitate, 0)) AS q
             FROM revizii_v2_necesar_linii l JOIN revizii_v2_necesar n ON n.id = l.necesar_id
             WHERE n.status IN ('deschis', 'in_pregatire', 'comandat', 'pregatit') AND l.status NOT IN ('anulat')
               AND n.id <> :ex
             GROUP BY l.produs_id, l.piesa_id"
        );
        $stmt->execute([':ex' => $excludeRequestId ?? 0]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['produs_id'] !== null) {
                $result['p' . $row['produs_id']] = ($result['p' . $row['produs_id']] ?? 0) + (float) $row['q'];
            } elseif ($row['piesa_id'] !== null) {
                $result['s' . $row['piesa_id']] = ($result['s' . $row['piesa_id']] ?? 0) + (float) $row['q'];
            }
        }

        return $result;
    }

    private function product(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, denumire, producator, cod_produs, tip_fluid, vascozitate, specificatie, aprobari, um FROM consumabile_produse WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function productStock(int $productId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cantitate), 0) FROM consumabile_recipiente
             WHERE produs_id = :p AND status <> 'inactiv' AND (data_expirare IS NULL OR data_expirare >= CURDATE())"
        );
        $stmt->execute([':p' => $productId]);

        return (float) $stmt->fetchColumn();
    }

    /** @return array<int, string> alte produse active de acelasi tip de fluid, cu stoc (omologarea se verifica separat) */
    private function compatibleProducts(int $productId, string $fluidType): array
    {
        if ($fluidType === '') {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT p.denumire, p.vascozitate, SUM(r.cantitate) AS q FROM consumabile_produse p
             JOIN consumabile_recipiente r ON r.produs_id = p.id AND r.status <> 'inactiv' AND r.cantitate > 0
             WHERE p.activ = 1 AND p.tip_fluid = :t AND p.id <> :p GROUP BY p.id, p.denumire, p.vascozitate ORDER BY q DESC LIMIT 5"
        );
        $stmt->execute([':t' => $fluidType, ':p' => $productId]);

        return array_map(static fn (array $r): string => trim($r['denumire'] . ' ' . ($r['vascozitate'] ?? '')) . ' (' . rtrim(rtrim(number_format((float) $r['q'], 1, '.', ''), '0'), '.') . ')',
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function partById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, cod_piesa, denumire, cod_oem, unitate_masura, stoc_curent, pret_achizitie FROM mentenanta_piese WHERE id = :id AND arhivat_la IS NULL');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function partByCode(string $code): ?array
    {
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, cod_piesa, denumire, cod_oem, unitate_masura, stoc_curent, pret_achizitie FROM mentenanta_piese WHERE cod_piesa = :c AND arhivat_la IS NULL LIMIT 1');
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function vehicleRow(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT nr_inmatriculare, marca, model FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ================================================================= cereri

    /** @param array<string, mixed> $input */
    public function create(array $input, ?int $userId): int
    {
        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        $open = $this->db->prepare("SELECT id FROM revizii_v2_necesar WHERE vehicle_id = :v AND status IN ('deschis', 'in_pregatire', 'comandat', 'pregatit') ORDER BY id DESC LIMIT 1");
        $open->execute([':v' => $vehicleId]);
        $existing = $open->fetchColumn();
        if ($existing !== false) {
            throw new InvalidArgumentException('Vehiculul are deja o cerere de necesar deschisă (#' . (int) $existing . '). Actualizeaz-o sau închide-o întâi.');
        }
        $preview = $this->preview($vehicleId);
        if ($preview['linii'] === []) {
            throw new InvalidArgumentException('Revizia nu are operațiuni pentru care să se pregătească piese.');
        }
        $date = trim((string) ($input['data_necesara'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Data necesară este invalidă.');
        }
        $workOrder = $this->db->prepare("SELECT id FROM revizii_v2_lucrari WHERE vehicle_id = :v AND status IN ('programata', 'in_desfasurare') ORDER BY id DESC LIMIT 1");
        $workOrder->execute([':v' => $vehicleId]);
        $orderId = $workOrder->fetchColumn();

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                'INSERT INTO revizii_v2_necesar (vehicle_id, lucrare_id, plan_id, tip, eticheta, km_tinta, status, responsabil, data_necesara, observatii, cost_estimat, created_by, updated_by)
                 VALUES (:v, :l, :p, :t, :e, :k, :s, :r, :d, :o, :c, :u, :u2)'
            )->execute([
                ':v' => $vehicleId, ':l' => $orderId !== false ? (int) $orderId : null, ':p' => $preview['plan_id'], ':t' => $preview['tip'], ':e' => $preview['eticheta'],
                ':k' => $preview['km_tinta'], ':s' => 'deschis', ':r' => self::text($input['responsabil'] ?? null, 120), ':d' => $date !== '' ? $date : null,
                ':o' => self::text($input['observatii'] ?? null, 1000), ':c' => $preview['costuri']['estimat'] ?: null, ':u' => $userId, ':u2' => $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $insert = $this->db->prepare(
                'INSERT INTO revizii_v2_necesar_linii (necesar_id, operatie_id, sectiune, componenta, operatie, component_key, produs_id, piesa_id, referinta, cantitate, um, disponibil, lipsa, pret_unitar, status, observatii)
                 VALUES (:n, :o, :s, :c, :op, :k, :p, :pi, :r, :q, :um, :d, :l, :pr, :st, :obs)'
            );
            foreach ($preview['linii'] as $line) {
                $insert->execute([
                    ':n' => $id, ':o' => $line['operatie_id'], ':s' => $line['sectiune'], ':c' => mb_substr($line['componenta'], 0, 120), ':op' => mb_substr($line['operatie'], 0, 190),
                    ':k' => $line['component_key'], ':p' => $line['produs_id'], ':pi' => $line['piesa_id'], ':r' => $line['referinta'] !== null ? mb_substr($line['referinta'], 0, 190) : null,
                    ':q' => $line['cantitate'], ':um' => $line['um'], ':d' => $line['disponibil'], ':l' => $line['lipsa'], ':pr' => $line['pret_unitar'],
                    ':st' => $line['status'], ':obs' => $line['nota'] !== null ? mb_substr($line['nota'], 0, 500) : null,
                ]);
            }
            $this->service->model()->audit($id, 'necesar_nou', 'Necesar piese ' . $preview['nr'] . ' · Revizia ' . $preview['eticheta'], null, ['linii' => count($preview['linii'])], $userId);
            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $id;
    }

    /** @return array<string, mixed> cererea cu liniile ei si disponibilitatea curenta (recalculata) */
    public function detail(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.*, v.nr_inmatriculare AS nr, v.marca, v.model FROM revizii_v2_necesar n JOIN vehicule v ON v.id = n.vehicle_id WHERE n.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new InvalidArgumentException('Cererea nu există.');
        }
        $lines = $this->db->prepare('SELECT * FROM revizii_v2_necesar_linii WHERE necesar_id = :id ORDER BY sectiune, id');
        $lines->execute([':id' => $id]);
        $icons = $this->service->operationIcons();
        $reserved = $this->reserved($id);
        $rows = [];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $stock = null;
            $product = null;
            if ($line['produs_id'] !== null) {
                $stock = $this->productStock((int) $line['produs_id']);
                $product = $this->product((int) $line['produs_id']);
                $stock -= $reserved['p' . $line['produs_id']] ?? 0;
            } elseif ($line['piesa_id'] !== null) {
                $part = $this->db->prepare('SELECT stoc_curent FROM mentenanta_piese WHERE id = :id');
                $part->execute([':id' => (int) $line['piesa_id']]);
                $stock = (float) $part->fetchColumn() - ($reserved['s' . $line['piesa_id']] ?? 0);
            }
            $line['disponibil_acum'] = $stock !== null ? max(0.0, round($stock, 3)) : null;
            $line['produs'] = $product;
            $line['icon'] = $line['operatie_id'] !== null ? ($icons[(int) $line['operatie_id']]['icon'] ?? CatalogIconResolver::DEFAULT_ICON) : CatalogIconResolver::DEFAULT_ICON;
            foreach (['cantitate', 'disponibil', 'lipsa', 'pret_unitar'] as $field) {
                $line[$field] = $line[$field] !== null ? (float) $line[$field] : null;
            }
            $rows[] = $line;
        }
        $request['linii'] = $rows;
        $request['costuri'] = self::costs($rows);
        $request['pregatite'] = count(array_filter($rows, static fn (array $l): bool => $l['status'] === 'pregatit'));
        $request['active'] = count(array_filter($rows, static fn (array $l): bool => $l['status'] !== 'anulat'));
        $request['consum_real'] = $this->actualConsumption($request);

        return $request;
    }

    /** Consumul real (litri / bucati, cost) din lucrarea legata, dupa finalizare. */
    private function actualConsumption(array $request): ?array
    {
        if ($request['lucrare_id'] === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT l.status, l.cost_total, l.cost_materiale, l.cost_manopera FROM revizii_v2_lucrari l WHERE l.id = :id"
        );
        $stmt->execute([':id' => (int) $request['lucrare_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        $items = $this->db->prepare('SELECT operatie_id, cantitate_reala, um FROM revizii_v2_lucrari_operatii WHERE lucrare_id = :id AND cantitate_reala IS NOT NULL');
        $items->execute([':id' => (int) $request['lucrare_id']]);

        return [
            'status' => $order['status'],
            'cost_total' => $order['cost_total'] !== null ? (float) $order['cost_total'] : null,
            'cost_materiale' => $order['cost_materiale'] !== null ? (float) $order['cost_materiale'] : null,
            'cost_manopera' => $order['cost_manopera'] !== null ? (float) $order['cost_manopera'] : null,
            'cantitati' => $items->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input, ?int $userId): void
    {
        $before = $this->detail($id);
        $status = (string) ($input['status'] ?? $before['status']);
        if (!isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('Status invalid.');
        }
        if (in_array($before['status'], ['inchis', 'anulat'], true) && $status !== $before['status']) {
            throw new InvalidArgumentException('Cererea este închisă; nu se mai modifică.');
        }
        $date = trim((string) ($input['data_necesara'] ?? (string) $before['data_necesara']));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Data necesară este invalidă.');
        }
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('UPDATE revizii_v2_necesar SET status = :s, responsabil = :r, data_necesara = :d, observatii = :o, updated_by = :u WHERE id = :id')
                ->execute([':s' => $status, ':r' => self::text($input['responsabil'] ?? $before['responsabil'], 120), ':d' => $date !== '' ? $date : null,
                    ':o' => self::text($input['observatii'] ?? $before['observatii'], 1000), ':u' => $userId, ':id' => $id]);
            $lineStmt = $this->db->prepare('UPDATE revizii_v2_necesar_linii SET status = :s, observatii = :o WHERE id = :id AND necesar_id = :n');
            foreach ((array) ($input['linii'] ?? []) as $line) {
                $lineStatus = (string) ($line['status'] ?? '');
                if (!isset(self::LINE_STATUSES[$lineStatus])) {
                    continue;
                }
                $lineStmt->execute([':s' => $lineStatus, ':o' => self::text($line['observatii'] ?? null, 500), ':id' => (int) ($line['id'] ?? 0), ':n' => $id]);
            }
            $this->service->model()->audit($id, 'necesar_actualizat', 'Necesar piese #' . $id . ': ' . self::STATUSES[$status], ['status' => $before['status']], ['status' => $status, 'linii' => $input['linii'] ?? []], $userId);
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

    /** @return array<int, array<string, mixed>> cererea deschisa cea mai recenta a fiecarui vehicul */
    public function summaryByVehicle(): array
    {
        $rows = $this->db->query(
            "SELECT n.id, n.vehicle_id, n.status, n.eticheta, n.data_necesara, n.responsabil,
                    SUM(l.status <> 'anulat') AS linii, SUM(l.status = 'pregatit') AS pregatite, SUM(l.status = 'lipsa') AS lipsa
             FROM revizii_v2_necesar n LEFT JOIN revizii_v2_necesar_linii l ON l.necesar_id = n.id
             WHERE n.status IN ('deschis', 'in_pregatire', 'comandat', 'pregatit')
             GROUP BY n.id ORDER BY n.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']] = [
                'id' => (int) $row['id'], 'status' => (string) $row['status'], 'label' => self::STATUSES[$row['status']] ?? $row['status'],
                'eticheta' => $row['eticheta'], 'data_necesara' => $row['data_necesara'], 'responsabil' => $row['responsabil'],
                'linii' => (int) $row['linii'], 'pregatite' => (int) $row['pregatite'], 'lipsa' => (int) $row['lipsa'],
            ];
        }

        return $result;
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
