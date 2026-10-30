<?php
/**
 * ADMIN MODULE: Örnek Veri Temizliği (p=Cleanup)
 * Kurulumda veritabanına eklenen örnek (gerçek olmayan) kayıtları bulur ve onaydan sonra siler.
 * Eşleştirme sıkıdır: sipariş numarası, ISBN + kitap adı, @example.com e-postaları. Silmeden önce veritabanı yedeklenir.
 * Kategoriler, vitrinler, alt dükkânlar ve site ayarlarına dokunulmaz.
 */
if (!defined('SITE_URL')) exit;

// Kurulum örnek verisi (config.php → init_sqlite_db ile aynı)
$seed_orders = ['YNC-20260810-001', 'YNC-20260810-002'];
$seed_emails = ['ahmet@example.com', 'ayse@example.com'];
$seed_books = [
    '9789754580001' => 'Nutuk (Özel Ciltli)', '9789753420012' => 'Yüzüklerin Efendisi: Yüzük Kardeşliği', '9786053600023' => 'Satranç',
    '9789754580034' => 'Suç ve Ceza', '9786053600045' => 'Dönüşüm', '9786051140056' => 'Devlet-i ‘Aliyye (1. Cilt)', '9786053600067' => 'Amok Koşucusu',
    '9789753420029' => 'Yüzüklerin Efendisi: İki Kule', '9789753420036' => 'Yüzüklerin Efendisi: Kralın Dönüşü', '9789754580100' => 'Çalıkuşu',
];
$seed_authors = ['Mustafa Kemal Atatürk', 'J.R.R. Tolkien', 'Stefan Zweig', 'Fyodor Dostoyevski', 'Franz Kafka', 'Halil İnalcık'];
$seed_publishers = ['Türkiye İş Bankası Kültür Yayınları', 'Metis Yayınları', 'Can Yayınları', 'Kronik Kitap'];
$seed_collections = ['hasan-ali-yucel-serisi', 'ataturkun-tavsiyeleri', 'yuzuklerin-efendisi', 'stefan-zweig-serisi'];

$in = function (array $vals) { return implode(',', array_fill(0, count($vals), '?')); };

