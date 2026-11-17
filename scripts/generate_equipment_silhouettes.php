<?php
/**
 * Generează cele patru siluete din Catalog echipamente:
 *   htdocs/assets/img/echipamente/{sofer,tesa}-{m,f}.svg
 *
 * Toate siluetele au aceeași pânză (viewBox 0 0 360 860) și aceleași puncte de
 * ancorare, așa că punctele de pe corp (cască, piept, mâini, genunchi, talpă)
 * rămân în același loc indiferent de variantă. Coordonatele ancorelor stau în
 * DriverEquipmentModel::SILHOUETTE_ANCHORS — dacă schimbi proporțiile de aici,
 * verifică și acele procente.
 *
 * Rulare: php scripts/generate_equipment_silhouettes.php
 */

declare(strict_types=1);

const W = 360;
const CX = 180;

/** Oglindește toate perechile „x,y” față de axa verticală a pânzei. */
function mir(string $d): string
{
    return (string) preg_replace_callback('/(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', static function (array $m): string {
        return rtrim(rtrim(number_format(W - (float) $m[1], 2, '.', ''), '0'), '.') . ',' . $m[2];
    }, $d);
}

function n(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/** Formatează un șablon de path în care {x+12} / {y} sunt expresii. */
function p(string $tpl, array $vars): string
{
    return (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($vars): string {
        $expr = strtr($m[1], array_map(static fn($v) => '(' . $v . ')', $vars));
        if (!preg_match('/^[0-9+\-*\/(). ]+$/', $expr)) {
            throw new RuntimeException('Expresie invalidă: ' . $m[1]);
        }

        return n((float) eval('return ' . $expr . ';'));
    }, $tpl);
}

function both(string $d, string $attrs): string
{
    return '<path d="' . $d . '" ' . $attrs . '/>' . '<path d="' . mir($d) . '" ' . $attrs . '/>';
}

/**
 * Proporțiile corpului. S = x-ul umărului stâng (din perspectiva privitorului).
 */
function body(string $gender): array
{
    return $gender === 'f'
        ? ['S' => 124, 'chest' => 54, 'waist' => 43, 'hip' => 55, 'tw' => 21, 'te' => 26, 'ts' => 31, 'hx' => 30, 'hy' => 39]
        : ['S' => 110, 'chest' => 64, 'waist' => 54, 'hip' => 54, 'tw' => 25, 'te' => 31, 'ts' => 37, 'hx' => 33, 'hy' => 41];
}

function defs(): string
{
    return <<<SVG
<defs>
  <radialGradient id="skin" cx="38%" cy="30%" r="80%">
    <stop offset="0" stop-color="#3d6596"/><stop offset=".55" stop-color="#1c3558"/><stop offset="1" stop-color="#0b1a30"/>
  </radialGradient>
  <linearGradient id="skinV" x1="0" x2="1">
    <stop offset="0" stop-color="#132a48"/><stop offset=".5" stop-color="#2a4b78"/><stop offset="1" stop-color="#0f223d"/>
  </linearGradient>
  <linearGradient id="navy" x1="0" x2="1">
    <stop offset="0" stop-color="#16213a"/><stop offset=".45" stop-color="#26365c"/><stop offset="1" stop-color="#121b30"/>
  </linearGradient>
  <linearGradient id="navyDark" x1="0" x2="1">
    <stop offset="0" stop-color="#0f172a"/><stop offset=".5" stop-color="#1c2846"/><stop offset="1" stop-color="#0c1324"/>
  </linearGradient>
  <linearGradient id="orange" x1="0" x2="1" y1="0" y2="1">
    <stop offset="0" stop-color="#fb923c"/><stop offset="1" stop-color="#ea580c"/>
  </linearGradient>
  <linearGradient id="reflect" x1="0" x2="1">
    <stop offset="0" stop-color="#c8d400"/><stop offset=".5" stop-color="#f1ff3a"/><stop offset="1" stop-color="#b9c400"/>
  </linearGradient>
  <linearGradient id="helmet" x1="0" x2="1" y1="0" y2="1">
    <stop offset="0" stop-color="#fde047"/><stop offset=".55" stop-color="#facc15"/><stop offset="1" stop-color="#ca8a04"/>
  </linearGradient>
  <linearGradient id="boot" x1="0" x2="1">
    <stop offset="0" stop-color="#0b0f19"/><stop offset=".5" stop-color="#262c3a"/><stop offset="1" stop-color="#0b0f19"/>
  </linearGradient>
  <linearGradient id="glove" x1="0" x2="1">
    <stop offset="0" stop-color="#0d111b"/><stop offset=".5" stop-color="#2a3142"/><stop offset="1" stop-color="#0d111b"/>
  </linearGradient>
  <linearGradient id="blazer" x1="0" x2="1">
    <stop offset="0" stop-color="#1b2740"/><stop offset=".45" stop-color="#2f4166"/><stop offset="1" stop-color="#18223a"/>
  </linearGradient>
  <linearGradient id="shirt" x1="0" x2="1">
    <stop offset="0" stop-color="#b8cfee"/><stop offset=".5" stop-color="#e3eefc"/><stop offset="1" stop-color="#b1c8e8"/>
  </linearGradient>
  <linearGradient id="blouse" x1="0" x2="1">
    <stop offset="0" stop-color="#dfe5ee"/><stop offset=".5" stop-color="#ffffff"/><stop offset="1" stop-color="#d6dde8"/>
  </linearGradient>
  <linearGradient id="trouser" x1="0" x2="1">
    <stop offset="0" stop-color="#151b28"/><stop offset=".5" stop-color="#2b3446"/><stop offset="1" stop-color="#131925"/>
  </linearGradient>
  <linearGradient id="hair" x1="0" x2="0" y1="0" y2="1">
    <stop offset="0" stop-color="#3a3f4b"/><stop offset="1" stop-color="#1c2029"/>
  </linearGradient>
  <linearGradient id="screen" x1="0" x2="1" y1="0" y2="1">
    <stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#1d4ed8"/>
  </linearGradient>
</defs>
SVG;
}

/** Capul de manechin: fără trăsături, cu linii fine de „hologramă”. */
function head(array $b): string
{
    $hx = $b['hx'];
    $hy = $b['hy'];
    $cy = 80;
    $wire = 'fill="none" stroke="#7cc4ff" stroke-opacity=".38" stroke-width="1"';

    return '<rect x="' . (CX - 13) . '" y="112" width="26" height="34" rx="9" fill="url(#skinV)"/>'
        . '<ellipse cx="' . CX . '" cy="' . $cy . '" rx="' . $hx . '" ry="' . $hy . '" fill="url(#skin)" stroke="#8fd0ff" stroke-opacity=".55" stroke-width="1.2"/>'
        . '<ellipse cx="' . CX . '" cy="' . $cy . '" rx="' . n($hx * .45) . '" ry="' . $hy . '" ' . $wire . '/>'
        . '<path d="M' . (CX - $hx + 2) . ',' . ($cy - 8) . ' Q' . CX . ',' . ($cy + 4) . ' ' . (CX + $hx - 2) . ',' . ($cy - 8) . '" ' . $wire . '/>'
        . '<path d="M' . (CX - $hx + 5) . ',' . ($cy + 16) . ' Q' . CX . ',' . ($cy + 28) . ' ' . (CX + $hx - 5) . ',' . ($cy + 16) . '" ' . $wire . '/>'
        . '<ellipse cx="' . (CX - 10) . '" cy="' . ($cy - 20) . '" rx="10" ry="14" fill="#9fd4ff" fill-opacity=".12"/>';
}

function arms(array $b, string $fill, array $extra = []): string
{
    $v = ['S' => $b['S'], 'tw' => $b['tw'], 'te' => $b['te'], 'ts' => $b['ts']];
    $arm = p('M{S+4},152 Q{S-20},162 {S-22},212 L{S-30},314 L{S-36},440 L{S-36+tw},440 L{S-30+te},314 L{S-22+ts},226 Z', $v);
    $out = both($arm, 'fill="' . $fill . '"');
    // umbră pe interiorul brațului, ca să se citească volumul
    $shade = p('M{S-22+ts},226 L{S-30+te},314 L{S-36+tw},440 L{S-36+tw-6},440 L{S-30+te-6},314 L{S-22+ts-6},232 Z', $v);
    $out .= both($shade, 'fill="#000" fill-opacity=".18"');
    foreach ($extra as $piece) {
        $out .= both(p($piece[0], $v), $piece[1]);
    }

    return $out;
}

function hands(array $b, string $fill, string $stroke = 'none'): string
{
    $wx = $b['S'] - 36 + $b['tw'] / 2;
    $v = ['w' => $wx];
    $d = p('M{w-13},436 L{w+13},436 Q{w+16},462 {w+15},478 Q{w+13},500 {w},503 Q{w-14},501 {w-15},480 Q{w-16},460 {w-13},436 Z', $v);
    $thumb = p('M{w+12},446 Q{w+22},452 {w+20},470 Q{w+17},476 {w+13},470 Z', $v);

    return both($d, 'fill="' . $fill . '" stroke="' . $stroke . '" stroke-opacity=".5"') . both($thumb, 'fill="' . $fill . '"');
}

function torsoPath(array $b, int $bottom = 450): string
{
    $v = ['c' => CX, 'S' => $b['S'], 'ch' => $b['chest'], 'wa' => $b['waist'], 'hi' => $b['hip'], 'bt' => $bottom];

    return p('M{c-17},136 Q{S+8},138 {S},158 Q{S+4},194 {c-ch},222 L{c-wa},352 Q{c-hi},404 {c-hi-2},{bt} L{c+hi+2},{bt} Q{c+hi},404 {c+wa},352 L{c+ch},222 Q{360-S-4},194 {360-S},158 Q{360-S-8},138 {c+17},136 Z', $v);
}

function legs(array $b, string $fill): string
{
    $v = ['c' => CX, 'h' => $b['hip']];
    $leg = p('M{c-h},428 L{c},428 L{c-3},492 L{c-11},792 L{c-h+12},792 L{c-h+4},620 Q{c-h-2},520 {c-h},428 Z', $v);
    $out = both($leg, 'fill="' . $fill . '"');
    $out .= both(p('M{c-3},492 L{c-11},792 L{c-17},792 L{c-9},500 Z', $v), 'fill="#000" fill-opacity=".22"');

    return $out;
}

/* ------------------------------------------------------------------ Șofer -- */

function driver(string $g): string
{
    $b = body($g);
    $v = ['c' => CX, 'S' => $b['S'], 'h' => $b['hip'], 'ch' => $b['chest'], 'wa' => $b['waist']];
    $svg = '';

    // păr sub cască (doar varianta feminină), în spatele gâtului
    if ($g === 'f') {
        $svg .= '<path d="M' . (CX - 32) . ',70 Q' . (CX - 40) . ',130 ' . (CX - 30) . ',156 L' . (CX + 30) . ',156 Q' . (CX + 40) . ',130 ' . (CX + 32) . ',70 Z" fill="url(#hair)"/>';
    }

    // pantaloni de lucru + genunchiere + buzunare cargo + paspoal portocaliu
    $svg .= legs($b, 'url(#navy)');
    $svg .= both(p('M{c-h+5},606 Q{c-h+3},588 {c-h+18},586 L{c-14},586 Q{c-4},588 {c-6},606 L{c-7},650 Q{c-8},664 {c-20},664 L{c-h+16},664 Q{c-h+6},662 {c-h+6},648 Z', $v), 'fill="url(#navyDark)" stroke="#3b4d78" stroke-width="1.2"');
    $svg .= both(p('M{c-h+8},612 L{c-10},612 M{c-h+8},638 L{c-10},638', $v), 'stroke="#4a5d8a" stroke-width="1" stroke-dasharray="3 3" fill="none"');
    $svg .= both(p('M{c-h-1},500 L{c-h+18},500 L{c-h+19},560 Q{c-h+10},566 {c-h+1},560 Z', $v), 'fill="#1a2440" stroke="#3b4d78" stroke-width="1"');
    $svg .= both(p('M{c-h-1},500 L{c-h+18},500 L{c-h+18},512 L{c-h-1},512 Z', $v), 'fill="url(#orange)"');
    $svg .= both(p('M{c-h+1},440 Q{c-h-3},530 {c-h+5},620 L{c-h+12},790', $v), 'stroke="url(#orange)" stroke-width="3" fill="none"');
    $svg .= both(p('M{c-h+9},708 L{c-12},708 L{c-12},720 L{c-h+10},720 Z', $v), 'fill="url(#reflect)"');

    // bocanci
    $boot = p('M{c-h+10},782 L{c-10},782 L{c-9},812 Q{c-8},826 {c-22},830 L{c-h-14},832 Q{c-h-20},818 {c-h-2},806 Z', $v);
    $svg .= both($boot, 'fill="url(#boot)"');
    $svg .= both(p('M{c-h-18},826 L{c-8},826 L{c-8},836 L{c-h-20},836 Z', $v), 'fill="#05070c"');
    $svg .= both(p('M{c-h+10},786 L{c-10},786 L{c-10},792 L{c-h+10},792 Z', $v), 'fill="url(#orange)"');
    $svg .= both(p('M{c-h+14},798 L{c-14},798 M{c-h+14},806 L{c-14},806', $v), 'stroke="#64748b" stroke-width="1.4" fill="none"');

    // centură
    $svg .= '<rect x="' . (CX - $b['hip'] - 2) . '" y="424" width="' . (2 * $b['hip'] + 4) . '" height="12" rx="3" fill="#0b1020"/>';

    // brațe (mâneci) cu benzi reflectorizante și manșete portocalii
    $svg .= arms($b, 'url(#navy)', [
        ['M{S-28},362 L{S-28+te},362 L{S-29+te},378 L{S-29},378 Z', 'fill="url(#reflect)"'],
        ['M{S-35},420 L{S-35+tw},420 L{S-36+tw},440 L{S-36},440 Z', 'fill="url(#orange)"'],
        ['M{S+2},156 Q{S-16},164 {S-19},196 L{S-6},200 Q{S-4},170 {S+12},160 Z', 'fill="url(#orange)"'],
    ]);
    $svg .= hands($b, 'url(#glove)');
    $svg .= both(p('M{S-36+tw/2-14},440 L{S-36+tw/2+14},440 L{S-36+tw/2+14},448 L{S-36+tw/2-14},448 Z', ['S' => $b['S'], 'tw' => $b['tw']]), 'fill="url(#orange)"');

    // geaca de lucru
    $torso = torsoPath($b, 454);
    $svg .= '<clipPath id="torsoClip"><path d="' . $torso . '"/></clipPath>';
    $svg .= '<path d="' . $torso . '" fill="url(#navy)"/>';
    $svg .= '<g clip-path="url(#torsoClip)">';
    // panou portocaliu pe pieptul din dreapta privitorului + umăr stâng
    $svg .= '<path d="' . p('M{c+4},136 L{360-S+4},150 L{c+ch+6},262 L{c+4},262 Z', $v) . '" fill="url(#orange)"/>';
    $svg .= '<path d="' . p('M{c+4},262 L{c+ch+6},262', $v) . '" stroke="#9a3412" stroke-width="2"/>';
    // benzi reflectorizante cu linie argintie
    foreach ([318, 364] as $y) {
        $svg .= '<rect x="100" y="' . $y . '" width="160" height="18" fill="url(#reflect)"/>';
        $svg .= '<rect x="100" y="' . ($y + 7) . '" width="160" height="4" fill="#dfe6ee" fill-opacity=".85"/>';
    }
    // tiv
    $svg .= '<rect x="100" y="438" width="160" height="16" fill="#0f172a"/>';
    $svg .= '</g>';
    // fermoar, guler, buzunar
    $svg .= '<path d="M' . CX . ',140 L' . CX . ',452" stroke="#0a0f1c" stroke-width="3"/>';
    $svg .= '<path d="M' . CX . ',140 L' . CX . ',452" stroke="#94a3b8" stroke-width="1" stroke-dasharray="2 3"/>';
    $svg .= both(p('M{c-18},132 L{c-2},160 L{c-6},134 Z', $v), 'fill="#0f172a"');
    $svg .= '<path d="' . p('M{c-ch+12},228 L{c-12},228 L{c-12},290 L{c-ch+14},290 Z', $v) . '" fill="#1a2747" stroke="#3b4d78" stroke-width="1.2"/>';
    $svg .= '<path d="' . p('M{c-ch+12},228 L{c-12},228 L{c-12},240 L{c-ch+12},240 Z', $v) . '" fill="#22325a"/>';
    // stație radio prinsă pe piept (ancora „IT & Comunicații”)
    $rx = CX + 22;
    $svg .= '<rect x="' . $rx . '" y="186" width="20" height="36" rx="4" fill="#0b0f19" stroke="#475569"/>'
        . '<rect x="' . ($rx + 4) . '" y="192" width="12" height="9" rx="1.5" fill="url(#screen)"/>'
        . '<path d="M' . ($rx + 15) . ',186 L' . ($rx + 15) . ',160" stroke="#0b0f19" stroke-width="4" stroke-linecap="round"/>';

    $svg .= head($b);

    // cască de protecție
    $hx = $b['hx'];
    $svg .= '<path d="M' . (CX - $hx - 6) . ',66 Q' . (CX - $hx - 4) . ',20 ' . CX . ',18 Q' . (CX + $hx + 4) . ',20 ' . (CX + $hx + 6) . ',66 Z" fill="url(#helmet)"/>'
        . '<path d="M' . (CX - 8) . ',20 Q' . CX . ',16 ' . (CX + 8) . ',20 L' . (CX + 7) . ',64 L' . (CX - 7) . ',64 Z" fill="#fef08a" fill-opacity=".7"/>'
        . '<ellipse cx="' . CX . '" cy="66" rx="' . ($hx + 14) . '" ry="8" fill="#eab308"/>'
        . '<ellipse cx="' . CX . '" cy="64" rx="' . ($hx + 12) . '" ry="5" fill="#fde047"/>'
        . '<ellipse cx="' . (CX - 14) . '" cy="36" rx="9" ry="6" fill="#fff" fill-opacity=".45"/>';

    return $svg;
}

/* ------------------------------------------------------------------- TESA -- */

function tesa(string $g): string
{
    $b = body($g);
    $v = ['c' => CX, 'S' => $b['S'], 'h' => $b['hip'], 'ch' => $b['chest'], 'wa' => $b['waist']];
    $svg = '';

    if ($g === 'f') {
        // bob până la umeri, în spatele capului
        $svg .= '<path d="M' . (CX - 38) . ',78 Q' . (CX - 42) . ',28 ' . CX . ',30 Q' . (CX + 42) . ',28 ' . (CX + 38) . ',78 L' . (CX + 40) . ',134 Q' . (CX + 30) . ',142 ' . (CX + 20) . ',128 L' . (CX - 20) . ',128 Q' . (CX - 30) . ',142 ' . (CX - 40) . ',134 Z" fill="url(#hair)"/>';
    }

    // pantaloni de stofă cu dungă
    $svg .= legs($b, 'url(#trouser)');
    $svg .= both(p('M{c-h/2-2},440 L{c-h/2+2},788', $v), 'stroke="#475569" stroke-opacity=".6" stroke-width="1"');

    // pantofi
    if ($g === 'f') {
        $shoe = p('M{c-h+12},792 L{c-12},792 L{c-10},818 Q{c-10},828 {c-22},830 L{c-h-6},830 Q{c-h-10},816 {c-h+4},806 Z', $v);
    } else {
        $shoe = p('M{c-h+12},790 L{c-11},790 L{c-10},816 Q{c-9},828 {c-22},830 L{c-h-12},831 Q{c-h-18},818 {c-h},806 Z', $v);
    }
    $svg .= both($shoe, 'fill="url(#boot)"');
    $svg .= both(p('M{c-h-12},826 L{c-9},826 L{c-9},833 L{c-h-14},833 Z', $v), 'fill="#05070c"');
    $svg .= both(p('M{c-h+2},810 Q{c-h+16},800 {c-14},806', $v), 'stroke="#64748b" stroke-width="1.2" fill="none"');

    // brațe în sacou, manșete de cămașă, mâini de manechin
    $svg .= arms($b, 'url(#blazer)', [
        ['M{S-35},428 L{S-35+tw},428 L{S-36+tw},440 L{S-36},440 Z', 'fill="' . ($g === 'f' ? 'url(#blouse)' : 'url(#shirt)') . '"'],
    ]);
    $svg .= hands($b, 'url(#skinV)', '#8fd0ff');

    // cămașă / bluză (vizibilă între revere)
    $torso = torsoPath($b, $g === 'f' ? 440 : 452);
    $svg .= '<clipPath id="torsoClip"><path d="' . $torso . '"/></clipPath>';
    $svg .= '<path d="' . $torso . '" fill="url(#blazer)"/>';
    $svg .= '<g clip-path="url(#torsoClip)">';
    $inner = $g === 'f' ? 'url(#blouse)' : 'url(#shirt)';
    $svg .= '<path d="' . p('M{c-22},134 L{c+22},134 L{c+14},330 L{c},346 L{c-14},330 Z', $v) . '" fill="' . $inner . '"/>';
    if ($g === 'm') {
        // guler + cravată
        $svg .= '<path d="' . p('M{c-16},134 L{c},152 L{c-6},160 L{c-20},140 Z', $v) . '" fill="#f1f6fd"/>'
            . '<path d="' . p('M{c+16},134 L{c},152 L{c+6},160 L{c+20},140 Z', $v) . '" fill="#f1f6fd"/>'
            . '<path d="' . p('M{c-6},152 L{c+6},152 L{c+4},164 L{c+10},300 L{c},316 L{c-10},300 L{c-4},164 Z', $v) . '" fill="#1e3a8a"/>'
            . '<path d="' . p('M{c-6},200 L{c+8},186 M{c-8},240 L{c+9},226 M{c-9},280 L{c+10},266', $v) . '" stroke="#3b5bdb" stroke-width="2"/>';
    } else {
        $svg .= '<path d="' . p('M{c-20},136 Q{c},166 {c+20},136', $v) . '" fill="none" stroke="#cbd5e1" stroke-width="2"/>'
            . '<circle cx="' . CX . '" cy="170" r="4" fill="#cbd5e1"/>';
    }
    // revere
    $svg .= both(p('M{c-24},132 L{c-14},150 L{c-30},178 L{c-16},196 L{c-2},344 L{c-10},348 L{c-34},200 L{c-40},170 Z', $v), 'fill="#22314f" stroke="#40557f" stroke-width="1"');
    // buzunare
    $svg .= both(p('M{c-ch+4},380 L{c-14},376 L{c-14},382 L{c-ch+4},386 Z', $v), 'fill="#121b30"');
    $svg .= '</g>';
    // nasturi
    $svg .= '<circle cx="' . (CX + 2) . '" cy="372" r="3.5" fill="#0f172a" stroke="#64748b"/>'
        . '<circle cx="' . (CX + 2) . '" cy="404" r="3.5" fill="#0f172a" stroke="#64748b"/>';

    // ecuson pe șnur (ancora „Documente / Legitimații”)
    $svg .= '<path d="M' . (CX - 18) . ',140 Q' . (CX - 28) . ',200 ' . (CX - 40) . ',236 M' . (CX + 14) . ',142 Q' . (CX - 4) . ',200 ' . (CX - 28) . ',236" stroke="#2563eb" stroke-width="3.5" fill="none"/>'
        . '<rect x="' . (CX - 50) . '" y="234" width="32" height="42" rx="4" fill="#f8fafc" stroke="#94a3b8"/>'
        . '<rect x="' . (CX - 50) . '" y="234" width="32" height="11" rx="3" fill="#2563eb"/>'
        . '<circle cx="' . (CX - 34) . '" cy="256" r="5" fill="#94a3b8"/>'
        . '<rect x="' . (CX - 44) . '" y="265" width="20" height="3" rx="1.5" fill="#cbd5e1"/>';

    // tabletă ținută în mâna din dreapta privitorului (ancora „IT & Comunicații”)
    $wx = W - ($b['S'] - 36 + $b['tw'] / 2);
    $svg .= '<g transform="rotate(-8 ' . n($wx) . ' 452)">'
        . '<rect x="' . n($wx - 30) . '" y="404" width="46" height="62" rx="5" fill="#0b0f19" stroke="#475569"/>'
        . '<rect x="' . n($wx - 26) . '" y="409" width="38" height="52" rx="2" fill="url(#screen)"/>'
        . '<path d="M' . n($wx - 20) . ',446 L' . n($wx - 10) . ',436 L' . n($wx - 2) . ',442 L' . n($wx + 8) . ',426" stroke="#e0f2fe" stroke-width="2" fill="none"/>'
        . '</g>';

    $svg .= head($b);

    if ($g === 'm') {
        $svg .= '<path d="M' . (CX - 34) . ',74 Q' . (CX - 36) . ',36 ' . CX . ',34 Q' . (CX + 36) . ',36 ' . (CX + 34) . ',74 Q' . (CX + 30) . ',54 ' . (CX + 6) . ',52 Q' . (CX - 26) . ',52 ' . (CX - 34) . ',74 Z" fill="url(#hair)"/>';
    } else {
        $svg .= '<path d="M' . (CX - 32) . ',82 Q' . (CX - 34) . ',38 ' . CX . ',36 Q' . (CX + 34) . ',38 ' . (CX + 32) . ',82 Q' . (CX + 24) . ',54 ' . (CX - 4) . ',50 Q' . (CX - 26) . ',56 ' . (CX - 32) . ',82 Z" fill="url(#hair)"/>';
    }

    return $svg;
}

function document_svg(string $title, string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . W . ' 860" width="' . W . '" height="860" role="img" aria-label="' . $title . '">'
        . "\n<!-- Generat de scripts/generate_equipment_silhouettes.php — nu edita manual. -->\n"
        . defs() . $body . '</svg>' . "\n";
}

$outDir = dirname(__DIR__) . '/htdocs/assets/img/echipamente';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    throw new RuntimeException('Nu pot crea ' . $outDir);
}

$files = [
    'sofer-m.svg' => document_svg('Șofer — echipament de lucru', driver('m')),
    'sofer-f.svg' => document_svg('Șoferiță — echipament de lucru', driver('f')),
    'tesa-m.svg' => document_svg('TESA — ținută de birou', tesa('m')),
    'tesa-f.svg' => document_svg('TESA — ținută de birou', tesa('f')),
];

foreach ($files as $name => $content) {
    file_put_contents($outDir . '/' . $name, $content);
    echo 'Scris: assets/img/echipamente/' . $name . ' (' . strlen($content) . " octeți)\n";
}
