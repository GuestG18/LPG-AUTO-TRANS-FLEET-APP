<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ReviziiV2Service.php';

/**
 * Revizii & Mentenanță (V2): pagina unica ?page=revizii si actiunile JSON v2_*.
 * Ecranele existente (schimb ulei pe km SAS, plăcuțe, anvelope, istoric cu documente)
 * raman la ?page=revizii&tip=... si sunt legate din detaliul vehiculului.
 */
final class ReviziiV2Controller
{
    private const PAGE = 'revizii';

    private ReviziiV2Service $service;

    public function __construct(private PDO $db)
    {
        $this->service = new ReviziiV2Service($db);
    }

    public function index(): void
    {
        $workspace = null;
        $error = null;
        try {
            $workspace = $this->service->workspace();
        } catch (Throwable $exception) {
            error_log('[ReviziiV2][index] ' . $exception->getMessage());
            $error = 'Datele de mentenanță nu au putut fi încărcate.';
        }
        render('revizii/v2.php', [
            'pageTitle' => 'Revizii & Mentenanță',
            'currentPage' => self::PAGE,
            'workspace' => $workspace,
            'loadError' => $error,
            'canEdit' => can(self::PAGE, 'edit'),
            'canConfigure' => can(self::PAGE, 'configure'),
            'canExport' => can(self::PAGE, 'export'),
            'canSimulate' => can(self::PAGE, 'simulate'),
            'canDocuments' => can(self::PAGE, 'documents'),
        ]);
    }

    public function handle(string $action): bool
    {
        $map = [
            'v2_data' => fn () => $this->json(fn (): array => $this->service->workspace()),
            'v2_vehicle' => fn () => $this->json(fn (): array => $this->service->vehicleDetail((int) ($_GET['id'] ?? 0))),
            'v2_explain' => fn () => $this->json(fn (): array => $this->service->explain((int) ($_GET['id'] ?? 0))),
            'v2_workorder' => fn () => $this->json(fn (): array => $this->service->workOrderForm((int) ($_GET['vehicle_id'] ?? 0), ($_GET['id'] ?? '') !== '' ? (int) $_GET['id'] : null)),
            'v2_workorder_save' => fn () => $this->post('edit', fn (array $in): array => ['id' => $this->service->saveWorkOrder($in, $this->userId())]),
            'v2_workorder_finalize' => fn () => $this->post('edit', fn (array $in): array => $this->service->finalizeWorkOrder((int) ($in['id'] ?? 0), $in, $this->userId())),
            'v2_workorder_cancel' => fn () => $this->post('edit', function (array $in): array {
                $this->service->cancelWorkOrder((int) ($in['id'] ?? 0), (string) ($in['motiv'] ?? ''), $this->userId());
                return [];
            }),
            'v2_operation_record' => fn () => $this->post('edit', fn (array $in): array => ['id' => $this->service->recordOperation($in, $this->userId())]),
            'v2_execution_cancel' => fn () => $this->post('configure', function (array $in): array {
                $this->service->cancelExecution((int) ($in['id'] ?? 0), (string) ($in['motiv'] ?? ''), $this->userId());
                return [];
            }),
            'v2_history' => fn () => $this->json(fn (): array => ['rows' => $this->service->history($_GET)]),
            'v2_history_detail' => fn () => $this->json(fn (): array => $this->service->historyDetail((int) ($_GET['id'] ?? 0))),
            'v2_history_export' => fn () => $this->export(),
            'v2_config' => fn () => $this->json(fn (): array => $this->service->configData()),
            'v2_config_save' => fn () => $this->post('configure', fn (array $in): array => $this->saveConfig($in)),
            'v2_import_preview' => fn () => $this->post('configure', fn (array $in): array => $this->service->importPreview((string) ($in['text'] ?? ''))),
            'v2_import_apply' => fn () => $this->post('configure', fn (array $in): array => $this->service->importApply((string) ($in['text'] ?? ''),
                (int) ($in['sablon_x_id'] ?? 0), ($in['sablon_y_id'] ?? '') !== '' ? (int) $in['sablon_y_id'] : null, $this->userId())),
            'v2_doc_upload' => fn () => $this->upload(),
            'v2_doc' => fn () => $this->document(),
        ];
        if (!isset($map[$action])) {
            return false;
        }
        $map[$action]();

        return true;
    }

    /** @return array<string, mixed> */
    private function saveConfig(array $in): array
    {
        $entity = (string) ($in['entity'] ?? '');
        $data = is_array($in['data'] ?? null) ? $in['data'] : [];
        $userId = $this->userId();
        $id = match ($entity) {
            'operatie' => $this->service->saveOperationConfig($data, $userId),
            'sablon' => $this->service->saveTemplateConfig($data, $userId),
            'plan' => $this->service->savePlanConfig($data, $userId),
            'vehicul' => (function () use ($data, $userId): int {
                $this->service->saveVehicleConfig($data, $userId);
                return (int) ($data['vehicle_id'] ?? 0);
            })(),
            'setari' => (function () use ($data, $userId): int {
                $this->service->saveSettingsConfig($data, $userId);
                return 0;
            })(),
            default => throw new InvalidArgumentException('Entitate de configurare necunoscută.'),
        };

        return ['id' => $id, 'config' => $this->service->configData()];
    }

    private function export(): void
    {
        if (!can(self::PAGE, 'export')) {
            access_deny_403();
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rv2');
        if ($tmp === false) {
            http_response_code(500);
            echo 'Nu s-a putut crea fișierul temporar.';
            return;
        }
        try {
            $this->service->exportHistory($_GET, $tmp);
        } catch (Throwable $exception) {
            @unlink($tmp);
            error_log('[ReviziiV2][export] ' . $exception->getMessage());
            http_response_code(500);
            echo 'Exportul nu a putut fi generat.';
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="istoric-revizii-' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function upload(): void
    {
        $this->post('documents', fn (array $in): array => ['id' => $this->service->storeDocument((int) ($in['lucrare_id'] ?? 0), $_FILES['fisier'] ?? null, $this->userId())], false);
    }

    private function document(): void
    {
        $file = $this->service->documentFile((int) ($_GET['id'] ?? 0));
        if ($file === null) {
            http_response_code(404);
            echo 'Documentul nu există.';
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $file['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file['path']);
        exit;
    }

    /** @param callable(): array<string, mixed> $work */
    private function json(callable $work): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            echo json_encode(['ok' => true] + $work(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            error_log('[ReviziiV2] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Operațiunea nu a putut fi efectuată.'], JSON_UNESCAPED_UNICODE);
        }
    }

    /** POST JSON (sau formular la upload) cu CSRF si dreptul cerut. */
    private function post(string $permission, callable $work, bool $jsonBody = true): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $input = $_POST;
        if ($jsonBody && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $input = is_array($decoded) ? $decoded : [];
        }
        $token = $input['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token(is_string($token) ? $token : null)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Cerere invalidă (sesiune expirată?). Reîncarcă pagina.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!can(self::PAGE, $permission)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Nu ai dreptul pentru această acțiune.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $this->json(fn (): array => $work($input));
    }

    private function userId(): ?int
    {
        $id = (int) (current_user()['id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
