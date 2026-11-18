<?php
/** CLI-only read-only schema readiness probe. Never print credentials. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
$root=dirname(__DIR__);$file=$root.'/app/config.php';
if(!is_file($file)){fwrite(STDERR,"Configured local DB required.\n");exit(2);}
$config=require $file;require_once $root.'/app/Core/DB.php';
$db=DB::connect($config['db']);
$names=['erp_employees','erp_leave_requests','erp_attendance','erp_project_tasks','erp_timesheets','erp_pos_registers','erp_pos_carts','erp_pos_cart_lines','erp_service_tickets','erp_fixed_assets','erp_expense_claims'];
$st=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
$fail=[];
foreach($names as $name){$st->execute([$name]);if(!$st->fetchColumn())$fail[]=$name;}
if($fail){fwrite(STDERR,'ERP_SUITE_SCHEMA=INCOMPLETE; tables missing='.implode(',',$fail)."\n");exit(3);}
echo 'ERP_SUITE_SCHEMA=PASS; tables='.count($names)."; DB credentials not printed\n";
