<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Image Management
 *
 * Provides a per-album image grid and cross-album search with full management
 * capabilities:
 *   - Search images by filename or title, within one album or across all albums.
 *   - Edit image details (title, sort position, visibility).
 *   - Replace the image file while preserving the DB record and filename.
 *   - Regenerate the thumbnail for a single image (AJAX).
 *   - Delete a single image (original + thumbnail files + DB record).
 *   - Bulk-delete selected images (AJAX → ajax_image_delete.php).
 *   - Bulk-move selected images to another album (AJAX → ajax_image_move.php).
 *
 * GET actions:  list (default), edit
 * POST actions: save, delete
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$action   = $_GET['action'] ?? 'list';
$album_id = lumora_int($_GET['album'] ?? 0, 0, 0);
$img_id   = lumora_int($_GET['id']    ?? 0, 0, 1);
$page     = max(1, lumora_int($_GET['page'] ?? 1, 1, 1));
$search   = trim($_GET['search'] ?? '');
$per_page = 24;
$base     = lumora_base_url() . 'admin/images.php';
$base_h   = h($base);
$csrf     = h(lumora_csrf_token());
$csrf_js  = json_encode(lumora_csrf_token());
$search_h = h($search);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act        = $_POST['action']   ?? '';
    $post_id    = lumora_int($_POST['id']       ?? 0, 0, 1);
    $ret_alb    = lumora_int($_POST['album_id'] ?? 0, 0, 0);
    $ret_pg     = lumora_int($_POST['page']     ?? 1, 1, 1);
    $ret_search = trim($_POST['search'] ?? '');

    // Build return URL preserving album, page, and search context.
    $ret_parts = [];
    if ($ret_alb    > 0) $ret_parts[] = 'album='  . $ret_alb;
    if ($ret_pg     > 1) $ret_parts[] = 'page='   . $ret_pg;
    if ($ret_search !== '') $ret_parts[] = 'search=' . rawurlencode($ret_search);
    $ret_url = $base . ($ret_parts ? '?' . implode('&', $ret_parts) : '');

    // ── save (edit details + optional file replacement) ──────────────────────
    if ($act === 'save' && $post_id > 0) {
        $image = LumoraDB::fetchOne(
            'SELECT i.*, a.folder FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.id = ?',
            [$post_id]
        );

        if (!$image) {
            lum_flash('Image not found.', 'danger');
            lumora_redirect($ret_url);
        }

        $title    = trim($_POST['title'] ?? '');
        $pos      = lumora_int($_POST['pos']      ?? (int) $image['pos'], (int) $image['pos'], 0);
        $approved = lumora_int($_POST['approved'] ?? 0, 0, 0, 1);
        $updates  = ['title' => $title, 'pos' => $pos, 'approved' => $approved];

        // ── optional file replacement ────────────────────────────────────────
        $upload_error = $_FILES['new_image']['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($upload_error === UPLOAD_ERR_OK) {
            $tmp     = (string) ($_FILES['new_image']['tmp_name'] ?? '');
            $up_name = (string) ($_FILES['new_image']['name']     ?? '');
            $up_size = (int)   ($_FILES['new_image']['size']      ?? 0);
            $max_mb  = (int)   lumora_config('max_upload_size_mb', 0);
            $file_ok = true;

            if ($max_mb > 0 && $up_size > $max_mb * 1024 * 1024) {
                lum_flash('Replacement file exceeds the configured maximum size (' . $max_mb . ' MB).', 'danger');
                $file_ok = false;
            }

            if ($file_ok && !lumora_is_allowed_image($up_name)) {
                lum_flash(
                    'File type not allowed. Permitted extensions: '
                    . implode(', ', lumora_allowed_extensions()) . '.',
                    'danger'
                );
                $file_ok = false;
            }

            if ($file_ok && getimagesize($tmp) === false) {
                lum_flash('Uploaded file is not a valid image.', 'danger');
                $file_ok = false;
            }

            if ($file_ok) {
                $dest_path = lumora_album_path($image['folder']) . $image['filename'];
                if (!move_uploaded_file($tmp, $dest_path)) {
                    lum_flash('Could not save the uploaded file. Check folder permissions.', 'danger');
                    $file_ok = false;
                }
            }

            if ($file_ok) {
                $thumb_w = max(1, (int) lumora_config('thumb_width',  250));
                $thumb_h = max(1, (int) lumora_config('thumb_height', 250));
                $thumb_p = lumora_album_path($image['folder']) . LUMORA_THUMB_PREFIX . $image['filename'];
                $dest_p  = lumora_album_path($image['folder']) . $image['filename'];

                lumora_generate_thumb($dest_p, $thumb_p, $thumb_w, $thumb_h);

                [$w, $h]             = lumora_get_image_dimensions($dest_p);
                $updates['width']    = $w;
                $updates['height']   = $h;
                $updates['filesize'] = lumora_get_filesize($dest_p);

                lum_flash('Image file replaced and thumbnail regenerated.');
            }
        } elseif ($upload_error !== UPLOAD_ERR_NO_FILE) {
            lum_flash('File upload error (PHP error code ' . $upload_error . '). Please try again.', 'warning');
        }

        LumoraDB::update('images', $updates, 'id = ?', [$post_id]);
        lum_flash('Image details saved.');
        lumora_redirect($ret_url);
    }

    // ── delete (single image) ────────────────────────────────────────────────
    if ($act === 'delete' && $post_id > 0) {
        $image = LumoraDB::fetchOne(
            'SELECT i.id, i.filename, i.album_id, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.id = ?',
            [$post_id]
        );

        if ($image) {
            $orig_path  = lumora_album_path($image['folder']) . $image['filename'];
            $thumb_path = lumora_album_path($image['folder']) . LUMORA_THUMB_PREFIX . $image['filename'];

            if (is_file($orig_path))  unlink($orig_path);
            if (is_file($thumb_path)) unlink($thumb_path);

            LumoraDB::delete('images', 'id = ?', [$post_id]);

            // Reset album / category cover references (0 = auto-pick fallback).
            LumoraDB::query(
                'UPDATE `{PREFIX}albums` SET thumb_image_id = 0 WHERE thumb_image_id = ?',
                [$post_id]
            );
            LumoraDB::query(
                'UPDATE `{PREFIX}categories` SET thumb_image_id = 0 WHERE thumb_image_id = ?',
                [$post_id]
            );

            lum_flash('Image "' . $image['filename'] . '" deleted.');
        } else {
            lum_flash('Image not found.', 'danger');
        }

        lumora_redirect($ret_url);
    }
}

