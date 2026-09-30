<?php
/**
 * Drepturi de acces — consola ACL.
 *
 * ATENTIE: view-ul NU contine liste de pagini / actiuni. Sectiunile, modulele,
 * grupurile si actiunile vin din PermissionRegistry (permissions/modules/*.php).
 *
 * @var array $sections @var array $modules @var array $users @var ?array $selectedUser
 * @var array $granted @var array $roleDefaults @var bool $isConfigured @var array $templates
 * @var array $deprecated
 */
$sections = is_array($sections ?? null) ? $sections : [];
$modules = is_array($modules ?? null) ? $modules : [];
$users = is_array($users ?? null) ? $users : [];
$granted = is_array($granted ?? null) ? $granted : [];
$roleDefaults = is_array($roleDefaults ?? null) ? $roleDefaults : [];
$templates = is_array($templates ?? null) ? $templates : [];
$deprecated = is_array($deprecated ?? null) ? $deprecated : [];
$isConfigured = (bool) ($isConfigured ?? false);

$roleMeta = static function (string $rol): array {
    return match (strtolower(trim($rol))) {
        'admin'         => ['label' => 'Admin', 'plural' => 'Admini', 'icon' => 'bi-shield-fill-check', 'key' => 'admin'],
        'contabilitate' => ['label' => 'Contabilitate', 'plural' => 'Contabili', 'icon' => 'bi-calculator', 'key' => 'contabilitate'],
        default         => ['label' => 'Operator', 'plural' => 'Operatori', 'icon' => 'bi-person-gear', 'key' => 'operator'],
    };
};
$avatar = static function (array $user, string $class): string {
    $data = function_exists('profile_avatar_data') ? profile_avatar_data($user) : ['type' => 'none', 'initials' => ''];
    if (($data['type'] ?? '') === 'image' && !empty($data['url'])) {
        return '<span class="' . e($class) . ' has-image"><img src="' . e((string) $data['url']) . '" alt="" loading="lazy"></span>';
    }
    $initials = (string) ($data['initials'] ?? '');

    return '<span class="' . e($class) . '">' . ($initials !== '' ? e($initials) : '<i class="bi bi-person-fill" aria-hidden="true"></i>') . '</span>';
};

$selId = (int) ($selectedUser['id'] ?? 0);
$isAdminUser = $selectedUser !== null && (string) $selectedUser['rol'] === 'admin';
$selRole = $roleMeta((string) ($selectedUser['rol'] ?? ''));

// numarul de utilizatori pe rol, pentru filtrele din bara laterala
$roleCounts = [];
foreach ($users as $u) {
    $k = $roleMeta((string) $u['rol'])['key'];
    $roleCounts[$k] = ($roleCounts[$k] ?? 0) + 1;
}

$modulesBySection = [];
foreach ($modules as $key => $module) {
    $modulesBySection[(string) $module['section']][(string) $key] = $module;
}

$isChecked = static function (string $module, string $action) use ($granted, $isAdminUser): bool {
    return $isAdminUser || !empty($granted[$module][$action]);
};

$templatesForJs = array_map(static fn(array $t): array => [
    'id' => (int) $t['id'],
    'name' => (string) $t['name'],
    'system' => (int) ($t['is_system'] ?? 0) === 1,
    'perms' => $t['perms'] ?? [],
], $templates);

$assetVersion = static fn(string $path): string => (string) @filemtime(BASE_PATH . '/' . $path);
$url = static fn(string $action): string => build_query_url(['page' => 'drepturi_acces', 'action' => $action]);
?>
<link rel="stylesheet" href="<?= e(url('assets/css/drepturi-acces.css?v=' . $assetVersion('assets/css/drepturi-acces.css'))) ?>">

