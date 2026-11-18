<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// php -d extension_dir=... -d extension=pdo_sqlite -d extension=gd tests/watermark_test.php
$root = sys_get_temp_dir() . '/wm-test-' . bin2hex(random_bytes(8));
mkdir($root); mkdir($root . '/uploads');
define('UPLOAD_DIR', $root . '/uploads'); putenv('WATERMARK_DATA_DIR=' . $root . '/private');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, cover_image TEXT, extra_images TEXT)');
require __DIR__ . '/../admin/watermark_lib.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function fail_expected(callable $f, $message) { try { $f(); } catch (Throwable $e) { check(true, $message); return; } throw new RuntimeException('Expected failure: ' . $message); }
function fixture(string $name, int $color): void {
    $im = imagecreatetruecolor(500, 667); imagefill($im, 0, 0, $color); imagepng($im, UPLOAD_DIR . '/' . $name); imagedestroy($im);
}
function ready(array $item): array {
    $dir = wm_dir($item); copy(wm_source($item['source_name']), $dir . '/original');
    copy($dir . '/original', $dir . '/preview.jpg');
    wm_exec("UPDATE wm_items SET status = 'ready', source_sha = ? WHERE id = ?", [hash_file('sha256', $dir . '/original'), $item['id']]);
    return wm_item($item['id']);
}
wm_init(); $lock = wm_lock();
try {
    fixture('cover.png', 0x718293); fixture('extra.png', 0x214365);
    wm_exec('INSERT INTO books VALUES (1, ?, ?)', ['cover.png', '["cover.png","extra.png"]']);
    wm_exec('INSERT INTO books VALUES (2, ?, ?)', ['cover.png', null]);
    wm_exec('INSERT INTO books VALUES (3, ?, ?)', ['https://example.org/image.jpg', null]);
    $job = wm_create(); $items = wm_exec('SELECT * FROM wm_items WHERE job_id = ? ORDER BY seq', [$job])->fetchAll(PDO::FETCH_ASSOC);
    check(count($items) === 2, 'Deduplicate shared images and exclude external images');
    check(wm_status(wm_create())['total'] === 0, 'Do not enqueue files already in an active job');
    check(!wm_name('../escape.png') && !wm_name('a\\escape.png') && !wm_name('https://a/b.png') && !wm_name('x.php') && !wm_name("a\0.png"), 'Reject unsafe paths and unsupported extensions');
    $item = ready($items[0]); $hash = hash_file('sha256', wm_source('cover.png'));
    wm_apply($item); $item = wm_item($item['id']);
    $b1 = wm_exec('SELECT * FROM books WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $b2 = wm_exec('SELECT * FROM books WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
    check($item['status'] === 'applied' && $b1['cover_image'] === $item['output_name'] && $b2['cover_image'] === $item['output_name'], 'Publish shared references in one transaction');
    check(json_decode($b1['extra_images'], true)[0] === $item['output_name'], 'Update extra-image references');
    check(!is_file(UPLOAD_DIR . '/cover.png') && hash_file('sha256', wm_dir($item) . '/original') === $hash, 'Unused source file removed after publish; verified backup kept');
    check(!is_file(wm_dir($item) . '/preview.jpg'), 'Preview removed after publish');
    check(wm_status(wm_create())['total'] === 0, 'Never enqueue generated output images');
    wm_exec('UPDATE books SET extra_images = ? WHERE id = 2', ['["new.png"]']);
    fail_expected(fn() => wm_restore($item), 'Refuse rollback after a concurrent book edit');
    check(wm_exec('SELECT cover_image FROM books WHERE id = 1')->fetchColumn() === $item['output_name'], 'A rollback conflict leaves all book references unchanged');
    wm_exec('UPDATE books SET extra_images = NULL WHERE id = 2');
    $outName = $item['output_name']; wm_restore($item);
    check(!is_file(UPLOAD_DIR . '/' . $outName), 'Unused cleaned copy removed after rollback');
    check(hash_file('sha256', wm_source('cover.png')) === $hash, 'Restore original bytes even after orphan cleanup');
    check(wm_exec('SELECT cover_image FROM books WHERE id = 1')->fetchColumn() === 'cover.png' && wm_item($item['id'])['status'] === 'restored', 'Restore cover and extra-image references');
    $other = ready($items[1]); file_put_contents(wm_dir($other) . '/original', 'broken');
    fail_expected(fn() => wm_apply($other), 'Reject corrupt backups before publication');
    wm_exec("UPDATE wm_items SET status = 'pending' WHERE id = ?", [$other['id']]);
    if (getenv('WATERMARK_CLASSPATH')) {
        wm_prepare(wm_item($other['id']));
        check(wm_item($other['id'])['status'] === 'skipped', 'Real Java cleaner skips unmarked solid image');
        check(!is_file(wm_dir($other) . '/original'), 'Release unneeded backup for skipped image');
    }
    wm_exec("UPDATE wm_items SET status = 'pending' WHERE id = ?", [$other['id']]);
    unlink(UPLOAD_DIR . '/extra.png'); wm_prepare(wm_item($other['id']));
    check(wm_item($other['id'])['status'] === 'error', 'Persist per-image errors so the queue can continue');
    echo "All watermark checks passed.\n";
} finally {
    flock($lock, LOCK_UN); fclose($lock);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($root);
}
