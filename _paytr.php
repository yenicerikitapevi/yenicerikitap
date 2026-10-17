<?php
/**
 * PAYTR iFRAME API ENTEGRASYONU (_paytr.php) - config.php tarafından yüklenir
 * Doküman: https://dev.paytr.com/iframe-api
 *
 * Mağaza bilgileri önce ortam değişkenlerinden (Render panelinde PAYTR_MERCHANT_ID, PAYTR_MERCHANT_KEY,
 * PAYTR_MERCHANT_SALT, PAYTR_TEST_MODE), yoksa admin panelindeki Site Ayarları'ndan okunur.
 */
if (!defined('SITE_URL')) exit;

define('PAYTR_TOKEN_URL', 'https://www.paytr.com/odeme/api/get-token');
define('PAYTR_IFRAME_URL', 'https://www.paytr.com/odeme/guvenli/');
define('PAYTR_TIMEOUT_MINUTES', 30);        // PayTR ödeme sayfasının geçerlilik süresi
define('PAYTR_EXPIRE_MINUTES', 60);         // Bu süre içinde ödenmeyen kartlı siparişler iptal edilip stok geri alınır

function paytr_config() {
    $env_or_setting = function ($env, $key, $default = '') {
        $val = getenv($env);
        return ($val !== false && $val !== '') ? $val : setting($key, $default);
    };

    return [
        'merchant_id'   => trim($env_or_setting('PAYTR_MERCHANT_ID', 'payment_merchant_id')),
        'merchant_key'  => trim($env_or_setting('PAYTR_MERCHANT_KEY', 'payment_api_key')),
        'merchant_salt' => trim($env_or_setting('PAYTR_MERCHANT_SALT', 'payment_merchant_salt')),
        'test_mode'     => $env_or_setting('PAYTR_TEST_MODE', 'paytr_test_mode', '1') === '0' ? '0' : '1',
    ];
}

/**
 * Kartla ödeme yalnızca üç mağaza bilgisi de girildiğinde müşteriye sunulur
 */
function paytr_enabled() {
    $cfg = paytr_config();
    return $cfg['merchant_id'] !== '' && $cfg['merchant_key'] !== '' && $cfg['merchant_salt'] !== '';
}

/**
 * PayTR merchant_oid yalnızca harf ve rakam kabul eder: YNC-20261017-AB12CD -> YNC20261017AB12CD
 */
function paytr_merchant_oid($order_number) {
    return preg_replace('/[^A-Za-z0-9]/', '', $order_number);
}

/**
 * Müşterinin gerçek IP adresi (Render proxy'si arkasında X-Forwarded-For kullanılır)
 */
function paytr_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * 1. ADIM: Ödeme formu (iframe) için token alınır
 * $order: merchant_oid, email, total (TL), user_name, user_address, user_phone, basket ([[ad, birim fiyat, adet], ...])
 * Dönüş: ['ok' => true, 'token' => '...'] veya ['ok' => false, 'error' => '...']
 */
function paytr_get_token($order) {
    $cfg = paytr_config();
    $merchant_oid    = $order['merchant_oid'];
    $email           = $order['email'];
    $payment_amount  = (string)(int)round($order['total'] * 100); // Kuruş cinsinden
    $user_ip         = paytr_client_ip();
    $no_installment  = '0';
    $max_installment = '0';
    $currency        = 'TL';
    $test_mode       = $cfg['test_mode'];

    $basket = [];
    foreach ($order['basket'] as $line) {
        $basket[] = [$line[0], number_format((float)$line[1], 2, '.', ''), (int)$line[2]];
    }
    $user_basket = base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE));

    $hash_str = $cfg['merchant_id'] . $user_ip . $merchant_oid . $email . $payment_amount . $user_basket
              . $no_installment . $max_installment . $currency . $test_mode;
    $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $cfg['merchant_salt'], $cfg['merchant_key'], true));

    $post = [
        'merchant_id'       => $cfg['merchant_id'],
        'user_ip'           => $user_ip,
        'merchant_oid'      => $merchant_oid,
        'email'             => $email,
        'payment_amount'    => $payment_amount,
        'paytr_token'       => $paytr_token,
        'user_basket'       => $user_basket,
        'debug_on'          => $test_mode,
        'no_installment'    => $no_installment,
        'max_installment'   => $max_installment,
        'user_name'         => $order['user_name'],
        'user_address'      => $order['user_address'],
        'user_phone'        => $order['user_phone'],
        'merchant_ok_url'   => SITE_URL . '/checkout.php?odeme=basarili',
        'merchant_fail_url' => SITE_URL . '/checkout.php?odeme=basarisiz',
        'timeout_limit'     => (string)PAYTR_TIMEOUT_MINUTES,
        'currency'          => $currency,
        'test_mode'         => $test_mode,
        'lang'              => 'tr',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, PAYTR_TOKEN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => 'PayTR bağlantı hatası: ' . $err];
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (($result['status'] ?? '') === 'success' && !empty($result['token'])) {
        return ['ok' => true, 'token' => $result['token']];
    }
    return ['ok' => false, 'error' => 'PayTR token alınamadı: ' . ($result['reason'] ?? (string)$response)];
}

/**
 * 2. ADIM: Bildirim (callback) isteğinin gerçekten PayTR'den geldiği doğrulanır
 */
function paytr_verify_callback($post) {
    $cfg = paytr_config();
    foreach (['merchant_oid', 'status', 'total_amount', 'hash'] as $field) {
        if (!isset($post[$field])) return false;
    }
    $hash = base64_encode(hash_hmac('sha256', $post['merchant_oid'] . $cfg['merchant_salt'] . $post['status'] . $post['total_amount'], $cfg['merchant_key'], true));
    return hash_equals($hash, (string)$post['hash']);
}

/**
 * Ödemesi alınamayan kartlı siparişi iptal eder ve ayrılan stoğu geri ekler.
 * Çağıran taraf veritabanı işlemini (transaction) açmış olmalıdır.
 */
function paytr_cancel_order($order_id, $reason) {
    $updated = db_query("UPDATE orders SET status = 'İptal' WHERE id = ? AND status = 'Ödeme Bekliyor'", [$order_id])->rowCount();
    if ($updated) {
        db_query("UPDATE books SET stock = stock + (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ? AND book_id = books.id) WHERE id IN (SELECT book_id FROM order_items WHERE order_id = ?)", [$order_id, $order_id]);
        db_query("UPDATE transactions SET status = 'Başarısız', gateway_response = ? WHERE order_id = ?", [$reason, $order_id]);
    }
    return $updated > 0;
}

/**
 * Ödeme sayfası kapatılıp yarıda bırakılan kartlı siparişler stoğu süresiz tutmasın
 */
function paytr_expire_pending_orders() {
    global $pdo;
    $cutoff = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite')
        ? "datetime('now', '-" . PAYTR_EXPIRE_MINUTES . " minutes')"
        : "NOW() - INTERVAL " . PAYTR_EXPIRE_MINUTES . " MINUTE";
    $stale = db_fetch_all("SELECT id FROM orders WHERE status = 'Ödeme Bekliyor' AND payment_method = 'Kredi Kartı' AND created_at < {$cutoff}");

    foreach ($stale as $order) {
        $pdo->beginTransaction();
        paytr_cancel_order($order['id'], 'PayTR ödemesi ' . PAYTR_EXPIRE_MINUTES . ' dakika içinde tamamlanmadı, sipariş otomatik iptal edildi');
        $pdo->commit();
    }
}
