<?php
require __DIR__ . '/../app/bootstrap.php';

$kats = categories($pdo);

// Kategori bazlı özet (adet + maliyet)
$ozet = stock_summary($pdo);
$genelAdet = array_sum(array_column($ozet, 'adet'));
$genelMaliyet = array_sum(array_column($ozet, 'maliyet'));

// Sayım ilerlemesi
$sd = $pdo->query("SELECT COUNT(*) AS toplam, COALESCE(SUM(CASE WHEN counted=1 THEN 1 ELSE 0 END),0) AS sayilan
                   FROM devices WHERE sale_date IS NULL")->fetch();
$sayilan = (int)$sd['sayilan'];

// Stokta olan tüm cihazlar (ürün bazlı)
$rows = $pdo->query("
    SELECT d.id, d.imei, d.model, d.category_id, c.name AS kategori,
           d.purchase_date, d.purchase_price, d.counted
    FROM devices d JOIN categories c ON c.id = d.category_id
    WHERE d.sale_date IS NULL
    ORDER BY c.sort_order, d.model COLLATE NOCASE, d.id
")->fetchAll();

foreach ($rows as &$r) {
    $r['imei'] = imei_dec($r['imei']);
}
unset($r);

// Satır verisi — JS sadece görünen sayfayı çizer (tüm satırlar DOM'a basılmaz, hız için)
$data = [];
foreach ($rows as $r) {
    $data[] = [
        'id'       => (int)$r['id'],
        'kat'      => (string)$r['category_id'],
        'counted'  => (int)$r['counted'] === 1 ? 1 : 0,
        'imei'     => $r['imei'],
        'model'    => $r['model'],
        'kategori' => $r['kategori'],
        'pd'       => trdate($r['purchase_date']),
        'maliyet'  => (float)$r['purchase_price'],
        'ara'      => tr_lower($r['imei'] . ' ' . $r['model'] . ' ' . $r['kategori']),
    ];
}

page_header('Stok', 'stok');
?>
<div class="sayfa-alt" style="margin:0 0 14px">
  <h1 style="margin:0">Stok / Sayım</h1>
  <div class="actions">
    <button class="btn sec" onclick="gorunenleriAktar()">Excel'e Aktar</button>
    <button class="btn tehlike" id="sifirlaBtn">Sayımı Sıfırla</button>
  </div>
</div>

<div class="cards">
  <?php foreach ($kats as $k): $o = $ozet[(int)$k['id']] ?? ['adet' => 0, 'maliyet' => 0]; ?>
    <div class="card">
      <div class="k"><?= e($k['name']) ?> STOK</div>
      <div class="v"><?= tl($o['maliyet']) ?> TL</div>
      <div class="alt"><?= $o['adet'] ?> adet</div>
    </div>
  <?php endforeach; ?>
  <div class="card" style="background:#1a1a19;color:#fff;border-color:#1a1a19">
    <div class="k" style="color:#c3c2b7">TOPLAM STOK</div>
    <div class="v"><?= tl($genelMaliyet) ?> TL</div>
    <div class="alt" style="color:#c3c2b7"><?= $genelAdet ?> adet</div>
  </div>
  <div class="card" style="background:#e6f5e6;border-color:#b5dfb5">
    <div class="k" style="color:#006300">SAYILAN</div>
    <div class="v" style="color:#006300"><span id="sayilanAdet"><?= $sayilan ?></span> / <?= $genelAdet ?></div>
    <div class="alt" style="color:#006300"><span id="kalanAdet"><?= $genelAdet - $sayilan ?></span> kaldı</div>
  </div>
</div>

<div class="filtre-butonlar">
  <div>
    <label>Kategori</label>
    <div class="seg" id="katSeg">
      <a href="#" class="active" data-kat="">Tümü</a>
      <?php foreach ($kats as $k): ?>
        <a href="#" data-kat="<?= $k['id'] ?>"><?= e($k['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div>
    <label>Sayım Durumu</label>
    <div class="seg" id="durumSeg">
      <a href="#" class="active" data-durum="">Tümü</a>
      <a href="#" data-durum="0" class="st-satildi">Sayılmayan</a>
      <a href="#" data-durum="1" class="st-stok">Sayılan</a>
    </div>
  </div>
  <div class="grow">
    <label>Barkod / Model ara <span class="muted">(barkodu okutup Enter → sayıldı işaretlenir)</span></label>
    <input type="text" id="araKutu" placeholder="Barkod okut veya model yaz..." autocomplete="off" autofocus>
  </div>
</div>
<div id="barkodMesaj" style="min-height:20px;font-size:.85rem;font-weight:600;margin:-4px 0 10px"></div>

<div class="table-wrap">
<table>
  <thead>
    <tr>
      <th style="width:44px;text-align:center">✓</th>
      <th>Barkod (IMEI)</th><th>Model</th><th>Kategori</th>
      <th>Alış Tarihi</th><th class="num">Maliyet</th>
    </tr>
  </thead>
  <tbody id="satirGovde"><!-- satırlar JS ile çizilir --></tbody>
  <tfoot>
    <tr class="toplam">
      <td></td>
      <td colspan="4"><span id="gorunurAdet"><?= $genelAdet ?></span> ürün görünüyor</td>
      <td class="num" id="gorunurMaliyet"><?= tl($genelMaliyet) ?></td>
    </tr>
  </tfoot>
</table>
</div>

<div id="sayfalama" style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
  <span class="muted" id="sayfa-bilgi" style="font-size:.85rem"></span>
  <div style="display:flex;gap:3px;flex-wrap:wrap" id="sayfa-butonlar"></div>
  <div style="margin-left:auto;display:flex;align-items:center;gap:6px">
    <label class="muted" style="font-size:.82rem">Sayfa başına:</label>
    <select id="sayfa-boyutu" style="width:auto;padding:3px 6px">
      <option value="25">25</option>
      <option value="50">50</option>
      <option value="100">100</option>
      <option value="9999">Tümü</option>
    </select>
  </div>
</div>

<script src="assets/xlsx.full.min.js"></script>
<script src="assets/app.js"></script>
<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
const trSayi = v => new Intl.NumberFormat('tr-TR').format(v);
const esc = s => (s == null ? '' : String(s)).replace(/[&<>"']/g,
  c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const dataMap = {};
DATA.forEach(d => dataMap[d.id] = d);

const araKutu = document.getElementById('araKutu');
const govde   = document.getElementById('satirGovde');

let filtreKat   = '';
let filtreDurum = '';
let sayfaBoyutu = parseInt(localStorage.getItem('stok_sb') || '50');
let mevcutSayfa = 1;
let filtreli    = [];

// ---- Filtre butonları ----
document.querySelectorAll('#katSeg a').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  document.querySelectorAll('#katSeg a').forEach(x => x.classList.remove('active'));
  a.classList.add('active');
  filtreKat = a.dataset.kat;
  mevcutSayfa = 1; filtrele();
}));
document.querySelectorAll('#durumSeg a').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  document.querySelectorAll('#durumSeg a').forEach(x => x.classList.remove('active'));
  a.classList.add('active');
  filtreDurum = a.dataset.durum;
  mevcutSayfa = 1; filtrele();
}));
araKutu.addEventListener('input', () => { mevcutSayfa = 1; filtrele(); });

