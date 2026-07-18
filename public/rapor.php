<?php
require __DIR__ . '/../app/bootstrap.php';

$kats = categories($pdo);
$palet = chart_palette();

// Verideki yıllar (satış + alış), yoksa içinde bulunulan yıl
$yillar = $pdo->query("
    SELECT DISTINCT strftime('%Y', sale_date) AS y FROM devices WHERE sale_date IS NOT NULL
    UNION SELECT DISTINCT strftime('%Y', purchase_date) FROM devices
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);
$yillar = array_values(array_filter($yillar));
if (!$yillar) $yillar = [date('Y')];

$yil = $_GET['yil'] ?? $yillar[0];
if (!in_array($yil, $yillar, true)) $yil = $yillar[0];

$veri = monthly_sales($pdo, "$yil-01", "$yil-12");

// Tablo verisi: 12 ay x (her kategori satış, toplam, her kategori kâr, kâr toplam)
$tablo = [];
$yilTop = ['satis' => array_fill_keys(array_column($kats, 'id'), 0.0), 'satisT' => 0.0,
           'kar'   => array_fill_keys(array_column($kats, 'id'), 0.0), 'karT'   => 0.0];
for ($m = 1; $m <= 12; $m++) {
    $ayKey = sprintf('%s-%02d', $yil, $m);
    $satir = ['ay' => $m . '.AY', 'satis' => [], 'satisT' => 0.0, 'kar' => [], 'karT' => 0.0];
    foreach ($kats as $k) {
        $kid = (int)$k['id'];
        $s = $veri[$ayKey][$kid]['satis'] ?? 0.0;
        $kr = $veri[$ayKey][$kid]['kar'] ?? 0.0;
        $satir['satis'][$kid] = $s;
        $satir['kar'][$kid] = $kr;
        $satir['satisT'] += $s;
        $satir['karT'] += $kr;
        $yilTop['satis'][$kid] += $s;
        $yilTop['kar'][$kid] += $kr;
    }
    $yilTop['satisT'] += $satir['satisT'];
    $yilTop['karT'] += $satir['karT'];
    $tablo[] = $satir;
}

// Excel'e aktarma verisi
$basliklar = ['AY'];
foreach ($kats as $k) $basliklar[] = $k['name'];
$basliklar[] = 'TOPLAM';
foreach ($kats as $k) $basliklar[] = 'KAR ' . $k['name'];
$basliklar[] = 'KAR TOPLAM';
$export = [$basliklar];
foreach ($tablo as $t) {
    $satir = [$t['ay']];
    foreach ($kats as $k) $satir[] = $t['satis'][(int)$k['id']];
    $satir[] = $t['satisT'];
    foreach ($kats as $k) $satir[] = $t['kar'][(int)$k['id']];
    $satir[] = $t['karT'];
    $export[] = $satir;
}
$satir = ['TOPLAM'];
foreach ($kats as $k) $satir[] = $yilTop['satis'][(int)$k['id']];
$satir[] = $yilTop['satisT'];
foreach ($kats as $k) $satir[] = $yilTop['kar'][(int)$k['id']];
$satir[] = $yilTop['karT'];
$export[] = $satir;

// Grafik serileri
$etiketler = [];
for ($m = 1; $m <= 12; $m++) $etiketler[] = $m . '.AY';
$satisSeri = []; $karSeri = []; $toplamSatis = []; $toplamKar = [];
foreach ($kats as $i => $k) {
    $kid = (int)$k['id'];
    $renk = $palet[$i % count($palet)];
    $satisSeri[] = ['label' => $k['name'], 'data' => array_map(fn($t) => $t['satis'][$kid], $tablo), 'backgroundColor' => $renk];
    $karSeri[]   = ['label' => $k['name'], 'data' => array_map(fn($t) => $t['kar'][$kid], $tablo), 'backgroundColor' => $renk];
}
$toplamSatis = array_map(fn($t) => $t['satisT'], $tablo);
$toplamKar = array_map(fn($t) => $t['karT'], $tablo);

// Serbest tarih aralığı özeti
$b1 = $_GET['b1'] ?? '';
$b2 = $_GET['b2'] ?? '';
$aralik = null;
if ($b1 !== '' && $b2 !== '') {
    $aralik = range_sales_summary($pdo, $b1, $b2);
}

page_header('Rapor', 'rapor');
?>
<div class="sayfa-alt" style="margin:0 0 14px">
  <h1 style="margin:0">Rapor — <?= e($yil) ?></h1>
  <div class="actions">
    <form method="get">
      <select name="yil" onchange="this.form.submit()">
        <?php foreach ($yillar as $y): ?>
          <option value="<?= e($y) ?>" <?= $y === $yil ? 'selected' : '' ?>><?= e($y) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <button class="btn sec" onclick="excelAktar(EXPORT_DATA, 'rapor_<?= e($yil) ?>', 'Rapor <?= e($yil) ?>')">Excel'e Aktar</button>
  </div>
</div>

