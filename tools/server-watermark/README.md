# Sunucuda görsel temizliği

Yönetim paneli → **Görsel Temizliği** (`/admin/index.php?p=Watermarks`). Bu ekran, siteye önceden yüklenmiş kapakları ve `extra_images` içindeki yerel fotoğrafları işler. Bilgisayara indirme veya üçüncü taraf görüntü API'si gerekmez.

## Kurulum

Dockerfile `downloads/` klasöründeki en yeni `kitapekapp-*-guncelleme.zip` paketindeki temizleyiciyi kullanır (sürüm adı sabit yazılmaz; uygulama güncellendiğinde sunucu temizleyicisi de güncellenir). Derleme aşaması Java 17 ile küçük bir CLI adaptörü derler; çalışma imajına Java, SLF4J ve Commons Lang eklenir. Paket içindeki `nk_wm_mask.dat` ayrıca indirilmez. Docker imajını yeniden derleyip dağıtmak gerekir; yalnızca PHP dosyalarını kopyalamak yeterli değildir.

Render'ın mevcut `/var/data` diskiyle varsayılan yedek dizini `/var/data/watermark-jobs` olur. Diğer kurulumlarda `WATERMARK_DATA_DIR` web kökü **dışındaki kalıcı ve PHP tarafından yazılabilen** bir dizine ayarlanmalıdır. Araç, web kökü içindeki yedek dizinini reddeder. Sunucuda `proc_open`, PDO SQLite/MySQL ve GD açık olmalıdır. SQLite varsayılanında kuyruk tabloları mevcut uygulama veritabanında oluşturulur; MySQL'de tabloların InnoDB kullanması gerekir.

İsteğe bağlı ortam değişkenleri: `WATERMARK_JAVA` (varsayılan `java`), `WATERMARK_CLASSPATH` (varsayılan `/opt/watermark/*:/usr/share/java/*`). Bunlar yönetici tarafından sunucu yapılandırmasından verilir, HTTP isteklerinden alınmaz.

## Kullanım

1. **Görselleri tara ve yeni iş oluştur**: Aynı dosyanın farklı kitaplardaki kullanımları tek kayıtta toplanır. Harici URL'ler, desteklenmeyen dosyalar ve önceki temiz çıktılar kuyruğa alınmaz. Kapaklar ve ek fotoğraflar dahildir; dükkân logoları kapsam dışıdır.
2. **6 örnek hazırla**: İlk altı bekleyen dosyayı işler. Önce/sonra görüntülerini kontrol edin. Bu adım canlı katalogdaki görselleri değiştirmez.
3. **Tüm önizlemeleri hazırla**: Kalan dosyaları işler; her istek sunucuda ~20 saniyede birkaç görsel (en çok 50) işler. Sayfa açık kaldığı sürece ilerler; Duraklat veya sayfayı kapatma sonrasında yeniden devam edilebilir. Sunucu arka plan servisi/cron değildir.
4. Tek bir görseli veya **Hazır kopyaları yayımla** ile tüm hazır görselleri yayımlayın. Her görselin referansları ayrı veritabanı işleminde değiştirilir. Toplu yayımlama tüm hazır kopyaları kapsar; tüm dosyaları insanın tek tek onayladığı anlamına gelmez.
5. Tek görseli veya bu işte yayımlanmış tüm görselleri geri alabilirsiniz. Sonradan değiştirilmiş kitap referansları varsa ilgili görselin geri alınması durur ve hata gösterilir; diğer görseller ilerler. Sorun giderilince **Hatayı giderdim, yeniden dene** ile hata işaretini temizleyin ve ilgili adıma devam edin.

Temiz kopyalar `wmclean_…jpg` adıyla (JPEG, kalite 0,92) kaydedilir; eski dosyaya yazılmaz. Böylece `cover.php`'nin bir yıllık immutable önbelleğine rağmen yeni URL kullanılır. Orijinalin doğrulanmış yedeği ve referans değişiklikleri tutulur. Yayımlanınca önizleme silinir; eski dosya hiçbir kitapta kalmadıysa yükleme klasöründen silinir (geri almada yedekten geri yazılır). Geri alınınca artık kullanılmayan temiz kopya silinir. Orijinal yedekleri (görsel başına kaynak boyutu kadar) otomatik silinmez; disk boyutunu takip edin. Hazırlık, diskte 300 MB'tan az yer kalınca durur (veritabanı yazabilsin).

## Kalite ve sınırlar

Mevcut `WmLogo` ve `WmTile` kullanılır. `WmAlign` içindeki koşulsuz temizleme yolu kullanılmaz: güvenilir eşleşme bulunmazsa dosya **atlanır**. Kısmen temizlenmiş veya farklı boyut/yerleşimdeki filigranlar bu nedenle kalabilir. Bu sistem kusursuz restorasyon vadetmez. Çıktı JPEG'dir (PNG kaynağın ~10 katı yer kaplıyordu). JPEG, PNG ve WebP kabul edilir; üst sınır 2 milyon piksel ve 16 MB, işlem başına 25 saniye ve Java heap sınırı 128 MB'dır. WebP giriş GD ile yerel geçici PNG'ye dönüştürülür. Fotoğraf işleyen eski temizleyici RGB çıktı verir; saydam grafikler için tasarlanmamıştır.

İşler aynı sunucuda dosya kilidiyle sıraya girer. Çok sunuculu kurulumlarda ortak kalıcı disk ve ortak kilitleme gerekir. Uygulamadaki diğer görsel değiştirme/aktarma işlemlerini temizlik sırasında çalıştırmamak uygundur; referans değişiklikleri karşılaştırılarak çatışmalar engellenir. Dosya depolaması ve veritabanı arasında dağıtık işlem yoktur: yayımlama öncesi kesintide referanssız temiz kopya kalabilir; yeniden deneme yeni ad kullanır, artık dosyalar mevcut disk temizliğiyle kaldırılabilir.

## Doğrulama

```sh
php tests/watermark_test.php
javac -encoding UTF-8 --release 17 -cp /opt/watermark/cleaner.jar:/opt/watermark/runner.jar -d /tmp/wm-tests tests/WatermarkCliTest.java
java -Djava.awt.headless=true -Xmx128m -cp '/tmp/wm-tests:/opt/watermark/*:/usr/share/java/*' WatermarkCliTest /tmp/wm-fixtures
```

PHP testine Java yolu/classpath ortam değişkenleri verilirse gerçek temizleyiciyle filigransız örneğin atlandığı da doğrulanır. Testler sentetik örnek kullanır. Gerçek katalogda başarı oranı ve görsel kalite, dağıtımdan sonra örnek önizlemelerde kontrol edilmelidir.
