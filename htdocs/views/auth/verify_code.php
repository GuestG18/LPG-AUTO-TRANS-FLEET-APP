<div class="card auth-card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4 mb-2 text-center">Verificare email</h1>
        <p class="text-muted text-center mb-4">
            Am trimis un cod din 6 cifre la <strong><?= e((string) ($emailMasked ?? '')) ?></strong>.
        </p>

        <?php if (!(bool) ($deliverySent ?? true)): ?>
            <div class="alert alert-warning" role="alert">
                <div class="fw-semibold mb-1">Emailul nu a fost acceptat de serverul SMTP.</div>

                <?php if ((string) ($localFallbackCode ?? '') !== ''): ?>
                    <div class="mb-2">
                        Pentru modul local, foloseste codul:
                        <code class="fs-5"><?= e((string) $localFallbackCode) ?></code>
                    </div>
                <?php endif; ?>

                <div class="small">
                    Furnizor: <?= e((string) ($deliveryProvider ?? 'smtp')) ?>
                    <?php if ((int) ($deliveryLogId ?? 0) > 0): ?>
                        - log #<?= e((string) (int) $deliveryLogId) ?>
                    <?php endif; ?>
                </div>

                <?php if ((string) ($deliveryError ?? '') !== ''): ?>
                    <div class="small mt-1">Eroare: <?= e((string) $deliveryError) ?></div>
                <?php endif; ?>

                <?php if (!empty($deliveryWarnings)): ?>
                    <ul class="small mb-0 mt-2 ps-3">
                        <?php foreach ((array) $deliveryWarnings as $warning): ?>
                            <li><?= e((string) $warning) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(build_query_url(['page' => 'login', 'action' => 'verify_code'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="cod_verificare">Cod verificare</label>
                <input
                    type="text"
                    id="cod_verificare"
                    name="cod_verificare"
                    class="form-control"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="\d{6}"
                    maxlength="6"
                    placeholder="Ex: 123456"
                    required
                >
            </div>

            <div class="small text-muted mb-4">
                Codul expira in aproximativ <?= e((string) max(0, (int) floor(((int) ($expiresInSeconds ?? 0)) / 60))) ?> minute.
            </div>

            <button type="submit" class="btn btn-primary w-100">Verifica si continua</button>
        </form>

        <div class="d-grid gap-2 mt-3">
            <form method="post" action="<?= e(build_query_url(['page' => 'login', 'action' => 'resend_code'])) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary w-100" <?= ((int) ($resendWaitSeconds ?? 0) > 0) ? 'disabled' : '' ?>>
                    Retrimite cod
                </button>
            </form>

            <?php if ((int) ($resendWaitSeconds ?? 0) > 0): ?>
                <div class="small text-muted text-center">
                    Poti retrimite codul peste <?= e((string) (int) $resendWaitSeconds) ?> secunde.
                </div>
            <?php endif; ?>

            <a class="btn btn-link" href="<?= e(build_query_url(['page' => 'login'])) ?>">Inapoi la autentificare</a>
        </div>
    </div>
</div>
