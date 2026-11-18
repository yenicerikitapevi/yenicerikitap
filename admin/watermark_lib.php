<?php
/**
 * Sunucuda görsel temizliği (yönetim paneli → Görsel Temizliği). Çağıran yönetici doğrulamasını yapar; yazan işlemler wm_lock() tutar.
 * Disk: çıktı JPEG; yayımlanınca önizleme ve artık kullanılmayan eski dosya silinir (orijinalin yedeği işlem klasöründe kalır,
 * geri almada geri yazılır). Hazırlık için en az WM_DISK_RESERVE boş yer bırakılır (veritabanı yazabilsin).
 */
const WM_DISK_RESERVE = 300 * 1024 * 1024;
function wm_exec(string $sql, array $args = []): PDOStatement {
    $s = $GLOBALS['pdo']->prepare($sql); $s->execute($args); return $s;
}
function wm_root(): string {
    $dir = getenv('WATERMARK_DATA_DIR') ?: dirname(UPLOAD_DIR) . '/watermark-jobs';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Yedek klasörü oluşturulamadı.');
    $real = realpath($dir); $web = realpath(__DIR__ . '/..');
    $a = strtolower(str_replace('\\', '/', $real . '/'));
    $b = strtolower(str_replace('\\', '/', $web . '/'));
    if (str_starts_with($a, $b)) throw new RuntimeException('WATERMARK_DATA_DIR web kökü dışında kalıcı bir klasör olmalı.');
    return $real;
}
function wm_init(): void {
    wm_root();
    $mysql = $GLOBALS['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $jsonType = $mysql ? 'LONGTEXT' : 'TEXT';
    if ($mysql) {
        $table = wm_exec("SHOW TABLE STATUS LIKE 'books'")->fetch(PDO::FETCH_ASSOC);
        if (strtolower($table['Engine'] ?? '') !== 'innodb') throw new RuntimeException('Görsel işlemleri için books tablosu InnoDB olmalı.');
    }
    wm_exec('CREATE TABLE IF NOT EXISTS wm_jobs (id VARCHAR(32) PRIMARY KEY, created_at VARCHAR(32) NOT NULL)' . $suffix);
    wm_exec('CREATE TABLE IF NOT EXISTS wm_items (id VARCHAR(32) PRIMARY KEY, job_id VARCHAR(32) NOT NULL,
        seq INTEGER NOT NULL, source_name VARCHAR(255) NOT NULL, source_sha VARCHAR(64), output_name VARCHAR(255),
        status VARCHAR(20) NOT NULL, kind VARCHAR(20), error TEXT, changes_json ' . $jsonType . ')' . $suffix);
    // Each index is created once, not on every poll; MySQL has no CREATE INDEX IF NOT EXISTS.
    if ($GLOBALS['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        wm_exec('CREATE INDEX IF NOT EXISTS wm_queue ON wm_items(job_id, status, seq)');
    } elseif (!wm_exec("SHOW INDEX FROM wm_items WHERE Key_name = 'wm_queue'")->fetch()) {
        wm_exec('CREATE INDEX wm_queue ON wm_items(job_id, status, seq)');
    }
}
function wm_lock() {
    $h = fopen(wm_root() . '/worker.lock', 'c');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) throw new RuntimeException('Başka bir görsel işlemi sürüyor; biraz sonra tekrar deneyin.');
    return $h;
}
function wm_name(string $s): bool {
    return strlen($s) <= 255 && !str_contains($s, "\0") && basename($s) === $s
        && !str_contains($s, '\\') && !str_contains($s, ':')
        && preg_match('/\.(jpe?g|png|webp)$/iD', $s);
}
function wm_source(string $s): string {
    if (!wm_name($s)) throw new RuntimeException('Yerel görsel adı geçersiz.');
    $p = UPLOAD_DIR . '/' . $s;
    if (is_link($p) || !is_file($p)) throw new RuntimeException('Görsel bulunamadı veya dosya bağlantısı desteklenmiyor.');
    return $p;
}
function wm_item(string $id): array {
    $r = wm_exec('SELECT * FROM wm_items WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('İşlem kaydı bulunamadı.'); return $r;
}
function wm_dir(array $item): string {
    foreach (['id', 'job_id'] as $key) if (!preg_match('/^[a-f0-9]{32}$/D', $item[$key])) throw new RuntimeException('İşlem kimliği geçersiz.');
    $d = wm_root() . '/' . $item['job_id'] . '/' . $item['id'];
    if (!is_dir($d) && !mkdir($d, 0700, true)) throw new RuntimeException('Yedek klasörü açılamadı.');
    return $d;
}
function wm_create(): string {
    $id = bin2hex(random_bytes(16)); $seen = [];
    $active = array_fill_keys(wm_exec("SELECT source_name FROM wm_items WHERE status IN ('pending','ready','applied')")->fetchAll(PDO::FETCH_COLUMN), true);
    $s = wm_exec('SELECT cover_image, extra_images FROM books ORDER BY id');
    while ($b = $s->fetch(PDO::FETCH_ASSOC)) {
        $extras = json_decode((string)$b['extra_images'], true);
        foreach (array_merge([(string)$b['cover_image']], is_array($extras) ? $extras : []) as $name) {
            if (!is_string($name) || !wm_name($name) || str_starts_with($name, 'wmclean_') || isset($active[$name])) continue;
            if (is_file(UPLOAD_DIR . '/' . $name) && !is_link(UPLOAD_DIR . '/' . $name)) $seen[$name] = true;
        }
    }
    $GLOBALS['pdo']->beginTransaction();
    try {
        wm_exec('INSERT INTO wm_jobs (id, created_at) VALUES (?, ?)', [$id, date('c')]);
        $n = 0;
        foreach ($seen as $name => $_) wm_exec('INSERT INTO wm_items (id, job_id, seq, source_name, status) VALUES (?, ?, ?, ?, ?)', [bin2hex(random_bytes(16)), $id, ++$n, $name, 'pending']);
        $GLOBALS['pdo']->commit();
    } catch (Throwable $e) { $GLOBALS['pdo']->rollBack(); throw $e; }
    return $id;
}
function wm_clean(string $input, string $output): array {
    $meta = @getimagesize($input);
    if (!$meta || $meta[0] * $meta[1] > 2000000 || filesize($input) > 16 * 1024 * 1024)
        throw new RuntimeException('Desteklenen sınır: 2 milyon piksel ve 16 MB.');
    if (!in_array($meta[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('JPEG, PNG veya WebP gerekli.');
    $converted = dirname($output) . '/input.png';
    if ($meta[2] === IMAGETYPE_WEBP) {
        $im = @imagecreatefromwebp($input);
        if (!$im || !imagepng($im, $converted)) throw new RuntimeException('WebP okunamadı.');
        imagedestroy($im); $input = $converted;
    }
    $stdout = dirname($output) . '/stdout.txt'; $stderr = dirname($output) . '/stderr.txt';
    $cmd = [getenv('WATERMARK_JAVA') ?: 'java', '-Djava.awt.headless=true', '-Xmx128m', '-cp',
        getenv('WATERMARK_CLASSPATH') ?: '/opt/watermark/*:/usr/share/java/*', 'WatermarkCli', $input, $output];
    $p = proc_open($cmd, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Sunucu temizleyicisi başlatılamadı.');
    $deadline = microtime(true) + 25; $timedOut = false;
    do {
        $state = proc_get_status($p);
        if (!$state['running']) break;
        if (microtime(true) > $deadline) { $timedOut = true; proc_terminate($p, 9); break; }
        usleep(100000);
    } while (true);
    $exit = proc_close($p);
    if (isset($state['exitcode']) && $state['exitcode'] >= 0) $exit = $state['exitcode'];
    @unlink($converted);
    if ($timedOut || $exit !== 0) throw new RuntimeException($timedOut ? 'Temizleyici süre sınırına ulaştı.' : 'Sunucu temizleyicisi çalışmadı; sunucu günlüğünü kontrol edin.');
    $report = json_decode((string)file_get_contents($stdout), true);
    if (!is_array($report) || !in_array($report['status'] ?? '', ['ready', 'skipped'], true)) throw new RuntimeException('Temizleyiciden geçerli yanıt alınamadı.');
    if ($report['status'] === 'ready') {
        $result = @getimagesize($output);
        if (!$result || $result[0] !== $meta[0] || $result[1] !== $meta[1] || $result[2] !== IMAGETYPE_JPEG) throw new RuntimeException('Çıktı doğrulanamadı.');
    }
    return $report;
}
function wm_prepare(array $item): void {
    if ($item['status'] !== 'pending') return;
    try {
        $source = wm_source($item['source_name']); $dir = wm_dir($item);
        $free = disk_free_space(wm_root());
        if ($free === false || $free < WM_DISK_RESERVE + filesize($source) * 3) throw new RuntimeException('Diskte yeterli boş yer yok (en az 300 MB boş kalmalı). Disk Kullanımı sayfasından yer açın.');
        $sha = hash_file('sha256', $source);
        if (!copy($source, $dir . '/original') || hash_file('sha256', $dir . '/original') !== $sha) throw new RuntimeException('Yedek doğrulanamadı.');
        $report = wm_clean($dir . '/original', $dir . '/preview.jpg');
        wm_exec('UPDATE wm_items SET status = ?, source_sha = ?, kind = ?, error = ? WHERE id = ?',
            [$report['status'], $sha, $report['kind'] ?? '', $report['status'] === 'skipped' ? 'Güvenilir filigran eşleşmesi bulunmadı; değiştirilmedi.' : '', $item['id']]);
        if ($report['status'] === 'skipped') @unlink($dir . '/original');
    } catch (Throwable $e) { wm_exec("UPDATE wm_items SET status = 'error', error = ? WHERE id = ?", [$e->getMessage(), $item['id']]); }
}
/** Exact values and compare-and-swap protect edits made by imports and other admins. */
function wm_swap_book(int $id, array $before, array $after): void {
    $s = wm_exec("UPDATE books SET cover_image = ?, extra_images = ? WHERE id = ? AND COALESCE(cover_image, '') = ? AND COALESCE(extra_images, '') = ?",
        [$after['cover_image'], $after['extra_images'], $id, (string)$before['cover_image'], (string)$before['extra_images']]);
    if ($s->rowCount() !== 1) throw new RuntimeException('Kitap görselleri başka bir işlemde değişti. İşlem uygulanmadı.');
}
function wm_apply(array $item): void {
    if ($item['status'] !== 'ready') return;
    $dir = wm_dir($item); $source = wm_source($item['source_name']);
    if (hash_file('sha256', $source) !== $item['source_sha'] || hash_file('sha256', $dir . '/original') !== $item['source_sha'])
        throw new RuntimeException('Önizlemeden sonra kaynak veya yedek değişti.');
    $output = 'wmclean_' . $item['id'] . '.jpg'; $dest = UPLOAD_DIR . '/' . $output;
    if (file_exists($dest)) {
        // Önceki istek kopyalayıp referansları kaydetmeden durmuş olabilir
        $output = 'wmclean_' . $item['id'] . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $dest = UPLOAD_DIR . '/' . $output;
    }
    $tmp = $dest . '.tmp';
    if (!copy($dir . '/preview.jpg', $tmp) || !rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException('Temiz kopya kaydedilemedi.'); }
    $GLOBALS['pdo']->beginTransaction();
    try {
        $changes = [];
        // Yalnızca bu dosyayı kullanan kitaplar (tüm tablo her görselde taranmaz)
        $s = wm_exec('SELECT id, cover_image, extra_images FROM books WHERE cover_image = ? OR extra_images LIKE ?', [$item['source_name'], '%' . $item['source_name'] . '%']);
        $books = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        foreach ($books as $b) {
            $before = ['cover_image' => $b['cover_image'], 'extra_images' => $b['extra_images']]; $after = $before;
            if ($b['cover_image'] === $item['source_name']) $after['cover_image'] = $output;
            $extras = json_decode((string)$b['extra_images'], true);
            if (is_array($extras) && in_array($item['source_name'], $extras, true)) {
                $after['extra_images'] = json_encode(array_map(fn($x) => $x === $item['source_name'] ? $output : $x, $extras), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            if ($after === $before) continue;
            wm_swap_book((int)$b['id'], $before, $after);
            $changes[] = ['id' => (int)$b['id'], 'before' => $before, 'after' => $after];
        }
        if (!$changes) throw new RuntimeException('Kaynak görsel artık hiçbir kitapta kullanılmıyor.');
        wm_exec("UPDATE wm_items SET status = 'applied', output_name = ?, changes_json = ?, error = '' WHERE id = ?", [$output, json_encode($changes, JSON_THROW_ON_ERROR), $item['id']]);
        $GLOBALS['pdo']->commit();
    } catch (Throwable $e) { $GLOBALS['pdo']->rollBack(); @unlink($dest); throw $e; }
    // Disk: önizleme artık gereksiz; eski dosya hiçbir kitapta kalmadıysa silinir (doğrulanmış yedeği işlem klasöründe)
    @unlink($dir . '/preview.jpg');
    wm_unlink_if_unused($item['source_name']);
}
/** Yükleme klasöründeki dosya hiçbir kitapta kullanılmıyorsa siler */
function wm_unlink_if_unused(string $name): void {
    if (!wm_name($name)) return;
    $used = wm_exec('SELECT id FROM books WHERE cover_image = ? OR extra_images LIKE ? LIMIT 1', [$name, '%"' . $name . '"%'])->fetch();
    if (!$used && is_file(UPLOAD_DIR . '/' . $name) && !is_link(UPLOAD_DIR . '/' . $name)) @unlink(UPLOAD_DIR . '/' . $name);
}
function wm_restore(array $item): void {
    if ($item['status'] !== 'applied') return;
    $backup = wm_dir($item) . '/original';
    if (!is_file($backup) || hash_file('sha256', $backup) !== $item['source_sha']) throw new RuntimeException('Yedek doğrulanamadı.');
    if (!wm_name($item['source_name'])) throw new RuntimeException('Dosya adı geçersiz.');
    $dest = UPLOAD_DIR . '/' . $item['source_name'];
    if (file_exists($dest) || is_link($dest)) {
        if (is_link($dest) || hash_file('sha256', $dest) !== $item['source_sha']) throw new RuntimeException('Eski dosya adı farklı bir içerikle kullanılıyor; üzerine yazılmadı.');
    } else {
        // Exclusive create: never replace a file produced by another operation.
        $out = @fopen($dest, 'xb'); $in = fopen($backup, 'rb');
        if (!$out) { fclose($in); throw new RuntimeException('Eski dosya geri yazılamadı.'); }
        $ok = stream_copy_to_stream($in, $out); fclose($in); fclose($out);
        if (!$ok || hash_file('sha256', $dest) !== $item['source_sha']) { @unlink($dest); throw new RuntimeException('Geri yüklenen dosya doğrulanamadı.'); }
    }
    $changes = json_decode((string)$item['changes_json'], true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['pdo']->beginTransaction();
    try {
        foreach ($changes as $c) wm_swap_book((int)$c['id'], $c['after'], $c['before']);
        wm_exec("UPDATE wm_items SET status = 'restored', error = '' WHERE id = ?", [$item['id']]);
        $GLOBALS['pdo']->commit();
    } catch (Throwable $e) { $GLOBALS['pdo']->rollBack(); throw $e; }
    if (!empty($item['output_name'])) wm_unlink_if_unused($item['output_name']);
}
function wm_status(string $job, int $offset = 0): array {
    $counts = [];
    foreach (wm_exec('SELECT status, COUNT(*) AS n FROM wm_items WHERE job_id = ? GROUP BY status', [$job])->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['status']] = (int)$r['n'];
    $s = $GLOBALS['pdo']->prepare('SELECT id, seq, source_name, status, kind, error FROM wm_items WHERE job_id = ? ORDER BY seq LIMIT 12 OFFSET ' . max(0, $offset)); $s->execute([$job]);
    return ['job' => $job, 'counts' => $counts, 'items' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => array_sum($counts)];
}
