<?php
/**
 * NADİRKİTAP KATEGORİ NUMARASI EŞLEŞMESİ
 * Aktarım uygulaması kategori adı yerine NadirKitap kategori numarası (ör. "104") gönderebiliyor.
 * Her numaranın bağlı olduğu kategori burada tutulur: yönetici "104"ü yeniden adlandırınca ya da başka
 * bir kategoriyle birleştirince sonraki aktarımlar da aynı kategoriye gider, "104" yeniden oluşmaz.
 */
if (!defined('SITE_URL')) exit;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nk_category_map (nk_code TEXT PRIMARY KEY, category_id INTEGER NOT NULL);");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nk_category_map (nk_code VARCHAR(20) PRIMARY KEY, category_id INT NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
}
// Daha önce numarayla oluşmuş kategorileri eşleşmeye ekle (bir kez; sonrakilerde zaten kayıtlı)
foreach (db_fetch_all("SELECT c.id, c.name FROM categories c LEFT JOIN nk_category_map m ON m.category_id = c.id WHERE m.nk_code IS NULL") as $c) {
    $code = trim((string)$c['name']);
    if (ctype_digit($code) && !db_fetch("SELECT nk_code FROM nk_category_map WHERE nk_code = ?", [$code])) {
        db_query("INSERT INTO nk_category_map (nk_code, category_id) VALUES (?, ?)", [$code, (int)$c['id']]);
    }
}

function is_numeric_category_name($name) {
    return ctype_digit(trim((string)$name));
}

/** NadirKitap numarasının bağlı olduğu kategori (yoksa null) */
function nk_category_for_code($code) {
    $row = db_fetch("SELECT c.id FROM nk_category_map m JOIN categories c ON c.id = m.category_id WHERE m.nk_code = ?", [trim((string)$code)]);
    return $row ? (int)$row['id'] : null;
}

function nk_category_map_set($code, $category_id) {
    db_query("DELETE FROM nk_category_map WHERE nk_code = ?", [trim((string)$code)]);
    db_query("INSERT INTO nk_category_map (nk_code, category_id) VALUES (?, ?)", [trim((string)$code), (int)$category_id]);
}

/** Site menüsündeki kategoriler: yalnızca adı olan (numara olmayan) ana kategoriler */
function nav_categories() {
    return array_values(array_filter(
        db_fetch_all("SELECT * FROM categories WHERE parent_id IS NULL ORDER BY name ASC"),
        function ($c) { return !is_numeric_category_name($c['name']); }
    ));
}

/** NadirKitap kitap sayfasından, verilen numaraya ait kategori adını bulmaya çalışır. [ad, güven('yüksek'|'tahmini')] */
function nk_category_name_from_html($html, $code) {
    $code = preg_quote((string)$code, '/');
    $clean = function ($t) {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return (mb_strlen($t, 'UTF-8') >= 2 && mb_strlen($t, 'UTF-8') <= 60 && !preg_match('/^\d+$/', $t)) ? $t : '';
    };
    // 1) JSON-LD BreadcrumbList: adresi bu numarayı içeren öğe
    $crumbs = [];
    if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
        foreach ($m[1] as $json) {
            $d = json_decode(trim($json), true);
            foreach ((isset($d['@graph']) ? $d['@graph'] : [$d]) as $node) {
                if (($node['@type'] ?? '') === 'BreadcrumbList') {
                    foreach ($node['itemListElement'] ?? [] as $it) {
                        $url = is_array($it['item'] ?? null) ? ($it['item']['@id'] ?? '') : ($it['item'] ?? '');
                        $name = $clean($it['name'] ?? (is_array($it['item'] ?? null) ? ($it['item']['name'] ?? '') : ''));
                        if ($name !== '') {
                            $crumbs[] = $name;
                            if (preg_match('/(?<!\d)' . $code . '(?!\d)/', (string)$url)) return [$name, 'yüksek'];
                        }
                    }
                }
            }
        }
    }
    // 2) Kategori bağlantısı: adresinde "kategori/category" ve bu numara geçen <a>
    if (preg_match_all('#<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
        $votes = [];
        foreach ($m as $a) {
            if (preg_match('/kateg|categor/i', $a[1]) && preg_match('/(?<!\d)' . $code . '(?!\d)/', $a[1])) {
                $name = $clean($a[2]);
                if ($name !== '') $votes[$name] = ($votes[$name] ?? 0) + 1;
            }
        }
        if ($votes) {
            arsort($votes);
            return [array_key_first($votes), 'yüksek'];
        }
    }
    // 3) Breadcrumb'da kitaptan önceki son öğe (numara eşleşmedi: tahmini)
    if (count($crumbs) >= 2) {
        return [$crumbs[count($crumbs) - 2], 'tahmini'];
    }
    return ['', ''];
}

/**
 * Aktarım sırasında bilinmeyen NadirKitap numarası için kategori adını kitabın NadirKitap sayfasından bulur.
 * Yalnızca kesin eşleşme ("yüksek") kabul edilir; bulunamazsa '' döner (kategori numarayla oluşur, panelden adlandırılır).
 * Aynı istekte aynı numara bir kez denenir; başarısız numara 1 gün boyunca tekrar denenmez.
 */
function nk_resolve_category_name($code, $source_url) {
    static $tried = [];
    $code = trim((string)$code);
    if ($code === '' || isset($tried[$code]) || !preg_match('#^https?://(www\.)?nadirkitap\.com/#i', (string)$source_url) || !function_exists('nk_sync_curl_get')) {
        return $tried[$code] ?? '';
    }
    static $lookups = 0;
    if ($lookups >= 3) {
        return ''; // aktarım uzamasın: istek başına en fazla 3 yeni numara sorgulanır, kalanı sonraki pakette
    }
    $lookups++;
    $tried[$code] = '';
    $fail_key = 'nk_cat_fail_' . $code;
    $last_fail = (int)setting($fail_key, '0');
    if ($last_fail > time() - 86400) {
        return '';
    }
    $r = nk_sync_curl_get($source_url);
    [$name, $conf] = $r['ok'] ? nk_category_name_from_html($r['html'], $code) : ['', ''];
    if ($name !== '' && $conf === 'yüksek') {
        $tried[$code] = $name;
        return $name;
    }
    try {
        db_query("DELETE FROM settings WHERE setting_key = ?", [$fail_key]);
        db_query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$fail_key, (string)time()]);
    } catch (Throwable $e) {
    }
    return '';
}
