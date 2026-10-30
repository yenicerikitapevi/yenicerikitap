<?php
/**
 * 404 SAYFASI — gerçek 404 durum kodu, arama kutusu ve popüler kategoriler.
 * Apache ErrorDocument ile ya da bulunamayan kitap/kategori için doğrudan require ile açılır.
 */
require_once __DIR__ . '/config.php';

http_response_code(404);
header('Cache-Control: no-store');
$popular = array_values(array_filter(db_fetch_all("SELECT c.id, c.name, c.slug, c.icon, COUNT(b.id) as cnt FROM categories c JOIN books b ON b.category_id = c.id AND b.is_active = 1 GROUP BY c.id, c.name, c.slug, c.icon ORDER BY cnt DESC"), function ($c) { return !is_numeric_category_name($c['name']); }));
$popular = array_slice($popular, 0, 8);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head(['title' => 'Sayfa Bulunamadı | ' . SEO_BRAND, 'description' => seo_description('Aradığınız sayfa bulunamadı. Kitap adı, yazar veya yayınevi ile arama yapabilir ya da popüler kategorilerimize göz atabilirsiniz.'), 'robots' => 'noindex,follow']) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body>
    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="/" class="navbar-brand text-dark d-flex align-items-center">
                <img src="/assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" width="164" height="48" style="height: 48px; width: auto;">
            </a>
            <a href="/" class="btn btn-outline-dark rounded-pill px-3 fw-bold"><i class="bi bi-house-door me-1"></i> Ana Sayfa</a>
        </div>
    </header>
    <main class="container py-5" style="max-width: 760px;">
        <div class="text-center mb-4">
            <div class="display-1 fw-extrabold text-danger">404</div>
            <h1 class="h3 fw-bold">Aradığınız sayfa bulunamadı</h1>
            <p class="text-muted">Sayfa taşınmış ya da kaldırılmış olabilir. Kitabı aşağıdan arayabilirsiniz.</p>
        </div>
        <form action="/category.php" method="GET" class="d-flex gap-2 mb-5" role="search">
            <input type="search" name="q" class="form-control form-control-lg" placeholder="Kitap adı, yazar veya yayınevi..." aria-label="Kitap ara" required>
            <button class="btn btn-danger btn-lg px-4" type="submit"><i class="bi bi-search"></i><span class="visually-hidden">Ara</span></button>
        </form>
        <?php if ($popular): ?>
            <h2 class="h5 fw-bold mb-3">Popüler Kategoriler</h2>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php foreach ($popular as $c): ?>
                    <a href="/<?= e(category_path($c)) ?>" class="btn btn-outline-dark rounded-pill"><i class="bi <?= e($c['icon'] ?: 'bi-book') ?> me-1 text-danger"></i><?= e($c['name']) ?> <span class="text-muted">(<?= (int)$c['cnt'] ?>)</span></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-3 fs-7">
            <a href="/category.php" class="text-danger">Tüm kitaplar</a>
            <a href="/magaza.php" class="text-danger">Sahaflar</a>
            <a href="/contact.php" class="text-danger">İletişim</a>
        </div>
    </main>
</body>
</html>
