<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Configuration
 *
 * Handles all gallery settings stored in lum_config.
 * Also provides config export (JSON download) and import.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$base  = lumora_base_url() . 'admin/config.php';
$csrf  = h(lumora_csrf_token());
$base_h = h($base);

// ── Config export ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    lumora_csrf_validate();  // CSRF via GET param handled below
    // Validate via header since it's a GET request; accept only when admin.
    // (lumora_require_admin() already ran above)
    $rows = LumoraDB::fetchAll('SELECT name, value FROM `{PREFIX}config` ORDER BY name ASC');
    $data = [];
    foreach ($rows as $r) { $data[$r['name']] = $r['value']; }
    $json = json_encode(['lumora_config' => $data, 'exported_at' => date('c'), 'version' => LUMORA_VERSION], JSON_PRETTY_PRINT);
    $filename = 'lumora-config-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// ── POST: save settings ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? 'save';

    if ($act === 'save') {
        // White-list the settings we accept.
        $allowed = [
            'gallery_name', 'gallery_description', 'base_url',
            'theme', 'thumb_width', 'thumb_height', 'per_page',
            'allowed_extensions',
            'custom_header_path', 'custom_footer_path',
        ];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $val = match($key) {
                    'thumb_width', 'thumb_height' => (string) max(1, (int) $_POST[$key]),
                    'per_page'                    => (string) max(1, (int) $_POST[$key]),
                    'base_url'                    => rtrim(trim($_POST[$key]), '/') . '/',
                    default                       => trim($_POST[$key]),
                };
                lumora_set_config($key, $val);
            }
        }
        lum_flash('Settings saved.');
        lumora_redirect($base);
    }

    if ($act === 'import') {
        $file = $_FILES['config_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            lum_flash('No file uploaded or upload error.', 'danger');
            lumora_redirect($base);
        }

        $json = @file_get_contents($file['tmp_name']);
        $data = $json ? @json_decode($json, true) : null;

        if (!$data || !isset($data['lumora_config']) || !is_array($data['lumora_config'])) {
            lum_flash('Invalid config file format.', 'danger');
            lumora_redirect($base);
        }

        $safe_keys = ['gallery_name', 'gallery_description', 'theme', 'thumb_width', 'thumb_height',
                      'per_page', 'allowed_extensions', 'custom_header_path', 'custom_footer_path'];
        $imported = 0;
        foreach ($data['lumora_config'] as $k => $v) {
            if (in_array($k, $safe_keys, true)) {
                lumora_set_config($k, (string)$v);
                $imported++;
            }
        }
        // base_url is intentionally excluded from import to avoid breaking the current install.
        lum_flash('Imported ' . $imported . ' settings. Note: base_url was not changed.');
        lumora_redirect($base);
    }
}

// ── Current values ────────────────────────────────────────────────────────────
$cfg = [
    'gallery_name'        => lumora_config('gallery_name',        'Lumora Gallery'),
    'gallery_description' => lumora_config('gallery_description', ''),
    'base_url'            => lumora_config('base_url',            ''),
    'theme'               => lumora_config('theme',               'default'),
    'thumb_width'         => lumora_config('thumb_width',         '250'),
    'thumb_height'        => lumora_config('thumb_height',        '250'),
    'per_page'            => lumora_config('per_page',            '48'),
    'allowed_extensions'  => lumora_config('allowed_extensions',  'jpg,jpeg,png,gif,webp'),
    'custom_header_path'  => lumora_config('custom_header_path',  ''),
    'custom_footer_path'  => lumora_config('custom_footer_path',  ''),
];

// Detect active image processor (no config needed — auto-detected at runtime).
$processor_status = extension_loaded('imagick')
    ? '✓ Imagick PHP extension (active, preferred)'
    : (extension_loaded('gd')
        ? '⚠ GD library (fallback — install the PHP imagick extension for better quality)'
        : '✗ None found — thumbnail generation disabled');

