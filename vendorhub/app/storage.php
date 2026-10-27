<?php
declare(strict_types=1);

// Files live on disk outside the web root. They are served only through scripts that check who is asking.
final class Storage
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    public const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    public static function root(): string
    {
        $dir = (string) cfg('storage_dir', APP_ROOT . '/storage');
        return rtrim($dir, '/\\');
    }

    // <seller id>/<verification id>/<kind>-<file id>.<ext>
    public static function verificationRe(): string { return '#^(' . UUID_RE . ')/(' . UUID_RE . ')/[a-z_]+-' . UUID_RE . '\.(jpg|png|webp|pdf)$#'; }
    // <seller id>/<product id>/<image id>.<ext>
    public static function productRe(): string { return '#^(' . UUID_RE . ')/(' . UUID_RE . ')/' . UUID_RE . '\.(jpg|png|webp)$#'; }
    // <seller id>/<image id>.<ext>
    public static function storeRe(): string { return '#^(' . UUID_RE . ')/' . UUID_RE . '\.(jpg|png|webp)$#'; }

    private static function full(string $area, string $relative): string
    {
        if (!in_array($area, ['verification', 'products', 'stores'], true) || str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new RuntimeException('Invalid file path.');
        }
        return self::root() . '/' . $area . '/' . $relative;
    }

    public static function save(string $area, string $relative, string $bytes): void
    {
        $target = self::full($area, $relative);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the storage folder.');
        }
        if (file_put_contents($target, $bytes) === false) {
            throw new RuntimeException('Could not save the file.');
        }
    }

    public static function read(string $area, string $relative): ?string
    {
        $p = self::full($area, $relative);
        $b = is_file($p) ? file_get_contents($p) : false;
        return $b === false ? null : $b;
    }

    public static function remove(string $area, string $relative): void
    {
        try {
            $p = self::full($area, $relative);
            if (is_file($p)) {
                @unlink($p);
            }
        } catch (Throwable) {
        }
    }

    // The browser-reported type is not trusted: the file's own first bytes decide.
    public static function sniff(string $bytes): ?string
    {
        if (strlen($bytes) < 12) return null;
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
        if (str_starts_with($bytes, '%PDF-')) return 'application/pdf';
        return null;
    }

    // Re-encodes an image (drops hidden data, caps the size to save space). Falls back to the original without GD.
    public static function cleanImage(string $bytes, string $mime, int $maxDim = 2400): string
    {
        if (!function_exists('imagecreatefromstring') || !in_array($mime, self::IMAGE_TYPES, true)) {
            return $bytes;
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return $bytes;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > $maxDim || $h > $maxDim) {
            $scale = $maxDim / max($w, $h);
            $resized = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($resized) {
                $img = $resized;
            }
        }
        ob_start();
        $ok = match ($mime) {
            'image/png' => (function () use ($img) { imagesavealpha($img, true); return imagepng($img, null, 6); })(),
            'image/webp' => function_exists('imagewebp') ? imagewebp($img, null, 85) : false,
            default => imagejpeg($img, null, 85),
        };
        $out = (string) ob_get_clean();
        return $ok && $out !== '' ? $out : $bytes;
    }

    // Reads one uploaded file from $_FILES-style entry. Returns [bytes, mime] or an error string.
    public static function fromUpload(array $file, array $allowed, int $maxBytes): array|string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Choose a file first.';
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') && !defined('VH_TESTING')) {
            return ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'That file is too large for the server.' : 'The upload failed. Please try again.';
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
            return 'The file is too large (limit ' . (int) ($maxBytes / 1048576) . ' MB).';
        }
        $bytes = file_get_contents($file['tmp_name']);
        $mime = $bytes === false ? null : self::sniff($bytes);
        if ($mime === null || !in_array($mime, $allowed, true)) {
            return 'This file type is not allowed.';
        }
        return [$bytes, $mime];
    }

    public static function send(string $bytes, string $mime, bool $public, bool $inline = true): never
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($public ? 'public, max-age=3600' : 'private, no-store'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment'));
        echo $bytes;
        exit;
    }
}
