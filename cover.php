<?php
/**
 * Kalıcı diskteki yüklenmiş kapak resimlerini sunar (cover.php?f=dosya.jpg)
 */
require_once __DIR__ . '/config.php';

$name = basename($_GET['f'] ?? '');
$path = UPLOAD_DIR . '/' . $name;
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

if ($name === '' || !isset($types[$ext]) || !is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable'); // dosya adları benzersiz, içerik değişmez
header('X-Content-Type-Options: nosniff');
readfile($path);
