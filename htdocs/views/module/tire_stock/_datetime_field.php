<?php
/**
 * Un singur camp "data si ora" pentru montaj / mutare / demontare anvelope, cu selectorul din
 * "Adauga cursa" (assets/js/datetime-picker-field.js). Trimite doua campuri ascunse: data (Y-m-d)
 * si ora (HH:MM); din minutul urmator acestei ore se numara km SAS ai anvelopei.
 *
 * Intrari: $dtpLabel, $dtpDateName, $dtpTimeName; optional $dtpId, $dtpSmall (bara de demontare).
 */
$dtpId = (string) ($dtpId ?? ('dtp' . bin2hex(random_bytes(4))));
$dtpSmall = !empty($dtpSmall);
?>
<?php if (!$dtpSmall): ?><label class="form-label" for="<?= e($dtpId) ?>"><?= e($dtpLabel) ?></label><?php endif; ?>
<div class="dispatcher-datetime-field" data-datetime-picker>
    <div class="input-group dispatcher-datetime-input-group<?= $dtpSmall ? ' input-group-sm' : '' ?>">
        <input type="text" class="form-control" id="<?= e($dtpId) ?>" placeholder="dd/mm/yyyy HH:mm" inputmode="numeric" autocomplete="off" maxlength="16" aria-label="<?= e($dtpLabel) ?>" title="<?= e($dtpLabel) ?>: km din SAS se numără de la minutul următor" data-dtp-display>
        <button type="button" class="btn btn-outline-secondary" aria-label="Deschide calendarul si ora" aria-expanded="false" data-dtp-toggle>
            <i class="bi bi-calendar3" aria-hidden="true"></i>
        </button>
    </div>
    <div class="dispatcher-datetime-popover" hidden data-dtp-popover></div>
    <input type="hidden" name="<?= e($dtpDateName) ?>" value="<?= e(date('Y-m-d')) ?>" data-dtp-date>
    <input type="hidden" name="<?= e($dtpTimeName) ?>" value="<?= e(date('H:i')) ?>" data-dtp-time>
</div>
<?php unset($dtpId, $dtpSmall); ?>
