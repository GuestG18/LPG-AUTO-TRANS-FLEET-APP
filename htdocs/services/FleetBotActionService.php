<?php
declare(strict_types=1);

/**
 * Safe read-only bridge between FleetBot (WhatsApp/LLM) and the Fleet app.
 *
 * Important design rule:
 * - The LLM never receives SQL access.
 * - The LLM selects one whitelisted action + structured arguments.
 * - This service executes the action using the application's existing models.
 *
 * The caller is responsible for authenticating the WhatsApp sender and checking
 * the corresponding app permission before calling execute().
 */
final class FleetBotActionService
{
    private PDO $db;
    private FuelModel $fuelModel;
    private DocumentModel $documentModel;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->fuelModel = new FuelModel($db);
        $this->documentModel = new DocumentModel($db);
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(string $action, array $arguments): array
    {
        return match ($action) {
            'fuel_consumption' => $this->getFuelConsumption(
                (string) ($arguments['vehicle'] ?? ''),
                (string) ($arguments['date'] ?? '')
            ),
            'vehicle_document_status' => $this->getVehicleDocumentStatus(
                (string) ($arguments['vehicle'] ?? ''),
                (string) ($arguments['document_type'] ?? '')
            ),
            default => $this->error('unsupported_action', 'Acțiunea cerută nu este disponibilă pentru FleetBot.'),
        };
    }

    /**
     * Reads the same fuel KPI logic used by the Carburanti page for one vehicle
     * and one calendar day.
     *
     * @return array<string,mixed>
     */
    public function getFuelConsumption(string $vehicleRegistration, string $date): array
    {
        $vehicle = $this->resolveVehicle($vehicleRegistration);
        if ($vehicle === null) {
            return $this->error('vehicle_not_found', 'Vehiculul nu a fost găsit.');
        }

        $day = $this->parseDate($date);
        if ($day === null) {
            return $this->error('invalid_date', 'Data nu este validă. Folosește o dată calendaristică reală.');
        }

        $filters = [
            'date_from' => $day,
            'date_to' => $day,
            'period' => (new DateTimeImmutable($day))->format('d.m.Y') . ' - ' . (new DateTimeImmutable($day))->format('d.m.Y'),
            'vehicle' => (string) $vehicle['nr_inmatriculare'],
            'vehicles' => [(string) $vehicle['nr_inmatriculare']],
            'transport_group' => '',
            'fuel_type' => '',
            'brand' => '',
        ];

        try {
            $this->fuelModel->ensureSchema();
            $this->fuelModel->refreshAutomaticAssociations($day, $day);
            $data = $this->fuelModel->getDashboardData($filters);
            $kpis = is_array($data['kpis'] ?? null) ? $data['kpis'] : [];
        } catch (Throwable $exception) {
            error_log('[FleetBotActionService][fuel_consumption] ' . $exception->getMessage());
            return $this->error('query_failed', 'Nu am putut calcula consumul pentru moment.');
        }

        return [
            'ok' => true,
            'action' => 'fuel_consumption',
            'vehicle' => (string) $vehicle['nr_inmatriculare'],
            'date' => $day,
            'data' => [
                'motorina_liters' => round((float) ($kpis['motorina_liters'] ?? 0), 2),
                'motorina_avg_l100' => round((float) ($kpis['motorina_avg_l100'] ?? 0), 2),
                'adblue_liters' => round((float) ($kpis['adblue_liters'] ?? 0), 2),
                'adblue_percent' => round((float) ($kpis['adblue_percent'] ?? 0), 2),
                'total_value' => round((float) ($kpis['total_value'] ?? 0), 2),
                'linked_km' => round((float) ($kpis['linked_km'] ?? 0), 2),
            ],
        ];
    }

