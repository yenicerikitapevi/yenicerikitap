-- ============================================================
-- YENİÇERİ KİTABEVİ - UÇTAN UCA KİTAP E-TİCARET PLATFORMU SCHEMAS
-- Database Engine: MySQL / MariaDB (InnoDB)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS action_logs;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS book_collection;
DROP TABLE IF EXISTS book_showcase;
DROP TABLE IF EXISTS collections;
DROP TABLE IF EXISTS showcases;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS publishers;
DROP TABLE IF EXISTS authors;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. KULLANICILAR (MÜŞTERİLER & ADMİNLER)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'customer') DEFAULT 'customer',
    status ENUM('active', 'inactive') DEFAULT 'active',
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. YAZARLAR
CREATE TABLE authors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    bio TEXT NULL,
    photo VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. YAYINEVLERİ
CREATE TABLE publishers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    logo VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. KATEGORİLER
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    parent_id INT DEFAULT NULL,
    icon VARCHAR(50) DEFAULT 'bi-book',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. KİTAPLAR
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    original_title VARCHAR(200) NULL,
    isbn VARCHAR(20) NULL UNIQUE,
    author_id INT NOT NULL,
    publisher_id INT NOT NULL,
    category_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    old_price DECIMAL(10,2) NULL,
    stock INT NOT NULL DEFAULT 0,
    pages INT NULL,
    year INT NULL,
    summary TEXT NULL,
    cover_image VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE CASCADE,
    FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. VİTRİNLER (SHOWCASES)
CREATE TABLE showcases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL DEFAULT 'standard',
    description VARCHAR(255) NULL,
    icon VARCHAR(50) DEFAULT 'bi-star-fill'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. KOLEKSİYONLAR (THEMATIC MARKETING BLOCKS)
CREATE TABLE collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    banner_image VARCHAR(255) NULL,
    description TEXT NULL,
    badge_text VARCHAR(50) DEFAULT 'Özel Koleksiyon'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. KİTAP - VİTRİN PİVOT TABLOSU
