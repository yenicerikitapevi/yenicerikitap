<?php
/**
 * SATICI PANELİ: Alt dükkân yalnızca kendi kitaplarının ve vitrin sayfasının raporunu görür
 */
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['store_user'])) {
    redirect('login.php');
}
$store = db_fetch("SELECT * FROM stores WHERE id = ?", [(int)$_SESSION['store_user']['id']]);
if (!$store || !$store['is_active']) {
    unset($_SESSION['store_user']);
    redirect('login.php');
}

$report_store_id = (int)$store['id'];
$report_base     = 'index.php?';
$book_count      = (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE store_id = ? AND is_active = 1", [$report_store_id])['cnt'] ?? 0);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Satıcı Paneli | <?= e($store['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-light">
    <nav class="bg-dark text-white py-2 mb-4 shadow">
        <div class="container-xl d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <span class="bg-white rounded-3 p-1"><img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 30px;"></span>
                <div>
                    <div class="fw-bold"><i class="bi bi-shop text-danger me-1"></i><?= e($store['name']) ?></div>
                    <small class="text-secondary">Satıcı Paneli • <?= $book_count ?> aktif kitap</small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="../magaza.php?s=<?= urlencode($store['slug']) ?>" target="_blank" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Vitrinim</a>
                <a href="logout.php" class="btn btn-danger btn-sm"><i class="bi bi-power me-1"></i>Çıkış</a>
            </div>
        </div>
    </nav>
    <main class="container-xl pb-5">
        <?php
        $my_campaigns = array_filter(campaigns_active(), function ($c) use ($report_store_id) {
            return in_array($c['scope_type'], ['all', 'store'], true) && campaign_matches($c, ['id' => 0, 'store_id' => $report_store_id, 'category_id' => 0, 'author_id' => 0, 'publisher_id' => 0]);
        });
        ?>
        <?php if ($my_campaigns): ?>
            <div class="alert alert-warning border rounded-4 fs-7 mb-4">
                <div class="fw-bold mb-1"><i class="bi bi-gift-fill me-1"></i> Dükkânınızda geçerli kampanyalar</div>
                <?php foreach ($my_campaigns as $c): ?>
                    <div>• <b><?= e($c['name']) ?></b><?= $c['description'] ? ' — ' . e($c['description']) : '' ?><?= $c['ends_at'] ? ' <span class="text-muted">(' . date('d.m.Y H:i', strtotime($c['ends_at'])) . ' tarihine kadar)</span>' : '' ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h5 class="fw-bold mb-3">Ziyaret ve Satış Raporu</h5>
        <?php include __DIR__ . '/../admin/modules/reports.php'; ?>
    </main>
</body>
</html>
