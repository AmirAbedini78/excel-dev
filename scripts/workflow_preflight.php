<?php
/** CLI-only, read-only schema readiness check after deployment. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
$root=dirname(__DIR__);$configPath=$root.'/app/config.php';
if(!is_file($configPath)){fwrite(STDERR,"WORKFLOW_SCHEMA=UNKNOWN: configured environment required\n");exit(2);}
$config=require $configPath;
require_once $root.'/app/Core/DB.php';
$db=DB::connect($config['db']);
$tables=['erp_workflow_definitions','erp_workflow_steps','erp_workflow_cases','erp_workflow_events'];
$stmt=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
$missing=[];
foreach($tables as $table){$stmt->execute([$table]);if(!$stmt->fetchColumn())$missing[]=$table;}
if($missing){fwrite(STDERR,"WORKFLOW_SCHEMA=INCOMPLETE: ".implode(',',$missing)."\n");exit(3);}
echo "WORKFLOW_SCHEMA=PASS; tables=4; read_only=true\n";
