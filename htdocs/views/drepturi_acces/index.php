<?php
/** @var array $groups @var array $pages @var array $users @var ?array $selectedUser @var array $granted @var bool $isConfigured @var array $templates */
$groups = is_array($groups ?? null) ? $groups : [];
$pages = is_array($pages ?? null) ? $pages : [];
$users = is_array($users ?? null) ? $users : [];
$granted = is_array($granted ?? null) ? $granted : [];
$templates = is_array($templates ?? null) ? $templates : [];
$subtitle = (string) ($subtitle ?? '');

$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = mb_substr($parts[1] ?? '', 0, 1);
    return mb_strtoupper($a . $b) ?: 'U';
};
$roleBadge = static function (string $rol): string {
    return function_exists('role_badge_html') ? role_badge_html($rol) : e($rol);
};
$selId = (int) ($selectedUser['id'] ?? 0);
$isGranted = static function (string $p, string $a) use ($granted): bool {
    return !empty($granted[$p][$a]);
};

// grupeaza paginile pe grup
$pagesByGroup = [];
foreach ($pages as $key => $page) {
    $pagesByGroup[(string) ($page['group'] ?? 'operational')][(string) $key] = $page;
}
$baseUrl = build_query_url(['page' => 'drepturi_acces']);
?>
<style>
.dax{--pri:#0d6efd}
.dax .card{border:1px solid #e2e8f0;border-radius:.8rem;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.dax .templates{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;padding:.7rem .9rem}
.dax .tpl-btn{border:1px solid #cbd5e1;background:#fff;border-radius:2rem;padding:.3rem .8rem;font-size:.82rem;color:#334155;display:inline-flex;align-items:center;gap:.4rem}
.dax .tpl-btn:hover{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.dax .layout{display:grid;grid-template-columns:260px 1fr;gap:1rem;align-items:start}
@media(max-width:900px){.dax .layout{grid-template-columns:1fr}}
.dax .u-item{display:flex;align-items:center;gap:.6rem;padding:.5rem .55rem;border-radius:.55rem;color:inherit;text-decoration:none}
.dax .u-item:hover{background:#f8fafc}
.dax .u-item.sel{background:#eef4ff;border:1px solid #cfe0ff}
.dax .u-ava{width:34px;height:34px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex:0 0 34px;background:linear-gradient(135deg,#1d4ed8,#2563eb)}
.dax .u-nm{font-weight:600;font-size:.85rem;line-height:1.05;color:#0f172a}
.dax .u-rl{font-size:.72rem;color:#64748b}
.dax .u-dot{margin-left:auto;font-size:.6rem}
.dax .detail-head{display:flex;align-items:center;gap:.9rem;padding:.9rem 1.1rem;border-bottom:1px solid #eef2f7;flex-wrap:wrap}
.dax .dh-ava{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
.dax .grp-head{display:flex;align-items:center;gap:.55rem;padding:.55rem 1.1rem;background:#f1f5f9;border-top:1px solid #e2e8f0;font-weight:600;color:#334155;font-size:.88rem}
.dax .grp-head .cnt{color:#64748b;font-weight:500;font-size:.76rem}
.dax .prow{border-bottom:1px solid #f1f5f9;padding:.55rem 1.1rem}
.dax .prow-main{display:flex;align-items:center;gap:.7rem}
.dax .prow-main .pi{color:#94a3b8;width:18px;text-align:center}
.dax .prow-main .pn{font-weight:600;color:#1e293b}
.dax .prow-main .master{margin-left:auto;display:flex;align-items:center;gap:.5rem}
.dax .caps{display:flex;flex-wrap:wrap;gap:.35rem 1.2rem;padding:.5rem 0 .1rem 2.9rem}
.dax .cap{display:flex;align-items:center;gap:.5rem;min-width:230px}
.dax .cap .cn{font-size:.82rem;color:#334155}
.dax .tag{font-size:.64rem;border-radius:.3rem;padding:.02rem .32rem;margin-left:.2rem}
.dax .tag.lock{color:#b45309;background:#fef3c7}
.dax .tag.sens{color:#3730a3;background:#e0e7ff}
.dax .scope-badge{font-size:.66rem;border-radius:.3rem;padding:.05rem .4rem;margin-left:.4rem}
.dax .scope-accountancy{color:#166534;background:#dcfce7}
.dax .scope-admin{color:#9f1239;background:#ffe4e6}
.dax .hint{color:#64748b;font-size:.8rem}
.dax .sw{position:relative;display:inline-block;width:36px;height:20px;flex:0 0 36px}
.dax .sw input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer}
.dax .track{position:absolute;inset:0;background:#cbd5e1;border-radius:20px;transition:.15s;pointer-events:none}
.dax .track:before{content:"";position:absolute;height:14px;width:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.15s;box-shadow:0 1px 2px rgba(0,0,0,.25)}
.dax .sw input:checked + .track{background:var(--pri)}
.dax .sw input:checked + .track:before{transform:translateX(16px)}
.dax .sw.sm{width:32px;height:18px;flex:0 0 32px}
.dax .sw.sm .track:before{height:12px;width:12px}
.dax .sw.sm input:checked + .track:before{transform:translateX(14px)}
.dax .savebar{position:sticky;bottom:0;display:flex;align-items:center;gap:.8rem;padding:.8rem 1.1rem;background:#fff;border-top:1px solid #e2e8f0;border-radius:0 0 .8rem .8rem;flex-wrap:wrap}
.dax .effect{display:flex;align-items:center;gap:.5rem;padding:.5rem .8rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:.6rem;color:#166534;font-size:.8rem}
</style>

<div class="dax">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-shield-lock text-primary"></i> Drepturi de acces</h1>
  </div>
  <p class="hint mb-3" style="max-width:840px"><?= e($subtitle) ?> Adminul are automat acces la tot. Un utilizator neconfigurat păstrează accesul implicit al rolului său până când salvezi drepturi pentru el.</p>

  <?php if ($templates !== []): ?>
  <div class="card mb-3">
    <div class="templates">
      <span class="hint me-1"><i class="bi bi-magic"></i> Șabloane de rol:</span>
      <?php foreach ($templates as $tpl): ?>
        <form method="post" action="<?= e(build_query_url(['page' => 'drepturi_acces', 'action' => 'apply_template'])) ?>" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int) $selId ?>">
          <input type="hidden" name="template_id" value="<?= (int) $tpl['id'] ?>">
          <button type="submit" class="tpl-btn" <?= $selId <= 0 ? 'disabled' : '' ?> title="Aplică acest șablon utilizatorului selectat">
            <i class="bi bi-person-check"></i> <?= e((string) $tpl['name']) ?>
            <?php if ((int) ($tpl['is_system'] ?? 0) === 1): ?><span class="badge text-bg-light">sistem</span><?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="layout">
    <!-- USERS -->
    <div class="card p-2">
      <?php if ($users === []): ?>
        <div class="hint p-2">Niciun utilizator.</div>
      <?php else: foreach ($users as $u):
        $uid = (int) $u['id'];
        $configured = (int) ($u['is_configured'] ?? 0) === 1; ?>
        <a class="u-item <?= $uid === $selId ? 'sel' : '' ?>" href="<?= e(build_query_url(['page' => 'drepturi_acces', 'user' => $uid])) ?>">
          <span class="u-ava"><?= e($initials((string) $u['nume'])) ?></span>
          <span>
            <span class="u-nm"><?= e((string) $u['nume']) ?></span><br>
            <span class="u-rl"><?= e(function_exists('role_display_name') ? role_display_name((string) $u['rol']) : (string) $u['rol']) ?><?= (string) $u['status'] !== 'activ' ? ' · inactiv' : '' ?></span>
          </span>
          <?php if ((string) $u['rol'] === 'admin'): ?>
            <i class="bi bi-shield-fill-check u-dot text-primary" title="Admin — acces total"></i>
          <?php elseif ($configured): ?>
            <i class="bi bi-circle-fill u-dot text-success" title="Drepturi configurate"></i>
          <?php else: ?>
            <i class="bi bi-circle u-dot text-muted" title="Acces implicit (rol)"></i>
          <?php endif; ?>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <!-- DETAIL -->
    <div class="card">
      <?php if ($selectedUser === null): ?>
        <div class="p-4 hint">Selectează un utilizator din stânga pentru a-i configura drepturile.</div>
      <?php else:
        $isAdminUser = (string) $selectedUser['rol'] === 'admin'; ?>
        <form method="post" id="daxForm">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int) $selId ?>">

          <div class="detail-head">
            <div class="dh-ava"><?= e($initials((string) $selectedUser['nume'])) ?></div>
            <div>
              <div class="fw-bold"><?= e((string) $selectedUser['nume']) ?> <?= $roleBadge((string) $selectedUser['rol']) ?></div>
              <div class="hint"><?= e((string) $selectedUser['email']) ?> · <?= $isConfigured ? 'drepturi configurate' : 'acces implicit al rolului' ?></div>
            </div>
            <div class="ms-auto d-flex gap-2 flex-wrap">
              <input type="text" name="template_name" class="form-control form-control-sm" style="width:170px" placeholder="Nume șablon nou…">
              <button type="submit" class="btn btn-sm btn-outline-secondary" formaction="<?= e(build_query_url(['page' => 'drepturi_acces', 'action' => 'save_template'])) ?>"><i class="bi bi-bookmark-plus"></i> Salvează ca șablon</button>
            </div>
          </div>

          <?php if ($isAdminUser): ?>
            <div class="alert alert-primary m-3 mb-0 py-2 small"><i class="bi bi-shield-fill-check"></i> Acest utilizator este <strong>admin</strong> și are acces la tot. Drepturile de mai jos se aplică doar utilizatorilor non-admin.</div>
          <?php endif; ?>

          <?php foreach ($groups as $groupKey => $group):
            $groupPages = $pagesByGroup[(string) $groupKey] ?? [];
            if ($groupPages === []) { continue; } ?>
            <div class="grp-head"><i class="bi <?= e((string) ($group['icon'] ?? 'bi-folder')) ?> text-primary"></i> <?= e((string) $group['label']) ?> <span class="cnt">· <?= count($groupPages) ?> pagini</span></div>

            <?php foreach ($groupPages as $pageKey => $page):
              $actions = (array) ($page['actions'] ?? []);
              $scope = (string) ($page['scope'] ?? 'all'); ?>
              <div class="prow" data-page="<?= e((string) $pageKey) ?>">
                <div class="prow-main">
                  <i class="bi <?= e((string) ($page['icon'] ?? 'bi-file')) ?> pi"></i>
                  <span class="pn"><?= e((string) $page['label']) ?></span>
                  <?php if ($scope === 'accountancy'): ?><span class="scope-badge scope-accountancy">contabilitate</span>
                  <?php elseif ($scope === 'admin'): ?><span class="scope-badge scope-admin">admin</span><?php endif; ?>
                  <div class="master">
                    <span class="hint">Acces pagină</span>
                    <label class="sw"><input type="checkbox" class="dax-view" name="perm[<?= e((string) $pageKey) ?>][view]" value="1" <?= $isGranted((string) $pageKey, 'view') ? 'checked' : '' ?>><span class="track"></span></label>
                  </div>
                </div>
                <?php
                $extra = array_filter($actions, static fn($k) => $k !== 'view', ARRAY_FILTER_USE_KEY);
                if ($extra !== []): ?>
                <div class="caps">
                  <?php foreach ($extra as $actionKey => $meta): ?>
                    <label class="cap">
                      <span class="sw sm"><input type="checkbox" class="dax-action" name="perm[<?= e((string) $pageKey) ?>][<?= e((string) $actionKey) ?>]" value="1" <?= $isGranted((string) $pageKey, (string) $actionKey) ? 'checked' : '' ?>><span class="track"></span></span>
                      <span class="cn"><?= e((string) ($meta['label'] ?? $actionKey)) ?><?php
                        if (($meta['admin'] ?? false) === true): ?><span class="tag lock"><i class="bi bi-lock-fill"></i> azi doar admin</span><?php endif;
                        if (($meta['sensitive'] ?? false) === true): ?><span class="tag sens">sensibil</span><?php endif; ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>

          <div class="savebar">
            <div class="effect"><i class="bi bi-eye"></i> <span id="daxCount">…</span></div>
            <div class="ms-auto d-flex gap-2 align-items-center">
              <button type="submit" class="btn btn-outline-danger btn-sm" formaction="<?= e(build_query_url(['page' => 'drepturi_acces', 'action' => 'reset_user'])) ?>" onclick="return confirm('Resetezi utilizatorul la accesul implicit al rolului său?');"><i class="bi bi-arrow-counterclockwise"></i> Resetează la rol</button>
              <button type="submit" class="btn btn-primary" formaction="<?= e(build_query_url(['page' => 'drepturi_acces', 'action' => 'save'])) ?>"><i class="bi bi-check2-circle"></i> Salvează drepturile</button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('daxForm');
  if (!form) return;

  // Bifarea unei actiuni implica accesul la pagina (view).
  form.querySelectorAll('.prow').forEach(function (row) {
    var view = row.querySelector('.dax-view');
    var actions = row.querySelectorAll('.dax-action');
    actions.forEach(function (a) {
      a.addEventListener('change', function () {
        if (a.checked && view) view.checked = true;
        updateCount();
      });
    });
    if (view) {
      view.addEventListener('change', function () {
        if (!view.checked) actions.forEach(function (a) { a.checked = false; });
        updateCount();
      });
    }
  });

  function updateCount() {
    var pages = 0, acts = 0;
    form.querySelectorAll('.prow').forEach(function (row) {
      var view = row.querySelector('.dax-view');
      if (view && view.checked) pages++;
      row.querySelectorAll('.dax-action').forEach(function (a) { if (a.checked) acts++; });
    });
    var el = document.getElementById('daxCount');
    if (el) el.innerHTML = 'Cu setările curente: acces la <strong>' + pages + '</strong> pagini și <strong>' + acts + '</strong> acțiuni suplimentare.';
  }
  updateCount();
})();
</script>
