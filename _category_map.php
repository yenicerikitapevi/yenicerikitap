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

/**
 * NadirKitap kategori tablosu: numara => [sitede kullanılacak ad, üst kategori numarası|null]
 * (_nk_categories.php, NadirKitap'ın kategori sayfalarından çıkarıldı; sunucu NadirKitap'a bağlanamadığı için dosyada tutulur)
 */
function nk_category_table() {
    static $t = null;
    if ($t === null) {
        $t = is_file(__DIR__ . '/_nk_categories.php') ? (array)(require __DIR__ . '/_nk_categories.php') : [];
    }
    return $t;
}

function nk_category_label($code) {
    $t = nk_category_table();
    return $t[trim((string)$code)][0] ?? '';
}

/**
 * Tablodaki numaranın sitedeki kategorisi: eşleşme varsa o, yoksa aynı adlı kategori, o da yoksa üst kategorisinin
 * altında oluşturulur (alt kategoriler menüye dolmasın). Tabloda olmayan numarada null döner.
 */
function nk_category_ensure($code, $depth = 0) {
    $code = trim((string)$code);
    $mapped = nk_category_for_code($code);
    if ($mapped) return $mapped;
    $t = nk_category_table();
    if (!isset($t[$code]) || $depth > 4) return null;
    [$label, $parent_code] = $t[$code];
    $existing = find_by_name('categories', $label);
    if ($existing) {
        $id = (int)$existing['id'];
    } else {
        $parent_id = $parent_code !== null ? nk_category_ensure($parent_code, $depth + 1) : null;
        db_query("INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)", [$label, unique_slug('categories', $label), $parent_id]);
        $id = (int)db_last_id();
    }
    nk_category_map_set($code, $id);
    return $id;
}

