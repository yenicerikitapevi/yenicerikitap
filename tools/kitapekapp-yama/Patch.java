import net.bytebuddy.jar.asm.*;
import java.nio.file.*;
import java.util.*;

/** kitapekapp 1.4.2 → 1.4.3: 500 seçeneği açılır, 1000 yerine "Sınırsız" (100000) gelir; yazılar LimitText ile. */
public class Patch {
    static final String H = "com/netarti/nkapp/ui/LimitText";
    static int changes = 0;

    public static void main(String[] a) throws Exception {
        Path root = Path.of(a[0]);
        patch(root.resolve("com/netarti/nkapp/ui/NkUploaderApp.class"));
        patch(root.resolve("com/netarti/nkapp/ui/NkUploaderApp$2.class"));
        patch(root.resolve("com/netarti/nkapp/ui/NkUploaderApp$2$1.class"));
        patch(root.resolve("com/netarti/nkapp/core/UploadClient.class"));
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

                    public void visitFieldInsn(int op, String owner, String fname, String fdesc) {
                        super.visitFieldInsn(op, owner, fname, fdesc);
                        // updateControlStates: 500/1000 her zaman kapatılıyordu → 25/250 gibi yalnızca aktarım sürerken kapalı
                        if (op == Opcodes.GETFIELD && name.equals("updateControlStates") && (fname.equals("limit500Btn") || fname.equals("limit1000Btn"))) idleNext = true;
                    }

                    public void visitLdcInsn(Object v) {
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
                        if (enableNext && mname.equals("setDisable") && owner.equals("javafx/scene/control/ToggleButton")) {
                            enableNext = false; log("setDisable(true) → false");
                            super.visitInsn(Opcodes.POP); super.visitInsn(Opcodes.ICONST_0);
                        }
                        super.visitMethodInsn(op, owner, mname, mdesc, itf);
                    }

                    public void visitInsn(int op) {
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
