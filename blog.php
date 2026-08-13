<?php
/**
 * STOREFRONT: Blog Page (blog.php)
 */
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edebiyat & Kitap Önerileri Blogu | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
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
            <h2 class="fw-extrabold text-dark">Kitap Önerileri ve Edebi Yazılar</h2>
            <p class="text-muted">Okuma listeleri, yazar biyografileri ve kitap tahlilleri.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <span class="badge bg-danger bg-opacity-10 text-danger mb-2">Tarih & Atatürk</span>
                        <h5 class="fw-bold text-dark">Mustafa Kemal Atatürk’ün Okuma Tutkusu ve Nutuk</h5>
                        <p class="text-muted fs-7">Gazi’nin fikir dünyasını şekillendiren, Çalıkuşu’ndan Beyaz Zambaklar Ülkesinde’ye tarihi klasikler...</p>
                        <span class="text-muted fs-8">10 Ağustos 2026 • 5 dk okuma</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <span class="badge bg-dark text-white mb-2">Yazar İncelemesi</span>
                        <h5 class="fw-bold text-dark">Stefan Zweig Ve Satranç Romanındaki Derin Sembolizm</h5>
                        <p class="text-muted fs-7">Zweig’ın son eseri olan Satranç novellasındaki Dr. B. karakterinin psikolojik hapsedilmesi...</p>
                        <span class="text-muted fs-8">08 Ağustos 2026 • 4 dk okuma</span>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <span class="badge bg-danger text-white mb-2">Orta Dünya</span>
                        <h5 class="fw-bold text-dark">J.R.R. Tolkien ve Yüzüklerin Efendisi Mimarisi</h5>
                        <p class="text-muted fs-7">Orta Dünya evreninin filoloji ve mitoloji ile harmanlanarak yaratılması üzerine detaylı inceleme...</p>
                        <span class="text-muted fs-8">05 Ağustos 2026 • 7 dk okuma</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
