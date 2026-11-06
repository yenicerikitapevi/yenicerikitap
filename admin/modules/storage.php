<?php
/**
 * ADMIN MODULE: Disk Kullanımı (p=Storage)
 * Kalıcı diskte (Render /var/data) neyin ne kadar yer kapladığını gösterir; onayla yer açar:
 *  - Hiçbir kitapta / dükkânda kullanılmayan (artık) yüklenmiş görseller (1 saatten eski olanlar)
 *  - WebP önbelleği (cover.php gerektiğinde yeniden üretir)
 *  - Örnek veri temizliğinden kalan eski veritabanı yedekleri
 *  - Veritabanını sıkıştırma (VACUUM): silinen kayıtların boş alanını geri verir
 */
if (!defined('SITE_URL')) exit;

$is_sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$data_dir = $is_sqlite ? dirname(SQLITE_PATH) : UPLOAD_DIR;

$fmt_bytes = function ($b) {
    $b = (float)$b;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024 || $u === 'GB') return number_format($b, $u === 'B' ? 0 : 1, ',', '.') . ' ' . $u;
        $b /= 1024;
    }
};

/** Kitapların, ek görsellerin ve dükkân logolarının kullandığı yerel dosya adları */
function storage_referenced_files() {
    $ref = [];
    $add = function ($v) use (&$ref) {
        $v = trim((string)$v);
        if ($v !== '' && !preg_match('#^https?://#i', $v)) $ref[basename($v)] = true;
    };
    foreach (db_fetch_all("SELECT cover_image, extra_images FROM books") as $b) {
        $add($b['cover_image']);
        $x = json_decode((string)($b['extra_images'] ?? ''), true);
        if (is_array($x)) foreach ($x as $f) $add($f);
    }
    try {
        foreach (db_fetch_all("SELECT logo FROM stores") as $s) $add($s['logo']);
    } catch (Throwable $e) {}
    return $ref;
}

/** Yükleme klasöründeki dosyalar: ad => [boyut, değişme zamanı] */
function storage_upload_files($dir) {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isFile()) $out[$f->getFilename()] = [$f->getSize(), $f->getMTime()];
    }
    return $out;
}

$webp_dir = UPLOAD_DIR . '/webp';
$backups = $is_sqlite ? (glob(SQLITE_PATH . '.yedek-*') ?: []) : [];

// --- İşlemler (her biri onay ister) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op'])) {
    @set_time_limit(180);
    $op = $_POST['op'];
    $freed = 0;
    $n = 0;
    if ($op === 'orphans') {
        $ref = storage_referenced_files();
        $limit = time() - 3600; // devam eden bir aktarımın yeni yazdığı dosyalara dokunma
        foreach (storage_upload_files(UPLOAD_DIR) as $name => [$size, $mtime]) {
            if (isset($ref[$name]) || $mtime > $limit || $name === '.htaccess') continue;
            if (@unlink(UPLOAD_DIR . '/' . $name)) { $freed += $size; $n++; }
        }
        log_action('Artık Görseller Silindi', 'Storage', null, $n . ' dosya, ' . $fmt_bytes($freed));
        set_flash('success', "Kullanılmayan {$n} görsel silindi, " . $fmt_bytes($freed) . ' yer açıldı.');
    } elseif ($op === 'webp') {
        foreach (storage_upload_files($webp_dir) as $name => [$size]) {
            if (@unlink($webp_dir . '/' . $name)) { $freed += $size; $n++; }
        }
        log_action('WebP Önbelleği Silindi', 'Storage', null, $n . ' dosya, ' . $fmt_bytes($freed));
        set_flash('success', "WebP önbelleğinden {$n} dosya silindi, " . $fmt_bytes($freed) . ' yer açıldı. Görseller ziyaret edildikçe yeniden üretilir.');
    } elseif ($op === 'backups') {
        foreach ($backups as $f) {
            $size = (int)@filesize($f);
            if (@unlink($f)) { $freed += $size; $n++; }
        }
        log_action('Eski Yedekler Silindi', 'Storage', null, $n . ' dosya, ' . $fmt_bytes($freed));
        set_flash('success', "{$n} eski veritabanı yedeği silindi, " . $fmt_bytes($freed) . ' yer açıldı.');
    } elseif ($op === 'vacuum' && $is_sqlite) {
        $before = (int)@filesize(SQLITE_PATH);
        try {
            $pdo->exec('VACUUM');
            clearstatcache();
            $freed = max(0, $before - (int)@filesize(SQLITE_PATH));
            log_action('Veritabanı Sıkıştırıldı', 'Storage', null, $fmt_bytes($freed));
            set_flash('success', 'Veritabanı sıkıştırıldı, ' . $fmt_bytes($freed) . ' yer açıldı.');
        } catch (Throwable $e) {
            set_flash('danger', 'Sıkıştırma yapılamadı (diskte veritabanı kadar boş yer gerekir; önce görselleri temizleyin): ' . $e->getMessage());
        }
    }
    redirect('index.php?p=Storage');
}

