<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['admin_id'])) { header('Location: index.php#admin'); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function go(): never { header('Location: admin.php'); exit; }
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { http_response_code(500); exit('Database connection failed. Check config.php.'); }
$me = (int)$_SESSION['admin_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals(token(), (string)$_POST['csrf'])) { http_response_code(403); exit('Invalid request token.'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_admin') {
        $username = trim((string)($_POST['username'] ?? '')); $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) $_SESSION['admin_flash'] = 'Username must be 3–40 characters: letters, numbers, dot, dash, underscore.';
        elseif (strlen($password) < 12) $_SESSION['admin_flash'] = 'Use an admin password of at least 12 characters.';
        else {
            try { $q=$pdo->prepare('INSERT INTO admins (username,password_hash) VALUES (?,?)'); $q->execute([$username,password_hash($password,PASSWORD_DEFAULT)]); $_SESSION['admin_flash']='Admin account created.'; }
            catch (PDOException $e) { $_SESSION['admin_flash'] = $e->getCode()==='23000' ? 'That username is already in use.' : 'Could not create the account.'; }
        }
        go();
    }
    if ($action === 'delete_admin') {
        $id=(int)($_POST['admin_id'] ?? 0); $count=(int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($id === $me) $_SESSION['admin_flash']='You cannot delete the account you are currently using.';
        elseif ($count <= 1) $_SESSION['admin_flash']='The last admin account cannot be deleted.';
        else { $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$id]); $_SESSION['admin_flash']='Admin account deleted.'; }
        go();
    }
    if ($action === 'change_password') {
        $current=(string)($_POST['current_password'] ?? ''); $new=(string)($_POST['new_password'] ?? ''); $confirm=(string)($_POST['confirm_password'] ?? '');
        $q=$pdo->prepare('SELECT password_hash FROM admins WHERE id=?'); $q->execute([$me]); $hash=$q->fetchColumn();
        if (!$hash || !password_verify($current,(string)$hash)) $_SESSION['admin_flash']='Current password is incorrect.';
        elseif (strlen($new)<12) $_SESSION['admin_flash']='New password must be at least 12 characters.';
        elseif ($new !== $confirm) $_SESSION['admin_flash']='New passwords do not match.';
        else { $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$me]); session_regenerate_id(true); $_SESSION['admin_id']=$me; $_SESSION['admin_flash']='Password changed successfully.'; }
        go();
    }
    if ($action === 'set_global_lock') {
        $lockValue = isset($_POST['locked']) && (string)$_POST['locked'] === '1' ? '1' : '0';
        try {
            $stmt = $pdo->prepare("INSERT INTO board_settings (setting_key, setting_value) VALUES ('global_lock', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$lockValue]);
            $_SESSION['admin_flash'] = $lockValue === '1' ? 'Global board lock enabled. Visitors can read but cannot post.' : 'Global board lock disabled. Posting is open again.';
        } catch (Throwable $e) {
            $_SESSION['admin_flash'] = 'Board lock settings are not installed yet. Log in, run upgrade.php once, then return here.';
        }
        go();
    }
    if ($action === 'unban') { $pdo->prepare('DELETE FROM bans WHERE id=?')->execute([(int)($_POST['ban_id'] ?? 0)]); $_SESSION['admin_flash']='IP ban removed.'; go(); }
}
$admins=$pdo->query('SELECT id,username FROM admins ORDER BY id ASC')->fetchAll();
$bans=$pdo->query('SELECT id,ip_address,reason,created_at FROM bans ORDER BY id DESC')->fetchAll();
$globalLockEnabled = false;
$globalLockAvailable = true;
try {
    $globalLockEnabled = $pdo->query("SELECT setting_value FROM board_settings WHERE setting_key = 'global_lock' LIMIT 1")->fetchColumn() === '1';
} catch (Throwable $e) { $globalLockAvailable = false; }
$flash=(string)($_SESSION['admin_flash'] ?? ''); unset($_SESSION['admin_flash']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin dashboard — <?= h(BOARD_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#111318;--panel:#1b1e25;--line:#343944;--text:#e6e8ed;--muted:#969daa;--link:#9dbcfb;--red:#ff8585}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,sans-serif}main{max-width:850px;margin:auto;padding:20px}a{color:var(--link)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:16px;margin:16px 0}input,button{font:inherit;background:#101217;color:var(--text);border:1px solid #454b58;border-radius:5px;padding:9px}input{width:100%;display:block;margin:5px 0 12px}button{cursor:pointer;background:#292e39}button:hover{background:#373e4b}.row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.muted{color:var(--muted)}.flash{padding:10px;border:1px solid #7a5a2a;border-radius:5px}.danger{color:var(--red)}form.inline{display:inline}.entry{padding:10px 0;border-bottom:1px solid var(--line)}.entry:last-child{border:0}.status-on{color:#ff8585;font-weight:700}.status-off{color:#8ce0a2;font-weight:700}.lock-panel{border-color:#9a4545}
</style></head><body><main>
<div class="row"><div><h1>Admin dashboard</h1><div class="muted"><?= h(BOARD_TITLE) ?> · signed in as <?= h((string)($_SESSION['admin_name'] ?? 'Admin')) ?></div></div><a href="index.php">← Back to board</a></div>
<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>
<section class="panel lock-panel"><h2>Global board lock</h2>
<?php if (!$globalLockAvailable): ?><p class="danger">The global lock setting has not been installed yet. Run <code>upgrade.php</code> once while logged in as admin, then reload this page.</p>
<?php else: ?><p>Status: <span class="<?= $globalLockEnabled ? 'status-on' : 'status-off' ?>"><?= $globalLockEnabled ? 'LOCKED — visitors are read-only' : 'OPEN — visitors can post' ?></span></p>
<p class="muted">When locked, visitors can still read the board but cannot start threads or reply. Admins can continue posting and moderating. This setting is saved in MySQL and persists across sessions.</p>
<form method="post" onsubmit="return confirm('<?= $globalLockEnabled ? 'Unlock the board and allow public posting?' : 'Lock the whole board and stop public posting?' ?>')"><input type="hidden" name="csrf" value="<?= h(token()) ?>"><input type="hidden" name="action" value="set_global_lock"><input type="hidden" name="locked" value="<?= $globalLockEnabled ? '0' : '1' ?>"><button><?= $globalLockEnabled ? 'Unlock entire board' : 'Lock entire board' ?></button></form><?php endif; ?></section>
<section class="panel"><h2>Admin accounts</h2><p class="muted">Each administrator has an individual login. Passwords are stored as hashes.</p>
<?php foreach ($admins as $a): ?><div class="entry row"><span><?= h((string)$a['username']) ?> <?= (int)$a['id']===$me ? '<strong>(you)</strong>' : '' ?></span><?php if ((int)$a['id']!==$me && count($admins)>1): ?><form class="inline" method="post" onsubmit="return confirm('Delete this admin account?')"><input type="hidden" name="csrf" value="<?= h(token()) ?>"><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>"><button>Delete account</button></form><?php endif; ?></div><?php endforeach; ?>
<h3>Add administrator</h3><form method="post"><input type="hidden" name="csrf" value="<?= h(token()) ?>"><input type="hidden" name="action" value="create_admin"><label>Username<input name="username" required minlength="3" maxlength="40" pattern="[A-Za-z0-9_.-]+" autocomplete="off"></label><label>Temporary or initial password (at least 12 characters)<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><button>Create admin account</button></form></section>
<section class="panel"><h2>Change your password</h2><form method="post"><input type="hidden" name="csrf" value="<?= h(token()) ?>"><input type="hidden" name="action" value="change_password"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label><button>Change password</button></form></section>
<section class="panel"><h2>IP ban management</h2><p class="muted">Bans are based on the visitor IP seen by the server. Shared networks may share an IP address.</p>
<?php if (!$bans): ?><p class="muted">There are currently no IP bans.</p><?php else: foreach ($bans as $b): ?><div class="entry row"><div><strong><?= h((string)$b['ip_address']) ?></strong><div class="muted"><?= h((string)$b['reason']) ?> · <?= h((string)$b['created_at']) ?></div></div><form class="inline" method="post"><input type="hidden" name="csrf" value="<?= h(token()) ?>"><input type="hidden" name="action" value="unban"><input type="hidden" name="ban_id" value="<?= (int)$b['id'] ?>"><button>Unban IP</button></form></div><?php endforeach; endif; ?></section>
</main></body></html>
