<?php
require_once __DIR__ . '/../config.php';

if (isset($_SESSION['store_user'])) {
    log_action('Satıcı Çıkışı', 'Stores', $_SESSION['store_user']['id'], 'Satıcı paneli oturumu kapatıldı.');
    unset($_SESSION['store_user']);
}

redirect('login.php');
