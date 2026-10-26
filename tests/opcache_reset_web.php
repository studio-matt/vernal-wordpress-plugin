<?php
/**
 * Deploy recovery: OPcache/status probe + one-shot mu-plugin installer.
 *
 * Writes mu-plugin to wp-content/mu-plugins/ (NOT plugins/mu-plugins).
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

$base = dirname(__DIR__); // .../wp-content/plugins/vernal-contentum
$wp_content = dirname($base, 2); // .../wp-content  (NOT dirname once — that is plugins/)
if (!is_dir($wp_content) || basename($wp_content) !== 'wp-content') {
    // Fallback for older PHP without dirname levels / odd layouts.
    $wp_content = dirname(dirname($base));
}
$mu_dir = $wp_content . '/mu-plugins';
$mu_file = $mu_dir . '/vernal-opcache-recover.php';
$diag_file = $wp_content . '/uploads/vernal-opcache-recover-diag.json';

$paths = array(
    $base . '/vernal-contentum.php',
    $base . '/includes/class-vernal-api.php',
    $base . '/includes/class-vernal-internal-links.php',
    $base . '/includes/class-vernal-internal-link-inserter.php',
);

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';

if ($action === 'install_mu') {
    if (!is_dir($mu_dir)) {
        if (!@mkdir($mu_dir, 0755, true) && !is_dir($mu_dir)) {
            echo "mu_mkdir_failed\n";
            echo "mu_dir=" . $mu_dir . "\n";
            exit;
        }
    }
    $mu = <<<'PHP'
<?php
/**
 * One-shot Vernal runtime identity + OPcache recovery. Self-deletes after plugins_loaded.
 */
add_action('plugins_loaded', function () {
    $reset = false;
    if (function_exists('opcache_reset')) {
        $reset = (bool) @opcache_reset();
    }
    $n = 0;
    if (function_exists('opcache_invalidate') && defined('WP_PLUGIN_DIR')) {
        foreach (array(
            WP_PLUGIN_DIR . '/vernal-contentum/vernal-contentum.php',
            WP_PLUGIN_DIR . '/vernal-contentum/includes/class-vernal-api.php',
            WP_PLUGIN_DIR . '/vernal-contentum/includes/class-vernal-internal-links.php',
            WP_PLUGIN_DIR . '/vernal-contentum/includes/class-vernal-internal-link-inserter.php',
        ) as $t) {
            if (is_string($t) && file_exists($t) && @opcache_invalidate($t, true)) {
                $n++;
            }
        }
    }

    $active = array();
    if (function_exists('get_option')) {
        $raw = get_option('active_plugins', array());
        if (is_array($raw)) {
            $active = $raw;
        }
    }

    $boot = defined('WP_PLUGIN_DIR') ? (WP_PLUGIN_DIR . '/vernal-contentum/vernal-contentum.php') : '';
    $file_ver = null;
    if (is_string($boot) && is_readable($boot)) {
        $raw = file_get_contents($boot);
        if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
            $file_ver = $m[1];
        }
    }

    $diag = array(
        'ts' => gmdate('c'),
        'runtime_version' => defined('VERNAL_CONTENTUM_VERSION') ? VERNAL_CONTENTUM_VERSION : null,
        'file_version' => $file_ver,
        'plugin_dir' => defined('VERNAL_CONTENTUM_PLUGIN_DIR') ? VERNAL_CONTENTUM_PLUGIN_DIR : null,
        'plugin_basename' => defined('VERNAL_CONTENTUM_PLUGIN_BASENAME') ? VERNAL_CONTENTUM_PLUGIN_BASENAME : null,
        'active_plugins' => $active,
        'opcache_reset' => $reset ? 1 : 0,
        'opcache_invalidate_n' => $n,
        'opcache_enabled' => (function_exists('opcache_get_status') && ($st = @opcache_get_status(false)) && !empty($st['opcache_enabled'])) ? 1 : 0,
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'worker' => getmypid(),
    );

    if (!headers_sent()) {
        header('X-Vernal-Runtime-Version: ' . (string) ($diag['runtime_version'] ?: 'none'));
        header('X-Vernal-File-Version: ' . (string) ($diag['file_version'] ?: 'none'));
        header('X-Vernal-Opcache-Reset: ' . (string) $diag['opcache_reset']);
    }

    $uploads = defined('WP_CONTENT_DIR') ? (WP_CONTENT_DIR . '/uploads/vernal-opcache-recover-diag.json') : '';
    if (is_string($uploads) && $uploads !== '') {
        @file_put_contents($uploads, wp_json_encode($diag));
    }

    $self = __FILE__;
    if (is_string($self) && strpos($self, 'vernal-opcache-recover.php') !== false) {
        @unlink($self);
    }
}, 0);
PHP;
    $ok = @file_put_contents($mu_file, $mu);
    echo "ok\n";
    echo "action=install_mu\n";
    echo "write=" . ($ok !== false ? '1' : '0') . "\n";
    echo "bytes=" . ($ok !== false ? (int) $ok : 0) . "\n";
    echo "mu_file=" . $mu_file . "\n";
    echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
    echo "wp_content=" . $wp_content . "\n";
    exit;
}

