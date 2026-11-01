<?php
/**
 * NADİRKİTAP OTOMATİK SENKRONİZASYON VE SATILAN KİTAPLARI DÜŞÜRME MOTORU (_nk_sync.php)
 * 
 * Bu motor:
 * 1. Sahafın NadirKitap mağazasındaki aktif kitapları tarar.
 * 2. NadirKitap'ta artık bulunmayan (satılmış veya silinmiş) kitapları otomatik olarak
 *    Yeniçeri Kitabevi'ndeki sahaf sayfasından düşürür (stok = 0, is_active = 0 yapar ve vitrinden kaldırır).
 * 3. NadirKitap'a yeni eklenen kitapları sahafın dükkânına kaydeder.
 * 4. Fiyatı değişen mevcut kitapları güncel tutar.
 */
if (!defined('SITE_URL')) exit;

// Veritabanı sütun kontrolü (stores tablosuna son senkronizasyon zamanı ve sonucu ekle)
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $existing_store_cols = array_column($pdo->query("PRAGMA table_info(stores)")->fetchAll(), 'name');
    if (!in_array('last_sync_at', $existing_store_cols, true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN last_sync_at TEXT DEFAULT NULL;");
    }
    if (!in_array('last_sync_result', $existing_store_cols, true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN last_sync_result TEXT DEFAULT NULL;");
    }
} else {
    $existing_store_cols = array_column($pdo->query("SHOW COLUMNS FROM stores")->fetchAll(), 'Field');
    if (!in_array('last_sync_at', $existing_store_cols, true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN last_sync_at DATETIME NULL;");
    }
    if (!in_array('last_sync_result', $existing_store_cols, true)) {
        $pdo->exec("ALTER TABLE stores ADD COLUMN last_sync_result TEXT NULL;");
    }
}

/**
 * NadirKitap sayfalarını gerçek tarayıcı başlıklarıyla cURL üzerinden çeker.
 */
function nk_sync_curl_get($url, $cookie_file = null) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\nAccept: text/html\r\n",
                'timeout' => 20
            ]
        ]);
        $html = @file_get_contents($url, false, $ctx);
        return [
            'ok'    => ($html !== false && strlen($html) > 0),
            'code'  => $html !== false ? 200 : 0,
            'html'  => (string)$html,
            'error' => $html === false ? 'file_get_contents basarisiz' : ''
        ];
    }

    $ch = curl_init();
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer: https://www.nadirkitap.com/',
        'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1'
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '' // Otomatik gzip/deflate çözümü
    ]);

    if ($cookie_file) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    }

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok'    => ($http_code === 200 && $html !== false),
        'code'  => (int)$http_code,
        'html'  => (string)$html,
        'error' => $error
    ];
}

/**
 * NadirKitap mağaza HTML sayfasından aktif kitap kodlarını ve temel ürün bilgilerini ayıklar.
 */
function nk_sync_parse_items_from_html($html) {
    $products = [];

    // Cloudflare doğrulamasına takıldıysa bildir
    if (stripos($html, 'Just a moment...') !== false || stripos($html, 'window._cf_chl_opt') !== false) {
        return [
            'is_cloudflare' => true,
            'items'         => []
        ];
    }

    // 1. Ürün kartlarını veya doğrudan kitap bağlantılarını tespit et
    // NadirKitap link deseni: href="...-kitap12345678.html" veya href="...-urun12345678.html"
    if (preg_match_all('#href=["\'](https?://www\.nadirkitap\.com)?/([^"\']*-(?:kitap|urun)(\d+)\.html)["\']#i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $code = trim($m[3]);
            $path = trim($m[2]);
            $full_url = 'https://www.nadirkitap.com/' . $path;

            if (!isset($products[$code])) {
                $products[$code] = [
                    'code'   => $code,
                    'url'    => $full_url,
                    'title'  => '',
                    'price'  => 0.0,
                    'image'  => null
                ];
            }
        }
    }

    // 2. Ürün başlıklarını ve fiyatlarını kart bloklarından zenginleştir
    // NadirKitap kartları: <div class="urun-kutu ..."> veya <li class="product ...">
    if (preg_match_all('#<(?:div|li)[^>]*class=["\'][^"\']*(?:urun-kutu|product|urun)[^"\']*["\'][^>]*>(.*?)</(?:div|li)>#is', $html, $card_matches)) {
        foreach ($card_matches[1] as $card_html) {
            if (preg_match('#-(?:kitap|urun)(\d+)\.html#i', $card_html, $cm)) {
                $code = trim($cm[1]);
                if (isset($products[$code])) {
                    // Başlık ayıkla
                    if (preg_match('#<h\d[^>]*>(?:<a[^>]*>)?(.*?)(?:</a>)?</h\d>#is', $card_html, $tm)) {
                        $products[$code]['title'] = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    } elseif (preg_match('#title=["\']([^"\']+)["\']#i', $card_html, $tm)) {
                        $products[$code]['title'] = trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }

                    // Fiyat ayıkla: 25,00 TL / 150.00 TL / 1.250,00 TL
                    if (preg_match('#(\d[\d\.,]*)\s*(?:TL|₺)#iu', $card_html, $pm)) {
                        $products[$code]['price'] = nk_sync_parse_price($pm[1]);
                    }

                    // Görsel ayıkla
                    if (preg_match('#(?:data-src|src)=["\']([^"\']*(?:nk_|kitap)[^"\']*\.(?:jpg|jpeg|png|webp))["\']#i', $card_html, $im)) {
                        $products[$code]['image'] = trim($im[1]);
                    }
                }
            }
        }
    }

    return [
        'is_cloudflare' => false,
        'items'         => $products
    ];
}

