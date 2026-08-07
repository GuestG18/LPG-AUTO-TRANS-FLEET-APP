<?php
/** @var array $filters @var array $rows @var int $rowsTotal @var int $timelineLimit @var array $kpis @var array $topUsers @var array $distribution @var array $userOptions @var array $moduleOptions @var string $subtitle */
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$kpis = is_array($kpis ?? null) ? $kpis : [];
$topUsers = is_array($topUsers ?? null) ? $topUsers : [];
$distribution = is_array($distribution ?? null) ? $distribution : ['total' => 0, 'items' => []];
$userOptions = is_array($userOptions ?? null) ? $userOptions : [];
$moduleOptions = is_array($moduleOptions ?? null) ? $moduleOptions : [];
$subtitle = (string) ($subtitle ?? '');

$actionMeta = [
    'create'  => ['label' => 'Creare',        'icon' => 'bi-plus-lg',                'cls' => 'create'],
    'update'  => ['label' => 'Modificare',    'icon' => 'bi-pencil',                 'cls' => 'update'],
    'delete'  => ['label' => 'Ștergere',      'icon' => 'bi-trash3',                 'cls' => 'delete'],
    'restore' => ['label' => 'Restaurare',    'icon' => 'bi-arrow-counterclockwise', 'cls' => 'restore'],
    'status'  => ['label' => 'Status',         'icon' => 'bi-toggle-on',              'cls' => 'status'],
    'login'   => ['label' => 'Autentificare', 'icon' => 'bi-box-arrow-in-right',     'cls' => 'login'],
];
$distLabels = [
    'update' => 'Modificări', 'create' => 'Creări', 'login' => 'Autentificări',
    'delete' => 'Ștergeri', 'restore' => 'Restaurări', 'status' => 'Status',
];
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = mb_substr($parts[1] ?? '', 0, 1);
    return mb_strtoupper($a . $b) !== '' ? mb_strtoupper($a . $b) : 'U';
};
$avatarClass = static function (int $id): string {
    return ['a', 'b', 'c', 'd'][$id % 4];
};
$roleName = static fn (string $r): string => function_exists('role_display_name') ? role_display_name($r) : $r;
$monthsRo = [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
$baseParams = ['page' => 'activitate_utilizatori'];
$exportParams = array_merge($baseParams, [
    'action'     => 'export',
    'date_start' => $filters['date_start'] ?? '',
    'date_end'   => $filters['date_end'] ?? '',
    'user'       => $filters['user_id'] ?? 0,
    'module'     => $filters['module'] ?? '',
    'action_type'=> $filters['action'] ?? '',
    'q'          => $filters['search'] ?? '',
]);
?>
<style>
.uact{--pri:#0d6efd}
.uact .card{background:#fff;border:1px solid #dde5ef;border-radius:8px;box-shadow:0 8px 20px rgba(15,23,42,.035)}
.uact .title{color:#0f172a;font-size:1.45rem;font-weight:800;line-height:1.2;margin:0 0 .25rem;display:flex;align-items:center;gap:.55rem}
.uact .title i{color:var(--pri)}
.uact .sub{color:#64748b;font-size:.95rem;margin:0 0 1.1rem;max-width:840px}
.uact .filters{display:flex;flex-direction:row;flex-wrap:wrap;gap:12px;align-items:flex-end;padding:16px;border-radius:14px;margin-bottom:1rem}
.uact .filters label{font-size:.72rem;color:#64748b;font-weight:700;margin-bottom:2px}
.uact .filters .form-select,.uact .filters .form-control{height:2.45rem;border-radius:6px;font-size:.9rem}
.uact .fld{display:flex;flex-direction:column;min-width:150px}
.uact .fld.search{min-width:220px;flex:1 1 220px}
.uact .kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin-bottom:1rem}
.uact .kpi{min-height:92px;display:flex;flex-direction:row;align-items:center;gap:1rem;padding:1rem}
.uact .kpi-ic{width:44px;height:44px;flex:0 0 44px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.uact .kpi-ic.blue{color:#2563eb;background:#dbeafe}
.uact .kpi-ic.green{color:#16a34a;background:#dcfce7}
.uact .kpi-ic.amber{color:#d97706;background:#fef3c7}
.uact .kpi-ic.purple{color:#7c3aed;background:#ede9fe}
.uact .kpi-tx{min-width:0}
.uact .kpi-tx .lbl{color:#334155;font-size:.78rem;font-weight:700}
.uact .kpi-tx .val{color:#0f172a;font-size:1.4rem;font-weight:800;line-height:1.12;font-variant-numeric:tabular-nums}
.uact .kpi-tx .val small{font-size:.8rem;font-weight:600;color:#64748b}
.uact .kpi-tx .meta{color:#64748b;font-size:.74rem}
.uact .kpi-tx .up{color:#16a34a;font-weight:700}
.uact .kpi-tx .down{color:#dc2626;font-weight:700}
.uact .grid{display:grid;grid-template-columns:1fr 340px;gap:1rem;align-items:start}
.uact .chead{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eef2f7}
.uact .chead h2{font-size:.98rem;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:.5rem}
.uact .chead h2 i{color:#64748b}
.uact .tl{max-height:min(620px,70vh);overflow-y:auto;overscroll-behavior:contain}
.uact .tl::-webkit-scrollbar{width:9px}
.uact .tl::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px;border:2px solid #fff}
.uact .tl::-webkit-scrollbar-thumb:hover{background:#94a3b8}
.uact .daysep{position:sticky;top:0;z-index:2;padding:8px 16px;background:#eef2f7;border-bottom:1px solid #e2e8f0;font-size:.74rem;font-weight:700;color:#475569;text-transform:capitalize}
.uact .row{display:grid;grid-template-columns:54px 22px 1fr;padding:13px 16px;border-bottom:1px solid #eef2f7}
.uact .row:last-child{border-bottom:0}
.uact .row .time{font-size:.78rem;color:#64748b;font-variant-numeric:tabular-nums;padding-top:2px}
.uact .rail{position:relative}
.uact .rail .dot{width:11px;height:11px;border-radius:50%;border:2px solid #fff;position:absolute;left:4px;top:4px;box-shadow:0 0 0 1px #dde5ef}
.uact .rail:before{content:"";position:absolute;left:9px;top:0;bottom:-13px;width:1px;background:#e8edf3}
.uact .row:last-child .rail:before{display:none}
.uact .rtop{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.uact .uava{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;background:linear-gradient(135deg,#1d4ed8,#2563eb)}
.uact .uava.b{background:linear-gradient(135deg,#6d28d9,#7c3aed)}
.uact .uava.c{background:linear-gradient(135deg,#15803d,#16a34a)}
.uact .uava.d{background:linear-gradient(135deg,#c2410c,#ea580c)}
.uact .uname{font-weight:700;font-size:.86rem;color:#0f172a}
.uact .abadge{font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:6px;display:inline-flex;align-items:center;gap:4px}
.uact .a-create{background:#dcfce7;color:#16a34a}
.uact .a-update{background:#dbeafe;color:#2563eb}
.uact .a-delete{background:#fee2e2;color:#dc2626}
.uact .a-login{background:#ede9fe;color:#7c3aed}
.uact .a-restore{background:#cffafe;color:#0891b2}
.uact .a-status{background:#fef3c7;color:#d97706}
.uact .dot-create{background:#16a34a}.uact .dot-update{background:#2563eb}.uact .dot-delete{background:#dc2626}
.uact .dot-login{background:#7c3aed}.uact .dot-restore{background:#0891b2}.uact .dot-status{background:#d97706}
.uact .mod{font-size:.72rem;color:#64748b;border:1px solid #dde5ef;border-radius:6px;padding:2px 8px;font-weight:600}
.uact .desc{font-size:.86rem;color:#334155;margin-top:5px}
.uact .expand{font-size:.78rem;color:var(--pri);cursor:pointer;margin-top:7px;display:inline-flex;align-items:center;gap:5px;user-select:none;font-weight:600}
.uact .expand i{transition:transform .15s}
.uact .diff{margin-top:9px;border:1px solid #dde5ef;border-radius:8px;overflow:hidden;font-size:.78rem}
.uact .diff .dh{padding:7px 10px;background:#eef2f7;color:#64748b;font-weight:700}
.uact .diff table{width:100%;border-collapse:collapse;margin:0}
.uact .diff td{padding:6px 10px;border-top:1px solid #eef2f7;vertical-align:top}
.uact .diff td.k{color:#64748b;width:170px;font-weight:600}
.uact .diff .old{color:#dc2626;text-decoration:line-through;opacity:.85}
.uact .diff .new{color:#16a34a;font-weight:700}
.uact .diff .arw{color:#94a3b8;margin:0 6px}
.uact .tu{display:flex;align-items:center;gap:11px;padding:12px 16px;border-bottom:1px solid #eef2f7}
.uact .tu:last-child{border-bottom:0}
.uact .tu .uava{width:32px;height:32px;font-size:11px}
.uact .tu .nm{font-weight:700;font-size:.86rem;color:#0f172a}
.uact .tu .rl{font-size:.72rem;color:#64748b}
.uact .tu .spark{margin-left:auto;display:flex;align-items:flex-end;gap:2px;height:26px}
.uact .tu .spark i{width:4px;background:var(--pri);border-radius:1px;opacity:.85;display:block;min-height:2px}
.uact .tu .cnt{font-weight:800;font-variant-numeric:tabular-nums;font-size:.95rem;min-width:32px;text-align:right;color:#0f172a}
.uact .legend{display:flex;flex-wrap:wrap;gap:8px 14px;padding:14px 16px 6px;font-size:.78rem;color:#64748b}
.uact .legend span{display:inline-flex;align-items:center;gap:6px;font-weight:600}
.uact .legend em{width:9px;height:9px;border-radius:3px;display:inline-block;font-style:normal}
.uact .distbar{height:9px;display:flex;margin:0 16px 16px;border-radius:6px;overflow:hidden;background:#eef2f7}
.uact .empty{padding:38px 16px;text-align:center;color:#64748b}
.uact .empty i{font-size:2rem;color:#cbd5e1;display:block;margin-bottom:.5rem}
.uact .note{margin-top:1.1rem;font-size:.78rem;color:#64748b;display:flex;gap:8px;align-items:flex-start;line-height:1.55}
.uact .note i{color:var(--pri);margin-top:2px}
@media(max-width:1050px){.uact .grid{grid-template-columns:1fr}.uact .kpis{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="uact">
  <h1 class="title"><i class="bi bi-person-lines-fill"></i> Activitate utilizatori</h1>
  <p class="sub"><?= e($subtitle) ?> Vizibil doar administratorilor.</p>

  <form method="get" class="card filters">
    <input type="hidden" name="page" value="activitate_utilizatori">
    <div class="fld">
      <label>Utilizator</label>
      <select name="user" class="form-select form-select-sm">
        <option value="0">Toți utilizatorii</option>
        <?php foreach ($userOptions as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= (int) ($filters['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e((string) $u['nume']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fld">
      <label>Modul</label>
      <select name="module" class="form-select form-select-sm">
        <option value="">Toate modulele</option>
        <?php foreach ($moduleOptions as $m): ?>
          <option value="<?= e((string) $m['key']) ?>" <?= (string) ($filters['module'] ?? '') === (string) $m['key'] ? 'selected' : '' ?>><?= e((string) $m['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fld">
      <label>Tip acțiune</label>
      <select name="action_type" class="form-select form-select-sm">
        <option value="">Toate</option>
        <?php foreach ($actionMeta as $key => $meta): ?>
          <option value="<?= e($key) ?>" <?= (string) ($filters['action'] ?? '') === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fld" style="min-width:140px">
      <label>De la</label>
      <input type="date" name="date_start" class="form-control form-control-sm" value="<?= e((string) ($filters['date_start'] ?? '')) ?>">
    </div>
    <div class="fld" style="min-width:140px">
      <label>Până la</label>
      <input type="date" name="date_end" class="form-control form-control-sm" value="<?= e((string) ($filters['date_end'] ?? '')) ?>">
    </div>
    <div class="fld search">
      <label>Căutare</label>
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Nume, descriere, nr. înregistrare…" value="<?= e((string) ($filters['search'] ?? '')) ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filtrează</button>
    <a href="<?= e(build_query_url($exportParams)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i> Export CSV</a>
  </form>

  <div class="kpis">
    <div class="card kpi">
      <div class="kpi-ic blue"><i class="bi bi-calendar-check"></i></div>
      <div class="kpi-tx">
        <div class="lbl">Acțiuni azi</div>
        <div class="val"><?= (int) ($kpis['today'] ?? 0) ?></div>
        <div class="meta">
          <?php $d = $kpis['delta_pct'] ?? null; if ($d !== null): ?>
            <span class="<?= $d >= 0 ? 'up' : 'down' ?>"><?= ($d >= 0 ? '+' : '') . (int) $d ?>%</span> față de ieri (<?= (int) ($kpis['yesterday'] ?? 0) ?>)
          <?php else: ?>
            ieri: <?= (int) ($kpis['yesterday'] ?? 0) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="card kpi">
      <div class="kpi-ic green"><i class="bi bi-people"></i></div>
      <div class="kpi-tx">
        <div class="lbl">Utilizatori activi</div>
        <div class="val"><?= (int) ($kpis['active_users'] ?? 0) ?> <small>/ <?= (int) ($kpis['total_users'] ?? 0) ?></small></div>
        <div class="meta">în ultimele 7 zile</div>
      </div>
    </div>
    <div class="card kpi">
      <div class="kpi-ic amber"><i class="bi bi-star-fill"></i></div>
      <div class="kpi-tx">
        <div class="lbl">Cea mai activă persoană</div>
        <div class="val" style="font-size:1.05rem"><?= e((string) ($kpis['top_user_name'] ?? '—')) ?></div>
        <div class="meta"><?= (int) ($kpis['top_user_count'] ?? 0) ?> acțiuni în perioadă</div>
      </div>
    </div>
    <div class="card kpi">
      <div class="kpi-ic purple"><i class="bi bi-box-arrow-in-right"></i></div>
      <div class="kpi-tx">
        <div class="lbl">Autentificări (7 zile)</div>
        <div class="val"><?= (int) ($kpis['logins_7d'] ?? 0) ?></div>
        <div class="meta"><?= (int) ($kpis['failed_logins_7d'] ?? 0) ?> încercări eșuate</div>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <div class="chead">
        <h2><i class="bi bi-clock-history"></i> Cronologie</h2>
        <span class="text-muted" style="font-size:.78rem">
          <?= (int) $rowsTotal ?> acțiuni<?= (int) $rowsTotal > (int) $timelineLimit ? ' · afișate primele ' . (int) $timelineLimit : '' ?>
        </span>
      </div>

      <div class="tl">
      <?php if ($rows === []): ?>
        <div class="empty"><i class="bi bi-inbox"></i>Nicio activitate în perioada și filtrele selectate.</div>
      <?php else:
        $currentDay = null;
        foreach ($rows as $row):
          $key = (string) $row['action_key'];
          $meta = $actionMeta[$key] ?? ['label' => $key, 'icon' => 'bi-dot', 'cls' => 'update'];
          $ts = (string) $row['ts'];
          $day = substr($ts, 0, 10);
          if ($day !== $currentDay):
            $currentDay = $day;
            $dt = null;
            try { $dt = new DateTime($day); } catch (Throwable) {}
            ?>
            <div class="daysep"><?= $dt ? ((int) $dt->format('j') . ' ' . ($monthsRo[(int) $dt->format('n')] ?? '') . ' ' . $dt->format('Y')) : e($day) ?></div>
          <?php endif;
          $uid = (int) $row['user_id'];
          $before = is_array($row['before'] ?? null) ? $row['before'] : null;
          $after = is_array($row['after'] ?? null) ? $row['after'] : null;
          $changes = [];
          if ($before !== null && $after !== null) {
              foreach (array_keys($before + $after) as $fk) {
                  $ov = $before[$fk] ?? null;
                  $nv = $after[$fk] ?? null;
                  if (is_scalar($ov) || $ov === null) { $ov = (string) ($ov ?? '—'); } else { $ov = json_encode($ov, JSON_UNESCAPED_UNICODE); }
                  if (is_scalar($nv) || $nv === null) { $nv = (string) ($nv ?? '—'); } else { $nv = json_encode($nv, JSON_UNESCAPED_UNICODE); }
                  if ($ov !== $nv) { $changes[(string) $fk] = [$ov, $nv]; }
              }
          }
          $timePart = strlen($ts) >= 16 ? substr($ts, 11, 5) : '';
          $rowId = 'ud' . (int) ($row['record_id'] ?? 0) . '_' . substr(md5($ts . $key . $uid), 0, 6);
          ?>
          <div class="row">
            <div class="time"><?= e($timePart) ?></div>
            <div class="rail"><span class="dot dot-<?= e($meta['cls']) ?>"></span></div>
            <div>
              <div class="rtop">
                <span class="uava <?= e($avatarClass($uid)) ?>"><?= e($initials((string) $row['user_name'])) ?></span>
                <span class="uname"><?= e((string) $row['user_name']) ?></span>
                <span class="abadge a-<?= e($meta['cls']) ?>"><i class="bi <?= e($meta['icon']) ?>"></i><?= e($meta['label']) ?></span>
                <span class="mod"><?= e((string) $row['module_label']) ?></span>
              </div>
              <div class="desc"><?= e((string) $row['description']) ?></div>
              <?php if ($changes !== []): ?>
                <span class="expand" data-target="<?= e($rowId) ?>"><i class="bi bi-chevron-down"></i> Vezi ce s-a schimbat (<?= count($changes) ?> <?= count($changes) === 1 ? 'câmp' : 'câmpuri' ?>)</span>
                <div class="diff" id="<?= e($rowId) ?>" style="display:none">
                  <div class="dh">Modificări înregistrate</div>
                  <table>
                    <?php foreach ($changes as $fk => $pair): ?>
                      <tr>
                        <td class="k"><?= e(ucfirst(str_replace('_', ' ', $fk))) ?></td>
                        <td><span class="old"><?= e($pair[0]) ?></span><span class="arw">→</span><span class="new"><?= e($pair[1]) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:1rem">
      <div class="card">
        <div class="chead"><h2><i class="bi bi-trophy"></i> Cei mai activi</h2></div>
        <?php if ($topUsers === []): ?>
          <div class="empty" style="padding:22px 16px">Nicio activitate.</div>
        <?php else:
          $maxSpark = 1;
          foreach ($topUsers as $tu) { $maxSpark = max($maxSpark, max($tu['spark'] ?: [0])); }
          foreach ($topUsers as $tu):
            $uid = (int) $tu['user_id']; ?>
          <div class="tu">
            <span class="uava <?= e($avatarClass($uid)) ?>"><?= e($initials((string) $tu['name'])) ?></span>
            <span>
              <span class="nm"><?= e((string) $tu['name']) ?></span><br>
              <span class="rl"><?= e($roleName((string) $tu['role'])) ?></span>
            </span>
            <span class="spark">
              <?php foreach ($tu['spark'] as $v): $h = (int) round(((int) $v / $maxSpark) * 100); ?>
                <i style="height:<?= max(6, $h) ?>%" title="<?= (int) $v ?>"></i>
              <?php endforeach; ?>
            </span>
            <span class="cnt"><?= (int) $tu['count'] ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if (($distribution['total'] ?? 0) > 0): ?>
      <div class="card">
        <div class="chead"><h2><i class="bi bi-pie-chart"></i> Distribuție acțiuni</h2></div>
        <div class="legend">
          <?php foreach ($distribution['items'] as $it): $m = $actionMeta[$it['action']] ?? null; ?>
            <span><em class="dot-<?= e($m['cls'] ?? 'update') ?>"></em><?= e($distLabels[$it['action']] ?? $it['action']) ?> · <?= (int) $it['pct'] ?>%</span>
          <?php endforeach; ?>
        </div>
        <div class="distbar">
          <?php foreach ($distribution['items'] as $it): $m = $actionMeta[$it['action']] ?? null; ?>
            <div class="dot-<?= e($m['cls'] ?? 'update') ?>" style="width:<?= (int) $it['pct'] ?>%"></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="note">
    <i class="bi bi-info-circle-fill"></i>
    <span>Sursele jurnalului: modulele Documente și Programare concedii (audit_log), Dispecer curse (cursa_audit_log) și autentificările reușite. Modulele care nu scriu încă în audit vor apărea aici pe măsură ce li se adaugă logarea.</span>
  </div>
</div>

<script>
(function () {
  document.querySelectorAll('.uact .expand').forEach(function (el) {
    el.addEventListener('click', function () {
      var d = document.getElementById(el.getAttribute('data-target'));
      if (!d) return;
      var open = d.style.display !== 'none';
      d.style.display = open ? 'none' : 'block';
      var ic = el.querySelector('i');
      if (ic) ic.style.transform = open ? '' : 'rotate(180deg)';
    });
  });
})();
</script>
