<?php
/**
 * YENİÇERİ KİTABEVİ - Alt Dükkan & NadirKitap Aktarım API Uç Noktası
 * Desteklenen rotalar:
 *   POST /api/nkapp/conn
 *   GET  /api/nkapp/store-info
 *   GET  /api/nkapp/existing-product-codes
 *   POST /api/nkapp/pbatch/{storeCode}/start
 *   POST /api/nkapp/pbatch/{storeCode}/{productType}/{page}
 *   POST /api/nkapp/pbatch/{storeCode}/finish
 *   POST /api/nkapp/pbatch/{storeCode}/error-report
 *   POST /api/nkapp/client-logs
 */
// Uygulama yalnızca JSON okur: PHP uyarıları/hataları yanıta karışmasın (sunucu loguna yazılır).
// Araya giren her çıktı tamponda tutulur ve JSON gönderilmeden önce atılır; ölümcül hatada da JSON döner.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) && empty($GLOBALS['nk_json_sent'])) {
        while (ob_get_level()) ob_end_clean();
        error_log('[nkapp] Ölümcül hata: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')');
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'messageList' => ['Sunucu hatası: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'],
            // Uygulama hata penceresinde 'error' alanını gösteriyor: gerçek sebep orada görünsün
            'success' => false, 'payload' => null, 'error' => 'Sunucu hatası: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');

