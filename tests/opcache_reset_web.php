<?php
/**
 * Deploy recovery helpers for MH dual-folder install.
 *
 * Token-gated. Remove after recovery.
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$token = isset($_GET['k']) ? (string) $_GET['k'] : '';
$expect = 'vernal_opcache_recover_mh_20261025';
if ($token === '' || !hash_equals($expect, $token)) {
    http_response_code(404);
    echo "not_found\n";
    exit;
}

$base = dirname(__DIR__); // .../plugins/vernal-contentum
$plugins_dir = dirname($base);
$wp_content = dirname($plugins_dir);
$mu_dir = $wp_content . '/mu-plugins';
$mu_file = $mu_dir . '/vernal-opcache-recover.php';
$diag_file = $wp_content . '/uploads/vernal-opcache-recover-diag.json';
$stale_dir = $plugins_dir . '/vernal-wordpress-plugin-main';
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';

if ($action === 'disable_duplicate') {
    $renamed = null;
    $rename_ok = false;
    if (is_dir($stale_dir)) {
        $renamed = $stale_dir . '.disabled-' . gmdate('Ymd-His');
        $rename_ok = @rename($stale_dir, $renamed);
        if (!$rename_ok) {
            // Fallback: neuter the bootstrap so WP cannot load it.
            $boot = $stale_dir . '/vernal-contentum.php';
            if (is_writable($boot) || is_writable($stale_dir)) {
                $neuter = "<?php\n/* Disabled duplicate Vernal install — use plugins/vernal-contentum/ */\nreturn;\n";
                $rename_ok = @file_put_contents($boot, $neuter) !== false;
                $renamed = $boot . ' (neutered)';
            }
        }
    } else {
        $rename_ok = true;
        $renamed = '(absent)';
    }

    if (!is_dir($mu_dir)) {
        @mkdir($mu_dir, 0755, true);
    }
    $mu = <<<'PHP'
<?php
/**
 * Keep only canonical vernal-contentum/ active. Self-deletes.
 */
$canonical = 'vernal-contentum/vernal-contentum.php';
$plugins_root = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (WP_CONTENT_DIR . '/plugins');
$canonical_abs = $plugins_root . '/' . $canonical;
$active = get_option('active_plugins', array());
if (!is_array($active)) {
    $active = array();
}
$before = $active;
$active = array_values(array_filter($active, function ($p) use ($canonical) {
    if (!is_string($p)) {
        return false;
    }
    // Drop GitHub-folder duplicate and any other non-canonical Vernal bootstraps.
    if ($p === $canonical) {
        return true;
    }
    if (strpos($p, 'vernal-wordpress-plugin') !== false) {
        return false;
    }
    if (strpos($p, 'vernal-contentum/') !== false && $p !== $canonical) {
        return false;
    }
    return true;
}));
if (file_exists($canonical_abs) && !in_array($canonical, $active, true)) {
    $active[] = $canonical;
}
$changed = ($active !== $before);
if ($changed) {
    update_option('active_plugins', $active);
}
$diag = array(
    'ts' => gmdate('c'),
    'action' => 'dedupe_active',
    'changed' => $changed ? 1 : 0,
    'before' => $before,
    'after' => $active,
    'canonical_exists' => file_exists($canonical_abs) ? 1 : 0,
);
@file_put_contents(
    WP_CONTENT_DIR . '/uploads/vernal-opcache-recover-diag.json',
    function_exists('wp_json_encode') ? wp_json_encode($diag) : json_encode($diag)
);
if (!headers_sent()) {
    header('X-Vernal-Dedupe: ' . ($changed ? '1' : '0'));
}
@unlink(__FILE__);
PHP;
    $mu_ok = @file_put_contents($mu_file, $mu) !== false;

    echo "ok\naction=disable_duplicate\n";
    echo "stale_was_dir=" . (is_dir($stale_dir) ? '1' : '0') . "\n";
    echo "rename_ok=" . ($rename_ok ? '1' : '0') . "\n";
    echo "renamed_to=" . (string) $renamed . "\n";
    echo "mu_write=" . ($mu_ok ? '1' : '0') . "\n";
    echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
    exit;
}

if ($action === 'install_mu') {
    // Back-compat alias
    $_GET['action'] = 'disable_duplicate';
    // fall through by re-including logic — just call same block via redirect style
    echo "ok\naction=install_mu_alias\n";
    echo "use=disable_duplicate\n";
    exit;
}

if ($action === 'remove_mu') {
    $removed = file_exists($mu_file) ? @unlink($mu_file) : false;
    $wrong = $plugins_dir . '/mu-plugins/vernal-opcache-recover.php';
    if (file_exists($wrong)) {
        @unlink($wrong);
    }
    echo "ok\naction=remove_mu\nremoved=" . ($removed ? '1' : '0') . "\n";
    echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
    exit;
}

if ($action === 'read_diag') {
    echo "ok\naction=read_diag\n";
    if (is_readable($diag_file)) {
        echo 'diag=' . trim((string) file_get_contents($diag_file)) . "\n";
    } else {
        echo "diag=\ndiag_exists=0\n";
    }
    exit;
}

// status
$boot = $base . '/vernal-contentum.php';
$ver = null;
if (is_readable($boot)) {
    $raw = file_get_contents($boot);
    if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
        $ver = $m[1];
    }
}
$stale_ver = null;
$stale_boot = $stale_dir . '/vernal-contentum.php';
if (is_readable($stale_boot)) {
    $raw = file_get_contents($stale_boot);
    if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
        $stale_ver = $m[1];
    }
}
echo "ok\naction=status\n";
echo "canonical_version=" . ($ver !== null ? $ver : 'unknown') . "\n";
echo "stale_dir_present=" . (is_dir($stale_dir) ? '1' : '0') . "\n";
echo "stale_dir_version=" . ($stale_ver !== null ? $stale_ver : 'missing') . "\n";
echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
echo "php=" . PHP_VERSION . "\n";
echo "worker=" . getmypid() . "\n";
