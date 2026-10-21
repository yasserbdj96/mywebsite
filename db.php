<?php
declare(strict_types=1);

/**
 * Shared database helpers (used by admin.php, api.php, image.php).
 * Database credentials live in config.php, which the installer in admin.php creates.
 */

const CONFIG_FILE = __DIR__ . '/config.php';
const MAX_IMAGE   = 2 * 1024 * 1024; // 2 MB (images are stored inside the database)
const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

function config(): ?array {
    static $c = false;
    if ($c === false) {
        $c = is_file(CONFIG_FILE) ? require CONFIG_FILE : null;
        if (!is_array($c) || empty($c['name'])) $c = null;
    }
    return $c;
}

function db_connect(array $c): PDO {
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $c = config();
        if (!$c) throw new RuntimeException('Not installed');
        $pdo = db_connect($c);
    }
    return $pdo;
}

/** Creates the tables if they do not exist yet. Safe to run many times. */
function install_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile (
        id            TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        title         VARCHAR(120) NOT NULL DEFAULT '',
        name          VARCHAR(120) NOT NULL DEFAULT '',
        description   VARCHAR(300) NOT NULL DEFAULT '',
        image_mime    VARCHAR(50)  NULL,
        image_data    MEDIUMBLOB   NULL,
        image_version INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS links (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
        platform    VARCHAR(50)  NOT NULL DEFAULT 'link',
        label       VARCHAR(120) NOT NULL DEFAULT '',
        url         VARCHAR(500) NOT NULL,
        description VARCHAR(200) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT IGNORE INTO profile (id) VALUES (1)");
}

/** First install only: copies your existing data.json (and its photo) into the empty tables. */
function seed_from_json(PDO $pdo): void {
    $file = __DIR__ . '/data.json';
    if (!is_file($file)) return;
    $d = json_decode((string)file_get_contents($file), true);
    if (!is_array($d)) return;

    $empty = !$pdo->query("SELECT COUNT(*) FROM links")->fetchColumn()
          && $pdo->query("SELECT name FROM profile WHERE id=1")->fetchColumn() === '';
    if (!$empty) return;

    $image = null;
    $img = (string)($d['image'] ?? '');
    $full = $img !== '' ? realpath(__DIR__ . '/' . $img) : false;
    if ($full && strpos($full, realpath(__DIR__) . DIRECTORY_SEPARATOR) === 0 && is_file($full) && filesize($full) <= MAX_IMAGE) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
        if (isset(IMAGE_TYPES[$mime])) $image = ['mime' => $mime, 'bytes' => (string)file_get_contents($full)];
    }

    $links = [];
    foreach ((array)($d['links'] ?? []) as $l) {
        if (!is_array($l) || empty($l['url']) || !is_safe_url((string)$l['url'])) continue;
        $links[] = [
            'platform'    => (string)($l['platform'] ?? 'link'),
            'label'       => (string)($l['label'] ?? ''),
            'url'         => (string)$l['url'],
            'description' => (string)($l['description'] ?? ''),
        ];
    }
    save_all($pdo, [
        'title'       => mb_substr((string)($d['title'] ?? ''), 0, 120),
        'name'        => mb_substr((string)($d['name'] ?? ''), 0, 120),
        'description' => mb_substr((string)($d['description'] ?? ''), 0, 300),
    ], $links, $image, false);
}

function is_safe_url(string $u): bool { return (bool)preg_match('~^(https?:|mailto:|tel:)~i', $u); }

/** Returns the same structure the old data.json had. */
function get_data(PDO $pdo): array {
    $p = $pdo->query("SELECT title, name, description, image_version, (image_data IS NOT NULL) AS has_image FROM profile WHERE id=1")->fetch()
        ?: ['title' => '', 'name' => '', 'description' => '', 'image_version' => 0, 'has_image' => 0];

    $links = [];
    foreach ($pdo->query("SELECT platform, label, url, description FROM links ORDER BY sort_order, id") as $r) {
        $row = ['platform' => $r['platform'], 'label' => $r['label'], 'url' => $r['url']];
        if ($r['description'] !== '') $row['description'] = $r['description'];
        $links[] = $row;
    }
    return [
        'title'       => $p['title'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'image'       => $p['has_image'] ? 'image.php?v=' . $p['image_version'] : '',
        'links'       => $links,
    ];
}

/** Saves profile text, links and (optionally) the photo in one transaction. */
function save_all(PDO $pdo, array $p, array $links, ?array $image, bool $removeImage): void {
    $pdo->beginTransaction();
    try {
        if ($image) {
            $st = $pdo->prepare("UPDATE profile SET title=?, name=?, description=?, image_mime=?, image_data=?, image_version=image_version+1 WHERE id=1");
            $st->bindValue(1, $p['title']);
            $st->bindValue(2, $p['name']);
            $st->bindValue(3, $p['description']);
            $st->bindValue(4, $image['mime']);
            $st->bindValue(5, $image['bytes'], PDO::PARAM_LOB);
            $st->execute();
        } elseif ($removeImage) {
            $pdo->prepare("UPDATE profile SET title=?, name=?, description=?, image_mime=NULL, image_data=NULL, image_version=image_version+1 WHERE id=1")
                ->execute([$p['title'], $p['name'], $p['description']]);
        } else {
            $pdo->prepare("UPDATE profile SET title=?, name=?, description=? WHERE id=1")
                ->execute([$p['title'], $p['name'], $p['description']]);
        }

        $pdo->exec("DELETE FROM links");
        $ins = $pdo->prepare("INSERT INTO links (sort_order, platform, label, url, description) VALUES (?,?,?,?,?)");
        foreach (array_values($links) as $i => $l) {
            $ins->execute([$i, $l['platform'], $l['label'], $l['url'], $l['description'] ?? '']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
