<?php
/** Read-only CLI readiness check. Do not expose via web/document-root. */
if (PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
$root=dirname(__DIR__);
$configPath=$root.'/app/config.php';
if(!is_file($configPath)){fwrite(STDERR,"ENTERPRISE_SCHEMA=UNKNOWN: configured environment required\n");exit(2);}
$config=require $configPath;
require_once $root.'/app/Core/DB.php';
require_once $root.'/app/Core/EnterpriseCatalog.php';
$db=DB::connect($config['db']);
$tables=array_map(fn($e)=>$e['table'],EnterpriseCatalog::records());
$tables=array_merge($tables,['erp_ext_events','erp_ext_links']);
$st=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
$missing=[];
foreach($tables as $table){$st->execute([$table]);if(!$st->fetchColumn())$missing[]=$table;}
if($missing){fwrite(STDERR,'ENTERPRISE_SCHEMA=INCOMPLETE; missing='.implode(',',$missing)."\n");exit(3);}
echo 'ENTERPRISE_SCHEMA=PASS; tables='.count($tables)."; read_only=true\n";
