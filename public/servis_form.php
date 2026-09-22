<?php
require __DIR__ . '/../app/bootstrap.php';

/* ---------- POST işlemleri ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM servis WHERE id = ?')->execute([$id]);
        flash_set('Kayıt silindi.');
        header('Location: servis.php');
        exit;
    }

    if ($action === 'save') {
        $musteri     = trim($_POST['musteri']     ?? '');
        $tel         = trim($_POST['tel']         ?? '');
        $cihaz       = trim($_POST['cihaz']       ?? '');
        $ariza       = trim($_POST['ariza']       ?? '');
        $alinanTarih = $_POST['alinan_tarih']     ?? '';
        $teslimTarih = $_POST['teslim_tarih']     ?? '';
        $maliyet     = parse_price($_POST['maliyet']  ?? '') ?? 0.0;
        $tahsilat    = parse_price($_POST['tahsilat'] ?? '') ?? 0.0;
        $durum       = $_POST['durum']            ?? 'alindi';
        $note        = trim($_POST['note']        ?? '');

        $gecerliDurumlar = ['alindi', 'tamircide', 'hazir', 'teslim', 'iptal'];
        if (!in_array($durum, $gecerliDurumlar, true)) $durum = 'alindi';

        $hatalar = [];
        if ($musteri === '')     $hatalar[] = 'Müşteri adı zorunlu.';
        if ($cihaz === '')       $hatalar[] = 'Cihaz bilgisi zorunlu.';
        if ($alinanTarih === '') $hatalar[] = 'Alındı tarihi zorunlu.';

        if ($hatalar) {
            flash_set(implode(' ', $hatalar), 'hata');
            header('Location: servis_form.php' . ($id ? '?id=' . $id : ''));
            exit;
        }

        // Teslim durumuna geçildi ama tarih girilmemişse bugünü ata
        if ($durum === 'teslim' && $teslimTarih === '') {
            $teslimTarih = date('Y-m-d');
        }

        $kar = $tahsilat - $maliyet;

        if ($id) {
            $pdo->prepare('UPDATE servis SET musteri=?, tel=?, cihaz=?, ariza=?,
                           alinan_tarih=?, teslim_tarih=?, maliyet=?, tahsilat=?,
                           kar=?, durum=?, note=? WHERE id=?')
                ->execute([
                    $musteri, $tel, $cihaz, $ariza,
                    $alinanTarih, $teslimTarih ?: null, $maliyet, $tahsilat,
                    $kar, $durum, $note, $id,
                ]);
            flash_set('Kayıt güncellendi.');
        } else {
            $pdo->prepare('INSERT INTO servis (musteri, tel, cihaz, ariza,
                           alinan_tarih, teslim_tarih, maliyet, tahsilat, kar, durum, note)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $musteri, $tel, $cihaz, $ariza,
                    $alinanTarih, $teslimTarih ?: null, $maliyet, $tahsilat,
                    $kar, $durum, $note,
                ]);
            flash_set('Kayıt eklendi.');
        }
        header('Location: servis.php');
        exit;
    }
}

/* ---------- Form gösterimi ---------- */
$id  = (int)($_GET['id'] ?? 0);
$kay = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM servis WHERE id = ?');
    $st->execute([$id]);
    $kay = $st->fetch();
    if (!$kay) {
        flash_set('Kayıt bulunamadı.', 'hata');
        header('Location: servis.php');
        exit;
    }
}

$baslik = $kay ? 'Servis Kaydını Düzenle' : 'Yeni Servis Kaydı';
page_header($baslik, 'servis');
?>

