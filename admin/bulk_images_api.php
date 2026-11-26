<?php
/**
 * TOPLU GÖRSEL YÜKLEME (admin/modules/bulkimages.php sayfası kullanır)
 *  action=match  : names[] (klasördeki göreli yollar) → hangi dosya hangi kitaba gider (dosya adındaki NadirKitap kodu ya da ISBN)
 *  action=upload : run, mode, files[] + book[] + seq[] → görselleri kaydeder; kitabın görsel listesini bu çalıştırmada ilk kez görünce
 *                  'replace' modunda eski görsellerin yerine koyar, sonrakileri ekler (kapak + en fazla 7 ek görsel)
 *  action=undo   : run → bu çalıştırmada değişen kitapların eski görsellerini geri yükler
 * Eski görsel dosyaları silinmez (geri alınabilsin); kullanılmayanlar Disk Kullanımı sayfasından temizlenebilir.
 */
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(120);
require_once __DIR__ . '/../config.php';

function bimg_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SESSION['admin_user']['role'] ?? '') !== 'admin') bimg_out(['ok' => false, 'message' => 'Oturum kapalı, yeniden giriş yapın.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['bulk_images_token']) || !hash_equals($_SESSION['bulk_images_token'], (string)($_POST['token'] ?? ''))) {
    bimg_out(['ok' => false, 'message' => 'Geçersiz istek. Sayfayı yenileyin.'], 403);
}
$admin_name = $_SESSION['admin_user']['name'] ?? '';
session_write_close();

$action = (string)($_POST['action'] ?? '');
$run = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_POST['run'] ?? '')));

if ($action === 'match') {
    $names = json_decode((string)($_POST['names'] ?? '[]'), true);
    if (!is_array($names) || !$names) bimg_out(['ok' => false, 'message' => 'Klasörde görsel bulunamadı.']);
    $names = array_slice(array_values(array_filter($names, 'is_string')), 0, 50000);
    $scope = (string)($_POST['scope'] ?? 'yeniceri');
    $mode = ($_POST['mode'] ?? 'replace') === 'empty' ? 'empty' : 'replace';
    [$by_nk, $by_isbn, $books] = bimg_index($scope);
    // Yarıda kalan yüklemeye devam: o çalıştırmada başlanmış kitaplar 'yalnızca görselsizler' modunda da tamamlanır
    $in_run = $run !== '' ? array_flip(array_map('intval', array_column(db_fetch_all("SELECT book_id FROM bulk_image_log WHERE run_id = ? AND undone_at IS NULL", [$run]), 'book_id'))) : [];
    $files = [];
    $unmatched = [];
    foreach ($names as $i => $path) {
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $path)) continue;
        $bid = bimg_match_path($path, $by_nk, $by_isbn);
        if ($bid) $files[$bid][] = ['i' => $i, 'path' => $path];
        else $unmatched[] = $path;
    }
    $plan = [];
    $skipped_has = 0;
    $over = 0;
    foreach ($files as $bid => $list) {
        $b = $books[$bid];
        $has = bimg_has_images($b);
        if ($mode === 'empty' && $has && !isset($in_run[$bid])) { $skipped_has++; continue; }
        // Sıra: ek numarası olmayan (5550001.jpg) kapak, sonra _2, (3)…; eşitse dosya yolu
        usort($list, function ($a, $c) {
            $d = bimg_order_key($a['path']) <=> bimg_order_key($c['path']);
            if ($d) return $d;
            $na = preg_replace('/\.[a-z0-9]+$/i', '', basename(str_replace('\\', '/', $a['path'])));
            $nc = preg_replace('/\.[a-z0-9]+$/i', '', basename(str_replace('\\', '/', $c['path'])));
            return strnatcasecmp($na, $nc) ?: strnatcasecmp($a['path'], $c['path']); // "kod.jpg" "kod-arka.jpg"dan önce
        });
        if (count($list) > 8) { $over += count($list) - 8; $list = array_slice($list, 0, 8); }
        foreach ($list as $seq => $f) $plan[] = ['i' => $f['i'], 'book' => (int)$bid, 'seq' => $seq];
    }
    $sample = [];
    foreach (array_slice(array_keys($files), 0, 12) as $bid) {
        $sample[] = ['title' => $books[$bid]['title'], 'code' => $books[$bid]['nadirkitap_code'] ?: $books[$bid]['isbn'], 'n' => count($files[$bid]), 'has' => bimg_has_images($books[$bid])];
    }
    bimg_out(['ok' => true, 'plan' => $plan, 'books' => count($files), 'books_planned' => count(array_unique(array_column($plan, 'book'))), 'skipped_has' => $skipped_has,
        'over' => $over, 'unmatched' => count($unmatched), 'unmatched_sample' => array_slice($unmatched, 0, 30), 'sample' => $sample,
        'free_mb' => (int)(bimg_free() / 1048576)]);
}

