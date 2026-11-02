<?php
/**
 * ISBN yerel yardımcı indirme: isbn_helper/yerel-yardimci.ps1 betiğini çift tıklanarak çalışan bir .bat dosyası olarak verir.
 * İzin verilen site adresleri (CORS) indirme anında sitenin kendi adresiyle doldurulur.
 */
require_once __DIR__ . '/../config.php';
auth_check('admin');

$host = strtolower(preg_replace('/:\d+$/', '', (string)parse_url(SITE_URL, PHP_URL_HOST)));
$port = parse_url(SITE_URL, PHP_URL_PORT);
$hosts = array_unique(array_filter([$host, preg_replace('/^www\./', '', $host), 'www.' . preg_replace('/^www\./', '', $host)]));
$origins = [];
foreach ($hosts as $h) {
    foreach (['https', 'http'] as $scheme) {
        $origins[] = $scheme . '://' . $h . ($port ? ':' . $port : '');
    }
}
$origins = array_values(array_unique(array_merge($origins, ['https://yenicerikitabevi.com', 'https://www.yenicerikitabevi.com'])));
$ps_list = implode(', ', array_map(function ($o) { return "'" . str_replace("'", "''", $o) . "'"; }, $origins));

$script = str_replace('__ORIGINS__', $ps_list, (string)file_get_contents(__DIR__ . '/isbn_helper/yerel-yardimci.ps1'));
// .bat + PowerShell tek dosya: cmd ilk satırları çalıştırır, PowerShell işaretten sonrasını okur
$bat = "@echo off\r\n"
    . "chcp 65001 >nul\r\n"
    . "title Yeniceri Kitabevi - ISBN yerel yardimci\r\n"
    . "powershell -NoProfile -ExecutionPolicy Bypass -Command \"\$s = Get-Content -LiteralPath '%~f0' -Raw -Encoding UTF8; \$i = \$s.IndexOf('#' + '##PS-BASLA'); Invoke-Expression \$s.Substring(\$i)\"\r\n"
    . "pause\r\n"
    . "exit /b\r\n"
    . "###PS-BASLA\r\n"
    . str_replace(["\r\n", "\n"], ["\n", "\r\n"], $script);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="yeniceri-isbn-yardimci.bat"');
header('Cache-Control: no-store');
echo $bat; // BOM'suz: cmd ilk satırdaki BOM'u komut sanar
