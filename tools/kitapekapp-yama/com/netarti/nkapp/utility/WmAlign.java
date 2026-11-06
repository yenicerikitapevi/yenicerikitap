package com.netarti.nkapp.utility;

import java.awt.image.BufferedImage;
import java.lang.reflect.Field;
import java.lang.reflect.Method;

/**
 * Filigran şablonunu her damga için ±R piksel içinde fotoğrafa en iyi oturduğu yere hizalar,
 * sonra uygulamanın kendi cleanWatermark yöntemini hizalanmış konumlarla çalıştırır.
 * (1-2 piksel kayma, temizlenen yerde "kabartma" gibi açık/koyu iz bırakıyordu.)
 */
public final class WmAlign {
    static final int R = 6;
    private WmAlign() {}

    private static Object get(String n) throws Exception {
        Field f = ImageUtil.class.getDeclaredField(n); f.setAccessible(true); return f.get(null);
    }

    public static BufferedImage clean(BufferedImage img) {
        try {
            synchronized (ImageUtil.class) {
                Method load = ImageUtil.class.getDeclaredMethod("loadWatermarkMask");
                load.setAccessible(true);
                load.invoke(null);
                if (!(boolean) get("maskLoaded")) return ImageUtil.cleanWatermark(img);
                int MW = (int) get("MASK_W"), MH = (int) get("MASK_H"), PW = (int) get("PW"), PH = (int) get("PH");
                int[][] pos = (int[][]) get("STAMP_POS");
                boolean[][] rib = (boolean[][]) get("ribbonMask");
                float[][] tpl = (float[][]) get("pooledDeltaMid");
                int w = img.getWidth(), h = img.getHeight();
                float[][] hp = highPass(img, w, h);
                int[][] saved = new int[pos.length][];
                for (int i = 0; i < pos.length; i++) saved[i] = pos[i].clone();
                try {
                    for (int[] p : pos) {
                        int ox = Math.round(p[0] * (w / (float) MW)), oy = Math.round(p[1] * (h / (float) MH));
                        double best = score(hp, rib, tpl, ox, oy, PW, PH, w, h); int bx = 0, by = 0;
                        for (int dy = -R; dy <= R; dy++) for (int dx = -R; dx <= R; dx++) {
                            double s = score(hp, rib, tpl, ox + dx, oy + dy, PW, PH, w, h);
                            if (s > best * 1.0001 + 1e-9) { best = s; bx = dx; by = dy; }
                        }
                        p[0] = toMask(ox + bx, w, MW, p[0]);
                        p[1] = toMask(oy + by, h, MH, p[1]);
                    }
                    return ImageUtil.cleanWatermark(img);
                } finally {
                    for (int i = 0; i < pos.length; i++) { pos[i][0] = saved[i][0]; pos[i][1] = saved[i][1]; }
                }
            }
        } catch (Throwable t) {
            return ImageUtil.cleanWatermark(img);
        }
    }

    /** Görüntü koordinatındaki hedefe en yakın düşen maske koordinatı (cleanWatermark aynı yuvarlamayı yapar) */
    static int toMask(int target, int size, int msize, int fallback) {
        float k = size / (float) msize;
        int p = Math.round(target / k), bestP = fallback, bestD = Integer.MAX_VALUE;
        for (int q = p - 2; q <= p + 2; q++) {
            int d = Math.abs(Math.round(q * k) - target);
            if (d < bestD) { bestD = d; bestP = q; }
        }
        return bestP;
    }

    static double score(float[][] hp, boolean[][] rib, float[][] tpl, int ox, int oy, int PW, int PH, int w, int h) {
        double s = 0;
        for (int y = 0; y < PH; y++) {
            int yy = oy + y; if (yy < 0 || yy >= h) continue;
            for (int x = 0; x < PW; x++) {
                int xx = ox + x; if (xx < 0 || xx >= w || !rib[y][x]) continue;
                s += tpl[y][x] * hp[yy][xx];
            }
        }
        return s;
    }

    /** Parlaklık − 7x7 ortalama (filigran harflerinin ince yapısını öne çıkarır) */
    static float[][] highPass(BufferedImage img, int w, int h) {
        float[][] l = new float[h][w];
        for (int y = 0; y < h; y++) for (int x = 0; x < w; x++) {
            int c = img.getRGB(x, y);
            l[y][x] = 0.299f * ((c >> 16) & 255) + 0.587f * ((c >> 8) & 255) + 0.114f * (c & 255);
        }
        double[][] I = new double[h + 1][w + 1];
        for (int y = 0; y < h; y++) for (int x = 0; x < w; x++) I[y + 1][x + 1] = l[y][x] + I[y][x + 1] + I[y + 1][x] - I[y][x];
        float[][] hp = new float[h][w];
        int r = 3;
        for (int y = 0; y < h; y++) for (int x = 0; x < w; x++) {
            int y0 = Math.max(0, y - r), y1 = Math.min(h, y + r + 1), x0 = Math.max(0, x - r), x1 = Math.min(w, x + r + 1);
            double m = (I[y1][x1] - I[y0][x1] - I[y1][x0] + I[y0][x0]) / ((y1 - y0) * (x1 - x0));
            hp[y][x] = (float) (l[y][x] - m);
        }
        return hp;
    }
}
