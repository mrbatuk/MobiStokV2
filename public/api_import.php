<?php
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function yanit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yanit(['ok' => false, 'hata' => 'Sadece POST.']);
}
check_csrf();

$girdi = json_decode(file_get_contents('php://input'), true);
if (!is_array($girdi) || !isset($girdi['rows']) || !is_array($girdi['rows'])) {
    yanit(['ok' => false, 'hata' => 'Geçersiz veri.']);
}

$wipe = !empty($girdi['wipe']);
$rows = $girdi['rows'];

$pdo->beginTransaction();
try {
    $silinen = 0;
    if ($wipe) {
        $silinen = (int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
        $pdo->exec('DELETE FROM devices');
    }

    // Kategori adı -> id (yoksa oluştur)
    $katCache = [];
    foreach (categories($pdo) as $k) {
        $katCache[mb_strtoupper($k['name'], 'UTF-8')] = (int)$k['id'];
    }

    $ins = $pdo->prepare('INSERT INTO devices (category_id, purchase_date, model, imei,
                          purchase_price, seller, note, purchase_note, sale_note, buyer,
                          sale_date, sale_price, profit)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $eklenen = 0;

    foreach ($rows as $r) {
        $katAd = trim((string)($r['category'] ?? ''));
        $tarih = (string)($r['purchase_date'] ?? '');
        $model = trim((string)($r['model'] ?? ''));
        if ($katAd === '' || $model === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
            continue;
        }
        $key = mb_strtoupper($katAd, 'UTF-8');
        if (!isset($katCache[$key])) {
            $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM categories')->fetchColumn();
            $st = $pdo->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
            $st->execute([$katAd, $max + 1]);
            $katCache[$key] = (int)$pdo->lastInsertId();
        }

        $alis = is_numeric($r['purchase_price'] ?? null) ? (float)$r['purchase_price'] : 0.0;
        $satisTarih = (string)($r['sale_date'] ?? '');
        $satis = is_numeric($r['sale_price'] ?? null) ? (float)$r['sale_price'] : null;
        $satildi = preg_match('/^\d{4}-\d{2}-\d{2}$/', $satisTarih) && $satis !== null;

        $ins->execute([
            $katCache[$key], $tarih, $model,
            imei_enc(trim((string)($r['imei'] ?? ''))),
            $alis,
            trim((string)($r['seller'] ?? '')),
            trim((string)($r['note'] ?? '')),
            trim((string)($r['purchase_note'] ?? '')),
            $satildi ? trim((string)($r['sale_note'] ?? '')) : '',
            $satildi ? trim((string)($r['buyer'] ?? '')) : '',
            $satildi ? $satisTarih : null,
            $satildi ? $satis : null,
            $satildi ? $satis - $alis : null,
        ]);
        $eklenen++;
    }

    $pdo->commit();
    yanit(['ok' => true, 'eklenen' => $eklenen, 'silinen' => $silinen]);
} catch (Throwable $ex) {
    $pdo->rollBack();
    yanit(['ok' => false, 'hata' => $ex->getMessage()]);
}
