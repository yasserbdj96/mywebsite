<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

try {
    $st = db()->query("SELECT image_mime, image_data, image_version FROM profile WHERE id=1");
    $r = $st->fetch();
} catch (Throwable $e) {
    $r = null;
}

if (!$r || $r['image_data'] === null || !isset(IMAGE_TYPES[$r['image_mime']])) {
    http_response_code(404);
    exit;
}

$etag = '"img-' . (int)$r['image_version'] . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable'); // URL changes (?v=) whenever the photo changes
header('X-Content-Type-Options: nosniff');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $r['image_mime']);
header('Content-Length: ' . strlen($r['image_data']));
echo $r['image_data'];
