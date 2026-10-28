<?php
declare(strict_types=1);

/**
 * Centru Kilometraj (?page=centru_kilometraj).
 *
 * Rute:
 *   (implicit)          pagina; cardurile se deseneaza din JSON (assets/js/centru-kilometraj.js)
 *   action=data         JSON: starea completa (doar baza de date, fara apeluri API)
 *   action=sync         POST: un lot de sincronizare SAS (butonul „Actualizează datele”)
 *   action=t0_lookup    POST: citirile istorice SAS + CardOil la T0, per vehicul (fara salvare)
 *   action=t0_save      POST: salveaza T0 (versiune noua; cele vechi raman in istoric)
 *   action=history      JSON: istoricul citirilor si al T0 pentru un vehicul
 *   action=save_policy  POST: politica sursei oficiale pentru totaluri
 *   action=manual_add   POST: citire manuala a odometrului unei semiremorci (optional si T0)
 *   action=manual_cancel POST: anuleaza o citire manuala (ramane in istoric)
 *   action=audit        JSON: jurnalul de audit al modulului
 * Toate actiunile sunt verificate si in router (permissions/modules/centru_kilometraj.php).
 */
class OdometerCenterController
{
    private const PAGE = 'centru_kilometraj';
    /** Un lot din butonul de actualizare: putine cereri, ca pagina sa raspunda repede. */
    private const WEB_SYNC_CALLS = 6;
    private const WEB_SYNC_SECONDS = 20;

    private OdometerCenterService $service;

    public function __construct(PDO $db)
    {
        $this->service = new OdometerCenterService($db);
    }

    public function handle(string $action): void
    {
        $this->requireAction('view');

        match ($action) {
            'data' => $this->dataAction(),
            'sync' => $this->syncAction(),
            't0_lookup' => $this->t0LookupAction(),
            't0_save' => $this->t0SaveAction(),
            'history' => $this->historyAction(),
            'save_policy' => $this->savePolicyAction(),
            'manual_add' => $this->manualAddAction(),
            'manual_cancel' => $this->manualCancelAction(),
            'audit' => $this->auditAction(),
            default => $this->indexAction(),
        };
    }

    private function indexAction(): void
    {
        render('centru_kilometraj/index.php', [
            'pageTitle' => 'Centru Kilometraj',
            'currentPage' => self::PAGE,
            'dashboard' => $this->service->buildDashboard(),
            'permissions' => $this->permissions(),
        ]);
    }

    private function dataAction(): void
    {
        $this->json(['ok' => true, 'dashboard' => $this->service->buildDashboard()]);
    }

    private function syncAction(): void
    {
        $this->requirePostWithCsrf();
        // Sesiunea nu e necesara cat timp ruleaza apelurile SAS: o eliberam ca restul paginii sa nu astepte.
        session_write_close();
        $stats = $this->service->syncSas(self::WEB_SYNC_CALLS, self::WEB_SYNC_SECONDS);
        $this->json(['ok' => !$stats['aborted'] && !$stats['locked'], 'stats' => $stats]);
    }

    private function t0LookupAction(): void
    {
        $this->requireAction('t0');
        $this->requirePostWithCsrf();
        try {
            $results = $this->service->lookupT0($this->vehicleIdsFromPost(), (string) ($_POST['t0'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $this->json(['ok' => true, 'results' => $results]);
    }

    private function t0SaveAction(): void
    {
        $this->requireAction('t0');
        $this->requirePostWithCsrf();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        try {
            $saved = $this->service->saveT0($this->vehicleIdsFromPost(), (string) ($_POST['t0'] ?? ''), $this->userId(), $reason !== '' ? $reason : null);
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $versions = array_filter(array_column($saved, 'version'));
        $this->json([
            'ok' => $versions !== [],
            'saved' => $saved,
            'message' => $versions !== []
                ? 'T0 salvat. Versiunile anterioare au rămas în istoric.'
                : 'Nimic de salvat: niciun vehicul selectat nu are surse de kilometraj.',
            'dashboard' => $this->service->buildDashboard(),
        ]);
    }

    private function historyAction(): void
    {
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $days = max(1, min(366, (int) ($_GET['days'] ?? 30)));
        try {
            $history = $this->service->vehicleHistory($vehicleId, $days, can(self::PAGE, 'audit'));
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 404);
        }
        $this->json(['ok' => true, 'history' => $history]);
    }

    private function savePolicyAction(): void
    {
        $this->requireAction('settings');
        $this->requirePostWithCsrf();
        try {
            $this->service->savePolicy((string) ($_POST['policy'] ?? ''), $this->userId());
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $this->json(['ok' => true, 'message' => 'Politica a fost salvată.', 'dashboard' => $this->service->buildDashboard()]);
    }

    private function manualAddAction(): void
    {
        $this->requireAction('manual');
        $this->requirePostWithCsrf();
        $note = trim((string) ($_POST['note'] ?? ''));
        $setT0 = ($_POST['set_t0'] ?? '') === '1';
        if ($setT0 && !can(self::PAGE, 't0')) {
            $this->json(['ok' => false, 'message' => 'Nu ai dreptul să setezi T0.'], 403);
        }
        try {
            $added = $this->service->addManualReading(
                (int) ($_POST['vehicle_id'] ?? 0),
                (string) ($_POST['measured_at'] ?? ''),
                (string) ($_POST['km'] ?? ''),
                $note !== '' ? $note : null,
                $setT0,
                $this->userId()
            );
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $this->json([
            'ok' => true,
            'message' => $added['t0_version'] !== null ? 'Citire salvată și setată ca T0.' : 'Citire salvată.',
            'dashboard' => $this->service->buildDashboard(),
        ]);
    }

    private function manualCancelAction(): void
    {
        $this->requireAction('manual');
        $this->requirePostWithCsrf();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        try {
            $this->service->cancelManualReading((int) ($_POST['vehicle_id'] ?? 0), (int) ($_POST['reading_id'] ?? 0), $reason !== '' ? $reason : null, $this->userId());
        } catch (InvalidArgumentException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
        $this->json(['ok' => true, 'message' => 'Citirea a fost anulată (rămâne în istoric).', 'dashboard' => $this->service->buildDashboard()]);
    }

    private function auditAction(): void
    {
        $this->requireAction('audit');
        $this->json(['ok' => true, 'audit' => $this->service->model()->getAudit(null, 200)]);
    }

    // ---------------------------------------------------------------- utilitare

    /** @return array<string, bool> */
    private function permissions(): array
    {
        return [
            'history' => can(self::PAGE, 'history'),
            't0' => can(self::PAGE, 't0'),
            'manual' => can(self::PAGE, 'manual'),
            'settings' => can(self::PAGE, 'settings'),
            'audit' => can(self::PAGE, 'audit'),
            'fuel' => can('carburanti'),
        ];
    }

    /** @return list<int> */
    private function vehicleIdsFromPost(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['vehicle_ids'] ?? []))));
        if ($ids === [] || count($ids) > 4) {
            throw new InvalidArgumentException('Selectează vehiculul (sau cele două componente ale ansamblului).');
        }

        return $ids;
    }

    private function userId(): ?int
    {
        $id = (int) (current_user()['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function requireAction(string $action): void
    {
        if (can(self::PAGE, $action)) {
            return;
        }
        if (($_GET['action'] ?? '') !== '' || $_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $this->json(['ok' => false, 'message' => 'Sesiunea a expirat. Reîncarcă pagina.'], 403);
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}
