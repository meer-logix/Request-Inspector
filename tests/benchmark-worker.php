<?php
$_SERVER['HTTP_HOST']='127.0.0.1:18880';$_SERVER['REQUEST_URI']='/benchmark';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
use RequestInspector\Settings;
use RequestInspector\Storage;
use RequestInspector\Recorder;
$mode=$argv[1]??'off';
$settings=Settings::defaults();$settings['mode']='all';$settings['php_errors']=false;
if($mode==='database')$settings['database']=true;
if($mode==='hooks')$settings['hooks']=true;
if($mode==='errors')$settings['php_errors']=true;
if($mode==='bodies'){$settings['request_body']=true;$settings['response_body']=true;}
$storage=new Storage();
$before=$storage->state();$queries=$wpdb->num_queries;
add_filter('pre_http_request',fn()=>['headers'=>['content-type'=>'application/json'],'body'=>'{"ok":true,"token":"synthetic"}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]],10,3);
if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();
$memory=memory_get_usage(false);$start=hrtime(true);
$recorder=$mode==='off'?null:new Recorder($settings);
if($recorder)$recorder->register();
for($i=0;$i<30;$i++)apply_filters('ri_benchmark_filter',42);
for($i=0;$i<10;$i++)$wpdb->get_var('SELECT 1');
wp_remote_get('https://benchmark.invalid/fixture');
if($mode==='errors')trigger_error('Synthetic benchmark notice',E_USER_NOTICE);
if($recorder)$recorder->finish();
$elapsed=(hrtime(true)-$start)/1000000;
$peak=max(0,memory_get_peak_usage(false)-$memory);$writes=$wpdb->num_queries-$queries-10;
$after=$storage->state();
echo wp_json_encode(['mode'=>$mode,'elapsed_ms'=>$elapsed,'peak_extra_bytes'=>$peak,'extra_queries'=>$writes,'stored_bytes'=>(int)$after['used_bytes']-(int)$before['used_bytes']]);
