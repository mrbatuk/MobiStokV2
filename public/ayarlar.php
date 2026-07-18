<?php
require __DIR__ . '/../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'shop') {
        $ad = trim($_POST['dukkan'] ?? '');
        if ($ad === '') {
            flash_set('Dükkan adı boş olamaz.', 'hata');
        } else {
            set_setting($pdo, 'shop_name', $ad);
            flash_set('Dükkan adı güncellendi.');
        }
        header('Location: ayarlar.php');
        exit;
    }

    if ($action === 'pass') {
        $eski = $_POST['eski'] ?? '';
        $s1 = $_POST['yeni'] ?? '';
        $s2 = $_POST['yeni2'] ?? '';
        $hash = setting($pdo, 'password_hash');
        if (!password_verify($eski, (string)$hash)) {
            flash_set('Mevcut şifre hatalı.', 'hata');
        } elseif (strlen($s1) < 4) {
            flash_set('Yeni şifre en az 4 karakter olmalı.', 'hata');
        } elseif ($s1 !== $s2) {
            flash_set('Yeni şifreler birbirini tutmuyor.', 'hata');
        } else {
            set_setting($pdo, 'password_hash', password_hash($s1, PASSWORD_DEFAULT));
            flash_set('Şifre değiştirildi.');
        }
        header('Location: ayarlar.php');
        exit;
    }

    if ($action === 'cat_add') {
        $ad = trim($_POST['ad'] ?? '');
        if ($ad === '') {
            flash_set('Kategori adı boş olamaz.', 'hata');
        } elseif (category_by_name($pdo, $ad)) {
            flash_set('Bu adla bir kategori zaten var.', 'hata');
        } else {
            $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM categories')->fetchColumn();
            $st = $pdo->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
            $st->execute([$ad, $max + 1]);
            flash_set('Kategori eklendi: ' . $ad);
        }
        header('Location: ayarlar.php');
        exit;
    }

    if ($action === 'cat_ren') {
        $id = (int)$_POST['id'];
        $ad = trim($_POST['ad'] ?? '');
        $mevcut = category_by_name($pdo, $ad);
        if ($ad === '') {
            flash_set('Kategori adı boş olamaz.', 'hata');
        } elseif ($mevcut && (int)$mevcut['id'] !== $id) {
            flash_set('Bu adla başka bir kategori var.', 'hata');
        } else {
            $st = $pdo->prepare('UPDATE categories SET name = ? WHERE id = ?');
            $st->execute([$ad, $id]);
            flash_set('Kategori adı güncellendi.');
        }
        header('Location: ayarlar.php');
        exit;
    }

    if ($action === 'cat_del') {
        $id = (int)$_POST['id'];
        $st = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE category_id = ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            flash_set('Bu kategoride kayıt var, silinemez.', 'hata');
        } else {
            $st = $pdo->prepare('DELETE FROM categories WHERE id = ?');
            $st->execute([$id]);
            flash_set('Kategori silindi.');
        }
        header('Location: ayarlar.php');
        exit;
    }
}

