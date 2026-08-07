<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h5 mb-0">Profilul meu</h2>
            </div>
            <div class="card-body">
                <form method="post" action="<?= e(build_query_url(['page' => 'profil', 'action' => 'actualizeaza'])) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="nume" class="form-label">Nume</label>
                        <input type="text" id="nume" name="nume" class="form-control <?= isset($errors['nume']) ? 'is-invalid' : '' ?>" value="<?= e((string) ($formData['nume'] ?? '')) ?>" required>
                        <?php if (isset($errors['nume'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['nume']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= e((string) ($formData['email'] ?? '')) ?>" required>
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="telefon" class="form-label">Telefon</label>
                        <input type="text" id="telefon" name="telefon" class="form-control <?= isset($errors['telefon']) ? 'is-invalid' : '' ?>" value="<?= e((string) ($formData['telefon'] ?? '')) ?>" placeholder="Ex: 0722000000 sau +40722000000">
                        <?php if (isset($errors['telefon'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['telefon']) ?></div>
                        <?php endif; ?>
                    </div>

                    <hr class="my-4">
                    <p class="text-muted">Schimbare parola (optional)</p>

                    <div class="mb-3">
                        <label for="parola_noua" class="form-label">Parola noua</label>
                        <input type="password" id="parola_noua" name="parola_noua" class="form-control <?= isset($errors['parola_noua']) ? 'is-invalid' : '' ?>">
                        <?php if (isset($errors['parola_noua'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['parola_noua']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label for="confirmare_parola" class="form-label">Confirmare parola</label>
                        <input type="password" id="confirmare_parola" name="confirmare_parola" class="form-control <?= isset($errors['confirmare_parola']) ? 'is-invalid' : '' ?>">
                        <?php if (isset($errors['confirmare_parola'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['confirmare_parola']) ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">Actualizeaza profilul</button>
                </form>
            </div>
        </div>

        <?php $passkeys = is_array($passkeys ?? null) ? $passkeys : []; ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <h2 class="h5 mb-0"><i class="bi bi-fingerprint text-primary"></i> Passkeys</h2>
                <span class="badge text-bg-light"><?= count($passkeys) ?> înregistrate</span>
            </div>
            <div class="card-body">
                <p class="text-muted small">Autentifică-te fără parolă folosind amprenta, fața sau PIN-ul dispozitivului. Parola rămâne ca metodă de rezervă.</p>

                <div id="passkey-reg-error" class="alert alert-danger py-2 small d-none" role="alert"></div>

                <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                    <div class="flex-grow-1" style="min-width:200px">
                        <label class="form-label small mb-1" for="passkey-label">Nume passkey (opțional)</label>
                        <input type="text" id="passkey-label" class="form-control form-control-sm" maxlength="120" placeholder="Ex: Laptop birou, iPhone">
                    </div>
                    <button id="passkey-add" type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i><span>Adaugă passkey</span>
                    </button>
                </div>

                <?php if ($passkeys === []): ?>
                    <div class="text-muted small fst-italic">Niciun passkey înregistrat încă.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($passkeys as $pk): ?>
                            <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-key text-secondary"></i>
                                    <div>
                                        <div class="fw-semibold small"><?= e((string) ($pk['label'] ?? 'Passkey')) ?></div>
                                        <div class="text-muted" style="font-size:.75rem">
                                            Adăugat <?= e(format_datetime_ro((string) ($pk['created_at'] ?? ''))) ?>
                                            <?php if (!empty($pk['last_used_at'])): ?> · ultima folosire <?= e(format_datetime_ro((string) $pk['last_used_at'])) ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <form method="post" action="<?= e(build_query_url(['page' => 'profil', 'action' => 'passkey_delete'])) ?>" onsubmit="return confirm('Ștergi acest passkey?');" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="passkey_id" value="<?= (int) ($pk['id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Șterge"><i class="bi bi-trash3"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div id="passkey-unsupported" class="text-muted small mt-2 d-none">
                    <i class="bi bi-info-circle"></i> Browserul sau dispozitivul acesta nu suportă passkeys.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/js/webauthn.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/webauthn.js'))) ?>"></script>
<script>
(() => {
    const addButton = document.getElementById('passkey-add');
    const errorBox = document.getElementById('passkey-reg-error');
    const labelInput = document.getElementById('passkey-label');
    const unsupported = document.getElementById('passkey-unsupported');
    if (!addButton) { return; }

    if (!window.FleetPasskey || !window.FleetPasskey.supported()) {
        addButton.disabled = true;
        if (labelInput) { labelInput.disabled = true; }
        if (unsupported) { unsupported.classList.remove('d-none'); }
        return;
    }

    if (!window.FleetPasskey.hostAllowed()) {
        addButton.disabled = true;
        if (labelInput) { labelInput.disabled = true; }
        if (unsupported) {
            const url = window.FleetPasskey.localhostUrl();
            unsupported.classList.remove('d-none');
            unsupported.innerHTML = '<i class="bi bi-info-circle"></i> Passkey-urile nu funcționează pe o adresă IP. '
                + 'Deschide aplicația la <a href="' + url + '">' + url + '</a>.';
        }
        return;
    }

    const optionsUrl = <?= json_encode(build_query_url(['page' => 'profil', 'action' => 'passkey_options'])) ?>;
    const verifyUrl = <?= json_encode(build_query_url(['page' => 'profil', 'action' => 'passkey_register'])) ?>;
    const csrf = <?= json_encode(csrf_token()) ?>;

    addButton.addEventListener('click', async () => {
        errorBox.classList.add('d-none');
        addButton.disabled = true;
        const span = addButton.querySelector('span');
        const original = span.textContent;
        span.textContent = 'Se creează passkey-ul...';
        try {
            await window.FleetPasskey.register(optionsUrl, verifyUrl, csrf, labelInput ? labelInput.value : '');
            window.location.reload();
        } catch (err) {
            errorBox.textContent = (err && err.message) ? err.message : 'Înregistrarea passkey a eșuat.';
            errorBox.classList.remove('d-none');
            addButton.disabled = false;
            span.textContent = original;
        }
    });
})();
</script>
