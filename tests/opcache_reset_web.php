<?php
/**
 * Deploy recovery helpers for MH mis-activated plugin directory.
 *
 * Root cause (2026-10): active plugin was
 *   vernal-wordpress-plugin-main/vernal-contentum.php (1.5.33)
 * while self-update wrote
 *   vernal-contentum/ (1.5.37+).
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

$base = dirname(__DIR__); // .../plugins/vernal-contentum (this tree)
$plugins_dir = dirname($base);
$wp_content = dirname($plugins_dir);
$mu_dir = $wp_content . '/mu-plugins';
$mu_file = $mu_dir . '/vernal-opcache-recover.php';
$diag_file = $wp_content . '/uploads/vernal-opcache-recover-diag.json';
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';

function vernal_probe_copy_tree($src, $dst) {
    if (!is_dir($src)) {
        return array('ok' => false, 'error' => 'src_missing');
    }
    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return array('ok' => false, 'error' => 'dst_mkdir_failed');
        }
    }
    $copied = 0;
    $failed = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0755, true)) {
                $failed++;
            }
            continue;
        }
        if (@copy($item->getPathname(), $target)) {
            $copied++;
        } else {
            $failed++;
        }
    }
    return array('ok' => $failed === 0, 'copied' => $copied, 'failed' => $failed);
}

if ($action === 'install_mu') {
    if (!is_dir($mu_dir) && !@mkdir($mu_dir, 0755, true) && !is_dir($mu_dir)) {
        echo "mu_mkdir_failed\nmu_dir={$mu_dir}\n";
        exit;
    }
    $mu = <<<'PHP'
<?php
/**
 * Switch MH from stale GitHub-folder install to canonical vernal-contentum/.
 * Runs at mu-plugin include time (before regular plugins load), then self-deletes.
 */
$old = 'vernal-wordpress-plugin-main/vernal-contentum.php';
$new = 'vernal-contentum/vernal-contentum.php';
$new_abs = (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (WP_CONTENT_DIR . '/plugins')) . '/' . $new;
$active = get_option('active_plugins', array());
if (!is_array($active)) {
    $active = array();
}
$before = $active;
$changed = false;
if (in_array($old, $active, true)) {
    $active = array_values(array_filter($active, function ($p) use ($old) {
        return $p !== $old;
    }));
    $changed = true;
}
if (file_exists($new_abs) && !in_array($new, $active, true)) {
    $active[] = $new;
    $changed = true;
}
if ($changed) {
    update_option('active_plugins', $active);
}

$boot = dirname($new_abs);
$file_ver = null;
if (is_readable($new_abs)) {
    $raw = file_get_contents($new_abs);
    if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
        $file_ver = $m[1];
    }
}
$diag = array(
    'ts' => gmdate('c'),
    'action' => 'switch_active',
    'changed' => $changed ? 1 : 0,
    'before' => $before,
    'after' => $active,
    'file_version_canonical' => $file_ver,
    'canonical_exists' => file_exists($new_abs) ? 1 : 0,
    'worker' => getmypid(),
);
$diag_path = WP_CONTENT_DIR . '/uploads/vernal-opcache-recover-diag.json';
@file_put_contents($diag_path, function_exists('wp_json_encode') ? wp_json_encode($diag) : json_encode($diag));
if (!headers_sent()) {
    header('X-Vernal-Switch-Active: ' . ($changed ? '1' : '0'));
    header('X-Vernal-File-Version: ' . (string) ($file_ver ?: 'none'));
}
@unlink(__FILE__);
PHP;
    $ok = @file_put_contents($mu_file, $mu);
    echo "ok\naction=install_mu\nwrite=" . ($ok !== false ? '1' : '0') . "\n";
    echo "mu_file={$mu_file}\nmu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
    exit;
}

if ($action === 'sync_active') {
    $dst = $plugins_dir . '/vernal-wordpress-plugin-main';
    $result = vernal_probe_copy_tree($base, $dst);
    $dst_boot = $dst . '/vernal-contentum.php';
    $ver = null;
    if (is_readable($dst_boot)) {
        $raw = file_get_contents($dst_boot);
        if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
            $ver = $m[1];
        }
    }
    echo "ok\naction=sync_active\n";
    echo "src={$base}\n";
    echo "dst={$dst}\n";
    echo "copied=" . (isset($result['copied']) ? (int) $result['copied'] : 0) . "\n";
    echo "failed=" . (isset($result['failed']) ? (int) $result['failed'] : 0) . "\n";
    echo "dst_version=" . ($ver !== null ? $ver : 'unknown') . "\n";
    echo "ok_flag=" . (!empty($result['ok']) ? '1' : '0') . "\n";
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
$const = null;
if (is_readable($boot)) {
    $raw = file_get_contents($boot);
    if (is_string($raw)) {
        if (preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
            $ver = $m[1];
        }
        if (preg_match("/define\\(\\s*'VERNAL_CONTENTUM_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $raw, $m2)) {
            $const = $m2[1];
        }
    }
}
$stale = $plugins_dir . '/vernal-wordpress-plugin-main/vernal-contentum.php';
$stale_ver = null;
if (is_readable($stale)) {
    $raw = file_get_contents($stale);
    if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
        $stale_ver = $m[1];
    }
}
$api = $base . '/includes/class-vernal-api.php';
echo "ok\naction=status\n";
echo "canonical_version=" . ($ver !== null ? $ver : 'unknown') . "\n";
echo "canonical_const=" . ($const !== null ? $const : 'unknown') . "\n";
echo "stale_dir_version=" . ($stale_ver !== null ? $stale_ver : 'missing') . "\n";
echo "api_has_process=" . (is_readable($api) && strpos((string) file_get_contents($api), 'process-source') !== false ? '1' : '0') . "\n";
echo "mu_exists=" . (file_exists($mu_file) ? '1' : '0') . "\n";
echo "mu_file={$mu_file}\n";
echo "wp_content={$wp_content}\n";
echo "sapi=" . PHP_SAPI . "\n";
echo "php=" . PHP_VERSION . "\n";
echo "worker=" . getmypid() . "\n";
