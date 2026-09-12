<?php
/** Deterministic packaging of plugin runtime, source and license files. */
date_default_timezone_set('UTC');
$root=dirname(__DIR__);
$plugin=$root.'/request-inspector';
if(!is_file($plugin.'/admin/build/index.asset.php'))throw new RuntimeException('Build assets are missing');
if(!is_dir($root.'/dist'))mkdir($root.'/dist');
$target=$root.'/dist/request-inspector-0.10.2.zip';
$zip=new ZipArchive();
if(true!==$zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE))throw new RuntimeException('Cannot create ZIP');
$iterator=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($plugin,FilesystemIterator::SKIP_DOTS),fn($item)=>!in_array($item->getFilename(),['node_modules','vendor','.git','.DS_Store'],true)));
$files=[];foreach($iterator as $file){if($file->isFile())$files[]=str_replace('\\','/',$file->getPathname());}sort($files,SORT_STRING);
foreach($files as $file){
 $name='request-inspector/'.substr($file,strlen(str_replace('\\','/',$plugin))+1);
 // Keep workspace guides out of the production plugin root.
 if(preg_match('~^request-inspector/[^/]+\\.md$~i',$name))continue;
 // Earlier builds imported the full logo; the current UI shares asserts/logo.png.
 if(str_starts_with($name,'request-inspector/admin/build/images/logo.'))continue;
 $zip->addFile($file,$name);$zip->setMtimeName($name,946684800);$zip->setCompressionName($name,ZipArchive::CM_DEFLATE,9);
 $zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,0100644<<16);
}
$count=$zip->numFiles;
$zip->close();
$hash=hash_file('sha256',$target);file_put_contents($target.'.sha256',$hash.'  '.basename($target)."\n");
echo 'Packaged '.$count.' files: '.$target."\nSHA-256: $hash\n";
