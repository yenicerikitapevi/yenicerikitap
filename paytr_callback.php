<?php
/**
 * PAYTR BİLDİRİM URL (paytr_callback.php)
 * PayTR Mağaza Paneli > Destek & Kurulum > Ayarlar > Bildirim URL alanına
 * https://ALANADINIZ/paytr_callback.php olarak girilmelidir.
 *
 * Ödemenin kesin sonucu yalnızca bu bildirimle belirlenir (müşterinin döndüğü sayfa değil).
 * PayTR aynı sipariş için birden fazla bildirim gönderebilir; her sipariş yalnızca bir kez işlenir.
 * Yanıt olarak sadece "OK" dönülmelidir, aksi hâlde PayTR bildirimi tekrar gönderir.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!paytr_enabled() || !paytr_verify_callback($_POST)) {
    error_log('[PayTR] Bildirim doğrulanamadı: ' . ($_POST['merchant_oid'] ?? '-'));
    die('PAYTR notification failed: bad hash');
}

$merchant_oid = $_POST['merchant_oid'];
$order = db_fetch("SELECT * FROM orders WHERE REPLACE(order_number, '-', '') = ? AND payment_method = 'Kredi Kartı'", [$merchant_oid]);

if (!$order) {
    error_log('[PayTR] Bildirimdeki sipariş bulunamadı: ' . $merchant_oid);
    echo 'OK';
    exit;
}

global $pdo;
$pdo->beginTransaction();

if ($_POST['status'] === 'success') {
    $paid = number_format(((int)$_POST['total_amount']) / 100, 2, '.', '');
    $msg = "PayTR ONAY - merchant_oid: {$merchant_oid} - Tahsil edilen: {$paid} TL"
         . (!empty($_POST['payment_type']) ? ' - Tür: ' . $_POST['payment_type'] : '')
         . (($_POST['test_mode'] ?? '0') === '1' ? ' - TEST' : '');

    if ($order['status'] === 'Ödeme Bekliyor') {
        db_query("UPDATE orders SET status = 'Hazırlanıyor' WHERE id = ?", [$order['id']]);
        db_query("UPDATE transactions SET status = 'Onaylandı', gateway_response = ? WHERE order_id = ?", [$msg, $order['id']]);
        log_action('PayTR Ödeme Onayı', 'Orders', $order['id'], "Sipariş {$order['order_number']} için {$paid} TL kartla tahsil edildi.");
    } elseif ($order['status'] === 'İptal') {
        // İptal edilmiş (ör. süre aşımı) siparişe geç ödeme geldiyse: para tahsil edildiği için sipariş geri açılır
        db_query("UPDATE books SET stock = stock - (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND book_id = books.id) WHERE id IN (SELECT book_id FROM order_items WHERE order_id = ?)", [$order['id'], $order['id']]);
        db_query("UPDATE orders SET status = 'Hazırlanıyor' WHERE id = ?", [$order['id']]);
        db_query("UPDATE transactions SET status = 'Onaylandı', gateway_response = ? WHERE order_id = ?", [$msg . ' - İPTAL SONRASI GELDİ, STOĞU KONTROL EDİN', $order['id']]);
        log_action('PayTR Geç Ödeme', 'Orders', $order['id'], "Otomatik iptal edilen {$order['order_number']} siparişine ödeme geldi; sipariş yeniden açıldı, stoğu kontrol edin.");
    }
} else {
    $reason = 'PayTR RED - Kod: ' . ($_POST['failed_reason_code'] ?? '-') . ' - ' . ($_POST['failed_reason_msg'] ?? '');
    if (paytr_cancel_order($order['id'], $reason)) {
        log_action('PayTR Ödeme Başarısız', 'Orders', $order['id'], "Sipariş {$order['order_number']} kartla ödenemedi, iptal edildi ve stok geri eklendi.");
    }
}

$pdo->commit();

// Önce PayTR'a yanıt verilir, sonra (bağlantı kapandıktan sonra) müşteriye e-posta gönderilir
$confirm_order = ($_POST['status'] === 'success') ? (int)$order['id'] : 0;
ignore_user_abort(true);
header('Content-Type: text/plain');
header('Content-Length: 2');
header('Connection: close');
echo 'OK';
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if ($confirm_order) {
    try {
        send_order_confirmation($confirm_order); // aynı sipariş için ikinci kez gönderilmez
    } catch (Throwable $e) {
        error_log('[Yeniceri] Ödeme sonrası e-posta: ' . $e->getMessage());
    }
}
