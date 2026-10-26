<?php
/**
 * Deploy recovery: reset OPcache in the current PHP worker.
 *
 * Hit this URL many times in parallel after a self-update that wrote files but
 * left workers serving stale bytecode (MH 2026-10 empty-500 case).
 *
 * Remove or rotate the token after recovery. Not loaded by WordPress.
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

$reset = false;
$invalidated = array();
if (function_exists('opcache_reset')) {
    $reset = (bool) opcache_reset();
}
$base = dirname(__DIR__);
$paths = array(
    $base . '/vernal-contentum.php',
    $base . '/includes/class-vernal-api.php',
    $base . '/includes/class-vernal-internal-links.php',
    $base . '/includes/class-vernal-internal-link-inserter.php',
);
if (function_exists('opcache_invalidate')) {
    foreach ($paths as $p) {
        if (is_string($p) && $p !== '' && file_exists($p)) {
            $invalidated[$p] = (bool) opcache_invalidate($p, true);
        }
    }
}

$ver = null;
$boot = $base . '/vernal-contentum.php';
if (is_readable($boot)) {
    $raw = file_get_contents($boot);
    if (is_string($raw) && preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $raw, $m)) {
        $ver = $m[1];
    }
}

echo "ok\n";
echo "opcache_reset=" . ($reset ? '1' : '0') . "\n";
echo "file_version=" . ($ver !== null ? $ver : 'unknown') . "\n";
echo "worker=" . getmypid() . "\n";
echo "invalidated=" . count(array_filter($invalidated)) . "\n";
