<div class="card auth-card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4 mb-2 text-center">Autentificare</h1>
        <p class="text-muted text-center mb-4">Fleet Management MVP</p>

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

            <button id="login-submit" type="submit" class="btn btn-primary w-100">Intra in aplicatie</button>
        </form>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('login-form');
    const submitButton = document.getElementById('login-submit');
    if (!form || !submitButton) {
        return;
    }

    form.addEventListener('submit', () => {
        submitButton.disabled = true;
        submitButton.textContent = 'Se pregateste verificarea...';
    });
})();
</script>
