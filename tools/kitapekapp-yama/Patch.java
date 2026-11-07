import net.bytebuddy.jar.asm.*;
import java.nio.file.*;
import java.util.*;

/** kitapekapp 1.4.2 → 1.4.5: 500 seçeneği açılır, 1000 yerine "Sınırsız" (100000) gelir; yazılar LimitText ile;
 *  filigran temizliği WmAlign ile (şablon her görselde hizalanır);
 *  1.4.6: sunucunun sayfa yanıtındaki "bekle (dakika)" değeri kullanılır (saatlik aktarım sınırı);
 *  1.4.7: her taranan sayfa günlüğe yazılır (sitede olan kitaplar atlanırken uygulama donmuş görünmesin). */
public class Patch {
    static final String H = "com/netarti/nkapp/ui/LimitText";
    static int changes = 0;

    public static void main(String[] a) throws Exception {
        Path root = Path.of(a[0]);
        patch(root.resolve("com/netarti/nkapp/ui/NkUploaderApp.class"));
        patch(root.resolve("com/netarti/nkapp/ui/NkUploaderApp$2.class"));
        patch(root.resolve("com/netarti/nkapp/ui/NkUploaderApp$2$1.class"));
        patch(root.resolve("com/netarti/nkapp/core/UploadClient.class"));
        patch(root.resolve("com/netarti/nkapp/utility/ImageUtil.class"));
        patch(root.resolve("com/netarti/nkapp/core/UploadProcessor.class"));
        System.out.println("TOPLAM DEĞİŞİKLİK: " + changes);
    }

    static void log(String s) { changes++; System.out.println("  " + s); }

