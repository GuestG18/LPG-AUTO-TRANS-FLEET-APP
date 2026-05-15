<div class="card border-0 shadow-sm">
    <div class="card-body text-center p-5">
        <h1 class="display-6 mb-3">500</h1>
        <p class="text-muted mb-4">A apărut o eroare internă. Te rugăm să reîncerci.</p>
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger text-start"><?= e($errorMessage) ?></div>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">Înapoi la tablou de bord</a>
    </div>
</div>