/** Silinecekleri hesaplar (önizleme ve silme aynı listeyi kullanır) */
$plan = function () use ($seed_orders, $seed_emails, $seed_books, $seed_authors, $seed_publishers, $seed_collections, $in) {
    $p = [];
    $p['orders'] = db_fetch_all("SELECT o.id, o.order_number, o.total_amount, o.status, o.created_at, u.name as customer FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.order_number IN (" . $in($seed_orders) . ")", $seed_orders);
    $order_ids = array_map('intval', array_column($p['orders'], 'id'));

    $p['books_delete'] = [];
    $p['books_deactivate'] = [];
    $books = db_fetch_all("SELECT b.id, b.title, b.isbn, b.is_active, b.store_id FROM books b WHERE b.isbn IN (" . $in(array_keys($seed_books)) . ")", array_keys($seed_books));
    foreach ($books as $b) {
        if (($seed_books[$b['isbn']] ?? null) !== $b['title'] || !empty($b['store_id'])) {
            continue; // adı değiştirilmiş ya da sahafa taşınmış: gerçek kayıt sayılır, dokunulmaz
        }
        $real_orders = (int)(db_fetch("SELECT COUNT(*) as cnt FROM order_items WHERE book_id = ?" . ($order_ids ? " AND order_id NOT IN (" . implode(',', $order_ids) . ")" : ''), [$b['id']])['cnt'] ?? 0);
        $b['real_orders'] = $real_orders;
        if ($real_orders > 0) {
            $p['books_deactivate'][] = $b;
        } else {
            $p['books_delete'][] = $b;
        }
    }
    $del_book_ids = array_map('intval', array_column($p['books_delete'], 'id'));

    $p['customers'] = [];
    foreach (db_fetch_all("SELECT id, name, email FROM users WHERE role = 'customer' AND email IN (" . $in($seed_emails) . ")", $seed_emails) as $u) {
        $other = (int)(db_fetch("SELECT COUNT(*) as cnt FROM orders WHERE user_id = ?" . ($order_ids ? " AND id NOT IN (" . implode(',', $order_ids) . ")" : ''), [$u['id']])['cnt'] ?? 0);
        if ($other === 0) $p['customers'][] = $u;
    }

    // Yazar / yayınevi: yalnızca silinecek kitaplardan başka kitabı kalmayanlar
    $unused = function ($table, $col, array $names) use ($del_book_ids, $in) {
        $out = [];
        foreach (db_fetch_all("SELECT id, name FROM {$table} WHERE name IN (" . $in($names) . ")", $names) as $r) {
            $left = (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE {$col} = ?" . ($del_book_ids ? " AND id NOT IN (" . implode(',', $del_book_ids) . ")" : ''), [$r['id']])['cnt'] ?? 0);
            if ($left === 0) $out[] = $r;
        }
        return $out;
    };
    $p['authors'] = $unused('authors', 'author_id', $seed_authors);
    $p['publishers'] = $unused('publishers', 'publisher_id', $seed_publishers);

    $p['collections'] = [];
    foreach (db_fetch_all("SELECT id, title, slug FROM collections WHERE slug IN (" . $in($seed_collections) . ")", $seed_collections) as $c) {
        $left = (int)(db_fetch("SELECT COUNT(*) as cnt FROM book_collection WHERE collection_id = ?" . ($del_book_ids ? " AND book_id NOT IN (" . implode(',', $del_book_ids) . ")" : ''), [$c['id']])['cnt'] ?? 0);
        if ($left === 0) $p['collections'][] = $c;
    }
    $p['logs'] = db_fetch_all("SELECT id, action, details FROM action_logs WHERE action = 'Sistem Başlatıldı' AND details LIKE ?", ['%örneklendi%']);
    return $p;
};

$p = $plan();
$total = count($p['orders']) + count($p['books_delete']) + count($p['books_deactivate']) + count($p['customers']) + count($p['authors']) + count($p['publishers']) + count($p['collections']) + count($p['logs']);
$is_sqlite = $GLOBALS['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_cleanup'])) {
    if (trim($_POST['confirm_text'] ?? '') !== 'TEMİZLE' || empty($_POST['confirm_backup'])) {
        set_flash('danger', 'Onay için kutuyu işaretleyip kutuya büyük harflerle TEMİZLE yazın.');
        redirect('index.php?p=Cleanup');
    }
    if ($total === 0) {
        set_flash('info', 'Silinecek örnek kayıt yok.');
        redirect('index.php?p=Cleanup');
    }
    global $pdo;
    // 1) Yedek: SQLite veritabanının tam kopyası (kalıcı diskte, veritabanının yanında)
    $backup = '';
    if ($is_sqlite) {
        $backup = SQLITE_PATH . '.yedek-' . date('Ymd-His') . '.db';
        try {
            $pdo->exec("VACUUM INTO " . $pdo->quote($backup));
        } catch (Throwable $e) {
            if (!@copy(SQLITE_PATH, $backup)) {
                set_flash('danger', 'Yedek alınamadığı için temizlik yapılmadı: ' . $e->getMessage());
                redirect('index.php?p=Cleanup');
            }
        }
    }
    // 2) Silme (tek işlem; hata olursa hiçbir şey silinmez)
    $ids = function ($rows) { return array_map('intval', array_column($rows, 'id')); };
    $del = function ($sql, array $ids) { if ($ids) db_query(str_replace('{ids}', implode(',', $ids), $sql)); };
    try {
        $pdo->beginTransaction();
        $o = $ids($p['orders']);
        $del("DELETE FROM order_items WHERE order_id IN ({ids})", $o);
        $del("DELETE FROM transactions WHERE order_id IN ({ids})", $o);
        $del("DELETE FROM orders WHERE id IN ({ids})", $o);
        $b = $ids($p['books_delete']);
        $del("DELETE FROM book_showcase WHERE book_id IN ({ids})", $b);
        $del("DELETE FROM book_collection WHERE book_id IN ({ids})", $b);
        $del("DELETE FROM analytics_events WHERE book_id IN ({ids})", $b);
        $del("DELETE FROM slug_redirects WHERE entity = 'book' AND target_id IN ({ids})", $b);
        $del("DELETE FROM books WHERE id IN ({ids})", $b);
        $del("UPDATE books SET is_active = 0 WHERE id IN ({ids})", $ids($p['books_deactivate']));
        $del("DELETE FROM users WHERE role = 'customer' AND id IN ({ids})", $ids($p['customers']));
        $del("DELETE FROM authors WHERE id IN ({ids})", $ids($p['authors']));
        $del("DELETE FROM publishers WHERE id IN ({ids})", $ids($p['publishers']));
        $del("DELETE FROM book_collection WHERE collection_id IN ({ids})", $ids($p['collections']));
        $del("DELETE FROM collections WHERE id IN ({ids})", $ids($p['collections']));
        $del("DELETE FROM action_logs WHERE id IN ({ids})", $ids($p['logs']));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('danger', 'Temizlik yapılamadı, hiçbir kayıt silinmedi: ' . $e->getMessage());
        redirect('index.php?p=Cleanup');
    }
    $summary = count($p['orders']) . ' sipariş, ' . count($p['books_delete']) . ' kitap silindi, ' . count($p['books_deactivate']) . ' kitap satıştan kaldırıldı, '
        . count($p['customers']) . ' müşteri, ' . count($p['authors']) . ' yazar, ' . count($p['publishers']) . ' yayınevi, ' . count($p['collections']) . ' koleksiyon silindi.';
    log_action('Örnek Veri Temizlendi', 'Cleanup', null, $summary . ($backup ? ' Yedek: ' . basename($backup) : ''));
    set_flash('success', 'Örnek veriler temizlendi: ' . $summary . ($backup ? ' Yedek dosyası: ' . basename($backup) : ''));
    redirect('index.php?p=Cleanup');
}

