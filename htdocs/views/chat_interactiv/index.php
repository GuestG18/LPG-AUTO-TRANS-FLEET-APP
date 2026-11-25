<?php
/**
 * Chat Interactiv — LPG Connect: sediul virtual 3D (multiplayer, panou informativ, chat).
 * Logica: assets/js/chat-interactiv/*.js (module ES, three.js r160 local). Stiluri: assets/css/chat-interactiv.css.
 * Serverul multiplayer: chat_interactiv_server/ (Node + ws). Vezi chat_interactiv_server/README.md.
 *
 * @var array $config
 */
$threeVendorBase = '/' . ltrim(rtrim(url('assets/vendor/three'), '/'), '/');
// Modulele paginii se importă prin importmap („ci/world.js”), fiecare cu versiunea lui (filemtime):
// după o actualizare, browserul nu rămâne cu module vechi din cache.
$jsBase = '/' . ltrim(rtrim(url('assets/js/chat-interactiv'), '/'), '/');
$importMap = [
    'three' => $threeVendorBase . '/three.module.js',
    'three/addons/' => $threeVendorBase . '/',
];
foreach (glob(BASE_PATH . '/assets/js/chat-interactiv/*.js') ?: [] as $moduleFile) {
    $importMap['ci/' . basename($moduleFile)] = $jsBase . '/' . basename($moduleFile) . '?v=' . (string) filemtime($moduleFile);
}
?>
<script type="importmap"><?= json_encode(['imports' => $importMap], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<link rel="stylesheet" href="<?= e(url('assets/css/chat-interactiv.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/chat-interactiv.css'))) ?>">
<script type="application/json" id="ci-config"><?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="ci-root" data-ci-root>
    <div class="ci-canvas" data-ci-canvas aria-label="Sediul virtual LPG — folosește WASD pentru mișcare"></div>
    <div class="ci-vignette" aria-hidden="true"></div>

    <div class="ci-hud-top">
        <div class="ci-brand"><i class="bi bi-buildings" aria-hidden="true"></i> LPG Connect <small>Sediul LPG</small></div>
        <div class="ci-net" data-net-status data-state="connecting"><i class="bi bi-circle-fill" aria-hidden="true"></i> <span>Se conectează…</span></div>
        <button type="button" class="ci-icon-btn" data-open-settings title="Setări" aria-label="Setări"><i class="bi bi-gear"></i></button>
    </div>

    <div class="ci-help" data-help hidden>
        <span><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> mers</span>
        <span><kbd>Shift</kbd> alergare</span>
        <span><i class="bi bi-mouse"></i> trage = cameră · rotiță = distanță</span>
        <span><kbd>E</kbd> interacțiune</span>
        <span><kbd>Enter</kbd> chat</span>
        <span><kbd>Esc</kbd> închide</span>
    </div>

    <div class="ci-seated" data-seated hidden><i class="bi bi-person-workspace" aria-hidden="true"></i> <span data-seated-text></span></div>
    <div class="ci-prompt" data-prompt hidden><kbd>E</kbd> — <span data-prompt-text></span></div>
    <div class="ci-toasts" data-toasts aria-live="polite"></div>

    <section class="ci-notes" data-notes hidden aria-label="Panou informativ"></section>
    <section class="ci-chat" data-chat aria-label="Chat general"></section>

    <section class="ci-laptop" data-laptop hidden aria-label="Laptop">
        <div class="ci-laptop-head">
            <b><i class="bi bi-laptop" aria-hidden="true"></i> Laptop — prezentare</b>
            <button type="button" class="ci-icon-btn" data-close title="Închide (Esc)" aria-label="Închide"><i class="bi bi-x-lg"></i></button>
        </div>
        <p class="ci-laptop-status" data-laptop-status></p>
        <video class="ci-laptop-preview" muted playsinline autoplay hidden></video>
        <div class="ci-laptop-actions">
            <button type="button" class="btn btn-primary" data-share-start><i class="bi bi-display" aria-hidden="true"></i> Partajează ecranul</button>
            <button type="button" class="btn btn-danger" data-share-stop hidden><i class="bi bi-stop-circle" aria-hidden="true"></i> Oprește partajarea</button>
        </div>
        <p class="ci-laptop-hint">Ecranul apare pe televizorul din cameră. Doar video, fără sunet.</p>
    </section>

    <section class="ci-screen-view" data-screen-view hidden aria-label="Ecran de prezentare">
        <div class="ci-screen-head">
            <b data-screen-title>Ecran de prezentare</b>
            <span>
                <button type="button" class="btn btn-sm btn-outline-light" data-fullscreen><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i> Ecran complet</button>
                <button type="button" class="ci-icon-btn" data-close title="Închide (Esc)" aria-label="Închide"><i class="bi bi-x-lg"></i></button>
            </span>
        </div>
        <div class="ci-screen-body">
            <video muted playsinline autoplay></video>
            <div class="ci-screen-empty" data-screen-empty>Nimeni nu prezintă acum. Mergi la un laptop și apasă <kbd>E</kbd> ca să-ți partajezi ecranul.</div>
        </div>
    </section>

    <div class="ci-settings" data-settings hidden>
        <div class="ci-settings-head"><b>Setări</b><button type="button" class="ci-icon-btn" data-close-settings aria-label="Închide"><i class="bi bi-x-lg"></i></button></div>
        <fieldset>
            <legend>Grafică</legend>
            <label class="form-check"><input class="form-check-input" type="radio" name="quality" value="high"> Înaltă (umbre, antialiasing)</label>
            <label class="form-check"><input class="form-check-input" type="radio" name="quality" value="low"> Redusă (PC-uri mai vechi)</label>
        </fieldset>
        <button type="button" class="btn btn-sm btn-outline-primary w-100 mb-2" data-change-avatar><i class="bi bi-person-bounding-box"></i> Schimbă avatarul</button>
        <details><summary>Diagnostic încărcare</summary><ul class="ci-diag" data-diag></ul></details>
    </div>

    <div class="ci-avatar-picker" data-avatar-picker hidden>
        <div class="ci-avatar-box">
            <h2>Alege-ți avatarul</h2>
            <p>Avatarul ales se păstrează pentru contul tău și îl văd toți colegii din sediul virtual.</p>
            <div class="ci-avatar-grid" data-avatar-grid></div>
            <button type="button" class="btn btn-sm btn-link" data-avatar-cancel hidden>Renunță</button>
        </div>
    </div>

    <div class="ci-loading" data-loading>
        <div class="ci-loading-box">
            <div class="ci-loading-logo"><i class="bi bi-buildings"></i></div>
            <div data-loading-text>Se încarcă…</div>
            <div class="ci-loading-track"><div class="ci-loading-bar" data-loading-bar></div></div>
        </div>
    </div>
</div>

<script type="module">import 'ci/main.js';</script>
