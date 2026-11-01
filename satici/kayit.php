<?php
/**
 * SAHAF ÜYELİK BAŞVURUSU: Firma bilgileriyle başvuru alınır, dükkân yönetici onayına kadar pasif bekler (stores.approval_status = 'pending')
 */
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['store_user'])) {
    redirect('index.php');
}

/** TC Kimlik No algoritma kontrolü */
function seller_valid_tckn($n) {
    if (!preg_match('/^[1-9]\d{10}$/', $n)) return false;
    $d = array_map('intval', str_split($n));
    $d10 = ((($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7) - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;
    if ($d10 < 0) $d10 += 10;
    return $d10 === $d[9] && (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
}

/** Vergi Kimlik No (10 hane) algoritma kontrolü */
function seller_valid_vkn($n) {
    if (!preg_match('/^\d{10}$/', $n)) return false;
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $t = ((int)$n[$i] + 9 - $i) % 10;
        $p = ($t * (2 ** (9 - $i))) % 9;
        if ($t !== 0 && $p === 0) $p = 9;
        $sum += $p;
    }
    return ((10 - ($sum % 10)) % 10) === (int)$n[9];
}

/** TR IBAN kontrolü (mod 97) */
function seller_valid_iban($iban) {
    if (!preg_match('/^TR\d{24}$/', $iban)) return false;
    $moved = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($moved) as $ch) {
        $numeric .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
    }
    $rem = 0;
    foreach (str_split($numeric, 7) as $chunk) {
        $rem = (int)(($rem . $chunk) % 97);
    }
    return $rem === 1;
}

if (empty($_SESSION['seller_apply_token'])) {
    $_SESSION['seller_apply_token'] = bin2hex(random_bytes(16));
}

$company_types = [
    'sahis'   => 'Şahıs Şirketi',
    'limited' => 'Limited Şirket',
    'anonim'  => 'Anonim Şirket',
    'diger'   => 'Diğer (Kooperatif, Dernek vb.)',
];

$errors = [];
$done = false;
$f = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['name', 'company_type', 'company_title', 'contact_name', 'tax_office', 'tax_number', 'email', 'phone', 'city', 'address', 'iban', 'nadirkitap_input', 'username'];
    foreach ($fields as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if (!hash_equals($_SESSION['seller_apply_token'], (string)($_POST['token'] ?? ''))) {
        $errors[] = 'Oturum süresi doldu. Lütfen formu tekrar gönderin.';
    }
    // Bot tuzağı: görünmeyen alan doldurulduysa sessizce reddet
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $errors[] = 'Başvuru alınamadı.';
    }
    // Aynı oturumdan art arda başvuruyu sınırla
    $recent = array_filter($_SESSION['seller_apply_times'] ?? [], fn($t) => $t > time() - 3600);
    if (count($recent) >= 3) {
        $errors[] = 'Çok fazla başvuru denemesi yapıldı. Lütfen bir saat sonra tekrar deneyin.';
    }

    $f['username']   = strtolower($f['username']);
    $f['tax_number'] = preg_replace('/\D/', '', $f['tax_number']);
    $f['iban']       = strtoupper(preg_replace('/\s+/', '', $f['iban']));
    $f['phone']      = preg_replace('/[^\d+ ()-]/', '', $f['phone']);

    if (mb_strlen($f['name'], 'UTF-8') < 2)            $errors[] = 'Dükkân / sahaf adını yazın.';
    if (!isset($company_types[$f['company_type']]))    $errors[] = 'Firma türünü seçin.';
    if (mb_strlen($f['company_title'], 'UTF-8') < 3)   $errors[] = 'Vergi levhasındaki ticari unvanı yazın.';
    if (mb_strlen($f['contact_name'], 'UTF-8') < 3)    $errors[] = 'Yetkili kişinin adını ve soyadını yazın.';
    if (mb_strlen($f['tax_office'], 'UTF-8') < 2)      $errors[] = 'Vergi dairesini yazın.';
    if ($f['company_type'] === 'sahis') {
        if (!seller_valid_tckn($f['tax_number']) && !seller_valid_vkn($f['tax_number'])) {
            $errors[] = 'Şahıs şirketi için geçerli bir TC Kimlik No (11 hane) veya Vergi No (10 hane) girin.';
        }
    } elseif (!seller_valid_vkn($f['tax_number'])) {
        $errors[] = 'Geçerli bir Vergi Kimlik No (10 hane) girin.';
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Geçerli bir e-posta adresi girin.';
    if (strlen(preg_replace('/\D/', '', $f['phone'])) < 10) $errors[] = 'Geçerli bir telefon numarası girin.';
    if (mb_strlen($f['city'], 'UTF-8') < 2)            $errors[] = 'İl bilgisini yazın.';
    if (mb_strlen($f['address'], 'UTF-8') < 10)        $errors[] = 'Açık adresi yazın.';
    if (!seller_valid_iban($f['iban']))                $errors[] = 'Geçerli bir TR IBAN girin (TR ile başlayan 26 karakter).';
    if (!preg_match('/^[a-z0-9._-]{4,30}$/', $f['username'])) {
        $errors[] = 'Kullanıcı adı 4-30 karakter olmalı; yalnızca küçük harf, rakam, nokta, tire ve alt çizgi içerebilir.';
    } elseif (db_fetch("SELECT id FROM stores WHERE LOWER(username) = ?", [$f['username']])) {
        $errors[] = 'Bu kullanıcı adı alınmış. Başka bir kullanıcı adı seçin.';
    }
    if (strlen($password) < 8)                         $errors[] = 'Şifre en az 8 karakter olmalı.';
    if ($password !== $password2)                      $errors[] = 'Şifreler birbiriyle aynı değil.';
    if (empty($_POST['accept_terms']))                 $errors[] = 'KVKK aydınlatma metnini ve bilgilerin doğruluğu beyanını onaylayın.';

    if (empty($errors) && db_fetch("SELECT id FROM stores WHERE tax_number = ? AND approval_status IN ('pending', 'approved')", [$f['tax_number']])) {
        $errors[] = 'Bu vergi / TC kimlik numarasıyla kayıtlı bir dükkân veya bekleyen bir başvuru zaten var.';
    }

    if (empty($errors)) {
        $nk_code = extract_nadirkitap_code($f['nadirkitap_input']);
        $nk_url  = (stripos($f['nadirkitap_input'], 'http') === 0) ? $f['nadirkitap_input'] : ($nk_code !== '' ? "https://www.nadirkitap.com/kitapara.php?satici={$nk_code}" : '');

        db_query(
            "INSERT INTO stores (name, slug, username, password_hash, plain_hint, nadirkitap_code, nadirkitap_url, city, phone, is_active, approval_status, company_type, company_title, contact_name, email, tax_office, tax_number, address, iban, applied_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, 0, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $f['name'], unique_slug('stores', $f['name']), $f['username'], password_hash($password, PASSWORD_DEFAULT),
                $nk_code ?: null, $nk_url ?: null, $f['city'], $f['phone'],
                $f['company_type'], $f['company_title'], $f['contact_name'], $f['email'], $f['tax_office'], $f['tax_number'], $f['address'], $f['iban'],
                date('Y-m-d H:i:s')
            ]
        );
        $new_id = (int)db_last_id();
        log_action('Sahaf Üyelik Başvurusu', 'Stores', $new_id, "Yeni başvuru: {$f['name']} ({$f['company_title']}), kullanıcı: {$f['username']}");

        $recent[] = time();
        $_SESSION['seller_apply_times'] = array_values($recent);
        $_SESSION['seller_apply_token'] = bin2hex(random_bytes(16));

        // Başvuru sahibine ve site yöneticisine bilgi e-postası (gönderilemezse başvuru yine kayıtlıdır)
        try {
            send_mail(
                $f['email'],
                'Satıcı başvurunuz alındı | ' . APP_NAME,
                mail_layout('Başvurunuz alındı', '<p>Merhaba ' . e($f['contact_name']) . ',</p><p><b>' . e($f['name']) . '</b> için satıcı başvurunuz bize ulaştı. Bilgileriniz incelendikten sonra onay sonucunu bu adrese e-posta ile bildireceğiz.</p><p>Kullanıcı adınız: <b>' . e($f['username']) . '</b></p>'),
                "Merhaba {$f['contact_name']},\n\n{$f['name']} için satıcı başvurunuz bize ulaştı. İnceleme sonrası sonucu e-posta ile bildireceğiz.\nKullanıcı adınız: {$f['username']}",
                'seller_apply'
            );
            $admin_email = setting('site_email', '');
            if ($admin_email !== '') {
                send_mail(
                    $admin_email,
                    'Yeni sahaf başvurusu: ' . $f['name'],
                    mail_layout('Yeni sahaf başvurusu', '<p><b>' . e($f['name']) . '</b> (' . e($f['company_title']) . ') satıcı olmak için başvurdu.</p><p>Yetkili: ' . e($f['contact_name']) . ' · ' . e($f['phone']) . ' · ' . e($f['email']) . '</p>' . mail_button(SITE_URL . '/admin/index.php?p=Stores', 'Başvuruyu İncele')),
                    "{$f['name']} ({$f['company_title']}) satıcı olmak için başvurdu.\nYetkili: {$f['contact_name']} / {$f['phone']} / {$f['email']}\nİncele: " . SITE_URL . '/admin/index.php?p=Stores',
                    'seller_apply_admin'
                );
            }
        } catch (Throwable $mail_err) {
            error_log('[Yeniceri] Sahaf başvuru e-postası: ' . $mail_err->getMessage());
        }

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Sahaf Üyelik Başvurusu | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: linear-gradient(135deg, #000000 0%, #16161e 100%); min-height: 100vh; padding: 32px 16px; }
        .apply-card { width: 100%; max-width: 760px; margin: 0 auto; border-radius: 20px; background: #fff; box-shadow: 0 20px 40px rgba(0,0,0,.6); border: 2px solid #e61919; }
        .apply-section { font-size: .78rem; letter-spacing: .06em; text-transform: uppercase; color: #e61919; font-weight: 700; border-bottom: 1px solid #eee; padding-bottom: 6px; margin: 8px 0 4px; }
        .hp-field { position: absolute; left: -9999px; }
    </style>
</head>
<body>
    <div class="apply-card p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 44px; object-fit: contain;" class="mb-3">
            <h5 class="fw-bold text-dark mb-1">Sahaf Üyelik Başvurusu</h5>
            <p class="text-muted fs-7 mb-0">Kitaplarınızı Yeniçeri Kitabevi'nde kendi dükkânınızla satın</p>
        </div>

        <?php if ($done): ?>
            <div class="text-center py-3">
                <i class="bi bi-hourglass-split text-danger" style="font-size: 3rem;"></i>
                <h5 class="fw-bold mt-3">Başvurunuz alındı</h5>
                <p class="text-muted fs-7 mb-4">Firma bilgileriniz incelendikten sonra başvurunuz onaylanacak ve sonucu <b><?= e($f['email']) ?></b> adresine e-posta ile bildireceğiz. Onaydan sonra kullanıcı adınız ve şifrenizle giriş yapabilirsiniz.</p>
                <a href="login.php" class="btn btn-danger px-4 fw-bold rounded-3">Giriş Sayfasına Dön</a>
            </div>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger fs-7 py-2">
                    <ul class="mb-0 ps-3"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <input type="hidden" name="token" value="<?= e($_SESSION['seller_apply_token']) ?>">
                <div class="hp-field" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                <div class="row g-3">
                    <div class="col-12"><div class="apply-section">Dükkân</div></div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">Dükkân / Sahaf Adı *</label>
                        <input type="text" name="name" class="form-control" maxlength="120" value="<?= e($f['name'] ?? '') ?>" placeholder="Sitede görünecek ad" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">NadirKitap Mağaza Bağlantısı veya Kodu</label>
                        <input type="text" name="nadirkitap_input" class="form-control" maxlength="255" value="<?= e($f['nadirkitap_input'] ?? '') ?>" placeholder="Varsa, örn: 348635">
                    </div>

                    <div class="col-12"><div class="apply-section">Firma Bilgileri</div></div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7 text-secondary">Firma Türü *</label>
                        <select name="company_type" class="form-select" required>
                            <option value="">Seçin</option>
                            <?php foreach ($company_types as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= ($f['company_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold fs-7 text-secondary">Ticari Unvan *</label>
                        <input type="text" name="company_title" class="form-control" maxlength="255" value="<?= e($f['company_title'] ?? '') ?>" placeholder="Vergi levhasında yazdığı gibi" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">Vergi Dairesi *</label>
                        <input type="text" name="tax_office" class="form-control" maxlength="120" value="<?= e($f['tax_office'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">Vergi No / TC Kimlik No *</label>
                        <input type="text" name="tax_number" class="form-control" inputmode="numeric" maxlength="11" value="<?= e($f['tax_number'] ?? '') ?>" required>
                        <small class="text-muted fs-8">Şirketler 10 haneli vergi no; şahıs şirketleri TC kimlik no da yazabilir.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold fs-7 text-secondary">IBAN (Hakediş ödemeleri için) *</label>
                        <input type="text" name="iban" class="form-control font-monospace" maxlength="34" value="<?= e($f['iban'] ?? '') ?>" placeholder="TR00 0000 0000 0000 0000 0000 00" required>
                        <small class="text-muted fs-8">Hesap, ticari unvan veya yetkili adına olmalıdır.</small>
                    </div>

                    <div class="col-12"><div class="apply-section">İletişim</div></div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">Yetkili Ad Soyad *</label>
                        <input type="text" name="contact_name" class="form-control" maxlength="150" value="<?= e($f['contact_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">Telefon *</label>
                        <input type="tel" name="phone" class="form-control" maxlength="30" value="<?= e($f['phone'] ?? '') ?>" placeholder="0555 000 00 00" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">E-posta *</label>
                        <input type="email" name="email" class="form-control" maxlength="190" value="<?= e($f['email'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold fs-7 text-secondary">İl *</label>
                        <input type="text" name="city" class="form-control" maxlength="100" value="<?= e($f['city'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold fs-7 text-secondary">Açık Adres *</label>
                        <textarea name="address" class="form-control" rows="2" maxlength="500" required><?= e($f['address'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12"><div class="apply-section">Giriş Bilgileri</div></div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7 text-secondary">Kullanıcı Adı *</label>
                        <input type="text" name="username" class="form-control" maxlength="30" autocomplete="username" value="<?= e($f['username'] ?? '') ?>" required>
                        <small class="text-muted fs-8">Küçük harf, rakam, . - _</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7 text-secondary">Şifre *</label>
                        <input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" required>
                        <small class="text-muted fs-8">En az 8 karakter</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold fs-7 text-secondary">Şifre (Tekrar) *</label>
                        <input type="password" name="password2" class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="accept_terms" id="accept_terms" value="1" <?= !empty($_POST['accept_terms']) ? 'checked' : '' ?> required>
                            <label class="form-check-label fs-7" for="accept_terms">
                                <a href="../legal.php?p=kvkk" target="_blank">KVKK aydınlatma metnini</a> okudum. Verdiğim firma bilgilerinin doğru olduğunu beyan eder, bu bilgilerin başvurumun değerlendirilmesi için işlenmesini kabul ederim.
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger w-100 py-2 fw-bold rounded-3 mt-4">
                    <i class="bi bi-send me-1"></i> Başvuruyu Gönder
                </button>
            </form>
            <div class="mt-4 text-center fs-7 text-muted border-top pt-3">
                Zaten hesabınız var mı? <a href="login.php" class="fw-semibold text-danger">Giriş yapın</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
