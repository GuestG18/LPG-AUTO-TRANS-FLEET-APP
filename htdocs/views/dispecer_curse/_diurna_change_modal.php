<?php
/**
 * Modificarea diurnelor unei curse (coloana "Diurna" din Desfasurator).
 * Operatorul trimite o cerere de aprobare; adminul o aplica direct.
 * Logica: assets/js/dispecer-diurna.js, endpoint dispecer_curse&action=request_diurna_change.
 */
$diurnaModalIsAdmin = (function_exists('can') && can('inactive_approvals', 'review'))
    || (!function_exists('can') && function_exists('is_admin') && is_admin());
?>
<div
    class="modal fade"
    id="diurnaChangeModal"
    tabindex="-1"
    aria-labelledby="diurnaChangeModalTitle"
    aria-hidden="true"
    data-diurna-modal
    data-diurna-mode="<?= $diurnaModalIsAdmin ? 'admin' : 'user' ?>"
    data-diurna-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'request_diurna_change'])) ?>"
    data-diurna-csrf="<?= e(csrf_token()) ?>"
>
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" data-diurna-form novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="diurnaChangeModalTitle">
                    <i class="bi bi-calendar2-week me-1" aria-hidden="true"></i>
                    Modifica diurnele - cursa <span data-diurna-trip-label>#-</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>

            <div class="modal-body" data-diurna-edit-step>
                <dl class="diurna-change-facts">
                    <div><dt>Sofer</dt><dd data-diurna-driver>-</dd></div>
                    <div><dt>Calculat dupa regula</dt><dd data-diurna-computed>-</dd></div>
                    <div><dt>Valoare actuala</dt><dd data-diurna-current>-</dd></div>
                </dl>

                <div class="alert alert-warning py-2 small" data-diurna-pending-warning hidden>
                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
                    <span data-diurna-pending-text></span>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="diurnaChangeValue">Numar nou de diurne <span class="text-danger">*</span></label>
                    <input class="form-control" type="number" id="diurnaChangeValue" name="diurne" min="0" max="60" step="1" inputmode="numeric" required data-diurna-value>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="diurnaChangeReason">Motiv</label>
                    <textarea class="form-control" id="diurnaChangeReason" name="motiv" rows="2" maxlength="500" placeholder="Ex.: rotunjire - soferul a plecat cu o seara inainte" data-diurna-reason></textarea>
                </div>

                <?php if ($diurnaModalIsAdmin): ?>
                    <p class="small text-muted mb-0">
                        <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                        Ai drept de aprobare: modificarea se aplica imediat si ramane in istoricul solicitarilor.
                    </p>
                <?php else: ?>
                    <p class="small text-muted mb-0">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Modificarea trebuie aprobata de administrator. Pana la decizie, cursa pastreaza valoarea actuala.
                    </p>
                <?php endif; ?>

                <div class="alert alert-danger py-2 small mt-3 mb-0" role="alert" data-diurna-error hidden></div>
            </div>

            <div class="modal-body" data-diurna-done-step hidden>
                <div class="diurna-change-done">
                    <i class="bi" data-diurna-done-icon aria-hidden="true"></i>
                    <div>
                        <strong data-diurna-done-title></strong>
                        <p class="mb-0" data-diurna-done-text></p>
                    </div>
                </div>
            </div>

            <div class="modal-footer" data-diurna-edit-footer>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunta</button>
                <button type="submit" class="btn btn-primary" data-diurna-submit>
                    <?= $diurnaModalIsAdmin ? 'Salveaza' : 'Trimite spre aprobare' ?>
                </button>
            </div>
            <div class="modal-footer" data-diurna-done-footer hidden>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Am inteles</button>
            </div>
        </form>
    </div>
</div>
