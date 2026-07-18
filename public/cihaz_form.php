<?php
require __DIR__ . '/../app/bootstrap.php';

$kats = categories($pdo);

/* ---------- POST işlemleri ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $st = $pdo->prepare('DELETE FROM devices WHERE id = ?');
        $st->execute([(int)$_POST['id']]);
        flash_set('Kayıt silindi.');
        header('Location: cihazlar.php');
        exit;
    }

    if ($action === 'sell') {
        $id = (int)$_POST['id'];
        $saleDate  = $_POST['sale_date'] ?? '';
        $salePrice = parse_price($_POST['sale_price'] ?? '');
        $saleNote  = trim($_POST['sale_note'] ?? '');
        $buyer     = trim($_POST['buyer'] ?? '');
        if ($saleDate === '' || $salePrice === null) {
            flash_set('Satış tarihi ve fiyatı zorunlu.', 'hata');
            header('Location: cihaz_form.php?id=' . $id . '&sat=1');
            exit;
        }
        $st = $pdo->prepare('UPDATE devices SET sale_date = ?, sale_price = ?,
                             profit = ? - purchase_price, sale_note = ?, buyer = ? WHERE id = ?');
        $st->execute([$saleDate, $salePrice, $salePrice, $saleNote, $buyer, $id]);
        flash_set('Satış kaydedildi.');
        header('Location: cihazlar.php');
        exit;
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $purchaseDate = $_POST['purchase_date'] ?? '';
        $model = trim($_POST['model'] ?? '');
        $imei = imei_enc(trim($_POST['imei'] ?? ''));
        $purchasePrice = parse_price($_POST['purchase_price'] ?? '');
        $seller = trim($_POST['seller'] ?? '');
        $note         = trim($_POST['note'] ?? '');
        $purchaseNote = trim($_POST['purchase_note'] ?? '');
        $saleNote     = trim($_POST['sale_note'] ?? '');
        $buyer        = trim($_POST['buyer'] ?? '');
        $saleDate = $_POST['sale_date'] ?? '';
        $salePrice = parse_price($_POST['sale_price'] ?? '');

        $hatalar = [];
        if (!$categoryId) $hatalar[] = 'Kategori seçin.';
        if ($purchaseDate === '') $hatalar[] = 'Alış tarihi zorunlu.';
        if ($model === '') $hatalar[] = 'Model zorunlu.';
        if ($purchasePrice === null) $hatalar[] = 'Alış fiyatı geçersiz.';
        if ($saleDate !== '' && $salePrice === null) $hatalar[] = 'Satış fiyatı geçersiz veya boş.';
        if ($saleDate === '' && $salePrice !== null) $hatalar[] = 'Satış tarihi girilmeden satış fiyatı girilemez.';

        // IMEI stok çakışması kontrolü (sadece yeni kayıt veya IMEI değiştiyse)
        if ($imei !== '') {
            $stIm = $pdo->prepare(
                'SELECT id, model FROM devices WHERE imei = ? AND sale_date IS NULL AND id != ?'
            );
            $stIm->execute([$imei, $id ?: 0]);
            $cakisan = $stIm->fetch();
            if ($cakisan) {
                $hatalar[] = 'Bu IMEI zaten stokta kayıtlı: ' . $cakisan['model'] . ' (ID: ' . $cakisan['id'] . ')';
            }
        }

        if ($hatalar) {
            flash_set(implode(' ', $hatalar), 'hata');
            header('Location: cihaz_form.php' . ($id ? '?id=' . $id : ''));
            exit;
        }

        // Satış girildiyse kâr otomatik hesaplanıp kaydedilir
        $sold = ($saleDate !== '');
        $profit = $sold ? $salePrice - $purchasePrice : null;

        if ($id) {
            $st = $pdo->prepare('UPDATE devices SET category_id=?, purchase_date=?, model=?, imei=?,
                                 purchase_price=?, seller=?, note=?, purchase_note=?, sale_note=?, buyer=?,
                                 sale_date=?, sale_price=?, profit=? WHERE id=?');
            $st->execute([$categoryId, $purchaseDate, $model, $imei, $purchasePrice, $seller, $note,
                          $purchaseNote, $saleNote, $buyer,
                          $sold ? $saleDate : null, $sold ? $salePrice : null, $profit, $id]);
            flash_set('Kayıt güncellendi.');
        } else {
            $st = $pdo->prepare('INSERT INTO devices (category_id, purchase_date, model, imei,
                                 purchase_price, seller, note, purchase_note, sale_note, buyer, sale_date, sale_price, profit)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$categoryId, $purchaseDate, $model, $imei, $purchasePrice, $seller, $note,
                          $purchaseNote, $saleNote, $buyer,
                          $sold ? $saleDate : null, $sold ? $salePrice : null, $profit]);
            flash_set('Kayıt eklendi.');
        }
        header('Location: cihazlar.php');
        exit;
    }
}

/* ---------- Form gösterimi ---------- */
$id = (int)($_GET['id'] ?? 0);
$satModu = isset($_GET['sat']);
$cihaz = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
    $st->execute([$id]);
    $cihaz = $st->fetch();
    if (!$cihaz) {
        flash_set('Kayıt bulunamadı.', 'hata');
        header('Location: cihazlar.php');
        exit;
    }
    $cihaz['imei'] = imei_dec($cihaz['imei']); // ekranda düz göster
}

