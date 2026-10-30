<?php
/**
 * Kalıcı diskteki yüklenmiş kapak resimlerini sunar (cover.php?f=dosya.jpg)
 * cover.php?f=dosya.jpg&fmt=webp → WebP sürümü (ilk istekte üretilip diskte saklanır; <picture> ile kullanılır)
 */
require_once __DIR__ . '/config.php';

$name = basename($_GET['f'] ?? '');
$path = UPLOAD_DIR . '/' . $name;
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif'
];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

if ($name === '' || !isset($types[$ext]) || !is_file($path)) {
    http_response_code(404);
    exit;
}

if (($_GET['fmt'] ?? '') === 'webp' && in_array($ext, ['jpg', 'jpeg', 'png'], true) && function_exists('imagewebp')) {
    $webp = UPLOAD_DIR . '/webp/' . pathinfo($name, PATHINFO_FILENAME) . '.webp';
    if (!is_file($webp) || filemtime($webp) < filemtime($path)) {
        $im = @imagecreatefromstring((string)file_get_contents($path));
        if ($im) {
            if (!is_dir(dirname($webp))) {
                @mkdir(dirname($webp), 0775, true);
            }
            imagepalettetotruecolor($im);
            imagealphablending($im, true);
            imagesavealpha($im, true);
            @imagewebp($im, $webp, 80);
            imagedestroy($im);
        }
    }
    if (is_file($webp) && filesize($webp) > 0) {
        $path = $webp;
        $ext = 'webp';
    }
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable'); // dosya adları benzersiz, içerik değişmez
header('X-Content-Type-Options: nosniff');
readfile($path);
