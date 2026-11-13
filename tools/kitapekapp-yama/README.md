# kitapekapp yaması (1.4.10)

Aktarım uygulamasındaki kitap sınırı değiştirildi. 1.4.10 sürümünde:

- **500** seçeneği açık.
- **1000** seçeneğinin yerine **Sınırsız** geldi. Sınırsız'ın değeri 100000'dir, yani pratikte mağazadaki tüm kitaplar aktarılır.
- Ekrandaki yazılar Sınırsız seçildiğinde sayı yerine "tüm kitaplar" diyor (`LimitText`).
- İlerleme çubuğu Sınırsız'da da ilerliyor (`LimitText.ratio`).
- **1.4.5:** Filigran temizliği iyileşti (`WmAlign`). Uygulamanın kendi temizleyicisi NadirKitap filigran şablonunu her görselde sabit konumlara koyuyordu; fotoğraf 1-2 piksel kaymışsa harflerin yerinde açık/koyu "kabartma" izi kalıyordu. `WmAlign`, her damgayı ±6 piksel içinde fotoğraftaki filigranla en iyi örtüştüğü yere hizalar, sonra uygulamanın temizleyicisini bu konumlarla çalıştırır. `ImageUtil.removeWatermarkBytes` artık `cleanWatermark` yerine `WmAlign.clean` çağırır. Bir hata olursa eski temizleyiciye düşer.
- **1.4.6:** Saatlik aktarım sınırı. Uygulamanın kendi kodunda, her sayfa gönderiminden sonra "bekle (dakika)" değeri gelirse bekleyip kaldığı sayfadan devam eden bir bölüm vardı, ama `UploadClient` sunucunun yanıtını atıp hep 0 döndürüyordu. Yama sunucunun sayfa yanıtını (`payload`, dakika) saklar (`LimitText.rememberPause`) ve dönüş değeri yapar (`LimitText.withPause`). `UploadProcessor` bekleme sırasında gösterdiği "Sunucu bağlantısı tekrar kuruluyor" yazısı yerine saatlik sınır açıklamasını ve devam saatini yazar (`LimitText.pauseStart/pauseEnd`). Bekleme bir bölümün (ör. Kitap&Dergi) son sayfasında gelirse uygulama sayfa arasında beklemiyordu; yama bu beklemeyi sonraki bölümün başında uygular (`pendingWaitStart/End`). Seçilen 25/250/500 sınırı aynı sayfada dolarsa beklenmez. Sayıyı sunucu belirler: `api/nkapp` mağaza başına 1 saatte gelen kitapları sayar, panelde **Site Ayarları → Aktarım: saatte en fazla kitap** (varsayılan 1000) dolunca pencerenin kalan dakikasını döndürür. Eski sürümler bu sayıyı yok sayar.
- **1.4.7:** Her taranan sayfa günlüğe yazılır (`LimitText.pageLog`; ilk sayfa, her 5 sayfa, son sayfa ve yeni kitap gönderilen sayfalar): "Sayfa 12 / 180 tarandı — henüz yeni kitap yok (sitede olanlar atlanıyor)". Önceden sitede olan kitaplar atlanırken ekranda hiçbir şey değişmiyor, uygulama donmuş görünüyordu.
- **1.4.8:** NadirKitap liste sayfası açılamazsa (ör. HTTP 504, NadirKitap'ın kendi sunucusunda zaman aşımı) aktarım bitmiyordu: hata "Bilgi" penceresinde HTML olarak görünüp aktarım duruyordu. Artık `UploadProcessor`'ın liste sayfası çekimi `LimitText.fetchPage` üzerinden yapılır; G/Ç ve HTTP hatalarında 3, 10 ve 20 dakika bekleyip yeniden dener (Durdur düğmesi bekleme sırasında da çalışır). Yine de durursa pencere kısa bir Türkçe açıklama ve kaldığı sayfayı gösterir (`LimitText.shortError`).
- **1.4.9:** NadirKitap'tan açılan her liste sayfasındaki tüm ürün kodları (sitede olup atlananlar dahil), ürün türü (adreste `tip=efemera` varsa Sanat & Koleksiyon) ve toplam sayfa sayısıyla sunucuya bildirilir: `POST /pbatch/{mağaza}/seen/{BOOK|EPHEMERA}/{sayfa}` (`LimitText.fetchPage` → `reportSeen`). Boş 1. sayfa yalnızca NadirKitap arama sayfası olarak tanındıysa "bu türde ürün yok" diye bildirilir. Sunucu (`_nk_sync.php` → `nk_seen_candidates`) iki türün de 1..son sayfası son 48 saatte görüldüyse görünmeyen kitapları satıcıya listeler; bu yöntemle bulunanlar hiçbir zaman otomatik düşürülmez (satıcı onaylar). Bildirim başarısız olursa aktarım etkilenmez.
- **1.4.10:** İkinci filigran türü (`WmLogo`): eski ilanların 250 px genişlikteki görsellerinde altta ortada tek, büyük, yarı saydam beyaz "NADIRKITAP" logosu var; çapraz desen temizleyicisi bunu silemiyordu. Logonun piksel piksel saydamlığı (en çok ~0,42) beş örnek görselden çıkarılıp sınıfa gömüldü. `WmLogo.clean` logoyu alt kenara göre beklenen yerde ±3 px arar (yüksek geçiren benzerlik ≥ 0,30; örneklerde 0,44–0,89, logosuz 199 görselde en çok 0,14), bulursa beyaz katmanı geri alır (`(görsel − 255·a)/(1 − a)`), yakındaki logo dışı piksellerin aralığına sıkıştırır ve 3x3 ortanca uygular. `WmAlign.clean` önce bunu dener; logo bulunduysa çapraz desen temizleyicisi çalışmaz.
- `updateControlStates` içinde 500 ve Sınırsız düğmeleri her durum güncellemesinde yeniden kapatılıyordu. Artık 25/250 gibi yalnızca aktarım sürerken kapalı (`!idle`). 1.4.3'teki "düğmeler gri kalıyor" sorunu bu.

Yama, 1.4.2 dağıtımındaki `app/kit-app-1.4.2-small.jar` dosyasının derlenmiş sınıflarına uygulanır. Sınıflar byte-buddy içindeki ASM ile değiştirilir; uygulamanın kaynak kodu gerekmez. Değişen sınıflar:

- `NkUploaderApp`
- `NkUploaderApp$2`
- `NkUploaderApp$2$1`
- `UploadClient`
- `ImageUtil` (yalnızca `removeWatermarkBytes` içindeki çağrı)
- `UploadProcessor` (bekleme yazıları, sayfa günlüğü, liste sayfası yeniden denemesi, görülen kodların bildirimi)
- `NkUploaderApp$2` (aktarım hatası penceresi)

## Yeniden üretme

Aşağıdaki komutları `kit-app-1.4.2-small.jar` dosyasının açıldığı `cls/` klasörünün bir üstünde çalıştırın:

```bash
javac -encoding UTF-8 -cp byte-buddy-1.14.12.jar -d out Patch.java
```

```bash
java -cp byte-buddy-1.14.12.jar:out Patch cls
```

```bash
javac -encoding UTF-8 --release 17 -cp "cls:deps/*" -d cls com/netarti/nkapp/ui/LimitText.java com/netarti/nkapp/utility/WmAlign.java com/netarti/nkapp/utility/WmLogo.java
```

Ardından iki düzenleme yapın:

- `cls/application.properties` dosyasında `app.version=1.4.10` olsun.
- `app/*.cfg` dosyalarında jar adı `kit-app-1.4.10-small.jar`, `app.version` ve `jpackage.app-version` değerleri `1.4.10` olsun.

`deps/` klasörü 1.4.2 dağıtımındaki `app/deps` klasörüdür (WmAlign derlenirken gerekir).

Dağıtım: `downloads/kitapekapp-1.4.10-guncelleme.zip`. Bu paket, mevcut 1.4.2 - 1.4.9 klasörünün içine kopyalanır.

Sitede zaten olan kitapların fotoğrafları aktarımda yeniden çekilmez. Yeni temizliğin onlara da uygulanması için panelde **Disk Kullanımı → Görselleri yenile** düğmesine basılır; sonraki aktarımda fotoğraflar bir kez yeniden çekilir.
