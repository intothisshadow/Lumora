<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Image View Counter (AJAX)
 *
 * Increments the hit counter for a single image.
 * Called as a fire-and-forget POST by the PhotoSwipe lightbox every time
 * a new image is displayed (lumora_render_lightbox_js in template.php).
 *
 * Throttling: the counter is incremented at most once per image per PHP
 * session. Rapid lightbox navigation or page refreshes will not repeatedly
 * inflate counts for the same image in the same browser session.
 *
 * No CSRF token is required: incrementing a public view counter is not a
 * sensitive or destructive action, and requiring a token would complicate
 * the fire-and-forget JS pattern unnecessarily.
 *
 * POST params:
 *   image_id  int   Database ID of the image being viewed.
 *
 * JSON response:
 *   { "ok": true }   — counter incremented (or already counted this session)
 *   { "ok": false }  — missing / invalid image_id
 */

define('LUMORA_ENTRY', true);
require_once __DIR__ . '/include/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Only POST is accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$image_id = lumora_int($_POST['image_id'] ?? 0, 0, 1);

if ($image_id === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing image_id']);
    exit;
}

// Session throttle: count at most once per image per session.
$session_key = 'img_hit_' . $image_id;

if (empty($_SESSION[$session_key])) {
    increment_image_hits($image_id);
    $_SESSION[$session_key] = true;
}

echo json_encode(['ok' => true]);
