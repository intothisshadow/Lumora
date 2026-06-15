<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration Hub
 *
 * Discovers installed importer plugins and displays migration status.
 * Each plugin handles its own import UI; this page acts as the entry point.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

define('LUMORA_ENTRY', true);
require_once __DIR__ . '/../include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_admin();

// ── Discover importer plugins ─────────────────────────────────────────────────
$importers = MigrationService::discoverImporters();

// ── Build page content ────────────────────────────────────────────────────────
ob_start();

if (empty($importers)) {
    echo '<div class="alert alert-info">'
        . '<strong>No importer plugins found.</strong> '
        . 'Place an importer plugin in the <code>plugins/</code> directory. '
        . 'Each plugin must contain a <code>plugin.json</code> manifest with <code>"type": "importer"</code>.'
        . '</div>';
} else {
    echo '<div class="row g-3">';
    foreach ($importers as $imp) {
        $name        = h($imp['name']        ?? 'Unknown Importer');
        $description = h($imp['description'] ?? '');
        $version     = h($imp['version']     ?? '');
        $author      = h($imp['author']      ?? '');
        $source      = $imp['source']        ?? ($imp['id'] ?? '');
        $min_lumora  = $imp['min_lumora']    ?? '1.0.0';
        $admin_url   = h(lumora_base_url() . ltrim($imp['admin_url'] ?? '', '/'));

        // Compatibility check
        $compatible  = MigrationService::isCompatible($min_lumora);
        $compat_html = $compatible
            ? '<span class="badge bg-success">Compatible</span>'
            : '<span class="badge bg-danger">Requires Lumora ' . h($min_lumora) . '+</span>';

        // Previous import status
        $status     = MigrationService::getMigrationStatus($source);
        $status_html = '';
        if ($status !== null) {
            $date        = h($status['imported_at'] ?? '');
            $n_cat       = number_format((int) ($status['categories'] ?? 0));
            $n_alb       = number_format((int) ($status['albums']     ?? 0));
            $n_img       = number_format((int) ($status['images']     ?? 0));
            $plg_ver     = h($status['plugin_version'] ?? '');
            $status_html = <<<HTML
<div class="alert alert-warning py-2 mb-2 small">
  <strong>⚠ Previously imported</strong> on {$date} (plugin v{$plg_ver})<br>
  Categories: {$n_cat} · Albums: {$n_alb} · Images: {$n_img}<br>
  Re-importing will create duplicate content unless you clear the gallery first.
</div>
HTML;
        }

        $run_btn = $compatible
            ? '<a href="' . $admin_url . '" class="btn btn-primary btn-sm">Run Importer</a>'
            : '<button class="btn btn-secondary btn-sm" disabled title="Incompatible with this Lumora version">Run Importer</button>';

        echo <<<HTML
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h5 class="card-title mb-0">{$name}</h5>
        {$compat_html}
      </div>
      <p class="card-text text-muted small mb-2">{$description}</p>
      {$status_html}
      <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted">v{$version}
HTML;
        if ($author !== '') {
            echo ' · ' . $author;
        }
        echo <<<HTML
        </small>
        {$run_btn}
      </div>
    </div>
  </div>
</div>
HTML;
    }
    echo '</div>';
}

// ── Migration history ─────────────────────────────────────────────────────────
$all_statuses = MigrationService::getAllStatuses();
if (!empty($all_statuses)) {
    echo '<h5 class="mt-4 mb-3">Migration History</h5>';
    echo '<div class="table-responsive"><table class="table table-sm table-bordered align-middle">';
    echo '<thead class="table-light"><tr>'
        . '<th>Source</th><th>Imported At</th>'
        . '<th class="text-end">Categories</th><th class="text-end">Albums</th><th class="text-end">Images</th>'
        . '<th>Plugin Version</th>'
        . '</tr></thead><tbody>';
    foreach ($all_statuses as $s) {
        $src   = h($s['source']         ?? '');
        $date  = h($s['imported_at']    ?? '');
        $ncat  = number_format((int) ($s['categories']    ?? 0));
        $nalb  = number_format((int) ($s['albums']        ?? 0));
        $nimg  = number_format((int) ($s['images']        ?? 0));
        $pver  = h($s['plugin_version'] ?? '');
        echo "<tr><td><code>{$src}</code></td><td>{$date}</td>"
            . "<td class=\"text-end\">{$ncat}</td><td class=\"text-end\">{$nalb}</td><td class=\"text-end\">{$nimg}</td>"
            . "<td>{$pver}</td></tr>";
    }
    echo '</tbody></table></div>';
}

// ── DB version notice ─────────────────────────────────────────────────────────
// Migration tables were added in DB v6; warn on older installs.
if (LUMORA_DB_VERSION > 5 && MigrationService::getMigrationStatus('__probe') === null
    && !@(function_exists('\\LumoraDB::pdo') && (function () {
        try { LumoraDB::pdo()->query('SELECT 1 FROM `' . LumoraDB::prefix() . 'migration_status` LIMIT 0'); return true; }
        catch (\Throwable) { return false; }
    })())
) {
    // Silently skip — getMigrationStatus already handles missing table gracefully
}

$content = ob_get_clean();
lum_admin_page('Import', $content, 'migrate');
