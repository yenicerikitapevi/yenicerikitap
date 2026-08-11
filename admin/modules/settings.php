<?php
/**
 * ADMIN MODULE: Settings (p=Settings)
 */
if (!defined('SITE_URL')) exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings_keys = [
        'site_title', 'site_description', 'site_email', 'site_phone', 
        'site_whatsapp', 'site_address', 'shipping_free_limit', 
        'flat_shipping_cost', 'payment_merchant_id', 'payment_api_key'
    ];

    foreach ($settings_keys as $key) {
        if (isset($_POST[$key])) {
            $val = sanitize($_POST[$key]);
            $exists = db_fetch("SELECT id FROM settings WHERE setting_key = ?", [$key]);
            if ($exists) {
                db_query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$val, $key]);
            } else {
                db_query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $val]);
            }
        }
    }

    log_action('Site Ayarları Güncellendi', 'Settings', 1, 'Site genel ayarları güncellendi.');
    set_flash('success', 'Site ayarları kaydedildi.');
    redirect('index.php?p=Settings');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass     = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    $admin_id = $_SESSION['admin_user']['id'];
    $admin = db_fetch("SELECT password_hash FROM users WHERE id = ?", [$admin_id]);

    if (!password_verify($current_pass, $admin['password_hash'])) {
        set_flash('danger', 'Mevcut şifreniz hatalı!');
    } elseif ($new_pass !== $confirm_pass) {
        set_flash('danger', 'Yeni şifreler eşleşmiyor!');
    } elseif (strlen($new_pass) < 6) {
        set_flash('danger', 'Yeni şifre en az 6 karakter olmalıdır!');
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        db_query("UPDATE users SET password_hash = ? WHERE id = ?", [$new_hash, $admin_id]);
        log_action('Şifre Değiştirildi', 'Settings', $admin_id, 'Admin hesabının şifresi değiştirildi.');
        set_flash('success', 'Şifreniz güncellendi.');
    }
    redirect('index.php?p=Settings');
}

$setting_rows = db_fetch_all("SELECT * FROM settings");
$s = [];
foreach ($setting_rows as $row) {
    $s[$row['setting_key']] = $row['setting_value'];
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Site Genel Ayarları & SEO</h5>
        <p class="text-muted fs-7 mb-0">İletişim bilgileri (Ankara/Etimesgut), kargo ücretleri ve admin şifresi.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom p-4">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-sliders text-danger me-2"></i> Sistem Konfigürasyonu</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="save_settings" value="1">

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold fs-7">Site Başlığı (Title) *</label>
                            <input type="text" name="site_title" class="form-control" value="<?= e($s['site_title'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold fs-7">SEO Açıklaması</label>
                            <textarea name="site_description" class="form-control" rows="2"><?= e($s['site_description'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7">E-Posta Adresi</label>
                            <input type="email" name="site_email" class="form-control" value="<?= e($s['site_email'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7">Telefon Numarası</label>
                            <input type="text" name="site_phone" class="form-control" value="<?= e($s['site_phone'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7">WhatsApp Hattı</label>
                            <input type="text" name="site_whatsapp" class="form-control" value="<?= e($s['site_whatsapp'] ?? '') ?>">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold fs-7">Fiziksel Mağaza Adresi</label>
                            <input type="text" name="site_address" class="form-control" value="<?= e($s['site_address'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Ücretsiz Kargo Limiti (TL)</label>
                            <input type="number" step="0.01" name="shipping_free_limit" class="form-control" value="<?= e($s['shipping_free_limit'] ?? '350.00') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Sabit Kargo Ücreti (TL)</label>
                            <input type="number" step="0.01" name="flat_shipping_cost" class="form-control" value="<?= e($s['flat_shipping_cost'] ?? '29.90') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Sanal POS Merchant ID</label>
                            <input type="text" name="payment_merchant_id" class="form-control" value="<?= e($s['payment_merchant_id'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Sanal POS API Key</label>
                            <input type="password" name="payment_api_key" class="form-control" value="<?= e($s['payment_api_key'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-danger font-semibold px-4 py-2 shadow">
                            <i class="bi bi-save me-1"></i> Ayarları Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom p-4">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-lock text-danger me-2"></i> Şifre Güncelleme</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="change_password" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Mevcut Şifreniz</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Yeni Şifre</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Yeni Şifre (Tekrar)</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn-dark w-100 font-semibold py-2">
                        <i class="bi bi-key-fill me-1"></i> Şifreyi Güncelle
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
