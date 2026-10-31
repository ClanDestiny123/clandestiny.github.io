<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        http_response_code(403); exit('Invalid request token. Go back and try again.');
    }
}
function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function client_ip(): string {
    // Do not trust X-Forwarded-For on shared hosting; use the server-provided remote address.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}
function redirect_board(): never { header('Location: index.php'); exit; }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed. Check config.php and your InfinityFree MySQL details. If this is a new install, run installer.php.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]); $admin = $stmt->fetch();
        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_name'] = (string)$admin['username'];
            redirect_board();
        }
        $_SESSION['flash'] = 'Login failed. Check your username and password.';
        redirect_board();
    }
    if ($action === 'logout') { unset($_SESSION['admin_id'], $_SESSION['admin_name']); session_regenerate_id(true); redirect_board(); }

    if ($action === 'post') {
        $body = trim((string)($_POST['body'] ?? ''));
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $sage = isset($_POST['sage']) ? 1 : 0;
        if ($body === '' || mb_strlen($body) > 4000) {
            $_SESSION['flash'] = 'Posts must contain 1–4,000 characters.'; redirect_board();
        }
        $ip = client_ip();
        $ban = $pdo->prepare('SELECT id FROM bans WHERE ip_address = ? LIMIT 1');
        $ban->execute([$ip]);
        if ($ban->fetch()) { http_response_code(403); exit('Posting is disabled for this IP address.'); }

        if ($threadId > 0) {
            $q = $pdo->prepare('SELECT id, sticky FROM threads WHERE id = ?'); $q->execute([$threadId]); $thread = $q->fetch();
            if (!$thread) { $_SESSION['flash'] = 'That thread no longer exists.'; redirect_board(); }
            $stmt = $pdo->prepare('INSERT INTO posts (thread_id, name, body, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$threadId, is_admin() ? (string)($_SESSION['admin_name'] ?? 'Admin') : 'Anonymous', $body, $ip]);
            if (!$sage && !(int)$thread['sticky']) $pdo->prepare('UPDATE threads SET bumped_at = NOW() WHERE id = ?')->execute([$threadId]);
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO threads (subject, sticky, created_at, bumped_at) VALUES (?, 0, NOW(), NOW())');
            $subject = trim((string)($_POST['subject'] ?? ''));
            if ($subject === '') $subject = mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 80);
            if (mb_strlen($subject) > 120) $subject = mb_substr($subject, 0, 120);
            $stmt->execute([$subject]); $newThreadId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO posts (thread_id, name, body, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$newThreadId, is_admin() ? (string)($_SESSION['admin_name'] ?? 'Admin') : 'Anonymous', $body, $ip]);
            $pdo->commit();
        }
        prune_threads($pdo);
        redirect_board();
    }

    if (!is_admin()) { http_response_code(403); exit('Admin login required.'); }
    if ($action === 'delete_post') {
        $id = (int)($_POST['post_id'] ?? 0);
        $q = $pdo->prepare('SELECT thread_id FROM posts WHERE id = ?'); $q->execute([$id]); $row = $q->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
            $count = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE thread_id = ?'); $count->execute([$row['thread_id']]);
            if ((int)$count->fetchColumn() === 0) $pdo->prepare('DELETE FROM threads WHERE id = ?')->execute([$row['thread_id']]);
        }
        redirect_board();
    }
    if ($action === 'edit_post') {
        $id = (int)($_POST['post_id'] ?? 0); $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '' && mb_strlen($body) <= 4000) $pdo->prepare('UPDATE posts SET body = ? WHERE id = ?')->execute([$body, $id]);
        redirect_board();
    }
    if ($action === 'sticky') {
        $id = (int)($_POST['thread_id'] ?? 0); $sticky = isset($_POST['sticky']) ? 1 : 0;
        $pdo->prepare('UPDATE threads SET sticky = ? WHERE id = ?')->execute([$sticky, $id]);
        prune_threads($pdo); redirect_board();
    }
    if ($action === 'ban') {
        $id = (int)($_POST['post_id'] ?? 0);
        $q = $pdo->prepare('SELECT ip_address FROM posts WHERE id = ?'); $q->execute([$id]); $ip = $q->fetchColumn();
        if ($ip && $ip !== 'unknown') $pdo->prepare('INSERT IGNORE INTO bans (ip_address, reason, created_at) VALUES (?, ?, NOW())')->execute([$ip, trim((string)($_POST['reason'] ?? 'Admin ban')) ?: 'Admin ban']);
        redirect_board();
    }
    if ($action === 'unban') { $pdo->prepare('DELETE FROM bans WHERE id = ?')->execute([(int)($_POST['ban_id'] ?? 0)]); redirect_board(); }
    if ($action === 'change_admin_name') {
        $name = trim((string)($_POST['admin_name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 40) {
            $pdo->prepare('UPDATE admins SET username = ? WHERE id = ?')->execute([$name, (int)$_SESSION['admin_id']]);
            $_SESSION['admin_name'] = $name;
        }
        redirect_board();
    }
}

function prune_threads(PDO $pdo): void {
    // Keep the newest 30 non-sticky threads; sticky threads are protected and may make the total exceed 30.
    $stmt = $pdo->query('SELECT id FROM threads WHERE sticky = 0 ORDER BY bumped_at DESC, id DESC LIMIT 18446744073709551615 OFFSET 30');
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM threads WHERE id IN ($marks)")->execute($ids);
    }
}
$threads = $pdo->query('SELECT * FROM threads ORDER BY sticky DESC, bumped_at DESC, id DESC')->fetchAll();
$postsStmt = $pdo->prepare('SELECT * FROM posts WHERE thread_id = ? ORDER BY id ASC');
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$bans = is_admin() ? $pdo->query('SELECT id, ip_address, reason, created_at FROM bans ORDER BY id DESC')->fetchAll() : [];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(BOARD_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#111318;--panel:#1b1e25;--line:#343944;--text:#e6e8ed;--muted:#969daa;--link:#9dbcfb;--red:#ff6868}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,sans-serif}header,main{max-width:960px;margin:auto;padding:18px}header{border-bottom:1px solid var(--line)}h1{margin:0;font-size:1.7rem}a{color:var(--link)}.muted{color:var(--muted)}.panel,.thread{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:14px;margin:14px 0}.thread.sticky{border-color:#b18a43}.post{padding:10px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.post:last-child{border:0}.meta{font-size:.85rem;color:var(--muted)}.name{color:#9da3ad;font-weight:650}.admin-name{color:var(--red);font-weight:800}.postid{color:#8ab4ff}.body{white-space:pre-wrap;margin-top:5px}.subject{font-weight:750}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}input,textarea,button{font:inherit;background:#101217;color:var(--text);border:1px solid #454b58;border-radius:5px;padding:9px}input,textarea{width:100%}textarea{min-height:95px;resize:vertical}button{cursor:pointer;background:#292e39}button:hover{background:#373e4b}.smallform{display:inline-flex;gap:5px;align-items:center;margin:3px 4px 3px 0}.smallform input{width:auto}.flash{padding:10px;border:1px solid #7a5a2a;border-radius:5px}.admin-tools{margin-top:7px;font-size:.85rem}.admin-tools details{display:inline-block}.compose-grid{display:grid;grid-template-columns:1fr 2fr;gap:10px}@media(max-width:600px){.compose-grid{grid-template-columns:1fr}}
</style></head><body>
<header><div class="row" style="justify-content:space-between"><div><h1><?= h(BOARD_TITLE) ?></h1><div class="muted">One page · latest 30 regular threads · sage does not bump</div></div>
<?php if (is_admin()): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button>Log out (<?= h((string)($_SESSION['admin_name'] ?? 'Admin')) ?>)</button></form><?php else: ?><a href="#admin">Admin login</a><?php endif; ?></div></header>
<main>
<?php if ($flash): ?><p class="flash"><?= h((string)$flash) ?></p><?php endif; ?>
<section class="panel"><h2 style="margin-top:0">Start a thread</h2><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="post">
<div class="compose-grid"><div><label>Subject (optional)<input name="subject" maxlength="120" placeholder="Thread subject"></label></div><div><label>Message<textarea name="body" required maxlength="4000" placeholder="Write your post…"></textarea></label></div></div>
<p class="muted">Posting as <strong class="<?= is_admin() ? 'admin-name' : 'name' ?>"><?= h(is_admin() ? (string)($_SESSION['admin_name'] ?? 'Admin') : 'Anonymous') ?></strong></p><button type="submit">Post thread</button></form></section>
<?php foreach ($threads as $thread): $postsStmt->execute([$thread['id']]); $posts = $postsStmt->fetchAll(); ?>
<article class="thread <?= (int)$thread['sticky'] ? 'sticky' : '' ?>" id="thread-<?= (int)$thread['id'] ?>">
<div class="subject"><?= (int)$thread['sticky'] ? '📌 ' : '' ?><?= h((string)$thread['subject']) ?> <span class="meta">/ thread #<?= (int)$thread['id'] ?></span></div>
<?php foreach ($posts as $post): ?>
<div class="post"><div class="meta"><span class="<?= $post['name'] === 'Anonymous' ? 'name' : 'admin-name' ?>"><?= h((string)$post['name']) ?></span> <span class="postid">No.<?= (int)$post['id'] ?></span> · <?= h((string)$post['created_at']) ?></div>
<div class="body" id="body-<?= (int)$post['id'] ?>"><?= h((string)$post['body']) ?></div>
<?php if (is_admin()): ?><div class="admin-tools">
<details><summary>Edit</summary><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="edit_post"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><textarea name="body" required maxlength="4000"><?= h((string)$post['body']) ?></textarea><button>Save edit</button></form></details>
<form class="smallform" method="post" onsubmit="return confirm('Delete this post?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><button>Delete</button></form>
<form class="smallform" method="post" onsubmit="return confirm('Ban this poster IP? Shared IPs may affect other people.')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="ban"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><input name="reason" placeholder="Ban reason" aria-label="Ban reason"><button>Ban IP</button></form>
</div><?php endif; ?></div>
<?php endforeach; ?>
<div class="row"><form method="post" style="flex:1;min-width:240px"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>"><textarea name="body" required maxlength="4000" placeholder="Reply to thread #<?= (int)$thread['id'] ?>" style="min-height:65px"></textarea><div class="row"><label><input type="checkbox" name="sage" value="1" style="width:auto"> Sage (don't bump)</label><button>Reply</button></div></form>
<?php if (is_admin()): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="sticky"><input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>"><input type="hidden" name="sticky" value="<?= (int)!$thread['sticky'] ?>"><button><?= (int)$thread['sticky'] ? 'Unsticky' : 'Sticky' ?></button></form><?php endif; ?></div>
</article>
<?php endforeach; ?>
<?php if (!$threads): ?><p class="muted">No threads yet. Start one above.</p><?php endif; ?>
<?php if (!is_admin()): ?><section class="panel" id="admin"><h2 style="margin-top:0">Admin login</h2><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="login"><p><label>Username<input name="username" required autocomplete="username"></label></p><p><label>Password<input type="password" name="password" required autocomplete="current-password"></label></p><button>Log in</button></form></section>
<?php else: ?><section class="panel"><h2 style="margin-top:0">Admin settings</h2><form method="post" class="row"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="change_admin_name"><label style="flex:1">Admin username / display name<input name="admin_name" maxlength="40" required value="<?= h((string)($_SESSION['admin_name'] ?? 'Admin')) ?>"></label><button>Change name</button></form>
<h3>IP bans</h3><?php if (!$bans): ?><p class="muted">No IP bans.</p><?php else: foreach ($bans as $ban): ?><div class="row"><span><?= h((string)$ban['ip_address']) ?> — <?= h((string)$ban['reason']) ?></span><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="unban"><input type="hidden" name="ban_id" value="<?= (int)$ban['id'] ?>"><button>Unban</button></form></div><?php endforeach; endif; ?></section><?php endif; ?>
<footer class="muted" style="padding:20px 0;text-align:center">QuietBoard · Keep it civil.</footer>
</main></body></html>
