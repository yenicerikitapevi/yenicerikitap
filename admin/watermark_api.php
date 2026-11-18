<?php
require_once __DIR__ . '/../config.php';
auth_check('admin');
require_once __DIR__ . '/watermark_lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$csrf = $_SESSION['watermark_csrf'] ?? '';
session_write_close(); // A slow cleaner must not block other admin requests.
try {
    wm_init();
    if (isset($_GET['image'])) {
        $item = wm_item((string)$_GET['image']);
        $kind = (string)($_GET['kind'] ?? '');
        if (!in_array($kind, ['original', 'preview'], true)) throw new RuntimeException('Görsel türü geçersiz.');
        $file = wm_dir($item) . ($kind === 'original' ? '/original' : '/preview.jpg');
        if ($kind === 'preview' && !is_file($file) && $item['status'] === 'applied' && wm_name((string)$item['output_name'])) $file = UPLOAD_DIR . '/' . $item['output_name'];
        if (!is_file($file)) { http_response_code(404); exit; }
        $meta = @getimagesize($file);
        if (!$meta) throw new RuntimeException('Görsel okunamadı.');
        header('Content-Type: ' . image_type_to_mime_type($meta[2]));
        header('Content-Length: ' . filesize($file)); readfile($file); exit;
    }
    $job = (string)($_POST['job'] ?? $_GET['job'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('Oturum doğrulanamadı; sayfayı yenileyin.'); }
        $lock = wm_lock();
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'create') {
            $job = wm_create();
        } elseif ($op === 'purge') {
            if (!wm_exec('SELECT id FROM wm_jobs WHERE id = ?', [$job])->fetch()) throw new RuntimeException('İş bulunamadı.');
            $freed = wm_purge($job);
            if (function_exists('log_action')) log_action('Görsel Temizliği Yedekleri Silindi', 'Watermarks', null, 'İş: ' . $job . ', ' . round($freed / 1048576, 1) . ' MB');
        } elseif ($op === 'retry') {
            $item = wm_item((string)($_POST['item'] ?? ''));
            if ($item['job_id'] !== $job) throw new RuntimeException('İşlem kaydı bu işe ait değil.');
            wm_exec("UPDATE wm_items SET error = '', status = ? WHERE id = ?", [$item['status'] === 'error' ? 'pending' : $item['status'], $item['id']]);
        } elseif (in_array($op, ['prepare', 'apply', 'restore'], true)) {
            if (!wm_exec('SELECT id FROM wm_jobs WHERE id = ?', [$job])->fetch()) throw new RuntimeException('İş bulunamadı.');
            $state = ['prepare' => 'pending', 'apply' => 'ready', 'restore' => 'applied'][$op];
            $itemId = (string)($_POST['item'] ?? '');
            $limit = max(1, min(50, (int)($_POST['limit'] ?? 50)));
            // Tek istekte en çok ~20 sn ya da $limit görsel (binlerce görselde istek sayısı azalır; PHP süre sınırı aşılmaz)
            $until = microtime(true) + 20; $done = 0;
            do {
                // Geri alma ters sırada (aynı kitabın görselleri en son yayımlanandan başlayarak)
            $s = wm_exec('SELECT * FROM wm_items WHERE job_id = ? AND status = ?' . ($itemId !== '' ? ' AND id = ?' : '') . " AND COALESCE(error, '') = '' ORDER BY seq " . ($op === 'restore' ? 'DESC' : 'ASC') . " LIMIT 1", $itemId !== '' ? [$job, $state, $itemId] : [$job, $state]);
                $item = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor();
                if (!$item) break;
                try {
                    if ($op === 'prepare') wm_prepare($item);
                    if ($op === 'apply') wm_apply($item);
                    if ($op === 'restore') wm_restore($item);
                } catch (Throwable $e) {
                    // Hatalı görsel elle incelenmek üzere işaretlenir; kuyruk dönmeye devam eder
                    wm_exec('UPDATE wm_items SET error = ? WHERE id = ?', [$e->getMessage(), $item['id']]);
                }
                $done++;
            } while ($itemId === '' && $done < $limit && microtime(true) < $until);
        } else { throw new RuntimeException('İşlem geçersiz.'); }
        // İşlem kaydı yalnızca iş oluşturmada (binlerce görselde her adım kaydedilmez)
        if ($op === 'create' && function_exists('log_action')) log_action('Görsel Temizliği', 'Watermarks', null, 'Yeni iş: ' . $job);
        flock($lock, LOCK_UN); fclose($lock);
    }
    $result = wm_status($job, (int)($_GET['offset'] ?? 0));
    $result['processed'] = $done ?? 0;
    if (isset($freed)) $result['freed'] = $freed;
    $result['disk'] = wm_disk_usage();
    $result['remaining'] = isset($state) ? (int)wm_exec("SELECT COUNT(*) FROM wm_items WHERE job_id = ? AND status = ? AND COALESCE(error, '') = ''", [$job, $state])->fetchColumn() : 0;
    header('Content-Type: application/json; charset=utf-8'); echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
