<?php
/** Synthetic operational demo — confined to an already existing DEMO company.
 * php scripts/seed_erp_suite_demo.php --workspace-id=1 --company-id=2 [--apply]
 * No employee national identifiers, bank details, secret keys, GL/stock posting.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
if(PHP_VERSION_ID<80100){fwrite(STDERR,"PHP 8.1+ required\n");exit(2);}
$opts=getopt('',['workspace-id:','company-id:','apply','help']);
if(isset($opts['help'])||!isset($opts['workspace-id'],$opts['company-id'])){
    echo "Usage: php scripts/seed_erp_suite_demo.php --workspace-id=ID --company-id=ID [--apply]\n";
    echo "Default is DRY RUN. Apply only to an isolated company named DEMO - ... / دمو - ... after DB backup.\n";exit(0);
}
$wid=(int)$opts['workspace-id'];$cid=(int)$opts['company-id'];
if($wid<1||$cid<1){fwrite(STDERR,"Invalid scope\n");exit(2);}
echo "ERP Suite demo: 12 employees, 4 projects, tasks, timesheets, attendance, leave, expenses, assets, service and optional POS draft data.\n";
echo "No posted sales, inventory movements, salaries, payroll or accounting entries.\n";
if(!isset($opts['apply'])){echo "DRY_RUN=PASS; database unchanged\n";exit(0);}
$root=dirname(__DIR__);$path=$root.'/app/config.php';
if(!is_file($path)){fwrite(STDERR,"Private DB config unavailable. Run in configured DEMO environment only.\n");exit(2);}
$config=require $path;
require_once $root.'/app/Core/DB.php';
require_once $root.'/app/Core/ErpSuiteDomain.php';
$db=DB::connect($config['db']);
$s=$db->prepare("SELECT c.name,w.owner_user_id FROM companies c JOIN workspaces w ON c.workspace_id=w.id WHERE c.id=? AND c.workspace_id=? AND c.active=1 AND w.status='active'");
$s->execute([$cid,$wid]);$company=$s->fetch();
if(!$company||!preg_match('/^(DEMO\s*[-—]|دمو\s*[-—])/u',trim((string)$company['name']))){
    fwrite(STDERR,"REFUSED: only existing, active DEMO-named companies.\n");exit(3);
}
$uid=(int)($company['owner_user_id']??0);
if($uid<1){fwrite(STDERR,"REFUSED: demo owner missing\n");exit(3);}
ErpSuiteDomain::migrate($db);
$st=$db->prepare("SELECT id FROM erp_employees WHERE workspace_id=? AND company_id=? AND code='DEMO-EMP-001' LIMIT 1");
$st->execute([$wid,$cid]);
if($st->fetchColumn()){echo "DEMO_ALREADY_SEEDED=PASS; no changes\n";exit(0);}
$db->beginTransaction();
try{
    $names=['سارا رضایی','علی احمدی','مریم کریمی','امیر حسینی','نگین رستگار','سینا مرادی','مهسا محمدی','رضا افشار','سمیرا موسوی','آرمان توکلی','هستی نوری','نیما قاسمی'];
    $departments=['فروش','مالی','تولید','عملیات','فناوری','انبار'];
    $st=$db->prepare("INSERT INTO erp_employees(workspace_id,company_id,code,full_name,department,job_title,hire_date,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'active',?,NOW(),NOW())");
    $emp=[];
    foreach($names as $i=>$name){
        $st->execute([$wid,$cid,'DEMO-EMP-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),$name,$departments[$i%6],'کارشناس',date('Y-m-d',strtotime('-'.(300+$i*26).' days')),$uid]);
        $emp[]=(int)$db->lastInsertId();
    }
    $proj=[];$find=$db->prepare("SELECT id FROM acc_projects WHERE workspace_id=? AND company_id=? AND code=? LIMIT 1");
    $ins=$db->prepare("INSERT INTO acc_projects(workspace_id,company_id,code,name,start_date,end_date,status,active,created_at,updated_at) VALUES(?,?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 120 DAY),'active',1,NOW(),NOW())");
    $titles=['پیاده‌سازی سیستم مانیتورینگ خط تولید','توسعه فروش سازمانی','نوسازی زیرساخت انبار','استقرار سامانه خدمات مشتریان'];
    foreach($titles as $i=>$title){
        $code='DEMO-PRJ-'.($i+1);$find->execute([$wid,$cid,$code]);$id=(int)$find->fetchColumn();
        if(!$id){$ins->execute([$wid,$cid,$code,$title]);$id=(int)$db->lastInsertId();}$proj[]=$id;
    }
    $tasks=[];$ins=$db->prepare("INSERT INTO erp_project_tasks(workspace_id,company_id,project_id,title,assignee_employee_id,due_date,estimated_hours,priority,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,DATE_ADD(CURDATE(),INTERVAL ? DAY),?,'normal',?, ?,NOW(),NOW())");
    foreach($proj as $k=>$pid)for($i=0;$i<4;$i++){
        $status=$i===0?'done':($i===1?'in_progress':'open');
        $ins->execute([$wid,$cid,$pid,['نیازسنجی و تحلیل','طراحی اجرایی','اجرای فاز اول','کنترل کیفیت و تحویل'][$i],$emp[($i+$k)%count($emp)],($i===2?-5:($i+1)*8),12+3*$i,$status,$uid]);
        $tasks[]=(int)$db->lastInsertId();
    }
    $ins=$db->prepare("INSERT INTO erp_timesheets(workspace_id,company_id,task_id,employee_id,work_date,hours,notes,created_by,created_at,updated_at) VALUES(?,?,?,?,DATE_SUB(CURDATE(),INTERVAL ? DAY),?, 'ثبت ساعات آزمایشی',?,NOW(),NOW())");
    foreach($tasks as $i=>$tid)$ins->execute([$wid,$cid,$tid,$emp[$i%count($emp)],$i%11,3.5,$uid]);
    $ins=$db->prepare("INSERT INTO erp_attendance(workspace_id,company_id,employee_id,work_date,hours,notes,created_by,created_at,updated_at) VALUES(?,?,?,DATE_SUB(CURDATE(),INTERVAL ? DAY),8,'حضور آزمایشی',?,NOW(),NOW())");
    foreach($emp as $i=>$eid)for($d=0;$d<3;$d++)$ins->execute([$wid,$cid,$eid,$d+1,$uid]);
    $ins=$db->prepare("INSERT INTO erp_leave_requests(workspace_id,company_id,employee_id,leave_type,starts_on,ends_on,notes,status,created_by,created_at,updated_at) VALUES(?,?,?,'annual',DATE_ADD(CURDATE(),INTERVAL ? DAY),DATE_ADD(CURDATE(),INTERVAL ? DAY),'مرخصی آزمایشی','pending',?,NOW(),NOW())");
    for($i=0;$i<4;$i++)$ins->execute([$wid,$cid,$emp[$i],$i*3+2,$i*3+3,$uid]);
    $ins=$db->prepare("INSERT INTO erp_expense_claims(workspace_id,company_id,employee_id,project_id,category,claim_date,amount,notes,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,CURDATE(),?,'هزینه نمونه بدون پرداخت','pending',?,NOW(),NOW())");
    for($i=0;$i<6;$i++)$ins->execute([$wid,$cid,$emp[$i],$proj[$i%4],['رفت‌وآمد','ایاب و ذهاب','ملزومات اداری'][$i%3],1500000+$i*380000,$uid]);
    $ins=$db->prepare("INSERT INTO erp_fixed_assets(workspace_id,company_id,code,name,purchase_date,purchase_cost,life_months,status,created_by,created_at,updated_at) VALUES(?,?,?,?,DATE_SUB(CURDATE(),INTERVAL ? DAY),?,36,'in_use',?,NOW(),NOW())");
    foreach(['لپ‌تاپ واحد فروش','پرینتر اداری','رک سرور','دستگاه بارکدخوان','اسکنر انبار','پروژکتور جلسه'] as $i=>$name)$ins->execute([$wid,$cid,'DEMO-ASSET-'.($i+1),$name,30*$i+30,85000000+$i*6000000,$uid]);
    $st=$db->prepare('SELECT id FROM acc_parties WHERE workspace_id=? AND company_id=? AND active=1 ORDER BY id LIMIT 4');$st->execute([$wid,$cid]);$parties=$st->fetchAll(PDO::FETCH_COLUMN);
    if($parties){
        $ins=$db->prepare("INSERT INTO erp_service_tickets(workspace_id,company_id,customer_id,subject,assignee_employee_id,priority,due_date,status,notes,created_by,created_at,updated_at) VALUES(?,?,?,?,?,'normal',DATE_ADD(CURDATE(),INTERVAL ? DAY),?,'پیگیری پس از فروش نمونه',?,NOW(),NOW())");
        for($i=0;$i<5;$i++)$ins->execute([$wid,$cid,(int)$parties[$i%count($parties)],['آموزش کاربری','درخواست سرویس','بررسی نصب','به‌روزرسانی قرارداد','پیگیری رضایت'][$i],$emp[$i],$i*2-2,$i===0?'resolved':'open',$uid]);
    }
    $st=$db->prepare('SELECT id FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND active=1 ORDER BY id LIMIT 1');$st->execute([$wid,$cid]);$warehouse=(int)$st->fetchColumn();
    $st=$db->prepare('SELECT id,purchase_price_1 FROM acc_items WHERE workspace_id=? AND company_id=? AND active=1 ORDER BY id LIMIT 3');$st->execute([$wid,$cid]);$items=$st->fetchAll();
    if($warehouse){
        $ins=$db->prepare("INSERT INTO erp_pos_registers(workspace_id,company_id,code,name,warehouse_id,status,created_by,created_at,updated_at) VALUES(?,?,'DEMO-POS-1','صندوق آزمایشی',?,'active',?,NOW(),NOW())");
        $ins->execute([$wid,$cid,$warehouse,$uid]);$reg=(int)$db->lastInsertId();
        $ins=$db->prepare("INSERT INTO erp_pos_carts(workspace_id,company_id,register_id,customer_id,notes,status,created_by,created_at,updated_at) VALUES(?,?,?,?,'سبد نمایشی — بدون دریافت وجه','draft',?,NOW(),NOW())");
        $ins->execute([$wid,$cid,$reg,$parties?(int)$parties[0]:null,$uid]);$cart=(int)$db->lastInsertId();
        $ins=$db->prepare("INSERT INTO erp_pos_cart_lines(workspace_id,company_id,cart_id,item_id,quantity,unit_price,discount_percent,tax_percent,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,0,0,?,NOW(),NOW())");
        foreach($items as $item)$ins->execute([$wid,$cid,$cart,(int)$item['id'],2,max(0,(float)$item['purchase_price_1']),$uid]);
    }
    $db->commit();
    echo "ERP_DEMO_SEED=PASS; all operational records synthetic; no ledger/stock/salary posting\n";
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,'Demo transaction rolled back: '.get_class($e)."\n");exit(4);
}
