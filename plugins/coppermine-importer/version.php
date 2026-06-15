<?php
declare(strict_types=1);
/**
 * Coppermine Importer — Version Constants
 *
 * This is the single source of truth for the plugin version.
 * Update LUMORA_CPG_IMPORTER_VERSION here; all other files reference
 * this constant instead of hardcoding a version string.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

/** Importer plugin version. Update only when releasing a new plugin version. */
define('LUMORA_CPG_IMPORTER_VERSION',     '1.0.0');

/** Minimum Lumora Gallery version required to run this plugin. */
define('LUMORA_CPG_IMPORTER_MIN_LUMORA',  '1.5.0');

/** Source identifier used in migration_status and migration_log tables. */
define('LUMORA_CPG_IMPORTER_SOURCE',      'coppermine');

/** Number of categories processed per AJAX chunk. */
define('LUMORA_CPG_IMPORTER_CAT_CHUNK',  100);

/** Number of albums processed per AJAX chunk. */
define('LUMORA_CPG_IMPORTER_ALB_CHUNK',  100);

/** Number of images processed per AJAX chunk. */
define('LUMORA_CPG_IMPORTER_IMG_CHUNK',  500);
