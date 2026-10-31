<?php
/**
 * Rapor kartlarına tıklanınca açılan ayrıntı (reports.php içinden include edilir; aynı dönem/dükkân filtresi).
 *  views    → son sayfa görüntülemeleri
 *  sessions → ziyaretler (giriş/çıkış sayfası, sayfa sayısı, süre, kaynak)
 *  cart     → sepete eklenen kitaplar (kaç kez, son ekleme, satın alınan adet)
 *  orders   → siparişler (satıcı müşteri adını görmez)
 */
if (!defined('SITE_URL') || !isset($detail)) exit;

$page_name = function ($r) use ($page_labels) {
    if (!empty($r['book_title'])) return $r['book_title'];
    return $page_labels[$r['page_type']] ?? ($r['page_type'] ?: 'Sayfa');
};
$titles = ['views' => 'Son Sayfa Görüntülemeleri', 'sessions' => 'Ziyaretler', 'cart' => 'Sepete Eklenen Kitaplar', 'orders' => 'Siparişler'];
?>
<div class="card border-0 shadow-sm rounded-4 mb-4 border-start border-danger border-4" id="detay">
    <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0"><i class="bi bi-list-ul text-danger me-1"></i><?= e($titles[$detail]) ?></h6>
        <a href="<?= e($detail_url($detail)) ?>" class="btn btn-sm btn-light border" title="Kapat"><i class="bi bi-x-lg"></i></a>
    </div>
    <div class="card-body p-0 mt-2">
        <div class="table-responsive" style="max-height: 460px; overflow-y: auto;">
            <table class="table table-sm table-hover align-middle mb-0 fs-7">
