# ISBN Yardımcısı (v2)

Kitap Yönetimi → **Yeni Kitap Ekle** sayfasının üstünde turuncu "ISBN ile otomatik doldur" kutusu çıkar.

## Ne yapar?

1. **📸 Fotoğraf çek / yükle** (telefonda kamerayı doğrudan açar):
   * Önce fotoğraftaki **barkod** okunur → ISBN ile arama yapılır.
   * Barkod yoksa fotoğraftaki yazılar (ad, yazar, yayınevi, çevirmen, yıl, ISBN) **Claude** ile okunur.
     ISBN görünmüyorsa ad + yazar ile Google Books / Open Library'de baskı aranır; tek ve yayınevi de
     tutan aday varsa o kullanılır, değilse "Olası baskılar" listesinden siz seçersiniz.
   * Ön kapak fotoğrafıysa kapak kırpılıp 600×900 yapılır. İnternette kapak bulunursa o kullanılır;
     kendi fotoğrafınız "Kendi fotoğrafımı kapak yap" düğmesiyle yedekte durur.
2. **ISBN yaz / 📷 Barkod tara** → aynı arama.
3. Doldurulan alanlar: kitap adı, orijinal adı, ISBN, yazar, yayınevi, kategori, sayfa, yıl, özet, çevirmen,
   fiyat, eski fiyat, kapak; **"Yeni Ürünler" (3 numaralı) vitrin** işaretlenir.
4. **Fiyat önceliği:** önce **Nadir Kitap** ve **Kitantik** ilanları (satıcı, durum, fiyat, kargo, ilan
   bağlantısı tabloda); en ucuz ilan **Satış Fiyatı** olur. İlan yoksa BKM, Kitapsepeti, D&R, Kitap Ambarı,
   Kitapyurdu'nun en ucuzu. Mağazaların liste (etiket) fiyatı **Eski Fiyat** olur. Kaynağı alanın altında yazar;
   tablodaki "Bu fiyatı kullan" ile değiştirebilirsiniz.
5. **Sarı alanlar:** tek kaynaktan gelen, kaynakların uyuşmadığı ya da fotoğraftan okunan bilgiler sarı
   işaretlenir, altında kaynağı yazar. Alanı düzelttiğinizde sarılık kalkar.
6. **Yazar / yayınevi:** listede **birebir aynı** ad varsa seçilir (büyük-küçük harf, Türkçe karakter farkı
   yok sayılır); benzer adlar eşleştirilmez, yoksa "+ Yeni" olarak eklenir.
