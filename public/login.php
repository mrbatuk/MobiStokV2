<?php
define('LOGIN_PAGE', true);
require __DIR__ . '/../app/bootstrap.php';

$hash = setting($pdo, 'password_hash');
$kurulum = ($hash === null); // ilk kurulum: henüz şifre belirlenmemiş
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($kurulum) {
        $dukkan = trim($_POST['dukkan'] ?? '');
        $s1 = $_POST['sifre'] ?? '';
        $s2 = $_POST['sifre2'] ?? '';
        if ($dukkan === '') {
            $hata = 'Dükkan adı boş olamaz.';
        } elseif (strlen($s1) < 4) {
            $hata = 'Şifre en az 4 karakter olmalı.';
        } elseif ($s1 !== $s2) {
            $hata = 'Şifreler birbirini tutmuyor.';
        } else {
            set_setting($pdo, 'shop_name', $dukkan);
            set_setting($pdo, 'password_hash', password_hash($s1, PASSWORD_DEFAULT));
            $_SESSION['auth'] = true;
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }
    } else {
        if (password_verify($_POST['sifre'] ?? '', $hash)) {
            $_SESSION['auth'] = true;
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }
        $hata = 'Şifre hatalı.';
    }
}

if (!empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}
$shop = setting($pdo, 'shop_name', 'Telefoncu Takip');
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $kurulum ? 'Kurulum' : 'Giriş' ?> — <?= e($shop) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<div class="login-box">
  <h1><?= $kurulum ? 'İlk Kurulum' : e($shop) ?></h1>
  <?php if ($hata): ?><div class="flash hata"><?= e($hata) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($kurulum): ?>
      <div class="form-row">
        <label>Dükkan Adı</label>
        <input type="text" name="dukkan" required autofocus>
      </div>
      <div class="form-row">
        <label>Giriş Şifresi</label>
        <input type="password" name="sifre" required>
      </div>
      <div class="form-row">
        <label>Şifre (tekrar)</label>
        <input type="password" name="sifre2" required>
      </div>
      <button class="btn" style="width:100%">Kur ve Başla</button>
    <?php else: ?>
      <div class="form-row">
        <label>Şifre</label>
        <input type="password" name="sifre" required autofocus>
      </div>
      <button class="btn" style="width:100%">Giriş</button>
    <?php endif; ?>
  </form>
</div>
</body>
</html>