// ── Load album list (for album selector + bulk-move dropdown) ──────────────────
$all_albums = LumoraDB::fetchAll(
    'SELECT a.id, a.title, a.folder, c.name AS cat_name
     FROM `{PREFIX}albums` a
     LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
     ORDER BY c.name ASC, a.title ASC'
);

// ── Build album selector options ──────────────────────────────────────────────
$sel_opts = '<option value="">— Select an album —</option>';
$_sc      = null;
foreach ($all_albums as $_sa) {
    $cat = $_sa['cat_name'] ?? 'Uncategorised';
    if ($cat !== $_sc) {
        if ($_sc !== null) $sel_opts .= '</optgroup>';
        $sel_opts .= '<optgroup label="' . h($cat) . '">';
        $_sc = $cat;
    }
    $sel      = ((int) $_sa['id'] === $album_id) ? ' selected' : '';
    $sel_opts .= '<option value="' . (int) $_sa['id'] . '"' . $sel . '>'
        . h($_sa['title']) . '</option>';
}
if ($_sc !== null) $sel_opts .= '</optgroup>';
unset($_sa, $_sc);

// ── Build move-target options (excludes current album) ────────────────────────
$move_opts = '<option value="">— Select target album —</option>';
$_mc       = null;
foreach ($all_albums as $_ma) {
    if ((int) $_ma['id'] === $album_id) continue;
    $cat = $_ma['cat_name'] ?? 'Uncategorised';
    if ($cat !== $_mc) {
        if ($_mc !== null) $move_opts .= '</optgroup>';
        $move_opts .= '<optgroup label="' . h($cat) . '">';
        $_mc = $cat;
    }
    $move_opts .= '<option value="' . (int) $_ma['id'] . '">'
        . h($_ma['title']) . '</option>';
}
if ($_mc !== null) $move_opts .= '</optgroup>';
unset($_ma, $_mc);

