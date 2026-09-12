<?php
/** Actual WordPress/database/transport assertions against the isolated test site. */
$_SERVER['HTTP_HOST'] = '127.0.0.1:18880';
$_SERVER['REQUEST_URI'] = '/integration?token=URL_SENTINEL';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('WP_ENVIRONMENT_TYPE', 'staging');
require (getenv('RI_WORDPRESS_PATH') ?: dirname(__DIR__) . '/.runtime/wordpress') . '/wp-load.php';
use RequestInspector\Settings;
use RequestInspector\Storage;
use RequestInspector\Recorder;
$checks = 0;
function check($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
$settings = array_merge(Settings::defaults(), array('enabled' => true, 'mode' => 'all', 'database' => true, 'hooks' => true, 'request_body' => true, 'response_body' => true, 'stack_traces' => true));
update_option(Settings::OPTION, $settings);
$storage = new Storage();
delete_option('request_inspector_scopes');
$storage->install();
$storage->purge();
$recorder = new Recorder($settings);
check($recorder->register(), 'Recorder registration');
add_filter('ri_fixture_filter', fn($value) => $value . '-preserved', 10, 1);
check('value-preserved' === apply_filters('ri_fixture_filter', 'value'), 'Hook observation preserves filter output');
do_action('ri_fixture_action');
do_action('ri_fixture_action');
$wpdb->get_var("SELECT 'SQL_SENTINEL'");
$wpdb->get_var("SELECT 'SQL_SENTINEL'");
foreach (array('/echo', '/failure', '/redirect', '/empty') as $path) {
    $response = wp_remote_get('http://127.0.0.1:18881' . $path . '?token=URL_SENTINEL', array('headers' => array('Authorization' => 'Bearer AUTH_SENTINEL'), 'timeout' => 5));
    check(!is_wp_error($response), 'Fixture transport: ' . $path);
}
$previous_called = 0;
// The default handler still receives the warning; quoted payload must not be retained.
trigger_error("Synthetic warning 'PHP_SENTINEL'", E_USER_WARNING);
$recorder->finish();
$page = $storage->listing(array());
check(5 === $page['total'], 'One root and four outgoing captures, got ' . $page['total']);
$roots = array_values(array_filter($page['items'], fn($row) => 'incoming' === $row['direction']));
check(1 === count($roots), 'Single incoming root');
$root = $roots[0];
check($root['metadata']['queries'] >= 2, 'Database capture');
check($root['metadata']['issues'] >= 1, 'PHP capture');
$events = $storage->events(array('request_id' => $root['id'], 'event_type' => 'db_query', 'duplicates_only' => true));
check($events['total'] >= 2, 'Exact duplicate groups');
$issues = $storage->events(array('request_id' => $root['id'], 'event_type' => 'error', 'severity' => 'warning'));
check($issues['total'] >= 1, 'Severity filter');
$hooks = $storage->events(array('request_id' => $root['id'], 'event_type' => 'hook', 'search' => 'ri_fixture_action'));
check(1 === $hooks['total'], 'Hook occurrence grouping');
check(2 === $hooks['items'][0]['metadata']['occurrences'], 'Observed hook count');
foreach (array('json', 'har', 'sql', 'curl', 'bodies') as $format) {
    $export = RequestInspector\Exports::create($root['id'], $format);
    check(!is_wp_error($export), 'Export ' . $format);
    check(!str_contains($export['content'], 'SENTINEL'), 'Sanitized export ' . $format);
    if (in_array($format, array('json', 'har', 'bodies'), true)) {
        check(null !== json_decode($export['content'], true), 'Parseable ' . $format);
    }
}
foreach (array('requests', 'events', 'bodies') as $table) {
    $data = wp_json_encode($wpdb->get_results('SELECT * FROM ' . $storage->tables[$table], ARRAY_A));
    foreach (array('URL_SENTINEL', 'AUTH_SENTINEL', 'HTTP_SENTINEL', 'SQL_SENTINEL', 'PHP_SENTINEL') as $secret) {
        check(!str_contains($data, $secret), 'Redaction: ' . $table . '/' . $secret);
    }
}
wp_set_current_user(0);
check(401 === rest_do_request(new WP_REST_Request('GET', '/request-inspector/v1/requests'))->get_status(), 'Anonymous REST denied');
wp_set_current_user(get_user_by('login', 'ri_admin')->ID);
check(200 === rest_do_request(new WP_REST_Request('GET', '/request-inspector/v1/requests'))->get_status(), 'Administrator REST allowed');
$request = new WP_REST_Request('POST', '/request-inspector/v1/settings');
$request->set_header('content-type', 'application/json');
$request->set_body(wp_json_encode(array('revision' => 0)));
check(409 === rest_do_request($request)->get_status(), 'Stale settings rejected');
function api($route, array $body) {
    $request = new WP_REST_Request('POST', '/request-inspector/v1/' . $route);
    $request->set_header('content-type', 'application/json');
    $request->set_body(wp_json_encode($body));
    return rest_do_request($request);
}
check(403 === api('advanced/replay', array('url'=>'https://example.com','confirm'=>true))->get_status(), 'Replay disabled by default');
check(400 === api('advanced/explain', array('sql'=>'DELETE FROM riwp_posts','confirm'=>true))->get_status(), 'EXPLAIN rejects writes');
check(200 === api('advanced/explain', array('sql'=>'SELECT ID FROM '.$wpdb->posts.' LIMIT 1','confirm'=>true))->get_status(), 'Timeout-bounded synthetic EXPLAIN');
check(400 === api('advanced/search', array('pattern'=>'(a+)+'))->get_status(), 'Search rejects expensive regex');
check(200 === api('advanced/search', array('pattern'=>'integration'))->get_status(), 'Restricted search');
$created = api('workflows/sessions', array('operation'=>'create','name'=>'Synthetic baseline'))->get_data();
check(is_array($created) && count($created)>0, 'Saved scope created');
$scope = $created[array_key_last($created)];
$members = api('workflows/sessions', array('operation'=>'show','id'=>$scope['id']))->get_data();
check(0 === $members['total'], 'New scope excludes earlier persisted captures');
check(200 === api('workflows/sessions', array('operation'=>'stop','id'=>$scope['id']))->get_status(), 'Scope stopped');
$compared = api('workflows/compare', array('ids'=>array($root['id'], $page['items'][0]['id'])))->get_data();
check(isset($compared['requests']) && !$compared['comparable'], 'Comparison flags mismatched captures');
check(400 === api('workflows/compare', array('ids'=>array(array(),2)))->get_status(), 'Comparison rejects malformed IDs');
check(200 === api('workflows/report', array())->get_status(), 'Bounded component report');
check(count(RequestInspector\Audit::listing())>=3, 'Operations are audited');
check($storage->arm(2), 'Arm next two');
check($storage->claim(), 'First atomic claim');
check($storage->claim(), 'Second atomic claim');
check(!$storage->claim(), 'No overrun');
$generation = (int) $storage->state()['generation'];
$storage->purge();
check(0 === $storage->listing(array())['total'], 'Purge visibility');
check(!$storage->write(array(array('trace_id' => wp_generate_uuid4())), $generation), 'Stale generation blocked');
$storage->install();
check(0 === $storage->listing(array())['total'], 'Repeatable schema');
update_option(Settings::OPTION, Settings::defaults());
echo "PASS: $checks integration assertions.\n";