/** Kategori ve alt kategorilerinin ID'leri (kategori sayfası alt kategorilerin kitaplarını da göstersin) */
function category_with_children_ids($category_id) {
    $ids = [(int)$category_id];
    $frontier = $ids;
    for ($level = 0; $level < 4 && $frontier; $level++) {
        $in = implode(',', array_map('intval', $frontier));
        $frontier = array_values(array_diff(array_map('intval', array_column(db_fetch_all("SELECT id FROM categories WHERE parent_id IN ({$in})"), 'id')), $ids));
        $ids = array_merge($ids, $frontier);
    }
    return $ids;
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

/* ===================== KATEGORİ TAHMİNİ (ISBN yardımcısı ve Excel'den toplu ekleme) ===================== */

/** Karşılaştırma için sadeleştirilmiş metin: küçük harf, Türkçe karakterler ASCII, yalnızca harf/rakam */
function cat_norm($s) {
    return trim(preg_replace('/[^a-z0-9]+/', ' ', str_replace('-', ' ', slugify_tr((string)$s))));
}

/**
 * Kitaba uygun mevcut kategori: [id, ad, nasıl] ya da null.
 *  1) kaynaktaki kategori adı birebir  2) ad kelimeleri bir kategori adının içinde ("Roman" → "Edebiyat & Roman")
 *  3) kaynak adı (x3) / kitap adı (x2) / özet (x1) anahtar kelimeleri; açık bir kazanan yoksa null
 *     (eşit puanda daha özel kategori seçilir: "Masal Kitapları" > "Çocuk & Gençlik")
 * Numarayla görünen (NadirKitap) kategoriler önerilmez.
 */
function guess_category($source_name, $text = '', $title = '') {
    static $cats = null;
    if ($cats === null) {
        $cats = [];
        foreach (db_fetch_all("SELECT id, name FROM categories ORDER BY id") as $c) {
            if (!is_numeric_category_name($c['name']) && cat_norm($c['name']) !== '') {
                $cats[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'n' => cat_norm($c['name'])];
            }
        }
    }
    if (!$cats) return null;
    $src = cat_norm($source_name);
    // Kaynak "Edebiyat > Roman > Türk Romanı" gibi yol olabilir: önce en özel parça
    $parts = array_reverse(array_filter(array_map('cat_norm', preg_split('/\s*(?:>|\/|,|»|\|)\s*/u', (string)$source_name))));
    foreach ($parts as $p) {
        foreach ($cats as $c) if ($c['n'] === $p) return [$c['id'], $c['name'], 'kaynakta aynı ad'];
    }
    $generic = ['ve', 'kitap', 'kitaplari', 'kitaplar', 'eserler', 'genel', 'diger', 'turk', 'dunya'];
    foreach ($parts as $p) {
        $pw = array_values(array_diff(explode(' ', $p), $generic));
        if (!$pw) continue;
        foreach ($cats as $c) {
            $cw = explode(' ', $c['n']);
            if (!array_diff($pw, $cw)) return [$c['id'], $c['name'], 'kaynaktaki “' . trim($source_name) . '” adına en yakın'];
        }
    }
    // Anahtar kelime puanı: kategori adının kendi kelimeleri + bilinen eşanlamlılar
    $hints = [
        'roman' => ['roman', 'romani', 'romanlari', 'oyku', 'oykuler', 'hikaye', 'hikayeler', 'siir', 'siirler', 'novel', 'fiction', 'edebiyat', 'polisiye', 'fantastik', 'bilim kurgu'],
        'cocuk' => ['cocuk', 'cocuklar', 'cocuklara', 'masal', 'masallar', 'masallari', 'genclik', 'okul oncesi', 'boyama', 'resimli'],
        'masal' => ['masal', 'masallar', 'masallari'],
        'tarih' => ['tarih', 'tarihi', 'osmanli', 'imparatorluk', 'savas', 'savasi', 'cumhuriyet', 'biyografi', 'ani', 'anilar', 'hatirat', 'history'],
        'felsefe' => ['felsefe', 'felsefesi', 'dusunce', 'psikoloji', 'sosyoloji', 'philosophy', 'nietzsche', 'ahlak'],
        'din' => ['din', 'dini', 'islam', 'kuran', 'hadis', 'tasavvuf', 'peygamber', 'siyer', 'ilmihal'],
        'klasik' => ['klasik', 'klasikleri', 'klasikler', 'hasan ali yucel'],
        'siir' => ['siir', 'siirler', 'siirleri'],
        'egitim' => ['test', 'soru bankasi', 'deneme sinavi', 'yks', 'lgs', 'kpss', 'tyt', 'ayt', 'konu anlatimli', 'ders'],
    ];
    $t_src = ' ' . $src . ' ';
    $t_title = ' ' . cat_norm($title) . ' ';
    $t_text = ' ' . cat_norm($text) . ' ';
    if (trim($t_src . $t_title . $t_text) === '') return null;
    $scores = [];
    foreach ($cats as $c) {
        $kw = array_diff(explode(' ', $c['n']), $generic);
        foreach ($kw as $w) {
            if (strlen($w) < 3) continue;
            foreach ($hints as $key => $list) if (strpos($w, $key) === 0) $kw = array_merge($kw, $list);
        }
        $kw = array_unique(array_filter($kw, function ($w) { return strlen($w) >= 3; }));
        $score = 0;
        foreach ($kw as $w) {
            $score += 3 * substr_count($t_src, ' ' . $w . ' ') + 2 * substr_count($t_title, ' ' . $w . ' ') + substr_count($t_text, ' ' . $w . ' ');
        }
        if ($score > 0) $scores[$c['id']] = [$score, count($kw), $c];
    }
    if (!$scores) return null;
    uasort($scores, function ($a, $b) { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; });
    $top = array_values($scores);
    // açık kazanan: en az 2 puan ve ikinciden belirgin fazla (eşitse daha özel olan)
    if ($top[0][0] < 2 || (isset($top[1]) && $top[1][0] !== $top[0][0] && $top[1][0] * 1.5 > $top[0][0])) return null;
    return [$top[0][2]['id'], $top[0][2]['name'], 'kitabın adı/özetine göre tahmin'];
}
