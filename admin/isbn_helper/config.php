<?php
/**
 * ISBN Yardımcısı ayarları. Çoğu durumda DEĞİŞTİRMENİZ GEREKMEZ.
 */
return [
    // Sitenizin adresi (sonunda / olmadan). Giriş kontrolü bu adrese yapılır. Alan adınız farklıysa değiştirin.
    'base_url'   => 'https://yenicerikitabevi.com',
    // Yönetim paneli klasörü (siteadı.com/admin ise '/admin')
    'admin_path' => '/admin',

    // Giriş kontrolü:
    //  'loopback' (önerilen, kurulum gerektirmez): ziyaretçinin çerezi ile paneldeki bir sayfa açılır;
    //                 panel sizi giriş sayfasına yönlendirmiyorsa oturumunuz geçerlidir.
    //  'session'      : PHP oturumunda 'session_key' anahtarına bakar (login.php'de $_SESSION['...'] adını bulun).
    //  'off'          : KAPALI (yalnızca kendi bilgisayarınızda deneme için! Canlı sitede ASLA kullanmayın.)
    'auth_mode'    => 'session',
    'session_key'  => 'admin_user',  // admin/login.php başarılı girişte $_SESSION['admin_user'] yazar
    'session_name' => '',      // panel özel oturum adı kullanıyorsa (nadir)
    'check_path'   => '/index.php?p=Products',   // loopback modunda açılacak panel sayfası

    // İsteğe bağlı: Google Books anahtarı (anahtarsız kota çoğu zaman dolu olur; ücretsiz alınabilir)
    'google_books_key' => '',

    // Sunucudan dışarı bağlanma ayarları
    'timeout'    => 14,
    'ssl_verify' => true,
    'ca_bundle'  => '',        // CA hatası alırsanız hosting'in cacert.pem yolunu yazın

    // Test amaçlı hata ayrıntısı
    'debug' => false,
];
