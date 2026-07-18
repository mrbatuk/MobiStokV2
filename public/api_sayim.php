<?php
// Stok sayım işaretleme (kalıcı) — tek ürün işaretle / tüm sayımı sıfırla
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'hata' => 'Sadece POST.']);
    exit;
}
check_csrf();

$girdi = json_decode(file_get_contents('php://input'), true);
$action = $girdi['action'] ?? '';

if ($action === 'reset') {
    $pdo->exec('UPDATE devices SET counted = 0');
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'set') {
    $id = (int)($girdi['id'] ?? 0);
    $counted = !empty($girdi['counted']) ? 1 : 0;
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'hata' => 'Geçersiz kayıt.']);
        exit;
    }
    $st = $pdo->prepare('UPDATE devices SET counted = ? WHERE id = ?');
    $st->execute([$counted, $id]);
    echo json_encode(['ok' => true, 'counted' => $counted]);
    exit;
}

echo json_encode(['ok' => false, 'hata' => 'Bilinmeyen işlem.']);
