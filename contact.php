<?php
/**
 * STOREFRONT: Contact Page (contact.php)
 */
require_once __DIR__ . '/config.php';

$sent = false;
$error = '';
$form = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contact'])) {
    foreach ($form as $key => $_) {
        $form[$key] = sanitize($_POST[$key] ?? '');
    }

    if (!empty($_POST['website'])) {
        // Gizli alan doldurulmuşsa spam botudur; sessizce başarılı gibi davran
        $sent = true;
    } elseif ($form['name'] === '' || $form['subject'] === '' || $form['message'] === '') {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Lütfen geçerli bir e-posta adresi girin.';
    } elseif (mb_strlen($form['message']) > 5000) {
        $error = 'Mesajınız çok uzun (en fazla 5000 karakter).';
    } else {
        db_query("INSERT INTO contact_messages (name, email, subject, message, ip_address) VALUES (?, ?, ?, ?, ?)",
            [$form['name'], $form['email'], $form['subject'], $form['message'], $_SERVER['REMOTE_ADDR'] ?? '']);
        $sent = true;
        $form = array_fill_keys(array_keys($form), '');
    }
}

$site_phone    = setting('site_phone', '0312 245 00 00');
$site_whatsapp = setting('site_whatsapp', '0555 000 00 00');
$site_email    = setting('site_email', 'iletisim@yenicerikitabevi.com');
$site_address  = setting('site_address', 'İstasyon Mah. Hikmet Özer Cad. No:48/A Etimesgut / Ankara');
track_view('contact');
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
                            <p class="text-secondary fs-7 mb-0"><?= e($site_address) ?></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3 mb-3">
                        <i class="bi bi-telephone-fill fs-3 text-danger"></i>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">Telefon Numarası:</h6>
                            <p class="text-secondary fs-7 mb-0"><?= e($site_phone) ?> / <?= e($site_whatsapp) ?></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-start gap-3">
                        <i class="bi bi-envelope-fill fs-3 text-danger"></i>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">E-Posta:</h6>
                            <p class="text-secondary fs-7 mb-0"><?= e($site_email) ?></p>
                        </div>
                    </div>
                </div>

                <a href="<?= e(whatsapp_link($site_whatsapp)) ?>" target="_blank" rel="noopener" class="btn btn-danger btn-lg w-100 rounded-3 font-bold py-3">
                    <i class="bi bi-whatsapp me-2"></i> WhatsApp Hattından Bize Yazın
                </a>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <h5 class="fw-bold text-dark mb-3">Mesaj Gönderin</h5>

                    <?php if ($sent): ?>
                        <div class="alert alert-success">Mesajınız başarıyla iletildi. En kısa sürede sizinle iletişime geçeceğiz.</div>
                    <?php elseif ($error !== ''): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="send_contact" value="1">
                        <!-- Spam botları için gizli alan; gerçek kullanıcılar görmez -->
                        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Adınız Soyadınız</label>
                            <input type="text" name="name" class="form-control" value="<?= e($form['name']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">E-Posta Adresiniz</label>
                            <input type="email" name="email" class="form-control" value="<?= e($form['email']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Konu</label>
                            <input type="text" name="subject" class="form-control" value="<?= e($form['subject']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fs-7 fw-semibold">Mesajınız</label>
                            <textarea name="message" class="form-control" rows="4" maxlength="5000" required><?= e($form['message']) ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-danger font-bold w-100 py-2.5 rounded-3">Mesajı Gönder</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?= track_script() ?>
</body>
</html>