if ($action === 'remove_mu') {
    $removed = false;
    if (file_exists($mu_file)) {
        $removed = @unlink($mu_file);
    }
    // Also remove mistaken path from 1.5.39
    $wrong = dirname($base) . '/mu-plugins/vernal-opcache-recover.php';
    if (file_exists($wrong)) {
        @unlink($wrong);
    }
    echo "ok\n";
    echo "action=remove_mu\n";
    echo "removed=" . ($removed ? '1' : '0') . "\n";
    echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
    exit;
}

if ($action === 'read_diag') {
    echo "ok\n";
    echo "action=read_diag\n";
    if (is_readable($diag_file)) {
        echo "diag=" . trim((string) file_get_contents($diag_file)) . "\n";
    } else {
        echo "diag=\n";
        echo "diag_exists=0\n";
    }
    exit;
}

$cfg = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : null;
$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$restrict = null;
if (is_array($cfg) && isset($cfg['directives']['opcache.restrict_api'])) {
    $restrict = $cfg['directives']['opcache.restrict_api'];
}

$reset = false;
if (function_exists('opcache_reset')) {
    $reset = (bool) @opcache_reset();
}

$invalidated = 0;
if (function_exists('opcache_invalidate')) {
    foreach ($paths as $p) {
        $real = realpath($p);
        $target = ($real !== false) ? $real : $p;
        if (is_string($target) && $target !== '' && file_exists($target) && @opcache_invalidate($target, true)) {
            $invalidated++;
        }
    }
}

$ver = null;
$const_in_file = null;
$boot = $base . '/vernal-contentum.php';
$boot_sha = null;
$boot_size = null;
$boot_mtime = null;
if (is_readable($boot)) {
    $raw = file_get_contents($boot);
    if (is_string($raw)) {
        $boot_sha = hash('sha256', $raw);
        $boot_size = strlen($raw);
        $boot_mtime = @filemtime($boot);
        if (preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
            $ver = $m[1];
        }
        if (preg_match("/define\\(\\s*'VERNAL_CONTENTUM_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $raw, $m2)) {
            $const_in_file = $m2[1];
        }
    }
}

$api = $base . '/includes/class-vernal-api.php';
$api_has_process = null;
$api_size = null;
if (is_readable($api)) {
    $api_raw = file_get_contents($api);
    if (is_string($api_raw)) {
        $api_size = strlen($api_raw);
        $api_has_process = (strpos($api_raw, 'process-source') !== false) ? 1 : 0;
    }
}

echo "ok\n";
echo "action=status\n";
echo "opcache_reset=" . ($reset ? '1' : '0') . "\n";
echo "file_version=" . ($ver !== null ? $ver : 'unknown') . "\n";
echo "const_in_file=" . ($const_in_file !== null ? $const_in_file : 'unknown') . "\n";
echo "worker=" . getmypid() . "\n";
echo "invalidated=" . $invalidated . "\n";
echo "opcache_enabled=" . (is_array($status) && !empty($status['opcache_enabled']) ? '1' : '0') . "\n";
echo "restrict_api=" . ($restrict === null || $restrict === '' ? '(empty)' : $restrict) . "\n";
echo "validate_timestamps=" . (is_array($cfg) && isset($cfg['directives']['opcache.validate_timestamps']) ? (int) $cfg['directives']['opcache.validate_timestamps'] : -1) . "\n";
echo "revalidate_freq=" . (is_array($cfg) && isset($cfg['directives']['opcache.revalidate_freq']) ? (int) $cfg['directives']['opcache.revalidate_freq'] : -1) . "\n";
echo "boot_sha16=" . ($boot_sha ? substr($boot_sha, 0, 16) : 'none') . "\n";
echo "boot_size=" . ($boot_size !== null ? $boot_size : 'none') . "\n";
echo "boot_mtime=" . ($boot_mtime !== null ? $boot_mtime : 'none') . "\n";
echo "api_size=" . ($api_size !== null ? $api_size : 'none') . "\n";
echo "api_has_process=" . ($api_has_process === null ? 'none' : $api_has_process) . "\n";
echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
echo "mu_file=" . $mu_file . "\n";
echo "wp_content=" . $wp_content . "\n";
echo "sapi=" . PHP_SAPI . "\n";
echo "php=" . PHP_VERSION . "\n";
echo "script_filename=" . (isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') . "\n";
echo "realpath_boot=" . (($rb = realpath($boot)) ? $rb : 'false') . "\n";
