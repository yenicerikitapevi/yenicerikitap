<?php
/**
 * ZİYARET / TRAFİK KAYDI (raporlama için)
 * - Her vitrin sayfası açılışı 'view', sepete ekleme 'cart_add' olarak analytics_events tablosuna yazılır.
 * - Zamanlar UTC olarak yazılır (siparişlerdeki CURRENT_TIMESTAMP ile aynı); raporlar Türkiye saatine (UTC+3) çevirir.
 * - Kişisel veri (IP, isim) tutulmaz: ziyaretçi ve oturum, rastgele çerezlerin özetiyle (hash) tanınır.
 * - Oturum (ziyaret) 30 dakika hareketsizlikten sonra biter; sayfada kalma süresi çıkışta track.php'ye gönderilir.
 * - Yönetici ve satıcı oturumları ile arama motoru botları sayılmaz.
 */
if (!defined('SITE_URL')) exit;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, page_type TEXT, book_id INTEGER, store_id INTEGER, visitor_hash TEXT, session_hash TEXT, referrer_host TEXT, duration_sec INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_events (id INT AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(20) NOT NULL, page_type VARCHAR(20) NULL, book_id INT NULL, store_id INT NULL, visitor_hash CHAR(40) NULL, session_hash CHAR(40) NULL, referrer_host VARCHAR(120) NULL, duration_sec INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_ae_created (created_at), INDEX idx_ae_book (book_id), INDEX idx_ae_store (store_id), INDEX idx_ae_session (session_hash)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
}
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ae_created ON analytics_events(created_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ae_book ON analytics_events(book_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ae_store ON analytics_events(store_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ae_session ON analytics_events(session_hash);");
}

/**
 * Bu istek sayılmalı mı? (bot, yönetici, satıcı değilse)
 */
function analytics_should_track() {
    if (isset($_SESSION['admin_user']) || isset($_SESSION['store_user'])) {
        return false;
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|curl|wget|python|java\/|headless|lighthouse|monitor|uptime/i', $ua)) {
        return false;
    }
    return true;
}

/**
 * Ziyaretçi (1 yıl) ve oturum (30 dk hareketsizlik) çerezlerini hazırlar; özetlerini döndürür
 */
function analytics_ids() {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $secure = (strpos(SITE_URL, 'https://') === 0);
    $vid = $_COOKIE['yk_vid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
        $vid = bin2hex(random_bytes(16));
    }
    $sid = $_COOKIE['yk_sid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) {
        $sid = bin2hex(random_bytes(16));
    }
    if (!headers_sent()) {
        setcookie('yk_vid', $vid, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('yk_sid', $sid, ['expires' => time() + 1800, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    }
    $ids = ['visitor' => sha1('v' . $vid), 'session' => sha1('s' . $sid)];
    return $ids;
}

/**
 * Sayfa görüntülemesini kaydeder. HTML çıktısından ÖNCE çağrılmalı (çerez yazar).
 * $page_type: home, category, detail, store, checkout, blog, contact, legal
 */
function track_view($page_type, $book_id = null, $store_id = null) {
    $GLOBALS['yk_track_id'] = 0;
    if (!analytics_should_track()) {
        return 0;
    }
    try {
        $ids = analytics_ids();
        $ref = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
        $own = parse_url(SITE_URL, PHP_URL_HOST);
        if ($ref !== '' && strcasecmp(preg_replace('/^www\./i', '', $ref), preg_replace('/^www\./i', '', (string)$own)) === 0) {
            $ref = ''; // site içi gezinme kaynak sayılmaz
        }
        db_query(
            "INSERT INTO analytics_events (event_type, page_type, book_id, store_id, visitor_hash, session_hash, referrer_host, created_at) VALUES ('view', ?, ?, ?, ?, ?, ?, ?)",
            [$page_type, $book_id ?: null, $store_id ?: null, $ids['visitor'], $ids['session'], $ref !== '' ? substr(strtolower($ref), 0, 120) : null, gmdate('Y-m-d H:i:s')]
        );
        $GLOBALS['yk_track_id'] = (int)db_last_id();
        // Ara sıra 13 aydan eski kayıtları temizle
        if (mt_rand(1, 500) === 1) {
            db_query("DELETE FROM analytics_events WHERE created_at < ?", [gmdate('Y-m-d H:i:s', strtotime('-400 days'))]);
        }
    } catch (Throwable $e) {
        // Raporlama hatası sayfayı asla bozmamalı
    }
    return $GLOBALS['yk_track_id'];
}

/**
 * Sayfa sonuna eklenen küçük betik: sayfada kalma süresini çıkışta gönderir, sepete eklemeleri bildirir
 */
function track_script() {
    $id = (int)($GLOBALS['yk_track_id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    return <<<HTML
<script>
(function () {
    var id = {$id}, shown = Date.now(), total = 0, sent = 0;
    function send(data) {
        var fd = new FormData();
        for (var k in data) fd.append(k, data[k]);
        if (navigator.sendBeacon) navigator.sendBeacon('track.php', fd);
        else fetch('track.php', { method: 'POST', body: fd, keepalive: true, credentials: 'same-origin' });
    }
    function report() {
        var s = Math.round((total + (shown ? Date.now() - shown : 0)) / 1000);
        if (s > sent) { sent = s; send({ a: 'time', id: id, s: s }); }
    }
    function flush() {
        if (shown) { total += Date.now() - shown; shown = 0; }
        report();
    }
    // Çıkış bildirimi bazı tarayıcılarda kaybolabilir; açık sekmede süre 15 sn'de bir de güncellenir (en fazla 30 dk)
    var timer = setInterval(function () {
        if (shown) report();
        if (sent >= 1800) clearInterval(timer);
    }, 15000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush(); else shown = Date.now();
    });
    window.addEventListener('pagehide', flush);
    window.ykTrackCart = function (bookId) { send({ a: 'cart', book: bookId }); };
})();
</script>
HTML;
}
