<?php if ($showSidebar && is_logged_in()): ?>
            </main>
        </div>
    </div>
<?php else: ?>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php $appScriptVersion = (string) @filemtime(BASE_PATH . '/assets/js/app.js'); ?>
<script src="<?= e(url('assets/js/app.js?v=' . $appScriptVersion)) ?>"></script>
<?php if (is_logged_in()): ?>
<?php
    // Aprobari live: popup Aproba / Respinge pentru administrator, statusul curselor
    // trimise spre aprobare pentru operator (vezi InactiveResourceApprovalController::liveAction).
    $approvalLiveVersion = (string) @filemtime(BASE_PATH . '/assets/js/approval-live.js');
    $approvalLiveUser = current_user();
?>
<div hidden
     data-approval-live
     data-live-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'approvals_live'])) ?>"
     data-approve-url="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>"
     data-reject-url="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>"
     data-dispatcher-url="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-user-id="<?= e((string) (int) ($approvalLiveUser['id'] ?? 0)) ?>"></div>
<script src="<?= e(url('assets/js/approval-live.js?v=' . $approvalLiveVersion)) ?>"></script>
<?php endif; ?>
</body>
</html>
