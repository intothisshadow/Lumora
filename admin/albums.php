<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Album Management
 *
 * Actions: list (default), new, edit, save, delete
 *
 * Creating an album:
 *   - Generates a zero-padded folder name (e.g. "00001") via lumora_generate_folder()
 *     unless the admin specifies a custom folder name.
 *   - Creates the filesystem directory albums/{folder}/ if it doesn't exist.
 *
 * List view — two modes:
 *
 *   Hierarchy mode (default — no search term, no category filter):
 *     All albums are grouped under their category in the full category tree.
 *     Albums belonging to subcategories are nested beneath their parent category
 *     header. Uncategorized albums (category_id = 0) appear at the top in a
 *     dedicated section. The complete album set is loaded in a single query;
 *     no pagination is applied in this mode.
 *
 *   Flat / filtered mode (search term or category filter active):
 *     Reverts to the traditional paginated table. Pagination, per-page selector,
 *     and category filter all work as before. A ✕ Clear button resets to
 *     hierarchy mode.
 *
 *   GET parameters:
 *     q:        partial album title search → triggers flat mode
 *     cat:      category filter (ID) → triggers flat mode
 *     per_page: persisted in $_SESSION['lum_adm_per_page_albums']
 *     page:     1-based, clamped by lumora_pagination()
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$action = $_GET['action'] ?? 'list';
$id     = lumora_int($_GET['id'] ?? 0, 0, 1);
$base   = lumora_base_url() . 'admin/albums.php';
$csrf   = h(lumora_csrf_token());
$base_h = h($base);

