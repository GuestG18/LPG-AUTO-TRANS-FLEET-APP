<?php $webauthnJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/webauthn.js'); ?>
<div class="card auth-card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4 mb-2 text-center">Autentificare</h1>
        <p class="text-muted text-center mb-4">Fleet Management MVP</p>

        <div id="passkey-block" class="mb-3 d-none">
            <button id="passkey-login" type="button" class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2">
                <i class="bi bi-fingerprint" aria-hidden="true"></i>
                <span>Autentificare cu passkey</span>
            </button>
            <div id="passkey-error" class="alert alert-danger mt-2 mb-0 py-2 small d-none" role="alert"></div>
            <div class="d-flex align-items-center my-3 text-muted">
                <hr class="flex-grow-1 my-0">
                <span class="px-2 small">sau cu parola</span>
                <hr class="flex-grow-1 my-0">
            </div>
        </div>

        <form id="login-form" method="post" action="<?= e(build_query_url(['page' => 'login'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" required>
            </div>

            <div class="mb-4">
                <label class="form-label" for="parola">Parola</label>
                <input type="password" id="parola" name="parola" class="form-control" required>
            </div>

            <button id="login-submit" type="submit" class="btn btn-outline-primary w-100">Intra cu parola</button>
        </form>
    </div>
</div>

<script src="<?= e(url('assets/js/webauthn.js?v=' . $webauthnJsVersion)) ?>"></script>
<script>
(() => {
    const form = document.getElementById('login-form');
    const submitButton = document.getElementById('login-submit');
    if (form && submitButton) {
        form.addEventListener('submit', () => {
            submitButton.disabled = true;
            submitButton.textContent = 'Se pregateste verificarea...';
        });
    }

    const block = document.getElementById('passkey-block');
    const button = document.getElementById('passkey-login');
    const errorBox = document.getElementById('passkey-error');
    if (!block || !button || !window.FleetPasskey || !window.FleetPasskey.supported()) {
        return;
    }

    if (!window.FleetPasskey.hostAllowed()) {
        block.classList.remove('d-none');
        button.classList.add('d-none');
        const url = window.FleetPasskey.localhostUrl();
        errorBox.className = 'alert alert-info mt-2 mb-0 py-2 small';
        errorBox.innerHTML = 'Passkey-urile nu funcționează pe o adresă IP. Deschide aplicația la '
            + '<a href="' + url + '">' + url + '</a> pentru a le folosi.';
        return;
    }

    block.classList.remove('d-none');

    const optionsUrl = <?= json_encode(build_query_url(['page' => 'login', 'action' => 'passkey_options'])) ?>;
    const verifyUrl = <?= json_encode(build_query_url(['page' => 'login', 'action' => 'passkey_verify'])) ?>;

    button.addEventListener('click', async () => {
        errorBox.classList.add('d-none');
        button.disabled = true;
        const label = button.querySelector('span');
        const original = label.textContent;
        label.textContent = 'Se asteapta passkey-ul...';
        try {
            const result = await window.FleetPasskey.login(optionsUrl, verifyUrl);
            window.location.href = result.redirect || <?= json_encode(url('index.php?page=dashboard')) ?>;
        } catch (err) {
            errorBox.textContent = (err && err.message) ? err.message : 'Autentificarea cu passkey a esuat.';
            errorBox.classList.remove('d-none');
            button.disabled = false;
            label.textContent = original;
        }
    });
})();
</script>