// ── Load selected album ───────────────────────────────────────────────────────
$album = null;
if ($album_id > 0) {
    $album = LumoraDB::fetchOne('SELECT * FROM `{PREFIX}albums` WHERE id = ?', [$album_id]);
    if (!$album) {
        lum_flash('Album not found.', 'danger');
        lumora_redirect($base);
    }
}

// ── Edit action: render standalone edit form ──────────────────────────────────
if ($action === 'edit') {
    if ($img_id === 0) {
        lumora_redirect($base . ($album_id > 0 ? '?album=' . $album_id : ''));
    }

    $edit_image = LumoraDB::fetchOne(
        'SELECT i.*, a.folder, a.title AS album_title, a.id AS album_id_val
         FROM `{PREFIX}images` i
         JOIN `{PREFIX}albums` a ON a.id = i.album_id
         WHERE i.id = ?',
        [$img_id]
    );

    if (!$edit_image) {
        lum_flash('Image not found.', 'danger');
        lumora_redirect($base . ($album_id > 0 ? '?album=' . $album_id : ''));
    }

    $edit_album_id = (int) $edit_image['album_id_val'];

    // Back URL preserves the context the user came from (album + page + search).
    $back_parts = [];
    if ($album_id > 0)  $back_parts[] = 'album='  . $album_id;
    if ($page     > 1)  $back_parts[] = 'page='   . $page;
    if ($search  !== '') $back_parts[] = 'search=' . rawurlencode($search);
    $back_url_h = h($base . ($back_parts ? '?' . implode('&', $back_parts) : ''));

    $thumb_url_h  = h(image_thumb_url($edit_image));
    $orig_url_h   = h(image_original_url($edit_image));
    $filename_h   = h($edit_image['filename']);
    $title_v      = h($edit_image['title']);
    $pos_v        = (int) $edit_image['pos'];
    $approved_chk = (int) $edit_image['approved'] ? ' checked' : '';
    $dims_h       = ($edit_image['width'] && $edit_image['height'])
        ? h($edit_image['width'] . ' × ' . $edit_image['height'] . ' px')
        : '—';
    $filesize_h   = h(lumora_format_bytes((int) $edit_image['filesize']));
    $album_title_h= h($edit_image['album_title']);
    $added_h      = h(substr((string) $edit_image['added_at'], 0, 10));
    $hits_h       = number_format((int) $edit_image['hits']);

    $allowed_exts_h = h(implode(', ', lumora_allowed_extensions()));
    $max_mb         = (int) lumora_config('max_upload_size_mb', 0);
    $size_hint      = $max_mb > 0 ? 'Max ' . $max_mb . ' MB. ' : '';

    $content = <<<HTML
<a href="{$back_url_h}" class="btn btn-sm btn-outline-secondary mb-3">← Back to Image List</a>

<div class="lum-adm-card">
  <div class="d-flex gap-3 align-items-start mb-4">
    <a href="{$orig_url_h}" target="_blank" rel="noopener">
      <img src="{$thumb_url_h}" alt="{$filename_h}" class="img-thumbnail"
           style="max-width:120px;max-height:120px;object-fit:contain">
    </a>
    <div>
      <div class="fw-semibold">{$filename_h}</div>
      <div class="text-muted small mt-1">{$dims_h} · {$filesize_h}</div>
      <div class="text-muted small">Album: {$album_title_h}</div>
      <div class="text-muted small">Added: {$added_h} · Views: {$hits_h}</div>
    </div>
  </div>

  <form method="post" action="{$base_h}" enctype="multipart/form-data">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="id"         value="{$img_id}">
    <input type="hidden" name="album_id"   value="{$edit_album_id}">
    <input type="hidden" name="page"       value="{$page}">
    <input type="hidden" name="search"     value="{$search_h}">
    <input type="hidden" name="csrf_token" value="{$csrf}">

    <div class="mb-3">
      <label class="form-label fw-semibold">Title</label>
      <input type="text" name="title" value="{$title_v}" class="form-control"
             placeholder="Leave blank to display the filename">
    </div>

    <div class="row g-3 mb-3">
      <div class="col-auto">
        <label class="form-label fw-semibold">Position (sort order)</label>
        <input type="number" name="pos" value="{$pos_v}" min="0" class="form-control"
               style="max-width:110px">
      </div>
      <div class="col-auto d-flex align-items-end pb-2">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="approved" value="1"
                 id="lum-img-approved"{$approved_chk}>
          <label class="form-check-label" for="lum-img-approved">Visible (approved)</label>
        </div>
      </div>
    </div>

    <hr class="my-4">

    <h6 class="fw-semibold mb-1">Replace Image File
      <span class="text-muted fw-normal">(optional)</span>
    </h6>
    <p class="text-muted small mb-2">
      Upload a new file to replace the existing image on disk.
      The original filename is preserved; dimensions and file size are updated automatically.
      {$size_hint}Allowed types: {$allowed_exts_h}.
    </p>
    <div class="mb-4">
      <input type="file" name="new_image" class="form-control" accept="image/*">
    </div>

    <button type="submit" class="btn btn-primary">Save Image</button>
    <a href="{$back_url_h}" class="btn btn-outline-secondary ms-2">Cancel</a>
  </form>
</div>
HTML;

    lum_admin_page('Edit Image', $content, 'images');
}

