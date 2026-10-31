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
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');

function nk_json_response($payload = null, $success = true, $messages = [], $error = null, $status = 200) {
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
    nk_json_response(null, true);
}

// Gövdeyi JSON olarak oku
$raw_body = file_get_contents('php://input');
$body = $raw_body ? json_decode($raw_body, true) : [];
if (!is_array($body)) {
    $body = [];
}

// Kimlik doğrulama: X-Api-Key (YNCAUTH:<base64_json> veya username:password:nkInput) veya JSON gövdesi
$api_key = trim($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '');
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
    // Geriye dönük anahtar desteği (varsayılan ilk alt dükkan)
    $configured_key = setting('nk_api_key', 'Yeniceri-348635');
    $default_store  = setting('nk_store_code', '348635');
    $valid_keys     = array_unique(array_filter([$configured_key, 'Yeniceri-348635', 'yeniceri-348635', '348635', $default_store]));

    if ($api_key !== '' && in_array($api_key, $valid_keys, true)) {
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
        $rows = db_fetch_all("SELECT nadirkitap_code FROM books WHERE store_id = ? AND nadirkitap_code IS NOT NULL AND nadirkitap_code != ''", [$auth_store_id]);
    } else {
        $rows = db_fetch_all("SELECT nadirkitap_code FROM books WHERE nadirkitap_code IS NOT NULL AND nadirkitap_code != ''");
    }
    $codes = [];
    foreach ($rows as $r) {
        $c = trim((string)$r['nadirkitap_code']);
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
    nk_json_response([
        'runId'                 => $run_id,
        'batchStatus'           => 'ALL',
        'maxBookProductCode'    => null,
        'maxNonBookProductCode' => null,
        'productStatus'         => 'ALL',
        'startPage'             => $start_page,
        'blockedCategories'     => [],
        'requestIntervalMs'     => 800
    ], true);
}

// 4. POST /pbatch/{storeCode}/finish
if (preg_match('#^/pbatch/([^/]+)/finish$#i', $route, $m)) {
    $total = (int)($body['totalProducts'] ?? 0);
    $run_id = sanitize($body['runId'] ?? '');
    log_action('Alt Dükkan NadirKitap Aktarımı', 'Stores', $auth_store_id, "Alt Dükkan: {$auth_store_name}, RunID: {$run_id}, Aktarılan Ürün: {$total}");
    nk_json_response(null, true, ["{$auth_store_name} alt dükkanına toplam {$total} ürün başarıyla aktarıldı."]);
}

// 4b. POST /sync-sold veya POST /pbatch/{storeCode}/sync-sold -> Satılan kitapları tespit edip düşürür
if ($route === '/sync-sold' || preg_match('#^/pbatch/([^/]+)/sync-sold$#i', $route)) {
    $active_codes = $body['activeCodes'] ?? $body['activeProductCodes'] ?? [];
    if (!is_array($active_codes) || empty($active_codes)) {
        // İstemci kod göndermediyse sunucu tarafı tarayıcı motorunu çalıştır
        $res = nk_sync_store($auth_store_id, 40);
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

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }

    $entity_cache = ['authors' => [], 'publishers' => [], 'categories' => []];
    $saved_count = 0;

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
            if ($cat_code !== '') {
                $category_id = nk_category_for_code($cat_code);
            }
            if (!$category_id && $cat_label === '' && $cat_code !== '') {
                // Uygulama yalnızca numara gönderdi: adı kitabın NadirKitap sayfasından bul (numara başına bir kez)
                $cat_label = nk_resolve_category_name($cat_code, $meta['sourceUrl'] ?? '');
            }
            if (!$category_id) {
                $category_id = nk_resolve_entity('categories', $cat_label !== '' ? $cat_label : $cat_code, $entity_cache);
                if ($cat_code !== '') {
                    nk_category_map_set($cat_code, $category_id);
                }
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

            // Görselleri kaydet
            $saved_images = [];
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
                    $fname = 'nk_' . ($auth_store_id ?: 0) . '_' . $safe_code . '_' . ($idx + 1) . '.' . $ext;
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

            // Mevcut kaydı kontrol et (nadirkitap_code ile)
            $existing_book = null;
            if ($nk_code !== '') {
                $existing_book = db_fetch("SELECT id, cover_image, shelf_code, isbn FROM books WHERE nadirkitap_code = ?", [$nk_code]);
            }

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
    } catch (Throwable $e) {
        if ($in_tx) {
            @$pdo->rollBack();
        }
        error_log('[Yeniceri nkapp] Batch kaydetme hatasi: ' . $e->getMessage());
        nk_json_response(null, false, ['Ürün kaydı sırasında sunucu hatası: ' . $e->getMessage()], 'SERVER_ERROR', 500);
    }

    nk_json_response($saved_count, true);
}

nk_json_response(null, false, ['Bilinmeyen API uç noktası: ' . $route], 'NOT_FOUND', 404);
