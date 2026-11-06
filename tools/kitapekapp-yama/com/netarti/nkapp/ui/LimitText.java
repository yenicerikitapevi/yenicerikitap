package com.netarti.nkapp.ui;

/** Kitap sınırı yazıları: SINIRSIZ (100000) seçildiğinde sayı yerine "tüm kitaplar" yazılır. */
public final class LimitText {
    public static final int SINIRSIZ = 100000;
    private LimitText() {}

    static boolean unlimited(int n) { return n >= SINIRSIZ; }

    /** "Aktarım başlatılıyor... Hedef Limit: X Kitap | Alt Dükkan: S | NK Mağaza: C" */
    public static String startLog(int limit, String store, String code) {
        return "Aktarım başlatılıyor... Hedef Limit: " + (unlimited(limit) ? "Sınırsız (mağazadaki tüm kitaplar)" : limit + " Kitap")
            + " | Alt Dükkan: " + store + " | NK Mağaza: " + code;
    }
    /** Başlat düğmesi */
    public static String button(int limit) {
        return unlimited(limit) ? "▶ Tüm Kitapları Alt Dükkana Aktarmayı Başlat" : "▶ " + limit + " Kitabı Alt Dükkana Aktarmayı Başlat";
    }
    public static String ready(int limit) {
        return unlimited(limit) ? "Hazır. Giriş yaptıktan sonra mağazadaki tüm kitapların aktarımını başlatabilirsiniz."
            : "Hazır. Giriş yaptıktan sonra " + limit + " kitaplık aktarımı başlatabilirsiniz.";
    }
    public static String target(int limit) {
        return unlimited(limit) ? "Hedef çekim limiti: Sınırsız (mağazadaki tüm kitaplar) olarak ayarlandı."
            : "Hedef çekim limiti: " + limit + " Kitap olarak ayarlandı.";
    }
    public static String done(int limit, int count, String store) {
        return unlimited(limit) ? "Aktarım tamamlandı! Toplam " + count + " adet ürün '" + store + "' alt dükkanına eklendi."
            : "Aktarım tamamlandı! Seçilen " + limit + " kitap limiti doğrultusunda toplam " + count + " adet ürün '" + store + "' alt dükkanına eklendi.";
    }
    public static String withLimit(String s, int limit) {
        return unlimited(limit) ? s + " (Limit: Sınırsız)" : s + " (Limit: " + limit + " Kitap)";
    }
    /** "Toplam: N / 250" ekindeki "/ 250" kısmı */
    public static String slash(int limit) {
        return unlimited(limit) ? "" : " / " + limit;
    }
    /** İlerleme çubuğu: sınırsızda yüklenen sayısına göre yavaşça dolar */
    public static double ratio(double uploaded, double limit) {
        return limit >= SINIRSIZ ? uploaded / (uploaded + 150.0) : uploaded / limit;
    }

    // --- Saatlik aktarım sınırı (1.4.6) ---
    private static volatile int pendingPause = 0;

    /** Sunucunun sayfa yanıtı: bekle (dakika). Sayı değilse 0. */
    public static void rememberPause(Object payload) {
        int m = 0;
        if (payload instanceof Number) m = ((Number) payload).intValue();
        pendingPause = (m > 0 && m <= 24 * 60) ? m : 0;
    }
    /** UploadClient sayfa gönderiminin dönüşü: uygulamanın kendi değeri yerine sunucunun bekleme süresi */
    public static Integer withPause(Integer original) {
        int m = pendingPause;
        pendingPause = 0;
        if (m > 0) return m;
        return original;
    }
    public static String pauseStart(Integer minutes) {
        int m = minutes == null ? 0 : minutes;
        java.time.LocalTime t = java.time.LocalTime.now().plusMinutes(m);
        return String.format("Saatlik aktarım sınırı doldu (NadirKitap engellemesin diye). %d dakika beklenecek; "
            + "saat %02d:%02d civarında kaldığı yerden kendiliğinden devam edecek. Uygulamayı kapatmayın.", m, t.getHour(), t.getMinute());
    }
    public static String pauseEnd() {
        return "Bekleme bitti, aktarım kaldığı yerden devam ediyor.";
    }
}