if ($action === 'upload') {
    if ($run === '') bimg_out(['ok' => false, 'message' => 'Çalıştırma kimliği yok.']);
    $mode = ($_POST['mode'] ?? 'replace') === 'empty' ? 'empty' : 'replace';
    $f = $_FILES['files'] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) bimg_out(['ok' => false, 'message' => 'Dosya gelmedi (boyut sınırı aşılmış olabilir).']);
    if (bimg_free() < 300 * 1048576) bimg_out(['ok' => false, 'stop' => true, 'message' => 'Diskte yer kalmadı (en az 300 MB boş kalmalı). Disk Kullanımı sayfasından kullanılmayan görselleri temizleyin.']);
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    $book_ids = array_map('intval', (array)($_POST['book'] ?? []));
    $seqs = array_map('intval', (array)($_POST['seq'] ?? []));
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $saved = 0;
    $errors = [];
    $by_book = [];
    foreach (array_keys($f['name']) as $k) {
        $bid = $book_ids[$k] ?? 0;
        $nm = (string)$f['name'][$k];
        if ($bid <= 0 || ($f['error'][$k] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$k])) { $errors[] = $nm . ': yüklenemedi'; continue; }
        if ($f['size'][$k] > 15 * 1048576) { $errors[] = $nm . ': 15 MB\'tan büyük'; continue; }
        $info = @getimagesize($f['tmp_name'][$k]);
        if (!$info || !isset($allowed[$info[2]])) { $errors[] = $nm . ': JPG/PNG/WEBP değil'; continue; }
        bimg_fix_orientation($f['tmp_name'][$k], $info[2]); // telefon fotoğrafı yan/ters görünmesin
        $md5 = md5_file($f['tmp_name'][$k]);
        $fname = 'bulk_' . $bid . '_' . (($seqs[$k] ?? 0) + 1) . '_' . substr($md5, 0, 8) . '.' . $allowed[$info[2]];
        $is_new = !file_exists(UPLOAD_DIR . '/' . $fname);
        if (!@move_uploaded_file($f['tmp_name'][$k], UPLOAD_DIR . '/' . $fname)) { $errors[] = $nm . ': kaydedilemedi'; continue; }
        $by_book[$bid][] = ['seq' => $seqs[$k] ?? 0, 'name' => $fname, 'new' => $is_new];
        $saved++;
    }
    // Sunucunun dosya sayısı sınırı yüzünden alınmayan dosyalar sessizce kaybolmasın
    for ($k = count($f['name']); $k < count($book_ids); $k++) $errors[] = 'Dosya #' . ($k + 1) . ': sunucu bu gruptaki dosyayı almadı';
    $updated = 0;
    foreach ($by_book as $bid => $list) {
        usort($list, function ($a, $b) { return $a['seq'] <=> $b['seq']; });
        if (bimg_apply($run, $bid, array_column($list, 'name'), $mode, $admin_name)) { $updated++; continue; }
        // Kitaba yazılmadıysa bu istekte oluşturulan dosyalar silinir (diskte sahipsiz kalmasın)
        foreach ($list as $x) if ($x['new']) @unlink(UPLOAD_DIR . '/' . $x['name']);
        $saved -= count($list);
        $errors[] = 'Kitap #' . $bid . ': atlandı (artık görseli var ya da kitap bulunamadı)';
    }
    bimg_out(['ok' => true, 'saved' => $saved, 'books' => $updated, 'errors' => $errors, 'free_mb' => (int)(bimg_free() / 1048576)]);
}

