<?php
declare(strict_types=1);

/**
 * Profil utilizator — helpers pentru avatar si status de prezenta.
 *
 * IMPORTANT
 *   `profile_status` (prezenta) este complet separat de `utilizatori.status`
 *   (starea de securitate/autorizare). Nimic de aici nu influenteaza dreptul
 *   de autentificare al unui cont.
 */

/** Paleta de emoji oferita in "Personalizeaza profilul". */
function profile_emoji_choices(): array
{
    return class_exists('UserAvatarService')
        ? UserAvatarService::EMOJI_CHOICES
        : ['😊', '😎', '🚚', '👩‍💻', '🦊', '⚙️'];
}

/** Culorile de fundal disponibile pentru avatarul emoji. */
function profile_avatar_colors(): array
{
    return class_exists('UserAvatarService')
        ? UserAvatarService::AVATAR_COLORS
        : ['#dbeafe'];
}

/**
 * Metadate pentru statusul de prezenta.
 *
 * @return array{key:string,label:string,dot:string,tone:string,title:string,description:string}
 */
function profile_status_meta(string $status): array
{
    $key = strtolower(trim($status));

    if ($key === 'ocupat') {
        return [
            'key' => 'ocupat',
            'label' => 'Ocupat',
            'dot' => '#f59e0b',
            'tone' => 'warn',
            'title' => 'Cont activ · ocupat',
            'description' => 'Colegii văd că ești ocupat. Autentificarea și aplicația funcționează normal.',
        ];
    }

    if ($key === 'indisponibil') {
        return [
            'key' => 'indisponibil',
            'label' => 'Indisponibil',
            'dot' => '#94a3b8',
            'tone' => 'muted',
            'title' => 'Cont activ · indisponibil',
            'description' => 'Colegii văd că ești indisponibil. Autentificarea și aplicația funcționează normal.',
        ];
    }

    return [
        'key' => 'activ',
        'label' => 'Activ',
        'dot' => '#22c55e',
        'tone' => 'ok',
        'title' => 'Cont activ',
        'description' => 'Te poți autentifica și utiliza aplicația normal.',
    ];
}

/** Statusurile de prezenta disponibile in selector. */
function profile_status_options(): array
{
    return [
        'activ' => profile_status_meta('activ'),
        'ocupat' => profile_status_meta('ocupat'),
        'indisponibil' => profile_status_meta('indisponibil'),
    ];
}

/**
 * Rezolva avatarul unui utilizator pentru afisare.
 *
 * MODEL
 *   Avatarul de baza (poza / initiale / icon) si badge-ul emoji sunt INDEPENDENTE.
 *   Alegerea unui emoji nu sterge poza, iar incarcarea unei poze nu sterge emoji-ul.
 *
 * @param array<string,mixed>|null $user
 * @return array{type:string,url:?string,emoji:?string,color:string,initials:string}
 */
function profile_avatar_data(?array $user): array
{
    $initials = '';
    if (is_array($user)) {
        $name = trim((string) ($user['nume'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name) ?: [];
            foreach (array_slice($parts, 0, 2) as $part) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }
    }

    $result = [
        'type' => 'none',
        'url' => null,
        'emoji' => null,
        'color' => '#dbeafe',
        'initials' => $initials,
    ];

    if (!is_array($user)) {
        return $result;
    }

    // --- badge emoji (independent de poza) ---
    $emoji = trim((string) ($user['avatar_emoji'] ?? ''));
    if ($emoji !== '') {
        $result['emoji'] = $emoji;
        $color = trim((string) ($user['avatar_color'] ?? ''));
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            $result['color'] = $color;
        }
    }

    // --- avatar de baza ---
    $type = strtolower(trim((string) ($user['avatar_type'] ?? 'none')));
    $value = trim((string) ($user['avatar_value'] ?? ''));

    if ($type === 'image' && $value !== '' && class_exists('UserAvatarService')) {
        $publicUrl = (new UserAvatarService())->publicUrl($value);
        if ($publicUrl !== null) {
            $result['type'] = 'image';
            $result['url'] = $publicUrl;
        }
    }

    return $result;
}

/**
 * Avatarul + statusul utilizatorului autentificat, pentru bara de sus.
 * Datele sunt memorate in sesiune si reimprospatate la salvarea profilului.
 *
 * @return array{avatar:array,status:array}
 */
