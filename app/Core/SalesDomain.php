<?php
/**
 * ERPSMART v10.3 Sales Fulfillment domain.
 *
 * Invariants:
 * - canonical commercial documents remain acc_sales_docs / acc_sales_lines;
 * - reservations reuse acc_inventory_reservations and never move stock;
 * - delivery is the only Cycle-6 operation that posts outbound stock movement;
 * - landed-cost valuation is an overlay/read bridge; inbound history is never rewritten;
 * - no GL voucher or invoice finalization is performed by this domain.
 */
final class SalesDomain
{
    public const VERSION='10.3.0';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS acc_sales_deliveries (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            delivery_no VARCHAR(120) NOT NULL,
            delivery_date DATE NOT NULL,
            sales_doc_id BIGINT NOT NULL,
            warehouse_id BIGINT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'posted',
            notes VARCHAR(1000) NULL,
            created_by INT NULL,
            posted_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_sales_delivery_no (workspace_id,company_id,delivery_no),
            INDEX idx_acc_sales_delivery_doc (workspace_id,company_id,sales_doc_id,status,delivery_date),
            INDEX idx_acc_sales_delivery_warehouse (workspace_id,company_id,warehouse_id,status,delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS acc_sales_delivery_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            delivery_id BIGINT NOT NULL,
            sales_line_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
            unit_cost_irr DECIMAL(20,2) NOT NULL DEFAULT 0,
            cost_basis VARCHAR(40) NOT NULL DEFAULT 'unavailable',
            line_cost_irr DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            INDEX idx_acc_sales_delivery_line (workspace_id,delivery_id,sales_line_id),
            INDEX idx_acc_sales_delivery_item (workspace_id,item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    }

    private static function assertCompany(int $wid,int $cid): void
    {
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);
        if(!$st->fetchColumn())throw new RuntimeException('company_not_found');
    }

    private static function assertOwned(int $wid,int $cid,string $table,int $id): void
    {
        if($id<=0||!in_array($table,['acc_sales_docs','acc_warehouses','acc_items','acc_sales_deliveries'],true))
            throw new RuntimeException('sales_entity_invalid');
        $st=pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");
        $st->execute([$wid,$cid,$id]);
        if(!$st->fetchColumn())throw new RuntimeException('sales_entity_not_owned');
    }

    public static function searchDocuments(int $wid,int $cid,string $query=''): array
    {
        self::assertCompany($wid,$cid);
        $query=trim($query);$params=[$wid,$cid];$where="d.workspace_id=? AND d.company_id=? AND d.doc_type IN ('invoice','preinvoice')";
        if($query!==''){
            $like='%'.$query.'%';
            $where.=" AND (d.document_no LIKE ? OR p.name LIKE ? OR p.code LIKE ?)";
            array_push($params,$like,$like,$like);
        }
        $st=pdo()->prepare("SELECT d.id,d.document_no,d.document_date,d.doc_type,d.workflow_status,d.party_id,p.code party_code,p.name party_name,d.warehouse_id,d.net_total,d.tax_total,
            COALESCE((SELECT SUM(dl.quantity) FROM acc_sales_delivery_lines dl JOIN acc_sales_deliveries dd ON dd.id=dl.delivery_id AND dd.workspace_id=dl.workspace_id WHERE dl.workspace_id=d.workspace_id AND dd.company_id=d.company_id AND dd.sales_doc_id=d.id AND dd.status='posted'),0) delivered_quantity,
            COALESCE((SELECT SUM(r.quantity) FROM acc_inventory_reservations r WHERE r.workspace_id=d.workspace_id AND r.company_id=d.company_id AND r.source_type='sales_doc' AND r.source_id=d.id AND r.status='active'),0) reserved_quantity
            FROM acc_sales_docs d
            LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id
            WHERE $where ORDER BY d.document_date DESC,d.id DESC LIMIT 100");
        $st->execute($params);return $st->fetchAll();
    }

    public static function document(int $wid,int $cid,int $salesDocId): ?array
    {
        self::assertCompany($wid,$cid);self::assertOwned($wid,$cid,'acc_sales_docs',$salesDocId);
        $st=pdo()->prepare("SELECT d.*,p.code party_code,p.name party_name,w.code warehouse_code,w.name warehouse_name
            FROM acc_sales_docs d
            LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id
            LEFT JOIN acc_warehouses w ON w.id=d.warehouse_id AND w.workspace_id=d.workspace_id
            WHERE d.id=? AND d.workspace_id=? AND d.company_id=? LIMIT 1");
        $st->execute([$salesDocId,$wid,$cid]);$doc=$st->fetch();if(!$doc)return null;
        $st=pdo()->prepare("SELECT l.*,i.code item_code,i.name item_name,i.item_type
            FROM acc_sales_lines l JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND l.sales_doc_id=? ORDER BY l.line_no");
        $st->execute([$wid,$salesDocId]);$doc['lines']=$st->fetchAll();return $doc;
    }

    private static function deliveredByLine(int $wid,int $cid,int $salesDocId): array
    {
        $st=pdo()->prepare("SELECT dl.sales_line_id,SUM(dl.quantity) quantity
            FROM acc_sales_delivery_lines dl
            JOIN acc_sales_deliveries d ON d.id=dl.delivery_id AND d.workspace_id=dl.workspace_id
            WHERE dl.workspace_id=? AND d.company_id=? AND d.sales_doc_id=? AND d.status='posted'
            GROUP BY dl.sales_line_id");
        $st->execute([$wid,$cid,$salesDocId]);$out=[];
        foreach($st->fetchAll() as $r)$out[(int)$r['sales_line_id']]=(float)$r['quantity'];
        return $out;
    }

    private static function reservationByItem(int $wid,int $cid,int $salesDocId,int $warehouseId=0): array
    {
        $params=[$wid,$cid,$salesDocId];$where="workspace_id=? AND company_id=? AND source_type='sales_doc' AND source_id=? AND status='active'";
        if($warehouseId>0){$where.=" AND warehouse_id=?";$params[]=$warehouseId;}
        $st=pdo()->prepare("SELECT item_id,SUM(quantity) quantity,MIN(warehouse_id) warehouse_id FROM acc_inventory_reservations WHERE $where GROUP BY item_id");
        $st->execute($params);$out=[];
        foreach($st->fetchAll() as $r)$out[(int)$r['item_id']]=['quantity'=>(float)$r['quantity'],'warehouse_id'=>(int)$r['warehouse_id']];
        return $out;
    }

    private static function inferWarehouse(int $wid,int $cid,int $salesDocId,int $docWarehouseId): int
    {
        if($docWarehouseId>0)return $docWarehouseId;
        $st=pdo()->prepare("SELECT warehouse_id FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type='sales_doc' AND source_id=? AND status='active' ORDER BY id LIMIT 1");
        $st->execute([$wid,$cid,$salesDocId]);return (int)($st->fetchColumn()?:0);
    }

    public static function fulfillment(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);$salesDocId=(int)($args['sales_doc_id']??0);
        $doc=self::document($wid,$cid,$salesDocId);if(!$doc)throw new RuntimeException('سند فروش پیدا نشد.');
        $warehouseId=max(0,(int)($args['warehouse_id']??0));
        if(!$warehouseId)$warehouseId=self::inferWarehouse($wid,$cid,$salesDocId,(int)($doc['warehouse_id']??0));
        if($warehouseId)self::assertOwned($wid,$cid,'acc_warehouses',$warehouseId);
        $delivered=self::deliveredByLine($wid,$cid,$salesDocId);
        $reserved=self::reservationByItem($wid,$cid,$salesDocId,$warehouseId);
        $positionMap=[];
        if($warehouseId){
            $pos=InventoryDomain::inventoryPosition($wid,$cid,['warehouse_id'=>$warehouseId,'limit'=>300]);
            foreach($pos['rows'] as $r)$positionMap[(int)$r['id']]=$r;
        }
        $remainingReserved=[];foreach($reserved as $iid=>$r)$remainingReserved[$iid]=(float)$r['quantity'];
        $rows=[];$orderedTotal=0.0;$deliveredTotal=0.0;$reservedTotal=0.0;$outstandingTotal=0.0;
        foreach((array)$doc['lines'] as $line){
            $lineId=(int)$line['id'];$itemId=(int)$line['item_id'];$ordered=(float)$line['quantity'];
            $done=min($ordered,(float)($delivered[$lineId]??0));$outstanding=max(0,$ordered-$done);
            $alloc=min($outstanding,max(0,(float)($remainingReserved[$itemId]??0)));
            $remainingReserved[$itemId]=max(0,(float)($remainingReserved[$itemId]??0)-$alloc);
            $p=$positionMap[$itemId]??[];
            $rows[]=[
                'sales_line_id'=>$lineId,'line_no'=>(int)$line['line_no'],'item_id'=>$itemId,
                'item_code'=>$line['item_code'],'item_name'=>$line['item_name'],'ordered_qty'=>$ordered,
                'delivered_qty'=>$done,'outstanding_qty'=>$outstanding,'reserved_qty'=>$alloc,
                'warehouse_id'=>$warehouseId?:null,'on_hand'=>(float)($p['on_hand']??0),
                'available'=>(float)($p['available']??0),'unit_price'=>(float)$line['unit_price'],
                'discount_amount'=>(float)$line['discount_amount'],'tax_amount'=>(float)$line['tax_amount'],
                'line_total'=>(float)$line['line_total']
            ];
            $orderedTotal+=$ordered;$deliveredTotal+=$done;$reservedTotal+=$alloc;$outstandingTotal+=$outstanding;
        }
        return [
            'sales_doc_id'=>$salesDocId,'document_no'=>$doc['document_no'],'document_date'=>$doc['document_date'],
            'customer_id'=>(int)$doc['party_id'],'customer_name'=>$doc['party_name']??'',
            'workflow_status'=>$doc['workflow_status'],'warehouse_id'=>$warehouseId?:null,
            'ordered_quantity'=>$orderedTotal,'delivered_quantity'=>$deliveredTotal,
            'reserved_quantity'=>$reservedTotal,'outstanding_quantity'=>$outstandingTotal,'rows'=>$rows
        ];
    }

    public static function normalizeReservationArgs(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);$salesDocId=(int)($args['sales_doc_id']??0);$warehouseId=(int)($args['warehouse_id']??0);
        self::assertOwned($wid,$cid,'acc_sales_docs',$salesDocId);self::assertOwned($wid,$cid,'acc_warehouses',$warehouseId);
        $doc=self::document($wid,$cid,$salesDocId);
        if(!$doc||!in_array((string)$doc['doc_type'],['invoice','preinvoice'],true)||in_array((string)$doc['workflow_status'],['void'],true))
            throw new RuntimeException('سند فروش برای رزرو معتبر نیست.');
        $requested=(array)($args['lines']??[]);if(!$requested)throw new RuntimeException('حداقل یک ردیف رزرو لازم است.');
        $ful=self::fulfillment($wid,$cid,['sales_doc_id'=>$salesDocId,'warehouse_id'=>$warehouseId]);$map=[];
        foreach($ful['rows'] as $r)$map[(int)$r['sales_line_id']]=$r;
        $lines=[];$byItem=[];
        foreach($requested as $raw){
            $lineId=(int)($raw['sales_line_id']??0);$qty=(float)($raw['quantity']??0);
            if(!$lineId||!isset($map[$lineId]))throw new RuntimeException('ردیف فروش معتبر نیست.');
            if($qty<=0||$qty>(float)$map[$lineId]['outstanding_qty']+0.000001)throw new RuntimeException('مقدار رزرو از باقیمانده فروش بیشتر است.');
            $itemId=(int)$map[$lineId]['item_id'];$lines[]=['sales_line_id'=>$lineId,'item_id'=>$itemId,'quantity'=>$qty];
            $byItem[$itemId]=($byItem[$itemId]??0)+$qty;
        }
        $current=self::reservationByItem($wid,$cid,$salesDocId,$warehouseId);
        $pos=InventoryDomain::inventoryPosition($wid,$cid,['warehouse_id'=>$warehouseId,'limit'=>300]);$positions=[];
        foreach($pos['rows'] as $r)$positions[(int)$r['id']]=$r;
        foreach($byItem as $itemId=>$qty){
            $available=(float)($positions[$itemId]['available']??0);$own=(float)($current[$itemId]['quantity']??0);
            if($qty>$available+$own+0.000001)throw new RuntimeException('موجودی قابل رزرو برای یکی از اقلام کافی نیست.');
        }
        return ['sales_doc_id'=>$salesDocId,'warehouse_id'=>$warehouseId,'document_no'=>$doc['document_no'],'lines'=>$lines,'by_item'=>$byItem,'notes'=>mb_substr(trim((string)($args['notes']??'')),0,500)];
    }

    public static function reserveStock(int $wid,int $cid,int $userId,array $args): array
    {
        self::assertCompany($wid,$cid);$pdo=pdo();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
        try{
            $salesDocId=(int)($args['sales_doc_id']??0);
            $lock=$pdo->prepare("SELECT id FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");
            $lock->execute([$salesDocId,$wid,$cid]);if(!$lock->fetchColumn())throw new RuntimeException('سند فروش پیدا نشد.');
            $norm=self::normalizeReservationArgs($wid,$cid,$args);
            $pdo->prepare("DELETE FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type='sales_doc' AND source_id=? AND status='active'")->execute([$wid,$cid,$salesDocId]);
            $ins=$pdo->prepare("INSERT INTO acc_inventory_reservations (workspace_id,company_id,warehouse_id,item_id,source_type,source_id,quantity,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,'sales_doc',?,?,'active',?,?,NOW(),NOW())");
            $qty=0.0;
            foreach($norm['by_item'] as $itemId=>$amount){$ins->execute([$wid,$cid,$norm['warehouse_id'],$itemId,$salesDocId,$amount,$norm['notes']?:('رزرو فروش '.$norm['document_no']),$userId]);$qty+=$amount;}
            if($owns)$pdo->commit();
            Audit::logForWorkspace($wid,'sales.reserve','acc_sales_docs',$salesDocId,'رزرو موجودی برای '.$norm['document_no'],null,['warehouse_id'=>$norm['warehouse_id'],'quantity'=>$qty]);
            return ['entity'=>'acc_inventory_reservations','sales_doc_id'=>$salesDocId,'document_no'=>$norm['document_no'],'warehouse_id'=>$norm['warehouse_id'],'reserved_quantity'=>$qty,'status'=>'active'];
        }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function normalizeDeliveryArgs(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);$salesDocId=(int)($args['sales_doc_id']??0);$warehouseId=(int)($args['warehouse_id']??0);
        self::assertOwned($wid,$cid,'acc_sales_docs',$salesDocId);self::assertOwned($wid,$cid,'acc_warehouses',$warehouseId);
        $doc=self::document($wid,$cid,$salesDocId);
        if(!$doc||!in_array((string)$doc['doc_type'],['invoice','preinvoice'],true)||in_array((string)$doc['workflow_status'],['void'],true))
            throw new RuntimeException('سند فروش برای تحویل معتبر نیست.');
        $date=AccountingRepository::date((string)($args['delivery_date']??''))?:date('Y-m-d');
        $requested=(array)($args['lines']??[]);if(!$requested)throw new RuntimeException('حداقل یک ردیف تحویل لازم است.');
        $ful=self::fulfillment($wid,$cid,['sales_doc_id'=>$salesDocId,'warehouse_id'=>$warehouseId]);$map=[];
        foreach($ful['rows'] as $r)$map[(int)$r['sales_line_id']]=$r;
        $lines=[];$byItem=[];
        foreach($requested as $raw){
            $lineId=(int)($raw['sales_line_id']??0);$qty=(float)($raw['quantity']??0);
            if(!$lineId||!isset($map[$lineId]))throw new RuntimeException('ردیف فروش برای تحویل معتبر نیست.');
            if($qty<=0||$qty>(float)$map[$lineId]['outstanding_qty']+0.000001)throw new RuntimeException('مقدار تحویل از باقیمانده فروش بیشتر است.');
            $itemId=(int)$map[$lineId]['item_id'];$lines[]=['sales_line_id'=>$lineId,'item_id'=>$itemId,'quantity'=>$qty,'item_code'=>$map[$lineId]['item_code'],'item_name'=>$map[$lineId]['item_name']];
            $byItem[$itemId]=($byItem[$itemId]??0)+$qty;
        }
        $reserved=self::reservationByItem($wid,$cid,$salesDocId,$warehouseId);
        $pos=InventoryDomain::inventoryPosition($wid,$cid,['warehouse_id'=>$warehouseId,'limit'=>300]);$positions=[];
        foreach($pos['rows'] as $r)$positions[(int)$r['id']]=$r;
        foreach($byItem as $itemId=>$qty){
            if($qty>(float)($reserved[$itemId]['quantity']??0)+0.000001)throw new RuntimeException('تحویل فقط از موجودی رزروشده همین سند مجاز است.');
            if($qty>(float)($positions[$itemId]['on_hand']??0)+0.000001)throw new RuntimeException('موجودی فیزیکی برای تحویل کافی نیست.');
        }
        return ['sales_doc_id'=>$salesDocId,'warehouse_id'=>$warehouseId,'document_no'=>$doc['document_no'],'delivery_date'=>$date,'lines'=>$lines,'by_item'=>$byItem,'notes'=>mb_substr(trim((string)($args['notes']??'')),0,1000)];
    }

    private static function costBasis(int $wid,int $cid,int $itemId,int $warehouseId=0): array
    {
        self::assertOwned($wid,$cid,'acc_items',$itemId);
        $st=pdo()->prepare("SELECT DISTINCT c.id FROM acc_trade_cases c JOIN acc_purchase_lines l ON l.purchase_doc_id=c.purchase_doc_id AND l.workspace_id=c.workspace_id WHERE c.workspace_id=? AND c.company_id=? AND l.item_id=? ORDER BY c.id DESC LIMIT 50");
        $st->execute([$wid,$cid,$itemId]);$weightedProjected=0.0;$weightedActual=0.0;$qty=0.0;$allActual=true;$cases=[];
        foreach($st->fetchAll(PDO::FETCH_COLUMN) as $caseId){
            $land=TradeDomain::landedCostSummary($wid,$cid,(int)$caseId);
            foreach((array)$land['allocations'] as $a){
                if((int)$a['item_id']!==$itemId||(float)$a['accepted_qty']<=0)continue;
                $q=(float)$a['accepted_qty'];$qty+=$q;
                $weightedProjected+=$q*(float)$a['projected_landed_unit_cost_irr'];
                $weightedActual+=$q*(float)$a['actual_recorded_landed_unit_cost_irr'];
                if((float)$land['actual_cost_type_coverage']<0.999)$allActual=false;
                $cases[]=$land['case_no'];
            }
        }
        if($qty>0){
            $projected=$weightedProjected/$qty;$actual=$allActual?$weightedActual/$qty:null;
            return ['unit_cost_irr'=>$actual!==null?$actual:$projected,'cost_basis'=>$actual!==null?'actual_landed':'projected_landed','projected_unit_cost_irr'=>$projected,'actual_unit_cost_irr'=>$actual,'actual_available'=>$actual!==null,'trade_cases'=>array_values(array_unique($cases))];
        }

        $params=[$wid,$cid,$itemId];$where="workspace_id=? AND company_id=? AND item_id=? AND direction='in' AND status='posted' AND quantity>0";
        if($warehouseId>0){$where.=" AND warehouse_id=?";$params[]=$warehouseId;}
        $st=pdo()->prepare("SELECT COALESCE(SUM(quantity*unit_cost)/NULLIF(SUM(quantity),0),0) FROM acc_stock_movements WHERE $where");
        $st->execute($params);$base=(float)$st->fetchColumn();
        if($base>0)return ['unit_cost_irr'=>$base,'cost_basis'=>'stock_base_fallback','projected_unit_cost_irr'=>$base,'actual_unit_cost_irr'=>null,'actual_available'=>false,'trade_cases'=>[]];
        $st=pdo()->prepare("SELECT purchase_price_1 FROM acc_items WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");$st->execute([$itemId,$wid,$cid]);$price=(float)$st->fetchColumn();
        return ['unit_cost_irr'=>$price,'cost_basis'=>$price>0?'item_purchase_price_fallback':'unavailable','projected_unit_cost_irr'=>$price,'actual_unit_cost_irr'=>null,'actual_available'=>false,'trade_cases'=>[]];
    }

    public static function deliverStock(int $wid,int $cid,int $userId,array $args): array
    {
        self::assertCompany($wid,$cid);$pdo=pdo();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
        try{
            $salesDocId=(int)($args['sales_doc_id']??0);
            $lock=$pdo->prepare("SELECT id FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");
            $lock->execute([$salesDocId,$wid,$cid]);if(!$lock->fetchColumn())throw new RuntimeException('سند فروش پیدا نشد.');
            $resLock=$pdo->prepare("SELECT id FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type='sales_doc' AND source_id=? AND status='active' FOR UPDATE");
            $resLock->execute([$wid,$cid,$salesDocId]);$resLock->fetchAll();
            $norm=self::normalizeDeliveryArgs($wid,$cid,$args);
            $no='DLV-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
            $pdo->prepare("INSERT INTO acc_sales_deliveries (workspace_id,company_id,delivery_no,delivery_date,sales_doc_id,warehouse_id,status,notes,created_by,posted_at,created_at,updated_at) VALUES (?,?,?,?,?,?,'posted',?,?,NOW(),NOW(),NOW())")
                ->execute([$wid,$cid,$no,$norm['delivery_date'],$salesDocId,$norm['warehouse_id'],$norm['notes']?:null,$userId]);
            $deliveryId=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare("INSERT INTO acc_sales_delivery_lines (workspace_id,delivery_id,sales_line_id,item_id,quantity,unit_cost_irr,cost_basis,line_cost_irr,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
            $move=$pdo->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at) VALUES (?,?,?,'sales_delivery','out',?,?,?,?, 'sales_delivery',?,?,?,'posted',?,NOW())");
            $qtyTotal=0.0;$costTotal=0.0;$basisCache=[];
            foreach($norm['lines'] as $l){
                $itemId=(int)$l['item_id'];if(!isset($basisCache[$itemId]))$basisCache[$itemId]=self::costBasis($wid,$cid,$itemId,$norm['warehouse_id']);
                $basis=$basisCache[$itemId];$lineCost=(float)$l['quantity']*(float)$basis['unit_cost_irr'];
                $ins->execute([$wid,$deliveryId,$l['sales_line_id'],$itemId,$l['quantity'],$basis['unit_cost_irr'],$basis['cost_basis'],$lineCost]);
                $deliveryLineId=(int)$pdo->lastInsertId();
                $move->execute([$wid,$cid,$norm['delivery_date'],$norm['warehouse_id'],$itemId,$l['quantity'],$basis['unit_cost_irr'],$deliveryId,$deliveryLineId,$no,$userId]);
                $qtyTotal+=(float)$l['quantity'];$costTotal+=$lineCost;
            }
            foreach($norm['by_item'] as $itemId=>$need){
                $st=$pdo->prepare("SELECT id,quantity FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type='sales_doc' AND source_id=? AND warehouse_id=? AND item_id=? AND status='active' ORDER BY id FOR UPDATE");
                $st->execute([$wid,$cid,$salesDocId,$norm['warehouse_id'],$itemId]);$remaining=(float)$need;
                foreach($st->fetchAll() as $r){
                    if($remaining<=0)break;$take=min($remaining,(float)$r['quantity']);$new=(float)$r['quantity']-$take;$remaining-=$take;
                    if($new<=0.000001)$pdo->prepare("UPDATE acc_inventory_reservations SET quantity=0,status='consumed',updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([(int)$r['id'],$wid]);
                    else $pdo->prepare("UPDATE acc_inventory_reservations SET quantity=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$new,(int)$r['id'],$wid]);
                }
                if($remaining>0.000001)throw new RuntimeException('رزرو همزمان تغییر کرده است؛ تحویل متوقف شد.');
            }
            if($owns)$pdo->commit();
            Audit::logForWorkspace($wid,'sales.delivery','acc_sales_deliveries',$deliveryId,'تحویل فروش '.$no,null,['sales_doc_id'=>$salesDocId,'quantity'=>$qtyTotal,'cost_irr'=>$costTotal]);
            return ['entity'=>'acc_sales_deliveries','id'=>$deliveryId,'delivery_no'=>$no,'sales_doc_id'=>$salesDocId,'document_no'=>$norm['document_no'],'warehouse_id'=>$norm['warehouse_id'],'delivered_quantity'=>$qtyTotal,'cogs_irr'=>$costTotal,'status'=>'posted'];
        }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function marginSummary(int $wid,int $cid,int $salesDocId): array
    {
        $doc=self::document($wid,$cid,$salesDocId);if(!$doc)throw new RuntimeException('سند فروش پیدا نشد.');
        $delivered=self::deliveredByLine($wid,$cid,$salesDocId);$rows=[];$revenue=0.0;$cogs=0.0;$deliveredQty=0.0;$allActual=true;$hasDelivered=false;
        foreach((array)$doc['lines'] as $line){
            $qty=(float)($delivered[(int)$line['id']]??0);if($qty<=0)continue;$hasDelivered=true;
            $ordered=max(0.000001,(float)$line['quantity']);$preTax=max(0,(float)$line['quantity']*(float)$line['unit_price']-(float)$line['discount_amount']);
            $revenueUnit=$preTax/$ordered;$basis=self::costBasis($wid,$cid,(int)$line['item_id'],(int)($line['warehouse_id']?:$doc['warehouse_id']));
            $lineRevenue=$qty*$revenueUnit;$lineCogs=$qty*(float)$basis['unit_cost_irr'];$margin=$lineRevenue-$lineCogs;
            if(empty($basis['actual_available']))$allActual=false;
            $rows[]=['sales_line_id'=>(int)$line['id'],'item_id'=>(int)$line['item_id'],'item_code'=>$line['item_code'],'item_name'=>$line['item_name'],'delivered_qty'=>$qty,
                'revenue_ex_tax_irr'=>$lineRevenue,'unit_cost_irr'=>(float)$basis['unit_cost_irr'],'cost_basis'=>$basis['cost_basis'],'cogs_irr'=>$lineCogs,'gross_margin_irr'=>$margin,'gross_margin_pct'=>$lineRevenue>0?$margin/$lineRevenue*100:0,
                'projected_unit_cost_irr'=>$basis['projected_unit_cost_irr'],'actual_unit_cost_irr'=>$basis['actual_unit_cost_irr'],'trade_cases'=>$basis['trade_cases']];
            $revenue+=$lineRevenue;$cogs+=$lineCogs;$deliveredQty+=$qty;
        }
        if(!$hasDelivered)$allActual=false;$margin=$revenue-$cogs;
        return ['sales_doc_id'=>$salesDocId,'document_no'=>$doc['document_no'],'customer_name'=>$doc['party_name']??'','delivered_quantity'=>$deliveredQty,
            'revenue_ex_tax_irr'=>$revenue,'cogs_irr'=>$cogs,'gross_margin_irr'=>$margin,'gross_margin_pct'=>$revenue>0?$margin/$revenue*100:0,
            'actual_landed_margin_available'=>$allActual,'margin_basis'=>$allActual?'actual_landed':($hasDelivered?'projected_or_fallback':'not_delivered'),'rows'=>$rows];
    }

    public static function deliveries(int $wid,int $cid,int $salesDocId,int $limit=50): array
    {
        self::assertOwned($wid,$cid,'acc_sales_docs',$salesDocId);$limit=max(1,min(200,$limit));
        $st=pdo()->prepare("SELECT d.*,w.code warehouse_code,w.name warehouse_name,
            COALESCE((SELECT SUM(l.quantity) FROM acc_sales_delivery_lines l WHERE l.workspace_id=d.workspace_id AND l.delivery_id=d.id),0) delivered_quantity,
            COALESCE((SELECT SUM(l.line_cost_irr) FROM acc_sales_delivery_lines l WHERE l.workspace_id=d.workspace_id AND l.delivery_id=d.id),0) cogs_irr
            FROM acc_sales_deliveries d JOIN acc_warehouses w ON w.id=d.warehouse_id AND w.workspace_id=d.workspace_id
            WHERE d.workspace_id=? AND d.company_id=? AND d.sales_doc_id=? ORDER BY d.delivery_date DESC,d.id DESC LIMIT $limit");
        $st->execute([$wid,$cid,$salesDocId]);return $st->fetchAll();
    }

    public static function managerBrief(int $wid,int $cid,int $limit=10): array
    {
        self::assertCompany($wid,$cid);$limit=max(1,min(20,$limit));
        $trade=TradeDomain::riskSummary($wid,$cid,$limit);$replen=InventoryDomain::replenishmentRisk($wid,$cid,['limit'=>$limit]);
        $docs=array_slice(self::searchDocuments($wid,$cid,''),0,$limit);$salesRisk=[];$margins=[];
        foreach($docs as $doc){
            $ful=self::fulfillment($wid,$cid,['sales_doc_id'=>(int)$doc['id']]);
            if((float)$ful['outstanding_quantity']>0.000001 && (float)$ful['reserved_quantity']+0.000001<(float)$ful['outstanding_quantity'])
                $salesRisk[]=['sales_doc_id'=>(int)$doc['id'],'document_no'=>$doc['document_no'],'customer_name'=>$doc['party_name'],'outstanding_quantity'=>$ful['outstanding_quantity'],'reserved_quantity'=>$ful['reserved_quantity']];
            if((float)$ful['delivered_quantity']>0){
                $m=self::marginSummary($wid,$cid,(int)$doc['id']);
                $margins[]=['sales_doc_id'=>(int)$doc['id'],'document_no'=>$doc['document_no'],'customer_name'=>$doc['party_name'],'gross_margin_irr'=>$m['gross_margin_irr'],'gross_margin_pct'=>$m['gross_margin_pct'],'margin_basis'=>$m['margin_basis']];
            }
        }
        return [
            'trade'=>['risk_count'=>$trade['risk_count'],'rows'=>array_slice($trade['rows'],0,$limit)],
            'inventory'=>['shortage_count'=>$replen['shortage_count'],'rows'=>array_slice($replen['rows'],0,$limit)],
            'sales'=>['at_risk_count'=>count($salesRisk),'at_risk'=>array_slice($salesRisk,0,$limit),'recent_margins'=>array_slice($margins,0,$limit)],
            'limitations'=>['cash_projection_not_enabled'=>'Cash transaction operational primitive is not complete; near-term cash is intentionally omitted.']
        ];
    }
}
