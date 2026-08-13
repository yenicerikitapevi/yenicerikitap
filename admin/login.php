<?php
require_once __DIR__ . '/../config.php';

$error = '';

if (isset($_SESSION['admin_user'])) {
    redirect(SITE_URL . '/admin/index.php?p=FrontPage');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        $user = db_fetch("SELECT * FROM users WHERE email = ? AND role = 'admin' AND status = 'active'", [$email]);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];

            log_action('Admin Girişi', 'Auth', $user['id'], 'Başarılı yönetim paneli oturumu açıldı.');
            redirect(SITE_URL . '/admin/index.php?p=FrontPage');
        } else {
            $error = 'Geçersiz e-posta veya parola!';
        }
    } else {
        $error = 'Lütfen tüm alanları doldurun.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Giriş | Yeniçeri Kitabevi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #000000 0%, #16161e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            border: 2px solid #e61919;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="login-card p-4 p-md-5">
        <div class="text-center mb-3">
            <img src="../assets/images/logo_vertical.png" alt="Yeniçeri Kitabevi" style="height: 135px; object-fit: contain;" class="mb-2">
            <p class="text-muted fs-7 mb-0">Yönetim Paneli Girişi</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger fs-7 py-2"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label fw-semibold fs-7 text-secondary">E-Posta Adresi</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" class="form-control bg-light border-start-0" placeholder="admin@yeniceri.com" value="admin@yeniceri.com" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold fs-7 text-secondary">Parola</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-key"></i></span>
                    <input type="password" name="password" class="form-control bg-light border-start-0" placeholder="••••••••" value="admin123" required>
                </div>
            </div>

            <button type="submit" class="btn btn-danger w-100 py-2.5 fw-bold rounded-3 shadow">
                <i class="bi bi-box-arrow-in-right me-1"></i> Panele Giriş Yap
            </button>
        </form>

        <div class="mt-4 text-center fs-7 text-muted border-top pt-3">
            <small><i class="bi bi-info-circle me-1"></i> Varsayılan Giriş: <strong>admin@yeniceri.com</strong> / <strong>admin123</strong></small>
        </div>
    </div>
</body>
</html>