<div class="fk">
  <form method="post" class="fk-b" style="max-width:560px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($kay): ?><input type="hidden" name="id" value="<?= $kay['id'] ?>"><?php endif; ?>

    <p class="fk-baslik">Müşteri &amp; Cihaz</p>
    <div class="form-grid">
      <div class="form-row">
        <label>Müşteri Adı</label>
        <input type="text" name="musteri" value="<?= e($kay['musteri'] ?? '') ?>" required autofocus placeholder="Ad Soyad">
      </div>
      <div class="form-row">
        <label>Telefon <span class="muted">(isteğe bağlı)</span></label>
        <input type="tel" name="tel" value="<?= e($kay['tel'] ?? '') ?>" placeholder="0555 000 00 00">
      </div>
      <div class="form-row">
        <label>Cihaz</label>
        <input type="text" name="cihaz" value="<?= e($kay['cihaz'] ?? '') ?>" required placeholder="Samsung A54, iPhone 12…">
      </div>
      <div class="form-row">
        <label>Arıza <span class="muted">(isteğe bağlı)</span></label>
        <input type="text" name="ariza" value="<?= e($kay['ariza'] ?? '') ?>" placeholder="Ekran kırık, şarj almıyor…">
      </div>
    </div>

    <hr class="fk-ayrac">
    <p class="fk-baslik">Tarih &amp; Durum</p>
    <div class="form-grid">
      <div class="form-row">
        <label>Alındı Tarihi</label>
        <input type="date" name="alinan_tarih" value="<?= e($kay['alinan_tarih'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="form-row">
        <label>Teslim Tarihi <span class="muted">(durum "Teslim" olunca otomatik dolar)</span></label>
        <input type="date" name="teslim_tarih" value="<?= e($kay['teslim_tarih'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>Durum</label>
        <select name="durum" id="durumSec">
          <?php
          $durumlar = [
            'alindi'    => 'Alındı',
            'tamircide' => 'Tamircide',
            'hazir'     => 'Hazır',
            'teslim'    => 'Teslim',
            'iptal'     => 'İptal',
          ];
          $mevcutDurum = $kay['durum'] ?? 'alindi';
          foreach ($durumlar as $val => $label):
          ?>
            <option value="<?= $val ?>" <?= $mevcutDurum === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <hr class="fk-ayrac">
    <p class="fk-baslik">Ücretler</p>
    <div class="form-grid">
      <div class="form-row">
        <label>Tamirciye Ödenen (TL)</label>
        <input type="text" name="maliyet" id="js-maliyet"
               value="<?= $kay ? tl($kay['maliyet']) : '' ?>"
               inputmode="decimal" placeholder="örn. 1000">
      </div>
      <div class="form-row">
        <label>Müşteriden Alınan (TL)</label>
        <input type="text" name="tahsilat" id="js-tahsilat"
               value="<?= $kay ? tl($kay['tahsilat']) : '' ?>"
               inputmode="decimal" placeholder="örn. 1500">
      </div>
    </div>
    <div id="js-kar-kutu" class="kar-kutu" style="margin-bottom:16px">Ücret giriniz…</div>

    <hr class="fk-ayrac">
    <div class="form-row" style="margin-bottom:16px">
      <label>Not <span class="muted">(isteğe bağlı)</span></label>
      <textarea name="note" rows="2" placeholder="Ek notlar…"><?= e($kay['note'] ?? '') ?></textarea>
    </div>

    <div class="actions">
      <button class="btn">Kaydet</button>
      <a class="btn sec" href="servis.php">Vazgeç</a>
      <?php if ($kay): ?>
        <button type="button" class="btn tehlike" style="margin-left:auto"
          onclick="if(confirm('<?= e($kay['musteri'] . ' — ' . $kay['cihaz']) ?> kaydı silinsin mi?')) {
            document.getElementById('silForm').submit();
          }">Sil</button>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($kay): ?>
<form method="post" id="silForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" value="<?= $kay['id'] ?>">
</form>
<?php endif; ?>

<script>
// ---- Kâr hesabı önizleme ----
(function () {
  const maliyetInp  = document.getElementById('js-maliyet');
  const tahsilatInp = document.getElementById('js-tahsilat');
  const kutu        = document.getElementById('js-kar-kutu');
  if (!maliyetInp || !tahsilatInp || !kutu) return;
  const fmt = v => new Intl.NumberFormat('tr-TR', {minimumFractionDigits:0, maximumFractionDigits:2}).format(v);
  const num = el => parseFloat((el.value||'').replace(/\./g,'').replace(',','.')) || 0;

  function hesapla() {
    const maliyet  = num(maliyetInp);
    const tahsilat = num(tahsilatInp);
    if (!tahsilat && !maliyet) { kutu.className = 'kar-kutu'; kutu.textContent = 'Ücret giriniz…'; return; }
    const kar = tahsilat - maliyet;
    kutu.className = 'kar-kutu ' + (kar >= 0 ? 'poz' : 'neg');
    kutu.innerHTML =
      '<span style="font-size:.82em;font-weight:400;opacity:.85;display:block;margin-bottom:3px">'
      + 'Tamirciye: ' + fmt(maliyet) + ' TL &nbsp;·&nbsp; Tahsilat: ' + fmt(tahsilat) + ' TL</span>'
      + (kar >= 0 ? '▲ Kâr: +' : '▼ Zarar: ') + fmt(kar) + ' TL';
  }

  maliyetInp.addEventListener('input', hesapla);
  tahsilatInp.addEventListener('input', hesapla);
  hesapla();
})();
</script>
<?php page_footer(); ?>
