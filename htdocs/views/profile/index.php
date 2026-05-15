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
    </div>
</div>