/**
 * Bir alt dükkanda (sahaf), verilen aktif NadirKitap kodları DIŞINDA kalan kitapları tespit edip
 * alt dükkandan otomatik olarak düşürür (is_active = 0, stock = 0).
 * 
 * @param int $store_id Alt dükkan ID
 * @param array $active_nk_codes NadirKitap'ta şu anda vitrinde bulunan aktif ürün kodları
 * @return array Düşürülen (satılan) kitapların listesi
 */
function nk_drop_sold_books($store_id, array $active_nk_codes) {
    global $pdo;
    $store_id = (int)$store_id;
    if ($store_id <= 0) return [];

    // Alt dükkanda şu an aktif olan ve NadirKitap koduna sahip tüm kitapları çek
    $my_books = db_fetch_all(
        "SELECT id, title, nadirkitap_code, price FROM books WHERE store_id = ? AND is_active = 1 AND nadirkitap_code IS NOT NULL AND nadirkitap_code != ''",
        [$store_id]
    );

    if (empty($my_books)) {
        return [];
    }

    $active_map = array_fill_keys(array_map('strval', $active_nk_codes), true);
    $dropped = [];

    foreach ($my_books as $b) {
        $code = trim((string)$b['nadirkitap_code']);
        // Eğer kitap NadirKitap'ın aktif vitrininde artık YOKSA -> Satılmıştır!
        if (!isset($active_map[$code])) {
            $bid = (int)$b['id'];
            db_query("UPDATE books SET is_active = 0, stock = 0 WHERE id = ?", [$bid]);
            // Vitrinlerden (showcases) de temizle ki anasayfada görünmesin
            db_query("DELETE FROM book_showcase WHERE book_id = ?", [$bid]);

            $dropped[] = [
                'id'    => $bid,
                'title' => $b['title'],
                'code'  => $code,
                'price' => (float)$b['price']
            ];
        }
    }

    return $dropped;
}

/**
 * Belirli bir sahaf (alt dükkân) için tam senkronizasyon çalıştırır:
 * 1. NadirKitap sayfalarını tarar.
 * 2. Satılan kitapları Yeniçeri'den anında düşürür.
 * 3. Yeni kitapları alt dükkâna ekler.
 * 
 * @param int $store_id Alt dükkan ID
 * @param int $max_pages Taranacak azami sayfa sayısı (varsayılan: 5 sayfa ~ 125-250 kitap)
 * @return array Senkronizasyon sonuç raporu
 */
