<?php
/**
 * SİTE İÇİ MESAJLAŞMA: müşteri ürün sayfasından satıcıya (sahaf ya da Yeniçeri Kitabevi) soru sorar.
 *  - Sahaf sorusu Satıcı Paneli → Mesajlar'da, Yeniçeri sorusu Yönetim → Mesajlar'da görünür ve oradan yanıtlanır.
 *  - Yanıt müşteriye e-postayla gider; üye müşteri Hesabım → Sorularım'da da görür.
 *  - Müşterinin e-posta adresi satıcıya gösterilmez (yanıt site üzerinden gider).
 */
if (!defined('SITE_URL')) exit;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS book_questions (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NULL, book_id INTEGER NULL, customer_id INTEGER NULL,
        name TEXT, email TEXT, message TEXT, reply TEXT NULL, replied_at DATETIME NULL, is_read INTEGER DEFAULT 0, ip_address TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS book_questions (id INT AUTO_INCREMENT PRIMARY KEY, store_id INT NULL, book_id INT NULL, customer_id INT NULL,
        name VARCHAR(120), email VARCHAR(190), message TEXT, reply TEXT NULL, replied_at TIMESTAMP NULL, is_read TINYINT DEFAULT 0, ip_address VARCHAR(64),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (store_id), INDEX (customer_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
}

// Okundu bilgisi: satıcı soruyu panelde açınca seller_read_at, müşteri yanıtı Hesabım'da görünce customer_read_at
(function () {
    global $pdo;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $have = $sqlite ? array_column($pdo->query("PRAGMA table_info(book_questions)")->fetchAll(), 'name')
                    : array_column($pdo->query("SHOW COLUMNS FROM book_questions")->fetchAll(), 'Field');
    foreach (['seller_read_at', 'customer_read_at'] as $c) {
        if (!in_array($c, $have, true)) {
            $pdo->exec("ALTER TABLE book_questions ADD COLUMN {$c} " . ($sqlite ? 'DATETIME' : 'DATETIME NULL'));
            // Önceden yanıtlanmış sorular satıcı tarafından okunmuş sayılır
            if ($c === 'seller_read_at') $pdo->exec("UPDATE book_questions SET seller_read_at = replied_at WHERE reply IS NOT NULL");
        }
    }
})();

/** Satıcı soruları görüntüledi: okunmamışları okundu yapar ($store_id null → Yeniçeri'ye gelenler) */
function bq_mark_seller_read($store_id) {
    $now = date('Y-m-d H:i:s');
    $store_id === null
        ? db_query("UPDATE book_questions SET seller_read_at = ? WHERE store_id IS NULL AND seller_read_at IS NULL", [$now])
        : db_query("UPDATE book_questions SET seller_read_at = ? WHERE store_id = ? AND seller_read_at IS NULL", [$now, (int)$store_id]);
}

/** Müşteri yanıtları Hesabım'da gördü: yanıtlanmış ve okunmamışları okundu yapar */
function bq_mark_customer_read($customer_id) {
    db_query("UPDATE book_questions SET customer_read_at = ? WHERE customer_id = ? AND reply IS NOT NULL AND customer_read_at IS NULL", [date('Y-m-d H:i:s'), (int)$customer_id]);
}

/** Müşterinin okumadığı yanıt sayısı (Hesabım rozeti için) */
function bq_customer_unread_count($customer_id) {
    return (int)(db_fetch("SELECT COUNT(*) AS c FROM book_questions WHERE customer_id = ? AND reply IS NOT NULL AND customer_read_at IS NULL", [(int)$customer_id])['c'] ?? 0);
}

/** "okundu" rozeti: ✓ gönderildi / ✓✓ okundu (tarih) */
function bq_read_badge($read_at, $who) {
    if ($read_at) {
        return '<span class="text-primary fs-8" title="' . e($who . ' okudu: ' . date('d.m.Y H:i', strtotime($read_at))) . '"><i class="bi bi-check2-all"></i> ' . e($who) . ' okudu · ' . e(date('d.m.Y H:i', strtotime($read_at))) . '</span>';
    }
    return '<span class="text-muted fs-8" title="Henüz okunmadı"><i class="bi bi-check2"></i> Gönderildi, ' . e(mb_strtolower($who, 'UTF-8')) . ' henüz okumadı</span>';
}

/** Okunmamış soru sayısı: $store_id null → Yeniçeri Kitabevi'ne gelenler */
function bq_unread_count($store_id) {
    $row = $store_id === null
        ? db_fetch("SELECT COUNT(*) AS c FROM book_questions WHERE store_id IS NULL AND reply IS NULL")
        : db_fetch("SELECT COUNT(*) AS c FROM book_questions WHERE store_id = ? AND reply IS NULL", [(int)$store_id]);
    return (int)($row['c'] ?? 0);
}

/** Soruları listeler (kitap adı ve adresiyle) */
function bq_list($store_id, $limit = 100) {
    $sql = "SELECT q.*, b.title AS book_title, b.slug AS book_slug FROM book_questions q LEFT JOIN books b ON b.id = q.book_id WHERE ";
    return $store_id === null
        ? db_fetch_all($sql . "q.store_id IS NULL ORDER BY (q.reply IS NULL) DESC, q.id DESC LIMIT " . (int)$limit)
        : db_fetch_all($sql . "q.store_id = ? ORDER BY (q.reply IS NULL) DESC, q.id DESC LIMIT " . (int)$limit, [(int)$store_id]);
}

/**
 * Yanıtı kaydeder ve müşteriye e-postayla gönderir. $store_id: yanıtlayanın dükkânı (null = Yeniçeri);
 * başka dükkânın sorusu yanıtlanamaz. Dönüş: [başarılı mı, mesaj]
 */
function bq_reply($question_id, $store_id, $reply) {
    $reply = trim(str_replace("\r", '', (string)$reply));
    if ($reply === '' || mb_strlen($reply, 'UTF-8') > 2000) return [false, 'Yanıt boş olamaz (en fazla 2000 karakter).'];
    $q = db_fetch("SELECT q.*, b.title AS book_title, b.slug AS book_slug FROM book_questions q LEFT JOIN books b ON b.id = q.book_id WHERE q.id = ?", [(int)$question_id]);
    if (!$q || ($store_id === null ? $q['store_id'] !== null : (int)$q['store_id'] !== (int)$store_id)) return [false, 'Soru bulunamadı.'];
    db_query("UPDATE book_questions SET reply = ?, replied_at = CURRENT_TIMESTAMP, is_read = 1, seller_read_at = COALESCE(seller_read_at, ?), customer_read_at = NULL WHERE id = ?", [$reply, date('Y-m-d H:i:s'), (int)$q['id']]);
    $seller = $store_id === null ? APP_NAME : (db_fetch("SELECT name FROM stores WHERE id = ?", [(int)$store_id])['name'] ?? 'Satıcı');
    $book_url = $q['book_id'] ? rtrim(SITE_URL, '/') . '/' . book_path(['id' => $q['book_id'], 'slug' => $q['book_slug']]) : rtrim(SITE_URL, '/');
    $sent = false;
    if (function_exists('send_mail') && filter_var($q['email'], FILTER_VALIDATE_EMAIL)) {
        $html = mail_layout('Sorunuz yanıtlandı', '<p>Merhaba ' . e($q['name']) . ',</p>'
            . '<p><b>' . e($seller) . '</b>, ' . ($q['book_title'] ? '“' . e($q['book_title']) . '” hakkındaki ' : '') . 'sorunuzu yanıtladı.</p>'
            . '<p style="color:#666;border-left:3px solid #ddd;padding-left:10px">' . nl2br(e($q['message'])) . '</p>'
            . '<p style="border-left:3px solid #c1121f;padding-left:10px">' . nl2br(e($reply)) . '</p>'
            . mail_button($q['customer_id'] ? rtrim(SITE_URL, '/') . '/hesap.php#sorularim' : $book_url, $q['customer_id'] ? 'Yanıtı sitede görüntüle' : 'Kitabı görüntüle'));
        $text = "Merhaba {$q['name']},\n\n{$seller} sorunuzu yanıtladı.\n\nSorunuz: {$q['message']}\n\nYanıt: {$reply}\n\n{$book_url}";
        $sent = send_mail($q['email'], APP_NAME . ' - Sorunuz yanıtlandı', $html, $text, 'soru');
    }
    return [true, 'Yanıt kaydedildi' . ($sent ? ' ve müşteriye e-postayla gönderildi.' : '. (E-posta gönderilemedi; müşteri üyeyse Hesabım sayfasında görür.)')];
}

/** Soru listesi + yanıt formu (Satıcı Paneli ve Yönetim paneli ortak). $book_prefix: kitap bağlantısının ön eki */
function bq_list_html(array $questions, $action_url, $token = '', $book_prefix = '../') {
    if (!$questions) return '<div class="text-center text-muted py-5">Henüz soru yok. Müşteriler kitap sayfalarındaki “Satıcıya soru sor” düğmesiyle soru sorabilir.</div>';
    $h = '<div class="list-group list-group-flush">';
    foreach ($questions as $q) {
        $h .= '<div class="list-group-item p-3 ' . ($q['reply'] === null ? 'bg-warning bg-opacity-10' : '') . '">';
        $h .= '<div class="d-flex justify-content-between flex-wrap gap-2 fs-8 text-muted mb-1"><span><b class="text-dark">' . e($q['name']) . '</b>'
            . ($q['book_title'] ? ' • <a href="' . e($book_prefix . book_path(['id' => $q['book_id'], 'slug' => $q['book_slug']])) . '" target="_blank" class="text-danger">' . e($q['book_title']) . '</a>' : '')
            . (empty($q['seller_read_at']) ? ' <span class="badge bg-danger ms-1">Yeni</span>' : '')
            . '</span><span>' . e(date('d.m.Y H:i', strtotime($q['created_at']))) . ($q['reply'] === null ? ' • <span class="badge bg-warning text-dark">Yanıt bekliyor</span>' : ' • <span class="badge bg-success">Yanıtlandı</span>') . '</span></div>';
        $h .= '<div class="fs-7 mb-2" style="white-space: pre-line;">' . e($q['message']) . '</div>';
        if ($q['reply'] !== null) {
            $h .= '<div class="fs-7 border-start border-3 border-danger ps-2 text-secondary" style="white-space: pre-line;">' . e($q['reply']) . '</div>';
            $h .= '<div class="mt-1">' . ($q['customer_id']
                ? bq_read_badge($q['customer_read_at'] ?? null, 'Müşteri')
                : '<span class="text-muted fs-8"><i class="bi bi-envelope-check"></i> Üye olmayan müşteriye e-postayla gönderildi (okunma bilgisi alınamaz)</span>') . '</div>';
        } else {
            $h .= '<form method="POST" action="' . e($action_url) . '" class="d-flex gap-2 align-items-start">'
                . '<input type="hidden" name="action" value="bq_reply"><input type="hidden" name="qid" value="' . (int)$q['id'] . '">'
                . ($token !== '' ? '<input type="hidden" name="token" value="' . e($token) . '">' : '')
                . '<textarea name="reply" class="form-control form-control-sm" rows="2" maxlength="2000" required placeholder="Yanıtınız (müşteriye e-postayla gider)"></textarea>'
                . '<button class="btn btn-sm btn-danger text-nowrap"><i class="bi bi-send me-1"></i>Yanıtla</button></form>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}
