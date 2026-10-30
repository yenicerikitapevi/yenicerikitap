<?php
/**
 * E-POSTA VE KARGO TAKİBİ
 * - SMTP ile gönderim (Site Ayarları → E-posta). SMTP girilmemişse PHP mail() denenir (Render'da genelde çalışmaz).
 * - Müşteriye: "Siparişiniz alındı" (havale/EFT'de sipariş anında, kartta ödeme onayında) ve "Kargoya verildi".
 * - Mağazaya: yeni sipariş bildirimi (Site Ayarları'nda bildirim adresi girildiyse).
 * - Her gönderim mail_log tablosuna yazılır; aynı e-posta iki kez gönderilmez (orders.mail_*_sent_at).
 */
if (!defined('SITE_URL')) exit;

(function () {
    global $pdo;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (id INTEGER PRIMARY KEY AUTOINCREMENT, to_email TEXT, subject TEXT, kind TEXT, order_id INTEGER, status TEXT, error TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
        $have = array_column($pdo->query("PRAGMA table_info(orders)")->fetchAll(), 'name');
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_log (id INT AUTO_INCREMENT PRIMARY KEY, to_email VARCHAR(190), subject VARCHAR(255), kind VARCHAR(30), order_id INT NULL, status VARCHAR(20), error TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $have = array_column($pdo->query("SHOW COLUMNS FROM orders")->fetchAll(), 'Field');
    }
    foreach (['shipping_carrier' => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL', 'tracking_url' => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL', 'shipped_at' => $sqlite ? 'DATETIME' : 'DATETIME NULL',
              'mail_confirm_sent_at' => $sqlite ? 'DATETIME' : 'DATETIME NULL', 'mail_shipped_sent_at' => $sqlite ? 'DATETIME' : 'DATETIME NULL'] as $c => $t) {
        if (!in_array($c, $have, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN {$c} {$t};");
        }
    }
})();

/**
 * Kargo firmaları ve takip adresi kalıpları ({no} = takip numarası).
 * Kalıbı olmayan firmada müşteriye firmanın takip sayfası ve numara gösterilir.
 * Firmalar adres biçimini değiştirebilir; siparişe tam takip bağlantısı da elle girilebilir.
 */
function shipping_carriers() {
    return [
        'yurtici' => ['name' => 'Yurtiçi Kargo', 'url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code={no}'],
        'aras'    => ['name' => 'Aras Kargo', 'url' => 'https://kargotakip.araskargo.com.tr/mainpage.aspx?code={no}'],
        'ptt'     => ['name' => 'PTT Kargo', 'url' => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q={no}'],
        'surat'   => ['name' => 'Sürat Kargo', 'url' => 'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno={no}'],
        'mng'     => ['name' => 'MNG Kargo (DHL eCommerce)', 'url' => '', 'home' => 'https://www.mngkargo.com.tr/'],
        'hepsijet'=> ['name' => 'HepsiJet', 'url' => '', 'home' => 'https://www.hepsijet.com/'],
        'kolaygelsin' => ['name' => 'Kolay Gelsin', 'url' => '', 'home' => 'https://www.kolaygelsin.com/'],
        'diger'   => ['name' => 'Diğer', 'url' => ''],
    ];
}

/** Müşteriye gösterilecek takip bağlantısı: elle girilen tam adres > firma kalıbı > firmanın ana sayfası */
function order_tracking_link($o) {
    if (!empty($o['tracking_url']) && preg_match('#^https://#i', $o['tracking_url'])) {
        return $o['tracking_url'];
    }
    $c = shipping_carriers()[$o['shipping_carrier'] ?? ''] ?? null;
    if (!$c || trim((string)$o['tracking_number']) === '') return null;
    if ($c['url'] !== '') return str_replace('{no}', rawurlencode(trim($o['tracking_number'])), $c['url']);
    return $c['home'] ?? null;
}

function order_carrier_name($o) {
    return shipping_carriers()[$o['shipping_carrier'] ?? '']['name'] ?? '';
}

function mail_from_email() {
    return setting('mail_from_email', setting('smtp_user', setting('site_email', 'iletisim@yenicerikitabevi.com')));
}

function mail_log_write($to, $subject, $kind, $order_id, $status, $error = '') {
    try {
        db_query("INSERT INTO mail_log (to_email, subject, kind, order_id, status, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$to, $subject, $kind, $order_id ?: null, $status, mb_substr((string)$error, 0, 500, 'UTF-8'), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
    }
}

function mail_encode_header($s) {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/** RFC 5322 ileti (HTML + düz metin) */
function mail_build_message($to, $subject, $html, $text, $from, $from_name, $reply_to = '') {
    $boundary = 'b' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($from, '@'), 1) ?: 'yenicerikitabevi.com';
    $h = [
        'Date: ' . date('r'),
        'From: ' . mail_encode_header($from_name) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if ($reply_to !== '') $h[] = 'Reply-To: <' . $reply_to . '>';
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
          . "--{$boundary}--\r\n";
    return [implode("\r\n", $h), $body];
}

/** Basit SMTP istemcisi (AUTH LOGIN, STARTTLS veya SSL). Başarıda true, hatada $error dolar. */
function smtp_send_raw($to, $headers, $body, &$error) {
    $host = trim(setting('smtp_host'));
    $port = (int)setting('smtp_port', '587');
    $secure = setting('smtp_secure', 'tls'); // tls (STARTTLS, 587) | ssl (465) | none
    $user = setting('smtp_user');
    $pass = setting('smtp_pass');
    $from = mail_from_email();
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $error = "Sunucuya bağlanılamadı ({$host}:{$port}): {$errstr}";
        return false;
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    // $label: hata kaydında görünecek komut adı (kullanıcı adı/şifre asla kayda yazılmaz)
    $cmd = function ($c, $expect, $label = null) use ($fp, $read, &$error) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), (array)$expect, true)) {
            $error = 'SMTP hatası (' . ($label ?? ($c === null ? 'bağlantı' : strtok((string)$c, ' :'))) . '): ' . mb_substr(trim($r), 0, 200, 'UTF-8');
            return false;
        }
        return true;
    };
    $ehlo = 'EHLO ' . (parse_url(CANONICAL_URL, PHP_URL_HOST) ?: 'localhost');
    $ok = $cmd(null, 220) && $cmd($ehlo, 250);
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', 220)
            && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))
            && $cmd($ehlo, 250);
        if (!$ok && $error === '') $error = 'TLS (STARTTLS) başlatılamadı.';
    }
    if ($ok && $user !== '') {
        $ok = $cmd('AUTH LOGIN', 334, 'AUTH') && $cmd(base64_encode($user), 334, 'AUTH') && $cmd(base64_encode($pass), 235, 'AUTH');
        if (!$ok) $error = 'Kullanıcı adı / şifre kabul edilmedi. ' . $error;
    }
    $data = str_replace("\n.", "\n..", $headers . "\r\n\r\n" . $body);
    $ok = $ok && $cmd('MAIL FROM:<' . $from . '>', 250) && $cmd('RCPT TO:<' . $to . '>', [250, 251]) && $cmd('DATA', 354) && $cmd($data . "\r\n.", 250, 'DATA');
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