7. **Kategori:** yalnızca birebir aynı ad seçilir; değilse **boş bırakılır**, uyarı ve "öneri" bağlantısı çıkar.
8. **Aynı ISBN zaten kayıtlıysa** kırmızı uyarı ve mevcut kitaba bağlantı çıkar; kayıt yapılmaz
   (Kaydet'e basılsa da sistem reddeder).
9. **Otomatik kaydet** (varsayılan KAPALI): Site Ayarları → "ISBN yardımcısı: fotoğraftan sonra otomatik
   kaydet". Açıksa fotoğrafla eklenen kitapta ad, yazar, yayınevi, fiyat ve kategori (formda zorunlu)
   bulunduysa 3 sn sonra kaydedilir; "İptal" ile durdurulabilir. Eksik varsa hangisinin eksik olduğu yazılır.

## Kurulum

Bu depoda kurulum hazırdır; sayfaya eklenen tek satır `admin/modules/products.php` sonundaki
`<script src="isbn_helper/isbn-helper.js?v=3" defer></script>` satırıdır. Fotoğraftan yazı okuma için
yalnızca bir adım gerekir:

### Claude API anahtarı (yalnızca fotoğraftan yazı okuma için)

1. <https://console.anthropic.com> → hesap açın → **Billing** bölümünden kredi yükleyin →
   **API Keys** → **Create Key**. Anahtar `sk-ant-...` ile başlar.
2. **Render** → yenicerikitabevi servisi → **Environment** → **Add Environment Variable**:
   `ANTHROPIC_API_KEY` = anahtarınız → **Save Changes** (servis yeniden başlar).
3. Anahtar **koda ve depoya yazılmaz**; `admin/isbn_helper/config.php` onu ortam değişkeninden okur.

Anahtar yoksa barkod, ISBN arama, fiyatlar ve diğer her şey çalışır; yalnızca barkodsuz fotoğrafta
"Fotoğraftan tanıma kapalı" yazar ve fotoğraf kapak olarak kullanılabilir.

Maliyet: her barkodsuz fotoğraf için bir Claude isteği yapılır (fotoğraf 1568 px'e küçültülerek gönderilir).
Barkodu okunan fotoğraflar ve ISBN aramaları Claude'a gitmez, ücretsizdir.

### Docker (Render bunu kendisi yapar)

`Dockerfile` Composer ile `composer.json`daki paketleri (Anthropic PHP SDK + Guzzle) kurar.
`vendor/` klasörü depoya eklenmez ve web'den açılamaz (`.htaccess`).

## Dosyalar

| Dosya | Görev |
|---|---|
| `admin/isbn_lookup.php` | Kitap bilgisi + ilan/mağaza fiyatları + "bu ISBN kayıtlı mı" (JSON) |
| `admin/isbn_photo.php` | Fotoğraftan yazı okuma (Claude) + kapak kırpma |
| `admin/isbn_cover.php` | İnternetteki kapağı indirip 600×900 JPEG yapar |
| `admin/isbn_helper/config.php` | Ayarlar: ilan sitesi adresleri, zaman aşımı, model, süre sınırı |
| `admin/isbn_helper/lib.php` | Arama ve ayrıştırma kodu |
| `admin/isbn_helper/isbn-helper.js` | Paneldeki kutu |
| `composer.json` | Claude SDK paketleri |

## Güvenlik ve sınırlar

* Yalnızca **giriş yapmış yönetici** kullanabilir (`$_SESSION['admin_user']`); diğerleri "Yetkisiz" alır.
* Her istek **50 sn** içinde biter (PHP sınırı 60 sn). Tüm siteler **paralel** sorgulanır; süre azalınca
  yeniden denemeler atlanır. Sonuç 10 dakika önbellekte tutulur.
* Fotoğraf en fazla 8 MB; yalnızca JPEG/PNG/WEBP kabul edilir.
* Nadir Kitap / Kitantik arama adresleri `config.php` → `listing_sites` içindedir. Siteler adres ya da sayfa
  yapısını değiştirirse "Kaynak durumu"nda ❌ görünür, diğer kaynaklar çalışmaya devam eder; yalnızca bu
  satırı güncellemek yeterlidir. Bot koruması sunucuyu engellerse "site sunucuyu engelledi" yazar.
* Deneme: panele giriş yapıp `https://yenicerikitabevi.com/admin/isbn_lookup.php?isbn=9789750719387` açın.

## Geri alma

* **Yalnızca fotoğraftan okumayı kapatmak:** Render → Environment → `ANTHROPIC_API_KEY` satırını silin.
* **Otomatik kaydı kapatmak:** Site Ayarları → "fotoğraftan sonra otomatik kaydet" kutusunu kaldırın.
* **Yardımcıyı tamamen kaldırmak:** `admin/modules/products.php` sonundaki `isbn-helper.js` script satırını
  silin. Form eskisi gibi elle çalışır; başka hiçbir şey değişmez.
* **Bu sürümün tamamını geri almak:** GitHub'da bu değişikliğin birleştirme (merge) kaydında **Revert**
  düğmesine basın ve açılan PR'ı birleştirin; Render önceki sürümü yeniden kurar. Veritabanında yapısal
  değişiklik yoktur (yalnızca `isbn_autosave` ayar satırı eklenir, zararsızdır).
