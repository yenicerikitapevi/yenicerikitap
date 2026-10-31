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
// Site veritabanı (aynı ISBN'li kitap var mı, otomatik kayıt ayarı). Yüklenemezse yardımcı yine çalışır.
$ih_site_db = false;
try {
    require_once __DIR__ . '/../config.php';
    $ih_site_db = function_exists('db_fetch');
} catch (Throwable $e) {
    $ih_site_db = false;
}

ih_require_admin();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // uzun arama sırasında panelin diğer sayfalarını kilitlemesin
}

/** Bu ISBN sitede kayıtlı mı? */
function ih_existing_book($isbn)
{
    global $ih_site_db;
    if (!$ih_site_db) return null;
    try {
        $cands = [$isbn];
        if (strlen($isbn) === 13 && strpos($isbn, '978') === 0) {
            $core = substr($isbn, 3, 9);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) $sum += (10 - $i) * (int)$core[$i];
            $c = (11 - $sum % 11) % 11;
            $cands[] = $core . ($c === 10 ? 'X' : (string)$c);
        }
        $ph = implode(',', array_fill(0, count($cands), '?'));
        $b = db_fetch("SELECT id, title, slug FROM books WHERE REPLACE(REPLACE(UPPER(isbn), '-', ''), ' ', '') IN ($ph) ORDER BY id LIMIT 1", $cands);
        if (!$b) return null;
        return [
            'id' => (int)$b['id'], 'title' => $b['title'],
            'edit_url' => 'index.php?p=Products&action=edit&id=' . (int)$b['id'],
            'url' => function_exists('book_path') ? '/' . book_path($b) : null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

// Panelin okuduğu ayarlar
if (isset($_GET['config'])) {
    $cfg = ih_config();
    ih_json([
        'ok' => true,
        'autosave' => $ih_site_db && function_exists('setting') && setting('isbn_autosave', '0') === '1',
        'ai_enabled' => !empty($cfg['anthropic_api_key']) && is_file(dirname(__DIR__) . '/vendor/autoload.php'),
    ]);
}

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
        $c['existing'] = ih_existing_book($isbn);
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
$res['existing'] = ih_existing_book($isbn);
ih_json($res);
