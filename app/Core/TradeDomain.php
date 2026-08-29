<?php
/**
 * ERPSMART v10.2 Trade + Logistics domain service.
 *
 * Invariants:
 * - purchase document remains the procurement/accounting source;
 * - stock movements remain immutable quantity history;
 * - trade costs never rewrite historical stock movement unit_cost;
 * - landed cost is an auditable deterministic bridge over purchase + trade costs;
 * - AI mutations are executed only through Proposal -> Human Approval.
 */
final class TradeDomain
{
    public const VERSION='10.2.0';
    private const INCOTERMS=['EXW','FCA','FAS','FOB','CFR','CIF','CPT','CIP','DAP','DPU','DDP'];
    private const SHIPMENT_MODES=['sea','air','road','rail','courier'];
    private const SHIPMENT_STATUSES=['planned','booked','in_transit','arrived','customs','cleared','delivered','canceled'];
    private const COST_TYPES=['freight','insurance','customs_duty','import_tax','brokerage','handling','storage','inspection','bank_fee','other'];
    private const COST_BASES=['estimated','actual'];
    private const CLEARANCE_STATUSES=['not_started','docs_pending','submitted','assessed','duties_pending','release_pending','released','hold'];
    private const MILESTONE_TYPES=['booking','departed','arrived','customs_entry','customs_assessment','customs_paid','customs_release','warehouse_received'];

