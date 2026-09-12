<?php
define('ABSPATH', dirname(__DIR__) . '/.runtime/wordpress/');
define('WPINC', 'wp-includes');
require dirname(__DIR__) . '/vendor/autoload.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
function wp_check_invalid_utf8($text, $strip = false) { return mb_convert_encoding($text,'UTF-8','UTF-8'); }
function wp_json_encode($value, $flags = 0) { return json_encode($value,$flags); }
function wp_parse_url($url) { return parse_url($url); }
function __($text, $domain = '') { return $text; }
function do_action($hook, ...$args) {}
function get_option($name, $default = false) { return $GLOBALS['ri_options'][$name] ?? $default; }
function sanitize_text_field($value) { return strip_tags($value); }
require dirname(__DIR__) . '/request-inspector/includes/class-redactor.php';
require dirname(__DIR__) . '/request-inspector/includes/class-settings.php';
require dirname(__DIR__) . '/request-inspector/includes/class-policy.php';
