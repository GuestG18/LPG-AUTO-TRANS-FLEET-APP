<?php
declare(strict_types=1);

/**
 * Vederea lunara a unui angajat in Contabilitate Personal.
 *
 * Tine separate conceptele care arata la fel dar nu sunt acelasi lucru:
 *  - zile lucratoare legale RO (calendar)      != zile lucrate de angajat (pontaj);
 *  - salariul curent din fisa                  != salariul aplicabil lunii (istoric);
 *  - salariul aplicabil                        != costul salarial al lunii (inregistrare).
 *
 * CO/CM-ul soferilor vine DOAR din Programare concedii (aprobat, read-only); la
 * personalul de birou se introduce in inregistrarea lunara. Dupa finalizare, luna
 * se citeste din instantaneul salvat, nu din datele curente.
 */
class StaffMonthlyAccountingService
{
    private StaffAccountancyModel $model;

    public function __construct(StaffAccountancyModel $model)
    {
        $this->model = $model;
    }

    /**
     * Luna contabila din query (?luna=YYYY-MM); implicit luna curenta.
     *
     * @return array{year: int, month: int, key: string, period: string, start: string, end: string, label: string, short_label: string}
     */
    public static function parsePeriod(?string $raw): array
    {
        $raw = trim((string) $raw);
        $year = (int) date('Y');
        $month = (int) date('n');
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $matches)) {
            $candidateYear = (int) $matches[1];
            $candidateMonth = (int) $matches[2];
            if ($candidateYear >= 2000 && $candidateYear <= 2100 && $candidateMonth >= 1 && $candidateMonth <= 12) {
                $year = $candidateYear;
                $month = $candidateMonth;
            }
        }

        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return [
            'year' => $year,
            'month' => $month,
            'key' => $first->format('Y-m'),
            'period' => $first->format('Y-m-d'),
            'start' => $first->format('Y-m-d'),
            'end' => $first->format('Y-m-t'),
            'label' => LegalCalendarService::MONTH_NAMES[$month] . ' ' . $year,
            'short_label' => LegalCalendarService::MONTH_SHORT[$month] . ' ' . $year,
            'prev' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
        ];
    }

    /**
     * Vederea lunara pentru toate randurile afisate, cu interogari pe lot
     * (inregistrari lunare si concedii), nu una per angajat.
     *
     * @return array<string, array<string, mixed>> cheie "driver-12" / "staff-3"
     */
    public function buildForRows(array $rows, array $salaryHistoryBySubject, array $period, ?int $workingDaysRo): array
    {
        $records = $this->model->getMonthlyRecordsForRows($rows, $period['period']);
        $driverIds = [];
        foreach ($rows as $row) {
            if ((string) ($row['source_type'] ?? '') === 'driver') {
                $driverIds[] = (int) ($row['source_id'] ?? 0);
            }
        }
        $leavesByDriver = $this->model->getApprovedLeavesForDrivers($driverIds, $period['start'], $period['end']);

        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['source_type'] ?? '') . '-' . (int) ($row['source_id'] ?? 0);
            $leaves = (string) ($row['source_type'] ?? '') === 'driver' ? ($leavesByDriver[(int) $row['source_id']] ?? []) : [];
            $result[$key] = $this->build($row, $salaryHistoryBySubject[$key] ?? [], $records[$key] ?? null, $leaves, $period, $workingDaysRo);
        }

        return $result;
    }

    public function buildForSubject(array $row, array $salaryHistory, array $period, ?int $workingDaysRo): array
    {
        $sourceType = (string) $row['source_type'];
        $sourceId = (int) $row['source_id'];
        $record = $this->model->findMonthlyRecord($sourceType, $sourceId, $period['period']);
        $leaves = $sourceType === 'driver'
            ? ($this->model->getApprovedLeavesForDrivers([$sourceId], $period['start'], $period['end'])[$sourceId] ?? [])
            : [];

        return $this->build($row, $salaryHistory, $record, $leaves, $period, $workingDaysRo);
    }

    public function build(array $row, array $salaryHistory, ?array $record, array $leaves, array $period, ?int $workingDaysRo): array
    {
        $isDriver = (string) ($row['source_type'] ?? '') === 'driver';
        $finalized = $record !== null && (string) $record['status'] === 'finalizat';
        $number = static fn (mixed $value): ?float => $value === null || $value === '' ? null : (float) $value;

        $currentSalary = $number($row['salariu'] ?? null);
        $hireDate = !empty($row['data_angajare']) ? substr((string) $row['data_angajare'], 0, 10) : null;
        // Salariul contractual valabil la sfarsitul lunii (angajatul poate fi angajat in cursul ei).
        $salary = StaffAccountancyModel::salaryAt($salaryHistory, $currentSalary, $hireDate, $period['end']);
        $changedInMonth = false;
        foreach ($salaryHistory as $history) {
            $effective = (string) $history['effective_date'];
            if ($effective >= $period['start'] && $effective <= $period['end']) {
                $changedInMonth = true;
                break;
            }
        }

        $leaveMap = StaffAccountancyModel::leaveDaysInMonth($leaves, $period['start'], $period['end']);
        if ($isDriver) {
            // Soferi: CO/CM exclusiv din Programare concedii (read-only).
            $coDays = $finalized ? $number($record['snapshot_zile_co_planificare']) : (float) $leaveMap['totals']['CO'];
            $cmDays = $finalized ? $number($record['snapshot_zile_cm_planificare']) : (float) $leaveMap['totals']['CM'];
            $leaveSource = 'planificare';
        } else {
            $coDays = $record !== null ? $number($record['zile_co']) : null;
            $cmDays = $record !== null ? $number($record['zile_cm']) : null;
            $leaveSource = 'inregistrare';
        }

        $regimeLive = StaffAccountancyModel::workRegimeLabel($row['regim_lucru'] ?? null, $row['regim_lucru_detalii'] ?? null);

        return [
            'is_driver' => $isDriver,
            'record' => $record,
            'record_status' => $record === null ? 'lipsa' : (string) $record['status'],
            'finalized' => $finalized,
            'applicable_salary' => $salary['amount'],
            'applicable_salary_source' => $salary['source'],
            'applicable_salary_since' => $salary['since'],
            'salary_changed_in_month' => $changedInMonth,
            'regime_label' => $finalized && $record['snapshot_regim_lucru'] !== null ? (string) $record['snapshot_regim_lucru'] : $regimeLive,
            'working_days_ro' => $finalized && $record['snapshot_zile_lucratoare_ro'] !== null ? (int) $record['snapshot_zile_lucratoare_ro'] : $workingDaysRo,
            'worked_days' => $record !== null ? $number($record['zile_lucrate']) : null,
            'co_days' => $coDays,
            'cm_days' => $cmDays,
            'absent_days' => $record !== null ? $number($record['zile_absente']) : null,
            'leave_source' => $leaveSource,
            'leave_days' => $leaveMap['days'],
            'leave_totals' => $leaveMap['totals'],
            'leaves' => $leaves,
            'salary_base' => $record !== null ? $number($record['salariu_baza']) : null,
            'bonuses' => $record !== null ? $number($record['sporuri']) : null,
            'deductions' => $record !== null ? $number($record['retineri']) : null,
            'adjustments' => $record !== null ? $number($record['alte_ajustari']) : null,
            'cost' => $record !== null ? $number($record['cost_total']) : null,
        ];
    }
}
