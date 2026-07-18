<?php
// Yardımcılar: biçimlendirme, ayarlar, kategoriler, rapor/stok sorguları, sayfa şablonu

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Türkçe büyük/küçük harf dönüşümü (PHP mb_strtolower I→ı farkını bilmiyor)
function tr_lower(string $s): string
{
    return mb_strtolower(
        str_replace(
            ['I',  'İ', 'Ğ', 'Ş', 'Ç', 'Ö', 'Ü'],
            ['ı',  'i', 'ğ', 'ş', 'ç', 'ö', 'ü'],
            $s
        ),
        'UTF-8'
    );
}

// Para biçimi: 9.750 / 9.750,50 (tam sayıysa kuruş gösterme)
function tl($v): string
{
    if ($v === null || $v === '') return '';
    $v = (float)$v;
    $decimals = (floor($v) == $v) ? 0 : 2;
    return number_format($v, $decimals, ',', '.');
}

// ISO tarih -> gg.aa.yyyy
function trdate(?string $iso): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? date('d.m.Y', $t) : $iso;
}

// Form girişindeki fiyatı normalize et: "9.750,50" / "9750.50" / "9750" hepsi kabul
function parse_price($s): ?float
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace(' ', '', $s);
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);   // binlik ayracı
        $s = str_replace(',', '.', $s);  // ondalık
    } elseif (substr_count($s, '.') > 1 || preg_match('/\.\d{3}$/', $s)) {
        // "10.500" veya "1.234.567" Türk binlik ayracıdır; ondalık için virgül kullanılır
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

/* ---------- IMEI Şifreleme ----------
 * Anahtar veritabanının settings tablosunda saklanır (imei_key).
 * DB yedeği alındığında anahtar da beraberinde gelir — ayrı dosya kaybı riski yok.
 * AES-256-ECB: deterministik (aynı giriş = aynı çıkış) → SQL exact-match araması çalışır.
 * --------------------------------------------------------- */

function imei_key(): string
{
    static $k = null;
    if ($k === null) {
        $pdo = db();  // singleton; bu noktada zaten başlatılmış
        $st  = $pdo->prepare("SELECT value FROM settings WHERE key='imei_key'");
        $st->execute();
        $hex = (string)$st->fetchColumn();
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            $hex = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO settings (key,value) VALUES ('imei_key',?)
                           ON CONFLICT(key) DO UPDATE SET value=excluded.value")
                ->execute([$hex]);
        }
        $k = hex2bin($hex);
    }
    return $k;
}

function imei_enc(string $plain): string
{
    if ($plain === '') return '';
    $enc = openssl_encrypt($plain, 'aes-256-ecb', imei_key(), OPENSSL_RAW_DATA);
    return $enc !== false ? base64_encode($enc) : $plain;
}

function imei_dec(string $enc): string
{
    if ($enc === '') return '';
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) === 0) return $enc; // şifresiz eski veri
    $plain = openssl_decrypt($raw, 'aes-256-ecb', imei_key(), OPENSSL_RAW_DATA);
    return ($plain !== false) ? $plain : $enc;
}

/* ---------- Ayarlar ---------- */

function setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $st = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
}

/* ---------- Kategoriler ---------- */

function categories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
}

function category_by_name(PDO $pdo, string $name): ?array
{
    $st = $pdo->prepare('SELECT * FROM categories WHERE name = ? COLLATE NOCASE');
    $st->execute([trim($name)]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- CSRF ---------- */

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Geçersiz istek (CSRF).');
    }
}

/* ---------- Mesajlar (flash) ---------- */

