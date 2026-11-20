<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Log in as an administrator on index.php first, then visit this page.'); }
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Ensure individual thread locking exists (safe to run repeatedly).
    $threadLockColumn = $pdo->query("SHOW COLUMNS FROM threads LIKE 'locked'")->fetch();
    if (!$threadLockColumn) {
        $pdo->exec('ALTER TABLE threads ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0');
    }

    // Persistent board-wide settings. The initial state is unlocked.
    $pdo->exec("CREATE TABLE IF NOT EXISTS board_settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO board_settings (setting_key, setting_value) VALUES ('global_lock', '0')");

    $message = 'Upgrade complete. Global board lock is installed and currently OFF. Delete upgrade.php now.';
} catch (Throwable $e) {
    http_response_code(500);
    exit('Upgrade failed. Back up your database and check the MySQL error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QuietBoard v3 upgrade</title></head>
<body style="font:16px/1.5 system-ui;max-width:680px;margin:40px auto;padding:0 16px;background:#111318;color:#e6e8ed">
<h1><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></h1>
<p>Visit <a style="color:#9dbcfb" href="admin.php">the admin dashboard</a> to lock or unlock public posting.</p>
<p><strong>Security:</strong> delete <code>upgrade.php</code> from htdocs immediately.</p>
</body></html>
