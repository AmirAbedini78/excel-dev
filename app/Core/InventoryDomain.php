<?php
/**
 * Shared Inventory + Procurement domain service.
 *
 * Source-of-truth rules:
 * - on_hand comes only from posted stock movements;
 * - reserved comes only from active reservations;
 * - expected inbound comes from goods purchase lines minus accepted receipts;
 * - rejected receipt quantity never increases stock;
 * - AI and manual UI both call this service, preventing duplicate stock logic.
 */
final class InventoryDomain
{
    public const VERSION='10.1.0';

    private static function assertCompany(int $wid,int $cid): void
    {
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);
        if(!$st->fetchColumn())throw new RuntimeException('company_not_found');
    }

    private static function assertOwned(int $wid,int $cid,string $table,int $id): void
    {
        if($id<=0||!in_array($table,['acc_warehouses','acc_items','acc_purchase_docs'],true))throw new RuntimeException('inventory_entity_invalid');
        $st=pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");
        $st->execute([$wid,$cid,$id]);
        if(!$st->fetchColumn())throw new RuntimeException('inventory_entity_not_owned');
    }

    public static function searchWarehouses(int $wid,int $cid,string $query=''): array
    {
        self::assertCompany($wid,$cid);$query=trim($query);
        if($query===''){
            $st=pdo()->prepare("SELECT id,code,name,warehouse_type,address FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND active=1 ORDER BY name LIMIT 50");
            $st->execute([$wid,$cid]);return $st->fetchAll();
        }
        $like='%'.$query.'%';$st=pdo()->prepare("SELECT id,code,name,warehouse_type,address FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND active=1 AND (name LIKE ? OR code LIKE ? OR address LIKE ?) ORDER BY name LIMIT 30");
        $st->execute([$wid,$cid,$like,$like,$like]);return $st->fetchAll();
    }

    public static function searchPurchaseDocuments(int $wid,int $cid,string $query=''): array
    {
        self::assertCompany($wid,$cid);$query=trim($query);
        $where="d.workspace_id=? AND d.company_id=? AND d.doc_type IN ('purchase_order_goods','purchase_invoice_goods') AND d.workflow_status<>'void'";$args=[$wid,$cid];
        if($query!==''){$where.=" AND (d.document_no LIKE ? OR p.name LIKE ?)";$like='%'.$query.'%';$args[]=$like;$args[]=$like;}
        $st=pdo()->prepare("SELECT d.id,d.document_no,d.doc_type,d.document_date,d.workflow_status,d.net_total,d.party_id,p.name supplier_name,d.warehouse_id,w.name warehouse_name FROM acc_purchase_docs d LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id LEFT JOIN acc_warehouses w ON w.id=d.warehouse_id AND w.workspace_id=d.workspace_id WHERE $where ORDER BY d.document_date DESC,d.id DESC LIMIT 50");
        $st->execute($args);return $st->fetchAll();
    }

    public static function purchaseDocument(int $wid,int $cid,int $purchaseDocId): ?array
    {
        self::assertCompany($wid,$cid);
        $st=pdo()->prepare("SELECT d.*,p.name supplier_name FROM acc_purchase_docs d LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id WHERE d.id=? AND d.workspace_id=? AND d.company_id=? AND d.doc_type IN ('purchase_order_goods','purchase_invoice_goods') AND d.workflow_status<>'void' LIMIT 1");
        $st->execute([$purchaseDocId,$wid,$cid]);$doc=$st->fetch();if(!$doc)return null;
        $ls=pdo()->prepare("SELECT l.*,i.code item_code,i.name item_name,u.name unit_name FROM acc_purchase_lines l JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=l.workspace_id LEFT JOIN acc_units u ON u.id=l.unit_id AND u.workspace_id=l.workspace_id WHERE l.workspace_id=? AND l.purchase_doc_id=? ORDER BY l.line_no");
        $ls->execute([$wid,$purchaseDocId]);$doc['lines']=$ls->fetchAll();return$doc;
    }

    public static function purchasePipeline(int $wid,int $cid,array $args=[]): array
    {
        self::assertCompany($wid,$cid);$docId=max(0,(int)($args['purchase_doc_id']??0));$warehouseId=max(0,(int)($args['warehouse_id']??0));$openOnly=!array_key_exists('open_only',$args)||filter_var($args['open_only'],FILTER_VALIDATE_BOOLEAN);$limit=max(1,min(300,(int)($args['limit']??100)));
        if($docId)self::assertOwned($wid,$cid,'acc_purchase_docs',$docId);if($warehouseId)self::assertOwned($wid,$cid,'acc_warehouses',$warehouseId);
        $where=["d.workspace_id=?","d.company_id=?","d.doc_type IN ('purchase_order_goods','purchase_invoice_goods')","d.workflow_status<>'void'"];$params=[$wid,$cid];
        if($docId){$where[]='d.id=?';$params[]=$docId;}
        if($warehouseId){$where[]='COALESCE(l.warehouse_id,d.warehouse_id)=?';$params[]=$warehouseId;}
        $sql="SELECT d.id purchase_doc_id,d.document_no,d.doc_type,d.document_date,d.workflow_status,d.party_id,p.name supplier_name,d.warehouse_id document_warehouse_id,l.id purchase_line_id,l.line_no,l.item_id,i.code item_code,i.name item_name,l.quantity ordered_qty,l.unit_price,l.line_total,COALESCE(l.warehouse_id,d.warehouse_id) planned_warehouse_id,w.name planned_warehouse_name,
            COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id WHERE rl.workspace_id=l.workspace_id AND rl.purchase_line_id=l.id AND r.status='posted'),0) accepted_qty,
            COALESCE((SELECT SUM(rl.rejected_qty) FROM acc_inventory_receipt_lines rl JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id WHERE rl.workspace_id=l.workspace_id AND rl.purchase_line_id=l.id AND r.status='posted'),0) rejected_qty
            FROM acc_purchase_lines l JOIN acc_purchase_docs d ON d.id=l.purchase_doc_id AND d.workspace_id=l.workspace_id JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=l.workspace_id LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id LEFT JOIN acc_warehouses w ON w.id=COALESCE(l.warehouse_id,d.warehouse_id) AND w.workspace_id=l.workspace_id WHERE ".implode(' AND ',$where)." ORDER BY d.document_date DESC,d.id DESC,l.line_no LIMIT $limit";
        $st=pdo()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();$out=[];$totalOpen=0.0;
        foreach($rows as $r){$ordered=(float)$r['ordered_qty'];$accepted=(float)$r['accepted_qty'];$open=max(0,$ordered-$accepted);$r['expected_inbound']=$open;$r['fully_received']=$open<=0.000001;if($openOnly&&$r['fully_received'])continue;$totalOpen+=$open;$out[]=$r;}
        return ['rows'=>$out,'open_line_count'=>count($out),'total_open_quantity'=>$totalOpen];
    }

    public static function inventoryPosition(int $wid,int $cid,array $args=[]): array
    {
        self::assertCompany($wid,$cid);$itemId=max(0,(int)($args['item_id']??0));$warehouseId=max(0,(int)($args['warehouse_id']??0));$limit=max(1,min(300,(int)($args['limit']??100)));
        if($itemId)self::assertOwned($wid,$cid,'acc_items',$itemId);if($warehouseId)self::assertOwned($wid,$cid,'acc_warehouses',$warehouseId);
        $where='workspace_id=? AND company_id=? AND active=1';$params=[$wid,$cid];if($itemId){$where.=' AND id=?';$params[]=$itemId;}
        $st=pdo()->prepare("SELECT id,code,name,item_type,min_stock,max_stock,purchase_price_1 FROM acc_items WHERE $where ORDER BY name LIMIT $limit");$st->execute($params);$items=$st->fetchAll();if(!$items)return ['rows'=>[],'warehouse_id'=>$warehouseId?:null];
        $ids=array_map(static fn($r)=>(int)$r['id'],$items);$ph=implode(',',array_fill(0,count($ids),'?'));
        $moveWhere="workspace_id=? AND company_id=? AND status='posted' AND item_id IN ($ph)";$moveParams=array_merge([$wid,$cid],$ids);if($warehouseId){$moveWhere.=' AND warehouse_id=?';$moveParams[]=$warehouseId;}
        $m=pdo()->prepare("SELECT item_id,SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END) on_hand FROM acc_stock_movements WHERE $moveWhere GROUP BY item_id");$m->execute($moveParams);$on=[];foreach($m->fetchAll() as $r)$on[(int)$r['item_id']]=(float)$r['on_hand'];
        $resWhere="workspace_id=? AND company_id=? AND status='active' AND item_id IN ($ph)";$resParams=array_merge([$wid,$cid],$ids);if($warehouseId){$resWhere.=' AND warehouse_id=?';$resParams[]=$warehouseId;}
        $rs=pdo()->prepare("SELECT item_id,SUM(quantity) reserved FROM acc_inventory_reservations WHERE $resWhere GROUP BY item_id");$rs->execute($resParams);$reserved=[];foreach($rs->fetchAll() as $r)$reserved[(int)$r['item_id']]=(float)$r['reserved'];
        $pipeline=self::purchasePipeline($wid,$cid,['warehouse_id'=>$warehouseId,'open_only'=>true,'limit'=>300]);$expected=[];foreach($pipeline['rows'] as $r){$iid=(int)$r['item_id'];if(in_array($iid,$ids,true))$expected[$iid]=($expected[$iid]??0)+(float)$r['expected_inbound'];}
        $rows=[];foreach($items as $i){$iid=(int)$i['id'];$oh=(float)($on[$iid]??0);$rv=(float)($reserved[$iid]??0);$av=$oh-$rv;$exp=(float)($expected[$iid]??0);$projected=$av+$exp;$min=(float)$i['min_stock'];$max=(float)$i['max_stock'];$target=$max>0?$max:$min;$suggested=max(0,$target-$projected);$rows[]=$i+['on_hand'=>$oh,'reserved'=>$rv,'available'=>$av,'expected_inbound'=>$exp,'projected_available'=>$projected,'shortage'=>$min>0&&$projected+0.000001<$min,'suggested_replenishment'=>$suggested];}
        return ['warehouse_id'=>$warehouseId?:null,'rows'=>$rows];
    }

    public static function replenishmentRisk(int $wid,int $cid,array $args=[]): array
    {
        $warehouseId=max(0,(int)($args['warehouse_id']??0));$limit=max(1,min(100,(int)($args['limit']??30)));$pos=self::inventoryPosition($wid,$cid,['warehouse_id'=>$warehouseId,'limit'=>300]);$rows=array_values(array_filter($pos['rows'],static fn($r)=>!empty($r['shortage'])));usort($rows,static fn($a,$b)=>(float)$b['suggested_replenishment']<=>(float)$a['suggested_replenishment']);$rows=array_slice($rows,0,$limit);return ['warehouse_id'=>$warehouseId?:null,'shortage_count'=>count($rows),'rows'=>$rows];
    }

    public static function validateReceiptArgs(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);$docId=(int)($args['purchase_doc_id']??0);$warehouseId=(int)($args['warehouse_id']??0);if($docId<=0||$warehouseId<=0)throw new RuntimeException('سند خرید و انبار برای رسید الزامی است.');self::assertOwned($wid,$cid,'acc_purchase_docs',$docId);self::assertOwned($wid,$cid,'acc_warehouses',$warehouseId);
        $doc=self::purchaseDocument($wid,$cid,$docId);if(!$doc)throw new RuntimeException('سند خرید کالایی معتبر پیدا نشد.');$date=AccountingRepository::date((string)($args['receipt_date']??''))?:date('Y-m-d');$requested=(array)($args['lines']??[]);if(!$requested)throw new RuntimeException('حداقل یک ردیف دریافت لازم است.');
        $pipeline=self::purchasePipeline($wid,$cid,['purchase_doc_id'=>$docId,'open_only'=>false,'limit'=>300]);$map=[];foreach($pipeline['rows'] as $r)$map[(int)$r['purchase_line_id']]=$r;$lines=[];
        foreach($requested as $raw){$lineId=(int)($raw['purchase_line_id']??0);if(!$lineId||!isset($map[$lineId]))throw new RuntimeException('ردیف خرید برای این سند معتبر نیست.');$accepted=max(0,(float)($raw['accepted_qty']??0));$rejected=max(0,(float)($raw['rejected_qty']??0));if($accepted+$rejected<=0)continue;$remaining=max(0,(float)$map[$lineId]['ordered_qty']-(float)$map[$lineId]['accepted_qty']);if($accepted+$rejected>$remaining+0.000001)throw new RuntimeException('مقدار دریافت از باقیمانده خرید بیشتر است.');$lines[]=['purchase_line_id'=>$lineId,'item_id'=>(int)$map[$lineId]['item_id'],'item_code'=>$map[$lineId]['item_code'],'item_name'=>$map[$lineId]['item_name'],'expected_qty'=>$remaining,'accepted_qty'=>$accepted,'rejected_qty'=>$rejected,'unit_cost'=>(float)$map[$lineId]['unit_price'],'notes'=>mb_substr(trim((string)($raw['notes']??'')),0,500)];}
        if(!$lines)throw new RuntimeException('هیچ مقدار دریافت معتبری ثبت نشده است.');return ['purchase_doc_id'=>$docId,'warehouse_id'=>$warehouseId,'receipt_date'=>$date,'supplier_id'=>(int)$doc['party_id'],'supplier_name'=>$doc['supplier_name']??'','document_no'=>$doc['document_no'],'lines'=>$lines,'notes'=>mb_substr(trim((string)($args['notes']??'')),0,1000)];
    }

    public static function createReceipt(int $wid,int $cid,int $userId,array $args): array
    {
        self::assertCompany($wid,$cid);$pdo=pdo();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{
            $docId=(int)($args['purchase_doc_id']??0);$lock=$pdo->prepare("SELECT id FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");$lock->execute([$docId,$wid,$cid]);if(!$lock->fetchColumn())throw new RuntimeException('سند خرید برای دریافت پیدا نشد.');$norm=self::validateReceiptArgs($wid,$cid,$args);$no='RCV-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
            $pdo->prepare("INSERT INTO acc_inventory_receipts (workspace_id,company_id,receipt_no,receipt_date,warehouse_id,source_type,purchase_doc_id,supplier_id,status,notes,created_by,approved_by,posted_at,created_at,updated_at) VALUES (?,?,?,?,?,'purchase',?,?, 'posted',?,?,?,?,NOW(),NOW())")
                ->execute([$wid,$cid,$no,$norm['receipt_date'],$norm['warehouse_id'],$norm['purchase_doc_id'],$norm['supplier_id'],$norm['notes'],$userId,$userId,date('Y-m-d H:i:s')]);$receiptId=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare("INSERT INTO acc_inventory_receipt_lines (workspace_id,receipt_id,line_no,purchase_line_id,item_id,expected_qty,received_qty,accepted_qty,rejected_qty,unit_cost,line_total,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");$move=$pdo->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at) VALUES (?,?,?,'purchase_receipt','in',?,?,?,?, 'inventory_receipt',?,?,?,'posted',?,NOW())");$n=1;$acceptedTotal=0.0;$rejectedTotal=0.0;
            foreach($norm['lines'] as $l){$received=$l['accepted_qty']+$l['rejected_qty'];$total=$l['accepted_qty']*$l['unit_cost'];$ins->execute([$wid,$receiptId,$n++,$l['purchase_line_id'],$l['item_id'],$l['expected_qty'],$received,$l['accepted_qty'],$l['rejected_qty'],$l['unit_cost'],$total,$l['notes']]);$receiptLineId=(int)$pdo->lastInsertId();if($l['accepted_qty']>0)$move->execute([$wid,$cid,$norm['receipt_date'],$norm['warehouse_id'],$l['item_id'],$l['accepted_qty'],$l['unit_cost'],$receiptId,$receiptLineId,$no,$userId]);$acceptedTotal+=$l['accepted_qty'];$rejectedTotal+=$l['rejected_qty'];}
            if($ownsTransaction)$pdo->commit();return ['entity'=>'acc_inventory_receipts','id'=>$receiptId,'receipt_no'=>$no,'status'=>'posted','purchase_doc_id'=>$norm['purchase_doc_id'],'purchase_document_no'=>$norm['document_no'],'warehouse_id'=>$norm['warehouse_id'],'accepted_quantity'=>$acceptedTotal,'rejected_quantity'=>$rejectedTotal];
        }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function receipts(int $wid,int $cid,int $limit=50): array
    {
        self::assertCompany($wid,$cid);$limit=max(1,min(200,$limit));$st=pdo()->prepare("SELECT r.*,w.name warehouse_name,d.document_no purchase_document_no,p.name supplier_name,COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl WHERE rl.workspace_id=r.workspace_id AND rl.receipt_id=r.id),0) accepted_quantity,COALESCE((SELECT SUM(rl.rejected_qty) FROM acc_inventory_receipt_lines rl WHERE rl.workspace_id=r.workspace_id AND rl.receipt_id=r.id),0) rejected_quantity FROM acc_inventory_receipts r JOIN acc_warehouses w ON w.id=r.warehouse_id AND w.workspace_id=r.workspace_id LEFT JOIN acc_purchase_docs d ON d.id=r.purchase_doc_id AND d.workspace_id=r.workspace_id LEFT JOIN acc_parties p ON p.id=r.supplier_id AND p.workspace_id=r.workspace_id WHERE r.workspace_id=? AND r.company_id=? ORDER BY r.receipt_date DESC,r.id DESC LIMIT $limit");$st->execute([$wid,$cid]);return$st->fetchAll();
    }
}
