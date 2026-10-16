<?php
/**
 * ADMIN MODULE: FrontPage (p=FrontPage)
 */
if (!defined('SITE_URL')) exit;

$total_books = db_fetch("SELECT COUNT(*) as cnt FROM books")['cnt'] ?? 0;
$total_users = db_fetch("SELECT COUNT(*) as cnt FROM users WHERE role = 'customer'")['cnt'] ?? 0;
$total_orders = db_fetch("SELECT COUNT(*) as cnt FROM orders")['cnt'] ?? 0;
// Ciroya yalnızca ödemesi alınmış siparişler girer (ödeme bekleyen ve iptal edilenler hariç)
$total_revenue = db_fetch("SELECT SUM(total_amount) as total FROM orders WHERE status NOT IN ('İptal', 'Ödeme Bekliyor')")['total'] ?? 0;

$critical_stock_books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name 
                                      FROM books b 
                                      LEFT JOIN authors a ON b.author_id = a.id 
                                      LEFT JOIN publishers p ON b.publisher_id = p.id 
                                      WHERE b.stock < 10 ORDER BY b.stock ASC");

$latest_orders = db_fetch_all("SELECT o.*, u.name as customer_name 
                               FROM orders o 
                               LEFT JOIN users u ON o.user_id = u.id 
                               ORDER BY o.created_at DESC LIMIT 5");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_stock_update'])) {
    $book_id = (int)$_POST['book_id'];
    $new_stock = (int)$_POST['new_stock'];
    
    db_query("UPDATE books SET stock = ? WHERE id = ?", [$new_stock, $book_id]);
    log_action('Kritik Stok Güncelleme', 'Products', $book_id, "Kitap ID: {$book_id} stok miktarı {$new_stock} yapıldı.");
    set_flash('success', 'Stok başarıyla güncellendi!');
    redirect('index.php?p=FrontPage');
}
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div>
                <div class="text-muted fs-7 fw-semibold">Toplam Ciro</div>
                <div class="fs-4 fw-extrabold text-dark mt-1"><?= format_price($total_revenue) ?></div>
            </div>
            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                <i class="bi bi-wallet2"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div>
                <div class="text-muted fs-7 fw-semibold">Toplam Sipariş</div>
                <div class="fs-4 fw-extrabold text-dark mt-1"><?= $total_orders ?></div>
            </div>
            <div class="stat-icon bg-dark text-white">
                <i class="bi bi-bag-check-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div>
                <div class="text-muted fs-7 fw-semibold">Aktif Müşteri</div>
                <div class="fs-4 fw-extrabold text-dark mt-1"><?= $total_users ?></div>
            </div>
            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                <i class="bi bi-people-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div>
                <div class="text-muted fs-7 fw-semibold">Katalogdaki Kitap</div>
                <div class="fs-4 fw-extrabold text-dark mt-1"><?= $total_books ?></div>
            </div>
            <div class="stat-icon bg-dark text-white">
                <i class="bi bi-book-half"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex align-items-center justify-content-between">
                <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill text-danger"></i> Kritik Stoktaki Kitaplar (Stok < 10)
                </h5>
                <span class="badge bg-danger text-white rounded-pill px-3 py-2"><?= count($critical_stock_books) ?> Kitap</span>
            </div>
            <div class="card-body px-4">
                <?php if (empty($critical_stock_books)): ?>
                    <div class="text-center py-4 text-muted fs-7">
                        <i class="bi bi-check-circle fs-3 text-success d-block mb-2"></i>
                        Tüm kitapların stok seviyeleri yeterli durumda!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light fs-7">
                                <tr>
                                    <th>Kitap</th>
                                    <th>Yazar / Yayınevi</th>
                                    <th>Fiyat</th>
                                    <th>Mevcut Stok</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($critical_stock_books as $book): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark fs-7"><?= e($book['title']) ?></div>
                                            <small class="text-muted fs-8">ISBN: <?= e($book['isbn']) ?></small>
                                        </td>
                                        <td>
                                            <div class="fs-7"><?= e($book['author_name']) ?></div>
                                            <small class="text-secondary fs-8"><?= e($book['publisher_name']) ?></small>
                                        </td>
                                        <td class="fw-bold text-danger fs-7"><?= format_price($book['price']) ?></td>
                                        <td>
                                            <span class="badge bg-danger text-white rounded-pill px-2 py-1">
                                                <?= $book['stock'] ?> Adet
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline-flex gap-1" style="max-width: 140px;">
                                                <input type="hidden" name="quick_stock_update" value="1">
                                                <input type="hidden" name="book_id" value="<?= $book['id'] ?>">
                                                <input type="number" name="new_stock" class="form-control form-control-sm text-center" value="<?= $book['stock'] + 20 ?>" required min="0">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Stok Ekle">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex align-items-center justify-content-between">
                <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-clock-history text-danger"></i> Son Siparişler
                </h5>
                <a href="index.php?p=Orders" class="btn btn-link btn-sm text-danger text-decoration-none fw-bold">Tümünü Gör</a>
            </div>
            <div class="card-body px-4">
                <?php if (empty($latest_orders)): ?>
                    <div class="text-center py-4 text-muted">Henüz sipariş kaydı bulunmuyor.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($latest_orders as $order): ?>
                            <div class="list-group-item px-0 py-3 d-flex align-items-center justify-content-between border-bottom border-light">
                                <div>
                                    <div class="fw-bold text-dark fs-7"><?= e($order['order_number']) ?></div>
                                    <small class="text-muted fs-8"><?= e($order['customer_name']) ?> • <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></small>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-danger fs-7"><?= format_price($order['total_amount']) ?></div>
                                    <?php
                                    $st_badge = [
                                        'Ödeme Bekliyor' => 'bg-info text-dark',
                                        'Hazırlanıyor' => 'bg-warning text-dark',
                                        'Kargolandı' => 'bg-dark text-white',
                                        'Tamamlandı' => 'bg-success text-white',
                                        'İptal' => 'bg-danger text-white'
                                    ];
                                    ?>
                                    <span class="badge <?= $st_badge[$order['status']] ?? 'bg-secondary' ?> fs-8">
                                        <?= e($order['status']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