CREATE TABLE book_showcase (
    book_id INT NOT NULL,
    showcase_id INT NOT NULL,
    PRIMARY KEY (book_id, showcase_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (showcase_id) REFERENCES showcases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. KİTAP - KOLEKSİYON PİVOT TABLOSU
CREATE TABLE book_collection (
    book_id INT NOT NULL,
    collection_id INT NOT NULL,
    PRIMARY KEY (book_id, collection_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. SİPARİŞLER
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(30) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    shipping_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('Hazırlanıyor', 'Kargolandı', 'Tamamlandı', 'İptal') DEFAULT 'Hazırlanıyor',
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Kredi Kartı',
    shipping_address TEXT NOT NULL,
    tracking_number VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. SİPARİŞ KALEMLERİ
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    book_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. ÖDEME VE İŞLEM KAYITLARI (TRANSACTIONS)
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    transaction_type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('Onaylandı', 'Bekliyor', 'Başarısız', 'İade') DEFAULT 'Onaylandı',
    gateway_response TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. ADMİN İŞLEM LOGLARI (ACTION LOGS)
CREATE TABLE action_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_module VARCHAR(50) NOT NULL,
    target_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    details TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. SİTE GENEL AYARLARI
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ÖRNEK SEED VERİLERİ
INSERT INTO users (id, name, email, password_hash, role, status, phone, address) VALUES
(1, 'Sistem Yöneticisi', 'admin@yeniceri.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1fWbY./aD.LGHG/bK3.g4rD8z9QyEWG', 'admin', 'active', '0312 245 00 00', 'Etimesgut / Ankara'),
(2, 'Ahmet Yılmaz', 'ahmet@example.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1fWbY./aD.LGHG/bK3.g4rD8z9QyEWG', 'customer', 'active', '0555 111 22 33', 'İstasyon Mah. Akasya Cad. No:12 Etimesgut / Ankara'),
(3, 'Ayşe Kaya', 'ayse@example.com', '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1fWbY./aD.LGHG/bK3.g4rD8z9QyEWG', 'customer', 'active', '0544 222 33 44', 'Eryaman Mah. 1. Etap No:45 Etimesgut / Ankara');

INSERT INTO authors (id, name, slug, bio) VALUES
(1, 'Mustafa Kemal Atatürk', 'mustafa-kemal-ataturk', 'Türkiye Cumhuriyeti kurucusu, büyük önder.'),
(2, 'J.R.R. Tolkien', 'j-r-r-tolkien', 'İngiliz filolog ve fantezi edebiyatının babası.'),
(3, 'Stefan Zweig', 'stefan-zweig', 'Avusturyalı roman ve biyografi yazarı.'),
(4, 'Fyodor Dostoyevski', 'fyodor-dostoyevski', 'Rus edebiyatının dev ismi.'),
(5, 'Franz Kafka', 'franz-kafka', 'Varoluşçu edebiyatın öncüsü.'),
(6, 'Halil İnalcık', 'halil-inalcik', 'Dünyaca ünlü Türk tarihçi.');

INSERT INTO publishers (id, name, slug) VALUES
(1, 'Türkiye İş Bankası Kültür Yayınları', 'is-bankasi-yayinlari'),
(2, 'Metis Yayınları', 'metis-yayinlari'),
(3, 'Can Yayınları', 'can-yayinlari'),
(4, 'Kronik Kitap', 'kronik-kitap');

INSERT INTO categories (id, name, slug, parent_id, icon) VALUES
(1, 'Edebiyat & Roman', 'edebiyat-roman', NULL, 'bi-book-half'),
(2, 'Tarih & Araştırma', 'tarih-arastirma', NULL, 'bi-hourglass-split'),
(3, 'Felsefe & Düşünce', 'felsefe-dusunce', NULL, 'bi-lightbulb'),
(4, 'Çocuk & Gençlik', 'cocuk-genclik', NULL, 'bi-balloon'),
(5, 'Dünya Klasikleri', 'dunya-klasikleri', 1, 'bi-journal-bookmark');

INSERT INTO showcases (id, title, slug, type, description, icon) VALUES
(1, 'Vitrindekiler', 'vitrindekiler', 'grid', 'Öne çıkan en popüler eserler', 'bi-stars'),
(2, 'Çok Satanlar', 'cok-satanlar', 'grid', 'En çok tercih edilen kitaplar', 'bi-fire'),
(3, 'Yeni Ürünler', 'yeni-urunler', 'grid', 'Rafımıza yeni eklenen taze eserler', 'bi-sparkles'),
(4, 'Tavsiye Kitaplar', 'tavsiye-kitaplar', 'scroll', 'Editörlerimizin özel önerileri', 'bi-hand-thumbs-up-fill'),
(5, 'Seçtiklerimiz', 'sectiklerimiz', 'grid', 'Bu haftanın özel seçkileri', 'bi-award-fill'),
(6, 'Çocuk Kitapları', 'cocuk-kitaplari', 'grid', 'Minik okurlar için eğitici kitaplar', 'bi-emoji-smile'),
(7, 'Haftanın Ürünleri', 'haftanin-urunleri', 'grid', 'Bu haftaya özel indirimli eserler', 'bi-percent'),
(8, 'Tarih Kitapları', 'tarih-kitaplari', 'grid', 'Geçmişten günümüze tarih eserleri', 'bi-journal-text');

INSERT INTO collections (id, title, slug, description, badge_text) VALUES
(1, 'Hasan Âli Yücel Klasikler Serisi', 'hasan-ali-yucel-serisi', 'Dünya edebiyatının en seçkin yapıtları İş Bankası Kültür Yayınları kalitesiyle.', 'Hasan Âli Yücel'),
(2, 'Atatürk’ün Tavsiye Ettiği Kitaplar', 'ataturkun-tavsiyeleri', 'Gazi Mustafa Kemal Atatürk’ün okuduğu ve çevresine tavsiye ettiği başyapıtlar.', 'Tarihi Miras'),
(3, 'Yüzüklerin Efendisi Evreni', 'yuzuklerin-efendisi', 'Orta Dünya efsanesinin tüm serileri ve özel ciltli edisyonları.', 'Orta Dünya'),
(4, 'Stefan Zweig Bütün Eserleri', 'stefan-zweig-serisi', 'İnsan ruhunun derinliklerine yolculuk yaptıran unutulmaz novellalar.', 'Zweig Seçkisi');

INSERT INTO books (id, title, original_title, isbn, author_id, publisher_id, category_id, price, old_price, stock, pages, year, summary, cover_image, is_active) VALUES
(1, 'Nutuk (Özel Ciltli)', 'Nutuk', '9789754580001', 1, 1, 2, 145.00, 220.00, 45, 640, 1927, 'Mustafa Kemal Atatürk’ün Milli Mücadele sürecini kendi kaleminden anlattığı muazzam tarihi eser.', 'nutuk.jpg', 1),
(2, 'Yüzüklerin Efendisi: Yüzük Kardeşliği', 'The Fellowship of the Ring', '9789753420012', 2, 2, 1, 280.00, 350.00, 18, 488, 1954, 'Tek Yüzük’ü yok etmek için yola çıkan dokuz kişilik kardeşliğin serüveni.', 'lotr_1.jpg', 1),
(3, 'Satranç', 'Schachnovelle', '9786053600023', 3, 1, 1, 45.00, 65.00, 120, 84, 1942, 'Bir otel odasında hapsedilen bir adamın zihnini koruma mücadelesi ve satranç tutkusu.', 'satranc.jpg', 1),
(4, 'Suç ve Ceza', 'Prestupleniye i nakazaniye', '9789754580034', 4, 1, 5, 120.00, 180.00, 8, 688, 1866, 'Raskolnikov’un vicdanı, ahlakı ve yoksulluğu üzerine insanlık tarihinin en büyük romanı.', 'suc_ve_ceza.jpg', 1),
(5, 'Dönüşüm', 'Die Verwandlung', '9786053600045', 5, 3, 1, 50.00, 75.00, 30, 96, 1915, 'Gregor Samsa’nın dev bir böceğe dönüşmesiyle başlayan absürt anlatı.', 'donusum.jpg', 1),
(6, 'Devlet-i ‘Aliyye (1. Cilt)', 'Devlet-i Aliyye', '9786051140056', 6, 4, 2, 210.00, 290.00, 25, 420, 2009, 'Halil İnalcık’ın altmış yılı bulan araştırmalarına dayanan Osmanlı ana eseri.', 'devlet_i_aliyye.jpg', 1),
(7, 'Amok Koşucusu', 'Amok', '9786053600067', 3, 1, 1, 42.00, 60.00, 5, 64, 1922, 'Malezya’da bir doktorun bir kadına karşı hissettiği takıntılı tutku.', 'amok.jpg', 1),
(8, 'Yüzüklerin Efendisi: İki Kule', 'The Two Towers', '9789753420029', 2, 2, 1, 280.00, 350.00, 12, 448, 1954, 'Kardeşliğin bozulmasının ardından Frodo ve Sam’in Hüküm Dağı’na yürüyüşü.', 'lotr_2.jpg', 1),
(9, 'Yüzüklerin Efendisi: Kralın Dönüşü', 'The Return of the King', '9789753420036', 2, 2, 1, 295.00, 370.00, 15, 512, 1955, 'Gondor’daki büyük savaş ve Yüzük Çağı’nın muazzam sonu.', 'lotr_3.jpg', 1),
(10, 'Çalıkuşu', 'Çalıkuşu', '9789754580100', 1, 3, 1, 110.00, 150.00, 60, 412, 1922, 'Feride’nin gurur ve aşk dolu yaşam öyküsü.', 'calikusu.jpg', 1);

INSERT INTO book_showcase (book_id, showcase_id) VALUES
(1, 1), (1, 2), (1, 8),
(2, 1), (2, 2),
(3, 1), (3, 2), (3, 3), (3, 7),
(4, 1), (4, 4), (4, 5),
(5, 3), (5, 6),
(6, 1), (6, 8),
(7, 3), (7, 7),
(8, 2), (8, 4),
(9, 2), (9, 5),
(10, 5), (10, 6);

INSERT INTO book_collection (book_id, collection_id) VALUES
(3, 1), (3, 4),
(4, 1),
(7, 1), (7, 4),
(1, 2), (6, 2),
(2, 3), (8, 3), (9, 3);

INSERT INTO orders (id, order_number, user_id, total_amount, shipping_amount, status, payment_method, shipping_address, tracking_number, created_at) VALUES
(1, 'YNC-20260810-001', 2, 470.00, 0.00, 'Tamamlandı', 'Kredi Kartı', 'İstasyon Mah. Akasya Cad. No:12 Etimesgut / Ankara', 'TR9988776655', '2026-08-09 14:30:00'),
(2, 'YNC-20260810-002', 3, 165.00, 20.00, 'Hazırlanıyor', 'EFT / Havale', 'Eryaman Mah. 1. Etap No:45 Etimesgut / Ankara', NULL, '2026-08-10 10:15:00');

INSERT INTO order_items (id, order_id, book_id, quantity, price, total) VALUES
(1, 1, 1, 1, 145.00, 145.00),
(2, 1, 2, 1, 280.00, 280.00),
(3, 1, 7, 1, 45.00, 45.00),
(4, 2, 4, 1, 120.00, 120.00),
(5, 2, 3, 1, 45.00, 45.00);

INSERT INTO transactions (id, order_id, transaction_type, amount, status, gateway_response) VALUES
(1, 1, 'Kredi Kartı', 470.00, 'Onaylandı', 'SUCCESS - POS Ref: 88716253'),
(2, 2, 'EFT / Havale', 165.00, 'Bekliyor', 'Ziraat Bankası Havale Bildirimi Bekleniyor');

INSERT INTO action_logs (id, user_id, action, target_module, target_id, ip_address, details) VALUES
(1, 1, 'Sistem Başlatıldı', 'System', 1, '127.0.0.1', 'Yeniçeri Kitabevi veritabanı başarıyla başlatıldı.');

INSERT INTO settings (setting_key, setting_value) VALUES
('site_title', 'Yeniçeri Kitabevi | Kitabın Güvenli ve Hızlı Adresi'),
('site_description', 'Binlerce roman, tarih, klasikler ve çocuk kitapları avantajlı fiyatlarla Yeniçeri Kitabevi Etimesgut Ankara şubesinde.'),
('site_email', 'iletisim@yenicerikitabevi.com'),
('site_phone', '0312 245 00 00'),
('site_whatsapp', '0555 000 00 00'),
('site_address', 'İstasyon Mah. Hikmet Özer Cad. No:48/A Etimesgut / Ankara'),
('shipping_free_limit', '350.00'),
('flat_shipping_cost', '29.90'),
('payment_merchant_id', ''),
('payment_api_key', ''),
('payment_merchant_salt', ''),
('paytr_test_mode', '1');