$kats = categories($pdo);
$shop = setting($pdo, 'shop_name', '');
$kayitSayisi = (int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
$mevcutSurum = trim((string)@file_get_contents(__DIR__ . '/../VERSION'));
if ($mevcutSurum === '') $mevcutSurum = '1.0.0';

page_header('Ayarlar', 'ayarlar');
?>
<h1>Ayarlar</h1>

<!-- ===== Yazilim Guncelleme ===== -->
<div class="panel" id="guncelleme-panel" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="margin:0 0 4px">Yazılım Güncelleme</h2>
      <p class="muted" style="margin:0">Mevcut sürüm: <strong>v<?= e($mevcutSurum) ?></strong></p>
    </div>
    <button class="btn" id="guncKontrolBtn" onclick="guncellemeKontrol()">Güncellemeleri Denetle</button>
  </div>
  <div id="guncelleme-sonuc" style="margin-top:14px"></div>
</div>

<div class="panel-grid">
  <div>
    <div class="panel">
      <h2 style="margin-top:0">Dükkan Adı</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="shop">
        <div class="form-row">
          <input type="text" name="dukkan" value="<?= e($shop) ?>" required>
        </div>
        <button class="btn">Kaydet</button>
      </form>
    </div>

    <div class="panel">
      <h2 style="margin-top:0">Şifre Değiştir</h2>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pass">
        <div class="form-row">
          <label>Mevcut Şifre</label>
          <input type="password" name="eski" required>
        </div>
        <div class="form-row">
          <label>Yeni Şifre</label>
          <input type="password" name="yeni" required>
        </div>
        <div class="form-row">
          <label>Yeni Şifre (tekrar)</label>
          <input type="password" name="yeni2" required>
        </div>
        <button class="btn">Şifreyi Değiştir</button>
      </form>
    </div>

    <div class="panel">
      <h2 style="margin-top:0">Kategoriler</h2>
      <?php foreach ($kats as $k): ?>
        <div style="display:flex;gap:6px;margin-bottom:8px">
          <form method="post" style="display:flex;gap:6px;flex:1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cat_ren">
            <input type="hidden" name="id" value="<?= $k['id'] ?>">
            <input type="text" name="ad" value="<?= e($k['name']) ?>" required>
            <button class="btn sec kucuk">Adı Değiştir</button>
          </form>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cat_del">
            <input type="hidden" name="id" value="<?= $k['id'] ?>">
            <button type="button" class="btn kucuk tehlike"
              onclick="silOnayla(this.form, '<?= e($k['name']) ?> kategorisi silinsin mi? (Sadece boş kategoriler silinebilir)')">Sil</button>
          </form>
        </div>
      <?php endforeach; ?>
      <form method="post" style="display:flex;gap:6px;margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cat_add">
        <input type="text" name="ad" placeholder="Yeni kategori (örn. Yenilenmiş, Aksesuar)" required>
        <button class="btn kucuk">Ekle</button>
      </form>
    </div>
  </div>

  <div>
    <!-- ===== Google Drive Yedekleme ===== -->
    <div class="panel" id="gdrive-panel">
      <h2 style="margin-top:0">
        <svg style="vertical-align:middle;margin-right:6px;margin-bottom:2px" width="20" height="20" viewBox="0 0 87.3 78" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M6.6 66.85l3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8H0c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
          <path d="M43.65 25L29.9 1.2C28.55 2 27.4 3.1 26.6 4.5L1.2 49.5C.4 50.9 0 52.45 0 54h27.5z" fill="#00ac47"/>
          <path d="M73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5H60l5.85 11.5z" fill="#ea4335"/>
          <path d="M43.65 25L57.4 1.2C56.05.4 54.5 0 52.95 0H34.35c-1.55 0-3.1.45-4.45 1.2z" fill="#00832d"/>
          <path d="M60 54H27.5L13.75 77.8c1.35.8 2.9 1.2 4.45 1.2H69.1c1.55 0 3.1-.4 4.45-1.2z" fill="#2684fc"/>
          <path d="M73.4 26.5l-12.6-21.8c-.8-1.4-1.95-2.5-3.3-3.3L43.65 25 60 54h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>
        </svg>
        Google Drive Yedekleme
      </h2>
      <div id="gdrive-icerik">
        <p class="muted" style="margin-top:0">Yükleniyor…</p>
      </div>
    </div>

    <div class="panel">
      <h2 style="margin-top:0">Excel'den İçe Aktar</h2>
      <p class="muted">
        Mevcut Excel dosyanı seç. <strong>SIFIR CİHAZ</strong> ve <strong>2.EL</strong> sayfaları okunur
        (RAPOR ve STOK atlanır; farklı adlı sayfalar aynı adla kategori olarak eklenir).
        Kolon sırası Excel'deki gibi olmalı: Alış Tarihi, Model, IMEI, Alış Fiyatı, Satıcı, Satış Tarihi, Satış Fiyatı.
      </p>
      <div class="form-row">
        <input type="file" id="dosya" accept=".xlsx,.xls">
      </div>
      <div class="form-row">
        <label style="display:flex;align-items:center;gap:6px;font-size:.9rem;color:#0b0b0b">
          <input type="checkbox" id="temizle" style="width:auto">
          İçe aktarmadan önce mevcut <?= $kayitSayisi ?> kaydı sil
        </label>
      </div>
      <div id="onizleme" class="muted" style="margin-bottom:10px"></div>
      <button class="btn" id="aktarBtn" disabled>İçe Aktar</button>
      <div id="sonuc" style="margin-top:10px"></div>
    </div>
  </div>
</div>

<script src="assets/xlsx.full.min.js"></script>
<script src="assets/app.js"></script>
<script>
/* ========== Google Drive Yedekleme ========== */
const GDRIVE_CSRF = <?= json_encode($_SESSION['csrf']) ?>;
let yoklaTimer = null;

async function gdriveYukle() {
  const ic = document.getElementById('gdrive-icerik');
  try {
    const res = await fetch('api_gdrive.php?action=durum');
    const d = await res.json();

    if (!d.rclone) {
      ic.innerHTML = `
        <div class="flash hata" style="margin-bottom:12px">
          <strong>rclone dosyası bulunamadı.</strong> Proje klasöründe <code>bin/rclone.exe</code> eksik.
        </div>
        <button class="btn sec" onclick="gdriveYukle()">Yenile</button>
      `;
      return;
    }

    if (d.bagli) {
      const siklukSecenekleri = [
        { val: 0,   label: 'Kapalı' },
        { val: 4,   label: 'Her 4 saatte bir' },
        { val: 24,  label: 'Günlük' },
        { val: 168, label: 'Haftalık' },
        { val: 720, label: 'Aylık' },
      ];
      const siklukOpts = siklukSecenekleri.map(o =>
        `<option value="${o.val}" ${d.sikluk == o.val ? 'selected' : ''}>${o.label}</option>`
      ).join('');

      const saklaSecenekleri = [
        { val: 3,  label: '3 gün' },
        { val: 7,  label: '7 gün' },
        { val: 14, label: '14 gün' },
        { val: 30, label: '30 gün' },
        { val: 0,  label: 'Sınırsız' },
      ];
      const saklaOpts = saklaSecenekleri.map(o =>
        `<option value="${o.val}" ${d.sakla_gun == o.val ? 'selected' : ''}>${o.label}</option>`
      ).join('');

      ic.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
          <span style="color:#16a34a;font-size:1.3rem;line-height:1">✓</span>
          <strong>Google Drive bağlı</strong>
        </div>
        ${d.son_yedek
          ? `<p class="muted" style="margin-top:0;margin-bottom:14px">Son yedek: <strong>${d.son_yedek}</strong></p>`
          : '<p class="muted" style="margin-top:0;margin-bottom:14px">Henüz yedekleme yapılmadı.</p>'}

        <div class="form-row" style="margin-bottom:12px">
          <label style="font-size:.83rem;color:var(--text3);margin-bottom:4px;display:block">Otomatik Yedekleme</label>
          <div style="display:flex;gap:8px;align-items:center">
            <select id="gdrive-sikluk" style="flex:1;max-width:180px">${siklukOpts}</select>
            <button class="btn kucuk" onclick="gdriveSiklukKaydet(this)">Kaydet</button>
          </div>
          <p class="muted" id="gdrive-sikluk-aciklama" style="margin-top:5px;margin-bottom:0"></p>
        </div>

        <div class="form-row" style="margin-bottom:16px">
          <label style="font-size:.83rem;color:var(--text3);margin-bottom:4px;display:block">Yedek Saklama Süresi</label>
          <div style="display:flex;gap:8px;align-items:center">
            <select id="gdrive-sakla-gun" style="flex:1;max-width:180px">${saklaOpts}</select>
            <button class="btn kucuk" onclick="gdriveSaklaGunKaydet(this)">Kaydet</button>
          </div>
          <p class="muted" style="margin-top:5px;margin-bottom:0;font-size:.82rem">Belirtilen süreden eski yedekler Drive'dan otomatik silinir.</p>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
          <button class="btn" onclick="gdriveYedekle(this)">Şimdi Yedekle</button>
          <button class="btn sec" onclick="gdriveYedekListele(this)">Yedekten Geri Yükle</button>
          <button class="btn sec" onclick="gdriveBaglantiKes(this)" style="color:#dc2626">Bağlantıyı Kes</button>
        </div>
        <div id="gdrive-geri-yukle-alan" style="margin-top:12px"></div>
        <div id="gdrive-mesaj" style="margin-top:10px"></div>
      `;
      gdriveSiklukAciklamaGuncelle();
    } else {
      ic.innerHTML = `
        <p class="muted" style="margin-top:0">Veritabanı dosyası Google Drive'daki <code>MobiStokYedek</code> klasörüne kopyalanır.</p>
        <button class="btn" onclick="gdriveAuthBaslat(this)">Google Drive'a Bağlan</button>
        <div id="gdrive-auth-bolum" style="margin-top:14px"></div>
      `;
    }
  } catch (e) {
    ic.innerHTML = `<div class="flash hata">Durum alınamadı: ${e}</div>`;
  }
}

function gdriveSiklukAciklamaGuncelle() {
  const sel = document.getElementById('gdrive-sikluk');
  const aciklama = document.getElementById('gdrive-sikluk-aciklama');
  if (!sel || !aciklama) return;
  const metinler = {
    0:   'Otomatik yedekleme kapalı.',
    4:   'Her 4 saatte bir herhangi bir sayfa açıldığında yedeklenir.',
    24:  'Her gün herhangi bir sayfa açıldığında yedeklenir.',
    168: 'Her hafta herhangi bir sayfa açıldığında yedeklenir.',
    720: 'Her ay herhangi bir sayfa açıldığında yedeklenir.',
  };
  aciklama.textContent = metinler[sel.value] || '';
  sel.addEventListener('change', () => {
    aciklama.textContent = metinler[sel.value] || '';
  });
}

async function gdriveSaklaGunKaydet(btn) {
  const sel = document.getElementById('gdrive-sakla-gun');
  btn.disabled = true;
  btn.textContent = '…';
  try {
    const fd = new FormData();
    fd.append('sakla_gun', sel.value);
    const res = await fetch('api_gdrive.php?action=sakla_gun_kaydet', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
      body: fd,
    });
    const d = await res.json();
    const mes = document.getElementById('gdrive-mesaj');
    if (d.ok) {
      mes.innerHTML = '<div class="flash ok">Saklama süresi kaydedildi.</div>';
      setTimeout(() => { mes.innerHTML = ''; }, 3000);
    } else {
      mes.innerHTML = `<div class="flash hata">${d.hata}</div>`;
    }
  } catch(e) {
    alert('Hata: ' + e);
  }
  btn.disabled = false;
  btn.textContent = 'Kaydet';
}

async function gdriveSiklukKaydet(btn) {
  const sel = document.getElementById('gdrive-sikluk');
  btn.disabled = true;
  btn.textContent = '…';
  try {
    const fd = new FormData();
    fd.append('sikluk', sel.value);
    const res = await fetch('api_gdrive.php?action=sikluk_kaydet', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
      body: fd,
    });
    const d = await res.json();
    const mes = document.getElementById('gdrive-mesaj');
    if (d.ok) {
      mes.innerHTML = '<div class="flash ok">Otomatik yedekleme ayarı kaydedildi.</div>';
      setTimeout(() => { mes.innerHTML = ''; }, 3000);
    } else {
      mes.innerHTML = `<div class="flash hata">${d.hata}</div>`;
    }
  } catch(e) {
    alert('Hata: ' + e);
  }
  btn.disabled = false;
  btn.textContent = 'Kaydet';
}

async function gdriveAuthBaslat(btn) {
  btn.disabled = true;
  btn.textContent = 'Bağlanıyor…';
  const bolum = document.getElementById('gdrive-auth-bolum');
  bolum.innerHTML = '<p class="muted">OAuth URL alınıyor, lütfen bekleyin…</p>';

  try {
    const res = await fetch('api_gdrive.php?action=auth_baslat', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
    });
    const d = await res.json();

    btn.disabled = false;
    btn.textContent = "Google Drive'a Bağlan";

    if (!d.ok) {
      bolum.innerHTML = `<div class="flash hata">${d.hata}</div>`;
      return;
    }

    if (d.url) {
      bolum.innerHTML = `
        <div class="flash ok" style="margin-bottom:10px">
          Aşağıdaki butona tıklayıp Google hesabınızla giriş yapın. Yetkilendirdikten sonra bu sayfa otomatik güncellenir.
        </div>
        <a href="${d.url}" target="_blank" class="btn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:4px;margin-bottom:1px"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.44 9.8 8.2 11.37-.11-.95-.21-2.41.04-3.45.24-1 1.55-6.57 1.55-6.57s-.4-.79-.4-1.96c0-1.84 1.07-3.21 2.39-3.21 1.13 0 1.68.85 1.68 1.87 0 1.14-.72 2.84-1.1 4.42-.31 1.32.66 2.39 1.96 2.39 2.35 0 3.93-3.02 3.93-6.6 0-2.72-1.84-4.76-5.17-4.76-3.77 0-6.13 2.82-6.13 5.97 0 1.08.32 1.84.82 2.43.23.27.26.38.18.68-.06.22-.19.74-.25.94-.08.3-.33.41-.6.3-1.68-.69-2.46-2.55-2.46-4.63 0-3.43 2.9-7.56 8.65-7.56 4.66 0 7.73 3.4 7.73 7.05 0 4.83-2.68 8.43-6.6 8.43-1.32 0-2.57-.71-3-1.5l-.83 3.18c-.3 1.12-.89 2.24-1.43 3.12C10.7 23.93 11.34 24 12 24c6.63 0 12-5.37 12-12S18.63 0 12 0z"/></svg>
          Google ile Yetkilendir →
        </a>
        <p class="muted" style="margin-top:10px;font-size:.82rem">Yetkilendirme bekleniyor… <span id="gdrive-dots">.</span></p>
      `;
      gdriveAuthYoklaBaslat();
    } else {
      bolum.innerHTML = `<div class="flash hata">OAuth URL alınamadı. rclone sürümünüzü kontrol edin.</div>`;
    }
  } catch (e) {
    btn.disabled = false;
    btn.textContent = "Google Drive'a Bağlan";
    bolum.innerHTML = `<div class="flash hata">İstek hatası: ${e}</div>`;
  }
}

function gdriveAuthYoklaBaslat() {
  if (yoklaTimer) clearInterval(yoklaTimer);
  let dots = 0;
  yoklaTimer = setInterval(async () => {
    const dotEl = document.getElementById('gdrive-dots');
    if (dotEl) { dots = (dots + 1) % 4; dotEl.textContent = '.'.repeat(dots + 1); }
    try {
      const res = await fetch('api_gdrive.php?action=auth_yokla');
      const d = await res.json();
      if (d.tamam) {
        clearInterval(yoklaTimer);
        yoklaTimer = null;
        gdriveYukle();
      } else if (!d.ok) {
        clearInterval(yoklaTimer);
        yoklaTimer = null;
        const bolum = document.getElementById('gdrive-auth-bolum');
        if (bolum) bolum.innerHTML = `<div class="flash hata">${d.hata}</div>`;
      }
    } catch {}
  }, 2000);
}

async function gdriveYedekle(btn) {
  btn.disabled = true;
  btn.textContent = 'Yedekleniyor…';
  const mes = document.getElementById('gdrive-mesaj');
  mes.innerHTML = '';

  try {
    const res = await fetch('api_gdrive.php?action=yedekle', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
    });
    const d = await res.json();

    if (d.ok) {
      mes.innerHTML = `<div class="flash ok">✓ Yedek tamamlandı: ${d.zaman}</div>`;
      const p = document.querySelector('#gdrive-icerik p.muted');
      if (p) p.innerHTML = `Son yedek: <strong>${d.zaman}</strong>`;
    } else {
      mes.innerHTML = `<div class="flash hata">Hata: <code style="font-size:.8em">${d.hata}</code></div>`;
    }
  } catch (e) {
    mes.innerHTML = `<div class="flash hata">Bağlantı hatası: ${e}</div>`;
  }

  btn.disabled = false;
  btn.textContent = 'Şimdi Yedekle';
}

async function gdriveYedekListele(btn) {
  const alan = document.getElementById('gdrive-geri-yukle-alan');
  btn.disabled = true;
  btn.textContent = 'Yükleniyor…';
  alan.innerHTML = '';
  try {
    const res = await fetch('api_gdrive.php?action=yedek_listele');
    const d = await res.json();
    if (!d.ok) {
      alan.innerHTML = `<div class="flash hata">${d.hata}</div>`;
    } else if (d.liste.length === 0) {
      alan.innerHTML = `<div class="flash" style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0">Drive'da henüz yedek yok.</div>`;
    } else {
      const satirlar = d.liste.map(y => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
          <span style="font-size:.9rem">${y.etiket}</span>
          <button class="btn kucuk" onclick="gdriveGeriYukle(this,'${y.dosya}','${y.etiket}')">Geri Yükle</button>
        </div>`).join('');
      alan.innerHTML = `
        <div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;background:var(--surface)">
          <p style="margin:0 0 8px;font-size:.83rem;color:var(--text3);font-weight:600">DRIVE'DAKİ YEDEKLER</p>
          ${satirlar}
        </div>`;
    }
  } catch(e) {
    alan.innerHTML = `<div class="flash hata">Hata: ${e}</div>`;
  }
  btn.disabled = false;
  btn.textContent = 'Yedekten Geri Yükle';
}

async function gdriveGeriYukle(btn, dosya, etiket) {
  if (!confirm(`"${etiket}" tarihli yedek geri yüklensin mi?\n\nMevcut veritabanı "telefoncu_onceki.db" olarak korunacak.`)) return;
  const mes = document.getElementById('gdrive-mesaj');
  btn.disabled = true;
  btn.textContent = 'İndiriliyor…';
  mes.innerHTML = '';
  try {
    const fd = new FormData();
    fd.append('dosya', dosya);
    const res = await fetch('api_gdrive.php?action=geri_yukle', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
      body: fd,
    });
    const d = await res.json();
    if (d.ok) {
      mes.innerHTML = `<div class="flash ok">✓ ${d.mesaj}</div>`;
      document.getElementById('gdrive-geri-yukle-alan').innerHTML = '';
      // Veritabanı değişti, sayfayı yenile
      setTimeout(() => location.reload(), 2000);
    } else {
      mes.innerHTML = `<div class="flash hata">${d.hata}</div>`;
      btn.disabled = false;
      btn.textContent = 'Geri Yükle';
    }
  } catch(e) {
    mes.innerHTML = `<div class="flash hata">Bağlantı hatası: ${e}</div>`;
    btn.disabled = false;
    btn.textContent = 'Geri Yükle';
  }
}

async function gdriveYedekListele(btn) {
  const alan = document.getElementById('gdrive-geri-yukle-alan');
  btn.disabled = true;
  btn.textContent = 'Yükleniyor…';
  alan.innerHTML = '';

  try {
    const res = await fetch('api_gdrive.php?action=yedek_listele');
    const d = await res.json();

    if (!d.ok) {
      alan.innerHTML = `<div class="flash hata">${d.hata}</div>`;
      btn.disabled = false; btn.textContent = 'Yedekten Geri Yükle';
      return;
    }

    if (d.liste.length === 0) {
      alan.innerHTML = `<div class="flash">Drive'da henüz yedek dosyası bulunamadı.</div>`;
      btn.disabled = false; btn.textContent = 'Yedekten Geri Yükle';
      return;
    }

    const opts = d.liste.map(f =>
      `<option value="${f.dosya}">${f.etiket}</option>`
    ).join('');

    alan.innerHTML = `
      <div style="background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:14px">
        <p style="margin:0 0 10px;font-size:.88rem;font-weight:600">Geri yüklenecek yedek:</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <select id="gdrive-geri-sec" style="flex:1;min-width:200px">${opts}</select>
          <button class="btn tehlike kucuk" onclick="gdriveGeriYukle(this)">Geri Yükle</button>
          <button class="btn sec kucuk" onclick="document.getElementById('gdrive-geri-yukle-alan').innerHTML=''">İptal</button>
        </div>
        <p class="muted" style="margin:8px 0 0;font-size:.8rem">⚠ Mevcut veri seçilen yedekle değiştirilecek. Bu işlem geri alınamaz.</p>
      </div>
    `;
  } catch (e) {
    alan.innerHTML = `<div class="flash hata">Hata: ${e}</div>`;
  }

  btn.disabled = false;
  btn.textContent = 'Yedekten Geri Yükle';
}

async function gdriveGeriYukle(btn) {
  const sec = document.getElementById('gdrive-geri-sec');
  const dosya = sec?.value;
  if (!dosya) return;
  if (!confirm(`"${dosya}" yedekten geri yüklensin mi?\n\nMevcut tüm veriler bu yedekle değiştirilecek!`)) return;

  btn.disabled = true;
  btn.textContent = 'Yükleniyor…';
  const mes = document.getElementById('gdrive-mesaj');

  try {
    const fd = new FormData();
    fd.append('dosya', dosya);
    const res = await fetch('api_gdrive.php?action=geri_yukle', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
      body: fd,
    });
    const d = await res.json();

    if (d.ok) {
      mes.innerHTML = `<div class="flash ok">✓ ${d.mesaj}</div>`;
      document.getElementById('gdrive-geri-yukle-alan').innerHTML = '';
      setTimeout(() => location.reload(), 2000);
    } else {
      mes.innerHTML = `<div class="flash hata">Hata: ${d.hata}</div>`;
      btn.disabled = false;
      btn.textContent = 'Geri Yükle';
    }
  } catch (e) {
    mes.innerHTML = `<div class="flash hata">Bağlantı hatası: ${e}</div>`;
    btn.disabled = false;
    btn.textContent = 'Geri Yükle';
  }
}

async function gdriveBaglantiKes(btn) {
  if (!confirm('Google Drive bağlantısı kaldırılsın mı?')) return;
  btn.disabled = true;
  btn.textContent = 'Kesiliyor…';

  try {
    const res = await fetch('api_gdrive.php?action=baglantiyi_kes', {
      method: 'POST',
      headers: { 'X-CSRF': GDRIVE_CSRF },
    });
    const d = await res.json();
    if (d.ok) {
      gdriveYukle();
    } else {
      btn.disabled = false;
      btn.textContent = 'Bağlantıyı Kes';
      alert('Bağlantı kesilemedi.');
    }
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Bağlantıyı Kes';
    alert('Hata: ' + e);
  }
}

gdriveYukle();
/* ========== /Google Drive Yedekleme ========== */

const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
let hazirSatirlar = [];

// Excel seri no veya "gg.aa.yyyy" -> ISO tarih
function tarihISO(v) {
  if (v == null || v === '') return null;
  if (typeof v === 'number') {
    const d = new Date(Math.round((v - 25569) * 86400 * 1000));
    if (isNaN(d)) return null;
    return d.toISOString().slice(0, 10);
  }
  const s = String(v).trim();
  let m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
  if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
  m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return m[1] + '-' + m[2] + '-' + m[3];
  return null;
}

function fiyat(v) {
  if (v == null || v === '') return null;
  if (typeof v === 'number') return v;
  let s = String(v).trim().replace(/\s/g, '');
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  else if ((s.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(s)) s = s.replace(/\./g, ''); // Türk binlik ayracı
  const f = parseFloat(s);
  return isNaN(f) ? null : f;
}

document.getElementById('dosya').addEventListener('change', async (ev) => {
  const dosya = ev.target.files[0];
  if (!dosya) return;
  const wb = XLSX.read(await dosya.arrayBuffer(), { type: 'array' });
  hazirSatirlar = [];
  const ozet = [];
  for (const ad of wb.SheetNames) {
    const adU = ad.trim().toUpperCase('tr-TR');
    if (adU === 'RAPOR' || adU === 'STOK') continue;
    let kategori = ad.trim();
    if (adU.includes('SIFIR')) kategori = 'SIFIR';
    else if (adU.includes('2.EL') || adU.includes('2EL')) kategori = '2.EL';
    const satirlar = XLSX.utils.sheet_to_json(wb.Sheets[ad], { header: 1, raw: true, defval: '' });
    let sayi = 0;
    for (let i = 1; i < satirlar.length; i++) { // ilk satır başlık
      const r = satirlar[i];
      const alisTarihi = tarihISO(r[0]);
      const model = String(r[1] ?? '').trim();
      if (!alisTarihi || !model) continue;
      const satisTarihi = tarihISO(r[5]);
      hazirSatirlar.push({
        category: kategori,
        purchase_date: alisTarihi,
        model: model,
        imei: String(r[2] ?? '').trim(),
        purchase_price: fiyat(r[3]) ?? 0,
        seller: String(r[4] ?? '').trim(),
        sale_date: satisTarihi,
        sale_price: satisTarihi ? fiyat(r[6]) : null
      });
      sayi++;
    }
    if (sayi > 0) ozet.push(ad + ' → ' + kategori + ': ' + sayi + ' kayıt');
  }
  document.getElementById('onizleme').textContent = hazirSatirlar.length
    ? 'Bulunan: ' + ozet.join(' | ')
    : 'Dosyada aktarılacak kayıt bulunamadı.';
  document.getElementById('aktarBtn').disabled = hazirSatirlar.length === 0;
});

document.getElementById('aktarBtn').addEventListener('click', async () => {
  const temizle = document.getElementById('temizle').checked;
  if (temizle && !confirm('Mevcut TÜM kayıtlar silinip Excel verileri aktarılacak. Emin misin?')) return;
  if (!temizle && !confirm(hazirSatirlar.length + ' kayıt eklenecek. Devam edilsin mi?')) return;
  const btn = document.getElementById('aktarBtn');
  btn.disabled = true;
  btn.textContent = 'Aktarılıyor...';
  try {
    const yanit = await fetch('api_import.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify({ wipe: temizle, rows: hazirSatirlar })
    });
    const veri = await yanit.json();
    if (veri.ok) {
      document.getElementById('sonuc').innerHTML =
        '<div class="flash ok">' + veri.eklenen + ' kayıt aktarıldı.' +
        (veri.silinen ? ' (' + veri.silinen + ' eski kayıt silindi)' : '') + '</div>';
    } else {
      document.getElementById('sonuc').innerHTML = '<div class="flash hata">Hata: ' + (veri.hata || 'bilinmeyen') + '</div>';
    }
  } catch (e) {
    document.getElementById('sonuc').innerHTML = '<div class="flash hata">Aktarım hatası: ' + e + '</div>';
  }
  btn.disabled = false;
  btn.textContent = 'İçe Aktar';
});

/* ========== Yazilim Guncelleme ========== */
function guncEsc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function guncNotBicimle(metin){
  if(!metin || !metin.trim()) return '<span class="muted">(Değişiklik notu girilmemiş.)</span>';
  return guncEsc(metin.trim()).split(/\r?\n/).map(satir=>{
    const t = satir.trim();
    if(/^[-*]\s+/.test(t)) return '&bull; ' + t.replace(/^[-*]\s+/,'');
    return satir === '' ? '<br>' : satir;
  }).join('<br>');
}

async function guncellemeKontrol(){
  const btn = document.getElementById('guncKontrolBtn');
  const kutu = document.getElementById('guncelleme-sonuc');
  const eski = btn.textContent;
  btn.disabled = true; btn.textContent = 'Denetleniyor…';
  kutu.innerHTML = '<p class="muted">GitHub kontrol ediliyor…</p>';
  try {
    const r = await fetch('api_update.php?action=kontrol');
    const d = await r.json();
    if(!d.ok){ kutu.innerHTML = '<div class="flash hata">' + guncEsc(d.hata || 'Kontrol başarısız.') + '</div>'; return; }
    if(!d.guncel_var){ kutu.innerHTML = '<div class="flash ok">' + guncEsc(d.mesaj || 'Zaten güncelsiniz.') + '</div>'; return; }
    kutu.innerHTML =
      '<div class="flash ok" style="margin-bottom:12px"><strong>Yeni sürüm mevcut: v' + guncEsc(d.yeni) + '</strong> '
        + '<span class="muted">(mevcut: v' + guncEsc(d.mevcut) + ')</span></div>'
      + '<div style="border:1px solid #e1e0d9;border-radius:8px;padding:12px;margin-bottom:12px;max-height:280px;overflow:auto">'
        + '<div style="font-weight:600;margin-bottom:6px">' + guncEsc(d.baslik || ('v'+d.yeni)) + '</div>'
        + '<div style="font-size:.9rem;line-height:1.5">' + guncNotBicimle(d.notlar) + '</div>'
      + '</div>'
      + '<div class="flash" style="background:#fff7e6;color:#7a5b00;border:1px solid #f0d9a0;margin-bottom:12px">'
        + '⚠ Güncelleme sadece program dosyalarını yeniler. Verileriniz (data klasörü) korunur ve işlem öncesi otomatik yedeklenir.'
      + '</div>'
      + '<button class="btn yesil" id="guncUygulaBtn">Şimdi Güncelle (v' + guncEsc(d.yeni) + ')</button>';
    document.getElementById('guncUygulaBtn').addEventListener('click', ()=>guncellemeUygula(d.tag, d.yeni));
  } catch(e){
    kutu.innerHTML = '<div class="flash hata">Bağlantı hatası: ' + guncEsc(e) + '</div>';
  } finally {
    btn.disabled = false; btn.textContent = eski;
  }
}

async function guncellemeUygula(tag, yeni){
  if(!confirm('v' + yeni + ' sürümüne güncellenecek.\n\nVeritabanınız otomatik yedeklenecek ve sadece program dosyaları değişecek.\n\nDevam edilsin mi?')) return;
  const kutu = document.getElementById('guncelleme-sonuc');
  const btn = document.getElementById('guncUygulaBtn');
  if(btn){ btn.disabled = true; btn.textContent = 'Güncelleniyor…'; }
  kutu.insertAdjacentHTML('beforeend', '<p class="muted" id="gunc-durum" style="margin-top:8px">İndiriliyor ve uygulanıyor, lütfen bekleyin…</p>');
  try {
    const govde = new URLSearchParams({ action:'uygula', tag: tag, csrf: CSRF });
    const r = await fetch('api_update.php', { method:'POST', body: govde });
    const d = await r.json();
    if(d.ok){
      kutu.innerHTML = '<div class="flash ok"><strong>' + guncEsc(d.mesaj) + '</strong><br>'
        + '<span class="muted">Yedek alındı: ' + guncEsc(d.yedek || '-') + ' · Sayfa yenileniyor…</span></div>';
      setTimeout(()=>location.reload(), 2500);
    } else {
      const durum = document.getElementById('gunc-durum'); if(durum) durum.remove();
      kutu.insertAdjacentHTML('beforeend', '<div class="flash hata" style="margin-top:8px">' + guncEsc(d.hata || 'Güncelleme başarısız.')
        + (d.yedek ? '<br><span class="muted">Veritabanı yedeği: ' + guncEsc(d.yedek) + '</span>' : '') + '</div>');
      if(btn){ btn.disabled = false; btn.textContent = 'Tekrar Dene'; }
    }
  } catch(e){
    const durum = document.getElementById('gunc-durum'); if(durum) durum.remove();
    kutu.insertAdjacentHTML('beforeend', '<div class="flash hata" style="margin-top:8px">Bağlantı hatası: ' + guncEsc(e) + '</div>');
    if(btn){ btn.disabled = false; btn.textContent = 'Tekrar Dene'; }
  }
}
</script>
<?php page_footer(); ?>
