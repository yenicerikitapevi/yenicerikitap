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
