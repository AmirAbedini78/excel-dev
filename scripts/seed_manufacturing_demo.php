<?php
/**
 * OPT-IN demo company seed. NEVER target operational accounting companies.
 * Usage: php scripts/seed_manufacturing_demo.php --workspace-id=2 --company-id=5 --apply
 * Default is dry-run. Creates only DEMO-prefixed item master, one warehouse,
 * manufacturing BOM drafts and plan drafts. No stock, sales, accounting entries.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
if (PHP_VERSION_ID < 80000) { fwrite(STDERR,"PHP 8+ CLI is required.\n");exit(2); }
$opts=getopt('', ['workspace-id:','company-id:','apply','help']);
if (isset($opts['help']) || !isset($opts['workspace-id'],$opts['company-id'])) {
    echo "Usage: php scripts/seed_manufacturing_demo.php --workspace-id=ID --company-id=ID [--apply]\n";
    echo "Requires an EXISTING, active company named 'DEMO - ...' or 'دمو - ...'. Default: DRY RUN.\n";
    exit(0);
}
$wid=(int)$opts['workspace-id'];$cid=(int)$opts['company-id'];
if($wid<=0 || $cid<=0) { fwrite(STDERR,"Invalid scope.\n");exit(2); }
$items=[
    ['ورق فولادی','steel-sheet',950000],['پروفیل آلومینیوم','al-profile',730000],
    ['پیچ صنعتی M8','bolt-m8',35000],['مهره M8','nut-m8',17000],
    ['واشر صنعتی','washer',9000],['کابل برق صنعتی','wire',65000],
    ['کانکتور صنعتی','connector',220000],['برد کنترل الکترونیکی','controller-board',1850000],
    ['مقاومت حرارتی','thermistor',155000],['سنسور فشار','pressure-sensor',2200000],
    ['بست فلزی','clamp',33000],['ورق عایق','insulation',175000],
    ['رله قدرت','power-relay',940000],['محافظ الکتریکی','fuse',230000],
    ['رنگ صنعتی','paint',250000],['چسب صنعتی','adhesive',160000],
    ['جعبه فلزی','metal-case',680000],['برچسب محصول','label',9500],
    ['کارتن صنعتی','carton',110000],['دفترچه راهنما','manual',24000],
    ['واشر آب‌بندی','gasket',57000],['لوله مسی','copper-tube',890000],
    ['اتصال لوله','pipe-fitting',125000],['فیوز شیشه‌ای','glass-fuse',38000],
];
$finished=['تابلو کنترل صنعتی','ماژول پایش فشار','پکیج اتوماسیون انبار','کنترلر پمپ','سامانه هشدار دما','برد کنترل هوشمند'];
echo "Demo manufacturing seed: workspace=$wid company=$cid; 30 items, 1 warehouse, 6 BOMs, 30 draft plans.\n";
echo "NO stock ledger, accounting vouchers, live production, or approved operations.\n";
if(!isset($opts['apply'])){echo "DRY RUN. Add --apply to write to an isolated DEMO company after backup.\n";exit(0);}
$root=dirname(__DIR__);$configPath=$root.'/app/config.php';
if(!is_file($configPath)){fwrite(STDERR,"Private app/config.php not found. Run only in a configured, isolated demo environment.\n");exit(2);}
$config=require $configPath;
require_once $root.'/app/Core/DB.php';
require_once $root.'/app/Core/ProductionDomain.php';
$db=DB::connect($config['db']);
$st=$db->prepare('SELECT c.name,w.owner_user_id FROM companies c JOIN workspaces w ON w.id=c.workspace_id WHERE c.id=? AND c.workspace_id=? AND c.active=1 AND w.status=\'active\' LIMIT 1');
$st->execute([$cid,$wid]);$company=$st->fetch();
if(!$company || !preg_match('/^(DEMO\s*[-—]|دمو\s*[-—])/u',trim((string)$company['name']))) {
    fwrite(STDERR,"REFUSED: company must be a dedicated active DEMO - ... / دمو - ... company in this workspace.\n");exit(3);
}
$actor=(int)($company['owner_user_id']??0);
if($actor<=0){fwrite(STDERR,"REFUSED: demo workspace owner missing.\n");exit(3);}
ProductionDomain::migrate($db);
$db->beginTransaction();
try {
    $wcode='DEMO-WH-01';
    $addWarehouse=$db->prepare("INSERT IGNORE INTO acc_warehouses (workspace_id,company_id,code,name,warehouse_type,active,created_at,updated_at) VALUES (?,?,'DEMO-WH-01','انبار آزمایشی تولید','general',1,NOW(),NOW())");
    $addWarehouse->execute([$wid,$cid]);
    $st=$db->prepare('SELECT id FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND code=? LIMIT 1');
    $st->execute([$wid,$cid,$wcode]);$warehouse=(int)$st->fetchColumn();
    $createItem=$db->prepare("INSERT IGNORE INTO acc_items (workspace_id,company_id,code,name,item_type,purchase_price_1,min_stock,max_stock,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?, ?,1,NOW(),NOW())");
    $findItem=$db->prepare('SELECT id FROM acc_items WHERE workspace_id=? AND company_id=? AND code=? LIMIT 1');
    $materialIds=[];
    foreach($items as $i=>$item){
        $code='DEMOPR-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT);
        $createItem->execute([$wid,$cid,$code,$item[0],'material',$item[2],5,120]);
        $findItem->execute([$wid,$cid,$code]);$materialIds[]=(int)$findItem->fetchColumn();
    }
    $finishedIds=[];
    foreach($finished as $i=>$title){
        $code='DEMOFG-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT);
        $createItem->execute([$wid,$cid,$code,$title,'product',0,0,0]);
        $findItem->execute([$wid,$cid,$code]);$finishedIds[]=(int)$findItem->fetchColumn();
    }
    $findBom=$db->prepare('SELECT id FROM erp_production_boms WHERE workspace_id=? AND company_id=? AND code=? AND revision=1 LIMIT 1');
    $addBom=$db->prepare("INSERT INTO erp_production_boms (workspace_id,company_id,code,revision,output_item_id,output_qty,status,notes,created_by,created_at) VALUES (?,?,?,1,?,1,'draft','Synthetic training fixture – no manufacturing posting',?,NOW())");
    $addLine=$db->prepare('INSERT INTO erp_production_bom_lines (workspace_id,company_id,bom_id,line_no,component_item_id,quantity_per,scrap_percent) VALUES (?,?,?,?,?,?,?)');
    $hasPlans=$db->prepare('SELECT COUNT(*) FROM erp_production_plans WHERE workspace_id=? AND company_id=? AND bom_id=?');
    $addPlan=$db->prepare("INSERT INTO erp_production_plans (workspace_id,company_id,bom_id,warehouse_id,planned_qty,target_date,status,notes,created_by,created_at) VALUES (?,?,?,?,?,DATE_ADD(CURDATE(),INTERVAL ? DAY),'draft','Synthetic training scenario',?,NOW())");
    foreach($finishedIds as $i=>$finishedItem){
        $code='DEMO-BOM-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT);
        $findBom->execute([$wid,$cid,$code]);$bomId=(int)$findBom->fetchColumn();
        if(!$bomId){
            $addBom->execute([$wid,$cid,$code,$finishedItem,$actor]);$bomId=(int)$db->lastInsertId();
            for($j=0;$j<4;$j++) {
                $part=$materialIds[($i*4+$j)%count($materialIds)];
                $addLine->execute([$wid,$cid,$bomId,$j+1,$part,($j%3)+1,$j===0?2:0]);
            }
        }
        $hasPlans->execute([$wid,$cid,$bomId]);
        if((int)$hasPlans->fetchColumn()===0){
            foreach([10,24,40,75,120] as $j=>$qty){
                $addPlan->execute([$wid,$cid,$bomId,$warehouse,$qty,($j+1)*7,$actor]);
            }
        }
    }
    $db->commit();
    echo "APPLIED. Synthetic master data, BOM and draft plans only. Company is isolated. Reruns are idempotent.\n";
} catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,"Seed transaction rolled back (".get_class($e).")\n");exit(4);
}
