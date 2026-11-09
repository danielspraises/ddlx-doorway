<?php
declare(strict_types=1);

/**
 * Small QR code generator (byte mode, error correction level M, versions 1 to 10).
 * Used to show the two-step sign-in setup code. It runs on the server, so the secret never reaches a third party.
 */

// version => [ecc codewords per block, [[block count, data codewords per block], ...]]
const QR_M = [
    1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
    6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
];
const QR_ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

function qr_gf(): array
{
    static $t = null;
    if ($t) return $t;
    $exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 256) $x ^= 0x11D; }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = [$exp, $log];
}

function qr_ecc(array $data, int $n): array
{
    [$exp, $log] = qr_gf();
    $gen = [1];
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $c) {
            $next[$j] ^= $c;
            $next[$j + 1] ^= $c === 0 ? 0 : $exp[$log[$c] + $i];
        }
        $gen = $next;
    }
    $rem = array_fill(0, $n, 0);
    foreach ($data as $d) {
        $f = $d ^ array_shift($rem);
        $rem[] = 0;
        if ($f !== 0) for ($j = 0; $j < $n; $j++) $rem[$j] ^= $exp[$log[$gen[$j + 1]] + $log[$f]];
    }
    return $rem;
}

function qr_bch(int $v, int $poly, int $bits): int
{
    $deg = strlen(decbin($poly)) - 1;
    $r = $v << $deg;
    for ($i = $bits + $deg - 1; $i >= $deg; $i--) if (($r >> $i) & 1) $r ^= $poly << ($i - $deg);
    return ($v << $deg) | $r;
}

/** @return array<int, array<int, bool>>|null  rows of dark (true) and light (false) modules, or null when the text is too long */
function qr_matrix(string $text): ?array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $ver = 0;
    foreach (QR_M as $v => [$ec, $groups]) {
        $cap = 0; foreach ($groups as [$n, $k]) $cap += $n * $k;
        $lenBits = $v < 10 ? 8 : 16;
        if (4 + $lenBits + 8 * count($bytes) <= $cap * 8) { $ver = $v; break; }
    }
    if ($ver === 0) return null;
    [$eccLen, $groups] = QR_M[$ver];
    $cap = 0; foreach ($groups as [$n, $k]) $cap += $n * $k;

    // Data bits: mode, length, bytes, terminator, padding.
    $bits = '0100' . str_pad(decbin(count($bytes)), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $words = array_map('bindec', str_split($bits, 8));
    for ($pad = 0; count($words) < $cap; $pad++) $words[] = $pad % 2 === 0 ? 0xEC : 0x11;

    // Split into blocks, add error correction, interleave.
    $blocks = []; $eccs = []; $pos = 0;
    foreach ($groups as [$n, $k]) for ($i = 0; $i < $n; $i++) { $b = array_slice($words, $pos, $k); $pos += $k; $blocks[] = $b; $eccs[] = qr_ecc($b, $eccLen); }
    $out = [];
    $maxK = max(array_map('count', $blocks));
    for ($i = 0; $i < $maxK; $i++) foreach ($blocks as $b) if (isset($b[$i])) $out[] = $b[$i];
    for ($i = 0; $i < $eccLen; $i++) foreach ($eccs as $e) $out[] = $e[$i];
    $stream = '';
    foreach ($out as $w) $stream .= str_pad(decbin($w), 8, '0', STR_PAD_LEFT);

    $size = 17 + 4 * $ver;
    $best = null; $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = qr_build($ver, $size, $stream, $mask);
        $s = qr_penalty($m, $size);
        if ($s < $bestScore) { $bestScore = $s; $best = $m; }
    }
    return $best;
}

