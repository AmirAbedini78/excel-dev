<?php
/**
 * ERPSMART 10.17.0 — P2P three-way matching + fail-closed policy hardening.
 * Canonical truth: acc_purchase_docs/lines + posted acc_inventory_receipt_lines.
 * Output: acc_purchase_docs (purchase_invoice_goods, draft) ONLY.
 * No payable journal, tax submission, payment, or additional stock posting.
 */
final class PurchaseMatchingDomain
{
    public const VERSION = '10.17.0-hardening';

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_p2p_vendor_bills (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL,
            purchase_order_id BIGINT NOT NULL, supplier_id BIGINT NOT NULL,
            supplier_invoice_no VARCHAR(120) NOT NULL, invoice_date DATE NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            revision INT NOT NULL DEFAULT 1,
            canonical_doc_id BIGINT NULL, total_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            submitted_by INT NOT NULL, reviewed_by INT NULL, reviewed_at DATETIME NULL,
            review_note VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_p2p_supplier_invoice (workspace_id,company_id,supplier_id,supplier_invoice_no),
            UNIQUE KEY uq_p2p_canonical (workspace_id,canonical_doc_id),
            INDEX idx_p2p_orders (workspace_id,company_id,purchase_order_id,status),
            INDEX idx_p2p_queue (workspace_id,company_id,status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_p2p_vendor_bill_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, bill_id BIGINT NOT NULL,
            purchase_line_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
            quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL,
            line_total DECIMAL(20,2) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_p2p_bill_line (workspace_id,bill_id,purchase_line_id),
            INDEX idx_p2p_po_line (workspace_id,purchase_line_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_p2p_company_policy (
            workspace_id INT NOT NULL, company_id INT NOT NULL,
            enforce_goods_match TINYINT(1) NOT NULL DEFAULT 1,
            allow_service_manual TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_p2p_bill_revisions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL, bill_id BIGINT NOT NULL,
            revision INT NOT NULL, status VARCHAR(20) NOT NULL,
            payload_hash CHAR(64) NOT NULL, total_amount DECIMAL(20,2) NOT NULL,
            lines_json MEDIUMTEXT NOT NULL, review_note VARCHAR(1000) NULL,
            reviewed_by INT NULL, amended_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_p2p_bill_rev (workspace_id,bill_id,revision),
            INDEX idx_p2p_bill_rev_bill (workspace_id,company_id,bill_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_p2p_policy_exceptions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL,
            source_path VARCHAR(80) NOT NULL, reason VARCHAR(1000) NOT NULL,
            actor_user_id INT NOT NULL, created_doc_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_p2p_exc (workspace_id,company_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::ensureColumn($db,'erp_p2p_vendor_bills','revision',"INT NOT NULL DEFAULT 1 AFTER status");
    }

    private static function ensureColumn(PDO $db,string $table,string $column,string $ddl): void
    {
        $q=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        $q->execute([$table,$column]);
        if(!$q->fetchColumn())$db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
    }

    /** Fail-closed when module p2p is enabled and company policy enforces goods matching. */
    public static function isGoodsMatchEnforced(int $wid,int $cid): bool
    {
        if($wid<1||$cid<1)return false;
        if(!class_exists('ModuleRegistry')||!ModuleRegistry::enabled('p2p',$wid))return false;
        self::ensurePolicyRow($wid,$cid);
        $q=pdo()->prepare('SELECT enforce_goods_match FROM erp_p2p_company_policy WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid,$cid]);
        return (int)$q->fetchColumn()===1;
    }

    public static function ensurePolicyRow(int $wid,int $cid): void
    {
        $q=pdo()->prepare('INSERT IGNORE INTO erp_p2p_company_policy (workspace_id,company_id,enforce_goods_match,allow_service_manual,updated_at) VALUES (?,?,1,1,NOW())');
        $q->execute([$wid,$cid]);
    }

    public static function setCompanyPolicy(int $wid,int $cid,int $uid,bool $enforceGoods,bool $allowServiceManual): void
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid,$cid);
        if($uid<1)throw new RuntimeException('کاربر نامعتبر است.');
        if(!ModuleRegistry::enabled('p2p',$wid))throw new RuntimeException('ماژول تطبیق خرید فعال نیست.');
        self::ensurePolicyRow($wid,$cid);
        $q=pdo()->prepare('UPDATE erp_p2p_company_policy SET enforce_goods_match=?,allow_service_manual=?,updated_by=?,updated_at=NOW() WHERE workspace_id=? AND company_id=?');
        $q->execute([$enforceGoods?1:0,$allowServiceManual?1:0,$uid,$wid,$cid]);
        Audit::log('p2p.policy.update','erp_p2p_company_policy',$cid,'به‌روزرسانی سیاست تطبیق خرید',null,['enforce'=>$enforceGoods,'service_manual'=>$allowServiceManual]);
    }

    public static function companyPolicy(int $wid,int $cid): array
    {
        self::assertCompany($wid,$cid);self::ensurePolicyRow($wid,$cid);
        $q=pdo()->prepare('SELECT * FROM erp_p2p_company_policy WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid,$cid]);return $q->fetch()?:['enforce_goods_match'=>1,'allow_service_manual'=>1];
    }

    /**
     * Domain boundary for all non-P2P goods-invoice writers.
     * $source: accounting_ui | ai_tool | import | api | other
     */
    public static function assertManualGoodsInvoiceAllowed(int $wid,int $cid,int $uid,string $docType,string $source,string $exceptionReason=''): void
    {
        self::assertCompany($wid,$cid);
        if($docType!=='purchase_invoice_goods'){
            if($docType==='purchase_invoice_service'&&self::isGoodsMatchEnforced($wid,$cid)){
                $pol=self::companyPolicy($wid,$cid);
                if(!(int)$pol['allow_service_manual'])throw new RuntimeException('فاکتور خدمات نیز برای این شرکت نیازمند مسیر مجاز است.');
            }
            return;
        }
        if(!self::isGoodsMatchEnforced($wid,$cid))return;
        $reason=trim($exceptionReason);
        if($reason==='')throw new RuntimeException('برای این شرکت ایجاد فاکتور کالایی فقط از مسیر تطبیق سه‌طرفه مجاز است (سیاست Fail-Closed).');
        if(mb_strlen($reason,'UTF-8')<12||mb_strlen($reason,'UTF-8')>1000)
            throw new RuntimeException('دلیل استثنای سیاست باید بین ۱۲ تا ۱۰۰۰ کاراکتر باشد.');
        if(!Tenant::can('accounting.purchase.exception')&&!Tenant::can('accounting.purchase.manage'))
            throw new RuntimeException('مجوز ثبت استثنای تطبیق خرید را ندارید.');
        if($uid<1)throw new RuntimeException('کاربر استثنا نامعتبر است.');
        // Exception is audited by caller after document id is known via recordException().
    }

    public static function recordException(int $wid,int $cid,int $uid,string $source,string $reason,?int $docId): void
    {
        $q=pdo()->prepare('INSERT INTO erp_p2p_policy_exceptions (workspace_id,company_id,source_path,reason,actor_user_id,created_doc_id,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $q->execute([$wid,$cid,substr($source,0,80),$reason,$uid,$docId]);
        Audit::log('p2p.policy.exception','erp_p2p_policy_exceptions',(int)pdo()->lastInsertId(),'استثنای سیاست تطبیق خرید',null,['source'=>$source,'doc'=>$docId]);
    }

    /** Block PO quantity/price mutation while pending/matched P2P bills exist. */
    public static function assertOrderLinesMutable(int $wid,int $cid,int $poId): void
    {
        if($poId<1)return;
        $q=pdo()->prepare("SELECT 1 FROM erp_p2p_vendor_bills WHERE workspace_id=? AND company_id=? AND purchase_order_id=? AND status IN ('pending','matched') LIMIT 1");
        $q->execute([$wid,$cid,$poId]);
        if($q->fetchColumn())throw new RuntimeException('سفارش دارای درخواست/تطبیق فعال P2P است و ردیف‌های آن قابل تغییر نیست.');
    }

    public static function scaled($raw,int $scale,int $maxWhole): int
    {
        if (!is_string($raw) && !is_int($raw)) throw new RuntimeException('عدد باید به صورت مقدار دهدهی معتبر ارسال شود.');
        $s=trim((string)$raw);
        if(!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,'.$scale.'}))?$/D',$s,$m))
            throw new RuntimeException('دقت یا قالب مقدار/قیمت نامعتبر است.');
        $whole=(int)$m[1];
        if($whole>$maxWhole)throw new RuntimeException('مقدار/قیمت خارج از دامنه مجاز است.');
        $pow=10**$scale;
        $fraction=(int)str_pad($m[2]??'', $scale,'0');
        return $whole*$pow+$fraction;
    }

    public static function fixed(int $units,int $scale): string
    {
        $pow=10**$scale;
        return (string)intdiv($units,$pow).'.'.str_pad((string)($units%$pow),$scale,'0',STR_PAD_LEFT);
    }

    public static function cents(int $qtyScaled,int $unitPriceCents): int
    {
        if($qtyScaled<=0||$qtyScaled>1000000000||$unitPriceCents<0||$unitPriceCents>1000000000000)
            throw new RuntimeException('مبلغ ردیف خارج از محدوده است.');
        // Split before multiplication: bounded, integer-only, half-up to 2 decimals.
        return intdiv($qtyScaled,10000)*$unitPriceCents
            + intdiv(($qtyScaled%10000)*$unitPriceCents+5000,10000);
    }

    private static function assertCompany(int $wid,int $cid): void
    {
        if($wid<1||$cid<1)throw new RuntimeException('شرکت/محیط کاری انتخاب نشده است.');
        $q=pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $q->execute([$wid,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
    }

    private static function order(PDO $db,int $wid,int $cid,int $poId,bool $lock=false): array
    {
        $q=$db->prepare('SELECT * FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? AND doc_type=\'purchase_order_goods\' LIMIT 1'.($lock?' FOR UPDATE':''));
        $q->execute([$poId,$wid,$cid]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('سفارش خرید معتبر در شرکت فعال پیدا نشد.');
        return $r;
    }

    private static function approvableOrder(array $order): void
    {
        if(!in_array((string)$order['workflow_status'],['approved','final'],true))
            throw new RuntimeException('ابتدا سفارش خرید باید توسط شخص دیگری تأیید شود.');
        // This pilot does not silently alter tax, FX or discount semantics.
        foreach(['discount_total','tax_total'] as $k){
            if(self::scaled((string)($order[$k]??'0'),2,10000000000)!==0)
                throw new RuntimeException('سفارش دارای تخفیف/مالیات است؛ تطبیق پیشرفته هنوز پشتیبانی نمی‌شود.');
        }
    }

    /** Lines and received/billed quantities are scoped by PO and workspace. */
    private static function orderLines(PDO $db,int $wid,int $cid,int $poId): array
    {
        $q=$db->prepare("SELECT l.id,l.item_id,l.unit_id,l.warehouse_id,l.cost_center_id,l.project_id,l.description,
                l.quantity,l.unit_price,l.discount_amount,l.discount_percent,
                COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl
                    JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id
                      AND r.company_id=? AND r.purchase_doc_id=? AND r.status='posted'
                    WHERE rl.workspace_id=l.workspace_id AND rl.purchase_line_id=l.id AND rl.item_id=l.item_id),0) AS accepted_qty,
                COALESCE((SELECT SUM(bl.quantity) FROM erp_p2p_vendor_bill_lines bl
                    JOIN erp_p2p_vendor_bills b ON b.id=bl.bill_id AND b.workspace_id=bl.workspace_id
                      AND b.company_id=? AND b.purchase_order_id=? AND b.status='matched'
                    WHERE bl.workspace_id=l.workspace_id AND bl.purchase_line_id=l.id AND bl.item_id=l.item_id),0) AS billed_qty
            FROM acc_purchase_lines l WHERE l.workspace_id=? AND l.purchase_doc_id=? ORDER BY l.line_no,l.id LIMIT 201");
        $q->execute([$cid,$poId,$cid,$poId,$wid,$poId]);$rows=$q->fetchAll();
        if(count($rows)>200)throw new RuntimeException('سفارش با بیش از ۲۰۰ ردیف در این نسخه پشتیبانی نمی‌شود.');
        return $rows;
    }

    private static function matchLines(array $po,array $rows,array $input): array
    {
        self::approvableOrder($po);
        if(!$rows||!$input||count($input)>200)throw new RuntimeException('حداقل یک ردیف خرید لازم است.');
        $map=[];foreach($rows as $r)$map[(int)$r['id']]=$r;
        $normalized=[];$sum=0;
        foreach($input as $rawLineId=>$form){
            if(!is_array($form))throw new RuntimeException('ردیف فاکتور نامعتبر است.');
            $id=filter_var((string)$rawLineId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id||!isset($map[$id]))throw new RuntimeException('ردیف فاکتور متعلق به این سفارش نیست.');
            $quantity=trim((string)($form['quantity']??'0'));
            if($quantity===''||$quantity==='0'||$quantity==='0.0000')continue;
            $qty=self::scaled($quantity,4,100000);
            if($qty<=0)continue;
            $r=$map[$id];
            if(self::scaled((string)($r['discount_amount']??'0'),2,10000000000)!==0
                || self::scaled((string)($r['discount_percent']??'0'),4,100000)!==0)
                throw new RuntimeException('ردیف دارای تخفیف است و تطبیق در این نسخه مجاز نیست.');
            $price=self::scaled((string)($form['unit_price']??''),2,10000000000);
            $orderPrice=self::scaled((string)$r['unit_price'],2,10000000000);
            if($price!==$orderPrice)throw new RuntimeException('قیمت فاکتور با سفارش خرید مطابقت ندارد.');
            $ordered=self::scaled((string)$r['quantity'],4,100000);
            $accepted=self::scaled((string)$r['accepted_qty'],4,100000);
            $billed=self::scaled((string)$r['billed_qty'],4,100000);
            if($billed>$accepted||$billed>$ordered||$qty>min($ordered,$accepted)-$billed)
                throw new RuntimeException('مقدار فاکتور بیشتر از کالای پذیرفته‌شده و صورتحساب‌نشده است.');
            $amount=self::cents($qty,$price);
            if($sum>100000000000000000-$amount)throw new RuntimeException('مبلغ فاکتور خارج از سقف ایمن است.');
            $sum+=$amount;
            $normalized[$id]=['purchase_line_id'=>$id,'item_id'=>(int)$r['item_id'],
                'quantity'=>self::fixed($qty,4),'unit_price'=>self::fixed($price,2),
                'line_total'=>self::fixed($amount,2),'source'=>$r];
        }
        if(!$normalized)throw new RuntimeException('هیچ مقدار قابل صورتحساب ثبت نشده است.');
        ksort($normalized,SORT_NUMERIC);
        return ['lines'=>$normalized,'total'=>self::fixed($sum,2)];
    }

    public static function orders(int $wid,int $cid): array
    {
        self::assertCompany($wid,$cid);
        $q=pdo()->prepare("SELECT d.id,d.document_no,d.document_date,d.workflow_status,d.created_by,p.name supplier_name,
            (SELECT COUNT(*) FROM acc_inventory_receipts r WHERE r.workspace_id=d.workspace_id AND r.company_id=d.company_id AND r.purchase_doc_id=d.id AND r.status='posted') receipt_count
            FROM acc_purchase_docs d LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id AND p.company_id=d.company_id
            WHERE d.workspace_id=? AND d.company_id=? AND d.doc_type='purchase_order_goods' AND d.workflow_status<>'void'
            ORDER BY d.id DESC LIMIT 80");
        $q->execute([$wid,$cid]);return $q->fetchAll();
    }

    public static function preview(int $wid,int $cid,int $poId): array
    {
        self::assertCompany($wid,$cid);$db=pdo();$order=self::order($db,$wid,$cid,$poId);
        $rows=self::orderLines($db,$wid,$cid,$poId);
        foreach($rows as &$r){
            $ordered=self::scaled((string)$r['quantity'],4,100000);
            $accepted=self::scaled((string)$r['accepted_qty'],4,100000);
            $billed=self::scaled((string)$r['billed_qty'],4,100000);
            $r['invoiceable_qty']=self::fixed(max(0,min($ordered,$accepted)-$billed),4);
        }unset($r);
        return ['order'=>$order,'lines'=>$rows];
    }

    public static function bills(int $wid,int $cid): array
    {
        self::assertCompany($wid,$cid);
        $q=pdo()->prepare('SELECT b.*,d.document_no order_no,p.name supplier_name FROM erp_p2p_vendor_bills b JOIN acc_purchase_docs d ON d.id=b.purchase_order_id AND d.workspace_id=b.workspace_id AND d.company_id=b.company_id LEFT JOIN acc_parties p ON p.id=b.supplier_id AND p.workspace_id=b.workspace_id AND p.company_id=b.company_id WHERE b.workspace_id=? AND b.company_id=? ORDER BY b.id DESC LIMIT 100');
        $q->execute([$wid,$cid]);return $q->fetchAll();
    }

    public static function approveOrder(int $wid,int $cid,int $uid,int $poId): void
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid,$cid);
        if($uid<1)throw new RuntimeException('کاربر تأییدکننده نامعتبر است.');
        $db=pdo();$db->beginTransaction();
        try{
            $po=self::order($db,$wid,$cid,$poId,true);
            if($po['workflow_status']!=='draft')throw new RuntimeException('فقط سفارش خرید موقت قابل تأیید است.');
            if((int)$po['created_by']<1 || (int)$po['created_by']===$uid)
                throw new RuntimeException('سازنده سفارش نمی‌تواند آن را تأیید کند.');
            $rows=self::orderLines($db,$wid,$cid,$poId);
            if(!$rows)throw new RuntimeException('سفارش بدون ردیف قابل تأیید نیست.');
            self::approvableOrder(array_merge($po,['workflow_status'=>'approved']));
            foreach($rows as $r){
                if(self::scaled((string)$r['quantity'],4,100000)<=0)
                    throw new RuntimeException('مقدار سفارش نامعتبر است.');
                if(self::scaled((string)$r['unit_price'],2,10000000000)<0)
                    throw new RuntimeException('قیمت سفارش نامعتبر است.');
                if(self::scaled((string)$r['discount_amount'],2,10000000000)!==0
                    ||self::scaled((string)$r['discount_percent'],4,100000)!==0)
                    throw new RuntimeException('سفارش دارای تخفیف است و هنوز برای تطبیق ساده آماده نیست.');
            }
            $q=$db->prepare("UPDATE acc_purchase_docs SET workflow_status='approved',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status='draft'");
            $q->execute([$poId,$wid,$cid]);
            if($q->rowCount()!==1)throw new RuntimeException('وضعیت سفارش هنگام تأیید تغییر کرده است.');
            $db->commit();
            Audit::log('p2p.order.approve','acc_purchase_docs',$poId,'تأیید سفارش خرید توسط شخص دوم');
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    private static function fingerprint(int $poId,int $supplier,string $invoiceNo,string $date,array $input): string
    {
        $canonical=[];
        if(count($input)>200)throw new RuntimeException('تعداد ردیف‌های فاکتور بیش از حد است.');
        foreach($input as $rawId=>$f){
            if(!is_array($f))throw new RuntimeException('ردیف نامعتبر است.');
            $id=filter_var((string)$rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id)throw new RuntimeException('شناسه ردیف نامعتبر است.');
            $v=trim((string)($f['quantity']??'0'));
            if($v===''||$v==='0'||$v==='0.0000')continue;
            $qty=self::scaled($v,4,100000);
            if($qty<=0)continue;
            $price=self::scaled((string)($f['unit_price']??''),2,10000000000);
            $canonical[$id]=[$id,self::fixed($qty,4),self::fixed($price,2)];
        }
        if(!$canonical)throw new RuntimeException('حداقل یک ردیف دارای مقدار لازم است.');
        ksort($canonical,SORT_NUMERIC);
        return hash('sha256',json_encode([$poId,$supplier,$invoiceNo,$date,array_values($canonical)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public static function submit(int $wid,int $cid,int $uid,int $poId,string $invoiceNo,string $invoiceDate,array $lines): array
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid,$cid);
        if($uid<1)throw new RuntimeException('شناسه کاربر نامعتبر است.');
        $invoiceNo=trim($invoiceNo);
        if($invoiceNo===''||mb_strlen($invoiceNo,'UTF-8')>120||preg_match('/[\x00-\x1F\x7F]/',$invoiceNo))
            throw new RuntimeException('شماره فاکتور تأمین‌کننده نامعتبر است.');
        $date=AccountingRepository::date($invoiceDate);
        $dt=$date?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if(!$dt||$dt->format('Y-m-d')!==$date)throw new RuntimeException('تاریخ فاکتور معتبر نیست.');
        $db=pdo();$db->beginTransaction();
        try{
            $po=self::order($db,$wid,$cid,$poId,true);
            $supplier=(int)$po['party_id'];
            $hash=self::fingerprint($poId,$supplier,$invoiceNo,$date,$lines);
            $q=$db->prepare('SELECT id,payload_hash,status FROM erp_p2p_vendor_bills WHERE workspace_id=? AND company_id=? AND supplier_id=? AND supplier_invoice_no=? LIMIT 1 FOR UPDATE');
            $q->execute([$wid,$cid,$supplier,$invoiceNo]);$existing=$q->fetch();
            if($existing){
                if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('شماره فاکتور تکراری با محتوای متفاوت است.');
                $db->commit();return ['id'=>(int)$existing['id'],'existing'=>true,'status'=>$existing['status']];
            }
            $norm=self::matchLines($po,self::orderLines($db,$wid,$cid,$poId),$lines);
            $q=$db->prepare("INSERT INTO erp_p2p_vendor_bills (workspace_id,company_id,purchase_order_id,supplier_id,supplier_invoice_no,invoice_date,payload_hash,status,total_amount,submitted_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'pending',?,?,NOW(),NOW())");
            $q->execute([$wid,$cid,$poId,$supplier,$invoiceNo,$date,$hash,$norm['total'],$uid]);$id=(int)$db->lastInsertId();
            $ins=$db->prepare('INSERT INTO erp_p2p_vendor_bill_lines (workspace_id,bill_id,purchase_line_id,item_id,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,?,NOW())');
            foreach($norm['lines'] as $line)$ins->execute([$wid,$id,$line['purchase_line_id'],$line['item_id'],$line['quantity'],$line['unit_price'],$line['line_total']]);
            $db->commit();
            Audit::log('p2p.bill.submit','erp_p2p_vendor_bills',$id,'ارسال فاکتور تأمین‌کننده برای تأیید');
            return ['id'=>$id,'existing'=>false,'status'=>'pending'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function approve(int $wid,int $cid,int $uid,int $billId): array
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$billId<1)throw new RuntimeException('شناسه درخواست/کاربر نامعتبر است.');
        $db=pdo();
        // Read immutable parent id BEFORE transaction. Starting an InnoDB RR snapshot
        // before obtaining the PO lock could hide another just-matched bill.
        $q=$db->prepare('SELECT purchase_order_id FROM erp_p2p_vendor_bills WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$billId,$wid,$cid]);$poId=(int)$q->fetchColumn();
        if(!$poId)throw new RuntimeException('درخواست فاکتور متعلق به شرکت نیست.');
        $db->beginTransaction();
        try{
            // Lock order FIRST; the first consistent read follows this locking read.
            $po=self::order($db,$wid,$cid,$poId,true);
            $q=$db->prepare('SELECT * FROM erp_p2p_vendor_bills WHERE id=? AND workspace_id=? AND company_id=? AND purchase_order_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$billId,$wid,$cid,$poId]);$bill=$q->fetch();
            if(!$bill)throw new RuntimeException('درخواست فاکتور یافت نشد.');
            if($bill['status']==='matched'){
                $q=$db->prepare("SELECT id FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? AND doc_type='purchase_invoice_goods' LIMIT 1");
                $q->execute([(int)$bill['canonical_doc_id'],$wid,$cid]);
                if(!$q->fetchColumn())throw new RuntimeException('سند مرتبط قبلی حذف شده است؛ نیاز به بررسی حسابداری.');
                $db->commit();return ['id'=>(int)$bill['canonical_doc_id'],'existing'=>true];
            }
            if($bill['status']!=='pending')throw new RuntimeException('این درخواست قابل تأیید نیست.');
            if((int)$bill['submitted_by']===$uid)throw new RuntimeException('درخواست‌کننده نمی‌تواند فاکتور خود را تأیید کند.');
            if((int)$bill['supplier_id']!==(int)$po['party_id'])throw new RuntimeException('تأمین‌کننده سفارش تغییر کرده است.');
            self::approvableOrder($po);
            $q=$db->prepare('SELECT purchase_line_id,quantity,unit_price,item_id FROM erp_p2p_vendor_bill_lines WHERE workspace_id=? AND bill_id=? ORDER BY purchase_line_id');
            $q->execute([$wid,$billId]);$saved=$q->fetchAll();
            $submitted=[];
            foreach($saved as $r)$submitted[(int)$r['purchase_line_id']]=['quantity'=>(string)$r['quantity'],'unit_price'=>(string)$r['unit_price']];
            $norm=self::matchLines($po,self::orderLines($db,$wid,$cid,$poId),$submitted);
            if($norm['total']!==(string)$bill['total_amount'])throw new RuntimeException('جمع درخواست با داده‌های ذخیره‌شده سازگار نیست.');
            $q=$db->prepare("SELECT id FROM acc_purchase_docs WHERE workspace_id=? AND company_id=? AND party_id=? AND doc_type IN ('purchase_invoice_goods','purchase_invoice_service') AND party_invoice_no=? AND workflow_status<>'void' LIMIT 1 FOR UPDATE");
            $q->execute([$wid,$cid,(int)$bill['supplier_id'],$bill['supplier_invoice_no']]);
            if($q->fetchColumn())throw new RuntimeException('فاکتور تأمین‌کننده قبلاً در حسابداری ثبت شده است.');
            $no='P2P-'.$billId;
            $q=$db->prepare("INSERT INTO acc_purchase_docs (workspace_id,company_id,doc_type,document_no,party_invoice_no,document_date,party_id,cost_center_id,project_id,warehouse_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at) VALUES (?,?,'purchase_invoice_goods',?,?,?,?,?,?,?,?,'draft','not_sent',?,0,0,?,?,NOW(),NOW())");
            $q->execute([$wid,$cid,$no,$bill['supplier_invoice_no'],$bill['invoice_date'],(int)$bill['supplier_id'],
                $po['cost_center_id'],$po['project_id'],$po['warehouse_id'],'سه‌طرفه تطبیق‌شده؛ پیش‌نویس بدون ثبت مالی / PO #'.$poId,
                $norm['total'],$norm['total'],$uid]);
            $docId=(int)$db->lastInsertId();
            $ins=$db->prepare('INSERT INTO acc_purchase_lines (workspace_id,purchase_doc_id,line_no,item_id,unit_id,description,quantity,unit_price,warehouse_id,cost_center_id,project_id,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $lineNo=1;
            foreach($norm['lines'] as $n){$src=$n['source'];$ins->execute([$wid,$docId,$lineNo++,$n['item_id'],$src['unit_id'],$src['description'],$n['quantity'],$n['unit_price'],
                $src['warehouse_id']?:$po['warehouse_id'],$src['cost_center_id']?:$po['cost_center_id'],$src['project_id']?:$po['project_id'],$n['line_total']]);}
            $up=$db->prepare("UPDATE erp_p2p_vendor_bills SET status='matched',canonical_doc_id=?,reviewed_by=?,reviewed_at=NOW(),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='pending'");
            $up->execute([$docId,$uid,$billId,$wid,$cid]);
            if($up->rowCount()!==1)throw new RuntimeException('درخواست هم‌زمان تغییر کرده است.');
            $db->commit();
            Audit::log('p2p.bill.match','erp_p2p_vendor_bills',$billId,'صدور پیش‌نویس فاکتور سه‌طرفه تطبیق‌شده');
            return ['id'=>$docId,'existing'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function reject(int $wid,int $cid,int $uid,int $billId,string $reason): void
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid,$cid);
        $reason=trim($reason);
        if(mb_strlen($reason,'UTF-8')<5||mb_strlen($reason,'UTF-8')>1000)
            throw new RuntimeException('دلیل رد باید بین ۵ تا ۱۰۰۰ کاراکتر باشد.');
        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM erp_p2p_vendor_bills WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$billId,$wid,$cid]);$bill=$q->fetch();
            if(!$bill||$bill['status']!=='pending')throw new RuntimeException('درخواست در وضعیت قابل رد نیست.');
            if((int)$bill['submitted_by']===$uid)throw new RuntimeException('درخواست‌کننده نمی‌تواند درخواست خودش را رد کند.');
            $q=$db->prepare("UPDATE erp_p2p_vendor_bills SET status='rejected',reviewed_by=?,reviewed_at=NOW(),review_note=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='pending'");
            $q->execute([$uid,$reason,$billId,$wid,$cid]);
            if($q->rowCount()!==1)throw new RuntimeException('درخواست هم‌زمان تغییر کرده است.');
            $db->commit();Audit::log('p2p.bill.reject','erp_p2p_vendor_bills',$billId,'رد فاکتور تأمین‌کننده');
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    /**
     * Controlled amendment of a rejected supplier bill under the same invoice number.
     * Preserves prior revision snapshot; requires a new independent reviewer.
     */
    public static function amend(int $wid,int $cid,int $uid,int $billId,string $invoiceDate,array $lines,string $amendNote): array
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$billId<1)throw new RuntimeException('شناسه درخواست/کاربر نامعتبر است.');
        $amendNote=trim($amendNote);
        if(mb_strlen($amendNote,'UTF-8')<5||mb_strlen($amendNote,'UTF-8')>1000)
            throw new RuntimeException('شرح اصلاح باید بین ۵ تا ۱۰۰۰ کاراکتر باشد.');
        $date=AccountingRepository::date($invoiceDate);
        $dt=$date?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if(!$dt||$dt->format('Y-m-d')!==$date)throw new RuntimeException('تاریخ فاکتور معتبر نیست.');
        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM erp_p2p_vendor_bills WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$billId,$wid,$cid]);$bill=$q->fetch();
            if(!$bill)throw new RuntimeException('درخواست فاکتور یافت نشد.');
            if($bill['status']!=='rejected')throw new RuntimeException('فقط درخواست ردشده قابل اصلاح است.');
            if($bill['status']==='matched'||$bill['canonical_doc_id'])throw new RuntimeException('فاکتور تطبیق‌شده قابل اصلاح نیست.');
            $poId=(int)$bill['purchase_order_id'];
            $po=self::order($db,$wid,$cid,$poId,true);
            if((int)$bill['supplier_id']!==(int)$po['party_id'])throw new RuntimeException('تأمین‌کننده سفارش تغییر کرده است.');
            $invoiceNo=(string)$bill['supplier_invoice_no'];
            $hash=self::fingerprint($poId,(int)$bill['supplier_id'],$invoiceNo,$date,$lines);
            if(hash_equals((string)$bill['payload_hash'],$hash)&&$date===(string)$bill['invoice_date'])
                throw new RuntimeException('محتوای اصلاح با نسخه ردشده یکسان است؛ تغییری ثبت نشده.');
            $q=$db->prepare('SELECT purchase_line_id,quantity,unit_price,item_id,line_total FROM erp_p2p_vendor_bill_lines WHERE workspace_id=? AND bill_id=? ORDER BY purchase_line_id');
            $q->execute([$wid,$billId]);$oldLines=$q->fetchAll();
            $rev=(int)($bill['revision']??1);
            $insRev=$db->prepare('INSERT INTO erp_p2p_bill_revisions (workspace_id,company_id,bill_id,revision,status,payload_hash,total_amount,lines_json,review_note,reviewed_by,amended_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $insRev->execute([$wid,$cid,$billId,$rev,'rejected',$bill['payload_hash'],$bill['total_amount'],
                json_encode($oldLines,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                $bill['review_note'],$bill['reviewed_by'],$uid]);
            $norm=self::matchLines($po,self::orderLines($db,$wid,$cid,$poId),$lines);
            $db->prepare('DELETE FROM erp_p2p_vendor_bill_lines WHERE workspace_id=? AND bill_id=?')->execute([$wid,$billId]);
            $ins=$db->prepare('INSERT INTO erp_p2p_vendor_bill_lines (workspace_id,bill_id,purchase_line_id,item_id,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,?,NOW())');
            foreach($norm['lines'] as $line)$ins->execute([$wid,$billId,$line['purchase_line_id'],$line['item_id'],$line['quantity'],$line['unit_price'],$line['line_total']]);
            $up=$db->prepare("UPDATE erp_p2p_vendor_bills SET status='pending',revision=?,invoice_date=?,payload_hash=?,total_amount=?,submitted_by=?,reviewed_by=NULL,reviewed_at=NULL,review_note=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='rejected'");
            $up->execute([$rev+1,$date,$hash,$norm['total'],$uid,'اصلاح پس از رد: '.$amendNote,$billId,$wid,$cid]);
            if($up->rowCount()!==1)throw new RuntimeException('درخواست هم‌زمان تغییر کرده است.');
            $db->commit();
            Audit::log('p2p.bill.amend','erp_p2p_vendor_bills',$billId,'اصلاح درخواست ردشده و ارسال مجدد برای تأیید');
            return ['id'=>$billId,'revision'=>$rev+1,'status'=>'pending','existing'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
