<?php
$path=dirname(__DIR__).'/.runtime/multisite/wordpress/wp-config.php';
$source=file_get_contents($path);
if(!str_contains($source,"define( 'MULTISITE'")) {
    $constants="\ndefine( 'WP_ALLOW_MULTISITE', true );\ndefine( 'MULTISITE', true );\ndefine( 'SUBDOMAIN_INSTALL', false );\ndefine( 'DOMAIN_CURRENT_SITE', 'ri-ms.test' );\ndefine( 'PATH_CURRENT_SITE', '/' );\ndefine( 'SITE_ID_CURRENT_SITE', 1 );\ndefine( 'BLOG_ID_CURRENT_SITE', 1 );\n";
    file_put_contents($path,str_replace('<?php','<?php'.$constants,$source));
}
