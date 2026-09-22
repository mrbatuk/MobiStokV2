<?php
require __DIR__ . '/../app/bootstrap.php';

$st = $pdo->query('SELECT * FROM servis ORDER BY alinan_tarih DESC, id DESC');
$rows = $st->fetchAll();

$data = [];
foreach ($rows as $r) {
    $data[] = [
        'id'      => (int)$r['id'],
        'musteri' => $r['musteri'],
        'tel'     => $r['tel'],
        'cihaz'   => $r['cihaz'],
        'ariza'   => $r['ariza'],
        'ad'      => trdate($r['alinan_tarih']),
        'td'      => $r['teslim_tarih'] ? trdate($r['teslim_tarih']) : '',
        'maliyet' => (float)$r['maliyet'],
        'tahsilat'=> (float)$r['tahsilat'],
        'kar'     => (float)$r['kar'],
        'durum'   => $r['durum'],
        'note'    => $r['note'],
        'ara'     => tr_lower($r['musteri'] . ' ' . $r['tel'] . ' ' . $r['cihaz'] . ' ' . $r['ariza'] . ' ' . $r['note']),
    ];
}

page_header('Servis', 'servis');
?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="ico-duzenle" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></symbol>
  <symbol id="ico-sil"     viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></symbol>
</svg>

<div class="sayfa-alt" style="margin:0 0 14px">
  <h1 style="margin:0">Servis</h1>
  <div class="actions">
    <a class="btn" href="servis_form.php">+ Yeni Kayıt</a>
  </div>
</div>

<div class="filtre-butonlar">
  <div>
    <label>Durum</label>
    <div class="seg" id="durumSeg">
      <a href="#" class="active" data-durum="tum">Tümü</a>
      <a href="#" data-durum="alindi">Alındı</a>
      <a href="#" data-durum="tamircide">Tamircide</a>
      <a href="#" data-durum="hazir">Hazır</a>
      <a href="#" data-durum="teslim">Teslim</a>
      <a href="#" data-durum="iptal">İptal</a>
    </div>
  </div>
  <div class="filtre-ozet">
    <span class="filtre-ozet-adet" id="ozetAdet"></span>
    <span class="filtre-ozet-sep" id="ozetSep" style="display:none">·</span>
    <span class="filtre-ozet-kar" id="ozetKar"></span>
  </div>
</div>

<div class="filters" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:14px">
  <div class="grow">
    <label>Ara (müşteri, cihaz, arıza, not)</label>
    <input type="text" id="araKutu" placeholder="Yazdıkça filtreler..." autocomplete="off">
  </div>
</div>

<form method="post" action="servis_form.php" id="silForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="silFormId">
</form>

<div class="table-wrap">
<table id="servisTablo">
  <thead>
    <tr>
      <th>Müşteri</th>
      <th>Tel</th>
      <th>Cihaz</th>
      <th>Arıza</th>
      <th>Alındı</th>
      <th>Teslim</th>
      <th class="num">Maliyet</th>
      <th class="num">Tahsilat</th>
      <th class="num">Kâr</th>
      <th>Durum</th>
      <th></th>
    </tr>
  </thead>
  <tbody id="satirGovde"><!-- JS ile çizilir --></tbody>
  <tfoot>
    <tr class="toplam">
      <td id="topOzet" colspan="6"></td>
      <td class="num" id="topMaliyet"></td>
      <td class="num" id="topTahsilat"></td>
      <td class="num" id="topKar"></td>
      <td></td>
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

