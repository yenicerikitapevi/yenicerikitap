<?php
/**
 * STOREFRONT: Contact Page (contact.php)
 */
require_once __DIR__ . '/config.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İletişim & Şube Konumu | Yeniçeri Kitabevi</title>
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
        <div class="row g-5">
            <div class="col-lg-6">
                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 font-bold mb-2">Bize Ulaşın</span>
                <h2 class="fw-extrabold text-dark mb-3">İletişim & Mağaza Adresimiz</h2>
                <p class="text-muted mb-4">Yeniçeri Kitabevi Ankara Etimesgut şubemiz ve online mağazamızla haftanın 7 günü hizmetinizdedir.</p>

                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <i class="bi bi-geo-alt-fill fs-3 text-danger"></i>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">Mağaza Adresi:</h6>
                            <p class="text-secondary fs-7 mb-0">İstasyon Mah. Hikmet Özer Cad. No:48/A<br>Etimesgut / Ankara</p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3 mb-3">
                        <i class="bi bi-telephone-fill fs-3 text-danger"></i>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">Telefon Numarası:</h6>
                            <p class="text-secondary fs-7 mb-0">0312 245 00 00 / 0555 000 00 00</p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3">
                        <i class="bi bi-envelope-fill fs-3 text-danger"></i>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">E-Posta:</h6>
                            <p class="text-secondary fs-7 mb-0">iletisim@yenicerikitabevi.com</p>
                        </div>
                    </div>
                </div>

                <a href="https://wa.me/905550000000" target="_blank" class="btn btn-danger btn-lg w-100 rounded-3 font-bold py-3">
                    <i class="bi bi-whatsapp me-2"></i> WhatsApp Hattından Bize Yazın
                </a>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <h5 class="fw-bold text-dark mb-3">Mesaj Gönderin</h5>

                    <?php if ($sent): ?>
                        <div class="alert alert-success">Mesajınız başarıyla iletildi. En kısa sürede sizinle iletişime geçeceğiz.</div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="send_contact" value="1">
                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Adınız Soyadınız</label>
                            <input type="text" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">E-Posta Adresiniz</label>
                            <input type="email" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Konu</label>
                            <input type="text" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Mesajınız</label>
                            <textarea class="form-control" rows="4" required></textarea>
                        </div>

                        <button type="submit" class="btn btn-danger font-bold w-100 py-2.5 rounded-3">Mesajı Gönder</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