function nk_json_response($payload = null, $success = true, $messages = [], $error = null, $status = 200) {
    // Yanıttan önce basılmış istenmeyen çıktı (uyarı, boşluk, BOM) atılır ve loga yazılır
    $stray = '';
    while (ob_get_level()) $stray .= (string)ob_get_clean();
    if (trim($stray) !== '') {
        error_log('[nkapp] JSON öncesi çıktı atıldı: ' . mb_substr(trim(strip_tags($stray)), 0, 500, 'UTF-8'));
    }
    $GLOBALS['nk_json_sent'] = true;
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode([
        'messageList' => array_values($messages),
        'success'     => (bool)$success,
        'payload'     => $payload,
        'error'       => $error
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Rota tespiti (.htaccess ?route=... veya REQUEST_URI üzerinden)
$route = $_GET['route'] ?? '';
if ($route === '') {
    $uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (preg_match('#/api/nkapp(?:/index\.php)?(/.*)?$#i', $uri_path, $m)) {
        $route = $m[1] ?? '/';
    } else {
        $route = $uri_path;
    }
}
$route = '/' . ltrim($route, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// İstemci logları her zaman kabul edilir
if ($route === '/client-logs' || strpos($route, '/client-logs') === 0) {
    // Uygulamanın kendi günlüğü: panelde (Disk Kullanımı → Aktarım günlüğü) görmek için diske yazılır
    try {
        $in = json_decode((string)file_get_contents('php://input'), true);
        $entries = is_array($in['entries'] ?? null) ? $in['entries'] : [];
        $who = '';
        $hk = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
        if (stripos($hk, 'YNCAUTH:') === 0) {
            $j = json_decode((string)base64_decode(strtr(substr($hk, 8), '-_', '+/')), true);
            $who = is_array($j) ? (string)($j['username'] ?? '') : '';
        }
        $who = preg_replace('/[^\p{L}\p{N}_.@-]/u', '', $who) ?: '?';
        $ver = preg_replace('/[^0-9A-Za-z._-]/', '', (string)($in['appVersion'] ?? ''));
        $dir = nk_client_log_dir();
        if ($entries && (is_dir($dir) || @mkdir($dir, 0775, true))) {
            $file = $dir . '/' . date('Ymd') . '.log';
            if (@filesize($file) < 15 * 1024 * 1024) {
                $out = '';
                foreach (array_slice($entries, 0, 2000) as $e) {
                    if (!is_array($e)) continue;
                    $ts = (int)(($e['timestamp'] ?? 0) / 1000) ?: time();
                    $msg = str_replace(["\r", "\n"], ' ', mb_substr((string)($e['message'] ?? ''), 0, 1000));
                    $st = trim((string)($e['stackTrace'] ?? ''));
                    if ($st !== '') $msg .= ' | ' . str_replace(["\r", "\n", "\t"], ' ', mb_substr($st, 0, 600));
                    $out .= date('d.m H:i:s', $ts) . ' ' . str_pad(substr((string)($e['level'] ?? ''), 0, 5), 5) . " [{$who} v{$ver}] " . $msg . "\n";
                }
                @file_put_contents($file, $out, FILE_APPEND | LOCK_EX);
            }
            foreach (glob($dir . '/*.log') ?: [] as $old) {
                if (@filemtime($old) < time() - 4 * 86400) @unlink($old);
            }
        }
    } catch (Throwable $e) {
    }
    nk_json_response(null, true);
}

// Gövdeyi JSON olarak oku
$raw_body = file_get_contents('php://input');
$body = $raw_body ? json_decode($raw_body, true) : [];
if (!is_array($body)) {
    $body = [];
}

// Kimlik doğrulama: X-Api-Key (YNCAUTH:<base64_json> veya username:password:nkInput) veya JSON gövdesi
$api_key = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
$nk_input = trim((string)($body['nkInput'] ?? $body['nadirkitap'] ?? ''));
$force_update_flag = !empty($body['forceUpdate']) || !empty($_GET['force']);

if (stripos($api_key, 'YNCAUTH:') === 0) {
    $b64 = substr($api_key, 8);
    $b64_std = strtr($b64, '-_', '+/');
    $pad = strlen($b64_std) % 4;
    if ($pad > 0) {
        $b64_std .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($b64_std, true);
    if ($decoded !== false) {
        $auth_data = json_decode($decoded, true);
        if (is_array($auth_data)) {
            $username          = trim((string)($auth_data['username'] ?? $username));
            $password          = (string)($auth_data['password'] ?? $password);
            $nk_input          = trim((string)($auth_data['nkInput'] ?? $nk_input));
            $force_update_flag = $force_update_flag || !empty($auth_data['forceUpdate']);
        }
    }
} elseif ($username === '' && strpos($api_key, ':') !== false) {
    $parts = explode(':', $api_key, 3);
    $username = trim($parts[0] ?? '');
    $password = $parts[1] ?? '';
    if (isset($parts[2])) {
        $nk_input = trim($parts[2]);
    }
}

$authenticated_store = null;
$store_code = '';

if ($username !== '') {
    $store_row = db_fetch("SELECT * FROM stores WHERE LOWER(username) = LOWER(?)", [$username]);
    if (!$store_row) {
        nk_json_response(null, false, ['Kullanıcı adı bulunamadı. Lütfen kullanıcı adınızı kontrol edin.'], 'INVALID_CREDENTIALS', 401);
    }
    if (($store_row['approval_status'] ?? 'approved') !== 'approved') {
        nk_json_response(null, false, ['Sahaf üyelik başvurunuz henüz onaylanmadı. Onaylandıktan sonra kitap aktarabilirsiniz.'], 'STORE_NOT_APPROVED', 403);
    }
    if ((int)$store_row['is_active'] !== 1) {
        nk_json_response(null, false, ['Bu alt dükkan hesabı şu anda pasif durumdadır.'], 'STORE_INACTIVE', 403);
    }
    $pass_ok = password_verify($password, $store_row['password_hash']) || ($store_row['plain_hint'] !== null && $store_row['plain_hint'] !== '' && hash_equals((string)$store_row['plain_hint'], $password));
    if (!$pass_ok) {
        nk_json_response(null, false, ['Şifre hatalı. Lütfen şifrenizi kontrol edin.'], 'INVALID_CREDENTIALS', 401);
    }
    $authenticated_store = $store_row;

    $extracted = extract_nadirkitap_code($nk_input);
    if ($extracted !== '') {
        $store_code = $extracted;
        $nk_url = (stripos($nk_input, 'http') === 0) ? $nk_input : ($store_row['nadirkitap_url'] ?: "https://www.nadirkitap.com/kitapara.php?satici={$store_code}");
        if ($store_row['nadirkitap_code'] !== $store_code || ($nk_input !== '' && stripos($nk_input, 'http') === 0 && $store_row['nadirkitap_url'] !== $nk_input)) {
            db_query("UPDATE stores SET nadirkitap_code = ?, nadirkitap_url = ? WHERE id = ?", [$store_code, $nk_url, $store_row['id']]);
            $authenticated_store['nadirkitap_code'] = $store_code;
            $authenticated_store['nadirkitap_url']  = $nk_url;
        }
    } else {
        $store_code = trim((string)($store_row['nadirkitap_code'] ?? ''));
    }

    if ($store_code === '') {
        nk_json_response(null, false, ['Lütfen NadirKitap mağaza kodunuzu (örn: 348635) veya mağaza bağlantınızı (URL) girin.'], 'MISSING_NK_CODE', 400);
    }
} else {
    // Geriye dönük anahtar desteği (varsayılan ilk alt dükkan). Yalnızca panelde açıkça tanımlanmış anahtar kabul edilir;
    // mağaza kodu herkese açık olduğundan kod veya koddan türetilen sabit anahtarlar kimlik doğrulama sayılmaz.
    $configured_key = trim((string)setting('nk_api_key', ''));
    $default_store  = setting('nk_store_code', '348635');
    $key_ok = $configured_key !== '' && strlen($configured_key) >= 16 && $api_key !== '' && hash_equals($configured_key, $api_key);

    if ($key_ok) {
        $authenticated_store = db_fetch("SELECT * FROM stores ORDER BY id ASC LIMIT 1");
        $store_code = $authenticated_store['nadirkitap_code'] ?? $default_store;
    } else {
        nk_json_response(null, false, ['Lütfen Kullanıcı Adı ve Şifreniz ile giriş yapın.'], 'UNAUTHORIZED', 401);
    }
}

$auth_store_id   = $authenticated_store ? (int)$authenticated_store['id'] : null;
$auth_store_name = $authenticated_store['name'] ?? 'Alt Dükkan';
$auth_store_slug = $authenticated_store['slug'] ?? '';

// 1. POST /conn -> NadirKitap Mağaza Kodu döndürür
if ($route === '/conn') {
    nk_json_response(
        (string)$store_code,
        true,
        ["{$auth_store_name} alt dükkanına başarıyla bağlanıldı (NK Mağaza Kodu: {$store_code})."]
    );
}

// 1b. GET /store-info -> Masaüstü uygulaması için Alt Dükkan detaylarını döndürür
if ($route === '/store-info') {
    $book_cnt = $auth_store_id ? (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE store_id = ?", [$auth_store_id])['cnt'] ?? 0) : 0;
    nk_json_response([
        'storeId'     => $auth_store_id,
        'storeName'   => $auth_store_name,
        'storeSlug'   => $auth_store_slug,
        'storeUrl'    => SITE_URL . '/magaza.php?s=' . urlencode($auth_store_slug),
        'nkStoreCode' => (string)$store_code,
        'bookCount'   => $book_cnt
    ], true);
}

// 2. GET /existing-product-codes -> Mükerrer kontrolü için bu alt dükkandaki mevcut NadirKitap ürün kodlarını döndürür
if ($route === '/existing-product-codes') {
    $force_reupload = $force_update_flag || setting('nk_force_update', '0') === '1';
    if ($force_reupload) {
        nk_json_response([], true);
    }
    if ($auth_store_id) {
        $rows = db_fetch_all("SELECT nadirkitap_code, cover_image FROM books WHERE store_id = ? AND nadirkitap_code IS NOT NULL AND nadirkitap_code != ''", [$auth_store_id]);
    } else {
        $rows = db_fetch_all("SELECT nadirkitap_code, cover_image FROM books WHERE nadirkitap_code IS NOT NULL AND nadirkitap_code != ''");
    }
    // "Görselleri yenile" sonrası: fotoğrafı o tarihten eski kitaplar listeye konmaz, uygulama onları yeniden gönderir
    $refresh_at = (int)setting('nk_image_refresh_at', '0');
    $codes = [];
    foreach ($rows as $r) {
        $c = trim((string)$r['nadirkitap_code']);
        if ($refresh_at > 0) {
            $cv = basename((string)$r['cover_image']);
            if ($cv !== '' && $cv !== 'book_placeholder.svg' && !preg_match('#^https?://#i', (string)$r['cover_image'])) {
                $mt = @filemtime(UPLOAD_DIR . '/' . $cv);
                if ($mt !== false && $mt < $refresh_at) continue;
            }
        }
        if ($c !== '') {
            $codes[] = $c;
        }
    }
    nk_json_response(array_values(array_unique($codes)), true);
}

// 3. POST /pbatch/{storeCode}/start
if (preg_match('#^/pbatch/([^/]+)/start$#i', $route, $m)) {
    $page_spec = trim((string)($body['pageSpec'] ?? '*'));
    $start_page = 1;
    if (preg_match('/^(\d+)/', $page_spec, $pm)) {
        $start_page = max(1, (int)$pm[1]);
    }
    $run_id = 'ync_' . ($auth_store_id ?: 0) . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
    // "Yeniden güncelle" açıkken uygulama her kitabı gönderir: görülen kodlar satılanları düşürmek için kaydedilir
    nk_run_start($run_id, $auth_store_id, $force_update_flag || setting('nk_force_update', '0') === '1', $page_spec, (string)($body['productType'] ?? ''));
    nk_json_response([
        'runId'                 => $run_id,
        'batchStatus'           => 'ALL',
        'maxBookProductCode'    => null,
        'maxNonBookProductCode' => null,
        'productStatus'         => 'ALL',
        'startPage'             => $start_page,
        'blockedCategories'     => [],
        // NadirKitap çok sık istekte bilgisayarı geçici engelliyor (Cloudflare 429): istekler arası bekleme
        'requestIntervalMs'     => max(800, (int)setting('nk_request_interval_ms', '2000'))
    ], true);
}

// 4. POST /pbatch/{storeCode}/finish
if (preg_match('#^/pbatch/([^/]+)/finish$#i', $route, $m)) {
    $total = (int)($body['totalProducts'] ?? 0);
    $run_id = sanitize($body['runId'] ?? '');
    nk_run_finish($run_id, $auth_store_id, $total, (string)($body['error'] ?? ''));
    log_action('Alt Dükkan NadirKitap Aktarımı', 'Stores', $auth_store_id, "Alt Dükkan: {$auth_store_name}, RunID: {$run_id}, Aktarılan Ürün: {$total}");
    nk_json_response(null, true, ["{$auth_store_name} alt dükkanına toplam {$total} ürün başarıyla aktarıldı."]);
}

// 4b. POST /sync-sold veya POST /pbatch/{storeCode}/sync-sold -> Satılan kitapları tespit edip düşürür
if ($route === '/sync-sold' || preg_match('#^/pbatch/([^/]+)/sync-sold$#i', $route)) {
    $active_codes = $body['activeCodes'] ?? $body['activeProductCodes'] ?? [];
    if (!is_array($active_codes) || empty($active_codes)) {
        // İstemci kod göndermedi: önce son tam aktarımlara bakılır (NadirKitap sunucudan taramayı Cloudflare ile engelliyor)
        $unseen = nk_unseen_candidates($auth_store_id);
        if ($unseen['safe']) {
            $n = nk_drop_unseen($auth_store_id, array_column($unseen['candidates'], 'id'));
            nk_json_response(['droppedCount' => $n], true, ["{$auth_store_name}: NadirKitap'ta artık görünmeyen {$n} kitap satıştan kaldırıldı. ({$unseen['note']})"]);
        }
        if ($unseen['runs'] && $unseen['complete'] && !$unseen['candidates']) {
            nk_json_response(['droppedCount' => 0], true, ["{$auth_store_name}: satılmış kitap yok, sitedeki tüm NadirKitap kitapları son aktarımda görüldü. ({$unseen['note']})"]);
        }
        if ($unseen['runs']) {
            $extra = $unseen['candidates'] ? ' Görünmeyen ' . count($unseen['candidates']) . ' kitap Satıcı Paneli\'nde onayınızı bekliyor.' : '';
            nk_json_response(['droppedCount' => 0], true, ["Satılanlar düşürülmedi: {$unseen['note']}{$extra}"]);
        }
        // Hiç tam aktarım yoksa eski yöntem: sunucudan tarama (Cloudflare engellerse açıklama döner)
        $res = nk_sync_store($auth_store_id, 40);
        if (!empty($res['is_cloudflare'])) {
            $res['message'] = 'NadirKitap sunucudan taramayı engelliyor. Satılanları düşürmek için önce "Daha önce aktarılmış kitapları da yeniden güncelle" işaretli bir aktarım yapın (250\'den fazla kitabınız varsa sonraki sayfalardan devam ederek), sonra bu düğmeye tekrar basın.';
        }
        nk_json_response($res, $res['success'], [$res['message'] ?? '']);
    } else {
        $dropped = nk_drop_sold_books($auth_store_id, $active_codes);
        $count = count($dropped);
        log_action('Satılan Kitapları Düşürme (API)', 'Stores', $auth_store_id, "{$auth_store_name}: {$count} adet satılan kitap düşürüldü.");
        nk_json_response([
            'droppedCount' => $count,
            'droppedBooks' => $dropped
        ], true, ["{$auth_store_name} alt dükkanından {$count} adet satılan kitap başarıyla düşürüldü."]);
    }
}

// 5. POST /pbatch/{storeCode}/error-report
if (preg_match('#^/pbatch/([^/]+)/error-report$#i', $route, $m)) {
    // Uygulama bunu yalnızca NadirKitap (Cloudflare) istekleri sınırladığında gönderir; panel günlüğüne yazılır
    try {
        $dir = nk_client_log_dir();
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            $f = fn($k) => str_replace(["\r", "\n"], ' ', mb_substr((string)($body[$k] ?? ''), 0, 500));
            $line = date('d.m H:i:s') . ' HATA  [' . ($username !== '' ? preg_replace('/[^\p{L}\p{N}_.@-]/u', '', $username) : '?') . '] '
                . 'NADİRKİTAP ERİŞİMİ SINIRLADI (' . $f('errorType') . ') HTTP ' . $f('httpStatus') . ', Retry-After: ' . ($f('retryAfterHeader') ?: '-')
                . ', istek sayısı: ' . $f('totalRequestCount') . ', adres: ' . $f('url') . ' | ' . $f('message') . ' ' . $f('details') . "\n";
            @file_put_contents($dir . '/' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $e) {
    }
    nk_json_response(null, true);
}

// Yardımcı fonksiyonlar: Yazar / Yayınevi / Kategori çözümleme ve oluşturma
function nk_resolve_entity($table, $name, &$cache) {
    $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($clean === '' || $clean === '-') {
        if ($table === 'authors') $clean = 'Kolektif / Belirtilmemiş';
        elseif ($table === 'publishers') $clean = 'Belirtilmemiş';
        else $clean = 'Genel / Sahaf';
    }
    if (mb_strlen($clean, 'UTF-8') > 115) {
        $clean = mb_substr($clean, 0, 115, 'UTF-8');
    }
    $key = tr_lower($clean);
    if (isset($cache[$table][$key])) {
        return (int)$cache[$table][$key];
    }
    $existing = find_by_name($table, $clean);
    if ($existing) {
        $id = (int)$existing['id'];
        $cache[$table][$key] = $id;
        return $id;
    }
    $slug = unique_slug($table, $clean);
    db_query("INSERT INTO {$table} (name, slug) VALUES (?, ?)", [$clean, $slug]);
    $id = (int)db_last_id();
    $cache[$table][$key] = $id;
    return $id;
}

function nk_parse_price($val) {
    $s = trim((string)$val);
    $s = str_ireplace(['TL', '₺', ' '], '', $s);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    $num = (float)preg_replace('/[^0-9.]/', '', $s);
    return max(0.0, round($num, 2));
}

function nk_clean_description($html) {
    $text = (string)$html;
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

// 6. POST /pbatch/{storeCode}/{productType}/{page} -> Sayfadaki ürünleri kullanıcının Alt Dükkanına kaydet
if (preg_match('#^/pbatch/([^/]+)/([^/]+)/(\d+)$#i', $route, $m)) {
    $items = $body['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        nk_json_response(0, true);
    }
    // Bu sayfada NadirKitap'ta görülen ürün kodları (tam aktarımla satılanları düşürmek için)
    nk_run_page((string)($body['runId'] ?? ''), $auth_store_id, (int)$m[3], array_map(function ($it) {
        return is_array($it) && is_array($it['meta'] ?? null) ? (string)($it['meta']['sourceProductCode'] ?? '') : '';
    }, $items));

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }

    $entity_cache = ['authors' => [], 'publishers' => [], 'categories' => []];
    $saved_count = 0;
    $stale_images = [];

    global $pdo;
    $in_tx = false;
    try {
        $pdo->beginTransaction();
        $in_tx = true;

        foreach ($items as $item) {
            $meta = $item['meta'] ?? [];
            $images = $item['images'] ?? [];
            if (!is_array($meta)) continue;

            $nk_code = trim((string)($meta['sourceProductCode'] ?? ''));
            $title = trim(html_entity_decode((string)($meta['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title === '') continue;
            if (mb_strlen($title, 'UTF-8') > 195) {
                $title = mb_substr($title, 0, 195, 'UTF-8');
            }

            $author_id    = nk_resolve_entity('authors', $meta['author'] ?? '', $entity_cache);
            $publisher_id = nk_resolve_entity('publishers', $meta['publisher'] ?? '', $entity_cache);

            // Uygulama kategori adı yerine NadirKitap kategori numarası gönderebilir: önce ad aranır,
            // yalnızca numara varsa numaranın eşleştiği kategori kullanılır (yönetici yeniden adlandırsa/birleştirse de)
            $cat_candidates = [trim((string)($meta['category2'] ?? '')), trim((string)($meta['category1'] ?? ''))];
            if (!empty($meta['categoryFullPath'])) {
                $parts = explode('>', (string)$meta['categoryFullPath']);
                $cat_candidates[] = trim((string)end($parts));
            }
            $cat_label = '';
            $cat_code = '';
            foreach ($cat_candidates as $cand) {
                $cand = trim(html_entity_decode($cand, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($cand === '') continue;
                if (is_numeric_category_name($cand)) {
                    $cat_code = $cat_code ?: $cand;
                } elseif ($cat_label === '') {
                    $cat_label = $cand;
                }
            }
            $category_id = null;
            if ($cat_label !== '') {
                // Ad geldiyse ad kullanılır. Numara-kategori eşleşmesi burada kaydedilmez: numara (category1) ile ad
                // (category2) farklı seviyelerden gelebiliyor; eşleştirmek o numaralı tüm kitapları yanlış kategoriye taşıyordu.
                $category_id = nk_resolve_entity('categories', $cat_label, $entity_cache);
            } elseif ($cat_code !== '') {
                // Önce NadirKitap kategori tablosu (_nk_categories.php): adı ve üst kategorisiyle oluşturulur
                $category_id = nk_category_ensure($cat_code);
                if (!$category_id) {
                    // Uygulama yalnızca numara gönderdi: adı kitabın NadirKitap sayfasından bul (numara başına bir kez)
                    $resolved = nk_resolve_category_name($cat_code, $meta['sourceUrl'] ?? '');
                    $category_id = nk_resolve_entity('categories', $resolved !== '' ? $resolved : $cat_code, $entity_cache);
                    nk_category_map_set($cat_code, $category_id);
                }
            } else {
                $category_id = nk_resolve_entity('categories', '', $entity_cache);
            }

            $price = nk_parse_price($meta['price'] ?? '0');
            $stock = max(1, (int)($meta['stockCount'] ?? 1));
            $pages = !empty($meta['pageCount']) && (int)$meta['pageCount'] > 0 ? (int)$meta['pageCount'] : null;
            $year  = !empty($meta['year']) && (int)$meta['year'] >= 1500 && (int)$meta['year'] <= ((int)date('Y') + 1) ? (int)$meta['year'] : null;

            $raw_isbn = preg_replace('/[^0-9Xx]/', '', (string)($meta['isbn'] ?? ''));
            $isbn = (strlen($raw_isbn) >= 10 && strlen($raw_isbn) <= 17) ? strtoupper($raw_isbn) : null;

            $shelf_code     = trim(html_entity_decode((string)($meta['sellerShelfCode'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            $condition_text = trim(html_entity_decode((string)($meta['condition'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            $cover_type     = trim(html_entity_decode((string)($meta['cover'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            $language       = trim(html_entity_decode((string)($meta['language'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            if (!empty($meta['languageSecond'])) {
                $language = $language ? ($language . ' / ' . trim($meta['languageSecond'])) : trim($meta['languageSecond']);
            }
            $publish_place  = trim(html_entity_decode((string)($meta['publishPlace'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
            $source_url     = trim((string)($meta['sourceUrl'] ?? '')) ?: null;

            $w = trim((string)($meta['width'] ?? ''));
            $h = trim((string)($meta['height'] ?? ''));
            $dimensions = ($w !== '' && $h !== '') ? "{$w} x {$h} cm" : (($w !== '' || $h !== '') ? trim("{$w} {$h}") : null);

            $trans_parts = array_filter([
                trim(html_entity_decode((string)($meta['translator'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                trim(html_entity_decode((string)($meta['preparer'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ]);
            $translator = !empty($trans_parts) ? implode(' / ', array_unique($trans_parts)) : null;

            $summary = nk_clean_description($meta['description'] ?? '');

            // Mevcut kaydı kontrol et (nadirkitap_code ile). Başka bir alt dükkanın kitabı devralınmaz;
            // yalnızca bu dükkanın veya henüz dükkana bağlanmamış eski kayıtlar güncellenir.
            $existing_book = null;
            if ($nk_code !== '') {
                if ($auth_store_id) {
                    $existing_book = db_fetch("SELECT id, cover_image, extra_images, shelf_code, isbn FROM books WHERE nadirkitap_code = ? AND (store_id = ? OR store_id IS NULL) ORDER BY store_id DESC", [$nk_code, $auth_store_id]);
                } else {
                    $existing_book = db_fetch("SELECT id, cover_image, extra_images, shelf_code, isbn FROM books WHERE nadirkitap_code = ? AND store_id IS NULL", [$nk_code]);
                }
            }

            // Görselleri kaydet. Sitede zaten görseli olan kitabın fotoğrafları yeniden yazılmaz
            // (yeniden aktarımda disk dolmasın; yalnızca fiyat/stok/bilgiler güncellenir).
            $keep_images = false;
            if ($existing_book) {
                $old_cover = basename((string)$existing_book['cover_image']);
                // Panelde "Görselleri yenile" denildiyse ondan önce yazılmış fotoğraflar bir kez yeniden çekilir
                $refresh_at = (int)setting('nk_image_refresh_at', '0');
                $keep_images = $old_cover !== '' && $old_cover !== 'book_placeholder.svg'
                    && (preg_match('#^https?://#i', (string)$existing_book['cover_image'])
                        || (is_file(UPLOAD_DIR . '/' . $old_cover) && @filemtime(UPLOAD_DIR . '/' . $old_cover) >= $refresh_at));
            }
            $saved_images = [];
            if ($keep_images) {
                $images = []; // gelen fotoğraflar çözülmez/yazılmaz
                $saved_images = array_values(array_filter(array_merge([(string)$existing_book['cover_image']], (array)(json_decode((string)($existing_book['extra_images'] ?? ''), true) ?: []))));
                $saved_images = array_values(array_unique($saved_images));
            }
            $allowed_exts = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
            if (is_array($images)) {
                foreach ($images as $idx => $img) {
                    $b64 = $img['base64'] ?? '';
                    if ($b64 === '') continue;
                    $bin = base64_decode($b64, true);
                    if ($bin === false || strlen($bin) < 64) continue;
                    $ext_raw = strtolower(trim((string)($img['extension'] ?? 'jpg')));
                    $ext = $allowed_exts[$ext_raw] ?? 'jpg';
                    $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '', $nk_code ?: uniqid('book_'));
                    // İçerik özeti dosya adında: kapak değişince adres de değişir (kapaklar 1 yıl önbelleğe alınıyor, aynı adla eski kapak görünürdü)
                    $fname = 'nk_' . ($auth_store_id ?: 0) . '_' . $safe_code . '_' . ($idx + 1) . '_' . substr(md5($bin), 0, 8) . '.' . $ext;
                    if (@file_put_contents(UPLOAD_DIR . '/' . $fname, $bin) !== false) {
                        $saved_images[] = $fname;
                    }
                }
            }

            if (empty($saved_images)) {
                for ($i = 1; $i <= 5; $i++) {
                    if (!empty($meta['image' . $i]) && preg_match('#^https?://#i', $meta['image' . $i])) {
                        $saved_images[] = trim($meta['image' . $i]);
                    }
                }
            }

            $cover_image = !empty($saved_images) ? $saved_images[0] : null;
            $extra_images_json = count($saved_images) > 1 ? json_encode($saved_images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

            // ISBN çakışma kontrolü
            $db_isbn = $isbn;
            if ($db_isbn !== null) {
                $exclude_book_id = $existing_book ? (int)$existing_book['id'] : 0;
                $dup_isbn = db_fetch("SELECT id FROM books WHERE isbn = ? AND id != ?", [$db_isbn, $exclude_book_id]);
                if ($dup_isbn) {
                    if ($summary === '' || stripos($summary, $db_isbn) === false) {
                        $summary = trim($summary . ($summary !== '' ? "\n" : '') . "ISBN: " . $db_isbn);
                    }
                    $db_isbn = null;
                }
            }

            if ($existing_book) {
                $book_id = (int)$existing_book['id'];
                $final_cover = $cover_image ?: ($existing_book['cover_image'] ?: 'book_placeholder.svg');
                // Yeniden aktarımda yeni fotoğraflar geldiyse eskileri (aktarımın yazdığı nk_ dosyaları) commit sonrası silinir
                if ($saved_images) {
                    $old_files = array_merge([(string)$existing_book['cover_image']], (array)(json_decode((string)($existing_book['extra_images'] ?? ''), true) ?: []));
                    foreach ($old_files as $of) {
                        $of = basename((string)$of);
                        if (strpos($of, 'nk_') === 0 && !in_array($of, $saved_images, true)) $stale_images[] = $of;
                    }
                }
                $final_shelf = $shelf_code ?: ($existing_book['shelf_code'] ?? null);
                db_query(
                    "UPDATE books SET store_id = ?, title = ?, isbn = ?, author_id = ?, publisher_id = ?, category_id = ?, price = ?, stock = ?, pages = ?, year = ?, summary = ?, cover_image = ?, is_active = 1, shelf_code = ?, condition_text = ?, cover_type = ?, dimensions = ?, language = ?, translator = ?, publish_place = ?, extra_images = ?, source_url = ? WHERE id = ?",
                    [$auth_store_id, $title, $db_isbn, $author_id, $publisher_id, $category_id, $price, $stock, $pages, $year, $summary, $final_cover, $final_shelf, $condition_text, $cover_type, $dimensions, $language, $translator, $publish_place, $extra_images_json, $source_url, $book_id]
                );
            } else {
                $final_cover = $cover_image ?: 'book_placeholder.svg';
                db_query(
                    "INSERT INTO books (store_id, title, original_title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active, nadirkitap_code, shelf_code, condition_text, cover_type, dimensions, language, translator, publish_place, extra_images, source_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$auth_store_id, $title, '', $db_isbn, $author_id, $publisher_id, $category_id, $price, $stock, $pages, $year, $summary, $final_cover, $nk_code ?: null, $shelf_code, $condition_text, $cover_type, $dimensions, $language, $translator, $publish_place, $extra_images_json, $source_url]
                );
                $book_id = (int)db_last_id();

                if ($book_id > 0) {
                    try {
                        db_query("INSERT OR IGNORE INTO book_showcase (book_id, showcase_id) VALUES (?, 3)", [$book_id]);
                        db_query("INSERT OR IGNORE INTO book_showcase (book_id, showcase_id) VALUES (?, 1)", [$book_id]);
                    } catch (Throwable $ignore) {
                        try {
                            db_query("INSERT IGNORE INTO book_showcase (book_id, showcase_id) VALUES (?, 3), (?, 1)", [$book_id, $book_id]);
                        } catch (Throwable $ignore2) {}
                    }
                }
            }

            book_refresh_slug($book_id); // okunabilir adres (ad değiştiyse eskisi 301 ile yönlenir)
            book_touch($book_id); // site haritası lastmod
            $saved_count++;
        }

        $pdo->commit();
        $in_tx = false;
        // Artık hiçbir kitapta kullanılmayan eski fotoğraflar diskten silinir (disk dolmasın)
        foreach (array_unique($stale_images) as $of) {
            $still = db_fetch("SELECT id FROM books WHERE cover_image = ? OR extra_images LIKE ? LIMIT 1", [$of, '%"' . $of . '"%']);
            if (!$still) @unlink(UPLOAD_DIR . '/' . $of);
        }
    } catch (Throwable $e) {
        if ($in_tx) {
            @$pdo->rollBack();
        }
        error_log('[Yeniceri nkapp] Batch kaydetme hatasi: ' . $e->getMessage());
        $why = 'Ürün kaydı sırasında sunucu hatası: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        nk_json_response(null, false, [$why], $why, 500);
    }

    nk_json_response($saved_count, true);
}

nk_json_response(null, false, ['Bilinmeyen API uç noktası: ' . $route], 'NOT_FOUND', 404);
