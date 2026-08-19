<?php
declare(strict_types=1);

/**
 * "Administrare tarife transport" — versioned commercial tariff management
 * plus diesel-price monitoring.
 *
 * SECURITY
 *   Every mutating action enforces, server-side: authentication, the
 *   `tarife_transport.manage` permission, POST-only, and CSRF.
 *   Hiding buttons in the view is never relied upon.
 */
class TransportTariffController
{
    private PDO $db;
    private TransportTariffModel $model;
    private TransportPricingService $pricing;
    private FuelPriceIndexService $fuelIndex;
    private TariffReviewService $reviews;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new TransportTariffModel($db);
        $this->fuelIndex = new FuelPriceIndexService($db);
        $this->pricing = new TransportPricingService($db, $this->model);
        $this->reviews = new TariffReviewService($db, $this->model, $this->fuelIndex);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'istoric':
                $this->historyAction();
                return;
            case 'store_version':
                $this->storeVersionAction();
                return;
            case 'dismiss_review':
                $this->dismissReviewAction();
                return;
            case 'save_settings':
                $this->saveSettingsAction();
                return;
            case 'preview':
                $this->previewAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'tarife_transport',
                ]);
                return;
        }
    }

    // -----------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------

    private function requireView(): void
    {
        if (function_exists('can') && can('tarife_transport', 'view')) {
            return;
        }
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        http_response_code(403);
        render('errors/403.php', ['pageTitle' => 'Acces interzis', 'currentPage' => '']);
        exit;
    }

    private function requireManage(): void
    {
        if (function_exists('can') && can('tarife_transport', 'manage')) {
            return;
        }
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => 'Nu ai dreptul sa modifici tarife.'], 403);
        }

        http_response_code(403);
        render('errors/403.php', ['pageTitle' => 'Acces interzis', 'currentPage' => '']);
        exit;
    }

    public function canManage(): bool
    {
        if (function_exists('can') && can('tarife_transport', 'manage')) {
            return true;
        }

        return function_exists('is_admin') && is_admin();
    }

    // -----------------------------------------------------------------
    // Page
    // -----------------------------------------------------------------

    private function indexAction(): void
    {
        $this->requireView();

        $schemaReady = $this->model->schemaReady();
        $beneficiaries = $this->model->getBeneficiaries(false);

        $selectedId = (int) ($_GET['beneficiar_id'] ?? 0);
        if ($selectedId <= 0 && $beneficiaries !== []) {
            $selectedId = (int) $beneficiaries[0]['id'];
        }
        $beneficiary = $selectedId > 0 ? $this->model->getBeneficiary($selectedId) : null;

        $activeTab = (string) ($_GET['tab'] ?? 'primar');
        if (!array_key_exists($activeTab, TransportTariffModel::TRANSPORT_TYPES)) {
            $activeTab = 'primar';
        }

        $data = [
            'schemaReady' => $schemaReady,
            'beneficiaries' => $beneficiaries,
            'selectedBeneficiaryId' => $selectedId,
            'beneficiary' => $beneficiary,
            'activeTab' => $activeTab,
            'canManage' => $this->canManage(),
            'settings' => $this->model->getSettings(),
            'thresholdPercent' => $this->model->getReviewThresholdPercent(),
            'versions' => [],
            'reviews' => [],
            'primaryRoutes' => [],
            'distributionRoutes' => [],
            'pdRoutes' => [],
            'vehiclePlates' => [],
            'history' => [],
            'fuel' => null,
            'summary' => [],
        ];

        if ($schemaReady && $selectedId > 0) {
            try {
                // Refresh recommendation state for this beneficiary on page open.
                $this->reviews->evaluateActiveVersions($selectedId);
            } catch (Throwable $exception) {
                error_log('[TransportTariffController][evaluate] ' . $exception->getMessage());
            }

            $data['versions'] = $this->model->getVersionsForBeneficiary($selectedId);
            $data['reviews'] = $this->model->getReviewsForBeneficiary($selectedId);
            $data['primaryRoutes'] = $this->model->getPrimaryRoutes($selectedId);
            $data['distributionRoutes'] = $this->model->getDistributionRoutes($selectedId, 'distributie');
            $data['pdRoutes'] = $this->model->getDistributionRoutes($selectedId, 'primar_distributie');
            $data['vehiclePlates'] = $this->model->getVehiclePlateMap();
            $data['history'] = $this->model->getHistory($selectedId, 8);
            $data['fuel'] = $this->reviews->getMonitoringSummary($selectedId);
            $data['summary'] = $this->buildBeneficiarySummary($selectedId, $data['versions'], $beneficiary, [
                    'primar' => count($data['primaryRoutes']),
                    'distributie' => count($data['distributionRoutes']),
                    'primar_distributie' => count($data['pdRoutes']),
                ]);
        }

        render('tarife_transport/index.php', array_merge($data, [
            'pageTitle' => 'Administrare tarife transport',
            'currentPage' => 'tarife_transport',
        ]));
    }

    private function historyAction(): void
    {
        $this->requireView();

        $selectedId = (int) ($_GET['beneficiar_id'] ?? 0);
        $beneficiaries = $this->model->getBeneficiaries(false);

        render('tarife_transport/istoric.php', [
            'pageTitle' => 'Istoric modificări tarife',
            'currentPage' => 'tarife_transport',
            'beneficiaries' => $beneficiaries,
            'selectedBeneficiaryId' => $selectedId,
            'history' => $this->model->getHistory($selectedId > 0 ? $selectedId : null, 200),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Build the right-sidebar summary. Deliberately does NOT reduce
     * Distribuție/P+D/Compresor to one misleading universal price.
     */
    private function buildBeneficiarySummary(int $beneficiaryId, array $versions, ?array $beneficiary, array $routeCounts = []): array
    {
        $today = date('Y-m-d');
        $activeByComponent = [];
        foreach ($versions as $version) {
            if ($this->model->deriveStatus($version, $today) !== 'active') {
                continue;
            }
            $key = (string) $version['component_key'] . '|' . (int) $version['route_ref_id'];
            $activeByComponent[$key] = $version;
        }

        $findBeneficiaryRate = static function (string $componentKey) use ($activeByComponent): ?float {
            $key = $componentKey . '|0';
            return isset($activeByComponent[$key]) ? (float) $activeByComponent[$key]['value'] : null;
        };

        $countRoutes = static function (array $versions, string $type) use ($today): int {
            $routes = [];
            foreach ($versions as $version) {
                if ((string) $version['transport_type'] !== $type) {
                    continue;
                }
                if ((int) $version['route_ref_id'] <= 0) {
                    continue;
                }
                $routes[(int) $version['route_ref_id']] = true;
            }
            return count($routes);
        };

        $compressorActive = 0;
        foreach (TransportTariffModel::COMPRESSOR_COMPONENTS as $componentKey) {
            $value = $findBeneficiaryRate($componentKey);
            if ($value !== null && $value > 0) {
                $compressorActive++;
            }
        }

        return [
            'primar' => [
                'label' => 'Primar km',
                'value' => $findBeneficiaryRate('pret_km'),
                'unit' => 'lei/km',
                'value_label' => null,
                'detail' => (int) ($routeCounts['primar'] ?? 0) . ' rute configurate',
                'supported' => !empty($beneficiary['suporta_primar']),
            ],
            'primar_tona' => [
                'label' => 'Primar tone',
                'value' => $findBeneficiaryRate('pret_tona'),
                'unit' => 'lei/tonă',
                'value_label' => null,
                'detail' => 'Preț la nivel de beneficiar',
                'supported' => !empty($beneficiary['suporta_primar']),
            ],
            'distributie' => [
                'label' => 'Distribuție',
                'value' => null,
                'unit' => '',
                'value_label' => 'Tarife pe rută',
                'detail' => (int) ($routeCounts['distributie'] ?? 0) . ' rute configurate',
                'supported' => !empty($beneficiary['suporta_distributie']),
            ],
            'primar_distributie' => [
                'label' => 'P+D (Primar + Distribuție)',
                'value' => null,
                'unit' => '',
                'value_label' => 'Tonă + Km pe rută',
                'detail' => (int) ($routeCounts['primar_distributie'] ?? 0) . ' rute configurate',
                'supported' => !empty($beneficiary['suporta_primar_distributie']),
            ],
            'compresor' => [
                'label' => 'Compresor',
                'value' => null,
                'unit' => '',
                // Compresor has NO routes — five independent beneficiary-level components.
                'value_label' => '5 componente',
                'detail' => $compressorActive . ' din 5 componente active',
                'supported' => !empty($beneficiary['suporta_compresor']),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Create a new tariff version
    // -----------------------------------------------------------------

    private function storeVersionAction(): void
    {
        $this->requireManage();

        $redirectBase = ['page' => 'tarife_transport'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url($redirectBase));
        }
        ensure_csrf_or_redirect(build_query_url($redirectBase));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $componentKey = trim((string) ($_POST['component_key'] ?? ''));
        $routeRefId = (int) ($_POST['route_ref_id'] ?? 0);
        $transportType = trim((string) ($_POST['transport_type'] ?? ''));
        $rawValue = trim((string) ($_POST['new_value'] ?? ''));
        $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $fuelWeightRaw = trim((string) ($_POST['fuel_weight'] ?? ''));

        $redirect = $redirectBase + [
            'beneficiar_id' => $beneficiaryId,
            'tab' => $transportType !== '' ? $transportType : 'primar',
        ];

        // --- server-side validation ------------------------------------
        $errors = [];

        if ($beneficiaryId <= 0 || $this->model->getBeneficiary($beneficiaryId) === null) {
            $errors[] = 'Beneficiar invalid.';
        }
        if (!array_key_exists($componentKey, TransportTariffModel::COMPONENTS)) {
            $errors[] = 'Componentă tarifară invalidă.';
        }
        if (!array_key_exists($transportType, TransportTariffModel::TRANSPORT_TYPES)) {
            $errors[] = 'Tip de transport invalid.';
        }

        $value = $this->normalizeDecimal($rawValue);
        if ($value === null || $value < 0) {
            $errors[] = 'Valoarea tarifului este invalidă (trebuie să fie un număr ≥ 0).';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1 || !$this->isValidDate($validFrom)) {
            $errors[] = 'Data de intrare în vigoare este invalidă.';
        }

        $componentMeta = TransportTariffModel::COMPONENTS[$componentKey] ?? null;
        $routeScope = 'none';
        $locId = null;
        $zoneId = null;

        if ($componentMeta !== null && $componentMeta['level'] === 'route') {
            if ($routeRefId <= 0) {
                $errors[] = 'Componenta necesită o rută validă.';
            } else {
                $route = $this->loadRouteForComponent($componentKey, $transportType, $routeRefId, $beneficiaryId);
                if ($route === null) {
                    $errors[] = 'Ruta selectată nu aparține beneficiarului curent.';
                } else {
                    $routeScope = (string) $route['scope'];
                    $locId = (int) $route['loc_incarcare_id'];
                    $zoneId = (int) $route['zona_distributie_id'];
                }
            }
        } else {
            $routeRefId = 0;
        }

        $fuelWeight = null;
        if ($fuelWeightRaw !== '') {
            $parsed = $this->normalizeDecimal($fuelWeightRaw);
            if ($parsed === null || $parsed < 0 || $parsed > 1) {
                $errors[] = 'Sensibilitatea la combustibil trebuie să fie între 0 și 1.';
            } else {
                $fuelWeight = $parsed;
            }
        }

        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect(build_query_url($redirect));
        }

        // --- capture the fuel reference for the NEW version -------------
        $reference = $this->reviews->captureReferenceForNewVersion($validFrom);
        $monitoring = $this->reviews->getMonitoringSummary($beneficiaryId);

        try {
            $this->db->beginTransaction();

            $created = $this->model->createVersion([
                'beneficiar_id' => $beneficiaryId,
                'transport_type' => $transportType,
                'component_key' => $componentKey,
                'route_scope' => $routeScope,
                'route_ref_id' => $routeRefId > 0 ? $routeRefId : null,
                'loc_incarcare_id' => $locId,
                'zona_distributie_id' => $zoneId,
                'value' => $value,
                'valid_from' => $validFrom,
                'fuel_weight' => $fuelWeight,
                'reference_fuel_price' => $reference['price'],
                'reference_captured_at' => $reference['price'] !== null ? $reference['captured_at'] : null,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'created_by' => $this->currentUserId(),
            ]);

            $this->model->recordHistory([
                'tariff_version_id' => $created['version_id'],
                'rule_signature' => TransportTariffModel::buildSignature($beneficiaryId, $componentKey, $routeRefId > 0 ? $routeRefId : null),
                'beneficiar_id' => $beneficiaryId,
                'transport_type' => $transportType,
                'component_key' => $componentKey,
                'route_ref_id' => $routeRefId > 0 ? $routeRefId : null,
                'route_label' => $this->buildRouteLabel($locId, $zoneId),
                'action' => $validFrom > date('Y-m-d') ? 'scheduled' : 'created',
                'old_value' => $created['previous_value'],
                'new_value' => $value,
                'unit' => TransportTariffModel::componentUnit($componentKey),
                'effective_from' => $validFrom,
                'effective_to' => null,
                'reference_fuel_price' => $reference['price'],
                'observed_fuel_price' => $monitoring['current_price'] ?? null,
                'fuel_variation_percent' => $monitoring['variation_percent'] ?? null,
                'fuel_liters_analysed' => $monitoring['liters'] ?? null,
                'fuel_period_start' => $monitoring['period_start'] ?? null,
                'fuel_period_end' => $monitoring['period_end'] ?? null,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'changed_by' => $this->currentUserId(),
                'changed_by_name' => $this->currentUserName(),
                'changed_at' => date('Y-m-d H:i:s'),
            ]);

            // Mark the recommendation that triggered this change as resolved.
            if ($created['previous_id'] !== null) {
                $this->model->markReviewResolved($created['previous_id'], 'REVIEWED', $this->currentUserId());
            }

            $this->db->commit();

            flash_set(
                'success',
                $validFrom > date('Y-m-d')
                    ? sprintf('Tarif programat: %s de la %s.', format_number_ro($value, 4), $this->formatDateRo($validFrom))
                    : sprintf('Tarif activ actualizat: %s de la %s.', format_number_ro($value, 4), $this->formatDateRo($validFrom))
            );
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[TransportTariffController][store_version] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva tariful: ' . $exception->getMessage());
        }

        redirect(build_query_url($redirect));
    }

    private function dismissReviewAction(): void
    {
        $this->requireManage();

        $redirectBase = ['page' => 'tarife_transport'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url($redirectBase));
        }
        ensure_csrf_or_redirect(build_query_url($redirectBase));

        $versionId = (int) ($_POST['tariff_version_id'] ?? 0);
        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $tab = trim((string) ($_POST['tab'] ?? 'primar'));

        if ($versionId > 0 && $this->model->markReviewResolved($versionId, 'DISMISSED', $this->currentUserId())) {
            $version = $this->model->getVersionById($versionId);
            if (is_array($version)) {
                $this->model->recordHistory([
                    'tariff_version_id' => $versionId,
                    'rule_signature' => (string) $version['rule_signature'],
                    'beneficiar_id' => (int) $version['beneficiar_id'],
                    'transport_type' => (string) $version['transport_type'],
                    'component_key' => (string) $version['component_key'],
                    'route_ref_id' => $version['route_ref_id'],
                    'action' => 'dismissed',
                    'old_value' => (float) $version['value'],
                    'new_value' => (float) $version['value'],
                    'unit' => (string) $version['unit'],
                    'reason' => 'Recomandare amânată de administrator',
                    'changed_by' => $this->currentUserId(),
                    'changed_by_name' => $this->currentUserName(),
                    'changed_at' => date('Y-m-d H:i:s'),
                ]);
            }
            flash_set('info', 'Recomandarea a fost amânată. Tariful rămâne neschimbat.');
        } else {
            flash_set('warning', 'Recomandarea nu a putut fi actualizată.');
        }

        redirect(build_query_url($redirectBase + ['beneficiar_id' => $beneficiaryId, 'tab' => $tab]));
    }

    private function saveSettingsAction(): void
    {
        $this->requireManage();

        $redirectBase = ['page' => 'tarife_transport'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url($redirectBase));
        }
        ensure_csrf_or_redirect(build_query_url($redirectBase));

        $beneficiaryId = (int) ($_POST['beneficiar_id'] ?? 0);
        $thresholdRaw = trim((string) ($_POST['fuel_review_threshold_percent'] ?? ''));

        if ($thresholdRaw === '') {
            $this->model->setSetting('fuel_review_threshold_percent', '', $this->currentUserId());
            flash_set('info', 'Pragul de revizuire a fost golit. Nu se vor mai emite recomandări automate.');
        } else {
            $threshold = $this->normalizeDecimal($thresholdRaw);
            if ($threshold === null || $threshold <= 0 || $threshold > 100) {
                flash_set('danger', 'Pragul trebuie să fie un procent între 0 și 100.');
                redirect(build_query_url($redirectBase + ['beneficiar_id' => $beneficiaryId]));
            }
            $this->model->setSetting('fuel_review_threshold_percent', (string) $threshold, $this->currentUserId());
            flash_set('success', 'Pragul de revizuire a fost salvat: ' . format_number_ro($threshold, 2) . '%.');
        }

        redirect(build_query_url($redirectBase + ['beneficiar_id' => $beneficiaryId]));
    }

    /**
     * JSON pricing preview for the trip form. The authoritative calculation
     * lives here, in the backend — the frontend never reimplements a formula.
     */
    private function previewAction(): void
    {
        $this->requireView();

        $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

        $trip = [
            'beneficiar_id' => (int) ($input['beneficiar_id'] ?? 0),
            'tip_transport' => (string) ($input['tip_transport'] ?? ''),
            'data_cursa' => (string) ($input['data_cursa'] ?? date('Y-m-d')),
            'vehicle_id' => (int) ($input['vehicle_id'] ?? 0),
            'loc_incarcare_id' => (int) ($input['loc_incarcare_id'] ?? 0),
            'zona_distributie_id' => (int) ($input['zona_distributie_id'] ?? 0),
            'cantitate_incarcata' => (float) ($input['cantitate_incarcata'] ?? 0),
            'km_cursa' => (float) ($input['km_cursa'] ?? 0),
            'km_totali' => (float) ($input['km_totali'] ?? 0),
            'ore_aspirare' => (float) ($input['ore_aspirare'] ?? 0),
            'km_dislocare' => (float) ($input['km_dislocare'] ?? 0),
            'tona_livrata' => (float) ($input['tona_livrata'] ?? 0),
            'tona_aspirata_lichida' => (float) ($input['tona_aspirata_lichida'] ?? 0),
            'tona_aspirata_gazoasa' => (float) ($input['tona_aspirata_gazoasa'] ?? 0),
        ];

        try {
            $this->json(['ok' => true, 'quote' => $this->pricing->quote($trip)]);
        } catch (Throwable $exception) {
            error_log('[TransportTariffController][preview] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => 'Nu s-a putut calcula previzualizarea.'], 500);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{scope:string, loc_incarcare_id:int, zona_distributie_id:int}|null */
    private function loadRouteForComponent(string $componentKey, string $transportType, int $routeRefId, int $beneficiaryId): ?array
    {
        // cost_cursa on a Primar route lives in configurare_rute_primar.
        if ($componentKey === 'cost_cursa' && in_array($transportType, ['primar', 'primar_tona'], true)) {
            $stmt = $this->db->prepare('
                SELECT id, loc_incarcare_id, zona_distributie_id
                FROM configurare_rute_primar WHERE id = :id AND beneficiar_id = :bid LIMIT 1
            ');
            $stmt->execute(['id' => $routeRefId, 'bid' => $beneficiaryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? [
                'scope' => 'primar',
                'loc_incarcare_id' => (int) $row['loc_incarcare_id'],
                'zona_distributie_id' => (int) $row['zona_distributie_id'],
            ] : null;
        }

        $scope = $transportType === 'distributie' ? 'distributie' : 'primar_distributie';
        $stmt = $this->db->prepare('
            SELECT id, loc_incarcare_id, zona_distributie_id, transport_scope
            FROM configurare_rute_distributie
            WHERE id = :id AND beneficiar_id = :bid AND transport_scope = :scope LIMIT 1
        ');
        $stmt->execute(['id' => $routeRefId, 'bid' => $beneficiaryId, 'scope' => $scope]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'scope' => (string) $row['transport_scope'],
            'loc_incarcare_id' => (int) $row['loc_incarcare_id'],
            'zona_distributie_id' => (int) $row['zona_distributie_id'],
        ] : null;
    }

    private function buildRouteLabel(?int $locId, ?int $zoneId): ?string
    {
        if ($locId === null || $zoneId === null || $locId <= 0 || $zoneId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT (SELECT nume FROM configurare_locuri_incarcare WHERE id = :loc) AS loc,
                   (SELECT nume FROM configurare_zone_distributie WHERE id = :zona) AS zona
        ');
        $stmt->execute(['loc' => $locId, 'zona' => $zoneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return trim((string) $row['loc']) . ' → ' . trim((string) $row['zona']);
    }

    private function normalizeDecimal(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function formatDateRo(string $date): string
    {
        try {
            return (new DateTimeImmutable($date))->format('d.m.Y');
        } catch (Throwable) {
            return $date;
        }
    }

    private function currentUserId(): ?int
    {
        $user = function_exists('current_user') ? current_user() : null;
        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function currentUserName(): string
    {
        $user = function_exists('current_user') ? current_user() : null;

        return trim((string) ($user['nume'] ?? 'Sistem'));
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requestedWith = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        return str_contains($accept, 'application/json')
            || strtolower($requestedWith) === 'xmlhttprequest';
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header_remove('Content-Type');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
