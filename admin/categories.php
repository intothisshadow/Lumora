<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Category Management
 *
 * Actions: list (default), new, edit, save, delete
 *
 * List view pagination:
 *   - per_page: read from ?per_page=N, persisted in $_SESSION['lum_adm_per_page_categories'].
 *   - page:     read from ?page=N; clamped to [1, total_pages] by lumora_pagination().
 *   - $all_cats (full flat list) is fetched unconditionally: used for the parent-name
 *     lookup map in the list view AND for the parent dropdown in the new/edit form.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

$action = $_GET['action'] ?? 'list';
$id     = lumora_int($_GET['id'] ?? 0, 0, 1);
$base   = lumora_base_url() . 'admin/categories.php';

// ── POST: save new or edited category ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $edit_id         = lumora_int($_POST['id']             ?? 0, 0, 0);
        $name            = trim($_POST['name']                 ?? '');
        $desc            = trim($_POST['description']          ?? '');
        $parent_id       = lumora_int($_POST['parent_id']      ?? 0, 0, 0);
        $pos             = lumora_int($_POST['pos']             ?? 0, 0, 0);
        $thumb_image_id  = lumora_int($_POST['thumb_image_id'] ?? 0, 0, 0);

        if ($name === '') {
            lum_flash('Category name is required.', 'danger');
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
            // Prevent a category from being its own parent.
            if ($parent_id === $edit_id) $parent_id = 0;
            LumoraDB::update('categories',
                ['name' => $name, 'description' => $desc, 'parent_id' => $parent_id,
                 'pos' => $pos, 'thumb_image_id' => $thumb_image_id],
                'id = ?', [$edit_id]
            );
            lum_flash('Category updated.');
        } else {
            LumoraDB::insert('categories', [
                'parent_id'      => $parent_id,
                'name'           => $name,
                'description'    => $desc,
                'pos'            => $pos,
                'thumb_image_id' => $thumb_image_id,
            ]);
            lum_flash('Category created.');
        }
        lumora_redirect($base);
    }

    if ($act === 'delete') {
        $del_id = lumora_int($_POST['id'] ?? 0, 0, 1);
        if ($del_id > 0) {
            // Re-parent children to the deleted category's parent.
            $cat = get_category($del_id);
            if ($cat) {
                LumoraDB::query(
                    'UPDATE `{PREFIX}categories` SET parent_id = ? WHERE parent_id = ?',
                    [(int)$cat['parent_id'], $del_id]
                );
                LumoraDB::query(
                    'UPDATE `{PREFIX}albums` SET category_id = ? WHERE category_id = ?',
                    [(int)$cat['parent_id'], $del_id]
                );
                LumoraDB::delete('categories', 'id = ?', [$del_id]);
                lum_flash('Category deleted. Child items moved to parent.');
            }
        }
        lumora_redirect($base);
    }
}

// ── Common setup ──────────────────────────────────────────────────────────────
// $all_cats is used for: (1) new/edit parent dropdown, (2) id→name map in list.
$all_cats = get_all_categories_flat();
$csrf     = h(lumora_csrf_token());
$base_h   = h($base);

