<?php
$expensePromptPayload = is_array($postCreateExpensePrompt ?? null) ? $postCreateExpensePrompt : null;
$expensePromptRaceId = (int) ($expensePromptPayload['race_id'] ?? 0);
$expensePromptMode = (string) (($expensePromptPayload['mode'] ?? '') === 'updated' ? 'updated' : 'created');
$expensePromptReturnUrl = (string) ($dispecerReturnUrl ?? ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dispecer_curse'])));
$expensePromptTitle = $expensePromptMode === 'updated' ? 'Cursa a fost actualizata' : 'Cursa a fost adaugata';
$expensePromptEditUrl = $expensePromptRaceId > 0
    ? build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $expensePromptRaceId]) . '#expense-section'
    : '#';
?>

<?php if ($expensePromptRaceId > 0): ?>
    <div class="modal fade" id="postCreateExpensePromptModal" tabindex="-1" aria-labelledby="postCreateExpensePromptTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="postCreateExpensePromptTitle"><?= e($expensePromptTitle) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                </div>
                <div class="modal-body">
                    Vrei sa adaugi cheltuieli pe cursa acum?
                </div>
                <div class="modal-footer justify-content-center gap-2">
                    <a class="btn btn-primary" href="<?= e($expensePromptEditUrl) ?>" data-role="post-create-expense-yes">Da</a>
                    <button type="button" class="btn btn-outline-secondary" data-role="post-create-expense-no">Nu</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="postCreateExpenseChoiceModal" tabindex="-1" aria-labelledby="postCreateExpenseChoiceTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="postCreateExpenseChoiceTitle">Cheltuieli cursa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                </div>
                <div class="modal-body">
                    Alege de ce nu adaugi cheltuieli acum pentru aceasta cursa.
                </div>
                <div class="modal-footer justify-content-center gap-2">
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'update_expense_status'])) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $expensePromptRaceId) ?>">
                        <input type="hidden" name="cheltuieli_choice" value="not_applicable">
                        <input type="hidden" name="return_url" value="<?= e($expensePromptReturnUrl) ?>">
                        <button type="submit" class="btn btn-outline-secondary">Nu e cazul</button>
                    </form>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'update_expense_status'])) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $expensePromptRaceId) ?>">
                        <input type="hidden" name="cheltuieli_choice" value="pending">
                        <input type="hidden" name="return_url" value="<?= e($expensePromptReturnUrl) ?>">
                        <button type="submit" class="btn btn-primary">Nu acum</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var promptModalEl = document.getElementById('postCreateExpensePromptModal');
            var choiceModalEl = document.getElementById('postCreateExpenseChoiceModal');
            var noButtonEl = promptModalEl instanceof HTMLElement
                ? promptModalEl.querySelector('[data-role="post-create-expense-no"]')
                : null;
            var yesLinkEl = promptModalEl instanceof HTMLElement
                ? promptModalEl.querySelector('[data-role="post-create-expense-yes"]')
                : null;

            if (!(promptModalEl instanceof HTMLElement) || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return;
            }

            var promptModal = new bootstrap.Modal(promptModalEl);
            var choiceModal = choiceModalEl instanceof HTMLElement ? new bootstrap.Modal(choiceModalEl) : null;
            var shouldShowChoice = false;

            if (noButtonEl instanceof HTMLButtonElement && choiceModal !== null) {
                noButtonEl.addEventListener('click', function () {
                    shouldShowChoice = true;
                    promptModal.hide();
                });

                promptModalEl.addEventListener('hidden.bs.modal', function () {
                    if (!shouldShowChoice) {
                        return;
                    }

                    shouldShowChoice = false;
                    choiceModal.show();
                });
            }

            if (yesLinkEl instanceof HTMLAnchorElement) {
                yesLinkEl.addEventListener('click', function (event) {
                    var targetUrl;
                    try {
                        targetUrl = new URL(yesLinkEl.href, window.location.href);
                    } catch (error) {
                        return;
                    }

                    if (
                        targetUrl.pathname !== window.location.pathname
                        || targetUrl.search !== window.location.search
                        || targetUrl.hash === ''
                    ) {
                        return;
                    }

                    var targetEl = document.getElementById(targetUrl.hash.slice(1));
                    if (!(targetEl instanceof HTMLElement)) {
                        return;
                    }

                    event.preventDefault();
                    promptModalEl.addEventListener('hidden.bs.modal', function () {
                        window.history.replaceState(null, '', targetUrl.href);
                        targetEl.scrollIntoView({ block: 'start' });
                    }, { once: true });
                    promptModal.hide();
                });
            }

            promptModal.show();
        });
    </script>
<?php endif; ?>
