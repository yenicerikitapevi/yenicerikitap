<?php
/**
 * STOREFRONT: Müşteri Hesabı (hesap.php)
 * ?a=giris | kayit | cikis | sifre (şifremi unuttum) | yeni-sifre&t=TOKEN ; parametresiz: Hesabım (siparişler, bilgiler, şifre)
 * Daha önce üye olmadan sipariş veren müşterinin hesabı rastgele şifreyle zaten vardır; e-postaya giden bağlantıyla şifre belirler.
 */
require_once __DIR__ . '/config.php';

$a = $_GET['a'] ?? '';
$me = $_SESSION['customer_user'] ?? null;
if (empty($_SESSION['customer_token'])) {
    $_SESSION['customer_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['customer_token'];
$errors = [];
$notice = '';
$f = ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];

/** Aynı oturumdan çok sayıda denemeyi yavaşlatır (giriş, kayıt, şifre e-postası) */
function customer_rate_ok($key, $max, $window = 900) {
    $_SESSION[$key] = array_values(array_filter($_SESSION[$key] ?? [], fn($t) => $t > time() - $window));
    return count($_SESSION[$key]) < $max;
}
function customer_rate_hit($key) {
    $_SESSION[$key][] = time();
}

function customer_login($u) {
    session_regenerate_id(true);
    $_SESSION['customer_user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email']];
    db_query("UPDATE users SET last_login_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int)$u['id']]);
}

/** Şifre belirleme/sıfırlama bağlantısı e-postası (1 saat geçerli) */
function customer_send_reset($u) {
    $raw = bin2hex(random_bytes(24));
    db_query("UPDATE users SET reset_token_hash = ?, reset_expires = ? WHERE id = ?", [hash('sha256', $raw), date('Y-m-d H:i:s', time() + 3600), (int)$u['id']]);
    $url = abs_url('hesap.php?a=yeni-sifre&t=' . $raw);
    $html = mail_layout('Şifre belirleme', '<p>Merhaba ' . e($u['name']) . ',</p><p>Yeniçeri Kitabevi hesabınız için şifre belirlemek üzere aşağıdaki düğmeye tıklayın. Bağlantı 1 saat geçerlidir.</p>'
        . mail_button($url, 'Şifremi Belirle') . '<p style="color:#888;font-size:13px">Bu isteği siz yapmadıysanız bu e-postayı dikkate almayın; şifreniz değişmez.</p>');
    $text = "Merhaba {$u['name']},\n\nŞifrenizi belirlemek için (1 saat geçerli): {$url}\n\nBu isteği siz yapmadıysanız dikkate almayın.";
    return send_mail($u['email'], 'Yeniçeri Kitabevi - Şifre belirleme', $html, $text, 'hesap');
}

if ($a === 'cikis') {
    unset($_SESSION['customer_user']);
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $errors[] = 'Oturum süresi doldu. Lütfen formu tekrar gönderin.';
    } elseif ($a === 'giris') {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if (!customer_rate_ok('customer_login_times', 8)) {
            $errors[] = 'Çok fazla deneme yapıldı. Lütfen 15 dakika sonra tekrar deneyin.';
        } else {
            $u = db_fetch("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND role = 'customer'", [$email]);
            if ($u && $u['status'] === 'active' && password_verify($pass, (string)$u['password_hash'])) {
                customer_login($u);
                $back = (string)($_POST['back'] ?? '');
                redirect(preg_match('#^[a-z0-9_\-]+\.php(\?[^\s]*)?$#i', $back) ? $back : 'hesap.php');
            }
            customer_rate_hit('customer_login_times');
            sleep(1);
            $errors[] = ($u && $u['status'] !== 'active')
                ? 'Hesabınız şu an kullanıma kapalı. Lütfen bizimle iletişime geçin.'
                : 'E-posta veya şifre hatalı. Daha önce üye olmadan sipariş verdiyseniz “Şifremi unuttum” ile şifre belirleyebilirsiniz.';
        }
        $f['email'] = $email;
    } elseif ($a === 'kayit') {
        foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));
        $f['phone'] = preg_replace('/[^\d+ ()-]/', '', $f['phone']);
        $pass = (string)($_POST['password'] ?? '');
        if (trim((string)($_POST['website'] ?? '')) !== '') $errors[] = 'Kayıt alınamadı.';
        if (!customer_rate_ok('customer_signup_times', 3, 3600)) $errors[] = 'Çok fazla kayıt denemesi yapıldı. Lütfen bir saat sonra tekrar deneyin.';
        if (mb_strlen($f['name'], 'UTF-8') < 3) $errors[] = 'Adınızı ve soyadınızı yazın.';
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Geçerli bir e-posta adresi girin.';
        if ($f['phone'] !== '' && strlen(preg_replace('/\D/', '', $f['phone'])) < 10) $errors[] = 'Telefon numarası eksik görünüyor.';
        if (strlen($pass) < 8) $errors[] = 'Şifre en az 8 karakter olmalı.';
        if ($pass !== (string)($_POST['password2'] ?? '')) $errors[] = 'Şifreler birbiriyle aynı değil.';
        if (empty($_POST['accept_terms'])) $errors[] = 'Üyelik için KVKK aydınlatma metnini onaylayın.';

        if (empty($errors)) {
            customer_rate_hit('customer_signup_times');
            $u = db_fetch("SELECT * FROM users WHERE LOWER(email) = LOWER(?)", [$f['email']]);
            if ($u) {
                // Hesap başkası tarafından ele geçirilmesin: mevcut e-postaya şifre bağlantısı gönderilir, şifre burada değiştirilmez
                if ($u['role'] === 'customer' && $u['status'] === 'active') customer_send_reset($u);
                $notice = 'Bu e-posta adresiyle daha önce sipariş verilmiş ya da üye olunmuş. Hesabınıza şifre belirlemeniz için e-posta adresinize bir bağlantı gönderdik.';
                $f = ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];
            } else {
                db_query("INSERT INTO users (name, email, password_hash, role, status, phone, address) VALUES (?, ?, ?, 'customer', 'active', ?, ?)",
                    [$f['name'], $f['email'], password_hash($pass, PASSWORD_DEFAULT), $f['phone'], $f['address']]);
                $u = db_fetch("SELECT * FROM users WHERE id = ?", [db_last_id()]);
                customer_login($u);
                set_flash('success', 'Üyeliğiniz oluşturuldu. Hoş geldiniz!');
                redirect('hesap.php');
            }
        }
    } elseif ($a === 'sifre') {
        $email = trim((string)($_POST['email'] ?? ''));
        if (customer_rate_ok('customer_reset_times', 3, 3600)) {
            customer_rate_hit('customer_reset_times');
            $u = db_fetch("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND role = 'customer' AND status = 'active'", [$email]);
            if ($u) customer_send_reset($u);
        }
        // Hesabın var olup olmadığı belli edilmez
        $notice = 'Bu e-posta adresiyle bir hesap varsa şifre belirleme bağlantısı gönderildi. Gelen kutunuzu (ve istenmeyen klasörünü) kontrol edin.';
    } elseif ($a === 'yeni-sifre') {
        $raw = (string)($_POST['t'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $u = $raw !== '' ? db_fetch("SELECT * FROM users WHERE reset_token_hash = ? AND role = 'customer'", [hash('sha256', $raw)]) : null;
        if (!$u || strtotime((string)$u['reset_expires']) < time()) {
            $errors[] = 'Bağlantının süresi dolmuş ya da geçersiz. Lütfen yeni bağlantı isteyin.';
        } elseif (strlen($pass) < 8) {
            $errors[] = 'Şifre en az 8 karakter olmalı.';
        } elseif ($pass !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'Şifreler birbiriyle aynı değil.';
        } else {
            db_query("UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_expires = NULL WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), (int)$u['id']]);
            if ($u['status'] === 'active') customer_login($u);
            set_flash('success', 'Şifreniz kaydedildi.');
            redirect($u['status'] === 'active' ? 'hesap.php' : 'hesap.php?a=giris');
        }
    } elseif ($me && $a === 'bilgiler') {
        foreach (['name', 'phone', 'address'] as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
        $f['phone'] = preg_replace('/[^\d+ ()-]/', '', $f['phone']);
        if (mb_strlen($f['name'], 'UTF-8') < 3) {
            $errors[] = 'Adınızı ve soyadınızı yazın.';
        } else {
            db_query("UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?", [$f['name'], $f['phone'], $f['address'], $me['id']]);
            $_SESSION['customer_user']['name'] = $f['name'];
            set_flash('success', 'Bilgileriniz güncellendi.');
            redirect('hesap.php#bilgiler');
        }
    } elseif ($me && $a === 'sifre-degistir') {
        $u = db_fetch("SELECT * FROM users WHERE id = ?", [$me['id']]);
        $pass = (string)($_POST['password'] ?? '');
        if (!$u || !password_verify((string)($_POST['current'] ?? ''), (string)$u['password_hash'])) {
            $errors[] = 'Mevcut şifreniz hatalı.';
        } elseif (strlen($pass) < 8) {
            $errors[] = 'Yeni şifre en az 8 karakter olmalı.';
        } elseif ($pass !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'Yeni şifreler birbiriyle aynı değil.';
        } else {
            db_query("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), $me['id']]);
            set_flash('success', 'Şifreniz değiştirildi.');
            redirect('hesap.php#sifre');
        }
    }
}

// Giriş gerektiren sayfa: hesap paneli
$user = null;
$orders = [];
if (!in_array($a, ['giris', 'kayit', 'sifre', 'yeni-sifre'], true)) {
    if (!$me) redirect('hesap.php?a=giris');
    $user = db_fetch("SELECT * FROM users WHERE id = ? AND role = 'customer'", [$me['id']]);
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['customer_user']);
        redirect('hesap.php?a=giris');
    }
    $orders = db_fetch_all("SELECT id, order_number, total_amount, status, payment_method, tracking_number, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 50", [$user['id']]);
    if (!$errors) $f = ['name' => $user['name'], 'email' => $user['email'], 'phone' => (string)$user['phone'], 'address' => (string)$user['address']];
} elseif ($me && in_array($a, ['giris', 'kayit'], true)) {
    redirect('hesap.php');
}

$titles = ['giris' => 'Giriş Yap', 'kayit' => 'Üye Ol', 'sifre' => 'Şifremi Unuttum', 'yeni-sifre' => 'Şifre Belirle'];
$page_title = $titles[$a] ?? 'Hesabım';
$flash = get_flash();
$status_badge = ['Ödeme Bekliyor' => 'warning', 'Hazırlanıyor' => 'info', 'Kargolandı' => 'primary', 'Tamamlandı' => 'success', 'İptal' => 'danger'];
$header_active = '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head(['title' => seo_title($page_title), 'description' => seo_description('Yeniçeri Kitabevi müşteri hesabı: üye olun, giriş yapın, siparişlerinizi görüntüleyin.'), 'canonical' => 'hesap.php', 'robots' => 'noindex,follow']) ?>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/_site_header.php'; ?>

    <main class="container py-5" style="max-width: <?= $user ? '980px' : '520px' ?>;">
        <?= $flash ?>
        <?php if ($errors): ?><div class="alert alert-danger fs-7"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert alert-info fs-7"><?= e($notice) ?></div><?php endif; ?>

        <?php if ($a === 'giris'): ?>
            <h1 class="h3 fw-extrabold mb-3"><i class="bi bi-person-circle text-danger me-2"></i>Giriş Yap</h1>
            <form method="POST" action="hesap.php?a=giris" class="card border-0 shadow-sm rounded-4 p-4">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="back" value="<?= e($_GET['back'] ?? '') ?>">
                <label class="form-label fw-semibold fs-7" for="lMail">E-posta</label>
                <input type="email" name="email" id="lMail" class="form-control mb-3" value="<?= e($f['email']) ?>" required autocomplete="email">
                <label class="form-label fw-semibold fs-7" for="lPass">Şifre</label>
                <input type="password" name="password" id="lPass" class="form-control mb-2" required autocomplete="current-password">
                <a href="hesap.php?a=sifre" class="fs-7 text-danger mb-3">Şifremi unuttum / şifre belirle</a>
                <button class="btn btn-danger fw-bold w-100">Giriş Yap</button>
            </form>
            <p class="text-center fs-7 mt-3">Hesabınız yok mu? <a href="hesap.php?a=kayit" class="fw-bold text-danger">Üye Olun</a></p>

        <?php elseif ($a === 'kayit'): ?>
            <h1 class="h3 fw-extrabold mb-1"><i class="bi bi-person-plus text-danger me-2"></i>Üye Ol</h1>
            <p class="text-muted fs-7 mb-3">Üye olarak siparişlerinizi tek yerden takip edebilir, bilgileriniz ödeme sayfasında hazır gelir.</p>
            <form method="POST" action="hesap.php?a=kayit" class="card border-0 shadow-sm rounded-4 p-4">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                <label class="form-label fw-semibold fs-7" for="kName">Ad Soyad *</label>
                <input type="text" name="name" id="kName" class="form-control mb-3" value="<?= e($f['name']) ?>" required autocomplete="name">
                <label class="form-label fw-semibold fs-7" for="kMail">E-posta *</label>
                <input type="email" name="email" id="kMail" class="form-control mb-3" value="<?= e($f['email']) ?>" required autocomplete="email">
                <label class="form-label fw-semibold fs-7" for="kPhone">Telefon</label>
                <input type="tel" name="phone" id="kPhone" class="form-control mb-3" value="<?= e($f['phone']) ?>" autocomplete="tel" placeholder="05xx xxx xx xx">
                <label class="form-label fw-semibold fs-7" for="kAddr">Teslimat Adresi</label>
                <textarea name="address" id="kAddr" class="form-control mb-3" rows="2" autocomplete="street-address"><?= e($f['address']) ?></textarea>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fw-semibold fs-7" for="kPass">Şifre *</label><input type="password" name="password" id="kPass" class="form-control" minlength="8" required autocomplete="new-password"></div>
                    <div class="col-6"><label class="form-label fw-semibold fs-7" for="kPass2">Şifre (tekrar) *</label><input type="password" name="password2" id="kPass2" class="form-control" minlength="8" required autocomplete="new-password"></div>
                </div>
                <div class="form-check fs-7 mb-3">
                    <input class="form-check-input" type="checkbox" name="accept_terms" id="kTerms" value="1" required>
                    <label class="form-check-label" for="kTerms"><a href="legal.php?p=kvkk" target="_blank">KVKK aydınlatma metnini</a> okudum, kişisel verilerimin üyelik ve sipariş işlemleri için işlenmesini kabul ediyorum.</label>
                </div>
                <button class="btn btn-danger fw-bold w-100">Üye Ol</button>
            </form>
            <p class="text-center fs-7 mt-3">Zaten üye misiniz? <a href="hesap.php?a=giris" class="fw-bold text-danger">Giriş Yapın</a></p>

        <?php elseif ($a === 'sifre'): ?>
            <h1 class="h3 fw-extrabold mb-1"><i class="bi bi-key text-danger me-2"></i>Şifremi Unuttum</h1>
            <p class="text-muted fs-7 mb-3">E-posta adresinizi yazın, şifre belirleme bağlantısı gönderelim. Daha önce üye olmadan sipariş verdiyseniz de bu yolla hesabınıza şifre belirleyebilirsiniz.</p>
            <form method="POST" action="hesap.php?a=sifre" class="card border-0 shadow-sm rounded-4 p-4">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label class="form-label fw-semibold fs-7" for="sMail">E-posta</label>
                <input type="email" name="email" id="sMail" class="form-control mb-3" required autocomplete="email">
                <button class="btn btn-danger fw-bold w-100">Bağlantı Gönder</button>
            </form>
            <p class="text-center fs-7 mt-3"><a href="hesap.php?a=giris" class="text-danger">Girişe dön</a></p>

        <?php elseif ($a === 'yeni-sifre'): ?>
            <h1 class="h3 fw-extrabold mb-3"><i class="bi bi-shield-lock text-danger me-2"></i>Şifre Belirle</h1>
            <form method="POST" action="hesap.php?a=yeni-sifre" class="card border-0 shadow-sm rounded-4 p-4">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="t" value="<?= e($_REQUEST['t'] ?? '') ?>">
                <label class="form-label fw-semibold fs-7" for="nPass">Yeni şifre</label>
                <input type="password" name="password" id="nPass" class="form-control mb-3" minlength="8" required autocomplete="new-password">
                <label class="form-label fw-semibold fs-7" for="nPass2">Yeni şifre (tekrar)</label>
                <input type="password" name="password2" id="nPass2" class="form-control mb-3" minlength="8" required autocomplete="new-password">
                <button class="btn btn-danger fw-bold w-100">Şifreyi Kaydet</button>
            </form>

        <?php else: ?>
            <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                <h1 class="h3 fw-extrabold mb-0"><i class="bi bi-person-circle text-danger me-2"></i>Hesabım</h1>
                <div class="d-flex align-items-center gap-3 fs-7"><span class="text-muted"><?= e($user['email']) ?></span><a href="hesap.php?a=cikis" class="btn btn-outline-dark btn-sm rounded-pill"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</a></div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-bag-check me-1 text-danger"></i>Siparişlerim</h2>
                <?php if (!$orders): ?>
                    <p class="text-muted fs-7 mb-0">Henüz siparişiniz yok. <a href="index.php" class="text-danger">Alışverişe başlayın</a>.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle fs-7 mb-0">
                            <thead><tr class="text-muted"><th>Sipariş No</th><th>Tarih</th><th>Durum</th><th class="text-end">Tutar</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td class="fw-semibold font-monospace"><?= e($o['order_number']) ?></td>
                                    <td><?= e(date('d.m.Y', strtotime($o['created_at']))) ?></td>
                                    <td><span class="badge text-bg-<?= $status_badge[$o['status']] ?? 'secondary' ?>"><?= e($o['status']) ?></span></td>
                                    <td class="text-end fw-bold"><?= format_price($o['total_amount']) ?></td>
                                    <td class="text-end">
                                        <form method="POST" action="siparis-takip.php" class="d-inline">
                                            <input type="hidden" name="no" value="<?= e($o['order_number']) ?>">
                                            <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                                            <button class="btn btn-sm btn-outline-danger">Detay</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="row g-4">
                <div class="col-lg-7" id="bilgiler">
                    <form method="POST" action="hesap.php?a=bilgiler" class="card border-0 shadow-sm rounded-4 p-4 h-100">
                        <h2 class="h6 fw-bold mb-3"><i class="bi bi-person-lines-fill me-1 text-danger"></i>Bilgilerim</h2>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <label class="form-label fw-semibold fs-7" for="bName">Ad Soyad</label>
                        <input type="text" name="name" id="bName" class="form-control mb-3" value="<?= e($f['name']) ?>" required>
                        <label class="form-label fw-semibold fs-7" for="bPhone">Telefon</label>
                        <input type="tel" name="phone" id="bPhone" class="form-control mb-3" value="<?= e($f['phone']) ?>">
                        <label class="form-label fw-semibold fs-7" for="bAddr">Teslimat Adresi</label>
                        <textarea name="address" id="bAddr" class="form-control mb-3" rows="3"><?= e($f['address']) ?></textarea>
                        <button class="btn btn-dark fw-bold">Kaydet</button>
                    </form>
                </div>
                <div class="col-lg-5" id="sifre">
                    <form method="POST" action="hesap.php?a=sifre-degistir" class="card border-0 shadow-sm rounded-4 p-4 h-100">
                        <h2 class="h6 fw-bold mb-3"><i class="bi bi-shield-lock me-1 text-danger"></i>Şifre Değiştir</h2>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <input type="password" name="current" class="form-control mb-2" placeholder="Mevcut şifre" required autocomplete="current-password">
                        <input type="password" name="password" class="form-control mb-2" placeholder="Yeni şifre (en az 8)" minlength="8" required autocomplete="new-password">
                        <input type="password" name="password2" class="form-control mb-3" placeholder="Yeni şifre (tekrar)" minlength="8" required autocomplete="new-password">
                        <button class="btn btn-outline-dark fw-bold">Şifreyi Değiştir</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>

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
                <a href="checkout.php" class="btn btn-danger w-100 py-2.5 fw-bold rounded-3 shadow">Sepeti Onayla ve Öde <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= asset_v('assets/js/app.js') ?>"></script>
</body>
</html>
