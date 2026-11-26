<?php
/**
 * Toplu görsel yükleme günlüğü: her çalıştırmada değişen kitapların eski görselleri (geri alma için).
 * Disk Kullanımı'ndaki "kullanılmayan görselleri sil" işlemi son 30 günün günlüğündeki eski görselleri korur.
 */
if (!defined('SITE_URL')) exit;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bulk_image_log (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL, book_id INTEGER NOT NULL, old_cover TEXT, old_extra TEXT,
        created_by TEXT, undone_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_bulk_image_log ON bulk_image_log (run_id, book_id)");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bulk_image_log (id INT AUTO_INCREMENT PRIMARY KEY, run_id VARCHAR(40) NOT NULL, book_id INT NOT NULL, old_cover VARCHAR(500) NULL, old_extra TEXT NULL,
        created_by VARCHAR(120) NULL, undone_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY ux_bulk_image_log (run_id, book_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
}

/** Son 30 gündeki toplu yüklemelerin geri alma için sakladığı eski görsel adları */
function bulk_images_protected_files() {
    $out = [];
    foreach (db_fetch_all("SELECT old_cover, old_extra FROM bulk_image_log WHERE undone_at IS NULL AND created_at >= ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]) as $r) {
        foreach (array_merge([(string)$r['old_cover']], (array)(json_decode((string)$r['old_extra'], true) ?: [])) as $f) {
            $f = trim((string)$f);
            if ($f !== '' && !preg_match('#^https?://#i', $f)) $out[basename($f)] = true;
        }
    }
    return $out;
}