if ($action === 'undo') {
    $rows = db_fetch_all("SELECT * FROM bulk_image_log WHERE run_id = ? AND undone_at IS NULL AND created_at >= ?", [$run, date('Y-m-d H:i:s', time() - BULK_IMAGES_KEEP_DAYS * 86400)]);
    if (!$rows) bimg_out(['ok' => false, 'message' => 'Geri alınacak kayıt yok ya da ' . BULK_IMAGES_KEEP_DAYS . ' günlük geri alma süresi doldu.']);
    $n = 0;
    $kept = 0;
    foreach ($rows as $r) {
        // Kitap daha sonraki (geri alınmamış) bir yüklemede de değiştiyse dokunulmaz; önce o yükleme geri alınmalı
        $later = db_fetch("SELECT id FROM bulk_image_log WHERE book_id = ? AND id > ? AND undone_at IS NULL LIMIT 1", [(int)$r['book_id'], (int)$r['id']]);
        if ($later) { $kept++; continue; }
        // Kitabın görselleri bu yüklemeden sonra elle değiştirildiyse dokunulmaz
        if ($r['new_cover'] !== null) {
            $st = db_query("UPDATE books SET cover_image = ?, extra_images = ? WHERE id = ? AND COALESCE(cover_image, '') = ? AND COALESCE(extra_images, '') = ?",
                [$r['old_cover'], $r['old_extra'], (int)$r['book_id'], (string)$r['new_cover'], (string)$r['new_extra']]);
            if ($st->rowCount() !== 1) { $kept++; continue; }
        } else {
            db_query("UPDATE books SET cover_image = ?, extra_images = ? WHERE id = ?", [$r['old_cover'], $r['old_extra'], (int)$r['book_id']]);
        }
        db_query("UPDATE bulk_image_log SET undone_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int)$r['id']]);
        $n++;
    }
    log_action('Toplu Görsel Geri Alındı', 'Products', null, "{$n} kitabın görselleri eski hâline döndü ({$run})" . ($kept ? ", {$kept} kitap sonraki yüklemede değiştiği için atlandı" : ''));
    bimg_out(['ok' => true, 'message' => "{$n} kitabın görselleri eski hâline döndürüldü." . ($kept ?  " {$kept} kitap sonradan değiştiği (daha yeni bir yükleme ya da elle düzenleme) için atlandı." : '')]);
}

bimg_out(['ok' => false, 'message' => 'Bilinmeyen işlem.'], 400);

// ---------------------------------------------------------------------------

/** Kapsamdaki kitaplar: NadirKitap kodu ve ISBN (rakam/X) dizinleri */
function bimg_index($scope) {
    $where = '1=1';
    $params = [];
    if ($scope === 'yeniceri') {
        $where = 'store_id IS NULL';
    } elseif (preg_match('/^store:(\d+)$/', $scope, $m)) {
        $where = 'store_id = ?';
        $params[] = (int)$m[1];
    }
    $by_nk = [];
    $by_isbn = [];
    $books = [];
    foreach (db_fetch_all("SELECT id, title, isbn, nadirkitap_code, cover_image, extra_images FROM books WHERE {$where}", $params) as $b) {
        $books[(int)$b['id']] = $b;
        $nk = trim((string)$b['nadirkitap_code']);
        if ($nk !== '') $by_nk[$nk] = $by_nk[$nk] ?? (int)$b['id'];
        $is = strtoupper(preg_replace('/[^0-9Xx]/', '', (string)$b['isbn']));
        if (strlen($is) >= 10) {
            foreach (bimg_isbn_forms($is) as $form) $by_isbn[$form] = $by_isbn[$form] ?? (int)$b['id'];
        }
    }
    return [$by_nk, $by_isbn, $books];
}

/**
 * Dosya yolundan kitap: önce dosya adı, sonra üst klasör adları. ISBN (tire/boşluklu olabilir) ya da NadirKitap kodu (4-10 hane).
 * "5296984_2.jpg", "5296984 (3).jpg", "9789753420012-arka.jpg", "5296984/1.jpg" gibi adlar eşleşir.
 */
function bimg_match_path($path, $by_nk, $by_isbn) {
    $parts = preg_split('#[\\\\/]#', $path);
    $base = preg_replace('/\.[a-z0-9]+$/i', '', array_pop($parts));
    $cands = array_merge([$base], array_reverse($parts));
    foreach ($cands as $s) {
        // ISBN: tire/boşlukları kaldırıp 13 haneli 978/979 ya da 10 haneli
        // önce bitişik yazım ("9789754580034-2"), sonra tireli/boşluklu yazım ("978-975-458-003-4")
        foreach ([strtoupper($s), strtoupper(preg_replace('/(?<=[0-9])[\s-]+(?=[0-9X])/i', '', $s))] as $flat) {
            if (preg_match_all('/(?<![0-9])(97[89][0-9]{10}|[0-9]{9}[0-9X])(?![0-9])/', $flat, $m)) {
                foreach ($m[1] as $c) if ((strlen($c) === 13 || bimg_isbn_ok($c)) && isset($by_isbn[$c])) return $by_isbn[$c]; // 10 hanede sağlama şart (tarih/saat sayıları karışmasın)
            }
        }
        // NadirKitap kodu: adın başında olmalı (ör. "5296984", "5296984_2", "NK-5296984 arka"); "IMG_20231105_1234" gibi adlar eşleşmez
        if (preg_match('/^\s*(?:nk|NK|Nk)?[\s#_-]*([0-9]{4,10})(?:[\s_.\-()\[\]].*)?$/u', $s, $m)) {
            $c = $m[1];
            if (isset($by_nk[$c])) return $by_nk[$c];
            $t = ltrim($c, '0');
            if ($t !== $c && isset($by_nk[$t])) return $by_nk[$t];
        }
    }
    return 0;
}

/** ISBN'in 10 ve 13 haneli yazımları (dosya adında hangisi olursa eşleşsin) */
function bimg_isbn_forms($is) {
    $out = [$is];
    if (strlen($is) === 13 && strpos($is, '978') === 0) {
        $core = substr($is, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) $sum += (10 - $i) * (int)$core[$i];
        $c = (11 - $sum % 11) % 11;
        $out[] = $core . ($c === 10 ? 'X' : (string)$c);
    } elseif (strlen($is) === 10) {
        $core = '978' . substr($is, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int)$core[$i] * ($i % 2 ? 3 : 1);
        $out[] = $core . ((10 - $sum % 10) % 10);
    }
    return $out;
}

/** ISBN sağlama basamağı doğru mu (rastgele sayılar ISBN sanılmasın) */
function bimg_isbn_ok($c) {
    if (strlen($c) === 13) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int)$c[$i] * ($i % 2 ? 3 : 1);
        return (10 - $sum % 10) % 10 === (int)$c[12];
    }
    if (strlen($c) === 10) {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) $sum += (10 - $i) * (int)$c[$i];
        $chk = (11 - $sum % 11) % 11;
        return ($chk === 10 ? 'X' : (string)$chk) === $c[9];
    }
    return false;
}