    static void patch(Path f) throws Exception {
        System.out.println(f.getFileName());
        ClassReader cr = new ClassReader(Files.readAllBytes(f));
        ClassWriter cw = new ClassWriter(cr, ClassWriter.COMPUTE_MAXS);
        String cls = cr.getClassName();
        cr.accept(new ClassVisitor(Opcodes.ASM9, cw) {
            public MethodVisitor visitMethod(int acc, String name, String desc, String sig, String[] ex) {
                MethodVisitor mv = super.visitMethod(acc, name, desc, sig, ex);
                return new MethodVisitor(Opcodes.ASM9, mv) {
                    boolean enableNext = false, unlimitedNext = false, idleNext = false;
                    boolean batchPostNext = false, waitMsgNext = false, resumeMsgNext = false, pageMsgNext = false;
                    final boolean batchMethod = cls.endsWith("UploadClient") && name.equals("a")
                        && desc.equals("(Lcom/netarti/nkapp/dto/ProductsBatchRequest;Ljava/lang/String;Lcom/netarti/nkapp/enums/ProductType;I)Ljava/lang/Integer;");
                    final boolean stageMethod = cls.endsWith("UploadProcessor") && name.equals("a") && desc.startsWith("(Lcom/netarti/nkapp/core/UploadProcessor$CategoryStage;Lcom/netarti/nkapp/enums/PageMode;III");

                    public void visitCode() {
                        super.visitCode();
                        if (stageMethod) {
                            // Önceki bölümün (ör. Kitap&Dergi) son sayfasında gelen bekleme, sonraki bölüm başlamadan uygulanır
                            log("bölüm başında bekleyen saatlik bekleme → LimitText.pendingWaitStart/End");
                            super.visitVarInsn(Opcodes.ALOAD, 11);
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "pendingWaitStart", "(Lcom/netarti/nkapp/ui/ProgressSink;)J", false);
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, "com/netarti/nkapp/core/UploadProcessor", "a", "(J)V", false);
                            super.visitVarInsn(Opcodes.ALOAD, 11);
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "pendingWaitEnd", "(Lcom/netarti/nkapp/ui/ProgressSink;)V", false);
                        }
                    }

                    public void visitFieldInsn(int op, String owner, String fname, String fdesc) {
                        super.visitFieldInsn(op, owner, fname, fdesc);
                        // Bekleme mesajları ("Sunucu bağlantısı tekrar kuruluyor" yerine saatlik sınır açıklaması)
                        if (stageMethod && op == Opcodes.GETSTATIC && owner.equals("com/netarti/nkapp/utility/UiMessage")) {
                            if (fname.equals("v")) waitMsgNext = true;
                            if (fname.equals("w")) resumeMsgNext = true;
                        }
                        // updateControlStates: 500/1000 her zaman kapatılıyordu → 25/250 gibi yalnızca aktarım sürerken kapalı
                        if (op == Opcodes.GETFIELD && name.equals("updateControlStates") && (fname.equals("limit500Btn") || fname.equals("limit1000Btn"))) idleNext = true;
                    }

                    public void visitLdcInsn(Object v) {
                        if (stageMethod && "%s yükleniyor...".equals(v)) pageMsgNext = true;
                        if (v instanceof String s && name.equals("start") && cls.endsWith("NkUploaderApp")) {
                            switch (s) {
                                case "✓ 250 Kitap (Aktif)": log("etiket: 250 Kitap"); super.visitLdcInsn("250 Kitap"); return;
                                case "500 Kitap": enableNext = true; log("500 seçeneği açılıyor"); break;
                                case "1000 Kitap": enableNext = true; unlimitedNext = true; log("1000 → Sınırsız"); super.visitLdcInsn("Sınırsız"); return;
                                case "(Şu anda 250 kitaplık paket aktiftir; 500 ve 1000 seçenekleri pasiftir)":
                                    log("açıklama"); super.visitLdcInsn("(\"Sınırsız\" seçilirse mağazadaki tüm kitaplar aktarılır)"); return;
                            }
                        }
                        super.visitLdcInsn(v);
                    }

                    public void visitIntInsn(int op, int operand) {
                        if (unlimitedNext && op == Opcodes.SIPUSH && operand == 1000) {
                            unlimitedNext = false; log("değer 1000 → 100000");
                            super.visitLdcInsn(100000); return;
                        }
                        super.visitIntInsn(op, operand);
                    }

                    public void visitMethodInsn(int op, String owner, String mname, String mdesc, boolean itf) {
                        if (stageMethod && pageMsgNext && owner.equals("com/netarti/nkapp/ui/ProgressSink") && mname.equals("a") && mdesc.equals("(Ljava/lang/String;)V")) {
                            // "Kitap&Dergi yükleniyor..." durum yazısının hemen ardından: taranan sayfa günlüğe yazılır
                            pageMsgNext = false; log("her sayfa sonrası günlük satırı → LimitText.pageLog");
                            super.visitMethodInsn(op, owner, mname, mdesc, itf);
                            super.visitVarInsn(Opcodes.ALOAD, 11);
                            super.visitVarInsn(Opcodes.ILOAD, 5);
                            super.visitVarInsn(Opcodes.ALOAD, 18);
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "pageLog", "(Lcom/netarti/nkapp/ui/ProgressSink;ILjava/lang/Integer;)V", false);
                            return;
                        }
                        if (stageMethod && (waitMsgNext || resumeMsgNext) && owner.equals("com/netarti/nkapp/utility/UiMessage") && mname.equals("a")) {
                            super.visitMethodInsn(op, owner, mname, mdesc, itf);
                            super.visitInsn(Opcodes.POP);
                            if (waitMsgNext) {
                                waitMsgNext = false; log("bekleme mesajı → LimitText.pauseStart");
                                super.visitVarInsn(Opcodes.ALOAD, 22);
                                super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "pauseStart", "(Ljava/lang/Integer;)Ljava/lang/String;", false);
                            } else {
                                resumeMsgNext = false; log("devam mesajı → LimitText.pauseEnd");
                                super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "pauseEnd", "()Ljava/lang/String;", false);
                            }
                            return;
                        }
                        if (batchMethod && op == Opcodes.INVOKEVIRTUAL && owner.equals("com/netarti/nkapp/core/UploadHelper") && mname.equals("a")
                                && mdesc.equals("(Lokhttp3/Request;Ljava/lang/String;Lcom/netarti/nkapp/core/UploadHelper$Handler;)Ljava/lang/Object;")) {
                            batchPostNext = true;
                        }
                        if (op == Opcodes.INVOKESTATIC && name.equals("removeWatermarkBytes") && owner.equals("com/netarti/nkapp/utility/ImageUtil") && mname.equals("cleanWatermark")) {
                            log("removeWatermarkBytes: cleanWatermark → WmAlign.clean");
                            super.visitMethodInsn(op, "com/netarti/nkapp/utility/WmAlign", "clean", mdesc, false); return;
                        }
                        if (enableNext && mname.equals("setDisable") && owner.equals("javafx/scene/control/ToggleButton")) {
                            enableNext = false; log("setDisable(true) → false");
                            super.visitInsn(Opcodes.POP); super.visitInsn(Opcodes.ICONST_0);
                        }
                        super.visitMethodInsn(op, owner, mname, mdesc, itf);
                    }

                    public void visitInsn(int op) {
                        if (batchMethod && batchPostNext && op == Opcodes.POP) {
                            batchPostNext = false; log("sayfa yanıtı (bekle dakikası) saklanıyor → LimitText.rememberPause");
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "rememberPause", "(Ljava/lang/Object;)V", false); return;
                        }
                        if (batchMethod && op == Opcodes.ARETURN) {
                            // Seçilen kitap sınırı (25/250/500) dolduysa beklemeye gerek yok: aktarım zaten bitiyor
                            log("dönüş değeri → LimitText.withPause");
                            super.visitVarInsn(Opcodes.ALOAD, 0);
                            super.visitFieldInsn(Opcodes.GETFIELD, "com/netarti/nkapp/core/UploadClient", "limitReached", "Z");
                            super.visitVarInsn(Opcodes.ALOAD, 0);
                            super.visitFieldInsn(Opcodes.GETFIELD, "com/netarti/nkapp/core/UploadClient", "currentRunUploaded", "Ljava/util/concurrent/atomic/AtomicInteger;");
                            super.visitMethodInsn(Opcodes.INVOKEVIRTUAL, "java/util/concurrent/atomic/AtomicInteger", "get", "()I", false);
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "withPause", "(Ljava/lang/Integer;ZI)Ljava/lang/Integer;", false);
                        }
                        if (idleNext && op == Opcodes.ICONST_1) {
                            idleNext = false; log("updateControlStates: setDisable(true) → setDisable(!idle)");
                            super.visitInsn(Opcodes.ICONST_1); super.visitVarInsn(Opcodes.ILOAD, 1); super.visitInsn(Opcodes.ISUB); return;
                        }
                        if (op == Opcodes.DDIV && cls.endsWith("NkUploaderApp$2") && name.equals("lambda$call$1")) {
                            log("ilerleme oranı → LimitText.ratio");
                            super.visitMethodInsn(Opcodes.INVOKESTATIC, H, "ratio", "(DD)D", false); return;
                        }
                        super.visitInsn(op);
                    }

                    public void visitInvokeDynamicInsn(String n, String d, Handle bsm, Object... args) {
                        if (bsm.getName().equals("makeConcatWithConstants") && args.length > 0) {
                            String r = String.valueOf(args[0]);
                            String target = null;
                            if (r.startsWith("Aktarım başlatılıyor... Hedef Limit:") && d.equals("(ILjava/lang/String;Ljava/lang/String;)Ljava/lang/String;")) target = "startLog";
                            else if (r.startsWith("▶ \u0001 Kitabı") && d.equals("(I)Ljava/lang/String;")) target = "button";
                            else if (r.startsWith("Hazır. Giriş yaptıktan sonra \u0001") && d.equals("(I)Ljava/lang/String;")) target = "ready";
                            else if (r.startsWith("Hedef çekim limiti:") && d.equals("(I)Ljava/lang/String;")) target = "target";
                            else if (r.startsWith("Aktarım tamamlandı! Seçilen") && d.equals("(IILjava/lang/String;)Ljava/lang/String;")) target = "done";
                            else if (r.equals("\u0001 (Limit: \u0001 Kitap)") && d.equals("(Ljava/lang/String;I)Ljava/lang/String;")) target = "withLimit";
                            else if (r.equals(" / \u0001") && d.equals("(I)Ljava/lang/String;") && cls.endsWith("UploadClient")) target = "slash";
                            if (target != null) {
                                log(name + ": \"" + r.replace("\u0001", "{}") + "\" → LimitText." + target);
                                super.visitMethodInsn(Opcodes.INVOKESTATIC, H, target, d, false); return;
                            }
                        }
                        super.visitInvokeDynamicInsn(n, d, bsm, args);
                    }
                };
            }
        }, 0);
        Files.write(f, cw.toByteArray());
    }
}
