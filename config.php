<?php
/**
 * YENİÇERİ KİTABEVİ - ÇEKİRDEK KONFİGÜRASYON VE GÜVENLİK KÜTÜPHANESİ
 * Platform: Native PHP (OOP / Modular PDO Connection)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. VERİTABANI AYARLARI (MySQL & Fallback SQLite)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'yeniceri_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Site Temel Sabitleri - Dinamik Domain Tespiti (Render, Vercel ve Localhost Uyumlu)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
define('SITE_URL', $protocol . $host);
define('APP_NAME', 'Yeniçeri Kitabevi');

global $pdo;

try {
    // 1. Deneme: MySQL / MariaDB PDO Bağlantısı
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // 2. Deneme: MySQL sunucusu çalışıyor ancak 'yeniceri_db' veritabanı henüz oluşturulmadıysa otomatik oluştur
    if (strpos($e->getMessage(), 'Unknown database') !== false || $e->getCode() == 1049) {
        try {
            $root_pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4", DB_USER, DB_PASS);
            $root_pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            // MySQL şemasını schema.sql dosyasından import et
            $schema_file = __DIR__ . '/schema.sql';
            if (file_exists($schema_file)) {
                $sql_content = file_get_contents($schema_file);
                $pdo->exec($sql_content);
            }
        } catch (PDOException $ex_create) {
            init_sqlite_fallback();
        }
    } else {
        init_sqlite_fallback();
    }
}

/**
 * SQLite Otomatik Geçiş ve Başlatma Fonksiyonu
 */
