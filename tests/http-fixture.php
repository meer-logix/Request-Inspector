<?php
// Synthetic transport fixture, never included in the plugin package.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ('/redirect' === $path) {
    header('Location: /echo');
    exit;
}
if ('/empty' === $path) {
    http_response_code(204);
    exit;
}
if ('/failure' === $path) {
    http_response_code(503);
}
header('Content-Type: application/json');
echo json_encode(array('result' => 'fixture', 'token' => 'HTTP_SENTINEL', 'method' => $_SERVER['REQUEST_METHOD']));
