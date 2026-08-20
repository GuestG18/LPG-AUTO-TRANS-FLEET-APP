<?php
/**
 * Profilul meu — refactor vizual dupa mockup-urile de referinta.
 *
 * NOTE
 *   - Toate datele afisate provin din utilizatorul autentificat; nimic nu este hardcodat.
 *   - Sectiunea Passkeys pastreaza EXACT aceleasi id-uri si acelasi script ca
 *     implementarea existenta (#passkey-add, #passkey-reg-error, #passkey-label,
 *     #passkey-unsupported), ca sa nu apara regresii de autentificare.
 *   - `profile_status` este status de PREZENTA. Starea de securitate a contului
 *     (`utilizatori.status`) este afisata separat si nu poate fi editata de aici.
 *
 * @var array $formData
 * @var array $errors
 * @var array $passkeys
 * @var array $user
 * @var array $avatar
 * @var array $statusMeta
 * @var array $emojiChoices
 * @var array $avatarColors
 * @var array $statusOptions
 */

$passkeys = is_array($passkeys ?? null) ? $passkeys : [];
$errors = is_array($errors ?? null) ? $errors : [];
$user = is_array($user ?? null) ? $user : [];
$avatar = is_array($avatar ?? null) ? $avatar : profile_avatar_data($user);
$statusMeta = is_array($statusMeta ?? null) ? $statusMeta : profile_status_meta('activ');
$emojiChoices = is_array($emojiChoices ?? null) ? $emojiChoices : profile_emoji_choices();
$avatarColors = is_array($avatarColors ?? null) ? $avatarColors : profile_avatar_colors();
$statusOptions = is_array($statusOptions ?? null) ? $statusOptions : profile_status_options();

$roleLabel = function_exists('role_display_name')
    ? role_display_name((string) ($user['rol'] ?? ''))
    : (string) ($user['rol'] ?? '');

// Starea de securitate a contului (activ / inactiv) — read-only aici.
$accountActive = strtolower((string) ($user['status'] ?? 'activ')) === 'activ';

$selectedStatus = (string) ($formData['profile_status'] ?? 'activ');
$selectedMeta = profile_status_meta($selectedStatus);

$passkeyCount = count($passkeys);
$passkeyCounterLabel = $passkeyCount === 1 ? '1 înregistrat' : $passkeyCount . ' înregistrate';