<div class="dax" id="dax"
     data-user-id="<?= (int) $selId ?>"
     data-admin-user="<?= $isAdminUser ? '1' : '0' ?>"
     data-configured="<?= $isConfigured ? '1' : '0' ?>"
     data-role-label="<?= e($selRole['label']) ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-url-save="<?= e($url('save')) ?>"
     data-url-reset="<?= e($url('reset_user')) ?>"
     data-url-save-template="<?= e($url('save_template')) ?>"
     data-url-delete-template="<?= e($url('delete_template')) ?>">

  <!-- ============================ UTILIZATORI ============================ -->
  <aside class="dax-users" aria-label="Utilizatori">
    <div class="dax-users-head">
      <h2>Utilizatori</h2>
      <?php if (function_exists('can') && can('utilizatori', 'create')): ?>
        <a class="btn btn-primary btn-sm dax-btn-new" href="<?= e(build_query_url(['page' => 'utilizatori', 'action' => 'create'])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Utilizator nou</a>
      <?php endif; ?>
    </div>
    <label class="dax-search dax-search-sm">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="search" id="daxUserSearch" placeholder="Caută utilizator..." autocomplete="off" aria-label="Caută utilizator">
    </label>
    <div class="dax-user-filters" role="group" aria-label="Filtrează după rol">
      <button type="button" class="dax-chip is-active" data-user-role="">Toți <span><?= count($users) ?></span></button>
      <?php foreach (['admin' => 'Admini', 'contabilitate' => 'Contabili', 'operator' => 'Operatori'] as $rk => $rl): if (empty($roleCounts[$rk])) { continue; } ?>
        <button type="button" class="dax-chip" data-user-role="<?= e($rk) ?>"><?= e($rl) ?> <span><?= (int) $roleCounts[$rk] ?></span></button>
      <?php endforeach; ?>
    </div>
    <nav class="dax-user-list" id="daxUserList">
      <?php if ($users === []): ?>
        <div class="dax-empty">Niciun utilizator.</div>
      <?php endif; ?>
      <?php foreach ($users as $u):
        $uid = (int) $u['id'];
        $rm = $roleMeta((string) $u['rol']);
        $active = (string) ($u['status'] ?? '') === 'activ';
        $configured = (int) ($u['is_configured'] ?? 0) === 1; ?>
        <a class="dax-user <?= $uid === $selId ? 'is-selected' : '' ?>"
           href="<?= e(build_query_url(['page' => 'drepturi_acces', 'user' => $uid])) ?>"
           data-role="<?= e($rm['key']) ?>"
           data-search="<?= e(mb_strtolower((string) $u['nume'] . ' ' . (string) ($u['email'] ?? '') . ' ' . $rm['label'])) ?>"
           <?= $uid === $selId ? 'aria-current="page"' : '' ?>>
          <?= $avatar($u, 'dax-ava') ?>
          <span class="dax-user-text">
            <span class="dax-user-name"><?= e((string) $u['nume']) ?></span>
            <span class="dax-user-role"><?= e(mb_strtolower($rm['label'])) ?><?= $configured && $rm['key'] !== 'admin' ? ' · personalizat' : '' ?></span>
          </span>
          <span class="dax-dot <?= $active ? 'is-on' : '' ?>" title="<?= $active ? 'Cont activ' : 'Cont inactiv' ?>"></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <!-- ============================ ZONA ACL ============================ -->
  <main class="dax-main">
    <header class="dax-page-head">
      <div>
        <h1>Drepturi de acces</h1>
        <p>Configurează paginile și acțiunile disponibile utilizatorilor.</p>
      </div>
      <nav class="dax-crumbs" aria-label="Breadcrumb">
        <span>Administrare</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
        <a href="<?= e(build_query_url(['page' => 'utilizatori'])) ?>">Utilizatori</a><i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span class="is-current">Drepturi de acces</span>
      </nav>
    </header>

    <?php if ($selectedUser === null): ?>
      <div class="dax-card dax-empty-state">Selectează un utilizator din stânga pentru a-i configura drepturile.</div>
    <?php else: ?>

    <!-- Profil utilizator -->
    <section class="dax-card dax-profile">
      <?= $avatar($selectedUser, 'dax-ava dax-ava-xl') ?>
      <div class="dax-profile-id">
        <div class="dax-profile-name">
          <?= e((string) $selectedUser['nume']) ?>
          <span class="dax-role-badge is-<?= e($selRole['key']) ?>"><?= e($selRole['label']) ?></span>
        </div>
        <div class="dax-profile-meta">
          <span><?= e((string) ($selectedUser['email'] ?? '')) ?></span>
          <?php $active = (string) ($selectedUser['status'] ?? '') === 'activ'; ?>
          <span class="dax-status <?= $active ? 'is-on' : '' ?>"><i class="bi <?= $active ? 'bi-check-circle-fill' : 'bi-dash-circle' ?>" aria-hidden="true"></i> <?= $active ? 'Activ' : 'Inactiv' ?></span>
          <span class="dax-source-chip" id="daxSourceChip"><?= $isAdminUser ? 'Acces total' : ($isConfigured ? 'Drepturi personalizate' : 'Moștenește rolul') ?></span>
        </div>
      </div>
      <div class="dax-profile-role">
        <span class="dax-label">Rol de bază</span>
        <a class="dax-select" href="<?= e(build_query_url(['page' => 'utilizatori', 'action' => 'edit', 'id' => $selId])) ?>" title="Rolul se schimbă din fișa utilizatorului">
          <i class="bi <?= e($selRole['icon']) ?>" aria-hidden="true"></i>
          <span><?= e($selRole['label']) ?></span>
          <i class="bi bi-pencil dax-select-caret" aria-hidden="true"></i>
        </a>
      </div>
      <div class="dax-profile-actions">
        <div class="dax-dropdown">
          <button type="button" class="dax-btn dax-btn-soft" id="daxTplToggle" aria-haspopup="menu" aria-expanded="false" <?= $isAdminUser ? 'disabled' : '' ?>>
            <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Aplică șablon
          </button>
          <div class="dax-menu" id="daxTplMenu" role="menu" hidden>
            <div class="dax-menu-title">Șabloane de rol</div>
            <?php if ($templatesForJs === []): ?><div class="dax-menu-empty">Niciun șablon salvat.</div><?php endif; ?>
            <?php foreach ($templatesForJs as $tpl): ?>
              <div class="dax-menu-row">
                <button type="button" class="dax-menu-item" role="menuitem" data-apply-template="<?= (int) $tpl['id'] ?>">
                  <i class="bi bi-person-check" aria-hidden="true"></i> <?= e($tpl['name']) ?>
                  <?php if ($tpl['system']): ?><span class="dax-tag">sistem</span><?php endif; ?>
                </button>
                <?php if (!$tpl['system']): ?>
                  <button type="button" class="dax-menu-del" data-delete-template="<?= (int) $tpl['id'] ?>" data-name="<?= e($tpl['name']) ?>" title="Șterge șablonul" aria-label="Șterge șablonul <?= e($tpl['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" class="dax-btn dax-btn-outline" data-open-save-template <?= $isAdminUser ? 'disabled' : '' ?>><i class="bi bi-bookmark" aria-hidden="true"></i> Salvează ca șablon</button>
        <button type="button" class="dax-btn dax-btn-danger" data-reset-role <?= $isAdminUser ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează la rol</button>
      </div>
    </section>

    <!-- KPI -->
    <section class="dax-kpis" aria-label="Rezumat drepturi">
      <div class="dax-card dax-kpi"><span class="dax-kpi-ico is-blue"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><div><strong data-kpi="pages">0</strong><span>pagini accesibile</span></div></div>
      <div class="dax-card dax-kpi"><span class="dax-kpi-ico is-green"><i class="bi bi-check-lg" aria-hidden="true"></i></span><div><strong data-kpi="actions">0</strong><span>acțiuni permise</span></div></div>
      <div class="dax-card dax-kpi"><span class="dax-kpi-ico is-red"><i class="bi bi-slash-circle" aria-hidden="true"></i></span><div><strong data-kpi="blocked">0</strong><span>pagini blocate</span></div></div>
      <div class="dax-card dax-kpi"><span class="dax-kpi-ico is-orange"><i class="bi bi-lock" aria-hidden="true"></i></span><div><strong data-kpi="admin">0</strong><span>restricții admin</span></div></div>
    </section>

    <?php if ($isAdminUser): ?>
      <div class="dax-notice"><i class="bi bi-shield-fill-check" aria-hidden="true"></i> <strong><?= e((string) $selectedUser['nume']) ?></strong> este administrator și are acces la tot. Drepturile de mai jos se configurează doar pentru utilizatorii non-admin.</div>
    <?php endif; ?>

    <!-- Cautare + filtre -->
    <section class="dax-card dax-toolbar">
      <label class="dax-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="daxSearch" placeholder="Caută pagină sau permisiune..." autocomplete="off" aria-label="Caută pagină sau permisiune">
      </label>
      <div class="dax-pills" role="group" aria-label="Filtrează paginile">
        <button type="button" class="dax-pill is-all is-active" data-filter="all">Toate <span data-count="all">0</span></button>
        <button type="button" class="dax-pill is-allowed" data-filter="allowed"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Permise <span data-count="allowed">0</span></button>
        <button type="button" class="dax-pill is-blocked" data-filter="blocked"><i class="bi bi-slash-circle" aria-hidden="true"></i> Blocate <span data-count="blocked">0</span></button>
        <button type="button" class="dax-pill is-custom" data-filter="custom"><i class="bi bi-sliders" aria-hidden="true"></i> Personalizate <span data-count="custom">0</span></button>
        <button type="button" class="dax-pill is-admin" data-filter="admin"><i class="bi bi-lock-fill" aria-hidden="true"></i> Admin <span data-count="admin">0</span></button>
      </div>
    </section>

    <!-- Sectiuni / module (din registru) -->
    <form id="daxForm" class="dax-sections" onsubmit="return false">
      <?php foreach ($sections as $sectionKey => $section):
        $sectionModules = $modulesBySection[(string) $sectionKey] ?? [];
        if ($sectionModules === []) { continue; } ?>
        <section class="dax-card dax-section" data-section="<?= e((string) $sectionKey) ?>">
          <button type="button" class="dax-section-head" aria-expanded="true">
            <i class="bi <?= e((string) $section['icon']) ?> dax-section-ico" aria-hidden="true"></i>
            <span class="dax-section-title"><?= e((string) $section['label']) ?></span>
            <span class="dax-section-count" data-section-count></span>
            <span class="dax-progress" aria-hidden="true"><span data-section-bar></span></span>
            <span class="dax-section-pct" data-section-pct></span>
            <i class="bi bi-chevron-up dax-chevron" aria-hidden="true"></i>
          </button>
          <div class="dax-section-body">
            <?php foreach ($sectionModules as $moduleKey => $module):
              $moduleKey = (string) $moduleKey;
              $viewMeta = $module['actions']['view'];
              $pageLocked = (bool) $viewMeta['admin_only'];
              $extra = array_filter($module['actions'], static fn(array $a): bool => $a['key'] !== 'view');
              $hasAdminOnly = $pageLocked || array_filter($extra, static fn(array $a): bool => $a['admin_only']) !== [];
              $searchText = mb_strtolower($module['label'] . ' ' . $module['description'] . ' ' . $moduleKey);
              ?>
              <div class="dax-module" data-module="<?= e($moduleKey) ?>" data-has-admin="<?= $hasAdminOnly ? '1' : '0' ?>" data-search="<?= e($searchText) ?>">
                <div class="dax-module-row" <?= $extra !== [] ? 'data-toggle-module tabindex="0" role="button" aria-expanded="false"' : '' ?>>
                  <i class="bi <?= e((string) $module['icon']) ?> dax-module-ico" aria-hidden="true"></i>
                  <div class="dax-module-text">
                    <span class="dax-module-name"><?= e((string) $module['label']) ?>
                      <span class="dax-chip-custom" data-module-custom hidden>Personalizat</span>
                    </span>
                    <?php if ($module['description'] !== ''): ?><span class="dax-module-desc"><?= e((string) $module['description']) ?></span><?php endif; ?>
                  </div>
                  <?php if ($extra !== []): ?><span class="dax-module-tally" data-module-tally title="Acțiuni permise din total"></span><?php endif; ?>
                  <?php if ($pageLocked): ?>
                    <span class="dax-lock" title="Pagina este rezervată rolului admin (verificare în controller)."><i class="bi bi-lock-fill" aria-hidden="true"></i> Doar administrator</span>
                    <input type="checkbox" class="d-none" data-view data-locked <?= $isAdminUser ? 'checked' : '' ?> disabled>
                  <?php else: ?>
                    <label class="dax-switch" title="<?= e((string) $viewMeta['label']) ?>">
                      <input type="checkbox" data-view data-perm="<?= e($moduleKey) ?>.view" <?= $isChecked($moduleKey, 'view') ? 'checked' : '' ?> <?= $isAdminUser ? 'disabled' : '' ?>>
                      <span class="dax-switch-track" aria-hidden="true"></span>
                      <span class="dax-switch-label">Acces pagină</span>
                    </label>
                  <?php endif; ?>
                  <?php if ($extra !== []): ?>
                    <i class="bi bi-chevron-right dax-chevron" aria-hidden="true"></i>
                  <?php else: ?>
                    <span class="dax-chevron-spacer" aria-hidden="true"></span>
                  <?php endif; ?>
                </div>

                <?php if ($extra !== []): ?>
                  <div class="dax-module-body" hidden>
                    <?php foreach ($module['groups'] as $groupKey => $groupLabel):
                      $groupActions = array_filter($extra, static fn(array $a): bool => $a['group'] === $groupKey);
                      if ($groupActions === []) { continue; } ?>
                      <div class="dax-group">
                        <div class="dax-group-title"><?= e((string) $groupLabel) ?></div>
                        <?php foreach ($groupActions as $actionKey => $action):
                          $permKey = $moduleKey . '.' . $actionKey;
                          $locked = (bool) $action['admin_only']; ?>
                          <div class="dax-action <?= $locked ? 'is-locked' : '' ?>" data-action-row data-search="<?= e(mb_strtolower($action['label'] . ' ' . $permKey)) ?>">
                            <label>
                              <input type="checkbox" class="dax-check" data-action
                                     <?= $locked ? 'data-locked' : 'data-perm="' . e($permKey) . '"' ?>
                                     <?= ($locked ? $isAdminUser : $isChecked($moduleKey, (string) $actionKey)) ? 'checked' : '' ?>
                                     <?= ($locked || $isAdminUser) ? 'disabled' : '' ?>>
                              <span class="dax-action-label"><?= e((string) $action['label']) ?></span>
                            </label>
                            <span class="dax-action-tags">
                              <?php if ($locked): ?><span class="dax-lock" title="Verificată în controller doar pentru rolul admin — nu poate fi acordată."><i class="bi bi-lock-fill" aria-hidden="true"></i> Doar administrator</span><?php endif; ?>
                              <?php if (!$locked && $action['default_admin']): ?><span class="dax-tag" title="Un utilizator nepersonalizat nu o are; se poate acorda explicit.">implicit admin</span><?php endif; ?>
                              <?php if ($action['default_accountancy']): ?><span class="dax-tag" title="Implicit doar pentru admin / contabilitate; se poate acorda explicit.">implicit contabilitate</span><?php endif; ?>
                              <?php if ($action['sensitive']): ?><span class="dax-tag is-sensitive">sensibil</span><?php endif; ?>
                              <span class="dax-chip-custom" data-action-custom hidden></span>
                            </span>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <div class="dax-card dax-empty-state" id="daxNoResults" hidden>Nicio pagină sau permisiune nu corespunde căutării.</div>
    </form>

    <?php if ($deprecated !== []): ?>
      <details class="dax-card dax-deprecated">
        <summary><i class="bi bi-archive" aria-hidden="true"></i> <?= count($deprecated) ?> permisiuni retrase din registru — păstrate în istoric, fără efect</summary>
        <ul>
          <?php foreach ($deprecated as $d): ?>
            <li><code><?= e((string) $d['permission_key']) ?></code> <?= e((string) $d['module_label']) ?> — <?= e((string) $d['label']) ?>
              <span class="dax-muted">(retrasă <?= e((string) $d['deprecated_at']) ?>, <?= (int) $d['users'] ?> utilizatori)</span></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <!-- Bara de modificari nesalvate -->
    <div class="dax-savebar" id="daxSavebar" hidden>
      <span class="dax-savebar-ico" aria-hidden="true"><i class="bi bi-exclamation-lg"></i></span>
      <div class="dax-savebar-text">
        <strong id="daxDirtyText">Ai modificări nesalvate</strong>
        <span>Modificările tale nu sunt încă aplicate utilizatorului.</span>
      </div>
      <div class="dax-savebar-actions">
        <button type="button" class="dax-btn dax-btn-outline" data-discard>Renunță</button>
        <button type="button" class="dax-btn dax-btn-danger" data-reset-role><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează la rol</button>
        <button type="button" class="dax-btn dax-btn-primary" data-save><i class="bi bi-check-lg" aria-hidden="true"></i> Salvează modificările</button>
      </div>
    </div>
    <?php endif; ?>
  </main>
