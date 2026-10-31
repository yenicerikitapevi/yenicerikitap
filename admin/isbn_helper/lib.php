<?php
/**
 * ISBN Yardımcısı – ortak kütüphane (PHP 7.4+, curl, dom, mbstring gerekir; kapak için gd).
 * Bu dosyayı doğrudan çağırmayın; isbn_lookup.php ve isbn_cover.php kullanır.
 */
if (!defined('IH_LOADED')) {
    define('IH_LOADED', 1);
}

function ih_config()
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

function ih_json($data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/* ===================== GİRİŞ KONTROLÜ ===================== */

function ih_is_admin()
{
    $cfg = ih_config();
    $mode = isset($cfg['auth_mode']) ? $cfg['auth_mode'] : 'loopback';
    if ($mode === 'off') {
        return true;
    }
    if ($mode === 'session') {
        $key = (string)$cfg['session_key'];
        if ($key === '') {
            return false;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!empty($cfg['session_name'])) {
                session_name($cfg['session_name']);
            }
            @session_start(['read_and_close' => true]);   // oturumu hemen bırak, panelin işini kilitlemesin
        }
        return !empty($_SESSION[$key]);
    }
    // loopback: ziyaretçinin çerezi ile panel sayfasını sunucudan aç
    $cookie = isset($_SERVER['HTTP_COOKIE']) ? $_SERVER['HTTP_COOKIE'] : '';
    if ($cookie === '') {
        return false;
    }
    $ckey = sys_get_temp_dir() . '/ih_auth_' . hash('sha256', $cookie . '|' . $cfg['base_url']);
    if (is_file($ckey) && (time() - filemtime($ckey)) < 60) {
        return true;
    }
    $url = rtrim($cfg['base_url'], '/') . rtrim($cfg['admin_path'], '/') . $cfg['check_path'];
    $ch = curl_init($url);
    curl_setopt_array($ch, ih_curl_base($cfg) + [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie, 'Accept: text/html', 'X-ISBN-Helper-Check: 1'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $GLOBALS['ih_auth_info'] = 'doğrulama isteği: HTTP ' . $code . ($code === 0 ? ' (bağlanılamadı)' : '');
    $ok = ($code === 200 && is_string($body) && stripos($body, 'name="password"') === false && strlen($body) > 500);
    if ($ok) {
        @file_put_contents($ckey, '1');
    }
    return $ok;
}

function ih_require_admin()
{
    if (!ih_is_admin()) {
        $d = isset($GLOBALS['ih_auth_info']) ? $GLOBALS['ih_auth_info'] : '';
        ih_json(['ok' => false, 'error' => 'Yetkisiz: önce yönetim paneline giriş yapın.' . ($d ? ' (' . $d . ')' : ''), 'auth' => false], 403);
    }
}

/* ===================== HTTP ===================== */

define('IH_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');

function ih_curl_base($cfg)
{
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => !empty($cfg['ssl_verify']),
        CURLOPT_SSL_VERIFYHOST => !empty($cfg['ssl_verify']) ? 2 : 0,
        CURLOPT_USERAGENT      => IH_UA,
    ];
    if (!empty($cfg['ca_bundle'])) {
        $o[CURLOPT_CAINFO] = $cfg['ca_bundle'];
    }
    return $o;
}

function ih_header_variants()
{
    return [
        ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8', 'Accept-Language: tr-TR,tr;q=0.9,en;q=0.6'],
        [],
        ['Accept: */*', 'Accept-Language: tr-TR,tr;q=0.9'],
    ];
}

/**
 * Paralel GET. $reqs: anahtar => url (ya da ['url'=>..,'headers'=>[..],'max_bytes'=>..])
 * 403/429/5xx ve ağ hatalarında farklı başlıklarla en fazla 3 deneme.
 * Dönüş: anahtar => ['code'=>int,'body'=>string,'url'=>son adres,'err'=>string]
 */
function ih_fetch_many(array $reqs)
{
    $cfg = ih_config();
    $variants = ih_header_variants();
    $out = [];
    $pending = array_keys($reqs);
    for ($attempt = 0; $attempt < 3 && $pending; $attempt++) {
        // Genel süre sınırı (ih_set_deadline): kalan süre azsa yeniden deneme yapılmaz
        $left = ih_time_left();
        if ($attempt > 0 && $left !== null && $left < 6) {
            break;
        }
        if ($attempt > 0) {
            usleep(600000 * $attempt);
        }
        $mh = curl_multi_init();
        $hs = [];
        foreach ($pending as $k) {
            $r = is_array($reqs[$k]) ? $reqs[$k] : ['url' => $reqs[$k]];
            $ch = curl_init($r['url']);
            $hdr = array_merge($variants[$attempt], isset($r['headers']) ? $r['headers'] : []);
            curl_setopt_array($ch, ih_curl_base($cfg) + [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_HTTPHEADER     => $hdr,
            ]);
            $left = ih_time_left();
            if ($left !== null) {
                curl_setopt($ch, CURLOPT_TIMEOUT, max(2, min((int)$cfg['timeout'], (int)floor($left) - 1)));
            }
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        $running = null;
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK);
        $next = [];
        foreach ($hs as $k => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out[$k] = [
                'code' => $code,
                'body' => is_string($body) ? $body : '',
                'url'  => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                'err'  => curl_error($ch),
            ];
            if ($code === 0 || in_array($code, [403, 429, 500, 502, 503], true)) {
                $next[] = $k;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        // 404 gibi kalıcı hatalar için tekrar denemeyiz
        $pending = $next;
    }
    return $out;
}

/** Tüm isteğin bitmesi gereken an (PHP 60 sn sınırının altında kalmak için) */
function ih_set_deadline($seconds)
{
    $GLOBALS['ih_deadline'] = microtime(true) + $seconds;
}

function ih_time_left()
{
    return isset($GLOBALS['ih_deadline']) ? $GLOBALS['ih_deadline'] - microtime(true) : null;
}

function ih_fetch($url, $headers = [])
{
    $r = ih_fetch_many(['x' => ['url' => $url, 'headers' => $headers]]);
    return $r['x'];
}

/* ===================== ISBN ===================== */

function ih_isbn13_ok($s)
{
    if (!preg_match('/^\d{13}$/', $s)) {
        return false;
    }
    $t = 0;
    for ($i = 0; $i < 12; $i++) {
        $t += (int)$s[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return ((10 - $t % 10) % 10) === (int)$s[12];
}

function ih_isbn10_ok($s)
{
    if (!preg_match('/^\d{9}[\dXx]$/', $s)) {
        return false;
    }
    $t = 0;
    for ($i = 0; $i < 10; $i++) {
        $c = $s[$i];
        $t += (10 - $i) * (($c === 'X' || $c === 'x') ? 10 : (int)$c);
    }
    return $t % 11 === 0;
}

function ih_isbn10_to_13($s)
{
    $core = '978' . substr($s, 0, 9);
    $t = 0;
    for ($i = 0; $i < 12; $i++) {
        $t += (int)$core[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $core . ((10 - $t % 10) % 10);
}

function ih_normalize_isbn($raw)
{
    $s = preg_replace('/[\s\-]/', '', (string)$raw);
    if (ih_isbn13_ok($s)) {
        return $s;
    }
    if (ih_isbn10_ok($s)) {
        return ih_isbn10_to_13(strtoupper($s));
    }
    return null;
}

/* ===================== METİN ===================== */

function ih_norm($s)
{
    $s = (string)$s;
    $s = strtr($s, [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u',
        'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c', 'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'Á' => 'a',
        'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ß' => 'ss',
    ]);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim($s);
}

function ih_clean_text($s)
{
    $s = preg_replace('/<[^>]+>/', ' ', (string)$s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\xC2\xA0", ' ', $s);
    $s = preg_replace('/[ \t]+/', ' ', $s);
    $s = preg_replace('/\s*\n\s*/', "\n", $s);
    return trim($s);
}

function ih_parse_tl($s)
{
    if ($s === null || $s === '') {
        return null;
    }
    if (is_int($s) || is_float($s)) {
        return (float)$s;
    }
    $t = preg_replace('/[^\d,.\-]/', '', (string)$s);
    if ($t === '' || $t === null) {
        return null;
    }
    if (strpos($t, ',') !== false && strpos($t, '.') !== false) {
        $t = str_replace(',', '.', str_replace('.', '', $t));
    } elseif (strpos($t, ',') !== false) {
        $t = str_replace(',', '.', $t);
    } elseif (substr_count($t, '.') > 1) {
        $t = str_replace('.', '', $t);
    }
    return is_numeric($t) ? (float)$t : null;
}

function ih_int($v)
{
    return preg_match('/\d+/', (string)$v, $m) ? (int)$m[0] : null;
}

function ih_year($v)
{
    return preg_match('/(?:19|20)\d{2}/', (string)$v, $m) ? (int)$m[0] : null;
}

function ih_name($v)
{
    if (is_array($v)) {
        if (isset($v['name'])) {
            return $v['name'];
        }
        $parts = [];
        foreach ($v as $x) {
            $n = ih_name($x);
            if ($n) {
                $parts[] = $n;
            }
        }
        return implode(', ', $parts);
    }
    return $v;
}

function ih_good_summary($s)
{
    $s = ih_clean_text($s);
    if (mb_strlen($s, 'UTF-8') < 30) {
        return '';
    }
    $low = mb_strtolower(mb_substr($s, 0, 200, 'UTF-8'), 'UTF-8');
    foreach (['satın alınırken dikkat', 'fiyatı ve özellikleri', 'en uygun fiyat', 'indirimli fiyat'] as $j) {
        if (strpos($low, $j) !== false) {
            return '';
        }
    }
    return $s;
}

function ih_json_decode_loose($s)
{
    $d = json_decode($s, true);
    if ($d === null) {
        $d = json_decode(preg_replace('/[\x00-\x1F]/', ' ', $s), true);
    }
    return $d;
}

function ih_jsonld_all($html)
{
    $out = [];
    if (preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s', $html, $ms)) {
        foreach ($ms[1] as $raw) {
            $d = ih_json_decode_loose(trim($raw));
            if (!is_array($d)) {
                continue;
            }
            if (isset($d['@graph']) && is_array($d['@graph'])) {
                $items = $d['@graph'];
            } elseif (array_keys($d) === range(0, count($d) - 1)) {
                $items = $d;
            } else {
                $items = [$d];
            }
            foreach ($items as $g) {
                if (is_array($g)) {
                    $out[] = $g;
                }
            }
        }
    }
    return $out;
}

function ih_types($g)
{
    $t = isset($g['@type']) ? $g['@type'] : [];
    return is_array($t) ? $t : [$t];
}

function ih_jsonld_book($html)
{
    $all = ih_jsonld_all($html);
    foreach ($all as $g) {
        $t = ih_types($g);
        if (in_array('Book', $t, true) || (in_array('Product', $t, true) && (!empty($g['isbn']) || !empty($g['gtin13'])))) {
            return $g;
        }
    }
    foreach ($all as $g) {
        if (in_array('Product', ih_types($g), true)) {
            return $g;
        }
    }
    return null;
}

function ih_list($v)
{
    if ($v === null || $v === '' || $v === []) {
        return [];
    }
    if (is_array($v) && (array_keys($v) === range(0, count($v) - 1))) {
        return $v;
    }
    return [$v];
}

function ih_dom($html)
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($d);
}

function ih_cls($c)
{
    return "contains(concat(' ',normalize-space(@class),' '),' $c ')";
}

function ih_res($key, $store, $url = null)
{
    return ['key' => $key, 'store' => $store, 'url' => $url, 'meta' => null, 'offer' => null, 'error' => null];
}

/* ===================== BKM / KİTAPSEPETİ ===================== */

function ih_js_literal($raw)
{
    $s = str_replace(['\\\\', "\\'", '\\"'], ["\x01", "'", '"'], $raw);
    $s = str_replace("\x01", '\\', $s);
    return ih_json_decode_loose($s);
}

function ih_product_data($html)
{
    $out = [];
    if (preg_match_all("/PRODUCT_DATA\\.push\\(JSON\\.parse\\('(.*?)'\\)\\)/s", $html, $ms)) {
        foreach ($ms[1] as $raw) {
            $d = ih_js_literal($raw);
            if (is_array($d)) {
                $out[] = $d;
            }
        }
    }
    return $out;
}

function ih_pairs($extra)
{
    $d = [];
    foreach (explode('|', (string)$extra) as $part) {
        if (strpos($part, '#') !== false) {
            list($k, $v) = explode('#', $part, 2);
            $d[trim($k)] = trim($v);
        }
    }
    return $d;
}

/** Aşama 1: arama sayfasından aday ürünü seç. Dönüş [sonuç, sonraki-istek|null, bağlam] */
function ih_ticimax_stage1($key, $store, $host, $isbn, $resp)
{
    $r = ih_res($key, $store);
    if ($resp['code'] !== 200) {
        $r['error'] = 'HTTP ' . $resp['code'];
        return [$r, null, null];
    }
    $items = ih_product_data($resp['body']);
    if (!$items) {
        $r['error'] = 'ürün bulunamadı';
        return [$r, null, null];
    }
    $cand = null;
    foreach ($items as $it) {
        $ex = ih_pairs(isset($it['additional_field_7']) ? $it['additional_field_7'] : '');
        if ((isset($it['code']) && $it['code'] === $isbn) || (isset($ex['ISBN']) && $ex['ISBN'] === $isbn)) {
            $cand = $it;
            break;
        }
    }
    if (!$cand) {
        $cand = $items[0];
    }
    $url = 'https://www.' . $host . '/' . ltrim(isset($cand['url']) ? $cand['url'] : '', '/');
    $r['url'] = $url;
    return [$r, $url, $cand];
}

function ih_ticimax_stage2($r, $isbn, $cand, $page)
{
    $html = $page['code'] === 200 ? $page['body'] : '';
    $book = $html ? ih_jsonld_book($html) : null;
    $pg = $book ? (!empty($book['isbn']) ? $book['isbn'] : (isset($book['gtin13']) ? $book['gtin13'] : null)) : null;
    $ex = ih_pairs(isset($cand['additional_field_7']) ? $cand['additional_field_7'] : '');
    if ($pg && preg_replace('/\D/', '', (string)$pg) !== $isbn) {
        $r['error'] = 'ISBN eşleşmedi';
        return $r;
    }
    if (!$pg && !((isset($cand['code']) && $cand['code'] === $isbn) || (isset($ex['ISBN']) && $ex['ISBN'] === $isbn))) {
        $r['error'] = 'ISBN doğrulanamadı';
        return $r;
    }
    $covers = [];
    if (!empty($cand['image'])) {
        $covers[] = $cand['image'];
    }
    foreach (ih_list($book ? (isset($book['image']) ? $book['image'] : null) : null) as $u) {
        if (is_string($u) && $u !== '') {
            $covers[] = $u;
        }
    }
    $meta = [
        'title'     => isset($cand['name']) ? $cand['name'] : null,
        'author'    => !empty($cand['model']) ? $cand['model'] : ih_name($book ? (isset($book['author']) ? $book['author'] : null) : null),
        'publisher' => !empty($cand['brand']) ? $cand['brand'] : ih_name($book ? (isset($book['publisher']) ? $book['publisher'] : null) : null),
        'pages'     => ih_int(isset($ex['Sayfa Sayısı']) ? $ex['Sayfa Sayısı'] : ($book && isset($book['numberOfPages']) ? $book['numberOfPages'] : null)),
        'year'      => ih_year(isset($ex['Basım Yılı']) ? $ex['Basım Yılı'] : null),
        'summary'   => ih_good_summary($book && isset($book['description']) ? $book['description'] : ''),
        'category'  => isset($cand['category']) ? $cand['category'] : null,
        'cover_urls' => array_values(array_unique($covers)),
    ];
    if (!$meta['year'] && $html && preg_match('#Basım Yılı\s*</span>\s*<span[^>]*>\s*:?\s*</span>\s*<span[^>]*>\s*(\d{4})#u', $html, $m)) {
        $meta['year'] = (int)$m[1];
    }
    $r['meta'] = $meta;
    $price = ih_parse_tl(isset($cand['total_sale_price']) && $cand['total_sale_price'] ? $cand['total_sale_price'] : (isset($cand['sale_price']) ? $cand['sale_price'] : null));
    $lst = ih_parse_tl(isset($cand['total_base_price']) ? $cand['total_base_price'] : null);
    if ($price) {
        $r['offer'] = ['price' => $price, 'list_price' => ($lst && $lst >= $price) ? $lst : null,
                       'in_stock' => (isset($cand['quantity']) ? (float)$cand['quantity'] : 0) > 0];
    }
    return $r;
}

/* ===================== D&R ===================== */

function ih_dr_parse($isbn, $resp)
{
    $r = ih_res('dr', 'D&R');
    if ($resp['code'] !== 200) {
        $r['error'] = 'HTTP ' . $resp['code'];
        return $r;
    }
    $html = $resp['body'];
    $r['url'] = $resp['url'];
    $book = ih_jsonld_book($html);
    if (strpos($resp['url'], 'urunno=') === false || !$book) {
        $r['error'] = 'ürün bulunamadı';
        return $r;
    }
    $gt = preg_replace('/\D/', '', (string)(!empty($book['gtin13']) ? $book['gtin13'] : (isset($book['isbn']) ? $book['isbn'] : '')));
    if ($gt !== $isbn) {
        $r['error'] = 'ISBN eşleşmedi';
        return $r;
    }
    $imgs = array_values(array_filter(ih_list(isset($book['image']) ? $book['image'] : null), 'is_string'));
    $meta = [
        'title'     => isset($book['name']) ? $book['name'] : null,
        'author'    => ih_name(isset($book['author']) ? $book['author'] : null),
        'publisher' => ih_name(isset($book['publisher']) ? $book['publisher'] : null),
        'pages'     => ih_int(isset($book['numberOfPages']) ? $book['numberOfPages'] : null),
        'year'      => ih_year(isset($book['datePublished']) ? $book['datePublished'] : null),
        'summary'   => ih_good_summary(isset($book['description']) ? $book['description'] : ''),
        'cover_urls' => array_slice($imgs, 0, 1),
    ];
    $p = strpos($html, 'BreadcrumbList');
    if ($p !== false && preg_match_all('/"name":\s*"([^"]+)"/', substr($html, $p, 3000), $cm) && count($cm[1]) > 1) {
        $meta['category'] = json_decode('"' . end($cm[1]) . '"') ?: end($cm[1]);
    }
    $r['meta'] = $meta;
    $off = isset($book['offers']) ? $book['offers'] : [];
    if (is_array($off) && isset($off[0])) {
        $off = $off[0];
    }
    $price = ih_parse_tl(isset($off['price']) ? $off['price'] : null);
    $lst = null;
    if (preg_match("/data-product-campaigns='(.*?)'/s", $html, $m)) {
        $camp = ih_json_decode_loose(str_replace('&quot;', '"', $m[1]));
        if (is_array($camp) && $camp && is_array($camp[0])) {
            $lst = ih_parse_tl(isset($camp[0]['SalePrice']) ? $camp[0]['SalePrice'] : null);
            if (!empty($camp[0]['TotalPriceDR'])) {
                $price = ih_parse_tl($camp[0]['TotalPriceDR']) ?: $price;
            }
        }
    }
    if ($price) {
        $r['offer'] = ['price' => $price, 'list_price' => ($lst && $lst > $price) ? $lst : null,
                       'in_stock' => strpos((string)(isset($off['availability']) ? $off['availability'] : ''), 'InStock') !== false];
    }
    return $r;
}

/* ===================== KİTAP AMBARI ===================== */

function ih_kitapambari_parse($isbn, $resp)
{
    $r = ih_res('kitapambari', 'Kitap Ambarı');
    if ($resp['code'] !== 200) {
        $r['error'] = 'HTTP ' . $resp['code'];
        return $r;
    }
    $r['url'] = $resp['url'];
    $x = ih_dom($resp['body']);
    $v = $x->query('//*[' . ih_cls('prd_view') . ' and @data-prd-barcode]')->item(0);
    if (!$v || $v->getAttribute('data-prd-barcode') !== $isbn) {
        $r['error'] = 'ürün bulunamadı';
        return $r;
    }
    $fields = [];
    foreach ($x->query('//*[' . ih_cls('prd_fields_item') . ']') as $it) {
        $lab = $x->query('.//*[' . ih_cls('prd_fields_label') . ']', $it)->item(0);
        $txt = $x->query('.//*[' . ih_cls('prd_fields_text') . ']', $it)->item(0);
        if ($lab && $txt) {
            $fields[ih_norm($lab->textContent)] = ih_clean_text($txt->textContent);
        }
    }
    $pub = $x->query('//*[' . ih_cls('prd_view') . ']//*[' . ih_cls('prd_brand_box') . ']//a[' . ih_cls('publisher') . ']')->item(0);
    $auth = $x->query('//*[' . ih_cls('prd_view') . ']//*[' . ih_cls('prd_brand_box') . ']//a[' . ih_cls('writer') . ']')->item(0);
    $imgs = [];
    foreach ($x->query('//*[' . ih_cls('prd_view_img_box') . ']//a[@href]') as $a) {
        $h = $a->getAttribute('href');
        if (strpos($h, 'http') === 0) {
            $imgs[] = $h;
        }
    }
    $desc = $x->query('//*[' . ih_cls('prd_description') . ' or ' . ih_cls('prd_desc') . ' or @id="prd_description" or contains(@class,"prd_tab_content")]')->item(0);
    $r['meta'] = [
        'title'     => $v->getAttribute('data-prd-name'),
        'author'    => $auth ? ih_clean_text($auth->textContent) : null,
        'publisher' => $pub ? ih_clean_text($pub->textContent) : null,
        'pages'     => ih_int(isset($fields['sayfa sayisi']) ? $fields['sayfa sayisi'] : null),
        'year'      => ih_year(isset($fields['basim yili']) ? $fields['basim yili'] : (isset($fields['yayin tarihi']) ? $fields['yayin tarihi'] : null)),
        'summary'   => $desc ? ih_good_summary($desc->textContent) : '',
        'cover_urls' => array_slice($imgs, 0, 1),
    ];
    $price = ih_parse_tl($v->getAttribute('data-prd-final-price'));
    $lst = ih_parse_tl($v->getAttribute('data-prd-price'));
    if ($price) {
        $r['offer'] = ['price' => $price, 'list_price' => ($lst && $lst > $price) ? $lst : null, 'in_stock' => true];
    }
    return $r;
}

/* ===================== KİTAPYURDU ===================== */

const IH_PUB_GENERIC = ['yayinlari', 'yayinevi', 'yayincilik', 'yayinlar', 'kitap', 'kitabevi'];

/** Arama sonuç sayfalarından sıralı aday adresleri çıkarır */
function ih_kitapyurdu_cards($hint, array $searchResps)
{
    $title = $hint['title'];
    $pubTokens = array_diff(array_filter(explode(' ', ih_norm(isset($hint['publisher']) ? $hint['publisher'] : ''))), IH_PUB_GENERIC);
    $cards = [];
    $seen = [];
    foreach ($searchResps as $resp) {
        if ($resp['code'] !== 200) {
            continue;
        }
        $x = ih_dom($resp['body']);
        foreach ($x->query('//*[' . ih_cls('ky-product') . ']') as $c) {
            $a = $x->query('.//a[' . ih_cls('ky-product-title') . ']', $c)->item(0);
            if (!$a) {
                continue;
            }
            $href = explode('?', $a->getAttribute('href'))[0];
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = 1;
            $pub = $x->query('.//*[' . ih_cls('ky-product-publisher') . ']', $c)->item(0);
            $ptok = $pub ? array_filter(explode(' ', ih_norm($pub->textContent))) : [];
            $score = count(array_intersect($pubTokens, $ptok)) / max(1, count($pubTokens));
            $t1 = explode(' ', ih_norm($title));
            $t2 = explode(' ', ih_norm($a->textContent));
            $same = $t1[0] === $t2[0];
            $cards[] = [-($score + ($same ? 0.5 : 0)), $href];
        }
    }
    usort($cards, function ($a, $b) {
        return $a[0] <=> $b[0];
    });
    return array_slice(array_map(function ($c) {
        return $c[1];
    }, $cards), 0, 8);
}

function ih_kitapyurdu_parse($isbn, $hint, $href, $html)
{
    $r = ih_res('kitapyurdu', 'Kitapyurdu', $href);
    $book = ih_jsonld_book($html) ?: [];
    $x = ih_dom($html);
    $attrs = [];
    foreach ($x->query('//*[' . ih_cls('ky-pd-attributes__item') . ']|//*[' . ih_cls('ky-pd-attributes') . ']//li') as $li) {
        $t = trim(preg_replace('/\s+/u', ' ', $li->textContent));
        if (strpos($t, ':') !== false) {
            list($k, $v) = explode(':', $t, 2);
            $attrs[ih_norm($k)] = trim($v);
        }
    }
    $auth = $x->query('//*[' . ih_cls('ky-pd-author') . ']//a|//*[contains(@class,"author")]//a[contains(@href,"/yazar/")]')->item(0);
    $pubel = $x->query('//*[contains(@class,"publisher")]//a[contains(@href,"/yayinevi/")]')->item(0);
    $imgs = array_values(array_filter(ih_list(isset($book['image']) ? $book['image'] : null), 'is_string'));
    $off = isset($book['offers']) ? $book['offers'] : [];
    if (is_array($off) && isset($off[0])) {
        $off = $off[0];
    }
    $price = ih_parse_tl(isset($off['price']) ? $off['price'] : null);
    $lst = ih_parse_tl(isset($attrs['liste fiyati']) ? $attrs['liste fiyati'] : null);
    $r['meta'] = [
        'title'     => isset($book['name']) ? $book['name'] : null,
        'author'    => $auth ? ih_clean_text($auth->textContent) : (isset($hint['author']) ? $hint['author'] : null),
        'publisher' => $pubel ? ih_clean_text($pubel->textContent) : ih_name(isset($book['brand']) ? $book['brand'] : null),
        'pages'     => ih_int(isset($attrs['sayfa sayisi']) ? $attrs['sayfa sayisi'] : null),
        'year'      => ih_year(isset($attrs['yayin tarihi']) ? $attrs['yayin tarihi'] : null),
        'summary'   => ih_good_summary(isset($book['description']) ? $book['description'] : ''),
        'cover_urls' => array_slice($imgs, 0, 1),
    ];
    if ($price) {
        $r['offer'] = ['price' => $price, 'list_price' => ($lst && $lst > $price) ? $lst : null,
                       'in_stock' => strpos((string)(isset($off['availability']) ? $off['availability'] : ''), 'InStock') !== false];
    }
    return $r;
}

/* ===================== OPEN LIBRARY / GOOGLE BOOKS ===================== */

function ih_openlibrary_parse($isbn, $resp, $edResp, $workResp)
{
    $r = ih_res('openlibrary', 'Open Library');
    if ($resp['code'] !== 200) {
        $r['error'] = 'HTTP ' . $resp['code'];
        return $r;
    }
    $all = json_decode($resp['body'], true);
    $d = is_array($all) && isset($all['ISBN:' . $isbn]) ? $all['ISBN:' . $isbn] : null;
    if (!$d) {
        $r['error'] = 'kayıt yok';
        return $r;
    }
    $r['url'] = isset($d['url']) ? $d['url'] : null;
    $names = function ($arr) {
        $o = [];
        foreach ((array)$arr as $a) {
            if (isset($a['name'])) {
                $o[] = $a['name'];
            }
        }
        return implode(', ', $o);
    };
    $meta = [
        'title'     => isset($d['title']) ? $d['title'] : null,
        'author'    => $names(isset($d['authors']) ? $d['authors'] : []),
        'publisher' => $names(isset($d['publishers']) ? $d['publishers'] : []),
        'pages'     => isset($d['number_of_pages']) ? $d['number_of_pages'] : null,
        'year'      => ih_year(isset($d['publish_date']) ? $d['publish_date'] : null),
        'summary'   => '',
        'cover_urls' => [],
    ];
    $cov = isset($d['cover']) ? $d['cover'] : [];
    foreach (['large', 'medium'] as $k) {
        if (!empty($cov[$k])) {
            $meta['cover_urls'][] = $cov[$k];
        }
    }
    $ed = $edResp && $edResp['code'] === 200 ? json_decode($edResp['body'], true) : null;
    if (is_array($ed)) {
        if (!empty($ed['translation_of'])) {
            $meta['original_title'] = $ed['translation_of'];
        } elseif ($workResp && $workResp['code'] === 200) {
            $w = json_decode($workResp['body'], true);
            if (is_array($w) && !empty($w['title']) && ih_norm($w['title']) !== ih_norm(isset($d['title']) ? $d['title'] : '')) {
                $meta['original_title'] = $w['title'];
            }
        }
        if (!empty($ed['number_of_pages']) && !$meta['pages']) {
            $meta['pages'] = $ed['number_of_pages'];
        }
    }
    $r['meta'] = $meta;
    return $r;
}

function ih_googlebooks_parse($isbn, $resp)
{
    $r = ih_res('googlebooks', 'Google Books');
    if ($resp['code'] === 429) {
        $r['error'] = 'kota doldu (config.php içine google_books_key ekleyin)';
        return $r;
    }
    if ($resp['code'] !== 200) {
        $r['error'] = 'HTTP ' . $resp['code'];
        return $r;
    }
    $j = json_decode($resp['body'], true);
    if (empty($j['items'][0]['volumeInfo'])) {
        $r['error'] = 'kayıt yok';
        return $r;
    }
    $v = $j['items'][0]['volumeInfo'];
    $r['url'] = isset($v['infoLink']) ? $v['infoLink'] : null;
    $links = isset($v['imageLinks']) ? $v['imageLinks'] : [];
    $cov = [];
    foreach (['extraLarge', 'large', 'medium', 'thumbnail'] as $k) {
        if (!empty($links[$k])) {
            $cov[] = str_replace(['http://', 'zoom=1'], ['https://', 'zoom=0'], $links[$k]);
        }
    }
    $r['meta'] = [
        'title'     => isset($v['title']) ? $v['title'] : null,
        'author'    => implode(', ', isset($v['authors']) ? $v['authors'] : []),
        'publisher' => isset($v['publisher']) ? $v['publisher'] : null,
        'pages'     => !empty($v['pageCount']) ? $v['pageCount'] : null,
        'year'      => ih_year(isset($v['publishedDate']) ? $v['publishedDate'] : null),
        'summary'   => ih_clean_text(isset($v['description']) ? $v['description'] : ''),
        'category'  => implode(', ', isset($v['categories']) ? $v['categories'] : []),
        'cover_urls' => array_slice($cov, 0, 1),
    ];
    return $r;
}

/* ===================== İKİNCİ EL İLANLAR (Nadir Kitap, Kitantik) ===================== */

/** Ürün bağlantısı mı? (ilan sitesine göre) */
function ih_listing_href_ok($key, $href)
{
    if ($key === 'nadirkitap') {
        return (bool)preg_match('#-(?:kitap|urun)\d+\.html#i', $href);
    }
    return (bool)preg_match('#/(?:urun|kitap|product|ilan)[/-]#i', $href) || (bool)preg_match('#-p-\d+#i', $href);
}

/** Kart metninden durum (yeni / ikinci el) */
function ih_listing_condition($text)
{
    $t = mb_strtolower((string)$text, 'UTF-8');
    if (preg_match('/ikinci el|2\. ?el|kullanılmış|sahaf|çok iyi|orta|yıpranmış|eski baskı/u', $t)) {
        return 'İkinci el';
    }
    if (preg_match('/sıfır|yeni ürün|yeni kitap|\byeni\b|ambalajında/u', $t)) {
        return 'Yeni';
    }
    return '';
}

/**
 * İlan sitesi arama sayfasını ayrıştırır → ['listings' => [title, url, seller, condition, price, shipping]]
 * Önce JSON-LD (ItemList/Product/Offer), olmazsa ürün bağlantısı çevresindeki metin.
 * $hint varsa (ad/yazar ile arama) başlığı uyuşmayan ilanlar elenir.
 */
function ih_listing_parse($key, $store, $resp, $hint = null)
{
    $r = ih_res($key, $store, isset($resp['url']) ? $resp['url'] : null);
    $r['listings'] = [];
    if (!$resp || $resp['code'] !== 200) {
        $r['error'] = 'HTTP ' . ($resp ? $resp['code'] : 0);
        return $r;
    }
    $html = $resp['body'];
    if (stripos($html, 'cf-chl') !== false || stripos($html, 'Just a moment') !== false) {
        $r['error'] = 'site sunucuyu engelledi (bot koruması)';
        return $r;
    }
    $port = parse_url($resp['url'], PHP_URL_PORT);
    $host = parse_url($resp['url'], PHP_URL_SCHEME) . '://' . parse_url($resp['url'], PHP_URL_HOST) . ($port ? ':' . $port : '');
    $abs = function ($u) use ($host) {
        $u = html_entity_decode(trim((string)$u), ENT_QUOTES, 'UTF-8');
        if ($u === '') return '';
        if (strpos($u, '//') === 0) return 'https:' . $u;
        if (!preg_match('#^https?://#i', $u)) return $host . '/' . ltrim($u, '/');
        return $u;
    };
    $list = [];
    // 1) JSON-LD
    foreach (ih_jsonld_all($html) as $g) {
        $items = [];
        if (in_array('ItemList', ih_types($g), true) && !empty($g['itemListElement'])) {
            foreach ((array)$g['itemListElement'] as $el) {
                $items[] = isset($el['item']) && is_array($el['item']) ? $el['item'] : $el;
            }
        } elseif (in_array('Product', ih_types($g), true) || in_array('Book', ih_types($g), true)) {
            $items[] = $g;
        }
        foreach ($items as $it) {
            $offers = isset($it['offers']) ? $it['offers'] : null;
            if (!$offers) continue;
            $offs = isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice']) ? [$offers] : (array)$offers;
            foreach ($offs as $o) {
                $price = ih_parse_tl(isset($o['price']) ? $o['price'] : (isset($o['lowPrice']) ? $o['lowPrice'] : null));
                if (!$price) continue;
                $cond = '';
                if (!empty($o['itemCondition'])) {
                    $cond = stripos($o['itemCondition'], 'Used') !== false ? 'İkinci el' : (stripos($o['itemCondition'], 'New') !== false ? 'Yeni' : '');
                }
                $ship = null;
                if (isset($o['shippingDetails']['shippingRate']['value'])) {
                    $ship = ih_parse_tl($o['shippingDetails']['shippingRate']['value']);
                }
                $list[] = [
                    'title' => ih_clean_text(isset($it['name']) ? $it['name'] : ''),
                    'url' => $abs(isset($o['url']) ? $o['url'] : (isset($it['url']) ? $it['url'] : '')),
                    'seller' => ih_clean_text(isset($o['seller']['name']) ? $o['seller']['name'] : ''),
                    'condition' => $cond, 'price' => $price, 'shipping' => $ship,
                ];
            }
        }
    }
    // 2) HTML kartları
    if (!$list && class_exists('DOMDocument')) {
        $xp = ih_dom($html);
        if ($xp) {
            $seen = [];
            foreach ($xp->query('//a[@href]') as $a) {
                $href = $a->getAttribute('href');
                if (!ih_listing_href_ok($key, $href)) continue;
                $url = $abs($href);
                if (isset($seen[$url])) continue;
                // Fiyat içeren en yakın kapsayıcı blok
                $node = $a;
                $block = null;
                for ($up = 0; $up < 6 && $node && $node->parentNode; $up++) {
                    $node = $node->parentNode;
                    $txt = isset($node->textContent) ? $node->textContent : '';
                    if (preg_match('/\d[\d.,]*\s*(?:TL|₺)/u', $txt)) {
                        $block = $node;
                        break;
                    }
                }
                if (!$block) continue;
                // Metin parçalarını satır satır al (bitişik yazılmasın)
                $parts = [];
                foreach ($xp->query('.//text()', $block) as $tn) {
                    $t = trim(preg_replace('/\s+/u', ' ', $tn->nodeValue));
                    if ($t !== '') $parts[] = $t;
                }
                $txt = implode("\n", $parts);
                if (mb_strlen($txt, 'UTF-8') > 1500) continue; // tüm sayfayı kapsayan blok: güvenilmez
                if (!preg_match('/(\d{1,3}(?:[.\s]\d{3})*(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(?:TL|₺)/u', $txt, $pm)) continue;
                $price = ih_parse_tl($pm[1]);
                if (!$price) continue;
                $ship = null;
                if (preg_match('/kargo[^0-9₺]{0,25}(\d+(?:[.,]\d{1,2})?)\s*(?:TL|₺)/iu', $txt, $sm)) {
                    $ship = ih_parse_tl($sm[1]);
                } elseif (preg_match('/(?:ücretsiz|bedava)\s*kargo|kargo\s*(?:ücretsiz|bedava)/iu', $txt)) {
                    $ship = 0.0;
                }
                $seller = '';
                if (preg_match('/(?:satıcı|mağaza)\s*:\s*([^\n|•]{2,40})/iu', $txt, $sl)) {
                    $seller = trim($sl[1]);
                }
                $title = ih_clean_text($a->getAttribute('title') ?: $a->textContent);
                if (mb_strlen($title, 'UTF-8') < 2) continue;
                $seen[$url] = 1;
                $list[] = ['title' => $title, 'url' => $url, 'seller' => $seller, 'condition' => ih_listing_condition($txt), 'price' => $price, 'shipping' => $ship];
            }
        }
    }
    // Ad/yazar ile aramada başlığı tutmayanları ele
    if ($hint && !empty($hint['title'])) {
        $want = array_filter(explode(' ', ih_norm($hint['title'])), function ($w) { return strlen($w) > 2; });
        $list = array_values(array_filter($list, function ($l) use ($want) {
            if (!$want) return true;
            $have = ' ' . ih_norm($l['title']) . ' ';
            $hit = 0;
            foreach ($want as $w) {
                if (strpos($have, ' ' . $w . ' ') !== false) $hit++;
            }
            return $hit / count($want) >= 0.7;
        }));
    }
    usort($list, function ($a, $b) { return $a['price'] <=> $b['price']; });
    foreach ($list as $i => $l) {
        $list[$i]['store'] = $store;
    }
    $r['listings'] = array_slice($list, 0, 15);
    if (!$r['listings'] && !$r['error']) {
        $r['error'] = 'ilan bulunamadı';
    }
    return $r;
}

/** Ayarlardaki ilan siteleri için arama istekleri */
function ih_listing_requests($q, $suffix = '')
{
    $reqs = [];
    foreach ((array)(ih_config()['listing_sites'] ?? []) as $k => $site) {
        if (!empty($site['search'])) {
            $reqs[$k . $suffix] = str_replace('{q}', rawurlencode($q), $site['search']);
        }
    }
    return $reqs;
}

/** Seçilen alan değerini kaç kaynak doğruluyor? (sarı işaret için) */
function ih_field_meta(array $results, array $book, array $src)
{
    $meta = [];
    $names = [];
    foreach ($results as $r) {
        $names[$r['key']] = $r['store'];
    }
    foreach (['title', 'original_title', 'author', 'publisher', 'pages', 'year', 'category', 'summary'] as $f) {
        if (empty($book[$f])) continue;
        $agree = [];
        foreach ($results as $r) {
            if (empty($r['meta'][$f])) continue;
            $same = in_array($f, ['pages', 'year'], true)
                ? (int)$r['meta'][$f] === (int)$book[$f]
                : ($f === 'summary' ? $r['key'] === $src[$f] : ih_norm($r['meta'][$f]) === ih_norm($book[$f]));
            if ($same) $agree[] = $r['store'];
        }
        $meta[$f] = ['source' => isset($names[$src[$f]]) ? $names[$src[$f]] : (string)$src[$f], 'agree' => count(array_unique($agree)), 'sources' => array_values(array_unique($agree))];
    }
    return $meta;
}

/* ===================== BİRLEŞTİRME ===================== */

const IH_PRIORITY = ['bkm', 'kitapsepeti', 'dr', 'kitapambari', 'kitapyurdu', 'googlebooks', 'openlibrary'];
const IH_SUMMARY_PRIORITY = ['dr', 'kitapyurdu', 'kitapsepeti', 'kitapambari', 'bkm', 'googlebooks'];

function ih_pick(array $results, $field, array $order = IH_PRIORITY)
{
    $by = [];
    foreach ($results as $r) {
        $by[$r['key']] = $r;
    }
    foreach ($order as $k) {
        if (isset($by[$k]) && !empty($by[$k]['meta'][$field])) {
            return [$by[$k]['meta'][$field], $k];
        }
    }
    return [null, null];
}

function ih_build_prices(array $results)
{
    $offers = [];
    foreach ($results as $r) {
        if (!empty($r['offer']['price'])) {
            $offers[] = [
                'store' => $r['store'], 'key' => $r['key'], 'url' => $r['url'], 'price' => $r['offer']['price'],
                'list_price' => $r['offer']['list_price'], 'in_stock' => (bool)$r['offer']['in_stock'],
            ];
        }
    }
    usort($offers, function ($a, $b) {
        if ($a['in_stock'] !== $b['in_stock']) {
            return $a['in_stock'] ? -1 : 1;
        }
        return $a['price'] <=> $b['price'];
    });
    $avail = array_values(array_filter($offers, function ($o) {
        return $o['in_stock'];
    }));
    if (!$avail) {
        $avail = $offers;
    }
    $suggested = $avail ? $avail[0]['price'] : null;
    $lists = [];
    foreach ($offers as $o) {
        if ($o['list_price']) {
            $lists[] = (string)$o['list_price'];
        }
    }
    $old = null;
    if ($lists) {
        $cnt = array_count_values($lists);
        $top = max($cnt);
        $cands = [];
        foreach ($cnt as $v => $n) {
            if ($n === $top) {
                $cands[] = (float)$v;
            }
        }
        $old = min($cands);
        if ($suggested && $old <= $suggested) {
            $old = null;
        }
    }
    foreach ($offers as $i => $o) {
        $offers[$i]['cheapest'] = $avail && $o['key'] === $avail[0]['key'];
    }
    return ['offers' => $offers, 'suggested_price' => $suggested, 'suggested_old_price' => $old,
            'cheapest' => $avail ? $avail[0] : null];
}

function ih_lookup($isbn)
{
    $cfg = ih_config();
    $t0 = microtime(true);
    if (ih_time_left() === null) {
        ih_set_deadline((int)($cfg['request_budget'] ?? 50));
    }
    // --- Aşama 1: tüm kaynaklar paralel (ikinci el ilan siteleri dahil)
    $gb = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn . '&maxResults=3';
    if (!empty($cfg['google_books_key'])) {
        $gb .= '&key=' . urlencode($cfg['google_books_key']);
    }
    $s1 = ih_fetch_many([
        'bkm'  => 'https://www.bkmkitap.com/arama?q=' . $isbn,
        'ks'   => 'https://www.kitapsepeti.com/arama?q=' . $isbn,
        'dr'   => 'https://www.dr.com.tr/search?q=' . $isbn,
        'ka'   => 'https://www.kitapambari.com/arama?q=' . $isbn,
        'ol'   => 'https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data',
        'oled' => 'https://openlibrary.org/isbn/' . $isbn . '.json',
        'gb'   => $gb,
    ] + ih_listing_requests($isbn));
    // --- Aşama 2: ürün sayfaları
    $stage2 = [];
    $ctx = [];
    $results = [];
    foreach ([['bkm', 'bkm', 'BKM Kitap', 'bkmkitap.com'], ['ks', 'kitapsepeti', 'Kitapsepeti', 'kitapsepeti.com']] as $t) {
        list($rk, $key, $store, $host) = $t;
        list($r, $url, $cand) = ih_ticimax_stage1($key, $store, $host, $isbn, $s1[$rk]);
        $ctx[$rk] = [$r, $cand];
        if ($url) {
            $stage2[$rk . '_p'] = $url;
        } else {
            $results[] = $r;
        }
    }
    if ($s1['dr']['code'] === 200 && strpos($s1['dr']['url'], 'urunno=') === false) {
        $stage2['dr2'] = 'https://www.dr.com.tr/search?q=' . $isbn;   // D&R bazen ilk istekte arama sayfası döndürüyor
    }
    $workUrl = null;
    $ed = $s1['oled']['code'] === 200 ? json_decode($s1['oled']['body'], true) : null;
    if (is_array($ed) && empty($ed['translation_of']) && !empty($ed['works'][0]['key'])) {
        $stage2['olw'] = 'https://openlibrary.org' . $ed['works'][0]['key'] . '.json';
    }
    $s2 = $stage2 ? ih_fetch_many($stage2) : [];
    foreach ($ctx as $rk => $c) {
        if (isset($s2[$rk . '_p'])) {
            $results[] = ih_ticimax_stage2($c[0], $isbn, $c[1], $s2[$rk . '_p']);
        }
    }
    $drResp = isset($s2['dr2']) && $s2['dr2']['code'] === 200 ? $s2['dr2'] : $s1['dr'];
    $results[] = ih_dr_parse($isbn, $drResp);
    $results[] = ih_kitapambari_parse($isbn, $s1['ka']);
    $results[] = ih_openlibrary_parse($isbn, $s1['ol'], $s1['oled'], isset($s2['olw']) ? $s2['olw'] : null);
    $results[] = ih_googlebooks_parse($isbn, $s1['gb']);

    // İlan siteleri (ISBN ile)
    $listing_res = [];
    foreach ((array)($cfg['listing_sites'] ?? []) as $lk => $site) {
        if (isset($s1[$lk])) {
            $listing_res[$lk] = ih_listing_parse($lk, $site['name'], $s1[$lk]);
        }
    }

    // --- Aşama 3: Kitapyurdu (ISBN ile aranamaz → başlık ipucu) + ISBN'le ilan bulunamayan sitelerde ad/yazar araması
    $hint = [
        'title' => ih_pick($results, 'title')[0], 'author' => ih_pick($results, 'author')[0],
        'publisher' => ih_pick($results, 'publisher')[0],
    ];
    if ($hint['title']) {
        $q1 = trim($hint['title'] . ' ' . ($hint['publisher'] ?: ''));
        $q2 = trim($hint['title'] . ' ' . ($hint['author'] ?: ''));
        $base = 'https://www.kitapyurdu.com/index.php?route=product/search&filter_name=';
        $text_q = [];
        foreach (ih_listing_requests($q2, '_t') as $lk_t => $u) {
            if (empty($listing_res[substr($lk_t, 0, -2)]['listings'])) {
                $text_q[$lk_t] = $u;
            }
        }
        $s3 = ih_fetch_many(['a' => $base . rawurlencode($q1), 'b' => $base . rawurlencode($q2)] + $text_q);
        foreach ($text_q as $lk_t => $u) {
            $lk = substr($lk_t, 0, -2);
            $alt = ih_listing_parse($lk, $cfg['listing_sites'][$lk]['name'], $s3[$lk_t], $hint);
            if ($alt['listings']) {
                $alt['matched_by'] = 'ad + yazar';
                $listing_res[$lk] = $alt;
            }
        }
        $hrefs = ih_kitapyurdu_cards($hint, [$s3['a'], $s3['b']]);
        $ky = ih_res('kitapyurdu', 'Kitapyurdu');
        if (!$hrefs) {
            $ky['error'] = 'arama sonucu yok';
        } else {
            $reqs = [];
            foreach ($hrefs as $i => $h) {
                $reqs['p' . $i] = $h;
            }
            $s4 = ih_fetch_many($reqs);
            $found = false;
            foreach ($hrefs as $i => $h) {
                $p = $s4['p' . $i];
                if ($p['code'] === 200 && preg_match('#ISBN:\s*</span>\s*<span[^>]*>\s*([\dXx\-]+)#', $p['body'], $m)
                    && preg_replace('/\D/', '', $m[1]) === $isbn) {
                    $ky = ih_kitapyurdu_parse($isbn, $hint, $h, $p['body']);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $ky['error'] = 'ürün bulunamadı (bu ISBN ile eşleşen baskı yok)';
            }
        }
        $results[] = $ky;
    } else {
        $ky = ih_res('kitapyurdu', 'Kitapyurdu');
        $ky['error'] = 'başlık bulunamadı';
        $results[] = $ky;
    }

    $book = ['isbn' => $isbn];
    $src = [];
    foreach (['title', 'author', 'publisher', 'pages', 'year', 'category'] as $f) {
        list($book[$f], $src[$f]) = ih_pick($results, $f);
    }
    list($book['summary'], $src['summary']) = ih_pick($results, 'summary', IH_SUMMARY_PRIORITY);
    list($book['original_title'], $src['original_title']) = ih_pick($results, 'original_title');

    $covers = [];
    $seen = [];
    foreach (IH_PRIORITY as $k) {
        foreach ($results as $r) {
            if ($r['key'] === $k && !empty($r['meta']['cover_urls'])) {
                foreach ($r['meta']['cover_urls'] as $u) {
                    if ($u && !isset($seen[$u])) {
                        $seen[$u] = 1;
                        $covers[] = ['url' => $u, 'source' => $r['store']];
                    }
                }
            }
        }
    }
    $summaries = [];
    foreach ($results as $r) {
        if (!empty($r['meta']['summary'])) {
            $summaries[] = ['source' => $r['store'], 'text' => $r['meta']['summary']];
        }
    }
    $prices = ih_build_prices($results);
    // Fiyat önceliği: önce Nadir Kitap / Kitantik ilanları, yoksa yeni kitap mağazaları
    $listings = [];
    foreach ($listing_res as $lr) {
        foreach ($lr['listings'] as $l) {
            $l['matched_by'] = isset($lr['matched_by']) ? $lr['matched_by'] : 'ISBN';
            $listings[] = $l;
        }
    }
    usort($listings, function ($a, $b) { return $a['price'] <=> $b['price']; });
    $suggested_source = $prices['cheapest'] ? $prices['cheapest']['store'] : null;
    if ($listings) {
        $best = $listings[0];
        $prices['suggested_price'] = $best['price'];
        $suggested_source = $best['store'] . ($best['seller'] ? ' · ' . $best['seller'] : '') . ($best['condition'] ? ' (' . $best['condition'] . ')' : '');
        if ($prices['suggested_old_price'] !== null && $prices['suggested_old_price'] <= $best['price']) {
            $prices['suggested_old_price'] = null;
        }
    }
    // Mağazalarda hiç kaydı olmayan (eski/nadir) kitap: ISBN ile bulunan ilanın adı kullanılır (sarı işaretlenir)
    if (empty($book['title'])) {
        foreach ($listings as $l) {
            if ($l['matched_by'] === 'ISBN' && $l['title'] !== '') {
                $book['title'] = $l['title'];
                foreach ($listing_res as $lk => $lr) {
                    if ($lr['store'] === $l['store']) $src['title'] = $lk;
                }
                break;
            }
        }
    }
    foreach ($listing_res as $lr) {
        $results[] = $lr; // kaynak durumu listesinde görünsün
    }
    $srcs = [];
    foreach ($results as $r) {
        $srcs[] = ['key' => $r['key'], 'store' => $r['store'], 'url' => $r['url'],
                   'ok' => !empty($r['meta']) || !empty($r['offer']) || !empty($r['listings']), 'error' => $r['error'],
                   'price' => isset($r['offer']['price']) ? $r['offer']['price'] : null];
    }
    $found = !empty($book['title']);
    return [
        'ok'    => $found,
        'error' => $found ? null : 'Bu ISBN için hiçbir kaynakta kayıt bulunamadı.',
        'isbn'  => $isbn,
        'title' => $book['title'], 'original_title' => $book['original_title'], 'author' => $book['author'],
        'publisher' => $book['publisher'], 'pages' => $book['pages'], 'year' => $book['year'],
        'summary' => $book['summary'], 'category' => $book['category'],
        'cover_url' => $covers ? $covers[0]['url'] : null,
        'covers' => $covers, 'summaries' => $summaries,
        'prices' => $prices['offers'],
        'cheapest' => $prices['cheapest'],
        'suggested_price' => $prices['suggested_price'],
        'suggested_old_price' => $prices['suggested_old_price'],
        'suggested_source' => $suggested_source,
        'old_price_source' => $prices['suggested_old_price'] !== null ? 'mağazaların liste (etiket) fiyatı' : null,
        'listings' => $listings,
        'field_sources' => array_filter($src),
        'field_meta' => ih_field_meta($results, $book, $src),
        'sources' => $srcs,
        'took_ms' => (int)((microtime(true) - $t0) * 1000),
    ];
}

/* ===================== KAPAK (GD) ===================== */

/** Dışarıdaki adreslerin yalnızca genel (public) IP'lere gitmesine izin ver (SSRF koruması) */
function ih_public_url($url)
{
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower(isset($p['scheme']) ? $p['scheme'] : ''), ['http', 'https'], true)) {
        return false;
    }
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) {
        return false;
    }
    $host = $p['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array)@gethostbynamel($host);
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function ih_cover_scale_fit($im, $W, $H)
{
    // "cover" gibi: ortadan kırp + ölçekle
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = max($W / $w, $H / $h);
    $cw = (int)round($W / $scale);
    $ch = (int)round($H / $scale);
    $sx = (int)(($w - $cw) / 2);
    $sy = (int)(($h - $ch) / 2);
    $dst = imagecreatetruecolor($W, $H);
    imagecopyresampled($dst, $im, 0, 0, $sx, $sy, $W, $H, $cw, $ch);
    return $dst;
}

function ih_to_600x900($im)
{
    $W = 600;
    $H = 900;
    $w = imagesx($im);
    $h = imagesy($im);
    $target = $W / $H;
    if (abs($w / $h - $target) / $target <= 0.07) {
        return ih_cover_scale_fit($im, $W, $H);
    }
    $scale = min($W / $w, $H / $h);
    $nw = max(1, (int)floor($w * $scale));
    $nh = max(1, (int)floor($h * $scale));
    // bulanık zemin: küçük boyutta kırp, bulanıklaştır, büyüt
    $small = ih_cover_scale_fit($im, 60, 90);
    for ($i = 0; $i < 4; $i++) {
        imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
    }
    $bg = imagecreatetruecolor($W, $H);
    imagecopyresampled($bg, $small, 0, 0, 0, 0, $W, $H, 60, 90);
    imagedestroy($small);
    imagecopyresampled($bg, $im, (int)(($W - $nw) / 2), (int)(($H - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    return $bg;
}

function ih_load_image($bytes)
{
    if (strlen($bytes) < 3000) {
        return null;
    }
    $im = @imagecreatefromstring($bytes);
    if (!$im) {
        return null;
    }
    // EXIF yönü (yalnızca JPEG, varsa)
    if (function_exists('exif_read_data') && substr($bytes, 0, 2) === "\xFF\xD8") {
        $tmp = @tempnam(sys_get_temp_dir(), 'ihx');
        if ($tmp) {
            file_put_contents($tmp, $bytes);
            $ex = @exif_read_data($tmp);
            @unlink($tmp);
            if (!empty($ex['Orientation'])) {
                $deg = [3 => 180, 6 => -90, 8 => 90];
                if (isset($deg[$ex['Orientation']])) {
                    $rot = imagerotate($im, $deg[$ex['Orientation']], 0);
                    if ($rot) {
                        $im = $rot;
                    }
                }
            }
        }
    }
    return $im;
}