function qr_build(int $ver, int $size, string $stream, int $mask): array
{
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = array_fill(0, $size, array_fill(0, $size, false)); // reserved for function patterns
    $set = function (int $r, int $c, bool $dark) use (&$m, &$fn, $size) { if ($r >= 0 && $c >= 0 && $r < $size && $c < $size) { $m[$r][$c] = $dark; $fn[$r][$c] = true; } };

    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
            $dark = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6 && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
            $set($r0 + $r, $c0 + $c, $dark);
        }
    }
    for ($i = 8; $i < $size - 8; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    $al = QR_ALIGN[$ver];
    foreach ($al as $r) foreach ($al as $c) {
        $last = $al[count($al) - 1];
        if (($r === 6 && $c === 6) || ($r === 6 && $c === $last) || ($r === $last && $c === 6)) continue; // would sit on a finder pattern
        for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) $set($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1);
    }
    $set($size - 8, 8, true); // the always-dark module

    // Reserve format and version areas, filled in below.
    for ($i = 0; $i < 9; $i++) { if (!$fn[8][$i]) $fn[8][$i] = true; if (!$fn[$i][8]) $fn[$i][8] = true; }
    for ($i = 0; $i < 8; $i++) { $fn[8][$size - 1 - $i] = true; $fn[$size - 1 - $i][8] = true; }
    if ($ver >= 7) for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) { $fn[$i][$size - 11 + $j] = true; $fn[$size - 11 + $j][$i] = true; }

    // Data, zigzag from the bottom right, skipping the timing column.
    $i = 0; $n = strlen($stream); $up = true;
    for ($c = $size - 1; $c > 0; $c -= 2) {
        if ($c === 6) $c--;
        for ($k = 0; $k < $size; $k++) {
            $r = $up ? $size - 1 - $k : $k;
            for ($d = 0; $d < 2; $d++) {
                $cc = $c - $d;
                if ($fn[$r][$cc]) continue;
                $bit = $i < $n ? $stream[$i] === '1' : false; $i++;
                $flip = match ($mask) { 0 => ($r + $cc) % 2 === 0, 1 => $r % 2 === 0, 2 => $cc % 3 === 0, 3 => ($r + $cc) % 3 === 0, 4 => (intdiv($r, 2) + intdiv($cc, 3)) % 2 === 0, 5 => ($r * $cc) % 2 + ($r * $cc) % 3 === 0, 6 => (($r * $cc) % 2 + ($r * $cc) % 3) % 2 === 0, default => (($r + $cc) % 2 + ($r * $cc) % 3) % 2 === 0 };
                $m[$r][$cc] = $bit !== $flip;
            }
        }
        $up = !$up;
    }

    // Format information (level M = 00) with the mask number.
    $fmt = qr_bch($mask, 0x537, 5) ^ 0x5412;
    for ($k = 0; $k < 15; $k++) {
        $bit = (($fmt >> $k) & 1) === 1;
        if ($k < 6) $m[$k][8] = $bit; elseif ($k === 6) $m[7][8] = $bit; elseif ($k === 7) $m[8][8] = $bit; elseif ($k === 8) $m[8][7] = $bit; else $m[8][14 - $k] = $bit;
        if ($k < 8) $m[8][$size - 1 - $k] = $bit; else $m[$size - 15 + $k][8] = $bit;
    }
    if ($ver >= 7) {
        $info = qr_bch($ver, 0x1F25, 6);
        for ($k = 0; $k < 18; $k++) { $bit = (($info >> $k) & 1) === 1; $m[intdiv($k, 3)][$size - 11 + $k % 3] = $bit; $m[$size - 11 + $k % 3][intdiv($k, 3)] = $bit; }
    }
    return $m;
}

function qr_penalty(array $m, int $size): int
{
    $p = 0;
    for ($pass = 0; $pass < 2; $pass++) {
        for ($a = 0; $a < $size; $a++) {
            $run = 1;
            for ($b = 1; $b < $size; $b++) {
                $cur = $pass === 0 ? $m[$a][$b] : $m[$b][$a]; $prev = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];
                if ($cur === $prev) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; } else $run = 1;
            }
        }
    }
    for ($r = 0; $r < $size - 1; $r++) for ($c = 0; $c < $size - 1; $c++) if ($m[$r][$c] === $m[$r][$c + 1] && $m[$r][$c] === $m[$r + 1][$c] && $m[$r][$c] === $m[$r + 1][$c + 1]) $p += 3;
    $pat = [true, false, true, true, true, false, true, false, false, false, false];
    for ($a = 0; $a < $size; $a++) for ($b = 0; $b <= $size - 11; $b++) {
        $h1 = $h2 = $v1 = $v2 = true;
        for ($k = 0; $k < 11; $k++) {
            if ($m[$a][$b + $k] !== $pat[$k]) $h1 = false; if ($m[$a][$b + $k] !== $pat[10 - $k]) $h2 = false;
            if ($m[$b + $k][$a] !== $pat[$k]) $v1 = false; if ($m[$b + $k][$a] !== $pat[10 - $k]) $v2 = false;
        }
        $p += 40 * ((int) $h1 + (int) $h2 + (int) $v1 + (int) $v2);
    }
    $dark = 0; foreach ($m as $row) foreach ($row as $v) if ($v) $dark++;
    return $p + 10 * (int) (abs($dark * 100 / ($size * $size) - 50) / 5);
}

/** The code as an inline SVG (dark modules on white with the required quiet zone). */
function qr_svg(string $text, string $label = 'QR code'): string
{
    $m = qr_matrix($text);
    if (!$m) return '';
    $n = count($m); $q = 4; $d = '';
    foreach ($m as $r => $row) {
        $c = 0;
        while ($c < $n) {
            if (!$row[$c]) { $c++; continue; }
            $start = $c; while ($c < $n && $row[$c]) $c++;
            $d .= 'M' . ($start + $q) . ' ' . ($r + $q) . 'h' . ($c - $start) . 'v1h-' . ($c - $start) . 'z';
        }
    }
    $t = $n + 2 * $q;
    return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges"><rect width="' . $t . '" height="' . $t . '" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
}
