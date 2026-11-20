<?php
/**
 * ERPSMART 10.18.1 — Order-to-Cash integrity hotfix.
 *
 * Semantics:
 * - Fulfillable commercial orders: preinvoice|invoice that are NOT O2C billing docs
 * - O2C billing invoices (erp_o2c_invoice_links.invoice_doc_id) are never fulfillable
 * - Partial invoicing allowed across multiple invoices per delivery line (SUM ≤ delivered)
 * - AR/GL voucher posting and cash settlement remain NOT implemented
 */
final class OrderToCashDomain
{
    public const VERSION = '10.19.1-o2c';
    /** AR posting kernel lives in AccountingPostingDomain (v10.19.1); requires company map + open fiscal year. */
    public const AR_POSTING = 'KERNEL_AVAILABLE';
    public const SETTLEMENT = 'NOT_IMPLEMENTED';
    public const BILLING_NOTE_MARKER = '[O2C_BILLING_DOC]';

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
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_o2c_invoice_line (workspace_id,invoice_doc_id,sales_line_id),
            INDEX idx_o2c_delivery_line (workspace_id,delivery_id,sales_line_id),
            INDEX idx_o2c_source (workspace_id,company_id,source_sales_doc_id),
            INDEX idx_o2c_invoice (workspace_id,company_id,invoice_doc_id),
            INDEX idx_o2c_line (workspace_id,sales_line_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try {
            $cols = $db->query("SHOW COLUMNS FROM erp_o2c_invoice_links LIKE 'status'")->fetchAll();
            if (!$cols) {
                $db->exec("ALTER TABLE erp_o2c_invoice_links ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER line_total");
                $db->exec("ALTER TABLE erp_o2c_invoice_links ADD COLUMN cancelled_at DATETIME NULL AFTER status");
                $db->exec("ALTER TABLE erp_o2c_invoice_links ADD COLUMN cancelled_by INT NULL AFTER cancelled_at");
            }
        } catch (Throwable $e) { /* additive */ }

        // Backward-compatible: drop v10.18.0 unique that blocked partial invoicing.
        try{
            $idx=$db->query("SHOW INDEX FROM erp_o2c_invoice_links WHERE Key_name='uq_o2c_delivery_line'")->fetchAll();
            if($idx)$db->exec('ALTER TABLE erp_o2c_invoice_links DROP INDEX uq_o2c_delivery_line');
        }catch(Throwable $e){/* table may be fresh */}
        try{
            $idx=$db->query("SHOW INDEX FROM erp_o2c_invoice_links WHERE Key_name='uq_o2c_invoice_line'")->fetchAll();
            if(!$idx)$db->exec('ALTER TABLE erp_o2c_invoice_links ADD UNIQUE KEY uq_o2c_invoice_line (workspace_id,invoice_doc_id,sales_line_id)');
        }catch(Throwable $e){/* ignore race */}
        try{
            $idx=$db->query("SHOW INDEX FROM erp_o2c_invoice_links WHERE Key_name='idx_o2c_delivery_line'")->fetchAll();
            if(!$idx)$db->exec('ALTER TABLE erp_o2c_invoice_links ADD INDEX idx_o2c_delivery_line (workspace_id,delivery_id,sales_line_id)');
        }catch(Throwable $e){/* ignore */}

        $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_delivery_idempotency (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            sales_doc_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL DEFAULT '',
            delivery_id BIGINT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,sales_doc_id,idempotency_key),
            UNIQUE KEY uq_o2c_delivery_id (workspace_id,delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try{
            $cols=$db->query("SHOW COLUMNS FROM erp_o2c_delivery_idempotency LIKE 'request_hash'")->fetchAll();
            if(!$cols)$db->exec("ALTER TABLE erp_o2c_delivery_idempotency ADD COLUMN request_hash CHAR(64) NOT NULL DEFAULT '' AFTER idempotency_key");
        }catch(Throwable $e){/* ignore */}

        $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_invoice_idempotency (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            delivery_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,delivery_id,idempotency_key),
            UNIQUE KEY uq_o2c_inv_idem_doc (workspace_id,invoice_doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Stable lock targets so concurrent reservations serialize even when no active rows exist.
        $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_stock_locks (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            warehouse_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,warehouse_id,item_id)
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

    public static function requestHash(array $payload): string
    {
        return hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function assertCompany(int $wid,int $cid): void
    {
        if($wid<1||$cid<1)throw new RuntimeException('شرکت/محیط کاری انتخاب نشده است.');
        $q=pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $q->execute([$wid,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
    }

    public static function isBillingDocument(int $wid,int $cid,int $salesDocId): bool
    {
        if($salesDocId<1)return false;
        $q=pdo()->prepare('SELECT 1 FROM erp_o2c_invoice_links WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
        $q->execute([$wid,$cid,$salesDocId]);
        if($q->fetchColumn())return true;
        $q=pdo()->prepare('SELECT notes FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$salesDocId,$wid,$cid]);
        $notes=(string)$q->fetchColumn();
        return $notes!=='' && str_contains($notes,self::BILLING_NOTE_MARKER);
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
        $wid=(int)($doc['workspace_id']??0);$cid=(int)($doc['company_id']??0);$id=(int)($doc['id']??0);
        if($wid>0&&$cid>0&&$id>0&&self::isBillingDocument($wid,$cid,$id))
            throw new RuntimeException('فاکتور صورتحساب O2C قابل رزرو/تحویل نیست.');
        $notes=(string)($doc['notes']??'');
        if($notes!==''&&str_contains($notes,self::BILLING_NOTE_MARKER))
            throw new RuntimeException('فاکتور صورتحساب O2C قابل رزرو/تحویل نیست.');
    }

    public static function requireConfirmableOrder(array $doc): void
    {
        $type=(string)($doc['doc_type']??'');
        if(!in_array($type,['preinvoice','invoice'],true))
            throw new RuntimeException('نوع سند برای تأیید سفارش معتبر نیست.');
        $wid=(int)($doc['workspace_id']??0);$cid=(int)($doc['company_id']??0);$id=(int)($doc['id']??0);
        if($wid>0&&$cid>0&&$id>0&&self::isBillingDocument($wid,$cid,$id))
            throw new RuntimeException('فاکتور صورتحساب O2C قابل تأیید به‌عنوان سفارش نیست.');
        $notes=(string)($doc['notes']??'');
        if($notes!==''&&str_contains($notes,self::BILLING_NOTE_MARKER))
            throw new RuntimeException('فاکتور صورتحساب O2C قابل تأیید به‌عنوان سفارش نیست.');
    }

    /** Acquire deterministic warehouse/item locks (row locks + named GET_LOCK). */
    public static function lockStockTargets(PDO $db,int $wid,int $cid,int $warehouseId,array $itemIds): void
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$itemIds),fn($x)=>$x>0)));
        sort($ids);
        foreach($ids as $itemId){
            $db->prepare('INSERT INTO erp_o2c_stock_locks (workspace_id,company_id,warehouse_id,item_id,updated_at) VALUES (?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)')->execute([$wid,$cid,$warehouseId,$itemId]);
            // Named lock survives empty InnoDB row-set races across connections.
            $name=sprintf('o2c_res_%d_%d_%d_%d',$wid,$cid,$warehouseId,$itemId);
            $st=$db->query("SELECT GET_LOCK(".$db->quote($name).",30)");
            $got=$st->fetchColumn();
            if((int)$got!==1)throw new RuntimeException('قفل موجودی انبار در دسترس نیست؛ بعداً تلاش کنید.');
        }
        if(!$ids)return;
        $in=implode(',',array_fill(0,count($ids),'?'));
        $params=array_merge([$wid,$cid,$warehouseId],$ids);
        $st=$db->prepare("SELECT item_id FROM erp_o2c_stock_locks WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id IN ($in) ORDER BY item_id FOR UPDATE");
        $st->execute($params);$st->fetchAll();
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
            self::requireConfirmableOrder($doc);
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
            WHERE workspace_id=? AND company_id=? AND source_sales_doc_id=? AND COALESCE(status,'active')='active'
            GROUP BY sales_line_id");
        $st->execute([$wid,$cid,$sourceSalesDocId]);$out=[];
        foreach($st->fetchAll() as $r)$out[(int)$r['sales_line_id']]=self::scaledQty((string)$r['quantity']);
        return $out;
    }

    /**
     * Controlled cancellation of an O2C draft billing invoice.
     * Voids the document, marks links cancelled (keeps audit rows), restores invoiceable qty.
     * Never hard-deletes posted AR evidence.
     */
    public static function cancelDraftBillingInvoice(int $wid,int $cid,int $uid,int $invoiceDocId): array
    {
        Tenant::requirePermission('accounting.sales.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$invoiceDocId<1)throw new RuntimeException('شناسه نامعتبر است.');
        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$invoiceDocId,$wid,$cid]);$inv=$q->fetch();
            if(!$inv)throw new RuntimeException('فاکتور پیدا نشد.');
            if(!self::isBillingDocument($wid,$cid,$invoiceDocId))
                throw new RuntimeException('فقط فاکتور صورتحساب O2C قابل ابطال کنترل‌شده است.');
            $posted=$db->prepare('SELECT 1 FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
            $posted->execute([$wid,$cid,$invoiceDocId]);
            if($posted->fetchColumn())
                throw new RuntimeException('فاکتور ثبت‌شده در AR قابل حذف/ابطال مستقیم نیست؛ نیاز به سند معکوس است.');
            if((string)$inv['workflow_status']==='void'){
                $db->commit();
                return ['id'=>$invoiceDocId,'status'=>'void','existing'=>true];
            }
            if((string)$inv['workflow_status']!=='draft')
                throw new RuntimeException('فقط پیش‌نویس فاکتور O2C قابل ابطال است.');
            $db->prepare("UPDATE erp_o2c_invoice_links SET status='cancelled',cancelled_at=NOW(),cancelled_by=?
                WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND COALESCE(status,'active')='active'")
                ->execute([$uid,$wid,$cid,$invoiceDocId]);
            $db->prepare("UPDATE acc_sales_docs SET workflow_status='void',notes=CONCAT(COALESCE(notes,''),' [O2C_CANCELLED]'),updated_at=NOW()
                WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status='draft'")
                ->execute([$invoiceDocId,$wid,$cid]);
            $db->commit();
            Audit::log('o2c.invoice.cancel','acc_sales_docs',$invoiceDocId,'ابطال کنترل‌شده پیش‌نویس فاکتور O2C');
            return ['id'=>$invoiceDocId,'status'=>'void','existing'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    /**
     * Safe draft removal entry: billing/O2C drafts → cancel; plain drafts without links → hard delete.
     */
    public static function deleteOrCancelSalesDraft(int $wid,int $cid,int $uid,int $salesDocId): array
    {
        Tenant::requirePermission('accounting.sales.manage');
        self::assertCompany($wid,$cid);
        $db=pdo();
        $q=$db->prepare('SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$salesDocId,$wid,$cid]);$doc=$q->fetch();
        if(!$doc)throw new RuntimeException('سند فروش پیدا نشد.');
        if((string)$doc['workflow_status']!=='draft')
            throw new RuntimeException('فقط پیش‌نویس فروش قابل حذف/ابطال است.');
        $posted=$db->prepare('SELECT 1 FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
        $posted->execute([$wid,$cid,$salesDocId]);
        if($posted->fetchColumn())
            throw new RuntimeException('سند دارای ثبت AR است و قابل حذف نیست.');
        if(self::isBillingDocument($wid,$cid,$salesDocId))
            return self::cancelDraftBillingInvoice($wid,$cid,$uid,$salesDocId)+['mode'=>'cancel'];
        $link=$db->prepare('SELECT 1 FROM erp_o2c_invoice_links WHERE workspace_id=? AND company_id=? AND (invoice_doc_id=? OR source_sales_doc_id=?) LIMIT 1');
        $link->execute([$wid,$cid,$salesDocId,$salesDocId]);
        if($link->fetchColumn())
            throw new RuntimeException('سند دارای پیوند O2C است؛ حذف فیزیکی مجاز نیست.');
        $db->beginTransaction();
        try{
            $db->prepare('DELETE FROM acc_sales_lines WHERE workspace_id=? AND sales_doc_id=?')->execute([$wid,$salesDocId]);
            $db->prepare('DELETE FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status=?')
                ->execute([$salesDocId,$wid,$cid,'draft']);
            $db->commit();
            Audit::log('acc.sale.delete','acc_sales_docs',$salesDocId,'حذف پیش‌نویس فروش بدون پیوند O2C');
            return ['id'=>$salesDocId,'status'=>'deleted','mode'=>'delete'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
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
        $ful['is_billing_document']=self::isBillingDocument($wid,$cid,$salesDocId);
        return $ful;
    }

    /**
     * Create draft customer invoice from posted delivery uninvoiced quantity.
     * Supports multiple partial invoices per delivery line (SUM ≤ delivered).
     * No voucher / cash / AR posting.
     */
    public static function createInvoiceFromDelivery(int $wid,int $cid,int $uid,int $sourceSalesDocId,int $deliveryId,array $lines,string $idempotencyKey=''): array
    {
        Tenant::requirePermission('accounting.sales.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$sourceSalesDocId<1||$deliveryId<1)throw new RuntimeException('شناسه نامعتبر است.');
        if(!$lines||count($lines)>200)throw new RuntimeException('ردیف‌های فاکتور نامعتبر است.');
        $idem=trim($idempotencyKey);
        if($idem!==''&&!preg_match('/^[a-f0-9]{16,64}$/i',$idem))throw new RuntimeException('کلید idempotency نامعتبر است.');

        // Normalize line map for fingerprint (sorted).
        $fpLines=[];
        foreach($lines as $rawId=>$form){
            if(!is_array($form))continue;
            $lineId=filter_var((string)$rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$lineId)continue;
            $qtyRaw=trim((string)($form['quantity']??'0'));
            if($qtyRaw===''||$qtyRaw==='0'||$qtyRaw==='0.0000')continue;
            $fpLines[(string)$lineId]=self::fixedQty(self::scaledQty($qtyRaw));
        }
        ksort($fpLines);
        $reqHash=self::requestHash(['source'=>$sourceSalesDocId,'delivery'=>$deliveryId,'lines'=>$fpLines]);

        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare("SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$sourceSalesDocId,$wid,$cid]);$src=$q->fetch();
            if(!$src)throw new RuntimeException('سند مبدأ پیدا نشد.');
            self::requireFulfillableStatus($src);
            if(self::isBillingDocument($wid,$cid,$sourceSalesDocId))
                throw new RuntimeException('نمی‌توان از فاکتور صورتحساب به‌عنوان مبدأ فاکتور استفاده کرد.');

            $q=$db->prepare("SELECT * FROM acc_sales_deliveries WHERE id=? AND workspace_id=? AND company_id=? AND sales_doc_id=? AND status='posted' LIMIT 1 FOR UPDATE");
            $q->execute([$deliveryId,$wid,$cid,$sourceSalesDocId]);$del=$q->fetch();
            if(!$del)throw new RuntimeException('تحویل قطعی متعلق به این سند پیدا نشد.');

            if($idem!==''){
                $q=$db->prepare('SELECT invoice_doc_id,request_hash FROM erp_o2c_invoice_idempotency WHERE workspace_id=? AND company_id=? AND delivery_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
                $q->execute([$wid,$cid,$deliveryId,$idem]);$ex=$q->fetch();
                if($ex){
                    if((string)$ex['request_hash']!==$reqHash)
                        throw new RuntimeException('کلید idempotency با محتوای درخواست متفاوت استفاده شده است.');
                    if($db->inTransaction())$db->commit();
                    return ['id'=>(int)$ex['invoice_doc_id'],'existing'=>true,'status'=>'draft','ar_posting'=>self::AR_POSTING];
                }
            }

            $q=$db->prepare("SELECT dl.sales_line_id,dl.item_id,dl.quantity,
                COALESCE((SELECT SUM(l.quantity) FROM erp_o2c_invoice_links l WHERE l.workspace_id=dl.workspace_id AND l.company_id=? AND l.delivery_id=dl.delivery_id AND l.sales_line_id=dl.sales_line_id AND COALESCE(l.status,'active')='active'),0) invoiced_qty
                FROM acc_sales_delivery_lines dl WHERE dl.workspace_id=? AND dl.delivery_id=? FOR UPDATE");
            $q->execute([$cid,$wid,$deliveryId]);$dlines=[];
            foreach($q->fetchAll() as $r)$dlines[(int)$r['sales_line_id']]=$r;
            // Also lock existing invoice links for this delivery to serialize concurrent partial invoices.
            $q=$db->prepare('SELECT id FROM erp_o2c_invoice_links WHERE workspace_id=? AND company_id=? AND delivery_id=? FOR UPDATE');
            $q->execute([$wid,$cid,$deliveryId]);$q->fetchAll();

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
            $notes=self::BILLING_NOTE_MARKER.' فاکتور پیش‌نویس O2C از تحویل #'.$del['delivery_no'].' / مبدأ #'.$src['document_no'].' — بدون ثبت AR/GL';
            $db->prepare("INSERT INTO acc_sales_docs (workspace_id,company_id,doc_type,document_no,document_date,due_date,party_id,warehouse_id,cost_center_id,project_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at)
                VALUES (?,?,'invoice',?,?,?,?,?,?,?,?, 'draft','not_sent',?,?,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid,$cid,$no,date('Y-m-d'),$src['due_date'],(int)$src['party_id'],
                    $src['warehouse_id'],$src['cost_center_id'],$src['project_id'],$notes,
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
            if($idem!==''){
                $db->prepare('INSERT INTO erp_o2c_invoice_idempotency (workspace_id,company_id,delivery_id,idempotency_key,request_hash,invoice_doc_id,created_at) VALUES (?,?,?,?,?,?,NOW())')
                    ->execute([$wid,$cid,$deliveryId,$idem,$reqHash,$invoiceId]);
            }
            $db->commit();
            Audit::log('o2c.invoice.draft','acc_sales_docs',$invoiceId,'صدور پیش‌نویس فاکتور از تحویل بدون ثبت AR');
            return ['id'=>$invoiceId,'document_no'=>$no,'status'=>'draft','ar_posting'=>self::AR_POSTING,'net_total'=>PurchaseMatchingDomain::fixed($net,2),'existing'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
