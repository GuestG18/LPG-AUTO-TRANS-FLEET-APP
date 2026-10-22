<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/models/FuelFxAdjustmentModel.php';
require_once dirname(__DIR__) . '/services/FuelFxCalculator.php';
require_once dirname(__DIR__) . '/services/BnrExchangeRateService.php';
require_once dirname(__DIR__) . '/services/FuelFxAdjustmentService.php';

/**
 * „Centru ajustare tarife combustibil & curs BNR” (?page=ajustare_tarife).
 *
 *   index            GET        pagina
 *   series           GET JSON   seriile graficelor pe 1/3/6/12 luni          [view]
 *   auto_sync        POST JSON  sincronizare BNR doar dacă datele sunt vechi    [view]
 *                               (limitată la o încercare la 2 ore)
 *   compare          GET JSON   indicatori actuali vs ultima aprobare          [view]
 *   approval(s)      GET JSON   o aprobare / toate aprobările                   [view]
 *   sync_bnr         POST JSON  sincronizare manuală BNR + instantaneu          [sync]
 *   save_snapshot    POST JSON  salvează simularea curentă                      [sync]
 *   save_settings    POST       setări (interval, bază, prag, pondere provizorie) [configure]
 *   save_baseline    POST       referință nouă din fereastra la o dată          [configure]
 *   save_test_months POST       prețuri de test pe lună + referința de test      [configure]
 *                               (pagina în mod test: ?test=1, doar simulare)
 *   compare_periods  GET JSON   calculator: lună / 3 luni față de lună / 3 luni   [view]
 *   save_period_baseline POST JSON referință din perioada calendaristică aleasă   [configure]
 *   apply            POST JSON  aplicarea tarifelor contractuale                [apply]
 *
 * Fiecare acțiune care scrie: autentificare (router), drept ACL (router + aici),
 * POST, CSRF. Butoanele ascunse în pagină nu sunt considerate protecție.
 */
class FuelFxAdjustmentController
{
    private const PAGE = 'ajustare_tarife';

