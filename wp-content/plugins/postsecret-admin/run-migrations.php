<?php
/**
 * Manual migration runner for PostSecret Admin.
 *
 * Run this file directly to execute all pending migrations,
 * or pass ?only=<slug[,slug2,...]> to run specific ones.
 *
 * @package PostSecret\Admin
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

if (!current_user_can('manage_options') && !defined('WP_CLI')) {
    wp_die('Unauthorized');
}

echo "<h1>PostSecret Database Migrations</h1>\n";

$migrations_dir = __DIR__ . '/migrations/';
$migrations = glob($migrations_dir . '*.php');
sort($migrations);

/**
 * Turn a filename (e.g., "002_facets.php") into a safe slug "002_facets".
 */
$slugify = static function (string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    return preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($base));
};

/**
 * Optional filter: ?only=003_embeddings or ?only=001_init,003_embeddings.php
 * Accepts slugs (without .php) or exact filenames; case-insensitive.
 */
$only_raw = isset($_GET['only']) ? trim((string)$_GET['only']) : '';
if ($only_raw !== '') {
    $only_set = [];
    foreach (preg_split('/\s*,\s*/', $only_raw, -1, PREG_SPLIT_NO_EMPTY) as $piece) {
        $p = strtolower($piece);
        // accept either "003_embeddings" or "003_embeddings.php"
        $only_set[$p] = true;
        if (substr($p, -4) !== '.php') {
            $only_set[$p . '.php'] = true;
        } else {
            $only_set[substr($p, 0, -4)] = true; // slug form
        }
        // also accept slugified variant of whatever was passed
        $only_set[$slugify($p)] = true;
    }

    $migrations = array_values(array_filter($migrations, function ($file) use ($only_set, $slugify) {
        $base = strtolower(basename($file));   // e.g. 003_embeddings.php
        $slug = strtolower($slugify($base));   // e.g. 003_embeddings
        return isset($only_set[$base]) || isset($only_set[$slug]);
    }));

    if (empty($migrations)) {
        echo "<p><em>No migrations matched <code>" . esc_html($only_raw) . "</code>.</em></p>";
        echo "<p><a href='" . esc_url(admin_url()) . "'>← Back to Dashboard</a></p>";
        exit;
    }

    echo "<p><strong>Filtered run:</strong> "
        . esc_html(implode(', ', array_map('basename', $migrations)))
        . "</p>";
}

foreach ($migrations as $migration_file) {
    $migration_name = basename($migration_file);
    $slug = $slugify($migration_name);

    // Preferred function name inside the migration file
    $preferred_fn = "\\PostSecret\\Admin\\Migrations\\up_{$slug}";
    $legacy_fn = "\\PostSecret\\Admin\\Migrations\\up";

    echo "<p>Running migration: <strong>" . esc_html($migration_name) . "</strong>...";

    $result = (static function (string $file, string $preferred_fn, string $legacy_fn) {
        ob_start();
        try {
            // Include once and capture any return (for closure-based migrations)
            /** @var mixed $maybe_callable */
            $maybe_callable = (static function ($f) {
                return include $f;
            })($file);

            // Preferred: function up_<slug>()
            if (function_exists($preferred_fn)) {
                $preferred_fn();
            } // Legacy: function up()
            elseif (function_exists($legacy_fn)) {
                echo "[notice] Using legacy migration function `{$legacy_fn}`. Consider renaming to `{$preferred_fn}`.\n";
                $legacy_fn();
            } // Closure-based: migration file returned a callable
            elseif (is_callable($maybe_callable)) {
                $maybe_callable();
            } else {
                throw new \RuntimeException(
                    "No callable migration found. Expected {$preferred_fn}(), {$legacy_fn}(), or a returned closure."
                );
            }

            $output = ob_get_clean();
            return ['success' => true, 'output' => $output];
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            return ['success' => false, 'error' => $e->getMessage(), 'output' => $output];
        }
    })($migration_file, $preferred_fn, $legacy_fn);

    if ($result['success']) {
        echo " <span style='color:green;'>✓ Success</span></p>\n";
        if (!empty($result['output'])) {
            echo "<pre>" . esc_html($result['output']) . "</pre>\n";
        }
    } else {
        echo " <span style='color:red;'>✗ Failed</span></p>\n";
        echo "<p style='color:red;'>Error: " . esc_html($result['error']) . "</p>\n";
        if (!empty($result['output'])) {
            echo "<pre>" . esc_html($result['output']) . "</pre>\n";
        }
        // Bail on first failure so you can fix and re-run.
        break;
    }
}

echo "<p><strong>Migrations complete!</strong></p>\n";
echo "<p><a href='" . esc_url(admin_url()) . "'>← Back to Dashboard</a></p>\n";