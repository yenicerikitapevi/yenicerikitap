<?php
/**
 * SAHAF HAKEDİŞ ÖDEMELERİ
 *  - Müşterinin ödediği para Yeniçeri'nin hesabına gelir; sahafın payı haftalık olarak havaleyle aktarılır.
 *  - Sahafın bir siparişteki hakedişi = kendi kitaplarının satış tutarı − komisyon + kargo payı.
 *    Kargo payı: kargoyu sahaf gönderdiği için müşterinin ödediği kargo ücreti; karma siparişte paket sayısına bölünür.
 *  - Vade: sipariş kargolandıktan (yoksa tamamlandıktan) PAYOUT_HOLD_DAYS gün sonra (cayma/iade süresi) ödemeye açılır.
 *  - Yönetici bankadan havaleyi yapar, "Ödeme yaptım" ile kaydeder (payouts + payout_lines).
 *    Satırlar fark (delta) olarak tutulur: ödendikten sonra iptal edilen siparişin tutarı sonraki ödemeden kendiliğinden düşer.
 */
if (!defined('SITE_URL')) exit;

define('PAYOUT_HOLD_DAYS', 14);

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payouts (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, amount REAL NOT NULL, items_total REAL DEFAULT 0,
        commission_total REAL DEFAULT 0, shipping_total REAL DEFAULT 0, iban TEXT, reference TEXT, paid_at DATETIME, created_by TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payout_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, payout_id INTEGER NOT NULL, store_id INTEGER NOT NULL, order_id INTEGER NOT NULL,
        items_total REAL DEFAULT 0, commission REAL DEFAULT 0, shipping REAL DEFAULT 0, net REAL NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_payout_lines_store ON payout_lines (store_id, order_id)");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payouts (id INT AUTO_INCREMENT PRIMARY KEY, store_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, items_total DECIMAL(12,2) DEFAULT 0,
        commission_total DECIMAL(12,2) DEFAULT 0, shipping_total DECIMAL(12,2) DEFAULT 0, iban VARCHAR(40) NULL, reference VARCHAR(255) NULL, paid_at DATETIME NULL,
        created_by VARCHAR(120) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (store_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payout_lines (id INT AUTO_INCREMENT PRIMARY KEY, payout_id INT NOT NULL, store_id INT NOT NULL, order_id INT NOT NULL,
        items_total DECIMAL(12,2) DEFAULT 0, commission DECIMAL(12,2) DEFAULT 0, shipping DECIMAL(12,2) DEFAULT 0, net DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (store_id, order_id), INDEX (payout_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
}

/** Haftalık ödeme günü (1 = Pazartesi … 7 = Pazar), Site Ayarları: payout_weekday */
function po_weekday() {
    $d = (int)setting('payout_weekday', '1');
    return ($d >= 1 && $d <= 7) ? $d : 1;
}

function po_weekday_name($d = null) {
    return [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'][$d ?? po_weekday()];
}

/** Bugün ya da sonraki ödeme günü (Y-m-d) */
function po_next_payday($from = null) {
    $t = $from ? strtotime($from) : time();
    $diff = (po_weekday() - (int)date('N', $t) + 7) % 7;
    return date('Y-m-d', strtotime("+{$diff} day", strtotime(date('Y-m-d', $t))));
}

/**
 * Sahaf × sipariş hakediş satırları. $store_id null → bütün sahaflar.
 * Her satır: order_id, order_number, status, store_id, items_total, commission, shipping, due (şu anki hakediş),
 *            paid (önceden ödenen), delta (ödenecek fark), matures_at (vade), ready (delta ödemeye açık mı), state (metin)
 */
function po_rows($store_id = null) {
    $params = [];
    $sw = '';
    if ($store_id !== null) {
        $sw = ' AND b.store_id = ?';
        $params[] = (int)$store_id;
    }
    // Sahaf kitabı olan, ödemesi alınmış siparişler + iptal edilmiş ama önceden hakedişi ödenmiş olanlar
    $items = db_fetch_all("SELECT oi.order_id, b.store_id, SUM(oi.total) AS items_total, SUM(COALESCE(oi.commission_amount, 0)) AS commission
                           FROM order_items oi JOIN books b ON b.id = oi.book_id JOIN orders o ON o.id = oi.order_id
                           WHERE b.store_id IS NOT NULL{$sw}
                             AND (o.status IN ('Hazırlanıyor', 'Kargolandı', 'Tamamlandı')
                                  OR (o.status = 'İptal' AND EXISTS (SELECT 1 FROM payout_lines pl WHERE pl.order_id = oi.order_id AND pl.store_id = b.store_id)))
                           GROUP BY oi.order_id, b.store_id", $params);
    if (!$items) return [];
    $oids = array_values(array_unique(array_map('intval', array_column($items, 'order_id'))));
    $in = implode(',', $oids);
    $orders = [];
    foreach (db_fetch_all("SELECT id, order_number, status, shipping_amount, shipped_at, created_at FROM orders WHERE id IN ({$in})") as $o) $orders[(int)$o['id']] = $o;
    // Paket sayısı: siparişteki sahaf sayısı + (Yeniçeri'nin kitabı varsa) 1
    $parcels = [];
    foreach (db_fetch_all("SELECT oi.order_id, COUNT(DISTINCT b.store_id) AS stores, SUM(CASE WHEN b.store_id IS NULL OR b.id IS NULL THEN 1 ELSE 0 END) AS own
                           FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id WHERE oi.order_id IN ({$in}) GROUP BY oi.order_id") as $r) {
        $parcels[(int)$r['order_id']] = max(1, (int)$r['stores'] + ((int)$r['own'] > 0 ? 1 : 0));
    }
    $ships = [];
    foreach (db_fetch_all("SELECT order_id, store_id, shipped_at FROM order_shipments WHERE order_id IN ({$in})") as $s) $ships[$s['order_id'] . ':' . $s['store_id']] = $s['shipped_at'];
    $paid = [];
    foreach (db_fetch_all("SELECT order_id, store_id, SUM(net) AS net, SUM(items_total) AS it, SUM(commission) AS co, SUM(shipping) AS sh FROM payout_lines WHERE order_id IN ({$in}) GROUP BY order_id, store_id") as $p) {
        $paid[$p['order_id'] . ':' . $p['store_id']] = ['net' => (float)$p['net'], 'it' => (float)$p['it'], 'co' => (float)$p['co'], 'sh' => (float)$p['sh']];
    }

    $now = time();
    $rows = [];
    foreach ($items as $it) {
        $oid = (int)$it['order_id'];
        $sid = (int)$it['store_id'];
        $o = $orders[$oid] ?? null;
        if (!$o) continue;
        $key = $oid . ':' . $sid;
        $cancel = $o['status'] === 'İptal';
        $items_total = round((float)$it['items_total'], 2);
        $commission = round((float)$it['commission'], 2);
        $shipping = round((float)$o['shipping_amount'] / ($parcels[$oid] ?? 1), 2);
        $due = $cancel ? 0.0 : round($items_total - $commission + $shipping, 2);
        $pp = $paid[$key] ?? ['net' => 0, 'it' => 0, 'co' => 0, 'sh' => 0];
        $paid_net = round($pp['net'], 2);
        $delta = round($due - $paid_net, 2);
        // Ödenecek farkın bileşenleri (iptalde önceden ödenenlerin tersi)
        $d_items = round(($cancel ? 0 : $items_total) - $pp['it'], 2);
        $d_comm = round(($cancel ? 0 : $commission) - $pp['co'], 2);
        $d_ship = round(($cancel ? 0 : $shipping) - $pp['sh'], 2);
        // Vade: sahafın kargo tarihi, yoksa siparişin kargo tarihi; tamamlanmış ama tarihsiz siparişte sipariş tarihi
        $base = $ships[$key] ?? ($o['shipped_at'] ?: ($o['status'] === 'Tamamlandı' ? $o['created_at'] : null));
        $matures = $base ? date('Y-m-d H:i:s', strtotime($base) + PAYOUT_HOLD_DAYS * 86400) : null;
        if (abs($delta) < 0.005) {
            $ready = false;
            $state = $cancel ? 'İptal (iade düşüldü)' : 'Ödendi';
        } elseif ($delta < 0) {
            $ready = true; // iptal/iade: sonraki ödemeden düşülür
            $state = 'İade düşülecek';
        } elseif ($o['status'] === 'Hazırlanıyor' || !$matures) {
            $ready = false;
            $state = 'Kargolanınca vadeye girer';
        } elseif (strtotime($matures) > $now) {
            $ready = false;
            $state = 'Vade: ' . date('d.m.Y', strtotime($matures));
        } else {
            $ready = true;
            $state = 'Ödemeye hazır';
        }
        $rows[] = ['order_id' => $oid, 'order_number' => $o['order_number'], 'status' => $o['status'], 'created_at' => $o['created_at'], 'store_id' => $sid,
            'items_total' => $items_total, 'commission' => $commission, 'shipping' => $shipping, 'parcels' => $parcels[$oid] ?? 1,
            'due' => $due, 'paid' => $paid_net, 'delta' => $delta, 'd_items' => $d_items, 'd_comm' => $d_comm, 'd_ship' => $d_ship, 'matures_at' => $matures, 'ready' => $ready, 'state' => $state];
    }
    usort($rows, function ($a, $b) { return $b['order_id'] <=> $a['order_id']; });
    return $rows;
}

/** Özet: ['ready' => ödemeye hazır net, 'pending' => vadesi beklenen, 'paid' => toplam ödenen, 'ready_rows' => [...]] */
function po_summary($store_id) {
    $rows = po_rows($store_id);
    $ready = 0.0;
    $pending = 0.0;
    $ready_rows = [];
    foreach ($rows as $r) {
        if ($r['ready']) {
            $ready += $r['delta'];
            $ready_rows[] = $r;
        } elseif ($r['delta'] > 0) {
            $pending += $r['delta'];
        }
    }
    $paid_total = (float)(db_fetch("SELECT COALESCE(SUM(amount), 0) AS s FROM payouts WHERE store_id = ?", [(int)$store_id])['s'] ?? 0);
    return ['ready' => round($ready, 2), 'pending' => round($pending, 2), 'paid' => round($paid_total, 2), 'ready_rows' => $ready_rows, 'rows' => $rows];
}

/**
 * Ödemeyi kaydeder. $expected: ekranda görünen tutar (arada değiştiyse kaydedilmez). Dönüş: [başarılı mı, mesaj, payout_id]
 */
function po_record($store_id, $expected, $reference, $admin_name = '') {
    global $pdo;
    $store = db_fetch("SELECT id, name, email, iban FROM stores WHERE id = ?", [(int)$store_id]);
    if (!$store) return [false, 'Satıcı bulunamadı.', 0];
    $pdo->beginTransaction();
    try {
        $sum = po_summary($store['id']);
        if ($sum['ready'] <= 0) {
            $pdo->rollBack();
            return [false, 'Bu satıcıya ödenecek tutar yok.', 0];
        }
        if (abs($sum['ready'] - round((float)$expected, 2)) >= 0.01) {
            $pdo->rollBack();
            return [false, 'Tutar bu arada değişti (' . format_price($sum['ready']) . '). Sayfayı yenileyip tekrar kontrol edin.', 0];
        }
        $it = $co = $sh = 0.0;
        foreach ($sum['ready_rows'] as $r) {
            $it += $r['d_items'];
            $co += $r['d_comm'];
            $sh += $r['d_ship'];
        }
        $now = date('Y-m-d H:i:s');
        db_query("INSERT INTO payouts (store_id, amount, items_total, commission_total, shipping_total, iban, reference, paid_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$store['id'], $sum['ready'], round($it, 2), round($co, 2), round($sh, 2), $store['iban'], mb_substr(trim((string)$reference), 0, 255, 'UTF-8'), $now, mb_substr($admin_name, 0, 120, 'UTF-8')]);
        $pid = (int)$pdo->lastInsertId();
        foreach ($sum['ready_rows'] as $r) {
            db_query("INSERT INTO payout_lines (payout_id, store_id, order_id, items_total, commission, shipping, net) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$pid, $store['id'], $r['order_id'], $r['d_items'], $r['d_comm'], $r['d_ship'], $r['delta']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Yeniceri] Hakediş kaydı: ' . $e->getMessage());
        return [false, 'Ödeme kaydedilemedi: ' . $e->getMessage(), 0];
    }
    log_action('Hakediş Ödendi', 'Payouts', $pid, $store['name'] . ': ' . format_price($sum['ready']) . ($reference !== '' ? ' (' . $reference . ')' : ''));
    $mailed = po_send_mail($pid);
    return [true, $store['name'] . ' için ' . format_price($sum['ready']) . ' ödeme kaydedildi.' . ($mailed ? ' Satıcıya e-posta gönderildi.' : ''), $pid];
}

/** Hatalı kaydı geri alır (ödeme ve satırları silinir; tutar yeniden ödenecekler arasına döner) */
function po_undo($payout_id) {
    $p = db_fetch("SELECT p.*, s.name AS store_name FROM payouts p LEFT JOIN stores s ON s.id = p.store_id WHERE p.id = ?", [(int)$payout_id]);
    if (!$p) return [false, 'Ödeme kaydı bulunamadı.'];
    db_query("DELETE FROM payout_lines WHERE payout_id = ?", [(int)$payout_id]);
    db_query("DELETE FROM payouts WHERE id = ?", [(int)$payout_id]);
    log_action('Hakediş Kaydı Geri Alındı', 'Payouts', (int)$payout_id, ($p['store_name'] ?? '') . ': ' . format_price($p['amount']));
    return [true, 'Ödeme kaydı geri alındı; tutar yeniden ödenecekler listesinde.'];
}

/** Satıcının ödeme geçmişi */
function po_history($store_id = null, $limit = 50) {
    $sql = "SELECT p.*, s.name AS store_name, (SELECT COUNT(*) FROM payout_lines pl WHERE pl.payout_id = p.id) AS line_count FROM payouts p LEFT JOIN stores s ON s.id = p.store_id";
    return $store_id === null
        ? db_fetch_all($sql . " ORDER BY p.id DESC LIMIT " . (int)$limit)
        : db_fetch_all($sql . " WHERE p.store_id = ? ORDER BY p.id DESC LIMIT " . (int)$limit, [(int)$store_id]);
}

function po_lines($payout_id) {
    return db_fetch_all("SELECT pl.*, o.order_number FROM payout_lines pl LEFT JOIN orders o ON o.id = pl.order_id WHERE pl.payout_id = ? ORDER BY pl.order_id", [(int)$payout_id]);
}

/** İşaretli tutar: -₺15,00 (sıfırsa —) */
function po_money($v) {
    $v = round((float)$v, 2);
    if (abs($v) < 0.005) return '—';
    return ($v < 0 ? '-' : '') . format_price(abs($v));
}

/** IBAN'ı okunur biçimde (4'lü gruplar) */
function po_format_iban($iban) {
    $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
    return $iban === '' ? '' : trim(chunk_split($iban, 4, ' '));
}

/** Satıcıya "hakedişiniz gönderildi" e-postası (döküm ile) */
function po_send_mail($payout_id) {
    $p = db_fetch("SELECT p.*, s.name AS store_name, s.email FROM payouts p JOIN stores s ON s.id = p.store_id WHERE p.id = ?", [(int)$payout_id]);
    if (!$p || !filter_var(trim((string)$p['email']), FILTER_VALIDATE_EMAIL) || !function_exists('send_mail')) return false;
    $rows = '';
    $text = '';
    foreach (po_lines($payout_id) as $l) {
        $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee">' . e($l['order_number'] ?: '#' . $l['order_id']) . '</td>'
            . '<td align="right" style="padding:6px 0;border-bottom:1px solid #eee">' . e(po_money($l['items_total'])) . '</td>'
            . '<td align="right" style="padding:6px 0;border-bottom:1px solid #eee">' . e(po_money(-$l['commission'])) . '</td>'
            . '<td align="right" style="padding:6px 0;border-bottom:1px solid #eee">' . e(po_money($l['shipping'])) . '</td>'
            . '<td align="right" style="padding:6px 0;border-bottom:1px solid #eee;font-weight:bold">' . e(po_money($l['net'])) . '</td></tr>';
        $text .= ($l['order_number'] ?: '#' . $l['order_id']) . ': ' . po_money($l['net']) . "\n";
    }
    $subject = 'Hakediş ödemeniz yapıldı: ' . format_price($p['amount']);
    $html = mail_layout($subject,
        '<h1 style="font-size:20px;margin:0 0 8px">Hakediş ödemeniz yapıldı</h1>'
        . '<p style="font-size:14px;color:#444">Merhaba ' . e($p['store_name']) . ', <b>' . e(format_price($p['amount'])) . '</b> tutarındaki hakedişiniz ' . e(date('d.m.Y', strtotime($p['paid_at']))) . ' tarihinde'
        . ($p['iban'] ? ' <b style="font-family:monospace">' . e(po_format_iban($p['iban'])) . '</b> hesabınıza' : '') . ' gönderildi.' . ($p['reference'] ? ' Açıklama: ' . e($p['reference']) : '') . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px"><tr style="color:#666"><td>Sipariş</td><td align="right">Kitap</td><td align="right">Komisyon</td><td align="right">Kargo</td><td align="right">Net</td></tr>' . $rows
        . '<tr><td colspan="4" style="padding:8px 0;font-weight:bold">Toplam</td><td align="right" style="padding:8px 0;font-weight:bold;color:#dc3545">' . e(format_price($p['amount'])) . '</td></tr></table>'
        . mail_button(abs_url('satici/index.php?tab=hakedis'), 'Hakedişlerimi Gör'));
    $text = "Merhaba {$p['store_name']},\n\n" . format_price($p['amount']) . " tutarındaki hakedişiniz gönderildi.\n\n{$text}\nToplam: " . format_price($p['amount']) . "\n\n" . abs_url('satici/index.php?tab=hakedis');
    return send_mail($p['email'], $subject, $html, $text, 'payout');
}
