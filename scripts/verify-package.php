<?php
/** Inspect and load the actual packaged plugin in the isolated local fixture. */
$root = dirname(__DIR__);
$zip = new ZipArchive();
$dockChunks = 0;
if (true !== $zip->open($root . '/dist/request-inspector-0.10.2.zip')) throw new RuntimeException('Cannot open release ZIP');
for ($i = 0; $i < $zip->numFiles; ++$i) {
    $name = $zip->getNameIndex($i);
    if (preg_match('~^request-inspector/admin/build/ri-live-dock\.[a-f0-9]+\.js$~', $name)) ++$dockChunks;
    if (!str_starts_with($name, 'request-inspector/') || str_contains($name, '..') || str_contains($name, '\\')) throw new RuntimeException('Invalid archive path');
}
foreach (['request-inspector.php','readme.txt','LICENSE.txt','admin/build/index.js','admin/build/index.asset.php','admin/build/live.js','admin/build/live.asset.php','admin/build/live.css','admin/build/live-rtl.css','admin/src/live/dock.tsx','admin/webpack.config.js','admin/src/index.tsx','admin/package-lock.json','asserts/logo.png'] as $file) {
    if (false === $zip->locateName('request-inspector/' . $file)) throw new RuntimeException('Missing: ' . $file);
}
if (1 !== $dockChunks) throw new RuntimeException('Expected exactly one current lazy dock chunk');
foreach (['ri-live-dock.css', 'ri-live-dock-rtl.css'] as $file) {
    if (false === $zip->locateName('request-inspector/admin/build/' . $file)) throw new RuntimeException('Missing lazy stylesheet');
}
$destination = $root . '/.runtime/packaged-0.10.2';
if (!is_dir($destination)) mkdir($destination);
if (!$zip->extractTo($destination)) throw new RuntimeException('Extraction failed');
$zip->close();
define('WP_PLUGIN_DIR', $destination);
$_SERVER['HTTP_HOST'] = '127.0.0.1:18880';
require $root . '/.runtime/wordpress/wp-load.php';
if (realpath(REQUEST_INSPECTOR_FILE) !== realpath($destination . '/request-inspector/request-inspector.php')) throw new RuntimeException('Loaded source instead of ZIP');
if ('0.10.2' !== REQUEST_INSPECTOR_VERSION) throw new RuntimeException('Unexpected version');
wp_set_current_user(1);
$response = rest_do_request(new WP_REST_Request('GET', '/request-inspector/v1/settings'));
if (200 !== $response->get_status()) throw new RuntimeException('Packaged plugin API unavailable');
$live = rest_do_request(new WP_REST_Request('GET', '/request-inspector/v1/live/preferences'));
if (200 !== $live->get_status() || !array_key_exists('schema_ready', $live->get_data())) throw new RuntimeException('Packaged live integration unavailable');
echo "PASS: ZIP paths, required assets/source/licenses, extracted plugin bootstrap and authenticated settings API.\n";
