<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Configuration
 *
 * Handles all gallery settings stored in lum_config.
 * Also provides config export (JSON download) and import.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$base   = lumora_base_url() . 'admin/config.php';
$csrf   = h(lumora_csrf_token());
$base_h = h($base);

// ── Config export ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    // CSRF: the export link embeds the token in the query string; validate it here.
    // lumora_csrf_validate() checks $_POST only, so we validate $_GET directly.
    // The admin session check (lumora_require_admin() above) is the primary guard.
    if (
        !isset($_GET['csrf_token']) ||
        !hash_equals(lumora_csrf_token(), $_GET['csrf_token'])
    ) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
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
            'timezone',
            'thumb_quality',
            'max_upload_size_mb', 'max_image_width', 'max_image_height',
            'count_album_views', 'log_mode', 'gallery_offline',
            'latest_albums_count',
            'who_is_online_duration',
            'show_powered_by',
        ];

        // Boolean checkbox keys: always save even when not present in POST
        // (unchecked checkbox sends nothing; hidden input handles the default).
        $bool_keys = ['count_album_views', 'gallery_offline', 'show_powered_by'];

        foreach ($allowed as $key) {
            if (in_array($key, $bool_keys, true)) {
                // Hidden input guarantees the key is always present ('0' or '1').
                $val = (isset($_POST[$key]) && $_POST[$key] === '1') ? '1' : '0';
                lumora_set_config($key, $val);
            } elseif (isset($_POST[$key])) {
                $val = match($key) {
                    'thumb_width', 'thumb_height'           => (string) max(1, (int) $_POST[$key]),
                    'per_page'                              => (string) max(1, (int) $_POST[$key]),
                    'base_url'                              => rtrim(trim($_POST[$key]), '/') . '/',
                    'thumb_quality'                         => (string) max(1, min(100, (int) $_POST[$key])),
                    'max_upload_size_mb',
                    'max_image_width',
                    'max_image_height'                      => (string) max(0, (int) $_POST[$key]),
                    'latest_albums_count'                   => (string) max(0, min(50, (int) $_POST[$key])),
                    'who_is_online_duration'                 => (string) max(1, min(60, (int) $_POST[$key])),
                    'log_mode'                              => in_array($_POST[$key], ['off', 'errors', 'all'], true)
                                                                ? $_POST[$key] : 'off',
                    'timezone'                              => in_array(trim($_POST[$key]), \DateTimeZone::listIdentifiers(), true)
                                                                ? trim($_POST[$key]) : 'UTC',
                    default                                 => trim($_POST[$key]),
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

        $safe_keys = [
            'gallery_name', 'gallery_description', 'theme',
            'thumb_width', 'thumb_height', 'per_page',
            'allowed_extensions', 'custom_header_path', 'custom_footer_path',
            'timezone', 'thumb_quality',
            'max_upload_size_mb', 'max_image_width', 'max_image_height',
            'count_album_views', 'log_mode', 'gallery_offline',
            'latest_albums_count', 'who_is_online_duration',
            'show_powered_by',
        ];
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
    'timezone'            => lumora_config('timezone',            'UTC'),
    'thumb_quality'       => lumora_config('thumb_quality',       '85'),
    'max_upload_size_mb'  => lumora_config('max_upload_size_mb',  '0'),
    'max_image_width'     => lumora_config('max_image_width',     '0'),
    'max_image_height'    => lumora_config('max_image_height',    '0'),
    'count_album_views'   => lumora_config('count_album_views',   '1'),
    'log_mode'            => lumora_config('log_mode',            'off'),
    'gallery_offline'     => lumora_config('gallery_offline',     '0'),
    'latest_albums_count' => lumora_config('latest_albums_count', '5'),
    'who_is_online_duration' => lumora_config('who_is_online_duration', '5'),
    'show_powered_by'        => lumora_config('show_powered_by',        '1'),
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

// Pre-compute values safe for use in HTML attributes.
$v_gallery_name    = h($cfg['gallery_name']);
$v_base_url        = h($cfg['base_url']);
$v_gallery_desc    = h($cfg['gallery_description']);
$v_per_page        = h($cfg['per_page']);
$v_allowed_ext     = h($cfg['allowed_extensions']);
$v_custom_header   = h($cfg['custom_header_path']);
$v_custom_footer   = h($cfg['custom_footer_path']);
$v_timezone        = h($cfg['timezone']);
$v_thumb_quality   = h($cfg['thumb_quality']);
$v_max_upload      = h($cfg['max_upload_size_mb']);
$v_max_img_w       = h($cfg['max_image_width']);
$v_max_img_h       = h($cfg['max_image_height']);
$v_thumb_w         = h($cfg['thumb_width']);
$v_thumb_h         = h($cfg['thumb_height']);

// Select / checkbox states.
$sel_log_off    = $cfg['log_mode'] === 'off'    ? ' selected' : '';
$sel_log_errors = $cfg['log_mode'] === 'errors' ? ' selected' : '';
$sel_log_all    = $cfg['log_mode'] === 'all'    ? ' selected' : '';
$chk_album_views = $cfg['count_album_views'] === '1' ? ' checked' : '';
$chk_offline      = $cfg['gallery_offline']   === '1' ? ' checked' : '';
$chk_powered_by   = $cfg['show_powered_by']   === '1' ? ' checked' : '';
$v_latest_albums  = h($cfg['latest_albums_count']);
$v_who_online_dur = h($cfg['who_is_online_duration']);

$export_url = h($base . '?export=1&csrf_token=' . urlencode(lumora_csrf_token()));
$processor_h = h($processor_status);

$content = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">Gallery Settings</h5>
  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="csrf_token" value="{$csrf}">

    <!-- ── Basic ──────────────────────────────────────────────────── -->
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Gallery Name</label>
        <input type="text" name="gallery_name" value="{$v_gallery_name}" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Base URL</label>
        <input type="url" name="base_url" value="{$v_base_url}" class="form-control" required>
        <div class="form-text">Public URL with trailing slash, e.g. https://example.com/gallery/</div>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Gallery Description</label>
      <textarea name="gallery_description" rows="2" class="form-control">{$v_gallery_desc}</textarea>
    </div>

    <!-- ── Appearance ─────────────────────────────────────────────── -->
    <hr class="my-4">
    <h6 class="mb-3 text-muted">Appearance</h6>

    <div class="mb-3">
      <label class="form-label fw-semibold">Active Theme</label>
      <select name="theme" class="form-select" style="max-width:220px">{$theme_opts}</select>
      <div class="form-text">Themes are folders inside <code>themes/</code> that contain a <code>template.html</code>.</div>
    </div>

    <div class="mb-3">
      <div class="form-check form-switch">
        <input type="hidden" name="show_powered_by" value="0">
        <input class="form-check-input" type="checkbox" id="show_powered_by"
               name="show_powered_by" value="1"{$chk_powered_by}>
        <label class="form-check-label fw-semibold" for="show_powered_by">Show Powered By Credit</label>
      </div>
      <div class="form-text">Display a &ldquo;Powered by Lumora Gallery&rdquo; credit in the site footer. Themes use the <code>{POWERED_BY}</code> template token to control placement.</div>
    </div>

    <!-- ── Images & Thumbnails ────────────────────────────────────── -->
    <hr class="my-4">
    <h6 class="mb-3 text-muted">Images &amp; Thumbnails</h6>

    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Thumbnail Max Width (px)</label>
        <input type="number" name="thumb_width" value="{$v_thumb_w}" class="form-control" min="32" max="2000">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Thumbnail Max Height (px)</label>
        <input type="number" name="thumb_height" value="{$v_thumb_h}" class="form-control" min="32" max="2000">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Images per Page</label>
        <input type="number" name="per_page" value="{$v_per_page}" class="form-control" min="1" max="500">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Allowed Extensions</label>
      <input type="text" name="allowed_extensions" value="{$v_allowed_ext}" class="form-control font-monospace" style="max-width:320px">
      <div class="form-text">Comma-separated list, e.g. <code>jpg,jpeg,png,gif,webp</code></div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Image Processor</label>
      <p class="mb-0"><strong>{$processor_h}</strong></p>
      <div class="form-text">Detected automatically — no configuration needed. Imagick PHP extension is preferred; GD is used as fallback.</div>
    </div>

    <!-- ── Custom HTML ────────────────────────────────────────────── -->
    <hr class="my-4">
    <h6 class="mb-3 text-muted">Custom HTML (optional)</h6>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Custom Header File Path</label>
        <input type="text" name="custom_header_path" value="{$v_custom_header}" class="form-control font-monospace">
        <div class="form-text">Path relative to Lumora root, e.g. <code>custom/header.html</code></div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Custom Footer File Path</label>
        <input type="text" name="custom_footer_path" value="{$v_custom_footer}" class="form-control font-monospace">
      </div>
    </div>

    <!-- ── Gallery Behavior ───────────────────────────────────────── -->
    <hr class="my-4">
    <h6 class="mb-3 text-muted">Gallery Behavior</h6>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Timezone</label>
        <input type="text" name="timezone" value="{$v_timezone}"
               list="lum-tz-list" class="form-control font-monospace" style="max-width:280px">
        <datalist id="lum-tz-list">
          <option value="UTC">
          <option value="Europe/Helsinki">
          <option value="Europe/Tallinn">
          <option value="Europe/Riga">
          <option value="Europe/Vilnius">
          <option value="Europe/Stockholm">
          <option value="Europe/Oslo">
          <option value="Europe/Copenhagen">
          <option value="Europe/London">
          <option value="Europe/Dublin">
          <option value="Europe/Lisbon">
          <option value="Europe/Paris">
          <option value="Europe/Berlin">
          <option value="Europe/Amsterdam">
          <option value="Europe/Brussels">
          <option value="Europe/Madrid">
          <option value="Europe/Rome">
          <option value="Europe/Vienna">
          <option value="Europe/Zurich">
          <option value="Europe/Warsaw">
          <option value="Europe/Prague">
          <option value="Europe/Budapest">
          <option value="Europe/Bucharest">
          <option value="Europe/Athens">
          <option value="Europe/Sofia">
          <option value="Europe/Moscow">
          <option value="America/New_York">
          <option value="America/Chicago">
          <option value="America/Denver">
          <option value="America/Los_Angeles">
          <option value="America/Anchorage">
          <option value="America/Sao_Paulo">
          <option value="America/Argentina/Buenos_Aires">
          <option value="America/Toronto">
          <option value="America/Vancouver">
          <option value="America/Mexico_City">
          <option value="Asia/Tokyo">
          <option value="Asia/Seoul">
          <option value="Asia/Shanghai">
          <option value="Asia/Hong_Kong">
          <option value="Asia/Singapore">
          <option value="Asia/Bangkok">
          <option value="Asia/Kolkata">
          <option value="Asia/Dubai">
          <option value="Asia/Riyadh">
          <option value="Asia/Istanbul">
          <option value="Australia/Sydney">
          <option value="Australia/Melbourne">
          <option value="Australia/Perth">
          <option value="Pacific/Auckland">
          <option value="Pacific/Honolulu">
          <option value="Africa/Johannesburg">
          <option value="Africa/Cairo">
          <option value="Africa/Lagos">
        </datalist>
        <div class="form-text">Any valid PHP timezone identifier (e.g. <code>Europe/Helsinki</code>). Affects all displayed dates and timestamps.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Logging Mode</label>
        <select name="log_mode" class="form-select" style="max-width:280px">
          <option value="off"{$sel_log_off}>Off — no logging</option>
          <option value="errors"{$sel_log_errors}>Errors only (PHP error_log)</option>
          <option value="all"{$sel_log_all}>All visits + errors (DB + error_log)</option>
        </select>
        <div class="form-text"><em>All visits</em> mode requires the <code>{PREFIX}log</code> table
          (added in DB version 2 — run the <code>CREATE TABLE</code> from
          <code>install/schema.sql</code> on existing installations).</div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input type="hidden" name="count_album_views" value="0">
          <input class="form-check-input" type="checkbox" id="count_album_views"
                 name="count_album_views" value="1"{$chk_album_views}>
          <label class="form-check-label fw-semibold" for="count_album_views">Count Album Views</label>
        </div>
        <div class="form-text">Track how many times each album page is visited. Session-throttled to one count per visitor per session. Disable to reduce write load on very high-traffic galleries.</div>
      </div>
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input type="hidden" name="gallery_offline" value="0">
          <input class="form-check-input" type="checkbox" id="gallery_offline"
                 name="gallery_offline" value="1"{$chk_offline}>
          <label class="form-check-label fw-semibold text-danger" for="gallery_offline">Gallery Offline Mode</label>
        </div>
        <div class="form-text">Show a "Gallery Offline" maintenance page to all visitors. Admins always see the real gallery. Enable before running maintenance that modifies the <code>albums/</code> directory.</div>
      </div>
    </div>

    <!-- ── Upload & Image Limits ──────────────────────────────────── -->
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Latest Updated Albums (home page)</label>
        <input type="number" name="latest_albums_count" value="{$v_latest_albums}"
               class="form-control" min="0" max="50" style="max-width:120px">
        <div class="form-text">How many recently updated albums to display on the home page. Set to <code>0</code> to hide the section.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Who Is Online — Window (minutes)</label>
        <input type="number" name="who_is_online_duration" value="{$v_who_online_dur}"
               class="form-control" min="1" max="60" style="max-width:120px">
        <div class="form-text">Visitors active within this many minutes are counted as online. Default 5. Range 1–60.</div>
      </div>
    </div>

    <hr class="my-4">
    <h6 class="mb-3 text-muted">Upload &amp; Image Limits</h6>
    <p class="text-muted small mb-3">These limits are applied per image during <strong>Batch Add</strong>. Originals that exceed the dimension limits are downscaled in-place (overwriting the source file); originals that exceed the file-size limit are skipped entirely.</p>

    <div class="row g-3 mb-3">
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Thumbnail JPEG Quality</label>
        <input type="number" name="thumb_quality" value="{$v_thumb_quality}"
               class="form-control" min="1" max="100">
        <div class="form-text">1–100, default 85. Applies to JPEG and WebP thumbnails on Batch Add.</div>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Max File Size (MB)</label>
        <input type="number" name="max_upload_size_mb" value="{$v_max_upload}"
               class="form-control" min="0">
        <div class="form-text">0 = no limit. Files exceeding this size are skipped on Batch Add.</div>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Max Original Width (px)</label>
        <input type="number" name="max_image_width" value="{$v_max_img_w}"
               class="form-control" min="0">
        <div class="form-text">0 = no limit. Oversized originals are downscaled in-place on Batch Add.</div>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Max Original Height (px)</label>
        <input type="number" name="max_image_height" value="{$v_max_img_h}"
               class="form-control" min="0">
        <div class="form-text">0 = no limit. Aspect ratio is preserved; images are never upscaled.</div>
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

lum_admin_page('Configuration', $content, 'config');
