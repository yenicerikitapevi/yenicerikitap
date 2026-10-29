# ISBN Yardımcısı

Kitap Yönetimi → **Yeni Kitap Ekle** sayfasının üstünde turuncu "ISBN ile otomatik doldur" kutusu çıkar:
ISBN yaz / barkod tara / barkod fotoğrafı yükle → kitap adı, orijinal adı, yazar, yayınevi, kategori,
sayfa, yıl, özet, en ucuz fiyat, eski fiyat ve kapak (600×900) forma gelir. **Kaydet'e siz basarsınız.**

Bu depoda kurulum hazırdır (ek adım gerekmez):

| Dosya | Görev |
|---|---|
| `admin/isbn_lookup.php` | Kitap bilgisi + mağaza fiyatları (JSON) |
| `admin/isbn_cover.php` | Kapak görselini indirip 600×900 JPEG yapar |
| `admin/isbn_helper/` | `config.php`, `lib.php`, `isbn-helper.js`, barkod kütüphanesi, `.htaccess` |
| `admin/modules/products.php` | "Yeni Kitap Ekle" formunun sonundaki script satırı |

* Giriş kontrolü `session` modundadır: `$_SESSION['admin_user']` (bkz. `admin/login.php`). Giriş yapmamış
  ziyaretçi `isbn_lookup.php` / `isbn_cover.php` adreslerinden "Yetkisiz" alır.
* Sunucuda PHP 7.4+, `curl`, `gd`, `dom`, `mbstring` eklentileri gerekir (Dockerfile `gd` ve `exif` kurar).
* Deneme: panele giriş yapıp `https://SITENIZ/admin/isbn_lookup.php?isbn=9789750719387` açın → `{"ok":true,...}`.
* Ayarlar `admin/isbn_helper/config.php` içindedir (Google Books anahtarı, SSL `ca_bundle` vb.).
* Kamera ile barkod tarama yalnızca `https://` adreslerde çalışır.
* Mağaza sayfa yapısı değişirse o mağaza "Kaynak durumu"nda ❌ görünür; diğerleri çalışmaya devam eder.
