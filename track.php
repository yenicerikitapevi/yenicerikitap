<?php
/**
 * Ziyaret kaydı uç noktası (navigator.sendBeacon ile çağrılır)
 *  a=time&id=..&s=..  → sayfada kalma süresini günceller (yalnızca aynı ziyaretçinin kaydı)
 *  a=cart&book=..     → sepete ekleme olayı
 */
require_once __DIR__ . '/config.php';

http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !analytics_should_track() || empty($_COOKIE['yk_vid'])) {
    exit;
}

$ids = analytics_ids();
$action = $_POST['a'] ?? '';

try {
    if ($action === 'time') {
        $id = (int)($_POST['id'] ?? 0);
        $sec = min(1800, max(0, (int)($_POST['s'] ?? 0)));
        if ($id > 0) {
            db_query("UPDATE analytics_events SET duration_sec = ? WHERE id = ? AND visitor_hash = ? AND event_type = 'view' AND duration_sec < ?", [$sec, $id, $ids['visitor'], $sec]);
        }
    } elseif ($action === 'cart') {
        $book = db_fetch("SELECT id, store_id FROM books WHERE id = ?", [(int)($_POST['book'] ?? 0)]);
        if ($book) {
            db_query(
                "INSERT INTO analytics_events (event_type, book_id, store_id, visitor_hash, session_hash, created_at) VALUES ('cart_add', ?, ?, ?, ?, ?)",
                [$book['id'], $book['store_id'] ?: null, $ids['visitor'], $ids['session'], gmdate('Y-m-d H:i:s')]
            );
        }
    }
} catch (Throwable $e) {
}
