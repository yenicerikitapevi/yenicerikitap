<?php
/**
 * ADMIN MODULE: Settings (p=Settings)
 */
if (!defined('SITE_URL')) exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings_keys = [
        'site_title', 'site_description', 'site_email', 'site_phone', 
        'site_whatsapp', 'site_address', 'shipping_free_limit', 
        'flat_shipping_cost', 'bank_name', 'bank_account_name', 'bank_iban',
        'company_legal_name', 'company_tax_office', 'company_tax_number', 'company_mersis',
        'payment_merchant_id', 'payment_api_key', 'payment_merchant_salt', 'paytr_test_mode',
        'nk_store_code', 'nk_api_key'
    ];
    // Gizli anahtarlar sayfada gösterilmez; alan boş bırakılırsa kayıtlı değer korunur
    $secret_keys = ['payment_api_key', 'payment_merchant_salt'];

    foreach ($settings_keys as $key) {
        if (isset($_POST[$key])) {
            $val = sanitize($_POST[$key]);
            if (in_array($key, $secret_keys, true) && $val === '') {
                continue;
            }
            if ($key === 'paytr_test_mode') {
                $val = ($val === '0') ? '0' : '1';
            }
            if (in_array($key, ['shipping_free_limit', 'flat_shipping_cost'], true)) {
                $val = number_format(max(0, (float)str_replace(',', '.', $val)), 2, '.', '');
            }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email'])) {
    $new_email    = trim(sanitize($_POST['new_email'] ?? ''));
    $current_pass = $_POST['current_password'] ?? '';

    $admin_id = $_SESSION['admin_user']['id'];
    $admin = db_fetch("SELECT email, password_hash FROM users WHERE id = ?", [$admin_id]);
    $taken = db_fetch("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?", [$new_email, $admin_id]);

    if (!password_verify($current_pass, $admin['password_hash'])) {
        set_flash('danger', 'Mevcut şifreniz hatalı!');
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        set_flash('danger', 'Lütfen geçerli bir e-posta adresi girin.');
    } elseif ($taken) {
        set_flash('danger', 'Bu e-posta adresi başka bir hesapta (ör. bir müşteride) kayıtlı.');
    } else {
        db_query("UPDATE users SET email = ? WHERE id = ?", [$new_email, $admin_id]);
        $_SESSION['admin_user']['email'] = $new_email;
        log_action('Giriş E-postası Değiştirildi', 'Settings', $admin_id, "{$admin['email']} → {$new_email}");
        set_flash('success', 'Giriş e-postanız güncellendi. Bir sonraki girişte ' . $new_email . ' adresini kullanın.');
    }
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
    } elseif (strlen($new_pass) < 10) {
        set_flash('danger', 'Yeni şifre en az 10 karakter olmalıdır!');
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        db_query("UPDATE users SET password_hash = ? WHERE id = ?", [$new_hash, $admin_id]);
        log_action('Şifre Değiştirildi', 'Settings', $admin_id, 'Admin hesabının şifresi değiştirildi.');
        set_flash('success', 'Şifreniz güncellendi.');
    }
    redirect('index.php?p=Settings');
}

