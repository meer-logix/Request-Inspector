<?php
// Isolated concurrency fixture. Never distributed.
$_SERVER['HTTP_HOST'] = '127.0.0.1:18880';
require dirname(__DIR__) . '/.runtime/wordpress/wp-load.php';
use RequestInspector\Storage;
use RequestInspector\Settings;
$storage = new Storage();
$action = $argv[1] ?? '';
if ('prepare' === $action) {
    $storage->purge();
    update_option(Settings::OPTION, array_merge(Settings::defaults(), ['max_records'=>10]));
    $storage->arm(7);
    echo 'ready';
} elseif ('claim' === $action) {
    $count=0;
    for($i=0;$i<8;$i++) $count += (int)$storage->claim();
    echo $count;
} elseif ('write' === $action) {
    $count=0;
    for($i=0;$i<5;$i++) {
        $trace=wp_generate_uuid4();
        $row=['trace_id'=>$trace,'direction'=>'incoming','type'=>'cli','method'=>'GET','url'=>'/concurrency','duration_us'=>1,'component'=>'Unknown','started_at'=>gmdate('Y-m-d H:i:s'),'metadata'=>[]];
        $child=array_merge($row,['direction'=>'outgoing']);
        $count += (int)$storage->write([$row,$child,$child], (int)$storage->state()['generation']);
    }
    echo $count;
} elseif ('state' === $action) {
    global $wpdb;
    echo wp_json_encode(['control'=>$storage->state(),'rows'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$storage->tables['requests']),'roots'=>(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$storage->tables['requests'].' WHERE parent_id IS NULL')]);
} elseif ('reset' === $action) {
    $storage->purge();
    update_option(Settings::OPTION, Settings::defaults());
}
