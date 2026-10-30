<?php
/**
 * STOREFRONT: Blog Page (blog.php)
 */
require_once __DIR__ . '/config.php';
track_view('blog');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head(['title' => seo_title('Edebiyat & Kitap Önerileri Blogu'), 'description' => seo_description('Kitap önerileri, edebiyat yazıları ve okuma listeleri Yeniçeri Kitabevi blogunda. Yeni çıkanlar, klasikler ve yazar incelemeleri.'), 'canonical' => 'blog.php', 'robots' => 'noindex,follow', 'jsonld' => [jsonld_breadcrumb([['Ana Sayfa', ''], ['Blog', null]])]]) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body>
    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center">
                <img src="assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 48px; max-width: 220px; object-fit: contain;">
            </a>
            <a href="index.php" class="btn btn-outline-dark rounded-pill px-3 font-semibold"><i class="bi bi-arrow-left me-1"></i> Anasayfaya Dön</a>
        </div>
    </header>

    <div class="container py-5">
        <div class="text-center max-w-xl mx-auto mb-5">
            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 font-bold mb-2">Yeniçeri Edebiyat</span>
            <h1 class="h2 fw-extrabold text-dark">Kitap Önerileri ve Edebi Yazılar</h1>
            <p class="text-muted">Okuma listeleri, yazar biyografileri ve kitap tahlilleri.</p>
        </div>

        <div class="text-center bg-white border rounded-4 p-5 shadow-sm mx-auto" style="max-width: 640px;">
            <i class="bi bi-journal-text display-4 text-danger opacity-50 d-block mb-3"></i>
            <h2 class="h5 fw-bold">Yazılarımız çok yakında burada</h2>
            <p class="text-muted fs-7 mb-4">Okuma listeleri, yazar incelemeleri ve kitap önerileri hazırlanıyor. Bu arada kataloğumuza göz atabilirsiniz.</p>
            <a href="category.php" class="btn btn-danger rounded-pill px-4">Kitapları Keşfet</a>
        </div>
    </div>
    <?= track_script() ?>
</body>
</html>