/** Eticheta prietenoasa pentru dispozitiv, derivata din transports. */
$deviceLabel = static function (?string $transports): string {
    $list = array_filter(array_map('trim', explode(',', (string) $transports)));
    if ($list === []) {
        return 'Necunoscut';
    }
    if (in_array('internal', $list, true)) {
        return 'Acest dispozitiv';
    }
    if (in_array('hybrid', $list, true)) {
        return 'Telefon / tabletă';
    }
    if (in_array('usb', $list, true) || in_array('nfc', $list, true) || in_array('ble', $list, true)) {
        return 'Cheie de securitate';
    }

    return ucfirst((string) $list[0]);
};
?>
<div class="pf-page">

    <div class="pf-head">
        <span class="pf-head-icon"><i class="bi bi-person-circle" aria-hidden="true"></i></span>
        <div>
            <h1>Profilul meu</h1>
            <p>Gestionează informațiile contului tău și preferințele de autentificare.</p>
        </div>
    </div>

    <!-- ================= Identity + personalization ================= -->
    <section class="pf-card pf-card-pad pf-identity">

        <div class="pf-identity-main">
            <div class="pf-avatar-wrap">
                <?= profile_avatar_markup($avatar, 'pf-avatar-lg', (string) ($user['nume'] ?? 'Avatar')) ?>
                <span class="pf-avatar-dot" id="pf-avatar-dot"
                      data-locked="<?= $accountActive ? '0' : '1' ?>"
                      style="background: <?= e($accountActive ? (string) $selectedMeta['dot'] : '#94a3b8') ?>"
                      title="<?= e((string) $selectedMeta['label']) ?>"></span>
            </div>

            <div class="pf-identity-text">
                <h2 class="pf-identity-name"><?= e((string) ($user['nume'] ?? '')) ?></h2>
                <span class="pf-role-badge"><?= e($roleLabel) ?></span>

                <div class="pf-contact">
                    <i class="bi bi-envelope" aria-hidden="true"></i>
                    <span><?= e((string) ($user['email'] ?? '')) ?></span>
                </div>
                <?php if (trim((string) ($user['telefon'] ?? '')) !== ''): ?>
                    <div class="pf-contact">
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <span><?= e((string) $user['telefon']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="pf-status-block">
            <p class="pf-block-label">Status cont</p>
            <span class="pf-status-pill <?= $accountActive ? '' : 'is-muted' ?>" id="pf-status-pill">
                <span class="pf-status-dot" style="background: <?= e($accountActive ? (string) $selectedMeta['dot'] : '#94a3b8') ?>"></span>
                <span id="pf-status-pill-label"><?= e($accountActive ? (string) $selectedMeta['label'] : 'Inactiv') ?></span>
            </span>
            <p class="pf-status-note" id="pf-status-note">
                <?= $accountActive
                    ? e((string) $selectedMeta['description'])
                    : 'Contul tău este dezactivat. Contactează un administrator.' ?>
            </p>
        </div>

        <div class="pf-personalize">
            <h3>Personalizează profilul</h3>
            <p>Alege o poză de profil și, opțional, un emoji care te reprezintă.</p>

            <div class="pf-btn-row">
                <button type="button" class="pf-btn pf-btn-outline-primary" id="pf-upload-trigger">
                    <i class="bi bi-camera" aria-hidden="true"></i> Încarcă poză
                </button>
                <button type="button" class="pf-btn" id="pf-emoji-trigger">
                    <i class="bi bi-emoji-smile" aria-hidden="true"></i> Alege emoji
                </button>
                <input type="file" id="pf-file-input" accept="image/jpeg,image/png,image/webp" hidden>
            </div>

            <div class="pf-emoji-row" id="pf-emoji-row">
                <?php foreach ($emojiChoices as $index => $emoji): ?>
                    <?php
                    $color = $avatarColors[$index % max(1, count($avatarColors))] ?? '#f1f5f9';
                    // Badge-ul emoji este independent de poza.
                    $isSelected = (string) ($avatar['emoji'] ?? '') === $emoji && $emoji !== '';
                    ?>
                    <button type="button"
                            class="pf-emoji <?= $isSelected ? 'is-selected' : '' ?>"
                            data-emoji="<?= e($emoji) ?>"
                            data-color="<?= e((string) $color) ?>"
                            style="background: <?= e((string) $color) ?>"
                            title="Adaugă acest emoji ca badge pe avatar (click din nou pentru a-l elimina)"
                            aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"><?= e($emoji) ?></button>
                <?php endforeach; ?>
            </div>

            <p class="pf-hint">
                Format recomandat: JPG, PNG. Dimensiune maximă: 2MB.
                Emoji-ul apare ca badge în colțul avatarului — click din nou pe el pentru a-l elimina.
            </p>
            <p class="pf-invalid" id="pf-avatar-error" hidden></p>
        </div>
    </section>

    <!-- ================= Account info + status ================= -->
    <div class="pf-body">

        <!-- ---------- Informații cont ---------- -->
        <section class="pf-card pf-card-pad">
            <h2 class="pf-card-title">
                <span class="pf-title-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                Informații cont
            </h2>

            <form method="post" action="<?= e(build_query_url(['page' => 'profil', 'action' => 'actualizeaza'])) ?>" novalidate id="pf-profile-form">
                <?= csrf_field() ?>

                <div class="pf-field-row">
                    <span class="pf-field-icon"><i class="bi bi-person" aria-hidden="true"></i></span>
                    <div class="pf-field">
                        <label for="nume">Nume complet</label>
                        <input type="text" id="nume" name="nume" maxlength="120" required
                               class="pf-input <?= isset($errors['nume']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string) ($formData['nume'] ?? '')) ?>">
                        <?php if (isset($errors['nume'])): ?>
                            <div class="pf-invalid"><?= e((string) $errors['nume']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pf-field-row">
                    <span class="pf-field-icon"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                    <div class="pf-field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" maxlength="190" required
                               class="pf-input <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string) ($formData['email'] ?? '')) ?>">
                        <?php if (isset($errors['email'])): ?>
                            <div class="pf-invalid"><?= e((string) $errors['email']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pf-field-row">
                    <span class="pf-field-icon"><i class="bi bi-telephone" aria-hidden="true"></i></span>
                    <div class="pf-field">
                        <label for="telefon">Telefon</label>
                        <input type="text" id="telefon" name="telefon" maxlength="20"
                               class="pf-input <?= isset($errors['telefon']) ? 'is-invalid' : '' ?>"
                               value="<?= e((string) ($formData['telefon'] ?? '')) ?>"
                               placeholder="Ex: 0722000000 sau +40722000000">
                        <?php if (isset($errors['telefon'])): ?>
                            <div class="pf-invalid"><?= e((string) $errors['telefon']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <hr class="pf-divider">
                <p class="pf-section-label">Schimbare parola (opțional)</p>

                <div class="pf-field-row" id="pf-password-block">
                    <span class="pf-field-icon"><i class="bi bi-lock" aria-hidden="true"></i></span>
                    <div class="pf-password-grid">
                        <div class="pf-field">
                            <label for="parola_noua">Parola nouă</label>
                            <div class="pf-input-wrap">
                                <input type="password" id="parola_noua" name="parola_noua" autocomplete="new-password"
                                       class="pf-input <?= isset($errors['parola_noua']) ? 'is-invalid' : '' ?>"
                                       placeholder="Introdu parola nouă">
                                <button type="button" class="pf-eye" data-pf-toggle="parola_noua" aria-label="Arată parola">
                                    <i class="bi bi-eye-slash" aria-hidden="true"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['parola_noua'])): ?>
                                <div class="pf-invalid"><?= e((string) $errors['parola_noua']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="pf-field">
                            <label for="confirmare_parola">Confirmare parolă</label>
                            <div class="pf-input-wrap">
                                <input type="password" id="confirmare_parola" name="confirmare_parola" autocomplete="new-password"
                                       class="pf-input <?= isset($errors['confirmare_parola']) ? 'is-invalid' : '' ?>"
                                       placeholder="Confirmă parola nouă">
                                <button type="button" class="pf-eye" data-pf-toggle="confirmare_parola" aria-label="Arată parola">
                                    <i class="bi bi-eye-slash" aria-hidden="true"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['confirmare_parola'])): ?>
                                <div class="pf-invalid"><?= e((string) $errors['confirmare_parola']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Statusul de prezenta se trimite cu acelasi formular -->
                <input type="hidden" name="profile_status" id="pf-status-input" value="<?= e($selectedStatus) ?>">
                <input type="hidden" name="status_message" id="pf-status-message-input"
                       value="<?= e((string) ($formData['status_message'] ?? '')) ?>">

                <div class="pf-form-actions">
                    <button type="submit" class="pf-btn pf-btn-primary">
                        <i class="bi bi-save" aria-hidden="true"></i> Actualizează profilul
                    </button>
                </div>
            </form>
        </section>

        <!-- ---------- Status utilizator ---------- -->
        <section class="pf-card pf-card-pad">
                <h2 class="pf-card-title">
                    <span class="pf-title-icon is-green"><i class="bi bi-activity" aria-hidden="true"></i></span>
                    Status utilizator
                </h2>

                <div class="pf-field">
                    <label for="pf-status-select">Starea contului</label>
                    <div class="pf-select-wrap">
                        <span class="pf-select-dot" id="pf-select-dot" style="background: <?= e((string) $selectedMeta['dot']) ?>"></span>
                        <select id="pf-status-select" class="pf-input">
                            <?php foreach ($statusOptions as $key => $meta): ?>
                                <option value="<?= e((string) $key) ?>"
                                        data-dot="<?= e((string) $meta['dot']) ?>"
                                        data-tone="<?= e((string) $meta['tone']) ?>"
                                        data-title="<?= e((string) $meta['title']) ?>"
                                        data-description="<?= e((string) $meta['description']) ?>"
                                        <?= $key === $selectedStatus ? 'selected' : '' ?>>
                                    <?= e((string) $meta['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="bi bi-chevron-down pf-select-caret" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="pf-field" style="margin-top:16px;">
                    <label for="pf-status-message">Mesaj personalizat (opțional)</label>
                    <input type="text" id="pf-status-message" class="pf-input <?= isset($errors['status_message']) ? 'is-invalid' : '' ?>"
                           maxlength="255"
                           value="<?= e((string) ($formData['status_message'] ?? '')) ?>"
                           placeholder="Ex: Sunt la birou, disponibil pentru întrebări.">
                    <?php if (isset($errors['status_message'])): ?>
                        <div class="pf-invalid"><?= e((string) $errors['status_message']) ?></div>
                    <?php endif; ?>
                    <p class="pf-help">Acest mesaj este vizibil pentru colegi în aplicație.</p>
                </div>

                <div class="pf-status-panel <?= $accountActive ? ('is-' . ($selectedMeta['tone'] === 'ok' ? 'ok' : $selectedMeta['tone'])) : 'is-muted' ?>" id="pf-status-panel">
                    <i class="bi <?= $accountActive ? 'bi-shield-check' : 'bi-shield-exclamation' ?>" aria-hidden="true" id="pf-status-panel-icon"></i>
                    <div>
                        <strong id="pf-status-panel-title">
                            <?= e($accountActive ? (string) $selectedMeta['title'] : 'Cont dezactivat') ?>
                        </strong>
                        <span id="pf-status-panel-text">
                            <?= e($accountActive
                                ? (string) $selectedMeta['description']
                                : 'Contul nu poate fi folosit pentru autentificare. Contactează un administrator.') ?>
                        </span>
                    </div>
                </div>

                <p class="pf-help" style="margin-top:10px;">
                    Statusul de prezență nu modifică drepturile contului tău.
                    Se salvează împreună cu <strong>Actualizează profilul</strong>.
                </p>
        </section>
    </div>

    <!-- ================= Passkeys ================= -->
    <section class="pf-card pf-card-pad">
        <div class="pf-passkeys-head">
            <h2 class="pf-card-title" style="margin-bottom:0;">
                <span class="pf-title-icon"><i class="bi bi-fingerprint" aria-hidden="true"></i></span>
                Passkeys
            </h2>
            <span class="pf-counter"><?= e($passkeyCounterLabel) ?></span>
        </div>

        <p class="pf-passkeys-desc">
            Autentifică-te fără parolă folosind amprenta, fața sau PIN-ul dispozitivului.
            Parola rămâne ca metodă de rezervă.
        </p>

        <div id="passkey-reg-error" class="pf-modal-error d-none" role="alert"></div>

        <div class="pf-field-row" style="align-items:flex-end;">
            <div class="pf-field" style="max-width:320px;">
                <label for="passkey-label">Nume passkey (opțional)</label>
                <input type="text" id="passkey-label" class="pf-input" maxlength="120" placeholder="Ex: Laptop birou, iPhone">
            </div>
        </div>

        <div class="pf-table-wrap">
            <table class="pf-table">
                <thead>
                    <tr>
                        <th>Nume passkey</th>
                        <th>Dispozitiv</th>
                        <th>Ultima utilizare</th>
                        <th>Creat la</th>
                        <th style="text-align:right;">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($passkeys === []): ?>
                        <tr>
                            <td colspan="5">
                                <div class="pf-empty">
                                    <i class="bi bi-key pf-empty-icon" aria-hidden="true"></i>
                                    <strong>Nu ai înregistrate passkeys.</strong>
                                    <span>Adaugă un passkey pentru autentificare rapidă și sigură.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($passkeys as $pk): ?>
                            <tr>
                                <td>
                                    <i class="bi bi-key" style="color:#94a3b8;margin-right:8px;" aria-hidden="true"></i>
                                    <strong><?= e((string) ($pk['label'] ?? 'Passkey')) ?></strong>
                                </td>
                                <td><?= e($deviceLabel($pk['transports'] ?? null)) ?></td>
                                <td>
                                    <?= !empty($pk['last_used_at'])
                                        ? e(format_datetime_ro((string) $pk['last_used_at']))
                                        : '<span style="color:#94a3b8">Niciodată</span>' ?>
                                </td>
                                <td><?= e(format_datetime_ro((string) ($pk['created_at'] ?? ''))) ?></td>
                                <td style="text-align:right;">
                                    <form method="post"
                                          action="<?= e(build_query_url(['page' => 'profil', 'action' => 'passkey_delete'])) ?>"
                                          onsubmit="return confirm('Ștergi acest passkey?');"
                                          style="display:inline;margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="passkey_id" value="<?= (int) ($pk['id'] ?? 0) ?>">
                                        <button type="submit" class="pf-icon-btn" title="Șterge passkey"
                                                style="color:#dc2626;border-color:#fecaca;">
                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pf-passkeys-actions">
            <button id="passkey-add" type="button" class="pf-btn pf-btn-primary">
                <i class="bi bi-plus-lg" aria-hidden="true"></i><span>Adaugă passkey</span>
            </button>
        </div>

        <div id="passkey-unsupported" class="pf-help d-none" style="margin-top:12px;">
            <i class="bi bi-info-circle" aria-hidden="true"></i> Browserul sau dispozitivul acesta nu suportă passkeys.
        </div>
    </section>
</div>

<!-- ================= Crop modal (referinta #2) ================= -->
<div class="pf-modal-backdrop" id="pf-crop-modal" hidden>
    <div class="pf-modal" role="dialog" aria-modal="true" aria-labelledby="pf-crop-title">
        <div class="pf-modal-head">
            <h2 id="pf-crop-title">Decupează poza de profil</h2>
            <button type="button" class="pf-modal-close" id="pf-crop-close" aria-label="Închide">&times;</button>
        </div>

        <p class="pf-modal-desc">Mută și ajustează imaginea pentru a o poziționa corect în cadru.</p>

        <div class="pf-modal-error" id="pf-crop-error" hidden></div>

        <div class="pf-crop-stage" id="pf-crop-stage">
            <canvas class="pf-crop-canvas" id="pf-crop-canvas" width="768" height="768"></canvas>
            <div class="pf-crop-overlay">
                <div class="pf-crop-circle"><span class="pf-crop-grid"></span></div>
            </div>
        </div>

        <div class="pf-crop-controls">
            <button type="button" class="pf-icon-btn" id="pf-zoom-out" aria-label="Micșorează">
                <i class="bi bi-zoom-out" aria-hidden="true"></i>
            </button>
            <input type="range" class="pf-range" id="pf-zoom-range" min="100" max="400" value="100" step="1" aria-label="Zoom">
            <button type="button" class="pf-icon-btn" id="pf-zoom-in" aria-label="Mărește">
                <i class="bi bi-zoom-in" aria-hidden="true"></i>
            </button>
            <button type="button" class="pf-icon-btn" id="pf-rotate" aria-label="Rotește 90°">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            </button>
        </div>

        <div class="pf-modal-info">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <span>Imaginea va fi afișată circular în profil.</span>
        </div>

        <div class="pf-modal-foot">
            <button type="button" class="pf-btn" id="pf-crop-cancel">Anulează</button>
            <button type="button" class="pf-btn pf-btn-primary" id="pf-crop-save">Salvează poza</button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= e(url('assets/css/profil.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/profil.css'))) ?>">
<script>
window.FleetProfileConfig = {
    csrf: <?= json_encode(csrf_token()) ?>,
    uploadUrl: <?= json_encode(build_query_url(['page' => 'profil', 'action' => 'avatar_upload'])) ?>,
    emojiUrl: <?= json_encode(build_query_url(['page' => 'profil', 'action' => 'avatar_emoji'])) ?>,
    maxBytes: <?= json_encode(UserAvatarService::MAX_UPLOAD_BYTES) ?>,
    outputSize: <?= json_encode(UserAvatarService::OUTPUT_SIZE) ?>
};
</script>
<script src="<?= e(url('assets/js/profil.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/profil.js'))) ?>" defer></script>

<script src="<?= e(url('assets/js/webauthn.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/webauthn.js'))) ?>"></script>
<script>
/* Passkeys — logica existenta, pastrata neschimbata. */
(() => {
    const addButton = document.getElementById('passkey-add');
    const errorBox = document.getElementById('passkey-reg-error');
    const labelInput = document.getElementById('passkey-label');
    const unsupported = document.getElementById('passkey-unsupported');
    if (!addButton) { return; }

    if (!window.FleetPasskey || !window.FleetPasskey.supported()) {
        addButton.disabled = true;
        if (labelInput) { labelInput.disabled = true; }
        if (unsupported) { unsupported.classList.remove('d-none'); }
        return;
    }

    if (!window.FleetPasskey.hostAllowed()) {
        addButton.disabled = true;
        if (labelInput) { labelInput.disabled = true; }
        if (unsupported) {
            const url = window.FleetPasskey.localhostUrl();
            unsupported.classList.remove('d-none');
            unsupported.innerHTML = '<i class="bi bi-info-circle"></i> Passkey-urile nu funcționează pe o adresă IP. '
                + 'Deschide aplicația la <a href="' + url + '">' + url + '</a>.';
        }
        return;
    }

    const optionsUrl = <?= json_encode(build_query_url(['page' => 'profil', 'action' => 'passkey_options'])) ?>;
    const verifyUrl = <?= json_encode(build_query_url(['page' => 'profil', 'action' => 'passkey_register'])) ?>;
    const csrf = <?= json_encode(csrf_token()) ?>;

    addButton.addEventListener('click', async () => {
        errorBox.classList.add('d-none');
        addButton.disabled = true;
        const span = addButton.querySelector('span');
        const original = span.textContent;
        span.textContent = 'Se creează passkey-ul...';
        try {
            await window.FleetPasskey.register(optionsUrl, verifyUrl, csrf, labelInput ? labelInput.value : '');
            window.location.reload();
        } catch (err) {
            errorBox.textContent = (err && err.message) ? err.message : 'Înregistrarea passkey a eșuat.';
            errorBox.classList.remove('d-none');
            addButton.disabled = false;
            span.textContent = original;
        }
    });
})();
</script>
