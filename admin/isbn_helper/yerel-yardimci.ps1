# Yeniçeri Kitabevi – ISBN yerel yardımcı
# D&R ve Kitapyurdu bulut sunucusundan gelen istekleri engelliyor. Bu yardımcı yöneticinin bilgisayarında çalışır;
# panel (ISBN ile otomatik doldur) bu iki sitenin sayfalarını bilgisayarın kendi internetinden indirtir, ayrıştırma sunucuda yapılır.
#  - Yalnızca 127.0.0.1 üzerinden, yalnızca sitenin kendi adresinden gelen isteklere cevap verir
#  - Yalnızca https://*.dr.com.tr ve https://*.kitapyurdu.com sayfalarını indirir (genel amaçlı vekil değildir)
# Panelden indirilen .bat dosyası bu betiği çalıştırır; izin verilen adresler indirirken sitenin adresiyle doldurulur.

$Port = 17891
$Version = '1'
$AllowedOrigins = @(__ORIGINS__)
$AllowedHosts = @('dr.com.tr', 'kitapyurdu.com')

[Console]::OutputEncoding = [Text.Encoding]::UTF8
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Net.Http
Add-Type -AssemblyName System.Web.Extensions

$handler = New-Object System.Net.Http.HttpClientHandler
$handler.AutomaticDecompression = [System.Net.DecompressionMethods]'GZip, Deflate'
$handler.CookieContainer = New-Object System.Net.CookieContainer
$handler.AllowAutoRedirect = $true
$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromSeconds(20)
[void]$client.DefaultRequestHeaders.TryAddWithoutValidation('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36')
[void]$client.DefaultRequestHeaders.TryAddWithoutValidation('Accept', 'text/html,application/xhtml+xml,*/*;q=0.8')
[void]$client.DefaultRequestHeaders.TryAddWithoutValidation('Accept-Language', 'tr-TR,tr;q=0.9,en;q=0.6')

$json = New-Object System.Web.Script.Serialization.JavaScriptSerializer
$json.MaxJsonLength = [int]::MaxValue

function Test-AllowedUrl([string]$u) {
    try {
        $uri = [Uri]$u
        if ($uri.Scheme -ne 'https') { return $false }
        $h = $uri.Host.ToLowerInvariant()
        foreach ($d in $AllowedHosts) {
            if ($h -eq $d -or $h.EndsWith('.' + $d)) { return $true }
        }
    } catch {}
    return $false
}

# Adresleri aynı anda indirir: adres => @{ code; url (yönlendirme sonrası); body }
function Get-Pages($urls) {
    $tasks = @{}
    foreach ($u in $urls) {
        if (Test-AllowedUrl $u) { $tasks[$u] = $client.GetAsync($u) }
    }
    $out = @{}
    foreach ($u in $tasks.Keys) {
        try {
            $resp = $tasks[$u].GetAwaiter().GetResult()
            $bytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
            $enc = [Text.Encoding]::UTF8
            $cs = $null
            if ($resp.Content.Headers.ContentType) { $cs = $resp.Content.Headers.ContentType.CharSet }
            if ($cs) { try { $enc = [Text.Encoding]::GetEncoding($cs.Trim('"')) } catch {} }
            $out[$u] = @{ code = [int]$resp.StatusCode; url = $resp.RequestMessage.RequestUri.AbsoluteUri; body = $enc.GetString($bytes) }
        } catch {
            $out[$u] = @{ code = 0; url = $u; body = '' }
        }
    }
    return $out
}

function Send-Json($res, $obj, [int]$code = 200) {
    $bytes = [Text.Encoding]::UTF8.GetBytes($json.Serialize($obj))
    $res.StatusCode = $code
    $res.ContentType = 'application/json; charset=utf-8'
    $res.ContentLength64 = $bytes.Length
    $res.OutputStream.Write($bytes, 0, $bytes.Length)
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$Port/")
try {
    $listener.Start()
} catch {
    Write-Host ''
    Write-Host "  $Port numaralı bağlantı noktası kullanımda: yardımcı zaten açık olabilir." -ForegroundColor Yellow
    Write-Host '  Bu pencereyi kapatabilirsiniz.'
    return
}

Write-Host ''
Write-Host '  Yeniçeri Kitabevi – ISBN yerel yardımcı çalışıyor.' -ForegroundColor Green
Write-Host '  Panelde "ISBN ile otomatik doldur" D&R ve Kitapyurdu fiyatlarını bu bilgisayar üzerinden alacak.'
Write-Host '  Kitap eklerken bu pencere açık kalsın; işiniz bitince kapatabilirsiniz.'
Write-Host ''

while ($listener.IsListening) {
    $ctx = $listener.GetContext()
    $req = $ctx.Request
    $res = $ctx.Response
    try {
        $origin = $req.Headers['Origin']
        $allowed = $origin -and ($AllowedOrigins -contains $origin)
        if ($allowed) {
            $res.AddHeader('Access-Control-Allow-Origin', $origin)
            $res.AddHeader('Vary', 'Origin')
            $res.AddHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            $res.AddHeader('Access-Control-Allow-Headers', 'Content-Type')
            $res.AddHeader('Access-Control-Allow-Private-Network', 'true')
        }
        $path = $req.Url.AbsolutePath
        if ($req.HttpMethod -eq 'OPTIONS') {
            $res.StatusCode = 204
        } elseif ($path -eq '/ping' -and -not $origin) {
            Send-Json $res @{ ok = $true; version = $Version; note = 'Yerel yardımcı çalışıyor.' }
        } elseif (-not $allowed) {
            Send-Json $res @{ ok = $false; error = 'izin verilmeyen adres' } 403
        } elseif ($path -eq '/ping') {
            Send-Json $res @{ ok = $true; version = $Version }
        } elseif ($path -eq '/fetch' -and $req.HttpMethod -eq 'POST') {
            $reader = New-Object System.IO.StreamReader($req.InputStream, [Text.Encoding]::UTF8)
            $data = $json.DeserializeObject($reader.ReadToEnd())
            $urls = @($data['urls'] | Select-Object -First 10)
            $pages = Get-Pages $urls
            Write-Host ("  {0:HH:mm:ss}  {1} sayfa indirildi" -f (Get-Date), $pages.Count)
            Send-Json $res @{ ok = $true; pages = $pages }
        } else {
            Send-Json $res @{ ok = $false; error = 'bilinmeyen istek' } 404
        }
    } catch {
        try { Send-Json $res @{ ok = $false; error = $_.Exception.Message } 500 } catch {}
    } finally {
        $res.Close()
    }
}
