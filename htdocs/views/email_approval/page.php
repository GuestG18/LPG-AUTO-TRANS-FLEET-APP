<?php
/**
 * Pagina publica de decizie, deschisa dintr-un link din email.
 *
 * Variabile: $state ('confirm'|'done'|'blocked'), $title, $message,
 *            $record (randul din approval_email_actions + cererea), $documents, $token
 */
$state = $state ?? 'blocked';
$record = $record ?? null;
$documents = $documents ?? [];
$message = $message ?? '';
$isApprove = $record !== null && (string) $record['action'] === 'approve';

$resourceTypes = ['vehicle' => 'Vehicul', 'driver' => 'Sofer', 'repair' => 'Reparatie'];

$formatDate = static function ($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? (string) $value : date('d.m.Y', $timestamp);
};

$accent = $isApprove ? '#16a34a' : '#dc2626';
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Decizie cerere') ?> - <?= e(APP_NAME) ?></title>
<style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
        margin: 0; padding: 24px 16px; background: #f1f5f9; color: #0f172a;
        font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
        -webkit-text-size-adjust: 100%;
    }
    .wrap { max-width: 480px; margin: 0 auto; }
    .card {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
        padding: 24px; box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
    }
    .badge {
        display: inline-block; padding: 5px 12px; border-radius: 999px;
        background: #fee2e2; color: #b91c1c; font-size: 12px; font-weight: 700;
    }
    h1 { margin: 16px 0 4px; font-size: 22px; line-height: 1.25; }
    .sub { margin: 0 0 20px; color: #64748b; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; margin: 4px 0 8px; }
    td { padding: 7px 0; font-size: 14px; vertical-align: top; }
    td.k { color: #64748b; white-space: nowrap; padding-right: 14px; }
    td.v { color: #0f172a; font-weight: 600; }
    .rule { border-top: 1px solid #e2e8f0; margin: 16px 0; }
    button {
        display: block; width: 100%; padding: 18px 12px; margin-top: 8px;
        border: 0; border-radius: 10px; background: <?= $accent ?>; color: #fff;
        font-size: 18px; font-weight: 700; cursor: pointer; font-family: inherit;
    }
    button:active { filter: brightness(.92); }
    .hint { margin: 14px 0 0; color: #64748b; font-size: 12px; line-height: 1.6; text-align: center; }
    .result-icon {
        width: 60px; height: 60px; margin: 0 auto 16px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 30px; font-weight: 700; color: #fff;
    }
    .center { text-align: center; }
    .muted { color: #64748b; font-size: 14px; line-height: 1.6; }
    @media (prefers-color-scheme: dark) {
        body { background: #0f172a; color: #e2e8f0; }
        .card { background: #1e293b; border-color: #334155; }
        td.v { color: #f1f5f9; }
        .rule { border-color: #334155; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">

    <?php if ($state === 'confirm' && $record !== null): ?>

        <span class="badge"><?= e((string) $record['inactive_reason_label']) ?></span>
        <h1><?= e((string) $record['resource_label']) ?></h1>
        <p class="sub">
            <?= e($resourceTypes[(string) $record['resource_type']] ?? (string) $record['resource_type']) ?>
            &middot; cerere #<?= (int) $record['approval_id'] ?>
        </p>

        <div class="rule"></div>
        <table>
            <tr><td class="k">Motiv</td><td class="v"><?= e((string) $record['inactive_reason_label']) ?></td></tr>
            <?php if ($documents !== []): ?>
                <tr>
                    <td class="k">Documente afectate</td>
                    <td class="v"><?= e(implode(', ', array_column($documents, 'document_name'))) ?></td>
                </tr>
            <?php endif; ?>
            <tr><td class="k">Inactiv din</td><td class="v"><?= e($formatDate($record['inactive_since'])) ?></td></tr>
            <tr><td class="k">Utilizat in</td><td class="v"><?= e((string) $record['usage_context']) ?></td></tr>
            <tr><td class="k">Solicitat de</td><td class="v"><?= e((string) ($record['requested_by_name'] ?? '-')) ?></td></tr>
        </table>
        <div class="rule"></div>

        <form method="post" action="<?= e(url('index.php')) ?>?page=aprobare_email&amp;action=confirm">
            <input type="hidden" name="t" value="<?= e((string) $token) ?>">
            <button type="submit">
                <?= $isApprove ? 'Da, aprob' : 'Da, resping' ?>
            </button>
        </form>

        <p class="hint">
            Un singur apas si cererea isi schimba starea in aplicatie.<br>
            Daca ai deschis linkul din greseala, inchide pagina &mdash; nu se intampla nimic.
        </p>

    <?php elseif ($state === 'done'): ?>

        <div class="result-icon" style="background: <?= $accent ?>;">&#10003;</div>
        <div class="center">
            <h1 style="margin-top:0;"><?= e($title ?? '') ?></h1>
            <?php if ($record !== null): ?>
                <p class="sub" style="margin-bottom:16px;">
                    <?= e((string) $record['resource_label']) ?> &middot; cerere #<?= (int) $record['approval_id'] ?>
                </p>
            <?php endif; ?>
            <p class="muted"><?= e($message) ?></p>
            <p class="hint" style="margin-top:18px;">Poti inchide pagina. Nu mai ai nimic de facut.</p>
        </div>

    <?php else: ?>

        <div class="center">
            <h1 style="margin-top:0;"><?= e($title ?? 'Link indisponibil') ?></h1>
            <p class="muted"><?= e($message) ?></p>
            <?php if ($record !== null): ?>
                <p class="sub" style="margin-top:16px;">
                    <?= e((string) $record['resource_label']) ?> &middot; cerere #<?= (int) $record['approval_id'] ?>
                </p>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    </div>
</div>
</body>
</html>
