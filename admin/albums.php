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
        $edit_id    = lumora_int($_POST['id'] ?? 0, 0, 0);
        $title      = trim($_POST['title']       ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $cat_id     = lumora_int($_POST['category_id'] ?? 0, 0, 0);
        $visibility = lumora_int($_POST['visibility']  ?? 0, 0, 0, 1);
        $pos        = lumora_int($_POST['pos']          ?? 0, 0, 0);
        $folder     = lumora_sanitize_folder(trim($_POST['folder'] ?? ''));

        if ($title === '') {
            lum_flash('Album title is required.', 'danger');
            lumora_redirect($base . '?action=' . ($edit_id ? 'edit&id=' . $edit_id : 'new'));
        }

        if ($edit_id > 0) {
            // Editing — don't change folder (to avoid breaking filesystem paths).
            LumoraDB::update('albums',
                ['title' => $title, 'description' => $desc, 'category_id' => $cat_id,
                 'visibility' => $visibility, 'pos' => $pos],
                'id = ?', [$edit_id]
            );
            lum_flash('Album updated.');
        } else {
            // New album.
            // Use the next auto-increment to generate the folder name if not provided.
            if ($folder === '') {
                // Get the next ID by inserting and retrieving.
                $new_id = (int) LumoraDB::insert('albums', [
                    'category_id' => $cat_id,
                    'folder'      => '__tmp__',   // temporary, replaced immediately
                    'title'       => $title,
                    'description' => $desc,
                    'visibility'  => $visibility,
                    'pos'         => $pos,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $folder = lumora_generate_folder($new_id);
                LumoraDB::update('albums', ['folder' => $folder], 'id = ?', [$new_id]);
            } else {
                // Check folder is unique.
                $exists = LumoraDB::fetchValue(
                    'SELECT id FROM `{PREFIX}albums` WHERE folder = ?', [$folder]
                );
                if ($exists) {
                    lum_flash('Folder name "' . $folder . '" is already in use.', 'danger');
                    lumora_redirect($base . '?action=new');
                }
                LumoraDB::insert('albums', [
                    'category_id' => $cat_id,
                    'folder'      => $folder,
                    'title'       => $title,
                    'description' => $desc,
                    'visibility'  => $visibility,
                    'pos'         => $pos,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }

            // Create the filesystem directory.
            $dir = LUMORA_ALBUMS_PATH . $folder;
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
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
            // Delete images first, then album.
            LumoraDB::delete('images', 'album_id = ?', [$del_id]);
            LumoraDB::delete('albums', 'id = ?', [$del_id]);
            lum_flash('Album deleted. Image files on disk were NOT removed.');
        }
        lumora_redirect($base);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
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

$all_cats = get_all_categories_flat();

// ── New / Edit form ─────────────────────────────────────────────────────────
if ($action === 'new' || $action === 'edit') {
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
    $cat_v   = (int)($album['category_id'] ?? 0);
    $vis_v   = (int)($album['visibility']  ?? 0);
    $pos_v   = (int)($album['pos']         ?? 0);
    $id_v    = (int)($album['id']          ?? 0);
    $folder_v= h($album['folder']       ?? '');
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
    <div class="mb-4">
      <label class="form-label fw-semibold">Position (sort order)</label>
      <input type="number" name="pos" value="{$pos_v}" class="form-control" style="max-width:120px">
    </div>
    <button type="submit" class="btn btn-primary">Save Album</button>
  </form>
</div>
HTML;
    lum_admin_page($ftitle, $content, 'albums');
}

// ── List ─────────────────────────────────────────────────────────────────────
$filter_cat = lumora_int($_GET['cat'] ?? 0, 0, 0);
$filter_sql = $filter_cat > 0 ? ' WHERE a.category_id = ' . $filter_cat : '';

$albums = LumoraDB::fetchAll(
    'SELECT a.*, c.name AS cat_name,
            (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
     FROM `{PREFIX}albums` a
     LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id'
    . $filter_sql .
    ' ORDER BY c.name ASC, a.pos ASC, a.title ASC'
);

$rows = '';
if (empty($albums)) {
    $rows = '<tr><td colspan="7" class="text-center text-muted py-4">No albums yet. <a href="' . $base_h . '?action=new">Create one</a>.</td></tr>';
} else {
    foreach ($albums as $a) {
        $title_h  = h($a['title']);
        $cat_h    = h($a['cat_name'] ?? '—');
        $folder_h = h($a['folder']);
        $vis_h    = $a['visibility'] ? '<span class="badge bg-secondary">Private</span>' : '<span class="badge bg-success">Public</span>';
        $img_cnt  = number_format((int)$a['image_count']);
        $edit_url = h($base . '?action=edit&id=' . (int)$a['id']);
        $batch_url= h(lumora_base_url() . 'admin/batch.php?album=' . (int)$a['id']);
        $view_url = h(lumora_base_url() . 'album.php?album=' . (int)$a['id']);
        $del_conf = "Delete album '" . addslashes($a['title']) . "'? All database records will be removed (files kept on disk).";
        $rows .= <<<HTML
<tr>
  <td><a href="{$edit_url}">{$title_h}</a></td>
  <td>{$cat_h}</td>
  <td><code>{$folder_h}</code></td>
  <td>{$img_cnt}</td>
  <td>{$vis_h}</td>
  <td>
    <a href="{$batch_url}" class="btn btn-sm btn-outline-primary" title="Batch Add">⬆️</a>
    <a href="{$view_url}" class="btn btn-sm btn-outline-secondary" target="_blank" title="View album">↗</a>
    <a href="{$edit_url}" class="btn btn-sm btn-outline-secondary" title="Edit">✏️</a>
  </td>
  <td>
    <form method="post" action="{$base_h}" onsubmit="return confirm('{$del_conf}')">
      <input type="hidden" name="action"     value="delete">
      <input type="hidden" name="id"         value="{$a['id']}">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit" class="btn btn-sm btn-outline-danger">🗑</button>
    </form>
  </td>
</tr>
HTML;
    }
}

$total  = count($albums);
$new_h  = h($base . '?action=new');
$content = '<div class="d-flex justify-content-between align-items-center mb-3">'
    . '<span class="text-muted">' . $total . ' ' . ($total === 1 ? 'album' : 'albums') . '</span>'
    . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Album</a>'
    . '</div>'
    . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
    . '<thead><tr><th>Title</th><th>Category</th><th>Folder</th><th>Images</th><th>Visibility</th><th>Actions</th><th></th></tr></thead>'
    . '<tbody>' . $rows . '</tbody></table></div>';

lum_admin_page('Albums', $content, 'albums');