/**
 * E-posta gönder. $kind: order_confirm, order_shipped, shop_new_order, test
 */
function send_mail($to, $subject, $html, $text, $kind = 'genel', $order_id = null) {
    $to = trim((string)$to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log_write($to, $subject, $kind, $order_id, 'hata', 'Geçersiz e-posta adresi');
        return false;
    }
    $from = mail_from_email();
    [$headers, $body] = mail_build_message($to, $subject, $html, $text, $from, setting('mail_from_name', APP_NAME), setting('site_email'));
    $error = '';
    if (trim(setting('smtp_host')) !== '') {
        $ok = smtp_send_raw($to, $headers, $body, $error);
    } else {
        // SMTP yoksa sunucunun mail() fonksiyonu (sendmail) denenir
        $mh = preg_replace('/^(To|Subject):.*\r\n/m', '', $headers);
        $ok = @mail($to, mail_encode_header($subject), $body, $mh);
        if (!$ok) $error = 'SMTP ayarlanmamış ve sunucuda mail() çalışmıyor. Site Ayarları → E-posta bölümünü doldurun.';
    }
    mail_log_write($to, $subject, $kind, $order_id, $ok ? 'gönderildi' : 'hata', $error);
    if (!$ok) error_log('[Yeniceri] E-posta gönderilemedi (' . $kind . ' → ' . $to . '): ' . $error);
    return $ok;
}