function flash_set(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_get(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/* ---------- Sorgular ---------- */

// Stokta olanların kategori bazlı özeti: [category_id => ['adet'=>, 'maliyet'=>]]
function stock_summary(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT category_id, COUNT(*) AS adet, COALESCE(SUM(purchase_price),0) AS maliyet
        FROM devices WHERE sale_date IS NULL
        GROUP BY category_id
    ")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['category_id']] = ['adet' => (int)$r['adet'], 'maliyet' => (float)$r['maliyet']];
    }
    return $out;
}

// Aylık satış/kâr toplamları: ['YYYY-MM'][category_id] = ['satis'=>, 'kar'=>]
function monthly_sales(PDO $pdo, string $fromMonth, string $toMonth): array
{
    $st = $pdo->prepare("
        SELECT strftime('%Y-%m', sale_date) AS ay, category_id,
               COALESCE(SUM(sale_price),0) AS satis,
               COALESCE(SUM(profit),0) AS kar
        FROM devices
        WHERE sale_date IS NOT NULL
          AND strftime('%Y-%m', sale_date) BETWEEN ? AND ?
        GROUP BY ay, category_id
    ");
    $st->execute([$fromMonth, $toMonth]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['ay']][(int)$r['category_id']] = ['satis' => (float)$r['satis'], 'kar' => (float)$r['kar']];
    }
    return $out;
}

// Tarih aralığı satış özeti: [category_id => ['adet'=>, 'ciro'=>, 'kar'=>]]
function range_sales_summary(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("
        SELECT category_id, COUNT(*) AS adet,
               COALESCE(SUM(sale_price),0) AS ciro,
               COALESCE(SUM(profit),0) AS kar
        FROM devices
        WHERE sale_date IS NOT NULL AND sale_date BETWEEN ? AND ?
        GROUP BY category_id
    ");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['category_id']] = [
            'adet' => (int)$r['adet'], 'ciro' => (float)$r['ciro'], 'kar' => (float)$r['kar'],
        ];
    }
    return $out;
}

// Model/Satıcı öneri listeleri (datalist için)
function distinct_values(PDO $pdo, string $column): array
{
    $col = in_array($column, ['model', 'seller'], true) ? $column : 'model';
    return $pdo->query("SELECT DISTINCT $col FROM devices WHERE $col <> '' ORDER BY $col COLLATE NOCASE")
               ->fetchAll(PDO::FETCH_COLUMN);
}

/* ---------- Grafik paleti (dataviz referans paleti, sabit sıra) ---------- */

function chart_palette(): array
{
    return ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834'];
}

/* ---------- Sayfa şablonu ---------- */

function page_header(string $title, string $active = ''): void
{
    global $pdo;
    $shop = setting($pdo, 'shop_name', 'Telefoncu');
    $items = [
        'index'    => ['index.php', 'Panel'],
        'cihazlar' => ['cihazlar.php', 'Cihazlar'],
        'stok'     => ['stok.php', 'Stok'],
        'rapor'    => ['rapor.php', 'Rapor'],
        'ayarlar'  => ['ayarlar.php', 'Ayarlar'],
    ];
    // Yedekleme uyarısı: Drive hiç bağlanmamış veya bağlantı kopuk
    $gdriveBagli = setting($pdo, 'gdrive_bagli', '0');
    $yedekUyari  = ($gdriveBagli !== '1');
    ?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($shop) ?></title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><?= e($shop) ?></a>
    <nav>
      <?php foreach ($items as $key => [$href, $label]): ?>
        <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <div class="topbar-sag">
        <?php if ($yedekUyari): ?>
          <a href="ayarlar.php" class="gdrive-uyari" title="Otomatik yedekleme bağlantısı kopuk">⚠ Yedeklenmiyor</a>
        <?php endif; ?>
        <a href="logout.php" class="cikis">Çıkış</a>
      </div>
    </nav>
  </div>
</header>
<main class="container">
<script>
// Otomatik Google Drive yedeği — arka planda, yanıt beklenmez
// LocalStorage'da "son kontrol" kaydı tutulur; sunucuya en fazla 30 dk'da bir gidilir.
window.addEventListener('load', () => {
  const SON_KONTROL_KEY = 'gdrive_son_kontrol';
  const KONTROL_ARALIGI = 4 * 60 * 60 * 1000; // 4 saat (ms)
  const sonKontrol = parseInt(localStorage.getItem(SON_KONTROL_KEY) || '0', 10);
  if (Date.now() - sonKontrol < KONTROL_ARALIGI) return; // Henüz erken, istek atma
  fetch('api_gdrive.php?action=otomatik')
    .then(() => localStorage.setItem(SON_KONTROL_KEY, Date.now()))
    .catch(() => {});
});
</script>
<?php
    $f = flash_get();
    if ($f) {
        echo '<div class="flash ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function page_footer(): void
{
    ?>
</main>
<footer class="site-footer">
  <div class="site-footer-ic">
    <span class="site-footer-brand">MobiStokV2</span>
    <span class="site-footer-sep">·</span>
    <a href="mailto:mehmetbtk@gmail.com">mehmetbtk@gmail.com</a>
    <span class="site-footer-sep">·</span>
    <a href="tel:+905435217476">0543 521 74 76</a>
  </div>
</footer>
</body>
</html><?php
}