function nk_sync_store($store_id, $max_pages = 5) {
    global $pdo;
    $store_id = (int)$store_id;
    $store = db_fetch("SELECT * FROM stores WHERE id = ?", [$store_id]);

    if (!$store) {
        return ['success' => false, 'message' => 'Alt dükkân bulunamadı.'];
    }

    $store_name = $store['name'];
    $nk_code    = trim((string)$store['nadirkitap_code']);
    $nk_url     = trim((string)$store['nadirkitap_url']);

    if ($nk_code === '' && $nk_url === '') {
        return [
            'success' => false,
            'message' => "{$store_name} için tanımlı bir NadirKitap mağaza bağlantısı veya kodu bulunamadı."
        ];
    }

    // Başlangıç URL'si oluştur
    $base_target_url = '';
    if ($nk_url !== '' && stripos($nk_url, 'http') === 0) {
        $base_target_url = $nk_url;
    } elseif ($nk_code !== '') {
        $base_target_url = "https://www.nadirkitap.com/kitapara.php?satici={$nk_code}";
    }

    $cookie_file = sys_get_temp_dir() . '/nk_cookie_store_' . $store_id . '.txt';
    $all_active_items = [];
    $pages_scanned = 0;
    $hit_cloudflare = false;
    $complete = false;      // mağazanın son sayfasına kadar okundu mu? (satılan düşürme yalnızca o zaman güvenli)
    $fetch_error = '';
    $started = microtime(true);
    $time_budget = 45; // saniye: web isteği sunucu/Render zaman aşımına (502) takılmasın
    @set_time_limit(120);

    // Sayfaları gez
    for ($page = 1; $page <= $max_pages; $page++) {
        if ($page > 1 && microtime(true) - $started > $time_budget) {
            break; // süre doldu: tarama yarım kalır, satılan düşürme yapılmaz
        }
        $page_url = $base_target_url;
        if ($page > 1) {
            $sep = (strpos($page_url, '?') !== false) ? '&' : '?';
            $page_url .= "{$sep}sayfa={$page}";
        }

        $res = nk_sync_curl_get($page_url, $cookie_file);
        if (!$res['ok']) {
            $fetch_error = 'NadirKitap sayfası açılamadı (HTTP ' . (int)$res['code'] . ($res['error'] ? ', ' . $res['error'] : '') . ')';
            break;
        }

        $parsed = nk_sync_parse_items_from_html($res['html']);
        if ($parsed['is_cloudflare']) {
            $hit_cloudflare = true;
            break;
        }

        if (empty($parsed['items'])) {
            // Boş sayfa: ilk sayfa değilse mağazanın sonuna gelindi demektir
            if ($page > 1) {
                $complete = true;
            } else {
                $fetch_error = 'NadirKitap mağaza sayfasında kitap bulunamadı (sayfa yapısı değişmiş olabilir)';
            }
            break;
        }

        $new_on_page = 0;
        $pages_scanned++;
        foreach ($parsed['items'] as $c => $it) {
            if (!isset($all_active_items[$c])) {
                $all_active_items[$c] = $it;
                $new_on_page++;
            }
        }
        if ($new_on_page === 0) {
            // Aynı liste tekrar döndü: sayfalama çalışmıyor olabilir, sonraki sayfalar hiç okunmamış olabilir.
            // Tamamlandı sayılmaz; aksi hâlde ilk sayfa dışındaki tüm kitaplar "satıldı" diye düşerdi.
            break;
        }

        // İstekler arası nazik bekleme (rate limit)
        usleep(300000); // 0.3 saniye
    }

    // Hiç sayfa okunamadıysa "başarılı" deme: gerçek sebebi kaydet ve göster
    if (!$hit_cloudflare && empty($all_active_items)) {
        $why = $fetch_error ?: 'NadirKitap\'tan kitap listesi alınamadı';
        db_query("UPDATE stores SET last_sync_at = ?, last_sync_result = ? WHERE id = ?", [date('Y-m-d H:i:s'), 'Hata: ' . $why, $store_id]);
        return ['success' => false, 'message' => 'Senkronizasyon yapılamadı: ' . $why . '. Hiçbir kitap değiştirilmedi.', 'store_name' => $store_name];
    }

    // Cloudflare engeli durumunda bilgilendir
    if ($hit_cloudflare && empty($all_active_items)) {
        $msg = "NadirKitap güvenlik duvarı (Cloudflare) web sunucusunun doğrudan taramasını durdurdu. Masaüstü kitapekapp uygulaması üzerinden oturumlu aktarımı tercih edebilirsiniz.";
        db_query("UPDATE stores SET last_sync_at = ?, last_sync_result = ? WHERE id = ?", [
            date('Y-m-d H:i:s'),
            'Hata: Cloudflare bot engeli',
            $store_id
        ]);
        return [
            'success'        => false,
            'is_cloudflare'  => true,
            'message'        => $msg,
            'store_name'     => $store_name
        ];
    }

    // 1. SATILAN KİTAPLARI DÜŞÜR (Eğer NadirKitap'tan en az bir aktif ürün alındıysa güvenle silme yap)
    $active_codes = array_keys($all_active_items);
    $dropped_books = [];
    // Yalnızca mağazanın tamamı okunduysa düşür; aksi hâlde okunmayan sayfalardaki kitaplar "satıldı" sanılırdı
    if (!empty($active_codes) && $complete) {
        $dropped_books = nk_drop_sold_books($store_id, $active_codes);
    }

    // 2. YENİ EKLENEN VEYA TEKRAR AKTİF OLAN KİTAPLARI GÜNCELLE
    $new_added = 0;
    $reactivated = 0;
    $price_updated = 0;

    foreach ($all_active_items as $code => $item) {
        $existing = db_fetch("SELECT id, is_active, price FROM books WHERE nadirkitap_code = ? AND store_id = ?", [$code, $store_id]);
        if ($existing) {
            if ((int)$existing['is_active'] === 0) {
                // Önceden pasif edilmiş ama şimdi Nadir'de tekrar vitrindeyse aktifleştir
                db_query("UPDATE books SET is_active = 1, stock = 1 WHERE id = ?", [$existing['id']]);
                $reactivated++;
            }
            // NadirKitap'ta fiyatı değiştiyse güncelle
            if ($item['price'] > 0 && abs((float)$existing['price'] - $item['price']) >= 0.01) {
                db_query("UPDATE books SET price = ? WHERE id = ?", [$item['price'], $existing['id']]);
                $price_updated++;
            }
        } else {
            // Tamamen yeni bir kitap: liste sayfasında yazar/kategori bilgisi yok. Yanlış bilgiyle satışa
            // açılmasın diye PASİF eklenir; aktarım uygulamasıyla gelince tam bilgileriyle satışa açılır.
            $title = trim($item['title'] ?: "NadirKitap #{$code}");
            $price = $item['price'] > 0 ? $item['price'] : 50.00;
            $cover = $item['image'] ?: 'book_placeholder.svg';

            try {
                $author_id    = nk_sync_placeholder_id('authors', 'Kolektif / Belirtilmemiş');
                $publisher_id = nk_sync_placeholder_id('publishers', 'Belirtilmemiş');
                $category_id  = nk_sync_placeholder_id('categories', 'Genel / Sahaf');

                db_query(
                    "INSERT INTO books (store_id, title, author_id, publisher_id, category_id, price, stock, cover_image, is_active, nadirkitap_code, source_url) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?)",
                    [$store_id, $title, $author_id, $publisher_id, $category_id, $price, $cover, $code, $item['url']]
                );
                $book_id = (int)db_last_id();
                book_refresh_slug($book_id); // okunabilir adres
                book_touch($book_id); // site haritası lastmod
                $new_added++;
            } catch (Throwable $e) {
                error_log('[Yeniceri nk_sync] Kitap eklenemedi (' . $code . '): ' . $e->getMessage());
            }
        }
    }

    $drop_cnt = count($dropped_books);
    $result_text = "Başarılı. Nadir'de Satılan Düşürülen: {$drop_cnt}, Yeni (pasif) Eklenen: {$new_added}, Yeniden Aktif: {$reactivated}, Fiyatı Güncellenen: {$price_updated}, Taranan Sayfa: {$pages_scanned}" . ($complete ? '' : ' (mağazanın tamamı okunamadı, düşürme yapılmadı)');

    db_query("UPDATE stores SET last_sync_at = ?, last_sync_result = ? WHERE id = ?", [
        date('Y-m-d H:i:s'),
        $result_text,
        $store_id
    ]);

    log_action('NadirKitap Senkronizasyonu', 'Stores', $store_id, "{$store_name}: {$result_text}");

    // Bildirim kutusu HTML göstermez: düz metin
    $msg = "Senkronizasyon tamamlandı ({$pages_scanned} sayfa, NadirKitap'ta " . count($all_active_items) . " kitap). ";
    $msg .= $complete ? "Satılmış {$drop_cnt} kitap dükkândan düşürüldü." : "Mağazanın tamamı okunamadığı için satılan kitap düşürme yapılmadı.";
    if ($new_added > 0) {
        $msg .= " Sitede olmayan {$new_added} kitap pasif olarak eklendi; aktarım uygulamasıyla aktarınca satışa açılır.";
    }
    if ($reactivated > 0) {
        $msg .= " {$reactivated} kitap yeniden satışa açıldı.";
    }
    if ($price_updated > 0) {
        $msg .= " {$price_updated} kitabın fiyatı güncellendi.";
    }

    return [
        'success'            => true,
        'store_name'         => $store_name,
        'scanned_pages'      => $pages_scanned,
        'active_on_nadir'    => count($all_active_items),
        'sold_dropped_count' => $drop_cnt,
        'sold_dropped_items' => $dropped_books,
        'new_added_count'    => $new_added,
        'reactivated_count'  => $reactivated,
        'message'            => $msg
    ];
}

/** Türkçe/İngilizce yazılmış fiyatı sayıya çevirir: "1.250,00" -> 1250.00, "150.00" -> 150.00, "1.250" -> 1250.00 */
function nk_sync_parse_price($raw) {
    $s = trim((string)$raw, " .,");
    if (strpos($s, ',') !== false) {
        // Virgül varsa ondalık ayırıcıdır, noktalar binlik ayırıcıdır
        $s = str_replace(',', '.', str_replace('.', '', $s));
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        // Yalnızca binlik ayırıcı nokta: 1.250
        $s = str_replace('.', '', $s);
    }
    return max(0.0, round((float)$s, 2));
}

/** Yer tutucu yazar / yayınevi / kategori kaydı (yoksa oluşturur) */
function nk_sync_placeholder_id($table, $name) {
    $row = find_by_name($table, $name);
    if ($row) {
        return (int)$row['id'];
    }
    db_query("INSERT INTO {$table} (name, slug) VALUES (?, ?)", [$name, unique_slug($table, $name)]);
    return (int)db_last_id();
}