<?php if ($detail === 'views'):
    $rows = db_fetch_all("SELECT e.created_at, e.page_type, e.referrer_host, e.duration_sec, e.session_hash, b.title as book_title, s.name as store_name
        FROM analytics_events e LEFT JOIN books b ON b.id = e.book_id LEFT JOIN stores s ON s.id = e.store_id
        WHERE {$ev_where} AND e.event_type = 'view' ORDER BY e.id DESC LIMIT 300", $ev_params); ?>
                <thead class="table-light fs-8 sticky-top"><tr><th class="ps-3">Saat</th><th>Sayfa</th><th>Kaynak</th><th class="text-end">Süre</th><th class="pe-3">Ziyaretçi</th></tr></thead>
                <tbody>
                    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">Bu dönemde kayıt yok.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr><td class="ps-3 text-nowrap"><?= e($tr_time($r['created_at'])) ?></td><td><?= e($page_name($r)) ?><?= !$is_seller && $r['store_name'] && $r['page_type'] === 'store' ? ' <span class="text-muted">(' . e($r['store_name']) . ')</span>' : '' ?></td>
                        <td class="fs-8"><?= e($r['referrer_host'] ?: 'Doğrudan / site içi') ?></td><td class="text-end"><?= $fmt_sec($r['duration_sec']) ?></td><td class="pe-3 font-monospace fs-8 text-muted">#<?= e(substr($r['session_hash'], 0, 6)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
<?php elseif ($detail === 'sessions'):
    $rows = db_fetch_all("SELECT e.session_hash, MIN(e.created_at) as started, MAX(e.created_at) as ended, COUNT(*) as pages, SUM(e.duration_sec) as secs,
            MIN(e.id) as first_id, MAX(e.id) as last_id, MAX(e.referrer_host) as ref
        FROM analytics_events e WHERE {$ev_where} AND e.event_type = 'view' GROUP BY e.session_hash ORDER BY started DESC LIMIT 200", $ev_params);
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['first_id']; $ids[] = (int)$r['last_id']; }
    $ev = [];
    if ($ids) {
        foreach (db_fetch_all("SELECT e.id, e.page_type, b.title as book_title FROM analytics_events e LEFT JOIN books b ON b.id = e.book_id WHERE e.id IN (" . implode(',', array_unique($ids)) . ")") as $x) $ev[$x['id']] = $x;
    }
    $carts = array_column(db_fetch_all("SELECT e.session_hash, COUNT(*) as cnt FROM analytics_events e WHERE {$ev_where} AND e.event_type = 'cart_add' GROUP BY e.session_hash", $ev_params), 'cnt', 'session_hash'); ?>
                <thead class="table-light fs-8 sticky-top"><tr><th class="ps-3">Başlangıç</th><th>Giriş sayfası</th><th>Çıkış sayfası</th><th class="text-end">Sayfa</th><th class="text-end">Süre</th><th class="text-end">Sepet</th><th class="pe-3">Kaynak</th></tr></thead>
                <tbody>
                    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Bu dönemde ziyaret yok.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr><td class="ps-3 text-nowrap"><?= e($tr_time($r['started'])) ?><?= $tr_time($r['ended'], 'H:i') !== $tr_time($r['started'], 'H:i') ? ' – ' . e($tr_time($r['ended'], 'H:i')) : '' ?></td>
                        <td><?= e($page_name($ev[$r['first_id']] ?? [])) ?></td><td><?= (int)$r['pages'] > 1 ? e($page_name($ev[$r['last_id']] ?? [])) : '<span class="text-muted">aynı sayfa (hemen çıktı)</span>' ?></td>
                        <td class="text-end"><?= (int)$r['pages'] ?></td><td class="text-end"><?= $fmt_sec($r['secs']) ?></td><td class="text-end"><?= !empty($carts[$r['session_hash']]) ? '<span class="badge bg-warning text-dark">' . (int)$carts[$r['session_hash']] . '</span>' : '-' ?></td>
                        <td class="pe-3 fs-8"><?= e($r['ref'] ?: 'Doğrudan') ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
<?php elseif ($detail === 'cart'):
    $rows = db_fetch_all("SELECT e.book_id, b.title, b.price, b.slug, b.id, a.name as author_name, COUNT(*) as cnt, COUNT(DISTINCT e.visitor_hash) as people, MAX(e.created_at) as last_at
        FROM analytics_events e JOIN books b ON b.id = e.book_id LEFT JOIN authors a ON a.id = b.author_id
        WHERE {$ev_where} AND e.event_type = 'cart_add' GROUP BY e.book_id, b.title, b.price, b.slug, b.id, a.name ORDER BY cnt DESC, last_at DESC LIMIT 200", $ev_params);
    $bought = array_column(db_fetch_all("SELECT oi.book_id, SUM(oi.quantity) as qty FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN books b ON oi.book_id = b.id WHERE {$sale_where} GROUP BY oi.book_id", $sale_params), 'qty', 'book_id'); ?>
                <thead class="table-light fs-8 sticky-top"><tr><th class="ps-3">Kitap</th><th class="text-end">Sepete eklenme</th><th class="text-end">Kişi</th><th class="text-end">Satın alınan</th><th class="text-end">Fiyat</th><th class="pe-3">Son ekleme</th></tr></thead>
                <tbody>
                    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Bu dönemde sepete ekleme yok.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $q = (int)($bought[$r['book_id']] ?? 0); ?>
                        <tr><td class="ps-3"><a href="../<?= e(book_path($r)) ?>" target="_blank" class="text-dark fw-semibold text-decoration-none"><?= e($r['title']) ?></a><div class="text-muted fs-8"><?= e($r['author_name']) ?></div></td>
                        <td class="text-end fw-bold"><?= (int)$r['cnt'] ?></td><td class="text-end"><?= (int)$r['people'] ?></td>
                        <td class="text-end"><?= $q > 0 ? '<span class="badge bg-success">' . $q . '</span>' : '<span class="text-muted" title="Sepete eklendi ama satın alınmadı">0</span>' ?></td>
                        <td class="text-end"><?= format_price($r['price']) ?></td><td class="pe-3 text-nowrap fs-8"><?= e($tr_time($r['last_at'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
<?php else:
    $rows = db_fetch_all("SELECT o.id, o.order_number, o.created_at, o.status, o.payment_method, u.name as customer, SUM(oi.quantity) as qty, SUM(oi.total) as amount, SUM(oi.commission_amount) as commission
        FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN books b ON oi.book_id = b.id LEFT JOIN users u ON u.id = o.user_id
        WHERE {$sale_where} GROUP BY o.id, o.order_number, o.created_at, o.status, o.payment_method, u.name ORDER BY o.created_at DESC LIMIT 200", $sale_params);
    $items = [];
    if ($rows) {
        foreach (db_fetch_all("SELECT oi.order_id, b.title, oi.quantity FROM order_items oi JOIN books b ON b.id = oi.book_id WHERE oi.order_id IN (" . implode(',', array_map('intval', array_column($rows, 'id'))) . ")" . ($scope_store > 0 ? " AND b.store_id = " . (int)$scope_store : '')) as $it) {
            $items[$it['order_id']][] = $it['title'] . ($it['quantity'] > 1 ? ' ×' . (int)$it['quantity'] : '');
        }
    } ?>
                <thead class="table-light fs-8 sticky-top"><tr><th class="ps-3">Tarih</th><th>Sipariş</th><?= $is_seller ? '' : '<th>Müşteri</th>' ?><th>Kitaplar</th><th class="text-end">Adet</th><th class="text-end">Tutar</th><?= $scope_store > 0 ? '<th class="text-end">Komisyon</th>' : '' ?><th class="pe-3">Durum</th></tr></thead>
                <tbody>
                    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Bu dönemde ödemesi alınmış sipariş yok.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr><td class="ps-3 text-nowrap"><?= e($tr_time($r['created_at'])) ?></td>
                        <td class="text-nowrap"><?= $is_seller ? e($r['order_number']) : '<a href="index.php?p=Orders" class="text-danger">' . e($r['order_number']) . '</a>' ?></td>
                        <?= $is_seller ? '' : '<td>' . e($r['customer']) . '</td>' ?>
                        <td class="fs-8"><?= e(implode(', ', $items[$r['id']] ?? [])) ?></td><td class="text-end"><?= (int)$r['qty'] ?></td><td class="text-end fw-bold"><?= format_price($r['amount']) ?></td>
                        <?= $scope_store > 0 ? '<td class="text-end">' . format_price($r['commission']) . '</td>' : '' ?>
                        <td class="pe-3"><span class="badge bg-light text-dark border"><?= e($r['status']) ?></span></td></tr>
                    <?php endforeach; ?>
                </tbody>
<?php endif; ?>
            </table>
        </div>
    </div>
</div>
