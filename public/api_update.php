<?php
// Yazilim guncelleme API — GitHub Releases uzerinden
// kontrol: son surumu ceker, yerel VERSION ile karsilastirir
// uygula : DB yedekler, surum zip'ini indirir/acar, SADECE app/ ve public/ (+VERSION,*.bat) uzerine yazar
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

const GH_REPO    = 'mrbatuk/MobiStokV2';
const PROJE_DIZ  = __DIR__ . '/..';
const VERSION_DOSYA = __DIR__ . '/../VERSION';

function yanit(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function mevcut_surum(): string
{
    $v = @file_get_contents(VERSION_DOSYA);
    $v = $v !== false ? trim($v) : '';
    return $v !== '' ? $v : '0.0.0';
}

// PowerShell ile HTTP GET (bundled PHP'de curl yok; User-Agent GitHub icin zorunlu)
function gh_son_surum(): array
{
    $url = 'https://api.github.com/repos/' . GH_REPO . '/releases/latest';
    $ps  = "try { (Invoke-WebRequest -Uri '" . $url . "' -UseBasicParsing "
         . "-Headers @{'User-Agent'='MobiStokV2-Updater'} -TimeoutSec 20).Content } "
         . "catch { 'HATA:' + \$_.Exception.Message }";
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . $ps . '" 2>&1';
    exec($cmd, $out);
    $raw = trim(implode("\n", $out));

    if (strpos($raw, 'HATA:') === 0) {
        if (stripos($raw, '404') !== false || stripos($raw, 'Not Found') !== false) {
            return ['durum' => 'yok']; // henuz yayinlanmis surum yok
        }
        return ['durum' => 'baglanti_hatasi', 'detay' => trim(substr($raw, 5))];
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['tag_name'])) {
        return ['durum' => 'yok'];
    }
    return [
        'durum'  => 'var',
        'tag'    => (string)$j['tag_name'],
        'notlar' => (string)($j['body'] ?? ''),
        'tarih'  => (string)($j['published_at'] ?? ''),
        'ad'     => (string)($j['name'] ?? $j['tag_name']),
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ---------------- KONTROL ---------------- */
if ($action === 'kontrol') {
    $mevcut = mevcut_surum();
    $s = gh_son_surum();

    if ($s['durum'] === 'baglanti_hatasi') {
        yanit(['ok' => false, 'hata' => 'GitHub bağlantısı kurulamadı. İnternet erişimini kontrol edin.',
               'detay' => $s['detay'] ?? '']);
    }
    if ($s['durum'] === 'yok') {
        yanit(['ok' => true, 'guncel_var' => false, 'mevcut' => $mevcut,
               'mesaj' => 'Şu an güncelsiniz. (Yayınlanmış yeni sürüm yok.)']);
    }

    $yeni = ltrim($s['tag'], 'vV');
    $guncelVar = version_compare($yeni, ltrim($mevcut, 'vV'), '>');

    yanit([
        'ok'         => true,
        'guncel_var' => $guncelVar,
        'mevcut'     => $mevcut,
        'yeni'       => $yeni,
        'tag'        => $s['tag'],
        'baslik'     => $s['ad'],
        'notlar'     => $s['notlar'],
        'tarih'      => $s['tarih'],
        'mesaj'      => $guncelVar ? '' : 'Zaten en güncel sürümü kullanıyorsunuz.',
    ]);
}

/* ---------------- UYGULA ---------------- */
if ($action === 'uygula') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        yanit(['ok' => false, 'hata' => 'Sadece POST.']);
    }
    check_csrf();

    $tag = trim($_POST['tag'] ?? '');
    if (!preg_match('/^v?\d+\.\d+\.\d+$/', $tag)) {
        yanit(['ok' => false, 'hata' => 'Geçersiz sürüm etiketi.']);
    }

    $proj   = realpath(PROJE_DIZ) ?: dirname(__DIR__);
    $dbFile = $proj . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'telefoncu.db';

    // 1) Veritabanini yedekle (guvenlik) — basaramazsak iptal
    $yedekAd = 'yedek_guncelleme_' . date('Ymd_His') . '.db';
    $yedek   = $proj . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $yedekAd;
    if (file_exists($dbFile) && !@copy($dbFile, $yedek)) {
        yanit(['ok' => false, 'hata' => 'Veritabanı yedeklenemedi — güncelleme iptal edildi.']);
    }

    // 2) Indir + ac + SADECE kod klasorlerini kopyala (data/ ve bin/ arsivde yok)
    $ps = <<<'PSCRIPT'
$ErrorActionPreference='Stop'
try {
  $repo='__REPO__'
  $tag='__TAG__'
  $proj='__PROJ__'
  $tmp=Join-Path $env:TEMP ('mobistok_upd_' + [Guid]::NewGuid().ToString('N'))
  New-Item -ItemType Directory -Path $tmp -Force | Out-Null
  $zip=Join-Path $tmp 'src.zip'
  $url='https://github.com/' + $repo + '/archive/refs/tags/' + $tag + '.zip'
  Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing -TimeoutSec 120
  Expand-Archive -Path $zip -DestinationPath $tmp -Force
  $src=(Get-ChildItem -Path $tmp -Directory | Select-Object -First 1).FullName
  if (-not $src) { throw 'Arsiv icerigi bulunamadi' }
  foreach ($klas in @('app','public')) {
    $s=Join-Path $src $klas
    if (Test-Path $s) {
      $d=Join-Path $proj $klas
      robocopy $s $d /E /NFL /NDL /NJH /NJS /NC /NS /NP > $null 2>&1
      if ($LASTEXITCODE -ge 8) { throw ($klas + ' kopyalanamadi (robocopy=' + $LASTEXITCODE + ')') }
    }
  }
  if (Test-Path (Join-Path $src 'VERSION')) { Copy-Item (Join-Path $src 'VERSION') $proj -Force }
  Get-ChildItem -Path $src -Filter *.bat -File | ForEach-Object { Copy-Item $_.FullName $proj -Force }
  Remove-Item -Path $tmp -Recurse -Force -ErrorAction SilentlyContinue
  Write-Output 'TAMAM'
} catch {
  Write-Output ('HATA:' + $_.Exception.Message)
}
PSCRIPT;

    $ps = str_replace(
        ['__REPO__', '__TAG__', '__PROJ__'],
        [GH_REPO, $tag, $proj],
        $ps
    );

    $scriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mobistok_apply_' . uniqid() . '.ps1';
    file_put_contents($scriptFile, $ps);

    set_time_limit(180);
    exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . $scriptFile . '" 2>&1', $out);
    @unlink($scriptFile);

    $res = trim(implode("\n", $out));
    if (strpos($res, 'TAMAM') !== false && strpos($res, 'HATA:') === false) {
        yanit(['ok' => true, 'mesaj' => 'Güncelleme tamamlandı (v' . ltrim($tag, 'vV') . ').',
               'yedek' => $yedekAd]);
    }
    yanit(['ok' => false, 'hata' => 'Güncelleme uygulanamadı: ' . mb_substr($res, 0, 400),
           'yedek' => $yedekAd]);
}

yanit(['ok' => false, 'hata' => 'Bilinmeyen işlem.']);
