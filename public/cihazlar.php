<?php
require __DIR__ . '/../app/bootstrap.php';

$kats = categories($pdo);

// Filtreler
$kat   = $_GET['kat']   ?? '';
$durum = $_GET['durum'] ?? 'stok'; // sayfa ilk açılınca varsayılan: Stokta
$q     = trim($_GET['q'] ?? '');
$at1   = $_GET['at1'] ?? '';
$at2   = $_GET['at2'] ?? '';
$st1   = $_GET['st1'] ?? '';
$st2   = $_GET['st2'] ?? '';


// Kategori ve durum artık JS tarafında filtreleniyor — sadece tarih ve metin SQL'de
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(d.model LIKE ? OR d.seller LIKE ? OR d.note LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($at1 !== '') { $where[] = 'd.purchase_date >= ?'; $params[] = $at1; }
if ($at2 !== '') { $where[] = 'd.purchase_date <= ?'; $params[] = $at2; }
if ($st1 !== '') { $where[] = 'd.sale_date >= ?'; $params[] = $st1; }
if ($st2 !== '') { $where[] = 'd.sale_date <= ?'; $params[] = $st2; }

$sql = 'SELECT d.*, c.name AS kategori FROM devices d JOIN categories c ON c.id = d.category_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY d.purchase_date DESC, d.id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// IMEI'leri çöz; $q varsa IMEI üzerinde de PHP tarafında filtrele
foreach ($rows as &$r) {
    $r['imei'] = imei_dec($r['imei']);
}
unset($r);
if ($q !== '') {
    $qL   = mb_strtolower($q, 'UTF-8');
    $rows = array_values(array_filter($rows, fn($r) =>
        mb_stripos($r['model'],        $qL, 0, 'UTF-8') !== false
     || mb_stripos($r['imei'],         $qL, 0, 'UTF-8') !== false
     || mb_stripos($r['seller'] ?? '', $qL, 0, 'UTF-8') !== false
     || mb_stripos($r['note']   ?? '', $qL, 0, 'UTF-8') !== false
    ));
}

// Satır verisi — JS sadece görünen sayfayı çizer (tüm satırlar DOM'a basılmaz, hız için).
// Fiyatlar ham sayı (JS biçimlendirir), tarihler hazır biçimli gönderilir.
$data = [];
foreach ($rows as $r) {
    $satildi = $r['sale_date'] !== null;
    $data[] = [
        'id'       => (int)$r['id'],
        'kat'      => (string)$r['category_id'],
        'kategori' => $r['kategori'],
        'durum'    => $satildi ? 'satildi' : 'stok',
        'model'    => $r['model'],
        'imei'     => $r['imei'],
        'seller'   => $r['seller'],
        'note'     => $r['note'],
        'buyer'    => $satildi ? ($r['buyer'] ?? '') : '',
        'pd'       => trdate($r['purchase_date']),
        'sd'       => $satildi ? trdate($r['sale_date']) : '',
        'sds'      => $satildi ? (string)$r['sale_date'] : '', // sıralama için ham ISO tarih
        'bekleme'  => max(0, (int)floor((($satildi ? strtotime($r['sale_date']) : strtotime('today')) - strtotime($r['purchase_date'])) / 86400)), // stokta: bugün−alış · satıldı: satış−alış

        'alis'     => (float)$r['purchase_price'],
        'satis'    => $satildi ? (float)$r['sale_price'] : null,
        'kar'      => $satildi ? (float)$r['profit'] : null,
        'snote'    => $r['sale_note'] ?? '',
        'ara'      => tr_lower($r['model'] . ' ' . $r['imei'] . ' ' . $r['seller'] . ' ' . $r['note']),
    ];
}

page_header('Cihazlar', 'cihazlar');
?>
<script>
// Sütun CSS'i render öncesi enjekte et; ID ile tutulur — sutunUygula() aynı etiketi günceller
(function(){
  const s = document.createElement('style');
  s.id = 'sutun-css';
  document.head.appendChild(s);
  try {
    const d = JSON.parse(localStorage.getItem('cihaz_sutunlar') || '{}');
    const vars = {kategori:true,'alis-tarihi':true,imei:true,satici:false,not:true,'satis-tarihi':true,'satis-fiyati':true,kar:true,alici:true,bekleme:true,durum:true};
    const gizli = Object.entries(vars).filter(([k,v]) => (d[k] !== undefined ? d[k] : v) === false).map(([k]) => k);
    s.textContent = gizli.map(k => `[data-col="${k}"]{display:none}`).join('');
  } catch(e) {}
})();
</script>
<!-- İkonlar bir kez tanımlanır; her satır <use> ile aynısını kullanır (tekrar tekrar çizilmez) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="ico-sat" viewBox="0 0 24 24"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58s1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41s-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></symbol>
  <symbol id="ico-duzenle" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></symbol>
  <symbol id="ico-sil" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></symbol>
</svg>
<div class="sayfa-alt" style="margin:0 0 14px">
  <h1 style="margin:0">Cihazlar</h1>
  <div class="actions">
    <div class="sutun-wrap">
      <button class="btn sec" onclick="sutunMenuAc(event)" id="sutunBtn">Sütunlar ▾</button>
      <div class="sutun-menu" id="sutunMenu"></div>
    </div>
    <button class="btn sec" onclick="gorunenleriAktar()">Excel'e Aktar</button>
    <a class="btn" href="cihaz_form.php">+ Yeni Kayıt</a>
  </div>
</div>

<div class="filtre-butonlar">
  <div>
    <label>Kategori</label>
    <div class="seg" id="katSeg">
      <a href="#" class="<?= $kat === '' ? 'active' : '' ?>" data-kat="">Tümü</a>
      <?php foreach ($kats as $k): ?>
        <a href="#" class="<?= $kat == $k['id'] ? 'active' : '' ?>" data-kat="<?= $k['id'] ?>"><?= e($k['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div>
    <label>Durum</label>
    <div class="seg" id="durumSeg">
      <a href="#" class="st-tum <?= $durum === 'tum' ? 'active' : '' ?>" data-durum="tum">Tümü</a>
      <a href="#" class="st-stok <?= $durum === 'stok' ? 'active' : '' ?>" data-durum="stok">Stokta</a>
      <a href="#" class="st-satildi <?= $durum === 'satildi' ? 'active' : '' ?>" data-durum="satildi">Satıldı</a>
    </div>
  </div>
  <!-- Sağ: özet sayaç (JS tarafından güncellenir) -->
  <div class="filtre-ozet">
    <span class="filtre-ozet-adet" id="ozetAdet"></span>
    <span class="filtre-ozet-sep" id="ozetSep1" style="display:none">·</span>
    <span class="filtre-ozet-deger" id="ozetDeger"></span>
    <span class="filtre-ozet-sep" id="ozetSep2" style="display:none">·</span>
    <span class="filtre-ozet-kar" id="ozetKar"></span>
  </div>
</div>

<form method="get" class="filters">
  <input type="hidden" name="kat" id="hiddenKat" value="<?= e($kat) ?>">
  <input type="hidden" name="durum" id="hiddenDurum" value="<?= e($durum) ?>">
  <div class="grow">
    <label>Ara (model, IMEI, satıcı, not)</label>
    <input type="text" name="q" id="araKutu" value="<?= e($q) ?>" placeholder="Yazdıkça filtreler..." autocomplete="off">
  </div>
  <div>
    <label>Alış (başlangıç)</label>
    <input type="date" name="at1" value="<?= e($at1) ?>">
  </div>
  <div>
    <label>Alış (bitiş)</label>
    <input type="date" name="at2" value="<?= e($at2) ?>">
  </div>
  <div>
    <label>Satış (başlangıç)</label>
    <input type="date" name="st1" value="<?= e($st1) ?>">
  </div>
  <div>
    <label>Satış (bitiş)</label>
    <input type="date" name="st2" value="<?= e($st2) ?>">
  </div>
  <div class="actions">
    <button class="btn sec">Filtrele</button>
    <a class="btn sec" href="cihazlar.php">Temizle</a>
  </div>
</form>

<!-- Silme için tek gizli form (her satıra ayrı form basılmaz) -->
<form method="post" action="cihaz_form.php" id="silForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="silFormId">
</form>

<div class="table-wrap">
<table id="cihazTablo">
  <thead>
    <tr>
      <th data-col="kategori">Kategori</th>
      <th data-col="alis-tarihi">Alış Tarihi</th>
      <th>Model</th>
      <th data-col="imei">IMEI</th>
      <th class="num">Alış Fiyatı</th>
      <th data-col="satici">Satıcı</th>
      <th data-col="not">Genel Not</th>
      <th data-col="satis-tarihi">Satış Tarihi</th>
      <th class="num" data-col="satis-fiyati">Satış Fiyatı</th>
      <th class="num" data-col="kar">Kâr</th>
      <th data-col="alici">Alıcı</th>
      <th data-col="bekleme">Bekleme</th>
      <th data-col="durum">Durum</th>
      <th></th>
    </tr>
  </thead>
  <tbody id="satirGovde"><!-- satırlar JS ile çizilir --></tbody>
  <tfoot>
    <tr class="toplam">
      <td data-col="kategori"></td>
      <td data-col="alis-tarihi"></td>
      <td id="topOzet"></td>
      <td data-col="imei"></td>
      <td class="num" id="topAlis"></td>
      <td data-col="satici"></td>
      <td data-col="not"></td>
      <td data-col="satis-tarihi"></td>
      <td class="num" data-col="satis-fiyati" id="topSatis"></td>
      <td class="num" data-col="kar" id="topKar"></td>
      <td data-col="alici"></td>
      <td data-col="bekleme"></td>
      <td data-col="durum"></td>
      <td></td>
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

// id -> veri nesnesi (inline not güncellemesi için)
const dataMap = {};
DATA.forEach(d => dataMap[d.id] = d);

const araKutu = document.getElementById('araKutu');
const govde   = document.getElementById('satirGovde');

let filtreKat   = '<?= e($kat) ?>';
let filtreDurum = '<?= e($durum) ?>';
let sayfaBoyutu = parseInt(localStorage.getItem('cihaz_sb') || '50');
let mevcutSayfa = 1;
let filtreli    = [];   // filtrelenmiş veri (tüm eşleşenler)

// ---- Kategori & Durum butonları ----
document.querySelectorAll('#katSeg a').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  filtreKat = a.dataset.kat;
  document.getElementById('hiddenKat').value = filtreKat;
  document.querySelectorAll('#katSeg a').forEach(x => x.classList.remove('active'));
  a.classList.add('active');
  mevcutSayfa = 1; filtrele();
}));
document.querySelectorAll('#durumSeg a').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  filtreDurum = a.dataset.durum;
  document.getElementById('hiddenDurum').value = filtreDurum;
  document.querySelectorAll('#durumSeg a').forEach(x => x.classList.remove('active'));
  a.classList.add('active');
  mevcutSayfa = 1; filtrele();
}));
araKutu.addEventListener('input', () => { mevcutSayfa = 1; filtrele(); });

