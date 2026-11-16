package com.netarti.nkapp.utility;

import java.awt.image.BufferedImage;
import java.lang.reflect.Field;
import java.lang.reflect.Method;

/**
 * Çapraz "NadirKitap" deseni, sabit boyutlu yerleşim (1.4.12).
 * Uygulamanın temizleyicisi damga konumlarını ve şablonu görsel boyutuyla ölçekliyor (w/412, h/550). Oysa NadirKitap'ın
 * büyük görsellerinde (ör. 500x667) desen ÖLÇEKLENMİYOR: şablon 1:1 boyutta, konumlar üst kenara ve SAĞ kenara göre sabit
 * (x = STAMP_POS.x + (w − 412), y = STAMP_POS.y). Yatay görsellerde aynı desen 90° saat yönünün tersine döndürülmüş.
 * Bu sınıf o yerleşimi arar (8 damganın en iyi 4'ünün ortalama benzerliği); bulursa uygulamanın kendi temizleyicisini
 * 412x550'lik 1:1 tuvaller üzerinde çalıştırıp sonucu geri yapıştırır. Bulamazsa null döner (eski ölçekli yol denenir).
 */
public final class WmTile {
    static final int SEARCH = 4;
    static final double MIN_TOP4 = 0.25;
    public static volatile double lastScore = 0;
    private WmTile() {}

    private static Object get(String n) throws Exception {
        Field f = ImageUtil.class.getDeclaredField(n); f.setAccessible(true); return f.get(null);
    }

    public static BufferedImage clean(BufferedImage img) {
        try {
            lastScore = 0;
            boolean landscape = img.getWidth() > img.getHeight();
            BufferedImage p = landscape ? rotate(img, true) : img;
            BufferedImage r = cleanPortrait(p);
            if (r == null) return null;
            return landscape ? rotate(r, false) : r;
        } catch (Throwable t) {
            return null;
        }
    }

    /** cw=true: 90° saat yönünde; false: saat yönünün tersine */
    static BufferedImage rotate(BufferedImage s, boolean cw) {
        int w = s.getWidth(), h = s.getHeight();
        BufferedImage o = new BufferedImage(h, w, BufferedImage.TYPE_INT_RGB);
        for (int y = 0; y < h; y++) for (int x = 0; x < w; x++) {
            if (cw) o.setRGB(h - 1 - y, x, s.getRGB(x, y)); else o.setRGB(y, w - 1 - x, s.getRGB(x, y));
        }
        return o;
    }

    private static BufferedImage cleanPortrait(BufferedImage img) throws Exception {
        synchronized (ImageUtil.class) {
            Method load = ImageUtil.class.getDeclaredMethod("loadWatermarkMask");
            load.setAccessible(true);
            load.invoke(null);
            if (!(boolean) get("maskLoaded")) return null;
            final int MW = (int) get("MASK_W"), MH = (int) get("MASK_H"), PW = (int) get("PW"), PH = (int) get("PH");
            int[][] pos = (int[][]) get("STAMP_POS");
            boolean[][] rib = (boolean[][]) get("ribbonMask");
            float[][] tpl = (float[][]) get("pooledDeltaMid");
            int w = img.getWidth(), h = img.getHeight();
            if (w < 300 || h < 340) return null;
            int shift = w - MW;                       // sağ kenara dayalı
            float[][] hp = WmAlign.highPass(img, w, h);

            // Şablon: şerit içinde ortalaması sıfır
            double tm = 0; int tn = 0;
            for (int y = 0; y < PH; y++) for (int x = 0; x < PW; x++) if (rib[y][x]) { tm += tpl[y][x]; tn++; }
            tm /= Math.max(1, tn);
            double tt = 0;
            for (int y = 0; y < PH; y++) for (int x = 0; x < PW; x++) if (rib[y][x]) { double v = tpl[y][x] - tm; tt += v * v; }

            double best = -9; int bdx = 0, bdy = 0;
            double[] sc = new double[pos.length];
            for (int dy = -SEARCH; dy <= SEARCH; dy++) for (int dx = -SEARCH; dx <= SEARCH; dx++) {
                for (int i = 0; i < pos.length; i++) sc[i] = ncc(hp, rib, tpl, tm, tt, pos[i][0] + shift + dx, pos[i][1] + dy, PW, PH, w, h);
                double[] s = sc.clone(); java.util.Arrays.sort(s);
                double top4 = (s[s.length - 1] + s[s.length - 2] + s[s.length - 3] + s[s.length - 4]) / 4;
                if (top4 > best) { best = top4; bdx = dx; bdy = dy; }
            }
            lastScore = best;
            if (best < MIN_TOP4) return null;

            BufferedImage out = new BufferedImage(w, h, BufferedImage.TYPE_INT_RGB);
            out.getGraphics().drawImage(img, 0, 0, null);
            int[][] saved = new int[pos.length][];
            for (int i = 0; i < pos.length; i++) saved[i] = pos[i].clone();
            try {
                // Her damga ayrı bir 412x550 tuvalde, tuvalin (CX, CY) noktasına gelecek şekilde temizlenir:
                // tuval görselle aynı boyutta olduğundan uygulamanın temizleyicisi ölçek uygulamaz (1:1).
                final int CX = 160, CY = 200;
                for (int i = 0; i < pos.length; i++) {
                    int sx = saved[i][0] + shift + bdx, sy = saved[i][1] + bdy;   // damganın görseldeki yeri
                    if (sx + PW <= 0 || sy + PH <= 0 || sx >= w || sy >= h) continue;
                    int ox = sx - CX, oy = sy - CY;                                 // tuvalin görseldeki sol üst köşesi
                    BufferedImage canvas = new BufferedImage(MW, MH, BufferedImage.TYPE_INT_RGB);
                    for (int y = 0; y < MH; y++) { int yy = Math.max(0, Math.min(h - 1, oy + y));
                        for (int x = 0; x < MW; x++) canvas.setRGB(x, y, img.getRGB(Math.max(0, Math.min(w - 1, ox + x)), yy)); }
                    for (int j = 0; j < pos.length; j++) { pos[j][0] = -100000; pos[j][1] = -100000; }
                    pos[i][0] = CX; pos[i][1] = CY;
                    BufferedImage cleaned = ImageUtil.cleanWatermark(canvas);
                    for (int y = 0; y < PH; y++) { int yy = sy + y; if (yy < 0 || yy >= h) continue;
                        for (int x = 0; x < PW; x++) { int xx = sx + x; if (xx < 0 || xx >= w) continue;
                            out.setRGB(xx, yy, cleaned.getRGB(CX + x, CY + y)); } }
                }
            } finally {
                for (int i = 0; i < pos.length; i++) { pos[i][0] = saved[i][0]; pos[i][1] = saved[i][1]; }
            }
            return out;
        }
    }

    static double ncc(float[][] hp, boolean[][] rib, float[][] tpl, double tm, double tt, int ox, int oy, int PW, int PH, int w, int h) {
        double s = 0, ll = 0; int n = 0;
        for (int y = 0; y < PH; y++) { int yy = oy + y; if (yy < 0 || yy >= h) continue;
            for (int x = 0; x < PW; x++) { int xx = ox + x; if (xx < 0 || xx >= w || !rib[y][x]) continue;
                double v = hp[yy][xx]; s += v * (tpl[y][x] - tm); ll += v * v; n++; } }
        if (n < 200) return 0;   // damganın çoğu görselin dışında
        return s / Math.sqrt(ll * tt + 1e-9);
    }
}
