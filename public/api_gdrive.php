<?php
// Google Drive yedekleme API — rclone kullanır
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

define('REMOTE_AD',      'mobistok-gdrive');
define('YEDEK_KLASORU',  'MobiStokYedek');
define('DB_YOL',         dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'telefoncu.db');
// Projeye gömülü rclone.exe (taşınabilir — kurulum gerekmez)
define('RCLONE',         dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'rclone.exe');

/* ---------------------------------------------------------- yardımcılar */

function rclone_mevcut(): bool
{
    return file_exists(RCLONE);
}

function remote_mevcut(): bool
{
    exec('"' . RCLONE . '" listremotes 2>&1', $out, $code);
    return in_array(REMOTE_AD . ':', (array)$out);
}

function yedek_dosya_adi(): string
{
    return 'telefoncu_' . date('Y-m-d_H-i') . '.db';
}

function cleanup_eski_yedekler(int $gunSayisi, string $dest): void
{
    if ($gunSayisi <= 0) return;
    exec('"' . RCLONE . '" lsf ' . escapeshellarg($dest) . ' 2>&1', $dosyalar, $code);
    if ($code !== 0) return;
    $sinir = time() - ($gunSayisi * 86400);
    foreach ($dosyalar as $dosya) {
        $dosya = trim($dosya);
        if (!preg_match('/^telefoncu_(\d{4}-\d{2}-\d{2})_\d{2}-\d{2}\.db$/', $dosya, $m)) continue;
        $ts = strtotime($m[1]);
        if ($ts !== false && $ts < $sinir) {
            exec('"' . RCLONE . '" deletefile ' . escapeshellarg($dest . '/' . $dosya) . ' 2>&1');
        }
    }
}

function log_dosyasi(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data'
         . DIRECTORY_SEPARATOR . 'gdrive_auth.log';
}

function pid_dosyasi(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data'
         . DIRECTORY_SEPARATOR . 'gdrive_auth.pid';
}

function islemi_sonlandir(): void
{
    $pid = intval(@file_get_contents(pid_dosyasi()));
    if ($pid > 0) {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID $pid 2>&1");
        } else {
            exec("kill -9 $pid 2>&1");
        }
    }
    @unlink(pid_dosyasi());
    @unlink(log_dosyasi());
}

/* ---------------------------------------------------------- işlemler */

