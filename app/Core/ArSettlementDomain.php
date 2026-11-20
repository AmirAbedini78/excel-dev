<?php
/**
 * ERPSMART 10.20.0 — Customer receipt posting + AR allocation (settlement).
 *
 * Receipt GL (canonical): Dr Cash/Bank GL · Cr Accounts Receivable
 * Allocations are subledger links from posted receipts to posted AR invoices.
 * Bank reconciliation is NOT claimed by posting a receipt.
 */
final class ArSettlementDomain
{
    public const VERSION = '10.20.0-settlement';
    public const SETTLEMENT = 'AVAILABLE';

    public static function migrate(PDO $db): void
    {
        try {
            $cols = $db->query("SHOW COLUMNS FROM erp_ar_company_maps LIKE 'cash_gl_account_id'")->fetchAll();
            if (!$cols) {
                $db->exec('ALTER TABLE erp_ar_company_maps ADD COLUMN cash_gl_account_id BIGINT NULL AFTER tax_account_id');
            }
        } catch (Throwable $e) { /* table may not exist yet */ }

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ar_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            party_id BIGINT NOT NULL,
            cash_account_id BIGINT NOT NULL,
            receipt_no VARCHAR(120) NOT NULL,
            receipt_date DATE NOT NULL,
            amount DECIMAL(20,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            voucher_id BIGINT NULL,
            cash_tx_id BIGINT NULL,
            idempotency_key CHAR(64) NULL,
            request_hash CHAR(64) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'IRR',
            description VARCHAR(500) NULL,
            created_by INT NOT NULL,
            approved_by INT NULL,
            posted_by INT NULL,
            reversed_by INT NULL,
            reversal_voucher_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_ar_receipt_no (workspace_id,company_id,receipt_no),
            UNIQUE KEY uq_ar_receipt_idem (workspace_id,company_id,idempotency_key),
            INDEX idx_ar_receipt_party (workspace_id,company_id,party_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ar_allocations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            receipt_id BIGINT NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            amount DECIMAL(20,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            idempotency_key CHAR(64) NULL,
            request_hash CHAR(64) NOT NULL,
            created_by INT NOT NULL,
            reversed_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_ar_alloc_idem (workspace_id,company_id,idempotency_key),
            INDEX idx_ar_alloc_receipt (workspace_id,receipt_id,status),
            INDEX idx_ar_alloc_invoice (workspace_id,invoice_doc_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت/محیط کاری انتخاب نشده است.');
        }
        $q = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $q->execute([$wid, $cid]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
        }
    }

    public static function setCashGlMap(int $wid, int $cid, int $uid, int $cashGlAccountId): void
    {
        Tenant::requirePermission('accounting.settings.manage');
        self::assertCompany($wid, $cid);
        // Reuse typed validation via reflection-free map set path: asset + postable
        $map = AccountingPostingDomain::companyMap($wid, $cid);
        if (!$map) {
            throw new RuntimeException('ابتدا نگاشت AR (دریافتنی/درآمد) را تنظیم کنید.');
        }
        // Validate through temporary setCompanyMap fields — call private via public assess after update.
        $q = pdo()->prepare('SELECT id,account_type,allow_posting,active FROM acc_accounts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$cashGlAccountId, $wid, $cid]);
        $acc = $q->fetch();
        if (!$acc || (int)$acc['active'] !== 1 || (int)$acc['allow_posting'] !== 1 || strtolower((string)$acc['account_type']) !== 'asset') {
            throw new RuntimeException('حساب نقد/بانک دفتر کل باید دارایی فعال و قابل ثبت باشد.');
        }
        if ($cashGlAccountId === (int)$map['receivable_account_id'] || $cashGlAccountId === (int)$map['revenue_account_id']) {
            throw new RuntimeException('حساب نقد نباید با دریافتنی یا درآمد یکی باشد.');
        }
        pdo()->prepare('UPDATE erp_ar_company_maps SET cash_gl_account_id=?, updated_by=?, updated_at=NOW() WHERE workspace_id=? AND company_id=?')
            ->execute([$cashGlAccountId, $uid, $wid, $cid]);
        Audit::log('ar.map.cash_gl', 'erp_ar_company_maps', $cid, 'تنظیم حساب نقد/بانک برای تسویه');
    }

    public static function createDraftReceipt(int $wid, int $cid, int $uid, array $args): array
    {
        Tenant::requirePermission('accounting.treasury.manage');
        self::assertCompany($wid, $cid);
        $partyId = (int)($args['party_id'] ?? 0);
        $cashAccountId = (int)($args['cash_account_id'] ?? 0);
        $date = AccountingRepository::date((string)($args['receipt_date'] ?? '')) ?: '';
        $amountRaw = trim((string)($args['amount'] ?? ''));
        $desc = mb_substr(trim((string)($args['description'] ?? '')), 0, 500);
        $idem = trim((string)($args['idempotency_key'] ?? ''));
        if ($partyId < 1 || $cashAccountId < 1 || $date === '' || $amountRaw === '') {
            throw new RuntimeException('مشتری، حساب نقد، تاریخ و مبلغ الزامی است.');
        }
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('کلید idempotency نامعتبر است.');
        }
        $amount = PurchaseMatchingDomain::scaled($amountRaw, 2, 10000000000);
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ دریافت باید مثبت باشد.');
        }
        $pq = pdo()->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $pq->execute([$partyId, $wid, $cid]);
        if (!$pq->fetchColumn()) {
            throw new RuntimeException('مشتری متعلق به شرکت نیست.');
        }
        $cq = pdo()->prepare('SELECT 1 FROM acc_cash_accounts WHERE id=? AND workspace_id=? AND company_id=? AND active=1 LIMIT 1');
        $cq->execute([$cashAccountId, $wid, $cid]);
        if (!$cq->fetchColumn()) {
            throw new RuntimeException('حساب نقد/بانک خزانه‌داری معتبر نیست.');
        }
        $hash = hash('sha256', json_encode([
            'party' => $partyId, 'cash' => $cashAccountId, 'date' => $date,
            'amount' => PurchaseMatchingDomain::fixed($amount, 2), 'desc' => $desc,
        ], JSON_UNESCAPED_UNICODE));

        $db = pdo();
        $db->beginTransaction();
        try {
            if ($idem !== '') {
                $ex = $db->prepare('SELECT id,status,request_hash FROM erp_ar_receipts WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
                $ex->execute([$wid, $cid, $idem]);
                $row = $ex->fetch();
                if ($row) {
                    if ((string)$row['request_hash'] !== $hash) {
                        throw new RuntimeException('کلید idempotency با محتوای متفاوت استفاده شده است.');
                    }
                    $db->commit();
                    return ['id' => (int)$row['id'], 'status' => (string)$row['status'], 'existing' => true];
                }
            }
            $no = 'ARR-' . strtoupper(bin2hex(random_bytes(3)));
            $db->prepare('INSERT INTO erp_ar_receipts (workspace_id,company_id,party_id,cash_account_id,receipt_no,receipt_date,amount,status,idempotency_key,request_hash,description,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,\'draft\',?,?,?,?,NOW(),NOW())')
                ->execute([$wid, $cid, $partyId, $cashAccountId, $no, $date, PurchaseMatchingDomain::fixed($amount, 2), $idem !== '' ? $idem : null, $hash, $desc, $uid]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            Audit::log('ar.receipt.draft', 'erp_ar_receipts', $id, 'پیش‌نویس دریافت مشتری');
            return ['id' => $id, 'receipt_no' => $no, 'status' => 'draft', 'existing' => false];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function approveReceipt(int $wid, int $cid, int $uid, int $receiptId): array
    {
        Tenant::requirePermission('accounting.treasury.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM erp_ar_receipts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$receiptId, $wid, $cid]);
            $r = $q->fetch();
            if (!$r) {
                throw new RuntimeException('رسید پیدا نشد.');
            }
            if ((string)$r['status'] === 'approved') {
                $db->commit();
                return ['id' => $receiptId, 'status' => 'approved', 'existing' => true];
            }
            if ((string)$r['status'] !== 'draft') {
                throw new RuntimeException('فقط پیش‌نویس قابل تأیید است.');
            }
            if ((int)$r['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده نمی‌تواند همان رسید را تأیید کند (Maker/Checker).');
            }
            $db->prepare("UPDATE erp_ar_receipts SET status='approved',approved_by=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='draft'")
                ->execute([$uid, $receiptId, $wid, $cid]);
            $db->commit();
            Audit::log('ar.receipt.approve', 'erp_ar_receipts', $receiptId, 'تأیید رسید دریافت');
            return ['id' => $receiptId, 'status' => 'approved', 'existing' => false];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function postReceipt(int $wid, int $cid, int $uid, int $receiptId, string $idempotencyKey = ''): array
    {
        Tenant::requirePermission('accounting.vouchers.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM erp_ar_receipts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$receiptId, $wid, $cid]);
            $r = $q->fetch();
            if (!$r) {
                throw new RuntimeException('رسید پیدا نشد.');
            }
            if ((string)$r['status'] === 'posted') {
                $db->commit();
                return ['id' => $receiptId, 'status' => 'posted', 'voucher_id' => (int)$r['voucher_id'], 'existing' => true];
            }
            if ((string)$r['status'] !== 'approved') {
                throw new RuntimeException('فقط رسید تأییدشده قابل ثبت دفتر است.');
            }
            if ((int)$r['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده نمی‌تواند رسید را ثبت دفتر کند (Maker/Checker).');
            }
            $map = AccountingPostingDomain::companyMap($wid, $cid);
            if (!$map || empty($map['cash_gl_account_id'])) {
                throw new RuntimeException('حساب نقد/بانک دفتر کل در نگاشت AR تنظیم نشده است.');
            }
            $cashGl = (int)$map['cash_gl_account_id'];
            $arGl = (int)$map['receivable_account_id'];
            $date = (string)$r['receipt_date'];
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);
            $amount = PurchaseMatchingDomain::scaled((string)$r['amount'], 2, 10000000000);

            $no = 'AR-RCPT-' . $receiptId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'o2c_ar_receipt',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'ثبت دریافت مشتری ' . $r['receipt_no'],
                    $receiptId, PurchaseMatchingDomain::fixed($amount, 2), PurchaseMatchingDomain::fixed($amount, 2), $uid,
                ]);
            $voucherId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $ins->execute([$wid, $voucherId, 1, $cashGl, null, 'نقد/بانک ' . $r['receipt_no'], PurchaseMatchingDomain::fixed($amount, 2), '0.00']);
            $ins->execute([$wid, $voucherId, 2, $arGl, (int)$r['party_id'], 'تسویه دریافتنی ' . $r['receipt_no'], '0.00', PurchaseMatchingDomain::fixed($amount, 2)]);

            // Mirror into cash transactions as posted evidence (not bank reconciliation).
            $txNo = 'CTX-' . $r['receipt_no'];
            $db->prepare("INSERT INTO acc_cash_transactions (workspace_id,company_id,transaction_no,transaction_date,direction,cash_account_id,party_id,amount,method,status,description,source_type,source_id,created_by,created_at,updated_at)
                VALUES (?,?,?,?,'in',?,?,?, 'bank','posted',?,'ar_receipt',?,?,NOW(),NOW())")
                ->execute([$wid, $cid, $txNo, $date, (int)$r['cash_account_id'], (int)$r['party_id'], PurchaseMatchingDomain::fixed($amount, 2), 'دریافت مشتری (ثبت دفتر)', $receiptId, $uid]);
            $cashTxId = (int)$db->lastInsertId();

            $db->prepare("UPDATE erp_ar_receipts SET status='posted',voucher_id=?,cash_tx_id=?,posted_by=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='approved'")
                ->execute([$voucherId, $cashTxId, $uid, $receiptId, $wid, $cid]);
            $db->commit();
            Audit::log('ar.receipt.post', 'acc_vouchers', $voucherId, 'ثبت دفتر دریافت مشتری');
            return ['id' => $receiptId, 'status' => 'posted', 'voucher_id' => $voucherId, 'existing' => false, 'bank_reconciled' => false];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function unallocatedReceiptAmount(int $wid, int $cid, int $receiptId): int
    {
        $q = pdo()->prepare("SELECT amount FROM erp_ar_receipts WHERE id=? AND workspace_id=? AND company_id=? AND status='posted' LIMIT 1");
        $q->execute([$receiptId, $wid, $cid]);
        $amt = $q->fetchColumn();
        if ($amt === false) {
            throw new RuntimeException('رسید ثبت‌شده پیدا نشد.');
        }
        $total = PurchaseMatchingDomain::scaled((string)$amt, 2, 10000000000);
        $a = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ar_allocations WHERE workspace_id=? AND company_id=? AND receipt_id=? AND status='active'");
        $a->execute([$wid, $cid, $receiptId]);
        $used = PurchaseMatchingDomain::scaled((string)$a->fetchColumn(), 2, 10000000000);
        return $total - $used;
    }

    public static function invoiceOpenAmount(int $wid, int $cid, int $invoiceDocId): int
    {
        $p = pdo()->prepare('SELECT net_total FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
        $p->execute([$wid, $cid, $invoiceDocId]);
        $net = $p->fetchColumn();
        if ($net === false) {
            throw new RuntimeException('فاکتور ثبت‌شده AR پیدا نشد.');
        }
        $total = PurchaseMatchingDomain::scaled((string)$net, 2, 10000000000);
        $a = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ar_allocations WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='active'");
        $a->execute([$wid, $cid, $invoiceDocId]);
        $used = PurchaseMatchingDomain::scaled((string)$a->fetchColumn(), 2, 10000000000);
        return $total - $used;
    }

    public static function invoiceSettlementStatus(int $wid, int $cid, int $invoiceDocId): array
    {
        $open = self::invoiceOpenAmount($wid, $cid, $invoiceDocId);
        $p = pdo()->prepare('SELECT net_total FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
        $p->execute([$wid, $cid, $invoiceDocId]);
        $net = PurchaseMatchingDomain::scaled((string)$p->fetchColumn(), 2, 10000000000);
        if ($open <= 0) {
            $status = 'SETTLED';
        } elseif ($open < $net) {
            $status = 'PARTIALLY_SETTLED';
        } else {
            $status = 'OPEN';
        }
        return [
            'invoice_doc_id' => $invoiceDocId,
            'net_total' => PurchaseMatchingDomain::fixed($net, 2),
            'open_amount' => PurchaseMatchingDomain::fixed(max(0, $open), 2),
            'status' => $status,
        ];
    }

    public static function allocate(int $wid, int $cid, int $uid, int $receiptId, int $invoiceDocId, string $amountRaw, string $idempotencyKey = ''): array
    {
        Tenant::requirePermission('accounting.treasury.manage');
        self::assertCompany($wid, $cid);
        $amount = PurchaseMatchingDomain::scaled(trim($amountRaw), 2, 10000000000);
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ تخصیص باید مثبت باشد.');
        }
        $idem = trim($idempotencyKey);
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('کلید idempotency نامعتبر است.');
        }
        $hash = hash('sha256', json_encode(['receipt' => $receiptId, 'invoice' => $invoiceDocId, 'amount' => PurchaseMatchingDomain::fixed($amount, 2)], JSON_UNESCAPED_UNICODE));

        $db = pdo();
        $db->beginTransaction();
        try {
            if ($idem !== '') {
                $ex = $db->prepare('SELECT id,request_hash,amount,status FROM erp_ar_allocations WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
                $ex->execute([$wid, $cid, $idem]);
                $row = $ex->fetch();
                if ($row) {
                    if ((string)$row['request_hash'] !== $hash) {
                        throw new RuntimeException('کلید idempotency با محتوای متفاوت استفاده شده است.');
                    }
                    $db->commit();
                    return ['id' => (int)$row['id'], 'status' => (string)$row['status'], 'existing' => true];
                }
            }
            $rq = $db->prepare("SELECT * FROM erp_ar_receipts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $rq->execute([$receiptId, $wid, $cid]);
            $receipt = $rq->fetch();
            if (!$receipt || (string)$receipt['status'] !== 'posted') {
                throw new RuntimeException('فقط رسید ثبت‌شده قابل تخصیص است.');
            }
            $iq = $db->prepare('SELECT p.*, d.party_id, d.company_id FROM erp_ar_postings p JOIN acc_sales_docs d ON d.id=p.invoice_doc_id AND d.workspace_id=p.workspace_id WHERE p.workspace_id=? AND p.company_id=? AND p.invoice_doc_id=? LIMIT 1 FOR UPDATE');
            $iq->execute([$wid, $cid, $invoiceDocId]);
            $inv = $iq->fetch();
            if (!$inv) {
                throw new RuntimeException('فاکتور AR ثبت‌شده پیدا نشد.');
            }
            if ((int)$inv['party_id'] !== (int)$receipt['party_id']) {
                throw new RuntimeException('مشتری رسید و فاکتور یکسان نیست.');
            }
            // Lock allocations for concurrency
            $db->prepare('SELECT id FROM erp_ar_allocations WHERE workspace_id=? AND company_id=? AND (receipt_id=? OR invoice_doc_id=?) FOR UPDATE')
                ->execute([$wid, $cid, $receiptId, $invoiceDocId]);

            $unalloc = self::unallocatedReceiptAmount($wid, $cid, $receiptId);
            $open = self::invoiceOpenAmount($wid, $cid, $invoiceDocId);
            if ($amount > $unalloc) {
                throw new RuntimeException('مبلغ تخصیص از مانده رسید بیشتر است.');
            }
            if ($amount > $open) {
                throw new RuntimeException('مبلغ تخصیص از مانده فاکتور بیشتر است.');
            }
            $db->prepare('INSERT INTO erp_ar_allocations (workspace_id,company_id,receipt_id,invoice_doc_id,amount,status,idempotency_key,request_hash,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,\'active\',?,?,?,NOW(),NOW())')
                ->execute([$wid, $cid, $receiptId, $invoiceDocId, PurchaseMatchingDomain::fixed($amount, 2), $idem !== '' ? $idem : null, $hash, $uid]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            Audit::log('ar.allocation.create', 'erp_ar_allocations', $id, 'تخصیص دریافت به فاکتور');
            return [
                'id' => $id, 'status' => 'active', 'existing' => false,
                'invoice' => self::invoiceSettlementStatus($wid, $cid, $invoiceDocId),
                'receipt_unallocated' => PurchaseMatchingDomain::fixed(self::unallocatedReceiptAmount($wid, $cid, $receiptId), 2),
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function reverseReceipt(int $wid, int $cid, int $uid, int $receiptId): array
    {
        Tenant::requirePermission('accounting.vouchers.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM erp_ar_receipts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$receiptId, $wid, $cid]);
            $r = $q->fetch();
            if (!$r) {
                throw new RuntimeException('رسید پیدا نشد.');
            }
            if ((string)$r['status'] === 'reversed') {
                $db->commit();
                return ['id' => $receiptId, 'status' => 'reversed', 'existing' => true];
            }
            if ((string)$r['status'] !== 'posted') {
                throw new RuntimeException('فقط رسید ثبت‌شده قابل برگشت است.');
            }
            $db->prepare("UPDATE erp_ar_allocations SET status='reversed',reversed_by=?,updated_at=NOW() WHERE workspace_id=? AND company_id=? AND receipt_id=? AND status='active'")
                ->execute([$uid, $wid, $cid, $receiptId]);

            $map = AccountingPostingDomain::companyMap($wid, $cid);
            $amount = PurchaseMatchingDomain::scaled((string)$r['amount'], 2, 10000000000);
            $date = (string)$r['receipt_date'];
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);
            $no = 'AR-RCPT-REV-' . $receiptId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'o2c_ar_receipt_reversal',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'برگشت دریافت ' . $r['receipt_no'],
                    $receiptId, PurchaseMatchingDomain::fixed($amount, 2), PurchaseMatchingDomain::fixed($amount, 2), $uid,
                ]);
            $revId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            // Reverse: Dr AR · Cr Cash
            $ins->execute([$wid, $revId, 1, (int)$map['receivable_account_id'], (int)$r['party_id'], 'برگشت تسویه', PurchaseMatchingDomain::fixed($amount, 2), '0.00']);
            $ins->execute([$wid, $revId, 2, (int)$map['cash_gl_account_id'], null, 'برگشت نقد', '0.00', PurchaseMatchingDomain::fixed($amount, 2)]);
            $db->prepare("UPDATE erp_ar_receipts SET status='reversed',reversed_by=?,reversal_voucher_id=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")
                ->execute([$uid, $revId, $receiptId, $wid, $cid]);
            $db->commit();
            Audit::log('ar.receipt.reverse', 'acc_vouchers', $revId, 'برگشت دریافت مشتری');
            return ['id' => $receiptId, 'status' => 'reversed', 'reversal_voucher_id' => $revId, 'existing' => false];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
