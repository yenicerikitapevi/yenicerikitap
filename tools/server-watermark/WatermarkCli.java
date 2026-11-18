import com.netarti.nkapp.utility.WmLogo;
import com.netarti.nkapp.utility.WmTile;
import java.awt.image.BufferedImage;
import java.io.File;
import javax.imageio.IIOImage;
import javax.imageio.ImageIO;
import javax.imageio.ImageWriteParam;
import javax.imageio.ImageWriter;
import javax.imageio.stream.ImageOutputStream;

/**
 * Sunucuda görsel temizliği için küçük komut satırı aracı: uygulamanın (kitapekapp) temizleyicisini çalıştırır.
 * Ağ ya da yapay zekâ yok. Yalnızca güvenilir eşleşme varsa çıktı yazar (alttaki logo → WmLogo, çapraz desen → WmTile);
 * WmAlign'in koşulsuz yolu kullanılmaz. Çıktı JPEG (kalite 0,92): PNG kaynağın ~10 katı yer kaplıyordu.
 */
public final class WatermarkCli {
    public static void main(String[] args) throws Exception {
        if (args.length != 2) throw new IllegalArgumentException("input output required");
        ImageIO.setUseCache(false);
        BufferedImage input = ImageIO.read(new File(args[0]));
        if (input == null || (long) input.getWidth() * input.getHeight() > 2000000)
            throw new IllegalArgumentException("unsupported image");
        BufferedImage output = WmLogo.clean(input);
        String kind = "logo";
        if (output == input) {
            output = WmTile.clean(input);
            kind = "diagonal";
        }
        if (output == null || output == input) {
            System.out.println("{\"status\":\"skipped\",\"reason\":\"no_confident_match\"}");
            return;
        }
        BufferedImage rgb = output;
        if (output.getType() != BufferedImage.TYPE_INT_RGB) {
            rgb = new BufferedImage(output.getWidth(), output.getHeight(), BufferedImage.TYPE_INT_RGB);
            rgb.getGraphics().drawImage(output, 0, 0, null);
        }
        ImageWriter w = ImageIO.getImageWritersByFormatName("jpeg").next();
        ImageWriteParam p = w.getDefaultWriteParam();
        p.setCompressionMode(ImageWriteParam.MODE_EXPLICIT);
        p.setCompressionQuality(0.92f);
        File out = new File(args[1]);
        try (ImageOutputStream os = ImageIO.createImageOutputStream(out)) {
            w.setOutput(os);
            w.write(null, new IIOImage(rgb, null, null), p);
        } finally {
            w.dispose();
        }
        System.out.println("{\"status\":\"ready\",\"kind\":\"" + kind + "\"}");
    }
}