// --- Ölçüm ---
$total = @disk_total_space($data_dir) ?: 0;
$free = @disk_free_space($data_dir) ?: 0;
$used_pct = $total > 0 ? round(($total - $free) / $total * 100) : 0;

$db_size = 0;
if ($is_sqlite) {
    foreach (['', '-wal', '-journal', '-shm'] as $suf) $db_size += (int)@filesize(SQLITE_PATH . $suf);
}
$backup_size = 0;
foreach ($backups as $f) $backup_size += (int)@filesize($f);

$files = storage_upload_files(UPLOAD_DIR);
$ref = storage_referenced_files();
$up_size = 0; $orphan_size = 0; $orphan_n = 0; $recent_n = 0;
$limit = time() - 3600;
foreach ($files as $name => [$size, $mtime]) {
    $up_size += $size;
    if (!isset($ref[$name]) && $name !== '.htaccess') {
        if ($mtime > $limit) { $recent_n++; continue; }
        $orphan_size += $size;
        $orphan_n++;
    }
}
$webp_files = storage_upload_files($webp_dir);
$webp_size = array_sum(array_column($webp_files, 0));

$tables = [];
foreach (['books', 'analytics_events', 'nk_run_codes', 'action_logs', 'orders', 'book_questions'] as $t) {
    try { $tables[$t] = (int)(db_fetch("SELECT COUNT(*) AS c FROM {$t}")['c'] ?? 0); } catch (Throwable $e) {}
}
?>

<div class="mb-4">
    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-hdd text-danger me-2"></i>Disk Kullanımı</h5>
    <p class="text-muted fs-7 mb-0">Veritabanı ve yüklenen görseller kalıcı diskte (<code><?= e($data_dir) ?></code>) durur. Disk dolarsa yeni kitap, sipariş ve aktarım kaydedilemez ("database or disk is full").</p>
</div>

