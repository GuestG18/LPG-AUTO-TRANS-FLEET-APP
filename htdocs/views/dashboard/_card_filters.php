<?php
declare(strict_types=1);

/**
 * Bara de filtre din interiorul unui card din Tablou de bord: filtrele paginii-sursă a cardului,
 * descrise în registru ('filter_fields') și salvate în config-ul cardului (layout-ul utilizatorului).
 * Valorile se validează pe server (DashboardWidgetRegistry::normalizeConfig + regulile paginii-sursă).
 * Comportamentul (meniuri, lună ‹ ›, aplicare, resetare) e în assets/js/dashboard-grid.js.
 *
 * @var string $cfWidget       id-ul cardului
 * @var array  $cfFields       [['key','label','type' => multi|select|period|month|text,'all' => eticheta „toate”], ...]
 * @var array  $cfOptions      key => [valoare => etichetă] (aceleași surse ca formularul paginii)
 * @var array  $cfConfig       config-ul salvat al cardului (validat)
 * @var array  $cfExtraLabels  (opțional) key => [valoare => etichetă] pentru valori alese care nu mai au date
 * @var string $cfShownFrom    începutul perioadei afișate (Y-m-d), pentru ‹ › din câmpul perioadă
 */
$cfExtraLabels = is_array($cfExtraLabels ?? null) ? $cfExtraLabels : [];
$cfShownFrom = (string) ($cfShownFrom ?? '');
$cfMonthNames = [1 => 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
$cfMonthLabel = static function (string $ym) use ($cfMonthNames): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    return $d ? $cfMonthNames[(int) $d->format('n')] . ' ' . $d->format('Y') : $ym;
};
$cfUid = preg_replace('/[^a-z0-9]+/i', '_', $cfWidget);
?>
<form class="dash-card-filters" data-dash-card-filters data-widget="<?= e($cfWidget) ?>" data-period-from="<?= e($cfShownFrom) ?>" autocomplete="off" onsubmit="return false">
    <?php foreach ($cfFields as $field): ?>
        <?php
        $key = (string) $field['key'];
        $type = (string) $field['type'];
        $label = (string) $field['label'];
        $all = (string) ($field['all'] ?? 'Toate');
        $options = (array) ($cfOptions[$key] ?? []);
        $value = $cfConfig[$key] ?? null;
        $extra = (array) ($cfExtraLabels[$key] ?? []);
        $fieldId = 'cf_' . $cfUid . '_' . preg_replace('/[^a-z0-9]+/i', '_', $key);
        ?>
        <?php if ($type === 'multi'): ?>
            <?php
            $selected = array_map('strval', (array) ($value ?? []));
            $known = array_map('strval', array_keys($options));
            $orphans = array_values(array_diff($selected, $known)); // alese, dar fără date cu filtrele de acum
            $toggleLabel = $selected === [] ? $all
                : (count($selected) === 1 ? (string) ($options[$selected[0]] ?? $extra[$selected[0]] ?? $selected[0]) : count($selected) . ' alese');
            ?>
            <div class="dash-cf-field">
                <span class="dash-cf-label" id="<?= e($fieldId) ?>_l"><?= e($label) ?></span>
                <div class="dash-dd" data-dash-dd>
                    <button type="button" class="form-select dash-dd-toggle<?= $selected !== [] ? ' is-set' : '' ?>" data-dash-dd-toggle aria-haspopup="true" aria-expanded="false" aria-labelledby="<?= e($fieldId) ?>_l"><?= e($toggleLabel) ?></button>
                    <div class="dash-dd-menu" data-dash-dd-menu hidden>
                        <input type="search" class="form-control form-control-sm" placeholder="Caută..." data-dash-dd-search aria-label="Caută în <?= e(mb_strtolower($label)) ?>">
                        <div class="dash-dd-quick">
                            <button type="button" data-dash-dd-clear><?= e($all) ?></button>
                            <span data-dash-dd-count></span>
                        </div>
                        <div class="dash-dd-list">
                            <?php foreach ($orphans as $orphan): ?>
                                <label class="dash-dd-option is-dropped" data-name="<?= e(mb_strtolower((string) ($extra[$orphan] ?? $orphan))) ?>" title="Ales, dar fără date cu filtrele de acum">
                                    <input type="checkbox" name="<?= e($key) ?>[]" value="<?= e($orphan) ?>" checked>
                                    <span><?= e((string) ($extra[$orphan] ?? $orphan)) ?></span><small>fără date</small>
                                </label>
                            <?php endforeach; ?>
                            <?php foreach ($options as $optValue => $optLabel): ?>
                                <label class="dash-dd-option" data-name="<?= e(mb_strtolower((string) $optLabel)) ?>">
                                    <input type="checkbox" name="<?= e($key) ?>[]" value="<?= e((string) $optValue) ?>"<?= in_array((string) $optValue, $selected, true) ? ' checked' : '' ?>>
                                    <span><?= e((string) $optLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if ($options === [] && $orphans === []): ?>
                                <p class="dash-dd-empty">Nicio opțiune cu filtrele de acum.</p>
                            <?php endif; ?>
                        </div>
                        <div class="dash-dd-foot"><button type="button" class="btn btn-sm btn-primary" data-dash-dd-apply>Aplică</button></div>
                    </div>
                </div>
            </div>

        <?php elseif ($type === 'select'): ?>
            <?php $current = $value === null ? '' : (string) $value; ?>
            <label class="dash-cf-field">
                <span class="dash-cf-label"><?= e($label) ?></span>
                <select class="form-select form-select-sm<?= $current !== '' ? ' is-set' : '' ?>" name="<?= e($key) ?>">
                    <option value=""><?= e($all) ?></option>
                    <?php if ($current !== '' && !array_key_exists($current, $options) && !array_key_exists((int) $current, $options)): ?>
                        <option value="<?= e($current) ?>" selected><?= e((string) ($extra[$current] ?? $current)) ?> — fără date</option>
                    <?php endif; ?>
                    <?php foreach ($options as $optValue => $optLabel): ?>
                        <option value="<?= e((string) $optValue) ?>"<?= $current === (string) $optValue ? ' selected' : '' ?>><?= e((string) $optLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

        <?php elseif ($type === 'text'): ?>
            <label class="dash-cf-field">
                <span class="dash-cf-label"><?= e($label) ?></span>
                <input type="search" class="form-control form-control-sm<?= $value !== null ? ' is-set' : '' ?>" name="<?= e($key) ?>" value="<?= e((string) ($value ?? '')) ?>" placeholder="<?= e($all) ?>" list="<?= e($fieldId) ?>_list">
                <?php if ($options !== []): ?>
                    <datalist id="<?= e($fieldId) ?>_list">
                        <?php foreach ($options as $optLabel): ?><option value="<?= e((string) $optLabel) ?>"></option><?php endforeach; ?>
                    </datalist>
                <?php endif; ?>
            </label>

        <?php elseif ($type === 'month'): ?>
            <?php $current = $value === null ? '' : (string) $value; ?>
            <div class="dash-cf-field">
                <span class="dash-cf-label"><?= e($label) ?></span>
                <div class="dash-cf-period">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dash-select-step="1" aria-label="Luna anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <select class="form-select form-select-sm<?= $current !== '' ? ' is-set' : '' ?>" name="<?= e($key) ?>" aria-label="<?= e($label) ?>">
                        <option value=""><?= e($all) ?></option>
                        <?php if ($current !== '' && !array_key_exists($current, $options)): ?>
                            <option value="<?= e($current) ?>" selected><?= e($cfMonthLabel($current)) ?></option>
                        <?php endif; ?>
                        <?php foreach ($options as $optValue => $optLabel): ?>
                            <option value="<?= e((string) $optValue) ?>"<?= $current === (string) $optValue ? ' selected' : '' ?>><?= e((string) $optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dash-select-step="-1" aria-label="Luna următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
            </div>

        <?php elseif ($type === 'period'): ?>
            <?php
            $from = (string) ($cfConfig['date_from'] ?? '');
            $to = (string) ($cfConfig['date_to'] ?? '');
            $mode = '';
            if ($from !== '' && $to !== '') {
                $f = new DateTimeImmutable($from);
                $mode = $f->format('d') === '01' && $to === $f->format('Y-m-t') ? $f->format('Y-m') : 'custom';
            }
            $months = [];
            $cursor = new DateTimeImmutable('first day of this month');
            for ($i = 0; $i < 18; $i++) {
                $months[$cursor->format('Y-m')] = $cfMonthLabel($cursor->format('Y-m'));
                $cursor = $cursor->modify('-1 month');
            }
            if ($mode !== '' && $mode !== 'custom' && !isset($months[$mode])) {
                $months[$mode] = $cfMonthLabel($mode);
                krsort($months);
            }
            ?>
            <div class="dash-cf-field is-wide">
                <span class="dash-cf-label"><?= e($label) ?></span>
                <div class="dash-cf-period">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dash-month-step="-1" aria-label="Luna anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <select class="form-select form-select-sm<?= $mode !== '' ? ' is-set' : '' ?>" data-dash-date-mode aria-label="<?= e($label) ?>">
                        <option value=""<?= $mode === '' ? ' selected' : '' ?>><?= e($all) ?></option>
                        <?php foreach ($months as $ym => $monthLabel): ?>
                            <option value="<?= e($ym) ?>"<?= $mode === $ym ? ' selected' : '' ?>><?= e($monthLabel) ?></option>
                        <?php endforeach; ?>
                        <option value="custom"<?= $mode === 'custom' ? ' selected' : '' ?>>Interval personalizat…</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dash-month-step="1" aria-label="Luna următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
                <div class="dash-cf-custom" data-dash-date-custom<?= $mode === 'custom' ? '' : ' hidden' ?>>
                    <input type="date" class="form-control form-control-sm" data-dash-date-from value="<?= e($from !== '' ? $from : $cfShownFrom) ?>" aria-label="De la">
                    <span>–</span>
                    <input type="date" class="form-control form-control-sm" data-dash-date-to value="<?= e($to) ?>" aria-label="Până la">
                </div>
                <input type="hidden" name="date_from" value="<?= e($from) ?>">
                <input type="hidden" name="date_to" value="<?= e($to) ?>">
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($cfConfig !== []): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary dash-cf-reset" data-dash-card-reset title="Șterge filtrele cardului (revine la filtrele de sus)">
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează
        </button>
    <?php endif; ?>
</form>
