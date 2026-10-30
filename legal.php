<?php
/**
 * STOREFRONT: Yasal Sayfalar (legal.php?p=...)
 * Ön Bilgilendirme Formu, Mesafeli Satış Sözleşmesi, Teslimat & İade, KVKK Aydınlatma Metni, Gizlilik & Çerez Politikası
 * Metinler 6502 sayılı Kanun, Mesafeli Sözleşmeler Yönetmeliği ve 6698 sayılı KVKK esas alınarak hazırlanmıştır.
 * Satıcı bilgileri admin panelindeki Site Ayarları'ndan okunur.
 */
require_once __DIR__ . '/config.php';

$pages = [
    'on-bilgilendirme' => 'Ön Bilgilendirme Formu',
    'mesafeli-satis'   => 'Mesafeli Satış Sözleşmesi',
    'teslimat-iade'    => 'Teslimat, İptal ve İade Koşulları',
    'kvkk'             => 'KVKK Aydınlatma Metni',
    'gizlilik'         => 'Gizlilik ve Çerez Politikası',
];
$p = $_GET['p'] ?? 'mesafeli-satis';
if (!isset($pages[$p])) {
    $p = 'mesafeli-satis';
}

$seller      = setting('company_legal_name', APP_NAME);
$brand       = APP_NAME;
$address     = setting('site_address', 'İstasyon Mah. Hikmet Özer Cad. No:48/A Etimesgut / Ankara');
$phone       = setting('site_phone', '0312 245 00 00');
$email       = setting('site_email', 'iletisim@yenicerikitabevi.com');
$tax_office  = setting('company_tax_office');
$tax_number  = setting('company_tax_number');
$mersis      = setting('company_mersis');
$website     = SITE_URL;
$card_enabled = paytr_enabled();
$free_limit  = format_price(setting('shipping_free_limit', '350'));
$flat_cost   = format_price(setting('flat_shipping_cost', '29.90'));
$updated     = '16.10.2026';