// ---- Sayfa boyutu ----
const sbSec = document.getElementById('sayfa-boyutu');
sbSec.value = sayfaBoyutu;
sbSec.addEventListener('change', () => {
  sayfaBoyutu = parseInt(sbSec.value);
  localStorage.setItem('stok_sb', sayfaBoyutu);
  mevcutSayfa = 1; ciz();
});

// ---- Filtrele (tüm veri üzerinde) ----
function filtrele() {
  const ham = araKutu.value.trim();
  const q1 = ham.toLocaleLowerCase('tr');
  const q2 = ham.toLowerCase();
  let adet = 0, maliyet = 0;
  filtreli = [];
  for (const d of DATA) {
    const katOk   = !filtreKat || d.kat === filtreKat;
    const durumOk = filtreDurum === '' || String(d.counted) === filtreDurum;
    const araOk   = !ham || d.ara.includes(q1) || d.ara.includes(q2);
    if (katOk && durumOk && araOk) { filtreli.push(d); adet++; maliyet += d.maliyet || 0; }
  }
  document.getElementById('gorunurAdet').textContent = adet;
  document.getElementById('gorunurMaliyet').textContent = trSayi(maliyet);
  ciz();
}

// ---- Tek satırın HTML'i ----
function satirHtml(d) {
  const c = d.counted === 1;
  return `<tr class="stok-satir${c ? ' sayildi' : ''}" data-id="${d.id}">
    <td style="text-align:center"><input type="checkbox" class="sayim-kutu"${c ? ' checked' : ''}></td>
    <td>${d.imei ? esc(d.imei) : '<span class="muted">—</span>'}</td>
    <td>${esc(d.model)}</td>
    <td><span class="badge">${esc(d.kategori)}</span></td>
    <td>${esc(d.pd)}</td>
    <td class="num">${trSayi(d.maliyet)}</td>
  </tr>`;
}

function sayfaNumaralari(mevcut, toplam) {
  if (toplam <= 7) return Array.from({length: toplam}, (_, i) => i + 1);
  if (mevcut <= 4) return [1,2,3,4,5,'…',toplam];
  if (mevcut >= toplam - 3) return [1,'…',toplam-4,toplam-3,toplam-2,toplam-1,toplam];
  return [1,'…',mevcut-1,mevcut,mevcut+1,'…',toplam];
}