<div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
        <b>Disk: <?= $fmt_bytes($total - $free) ?> / <?= $fmt_bytes($total) ?> dolu</b>
        <span class="<?= $used_pct >= 90 ? 'text-danger fw-bold' : 'text-muted' ?>">Boş: <?= $fmt_bytes($free) ?> (%<?= 100 - $used_pct ?>)</span>
    </div>
    <div class="progress" style="height: 14px;"><div class="progress-bar <?= $used_pct >= 90 ? 'bg-danger' : ($used_pct >= 75 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= $used_pct ?>%"></div></div>
    <?php if ($used_pct >= 90): ?>
        <div class="alert alert-danger fs-7 mt-3 mb-0">Disk neredeyse dolu. Aşağıdan kullanılmayan görselleri silin; yetmezse Render → servis → <b>Disks</b> bölümünden disk boyutunu büyütün.</div>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0 fs-7">
            <thead class="table-light fs-8"><tr><th class="ps-3">Ne</th><th class="text-end">Boyut</th><th>Açıklama</th><th class="pe-3 text-end"></th></tr></thead>
            <tbody>
                <tr><td class="ps-3 fw-semibold">Veritabanı</td><td class="text-end"><?= $fmt_bytes($db_size) ?></td>
                    <td class="fs-8 text-muted"><?php foreach ($tables as $t => $c): ?><?= e($t) ?>: <?= number_format($c, 0, ',', '.') ?> • <?php endforeach; ?></td>
                    <td class="pe-3 text-end"><?php if ($is_sqlite): ?><form method="POST" class="d-inline" onsubmit="return confirm('Veritabanı sıkıştırılsın mı? Birkaç saniye sürer.');"><input type="hidden" name="op" value="vacuum"><button class="btn btn-sm btn-outline-dark">Sıkıştır</button></form><?php endif; ?></td></tr>
                <tr><td class="ps-3 fw-semibold">Yüklenen görseller</td><td class="text-end"><?= $fmt_bytes($up_size) ?></td>
                    <td class="fs-8 text-muted"><?= number_format(count($files), 0, ',', '.') ?> dosya (kapaklar ve aktarılan kitap fotoğrafları)</td><td class="pe-3"></td></tr>
                <tr class="<?= $orphan_n ? 'table-warning' : '' ?>"><td class="ps-3 fw-semibold">↳ Kullanılmayan görseller</td><td class="text-end fw-bold"><?= $fmt_bytes($orphan_size) ?></td>
                    <td class="fs-8 text-muted"><?= number_format($orphan_n, 0, ',', '.') ?> dosya hiçbir kitapta kullanılmıyor (yeniden aktarım ya da silinen kitaplardan kalmış).<?= $recent_n ? ' Son 1 saatte yazılan ' . $recent_n . ' dosyaya dokunulmaz.' : '' ?></td>
                    <td class="pe-3 text-end"><?php if ($orphan_n): ?><form method="POST" class="d-inline" onsubmit="return confirm('Hiçbir kitapta kullanılmayan <?= $orphan_n ?> görsel silinsin mi? Geri alınamaz.');"><input type="hidden" name="op" value="orphans"><button class="btn btn-sm btn-danger">Sil</button></form><?php endif; ?></td></tr>
                <tr><td class="ps-3 fw-semibold">WebP önbelleği</td><td class="text-end"><?= $fmt_bytes($webp_size) ?></td>
                    <td class="fs-8 text-muted"><?= number_format(count($webp_files), 0, ',', '.') ?> dosya. Sayfaların hızlı açılması için üretilen kopyalar; silinirse ziyaret edildikçe yeniden üretilir.</td>
                    <td class="pe-3 text-end"><?php if ($webp_files): ?><form method="POST" class="d-inline" onsubmit="return confirm('WebP önbelleği silinsin mi? Görseller ziyaret edildikçe yeniden üretilir.');"><input type="hidden" name="op" value="webp"><button class="btn btn-sm btn-outline-danger">Sil</button></form><?php endif; ?></td></tr>
                <?php if ($is_sqlite): ?>
                <tr><td class="ps-3 fw-semibold">Eski veritabanı yedekleri</td><td class="text-end"><?= $fmt_bytes($backup_size) ?></td>
                    <td class="fs-8 text-muted"><?= count($backups) ?> dosya (Örnek Veri Temizliği öncesi alınan tam yedekler)<?= $backups ? ': ' . e(implode(', ', array_map('basename', $backups))) : '' ?></td>
                    <td class="pe-3 text-end"><?php if ($backups): ?><form method="POST" class="d-inline" onsubmit="return confirm('Eski veritabanı yedekleri silinsin mi? Geri alınamaz.');"><input type="hidden" name="op" value="backups"><button class="btn btn-sm btn-outline-danger">Sil</button></form><?php endif; ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted fs-8">Önce kullanılmayan görselleri ve yedekleri silin, sonra veritabanını sıkıştırın (sıkıştırma için diskte veritabanı kadar boş yer gerekir).</p>
