# kitapekapp yaması (1.4.5)

Aktarım uygulamasındaki kitap sınırı değiştirildi. 1.4.5 sürümünde:

- **500** seçeneği açık.
- **1000** seçeneğinin yerine **Sınırsız** geldi. Sınırsız'ın değeri 100000'dir, yani pratikte mağazadaki tüm kitaplar aktarılır.
- Ekrandaki yazılar Sınırsız seçildiğinde sayı yerine "tüm kitaplar" diyor (`LimitText`).
- İlerleme çubuğu Sınırsız'da da ilerliyor (`LimitText.ratio`).
- **1.4.5:** Filigran temizliği iyileşti (`WmAlign`). Uygulamanın kendi temizleyicisi NadirKitap filigran şablonunu her görselde sabit konumlara koyuyordu; fotoğraf 1-2 piksel kaymışsa harflerin yerinde açık/koyu "kabartma" izi kalıyordu. `WmAlign`, her damgayı ±6 piksel içinde fotoğraftaki filigranla en iyi örtüştüğü yere hizalar, sonra uygulamanın temizleyicisini bu konumlarla çalıştırır. `ImageUtil.removeWatermarkBytes` artık `cleanWatermark` yerine `WmAlign.clean` çağırır. Bir hata olursa eski temizleyiciye düşer.
- `updateControlStates` içinde 500 ve Sınırsız düğmeleri her durum güncellemesinde yeniden kapatılıyordu. Artık 25/250 gibi yalnızca aktarım sürerken kapalı (`!idle`). 1.4.3'teki "düğmeler gri kalıyor" sorunu bu.

Yama, 1.4.2 dağıtımındaki `app/kit-app-1.4.2-small.jar` dosyasının derlenmiş sınıflarına uygulanır. Sınıflar byte-buddy içindeki ASM ile değiştirilir; uygulamanın kaynak kodu gerekmez. Değişen sınıflar:

- `NkUploaderApp`
- `NkUploaderApp$2`
- `NkUploaderApp$2$1`
- `UploadClient`
- `ImageUtil` (yalnızca `removeWatermarkBytes` içindeki çağrı)

## Yeniden üretme

Aşağıdaki komutları `kit-app-1.4.2-small.jar` dosyasının açıldığı `cls/` klasörünün bir üstünde çalıştırın:

```bash
javac -encoding UTF-8 -cp byte-buddy-1.14.12.jar -d out Patch.java
```

```bash
java -cp byte-buddy-1.14.12.jar:out Patch cls
```

```bash
javac -encoding UTF-8 --release 17 -cp "cls:deps/*" -d cls com/netarti/nkapp/ui/LimitText.java com/netarti/nkapp/utility/WmAlign.java
```

Ardından iki düzenleme yapın:

- `cls/application.properties` dosyasında `app.version=1.4.5` olsun.
- `app/*.cfg` dosyalarında jar adı `kit-app-1.4.5-small.jar`, `app.version` ve `jpackage.app-version` değerleri `1.4.5` olsun.

`deps/` klasörü 1.4.2 dağıtımındaki `app/deps` klasörüdür (WmAlign derlenirken gerekir).

Dağıtım: `downloads/kitapekapp-1.4.5-guncelleme.zip`. Bu paket, mevcut 1.4.2, 1.4.3 ya da 1.4.4 klasörünün içine kopyalanır.

Sitede zaten olan kitapların fotoğrafları aktarımda yeniden çekilmez. Yeni temizliğin onlara da uygulanması için panelde **Disk Kullanımı → Görselleri yenile** düğmesine basılır; sonraki aktarımda fotoğraflar bir kez yeniden çekilir.
