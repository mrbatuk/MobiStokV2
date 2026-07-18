<?php
// Listeden hızlı not güncelleme (inline düzenleme)
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'hata' => 'Sadece POST.']);
    exit;
}
check_csrf();

$girdi = json_decode(file_get_contents('php://input'), true);
$id   = (int)($girdi['id'] ?? 0);
$note = trim((string)($girdi['note'] ?? ''));

if ($id <= 0) {
    echo json_encode(['ok' => false, 'hata' => 'Geçersiz kayıt.']);
    exit;
}

$st = $pdo->prepare('UPDATE devices SET note = ? WHERE id = ?');
$st->execute([$note, $id]);

echo json_encode(['ok' => true, 'note' => $note], JSON_UNESCAPED_UNICODE);
