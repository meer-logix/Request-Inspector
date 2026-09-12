<?php
$_SERVER['HTTP_HOST']='ri-ms.test';$_SERVER['REQUEST_URI']='/';
require dirname(__DIR__).'/.runtime/multisite/wordpress/wp-load.php';
use RequestInspector\Storage;
use RequestInspector\Settings;
use RequestInspector\Plugin;
$checks=0;
function verify($condition,$label){global $checks;++$checks;if(!$condition)throw new RuntimeException($label);}
verify(is_multisite(),'Network bootstrap');
Plugin::activate(true);
$site=wp_insert_site(['domain'=>'ri-ms.test','path'=>'/fixture-'.time().'/','network_id'=>1]);
verify(!is_wp_error($site),'New site created');
$main=new Storage();
switch_to_blog($site);
$child=new Storage();
verify(isset($child->state()['generation']),'New site receives diagnostic schema');
verify(!Settings::get()['enabled'],'New site starts disabled');
verify($child->tables['requests']!==$main->tables['requests'],'Site table isolation');
$settings=Settings::get();$settings['mode']='all';update_option(Settings::OPTION,$settings);
restore_current_blog();
verify('external'===Settings::get()['mode'],'Settings isolation');
Plugin::deactivate(true);
verify(!wp_next_scheduled('request_inspector_cleanup'),'Main cron removed');
switch_to_blog($site);verify(!wp_next_scheduled('request_inspector_cleanup'),'Child cron removed');restore_current_blog();
define('WP_UNINSTALL_PLUGIN','request-inspector/request-inspector.php');
require dirname(__DIR__).'/request-inspector/uninstall.php';
verify(isset($main->state()['generation']),'Default uninstall retains diagnostics');
$sites=get_sites(['number'=>100,'fields'=>'ids']);
foreach($sites as $id){switch_to_blog((int)$id);$settings=Settings::get();$settings['delete_on_uninstall']=true;update_option(Settings::OPTION,$settings);restore_current_blog();}
require dirname(__DIR__).'/request-inspector/uninstall.php';
foreach($sites as $id){
 switch_to_blog((int)$id);
 verify(null===$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'request_inspector_requests'))),'Opt-in uninstall removes diagnostic tables');
 verify(null!==$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->posts))),'Core tables preserved');
 restore_current_blog();
}
Plugin::activate(true);
echo "PASS: $checks multisite/lifecycle assertions.\n";
