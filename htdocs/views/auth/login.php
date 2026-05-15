<div class="card auth-card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4 mb-2 text-center">Autentificare</h1>
        <p class="text-muted text-center mb-4">Fleet Management MVP</p>

        <form method="post" action="<?= e(build_query_url(['page' => 'login'])) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" required>
            </div>

            <div class="mb-4">
                <label class="form-label" for="parola">Parolă</label>
                <input type="password" id="parola" name="parola" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary w-100">Intră în aplicație</button>
        </form>

        <hr class="my-4">
        <p class="small text-muted mb-0">
            Cont demo administrator: <strong>admin@example.com</strong><br>
            Parolă demo: <strong>Admin123!</strong>
        </p>
    </div>
</div>
