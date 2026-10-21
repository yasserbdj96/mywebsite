<?php
declare(strict_types=1);

/**
 * Links control panel (MySQL version)
 * 1st visit  -> installer: enter database info + choose an admin password. Tables are created automatically.
 * Later      -> login, then edit profile, photo and links. Everything is stored in the database.
 */

require __DIR__ . '/db.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

/* ---------- Helpers ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Invalid request token. Go back and reload the page.');
    }
}
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function redirect(): void { header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

/** Validates an uploaded image. Returns ['mime','bytes'] or null (and sets $error). */
function read_upload(array $file, string &$error): ?array {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { $error = 'Image upload failed (code ' . $file['error'] . '). The file may exceed the server upload limit.'; return null; }
    if ($file['size'] > MAX_IMAGE) { $error = 'Image is too large (max 2 MB).'; return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(IMAGE_TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
        $error = 'Only JPG, PNG, WebP or GIF images are allowed.';
        return null;
    }
    return ['mime' => $mime, 'bytes' => (string)file_get_contents($file['tmp_name'])];
}

/* ---------- State ---------- */
$config   = config();
$loggedIn = !empty($_SESSION['auth']) && $config;
$error    = '';

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // ----- Installer -----
    if ($action === 'install' && !$config) {
        $c = [
            'host' => trim((string)($_POST['db_host'] ?? '')) ?: 'localhost',
            'name' => trim((string)($_POST['db_name'] ?? '')),
            'user' => trim((string)($_POST['db_user'] ?? '')),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');

        if ($c['name'] === '' || $c['user'] === '') $error = 'Database name and user are required.';
        elseif (strlen($p1) < 8)                    $error = 'Admin password must be at least 8 characters.';
        elseif ($p1 !== $p2)                        $error = 'Admin passwords do not match.';
        else {
            try {
                $pdo = db_connect($c);
                install_tables($pdo);   // CREATE TABLE IF NOT EXISTS
                seed_from_json($pdo);   // imports your current data.json the first time
            } catch (Throwable $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
            if ($error === '') {
                $c['hash'] = password_hash($p1, PASSWORD_DEFAULT);
                $code = "<?php\nreturn " . var_export($c, true) . ";\n";
                if (file_put_contents(CONFIG_FILE, $code, LOCK_EX) === false) {
                    $error = 'Tables were created, but config.php could not be written (check folder permissions).';
                } else {
                    @chmod(CONFIG_FILE, 0600);
                    session_regenerate_id(true);
                    $_SESSION['auth'] = true;
                    flash('ok', 'Installed. Your existing data was imported.');
                    redirect();
                }
            }
        }
    }

    // ----- Login -----
    elseif ($action === 'login' && $config) {
        $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
        if ($_SESSION['tries'] > 5) sleep(3);
        if (password_verify((string)($_POST['password'] ?? ''), $config['hash'] ?? '')) {
            session_regenerate_id(true);
            $_SESSION['auth']  = true;
            $_SESSION['tries'] = 0;
            redirect();
        }
        sleep(1);
        $error = 'Wrong password.';
    }

    // ----- Logout -----
    elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // ----- Save -----
    elseif ($action === 'save' && $loggedIn) {
        $profile = [
            'title'       => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120),
            'name'        => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120),
            'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 300),
        ];

        $image = isset($_FILES['image']) ? read_upload($_FILES['image'], $error) : null;
        $remove = !empty($_POST['remove_image']);

        $links = [];
        $plat = (array)($_POST['platform'] ?? []);
        $labl = (array)($_POST['label'] ?? []);
        $urls = (array)($_POST['url'] ?? []);
        $desc = (array)($_POST['desc'] ?? []);
        foreach ($urls as $i => $u) {
            $u = trim((string)$u);
            $p = trim((string)($plat[$i] ?? ''));
            $l = trim((string)($labl[$i] ?? ''));
            $d = trim((string)($desc[$i] ?? ''));
            if ($u === '' && $l === '' && $p === '') continue; // empty row
            if (!is_safe_url($u)) {
                $error = 'Link #' . ($i + 1) . ': URL must start with https://, http://, mailto: or tel:';
                break;
            }
            $links[] = [
                'platform'    => mb_substr($p !== '' ? $p : 'link', 0, 50),
                'label'       => mb_substr($l !== '' ? $l : ($p ?: $u), 0, 120),
                'url'         => mb_substr($u, 0, 500),
                'description' => mb_substr($d, 0, 200),
            ];
        }

        if ($error === '') {
            try {
                save_all(db(), $profile, $links, $image, $remove);
                flash('ok', 'Saved. Your page is updated.');
                redirect();
            } catch (Throwable $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
        // Keep what the user typed so nothing is lost on error
        $draft = $profile;
        $draft['links'] = array_map(function ($l) {
            if ($l['description'] === '') unset($l['description']);
            return $l;
        }, $links);
        $_SESSION['draft'] = $draft;
    }
}

/* ---------- Load data for the panel ---------- */
$data = ['title' => '', 'name' => '', 'description' => '', 'image' => '', 'links' => []];
if ($loggedIn) {
    try {
        $data = get_data(db());
    } catch (Throwable $e) {
        $error = $error ?: 'Database error: ' . $e->getMessage();
    }
    if ($error && !empty($_SESSION['draft'])) $data = $_SESSION['draft'] + $data;
}
unset($_SESSION['draft']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$platforms = ['whatsapp', 'phone', 'email', 'facebook', 'instagram', 'telegram', 'twitter', 'x', 'github', 'linkedin', 'youtube', 'tiktok', 'snapchat', 'website', 'link'];

function link_row(array $l): string {
    ob_start(); ?>
    <li class="row">
      <div class="grid">
        <label>Platform <input name="platform[]" list="platforms" value="<?= h($l['platform'] ?? '') ?>" placeholder="whatsapp"></label>
        <label>Label <input name="label[]" value="<?= h($l['label'] ?? '') ?>" placeholder="WhatsApp"></label>
        <label class="wide">URL <input name="url[]" value="<?= h($l['url'] ?? '') ?>" placeholder="https://… / mailto:… / tel:…"></label>
        <label class="wide">Description (optional) <input name="desc[]" value="<?= h($l['description'] ?? '') ?>" placeholder="Shown under the label"></label>
      </div>
      <div class="tools">
        <button type="button" data-act="up" title="Move up" aria-label="Move up">↑</button>
        <button type="button" data-act="down" title="Move down" aria-label="Move down">↓</button>
        <button type="button" data-act="del" class="danger" title="Delete" aria-label="Delete link">✕</button>
      </div>
    </li>
    <?php
    return (string)ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Control panel</title>
<style>
  :root { --bg:#f3f4f6; --surface:#fff; --text:#1a1d23; --muted:#646b78; --line:#dfe2e8; --hover:#f6f7f9; --accent:#2748c9; --accent-text:#fff; --danger:#c42b2b; --ok:#1a7f45; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#101216; --surface:#171a20; --text:#e8eaee; --muted:#9aa1ae; --line:#292d36; --hover:#1d2129; --accent:#8fa6ff; --accent-text:#101216; --danger:#ff7b7b; --ok:#5fd18b; }
  }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.5 "Segoe UI",system-ui,-apple-system,Arial,sans-serif; }
  .wrap { max-width: 680px; margin: 0 auto; padding: 32px 20px 120px; }
  header.top { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:12px; }
  h1 { font-size:1.4rem; margin:0; }
  h2 { font-size:1.05rem; margin:0 0 14px; }
  .card { background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:20px; margin-bottom:18px; }
  label { display:block; font-size:.85rem; color:var(--muted); font-weight:600; margin-bottom:12px; }
  input[type=text], input:not([type]), input[type=password], textarea {
    display:block; width:100%; margin-top:4px; padding:10px 12px; font:inherit; color:var(--text);
    background:var(--bg); border:1px solid var(--line); border-radius:8px;
  }
  textarea { resize:vertical; min-height:70px; }
  input:focus, textarea:focus, button:focus-visible { outline:2px solid var(--accent); outline-offset:1px; }
  button { font:inherit; cursor:pointer; }
  .btn { display:inline-block; padding:10px 16px; border-radius:8px; border:1px solid var(--line); background:transparent; color:var(--text); font-weight:600; }
  .btn:hover { background:var(--hover); }
  .btn.primary { background:var(--accent); color:var(--accent-text); border-color:var(--accent); }
  .btn.primary:hover { opacity:.9; }
  .msg { padding:12px 14px; border-radius:10px; margin-bottom:18px; border:1px solid var(--line); background:var(--surface); word-break:break-word; }
  .msg.ok { border-color:var(--ok); color:var(--ok); }
  .msg.err { border-color:var(--danger); color:var(--danger); }
  .avatar-row { display:flex; align-items:center; gap:16px; margin-bottom:12px; }
  .avatar-row img { width:84px; height:84px; border-radius:50%; object-fit:cover; border:1px solid var(--line); background:var(--line); }
  .check { display:flex; align-items:center; gap:8px; font-weight:500; color:var(--text); margin:8px 0 0; }
  ul.rows { list-style:none; margin:0 0 14px; padding:0; display:grid; gap:12px; }
  .row { display:flex; gap:10px; border:1px solid var(--line); border-radius:10px; padding:14px; background:var(--bg); }
  .row .grid { flex:1; min-width:0; display:grid; grid-template-columns:1fr 1fr; gap:0 12px; }
  .row .wide { grid-column:1 / -1; }
  .row label { margin-bottom:10px; }
  .tools { display:flex; flex-direction:column; gap:6px; }
  .tools button { width:34px; height:34px; border:1px solid var(--line); background:var(--surface); color:var(--text); border-radius:8px; }
  .tools button:hover { background:var(--hover); }
  .tools .danger { color:var(--danger); }
  .savebar { position:fixed; left:0; right:0; bottom:0; background:var(--surface); border-top:1px solid var(--line); padding:12px 20px; }
  .savebar .in { max-width:680px; margin:0 auto; display:flex; justify-content:space-between; align-items:center; gap:12px; }
  .hint { color:var(--muted); font-size:.85rem; }
  .login { max-width:420px; margin:8vh auto 0; }
  .two { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; }
  @media (max-width:520px) { .row .grid, .two { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$config): ?>
  <div class="login">
    <h1>Install</h1>
    <p class="hint">Enter your MySQL database details (create the database and user in cPanel → MySQL Databases first, and add the user to the database with all privileges). Tables are created automatically, and your current <code>data.json</code> is imported.</p>
    <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="card" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <input type="hidden" name="action" value="install">
      <h2>Database</h2>
      <label>Host <input name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>"></label>
      <label>Database name <input name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" required></label>
      <div class="two">
        <label>Database user <input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" required></label>
        <label>Database password <input type="password" name="db_pass" autocomplete="new-password"></label>
      </div>
      <h2 style="margin-top:8px">Admin login</h2>
      <label>Admin password (8+ characters) <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label>Repeat admin password <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary" type="submit">Install</button>
    </form>
  </div>

<?php elseif (!$loggedIn): ?>
  <div class="login">
    <h1>Control panel</h1>
    <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <input type="hidden" name="action" value="login">
      <label>Password <input type="password" name="password" required autocomplete="current-password" autofocus></label>
      <button class="btn primary" type="submit">Log in</button>
    </form>
  </div>

<?php else: ?>
  <header class="top">
    <h1>Control panel</h1>
    <div style="display:flex;gap:8px">
      <a class="btn" href="/" target="_blank" style="text-decoration:none">View page</a>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn" type="submit">Log out</button>
      </form>
    </div>
  </header>

  <?php if ($flash): ?><div class="msg <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="mainForm">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
    <input type="hidden" name="action" value="save">

    <section class="card">
      <h2>Profile</h2>
      <div class="avatar-row">
        <img id="preview" alt="" src="<?= h($data['image']) ?>" <?= $data['image'] ? '' : 'hidden' ?>>
        <div>
          <label style="margin:0">Profile photo (max 2 MB)
            <input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp,image/gif" style="margin-top:6px">
          </label>
          <label class="check"><input type="checkbox" name="remove_image" value="1"> Remove current photo</label>
        </div>
      </div>
      <label>Name <input name="name" value="<?= h($data['name']) ?>"></label>
      <label>Bio <textarea name="description"><?= h($data['description']) ?></textarea></label>
      <label>Browser tab title <input name="title" value="<?= h($data['title']) ?>"></label>
    </section>

    <section class="card">
      <h2>Links</h2>
      <ul class="rows" id="rows">
        <?php foreach ($data['links'] as $l) echo link_row((array)$l); ?>
      </ul>
      <button type="button" class="btn" id="add">+ Add link</button>
      <p class="hint" style="margin-bottom:0">Use <code>https://…</code> for sites, <code>mailto:you@mail.com</code> for email, <code>tel:+213…</code> for phone. Empty rows are ignored.</p>
    </section>

    <div class="savebar"><div class="in">
      <span class="hint">Changes go live as soon as you save.</span>
      <button class="btn primary" type="submit">Save changes</button>
    </div></div>
  </form>

  <datalist id="platforms">
    <?php foreach ($platforms as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?>
  </datalist>
  <template id="tpl"><?= link_row([]) ?></template>

  <script>
    var rows = document.getElementById('rows');
    document.getElementById('add').addEventListener('click', function () {
      rows.appendChild(document.getElementById('tpl').content.cloneNode(true));
      rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-act]');
      if (!b) return;
      var row = b.closest('.row'), act = b.dataset.act;
      if (act === 'del') { if (confirm('Delete this link?')) row.remove(); }
      else if (act === 'up' && row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling);
      else if (act === 'down' && row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row);
    });
    document.getElementById('imageInput').addEventListener('change', function () {
      var f = this.files[0], img = document.getElementById('preview');
      if (f) { img.src = URL.createObjectURL(f); img.hidden = false; }
    });
  </script>
<?php endif; ?>

</div>
</body>
</html>