// ── List view ─────────────────────────────────────────────────────────────────
$is_search  = $search !== '';
$album_id_h = (string) $album_id; // safe: integer cast

// ── Combined album selector + search bar card ─────────────────────────────────
$content = '<div class="lum-adm-card mb-3">'
    . '<div class="d-flex flex-wrap gap-3 align-items-end">';

// Album selector form: preserves the active search term when switching albums.
$content .= '<div>'
    . '<label class="form-label mb-1 fw-semibold small text-muted">Album</label>'
    . '<form method="get" action="' . $base_h . '" class="d-flex gap-2 align-items-center">'
    . '<select name="album" class="form-select" style="max-width:380px">' . $sel_opts . '</select>'
    . '<input type="hidden" name="search" value="' . $search_h . '">'
    . '<button type="submit" class="btn btn-outline-secondary">Open →</button>'
    . '</form>'
    . '</div>';

// Search form: preserves the active album scope when submitting a search.
$content .= '<div class="flex-grow-1">'
    . '<label class="form-label mb-1 fw-semibold small text-muted">Search</label>'
    . '<form method="get" action="' . $base_h . '" class="d-flex gap-2 align-items-center flex-wrap">'
    . '<input type="hidden" name="album" value="' . $album_id_h . '">'
    . '<input type="text" name="search" value="' . $search_h . '" class="form-control"'
    . ' style="max-width:340px" placeholder="Search by filename or image name…"'
    . ' autocomplete="off" spellcheck="false">'
    . '<button type="submit" class="btn btn-primary">🔍 Search</button>';

if ($is_search) {
    // Clear search — return to album page (if one is selected) or bare list.
    $clear_parts = $album_id > 0 ? ['album=' . $album_id] : [];
    $clear_url_h = h($base . ($clear_parts ? '?' . implode('&', $clear_parts) : ''));
    $content    .= '<a href="' . $clear_url_h . '" class="btn btn-outline-secondary" title="Clear search">✕ Clear</a>';
}

$content .= '</form></div></div></div>'; // close search form div, album flex row, card

// ── Load and display images when album selected OR search is active ────────────
$show_content = ($album !== null) || $is_search;