// ---- Sayfa boyutu ----
const sbSec = document.getElementById('sayfa-boyutu');
sbSec.value = sayfaBoyutu;
sbSec.addEventListener('change', () => {
  sayfaBoyutu = parseInt(sbSec.value);
  localStorage.setItem('cihaz_sb', sayfaBoyutu);
  mevcutSayfa = 1; ciz();
});

// ---- Filtrele (tüm veri üzerinde) + özet/footer güncelle ----
function filtrele() {
  const ham = araKutu.value.trim();
  const q1 = ham.toLocaleLowerCase('tr');
  const q2 = ham.toLowerCase();
  let adet = 0, stokta = 0, alis = 0, satis = 0, kar = 0, stokDegeri = 0;
  filtreli = [];
  for (const d of DATA) {
    const katOk   = !filtreKat || d.kat === filtreKat;
    const durumOk = !filtreDurum || filtreDurum === 'tum' || d.durum === filtreDurum;
    const araOk   = !ham || d.ara.includes(q1) || d.ara.includes(q2);
    if (katOk && durumOk && araOk) {
      filtreli.push(d);
      adet++;
      alis += d.alis || 0;
      if (d.durum === 'satildi') { satis += d.satis || 0; kar += d.kar || 0; }
      else { stokta++; stokDegeri += d.alis || 0; }
    }
  }
  // Tablo footer toplamları
  document.getElementById('topOzet').textContent = adet + ' kayıt (' + stokta + ' stokta, ' + (adet - stokta) + ' satıldı)';
  document.getElementById('topAlis').textContent = trSayi(alis);
  document.getElementById('topSatis').textContent = trSayi(satis);
  const karHucre = document.getElementById('topKar');
  karHucre.textContent = trSayi(kar);
  karHucre.className = 'num ' + (kar >= 0 ? 'kar-poz' : 'kar-neg');
  // Filtre özet (sağ üst)
  document.getElementById('ozetAdet').textContent = adet + ' cihaz';
  const sep1 = document.getElementById('ozetSep1');
  const sep2 = document.getElementById('ozetSep2');
  const ozetD = document.getElementById('ozetDeger');
  const ozetK = document.getElementById('ozetKar');
  if (stokta > 0) { sep1.style.display = ''; ozetD.textContent = 'Stok: ' + trSayi(stokDegeri) + ' TL'; }
  else { sep1.style.display = 'none'; ozetD.textContent = ''; }
  if (kar > 0) { sep2.style.display = ''; ozetK.textContent = 'Kâr: ' + trSayi(kar) + ' TL'; }
  else { sep2.style.display = 'none'; ozetK.textContent = ''; }

  // "Satıldı" seçiliyken satış tarihine göre sırala (en yeni üstte);
  // diğer durumlarda varsayılan sıra (alış tarihi, en yeni üstte) korunur.
  if (filtreDurum === 'satildi') {
    filtreli.sort((a, b) => (b.sds || '').localeCompare(a.sds || '') || (b.id - a.id));
  }

  ciz();
}

