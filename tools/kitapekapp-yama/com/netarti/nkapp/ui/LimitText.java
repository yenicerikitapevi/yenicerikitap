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
    /** Sunucunun istediği beklemenin bittiği an (ms); uygulama sayfa arasında bekleyemediyse sonraki bölümün başında beklenir */
    private static volatile long pauseUntil = 0;
    private static volatile boolean waitedAtStage = false;

    /** Sunucunun sayfa yanıtı: bekle (dakika). Sayı değilse 0. */
    public static void rememberPause(Object payload) {
        int m = 0;
        if (payload instanceof Number) m = ((Number) payload).intValue();
        pendingPause = (m > 0 && m <= 24 * 60) ? m : 0;
        if (pendingPause > 0) pauseUntil = System.currentTimeMillis() + pendingPause * 60_000L;
    }
    /** UploadClient sayfa gönderiminin dönüşü: uygulamanın kendi değeri yerine sunucunun bekleme süresi */
    public static Integer withPause(Integer original, boolean limitReached, int uploadedSoFar) {
        uploadedTotal = uploadedSoFar;
        int m = pendingPause;
        pendingPause = 0;
        if (limitReached) { pauseUntil = 0; return original; } // seçilen kitap sayısı doldu, aktarım bitiyor
        if (m > 0) return m;
        return original;
    }
    /** Sayfa arasında bekleme başlıyor (uygulamanın kendi beklemesi) */
    public static String pauseStart(Integer minutes) {
        pauseUntil = 0;
        int m = minutes == null ? 0 : minutes;
        java.time.LocalTime t = java.time.LocalTime.now().plusMinutes(m);
        return String.format("Saatlik aktarım sınırı doldu (NadirKitap engellemesin diye). %d dakika beklenecek; "
            + "saat %02d:%02d civarında kaldığı yerden kendiliğinden devam edecek. Uygulamayı kapatmayın.", m, t.getHour(), t.getMinute());
    }
    public static String pauseEnd() {
        return "Bekleme bitti, aktarım kaldığı yerden devam ediyor.";
    }
    /** Bölüm başı: önceki bölümün son sayfasında gelen bekleme hâlâ sürüyorsa kalan süre (ms), yoksa 0 */
    public static long pendingWaitStart(com.netarti.nkapp.ui.ProgressSink sink) {
        long left = pauseUntil - System.currentTimeMillis();
        pauseUntil = 0;
        waitedAtStage = left > 0;
        if (left <= 0) return 0;
        int m = (int) Math.ceil(left / 60_000.0);
        try { if (sink != null) sink.b(pauseStart(m)); } catch (Throwable ignored) {}
        return left;
    }
    public static void pendingWaitEnd(com.netarti.nkapp.ui.ProgressSink sink) {
        if (!waitedAtStage) return;
        waitedAtStage = false;
        try { if (sink != null) sink.b(pauseEnd()); } catch (Throwable ignored) {}
    }

    // --- Sayfa günlüğü (1.4.7) ---
    private static volatile int lastUploadedSeen = 0;
    private static volatile int uploadedTotal = 0;

    /** Her sayfa işlendikten sonra: "Sayfa 12 / 180 tarandı" (ilk sayfada ve her 5 sayfada bir ya da yeni kitap gönderildiyse) */
    public static void pageLog(ProgressSink sink, int page, Integer lastPage) {
        try {
            int total = lastPage == null ? 0 : lastPage;
            int up = uploadedTotal;
            boolean news = up != lastUploadedSeen;
            lastUploadedSeen = up;
            if (!(page <= 1 || page % 5 == 0 || page >= total || news)) return;
            String s = "Sayfa " + page + (total > 0 ? " / " + total : "") + " tarandı"
                + (up > 0 ? " — bu aktarımda gönderilen yeni kitap: " + up : " — henüz yeni kitap yok (sitede olanlar atlanıyor)");
            if (sink != null) sink.b(s);
        } catch (Throwable ignored) {}
    }
}
