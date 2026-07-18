<?php
require __DIR__ . '/../app/bootstrap.php';

$kats = categories($pdo);
$palet = chart_palette();

// Stok özeti
$ozet = stock_summary($pdo);
$genelAdet = array_sum(array_column($ozet, 'adet'));
$genelMaliyet = array_sum(array_column($ozet, 'maliyet'));

// Bu ay / bu yıl satış-kâr
$buAy = date('Y-m');
$buYil = date('Y');
$st = $pdo->prepare("SELECT COUNT(*) AS adet, COALESCE(SUM(sale_price),0) AS ciro, COALESCE(SUM(profit),0) AS kar
                     FROM devices WHERE strftime('%Y-%m', sale_date) = ?");
$st->execute([$buAy]);
$ay = $st->fetch();
$st = $pdo->prepare("SELECT COUNT(*) AS adet, COALESCE(SUM(sale_price),0) AS ciro, COALESCE(SUM(profit),0) AS kar
                     FROM devices WHERE strftime('%Y', sale_date) = ?");
$st->execute([$buYil]);
$yil = $st->fetch();

// Son 12 ay grafik verisi
$aylar = [];
for ($i = 11; $i >= 0; $i--) {
    $aylar[] = date('Y-m', strtotime("first day of -$i month"));
}
$veri = monthly_sales($pdo, $aylar[0], end($aylar));

$ayAdlari = ['01'=>'Oca','02'=>'Şub','03'=>'Mar','04'=>'Nis','05'=>'May','06'=>'Haz',
             '07'=>'Tem','08'=>'Ağu','09'=>'Eyl','10'=>'Eki','11'=>'Kas','12'=>'Ara'];
$etiketler = [];
foreach ($aylar as $a) {
    [$y, $m] = explode('-', $a);
    $etiketler[] = $ayAdlari[$m] . ' ' . substr($y, 2);
}

$satisSeri = []; $karSeri = [];
foreach ($kats as $i => $k) {
    $kid = (int)$k['id'];
    $s = []; $kr = [];
    foreach ($aylar as $a) {
        $s[]  = $veri[$a][$kid]['satis'] ?? 0;
        $kr[] = $veri[$a][$kid]['kar'] ?? 0;
    }
    $renk = $palet[$i % count($palet)];
    $satisSeri[] = ['label' => $k['name'], 'data' => $s, 'backgroundColor' => $renk];
    $karSeri[]   = ['label' => $k['name'], 'data' => $kr, 'backgroundColor' => $renk];
}

page_header('Panel', 'index');
?>
<h1>Panel</h1>

<div class="cards">
  <?php foreach ($kats as $k): $o = $ozet[(int)$k['id']] ?? ['adet' => 0, 'maliyet' => 0]; ?>
    <div class="card">
      <div class="k"><?= e($k['name']) ?> STOK</div>
      <div class="v"><?= tl($o['maliyet']) ?> TL</div>
      <div class="alt"><?= $o['adet'] ?> adet</div>
    </div>
  <?php endforeach; ?>
  <div class="card">
    <div class="k">TOPLAM STOK</div>
    <div class="v"><?= tl($genelMaliyet) ?> TL</div>
    <div class="alt"><?= $genelAdet ?> adet</div>
  </div>
</div>

<div class="cards">
  <div class="card">
    <div class="k">BU AY SATIŞ</div>
    <div class="v"><?= tl($ay['ciro']) ?> TL</div>
    <div class="alt"><?= (int)$ay['adet'] ?> satış</div>
  </div>
  <div class="card">
    <div class="k">BU AY KÂR</div>
    <div class="v <?= (float)$ay['kar'] >= 0 ? 'kar-poz' : 'kar-neg' ?>"><?= tl($ay['kar']) ?> TL</div>
  </div>
  <div class="card">
    <div class="k">BU YIL SATIŞ (<?= $buYil ?>)</div>
    <div class="v"><?= tl($yil['ciro']) ?> TL</div>
    <div class="alt"><?= (int)$yil['adet'] ?> satış</div>
  </div>
  <div class="card">
    <div class="k">BU YIL KÂR (<?= $buYil ?>)</div>
    <div class="v <?= (float)$yil['kar'] >= 0 ? 'kar-poz' : 'kar-neg' ?>"><?= tl($yil['kar']) ?> TL</div>
  </div>
</div>

<div class="panel-grid">
  <div class="panel">
    <div class="chart-title">Son 12 Ay — Kategori Bazlı Satışlar (TL)</div>
    <div class="chart-wrap"><canvas id="grafSatis"></canvas></div>
  </div>
  <div class="panel">
    <div class="chart-title">Son 12 Ay — Kategori Bazlı Kâr (TL)</div>
    <div class="chart-wrap"><canvas id="grafKar"></canvas></div>
  </div>
</div>

<script src="assets/chart.umd.min.js"></script>
<script>
const ETIKETLER = <?= json_encode($etiketler, JSON_UNESCAPED_UNICODE) ?>;
const SATIS_SERI = <?= json_encode($satisSeri, JSON_UNESCAPED_UNICODE) ?>;
const KAR_SERI = <?= json_encode($karSeri, JSON_UNESCAPED_UNICODE) ?>;

const trSayi = v => new Intl.NumberFormat('tr-TR').format(v);
const grafikAyar = {
  type: 'bar',
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: '#52514e' } },
      tooltip: { callbacks: { label: c => c.dataset.label + ': ' + trSayi(c.parsed.y) + ' TL' } }
    },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#898781' }, border: { color: '#c3c2b7' } },
      y: { grid: { color: '#e1e0d9' }, ticks: { color: '#898781', callback: v => trSayi(v) },
           border: { display: false }, beginAtZero: true }
    },
    datasets: { bar: { borderRadius: 4, barPercentage: 0.7, categoryPercentage: 0.7 } }
  }
};

new Chart(document.getElementById('grafSatis'),
  { ...grafikAyar, data: { labels: ETIKETLER, datasets: SATIS_SERI } });
new Chart(document.getElementById('grafKar'),
  { ...grafikAyar, data: { labels: ETIKETLER, datasets: KAR_SERI } });
</script>
<?php page_footer(); ?>
