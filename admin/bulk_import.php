<?php
/**
 * Excel'den Toplu Ekle – tek satırı kaydeder (admin/modules/bulkimport.php sayfası satırları tek tek gönderir).
 * POST: token, isbn, title, price, author, publisher, category_id, store_id, stock, is_active, lookup (1 = ISBN ile kapak/bilgi çek)
 * Çıktı: {ok, status: 'added'|'exists'|'error', id, title, cover, message}
 */
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(60);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/isbn_helper/lib.php';

function bi_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SESSION['admin_user']['role'] ?? '') !== 'admin') bi_out(['ok' => false, 'status' => 'error', 'message' => 'Oturum kapalı, yeniden giriş yapın.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['bulk_import_token']) || !hash_equals($_SESSION['bulk_import_token'], (string)($_POST['token'] ?? ''))) {
    bi_out(['ok' => false, 'status' => 'error', 'message' => 'Geçersiz istek. Sayfayı yenileyin.'], 403);
}
session_write_close(); // uzun aramalar panelin diğer sayfalarını kilitlemesin

$isbn = ih_normalize_isbn((string)($_POST['isbn'] ?? ''));
if (!$isbn) bi_out(['ok' => false, 'status' => 'error', 'message' => 'Geçersiz ISBN']);

// Aynı ISBN (tireli/ISBN-10 yazımları dahil) zaten kayıtlıysa atlanır
$cands = [$isbn];
if (strpos($isbn, '978') === 0) {
    $core = substr($isbn, 3, 9);
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += (10 - $i) * (int)$core[$i];
    $c = (11 - $sum % 11) % 11;
    $cands[] = $core . ($c === 10 ? 'X' : (string)$c);
}
$ph = implode(',', array_fill(0, count($cands), '?'));
$ex = db_fetch("SELECT id, title FROM books WHERE REPLACE(REPLACE(UPPER(isbn), '-', ''), ' ', '') IN ($ph) LIMIT 1", $cands);
if ($ex) bi_out(['ok' => true, 'status' => 'exists', 'id' => (int)$ex['id'], 'title' => $ex['title'], 'message' => 'Zaten kayıtlı']);

/** Excel'deki BÜYÜK HARF adları Türkçe kurallarıyla "Baş Harfleri Büyük" yapar */
function bi_title_case($s) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    $letters = preg_replace('/[^\p{L}]/u', '', $s);
    if ($letters === '' || $letters !== mb_strtoupper($letters, 'UTF-8')) return $s;
    $lower = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s), 'UTF-8');
    return preg_replace_callback('/(^|[\s\-\'’(.\/])(\p{L})/u', function ($m) {
        $u = $m[2] === 'i' ? 'İ' : ($m[2] === 'ı' ? 'I' : mb_strtoupper($m[2], 'UTF-8'));
        return $m[1] . $u;
    }, $lower);
}

/** Yazar/yayınevi adını bulur, yoksa oluşturur */
function bi_entity($table, $name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return null;
    $row = find_by_name($table, $name);
    if ($row) return (int)$row['id'];
    db_query("INSERT INTO {$table} (name, slug) VALUES (?, ?)", [$name, unique_slug($table, $name)]);
    return (int)db_last_id();
}

$x_title     = trim((string)($_POST['title'] ?? ''));
$x_author    = trim((string)($_POST['author'] ?? ''));
$x_publisher = trim((string)($_POST['publisher'] ?? ''));
$price       = (float)str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string)($_POST['price'] ?? '0')));
$category_id = (int)($_POST['category_id'] ?? 0) ?: null;
$store_id    = (int)($_POST['store_id'] ?? 0) ?: null;
$stock       = max(0, (int)($_POST['stock'] ?? 0));
$is_active   = !empty($_POST['is_active']) ? 1 : 0;

$d = null;
if (!empty($_POST['lookup'])) {
    ih_set_deadline(35);
    try { $d = ih_lookup($isbn); } catch (Throwable $e) { $d = null; }
    if (!$d || empty($d['ok'])) $d = null;
}

$title = ($d && !empty($d['title'])) ? $d['title'] : bi_title_case($x_title);
if ($title === '') bi_out(['ok' => false, 'status' => 'error', 'message' => 'Kitap adı yok ve ISBN ile bulunamadı']);
$author    = $x_author !== '' ? bi_title_case($x_author) : ($d['author'] ?? '');
$publisher = $x_publisher !== '' ? $x_publisher : ($d['publisher'] ?? '');
if ($price <= 0 && $d && !empty($d['suggested_price'])) $price = (float)$d['suggested_price'];
// Fiyatsız kitap (ör. listede "ÇIKACAK") satışa açılmaz: pasif eklenir, fiyatı girilince yayına alınır
$no_price = $price <= 0;
if ($no_price) $is_active = 0;

// Kapak: ISBN aramasının bulduğu adresler öncelik sırasıyla denenir, ilk uygun olan 600x900 JPEG olarak kaydedilir
$cover = 'book_placeholder.svg';
if ($d && !empty($d['covers']) && function_exists('imagecreatefromstring')) {
    $urls = [];
    foreach ($d['covers'] as $cv) {
        $u = is_array($cv) ? ($cv['url'] ?? '') : (string)$cv;
        if ($u !== '' && ih_public_url($u)) $urls[] = $u;
    }
    $reqs = [];
    foreach (array_slice(array_values(array_unique($urls)), 0, 4) as $i => $u) {
        $p = parse_url($u);
        $reqs['c' . $i] = ['url' => $u, 'headers' => ['Referer: ' . $p['scheme'] . '://' . $p['host'] . '/', 'Accept: image/*,*/*;q=0.8']];
    }
    $got = $reqs ? ih_fetch_many($reqs) : [];
    foreach ($reqs as $k => $_) {
        $r = $got[$k] ?? null;
        if (!$r || $r['code'] !== 200 || strlen($r['body']) > 15 * 1024 * 1024) continue;
        $im = ih_load_image($r['body']);
        if (!$im) continue;
        $w = imagesx($im); $h = imagesy($im);
        if ($w < 150 || $h < 200) { imagedestroy($im); continue; }
        $out = ih_to_600x900($im);
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
        $name = 'cover_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (imagejpeg($out, UPLOAD_DIR . '/' . $name, 85)) $cover = $name;
        break;
    }
}

db_query("INSERT INTO books (title, original_title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active, store_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)", [
    $title, $d['original_title'] ?? null, $isbn, bi_entity('authors', $author), bi_entity('publishers', $publisher), $category_id,
    $price, $stock, ($d['pages'] ?? null) ?: null, ($d['year'] ?? null) ?: null, $d['summary'] ?? null, $cover, $is_active, $store_id,
]);
$book_id = (int)db_last_id();
book_refresh_slug($book_id);
book_touch($book_id);
log_action('Excel\'den Kitap Eklendi', 'Products', $book_id, $title . ' (' . $isbn . ')');

bi_out([
    'ok' => true, 'status' => 'added', 'id' => $book_id, 'title' => $title,
    'cover' => $cover !== 'book_placeholder.svg', 'found' => (bool)$d,
    'message' => ($d ? 'Eklendi' . ($cover !== 'book_placeholder.svg' ? ' (kapaklı)' : ' (kapak bulunamadı)') : 'Eklendi (ISBN ile bilgi bulunamadı, Excel bilgileri kullanıldı)')
        . ($no_price ? ' — fiyat yok, PASİF eklendi' : ''),
]);
