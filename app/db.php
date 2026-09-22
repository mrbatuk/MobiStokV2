<?php
// SQLite bağlantısı + ilk çalıştırmada şema kurulumu

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $pdo = new PDO('sqlite:' . $dir . DIRECTORY_SEPARATOR . 'telefoncu.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        db_init_schema($pdo);
    }
    return $pdo;
}

function db_init_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            sort_order INTEGER DEFAULT 0
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL REFERENCES categories(id),
            purchase_date TEXT NOT NULL,
            model TEXT NOT NULL,
            imei TEXT DEFAULT '',
            purchase_price REAL NOT NULL DEFAULT 0,
            seller TEXT DEFAULT '',
            note TEXT DEFAULT '',
            purchase_note TEXT DEFAULT '',
            sale_note TEXT DEFAULT '',
            buyer TEXT DEFAULT '',
            sale_date TEXT,
            sale_price REAL,
            profit REAL,
            counted INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_cat ON devices(category_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_sale ON devices(sale_date)");

    // Mevcut veritabanları için eksik kolonları güvenle ekle (migration)
    $cols = $pdo->query("PRAGMA table_info(devices)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('purchase_note', $cols, true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN purchase_note TEXT DEFAULT ''");
    }
    if (!in_array('sale_note', $cols, true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN sale_note TEXT DEFAULT ''");
    }
    if (!in_array('counted', $cols, true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN counted INTEGER DEFAULT 0");
    }
    if (!in_array('buyer', $cols, true)) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN buyer TEXT DEFAULT ''");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS servis (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            musteri       TEXT NOT NULL,
            tel           TEXT DEFAULT '',
            cihaz         TEXT NOT NULL,
            ariza         TEXT DEFAULT '',
            alinan_tarih  TEXT NOT NULL,
            teslim_tarih  TEXT,
            maliyet       REAL NOT NULL DEFAULT 0,
            tahsilat      REAL NOT NULL DEFAULT 0,
            kar           REAL NOT NULL DEFAULT 0,
            durum         TEXT NOT NULL DEFAULT 'alindi',
            note          TEXT DEFAULT '',
            created_at    TEXT DEFAULT (datetime('now'))
        )
    ");

    // Eski kayıtlarda note alanı alış notu olarak kullanılıyordu.
    // purchase_note boşsa note'u oraya taşı — SADECE BİR KEZ.
    // (Bayrak olmadan her sayfa yüklemesinde çalışıp sonradan eklenen
    //  genel notları da alış notuna taşır ve listeden kaybolmasına yol açar.)
    $noteMig = $pdo->prepare("SELECT value FROM settings WHERE key='note_migrated'");
    $noteMig->execute();
    if ($noteMig->fetchColumn() !== '1') {
        $pdo->exec("
            UPDATE devices
            SET purchase_note = note, note = ''
            WHERE (purchase_note IS NULL OR purchase_note = '') AND note != ''
        ");
        $pdo->prepare("INSERT INTO settings (key,value) VALUES ('note_migrated','1')
                       ON CONFLICT(key) DO UPDATE SET value='1'")->execute();
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO categories (name, sort_order) VALUES ('SIFIR', 1), ('2.EL', 2), ('AKSESUAR', 3)");
    }

    // IMEI şifreleme — sadece bir kez çalışır
    // imei_key() içeride db() singleton'ını çağırır; bu noktada PDO zaten hazır.
    $encFlag = $pdo->prepare("SELECT value FROM settings WHERE key='imei_encrypted'");
    $encFlag->execute();
    if ($encFlag->fetchColumn() !== '1') {
        $rows = $pdo->query(
            "SELECT id, imei FROM devices WHERE imei IS NOT NULL AND imei != ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $upd = $pdo->prepare("UPDATE devices SET imei=? WHERE id=?");
        foreach ($rows as $r) {
            $upd->execute([imei_enc($r['imei']), $r['id']]);
        }
        $pdo->prepare("INSERT INTO settings (key,value) VALUES ('imei_encrypted','1')
                       ON CONFLICT(key) DO UPDATE SET value='1'")->execute();
    }
}