function current_user_profile_visuals(): array
{
    $user = function_exists('current_user') ? current_user() : null;
    $userId = (int) ($user['id'] ?? 0);

    if ($userId <= 0) {
        return [
            'avatar' => profile_avatar_data(null),
            'status' => profile_status_meta('activ'),
        ];
    }

    // Se reimprospateaza si daca in sesiune lipseste cheia `avatar_emoji`: asta
    // inseamna un cache creat inainte de migrarea badge-ului, care altfel ar
    // ramane blocat pe o valoare veche pana la re-autentificare.
    if (!array_key_exists('avatar_type', $_SESSION['auth_user'] ?? [])
        || !array_key_exists('avatar_emoji', $_SESSION['auth_user'] ?? [])) {
        try {
            $stmt = get_pdo()->prepare(
                'SELECT avatar_type, avatar_value, avatar_emoji, avatar_color, profile_status
                 FROM utilizatori WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $_SESSION['auth_user']['avatar_type'] = $row['avatar_type'] ?? 'none';
                $_SESSION['auth_user']['avatar_value'] = $row['avatar_value'] ?? null;
                $_SESSION['auth_user']['avatar_emoji'] = $row['avatar_emoji'] ?? null;
                $_SESSION['auth_user']['avatar_color'] = $row['avatar_color'] ?? null;
                $_SESSION['auth_user']['profile_status'] = $row['profile_status'] ?? 'activ';
            }
        } catch (Throwable $exception) {
            // Migration not applied yet, or a transient DB issue: degrade quietly.
            $_SESSION['auth_user']['avatar_type'] = 'none';
            $_SESSION['auth_user']['avatar_value'] = null;
            $_SESSION['auth_user']['avatar_emoji'] = null;
            $_SESSION['auth_user']['avatar_color'] = null;
            $_SESSION['auth_user']['profile_status'] = 'activ';
        }
    }

    $merged = array_merge(is_array($user) ? $user : [], [
        'avatar_type' => $_SESSION['auth_user']['avatar_type'] ?? 'none',
        'avatar_value' => $_SESSION['auth_user']['avatar_value'] ?? null,
        'avatar_emoji' => $_SESSION['auth_user']['avatar_emoji'] ?? null,
        'avatar_color' => $_SESSION['auth_user']['avatar_color'] ?? null,
    ]);

    return [
        'avatar' => profile_avatar_data($merged),
        'status' => profile_status_meta((string) ($_SESSION['auth_user']['profile_status'] ?? 'activ')),
    ];
}

/** Reimprospateaza cache-ul de sesiune dupa salvarea profilului. */
function refresh_current_user_profile_visuals(array $row): void
{
    if (!isset($_SESSION['auth_user'])) {
        return;
    }

    $_SESSION['auth_user']['avatar_type'] = $row['avatar_type'] ?? 'none';
    $_SESSION['auth_user']['avatar_value'] = $row['avatar_value'] ?? null;
    $_SESSION['auth_user']['avatar_emoji'] = $row['avatar_emoji'] ?? null;
    $_SESSION['auth_user']['avatar_color'] = $row['avatar_color'] ?? null;
    $_SESSION['auth_user']['profile_status'] = $row['profile_status'] ?? 'activ';
}

/**
 * Markup-ul avatarului circular: un "stack" care contine avatarul de baza si,
 * optional, badge-ul emoji suprapus in coltul din stanga-jos.
 *
 * Badge-ul trebuie sa fie FRATE cu .profile-avatar, nu copil: avatarul de baza
 * are overflow:hidden pentru a decupa poza si ar taia badge-ul.
 *
 * @param array{type:string,url:?string,emoji:?string,color:string,initials:string} $avatar
 */
function profile_avatar_markup(array $avatar, string $extraClass = '', ?string $altName = null): string
{
    $stackClass = trim('profile-avatar-stack ' . $extraClass);
    $type = (string) ($avatar['type'] ?? 'none');

    if ($type === 'image' && !empty($avatar['url'])) {
        $base = '<span class="profile-avatar"><img src="' . e((string) $avatar['url'])
            . '" alt="' . e((string) ($altName ?? 'Avatar')) . '" loading="lazy"></span>';
    } else {
        $initials = (string) ($avatar['initials'] ?? '');
        $base = $initials !== ''
            ? '<span class="profile-avatar is-initials"><span class="profile-avatar-initials">' . e($initials) . '</span></span>'
            : '<span class="profile-avatar is-fallback"><i class="bi bi-person-fill" aria-hidden="true"></i></span>';
    }

    $badge = '';
    if (!empty($avatar['emoji'])) {
        $badge = '<span class="profile-avatar-badge" style="background:' . e((string) $avatar['color']) . '">'
            . '<span class="profile-avatar-badge-emoji">' . e((string) $avatar['emoji']) . '</span></span>';
    }

    return '<span class="' . e($stackClass) . '">' . $base . $badge . '</span>';
}