$modeller = distinct_values($pdo, 'model');
$saticilar = distinct_values($pdo, 'seller');

$baslik = $satModu ? 'Satış Kaydet' : ($cihaz ? 'Kaydı Düzenle' : 'Yeni Kayıt');
page_header($baslik, 'cihazlar');
?>
<?php
// Kategorinin adını bul
$katAd = '';
foreach ($kats as $k) { if ($cihaz && $k['id'] == $cihaz['category_id']) { $katAd = $k['name']; break; } }
// İki sütunlu mu? (düzenleme veya satış modu)
$ikiSutun = $cihaz !== null;
?>

<?php if ($satModu && $cihaz): /* ===== SATIŞ MODU ===== */ ?>

<div class="fk fk-iki">

  <!-- SOL: Alış özeti (salt okunur) -->
  <div class="fk-b">
    <p class="fk-baslik">Alış Bilgileri</p>
    <table class="detay-tablo">
      <tr><td class="detay-eti">Kategori</td><td><?= e($katAd) ?></td></tr>
      <tr><td class="detay-eti">Model</td><td><strong><?= e($cihaz['model']) ?></strong></td></tr>
      <?php if (trim((string)$cihaz['imei']) !== ''): ?>
      <tr><td class="detay-eti">IMEI</td><td><?= e($cihaz['imei']) ?></td></tr>
      <?php endif; ?>
      <tr><td class="detay-eti">Alış Tarihi</td><td><?= trdate($cihaz['purchase_date']) ?></td></tr>
      <tr><td class="detay-eti">Alış Fiyatı</td><td><strong><?= tl($cihaz['purchase_price']) ?> TL</strong></td></tr>
      <?php if (trim((string)$cihaz['seller']) !== ''): ?>
      <tr><td class="detay-eti">Satıcı Adı</td><td><?= e($cihaz['seller']) ?></td></tr>
      <?php endif; ?>
      <?php if (trim((string)$cihaz['purchase_note']) !== ''): ?>
      <tr><td class="detay-eti">Alış Notu</td><td><?= e($cihaz['purchase_note']) ?></td></tr>
      <?php endif; ?>
      <?php if (trim((string)$cihaz['note']) !== ''): ?>
      <tr><td class="detay-eti">Genel Not</td><td><?= e($cihaz['note']) ?></td></tr>
      <?php endif; ?>
      <?php if (trim((string)($cihaz['buyer'] ?? '')) !== ''): ?>
      <tr><td class="detay-eti">Alıcı</td><td><?= e($cihaz['buyer']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- SAĞ: Satış formu -->
  <div class="fk-b">
    <p class="fk-baslik">Satış Bilgileri</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sell">
      <input type="hidden" name="id" value="<?= $cihaz['id'] ?>">
      <input type="hidden" id="js-alis-fiyat" value="<?= (float)$cihaz['purchase_price'] ?>">
      <div class="form-row">
        <label>Satış Tarihi</label>
        <input type="date" name="sale_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-row">
        <label>Satış Fiyatı (TL)</label>
        <input type="text" name="sale_price" id="js-satis-fiyat" required autofocus inputmode="decimal" placeholder="örn. 12500">
      </div>
      <div id="js-kar-kutu" class="kar-kutu">Satış fiyatı giriniz…</div>
      <div class="form-row">
        <label>Alıcı Adı <span class="muted">(isteğe bağlı)</span></label>
        <input type="text" name="buyer" value="<?= e($cihaz['buyer'] ?? '') ?>" placeholder="Alıcının adı soyadı">
      </div>
      <div class="form-row">
        <label>Satış Notu <span class="muted">(isteğe bağlı)</span></label>
        <textarea name="sale_note" rows="2" placeholder="Notlar..."><?= e($cihaz['sale_note'] ?? '') ?></textarea>
      </div>
      <div class="actions">
        <button class="btn yesil">Satışı Kaydet</button>
        <a class="btn sec" href="cihazlar.php">Vazgeç</a>
      </div>
    </form>
  </div>

</div>

<?php else: /* ===== YENİ KAYIT veya DÜZENLEME ===== */ ?>

<div class="fk <?= $ikiSutun ? 'fk-iki' : '' ?>">

  <!-- SOL (her zaman): Alış bilgileri -->
  <form method="post" id="ana-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($cihaz): ?><input type="hidden" name="id" value="<?= $cihaz['id'] ?>"><?php endif; ?>

    <div class="fk-b">
      <p class="fk-baslik">Alış Bilgileri</p>
      <div class="form-grid">
        <div class="form-row">
          <label>Kategori</label>
          <select name="category_id" required>
            <?php foreach ($kats as $k): ?>
              <option value="<?= $k['id'] ?>" <?= $cihaz && $cihaz['category_id'] == $k['id'] ? 'selected' : '' ?>><?= e($k['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label>Alış Tarihi</label>
          <input type="date" name="purchase_date" value="<?= e($cihaz['purchase_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="form-row">
          <label>Model</label>
          <input type="text" name="model" list="modeller" value="<?= e($cihaz['model'] ?? '') ?>" required autofocus>
          <datalist id="modeller"><?php foreach ($modeller as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="form-row">
          <label>IMEI</label>
          <input type="text" name="imei" id="imei-input" value="<?= e($cihaz['imei'] ?? '') ?>"
                 autocomplete="off" data-haric="<?= $cihaz['id'] ?? 0 ?>">
          <div class="imei-uyari" id="imei-uyari"></div>
        </div>
        <div class="form-row">
          <label>Alış Fiyatı (TL)</label>
          <input type="text" name="purchase_price" id="js-alis-fiyat"
                 value="<?= $cihaz ? tl($cihaz['purchase_price']) : '' ?>"
                 required inputmode="decimal" placeholder="örn. 9750">
        </div>
        <div class="form-row">
          <label>Satıcı Adı</label>
          <input type="text" name="seller" list="saticilar" value="<?= e($cihaz['seller'] ?? '') ?>">
          <datalist id="saticilar"><?php foreach ($saticilar as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
      </div>
      <hr class="fk-ayrac">
      <div class="form-row">
        <label>Alış Notu <span class="muted">(listede görünmez)</span></label>
        <textarea name="purchase_note" rows="2"><?= e($cihaz['purchase_note'] ?? '') ?></textarea>
      </div>
      <div class="form-row" style="margin-bottom:0">
        <label>Genel Not <span class="muted">(listede görünür)</span></label>
        <textarea name="note" rows="2"><?= e($cihaz['note'] ?? '') ?></textarea>
      </div>
      <?php if (!$ikiSutun): ?>
      <hr class="fk-ayrac">
      <div class="actions">
        <button class="btn" form="ana-form">Kaydet</button>
        <a class="btn sec" href="cihazlar.php">Vazgeç</a>
      </div>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($ikiSutun): ?>
  <!-- SAĞ: Satış bilgileri + kâr -->
  <div class="fk-b">
    <p class="fk-baslik">Satış Bilgisi <span class="muted" style="text-transform:none;letter-spacing:0;font-weight:400;font-size:.85em">· boş bırakılırsa stokta sayılır</span></p>
    <div class="form-row">
      <label>Satış Tarihi</label>
      <input type="date" name="sale_date" form="ana-form" value="<?= e($cihaz['sale_date'] ?? '') ?>">
    </div>
    <div class="form-row">
      <label>Satış Fiyatı (TL)</label>
      <input type="text" name="sale_price" id="js-satis-fiyat" form="ana-form" inputmode="decimal"
             value="<?= $cihaz['sale_price'] !== null ? tl($cihaz['sale_price']) : '' ?>"
             placeholder="örn. 12500">
    </div>
    <div id="js-kar-kutu" class="kar-kutu">Satış fiyatı giriniz…</div>
    <hr class="fk-ayrac">
    <div class="form-row">
      <label>Alıcı Adı</label>
      <input type="text" name="buyer" form="ana-form" value="<?= e($cihaz['buyer'] ?? '') ?>" placeholder="Alıcının adı soyadı">
    </div>
    <div class="form-row" style="margin-bottom:0">
      <label>Satış Notu</label>
      <textarea name="sale_note" form="ana-form" rows="2"><?= e($cihaz['sale_note'] ?? '') ?></textarea>
    </div>
    <hr class="fk-ayrac">
    <div class="actions">
      <button class="btn" form="ana-form">Kaydet</button>
      <a class="btn sec" href="cihazlar.php">Vazgeç</a>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php endif; ?>

<script>
// ---- IMEI stok kontrolü ----
(function () {
  const inp    = document.getElementById('imei-input');
  const uyari  = document.getElementById('imei-uyari');
  const kaydetBtn = document.querySelector('button[name="action"][value="save"], form button[type="submit"]');
  if (!inp || !uyari) return;

  let zamanlayici;
  inp.addEventListener('input', () => {
    clearTimeout(zamanlayici);
    uyari.className = 'imei-uyari';
    if (kaydetBtn) kaydetBtn.disabled = false;
    const imei = inp.value.trim();
    if (imei.length < 5) return;
    zamanlayici = setTimeout(() => kontrol(imei), 400);
  });

  function kontrol(imei) {
    const haric = inp.dataset.haric || 0;
    fetch('api_imei.php?imei=' + encodeURIComponent(imei) + '&haric=' + haric)
      .then(r => r.json())
      .then(v => {
        if (v.durum === 'stokta') {
          uyari.textContent = '❌ Bu IMEI zaten stokta: ' + v.model + ' · Alış: ' + v.tarih;
          uyari.className = 'imei-uyari goster hata';
          if (kaydetBtn) kaydetBtn.disabled = true;
        } else if (v.durum === 'satilmis') {
          uyari.textContent = 'ℹ Bu IMEI daha önce satılmış: ' + v.model + ' · Satış: ' + v.tarih;
          uyari.className = 'imei-uyari goster bilgi';
          if (kaydetBtn) kaydetBtn.disabled = false;
        } else {
          uyari.className = 'imei-uyari';
          if (kaydetBtn) kaydetBtn.disabled = false;
        }
      })
      .catch(() => {});
  }

  if (inp.value.trim().length >= 5) kontrol(inp.value.trim());
})();

// ---- Kâr hesabı ----
(function () {
  const alisInp  = document.getElementById('js-alis-fiyat');
  const satisInp = document.getElementById('js-satis-fiyat');
  const kutu     = document.getElementById('js-kar-kutu');
  if (!satisInp || !kutu) return;
  const fmt = v => new Intl.NumberFormat('tr-TR', {minimumFractionDigits:0, maximumFractionDigits:2}).format(v);
  const num = el => parseFloat((el.value||'').replace(/\./g,'').replace(',','.')) || 0;

  function hesapla() {
    const alis  = alisInp ? num(alisInp) : 0;
    const satis = num(satisInp);
    if (!satis) { kutu.className = 'kar-kutu'; kutu.textContent = 'Satış fiyatı giriniz…'; return; }
    const kar = satis - alis;
    kutu.className = 'kar-kutu ' + (kar >= 0 ? 'poz' : 'neg');
    kutu.innerHTML =
      '<span style="font-size:.82em;font-weight:400;opacity:.85;display:block;margin-bottom:3px">'
      + 'Alış: ' + fmt(alis) + ' TL &nbsp;·&nbsp; Satış: ' + fmt(satis) + ' TL</span>'
      + (kar >= 0 ? '▲ Kâr: +' : '▼ Zarar: ') + fmt(kar) + ' TL';
  }

  if (alisInp)  alisInp.addEventListener('input', hesapla);
  satisInp.addEventListener('input', hesapla);
  hesapla();
})();
</script>
<?php page_footer(); ?>
