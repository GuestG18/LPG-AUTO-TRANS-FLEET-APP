<?php
$profile = is_array($profile ?? null) ? $profile : [];
$row = is_array($profile['row'] ?? null) ? $profile['row'] : [];
$documents = is_array($profile['documents'] ?? null) ? $profile['documents'] : [];
$periods = is_array($profile['employment_periods'] ?? null) ? $profile['employment_periods'] : [];
$salaryHistory = is_array($profile['salary_history'] ?? null) ? $profile['salary_history'] : [];
$driverHistory = is_array($profile['driver_history'] ?? null) ? $profile['driver_history'] : [];
$generatedBy = (string) ($generatedBy ?? '-');
$categoryLabel = static fn(string $category): string => $category === 'office' ? 'Personal de birou' : 'Operațional';
$seniorityLabel = static function (mixed $activeDays): string {
    $days = max(0, (int) $activeDays);
    if ($days <= 0) {
        return '-';
    }
    $monthsTotal = intdiv($days, 30);
    if ($monthsTotal <= 0) {
        return $days === 1 ? '1 zi' : $days . ' zile';
    }
    $years = intdiv($monthsTotal, 12);
    $months = $monthsTotal % 12;
    $parts = [];
    if ($years > 0) {
        $parts[] = $years === 1 ? '1 an' : $years . ' ani';
    }
    if ($months > 0) {
        $parts[] = $months === 1 ? '1 lună' : $months . ' luni';
    }
    return $parts !== [] ? implode(' ', $parts) : '0 luni';
};
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fișă istoric angajat - <?= e((string) ($row['nume'] ?? '-')) ?></title>
    <style>
        body{font-family:Arial,sans-serif;color:#111827;margin:0;background:#f8fafc}
        .sheet{max-width:920px;margin:24px auto;background:#fff;padding:34px 40px;border:1px solid #e5e7eb}
        header{border-bottom:2px solid #0f172a;padding-bottom:18px;margin-bottom:22px}
        h1{font-size:28px;margin:0 0 6px} h2{font-size:17px;margin:24px 0 10px}
        .muted{color:#64748b}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 24px}
        .field{border-bottom:1px solid #e5e7eb;padding:7px 0}.field span{display:block;color:#64748b;font-size:12px;text-transform:uppercase}.field strong{font-size:15px}
        table{width:100%;border-collapse:collapse;margin-top:8px} th,td{border:1px solid #e5e7eb;padding:8px 9px;text-align:left;font-size:13px} th{background:#f8fafc}
        footer{margin-top:28px;padding-top:14px;border-top:1px solid #e5e7eb;color:#64748b;font-size:12px;display:flex;justify-content:space-between}
        .actions{max-width:920px;margin:20px auto 0;text-align:right}.print-btn{border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:6px;padding:9px 14px;font-weight:700}
        @media print{body{background:#fff}.actions{display:none}.sheet{margin:0;border:0;max-width:none}.sheet{page-break-after:auto}}
    </style>
</head>
<body>
    <div class="actions"><button class="print-btn" type="button" onclick="window.print()">Printează / salvează PDF</button></div>
    <main class="sheet">
        <header>
            <div class="muted">Fleet Management</div>
            <h1>Fișă istoric angajat</h1>
            <div class="muted">Generată la <?= e(format_datetime_ro(date('Y-m-d H:i:s'))) ?> de <?= e($generatedBy) ?></div>
        </header>

        <section class="grid">
            <div class="field"><span>Nume</span><strong><?= e((string) ($row['nume'] ?? '-')) ?></strong></div>
            <div class="field"><span>ID angajat</span><strong><?= e((string) ($row['source_type'] ?? '-')) ?>-<?= e((string) ($row['source_id'] ?? '-')) ?></strong></div>
            <div class="field"><span>Tip personal</span><strong><?= e($categoryLabel((string) ($row['category'] ?? 'operational'))) ?></strong></div>
            <div class="field"><span>Sursa</span><strong><?= e((string) ($row['source_label'] ?? '-')) ?></strong></div>
            <div class="field"><span>Funcție</span><strong><?= e((string) ($row['functie'] ?? '-')) ?></strong></div>
            <div class="field"><span>Status</span><strong>Fost angajat</strong></div>
            <div class="field"><span>Data angajării</span><strong><?= e(!empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '-') ?></strong></div>
            <div class="field"><span>Data plecării</span><strong><?= e(!empty($row['termination_effective_date']) ? format_date_ro((string) $row['termination_effective_date']) : '-') ?></strong></div>
            <div class="field"><span>Ultima zi lucrată</span><strong><?= e(!empty($row['last_working_day']) ? format_date_ro((string) $row['last_working_day']) : '-') ?></strong></div>
            <div class="field"><span>Vechime</span><strong><?= e($seniorityLabel($row['active_days'] ?? 0)) ?></strong></div>
            <div class="field"><span>Motiv plecare</span><strong><?= e((string) ($row['termination_reason'] ?? '-')) ?></strong></div>
            <div class="field"><span>Eligibil reangajare</span><strong><?= (int) ($row['rehire_eligible'] ?? 0) === 1 ? 'Da' : 'Nu' ?></strong></div>
        </section>

        <h2>Observații plecare</h2>
        <p><?= nl2br(e((string) ($row['termination_notes'] ?? '-'))) ?></p>

        <h2>Documente</h2>
        <?php if (!empty($row['termination_document_path'])): ?>
            <p><strong>Document încetare:</strong> <?= e((string) ($row['termination_document_original'] ?? $row['termination_document_path'])) ?></p>
        <?php endif; ?>
        <table>
            <thead><tr><th>Tip document</th><th>Număr</th><th>Emitere</th><th>Expirare</th></tr></thead>
            <tbody>
                <?php if ($documents === []): ?><tr><td colspan="4">Nu există documente disponibile.</td></tr><?php endif; ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td><?= e((string) ($document['tip_document'] ?? '-')) ?></td>
                        <td><?= e((string) ($document['numar_document'] ?? '-')) ?></td>
                        <td><?= e(!empty($document['data_emitere']) ? format_date_ro((string) $document['data_emitere']) : '-') ?></td>
                        <td><?= e(!empty($document['data_expirare']) ? format_date_ro((string) $document['data_expirare']) : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Perioade de angajare</h2>
        <table>
            <thead><tr><th>Tip</th><th>Funcție</th><th>Angajare</th><th>Plecare</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($periods === []): ?><tr><td colspan="5">Nu există perioade înregistrate.</td></tr><?php endif; ?>
                <?php foreach ($periods as $period): ?>
                    <tr>
                        <td><?= e($categoryLabel((string) ($period['personnel_type'] ?? 'operational'))) ?></td>
                        <td><?= e((string) ($period['function_name'] ?? '-')) ?></td>
                        <td><?= e(!empty($period['hire_date']) ? format_date_ro((string) $period['hire_date']) : '-') ?></td>
                        <td><?= e(!empty($period['termination_date']) ? format_date_ro((string) $period['termination_date']) : '-') ?></td>
                        <td><?= e((string) ($period['status'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Istoric salarial</h2>
        <table>
            <thead><tr><th>Anterior</th><th>Curent</th><th>Data</th><th>Note</th></tr></thead>
            <tbody>
                <?php if ($salaryHistory === []): ?><tr><td colspan="4">Nu există istoric salarial.</td></tr><?php endif; ?>
                <?php foreach ($salaryHistory as $salaryRow): ?>
                    <tr>
                        <td><?= e($salaryRow['previous_salary'] !== null ? format_number_ro($salaryRow['previous_salary'], 0) . ' RON' : '-') ?></td>
                        <td><?= e(format_number_ro($salaryRow['current_salary'] ?? 0, 0) . ' RON') ?></td>
                        <td><?= e(!empty($salaryRow['effective_date']) ? format_date_ro((string) $salaryRow['effective_date']) : '-') ?></td>
                        <td><?= e((string) ($salaryRow['notes'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ((string) ($row['source_type'] ?? '') === 'driver'): ?>
            <h2>Istoric operațional</h2>
            <?php $trips = is_array($driverHistory['trips'] ?? null) ? $driverHistory['trips'] : []; ?>
            <table>
                <thead><tr><th>Data</th><th>Vehicul</th><th>Transport</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if ($trips === []): ?><tr><td colspan="4">Nu există curse disponibile.</td></tr><?php endif; ?>
                    <?php foreach ($trips as $trip): ?>
                        <tr>
                            <td><?= e(!empty($trip['data_start']) ? format_date_ro((string) $trip['data_start']) : '-') ?></td>
                            <td><?= e((string) ($trip['vehicle_label'] ?? '-')) ?></td>
                            <td><?= e((string) ($trip['tip_transport'] ?? '-')) ?></td>
                            <td><?= e((string) ($trip['status_facturare'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <footer>
            <span>Fleet Management</span>
            <span>Fișă generată automat</span>
        </footer>
    </main>
</body>
</html>