<div class="table-wrap" style="margin-bottom:20px">
<table>
  <thead>
    <tr>
      <th>Ay</th>
      <?php foreach ($kats as $k): ?><th class="num"><?= e($k['name']) ?></th><?php endforeach; ?>
      <th class="num">TOPLAM</th>
      <?php foreach ($kats as $k): ?><th class="num">KAR <?= e($k['name']) ?></th><?php endforeach; ?>
      <th class="num">KAR TOPLAM</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($tablo as $t): ?>
    <tr>
      <td><?= e($t['ay']) ?></td>
      <?php foreach ($kats as $k): ?><td class="num"><?= tl($t['satis'][(int)$k['id']]) ?></td><?php endforeach; ?>
      <td class="num"><strong><?= tl($t['satisT']) ?></strong></td>
      <?php foreach ($kats as $k): ?><td class="num"><?= tl($t['kar'][(int)$k['id']]) ?></td><?php endforeach; ?>
      <td class="num <?= $t['karT'] >= 0 ? 'kar-poz' : 'kar-neg' ?>"><?= tl($t['karT']) ?></td>
    </tr>
  <?php endforeach; ?>
  <tr class="toplam">
    <td>TOPLAM</td>
    <?php foreach ($kats as $k): ?><td class="num"><?= tl($yilTop['satis'][(int)$k['id']]) ?></td><?php endforeach; ?>
    <td class="num"><?= tl($yilTop['satisT']) ?></td>
    <?php foreach ($kats as $k): ?><td class="num"><?= tl($yilTop['kar'][(int)$k['id']]) ?></td><?php endforeach; ?>
    <td class="num <?= $yilTop['karT'] >= 0 ? 'kar-poz' : 'kar-neg' ?>"><?= tl($yilTop['karT']) ?></td>
  </tr>
  </tbody>
</table>
</div>

<div class="panel-grid">
  <div class="panel">
    <div class="chart-title">Kategori Bazlı Satışlar (TL)</div>
    <div class="chart-wrap"><canvas id="g1"></canvas></div>
  </div>
  <div class="panel">
    <div class="chart-title">Toplam Satışlar (TL)</div>
    <div class="chart-wrap"><canvas id="g2"></canvas></div>
  </div>
  <div class="panel">
    <div class="chart-title">Kategori Bazlı Kâr (TL)</div>
    <div class="chart-wrap"><canvas id="g3"></canvas></div>
  </div>
  <div class="panel">
    <div class="chart-title">Toplam Kâr (TL)</div>
    <div class="chart-wrap"><canvas id="g4"></canvas></div>
  </div>
</div>

<h2>Tarih Aralığı Özeti</h2>
<form method="get" class="filters">
  <input type="hidden" name="yil" value="<?= e($yil) ?>">
  <div>
    <label>Başlangıç</label>
    <input type="date" name="b1" value="<?= e($b1) ?>" required>
  </div>
  <div>
    <label>Bitiş</label>
    <input type="date" name="b2" value="<?= e($b2) ?>" required>
  </div>
  <div><button class="btn sec">Hesapla</button></div>
</form>

<?php if ($aralik !== null): ?>
<div class="table-wrap" style="max-width:640px">
<table>
  <thead><tr><th>Kategori</th><th class="num">Satış Adedi</th><th class="num">Ciro</th><th class="num">Kâr</th></tr></thead>
  <tbody>
  <?php
  $ta = 0; $tc = 0.0; $tk = 0.0;
  foreach ($kats as $k):
      $o = $aralik[(int)$k['id']] ?? ['adet' => 0, 'ciro' => 0.0, 'kar' => 0.0];
      $ta += $o['adet']; $tc += $o['ciro']; $tk += $o['kar'];
  ?>
    <tr>
      <td><?= e($k['name']) ?></td>
      <td class="num"><?= $o['adet'] ?></td>
      <td class="num"><?= tl($o['ciro']) ?></td>
      <td class="num <?= $o['kar'] >= 0 ? 'kar-poz' : 'kar-neg' ?>"><?= tl($o['kar']) ?></td>
    </tr>
  <?php endforeach; ?>
  <tr class="toplam">
    <td>TOPLAM</td>
    <td class="num"><?= $ta ?></td>
    <td class="num"><?= tl($tc) ?></td>
    <td class="num <?= $tk >= 0 ? 'kar-poz' : 'kar-neg' ?>"><?= tl($tk) ?></td>
  </tr>
  </tbody>
</table>
</div>
<?php endif; ?>

<script src="assets/chart.umd.min.js"></script>
<script src="assets/xlsx.full.min.js"></script>
<script src="assets/app.js"></script>
<script>
const EXPORT_DATA = <?= json_encode($export, JSON_UNESCAPED_UNICODE) ?>;
const ETIKETLER = <?= json_encode($etiketler, JSON_UNESCAPED_UNICODE) ?>;
const trSayi = v => new Intl.NumberFormat('tr-TR').format(v);

function cizim(id, datasets, legendGoster) {
  new Chart(document.getElementById(id), {
    type: 'bar',
    data: { labels: ETIKETLER, datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: legendGoster
          ? { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: '#52514e' } }
          : { display: false },
        tooltip: { callbacks: { label: c => c.dataset.label + ': ' + trSayi(c.parsed.y) + ' TL' } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#898781' }, border: { color: '#c3c2b7' } },
        y: { grid: { color: '#e1e0d9' }, ticks: { color: '#898781', callback: v => trSayi(v) },
             border: { display: false }, beginAtZero: true }
      },
      datasets: { bar: { borderRadius: 4, barPercentage: 0.7, categoryPercentage: 0.7 } }
    }
  });
}

cizim('g1', <?= json_encode($satisSeri, JSON_UNESCAPED_UNICODE) ?>, true);
cizim('g2', [{ label: 'Toplam Satış', data: <?= json_encode($toplamSatis) ?>, backgroundColor: '#2a78d6' }], false);
cizim('g3', <?= json_encode($karSeri, JSON_UNESCAPED_UNICODE) ?>, true);
cizim('g4', [{ label: 'Toplam Kâr', data: <?= json_encode($toplamKar) ?>, backgroundColor: '#1baf7a' }], false);
</script>
<?php page_footer(); ?>
