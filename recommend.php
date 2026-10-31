<?php
/**
 * "Bunlara da bakın" önerileri (sepete ekleme penceresi için)
 * recommend.php?id=12&exclude=3,5 → aynı yazar, sonra aynı kategori, sonra vitrindeki kitaplar (en fazla 4)
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

$id = (int)($_GET['id'] ?? 0);
$exclude = array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['exclude'] ?? '')))), 0, 100);
$exclude[] = $id;
$ex = implode(',', array_unique($exclude));
$book = db_fetch("SELECT id, author_id, category_id, store_id FROM books WHERE id = ?", [$id]);
$rand = $GLOBALS['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'RANDOM()' : 'RAND()';

$sel = "SELECT b.id, b.title, b.slug, b.price, b.old_price, b.cover_image, b.stock, a.name as author_name FROM books b LEFT JOIN authors a ON a.id = b.author_id
        WHERE b.is_active = 1 AND b.stock > 0 AND b.id NOT IN ({$ex})";
$out = [];
$add = function ($rows) use (&$out) {
    foreach ($rows as $r) {
        if (count($out) >= 4) return;
        $out[$r['id']] = $r;
    }
};
if ($book) {
    $add(db_fetch_all($sel . " AND b.author_id = ? ORDER BY b.id DESC LIMIT 4", [$book['author_id']]));
    if (count($out) < 4) $add(db_fetch_all($sel . " AND b.category_id = ?" . ($out ? " AND b.id NOT IN (" . implode(',', array_keys($out)) . ")" : '') . " ORDER BY {$rand} LIMIT 8", [$book['category_id']]));
}
if (count($out) < 4) {
    $add(db_fetch_all($sel . ($out ? " AND b.id NOT IN (" . implode(',', array_keys($out)) . ")" : '') . " AND b.id IN (SELECT book_id FROM book_showcase) ORDER BY b.id DESC LIMIT 8"));
}

echo json_encode(array_values(array_map(function ($r) {
    return [
        'id' => (int)$r['id'], 'title' => $r['title'], 'author' => $r['author_name'], 'price' => (float)$r['price'],
        'old_price' => $r['old_price'] ? (float)$r['old_price'] : null, 'url' => '/' . book_path($r), 'image' => '/' . ltrim(cover_url($r['cover_image'] ?? ''), '/'),
    ];
}, $out)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
