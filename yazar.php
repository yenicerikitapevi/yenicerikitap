<?php
/**
 * STOREFRONT: Yazar Sayfası (/yazar/{slug}) — biyografi ve yazarın kitapları
 */
require_once __DIR__ . '/config.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$author = $slug !== '' ? db_fetch("SELECT * FROM authors WHERE slug = ?", [$slug]) : null;
if (!$author) {
    $target = $slug !== '' ? slug_redirect_target('author', $slug) : null;
    if (!$target && $slug !== '') {
        $rows = db_fetch_all("SELECT id, slug FROM authors");
        $m = legacy_best_match($rows, legacy_clean_slug($slug), 0.85);
        $target = $m ? (int)$m['id'] : null;
    }
    $old = $target ? db_fetch("SELECT * FROM authors WHERE id = ?", [$target]) : null;
    if ($old) redirect_301(author_path($old));
    require __DIR__ . '/404.php';
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 36;
$total_books = (int)(db_fetch("SELECT COUNT(*) as cnt FROM books WHERE author_id = ? AND is_active = 1", [$author['id']])['cnt'] ?? 0);
$total_pages = max(1, (int)ceil($total_books / $per_page));
$page = min($page, $total_pages);
$books = db_fetch_all("SELECT b.*, a.name as author_name, p.name as publisher_name, s.name as store_name, s.slug as store_slug
                       FROM books b
                       LEFT JOIN authors a ON b.author_id = a.id
                       LEFT JOIN publishers p ON b.publisher_id = p.id
                       LEFT JOIN stores s ON b.store_id = s.id
                       WHERE b.author_id = ? AND b.is_active = 1
                       ORDER BY b.id DESC LIMIT {$per_page} OFFSET " . (($page - 1) * $per_page), [$author['id']]);

$bio = trim((string)$author['bio']);
$desc = trim((string)$author['meta_description']) !== '' ? $author['meta_description']
    : ($bio !== '' ? $author['name'] . ': ' . $bio : $author['name'] . ' kitapları Yeniçeri Kitabevi\'nde. ' . $total_books . ' eser, uygun fiyat ve hızlı kargo.');
$crumbs = [['Ana Sayfa', ''], ['Yazarlar', null], [$author['name'], null]];
$person = ['@context' => 'https://schema.org', '@type' => 'Person', 'name' => $author['name'], 'url' => abs_url(author_path($author))];
if ($bio !== '') $person['description'] = mb_substr($bio, 0, 500, 'UTF-8');
$head = [
    'title' => seo_title($author['name'] . ' Kitapları' . ($page > 1 ? ' - Sayfa ' . $page : '')),
    'description' => seo_description($desc),
    'canonical' => author_path($author) . ($page > 1 ? '?page=' . $page : ''),
    'type' => 'profile',
    'jsonld' => [$person, jsonld_breadcrumb([['Ana Sayfa', ''], [$author['name'], author_path($author)]])],
];
if ($books) {
    $head['image'] = cover_url($books[0]['cover_image'] ?? '');
}
if ($page > 1) $head['prev'] = author_path($author) . ($page > 2 ? '?page=' . ($page - 1) : '');
if ($page < $total_pages) $head['next'] = author_path($author) . '?page=' . ($page + 1);
track_view('author');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head($head) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body>
    <div class="top-bar py-2 border-bottom border-dark">
        <div class="container d-flex align-items-center justify-content-between">
            <div><i class="bi bi-geo-alt-fill text-danger me-1"></i> Etimesgut / Ankara</div>
            <div><a href="/" class="text-white text-decoration-none"><i class="bi bi-house-door me-1"></i> Anasayfa</a></div>
        </div>
    </div>

    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="/" class="navbar-brand text-dark d-flex align-items-center">
                <img src="/assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" width="164" height="48" style="height: 48px; width: auto; max-width: 220px; object-fit: contain;">
            </a>
            <button class="btn btn-outline-dark rounded-pill px-3 py-2 fw-bold" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer">
                <i class="bi bi-bag-handle-fill text-danger me-1"></i> Sepetim <span class="badge bg-danger rounded-pill cart-count-badge">0</span>
            </button>
        </div>
    </header>

    <div class="container py-5">
        <div class="mb-3"><?= breadcrumb_html($crumbs) ?></div>
        <div class="bg-white border rounded-4 p-4 mb-4 shadow-sm">
            <h1 class="h3 fw-extrabold text-dark mb-2"><?= e($author['name']) ?></h1>
            <div class="text-muted fs-7 mb-2"><?= $total_books ?> kitap</div>
            <?php if ($bio !== ''): ?>
                <div class="text-secondary fs-7">
                    <?php foreach (preg_split('/\n\s*\n/', $bio) as $para): ?>
                        <p class="mb-2"><?= nl2br(e(trim($para))) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <h2 class="h5 fw-bold mb-3"><?= e($author['name']) ?> Kitapları</h2>
        <?php if (!$books): ?>
            <div class="text-center py-5 bg-white rounded-4 border text-muted">Bu yazarın şu an satışta kitabı yok.</div>
        <?php else: ?>
            <div class="row g-3 row-cols-2 row-cols-md-4 row-cols-lg-6">
                <?php foreach ($books as $b): ?>
                    <div class="col"><?php include __DIR__ . '/_book_card.php'; ?></div>
                <?php endforeach; ?>
            </div>
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Sayfalama" class="mt-4">
                    <ul class="pagination justify-content-center flex-wrap gap-1">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link rounded-3 <?= $i === $page ? 'bg-danger border-danger text-white' : 'text-dark' ?>" href="<?= e(author_path($author) . ($i > 1 ? '?page=' . $i : '')) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="offcanvas offcanvas-end rounded-start-4" tabindex="-1" id="cartDrawer">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title fw-bold"><i class="bi bi-bag-fill text-danger me-2"></i> Alışveriş Sepetim</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body p-4 d-flex flex-column">
            <div id="cartDrawerList" class="flex-grow-1"></div>
            <div class="border-top pt-3 mt-auto">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="fw-bold text-dark">Toplam Tutar:</span>
                    <span class="fs-4 fw-extrabold text-danger" id="cartDrawerTotal">₺0,00</span>
                </div>
                <a href="/checkout.php" class="btn btn-danger w-100 py-2.5 fw-bold rounded-3 shadow">Sepeti Onayla ve Öde <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?= asset_v('assets/js/app.js') ?>" defer></script>
    <?= track_script() ?>
</body>
</html>
