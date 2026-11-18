<?php
/**
 * SAHAF SİPARİŞLERİ: sahafın kitabı satılınca kargoyu sahaf gönderir.
 *  - Satıcı Paneli → Siparişler: yalnızca kendi kitapları, raf kodu, müşteri adı/telefonu/adresi (ödeme alındıktan sonra)
 *  - Sahaf kargo firması + takip numarasını girer (order_shipments). Siparişin tamamı tek sahafınsa
 *    sipariş "Kargolandı" olur ve müşteriye her zamanki kargo e-postası gider; karma siparişte müşteriye
 *    "bir paketiniz kargoya verildi" e-postası gider.
 *  - Ödeme alınınca sahafa (stores.email) "yeni sipariş" e-postası gider (her sipariş/sahaf için bir kez).
 */
if (!defined('SITE_URL')) exit;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_shipments (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, store_id INTEGER NOT NULL,
        carrier TEXT NULL, tracking_number TEXT NULL, tracking_url TEXT NULL, shipped_at DATETIME NULL, notified_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_order_shipments ON order_shipments (order_id, store_id)");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_shipments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, store_id INT NOT NULL,
        carrier VARCHAR(30) NULL, tracking_number VARCHAR(100) NULL, tracking_url VARCHAR(500) NULL, shipped_at TIMESTAMP NULL, notified_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY ux_order_shipments (order_id, store_id), INDEX (store_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
}

/** Sahafın kargolayabileceği durumlar (ödeme alınmış) */
function so_paid_statuses() {
    return ['Hazırlanıyor', 'Kargolandı', 'Tamamlandı'];
}

function so_row_ensure($order_id, $store_id) {
    try {
        db_query("INSERT INTO order_shipments (order_id, store_id) VALUES (?, ?)", [(int)$order_id, (int)$store_id]);
    } catch (Throwable $e) {
        // satır zaten var (benzersiz indeks)
    }
}

/** Siparişteki bu dükkânın kalemleri (raf kodu, kapak ile) */
function so_store_items($order_id, $store_id) {
    return db_fetch_all("SELECT oi.*, b.title, b.isbn, b.shelf_code, b.cover_image, b.nadirkitap_code FROM order_items oi JOIN books b ON b.id = oi.book_id
                         WHERE oi.order_id = ? AND b.store_id = ? ORDER BY oi.id", [(int)$order_id, (int)$store_id]);
}

/** Siparişte kitabı olan sahaf dükkânları (store_id listesi) */
function so_order_store_ids($order_id) {
    return array_map('intval', array_column(db_fetch_all("SELECT DISTINCT b.store_id FROM order_items oi JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ? AND b.store_id IS NOT NULL", [(int)$order_id]), 'store_id'));
}

/** Siparişin bütün kalemleri bu dükkânın mı? (Yeniçeri'nin ya da başka sahafın kitabı yoksa) */
function so_order_is_single_store($order_id, $store_id) {
    $r = db_fetch("SELECT COUNT(*) AS n, SUM(CASE WHEN b.store_id = ? THEN 1 ELSE 0 END) AS mine FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?", [(int)$store_id, (int)$order_id]);
    return $r && (int)$r['n'] > 0 && (int)$r['n'] === (int)$r['mine'];
}

/** Dükkânın siparişleri: $filter = 'gonderilecek' | 'gonderildi' | 'bekleyen' | '' (iptaller hariç hepsi) | 'iptal' */
function so_store_orders($store_id, $filter = '', $limit = 100) {
    $where = "o.id IN (SELECT oi.order_id FROM order_items oi JOIN books b ON b.id = oi.book_id WHERE b.store_id = ?)";
    if ($filter === 'gonderilecek') $where .= " AND o.status = 'Hazırlanıyor' AND (sh.shipped_at IS NULL)";
    elseif ($filter === 'gonderildi') $where .= " AND sh.shipped_at IS NOT NULL";
    elseif ($filter === 'bekleyen') $where .= " AND o.status = 'Ödeme Bekliyor'";
    elseif ($filter === 'iptal') $where .= " AND o.status = 'İptal'";
    else $where .= " AND o.status != 'İptal'";
    return db_fetch_all("SELECT o.*, u.name AS customer_name, u.phone AS customer_phone,
                                sh.carrier AS sh_carrier, sh.tracking_number AS sh_tracking, sh.tracking_url AS sh_tracking_url, sh.shipped_at AS sh_shipped_at
                         FROM orders o LEFT JOIN users u ON u.id = o.user_id
                         LEFT JOIN order_shipments sh ON sh.order_id = o.id AND sh.store_id = ?
                         WHERE {$where} ORDER BY o.id DESC LIMIT " . (int)$limit, [(int)$store_id, (int)$store_id]);
}

/** Gönderilmeyi bekleyen (ödemesi alınmış, kargo girilmemiş) sipariş sayısı */
function so_to_ship_count($store_id) {
    $r = db_fetch("SELECT COUNT(*) AS c FROM orders o LEFT JOIN order_shipments sh ON sh.order_id = o.id AND sh.store_id = ?
                   WHERE o.status = 'Hazırlanıyor' AND sh.shipped_at IS NULL
                     AND o.id IN (SELECT oi.order_id FROM order_items oi JOIN books b ON b.id = oi.book_id WHERE b.store_id = ?)", [(int)$store_id, (int)$store_id]);
    return (int)($r['c'] ?? 0);
}

/** Siparişin sahaf gönderileri (yönetim ve takip sayfası için) */
function so_shipments($order_id) {
    return db_fetch_all("SELECT sh.*, s.name AS store_name, s.phone AS store_phone FROM order_shipments sh LEFT JOIN stores s ON s.id = sh.store_id WHERE sh.order_id = ? ORDER BY sh.id", [(int)$order_id]);
}

/**
 * Sahaf kargo bilgisini kaydeder. Dönüş: [başarılı mı, mesaj]
 */
function so_ship($order_id, $store_id, $carrier, $tracking_number) {
    $order_id = (int)$order_id;
    $store_id = (int)$store_id;
    $carriers = shipping_carriers();
    $carrier = array_key_exists((string)$carrier, $carriers) ? (string)$carrier : '';
    $tracking_number = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$tracking_number)), 0, 100, 'UTF-8');
    if ($carrier === '') return [false, 'Kargo firmasını seçin.'];
    if ($tracking_number === '') return [false, 'Takip numarasını yazın.'];

    $o = db_fetch("SELECT id, order_number, status, tracking_number FROM orders WHERE id = ?", [$order_id]);
    if (!$o || !so_store_items($order_id, $store_id)) return [false, 'Sipariş bulunamadı.'];
    if ($o['status'] === 'Ödeme Bekliyor') return [false, 'Bu siparişin ödemesi henüz alınmadı, göndermeyin.'];
    if ($o['status'] === 'İptal') return [false, 'Bu sipariş iptal edildi, göndermeyin.'];

    so_row_ensure($order_id, $store_id);
    $prev = db_fetch("SELECT * FROM order_shipments WHERE order_id = ? AND store_id = ?", [$order_id, $store_id]);
    $changed = !$prev || $prev['tracking_number'] !== $tracking_number || $prev['carrier'] !== $carrier;
    $now = date('Y-m-d H:i:s');
    db_query("UPDATE order_shipments SET carrier = ?, tracking_number = ?, shipped_at = COALESCE(shipped_at, ?) WHERE order_id = ? AND store_id = ?",
        [$carrier, $tracking_number, $now, $order_id, $store_id]);

    $single = so_order_is_single_store($order_id, $store_id);
    $mail_ok = null;
    if ($single) {
        // Tek sahaflık sipariş: siparişin kendi kargo bilgisi olur, müşteri her zamanki e-postayı alır
        db_query("UPDATE orders SET tracking_number = ?, shipping_carrier = ?, tracking_url = NULL,
                         status = CASE WHEN status = 'Hazırlanıyor' THEN 'Kargolandı' ELSE status END,
                         shipped_at = COALESCE(shipped_at, ?) WHERE id = ?", [$tracking_number, $carrier, $now, $order_id]);
        if ($changed) $mail_ok = send_order_shipped($order_id, true);
    } else {
        if ($changed) $mail_ok = so_send_partial_shipped($order_id, $store_id);
        // Bütün kalemler sahaflarınsa ve hepsi gönderildiyse sipariş Kargolandı olur
        $all = db_fetch("SELECT COUNT(*) AS n, SUM(CASE WHEN b.store_id IS NOT NULL AND sh.shipped_at IS NOT NULL THEN 1 ELSE 0 END) AS done
                         FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id LEFT JOIN order_shipments sh ON sh.order_id = oi.order_id AND sh.store_id = b.store_id
                         WHERE oi.order_id = ?", [$order_id]);
        if ($all && (int)$all['n'] > 0 && (int)$all['n'] === (int)$all['done']) {
            db_query("UPDATE orders SET status = 'Kargolandı', shipped_at = COALESCE(shipped_at, ?) WHERE id = ? AND status = 'Hazırlanıyor'", [$now, $order_id]);
        }
    }
    $store_name = db_fetch("SELECT name FROM stores WHERE id = ?", [$store_id])['name'] ?? ('#' . $store_id);
    log_action('Sahaf Kargoladı', 'Orders', $order_id, "{$store_name}: {$o['order_number']} → " . ($carriers[$carrier]['name'] ?? $carrier) . " / {$tracking_number}");

    $msg = "Sipariş {$o['order_number']} için kargo bilgisi kaydedildi.";
    if ($mail_ok === true) $msg .= ' Müşteriye kargo e-postası gönderildi.';
    elseif ($mail_ok === false) $msg .= ' Müşteriye e-posta gönderilemedi; Yeniçeri Kitabevi bilgilendirildi.';
    return [true, $msg];
}

/** Karma siparişte müşteriye: "siparişinizin bir paketi kargoya verildi" */
function so_send_partial_shipped($order_id, $store_id) {
    $o = order_load($order_id);
    $sh = db_fetch("SELECT * FROM order_shipments WHERE order_id = ? AND store_id = ?", [(int)$order_id, (int)$store_id]);
    if (!$o || !$sh || trim((string)$o['customer_email']) === '') return false;
    $items = so_store_items($order_id, $store_id);
    $pseudo = ['shipping_carrier' => $sh['carrier'], 'tracking_number' => $sh['tracking_number'], 'tracking_url' => $sh['tracking_url']];
    $carrier = order_carrier_name($pseudo);
    $link = order_tracking_link($pseudo);
    $name = trim((string)$o['customer_name']) ?: 'Değerli müşterimiz';
    $subject = 'Siparişinizin bir paketi kargoya verildi - ' . $o['order_number'];
    $list_html = '';
    $list_text = '';
    foreach ($items as $it) {
        $list_html .= '<li>' . e($it['title'] ?: 'Kitap #' . $it['book_id']) . ' × ' . (int)$it['quantity'] . '</li>';
        $list_text .= '- ' . ($it['title'] ?: 'Kitap #' . $it['book_id']) . ' x' . (int)$it['quantity'] . "\n";
    }
    $html = mail_layout($subject,
        '<h1 style="font-size:20px;margin:0 0 8px">Bir paketiniz yola çıktı!</h1>'
        . '<p style="font-size:14px;color:#444">Merhaba ' . e($name) . ', <b>' . e($o['order_number']) . '</b> numaralı siparişinizdeki şu kitaplar ayrı bir paketle kargoya verildi:</p>'
        . '<ul style="font-size:14px">' . $list_html . '</ul>'
        . '<div style="background:#f6f6f8;border-radius:8px;padding:14px;font-size:14px;margin:16px 0">' . ($carrier !== '' && $carrier !== 'Diğer' ? 'Kargo firması: <b>' . e($carrier) . '</b><br>' : '') . 'Takip numarası: <b style="font-family:monospace">' . e($sh['tracking_number']) . '</b></div>'
        . ($link ? mail_button($link, 'Kargomu Takip Et') : '')
        . '<p style="font-size:13px;color:#666">Siparişinizin diğer kitapları ayrı paketle gönderilecek; her paket için ayrı e-posta alırsınız.</p>'
        . '<p style="font-size:14px"><a href="' . e(order_tracking_page_url($o)) . '" style="color:#dc3545">Sipariş durumunu sitemizde görüntüleyin</a></p>');
    $text = "Merhaba {$name},\n\n{$o['order_number']} numaralı siparişinizdeki şu kitaplar ayrı bir paketle kargoya verildi:\n{$list_text}\n"
        . ($carrier !== '' ? "Kargo firması: {$carrier}\n" : '') . "Takip numarası: {$sh['tracking_number']}\n" . ($link ? "Kargo takibi: {$link}\n" : '')
        . "\nDiğer kitaplar ayrı paketle gönderilecek.\nSipariş durumu: " . order_tracking_page_url($o) . "\n\n" . APP_NAME;
    return send_mail($o['customer_email'], $subject, $html, $text, 'order_shipped_part', $o['id']);
}

/**
 * Ödeme alınan siparişte kitabı olan her sahafa "yeni sipariş, kargolayın" e-postası (her sahafa bir kez).
 * Ödeme Bekliyor / İptal siparişte gönderilmez.
 */
function so_notify_new_order($order_id) {
    $o = order_load($order_id);
    if (!$o || !in_array($o['status'], so_paid_statuses(), true)) return 0;
    $sent = 0;
    foreach (so_order_store_ids($order_id) as $sid) {
        $store = db_fetch("SELECT id, name, email FROM stores WHERE id = ?", [$sid]);
        $to = trim((string)($store['email'] ?? ''));
        if (!$store || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        so_row_ensure($order_id, $sid);
        $claimed = db_query("UPDATE order_shipments SET notified_at = ? WHERE order_id = ? AND store_id = ? AND notified_at IS NULL", [date('Y-m-d H:i:s'), (int)$order_id, $sid])->rowCount() === 1;
        if (!$claimed) continue;

        $items = so_store_items($order_id, $sid);
        $rows = '';
        $list_text = '';
        $sum = 0.0;
        foreach ($items as $it) {
            $sum += (float)$it['total'];
            $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f0f0f0">' . e($it['title'] ?: 'Kitap #' . $it['book_id']) . ' <span style="color:#888">× ' . (int)$it['quantity'] . '</span>'
                . (trim((string)$it['shelf_code']) !== '' ? '<br><span style="font-size:12px;color:#c2410c">Raf: <b>' . e($it['shelf_code']) . '</b></span>' : '')
                . '</td><td align="right" style="padding:8px 0;border-bottom:1px solid #f0f0f0;white-space:nowrap">' . e(format_price($it['total'])) . '</td></tr>';
            $list_text .= '- ' . ($it['title'] ?: 'Kitap #' . $it['book_id']) . ' x' . (int)$it['quantity'] . (trim((string)$it['shelf_code']) !== '' ? ' [Raf: ' . $it['shelf_code'] . ']' : '') . ' ' . format_price($it['total']) . "\n";
        }
        $panel = abs_url('satici/index.php?tab=siparis');
        $subject = 'Yeni sipariş: ' . $o['order_number'] . ' - kargolamanız bekleniyor';
        $html = mail_layout($subject,
            '<h1 style="font-size:20px;margin:0 0 8px">Kitabınız satıldı!</h1>'
            . '<p style="font-size:14px;color:#444">Merhaba ' . e($store['name']) . ', <b>' . e($o['order_number']) . '</b> numaralı siparişin ödemesi alındı. Aşağıdaki kitapları paketleyip kargoya verin, ardından takip numarasını Satıcı Paneli → Siparişler’e girin.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $rows
            . '<tr><td style="padding:8px 0;font-weight:bold">Kitaplarınızın tutarı</td><td align="right" style="padding:8px 0;font-weight:bold">' . e(format_price($sum)) . '</td></tr></table>'
            . '<div style="background:#f6f6f8;border-radius:8px;padding:14px;font-size:14px;margin:16px 0"><b>Alıcı</b><br>' . e($o['customer_name']) . (trim((string)$o['customer_phone']) !== '' ? ' • ' . e($o['customer_phone']) : '')
            . '<br>' . nl2br(e($o['shipping_address'])) . '</div>'
            . mail_button($panel, 'Satıcı Panelinde Aç')
            . '<p style="font-size:12px;color:#888">Takip numarasını girdiğinizde müşteriye otomatik e-posta gider. Sorunuz için ' . e(setting('site_phone')) . '.</p>');
        $text = "Merhaba {$store['name']},\n\n{$o['order_number']} numaralı siparişin ödemesi alındı. Şu kitapları kargolayın:\n{$list_text}\n"
            . "Alıcı: {$o['customer_name']} {$o['customer_phone']}\nAdres: {$o['shipping_address']}\n\nTakip numarasını girin: {$panel}\n\n" . APP_NAME;
        if (send_mail($to, $subject, $html, $text, 'store_new_order', $o['id'])) {
            $sent++;
        } else {
            db_query("UPDATE order_shipments SET notified_at = NULL WHERE order_id = ? AND store_id = ?", [(int)$order_id, $sid]);
        }
    }
    return $sent;
}
