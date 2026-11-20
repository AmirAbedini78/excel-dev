<?php
/**
 * ERPSMART 10.19.0 — Canonical AR posting kernel (sales invoice → balanced voucher).
 *
 * Uses existing acc_vouchers / acc_voucher_lines / acc_fiscal_years / acc_accounts.
 * Settlement / cash allocation remains NOT_IMPLEMENTED.
 */
final class AccountingPostingDomain
{
    public const VERSION = '10.19.0-ar';
    public const SETTLEMENT = 'NOT_IMPLEMENTED';

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_ar_company_maps (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            receivable_account_id BIGINT NOT NULL,
            revenue_account_id BIGINT NOT NULL,
            tax_account_id BIGINT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ar_postings (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            voucher_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            net_total DECIMAL(20,2) NOT NULL,
            posted_by INT NOT NULL,
            posted_at DATETIME NOT NULL,
            UNIQUE KEY uq_ar_invoice (workspace_id,company_id,invoice_doc_id),
            UNIQUE KEY uq_ar_idem (workspace_id,company_id,idempotency_key),
            UNIQUE KEY uq_ar_voucher (workspace_id,voucher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function assertCompany(int $wid,int $cid): void
    {
        if($wid<1||$cid<1)throw new RuntimeException('شرکت/محیط کاری انتخاب نشده است.');
        $q=pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $q->execute([$wid,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
    }

    private static function assertAccount(int $wid,int $cid,int $accountId,string $label): void
    {
        if($accountId<1)throw new RuntimeException($label.' مشخص نشده است.');
        $q=pdo()->prepare('SELECT 1 FROM acc_accounts WHERE id=? AND workspace_id=? AND company_id=? AND active=1 LIMIT 1');
        $q->execute([$accountId,$wid,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException($label.' معتبر نیست.');
    }

    public static function setCompanyMap(int $wid,int $cid,int $uid,array $map): void
    {
        Tenant::requirePermission('accounting.settings.manage');
        self::assertCompany($wid,$cid);
        $ar=(int)($map['receivable_account_id']??0);
        $rev=(int)($map['revenue_account_id']??0);
        $tax=(int)($map['tax_account_id']??0);
        self::assertAccount($wid,$cid,$ar,'حساب دریافتنی');
        self::assertAccount($wid,$cid,$rev,'حساب درآمد');
        if($tax>0)self::assertAccount($wid,$cid,$tax,'حساب مالیات');
        else $tax=null;
        pdo()->prepare('INSERT INTO erp_ar_company_maps (workspace_id,company_id,receivable_account_id,revenue_account_id,tax_account_id,updated_by,updated_at)
            VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE receivable_account_id=VALUES(receivable_account_id),revenue_account_id=VALUES(revenue_account_id),tax_account_id=VALUES(tax_account_id),updated_by=VALUES(updated_by),updated_at=NOW()')
            ->execute([$wid,$cid,$ar,$rev,$tax,$uid]);
        Audit::log('ar.map.set','erp_ar_company_maps',$cid,'تنظیم نگاشت حساب‌های AR');
    }

    public static function companyMap(int $wid,int $cid): ?array
    {
        $q=pdo()->prepare('SELECT * FROM erp_ar_company_maps WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid,$cid]);
        $row=$q->fetch();
        return $row?:null;
    }

    public static function assertFiscalOpen(int $wid,int $cid,string $date): int
    {
        $q=pdo()->prepare("SELECT id FROM acc_fiscal_years WHERE workspace_id=? AND company_id=? AND status='open' AND start_date<=? AND end_date>=? ORDER BY id DESC LIMIT 1");
        $q->execute([$wid,$cid,$date,$date]);
        $id=(int)$q->fetchColumn();
        if($id<1)throw new RuntimeException('برای تاریخ سند، سال مالی باز وجود ندارد.');
        return $id;
    }

    public static function assessReadiness(int $wid,int $cid): array
    {
        self::assertCompany($wid,$cid);
        $map=self::companyMap($wid,$cid);
        $fy=pdo()->prepare("SELECT COUNT(*) FROM acc_fiscal_years WHERE workspace_id=? AND company_id=? AND status='open'");
        $fy->execute([$wid,$cid]);
        $openFy=(int)$fy->fetchColumn();
        $ready=$map!==null && $openFy>0;
        return [
            'ready'=>$ready,
            'has_account_map'=>$map!==null,
            'open_fiscal_years'=>$openFy,
            'settlement'=>self::SETTLEMENT,
            'blockers'=>array_values(array_filter([
                $map===null?'account_map_missing':null,
                $openFy<1?'fiscal_year_closed_or_missing':null,
            ])),
        ];
    }

    /**
     * Post O2C billing invoice to AR/GL as a balanced final voucher.
     * Idempotent by invoice_doc_id and optional idempotency_key.
     */
    public static function postCustomerInvoice(int $wid,int $cid,int $uid,int $invoiceDocId,string $idempotencyKey=''): array
    {
        Tenant::requirePermission('accounting.vouchers.manage');
        self::assertCompany($wid,$cid);
        if($uid<1||$invoiceDocId<1)throw new RuntimeException('شناسه نامعتبر است.');
        $idem=trim($idempotencyKey);
        if($idem!==''&&!preg_match('/^[a-f0-9]{16,64}$/i',$idem))throw new RuntimeException('کلید idempotency نامعتبر است.');
        if($idem==='')$idem=hash('sha256','ar-inv-'.$wid.'-'.$cid.'-'.$invoiceDocId);

        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$invoiceDocId,$wid,$cid]);$inv=$q->fetch();
            if(!$inv)throw new RuntimeException('فاکتور پیدا نشد.');
            if((string)$inv['doc_type']!=='invoice')throw new RuntimeException('فقط فاکتور فروش قابل ثبت AR است.');
            if(!OrderToCashDomain::isBillingDocument($wid,$cid,$invoiceDocId))
                throw new RuntimeException('فقط فاکتور صورتحساب O2C برای ثبت AR مجاز است.');
            if(in_array((string)$inv['workflow_status'],['void'],true))
                throw new RuntimeException('فاکتور باطل قابل ثبت نیست.');
            if((int)$inv['created_by']>0 && (int)$inv['created_by']===$uid)
                throw new RuntimeException('ایجادکننده فاکتور نمی‌تواند آن را به دفتر ثبت کند (Maker/Checker).');

            $q=$db->prepare('SELECT voucher_id,idempotency_key,request_hash FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$wid,$cid,$invoiceDocId]);$existing=$q->fetch();
            $reqHash=hash('sha256',json_encode([
                'invoice'=>$invoiceDocId,
                'net'=>(string)$inv['net_total'],
                'tax'=>(string)$inv['tax_total'],
                'gross'=>(string)$inv['total_before_discount'],
                'disc'=>(string)$inv['discount_total'],
            ],JSON_UNESCAPED_UNICODE));
            if($existing){
                if((string)$existing['idempotency_key']!==$idem && $idem!==(string)$existing['idempotency_key']){
                    // Same invoice already posted — return existing regardless of key (unique invoice).
                }
                if((string)$existing['request_hash']!==$reqHash)
                    throw new RuntimeException('فاکتور پس از ثبت تغییر کرده است؛ ثبت مجدد مجاز نیست.');
                $db->commit();
                return ['existing'=>true,'voucher_id'=>(int)$existing['voucher_id'],'invoice_doc_id'=>$invoiceDocId,'status'=>'posted'];
            }
            $q=$db->prepare('SELECT voucher_id,request_hash FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1');
            $q->execute([$wid,$cid,$idem]);$byKey=$q->fetch();
            if($byKey){
                if((string)$byKey['request_hash']!==$reqHash)throw new RuntimeException('کلید idempotency با محتوای متفاوت استفاده شده است.');
                $db->commit();
                return ['existing'=>true,'voucher_id'=>(int)$byKey['voucher_id'],'invoice_doc_id'=>$invoiceDocId,'status'=>'posted'];
            }

            $map=self::companyMap($wid,$cid);
            if(!$map)throw new RuntimeException('نگاشت حساب‌های AR برای شرکت تنظیم نشده است.');
            $date=(string)$inv['document_date'];
            $fyId=self::assertFiscalOpen($wid,$cid,$date);

            $gross=PurchaseMatchingDomain::scaled((string)$inv['total_before_discount'],2,10000000000);
            $disc=PurchaseMatchingDomain::scaled((string)$inv['discount_total'],2,10000000000);
            $tax=PurchaseMatchingDomain::scaled((string)$inv['tax_total'],2,10000000000);
            $net=PurchaseMatchingDomain::scaled((string)$inv['net_total'],2,10000000000);
            $revenue=max(0,$gross-$disc);
            if($net!==$revenue+$tax)throw new RuntimeException('جمع خالص فاکتور با اجزای درآمد/مالیات بالانس نیست.');
            if($net<=0)throw new RuntimeException('فاکتور با مبلغ صفر قابل ثبت AR نیست.');

            $partyId=(int)$inv['party_id'];
            $q=$db->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
            $q->execute([$partyId,$wid,$cid]);
            if(!$q->fetchColumn())throw new RuntimeException('مشتری فاکتور متعلق به شرکت نیست.');

            $lines=[];
            // Dr AR = net
            $lines[]=['account_id'=>(int)$map['receivable_account_id'],'party_id'=>$partyId,'debit'=>$net,'credit'=>0,'desc'=>'دریافتنی مشتری '.$inv['document_no']];
            // Cr Revenue
            $lines[]=['account_id'=>(int)$map['revenue_account_id'],'party_id'=>$partyId,'debit'=>0,'credit'=>$revenue,'desc'=>'درآمد فروش '.$inv['document_no']];
            if($tax>0){
                $taxAcc=(int)($map['tax_account_id']??0);
                if($taxAcc<1)throw new RuntimeException('حساب مالیات برای فاکتور دارای مالیات تنظیم نشده است.');
                $lines[]=['account_id'=>$taxAcc,'party_id'=>null,'debit'=>0,'credit'=>$tax,'desc'=>'مالیات فروش '.$inv['document_no']];
            }
            $debit=0;$credit=0;
            foreach($lines as $l){$debit+=$l['debit'];$credit+=$l['credit'];}
            if($debit!==$credit)throw new RuntimeException('آرتیکل‌های AR بالانس نیستند.');

            $no='AR-'.$invoiceDocId.'-'.strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'o2c_ar',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid,$cid,$fyId,$no,$date,'ثبت خودکار AR از فاکتور '.$inv['document_no'],
                    $invoiceDocId,PurchaseMatchingDomain::fixed($debit,2),PurchaseMatchingDomain::fixed($credit,2),$uid
                ]);
            $voucherId=(int)$db->lastInsertId();
            $ins=$db->prepare("INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
            $n=1;
            foreach($lines as $l){
                $ins->execute([
                    $wid,$voucherId,$n++,$l['account_id'],$l['party_id'],$l['desc'],
                    PurchaseMatchingDomain::fixed((int)$l['debit'],2),PurchaseMatchingDomain::fixed((int)$l['credit'],2)
                ]);
            }
            $db->prepare("UPDATE acc_sales_docs SET workflow_status='final',notes=CONCAT(COALESCE(notes,''),' [AR_POSTED voucher=',?,']'),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")
                ->execute([(string)$voucherId,$invoiceDocId,$wid,$cid]);
            $db->prepare('INSERT INTO erp_ar_postings (workspace_id,company_id,invoice_doc_id,voucher_id,idempotency_key,request_hash,net_total,posted_by,posted_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
                ->execute([$wid,$cid,$invoiceDocId,$voucherId,$idem,$reqHash,PurchaseMatchingDomain::fixed($net,2),$uid]);
            $db->commit();
            Audit::log('ar.invoice.post','acc_vouchers',$voucherId,'ثبت AR فاکتور '.$inv['document_no']);
            return [
                'existing'=>false,'voucher_id'=>$voucherId,'voucher_no'=>$no,'invoice_doc_id'=>$invoiceDocId,
                'status'=>'posted','net_total'=>PurchaseMatchingDomain::fixed($net,2),'settlement'=>self::SETTLEMENT,
            ];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            // Concurrent post race: unique invoice/idempotency — return the winner's posting.
            if($e instanceof PDOException){
                $q=pdo()->prepare('SELECT voucher_id,request_hash FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
                $q->execute([$wid,$cid,$invoiceDocId]);$row=$q->fetch();
                if($row){
                    return ['existing'=>true,'voucher_id'=>(int)$row['voucher_id'],'invoice_doc_id'=>$invoiceDocId,'status'=>'posted','race_recovered'=>true];
                }
            }
            throw $e;
        }
    }

    public static function customerOutstanding(int $wid,int $cid,int $partyId): array
    {
        self::assertCompany($wid,$cid);
        if($partyId<1)throw new RuntimeException('مشتری نامعتبر است.');
        $map=self::companyMap($wid,$cid);
        if(!$map){
            return ['party_id'=>$partyId,'outstanding'=>'0.00','basis'=>'posted_voucher_lines','note'=>'ar_map_missing'];
        }
        $arAcc=(int)$map['receivable_account_id'];
        // Outstanding = net balance on the mapped AR control account for this party (not revenue/tax lines).
        $q=pdo()->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM acc_voucher_lines l
            JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final')
              AND l.party_id=? AND l.account_id=?");
        $q->execute([$wid,$cid,$partyId,$arAcc]);
        $bal=(string)$q->fetchColumn();
        return [
            'party_id'=>$partyId,
            'outstanding'=>PurchaseMatchingDomain::fixed(PurchaseMatchingDomain::scaled($bal,2,10000000000),2),
            'basis'=>'posted_ar_account_voucher_lines',
            'receivable_account_id'=>$arAcc,
        ];
    }
}