/** Dosya adındaki kod/ISBN dışındaki ilk sayı (sıra numarası); yoksa 0 */
function bimg_order_key($path) {
    $base = preg_replace('/\.[a-z0-9]+$/i', '', basename(str_replace('\\', '/', $path)));
    $flat = preg_replace('/(?<=[0-9])[\s-]+(?=[0-9X])/i', '', $base);
    if (preg_match('/(?<![0-9])(97[89][0-9]{10}|[0-9]{9}[0-9X])(?![0-9])/i', $base, $m)) {
        $rest = str_replace($m[1], ' ', $base);
    } elseif (preg_match('/(?<![0-9])(97[89][0-9]{10}|[0-9]{9}[0-9X])(?![0-9])/i', $flat, $m)) {
        $rest = str_replace($m[1], ' ', $flat);
    } else {
        $rest = preg_replace('/^\s*(?:nk)?[\s#_-]*[0-9]{4,10}/i', ' ', $base, 1);
    }
    return preg_match('/([0-9]{1,3})/', $rest, $m) ? (int)$m[1] : 0;
}

/** JPEG'in EXIF yönünü uygular (GD ve WebP dönüşümü EXIF'i yok sayar); gerekmiyorsa dosyaya dokunmaz */
function bimg_fix_orientation($path, $type) {
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) return;
    $ex = @exif_read_data($path);
    $deg = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
    if (!$deg) return;
    $im = @imagecreatefromjpeg($path);
    if (!$im) return;
    $r = imagerotate($im, $deg, 0);
    if ($r) { @imagejpeg($r, $path, 88); imagedestroy($r); }
    imagedestroy($im);
}

