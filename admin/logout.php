<?php
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['admin_user'])) {
    log_action('Admin Çıkışı', 'Auth', $_SESSION['admin_user']['id'], 'Yönetim paneli oturumu kapatıldı.');
    unset($_SESSION['admin_user']);
}

redirect(SITE_URL . '/admin/login.php');