switch ($action) {

    /* ---- DURUM ---- */
    case 'durum':
        echo json_encode([
            'ok'        => true,
            'rclone'    => rclone_mevcut(),
            'bagli'     => remote_mevcut(),
            'son_yedek' => setting($pdo, 'gdrive_son_yedek', ''),
            'sikluk'    => (int)setting($pdo, 'gdrive_sikluk', '0'),
            'sakla_gun' => (int)setting($pdo, 'gdrive_sakla_gun', '7'),
        ], JSON_UNESCAPED_UNICODE);
        break;

    /* ---- OAUTH BAŞLAT ---- */
    case 'auth_baslat':
        check_csrf();

        if (!rclone_mevcut()) {
            echo json_encode(['ok' => false, 'hata' => 'rclone kurulu değil.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Önceki yarım kalan işlemi temizle
        islemi_sonlandir();

        $logFile = log_dosyasi();
        $pidFile = pid_dosyasi();
        file_put_contents($logFile, '');

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'a'],
        ];

        $proc = proc_open(
            '"' . RCLONE . '" authorize "drive" --auth-no-open-browser',
            $desc,
            $pipes
        );

        if (!$proc || !is_resource($proc)) {
            echo json_encode(['ok' => false, 'hata' => 'İşlem başlatılamadı. rclone PATH\'te mi?'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        fclose($pipes[0]);
        $status = proc_get_status($proc);
        file_put_contents($pidFile, (int)$status['pid']);

        // URL çıkana kadar en fazla 8 saniye bekle
        $url = '';
        for ($i = 0; $i < 80; $i++) {
            usleep(100000); // 0.1 s
            $icerik = @file_get_contents($logFile) ?: '';
            // rclone lokal sunucu URL'si
            if (preg_match('#http://127\.0\.0\.1:\d+/auth[^\s\n]*#', $icerik, $m)) {
                $url = rtrim(trim($m[0]), '.');
                break;
            }
            // Doğrudan Google OAuth URL
            if (preg_match('#https://accounts\.google\.com/o/oauth2/[^\s\n"]+#', $icerik, $m)) {
                $url = rtrim(trim($m[0]), '.');
                break;
            }
        }

        echo json_encode(['ok' => true, 'url' => $url], JSON_UNESCAPED_UNICODE);
        break;

    /* ---- OAUTH YOKLA (frontend her 2 sn'de çağırır) ---- */
    case 'auth_yokla':
        $logFile = log_dosyasi();

        if (!file_exists($logFile)) {
            echo json_encode(['ok' => false, 'hata' => 'Yetkilendirme oturumu bulunamadı.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $icerik = file_get_contents($logFile);

        // Token alındı mı? (farklı rclone sürüm formatlarını destekler)
        $token = '';
        if (preg_match('/Paste the following into your remote machine --->\s*(.*?)\s*<---/s', $icerik, $m)) {
            $token = trim(preg_replace('/\r?\n/', '', $m[1]));
        } elseif (preg_match('/(\{"access_token"[^}]+\})/s', $icerik, $m)) {
            $token = trim(preg_replace('/\r?\n/', '', $m[1]));
        }

        if ($token !== '') {

            // rclone config dosyasına doğrudan yaz — Windows'ta JSON escaping sorununu önler
            exec('"' . RCLONE . '" config file 2>&1', $confOut);
            $confPath = '';
            foreach ($confOut as $line) {
                $line = trim($line);
                if (preg_match('/\.conf$/i', $line) && strpos($line, ' ') === false) {
                    $confPath = $line;
                    break;
                }
                // "Configuration file is stored at:" satırından sonraki satır yol olabilir
                if (!empty($confPath)) break;
            }
            // Alternatif: son satır genellikle dosya yoludur
            if (!$confPath) {
                foreach (array_reverse($confOut) as $line) {
                    $line = trim($line);
                    if ($line !== '' && !str_starts_with($line, '2')) {
                        $confPath = $line;
                        break;
                    }
                }
            }

            $hataMesaji = '';
            $tamam = false;

            if ($confPath && is_dir(dirname($confPath))) {
                $mevcutConf = file_exists($confPath) ? file_get_contents($confPath) : '';
                // Eski remote bloğunu sil
                $mevcutConf = preg_replace(
                    '/^\[' . preg_quote(REMOTE_AD, '/') . '\][^\[]*/ms',
                    '',
                    $mevcutConf
                );
                // Yeni remote bloğunu ekle
                $mevcutConf = rtrim($mevcutConf) . "\n\n[" . REMOTE_AD . "]\ntype = drive\nscope = drive\ntoken = " . $token . "\n";
                if (file_put_contents($confPath, $mevcutConf) !== false) {
                    $tamam = true;
                } else {
                    $hataMesaji = 'Config dosyasına yazılamadı: ' . $confPath;
                }
            } else {
                $hataMesaji = 'rclone config yolu bulunamadı: ' . implode(' | ', $confOut);
            }

            islemi_sonlandir();

            if ($tamam) {
                set_setting($pdo, 'gdrive_bagli', '1');
                echo json_encode(['ok' => true, 'tamam' => true], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => false, 'hata' => $hataMesaji], JSON_UNESCAPED_UNICODE);
            }
        } else {
            // Hâlâ bekliyoruz
            echo json_encode(['ok' => true, 'tamam' => false], JSON_UNESCAPED_UNICODE);
        }
        break;

    /* ---- YEDEKLE (elle) ---- */
    case 'yedekle':
        check_csrf();

        if (!remote_mevcut()) {
            echo json_encode(['ok' => false, 'hata' => 'Google Drive bağlı değil.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $src       = DB_YOL;
        $destKlas  = REMOTE_AD . ':' . YEDEK_KLASORU;
        $destDosya = $destKlas . '/' . yedek_dosya_adi();

        // Senkron — kullanıcı bekler, kesin sonuç döner
        set_time_limit(120);
        exec('"' . RCLONE . '" copyto ' . escapeshellarg($src) . ' ' . escapeshellarg($destDosya) . ' 2>&1', $out, $code);

        if ($code === 0) {
            $ts    = time();
            $zaman = date('d.m.Y H:i', $ts);
            set_setting($pdo, 'gdrive_son_yedek',    $zaman);
            set_setting($pdo, 'gdrive_son_yedek_ts', (string)$ts);
            set_setting($pdo, 'gdrive_bagli', '1');
            $gunSayisi = (int)setting($pdo, 'gdrive_sakla_gun', '7');
            cleanup_eski_yedekler($gunSayisi, $destKlas);
            echo json_encode(['ok' => true, 'zaman' => $zaman], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'hata' => implode("\n", $out)], JSON_UNESCAPED_UNICODE);
        }
        break;

    /* ---- SIKLIĞI KAYDET ---- */
    case 'sikluk_kaydet':
        check_csrf();
        $saat = (int)($_POST['sikluk'] ?? 0);
        if (!in_array($saat, [0, 4, 24, 168, 720], true)) {
            echo json_encode(['ok' => false, 'hata' => 'Geçersiz sıklık.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        set_setting($pdo, 'gdrive_sikluk', (string)$saat);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;

    /* ---- SAKLAMA SÜRESİ KAYDET ---- */
    case 'sakla_gun_kaydet':
        check_csrf();
        $gun = (int)($_POST['sakla_gun'] ?? 7);
        if (!in_array($gun, [3, 7, 14, 30, 0], true)) {
            echo json_encode(['ok' => false, 'hata' => 'Geçersiz değer.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        set_setting($pdo, 'gdrive_sakla_gun', (string)$gun);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;

    /* ---- OTOMATİK YEDEK KONTROLÜ (her sayfa yüklenince arka planda çağrılır) ---- */
    case 'otomatik':
        $sikluk = (int)setting($pdo, 'gdrive_sikluk', '0');

        if ($sikluk === 0) {
            echo json_encode(['ok' => true, 'atildi' => 'kapali'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // remote_mevcut() yerine önbelleklenmiş bayrak kullan — rclone process başlatılmaz,
        // sunucu bloke olmaz. Bağlantı durumu zaten 'yedekle'/'durum' işlemlerinde güncelleniyor.
        if (!rclone_mevcut() || setting($pdo, 'gdrive_bagli', '0') !== '1') {
            echo json_encode(['ok' => true, 'atildi' => 'bagli_degil'], JSON_UNESCAPED_UNICODE);
            break;
        }

        $sonTs   = (int)setting($pdo, 'gdrive_son_yedek_ts', '0');
        $gerekli = $sikluk * 3600; // saat → saniye

        if ((time() - $sonTs) < $gerekli) {
            echo json_encode(['ok' => true, 'atildi' => 'henuz_erken'], JSON_UNESCAPED_UNICODE);
            break;
        }

        // Yedek zamanı geldi → rclone'u senkron çalıştır, çıktıyı kontrol et
        $src       = DB_YOL;
        $destKlas  = REMOTE_AD . ':' . YEDEK_KLASORU;
        $destDosya = $destKlas . '/' . yedek_dosya_adi();
        exec('"' . RCLONE . '" copyto ' . escapeshellarg($src) . ' ' . escapeshellarg($destDosya) . ' 2>&1', $out, $code);

        // Sadece başarılıysa timestamp güncelle
        if ($code !== 0) {
            $logYol = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mobistok_yedek_hata.log';
            file_put_contents($logYol, date('d.m.Y H:i:s') . "\n" . implode("\n", $out) . "\n\n", FILE_APPEND);
            // Token süresi dolmuş olabilir — bağlı değil işaretle
            if (stripos(implode(' ', $out), 'token') !== false || stripos(implode(' ', $out), 'auth') !== false) {
                set_setting($pdo, 'gdrive_bagli', '0');
            }
            echo json_encode(['ok' => false, 'atildi' => 'hata', 'hata' => implode("\n", $out)], JSON_UNESCAPED_UNICODE);
            break;
        }

        $ts    = time();
        $zaman = date('d.m.Y H:i', $ts);
        set_setting($pdo, 'gdrive_son_yedek',    $zaman);
        set_setting($pdo, 'gdrive_son_yedek_ts', (string)$ts);
        // Eski yedekleri temizle
        $gunSayisi = (int)setting($pdo, 'gdrive_sakla_gun', '7');
        cleanup_eski_yedekler($gunSayisi, $destKlas);

        echo json_encode(['ok' => true, 'atildi' => 'baslatildi', 'zaman' => $zaman], JSON_UNESCAPED_UNICODE);
        break;

    /* ---- YEDEK LİSTESİ ---- */
    case 'yedek_listele':
        if (!remote_mevcut()) {
            echo json_encode(['ok' => false, 'hata' => 'Google Drive bağlı değil.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $klasor = REMOTE_AD . ':' . YEDEK_KLASORU;
        exec('"' . RCLONE . '" lsf ' . escapeshellarg($klasor) . ' 2>&1', $dosyalar, $code);
        if ($code !== 0) {
            echo json_encode(['ok' => false, 'hata' => implode("\n", $dosyalar)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $liste = [];
        foreach ($dosyalar as $d) {
            $d = trim($d);
            if (preg_match('/^telefoncu_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})\.db$/', $d, $m)) {
                $liste[] = [
                    'dosya' => $d,
                    'etiket' => $m[1] . ' ' . $m[2] . ':' . $m[3],
                ];
            }
        }
        // En yeni önce
        usort($liste, fn($a, $b) => strcmp($b['dosya'], $a['dosya']));
        echo json_encode(['ok' => true, 'liste' => $liste], JSON_UNESCAPED_UNICODE);
        break;

    /* ---- GERİ YÜKLE ---- */
    case 'geri_yukle':
        check_csrf();

        $dosyaAdi = $_POST['dosya'] ?? '';
        // Güvenlik: sadece beklenen formata izin ver
        if (!preg_match('/^telefoncu_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.db$/', $dosyaAdi)) {
            echo json_encode(['ok' => false, 'hata' => 'Geçersiz dosya adı.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!remote_mevcut()) {
            echo json_encode(['ok' => false, 'hata' => 'Google Drive bağlı değil.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $kaynak   = REMOTE_AD . ':' . YEDEK_KLASORU . '/' . $dosyaAdi;
        $gecici   = dirname(DB_YOL) . DIRECTORY_SEPARATOR . 'restore_temp.db';
        $yerinede = dirname(DB_YOL) . DIRECTORY_SEPARATOR . 'telefoncu_onceki.db';

        set_time_limit(120);
        exec('"' . RCLONE . '" copyto ' . escapeshellarg($kaynak) . ' ' . escapeshellarg($gecici) . ' 2>&1', $out, $code);

        if ($code !== 0) {
            echo json_encode(['ok' => false, 'hata' => 'İndirme hatası: ' . implode("\n", $out)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // SQLite dosyası mı kontrol et (ilk 6 bayt "SQLite" magic)
        $magic = @file_get_contents($gecici, false, null, 0, 6);
        if ($magic !== 'SQLite') {
            @unlink($gecici);
            echo json_encode(['ok' => false, 'hata' => 'İndirilen dosya geçerli bir veritabanı değil.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // PDO bağlantısını kapat (SQLite kilidini serbest bırak)
        $pdo = null;
        gc_collect_cycles();

        // Mevcut DB yedeğini al
        @unlink($yerinede);
        @copy(DB_YOL, $yerinede);

        // İndirilen dosyanın içeriğini doğrudan mevcut DB dosyasına yaz
        // (rename yerine — Windows'ta kilitli dosyalar rename'e izin vermiyor)
        $icerikYeni = file_get_contents($gecici);
        $sonuc = ($icerikYeni !== false && file_put_contents(DB_YOL, $icerikYeni) !== false);
        @unlink($gecici);

        if ($sonuc) {
            echo json_encode(['ok' => true, 'mesaj' => $dosyaAdi . ' geri yüklendi. Sayfa yenileniyor…'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'hata' => 'Dosya yazılamadı. Lütfen tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        }
        break;

    /* ---- BAĞLANTIYI KES ---- */
    case 'baglantiyi_kes':
        check_csrf();

        exec('"' . RCLONE . '" config delete ' . escapeshellarg(REMOTE_AD) . ' 2>&1', $out, $code);
        set_setting($pdo, 'gdrive_son_yedek', '');
        set_setting($pdo, 'gdrive_son_yedek_ts', '0');
        set_setting($pdo, 'gdrive_bagli', '0');

        echo json_encode(['ok' => $code === 0], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'hata' => 'Bilinmeyen eylem.'], JSON_UNESCAPED_UNICODE);
}