</div>

<!-- Dialog confirmare -->
<dialog class="dax-dialog" id="daxConfirm">
  <form method="dialog">
    <h3 data-confirm-title>Confirmare</h3>
    <p data-confirm-text></p>
    <div class="dax-dialog-actions">
      <button value="cancel" class="dax-btn dax-btn-outline">Anulează</button>
      <button value="ok" class="dax-btn dax-btn-primary" data-confirm-ok>Confirmă</button>
    </div>
  </form>
</dialog>

<!-- Dialog salvare sablon -->
<dialog class="dax-dialog" id="daxTemplateDialog">
  <form method="dialog" id="daxTemplateForm">
    <h3>Salvează ca șablon</h3>
    <p class="dax-muted">Șablonul preia drepturile bifate acum (inclusiv modificările nesalvate).</p>
    <label class="dax-field">
      <span>Nume șablon</span>
      <input type="text" name="template_name" maxlength="80" required placeholder="ex. Dispecer junior">
    </label>
    <label class="dax-field">
      <span>Salvează ca</span>
      <select name="template_id">
        <option value="0">Șablon nou</option>
        <?php foreach ($templatesForJs as $tpl): ?>
          <option value="<?= (int) $tpl['id'] ?>" data-name="<?= e($tpl['name']) ?>">Suprascrie „<?= e($tpl['name']) ?>”</option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="dax-dialog-actions">
      <button value="cancel" class="dax-btn dax-btn-outline" formnovalidate>Anulează</button>
      <button value="ok" class="dax-btn dax-btn-primary">Salvează șablonul</button>
    </div>
  </form>
</dialog>

<div class="dax-toast" id="daxToast" role="status" aria-live="polite" hidden></div>

<script type="application/json" id="daxData"><?= json_encode([
    'roleDefaults' => (object) $roleDefaults,
    'templates' => $templatesForJs,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(url('assets/js/drepturi-acces.js?v=' . $assetVersion('assets/js/drepturi-acces.js'))) ?>"></script>
