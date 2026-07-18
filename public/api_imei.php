<?php
// IMEI stok kontrolü — yeni/düzenleme formundan çağrılır
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$imeiDuz = trim($_GET['imei'] ?? '');
$haricId = (int)($_GET['haric'] ?? 0);

if (strlen($imeiDuz) < 5) {
    echo json_encode(['ok' => true, 'stokta' => false]);
    exit;
}

$imeiEnc = imei_enc($imeiDuz);

// Stokta mevcut mu?
$st = $pdo->prepare(
    'SELECT id, model, purchase_date FROM devices
     WHERE imei = ? AND sale_date IS NULL AND id != ?'
);
$st->execute([$imeiEnc, $haricId]);
$stokta = $st->fetch();

if ($stokta) {
    echo json_encode([
        'ok'     => true,
        'durum'  => 'stokta',
        'stokta' => true,
        'model'  => $stokta['model'],
        'tarih'  => trdate($stokta['purchase_date']),
        'id'     => $stokta['id'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Satılmış kayıt var mı?
$st = $pdo->prepare(
    'SELECT id, model, sale_date FROM devices
     WHERE imei = ? AND sale_date IS NOT NULL AND id != ?
     ORDER BY sale_date DESC LIMIT 1'
);
$st->execute([$imeiEnc, $haricId]);
$satilmis = $st->fetch();

if ($satilmis) {
    echo json_encode([
        'ok'     => true,
        'durum'  => 'satilmis',
        'stokta' => false,
        'model'  => $satilmis['model'],
        'tarih'  => trdate($satilmis['sale_date']),
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => true, 'durum' => 'yok', 'stokta' => false]);
}
