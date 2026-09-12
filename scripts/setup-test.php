<?php
/** Isolated local WordPress installation. Never load this in the distributable plugin. */
$root = dirname(__DIR__);
$db = new mysqli('127.0.0.1', 'root', 'ri_test_only', '', 19306);
$db->query('CREATE DATABASE IF NOT EXISTS request_inspector_test');
file_put_contents($root . '/.runtime/wordpress/wp-config.php', "<?php\ndefine('DB_NAME','request_inspector_test');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','ri_test_only');\ndefine('DB_HOST','127.0.0.1:19306');\ndefine('DB_CHARSET','utf8mb4');\ndefine('DB_COLLATE','');\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\ndefine('SAVEQUERIES',true);\ndefine('DISABLE_WP_CRON',true);\ndefine('AUTH_KEY','ri-local-auth-key-not-for-production');\ndefine('SECURE_AUTH_KEY','ri-local-secure-key-not-for-production');\ndefine('LOGGED_IN_KEY','ri-local-logged-key-not-for-production');\ndefine('NONCE_KEY','ri-local-nonce-key-not-for-production');\ndefine('AUTH_SALT','ri-local-auth-salt');\ndefine('SECURE_AUTH_SALT','ri-local-secure-salt');\ndefine('LOGGED_IN_SALT','ri-local-logged-salt');\ndefine('NONCE_SALT','ri-local-nonce-salt');\n\$table_prefix='riwp_';\ndefine('ABSPATH',__DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n");
define('WP_INSTALLING',true);
require $root . '/.runtime/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) {
 wp_install('Request Inspector Test', 'ri_admin', 'local-test@example.invalid', false, '', 'ri-local-test-password');
}
update_option('siteurl', 'http://127.0.0.1:18880');
update_option('home', 'http://127.0.0.1:18880');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$error = activate_plugin('request-inspector/request-inspector.php');
if (is_wp_error($error)) {throw new RuntimeException($error->get_error_message());}
echo "Isolated WordPress installed and plugin activated.\n";
