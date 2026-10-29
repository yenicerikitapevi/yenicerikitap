<?php
/**
 * ISBN Yardımcısı – kitap bilgisi + en uygun fiyat.
 * Kullanım: /admin/isbn_lookup.php?isbn=9789750719387   (yalnızca giriş yapmış yönetici)
 * Çıktı: JSON (title, original_title, author, publisher, pages, year, summary, cover_url, prices[], cheapest ...)
 */
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(60);
require __DIR__ . '/isbn_helper/lib.php';

ih_require_admin();

if (!function_exists('curl_init') || !class_exists('DOMDocument')) {
    ih_json(['ok' => false, 'error' => 'Sunucuda php-curl veya php-xml (dom) eklentisi kapalı.'], 500);
}

$raw = isset($_GET['isbn']) ? (string)$_GET['isbn'] : '';
$isbn = ih_normalize_isbn($raw);
if (!$isbn) {
    ih_json(['ok' => false, 'error' => 'Geçersiz ISBN (13 haneli ISBN-13 ya da ISBN-10 girin).'], 400);
}

// 10 dakikalık önbellek (aynı kitabı tekrar tekrar taramamak için)
$cacheFile = sys_get_temp_dir() . '/ih_lookup_' . $isbn . '.json';
if (empty($_GET['nocache']) && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 600) {
    $c = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($c)) {
        $c['cached'] = true;
        ih_json($c);
    }
}

try {
    $res = ih_lookup($isbn);
} catch (Exception $e) {
    ih_json(['ok' => false, 'error' => 'Arama sırasında hata: ' . (ih_config()['debug'] ? $e->getMessage() : 'beklenmeyen hata')], 500);
}
if (!empty($res['ok'])) {
    @file_put_contents($cacheFile, json_encode($res, JSON_UNESCAPED_UNICODE));
}
ih_json($res);
