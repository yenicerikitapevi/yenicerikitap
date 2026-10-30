<?php
/**
 * SATICI PANELİ GİRİŞİ: Alt dükkân (sahaf) kendi kullanıcı adı ve şifresiyle girer (stores tablosu)
 */
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['store_user'])) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    $store = $username !== '' ? db_fetch("SELECT * FROM stores WHERE LOWER(username) = ?", [$username]) : null;

    if ($store && password_verify($password, $store['password_hash'])) {
        if (!$store['is_active']) {
            $error = 'Dükkânınız pasif durumda. Lütfen site yöneticisiyle iletişime geçin.';
        } else {
            session_regenerate_id(true);
            $_SESSION['store_user'] = ['id' => (int)$store['id'], 'name' => $store['name'], 'username' => $store['username']];
            log_action('Satıcı Girişi', 'Stores', $store['id'], 'Satıcı paneline giriş: ' . $store['username']);
            redirect('index.php');
        }
    } else {
        sleep(1); // kaba kuvvet denemelerini yavaşlat
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Satıcı Paneli Giriş | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: linear-gradient(135deg, #000000 0%, #16161e 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .login-card { width: 100%; max-width: 420px; border-radius: 20px; background: #fff; box-shadow: 0 20px 40px rgba(0,0,0,.6); border: 2px solid #e61919; }
    </style>
</head>
<body>
    <div class="login-card p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 44px; object-fit: contain;" class="mb-3">
            <h5 class="fw-bold text-dark mb-1">Satıcı Paneli</h5>
            <p class="text-muted fs-7 mb-0">Dükkânınızın ziyaret ve satış raporları</p>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-danger fs-7 py-2"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-semibold fs-7 text-secondary">Kullanıcı Adı</label>
                <input type="text" name="username" class="form-control" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold fs-7 text-secondary">Şifre</label>
                <input type="password" name="password" class="form-control" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-danger w-100 py-2 fw-bold rounded-3">
                <i class="bi bi-box-arrow-in-right me-1"></i> Giriş Yap
            </button>
        </form>
        <div class="mt-4 text-center fs-8 text-muted border-top pt-3">
            Kitap aktarım uygulamasında kullandığınız kullanıcı adı ve şifre ile giriş yapın.
        </div>
    </div>
</body>
</html>