function init_sqlite_fallback() {
    global $pdo;
    try {
        $sqlite_file = __DIR__ . '/yeniceri.db';
        $is_new = !file_exists($sqlite_file);
        
        $pdo = new PDO("sqlite:" . $sqlite_file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        if ($is_new) {
            init_sqlite_db($pdo);
        }
    } catch (PDOException $sqlite_ex) {
        die('<div style="font-family: sans-serif; padding: 40px; text-align: center; background: #fff3f3; color: #721c24; border: 1px solid #f5c6cb; border-radius: 12px; margin: 50px auto; max-width: 600px;">
                <h2>⚠️ Veritabanı Bağlantı Uyarısı</h2>
                <p>Veritabanı sürücüsü başlatılamadı: <strong>' . htmlspecialchars($sqlite_ex->getMessage()) . '</strong></p>
                <p>Lütfen MySQL servisinizin (XAMPP/Laragon) açık olduğunu ya da PHP konfigürasyonunuzda <code>pdo_mysql</code> / <code>pdo_sqlite</code> sürücülerinin aktif olduğunu kontrol edin.</p>
             </div>');
    }
}

/**
 * XSS Koruması
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Girdi Temizleme
 */
function sanitize($input) {
    if (is_array($input)) {
        foreach ($input as $key => $val) {
            $input[$key] = sanitize($val);
        }
        return $input;
    }
    return trim(strip_tags($input ?? ''));
}

/**
 * Güvenli PDO Sorgu Çalıştırıcı
 */
function db_query($sql, $params = []) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch_all($sql, $params = []) {
    return db_query($sql, $params)->fetchAll();
}

function db_fetch($sql, $params = []) {
    return db_query($sql, $params)->fetch();
}

function db_last_id() {
    global $pdo;
    return $pdo->lastInsertId();
}

/**
 * Admin İşlem Loglama
 */
function log_action($action, $target_module, $target_id = null, $details = '') {
    $user_id = $_SESSION['admin_user']['id'] ?? $_SESSION['user']['id'] ?? 1;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $sql = "INSERT INTO action_logs (user_id, action, target_module, target_id, ip_address, details) 
            VALUES (?, ?, ?, ?, ?, ?)";
    db_query($sql, [$user_id, $action, $target_module, $target_id, $ip, $details]);
}

/**
 * Oturum ve Yetki Kontrolü
 */
function auth_check($role = 'admin') {
    if ($role === 'admin') {
        if (!isset($_SESSION['admin_user']) || $_SESSION['admin_user']['role'] !== 'admin') {
            header("Location: " . SITE_URL . "/admin/login.php");
            exit;
        }
    } elseif ($role === 'customer') {
        if (!isset($_SESSION['user'])) {
            header("Location: " . SITE_URL . "/login.php");
            exit;
        }
    }
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function set_flash($type, $message) {
    $_SESSION['flash_msg'] = ['type' => $type, 'text' => $message];
}

function get_flash() {
    if (isset($_SESSION['flash_msg'])) {
        $msg = $_SESSION['flash_msg'];
        unset($_SESSION['flash_msg']);
        return '<div class="alert alert-' . $msg['type'] . ' alert-dismissible fade show" role="alert">
                    ' . e($msg['text']) . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
    }
    return '';
}

function format_price($price) {
    return '₺' . number_format((float)$price, 2, ',', '.');
}

/**
 * SQLite Veritabanı Otomatik Kurulum Yardımcısı
 */
function init_sqlite_db($db) {
    $sqls = [
        "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT 'customer', status TEXT DEFAULT 'active', phone TEXT, address TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, bio TEXT, photo TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE publishers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, logo TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, parent_id INTEGER, icon TEXT DEFAULT 'bi-book', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE books (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, original_title TEXT, isbn TEXT UNIQUE, author_id INTEGER, publisher_id INTEGER, category_id INTEGER, price REAL, old_price REAL, stock INTEGER DEFAULT 0, pages INTEGER, year INTEGER, summary TEXT, cover_image TEXT, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE showcases (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT UNIQUE, type TEXT DEFAULT 'grid', description TEXT, icon TEXT DEFAULT 'bi-star-fill');",
        "CREATE TABLE collections (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT UNIQUE, banner_image TEXT, description TEXT, badge_text TEXT DEFAULT 'Özel Koleksiyon');",
        "CREATE TABLE book_showcase (book_id INTEGER, showcase_id INTEGER, PRIMARY KEY (book_id, showcase_id));",
        "CREATE TABLE book_collection (book_id INTEGER, collection_id INTEGER, PRIMARY KEY (book_id, collection_id));",
        "CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number TEXT UNIQUE, user_id INTEGER, total_amount REAL, shipping_amount REAL DEFAULT 0, status TEXT DEFAULT 'Hazırlanıyor', payment_method TEXT DEFAULT 'Kredi Kartı', shipping_address TEXT, tracking_number TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, book_id INTEGER, quantity INTEGER, price REAL, total REAL);",
        "CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, transaction_type TEXT, amount REAL, status TEXT DEFAULT 'Onaylandı', gateway_response TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE action_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, target_module TEXT, target_id INTEGER, ip_address TEXT, details TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);",
        "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT UNIQUE, setting_value TEXT);"
    ];

    foreach ($sqls as $s) {
        $db->exec($s);
    }

    $pass = password_hash('admin123', PASSWORD_DEFAULT);
    $cpass = password_hash('123456', PASSWORD_DEFAULT);

    $db->exec("INSERT INTO users (id, name, email, password_hash, role, status, phone, address) VALUES
        (1, 'Sistem Yöneticisi', 'admin@yeniceri.com', '$pass', 'admin', 'active', '0312 245 00 00', 'Etimesgut / Ankara'),
        (2, 'Ahmet Yılmaz', 'ahmet@example.com', '$cpass', 'customer', 'active', '0555 111 22 33', 'İstasyon Mah. Akasya Cad. No:12 Etimesgut / Ankara'),
        (3, 'Ayşe Kaya', 'ayse@example.com', '$cpass', 'customer', 'active', '0544 222 33 44', 'Eryaman Mah. 1. Etap No:45 Etimesgut / Ankara');");

    $db->exec("INSERT INTO authors (id, name, slug, bio) VALUES
        (1, 'Mustafa Kemal Atatürk', 'mustafa-kemal-ataturk', 'Türkiye Cumhuriyeti kurucusu, büyük önder.'),
        (2, 'J.R.R. Tolkien', 'j-r-r-tolkien', 'İngiliz filolog ve fantezi edebiyatının babası.'),
        (3, 'Stefan Zweig', 'stefan-zweig', 'Avusturyalı unutulmaz roman ve biyografi yazarı.'),
        (4, 'Fyodor Dostoyevski', 'fyodor-dostoyevski', 'Rus edebiyatının dev ismi.'),
        (5, 'Franz Kafka', 'franz-kafka', 'Varoluşçu edebiyatın öncüsü.'),
        (6, 'Halil İnalcık', 'halil-inalcik', 'Dünyaca ünlü Türk tarihçi.');");

    $db->exec("INSERT INTO publishers (id, name, slug) VALUES
        (1, 'Türkiye İş Bankası Kültür Yayınları', 'is-bankasi-yayinlari'),
        (2, 'Metis Yayınları', 'metis-yayinlari'),
        (3, 'Can Yayınları', 'can-yayinlari'),
        (4, 'Kronik Kitap', 'kronik-kitap');");

    $db->exec("INSERT INTO categories (id, name, slug, parent_id, icon) VALUES
        (1, 'Edebiyat & Roman', 'edebiyat-roman', NULL, 'bi-book-half'),
        (2, 'Tarih & Araştırma', 'tarih-arastirma', NULL, 'bi-hourglass-split'),
        (3, 'Felsefe & Düşünce', 'felsefe-dusunce', NULL, 'bi-lightbulb'),
        (4, 'Çocuk & Gençlik', 'cocuk-genclik', NULL, 'bi-balloon'),
        (5, 'Dünya Klasikleri', 'dunya-klasikleri', 1, 'bi-journal-bookmark');");

    $db->exec("INSERT INTO showcases (id, title, slug, type, description, icon) VALUES
        (1, 'Vitrindekiler', 'vitrindekiler', 'grid', 'Öne çıkan en popüler eserler', 'bi-stars'),
        (2, 'Çok Satanlar', 'cok-satanlar', 'grid', 'En çok tercih edilen kitaplar', 'bi-fire'),
        (3, 'Yeni Ürünler', 'yeni-urunler', 'grid', 'Rafımıza yeni eklenen taze eserler', 'bi-sparkles'),
        (4, 'Tavsiye Kitaplar', 'tavsiye-kitaplar', 'scroll', 'Editörlerimizin özel önerileri', 'bi-hand-thumbs-up-fill'),
        (5, 'Seçtiklerimiz', 'sectiklerimiz', 'grid', 'Bu haftanın özel seçkileri', 'bi-award-fill'),
        (6, 'Çocuk Kitapları', 'cocuk-kitaplari', 'grid', 'Minik okurlar için eğitici kitaplar', 'bi-emoji-smile'),
        (7, 'Haftanın Ürünleri', 'haftanin-urunleri', 'grid', 'Bu haftaya özel indirimli eserler', 'bi-percent'),
        (8, 'Tarih Kitapları', 'tarih-kitaplari', 'grid', 'Geçmişten günümüze tarih eserleri', 'bi-journal-text');");

    $db->exec("INSERT INTO collections (id, title, slug, description, badge_text) VALUES
        (1, 'Hasan Âli Yücel Klasikler Serisi', 'hasan-ali-yucel-serisi', 'Dünya edebiyatının en seçkin yapıtları İş Bankası Kültür Yayınları kalitesiyle.', 'Hasan Âli Yücel'),
        (2, 'Atatürk’ün Tavsiye Ettiği Kitaplar', 'ataturkun-tavsiyeleri', 'Gazi Mustafa Kemal Atatürk’ün okuduğu ve çevresine tavsiye ettiği başyapıtlar.', 'Tarihi Miras'),
        (3, 'Yüzüklerin Efendisi Evreni', 'yuzuklerin-efendisi', 'Orta Dünya efsanesinin tüm serileri ve özel ciltli edisyonları.', 'Orta Dünya'),
        (4, 'Stefan Zweig Bütün Eserleri', 'stefan-zweig-serisi', 'İnsan ruhunun derinliklerine yolculuk yaptıran unutulmaz novellalar.', 'Zweig Seçkisi');");

    $db->exec("INSERT INTO books (id, title, original_title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active) VALUES
        (1, 'Nutuk (Özel Ciltli)', 'Nutuk', '9789754580001', 1, 1, 2, 145.00, 220.00, 45, 640, 1927, 'Mustafa Kemal Atatürk’ün Milli Mücadele sürecini kendi kaleminden anlattığı muazzam tarihi eser.', 'nutuk.jpg', 1),
        (2, 'Yüzüklerin Efendisi: Yüzük Kardeşliği', 'The Fellowship of the Ring', '9789753420012', 2, 2, 1, 280.00, 350.00, 18, 488, 1954, 'Tek Yüzük’ü yok etmek için yola çıkan dokuz kişilik kardeşliğin serüveni.', 'lotr_1.jpg', 1),
        (3, 'Satranç', 'Schachnovelle', '9786053600023', 3, 1, 1, 45.00, 65.00, 120, 84, 1942, 'Bir otel odasında hapsedilen bir adamın zihnini koruma mücadelesi ve satranç tutkusu.', 'satranc.jpg', 1),
        (4, 'Suç ve Ceza', 'Prestupleniye i nakazaniye', '9789754580034', 4, 1, 5, 120.00, 180.00, 8, 688, 1866, 'Raskolnikov’un vicdanı, ahlakı ve yoksulluğu üzerine insanlık tarihinin en büyük romanı.', 'suc_ve_ceza.jpg', 1),
        (5, 'Dönüşüm', 'Die Verwandlung', '9786053600045', 5, 3, 1, 50.00, 75.00, 30, 96, 1915, 'Gregor Samsa’nın dev bir böceğe dönüşmesiyle başlayan absürt anlatı.', 'donusum.jpg', 1),
        (6, 'Devlet-i ‘Aliyye (1. Cilt)', 'Devlet-i Aliyye', '9786051140056', 6, 4, 2, 210.00, 290.00, 25, 420, 2009, 'Halil İnalcık’ın altmış yılı bulan araştırmalarına dayanan Osmanlı ana eseri.', 'devlet_i_aliyye.jpg', 1),
        (7, 'Amok Koşucusu', 'Amok', '9786053600067', 3, 1, 1, 42.00, 60.00, 5, 64, 1922, 'Malezya’da bir doktorun bir kadına karşı hissettiği takıntılı tutku.', 'amok.jpg', 1),
        (8, 'Yüzüklerin Efendisi: İki Kule', 'The Two Towers', '9789753420029', 2, 2, 1, 280.00, 350.00, 12, 448, 1954, 'Kardeşliğin bozulmasının ardından Frodo ve Sam’in Hüküm Dağı’na yürüyüşü.', 'lotr_2.jpg', 1),
        (9, 'Yüzüklerin Efendisi: Kralın Dönüşü', 'The Return of the King', '9789753420036', 2, 2, 1, 295.00, 370.00, 15, 512, 1955, 'Gondor’daki büyük savaş ve Yüzük Çağı’nın muazzam sonu.', 'lotr_3.jpg', 1),
        (10, 'Çalıkuşu', 'Çalıkuşu', '9789754580100', 1, 3, 1, 110.00, 150.00, 60, 412, 1922, 'Feride’nin gurur ve aşk dolu yaşam öyküsü.', 'calikusu.jpg', 1);");

    $db->exec("INSERT INTO book_showcase (book_id, showcase_id) VALUES
        (1, 1), (1, 2), (1, 8),
        (2, 1), (2, 2),
        (3, 1), (3, 2), (3, 3), (3, 7),
        (4, 1), (4, 4), (4, 5),
        (5, 3), (5, 6),
        (6, 1), (6, 8),
        (7, 3), (7, 7),
        (8, 2), (8, 4),
        (9, 2), (9, 5),
        (10, 5), (10, 6);");

    $db->exec("INSERT INTO book_collection (book_id, collection_id) VALUES
        (3, 1), (3, 4),
        (4, 1),
        (7, 1), (7, 4),
        (1, 2), (6, 2),
        (2, 3), (8, 3), (9, 3);");

    $db->exec("INSERT INTO orders (id, order_number, user_id, total_amount, shipping_amount, status, payment_method, shipping_address, tracking_number, created_at) VALUES
        (1, 'YNC-20260810-001', 2, 470.00, 0.00, 'Tamamlandı', 'Kredi Kartı', 'İstasyon Mah. Akasya Cad. No:12 Etimesgut / Ankara', 'TR9988776655', '2026-08-09 14:30:00'),
        (2, 'YNC-20260810-002', 3, 165.00, 20.00, 'Hazırlanıyor', 'EFT / Havale', 'Eryaman Mah. 1. Etap No:45 Etimesgut / Ankara', NULL, '2026-08-10 10:15:00');");

    $db->exec("INSERT INTO order_items (id, order_id, book_id, quantity, price, total) VALUES
        (1, 1, 1, 1, 145.00, 145.00),
        (2, 1, 2, 1, 280.00, 280.00),
        (3, 1, 7, 1, 45.00, 45.00),
        (4, 2, 4, 1, 120.00, 120.00),
        (5, 2, 3, 1, 45.00, 45.00);");

    $db->exec("INSERT INTO transactions (id, order_id, transaction_type, amount, status, gateway_response) VALUES
        (1, 1, 'Kredi Kartı', 470.00, 'Onaylandı', 'SUCCESS - POS Ref: 88716253'),
        (2, 2, 'EFT / Havale', 165.00, 'Bekliyor', 'Ziraat Bankası Havale Bekleniyor');");

    $db->exec("INSERT INTO action_logs (id, user_id, action, target_module, target_id, ip_address, details) VALUES
        (1, 1, 'Sistem Başlatıldı', 'System', 1, '127.0.0.1', 'Yeniçeri Kitabevi veritabanı başarıyla başlatıldı ve örneklendi.');");

    $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES
        ('site_title', 'Yeniçeri Kitabevi | Kitabın Güvenli ve Hızlı Adresi'),
        ('site_description', 'Binlerce roman, tarih, klasikler ve çocuk kitapları avantajlı fiyatlarla Yeniçeri Kitabevi Etimesgut Ankara şubesinde.'),
        ('site_email', 'iletisim@yenicerikitabevi.com'),
        ('site_phone', '0312 245 00 00'),
        ('site_whatsapp', '0555 000 00 00'),
        ('site_address', 'İstasyon Mah. Hikmet Özer Cad. No:48/A Etimesgut / Ankara'),
        ('shipping_free_limit', '350.00'),
        ('flat_shipping_cost', '29.90'),
        ('payment_merchant_id', 'MERCHANT_YENICERI_889'),
        ('payment_api_key', 'test_api_key_yeniceri_sec_99812');");
}