/**
 * Gönderim hakkını tek bir isteğe verir (aynı anda gelen iki PayTR bildirimi iki e-posta göndermesin).
 * Sütun boşsa doldurur ve true döner; gönderim başarısız olursa mail_claim_release ile geri alınır.
 */
function mail_claim($order_id, $column) {
    return db_query("UPDATE orders SET {$column} = ? WHERE id = ? AND {$column} IS NULL", [date('Y-m-d H:i:s'), (int)$order_id])->rowCount() === 1;
}

function mail_claim_release($order_id, $column) {
    db_query("UPDATE orders SET {$column} = NULL WHERE id = ?", [(int)$order_id]);
}

// --- Şablonlar ---
function mail_layout($title, $inner) {
    $logo = abs_url('assets/images/logo_horizontal.png');
    $foot = e(APP_NAME) . ' • ' . e(setting('site_address', '')) . ' • ' . e(setting('site_phone', ''));
    return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . e($title) . '</title></head>'
        . '<body style="margin:0;background:#f4f4f6;font-family:Arial,Helvetica,sans-serif;color:#222">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f6;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden;border-top:4px solid #dc3545">'
        . '<tr><td style="padding:20px 24px;border-bottom:1px solid #eee"><img src="' . e($logo) . '" alt="' . e(APP_NAME) . '" height="40" style="height:40px"></td></tr>'
        . '<tr><td style="padding:24px">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#fafafa;color:#888;font-size:12px;border-top:1px solid #eee">' . $foot . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function mail_button($url, $label) {
    return '<p style="margin:24px 0"><a href="' . e($url) . '" style="background:#dc3545;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block">' . e($label) . '</a></p>';
}

function order_load($order_id) {
    $o = db_fetch("SELECT o.*, u.name as customer_name, u.email as customer_email, u.phone as customer_phone FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?", [(int)$order_id]);
    if (!$o) return null;
    $o['items'] = db_fetch_all("SELECT oi.*, b.title FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?", [(int)$order_id]);
    return $o;
}

function order_tracking_page_url($o) {
    return abs_url('siparis-takip.php?no=' . rawurlencode($o['order_number']));
}

function order_items_html($o) {
    $rows = '';
    foreach ($o['items'] as $it) {
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #f0f0f0">' . e($it['title'] ?: 'Kitap #' . $it['book_id']) . ' <span style="color:#888">× ' . (int)$it['quantity'] . '</span></td>'
            . '<td align="right" style="padding:8px 0;border-bottom:1px solid #f0f0f0;white-space:nowrap">' . e(format_price($it['total'])) . '</td></tr>';
    }
    $sub = (float)$o['total_amount'] - (float)$o['shipping_amount'];
    $rows .= '<tr><td style="padding:6px 0;color:#666">Ara toplam</td><td align="right" style="padding:6px 0">' . e(format_price($sub)) . '</td></tr>';
    if ((float)($o['discount_amount'] ?? 0) > 0) {
        $rows .= '<tr><td style="padding:2px 0;color:#198754">Kampanya indirimi (dahil)</td><td align="right" style="padding:2px 0;color:#198754">-' . e(format_price($o['discount_amount'])) . '</td></tr>';
    }
    $rows .= '<tr><td style="padding:6px 0;color:#666">Kargo</td><td align="right" style="padding:6px 0">' . ((float)$o['shipping_amount'] > 0 ? e(format_price($o['shipping_amount'])) : 'Ücretsiz') . '</td></tr>';
    $rows .= '<tr><td style="padding:10px 0;font-weight:bold;font-size:16px;border-top:2px solid #222">Toplam</td><td align="right" style="padding:10px 0;font-weight:bold;font-size:16px;color:#dc3545;border-top:2px solid #222">' . e(format_price($o['total_amount'])) . '</td></tr>';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $rows . '</table>';
}

function order_items_text($o) {
    $t = '';
    foreach ($o['items'] as $it) {
        $t .= '- ' . ($it['title'] ?: 'Kitap #' . $it['book_id']) . ' x' . (int)$it['quantity'] . ': ' . format_price($it['total']) . "\n";
    }
    if ((float)($o['discount_amount'] ?? 0) > 0) $t .= 'Kampanya indirimi (dahil): -' . format_price($o['discount_amount']) . "\n";
    $t .= 'Kargo: ' . ((float)$o['shipping_amount'] > 0 ? format_price($o['shipping_amount']) : 'Ücretsiz') . "\n";
    return $t . 'TOPLAM: ' . format_price($o['total_amount']) . "\n";
}

/** "Siparişiniz alındı" — havale/EFT'de ödeme bilgileriyle; kartta ödeme onaylandıktan sonra */
function send_order_confirmation($order_id) {
    $o = order_load($order_id);
    if (!$o || $o['status'] === 'İptal' || !mail_claim($o['id'], 'mail_confirm_sent_at')) return false;
    $eft = $o['payment_method'] !== 'Kredi Kartı';
    $name = trim((string)$o['customer_name']) ?: 'Değerli müşterimiz';
    $subject = 'Siparişiniz alındı - ' . $o['order_number'];

    $pay_html = '';
    $pay_text = '';
    if ($eft && $o['status'] === 'Ödeme Bekliyor') {
        $iban = setting('bank_iban');
        $pay_html = '<div style="background:#fff8e1;border:1px solid #ffe08a;border-radius:8px;padding:14px;margin:16px 0;font-size:14px"><b>Havale / EFT bilgileri</b><br>'
            . ($iban !== '' ? (setting('bank_name') ? 'Banka: ' . e(setting('bank_name')) . '<br>' : '') . (setting('bank_account_name') ? 'Alıcı: ' . e(setting('bank_account_name')) . '<br>' : '') . 'IBAN: <b style="font-family:monospace">' . e($iban) . '</b><br>' : 'IBAN bilgisi için bizi arayın: ' . e(setting('site_phone')) . '<br>')
            . 'Tutar: <b>' . e(format_price($o['total_amount'])) . '</b><br>Açıklamaya sipariş numaranızı (<b>' . e($o['order_number']) . '</b>) yazın. Ödemeniz ulaşınca siparişiniz hazırlanır.</div>';
        $pay_text = "\nHAVALE / EFT\n" . ($iban !== '' ? 'Banka: ' . setting('bank_name') . "\nAlıcı: " . setting('bank_account_name') . "\nIBAN: " . $iban . "\n" : '') . 'Tutar: ' . format_price($o['total_amount']) . "\nAçıklamaya sipariş numaranızı yazın: " . $o['order_number'] . "\n";
    } elseif (!$eft) {
        $pay_html = '<p style="color:#198754;font-size:14px"><b>Kartla ödemeniz onaylandı.</b> Siparişiniz hazırlanıyor.</p>';
        $pay_text = "\nKartla ödemeniz onaylandı. Siparişiniz hazırlanıyor.\n";
    }

    $html = mail_layout($subject,
        '<h1 style="font-size:20px;margin:0 0 8px">Teşekkürler, siparişiniz alındı!</h1>'
        . '<p style="font-size:14px;color:#444;margin:0 0 16px">Merhaba ' . e($name) . ', <b>' . e($o['order_number']) . '</b> numaralı siparişiniz ' . e(date('d.m.Y H:i', strtotime($o['created_at']))) . ' tarihinde oluşturuldu.</p>'
        . $pay_html . order_items_html($o)
        . '<p style="font-size:14px;margin:16px 0 0"><b>Teslimat adresi</b><br>' . nl2br(e($o['shipping_address'])) . '</p>'
        . mail_button(order_tracking_page_url($o), 'Siparişimi Takip Et')
        . '<p style="font-size:12px;color:#888">Sipariş takip sayfasında sipariş numaranız ve bu e-posta adresiyle durumu görebilirsiniz. Sorularınız için bu e-postayı yanıtlayabilir ya da ' . e(setting('site_phone')) . ' numarasını arayabilirsiniz.</p>');
    $text = "Merhaba {$name},\n\n{$o['order_number']} numaralı siparişiniz alındı.\n{$pay_text}\n" . order_items_text($o)
        . "\nTeslimat adresi: {$o['shipping_address']}\n\nSipariş takibi: " . order_tracking_page_url($o) . "\n\n" . APP_NAME;

    $ok = send_mail($o['customer_email'], $subject, $html, $text, 'order_confirm', $o['id']);
    if (!$ok) mail_claim_release($o['id'], 'mail_confirm_sent_at'); // sonraki denemede tekrar gönderilebilsin

    // Mağazaya bildirim (ayarlanmışsa)
    $shop = trim(setting('order_notify_email'));
    if ($shop !== '') {
        $s2 = 'Yeni sipariş: ' . $o['order_number'] . ' - ' . format_price($o['total_amount']) . ' (' . $o['payment_method'] . ')';
        send_mail($shop, $s2, mail_layout($s2, '<h1 style="font-size:18px">Yeni sipariş: ' . e($o['order_number']) . '</h1><p style="font-size:14px">' . e($name) . ' • ' . e($o['customer_email']) . ' • ' . e($o['customer_phone']) . '<br>' . e($o['payment_method']) . ' • ' . e($o['status']) . '</p>' . order_items_html($o) . '<p style="font-size:14px"><b>Adres:</b> ' . e($o['shipping_address']) . '</p>' . mail_button(abs_url('admin/index.php?p=Orders'), 'Panelde Aç')),
            "Yeni sipariş {$o['order_number']}\n{$name} {$o['customer_email']} {$o['customer_phone']}\n" . order_items_text($o) . "Adres: {$o['shipping_address']}", 'shop_new_order', $o['id']);
    }
    return $ok;
}

/** "Siparişiniz kargoya verildi" — takip numarası ve bağlantısıyla (bir kez) */
function send_order_shipped($order_id, $force = false) {
    $o = order_load($order_id);
    if (!$o || trim((string)$o['tracking_number']) === '') return false;
    if ($force) {
        mail_claim_release($o['id'], 'mail_shipped_sent_at'); // yönetici bilerek tekrar gönderiyor
    }
    if (!mail_claim($o['id'], 'mail_shipped_sent_at')) return false;
    $name = trim((string)$o['customer_name']) ?: 'Değerli müşterimiz';
    $carrier = order_carrier_name($o);
    $link = order_tracking_link($o);
    $subject = 'Siparişiniz kargoya verildi - ' . $o['order_number'];
    $html = mail_layout($subject,
        '<h1 style="font-size:20px;margin:0 0 8px">Siparişiniz yola çıktı!</h1>'
        . '<p style="font-size:14px;color:#444">Merhaba ' . e($name) . ', <b>' . e($o['order_number']) . '</b> numaralı siparişiniz kargoya verildi.</p>'
        . '<div style="background:#f6f6f8;border-radius:8px;padding:14px;font-size:14px;margin:16px 0">' . ($carrier !== '' && $carrier !== 'Diğer' ? 'Kargo firması: <b>' . e($carrier) . '</b><br>' : '') . 'Takip numarası: <b style="font-family:monospace">' . e($o['tracking_number']) . '</b></div>'
        . ($link ? mail_button($link, 'Kargomu Takip Et') : '')
        . '<p style="font-size:14px"><a href="' . e(order_tracking_page_url($o)) . '" style="color:#dc3545">Sipariş durumunu sitemizde görüntüleyin</a></p>'
        . order_items_html($o));
    $text = "Merhaba {$name},\n\n{$o['order_number']} numaralı siparişiniz kargoya verildi.\n" . ($carrier !== '' ? "Kargo firması: {$carrier}\n" : '') . "Takip numarası: {$o['tracking_number']}\n" . ($link ? "Kargo takibi: {$link}\n" : '')
        . "Sipariş durumu: " . order_tracking_page_url($o) . "\n\n" . APP_NAME;
    $ok = send_mail($o['customer_email'], $subject, $html, $text, 'order_shipped', $o['id']);
    if (!$ok) mail_claim_release($o['id'], 'mail_shipped_sent_at');
    return $ok;
}