<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
const trSayi = v => new Intl.NumberFormat('tr-TR').format(v);
const esc = s => (s == null ? '' : String(s)).replace(/[&<>"']/g,
  c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const DURUM = {
  alindi:    ['Alındı',    'durum-alindi'],
  tamircide: ['Tamircide', 'durum-tamircide'],
  hazir:     ['Hazır',     'durum-hazir'],
  teslim:    ['Teslim',    'durum-teslim'],
  iptal:     ['İptal',     'durum-iptal'],
};

const araKutu    = document.getElementById('araKutu');
const govde      = document.getElementById('satirGovde');
let filtreDurum  = 'tum';
let sayfaBoyutu  = parseInt(localStorage.getItem('servis_sb') || '50');
let mevcutSayfa  = 1;
let filtreli     = [];

document.querySelectorAll('#durumSeg a').forEach(a => a.addEventListener('click', e => {
  e.preventDefault();
  filtreDurum = a.dataset.durum;
  document.querySelectorAll('#durumSeg a').forEach(x => x.classList.remove('active'));
  a.classList.add('active');
  mevcutSayfa = 1; filtrele();
}));
araKutu.addEventListener('input', () => { mevcutSayfa = 1; filtrele(); });

const sbSec = document.getElementById('sayfa-boyutu');
sbSec.value = sayfaBoyutu;
sbSec.addEventListener('change', () => {
  sayfaBoyutu = parseInt(sbSec.value);
  localStorage.setItem('servis_sb', sayfaBoyutu);
  mevcutSayfa = 1; ciz();
});

function filtrele() {
  const ham = araKutu.value.trim();
  const q   = ham.toLocaleLowerCase('tr');
  filtreli  = [];
  let topMaliyet = 0, topTahsilat = 0, topKar = 0;

  for (const d of DATA) {
    const durumOk = filtreDurum === 'tum' || d.durum === filtreDurum;
    const araOk   = !ham || d.ara.includes(q);
    if (durumOk && araOk) {
      filtreli.push(d);
      topMaliyet  += d.maliyet  || 0;
      topTahsilat += d.tahsilat || 0;
      topKar      += d.kar      || 0;
    }
  }

  document.getElementById('topOzet').textContent = filtreli.length + ' kayıt';
  document.getElementById('topMaliyet').textContent  = trSayi(topMaliyet);
  document.getElementById('topTahsilat').textContent = trSayi(topTahsilat);
  const karHucre = document.getElementById('topKar');
  karHucre.textContent = trSayi(topKar);
  karHucre.className = 'num ' + (topKar >= 0 ? 'kar-poz' : 'kar-neg');

  document.getElementById('ozetAdet').textContent = filtreli.length + ' kayıt';
  const aktif = filtreli.filter(d => d.durum !== 'iptal' && d.durum !== 'teslim').length;
  const sep = document.getElementById('ozetSep');
  const ozetK = document.getElementById('ozetKar');
  if (topKar !== 0) {
    sep.style.display = ''; ozetK.textContent = 'Kâr: ' + trSayi(topKar) + ' TL';
  } else {
    sep.style.display = 'none'; ozetK.textContent = '';
  }

  ciz();
}

function satirHtml(d) {
  const [durumLabel, durumCls] = DURUM[d.durum] || [d.durum, ''];
  const karCls = d.kar >= 0 ? 'kar-poz' : 'kar-neg';
  return `<tr>
    <td><strong>${esc(d.musteri)}</strong></td>
    <td>${d.tel ? `<a href="tel:${esc(d.tel)}">${esc(d.tel)}</a>` : ''}</td>
    <td>${esc(d.cihaz)}</td>
    <td class="muted" style="font-size:.88rem">${esc(d.ariza)}</td>
    <td>${esc(d.ad)}</td>
    <td>${esc(d.td)}</td>
    <td class="num">${trSayi(d.maliyet)}</td>
    <td class="num">${trSayi(d.tahsilat)}</td>
    <td class="num ${karCls}">${trSayi(d.kar)}</td>
    <td><span class="badge ${durumCls}">${durumLabel}</span></td>
    <td><div class="actions">
      <a class="btn-ikon duzenle" href="servis_form.php?id=${d.id}" title="Düzenle" aria-label="Düzenle"><svg><use href="#ico-duzenle"/></svg></a>
      <button type="button" class="btn-ikon sil" data-sil="${d.id}" data-label="${esc(d.musteri + ' — ' + d.cihaz)}" title="Sil" aria-label="Sil"><svg><use href="#ico-sil"/></svg></button>
    </div></td>
  </tr>`;
}

function sayfaNumaralari(mevcut, toplam) {
  if (toplam <= 7) return Array.from({length: toplam}, (_, i) => i + 1);
  if (mevcut <= 4) return [1,2,3,4,5,'…',toplam];
  if (mevcut >= toplam - 3) return [1,'…',toplam-4,toplam-3,toplam-2,toplam-1,toplam];
  return [1,'…',mevcut-1,mevcut,mevcut+1,'…',toplam];
}

function ciz() {
  const toplam = filtreli.length;
  const toplamSayfa = Math.max(1, Math.ceil(toplam / sayfaBoyutu));
  if (mevcutSayfa > toplamSayfa) mevcutSayfa = toplamSayfa;
  const bas = (mevcutSayfa - 1) * sayfaBoyutu;
  const bit = Math.min(bas + sayfaBoyutu, toplam);

  if (toplam === 0) {
    govde.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center;padding:24px">Kayıt bulunamadı.</td></tr>';
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

govde.addEventListener('click', e => {
  const silBtn = e.target.closest('.btn-ikon.sil');
  if (!silBtn) return;
  const label = silBtn.dataset.label || 'Bu';
  if (confirm(label + ' kaydı silinsin mi? Bu işlem geri alınamaz.')) {
    document.getElementById('silFormId').value = silBtn.dataset.sil;
    document.getElementById('silForm').submit();
  }
});

filtrele();
</script>
<?php page_footer(); ?>