    private PDO $db;
    private FuelFxAdjustmentService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new FuelFxAdjustmentService($db);
    }

    public function handle(string $action): void
    {
        $this->requireAction('view');

        match ($action) {
            'series' => $this->seriesAction(),
            'auto_sync' => $this->autoSyncAction(),
            'compare' => $this->compareAction(),
            'approval' => $this->approvalAction(),
            'approvals' => $this->approvalsAction(),
            'sync_bnr' => $this->syncAction(),
            'save_snapshot' => $this->saveSnapshotAction(),
            'save_settings' => $this->saveSettingsAction(),
            'save_baseline' => $this->saveBaselineAction(),
            'save_test_months' => $this->saveTestMonthsAction(),
            'compare_periods' => $this->comparePeriodsAction(),
            'save_period_baseline' => $this->savePeriodBaselineAction(),
            'apply' => $this->applyAction(),
            default => $this->indexAction(),
        };
    }

    // ---------------------------------------------------------------- pagina

    private function indexAction(): void
    {
        $testMode = (string) ($_GET['test'] ?? '') === '1';
        if ($testMode) {
            $this->service->enableTestMode();
        }
        $data = $this->service->build();
        $model = $this->service->model();

        render('ajustare_tarife/index.php', [
            'pageTitle' => 'Ajustare tarife combustibil & curs BNR',
            'currentPage' => self::PAGE,
            'indicators' => $data['indicators'],
            'simulation' => $data['simulation'],
            'approvals' => $model->getApprovals(5),
            'baselines' => $model->getBaselines(20),
            'lastSaved' => $model->getLatestSnapshot('saved'),
            'series' => [
                'fx' => $this->service->fxSeries(3),
                'diesel' => $this->service->dieselSeries(3),
            ],
            'beneficiaries' => array_values(array_unique(array_column($data['simulation']['rules'], 'beneficiar_nume'))),
            'canSync' => can(self::PAGE, 'sync'),
            'canConfigure' => can(self::PAGE, 'configure'),
            'canApply' => can(self::PAGE, 'apply'),
            'adjustBases' => FuelFxAdjustmentService::ADJUST_BASES,
            'testMode' => $testMode,
            'testMonths' => $model->getTestDieselMonths(),
            'testReferenceEnd' => (string) ($model->getSettings(new TransportTariffModel($this->db))['fx_test_reference_end'] ?? ''),
            'realMonthly' => $this->service->realMonthlyDiesel(13),
        ]);
    }

    private function seriesAction(): void
    {
        $months = (int) ($_GET['luni'] ?? 3);
        if (!in_array($months, [1, 3, 6, 12], true)) {
            $this->json(['ok' => false, 'message' => 'Perioadă invalidă.'], 422);
        }
        $this->json([
            'ok' => true,
            'months' => $months,
            'fx' => $this->service->fxSeries($months),
            'diesel' => $this->service->dieselSeries($months),
        ]);
    }

    private function compareAction(): void
    {
        $this->json(['ok' => true, 'compare' => $this->service->compareWithLastApproval()]);
    }

    private function approvalAction(): void
    {
        $approval = $this->service->model()->getApproval((int) ($_GET['id'] ?? 0));
        if ($approval === null) {
            $this->json(['ok' => false, 'message' => 'Aprobarea nu există.'], 404);
        }
        unset($approval['indicators_json']);
        $this->json(['ok' => true, 'approval' => $approval]);
    }

    private function approvalsAction(): void
    {
        $this->json(['ok' => true, 'approvals' => $this->service->model()->getApprovals(200)]);
    }

    // ---------------------------------------------------------------- sincronizare

    /**
     * La deschiderea paginii: dacă ultimul curs salvat e mai vechi decât ziua
     * lucrătoare precedentă, încearcă o sincronizare (cel mult o dată la 2 ore,
     * ca un BNR indisponibil să nu încetinească fiecare deschidere).
     */
    private function autoSyncAction(): void
    {
        $this->requirePostWithCsrf();

        $latest = $this->service->model()->getLatestRate('EUR');
        $expected = $this->previousBusinessDay();
        if ($latest !== null && $latest['rate_date'] >= $expected) {
            $this->json(['ok' => true, 'ran' => false, 'reason' => 'up_to_date']);
        }
        $status = $this->service->model()->getSyncStatus();
        $lastAttempt = $status['last_attempt']['started_at'] ?? null;
        if ($lastAttempt !== null && strtotime((string) $lastAttempt) > time() - 7200) {
            $this->json(['ok' => true, 'ran' => false, 'reason' => 'throttled']);
        }

        $result = (new BnrExchangeRateService($this->db))->sync('pagina', $this->userId());
        $this->json(['ok' => $result['status'] !== 'error', 'ran' => true, 'inserted' => $result['inserted'], 'message' => $result['message']]);
    }

    private function syncAction(): void
    {
        $this->requireAction('sync');
        $this->requirePostWithCsrf();

        $result = (new BnrExchangeRateService($this->db))->sync('manual', $this->userId());
        try {
            $this->service->recordAutoSnapshot();
        } catch (Throwable $exception) {
            error_log('[FuelFxAdjustmentController][snapshot] ' . $exception->getMessage());
        }

        $this->json([
            'ok' => $result['status'] !== 'error',
            'status' => $result['status'],
            'message' => $result['status'] === 'error'
                ? 'BNR indisponibil — datele salvate anterior rămân valabile. ' . $result['message']
                : 'Curs BNR actualizat. ' . $result['message'],
        ], $result['status'] === 'error' ? 502 : 200);
    }

    private function saveSnapshotAction(): void
    {
        $this->requireAction('sync');
        $this->requirePostWithCsrf();

        $note = trim((string) ($_POST['note'] ?? ''));
        if (mb_strlen($note) > 250) {
            $this->json(['ok' => false, 'message' => 'Nota poate avea cel mult 250 de caractere.'], 422);
        }
        $stored = $this->service->saveSnapshot($note !== '' ? $note : null, $this->userId());
        $this->json([
            'ok' => true,
            'snapshot_id' => $stored['id'],
            'message' => $stored['created']
                ? 'Simularea a fost salvată (#' . $stored['id'] . ').'
                : 'Simularea cu aceleași date este deja salvată (#' . $stored['id'] . ').',
        ]);
    }

    // ---------------------------------------------------------------- configurare

    private function saveSettingsAction(): void
    {
        $this->requireAction('configure');
        $back = build_query_url(['page' => self::PAGE]);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($back);
        }
        ensure_csrf_or_redirect($back);

        try {
            $this->service->saveSettings([
                'months' => $_POST['months'] ?? '3',
                'basis' => $_POST['basis'] ?? 'diesel_eur',
                'threshold' => $_POST['threshold'] ?? '',
                'provisional_weight' => $_POST['provisional_weight'] ?? '',
            ], $this->userId());
            $this->service->recordAutoSnapshot();
            flash_set('success', 'Setările ajustării au fost salvate. Simulările au fost recalculate.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }
        redirect($back);
    }

    private function saveBaselineAction(): void
    {
        $this->requireAction('configure');
        $back = build_query_url(['page' => self::PAGE]);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($back);
        }
        ensure_csrf_or_redirect($back);

        try {
            $id = $this->service->createBaselineFromWindow(
                trim((string) ($_POST['window_end'] ?? '')),
                trim((string) ($_POST['label'] ?? '')),
                trim((string) ($_POST['note'] ?? '')) ?: null,
                $this->userId(),
                !empty($_POST['make_default'])
            );
            $this->service->recordAutoSnapshot();
            flash_set('success', 'Referința #' . $id . ' a fost salvată.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }
        redirect($back);
    }

    private function saveTestMonthsAction(): void
    {
        $this->requireAction('configure');
        $back = build_query_url(['page' => self::PAGE, 'test' => '1']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($back);
        }
        ensure_csrf_or_redirect($back);

        try {
            $count = $this->service->saveTestMonths(
                (array) ($_POST['months'] ?? []),
                (string) ($_POST['reference_end'] ?? ''),
                $this->userId()
            );
            flash_set('success', 'Prețurile de test au fost salvate (' . $count . ' luni). Simularea de mai jos le folosește; datele reale nu s-au schimbat.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }
        redirect($back);
    }

    private function comparePeriodsAction(): void
    {
        if ((string) ($_GET['test'] ?? '') === '1') {
            $this->service->enableTestMode();
        }
        try {
            $result = $this->service->comparePeriods(
                (string) ($_GET['a'] ?? ''),
                (int) ($_GET['a_len'] ?? 1),
                (string) ($_GET['b'] ?? ''),
                (int) ($_GET['b_len'] ?? 1)
            );
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $this->json(['ok' => true, 'result' => $result]);
    }

    /** Perioada de referință din calculator devine referința implicită (date reale). */
    private function savePeriodBaselineAction(): void
    {
        $this->requireAction('configure');
        $this->requirePostWithCsrf();

        try {
            $period = FuelFxAdjustmentService::calendarPeriod((string) ($_POST['b'] ?? ''), (int) ($_POST['b_len'] ?? 1));
            $id = $this->service->createBaselineFromRange(
                $period['start'],
                $period['end'],
                'Referință ' . $period['label'],
                null,
                $this->userId(),
                true
            );
            $this->service->recordAutoSnapshot();
            $this->json(['ok' => true, 'baseline_id' => $id, 'message' => 'Referința #' . $id . ' (' . $period['label'] . ') este acum referința implicită.']);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    // ---------------------------------------------------------------- aplicare

    private function applyAction(): void
    {
        $this->requireAction('apply');
        $this->requirePostWithCsrf();

        if (($_POST['confirm'] ?? '') !== 'APLIC') {
            $this->json(['ok' => false, 'message' => 'Confirmarea explicită lipsește.'], 422);
        }

        $expected = [];
        foreach ((array) ($_POST['items'] ?? []) as $signature => $proposed) {
            $signature = (string) $signature;
            if (preg_match('/^\d+\|[a-z_]+\|\d+$/', $signature) !== 1 || !is_numeric($proposed)) {
                $this->json(['ok' => false, 'message' => 'Selecție de tarife invalidă.'], 422);
            }
            $expected[$signature] = (float) $proposed;
        }

        try {
            $result = $this->service->applyApproval(
                $expected,
                trim((string) ($_POST['effective_from'] ?? '')),
                trim((string) ($_POST['note'] ?? '')) ?: null,
                $this->userId(),
                (string) (current_user()['nume'] ?? ''),
                !empty($_POST['set_default'])
            );
        } catch (Throwable $exception) {
            error_log('[FuelFxAdjustmentController][apply] ' . $exception->getMessage());
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        }

        // Configurare transport / Dispecer citesc tabelele vechi: le aliniem cu
        // versiunile în vigoare azi (cele noi sunt viitoare, deci nu schimbă nimic acum).
        $tariffs = new TransportTariffModel($this->db);
        foreach ($result['beneficiaries'] as $beneficiaryId) {
            try {
                $tariffs->syncLegacyValues((int) $beneficiaryId);
            } catch (Throwable $exception) {
                error_log('[FuelFxAdjustmentController][legacy_sync] ' . $exception->getMessage());
            }
        }

        $this->json([
            'ok' => true,
            'approval_id' => $result['approval_id'],
            'message' => sprintf('Au fost create %d versiuni noi de tarif (aprobarea #%d). Cursele și facturile existente nu au fost modificate.', $result['items'], $result['approval_id']),
        ]);
    }

    // ---------------------------------------------------------------- utilitare

    private function requireAction(string $action): void
    {
        if (can(self::PAGE, $action)) {
            return;
        }
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'message' => 'Nu ai dreptul pentru această acțiune.'], 403);
        }
        access_deny_403();
    }

    private function requirePostWithCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
        }
        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina.'], 419);
        }
    }

    private function previousBusinessDay(): string
    {
        $day = new DateTimeImmutable('today');
        // Înainte de ~13:30 cursul zilei nu e încă publicat.
        if ((int) date('Hi') < 1330 || (int) $day->format('N') >= 6) {
            $day = $day->modify('-1 day');
        }
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('-1 day');
        }

        return $day->format('Y-m-d');
    }

    private function userId(): ?int
    {
        $id = (int) (current_user()['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function wantsJson(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || in_array((string) ($_GET['action'] ?? ''), ['series', 'compare', 'compare_periods', 'approval', 'approvals'], true);
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}
