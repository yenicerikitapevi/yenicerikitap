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

    // İkinci el ilan siteleri (fiyat önceliği bunlarda). {q} = ISBN ya da "ad yazar" araması (URL kodlanmış).
    // Siteler arama adresini değiştirirse yalnızca bu satırları güncelleyin.
    'listing_sites' => [
        'nadirkitap' => ['name' => 'Nadir Kitap', 'search' => 'https://www.nadirkitap.com/kitapara.php?ara=aramayap&kelime={q}'],
        'kitantik'   => ['name' => 'Kitantik',    'search' => 'https://www.kitantik.com/arama?q={q}'],
    ],

    // Fotoğraftan kitap tanıma (Claude). Anahtar KODA YAZILMAZ: Render → Environment → ANTHROPIC_API_KEY
    // (Ortam değişkeni yoksa ve gerçekten gerekiyorsa buraya yazılabilir; bu dosyayı herkese açık depoya göndermeyin.)
    'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
    'vision_model'      => 'claude-opus-5-5',

    // Bir isteğin en fazla süresi (sn). PHP sınırı 60 sn; altında kalmalı.
    'request_budget' => 50,

    // Test amaçlı hata ayrıntısı
    'debug' => false,
];
