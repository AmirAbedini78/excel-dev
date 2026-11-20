<?php
/**
 * ERPSMART 10.18.0 — Order-to-Cash bounded vertical slice.
 *
 * Semantics (evidence-based on current schema):
 * - acc_sales_docs.doc_type preinvoice|invoice = commercial sales document (quote/order/invoice-like)
 * - workflow draft → approved|final (maker≠checker) is the confirm gate for fulfillment
 * - reservation never posts stock; delivery posts outbound acc_stock_movements
 * - customer invoice from delivery creates a linked draft invoice only
 * - AR/GL voucher posting and cash settlement are explicitly NOT implemented here
 */
final class OrderToCashDomain
{
    public const VERSION = '10.18.0-o2c';
    public const AR_POSTING = 'BLOCKED';
    public const SETTLEMENT = 'NOT_IMPLEMENTED';

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_invoice_links (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            source_sales_doc_id BIGINT NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            delivery_id BIGINT NOT NULL,
            sales_line_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            quantity DECIMAL(20,4) NOT NULL,
            line_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_o2c_delivery_line (workspace_id,delivery_id,sales_line_id),
            INDEX idx_o2c_source (workspace_id,company_id,source_sales_doc_id),
            INDEX idx_o2c_invoice (workspace_id,company_id,invoice_doc_id),
            INDEX idx_o2c_line (workspace_id,sales_line_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_delivery_idempotency (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            sales_doc_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            delivery_id BIGINT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,sales_doc_id,idempotency_key),
            UNIQUE KEY uq_o2c_delivery_id (workspace_id,delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function scaledQty($raw): int
    {
        if(is_float($raw)||is_int($raw))$raw=number_format((float)$raw,4,'.','');
        return PurchaseMatchingDomain::scaled((string)$raw,4,100000);
    }

    public static function fixedQty(int $units): string
    {
        return PurchaseMatchingDomain::fixed($units,4);
    }

    private static function assertCompany(int $wid,int $cid): void
    {
        if($wid<1||$cid<1)throw new RuntimeException('شرکت/محیط کاری انتخاب نشده است.');
        $q=pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $q->execute([$wid,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
    }

    public static function requireFulfillableStatus(array $doc): void
    {
        $type=(string)($doc['doc_type']??'');
        if(!in_array($type,['preinvoice','invoice'],true))
            throw new RuntimeException('فقط پیش‌فاکتور/سند فروش کالایی برای چرخه O2C مجاز است.');
        if(!in_array((string)($doc['workflow_status']??''),['approved','final'],true))
            throw new RuntimeException('رزرو/تحویل فقط پس از تأیید سند فروش (approved/final) مجاز است.');
        if((string)($doc['workflow_status']??'')==='void')
            throw new RuntimeException('سند باطل قابل اجرا نیست.');
    }

    /** Maker/checker confirmation: draft → approved. */
    public static function confirmOrder(int $wid,int $cid,int $uid,int $salesDocId): void
    {
        Tenant::requirePermission('accounting.sales.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$salesDocId<1)throw new RuntimeException('شناسه نامعتبر است.');
        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare("SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$salesDocId,$wid,$cid]);$doc=$q->fetch();
            if(!$doc)throw new RuntimeException('سند فروش پیدا نشد.');
            if(!in_array((string)$doc['doc_type'],['preinvoice','invoice'],true))
                throw new RuntimeException('نوع سند برای تأیید سفارش معتبر نیست.');
            if((string)$doc['workflow_status']!=='draft')
                throw new RuntimeException('فقط پیش‌نویس قابل تأیید است.');
            if((int)$doc['created_by']>0 && (int)$doc['created_by']===$uid)
                throw new RuntimeException('ایجادکننده سند نمی‌تواند آن را تأیید کند (Maker/Checker).');
            $q=$db->prepare('SELECT COUNT(*) FROM acc_sales_lines WHERE workspace_id=? AND sales_doc_id=?');
            $q->execute([$wid,$salesDocId]);
            if((int)$q->fetchColumn()<1)throw new RuntimeException('سند بدون ردیف قابل تأیید نیست.');
            $up=$db->prepare("UPDATE acc_sales_docs SET workflow_status='approved',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status='draft'");
            $up->execute([$salesDocId,$wid,$cid]);
            if($up->rowCount()!==1)throw new RuntimeException('وضعیت سند هم‌زمان تغییر کرده است.');
            $db->commit();
            Audit::log('o2c.order.confirm','acc_sales_docs',$salesDocId,'تأیید سفارش فروش توسط شخص دوم');
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function invoicedByLine(int $wid,int $cid,int $sourceSalesDocId): array
    {
        $st=pdo()->prepare("SELECT sales_line_id,SUM(quantity) quantity FROM erp_o2c_invoice_links
            WHERE workspace_id=? AND company_id=? AND source_sales_doc_id=? GROUP BY sales_line_id");
        $st->execute([$wid,$cid,$sourceSalesDocId]);$out=[];
        foreach($st->fetchAll() as $r)$out[(int)$r['sales_line_id']]=self::scaledQty((string)$r['quantity']);
        return $out;
    }

    public static function fulfillmentSnapshot(int $wid,int $cid,int $salesDocId): array
    {
        $ful=SalesDomain::fulfillment($wid,$cid,['sales_doc_id'=>$salesDocId]);
        $invoiced=self::invoicedByLine($wid,$cid,$salesDocId);
        $rows=[];$eligibleTotal=0;$invoicedTotal=0;
        foreach($ful['rows'] as $r){
            $lineId=(int)$r['sales_line_id'];
            $delivered=self::scaledQty((string)$r['delivered_qty']);
            $inv=$invoiced[$lineId]??0;
            $eligible=max(0,$delivered-$inv);
            $rows[]=$r+[
                'invoiced_qty'=>self::fixedQty($inv),
                'invoiceable_qty'=>self::fixedQty($eligible),
            ];
            $eligibleTotal+=$eligible;$invoicedTotal+=$inv;
        }
        $ful['rows']=$rows;
        $ful['invoiced_quantity']=self::fixedQty($invoicedTotal);
        $ful['invoiceable_quantity']=self::fixedQty($eligibleTotal);
        $ful['ar_posting']=self::AR_POSTING;
        $ful['settlement']=self::SETTLEMENT;
        return $ful;
    }

    /**
     * Create draft customer invoice from posted deliveries' uninvoiced quantity.
     * No voucher / cash / AR posting.
     */
    public static function createInvoiceFromDelivery(int $wid,int $cid,int $uid,int $sourceSalesDocId,int $deliveryId,array $lines): array
    {
        Tenant::requirePermission('accounting.sales.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$sourceSalesDocId<1||$deliveryId<1)throw new RuntimeException('شناسه نامعتبر است.');
        if(!$lines||count($lines)>200)throw new RuntimeException('ردیف‌های فاکتور نامعتبر است.');
        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare("SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$sourceSalesDocId,$wid,$cid]);$src=$q->fetch();
            if(!$src)throw new RuntimeException('سند مبدأ پیدا نشد.');
            self::requireFulfillableStatus($src);
            $q=$db->prepare("SELECT * FROM acc_sales_deliveries WHERE id=? AND workspace_id=? AND company_id=? AND sales_doc_id=? AND status='posted' LIMIT 1 FOR UPDATE");
            $q->execute([$deliveryId,$wid,$cid,$sourceSalesDocId]);$del=$q->fetch();
            if(!$del)throw new RuntimeException('تحویل قطعی متعلق به این سند پیدا نشد.');

            $q=$db->prepare("SELECT dl.sales_line_id,dl.item_id,dl.quantity,
                COALESCE((SELECT SUM(l.quantity) FROM erp_o2c_invoice_links l WHERE l.workspace_id=dl.workspace_id AND l.company_id=? AND l.delivery_id=dl.delivery_id AND l.sales_line_id=dl.sales_line_id),0) invoiced_qty
                FROM acc_sales_delivery_lines dl WHERE dl.workspace_id=? AND dl.delivery_id=? FOR UPDATE");
            $q->execute([$cid,$wid,$deliveryId]);$dlines=[];
            foreach($q->fetchAll() as $r)$dlines[(int)$r['sales_line_id']]=$r;

            $srcLines=[];
            $q=$db->prepare('SELECT * FROM acc_sales_lines WHERE workspace_id=? AND sales_doc_id=? ORDER BY line_no');
            $q->execute([$wid,$sourceSalesDocId]);
            foreach($q->fetchAll() as $r)$srcLines[(int)$r['id']]=$r;

            $norm=[];$gross=0;$discount=0;$tax=0;$net=0;
            foreach($lines as $rawId=>$form){
                if(!is_array($form))throw new RuntimeException('ردیف نامعتبر است.');
                $lineId=filter_var((string)$rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$lineId||!isset($dlines[$lineId])||!isset($srcLines[$lineId]))
                    throw new RuntimeException('ردیف فاکتور به تحویل/سفارش متصل نیست.');
                $qtyRaw=trim((string)($form['quantity']??'0'));
                if($qtyRaw===''||$qtyRaw==='0'||$qtyRaw==='0.0000')continue;
                $qty=self::scaledQty($qtyRaw);
                if($qty<=0)continue;
                $delivered=self::scaledQty((string)$dlines[$lineId]['quantity']);
                $already=self::scaledQty((string)$dlines[$lineId]['invoiced_qty']);
                if($qty>$delivered-$already)
                    throw new RuntimeException('مقدار فاکتور از مقدار تحویل‌شده و فاکتورنشده بیشتر است.');
                $sl=$srcLines[$lineId];
                $ordered=max(1,self::scaledQty((string)$sl['quantity']));
                // Allocate pre-tax and tax proportionally by quantity units (exact integer cents via PurchaseMatchingDomain::cents style for money at 2dp).
                $unitPrice=PurchaseMatchingDomain::scaled((string)$sl['unit_price'],2,10000000000);
                $lineDisc=PurchaseMatchingDomain::scaled((string)($sl['discount_amount']??'0'),2,10000000000);
                $lineTax=PurchaseMatchingDomain::scaled((string)($sl['tax_amount']??'0'),2,10000000000);
                $shareDisc=(int)floor($lineDisc*$qty/$ordered);
                $shareTax=(int)floor($lineTax*$qty/$ordered);
                $base=PurchaseMatchingDomain::cents($qty,$unitPrice);
                $total=max(0,$base-$shareDisc+$shareTax);
                $norm[]=[
                    'sales_line_id'=>$lineId,'item_id'=>(int)$dlines[$lineId]['item_id'],
                    'quantity'=>self::fixedQty($qty),'unit_price'=>PurchaseMatchingDomain::fixed($unitPrice,2),
                    'discount_amount'=>PurchaseMatchingDomain::fixed($shareDisc,2),
                    'tax_percent'=>(string)($sl['tax_percent']??'0'),
                    'tax_amount'=>PurchaseMatchingDomain::fixed($shareTax,2),
                    'line_total'=>PurchaseMatchingDomain::fixed($total,2),
                    'description'=>(string)($sl['description']??''),
                    'unit_id'=>$sl['unit_id'],'warehouse_id'=>$sl['warehouse_id']?:$src['warehouse_id'],
                    'cost_center_id'=>$sl['cost_center_id']?:$src['cost_center_id'],
                    'project_id'=>$sl['project_id']?:$src['project_id'],
                ];
                $gross+=$base;$discount+=$shareDisc;$tax+=$shareTax;$net+=$total;
            }
            if(!$norm)throw new RuntimeException('هیچ مقدار قابل فاکتور ثبت نشده است.');

            $no='O2C-INV-'.$deliveryId.'-'.strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_sales_docs (workspace_id,company_id,doc_type,document_no,document_date,due_date,party_id,warehouse_id,cost_center_id,project_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at)
                VALUES (?,?,'invoice',?,?,?,?,?,?,?,?, 'draft','not_sent',?,?,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid,$cid,$no,date('Y-m-d'),$src['due_date'],(int)$src['party_id'],
                    $src['warehouse_id'],$src['cost_center_id'],$src['project_id'],
                    'فاکتور پیش‌نویس O2C از تحویل #'.$del['delivery_no'].' / مبدأ #'.$src['document_no'].' — بدون ثبت AR/GL',
                    PurchaseMatchingDomain::fixed($gross,2),PurchaseMatchingDomain::fixed($discount,2),
                    PurchaseMatchingDomain::fixed($tax,2),PurchaseMatchingDomain::fixed($net,2),$uid
                ]);
            $invoiceId=(int)$db->lastInsertId();
            $ins=$db->prepare("INSERT INTO acc_sales_lines (workspace_id,sales_doc_id,line_no,item_id,unit_id,warehouse_id,cost_center_id,project_id,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $link=$db->prepare('INSERT INTO erp_o2c_invoice_links (workspace_id,company_id,source_sales_doc_id,invoice_doc_id,delivery_id,sales_line_id,item_id,quantity,line_total,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
            $n=1;
            foreach($norm as $row){
                $ins->execute([$wid,$invoiceId,$n++,$row['item_id'],$row['unit_id'],$row['warehouse_id'],$row['cost_center_id'],$row['project_id'],$row['description'],$row['quantity'],$row['unit_price'],$row['discount_amount'],$row['tax_percent'],$row['tax_amount'],$row['line_total']]);
                $link->execute([$wid,$cid,$sourceSalesDocId,$invoiceId,$deliveryId,$row['sales_line_id'],$row['item_id'],$row['quantity'],$row['line_total'],$uid]);
            }
            $db->commit();
            Audit::log('o2c.invoice.draft','acc_sales_docs',$invoiceId,'صدور پیش‌نویس فاکتور از تحویل بدون ثبت AR');
            return ['id'=>$invoiceId,'document_no'=>$no,'status'=>'draft','ar_posting'=>self::AR_POSTING,'net_total'=>PurchaseMatchingDomain::fixed($net,2)];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