// ---- Sadece görünen sayfayı çiz ----
function ciz() {
  const toplam = filtreli.length;
  const toplamSayfa = Math.max(1, Math.ceil(toplam / sayfaBoyutu));
  if (mevcutSayfa > toplamSayfa) mevcutSayfa = toplamSayfa;
  const bas = (mevcutSayfa - 1) * sayfaBoyutu;
  const bit = Math.min(bas + sayfaBoyutu, toplam);

  if (toplam === 0) {
    govde.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center;padding:24px">Kayıt bulunamadı.</td></tr>';
  } else {
    let html = '';
    for (let i = bas; i < bit; i++) html += satirHtml(filtreli[i]);
    govde.innerHTML = html;
  }

  document.getElementById('sayfa-bilgi').textContent =
    toplam ? `${bas + 1}–${bit} / ${toplam} kayıt` : '';

  const kap = document.getElementById('sayfa-butonlar');
  kap.innerHTML = '';
  if (toplamSayfa > 1) {
    const ekle = (icerik, sayfa, disabled) => {
      const b = document.createElement('button');
      b.className = 'btn kucuk' + (sayfa === mevcutSayfa ? '' : ' sec');
      b.textContent = icerik;
      b.disabled = disabled;
      if (!disabled) b.onclick = () => { mevcutSayfa = sayfa; ciz(); };
      kap.appendChild(b);
    };
    ekle('‹', mevcutSayfa - 1, mevcutSayfa === 1);
    sayfaNumaralari(mevcutSayfa, toplamSayfa).forEach(p => {
      if (p === '…') { const s = document.createElement('span'); s.textContent = '…'; s.style.padding = '0 4px'; kap.appendChild(s); }
      else ekle(p, p, false);
    });
    ekle('›', mevcutSayfa + 1, mevcutSayfa === toplamSayfa);
  }
}

// ---- Sayım işaretleme (kalıcı) — olay delegasyonu ----
govde.addEventListener('change', e => {
  const kutu = e.target.closest('.sayim-kutu');
  if (!kutu) return;
  const tr = kutu.closest('tr');
  isaretle(tr.dataset.id, kutu.checked);
});

function isaretle(id, durum) {
  const d = dataMap[id];
  if (!d) return;
  const eski = d.counted;
  d.counted = durum ? 1 : 0;
  sayimIlerleme();
  filtrele();   // yeniden çiz (satır sayılan/sayılmayan filtresinden çıkabilir)

  fetch('api_sayim.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
    body: JSON.stringify({ action: 'set', id: id, counted: durum ? 1 : 0 })
  })
  .then(r => r.json())
  .then(v => { if (!v.ok) throw new Error(v.hata || 'hata'); })
  .catch(err => {
    d.counted = eski;            // hata olursa geri al
    sayimIlerleme(); filtrele();
    alert('Sayım kaydedilemedi: ' + err.message);
  });
}

function sayimIlerleme() {
  const sayilan = DATA.filter(d => d.counted === 1).length;
  document.getElementById('sayilanAdet').textContent = sayilan;
  document.getElementById('kalanAdet').textContent = DATA.length - sayilan;
}

// ---- Barkod okut + Enter → eşleşen ürünü işaretle ----
function barkodMesaj(metin, hata) {
  const el = document.getElementById('barkodMesaj');
  el.textContent = metin;
  el.style.color = hata ? '#d03b3b' : '#006300';
  el.style.fontWeight = '600';
  clearTimeout(barkodMesaj._t);
  barkodMesaj._t = setTimeout(() => { el.textContent = ''; }, 2500);
}

araKutu.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const kod = araKutu.value.trim().toLowerCase();
  if (!kod) return;
  // Tam barkod eşleşmesi (önce henüz sayılmamış)
  let hedef = DATA.find(d => d.imei.toLowerCase() === kod && d.counted === 0)
           || DATA.find(d => d.imei.toLowerCase() === kod);
  if (hedef) {
    araKutu.value = '';
    isaretle(hedef.id, true);   // veriyi güncelle + kaydet + yeniden çiz
    barkodMesaj('✓ ' + hedef.model + ' sayıldı', false);
    const tr = govde.querySelector(`tr[data-id="${hedef.id}"]`);
    if (tr) { tr.style.background = '#d4efd4'; setTimeout(() => { tr.style.background = ''; }, 800); }
  } else {
    barkodMesaj('✗ Bu barkod stokta bulunamadı', true);
    araKutu.select();
  }
});

// ---- Sayımı sıfırla ----
document.getElementById('sifirlaBtn').addEventListener('click', () => {
  if (!confirm('Tüm sayım işaretleri sıfırlanacak. Emin misin?')) return;
  fetch('api_sayim.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
    body: JSON.stringify({ action: 'reset' })
  })
  .then(r => r.json())
  .then(v => {
    if (!v.ok) throw new Error(v.hata || 'hata');
    DATA.forEach(d => d.counted = 0);
    sayimIlerleme();
    filtrele();
  })
  .catch(err => alert('Sıfırlanamadı: ' + err.message));
});

// ---- Excel: filtreli tüm satırlar (güncel sayım durumuyla) ----
function gorunenleriAktar() {
  const data = [['Barkod (IMEI)', 'Model', 'Kategori', 'Alış Tarihi', 'Maliyet', 'Sayıldı']];
  for (const d of filtreli) {
    data.push([d.imei, d.model, d.kategori, d.pd, d.maliyet, d.counted === 1 ? 'Evet' : 'Hayır']);
  }
  excelAktar(data, 'stok_sayim', 'Stok');
}

// İlk çizim
filtrele();
</script>
<?php page_footer(); ?>