if ($show_content) {

    // ── Count total matching rows first, then compute correct page ────────────
    if ($is_search) {
        $total = GalleryService::countSearchImages($search, $album_id);
    } else {
        $total = (int) LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM `{PREFIX}images` WHERE album_id = ?',
            [$album_id]
        );
    }

    $total_pages = max(1, (int) ceil($total / $per_page));
    $page        = max(1, min($page, $total_pages));
    $offset      = ($page - 1) * $per_page;

    // ── Fetch the current page of images ─────────────────────────────────────
    if ($is_search) {
        $images = GalleryService::searchImages($search, $album_id, $page, $per_page);
    } else {
        $images = LumoraDB::fetchAll(
            'SELECT i.*, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.album_id = ?
             ORDER BY i.pos ASC, i.id ASC
             LIMIT ? OFFSET ?',
            [$album_id, $per_page, $offset]
        );
    }

    // ── Closure: build a URL for a given page in the current context ──────────
    $make_page_url = static function (int $p) use ($base, $album_id, $is_search, $search): string {
        $parts = [];
        if ($album_id  > 0) $parts[] = 'album='  . $album_id;
        if ($is_search)     $parts[] = 'search=' . rawurlencode($search);
        if ($p         > 1) $parts[] = 'page='   . $p;
        return $base . ($parts ? '?' . implode('&', $parts) : '');
    };

    // ── Heading row ───────────────────────────────────────────────────────────
    if ($is_search) {
        $ctx_label = $album
            ? ' in <em>' . h($album['title']) . '</em>'
            : ' across all albums';
        $count_str = number_format($total) . ' ' . ($total === 1 ? 'image' : 'images');
        $content  .= '<div class="d-flex justify-content-between align-items-center mb-3">'
            . '<h5 class="mb-0">Results for &ldquo;' . $search_h . '&rdquo;' . $ctx_label
            . ' <span class="text-muted fw-normal small">(' . $count_str . ')</span></h5>'
            . '</div>';
    } else {
        $album_title_h = h($album['title']);
        $batch_url_h   = h(lumora_base_url() . 'admin/batch.php?album=' . $album_id);
        $content      .= '<div class="d-flex justify-content-between align-items-center mb-3">'
            . '<h5 class="mb-0">' . $album_title_h
            . ' <span class="text-muted fw-normal small">('
            . number_format($total) . ' ' . ($total === 1 ? 'image' : 'images') . ')</span></h5>'
            . '<a href="' . $batch_url_h . '" class="btn btn-sm btn-outline-primary">⬆️ Batch Add</a>'
            . '</div>';
    }

    // ── Bulk actions bar ──────────────────────────────────────────────────────
    $content .= <<<HTML
<div class="lum-adm-card mb-3 py-2">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <button id="lum-sel-all"  class="btn btn-sm btn-outline-secondary">Select All</button>
    <button id="lum-sel-none" class="btn btn-sm btn-outline-secondary">None</button>
    <span id="lum-sel-count" class="text-muted small">0 selected</span>
    <div class="vr d-none d-sm-block"></div>
    <button id="lum-bulk-delete" class="btn btn-sm btn-outline-danger" disabled>🗑 Delete Selected</button>
    <div class="vr d-none d-sm-block"></div>
    <div class="d-flex gap-1 align-items-center flex-wrap">
      <select id="lum-move-target" class="form-select form-select-sm" style="max-width:240px">
        {$move_opts}
      </select>
      <button id="lum-bulk-move" class="btn btn-sm btn-outline-primary" disabled>📦 Move Selected</button>
    </div>
  </div>
  <div id="lum-bulk-status" class="mt-2 small d-none"></div>