// İlk kurulumdan kalan örnek sanal POS değerleri gerçek bilgi sanılmasın
db_query("UPDATE settings SET setting_value = '' WHERE (setting_key = 'payment_merchant_id' AND setting_value = 'MERCHANT_YENICERI_889') OR (setting_key = 'payment_api_key' AND setting_value = 'test_api_key_yeniceri_sec_99812')");

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

                        <div class="col-md-12 pt-2">
                            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-building text-danger me-1"></i> Şirket / Satıcı Bilgileri</h6>
                            <small class="text-muted">Mesafeli Satış Sözleşmesi, Ön Bilgilendirme Formu ve KVKK metninde satıcı olarak gösterilir.</small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold fs-7">Ticari Unvan (veya şahıs şirketi sahibinin adı soyadı)</label>
                            <input type="text" name="company_legal_name" class="form-control" value="<?= e($s['company_legal_name'] ?? '') ?>" placeholder="Örn: Yeniçeri Kitap Kırtasiye Ltd. Şti.">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7">Vergi Dairesi</label>
                            <input type="text" name="company_tax_office" class="form-control" value="<?= e($s['company_tax_office'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7">Vergi No / TC Kimlik No</label>
                            <input type="text" name="company_tax_number" class="form-control" value="<?= e($s['company_tax_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7">MERSİS No (varsa)</label>
                            <input type="text" name="company_mersis" class="form-control" value="<?= e($s['company_mersis'] ?? '') ?>">
                        </div>

                        <div class="col-md-12 pt-2">
                            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-bank text-danger me-1"></i> Havale / EFT Bilgileri</h6>
                            <small class="text-muted">Sipariş veren müşteriye bu bilgiler gösterilir.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Banka Adı</label>
                            <input type="text" name="bank_name" class="form-control" value="<?= e($s['bank_name'] ?? '') ?>" placeholder="Örn: Ziraat Bankası">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Hesap Sahibi (Alıcı Adı)</label>
                            <input type="text" name="bank_account_name" class="form-control" value="<?= e($s['bank_account_name'] ?? '') ?>">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold fs-7">IBAN</label>
                            <input type="text" name="bank_iban" class="form-control font-monospace" value="<?= e($s['bank_iban'] ?? '') ?>" placeholder="TR00 0000 0000 0000 0000 0000 00">
                        </div>

                        <div class="col-md-12 pt-2">
                            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-credit-card text-danger me-1"></i> PayTR Kartla Ödeme</h6>
                            <small class="text-muted">Bilgiler PayTR Mağaza Paneli &gt; Destek &amp; Kurulum &gt; Entegrasyon Bilgileri sayfasındadır. Üçü de girildiğinde ödeme sayfasında kartla ödeme seçeneği açılır. PayTR panelindeki Bildirim URL: <code><?= e(SITE_URL) ?>/paytr_callback.php</code></small>
                            <div class="mt-2">
                                <?php if (paytr_enabled()): ?>
                                    <span class="badge bg-success">Kartla ödeme açık<?= paytr_config()['test_mode'] === '1' ? ' (Test Modu)' : '' ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Kartla ödeme kapalı</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Mağaza No (merchant_id)</label>
                            <input type="text" name="payment_merchant_id" class="form-control font-monospace" value="<?= e($s['payment_merchant_id'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Ödeme Modu</label>
                            <select name="paytr_test_mode" class="form-select">
                                <option value="1" <?= ($s['paytr_test_mode'] ?? '1') !== '0' ? 'selected' : '' ?>>Test Modu (gerçek para çekilmez)</option>
                                <option value="0" <?= ($s['paytr_test_mode'] ?? '1') === '0' ? 'selected' : '' ?>>Canlı Mod</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Mağaza Parola (merchant_key)</label>
                            <input type="password" name="payment_api_key" class="form-control font-monospace" autocomplete="new-password" placeholder="<?= !empty($s['payment_api_key']) ? 'Kayıtlı - değiştirmek için yazın' : '' ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7">Mağaza Gizli Anahtar (merchant_salt)</label>
                            <input type="password" name="payment_merchant_salt" class="form-control font-monospace" autocomplete="new-password" placeholder="<?= !empty($s['payment_merchant_salt']) ? 'Kayıtlı - değiştirmek için yazın' : '' ?>">
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
                        <input type="password" name="new_password" class="form-control" required minlength="10">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Yeni Şifre (Tekrar)</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="10">
                    </div>

                    <button type="submit" class="btn btn-dark w-100 font-semibold py-2">
                        <i class="bi bi-key-fill me-1"></i> Şifreyi Güncelle
                    </button>
                </form>
            </div>
        </div>

        <?php $admin_email = db_fetch("SELECT email FROM users WHERE id = ?", [$_SESSION['admin_user']['id']])['email'] ?? ''; ?>
        <div class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-header bg-white border-bottom p-4">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-envelope-at text-danger me-2"></i> Giriş E-postası</h5>
            </div>
            <div class="card-body p-4">
                <?php if ($admin_email === 'admin@yeniceri.com'): ?>
                    <div class="alert alert-warning fs-8 py-2">Hâlâ kurulumdaki varsayılan adresi kullanıyorsunuz. Bu adres herkese açık kaynak kodunda yazdığı için kendi e-postanızla değiştirmeniz önerilir.</div>
                <?php endif; ?>
                <form method="POST" action="">
                    <input type="hidden" name="change_email" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Mevcut Giriş E-postası</label>
                        <input type="text" class="form-control bg-light" value="<?= e($admin_email) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Yeni E-posta</label>
                        <input type="email" name="new_email" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7">Mevcut Şifreniz</label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn btn-dark w-100 font-semibold py-2">
                        <i class="bi bi-envelope-check me-1"></i> E-postayı Güncelle
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