// ── POST: save ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $edit_id        = lumora_int($_POST['id']             ?? 0, 0, 0);
        $title          = trim($_POST['title']                ?? '');
        $desc           = trim($_POST['description']          ?? '');
        $cat_id         = lumora_int($_POST['category_id']    ?? 0, 0, 0);
        $visibility     = lumora_int($_POST['visibility']     ?? 0, 0, 0, 1);
        $pos            = lumora_int($_POST['pos']             ?? 0, 0, 0);
        $folder         = lumora_sanitize_folder(trim($_POST['folder'] ?? ''));
        $thumb_image_id = lumora_int($_POST['thumb_image_id'] ?? 0, 0, 0);

        if ($title === '') {
            lum_flash('Album title is required.', 'danger');
            lumora_redirect($base . '?action=' . ($edit_id ? 'edit&id=' . $edit_id : 'new'));
        }

        // Validate thumb_image_id if provided.
        if ($thumb_image_id > 0) {
            $valid = LumoraDB::fetchValue(
                'SELECT id FROM `{PREFIX}images` WHERE id = ? AND approved = 1', [$thumb_image_id]
            );
            if (!$valid) {
                lum_flash('Cover image ID ' . $thumb_image_id . ' does not exist or is not approved. Cover cleared.', 'warning');
                $thumb_image_id = 0;
            }
        }

        if ($edit_id > 0) {
            // Editing — don't change folder (to avoid breaking filesystem paths).
            LumoraDB::update('albums',
                ['title' => $title, 'description' => $desc, 'category_id' => $cat_id,
                 'visibility' => $visibility, 'pos' => $pos, 'thumb_image_id' => $thumb_image_id],
                'id = ?', [$edit_id]
            );
            lum_flash('Album updated.');
        } else {
            // New album.
            if ($folder === '') {
                $new_id = (int) LumoraDB::insert('albums', [
                    'category_id'    => $cat_id,
                    'folder'         => '__tmp__',
                    'title'          => $title,
                    'description'    => $desc,
                    'visibility'     => $visibility,
                    'pos'            => $pos,
                    'thumb_image_id' => $thumb_image_id,
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $folder = lumora_generate_folder($new_id);
                LumoraDB::update('albums', ['folder' => $folder], 'id = ?', [$new_id]);
            } else {
                $exists = LumoraDB::fetchValue(
                    'SELECT id FROM `{PREFIX}albums` WHERE folder = ?', [$folder]
                );
                if ($exists) {
                    lum_flash('Folder name "' . $folder . '" is already in use.', 'danger');
                    lumora_redirect($base . '?action=new');
                }
                LumoraDB::insert('albums', [
                    'category_id'    => $cat_id,
                    'folder'         => $folder,
                    'title'          => $title,
                    'description'    => $desc,
                    'visibility'     => $visibility,
                    'pos'            => $pos,
                    'thumb_image_id' => $thumb_image_id,
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
            }

            $dir = LUMORA_ALBUMS_PATH . $folder;
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    lum_flash('Album saved but could not create directory albums/' . $folder . '/. Create it manually via FTP.', 'warning');
                    lumora_redirect($base);
                }
            }
            lum_flash('Album "' . $title . '" created. Upload images to albums/' . $folder . '/');
        }
        lumora_redirect($base);
    }

    if ($act === 'delete') {
        $del_id = lumora_int($_POST['id'] ?? 0, 0, 1);
        if ($del_id > 0) {
            $del_album = LumoraDB::fetchOne(
                'SELECT folder FROM `{PREFIX}albums` WHERE id = ?', [$del_id]
            );
            LumoraDB::delete('images', 'album_id = ?', [$del_id]);
            LumoraDB::delete('albums', 'id = ?', [$del_id]);

            $folder_msg = ' Image files on disk were NOT removed.';
            if ($del_album && $del_album['folder'] !== '') {
                $dir = LUMORA_ALBUMS_PATH . $del_album['folder'];
                if (is_dir($dir)) {
                    $scan     = scandir($dir);
                    $is_empty = ($scan !== false && count($scan) === 2);
                    if ($is_empty) {
                        if (rmdir($dir)) {
                            $folder_msg = ' Empty folder albums/' . $del_album['folder'] . '/ was removed.';
                        } else {
                            $folder_msg = ' Folder albums/' . $del_album['folder'] . '/ could not be removed — delete it manually via FTP.';
                        }
                    } else {
                        $folder_msg = ' Folder albums/' . $del_album['folder'] . '/ is not empty — files kept on disk.';
                    }
                } else {
                    $folder_msg = ' No folder found on disk for albums/' . $del_album['folder'] . '/';
                }
            }
            lum_flash('Album deleted.' . $folder_msg);
        }
        lumora_redirect($base);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build <option> list for the category dropdown on the new/edit form. */
function album_cat_options(array $cats, int $selected = 0): string
{
    $html = '<option value="0">— No category —</option>';
    foreach ($cats as $c) {
        $sel  = ((int)$c['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int)$c['id'] . '"' . $sel . '>'
            . h(($c['parent_id'] > 0 ? '— ' : '') . $c['name'])
            . '</option>';
    }
    return $html;
}

// ── Hierarchy render helpers ──────────────────────────────────────────────────

/**
 * Render a single album table row for the hierarchy view (6 columns).
 *
 * The Title cell is indented by $indent_px to reflect the category depth.
 *
 * @param array<string, mixed> $a         Album row (includes image_count, folder, etc.).
 * @param int                  $indent_px Left padding in pixels for the title cell.
 * @param string               $base_h    HTML-escaped base URL.
 * @param string               $csrf      HTML-escaped CSRF token.
 */
function render_album_row(array $a, int $indent_px, string $base_h, string $csrf): string
{
    $title_h    = h($a['title']);
    $folder_h   = h($a['folder']);
    $vis_h      = $a['visibility']
        ? '<span class="badge bg-secondary">Private</span>'
        : '<span class="badge bg-success">Public</span>';
    $img_cnt    = number_format((int) $a['image_count']);
    $edit_url   = h($base_h . '?action=edit&id=' . (int) $a['id']);
    $batch_url  = h(lumora_base_url() . 'admin/batch.php?album='  . (int) $a['id']);
    $images_url = h(lumora_base_url() . 'admin/images.php?album=' . (int) $a['id']);
    $view_url   = h(lumora_base_url() . 'album.php?album='        . (int) $a['id']);
    $del_conf   = h('Delete album \'' . $a['title'] . '\'? All DB records will be removed. If the album folder is empty it will also be deleted; otherwise files on disk are kept.');

    $title_cell = '<div style="padding-left:' . $indent_px . 'px">'
        . '<a href="' . $edit_url . '">' . $title_h . '</a>'
        . '</div>';

    return '<tr>'
        . '<td>' . $title_cell . '</td>'
        . '<td><code class="small">' . $folder_h . '</code></td>'
        . '<td>' . $img_cnt . '</td>'
        . '<td>' . $vis_h . '</td>'
        . '<td>'
        .   '<a href="' . $batch_url  . '" class="btn btn-sm btn-outline-primary"    title="Batch Add">&#x2B06;&#xFE0F;</a>'
        .   '<a href="' . $images_url . '" class="btn btn-sm btn-outline-secondary"  title="Manage Images">&#x1F4F8;</a>'
        .   '<a href="' . $view_url   . '" class="btn btn-sm btn-outline-secondary"  title="View album" target="_blank">&#x2197;</a>'
        .   '<a href="' . $edit_url   . '" class="btn btn-sm btn-outline-secondary"  title="Edit">&#x270F;&#xFE0F;</a>'
        . '</td>'
        . '<td>'
        .   '<form method="post" action="' . $base_h . '" data-confirm="' . $del_conf . '"'
        .       ' onsubmit="return confirm(this.dataset.confirm)">'
        .     '<input type="hidden" name="action"     value="delete">'
        .     '<input type="hidden" name="id"         value="' . (int) $a['id'] . '">'
        .     '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        .     '<button type="submit" class="btn btn-sm btn-outline-danger">&#x1F5D1;</button>'
        .   '</form>'
        . '</td>'
        . '</tr>';
}

/**
 * Recursively render category section headers and album rows for the hierarchy view.
 *
 * For each child category of $parent_cat_id:
 *   1. Renders a section-header <tr> showing the category name and album count.
 *   2. Renders each album in that category as an indented album row.
 *   3. Recurses into that category's children at depth + 1.
 *
 * Only categories that exist in $cats_by_parent are visited. A $visited
 * ref-array prevents infinite recursion caused by corrupt parent_id cycles.
 *
 * @param array<int, list<array<string, mixed>>> $cats_by_parent  parent_id => [category rows]
 * @param array<int, list<array<string, mixed>>> $albums_by_cat   category_id => [album rows]
 * @param int                                    $parent_cat_id   Starting parent category ID.
 * @param int                                    $depth           Nesting depth (0 = root categories).
 * @param string                                 $base_h          HTML-escaped base URL.
 * @param string                                 $csrf            HTML-escaped CSRF token.
 * @param array<int, true>                       $visited         Cycle guard, passed by ref.
 */
function render_album_tree(
    array  $cats_by_parent,
    array  $albums_by_cat,
    int    $parent_cat_id,
    int    $depth,
    string $base_h,
    string $csrf,
    array  &$visited
): string {
    if (!isset($cats_by_parent[$parent_cat_id])) return '';
    $html = '';

    foreach ($cats_by_parent[$parent_cat_id] as $c) {
        $cat_id = (int) $c['id'];
        if (isset($visited[$cat_id])) continue; // cycle guard
        $visited[$cat_id] = true;

        $cat_albums      = $albums_by_cat[$cat_id] ?? [];
        $cat_album_count = count($cat_albums);

        // Category section header: indented tree connector + name + album count badge.
        $cat_indent_px = $depth * 20;
        $connector     = $depth > 0
            ? '<span class="lum-tree-connector" aria-hidden="true">└ </span>'
            : '';
        $cat_name_h    = h($c['name']);
        $badge         = $cat_album_count > 0
            ? '<span class="badge rounded-pill text-bg-primary ms-2">' . $cat_album_count . '</span>'
            : '';

        $header_inner = '<div style="padding-left:' . $cat_indent_px . 'px">'
            . $connector . '<strong>' . $cat_name_h . '</strong>' . $badge
            . '</div>';

        $html .= '<tr class="lum-tree-cat-header"><td colspan="6">' . $header_inner . '</td></tr>';

        // Album rows nested one level deeper than the category header.
        $album_indent_px = ($depth + 1) * 20;
        foreach ($cat_albums as $a) {
            $html .= render_album_row($a, $album_indent_px, $base_h, $csrf);
        }

        // Recurse into child categories.
        $html .= render_album_tree(
            $cats_by_parent, $albums_by_cat, $cat_id, $depth + 1, $base_h, $csrf, $visited
        );
    }

    return $html;
}

// ── New / Edit form ───────────────────────────────────────────────────────────
if ($action === 'new' || $action === 'edit') {
    $all_cats = get_all_categories_flat();

    $album = ($action === 'edit' && $id > 0)
        ? LumoraDB::fetchOne('SELECT * FROM `{PREFIX}albums` WHERE id = ?', [$id])
        : null;

    if ($action === 'edit' && !$album) {
        lum_flash('Album not found.', 'danger');
        lumora_redirect($base);
    }

    $ftitle  = $action === 'new' ? 'New Album' : 'Edit Album';
    $title_v = h($album['title']       ?? '');
    $desc_v  = h($album['description'] ?? '');
    $cat_v   = (int)($album['category_id']    ?? 0);
    $vis_v   = (int)($album['visibility']     ?? 0);
    $pos_v   = (int)($album['pos']            ?? 0);
    $id_v    = (int)($album['id']             ?? 0);
    $folder_v= h($album['folder']            ?? '');
    $thumb_v = (int)($album['thumb_image_id'] ?? 0);
    $cat_opts= album_cat_options($all_cats, $cat_v);
    $vis_pub = $vis_v === 0 ? ' selected' : '';
    $vis_prv = $vis_v === 1 ? ' selected' : '';

    $folder_field = $action === 'new'
        ? '<div class="mb-3">
             <label class="form-label fw-semibold">Folder Path <small class="text-muted">(optional — auto-generated numeric if blank)</small></label>
             <input type="text" name="folder" value="" class="form-control font-monospace"
                    placeholder="e.g. Xena/Season1/1x01-SinsOfThePast">
             <div class="form-text">
               Use <code>/</code> to create subfolders: <code>ShowName/Season2/EpisodeSlug</code>.<br>
               Allowed: letters, digits, hyphens <code>-</code>, underscores <code>_</code>, dots <code>.</code>.<br>
               Must be unique. Leave blank for an auto-generated numeric folder (e.g. <code>00042</code>).
             </div>
           </div>'
        : '<div class="mb-3">
             <label class="form-label fw-semibold">Folder</label>
             <input type="text" value="' . $folder_v . '" class="form-control font-monospace" disabled>
             <div class="form-text">Folder cannot be changed after creation.</div>
           </div>';

    $content = <<<HTML
<a href="{$base_h}" class="btn btn-sm btn-outline-secondary mb-3">← Back to list</a>
<div class="lum-adm-card">
  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="id"         value="{$id_v}">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <div class="mb-3">
      <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
      <input type="text" name="title" value="{$title_v}" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Description</label>
      <textarea name="description" rows="3" class="form-control">{$desc_v}</textarea>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Category</label>
      <select name="category_id" class="form-select">{$cat_opts}</select>
    </div>
    {$folder_field}
    <div class="mb-3">
      <label class="form-label fw-semibold">Visibility</label>
      <select name="visibility" class="form-select" style="max-width:200px">
        <option value="0"{$vis_pub}>Public</option>
        <option value="1"{$vis_prv}>Private (hidden)</option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Position (sort order)</label>
      <input type="number" name="pos" value="{$pos_v}" class="form-control" style="max-width:120px">
    </div>
    <div class="mb-4">
      <label class="form-label fw-semibold">Cover Image <small class="text-muted">(optional)</small></label>
      <input type="number" name="thumb_image_id" value="{$thumb_v}" class="form-control"
             style="max-width:140px" min="0">
      <div class="form-text">Image ID to use as the album cover thumbnail. 0 = auto-pick the first image in this album.</div>
    </div>
    <button type="submit" class="btn btn-primary">Save Album</button>
  </form>
</div>
HTML;
    lum_admin_page($ftitle, $content, 'albums');
}

// ── List ──────────────────────────────────────────────────────────────────────

// Search / filter state (read early — determines which mode is used below).
$search     = trim($_GET['q']   ?? '');
$search_h   = h($search);
$filter_cat = lumora_int($_GET['cat'] ?? 0, 0, 0);

// Hierarchy mode: active when no search and no category filter are applied.
// Any filter or search term switches to the traditional flat paginated view.
$hierarchy_mode = ($search === '' && $filter_cat === 0);

// Per-page: read from GET, persist in session; used by flat mode and preserved
// in the hierarchy search form so flat mode inherits the preferred page size.
$valid_per_page = [25, 50, 100];
$raw_per_page   = lumora_int($_GET['per_page'] ?? 0, 0, 0);
if (in_array($raw_per_page, $valid_per_page, true)) {
    $_SESSION['lum_adm_per_page_albums'] = $raw_per_page;
    $per_page = $raw_per_page;
} else {
    $per_page = (int) ($_SESSION['lum_adm_per_page_albums'] ?? 25);
    if (!in_array($per_page, $valid_per_page, true)) $per_page = 25;
}

$page  = lumora_int($_GET['page'] ?? 1, 1, 1);
$new_h = h($base . '?action=new');

// ── Hierarchy mode ────────────────────────────────────────────────────────────
if ($hierarchy_mode) {
    // Two queries: all categories (with counts) and all albums.
    $all_cats_h = GalleryService::getAllCategoriesWithCounts();
    $all_albums = GalleryService::getAllAdminAlbumsGrouped();

    // Build data structures for tree rendering.
    $cats_by_parent = [];
    foreach ($all_cats_h as $c) {
        $cats_by_parent[(int) $c['parent_id']][] = $c;
    }
    $albums_by_cat = [];
    foreach ($all_albums as $a) {
        $albums_by_cat[(int) $a['category_id']][] = $a;
    }

    $total_albums = count($all_albums);
    $lbl          = $total_albums === 1 ? 'album' : 'albums';

    // ── Build hierarchy rows ──────────────────────────────────────────────────

    $rows = '';

    // 1. Uncategorized albums (category_id = 0) — always shown first if any exist.
    $uncategorized = $albums_by_cat[0] ?? [];
    if (!empty($uncategorized)) {
        $uc_count   = count($uncategorized);
        $uc_badge   = '<span class="badge rounded-pill text-bg-secondary ms-2">' . $uc_count . '</span>';
        $uc_header  = '<div><em class="text-muted">(No Category)</em>' . $uc_badge . '</div>';
        $rows .= '<tr class="lum-tree-cat-header"><td colspan="6">' . $uc_header . '</td></tr>';
        foreach ($uncategorized as $a) {
            $rows .= render_album_row($a, 20, $base_h, $csrf);
        }
    }

    // 2. Category hierarchy: root categories and their descendants.
    $visited = [];
    $rows   .= render_album_tree($cats_by_parent, $albums_by_cat, 0, 0, $base_h, $csrf, $visited);

    if ($rows === '') {
        $rows = '<tr><td colspan="6" class="text-center text-muted py-4">'
            . 'No albums yet. <a href="' . $new_h . '">Create one</a>.'
            . '</td></tr>';
    }

    // Hierarchy search form — submitting takes the user to flat/search mode.
    $search_form =
        '<form method="get" action="' . $base_h . '" class="d-flex align-items-center gap-2 mb-3 flex-wrap">'
        . '<input type="hidden" name="per_page" value="' . $per_page . '">'
        . '<div class="input-group input-group-sm" style="max-width:340px">'
        . '<input type="text" name="q" value="" class="form-control"'
        . ' placeholder="Search by album name\xe2\x80\xa6" maxlength="200" autocomplete="off">'
        . '<button type="submit" class="btn btn-outline-secondary">Search</button>'
        . '</div>'
        . '</form>';

    $summary_text = $total_albums > 0
        ? 'Hierarchy view &middot; ' . number_format($total_albums) . ' ' . $lbl
        : '0 albums';

    $content =
        $search_form
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
        . '<span class="text-muted small">' . $summary_text . '</span>'
        . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Album</a>'
        . '</div>'
        . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
        . '<thead><tr>'
        . '<th>Title</th>'
        . '<th>Folder</th>'
        . '<th style="width:70px">Images</th>'
        . '<th style="width:90px">Visibility</th>'
        . '<th>Actions</th>'
        . '<th style="width:50px"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';

    lum_admin_page('Albums', $content, 'albums');
}

// ── Flat / filtered mode ──────────────────────────────────────────────────────

// Database queries — only records for the current page are loaded.
$total  = GalleryService::countAdminAlbums($filter_cat, $search);
$albums = GalleryService::getAdminAlbums($filter_cat, $page, $per_page, $search);

// Pagination descriptor. URL pattern preserves cat, q, and per_page.
$url_params = 'per_page=' . $per_page;
if ($search !== '') {
    $url_params = 'q=' . urlencode($search) . '&' . $url_params;
}
if ($filter_cat > 0) {
    $url_params = 'cat=' . $filter_cat . '&' . $url_params;
}
$url_pattern = $base . '?' . $url_params . '&page=%d';
$pag         = lumora_pagination($total, $per_page, $page, $url_pattern);

// Row HTML.
$rows = '';
if (empty($albums)) {
    if ($total === 0 && $search !== '') {
        $clear_url_empty = $base . ($filter_cat > 0 ? '?cat=' . $filter_cat . '&per_page=' . $per_page : '?per_page=' . $per_page);
        $empty_msg = 'No albums found matching <strong>' . $search_h . '</strong>. '
            . '<a href="' . h($clear_url_empty) . '">Clear search</a>.';
    } elseif ($total === 0) {
        $empty_msg = 'No albums yet. <a href="' . $base_h . '?action=new">Create one</a>.';
    } else {
        $empty_msg = 'No albums on this page.';
    }
    $rows = '<tr><td colspan="7" class="text-center text-muted py-4">' . $empty_msg . '</td></tr>';
} else {
    foreach ($albums as $a) {
        $title_h    = h($a['title']);
        $cat_h      = h($a['cat_name'] ?? '—');
        $folder_h   = h($a['folder']);
        $vis_h      = $a['visibility'] ? '<span class="badge bg-secondary">Private</span>' : '<span class="badge bg-success">Public</span>';
        $img_cnt    = number_format((int)$a['image_count']);
        $edit_url   = h($base . '?action=edit&id=' . (int)$a['id']);
        $batch_url  = h(lumora_base_url() . 'admin/batch.php?album='  . (int)$a['id']);
        $images_url = h(lumora_base_url() . 'admin/images.php?album=' . (int)$a['id']);
        $view_url   = h(lumora_base_url() . 'album.php?album='        . (int)$a['id']);
        $del_conf   = h('Delete album \'' . $a['title'] . '\'? All DB records will be removed. If the album folder is empty it will also be deleted; otherwise files on disk are kept.');
        $rows .= '<tr>'
            . '<td><a href="' . $edit_url . '">' . $title_h . '</a></td>'
            . '<td>' . $cat_h . '</td>'
            . '<td><code>' . $folder_h . '</code></td>'
            . '<td>' . $img_cnt . '</td>'
            . '<td>' . $vis_h . '</td>'
            . '<td>'
            .   '<a href="' . $batch_url  . '" class="btn btn-sm btn-outline-primary"   title="Batch Add">&#x2B06;&#xFE0F;</a>'
            .   '<a href="' . $images_url . '" class="btn btn-sm btn-outline-secondary" title="Manage Images">&#x1F4F8;</a>'
            .   '<a href="' . $view_url   . '" class="btn btn-sm btn-outline-secondary" title="View album" target="_blank">&#x2197;</a>'
            .   '<a href="' . $edit_url   . '" class="btn btn-sm btn-outline-secondary" title="Edit">&#x270F;&#xFE0F;</a>'
            . '</td>'
            . '<td>'
            .   '<form method="post" action="' . $base_h . '" data-confirm="' . $del_conf . '"'
            .       ' onsubmit="return confirm(this.dataset.confirm)">'
            .     '<input type="hidden" name="action"     value="delete">'
            .     '<input type="hidden" name="id"         value="' . $a['id'] . '">'
            .     '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            .     '<button type="submit" class="btn btn-sm btn-outline-danger">&#x1F5D1;</button>'
            .   '</form>'
            . '</td>'
            . '</tr>';
    }
}

// Item count summary.
if ($total === 0) {
    $summary = $search !== ''
        ? 'No results for <strong>' . $search_h . '</strong>'
        : '0 albums';
} else {
    $label   = $total === 1 ? 'album' : 'albums';
    $summary = 'Showing ' . $pag['start_item'] . '&ndash;' . $pag['end_item'] . ' of ' . $total . ' ' . $label;
    if ($search !== '') {
        $summary .= ' matching <strong>' . $search_h . '</strong>';
    }
}

// Per-page selector form (preserves cat and q filters).
$preserve = [];
if ($filter_cat > 0) $preserve['cat'] = $filter_cat;
if ($search !== '')  $preserve['q']   = $search;
$per_page_sel = lum_per_page_selector($base, $preserve, $per_page);
$pag_html     = lum_admin_pagination($pag);

$pag_bar = $pag_html
    ? '<div class="d-flex justify-content-center my-2">' . $pag_html . '</div>'
    : '';

// Flat-mode search form: preserves per_page and category filter; resets to page 1.
$search_hidden_cat = $filter_cat > 0
    ? '<input type="hidden" name="cat" value="' . $filter_cat . '">'
    : '';
$clear_base = $base . ($filter_cat > 0 ? '?cat=' . $filter_cat . '&per_page=' . $per_page : '?per_page=' . $per_page);
$search_clear_html = $search !== ''
    ? '<a href="' . h($clear_base) . '" class="btn btn-sm btn-outline-secondary" title="Clear search">&#x2715; Clear</a>'
    : '';

$search_form =
    '<form method="get" action="' . $base_h . '" class="d-flex align-items-center gap-2 mb-3 flex-wrap">'
    . $search_hidden_cat
    . '<input type="hidden" name="per_page" value="' . $per_page . '">'
    . '<div class="input-group input-group-sm" style="max-width:340px">'
    . '<input type="text" name="q" value="' . $search_h . '" class="form-control"'
    . ' placeholder="Search by album name\xe2\x80\xa6" maxlength="200" autocomplete="off">'
    . '<button type="submit" class="btn btn-outline-secondary">Search</button>'
    . '</div>'
    . $search_clear_html
    . '</form>';

$content =
    $search_form
    . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
    . '<span class="text-muted small">' . $summary . '</span>'
    . '<div class="d-flex align-items-center gap-2">'
    . $per_page_sel
    . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Album</a>'
    . '</div>'
    . '</div>'
    . $pag_bar
    . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
    . '<thead><tr>'
    . '<th>Title</th><th>Category</th><th>Folder</th><th>Images</th><th>Visibility</th><th>Actions</th><th></th>'
    . '</tr></thead>'
    . '<tbody>' . $rows . '</tbody></table></div>'
    . $pag_bar;

lum_admin_page('Albums', $content, 'albums');
