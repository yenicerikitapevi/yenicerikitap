<?php
require_once __DIR__ . '/../config.php';

auth_check('admin');

// Modüller sayfa düzeni basıldıktan sonra redirect() çağırıyor; çıktıyı tamponla ki yönlendirme başlığı gönderilebilsin
ob_start();

$allowed_pages = [
    'FrontPage'    => 'frontpage.php',
    'Reports'      => 'reports.php',
    'Products'     => 'products.php',
    'Stores'       => 'stores.php',
    'Campaigns'    => 'campaigns.php',
    'Catalog'      => 'catalog.php',
    'Members'      => 'members.php',
    'Orders'       => 'orders.php',
    'Transactions' => 'transactions.php',
    'Messages'     => 'messages.php',
    'ActionLogs'   => 'actionlogs.php',
    'DataQuality'  => 'dataquality.php',
    'Cleanup'      => 'cleanup.php',
    'Settings'     => 'settings.php'
];

$requested_p = sanitize($_GET['p'] ?? 'FrontPage');

if (array_key_exists($requested_p, $allowed_pages)) {
    $current_p = $requested_p;
    $module_file = __DIR__ . '/modules/' . $allowed_pages[$requested_p];
} else {
    $current_p = 'FrontPage';
    $module_file = __DIR__ . '/modules/frontpage.php';
}

$admin_name = $_SESSION['admin_user']['name'] ?? 'Yönetici';
$unread_messages = db_fetch("SELECT COUNT(*) as cnt FROM contact_messages WHERE is_read = 0")['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetim Paneli | Yeniçeri Kitabevi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-sidebar shadow-lg">
            <div class="p-3 border-bottom border-secondary border-opacity-25 text-center">
                <a href="index.php?p=FrontPage" class="text-white text-decoration-none d-inline-block">
                    <div class="bg-white p-2 rounded-3 mb-1 shadow-sm d-inline-block">
                        <img src="../assets/images/logo_horizontal.png" alt="Yeniçeri Kitabevi" style="height: 38px; object-fit: contain;">
                    </div>
                    <small class="fw-bold text-secondary fs-8 d-block text-uppercase tracking-wider">Admin Panel</small>
                </a>
            </div>

            <nav class="mt-3">
                <div class="px-3 mb-2 text-uppercase text-secondary fs-8 fw-bold">Ana Menü</div>
                
                <a href="index.php?p=FrontPage" class="nav-link <?= $current_p === 'FrontPage' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                
                <a href="index.php?p=Reports" class="nav-link <?= $current_p === 'Reports' ? 'active' : '' ?>">
                    <i class="bi bi-graph-up-arrow"></i> Raporlar & Trafik
                </a>

                <a href="index.php?p=Products" class="nav-link <?= $current_p === 'Products' ? 'active' : '' ?>">
                    <i class="bi bi-book"></i> Kitap Yönetimi
                </a>

                <a href="index.php?p=Stores" class="nav-link <?= $current_p === 'Stores' ? 'active' : '' ?>">
                    <i class="bi bi-shop"></i> Alt Dükkanlar (Sahaflar)
                </a>

                <a href="index.php?p=Campaigns" class="nav-link <?= $current_p === 'Campaigns' ? 'active' : '' ?>">
                    <i class="bi bi-gift"></i> Kampanyalar
                </a>

                <a href="index.php?p=Catalog" class="nav-link <?= $current_p === 'Catalog' ? 'active' : '' ?>">
                    <i class="bi bi-tags"></i> Yazar & Yayınevi
                </a>

                <a href="index.php?p=Orders" class="nav-link <?= $current_p === 'Orders' ? 'active' : '' ?>">
                    <i class="bi bi-bag-check"></i> Sipariş Operasyonları
                </a>

                <a href="index.php?p=Members" class="nav-link <?= $current_p === 'Members' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> Müşteri Yönetimi
                </a>

                <a href="index.php?p=Transactions" class="nav-link <?= $current_p === 'Transactions' ? 'active' : '' ?>">
                    <i class="bi bi-credit-card-2-front"></i> Ödeme Kayıtları
                </a>

                <a href="index.php?p=Messages" class="nav-link <?= $current_p === 'Messages' ? 'active' : '' ?>">
                    <i class="bi bi-envelope"></i> Mesajlar
                    <?php if ($unread_messages > 0): ?><span class="badge bg-danger rounded-pill ms-1"><?= (int)$unread_messages ?></span><?php endif; ?>
                </a>

                <div class="px-3 mt-4 mb-2 text-uppercase text-secondary fs-8 fw-bold">Sistem & Denetim</div>

                <a href="index.php?p=DataQuality" class="nav-link <?= $current_p === 'DataQuality' ? 'active' : '' ?>">
                    <i class="bi bi-clipboard2-check"></i> Veri Kontrolü
                </a>

                <a href="index.php?p=Cleanup" class="nav-link <?= $current_p === 'Cleanup' ? 'active' : '' ?>">
                    <i class="bi bi-trash3"></i> Örnek Veri Temizliği
                </a>

                <a href="index.php?p=ActionLogs" class="nav-link <?= $current_p === 'ActionLogs' ? 'active' : '' ?>">
                    <i class="bi bi-journal-text"></i> İşlem Logları
                </a>

                <a href="index.php?p=Settings" class="nav-link <?= $current_p === 'Settings' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i> Site Ayarları
                </a>

                <div class="px-3 mt-5">
                    <a href="../index.php" target="_blank" class="btn btn-outline-light btn-sm w-100 mb-2">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Siteye Git
                    </a>
                    <a href="logout.php" class="btn btn-danger btn-sm w-100 font-bold">
                        <i class="bi bi-power me-1"></i> Oturumu Kapat
                    </a>
                </div>
            </nav>
        </aside>

        <main class="admin-content">
            <header class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom">
                <div>
                    <h4 class="fw-bold mb-0 text-dark">
                        <?php
                        $titles = [
                            'FrontPage' => 'Genel Bakış & İstatistikler',
                            'Reports' => 'Raporlar & Trafik Analizi',
                            'Products' => 'Kitap & Katalog Yönetimi',
                            'Stores' => 'Alt Dükkanlar & Satıcı Yönetimi',
                            'Campaigns' => 'Kampanyalar & Kuponlar',
                            'Members' => 'Müşteri & Üye Yönetimi',
                            'Orders' => 'Sipariş Operasyonları',
                            'Transactions' => 'Ödeme & Finans Takibi',
                            'Messages' => 'Gelen Mesajlar',
                            'ActionLogs' => 'Sistem ve Denetim Logları',
                            'DataQuality' => 'Veri Kontrolü (SEO)',
                            'Cleanup' => 'Örnek Veri Temizliği',
                            'Settings' => 'Genel Site Ayarları'
                        ];
                        echo $titles[$current_p] ?? 'Yönetim Paneli';
                        ?>
                    </h4>
                    <span class="text-muted fs-7">Parametre: <code>p=<?= e($current_p) ?></code></span>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-2 rounded-4 shadow-sm border">
                        <i class="bi bi-person-circle fs-4 text-danger"></i>
                        <div>
                            <div class="fw-bold fs-7 lh-1"><?= e($admin_name) ?></div>
                            <small class="text-muted fs-8">Sistem Yöneticisi</small>
                        </div>
                    </div>
                </div>
            </header>

            <?= get_flash() ?>

            <?php
            if (file_exists($module_file)) {
                include $module_file;
            } else {
                echo '<div class="alert alert-danger">Hata: Modül dosyası bulunamadı!</div>';
            }
            ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
