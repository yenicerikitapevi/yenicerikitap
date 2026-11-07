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
        lastDonePage = 0;
        lastTotalPages = 0;
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
            lastDonePage = page;
            lastTotalPages = total;
            int up = uploadedTotal;
            boolean news = up != lastUploadedSeen;
            lastUploadedSeen = up;
            if (!(page <= 1 || page % 5 == 0 || page >= total || news)) return;
            String s = "Sayfa " + page + (total > 0 ? " / " + total : "") + " tarandı"
                + (up > 0 ? " — bu aktarımda gönderilen yeni kitap: " + up : " — henüz yeni kitap yok (sitede olanlar atlanıyor)");
            if (sink != null) sink.b(s);
        } catch (Throwable ignored) {}
    }

    // --- Liste sayfası yeniden deneme ve kısa hata mesajı (1.4.8) ---
    private static volatile int lastDonePage = 0;
    private static volatile int lastTotalPages = 0;
    private static final long[] RETRY_WAIT_MIN = {3, 10, 20};

    /** UploadProcessor'ın liste sayfası çekimi (private a(String,int)); IO/HTTP hatasında bekleyip en fazla 3 kez daha dener */
    public static Object fetchPage(Object processor, String url, int page, ProgressSink sink) throws Throwable {
        java.lang.reflect.Method m = processor.getClass().getDeclaredMethod("a", String.class, int.class);
        m.setAccessible(true);
        for (int i = 0; ; i++) {
            try {
                return m.invoke(processor, url, page);
            } catch (java.lang.reflect.InvocationTargetException e) {
                Throwable c = e.getCause() == null ? e : e.getCause();
                if (i >= RETRY_WAIT_MIN.length || !retryable(c) || Thread.currentThread().isInterrupted()) throw c;
                long min = RETRY_WAIT_MIN[i];
                java.time.LocalTime t = java.time.LocalTime.now().plusMinutes(min);
                try {
                    if (sink != null) sink.b(String.format("%d. sayfa açılamadı (%s). %d dakika sonra (saat %02d:%02d) yeniden denenecek, aktarım devam edecek (deneme %d/%d).",
                        page, reason(c), min, t.getHour(), t.getMinute(), i + 1, RETRY_WAIT_MIN.length));
                } catch (Throwable ignored) {}
                try {
                    Thread.sleep(min * Long.getLong("kitapekapp.retryUnitMs", 60_000L));
                } catch (InterruptedException ie) {
                    Thread.currentThread().interrupt();
                    throw new com.netarti.nkapp.exception.UserCancelledException("Aktarım kullanıcı tarafından durduruldu.");
                }
            }
        }
    }

    static boolean retryable(Throwable c) {
        for (Throwable x = c; x != null; x = x.getCause()) {
            if (x instanceof RuntimeException && x.getClass().getSimpleName().contains("Cancelled")) return false;
            if (x.getClass().getSimpleName().contains("SessionExpired")) return false;
            if (x instanceof java.io.IOException) return true;
            if (x.getCause() == x) break;
        }
        return false;
    }

    static String reason(Throwable c) {
        String msg = c.getMessage() == null ? "" : c.getMessage();
        java.util.regex.Matcher mm = java.util.regex.Pattern.compile("code=(\\d+)").matcher(msg);
        if (mm.find()) return "NadirKitap HTTP " + mm.group(1);
        return "bağlantı hatası";
    }

    /** Aktarım durduğunda gösterilen mesaj: HTML ve uzun teknik ayrıntı yerine kısa açıklama + kaldığı sayfa */
    public static String shortError(String msg) {
        try {
            if (msg == null) msg = "Bilinmeyen hata";
            String base;
            java.util.regex.Matcher mm = java.util.regex.Pattern.compile("HTTP failed code=(\\d+)").matcher(msg);
            if (mm.find()) {
                int code = Integer.parseInt(mm.group(1));
                if (code == 429 || code == 403) base = "NadirKitap erişimi geçici olarak sınırladı (HTTP " + code + "). 1-2 saat bekleyip yeniden deneyin.";
                else if (code >= 500) base = "NadirKitap sunucusu yanıt vermedi (HTTP " + code + "). Bu NadirKitap'taki geçici bir yoğunluk; uygulama birkaç kez bekleyip yeniden denedi.";
                else base = "NadirKitap sayfası açılamadı (HTTP " + code + ").";
            } else {
                base = msg.replaceAll("<[^>]*>", " ").replaceAll("\\s+", " ").trim();
                if (base.length() > 250) base = base.substring(0, 250) + "...";
            }
            int done = lastDonePage, total = lastTotalPages;
            if (done > 0) {
                base += "\n\nAktarım " + done + ". sayfadan sonra durdu; o ana kadar gönderilen kitaplar sitede kayıtlı. "
                    + "Kaldığınız yerden devam etmek için \"Sayfa\" seçeneğine " + (done + 1) + (total > 0 ? " ve " + total : "") + " yazıp aktarımı yeniden başlatın.";
            }
            return base;
        } catch (Throwable t) {
            return msg;
        }
    }
}