// Build parent dropdown helper.
function cat_parent_options(array $cats, int $exclude_id = 0, int $selected = 0): string
{
    $html = '<option value="0">— Root (no parent) —</option>';
    foreach ($cats as $c) {
        if ((int)$c['id'] === $exclude_id) continue;
        $sel = ((int)$c['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int)$c['id'] . '"' . $sel . '>'
            . h(($c['parent_id'] > 0 ? '— ' : '') . $c['name'])
            . '</option>';
    }
    return $html;
}

// ── New / Edit form ─────────────────────────────────────────────────────────
if ($action === 'new' || $action === 'edit') {
    $cat = ($action === 'edit' && $id > 0) ? get_category($id) : null;
    if ($action === 'edit' && !$cat) {
        lum_flash('Category not found.', 'danger');
        lumora_redirect($base);
    }

    $title     = $action === 'new' ? 'New Category' : 'Edit Category';
    $name_v    = h($cat['name'] ?? '');
    $desc_v    = h($cat['description'] ?? '');
    $parent_v  = (int)($cat['parent_id'] ?? 0);
    $pos_v     = (int)($cat['pos'] ?? 0);
    $id_v      = (int)($cat['id'] ?? 0);
    $thumb_v   = (int)($cat['thumb_image_id'] ?? 0);
    $par_opts  = cat_parent_options($all_cats, $id_v, $parent_v);

    $content = <<<HTML
<a href="{$base_h}" class="btn btn-sm btn-outline-secondary mb-3">← Back to list</a>
<div class="lum-adm-card">
  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="id"         value="{$id_v}">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <div class="mb-3">
      <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
      <input type="text" name="name" value="{$name_v}" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Description</label>
      <textarea name="description" rows="3" class="form-control">{$desc_v}</textarea>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Parent Category</label>
      <select name="parent_id" class="form-select">{$par_opts}</select>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Position (sort order)</label>
      <input type="number" name="pos" value="{$pos_v}" class="form-control" style="max-width:120px">
      <div class="form-text">Lower numbers appear first. 0 = default.</div>
    </div>
    <div class="mb-4">
      <label class="form-label fw-semibold">Cover Image <small class="text-muted">(optional)</small></label>
      <input type="number" name="thumb_image_id" value="{$thumb_v}" class="form-control"
             style="max-width:140px" min="0">
      <div class="form-text">Image ID to use as the category cover thumbnail. 0 = auto-pick the first image from any album in this category.</div>
    </div>
    <button type="submit" class="btn btn-primary">Save Category</button>
  </form>
</div>
HTML;
    lum_admin_page($title, $content, 'categories');
}

// ── List ─────────────────────────────────────────────────────────────────────

// Build id→name map from the full category list (used for parent-name lookup).
// This covers categories that may not be on the current page.
$cat_map = array_column($all_cats, 'name', 'id');

// Per-page: read from GET, persist in session, fall back to default of 25.
$valid_per_page = [25, 50, 100];
$raw_per_page   = lumora_int($_GET['per_page'] ?? 0, 0, 0);
if (in_array($raw_per_page, $valid_per_page, true)) {
    $_SESSION['lum_adm_per_page_categories'] = $raw_per_page;
    $per_page = $raw_per_page;
} else {
    $per_page = (int) ($_SESSION['lum_adm_per_page_categories'] ?? 25);
    if (!in_array($per_page, $valid_per_page, true)) $per_page = 25;
}

// Current page (lumora_pagination() will clamp it to [1, total_pages]).
$page = lumora_int($_GET['page'] ?? 1, 1, 1);

// Database queries — only records for the current page are loaded.
$total      = GalleryService::countAllCategories();
$paged_cats = GalleryService::getPaginatedCategoriesFlat($page, $per_page);

// Pagination descriptor.
$url_pattern = $base . '?per_page=' . $per_page . '&page=%d';
$pag         = lumora_pagination($total, $per_page, $page, $url_pattern);

// Row HTML.
$rows = '';
if (empty($paged_cats)) {
    $empty_msg = ($total === 0)
        ? 'No categories yet. <a href="' . $base_h . '?action=new">Create one</a>.'
        : 'No categories on this page.';
    $rows = '<tr><td colspan="5" class="text-center text-muted py-4">' . $empty_msg . '</td></tr>';
} else {
    foreach ($paged_cats as $c) {
        $name_h   = h($c['name']);
        $parent_h = $c['parent_id'] > 0
            ? h($cat_map[$c['parent_id']] ?? '—')
            : '<span class="text-muted">Root</span>';
        $edit_url = h($base . '?action=edit&id=' . (int)$c['id']);
        $del_conf = h('Delete category \'' . $c['name'] . '\'? Child items will be moved to parent.');
        $rows .= <<<HTML
<tr>
  <td>{$name_h}</td>
  <td>{$parent_h}</td>
  <td>{$c['pos']}</td>
  <td><a href="{$edit_url}" class="btn btn-sm btn-outline-secondary">Edit</a></td>
  <td>
    <form method="post" action="{$base_h}" data-confirm="{$del_conf}" onsubmit="return confirm(this.dataset.confirm)">
      <input type="hidden" name="action"     value="delete">
      <input type="hidden" name="id"         value="{$c['id']}">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
  </td>
</tr>
HTML;
    }
}

// Item count summary.
if ($total === 0) {
    $summary = '0 categories';
} else {
    $label   = $total === 1 ? 'category' : 'categories';
    $summary = 'Showing ' . $pag['start_item'] . '–' . $pag['end_item'] . ' of ' . $total . ' ' . $label;
}

// Per-page selector and pagination controls.
$per_page_sel = lum_per_page_selector($base, [], $per_page);
$pag_html     = lum_admin_pagination($pag);
$new_h        = h($base . '?action=new');

$pag_bar = $pag_html
    ? '<div class="d-flex justify-content-center my-2">' . $pag_html . '</div>'
    : '';

$content =
    // Header: summary left, controls + new-category button right.
    '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
    . '<span class="text-muted small">' . $summary . '</span>'
    . '<div class="d-flex align-items-center gap-2">'
    . $per_page_sel
    . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Category</a>'
    . '</div>'
    . '</div>'
    // Top pagination.
    . $pag_bar
    // Table.
    . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
    . '<thead><tr><th>Name</th><th>Parent</th><th>Pos</th><th></th><th></th></tr></thead>'
    . '<tbody>' . $rows . '</tbody></table></div>'
    // Bottom pagination.
    . $pag_bar;

lum_admin_page('Categories', $content, 'categories');
