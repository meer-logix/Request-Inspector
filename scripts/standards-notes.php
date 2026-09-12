<?php
// Narrow annotations for reviewed diagnostic operations, not blanket exclusions.
$root = dirname(__DIR__) . '/request-inspector/';
$rules = array(
    'includes/class-api.php' => array(
        "SELECT GET_LOCK" => 'WordPress.DB.DirectDatabaseQuery -- A live database lock serializes settings updates across workers; caching is incorrect.',
        "SELECT RELEASE_LOCK" => 'WordPress.DB.DirectDatabaseQuery -- Release the connection-owned settings lock.',
        '$remaining = (int) $wpdb->get_var' => 'WordPress.DB.DirectDatabaseQuery -- Read current purge progress from plugin-owned tables.',
        "get_var( 'SELECT 1' )" => 'WordPress.DB.DirectDatabaseQuery -- Explicit administrator-triggered diagnostic fixture must execute a real query.',
    ),
    'includes/class-attribution.php' => array(
        'debug_backtrace(' => 'WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Diagnostic attribution is bounded and excludes all callback arguments.',
    ),
    'includes/class-recorder.php' => array(
        'set_error_handler(' => 'WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Opt-in PHP collector preserves the previously registered handler.',
        'error_reporting() &' => 'WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting,PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall -- Reads the existing mask without modifying reporting or display settings.',
    ),
    'uninstall.php' => array(
        'DROP TABLE IF EXISTS' => 'WordPress.DB.DirectDatabaseQuery -- Remove only plugin-owned tables after explicit uninstall deletion consent.',
    ),
);
foreach ($rules as $file => $matches) {
    $lines = file($root . $file);
    foreach ($lines as &$line) {
        foreach ($matches as $needle => $reason) {
            if (str_contains($line, $needle) && !str_contains($line, 'phpcs:ignore')) {
                $line = rtrim($line) . ' // phpcs:ignore ' . $reason . "\n";
            }
        }
    }
    unset($line);
    file_put_contents($root . $file, implode('', $lines));
}
