import java.awt.image.BufferedImage;
import java.io.File;
import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.util.Arrays;
import javax.imageio.ImageIO;
import com.netarti.nkapp.utility.WmTile;
import com.netarti.nkapp.utility.WmLogo;

/** Synthetic fixtures only: exercises the exact mask shipped in the existing package. */
public final class WatermarkCliTest {
    static Class<?> util;
    static Object get(String name) throws Exception {
        Field f = util.getDeclaredField(name); f.setAccessible(true); return f.get(null);
    }
    public static void main(String[] args) throws Exception {
        util = Class.forName("com.netarti.nkapp.utility.ImageUtil");
        Method load = util.getDeclaredMethod("loadWatermarkMask"); load.setAccessible(true); load.invoke(null);
        if (!(boolean)get("maskLoaded")) throw new AssertionError("mask missing");
        int pw = (int)get("PW"), ph = (int)get("PH"), mw = (int)get("MASK_W");
        int[][] positions = (int[][])get("STAMP_POS");
        float[][] delta = (float[][])get("pooledDeltaMid");
        BufferedImage blank = new BufferedImage(500, 667, BufferedImage.TYPE_INT_RGB);
        int[] pixels = new int[500 * 667]; Arrays.fill(pixels, 0x808080); blank.setRGB(0, 0, 500, 667, pixels, 0, 500);
        File dir = new File(args[0]); dir.mkdirs();
        File input = new File(dir, "blank.png"), output = new File(dir, "blank-out.png");
        ImageIO.write(blank, "png", input); output.delete();
        WatermarkCli.main(new String[]{input.toString(), output.toString()});
        if (output.exists()) throw new AssertionError("unmarked image edited");
        BufferedImage marked = new BufferedImage(500, 667, BufferedImage.TYPE_INT_RGB);
        marked.setRGB(0, 0, 500, 667, pixels, 0, 500);
        for (int[] p : positions) for (int y = 0; y < ph; y++) for (int x = 0; x < pw; x++) {
            int xx = p[0] + 500 - mw + x, yy = p[1] + y;
            if (xx < 0 || yy < 0 || xx >= 500 || yy >= 667) continue;
            int c = Math.max(0, Math.min(255, Math.round(128 + delta[y][x])));
            marked.setRGB(xx, yy, (c << 16) | (c << 8) | c);
        }
        input = new File(dir, "marked.png"); output = new File(dir, "cleaned.png");
        ImageIO.write(marked, "png", input);
        WatermarkCli.main(new String[]{input.toString(), output.toString()});
        if (!output.isFile() || WmTile.lastScore < 0.25) throw new AssertionError("marked image not detected");
        BufferedImage cleaned = ImageIO.read(output);
        long before = 0, after = 0;
        for (int y = 0; y < 667; y++) for (int x = 0; x < 500; x++) {
            before += Math.abs((marked.getRGB(x, y) & 255) - 128);
            after += Math.abs((cleaned.getRGB(x, y) & 255) - 128);
        }
        if (after >= before) throw new AssertionError("cleaning did not reduce synthetic watermark");
        System.out.println("PASS diagonal fixture, confidence=" + WmTile.lastScore + ", error before=" + before + ", after=" + after);
        BufferedImage landscape = new BufferedImage(667, 500, BufferedImage.TYPE_INT_RGB);
        for (int y = 0; y < 667; y++) for (int x = 0; x < 500; x++) landscape.setRGB(y, 499 - x, marked.getRGB(x, y));
        input = new File(dir, "landscape.png"); output = new File(dir, "landscape-out.png");
        ImageIO.write(landscape, "png", input);
        WatermarkCli.main(new String[]{input.toString(), output.toString()});
        if (!output.isFile() || ImageIO.read(output).getWidth() != 667) throw new AssertionError("landscape failed");
        System.out.println("PASS landscape orientation");
        Method scaled = WmLogo.class.getDeclaredMethod("scaled", double.class); scaled.setAccessible(true);
        float[][] alpha = (float[][])scaled.invoke(null, 1.0);
        BufferedImage logo = new BufferedImage(250, 350, BufferedImage.TYPE_INT_RGB);
        int[] logoPixels = new int[250 * 350]; Arrays.fill(logoPixels, 0x808080); logo.setRGB(0, 0, 250, 350, logoPixels, 0, 250);
        for (int y = 0; y < alpha.length; y++) for (int x = 0; x < alpha[0].length; x++) {
            int c = Math.round(128 * (1 - alpha[y][x]) + 255 * alpha[y][x]);
            logo.setRGB(30 + x, 350 - 108 + y, (c << 16) | (c << 8) | c);
        }
        input = new File(dir, "logo.png"); output = new File(dir, "logo-out.png");
        ImageIO.write(logo, "png", input);
        WatermarkCli.main(new String[]{input.toString(), output.toString()});
        if (!output.isFile() || WmLogo.lastScore < 0.3) throw new AssertionError("logo not detected");
        System.out.println("PASS logo fixture, confidence=" + WmLogo.lastScore);
    }
}