$themes = lumora_list_themes();
$theme_opts = '';
foreach ($themes as $t) {
    $sel = $t === $cfg['theme'] ? ' selected' : '';
    $theme_opts .= '<option value="' . h($t) . '"' . $sel . '>' . h(ucfirst($t)) . '</option>';
}
if (empty($themes)) {
    $theme_opts = '<option value="default" selected>default (no themes found)</option>';
}

$export_url = h($base . '?export=1&csrf_token=' . urlencode(lumora_csrf_token()));

$content = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">Gallery Settings</h5>
  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="csrf_token" value="{$csrf}">

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Gallery Name</label>
        <input type="text" name="gallery_name" value="{$cfg['gallery_name']}" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Base URL</label>
        <input type="url" name="base_url" value="{$cfg['base_url']}" class="form-control" required>
        <div class="form-text">Public URL with trailing slash, e.g. https://example.com/gallery/</div>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Gallery Description</label>
      <textarea name="gallery_description" rows="2" class="form-control">{$cfg['gallery_description']}</textarea>
    </div>

    <hr class="my-4">
    <h6 class="mb-3 text-muted">Appearance</h6>

    <div class="mb-3">
      <label class="form-label fw-semibold">Active Theme</label>
      <select name="theme" class="form-select" style="max-width:220px">{$theme_opts}</select>
      <div class="form-text">Themes are folders inside <code>themes/</code> that contain a <code>template.html</code>.</div>
    </div>

    <hr class="my-4">
    <h6 class="mb-3 text-muted">Images &amp; Thumbnails</h6>

    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Thumbnail Max Width (px)</label>
        <input type="number" name="thumb_width" value="{$cfg['thumb_width']}" class="form-control" min="32" max="2000">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Thumbnail Max Height (px)</label>
        <input type="number" name="thumb_height" value="{$cfg['thumb_height']}" class="form-control" min="32" max="2000">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Images per Page</label>
        <input type="number" name="per_page" value="{$cfg['per_page']}" class="form-control" min="1" max="500">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Allowed Extensions</label>
      <input type="text" name="allowed_extensions" value="{$cfg['allowed_extensions']}" class="form-control font-monospace" style="max-width:320px">
      <div class="form-text">Comma-separated list, e.g. <code>jpg,jpeg,png,gif,webp</code></div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Image Processor</label>
      <p class="mb-0"><strong>{$processor_status}</strong></p>
      <div class="form-text">Detected automatically — no configuration needed. Imagick PHP extension is preferred; GD is used as fallback.</div>
    </div>

    <hr class="my-4">
    <h6 class="mb-3 text-muted">Custom HTML (optional)</h6>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Custom Header File Path</label>
        <input type="text" name="custom_header_path" value="{$cfg['custom_header_path']}" class="form-control font-monospace">
        <div class="form-text">Path relative to Lumora root, e.g. <code>custom/header.html</code></div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Custom Footer File Path</label>
        <input type="text" name="custom_footer_path" value="{$cfg['custom_footer_path']}" class="form-control font-monospace">
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
  </form>
</div>

<div class="lum-adm-card">
  <h5 class="mb-3">Export / Import Configuration</h5>
  <p class="text-muted small">Export your settings to a JSON file for backup, or to quickly configure another Lumora installation.
     <strong>Note:</strong> base_url is never imported to prevent accidentally breaking an install.</p>
  <div class="d-flex gap-3 flex-wrap">
    <a href="{$export_url}" class="btn btn-outline-secondary">⬇ Export Config (JSON)</a>
    <form method="post" action="{$base_h}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="action"     value="import">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <input type="file" name="config_file" accept=".json,application/json" class="form-control form-control-sm" style="max-width:240px">
      <button type="submit" class="btn btn-outline-secondary btn-sm"
              onclick="return confirm('Import will overwrite current settings (except base_url). Continue?')">⬆ Import</button>
    </form>
  </div>
</div>
HTML;

// Escape values for use in HTML attributes
foreach ($cfg as $k => $v) {
    $content = str_replace('{$cfg[\'' . $k . '\']}', h($v), $content);
}

lum_admin_page('Configuration', $content, 'config');
