<?php
declare(strict_types=1);

class VehicleAuthorizationController
{
    private VehicleAuthorizationModel $authorizationModel;

    public function __construct(private PDO $db)
    {
        $this->authorizationModel = new VehicleAuthorizationModel($db);
    }

    public function handle(string $action): void
    {
        match ($action) {
            'store' => $this->storeAction(),
            'update' => $this->updateAction(),
            'delete' => $this->deleteAction(),
            'store_zone' => $this->storeZoneAction(),
            'delete_zone' => $this->deleteZoneAction(),
            default => $this->indexAction(),
        };
    }

    private function indexAction(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $result = $this->authorizationModel->getAuthorizationList($search, $page, 5);

        render('vehicle_authorizations/index.php', [
            'pageTitle' => 'Autorizații Vehicule',
            'currentPage' => 'autorizatii_vehicule',
            'search' => $search,
            'vehicles' => $this->authorizationModel->getVehicleOptions(),
            'zones' => $this->authorizationModel->getZones(),
            'rows' => $result['rows'],
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total_rows' => $result['total_rows'],
                'per_page' => $result['per_page'],
            ],
        ]);
    }

    private function storeAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        [$data, $errors] = $this->authorizationPayload();

        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->backUrl());
        }

        try {
            $this->authorizationModel->createAuthorization($data);
            flash_set('success', 'Autorizația a fost adăugată.');
        } catch (Throwable $exception) {
            error_log('[VehicleAuthorization][store] ' . $exception->getMessage());
            flash_set('danger', 'Autorizația nu a putut fi salvată.');
        }

        redirect($this->backUrl(['p' => 1]));
    }

    private function updateAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = (int) ($_POST['authorization_id'] ?? 0);
        $existing = $id > 0 ? $this->authorizationModel->getAuthorization($id) : null;

        if ($existing === null) {
            flash_set('warning', 'Autorizația selectată nu există.');
            redirect($this->backUrl());
        }

        [$data, $errors] = $this->authorizationPayload();
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->backUrl());
        }

        try {
            $this->authorizationModel->updateAuthorization($id, $data);
            flash_set('success', 'Autorizația a fost actualizată.');
        } catch (Throwable $exception) {
            error_log('[VehicleAuthorization][update] ' . $exception->getMessage());
            flash_set('danger', 'Autorizația nu a putut fi actualizată.');
        }

        redirect($this->backUrl());
    }

    private function deleteAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = (int) ($_POST['authorization_id'] ?? 0);
        $existing = $id > 0 ? $this->authorizationModel->getAuthorization($id) : null;

        if ($existing === null) {
            flash_set('warning', 'Autorizația selectată nu există.');
            redirect($this->backUrl());
        }

        try {
            $this->authorizationModel->deleteAuthorization($id);
            flash_set('success', 'Autorizația a fost ștearsă.');
        } catch (Throwable $exception) {
            error_log('[VehicleAuthorization][delete] ' . $exception->getMessage());
            flash_set('danger', 'Autorizația nu a putut fi ștearsă.');
        }

        redirect($this->backUrl());
    }

    private function storeZoneAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $name = $this->authorizationModel->normalizeZoneName((string) ($_POST['zone_name'] ?? ''));

        if ($name === '') {
            flash_set('danger', 'Numele zonei este obligatoriu.');
            redirect($this->backUrl());
        }

        try {
            $this->authorizationModel->addZone($name);
            flash_set('success', 'Zona a fost salvată.');
        } catch (Throwable $exception) {
            error_log('[VehicleAuthorization][zone-store] ' . $exception->getMessage());
            flash_set('danger', 'Zona nu a putut fi salvată.');
        }

        redirect($this->backUrl());
    }

    private function deleteZoneAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = (int) ($_POST['zone_id'] ?? 0);

        if ($id <= 0 || !$this->authorizationModel->zoneExists($id)) {
            flash_set('warning', 'Zona selectată nu există.');
            redirect($this->backUrl());
        }

        try {
            $this->authorizationModel->deleteZone($id);
            flash_set('success', 'Zona a fost ștearsă.');
        } catch (Throwable $exception) {
            flash_set('danger', $exception->getMessage());
        }

        redirect($this->backUrl());
    }

    private function authorizationPayload(): array
    {
        $data = [
            'vehicle_id' => (int) ($_POST['vehicle_id'] ?? 0),
            'authorization_type' => trim((string) ($_POST['authorization_type'] ?? '')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'zone_id' => (int) ($_POST['zone_id'] ?? 0),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'cost' => $this->normalizeCost($_POST['cost'] ?? ''),
        ];
        $errors = [];

        if ($data['vehicle_id'] <= 0 || !$this->authorizationModel->vehicleExists((int) $data['vehicle_id'])) {
            $errors[] = 'Selectează un număr de înmatriculare valid.';
        }

        if ($data['authorization_type'] === '') {
            $errors[] = 'Tipul autorizației este obligatoriu.';
        } elseif (mb_strlen((string) $data['authorization_type']) > 120) {
            $errors[] = 'Tipul autorizației nu poate depăși 120 de caractere.';
        }

        if (!$this->isValidDate((string) $data['start_date'])) {
            $errors[] = 'Data de început este obligatorie și trebuie să fie validă.';
        }

        if ($data['zone_id'] <= 0 || !$this->authorizationModel->zoneExists((int) $data['zone_id'])) {
            $errors[] = 'Selectează o zonă validă.';
        }

        if (!$this->isValidDate((string) $data['end_date'])) {
            $errors[] = 'Data de sfârșit este obligatorie și trebuie să fie validă.';
        }

        if ($data['cost'] === null || $data['cost'] < 0) {
            $errors[] = 'Costul trebuie să fie un număr pozitiv.';
        }

        if (
            $this->isValidDate((string) $data['start_date'])
            && $this->isValidDate((string) $data['end_date'])
            && (string) $data['end_date'] < (string) $data['start_date']
        ) {
            $errors[] = 'Data de sfârșit trebuie să fie după data de început.';
        }

        return [$data, $errors];
    }

    private function isValidDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function normalizeCost(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(' ', '', $raw);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
        }
        $raw = str_replace(',', '.', $raw);

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function indexUrl(array $extra = []): string
    {
        return build_query_url(array_merge(['page' => 'autorizatii_vehicule'], $extra));
    }

    private function backUrl(array $overrides = []): string
    {
        $params = ['page' => 'autorizatii_vehicule'];
        foreach (['q', 'p'] as $key) {
            $value = trim((string) ($_POST[$key] ?? $_GET[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return build_query_url(array_merge($params, $overrides));
    }
}