// ---- Bekleme hücresi ----
// Stokta: bugün−alış (kaç gündür bekliyor). Satıldı: satış−alış (kaç günde satıldı).
// İkisi de aynı renklerle: 60+ sarı, 120+ kırmızı (geç satılan/uzun bekleyen görülsün).
function beklemeHtml(d) {
  if (d.bekleme == null) return '';
  const g = d.bekleme;
  const metin = g + ' gün';
  const cls = g >= 120 ? 'bekleme-kirmizi' : (g >= 60 ? 'bekleme-sari' : 'bekleme-normal');
  const baslik = d.durum === 'satildi' ? `${g} günde satıldı` : `${g} gündür stokta bekliyor`;
  return `<span class="bekleme-rozet ${cls}" title="${baslik}">${metin}</span>`;
}

// ---- Tek satırın HTML'i ----
function satirHtml(d) {
  const satildi = d.durum === 'satildi';
  const karCls = satildi ? (d.kar >= 0 ? 'kar-poz' : 'kar-neg') : '';
  const satBtn = satildi ? '' :
    `<a class="btn-ikon sat" href="cihaz_form.php?id=${d.id}&sat=1" title="Sat" aria-label="Sat"><svg><use href="#ico-sat"/></svg></a>`;
  return `<tr data-id="${d.id}">
    <td data-col="kategori"><span class="badge">${esc(d.kategori)}</span></td>
    <td data-col="alis-tarihi">${esc(d.pd)}</td>
    <td>${esc(d.model)}</td>
    <td data-col="imei">${esc(d.imei)}</td>
    <td class="num">${trSayi(d.alis)}</td>
    <td data-col="satici">${esc(d.seller)}</td>
    <td class="not-hucre" data-col="not" data-id="${d.id}" title="Genel notu düzenlemek için tıkla"><span class="not-metin">${esc(d.note)}</span></td>
    <td data-col="satis-tarihi">${esc(d.sd)}</td>
    <td class="num" data-col="satis-fiyati">${satildi ? trSayi(d.satis) : ''}</td>
    <td class="num ${karCls}" data-col="kar">${satildi ? trSayi(d.kar) : ''}</td>
    <td data-col="alici">${esc(d.buyer)}</td>
    <td data-col="bekleme">${beklemeHtml(d)}</td>
    <td data-col="durum"><span class="badge ${satildi ? 'satildi' : 'stokta'}">${satildi ? 'Satıldı' : 'Stokta'}</span></td>
    <td><div class="actions">${satBtn}
      <a class="btn-ikon duzenle" href="cihaz_form.php?id=${d.id}" title="Düzenle" aria-label="Düzenle"><svg><use href="#ico-duzenle"/></svg></a>
      <button type="button" class="btn-ikon sil" data-sil="${d.id}" data-model="${esc(d.model)}" title="Sil" aria-label="Sil"><svg><use href="#ico-sil"/></svg></button>
    </div></td>
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
    govde.innerHTML = '<tr><td colspan="14" class="muted" style="text-align:center;padding:24px">Kayıt bulunamadı.</td></tr>';
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

// ---- Satır işlemleri: silme + inline not (olay delegasyonu) ----
govde.addEventListener('click', e => {
  const silBtn = e.target.closest('.btn-ikon.sil');
  if (silBtn) {
    const model = silBtn.dataset.model || 'Bu';
    if (confirm(model + ' kaydı silinsin mi? Bu işlem geri alınamaz.')) {
      document.getElementById('silFormId').value = silBtn.dataset.sil;
      document.getElementById('silForm').submit();
    }
    return;
  }
  const notTd = e.target.closest('.not-hucre');
  if (notTd && !notTd.classList.contains('duzenleniyor')) notEditAc(notTd);
});

// ---- Inline genel not düzenleme ----
let notDuzenlenen = null;
function notEditAc(td) {
  if (notDuzenlenen) return;
  notDuzenlenen = td;
  const span = td.querySelector('.not-metin');
  const eski = span.textContent;
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'not-input';
  input.value = eski;
  input.maxLength = 500;
  td.classList.add('duzenleniyor');
  span.style.display = 'none';
  td.appendChild(input);
  input.focus();
  input.select();

  let bitti = false;
  const kapat = (kaydet) => {
    if (bitti) return; bitti = true;
    const yeni = input.value.trim();
    input.remove();
    span.style.display = '';
    td.classList.remove('duzenleniyor');
    notDuzenlenen = null;
    if (kaydet && yeni !== eski) notKaydet(td, span, yeni, eski);
  };
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); kapat(true); }
    else if (e.key === 'Escape') { e.preventDefault(); kapat(false); }
  });
  input.addEventListener('blur', () => kapat(true));
}

function notKaydet(td, span, yeni, eski) {
  const id = td.dataset.id;
  span.textContent = yeni;               // iyimser güncelleme
  veriGuncelle(id, yeni);
  fetch('api_not.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
    body: JSON.stringify({ id: id, note: yeni })
  })
  .then(r => r.json())
  .then(v => { if (!v.ok) throw new Error(v.hata || 'kayıt hatası'); })
  .catch(err => {
    span.textContent = eski;             // hata olursa geri al
    veriGuncelle(id, eski);
    alert('Not kaydedilemedi: ' + err.message);
  });
}

// Veri nesnesindeki not + arama dizgisini güncelle (arama tutarlı kalsın)
function veriGuncelle(id, yeniNot) {
  const d = dataMap[id];
  if (!d) return;
  d.note = yeniNot;
  d.ara = (d.model + ' ' + d.imei + ' ' + d.seller + ' ' + yeniNot).toLocaleLowerCase('tr');
}

// ---- Excel: filtreli tüm satırlar (sadece görünen sayfa değil) ----
function gorunenleriAktar() {
  const data = [['Kategori','Alış Tarihi','Model','IMEI','Alış Fiyatı','Satıcı','Not','Satış Tarihi','Satış Fiyatı','Kâr','Alıcı','Bekleme (gün)','Satış Notu']];
  for (const d of filtreli) {
    data.push([
      d.kategori, d.pd, d.model, d.imei, d.alis, d.seller, d.note,
      d.sd, d.satis ?? '', d.kar ?? '', d.buyer, (d.bekleme ?? ''), d.snote
    ]);
  }
  excelAktar(data, 'cihazlar', 'Cihazlar');
}

// İlk çizim
filtrele();

// ---- Sütun gizle/göster ----
const SUTUNLAR = [
  { id: 'kategori',     label: 'Kategori',      varsayilan: true  },
  { id: 'alis-tarihi',  label: 'Alış Tarihi',   varsayilan: true  },
  { id: 'imei',         label: 'IMEI',           varsayilan: true  },
  { id: 'satici',       label: 'Satıcı',         varsayilan: false },
  { id: 'not',          label: 'Genel Not',      varsayilan: true  },
  { id: 'satis-tarihi', label: 'Satış Tarihi',   varsayilan: true  },
  { id: 'satis-fiyati', label: 'Satış Fiyatı',   varsayilan: true  },
  { id: 'kar',          label: 'Kâr',            varsayilan: true  },
  { id: 'alici',        label: 'Alıcı',          varsayilan: true  },
  { id: 'bekleme',      label: 'Bekleme',        varsayilan: true  },
  { id: 'durum',        label: 'Durum',          varsayilan: true  },
];
const SK = 'cihaz_sutunlar';

function sutunDurumOku() {
  try { return JSON.parse(localStorage.getItem(SK)) || {}; } catch { return {}; }
}

function sutunUygula(durum) {
  const gizliKolonlar = SUTUNLAR.filter(s => {
    return durum[s.id] === false ? true : (durum[s.id] === true ? false : !s.varsayilan);
  }).map(s => s.id);
  const css = document.getElementById('sutun-css');
  if (css) css.textContent = gizliKolonlar.map(k => `[data-col="${k}"]{display:none}`).join('');
}

function sutunMenuOlustur() {
  const menu = document.getElementById('sutunMenu');
  const durum = sutunDurumOku();
  menu.innerHTML = '<p style="font-size:.75rem;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.6px;margin:0 0 8px">Sütunları seç</p>';
  SUTUNLAR.forEach(s => {
    const gosteriliyor = durum[s.id] !== undefined ? durum[s.id] : s.varsayilan;
    const lbl = document.createElement('label');
    lbl.className = 'sutun-cb-lbl';
    lbl.innerHTML = `<input type="checkbox" ${gosteriliyor ? 'checked' : ''} data-col-id="${s.id}"> ${s.label}`;
    lbl.querySelector('input').addEventListener('change', function () {
      const d = sutunDurumOku();
      d[this.dataset.colId] = this.checked;
      localStorage.setItem(SK, JSON.stringify(d));
      sutunUygula(d);
    });
    menu.appendChild(lbl);
  });
}

function sutunMenuAc(e) {
  e.stopPropagation();
  const menu = document.getElementById('sutunMenu');
  const acik = menu.classList.toggle('acik');
  if (acik) sutunMenuOlustur();
}

document.addEventListener('click', () => {
  document.getElementById('sutunMenu').classList.remove('acik');
});

// Sayfa yüklenince uygula
sutunUygula(sutunDurumOku());
</script>
<?php page_footer(); ?>
