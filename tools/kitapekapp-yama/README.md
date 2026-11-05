# kitapekapp yaması (1.4.4)

Aktarım uygulamasındaki kitap sınırı değiştirildi. 1.4.4 sürümünde:

- **500** seçeneği açık.
- **1000** seçeneğinin yerine **Sınırsız** geldi. Sınırsız'ın değeri 100000'dir, yani pratikte mağazadaki tüm kitaplar aktarılır.
- Ekrandaki yazılar Sınırsız seçildiğinde sayı yerine "tüm kitaplar" diyor (`LimitText`).
- İlerleme çubuğu Sınırsız'da da ilerliyor (`LimitText.ratio`).
- `updateControlStates` içinde 500 ve Sınırsız düğmeleri her durum güncellemesinde yeniden kapatılıyordu. Artık 25/250 gibi yalnızca aktarım sürerken kapalı (`!idle`). 1.4.3'teki "düğmeler gri kalıyor" sorunu bu.

Yama, 1.4.2 dağıtımındaki `app/kit-app-1.4.2-small.jar` dosyasının derlenmiş sınıflarına uygulanır. Sınıflar byte-buddy içindeki ASM ile değiştirilir; uygulamanın kaynak kodu gerekmez. Değişen sınıflar:

- `NkUploaderApp`
- `NkUploaderApp$2`
- `NkUploaderApp$2$1`
- `UploadClient`

## Yeniden üretme

Aşağıdaki komutları `kit-app-1.4.2-small.jar` dosyasının açıldığı `cls/` klasörünün bir üstünde çalıştırın:

```bash
javac -encoding UTF-8 -cp byte-buddy-1.14.12.jar -d out Patch.java
```

```bash
java -cp byte-buddy-1.14.12.jar:out Patch cls
```

```bash
javac -encoding UTF-8 --release 17 -d cls com/netarti/nkapp/ui/LimitText.java
```

Ardından iki düzenleme yapın:

- `cls/application.properties` dosyasında `app.version=1.4.4` olsun.
- `app/*.cfg` dosyalarında jar adı `kit-app-1.4.4-small.jar`, `app.version` ve `jpackage.app-version` değerleri `1.4.4` olsun.

Dağıtım: `downloads/kitapekapp-1.4.4-guncelleme.zip`. Bu paket, mevcut 1.4.2 ya da 1.4.3 klasörünün içine kopyalanır.