// Satıcı künye bloğu (birden fazla metinde kullanılır)
function seller_block() {
    global $seller, $brand, $address, $phone, $email, $tax_office, $tax_number, $mersis, $website;
    $html  = '<ul class="list-unstyled mb-0">';
    $html .= '<li><strong>Unvan:</strong> ' . e($seller) . ($seller !== $brand ? ' (' . e($brand) . ')' : '') . '</li>';
    $html .= '<li><strong>Adres:</strong> ' . e($address) . '</li>';
    $html .= '<li><strong>Telefon:</strong> ' . e($phone) . '</li>';
    $html .= '<li><strong>E-posta:</strong> ' . e($email) . '</li>';
    if ($tax_office !== '' || $tax_number !== '') {
        $html .= '<li><strong>Vergi Dairesi / No:</strong> ' . e(trim($tax_office . ' / ' . $tax_number, ' /')) . '</li>';
    }
    if ($mersis !== '') {
        $html .= '<li><strong>MERSİS No:</strong> ' . e($mersis) . '</li>';
    }
    $html .= '<li><strong>Web sitesi:</strong> ' . e($website) . '</li>';
    $html .= '</ul>';
    return $html;
}
track_view('legal');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= seo_head(['title' => seo_title($pages[$p]), 'description' => seo_description($pages[$p] . ': ' . $brand . ' alışverişlerinizde geçerli koşullar, haklarınız ve satıcı bilgileri.'), 'canonical' => 'legal.php?p=' . $p, 'jsonld' => [jsonld_breadcrumb([['Ana Sayfa', ''], [$pages[$p], null]])]]) ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_v('assets/css/style.css') ?>">
    <style>
        .legal-text h2 { font-size: 1.15rem; font-weight: 700; margin-top: 1.75rem; }
        .legal-text p, .legal-text li { color: #374151; line-height: 1.7; }
    </style>
</head>
<body>
    <header class="bg-white py-3 border-bottom shadow-sm">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="index.php" class="navbar-brand text-dark d-flex align-items-center">
                <img src="assets/images/logo_horizontal.png" alt="<?= e($brand) ?>" style="height: 48px; max-width: 220px; object-fit: contain;">
            </a>
            <a href="index.php" class="btn btn-outline-dark rounded-pill px-3 font-semibold"><i class="bi bi-arrow-left me-1"></i> Anasayfaya Dön</a>
        </div>
    </header>

    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-3">
                <div class="list-group shadow-sm rounded-4">
                    <?php foreach ($pages as $slug => $title): ?>
                        <a href="legal.php?p=<?= $slug ?>" class="list-group-item list-group-item-action fs-7 <?= $slug === $p ? 'active bg-danger border-danger' : '' ?>"><?= e($title) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 legal-text">
                    <h1 class="fw-extrabold text-dark h3 mb-1"><?= e($pages[$p]) ?></h1>
                    <small class="text-muted d-block mb-4">Son güncelleme: <?= $updated ?></small>

<?php if ($p === 'on-bilgilendirme'): ?>
                    <p>İşbu Ön Bilgilendirme Formu, 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği uyarınca, sipariş verilmeden önce Alıcı'nın bilgilendirilmesi amacıyla düzenlenmiştir.</p>

                    <h2>1. Satıcı Bilgileri</h2>
                    <?= seller_block() ?>

                    <h2>2. Sözleşme Konusu Ürünler ve Fiyat</h2>
                    <p>Satın alınan ürünlerin adı, adedi, birim fiyatı ve toplam fiyatı ile kargo ücreti, sipariş onayından önce ödeme sayfasındaki "Sipariş Özeti" bölümünde gösterilir. Tüm fiyatlara KDV dahildir. Ürün fiyatları, sipariş onaylanana kadar Satıcı tarafından değiştirilebilir; onaylanan siparişin fiyatı değiştirilemez.</p>

                    <h2>3. Kargo Ücreti</h2>
                    <p><?= $free_limit ?> ve üzeri siparişlerde kargo ücretsizdir. Bu tutarın altındaki siparişlerde <?= $flat_cost ?> kargo ücreti uygulanır ve sipariş özetinde ayrıca gösterilir.</p>

                    <h2>4. Ödeme Şekli</h2>
                    <?php if ($card_enabled): ?>
                    <p>Ödeme, kredi kartı / banka kartı ile veya Havale/EFT yoluyla yapılabilir. Kartlı ödemeler, lisanslı ödeme kuruluşu PayTR Ödeme ve Elektronik Para Kuruluşu A.Ş. altyapısı üzerinden alınır; kart bilgileri Satıcı tarafından görülmez ve saklanmaz.</p>
                    <?php endif; ?>
                    <p><?= $card_enabled ? 'Havale/EFT seçilmesi hâlinde ödeme' : 'Ödeme' ?>, sipariş onayından sonra gösterilen banka hesabına Havale/EFT yoluyla yapılır. Havale açıklamasına sipariş numarasının yazılması gerekir. Ödemesi 3 iş günü içinde yapılmayan siparişler Satıcı tarafından iptal edilebilir.</p>

                    <h2>5. Teslimat</h2>
                    <p>Ürünler, ödemenin Satıcı hesabına geçmesinin ardından kargo firması aracılığıyla Alıcı'nın sipariş sırasında belirttiği teslimat adresine gönderilir. Ürünler genellikle ödeme onayından sonra 1-3 iş günü içinde kargoya verilir; teslimat süresi her halükarda yasal azami süre olan 30 günü geçemez.</p>

                    <h2>6. Cayma Hakkı</h2>
                    <p>Alıcı, ürünü teslim aldığı tarihten itibaren <strong>14 (on dört) gün</strong> içinde hiçbir gerekçe göstermeden ve cezai şart ödemeden sözleşmeden cayma hakkına sahiptir. Cayma hakkının kullanılması ve istisnaları hakkında ayrıntılı bilgi <a href="legal.php?p=teslimat-iade">Teslimat, İptal ve İade Koşulları</a> sayfasında yer alır.</p>

                    <h2>7. Şikâyet ve İtirazlar</h2>
                    <p>Alıcı, talep ve şikâyetlerini yukarıdaki iletişim bilgileri üzerinden Satıcı'ya iletebilir. Uyuşmazlık hâlinde, Ticaret Bakanlığı'nca her yıl belirlenen parasal sınırlar dahilinde Alıcı'nın yerleşim yerindeki veya işlemin yapıldığı yerdeki Tüketici Hakem Heyetlerine, bu sınırları aşan durumlarda Tüketici Mahkemelerine başvurulabilir.</p>

                    <p class="mt-4">Alıcı, ödeme sayfasındaki onay kutusunu işaretleyerek bu formu okuduğunu ve bilgilendirildiğini kabul eder.</p>

<?php elseif ($p === 'mesafeli-satis'): ?>
                    <h2>Madde 1 – Taraflar</h2>
                    <p><strong>1.1. Satıcı</strong></p>
                    <?= seller_block() ?>
                    <p class="mt-3"><strong>1.2. Alıcı</strong><br>Sipariş sırasında ödeme sayfasında adı soyadı, e-posta adresi, telefon numarası ve teslimat adresi belirtilen kişidir. Alıcı'nın bu bilgileri doğru vermesi kendi sorumluluğundadır.</p>

                    <h2>Madde 2 – Konu</h2>
                    <p>İşbu sözleşmenin konusu, Alıcı'nın <?= e($website) ?> internet sitesi üzerinden elektronik ortamda siparişini verdiği, nitelikleri ve satış fiyatı sitede belirtilen ürünlerin satışı ve teslimi ile ilgili olarak 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği hükümleri gereğince tarafların hak ve yükümlülüklerinin belirlenmesidir.</p>

                    <h2>Madde 3 – Sözleşme Konusu Ürün, Fiyat ve Ödeme</h2>
                    <p>3.1. Ürünlerin cinsi, adedi, KDV dahil satış fiyatı ve kargo ücreti sipariş özetinde ve sipariş sonrasında Alıcı'ya gösterilen sipariş bilgilerinde yer alır. Sipariş özeti bu sözleşmenin ayrılmaz parçasıdır.</p>
                    <p>3.2. <?= $free_limit ?> ve üzeri siparişlerde kargo ücretsizdir; altındaki siparişlerde <?= $flat_cost ?> kargo ücreti Alıcı tarafından ödenir.</p>
                    <?php if ($card_enabled): ?>
                    <p>3.3. Ödeme, kredi kartı / banka kartı (PayTR ödeme altyapısı) ile veya Havale/EFT yoluyla yapılır. Kartlı ödemede sipariş, ödemenin onaylanmasıyla işleme alınır. Havale/EFT ile verilen sipariş, ödemenin Satıcı'nın banka hesabına geçmesiyle işleme alınır; ödemesi 3 iş günü içinde yapılmayan siparişleri Satıcı iptal edebilir.</p>
                    <?php else: ?>
                    <p>3.3. Ödeme Havale/EFT yoluyla yapılır. Sipariş, ödemenin Satıcı'nın banka hesabına geçmesiyle işleme alınır. Ödemesi 3 iş günü içinde yapılmayan siparişleri Satıcı iptal edebilir.</p>
                    <?php endif; ?>

                    <h2>Madde 4 – Teslimat</h2>
                    <p>4.1. Ürün, Alıcı'nın belirttiği teslimat adresine kargo ile teslim edilir. Teslimat, ödeme onayından itibaren yasal 30 günlük süre içinde yapılır.</p>
                    <p>4.2. Alıcı'nın teslimat adresinde bulunmaması hâlinde Satıcı edimini yerine getirmiş sayılır; bu durumda ürünün kargo şubesinden teslim alınması Alıcı'nın sorumluluğundadır.</p>
                    <p>4.3. Alıcı, ürünü teslim alırken kargo paketini kontrol etmeli; hasarlı paketleri teslim almayarak kargo görevlisine tutanak tutturmalıdır. Hasarlı olduğu hâlde teslim alınan ürünlerde kargo kaynaklı hasar sorumluluğu ilgili kargo firması nezdinde değerlendirilir.</p>

                    <h2>Madde 5 – Satıcı'nın Yükümlülükleri</h2>
                    <p>5.1. Satıcı, ürünü sağlam, eksiksiz, siparişte belirtilen niteliklere uygun ve varsa yasal belgeleriyle birlikte teslim etmekle yükümlüdür.</p>
                    <p>5.2. Ürünün tedarikinin imkânsızlaşması hâlinde Satıcı, bu durumu öğrendiği tarihten itibaren 3 gün içinde Alıcı'ya bildirir ve tahsil edilen tutarı bildirim tarihinden itibaren en geç 14 gün içinde iade eder.</p>

                    <h2>Madde 6 – Cayma Hakkı</h2>
                    <p>6.1. Alıcı, ürünün kendisine veya gösterdiği üçüncü kişiye teslim tarihinden itibaren 14 gün içinde hiçbir gerekçe göstermeden ve cezai şart ödemeden sözleşmeden cayma hakkına sahiptir.</p>
                    <p>6.2. Cayma hakkının kullanılması için bu süre içinde Satıcı'ya yukarıdaki e-posta, telefon veya adres üzerinden açık bir bildirimde bulunulması yeterlidir.</p>
                    <p>6.3. Alıcı, cayma bildiriminden itibaren 10 gün içinde ürünü Satıcı'ya geri göndermekle yükümlüdür. Satıcı, cayma bildiriminin kendisine ulaştığı tarihten itibaren en geç 14 gün içinde ürün bedelini ve varsa teslimat masrafını Alıcı'nın bildireceği banka hesabına iade eder.</p>
                    <p>6.4. Ürünün olağan kullanımı dışında kullanılması nedeniyle oluşan değişiklik ve bozulmalardan Alıcı sorumludur.</p>
                    <p>6.5. Mesafeli Sözleşmeler Yönetmeliği'nin 15. maddesinde sayılan hâllerde (örneğin ambalajı açılmış olan ve iadesi sağlık/hijyen bakımından uygun olmayan ürünler, teslimden sonra ambalaj veya bant gibi koruyucu unsurları açılmış dijital içerik taşıyan ürünler ile gazete ve dergi gibi süreli yayınlar) cayma hakkı kullanılamaz.</p>

                    <h2>Madde 7 – Ayıplı Ürün</h2>
                    <p>Teslim edilen ürünün ayıplı olması hâlinde Alıcı, 6502 sayılı Kanun'un 11. maddesinde düzenlenen seçimlik haklarını (sözleşmeden dönme, bedelden indirim, ücretsiz onarım, ayıpsız misli ile değişim) kullanabilir.</p>

                    <h2>Madde 8 – Kişisel Veriler</h2>
                    <p>Alıcı'nın kişisel verileri, <a href="legal.php?p=kvkk">KVKK Aydınlatma Metni</a>'nde belirtilen amaçlar ve hukuki sebeplerle işlenir.</p>

                    <h2>Madde 9 – Uyuşmazlıkların Çözümü</h2>
                    <p>İşbu sözleşmeden doğan uyuşmazlıklarda, Ticaret Bakanlığı'nca ilan edilen parasal sınırlar dahilinde Alıcı'nın yerleşim yerindeki veya işlemin yapıldığı yerdeki Tüketici Hakem Heyetleri, bu sınırların üzerindeki uyuşmazlıklarda Tüketici Mahkemeleri yetkilidir.</p>

                    <h2>Madde 10 – Yürürlük</h2>
                    <p>Alıcı, ödeme sayfasında bu sözleşmeyi ve Ön Bilgilendirme Formu'nu okuduğunu ve kabul ettiğini onaylayarak siparişi verdiği anda işbu sözleşme kurulmuş ve yürürlüğe girmiş sayılır. Sözleşme ve sipariş bilgileri Satıcı tarafından saklanır; Alıcı talep etmesi hâlinde bir kopyasını e-posta ile alabilir.</p>

<?php elseif ($p === 'teslimat-iade'): ?>
                    <h2>Teslimat</h2>
                    <ul>
                        <li>Siparişler, <?= $card_enabled ? 'kartla ödemenin onaylanmasının veya ' : '' ?>Havale/EFT ödemesinin hesabımıza geçmesinin ardından genellikle <strong>1-3 iş günü</strong> içinde kargoya verilir.</li>
                        <li>Teslimat süresi yasal azami süre olan 30 günü geçmez.</li>
                        <li><?= $free_limit ?> ve üzeri siparişlerde kargo ücretsizdir; altındaki siparişlerde kargo ücreti <?= $flat_cost ?>'dir.</li>
                        <li>Kargoya verilen siparişlerin takip numarası talep hâlinde tarafınıza iletilir.</li>
                        <li>Paketi teslim alırken lütfen kontrol edin; hasarlı paketleri teslim almadan kargo görevlisine tutanak tutturun.</li>
                    </ul>

                    <h2>Sipariş İptali</h2>
                    <p>Kargoya verilmemiş siparişlerinizi <?= e($phone) ?> numaralı telefondan veya <?= e($email) ?> adresinden bize ulaşarak iptal edebilirsiniz. Ödemesi yapılmış siparişlerin bedeli en geç 14 gün içinde <?= $card_enabled ? 'kartla ödemelerde ödeme yapılan karta, Havale/EFT ödemelerinde ' : '' ?>bildirdiğiniz banka hesabına iade edilir.</p>

                    <h2>Cayma Hakkı ve İade</h2>
                    <ul>
                        <li>Ürünü teslim aldığınız tarihten itibaren <strong>14 gün</strong> içinde hiçbir gerekçe göstermeden iade edebilirsiniz.</li>
                        <li>İade talebinizi telefon, e-posta veya <a href="contact.php">iletişim formu</a> ile sipariş numaranızı belirterek bildirmeniz yeterlidir.</li>
                        <li>Ürünü, bildiriminizden itibaren 10 gün içinde faturası/sipariş özeti ile birlikte mağaza adresimize (<?= e($address) ?>) gönderin. İade için size bildirdiğimiz anlaşmalı kargo firması ile yapılan gönderimlerde kargo ücreti tarafımıza aittir.</li>
                        <li>İade edilen ürün bize ulaştıktan sonra, cayma bildiriminizin bize ulaştığı tarihten itibaren en geç 14 gün içinde ödemeniz <?= $card_enabled ? 'kartla ödemelerde ödeme yaptığınız karta, Havale/EFT ödemelerinde ' : '' ?>bildirdiğiniz IBAN'a iade edilir.</li>
                        <li>Ürünün olağan kullanımı dışında kullanılmış, yıpranmış veya zarar görmüş olması hâlinde oluşan değer kaybından alıcı sorumludur.</li>
                        <li>Yönetmelikte sayılan istisnalar (ör. ambalajı açılmış dijital içerikli ürünler, süreli yayınlar) için cayma hakkı kullanılamaz.</li>
                    </ul>

                    <h2>Hasarlı veya Hatalı Ürün</h2>
                    <p>Size yanlış veya kusurlu bir ürün ulaştıysa lütfen teslimden sonra en kısa sürede bizimle iletişime geçin. Ürün ücretsiz olarak değiştirilir veya bedeli iade edilir; kargo masrafları tarafımıza aittir.</p>

<?php elseif ($p === 'kvkk'): ?>
                    <p>6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") uyarınca, veri sorumlusu sıfatıyla <?= e($seller) ?> ("Şirket") olarak kişisel verilerinizi aşağıda açıklanan kapsamda işlemekteyiz.</p>

                    <h2>1. Veri Sorumlusu</h2>
                    <?= seller_block() ?>

                    <h2>2. İşlenen Kişisel Veriler</h2>
                    <ul>
                        <li><strong>Kimlik ve iletişim:</strong> ad soyad, e-posta adresi, telefon numarası, teslimat adresi</li>
                        <li><strong>Müşteri işlem:</strong> sipariş bilgileri, sipariş geçmişi, ödeme (<?= $card_enabled ? 'kart ödemesi sonucu, ' : '' ?>havale/EFT) kayıtları, iletişim formu mesajları</li>
                        <li><strong>İşlem güvenliği:</strong> IP adresi, işlem tarih ve saatleri</li>
                    </ul>
                    <p><?= $card_enabled ? 'Kartlı ödemelerde kart bilgileriniz doğrudan PayTR ödeme sayfasına girilir; sitemizde banka kartı/kredi kartı bilgisi alınmaz ve saklanmaz.' : 'Sitemizde banka kartı/kredi kartı bilgisi alınmaz ve saklanmaz.' ?></p>

                    <h2>3. İşleme Amaçları</h2>
                    <ul>
                        <li>Siparişlerin alınması, ödemenin takibi, ürünlerin teslimi, iade ve iptal süreçlerinin yürütülmesi</li>
                        <li>Mesafeli satış sözleşmesinin kurulması ve ifası</li>
                        <li>Talep, soru ve şikâyetlerinizin yanıtlanması</li>
                        <li>Fatura düzenlenmesi ve yasal saklama yükümlülükleri dahil mevzuattan doğan yükümlülüklerin yerine getirilmesi</li>
                        <li>Bilgi ve işlem güvenliğinin sağlanması, kötüye kullanımın önlenmesi</li>
                    </ul>

                    <h2>4. Hukuki Sebepler ve Toplama Yöntemi</h2>
                    <p>Kişisel verileriniz, internet sitemizdeki sipariş ve iletişim formları aracılığıyla elektronik ortamda toplanır ve KVKK'nın 5/2 maddesindeki şu hukuki sebeplere dayanılarak işlenir: (c) sözleşmenin kurulması veya ifası için gerekli olması, (ç) veri sorumlusunun hukuki yükümlülüğünü yerine getirebilmesi için zorunlu olması, (f) ilgili kişinin temel hak ve özgürlüklerine zarar vermemek kaydıyla veri sorumlusunun meşru menfaatleri için zorunlu olması.</p>

                    <h2>5. Kişisel Verilerin Aktarılması</h2>
                    <p>Kişisel verileriniz, yukarıdaki amaçlarla sınırlı olarak; siparişin teslimi için kargo firmalarına, ödemelerin alınması ve takibi için bankalara<?= $card_enabled ? ' ve ödeme kuruluşu PayTR Ödeme ve Elektronik Para Kuruluşu A.Ş.\'ye' : '' ?>, muhasebe ve vergi yükümlülükleri için mali müşavirimize ve talep hâlinde yetkili kamu kurum ve kuruluşlarına aktarılabilir.</p>
                    <p>İnternet sitemiz yurt dışında bulunan bir bulut barındırma (hosting) hizmeti üzerinde çalışmaktadır; bu nedenle sitemize ilettiğiniz veriler bu hizmet sağlayıcının sunucularında saklanır. Bu aktarım KVKK'nın 9. maddesine uygun olarak gerçekleştirilir.</p>

                    <h2>6. Saklama Süresi</h2>
                    <p>Kişisel verileriniz, işleme amacının gerektirdiği süre ve ilgili mevzuatta öngörülen saklama süreleri (ör. ticari defter ve belgeler için 10 yıl) boyunca saklanır; sürenin sonunda silinir, yok edilir veya anonim hâle getirilir.</p>

                    <h2>7. Haklarınız</h2>
                    <p>KVKK'nın 11. maddesi uyarınca; kişisel verilerinizin işlenip işlenmediğini öğrenme, işlenmişse buna ilişkin bilgi talep etme, işlenme amacını ve amacına uygun kullanılıp kullanılmadığını öğrenme, yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme, eksik veya yanlış işlenmişse düzeltilmesini isteme, KVKK'nın 7. maddesindeki şartlar çerçevesinde silinmesini veya yok edilmesini isteme, bu işlemlerin aktarıldığı üçüncü kişilere bildirilmesini isteme, münhasıran otomatik sistemlerle analiz edilmesi sonucu aleyhinize bir sonucun ortaya çıkmasına itiraz etme ve kanuna aykırı işleme sebebiyle zarara uğramanız hâlinde zararın giderilmesini talep etme haklarına sahipsiniz.</p>

                    <h2>8. Başvuru</h2>
                    <p>Haklarınıza ilişkin taleplerinizi, kimliğinizi tespit edici bilgilerle birlikte <?= e($email) ?> adresine e-posta ile veya <?= e($address) ?> adresine yazılı olarak iletebilirsiniz. Başvurunuz en geç 30 gün içinde ücretsiz olarak sonuçlandırılır.</p>

<?php elseif ($p === 'gizlilik'): ?>
                    <h2>Gizlilik</h2>
                    <p><?= e($brand) ?> olarak ziyaretçilerimizin ve müşterilerimizin gizliliğine önem veriyoruz. Sitemiz üzerinden paylaştığınız kişisel veriler yalnızca siparişlerinizin yerine getirilmesi ve sorularınızın yanıtlanması amacıyla kullanılır; reklam veya pazarlama amacıyla üçüncü kişilerle paylaşılmaz ya da satılmaz. Ayrıntılı bilgi için <a href="legal.php?p=kvkk">KVKK Aydınlatma Metni</a>'ni inceleyebilirsiniz.</p>

                    <h2>Güvenlik</h2>
                    <ul>
                        <li>Sitemizle aranızdaki bağlantı SSL (HTTPS) ile şifrelenir.</li>
                        <li><?= $card_enabled ? 'Sitemizde kredi kartı veya banka kartı bilgisi alınmaz ve saklanmaz; kartlı ödemeler PayTR\'ın güvenli ödeme sayfasında yapılır.' : 'Sitemizde kredi kartı veya banka kartı bilgisi alınmaz; ödemeler Havale/EFT ile bankanız üzerinden yapılır.' ?></li>
                        <li>Kişisel verilerinize yalnızca yetkili personelimiz erişebilir.</li>
                    </ul>

                    <h2>Çerezler ve Tarayıcı Depolaması</h2>
                    <p>Sitemiz reklam veya takip amaçlı çerez kullanmaz. Yalnızca sitenin çalışması için zorunlu olan şu teknolojiler kullanılır:</p>
                    <ul>
                        <li><strong>Oturum çerezi (PHPSESSID):</strong> Sayfalar arasında oturumunuzun sürdürülmesi için kullanılır, tarayıcınızı kapattığınızda silinir.</li>
                        <li><strong>Sepet bilgisi (tarayıcı yerel depolaması):</strong> Sepetinize eklediğiniz ürünler yalnızca kendi tarayıcınızda saklanır, siparişi tamamladığınızda silinir.</li>
                    </ul>
                    <p>Sitemizin görünümü için yazı tipi ve tasarım dosyaları üçüncü taraf içerik dağıtım ağlarından (Google Fonts, jsDelivr) yüklenir; bu sırada IP adresiniz bu hizmet sağlayıcılara iletilebilir. Tarayıcı ayarlarınızdan çerezleri silebilir veya engelleyebilirsiniz; ancak bu durumda sepet ve sipariş işlemleri çalışmayabilir.</p>

                    <h2>İletişim</h2>
                    <p>Gizlilikle ilgili sorularınız için <?= e($email) ?> adresine yazabilir veya <?= e($phone) ?> numarasından bize ulaşabilirsiniz.</p>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?= track_script() ?>
</body>
</html>