</div>
HTML;

    // ── Image table ───────────────────────────────────────────────────────────
    if (empty($images)) {
        if ($is_search) {
            $clear_parts = $album_id > 0 ? ['album=' . $album_id] : [];
            $clear_url_h = h($base . ($clear_parts ? '?' . implode('&', $clear_parts) : ''));
            $ctx_label   = $album ? ' in this album' : '';
            $content    .= '<div class="alert alert-info">'
                . 'No images found matching <strong>' . $search_h . '</strong>' . $ctx_label . '. '
                . '<a href="' . $clear_url_h . '">Clear search</a>'
                . '</div>';
        } else {
            $content .= '<div class="alert alert-info">No images in this album yet.'
                . ' <a href="' . $batch_url_h . '">Batch Add →</a></div>';
        }
    } else {
        $rows = '';
        foreach ($images as $img) {
            $thumb_url_h = h(image_thumb_url($img));
            $orig_url_h  = h(image_original_url($img));
            $filename_h  = h($img['filename']);
            $title_h     = h($img['title'] ?: $img['filename']);
            $dims_h      = ($img['width'] && $img['height'])
                ? h($img['width'] . '×' . $img['height'])
                : '—';
            $size_h      = h(lumora_format_bytes((int) $img['filesize']));
            $hits_h      = number_format((int) $img['hits']);
            $date_h      = h(substr((string) $img['added_at'], 0, 10));
            $vis_h       = $img['approved']
                ? '<span class="badge bg-success">Visible</span>'
                : '<span class="badge bg-secondary">Hidden</span>';

            // When searching, include album (and optional category) context in the title cell.
            $album_info_h = '';
            if ($is_search) {
                $cat = (string) ($img['cat_name'] ?? '');
                $alb = (string) ($img['album_title'] ?? '');
                $album_info_h = '<div class="text-muted" style="font-size:.73rem">'
                    . ($cat !== '' ? h($cat) . ' › ' : '') . h($alb)
                    . '</div>';
            }

            $img_id_v   = (int) $img['id'];
            $row_alb_id = $is_search ? (int) $img['album_id'] : $album_id;

            $edit_url_h = h(
                $base . '?action=edit&id=' . $img_id_v
                . '&album=' . $album_id
                . ($page > 1 ? '&page=' . $page : '')
                . ($is_search ? '&search=' . rawurlencode($search) : '')
            );
            $del_conf_h = h(
                "Delete '" . $img['filename'] . "'? "
                . "The image file and its thumbnail will be permanently removed from disk."
            );

            $rows .= <<<HTML
<tr>
  <td class="pe-0" style="width:30px">
    <input type="checkbox" class="form-check-input lum-img-check" value="{$img_id_v}">
  </td>
  <td class="text-muted small text-nowrap" style="width:50px">{$img_id_v}</td>
  <td style="width:70px">
    <a href="{$orig_url_h}" target="_blank" rel="noopener">
      <img src="{$thumb_url_h}" alt="{$filename_h}" loading="lazy"
           style="max-width:60px;max-height:48px;object-fit:contain;border-radius:3px">
    </a>
  </td>
  <td>
    <div class="fw-semibold small">{$title_h}</div>
    <div class="text-muted" style="font-size:.73rem;font-family:monospace">{$filename_h}</div>
    {$album_info_h}
  </td>
  <td class="text-muted small text-nowrap">{$dims_h}</td>
  <td class="text-muted small text-nowrap">{$size_h}</td>
  <td class="text-muted small">{$hits_h}</td>
  <td class="text-muted small text-nowrap">{$date_h}</td>
  <td>{$vis_h}</td>
  <td>
    <div class="d-flex gap-1 flex-nowrap">
      <a href="{$edit_url_h}" class="btn btn-sm btn-outline-secondary" title="Edit details">✏️</a>
      <button type="button"
              class="btn btn-sm btn-outline-secondary lum-rethumb-btn"
              data-image-id="{$img_id_v}"
              title="Regenerate thumbnail">🔄</button>
      <form method="post" action="{$base_h}"
            data-confirm="{$del_conf_h}"
            onsubmit="return confirm(this.dataset.confirm)">
        <input type="hidden" name="action"     value="delete">
        <input type="hidden" name="id"         value="{$img_id_v}">
        <input type="hidden" name="album_id"   value="{$row_alb_id}">
        <input type="hidden" name="page"       value="{$page}">
        <input type="hidden" name="search"     value="{$search_h}">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">🗑</button>
      </form>
    </div>
  </td>
</tr>
HTML;
        }

        $content .= '<div class="table-responsive">'
            . '<table class="table table-hover lum-adm-table align-middle">'
            . '<thead><tr>'
            . '<th style="width:30px">'
            .   '<input type="checkbox" class="form-check-input" id="lum-check-all-header">'
            . '</th>'
            . '<th class="text-muted" style="width:50px">ID</th>'
            . '<th>Thumb</th>'
            . '<th>Title / Filename' . ($is_search ? ' / Album' : '') . '</th>'
            . '<th>Dimensions</th>'
            . '<th>Size</th>'
            . '<th>Views</th>'
            . '<th>Added</th>'
            . '<th>Status</th>'
            . '<th>Actions</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table></div>';
    }

    // ── Pagination ────────────────────────────────────────────────────────────
    if ($total_pages > 1) {
        $pag_items = '';

        if ($total_pages <= 10) {
            for ($p = 1; $p <= $total_pages; $p++) {
                $active    = ($p === $page) ? ' active' : '';
                $pag_url_h = h($make_page_url($p));
                $pag_items .= '<li class="page-item' . $active . '">'
                    . '<a class="page-link" href="' . $pag_url_h . '">' . $p . '</a></li>';
            }
        } else {
            // Windowed pagination: first 2, current ±2, last 2.
            $window = 2;
            $show   = array_unique(array_merge(
                [1, 2],
                range(max(1, $page - $window), min($total_pages, $page + $window)),
                [$total_pages - 1, $total_pages]
            ));
            sort($show);

            $prev_shown = null;
            foreach ($show as $p) {
                if ($prev_shown !== null && $p > $prev_shown + 1) {
                    $pag_items .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                }
                $active    = ($p === $page) ? ' active' : '';
                $pag_url_h = h($make_page_url($p));
                $pag_items .= '<li class="page-item' . $active . '">'
                    . '<a class="page-link" href="' . $pag_url_h . '">' . $p . '</a></li>';
                $prev_shown = $p;
            }
        }

        $prev_dis   = $page <= 1             ? ' disabled' : '';
        $next_dis   = $page >= $total_pages  ? ' disabled' : '';
        $prev_url_h = h($make_page_url($page - 1));
        $next_url_h = h($make_page_url($page + 1));

        $content .= '<nav class="mt-3" aria-label="Page navigation">'
            . '<ul class="pagination pagination-sm justify-content-center flex-wrap">'
            . '<li class="page-item' . $prev_dis . '">'
            .   '<a class="page-link" href="' . $prev_url_h . '">‹ Prev</a>'
            . '</li>'
            . $pag_items
            . '<li class="page-item' . $next_dis . '">'
            .   '<a class="page-link" href="' . $next_url_h . '">Next ›</a>'
            . '</li>'
            . '</ul></nav>';
    }

    // ── JavaScript ────────────────────────────────────────────────────────────
    $ajax_base_js = json_encode(lumora_base_url() . 'admin/');

    $content .= <<<HTML