$section = function ($title, $rows, $cols, $note = '') {
    if (!$rows) return;
    echo '<h6 class="fw-bold mt-4 mb-2">' . e($title) . ' <span class="badge bg-warning text-dark">' . count($rows) . '</span></h6>';
    if ($note) echo '<div class="text-muted fs-8 mb-2">' . $note . '</div>';
    echo '<div class="table-responsive"><table class="table table-sm fs-7 mb-0"><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $c) echo '<td>' . e(is_callable($c) ? $c($r) : ($r[$c] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
?>

<div class="mb-4">
    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-trash3 text-danger me-2"></i>Örnek Veri Temizliği</h5>
    <p class="text-muted fs-7 mb-0">Site kurulurken veritabanına eklenen <b>örnek</b> sipariş, müşteri, kitap, yazar, yayınevi ve koleksiyonları bulur. Eşleştirme sıkıdır: sipariş numarası, ISBN ile kitap adının birlikte tutması ve <code>@example.com</code> e-postaları. Adını değiştirdiğiniz ya da sahafa taşınmış kitaplara, kategorilere, vitrinlere, alt dükkânlara ve site ayarlarına <b>dokunulmaz</b>.</p>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <?php if ($total === 0): ?>
            <div class="text-center py-4"><i class="bi bi-check-circle-fill text-success fs-1 d-block mb-2"></i><b>Örnek veri kalmadı.</b><div class="text-muted fs-7">Sitedeki tüm kayıtlar sizin eklediğiniz kayıtlar.</div></div>
        <?php else: ?>
            <div class="alert alert-warning fs-7 mb-0"><b><?= $total ?> örnek kayıt bulundu.</b> Aşağıdaki listeyi kontrol edin; yalnızca bunlar silinecek.</div>
            <?php
            $section('Örnek siparişler (kalemleri ve ödeme kayıtlarıyla)', $p['orders'], ['order_number', 'customer', function ($r) { return format_price($r['total_amount']); }, 'status', 'created_at']);
            $section('Silinecek örnek kitaplar', $p['books_delete'], [function ($r) { return '#' . $r['id']; }, 'title', 'isbn']);
            $section('Satıştan kaldırılacak örnek kitaplar', $p['books_deactivate'], [function ($r) { return '#' . $r['id']; }, 'title', function ($r) { return $r['real_orders'] . ' gerçek siparişte geçiyor'; }], 'Gerçek bir siparişte geçtikleri için silinmez (faturada kitap adı kaybolmasın); pasife alınır.');
            $section('Örnek müşteriler', $p['customers'], ['name', 'email']);
            $section('Kullanılmayacak örnek yazarlar', $p['authors'], ['name'], 'Başka kitabı olan yazarlar silinmez.');
            $section('Kullanılmayacak örnek yayınevleri', $p['publishers'], ['name'], 'Başka kitabı olan yayınevleri silinmez.');
            $section('Boş kalacak örnek koleksiyonlar', $p['collections'], ['title']);
            $section('Kurulum kaydı (işlem logu)', $p['logs'], ['action', 'details']);
            ?>
            <form method="POST" action="" class="border-top mt-4 pt-4" onsubmit="return confirm('Listelenen örnek kayıtlar silinecek. Devam edilsin mi?');">
                <input type="hidden" name="run_cleanup" value="1">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirm_backup" value="1" id="cb" required>
                    <label class="form-check-label fs-7" for="cb">
                        Listeyi kontrol ettim. <?= $is_sqlite ? 'Silmeden önce veritabanının tam yedeği alınacak (<code>' . e(basename(SQLITE_PATH)) . '.yedek-…db</code>).' : '<b class="text-danger">MySQL kullanıldığı için otomatik yedek alınamıyor; önce hosting panelinden yedek alın.</b>' ?>
                    </label>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="text" name="confirm_text" class="form-control" style="max-width: 220px;" placeholder="Onay için TEMİZLE yazın" autocomplete="off" required>
                    <button class="btn btn-danger fw-bold"><i class="bi bi-trash3 me-1"></i> Örnek Verileri Temizle</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
