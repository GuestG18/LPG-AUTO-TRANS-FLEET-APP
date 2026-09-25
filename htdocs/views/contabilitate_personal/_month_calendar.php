<?php
/**
 * Grila unei luni din calendarul legal RO.
 *
 * Variabile:
 *   $cal     rezultatul LegalCalendarService::getMonthSummary()
 *   $overlay (optional) [Y-m-d => ['code' => 'CO'|'CM'|..., 'label', 'from', 'to']]
 *            = starea ANGAJATULUI (Programare concedii), desenata separat de starea legala.
 *   $calSize (optional) 'sm' | 'lg'
 *
 * Starea legala (zi lucratoare / weekend / sarbatoare) e fundalul celulei; starea
 * angajatului e o eticheta distincta in celula, ca cele doua sa nu se confunde.
 */
$cal = is_array($cal ?? null) ? $cal : [];
$overlay = is_array($overlay ?? null) ? $overlay : [];
$calSize = (string) ($calSize ?? 'sm');
$calDays = is_array($cal['days'] ?? null) ? $cal['days'] : [];
$calComplete = !empty($cal['complete']);
$calLead = max(0, (int) ($cal['first_weekday'] ?? 1) - 1);
$calStatusLabel = [
    'working' => 'Zi lucrătoare (RO)',
    'weekend' => 'Weekend',
    'holiday' => 'Sărbătoare legală',
];
?>
<div class="cp-cal cp-cal-<?= e($calSize) ?> <?= $calComplete ? '' : 'is-incomplete' ?>">
    <div class="cp-cal-grid" role="grid" aria-label="Calendar legal <?= e((string) ($cal['label'] ?? '')) ?>">
        <?php foreach (['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'] as $weekdayLabel): ?>
            <div class="cp-cal-head" role="columnheader"><?= e($weekdayLabel) ?></div>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $calLead; $i++): ?>
            <div class="cp-cal-cell is-empty" aria-hidden="true"></div>
        <?php endfor; ?>
        <?php foreach ($calDays as $calDay): ?>
            <?php
            $calIso = (string) $calDay['date'];
            // Fara calendar complet nu afirmam ca o zi de lucru e "lucratoare".
            $calStatus = $calComplete || $calDay['status'] === 'weekend' ? (string) $calDay['status'] : 'unknown';
            $calTitle = format_date_ro($calIso) . ' — ' . ($calStatusLabel[$calStatus] ?? 'Sărbători neverificate');
            if (!empty($calDay['holiday'])) {
                $calTitle = format_date_ro($calIso) . ' — ' . $calDay['holiday'] . ' (sărbătoare legală' . ($calDay['is_weekend'] ? ', în weekend' : '') . ')';
            }
            $calMark = $overlay[$calIso] ?? null;
            if ($calMark !== null) {
                $calTitle .= "\n" . $calMark['label'] . ': ' . format_date_ro((string) $calMark['from']) . ' - ' . format_date_ro((string) $calMark['to']) . ' · Aprobat · Sursă: Planificare concedii';
            }
            ?>
            <div
                class="cp-cal-cell is-<?= e($calStatus) ?><?= !empty($calDay['holiday']) ? ' has-holiday' : '' ?><?= $calIso === date('Y-m-d') ? ' is-today' : '' ?>"
                role="gridcell"
                title="<?= e($calTitle) ?>"
            >
                <span class="cp-cal-day"><?= e((string) $calDay['day']) ?></span>
                <?php if ($calMark !== null): ?>
                    <span class="cp-cal-mark is-<?= e(strtolower((string) $calMark['code'])) ?>"><?= e((string) $calMark['code']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="cp-cal-legend">
        <span><i class="cp-dot is-working"></i>Zi lucrătoare (RO)</span>
        <span><i class="cp-dot is-weekend"></i>Weekend</span>
        <span><i class="cp-dot is-holiday"></i>Sărbătoare legală</span>
        <?php if ($overlay !== []): ?>
            <span class="cp-cal-legend-employee" title="Stare angajat, din Programare concedii (read-only)">
                <i class="cp-cal-mark is-co">CO</i><i class="cp-cal-mark is-cm">CM</i> concediu aprobat
            </span>
        <?php endif; ?>
    </div>
</div>