function bimg_has_images($b) {
    $c = trim((string)$b['cover_image']);
    return $c !== '' && $c !== 'book_placeholder.svg';
}

function bimg_free() {
    $f = @disk_free_space(is_dir(UPLOAD_DIR) ? UPLOAD_DIR : dirname(UPLOAD_DIR));
    return $f === false ? PHP_INT_MAX : (int)$f;
}

/** Kitaba görselleri yazar; bu çalıştırmada kitap ilk kez görülüyorsa eski hâli günlüğe alınır (geri alma için) */
function bimg_apply($run, $book_id, array $names, $mode, $admin_name) {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $b = db_fetch("SELECT id, cover_image, extra_images FROM books WHERE id = ?", [(int)$book_id]);
        if (!$b) { $pdo->rollBack(); return false; }
        $log = db_fetch("SELECT id FROM bulk_image_log WHERE run_id = ? AND book_id = ?", [$run, (int)$book_id]);
        if (!$log) {
            if ($mode === 'empty' && bimg_has_images($b)) { $pdo->rollBack(); return false; }
            db_query("INSERT INTO bulk_image_log (run_id, book_id, old_cover, old_extra, created_by) VALUES (?, ?, ?, ?, ?)",
                [$run, (int)$book_id, $b['cover_image'], $b['extra_images'], mb_substr($admin_name, 0, 120, 'UTF-8')]);
            $current = []; // ilk dosyalar: eski görsellerin yerine
        } else {
            $current = array_values(array_filter(array_merge([(string)$b['cover_image']], (array)(json_decode((string)$b['extra_images'], true) ?: []))));
            $current = array_values(array_unique($current));
        }
        $all = array_slice(array_values(array_unique(array_merge($current, $names))), 0, 8);
        $new_extra = count($all) > 1 ? json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        db_query("UPDATE books SET cover_image = ?, extra_images = ? WHERE id = ?", [$all[0], $new_extra, (int)$book_id]);
        db_query("UPDATE bulk_image_log SET new_cover = ?, new_extra = ? WHERE run_id = ? AND book_id = ?", [$all[0], $new_extra, $run, (int)$book_id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Yeniceri] Toplu görsel: ' . $e->getMessage());
        return false;
    }
}
