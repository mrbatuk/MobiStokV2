<?php
// Her sayfanın başında: oturum, veritabanı, giriş kontrolü
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Istanbul');
session_start();

// PHP sayfaları ve API yanıtları önbelleğe alınmasın — güncellemeden sonra
// tarayıcı hep taze kod/veri getirsin (statik dosyalar bundan etkilenmez).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$pdo = db();

// Sunucu her yeniden başladığında eski oturumları geçersiz kıl
$_token_file = __DIR__ . '/../data/server_token.txt';
if (file_exists($_token_file)) {
    $_server_token = trim(file_get_contents($_token_file));
    if (($_SESSION['server_token'] ?? '') !== $_server_token) {
        // Token eşleşmiyor: yeni sunucu başlatılmış, oturumu temizle
        session_destroy();
        session_start();
        $_SESSION['server_token'] = $_server_token;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// login.php kendi sayfasında LOGIN_PAGE tanımlar; diğer tüm sayfalar giriş ister
if (!defined('LOGIN_PAGE') && empty($_SESSION['auth'])) {
    header('Location: login.php');
    exit;
}