    /**
     * Finds the current/latest matching vehicle document and returns metadata.
     * It intentionally returns the stored file name only; generating a short-lived
     * download URL belongs to the secure-document layer that will be added next.
     *
     * @return array<string,mixed>
     */
    public function getVehicleDocumentStatus(string $vehicleRegistration, string $documentType): array
    {
        $vehicle = $this->resolveVehicle($vehicleRegistration);
        if ($vehicle === null) {
            return $this->error('vehicle_not_found', 'Vehiculul nu a fost găsit.');
        }

        $documentType = trim($documentType);
        if ($documentType === '') {
            return $this->error('missing_document_type', 'Tipul documentului lipsește.');
        }

        try {
            $documents = $this->documentModel->getDocumentsForVehicle((int) $vehicle['id']);
        } catch (Throwable $exception) {
            error_log('[FleetBotActionService][vehicle_document_status] ' . $exception->getMessage());
            return $this->error('query_failed', 'Nu am putut verifica documentele pentru moment.');
        }

        $needle = $this->normalizeText($documentType);
        $matches = array_values(array_filter($documents, function (array $document) use ($needle): bool {
            $candidate = $this->normalizeText((string) ($document['tip_document'] ?? ''));
            if ($candidate === '' || $needle === '') {
                return false;
            }

            return $candidate === $needle
                || str_contains($candidate, $needle)
                || str_contains($needle, $candidate);
        }));

        if ($matches === []) {
            return $this->error(
                'document_not_found',
                sprintf('Nu am găsit documentul „%s” pentru %s.', $documentType, (string) $vehicle['nr_inmatriculare'])
            );
        }

        // Prefer the document with the furthest expiry date. If expiry is missing,
        // fall back to the most recently updated record.
        usort($matches, static function (array $a, array $b): int {
            $expiryA = trim((string) ($a['data_expirare'] ?? ''));
            $expiryB = trim((string) ($b['data_expirare'] ?? ''));
            if ($expiryA !== $expiryB) {
                return strcmp($expiryB, $expiryA);
            }

            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        $document = $matches[0];
        $expiry = trim((string) ($document['data_expirare'] ?? ''));
        $daysRemaining = null;
        $status = 'fara_expirare';

        if ($expiry !== '') {
            try {
                $today = new DateTimeImmutable('today');
                $expiryDate = new DateTimeImmutable($expiry);
                $daysRemaining = (int) $today->diff($expiryDate)->format('%r%a');
                $status = $daysRemaining < 0 ? 'expirat' : ($daysRemaining <= 30 ? 'expira_curand' : 'valabil');
            } catch (Throwable) {
                $expiry = '';
            }
        }

        return [
            'ok' => true,
            'action' => 'vehicle_document_status',
            'vehicle' => (string) $vehicle['nr_inmatriculare'],
            'document_type' => (string) ($document['tip_document'] ?? $documentType),
            'data' => [
                'document_id' => (int) ($document['id'] ?? 0),
                'document_number' => (string) ($document['numar_document'] ?? ''),
                'expiry_date' => $expiry !== '' ? $expiry : null,
                'days_remaining' => $daysRemaining,
                'status' => $status,
                'has_file' => trim((string) ($document['fisier_stocat'] ?? '')) !== '',
                'stored_file' => trim((string) ($document['fisier_stocat'] ?? '')) ?: null,
                'original_file' => trim((string) ($document['fisier_original'] ?? '')) ?: null,
            ],
        ];
    }

    /** @return array{id:int,nr_inmatriculare:string}|null */
    private function resolveVehicle(string $vehicleRegistration): ?array
    {
        $vehicleRegistration = trim($vehicleRegistration);
        if ($vehicleRegistration === '') {
            return null;
        }

        $key = $this->registrationKey($vehicleRegistration);
        if ($key === '') {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT id, nr_inmatriculare
            FROM vehicule
            WHERE REPLACE(REPLACE(UPPER(TRIM(nr_inmatriculare)), " ", ""), "-", "") = :vehicle_key
            LIMIT 1
        ');
        $stmt->bindValue(':vehicle_key', $key, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'nr_inmatriculare' => (string) $row['nr_inmatriculare'],
        ];
    }

    private function registrationKey(string $value): string
    {
        return str_replace([' ', '-'], '', strtoupper(trim($value)));
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }

        foreach (['Y-m-d', 'd.m.Y', 'd-m-Y', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    /** @return array<string,mixed> */
    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }
}