<script>
(function () {
  'use strict';

  var csrf     = {$csrf_js};
  var ajaxBase = {$ajax_base_js};

  // ── Checkbox helpers ──────────────────────────────────────────────────────
  var checks  = Array.prototype.slice.call(document.querySelectorAll('.lum-img-check'));
  var hdrChk  = document.getElementById('lum-check-all-header');
  var cntEl   = document.getElementById('lum-sel-count');
  var delBtn  = document.getElementById('lum-bulk-delete');
  var moveBtn = document.getElementById('lum-bulk-move');
  var statEl  = document.getElementById('lum-bulk-status');

  function selAll(state) {
    checks.forEach(function (c) { c.checked = state; });
    if (hdrChk) hdrChk.checked = state;
    updateCount();
  }

  function updateCount() {
    var n = checks.filter(function (c) { return c.checked; }).length;
    if (cntEl)   cntEl.textContent  = n + ' selected';
    if (delBtn)  delBtn.disabled    = n === 0;
    if (moveBtn) moveBtn.disabled   = n === 0;
  }

  function selectedIds() {
    return checks
      .filter(function (c) { return c.checked; })
      .map(function (c) { return parseInt(c.value, 10); });
  }

  checks.forEach(function (c) { c.addEventListener('change', updateCount); });

  if (hdrChk) {
    hdrChk.addEventListener('change', function () { selAll(hdrChk.checked); });
  }

  var selAllBtn  = document.getElementById('lum-sel-all');
  var selNoneBtn = document.getElementById('lum-sel-none');
  if (selAllBtn)  selAllBtn.addEventListener('click',  function () { selAll(true);  });
  if (selNoneBtn) selNoneBtn.addEventListener('click', function () { selAll(false); });

  // ── Status display ────────────────────────────────────────────────────────
  function showStatus(msg, type) {
    if (!statEl) return;
    statEl.textContent  = msg;
    statEl.className    = 'mt-2 small text-' + (type || 'muted');
    statEl.classList.remove('d-none');
  }

  // ── Generic AJAX POST helper ──────────────────────────────────────────────
  function postJSON(endpoint, params, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxBase + endpoint, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 60000;

    xhr.onload = function () {
      if (xhr.status !== 200) {
        callback({ error: 'Server error ' + xhr.status }, null);
        return;
      }
      try {
        callback(null, JSON.parse(xhr.responseText));
      } catch (e) {
        callback({ error: 'Bad server response.' }, null);
      }
    };
    xhr.onerror = xhr.ontimeout = function () {
      callback({ error: 'Request failed or timed out.' }, null);
    };

    var body = 'csrf_token=' + encodeURIComponent(csrf);
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (Array.isArray(v)) {
        v.forEach(function (item) {
          body += '&' + encodeURIComponent(k + '[]') + '=' + encodeURIComponent(item);
        });
      } else {
        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
      }
    });

    xhr.send(body);
  }

  // ── Bulk delete ───────────────────────────────────────────────────────────
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (ids.length === 0) return;
      if (!confirm(
        'Permanently delete ' + ids.length + ' image' + (ids.length !== 1 ? 's' : '') +
        ' and their files?\n\nThis cannot be undone.'
      )) return;

      delBtn.disabled  = true;
      moveBtn.disabled = true;
      showStatus('Deleting\u2026', 'muted');

      postJSON('ajax_image_delete.php', { ids: ids }, function (err, data) {
        delBtn.disabled  = selectedIds().length === 0;
        moveBtn.disabled = selectedIds().length === 0;

        if (err) {
          showStatus('Error: ' + err.error, 'danger');
          return;
        }

        var msg = data.deleted + ' image' + (data.deleted !== 1 ? 's' : '') + ' deleted.';
        if (data.errors && data.errors.length) {
          msg += ' ' + data.errors.length + ' error(s): ' + data.errors.join(' | ');
        }
        showStatus(msg, (data.errors && data.errors.length) ? 'warning' : 'success');

        if (data.deleted > 0) {
          setTimeout(function () { location.reload(); }, 1400);
        }
      });
    });
  }

  // ── Bulk move ─────────────────────────────────────────────────────────────
  if (moveBtn) {
    moveBtn.addEventListener('click', function () {
      var ids      = selectedIds();
      var targetEl = document.getElementById('lum-move-target');
      var targetId = targetEl ? targetEl.value : '';

      if (ids.length === 0) return;
      if (!targetId) {
        alert('Please select a target album from the dropdown.');
        return;
      }
      if (!confirm(
        'Move ' + ids.length + ' image' + (ids.length !== 1 ? 's' : '') +
        ' to the selected album?'
      )) return;

      delBtn.disabled  = true;
      moveBtn.disabled = true;
      showStatus('Moving\u2026', 'muted');

      postJSON('ajax_image_move.php', { ids: ids, target_album_id: targetId }, function (err, data) {
        delBtn.disabled  = selectedIds().length === 0;
        moveBtn.disabled = selectedIds().length === 0;

        if (err) {
          showStatus('Error: ' + err.error, 'danger');
          return;
        }

        var msg = data.moved + ' image' + (data.moved !== 1 ? 's' : '') + ' moved.';
        if (data.errors && data.errors.length) {
          msg += ' ' + data.errors.length + ' could not be moved: ' + data.errors.join(' | ');
        }
        showStatus(msg, (data.errors && data.errors.length) ? 'warning' : 'success');

        if (data.moved > 0) {
          setTimeout(function () { location.reload(); }, 1400);
        }
      });
    });
  }

  // ── Per-row thumbnail regeneration ────────────────────────────────────────
  document.querySelectorAll('.lum-rethumb-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var imageId  = parseInt(btn.dataset.imageId, 10);
      var origText = btn.textContent;

      btn.disabled    = true;
      btn.textContent = '\u23F3'; // ⏳

      postJSON('ajax_image_rethumb.php', { image_id: imageId }, function (err, data) {
        btn.disabled    = false;
        btn.textContent = origText;

        var ok  = !err && data && data.ok;
        var msg = (err && err.error) || (data && data.message) || 'Unknown error.';

        if (ok) {
          btn.classList.add('btn-success');
          btn.classList.remove('btn-outline-secondary');
          setTimeout(function () {
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
          }, 1600);
        } else {
          alert('Thumbnail regeneration failed: ' + msg);
        }
      });
    });
  });

}());
</script>
HTML;
}

lum_admin_page('Images', $content, 'images');
