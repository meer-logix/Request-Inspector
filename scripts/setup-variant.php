<?php
// Provision only synthetic test databases on the dedicated local test server.
$variant=$argv[1]??'wordpress66';
if(!in_array($variant,['wordpress66','multisite/wordpress'],true))throw new RuntimeException('Unknown test variant');
$root=dirname(__DIR__).'/.runtime/'.$variant;
$name=$variant==='wordpress66'?'request_inspector_66_test':'request_inspector_ms_test';
$db=new mysqli('127.0.0.1','root','ri_test_only','',19306);
$db->query('CREATE DATABASE IF NOT EXISTS '.$name);
$config=file_get_contents(dirname(__DIR__).'/.runtime/wordpress/wp-config.php');
$config=str_replace("'request_inspector_test'","'".$name."'",$config);
$config=str_replace("'riwp_'",$variant==='wordpress66'?"'ri66_'":"'rims_'",$config);
file_put_contents($root.'/wp-config.php',$config);
if(!is_dir($root.'/wp-content/plugins'))mkdir($root.'/wp-content/plugins',0777,true);
if($variant==='wordpress66'){
 $_SERVER['HTTP_HOST']='127.0.0.1:18880';define('WP_INSTALLING',true);
 require $root.'/wp-load.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';
 if(!is_blog_installed())wp_install('Compatibility Test','ri_admin','local-test@example.invalid',false,'','ri-local-test-password');
}
echo "Test variant provisioned: $variant\n";