    private static function assertCompany(int $wid,int $cid): void
    {
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);if(!$st->fetchColumn())throw new RuntimeException('company_not_found');
    }

    private static function assertOwned(int $wid,int $cid,string $table,int $id): void
    {
        if($id<=0||!in_array($table,['acc_purchase_docs','acc_trade_cases','acc_trade_shipments'],true))throw new RuntimeException('trade_entity_invalid');
        $st=pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");
        $st->execute([$wid,$cid,$id]);if(!$st->fetchColumn())throw new RuntimeException('trade_entity_not_owned');
    }

    private static function code(string $raw,string $fallback=''): string
    {
        $v=strtoupper(trim($raw));return preg_match('/^[A-Z0-9._-]{1,40}$/D',$v)?$v:$fallback;
    }

    public static function searchCases(int $wid,int $cid,string $query=''): array
    {
        self::assertCompany($wid,$cid);$query=trim($query);$params=[$wid,$cid];$where='c.workspace_id=? AND c.company_id=?';
        if($query!==''){$like='%'.$query.'%';$where.=' AND (c.case_no LIKE ? OR d.document_no LIKE ? OR p.name LIKE ? OR c.proforma_no LIKE ?)';array_push($params,$like,$like,$like,$like);}
        $st=pdo()->prepare("SELECT c.*,d.document_no purchase_document_no,d.net_total purchase_net_total,p.name supplier_name,
            s.id latest_shipment_id,s.shipment_no latest_shipment_no,s.mode latest_shipment_mode,s.status latest_shipment_status,s.etd latest_etd,s.eta latest_eta,s.ata latest_ata
            FROM acc_trade_cases c
            JOIN acc_purchase_docs d ON d.id=c.purchase_doc_id AND d.workspace_id=c.workspace_id
            LEFT JOIN acc_parties p ON p.id=c.supplier_id AND p.workspace_id=c.workspace_id
            LEFT JOIN acc_trade_shipments s ON s.id=(SELECT s2.id FROM acc_trade_shipments s2 WHERE s2.workspace_id=c.workspace_id AND s2.company_id=c.company_id AND s2.trade_case_id=c.id AND s2.status<>'canceled' ORDER BY s2.id DESC LIMIT 1)
            WHERE $where ORDER BY c.id DESC LIMIT 100");
        $st->execute($params);return $st->fetchAll();
    }

    public static function caseRow(int $wid,int $cid,int $caseId): ?array
    {
        self::assertCompany($wid,$cid);$st=pdo()->prepare("SELECT c.*,d.document_no purchase_document_no,d.document_date purchase_document_date,d.net_total purchase_net_total,d.workflow_status purchase_status,p.name supplier_name
            FROM acc_trade_cases c JOIN acc_purchase_docs d ON d.id=c.purchase_doc_id AND d.workspace_id=c.workspace_id LEFT JOIN acc_parties p ON p.id=c.supplier_id AND p.workspace_id=c.workspace_id
            WHERE c.id=? AND c.workspace_id=? AND c.company_id=? LIMIT 1");$st->execute([$caseId,$wid,$cid]);$r=$st->fetch();return$r?:null;
    }

    public static function shipments(int $wid,int $cid,int $caseId): array
    {
        self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$st=pdo()->prepare("SELECT * FROM acc_trade_shipments WHERE workspace_id=? AND company_id=? AND trade_case_id=? ORDER BY id DESC");$st->execute([$wid,$cid,$caseId]);return$st->fetchAll();
    }

    public static function costs(int $wid,int $cid,int $caseId): array
    {
        self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$st=pdo()->prepare("SELECT c.*,s.shipment_no FROM acc_trade_costs c LEFT JOIN acc_trade_shipments s ON s.id=c.shipment_id AND s.workspace_id=c.workspace_id WHERE c.workspace_id=? AND c.company_id=? AND c.trade_case_id=? AND c.status='active' ORDER BY c.id DESC");$st->execute([$wid,$cid,$caseId]);return$st->fetchAll();
    }

    public static function milestones(int $wid,int $cid,int $caseId): array
    {
        self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$st=pdo()->prepare("SELECT m.*,s.shipment_no FROM acc_trade_milestones m LEFT JOIN acc_trade_shipments s ON s.id=m.shipment_id AND s.workspace_id=m.workspace_id WHERE m.workspace_id=? AND m.company_id=? AND m.trade_case_id=? ORDER BY COALESCE(m.actual_date,m.planned_date) DESC,m.id DESC");$st->execute([$wid,$cid,$caseId]);return$st->fetchAll();
    }

    public static function normalizeCaseArgs(int $wid,int $cid,array $args,bool $forCreate=true): array
    {
        self::assertCompany($wid,$cid);$docId=(int)($args['purchase_doc_id']??0);if($docId<=0)throw new RuntimeException('سند خرید برای پرونده بازرگانی الزامی است.');self::assertOwned($wid,$cid,'acc_purchase_docs',$docId);
        $doc=InventoryDomain::purchaseDocument($wid,$cid,$docId);if(!$doc)throw new RuntimeException('فقط سفارش/فاکتور خرید کالایی معتبر قابل اتصال است.');
        if($forCreate){$dupe=pdo()->prepare("SELECT id FROM acc_trade_cases WHERE workspace_id=? AND company_id=? AND purchase_doc_id=? LIMIT 1");$dupe->execute([$wid,$cid,$docId]);if($dupe->fetchColumn())throw new RuntimeException('برای این سند خرید قبلاً پرونده بازرگانی ساخته شده است.');}
        $incoterm=strtoupper(trim((string)($args['incoterm']??'')));if(!in_array($incoterm,self::INCOTERMS,true))throw new RuntimeException('Incoterm معتبر الزامی است.');
        $currency=self::code((string)($args['currency_code']??'IRR'),'');if(!preg_match('/^[A-Z]{3}$/D',$currency))throw new RuntimeException('کد ارز باید سه حرفی باشد.');
        $fx=(float)($args['fx_rate_to_irr']??0);if($currency==='IRR')$fx=1.0;if($fx<=0)throw new RuntimeException('نرخ تبدیل به ریال باید بیشتر از صفر باشد.');
        $proformaDate=AccountingRepository::date((string)($args['proforma_date']??''));
        return ['purchase_doc_id'=>$docId,'supplier_id'=>(int)$doc['party_id'],'purchase_document_no'=>$doc['document_no'],'supplier_name'=>$doc['supplier_name']??'',
            'proforma_no'=>mb_substr(trim((string)($args['proforma_no']??'')),0,120),'proforma_date'=>$proformaDate,
            'origin_country'=>mb_substr(trim((string)($args['origin_country']??'')),0,120),'destination_country'=>mb_substr(trim((string)($args['destination_country']??'')),0,120),
            'incoterm'=>$incoterm,'currency_code'=>$currency,'fx_rate_to_irr'=>$fx,'notes'=>mb_substr(trim((string)($args['notes']??'')),0,2000)];
    }

    public static function createCase(int $wid,int $cid,int $userId,array $args): array
    {
        self::assertCompany($wid,$cid);$pdo=pdo();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
        try{$docId=(int)($args['purchase_doc_id']??0);$lock=$pdo->prepare("SELECT id FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");$lock->execute([$docId,$wid,$cid]);if(!$lock->fetchColumn())throw new RuntimeException('سند خرید پیدا نشد.');$n=self::normalizeCaseArgs($wid,$cid,$args,true);$no='TRD-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
            $pdo->prepare("INSERT INTO acc_trade_cases (workspace_id,company_id,case_no,purchase_doc_id,supplier_id,proforma_no,proforma_date,origin_country,destination_country,incoterm,currency_code,fx_rate_to_irr,status,customs_declaration_no,customs_office,clearance_status,customs_entry_date,customs_release_date,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'planning',NULL,NULL,'not_started',NULL,NULL,?,?,NOW(),NOW())")
                ->execute([$wid,$cid,$no,$n['purchase_doc_id'],$n['supplier_id'],$n['proforma_no']?:null,$n['proforma_date'],$n['origin_country']?:null,$n['destination_country']?:null,$n['incoterm'],$n['currency_code'],$n['fx_rate_to_irr'],$n['notes']?:null,$userId]);$id=(int)$pdo->lastInsertId();
            if($owns)$pdo->commit();Audit::logForWorkspace($wid,'trade.case.create','acc_trade_cases',$id,'ایجاد پرونده بازرگانی '.$no,null,['purchase_doc_id'=>$n['purchase_doc_id'],'incoterm'=>$n['incoterm'],'currency'=>$n['currency_code']]);return ['entity'=>'acc_trade_cases','id'=>$id,'case_no'=>$no,'purchase_doc_id'=>$n['purchase_doc_id'],'status'=>'planning'];
        }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function normalizeShipmentArgs(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);$caseId=(int)($args['trade_case_id']??0);self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$mode=strtolower(trim((string)($args['mode']??'')));if(!in_array($mode,self::SHIPMENT_MODES,true))throw new RuntimeException('روش حمل معتبر نیست.');
        $status=strtolower(trim((string)($args['status']??'planned')));if(!in_array($status,self::SHIPMENT_STATUSES,true))throw new RuntimeException('وضعیت حمل معتبر نیست.');
        $etd=AccountingRepository::date((string)($args['etd']??''));$eta=AccountingRepository::date((string)($args['eta']??''));$ata=AccountingRepository::date((string)($args['ata']??''));if($etd&&$eta&&$eta<$etd)throw new RuntimeException('ETA نمی‌تواند قبل از ETD باشد.');
        return ['trade_case_id'=>$caseId,'shipment_no'=>mb_substr(trim((string)($args['shipment_no']??'')),0,120),'mode'=>$mode,'carrier'=>mb_substr(trim((string)($args['carrier']??'')),0,190),'forwarder'=>mb_substr(trim((string)($args['forwarder']??'')),0,190),'tracking_no'=>mb_substr(trim((string)($args['tracking_no']??'')),0,190),'origin_location'=>mb_substr(trim((string)($args['origin_location']??'')),0,190),'destination_location'=>mb_substr(trim((string)($args['destination_location']??'')),0,190),'etd'=>$etd,'eta'=>$eta,'ata'=>$ata,'status'=>$status,'package_count'=>max(0,(int)($args['package_count']??0)),'gross_weight_kg'=>max(0,(float)($args['gross_weight_kg']??0)),'notes'=>mb_substr(trim((string)($args['notes']??'')),0,1000)];
    }

    public static function createShipment(int $wid,int $cid,int $userId,array $args): array
    {
        $n=self::normalizeShipmentArgs($wid,$cid,$args);$no=$n['shipment_no']!==''?$n['shipment_no']:'SHP-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
        $dupe=pdo()->prepare("SELECT id FROM acc_trade_shipments WHERE workspace_id=? AND company_id=? AND shipment_no=? LIMIT 1");$dupe->execute([$wid,$cid,$no]);if($dupe->fetchColumn())throw new RuntimeException('شماره محموله تکراری است.');
        pdo()->prepare("INSERT INTO acc_trade_shipments (workspace_id,company_id,trade_case_id,shipment_no,mode,carrier,forwarder,tracking_no,origin_location,destination_location,etd,eta,ata,status,package_count,gross_weight_kg,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$wid,$cid,$n['trade_case_id'],$no,$n['mode'],$n['carrier']?:null,$n['forwarder']?:null,$n['tracking_no']?:null,$n['origin_location']?:null,$n['destination_location']?:null,$n['etd'],$n['eta'],$n['ata'],$n['status'],$n['package_count'],$n['gross_weight_kg'],$n['notes']?:null,$userId]);$id=(int)pdo()->lastInsertId();
        pdo()->prepare("UPDATE acc_trade_cases SET status=CASE WHEN status='planning' THEN 'in_transit' ELSE status END,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")->execute([$n['trade_case_id'],$wid,$cid]);Audit::logForWorkspace($wid,'trade.shipment.create','acc_trade_shipments',$id,'ثبت محموله '.$no,null,['trade_case_id'=>$n['trade_case_id'],'mode'=>$n['mode'],'eta'=>$n['eta']]);return ['entity'=>'acc_trade_shipments','id'=>$id,'shipment_no'=>$no,'trade_case_id'=>$n['trade_case_id'],'status'=>$n['status'],'eta'=>$n['eta']];
    }

    public static function normalizeCostArgs(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);$caseId=(int)($args['trade_case_id']??0);self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$shipmentId=(int)($args['shipment_id']??0);if($shipmentId>0){self::assertOwned($wid,$cid,'acc_trade_shipments',$shipmentId);$q=pdo()->prepare("SELECT 1 FROM acc_trade_shipments WHERE id=? AND trade_case_id=? AND workspace_id=? AND company_id=? LIMIT 1");$q->execute([$shipmentId,$caseId,$wid,$cid]);if(!$q->fetchColumn())throw new RuntimeException('محموله متعلق به این پرونده نیست.');}
        $type=strtolower(trim((string)($args['cost_type']??'')));if(!in_array($type,self::COST_TYPES,true))throw new RuntimeException('نوع هزینه بازرگانی معتبر نیست.');$basis=strtolower(trim((string)($args['basis']??'')));if(!in_array($basis,self::COST_BASES,true))throw new RuntimeException('مبنای هزینه باید estimated یا actual باشد.');$amount=(float)($args['amount']??0);if($amount<=0)throw new RuntimeException('مبلغ هزینه باید بیشتر از صفر باشد.');$currency=self::code((string)($args['currency_code']??'IRR'),'');if(!preg_match('/^[A-Z]{3}$/D',$currency))throw new RuntimeException('کد ارز هزینه نامعتبر است.');$fx=(float)($args['fx_rate_to_irr']??0);if($currency==='IRR')$fx=1.0;if($fx<=0)throw new RuntimeException('نرخ تبدیل هزینه به ریال معتبر نیست.');
        return ['trade_case_id'=>$caseId,'shipment_id'=>$shipmentId?:null,'cost_type'=>$type,'basis'=>$basis,'amount'=>$amount,'currency_code'=>$currency,'fx_rate_to_irr'=>$fx,'amount_irr'=>round($amount*$fx,2),'reference_no'=>mb_substr(trim((string)($args['reference_no']??'')),0,190),'notes'=>mb_substr(trim((string)($args['notes']??'')),0,1000)];
    }

    public static function addCost(int $wid,int $cid,int $userId,array $args): array
    {
        $n=self::normalizeCostArgs($wid,$cid,$args);pdo()->prepare("INSERT INTO acc_trade_costs (workspace_id,company_id,trade_case_id,shipment_id,cost_type,basis,amount,currency_code,fx_rate_to_irr,amount_irr,reference_no,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?,?,NOW(),NOW())")
            ->execute([$wid,$cid,$n['trade_case_id'],$n['shipment_id'],$n['cost_type'],$n['basis'],$n['amount'],$n['currency_code'],$n['fx_rate_to_irr'],$n['amount_irr'],$n['reference_no']?:null,$n['notes']?:null,$userId]);$id=(int)pdo()->lastInsertId();Audit::logForWorkspace($wid,'trade.cost.create','acc_trade_costs',$id,'ثبت هزینه بازرگانی '.$n['cost_type'],null,['trade_case_id'=>$n['trade_case_id'],'basis'=>$n['basis'],'amount_irr'=>$n['amount_irr']]);return ['entity'=>'acc_trade_costs','id'=>$id,'trade_case_id'=>$n['trade_case_id'],'cost_type'=>$n['cost_type'],'basis'=>$n['basis'],'amount_irr'=>$n['amount_irr']];
    }

    public static function updateClearance(int $wid,int $cid,int $userId,array $args): array
    {
        self::assertCompany($wid,$cid);$caseId=(int)($args['trade_case_id']??0);self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$current=self::caseRow($wid,$cid,$caseId);if(!$current)throw new RuntimeException('پرونده بازرگانی پیدا نشد.');$status=strtolower(trim((string)($args['clearance_status']??'')));if(!in_array($status,self::CLEARANCE_STATUSES,true))throw new RuntimeException('وضعیت ترخیص معتبر نیست.');$entry=AccountingRepository::date((string)($args['customs_entry_date']??''));$release=AccountingRepository::date((string)($args['customs_release_date']??''));if($entry&&$release&&$release<$entry)throw new RuntimeException('تاریخ ترخیص نمی‌تواند قبل از ورود گمرکی باشد.');$caseStatus=$status==='released'?'cleared':($status==='not_started'?(string)$current['status']:'customs');
        pdo()->prepare("UPDATE acc_trade_cases SET customs_declaration_no=?,customs_office=?,clearance_status=?,customs_entry_date=?,customs_release_date=?,status=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")
            ->execute([mb_substr(trim((string)($args['customs_declaration_no']??'')),0,120)?:null,mb_substr(trim((string)($args['customs_office']??'')),0,190)?:null,$status,$entry,$release,$caseStatus,$caseId,$wid,$cid]);Audit::logForWorkspace($wid,'trade.clearance.update','acc_trade_cases',$caseId,'به‌روزرسانی وضعیت ترخیص',null,['clearance_status'=>$status,'customs_release_date'=>$release]);return ['entity'=>'acc_trade_cases','id'=>$caseId,'clearance_status'=>$status,'status'=>$caseStatus];
    }

    public static function addMilestone(int $wid,int $cid,int $userId,array $args): array
    {
        self::assertCompany($wid,$cid);$caseId=(int)($args['trade_case_id']??0);self::assertOwned($wid,$cid,'acc_trade_cases',$caseId);$shipmentId=(int)($args['shipment_id']??0);if($shipmentId>0){self::assertOwned($wid,$cid,'acc_trade_shipments',$shipmentId);$chk=pdo()->prepare("SELECT 1 FROM acc_trade_shipments WHERE id=? AND workspace_id=? AND company_id=? AND trade_case_id=? LIMIT 1");$chk->execute([$shipmentId,$wid,$cid,$caseId]);if(!$chk->fetchColumn())throw new RuntimeException('محموله متعلق به این پرونده نیست.');}$type=strtolower(trim((string)($args['milestone_type']??'')));if(!in_array($type,self::MILESTONE_TYPES,true))throw new RuntimeException('Milestone معتبر نیست.');$planned=AccountingRepository::date((string)($args['planned_date']??''));$actual=AccountingRepository::date((string)($args['actual_date']??''));$status=$actual?'completed':'planned';pdo()->prepare("INSERT INTO acc_trade_milestones (workspace_id,company_id,trade_case_id,shipment_id,milestone_type,planned_date,actual_date,status,reference_no,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$wid,$cid,$caseId,$shipmentId?:null,$type,$planned,$actual,$status,mb_substr(trim((string)($args['reference_no']??'')),0,190)?:null,mb_substr(trim((string)($args['notes']??'')),0,1000)?:null,$userId]);$id=(int)pdo()->lastInsertId();Audit::logForWorkspace($wid,'trade.milestone.create','acc_trade_milestones',$id,'ثبت Milestone '.$type,null,['trade_case_id'=>$caseId,'actual_date'=>$actual]);return ['entity'=>'acc_trade_milestones','id'=>$id,'trade_case_id'=>$caseId,'milestone_type'=>$type,'status'=>$status];
    }

    public static function landedCostSummary(int $wid,int $cid,int $caseId): array
    {
        $case=self::caseRow($wid,$cid,$caseId);if(!$case)throw new RuntimeException('پرونده بازرگانی پیدا نشد.');$st=pdo()->prepare("SELECT l.id purchase_line_id,l.item_id,i.code item_code,i.name item_name,l.quantity,l.unit_price,l.line_total,
            COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id WHERE rl.workspace_id=l.workspace_id AND rl.purchase_line_id=l.id AND r.status='posted'),0) accepted_qty
            FROM acc_purchase_lines l JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=l.workspace_id WHERE l.workspace_id=? AND l.purchase_doc_id=? ORDER BY l.line_no");$st->execute([$wid,(int)$case['purchase_doc_id']]);$lines=$st->fetchAll();$base=0.0;foreach($lines as $l)$base+=(float)$l['line_total'];$costRows=self::costs($wid,$cid,$caseId);$by=['estimated'=>[],'actual'=>[]];foreach($costRows as $r){$b=(string)$r['basis'];$t=(string)$r['cost_type'];$by[$b][$t]=($by[$b][$t]??0)+(float)$r['amount_irr'];}$estimated=array_sum($by['estimated']);$actual=array_sum($by['actual']);$types=array_unique(array_merge(array_keys($by['estimated']),array_keys($by['actual'])));$projectedBy=[];foreach($types as $t)$projectedBy[$t]=array_key_exists($t,$by['actual'])?$by['actual'][$t]:($by['estimated'][$t]??0.0);$projected=array_sum($projectedBy);$alloc=[];$count=max(1,count($lines));$receivedBase=0.0;$receivedProjected=0.0;
        foreach($lines as $l){$lineBase=(float)$l['line_total'];$qty=(float)$l['quantity'];$weight=$base>0?$lineBase/$base:1/$count;$estAlloc=$estimated*$weight;$actAlloc=$actual*$weight;$projAlloc=$projected*$weight;$baseUnit=$qty>0?$lineBase/$qty:0.0;$projUnit=$qty>0?($lineBase+$projAlloc)/$qty:$baseUnit;$estUnit=$qty>0?($lineBase+$estAlloc)/$qty:$baseUnit;$actUnit=$qty>0?($lineBase+$actAlloc)/$qty:$baseUnit;$accepted=(float)$l['accepted_qty'];$receivedBase+=$accepted*$baseUnit;$receivedProjected+=$accepted*$projUnit;$alloc[]=['purchase_line_id'=>(int)$l['purchase_line_id'],'item_id'=>(int)$l['item_id'],'item_code'=>$l['item_code'],'item_name'=>$l['item_name'],'ordered_qty'=>$qty,'accepted_qty'=>$accepted,'purchase_base_irr'=>$lineBase,'weight'=>$weight,'estimated_trade_allocated_irr'=>$estAlloc,'actual_trade_allocated_irr'=>$actAlloc,'projected_trade_allocated_irr'=>$projAlloc,'base_unit_cost_irr'=>$baseUnit,'estimated_landed_unit_cost_irr'=>$estUnit,'actual_recorded_landed_unit_cost_irr'=>$actUnit,'projected_landed_unit_cost_irr'=>$projUnit,'received_inventory_value_projected_irr'=>$accepted*$projUnit];}
        $actualTypes=count(array_filter($types,static fn($t)=>array_key_exists($t,$by['actual'])));$coverage=count($types)>0?$actualTypes/count($types):0.0;return ['trade_case_id'=>$caseId,'case_no'=>$case['case_no'],'purchase_doc_id'=>(int)$case['purchase_doc_id'],'purchase_document_no'=>$case['purchase_document_no'],'purchase_base_irr'=>$base,'estimated_additional_irr'=>$estimated,'actual_additional_recorded_irr'=>$actual,'projected_additional_irr'=>$projected,'estimated_landed_total_irr'=>$base+$estimated,'actual_recorded_landed_total_irr'=>$base+$actual,'projected_landed_total_irr'=>$base+$projected,'actual_cost_type_coverage'=>$coverage,'estimated_by_type'=>$by['estimated'],'actual_by_type'=>$by['actual'],'projected_by_type'=>$projectedBy,'received_inventory_base_value_irr'=>$receivedBase,'received_inventory_projected_value_irr'=>$receivedProjected,'received_trade_uplift_irr'=>$receivedProjected-$receivedBase,'allocations'=>$alloc];
    }

    public static function caseSnapshot(int $wid,int $cid,int $caseId): array
    {
        $case=self::caseRow($wid,$cid,$caseId);if(!$case)throw new RuntimeException('پرونده بازرگانی پیدا نشد.');$ship=self::shipments($wid,$cid,$caseId);$cost=self::costs($wid,$cid,$caseId);$milestones=self::milestones($wid,$cid,$caseId);return ['case'=>$case,'shipments'=>array_slice($ship,0,20),'costs'=>array_slice($cost,0,50),'milestones'=>array_slice($milestones,0,30),'landed_cost'=>self::landedCostSummary($wid,$cid,$caseId)];
    }

    public static function riskSummary(int $wid,int $cid,int $limit=50): array
    {
        self::assertCompany($wid,$cid);$limit=max(1,min(100,$limit));$cases=self::searchCases($wid,$cid,'');$today=date('Y-m-d');$rows=[];
        foreach($cases as $c){if(in_array($c['status'],['closed','canceled'],true))continue;$score=0;$reasons=[];$delay=0;$eta=$c['latest_eta']??null;$ata=$c['latest_ata']??null;$shipStatus=(string)($c['latest_shipment_status']??'');if(!$c['latest_shipment_id']){$score+=2;$reasons[]='shipment_missing';}elseif($eta&&!$ata&&!in_array($shipStatus,['arrived','cleared','delivered','canceled'],true)&&$eta<$today){$delay=(int)floor((strtotime($today)-strtotime($eta))/86400);$score+=$delay>7?4:2;$reasons[]='shipment_delayed';}
            if(($c['clearance_status']??'not_started')==='hold'){$score+=4;$reasons[]='customs_hold';}elseif($c['status']==='customs'&&($c['clearance_status']??'')!=='released'){$score+=2;$reasons[]='customs_pending';}$landed=self::landedCostSummary($wid,$cid,(int)$c['id']);$est=(float)$landed['estimated_additional_irr'];$act=(float)$landed['actual_additional_recorded_irr'];$coverage=(float)$landed['actual_cost_type_coverage'];if($coverage>=0.999&&$est>0&&$act>$est*1.05){$score+=2;$reasons[]='cost_overrun';}$level=$score>=4?'high':($score>=2?'medium':'low');$rows[]=['trade_case_id'=>(int)$c['id'],'case_no'=>$c['case_no'],'purchase_document_no'=>$c['purchase_document_no'],'supplier_name'=>$c['supplier_name'],'status'=>$c['status'],'shipment_no'=>$c['latest_shipment_no'],'shipment_status'=>$shipStatus,'eta'=>$eta,'delay_days'=>$delay,'clearance_status'=>$c['clearance_status'],'projected_landed_total_irr'=>$landed['projected_landed_total_irr'],'risk_score'=>$score,'risk_level'=>$level,'reasons'=>$reasons];}
        usort($rows,static fn($a,$b)=>$b['risk_score']<=>$a['risk_score']);return ['rows'=>array_slice($rows,0,$limit),'risk_count'=>count(array_filter($rows,static fn($r)=>$r['risk_level']!=='low'))];
    }
}
