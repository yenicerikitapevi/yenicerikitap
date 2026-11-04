<?php
/**
 * Ürün sayfasındaki "Satıcıya soru sor" formu (AJAX, JSON döner).
 * Spam önlemleri: oturum anahtarı, gizli alan, oturum ve IP başına saatlik sınır.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function soru_out($ok, $msg, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['bq_token']) || !hash_equals($_SESSION['bq_token'], (string)($_POST['token'] ?? ''))) {
    soru_out(false, 'Oturum süresi doldu, sayfayı yenileyip tekrar deneyin.', 403);
}
if (!empty($_POST['website'])) soru_out(true, 'Sorunuz iletildi.'); // bot

$book = db_fetch("SELECT id, title, store_id FROM books WHERE id = ? AND is_active = 1", [(int)($_POST['book_id'] ?? 0)]);
if (!$book) soru_out(false, 'Kitap bulunamadı.');

$customer = !empty($_SESSION['customer_user']) ? db_fetch("SELECT id, name, email FROM users WHERE id = ? AND role = 'customer' AND status = 'active'", [(int)$_SESSION['customer_user']['id']]) : null;
$name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($customer['name'] ?? $_POST['name'] ?? ''))));
$email = trim((string)($customer['email'] ?? $_POST['email'] ?? ''));
$message = trim(str_replace("\r", '', strip_tags((string)($_POST['message'] ?? ''))));

if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120) soru_out(false, 'Lütfen adınızı yazın.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) soru_out(false, 'Yanıtın gönderileceği geçerli bir e-posta adresi yazın.');
$len = mb_strlen($message, 'UTF-8');
if ($len < 5 || $len > 1000) soru_out(false, 'Sorunuz 5 ile 1000 karakter arasında olmalı.');

// Saatlik sınır: oturum başına 5, IP başına 10 soru
$now = time();
$_SESSION['bq_times'] = array_values(array_filter((array)($_SESSION['bq_times'] ?? []), function ($t) use ($now) { return $t > $now - 3600; }));
$ip = substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
$ip_count = (int)(db_fetch("SELECT COUNT(*) AS c FROM book_questions WHERE ip_address = ? AND created_at > ?", [$ip, date('Y-m-d H:i:s', $now - 3600)])['c'] ?? 0);
if (count($_SESSION['bq_times']) >= 5 || $ip_count >= 10) soru_out(false, 'Kısa sürede çok fazla soru gönderdiniz. Lütfen biraz sonra tekrar deneyin.');

db_query("INSERT INTO book_questions (store_id, book_id, customer_id, name, email, message, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    [$book['store_id'] ?: null, (int)$book['id'], $customer['id'] ?? null, $name, $email, $message, $ip, date('Y-m-d H:i:s', $now)]);
$_SESSION['bq_times'][] = $now;

// Yeniçeri Kitabevi'ne gelen soru yöneticiye e-postayla bildirilir (sahaf soruları Satıcı Paneli'nde görünür)
if (empty($book['store_id']) && function_exists('send_mail')) {
    $to = setting('order_notify_email', '') ?: setting('site_email', '');
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $html = mail_layout('Yeni müşteri sorusu', '<p><b>' . e($name) . '</b>, “' . e($book['title']) . '” hakkında soru sordu:</p>'
            . '<p style="border-left:3px solid #c1121f;padding-left:10px">' . nl2br(e($message)) . '</p>'
            . mail_button(rtrim(SITE_URL, '/') . '/admin/index.php?p=Messages', 'Panelde yanıtla'));
        send_mail($to, 'Yeni soru: ' . $book['title'], $html, "{$name} sordu ({$book['title']}):\n\n{$message}", 'soru');
    }
}
soru_out(true, 'Sorunuz satıcıya iletildi. Yanıt ' . $email . ' adresine e-postayla gönderilecek' . ($customer ? ' ve Hesabım sayfasında da görünecek.' : '.'));
