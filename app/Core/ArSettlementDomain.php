<?php
/**
 * ERPSMART 10.20.1 — Treasury integrity for customer receipts + AR allocation.
 *
 * Receipt GL: Dr Cash/Bank GL (per treasury cash account) · Cr AR (snapshotted).
 * Reversal uses original voucher line accounts, not current mappings.
 * Bank reconciliation is NEVER claimed by posting a receipt.
 */
final class ArSettlementDomain
{
    public const VERSION = '10.21.0-settlement';
    public const SETTLEMENT = 'AVAILABLE';
    /** Validated payment methods for this release (cheque/Sayad = separate lifecycle). */
    public const METHODS = ['cash', 'bank_transfer', 'pos'];

    public static function migrate(PDO $db): void
    {
        try {
            $cols = $db->query("SHOW COLUMNS FROM erp_ar_company_maps LIKE 'cash_gl_account_id'")->fetchAll();
            if (!$cols) {
                $db->exec('ALTER TABLE erp_ar_company_maps ADD COLUMN cash_gl_account_id BIGINT NULL AFTER tax_account_id');
            }
        } catch (Throwable $e) { /* ignore */ }

        try {
            $cols = $db->query("SHOW COLUMNS FROM acc_cash_accounts LIKE 'gl_account_id'")->fetchAll();
            if (!$cols) {
                $db->exec('ALTER TABLE acc_cash_accounts ADD COLUMN gl_account_id BIGINT NULL AFTER iban');
            }
        } catch (Throwable $e) { /* ignore */ }

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ar_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            party_id BIGINT NOT NULL,
            cash_account_id BIGINT NOT NULL,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'bank_transfer',
            receipt_no VARCHAR(120) NOT NULL,
            receipt_date DATE NOT NULL,
            amount DECIMAL(20,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            voucher_id BIGINT NULL,
            cash_tx_id BIGINT NULL,
            reverse_cash_tx_id BIGINT NULL,
            cash_gl_account_id BIGINT NULL,
            ar_gl_account_id BIGINT NULL,
            idempotency_key CHAR(64) NULL,
            request_hash CHAR(64) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'IRR',
            description VARCHAR(500) NULL,
            created_by INT NOT NULL,
            approved_by INT NULL,
            posted_by INT NULL,
            reversed_by INT NULL,
            reversal_voucher_id BIGINT NULL,
            reversal_date DATE NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_ar_receipt_no (workspace_id,company_id,receipt_no),
            UNIQUE KEY uq_ar_receipt_idem (workspace_id,company_id,idempotency_key),
            INDEX idx_ar_receipt_party (workspace_id,company_id,party_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            "ALTER TABLE erp_ar_receipts ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'bank_transfer' AFTER cash_account_id",
            "ALTER TABLE erp_ar_receipts ADD COLUMN reverse_cash_tx_id BIGINT NULL AFTER cash_tx_id",
            "ALTER TABLE erp_ar_receipts ADD COLUMN cash_gl_account_id BIGINT NULL AFTER reverse_cash_tx_id",
            "ALTER TABLE erp_ar_receipts ADD COLUMN ar_gl_account_id BIGINT NULL AFTER cash_gl_account_id",
            "ALTER TABLE erp_ar_receipts ADD COLUMN reversal_date DATE NULL AFTER reversal_voucher_id",
        ] as $sql) {
            try { $db->exec($sql); } catch (Throwable $e) { /* additive */ }
        }

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

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ar_cash_gl_map_audit (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            cash_account_id BIGINT NOT NULL,
            old_gl_account_id BIGINT NULL,
            new_gl_account_id BIGINT NULL,
            changed_by INT NOT NULL,
            changed_at DATETIME NOT NULL,
            INDEX idx_ar_cash_gl_audit (workspace_id,company_id,cash_account_id)
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

    private static function assertAssetGl(int $wid, int $cid, int $accountId, string $label): void
    {
        if ($accountId < 1) {
            throw new RuntimeException($label . ' مشخص نشده است.');
        }
        $q = pdo()->prepare('SELECT account_type,allow_posting,active FROM acc_accounts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$accountId, $wid, $cid]);
        $acc = $q->fetch();
        if (!$acc || (int)$acc['active'] !== 1 || (int)$acc['allow_posting'] !== 1 || strtolower((string)$acc['account_type']) !== 'asset') {
            throw new RuntimeException($label . ' باید حساب دارایی فعال و قابل ثبت باشد.');
        }
    }

    /** Map a treasury cash/bank account to its GL asset account (company-scoped). */
    public static function setCashAccountGl(int $wid, int $cid, int $uid, int $cashAccountId, int $glAccountId): void
    {
        Tenant::requirePermission('accounting.settings.manage');
        self::assertCompany($wid, $cid);
        $cq = pdo()->prepare('SELECT id,gl_account_id,active FROM acc_cash_accounts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $cq->execute([$cashAccountId, $wid, $cid]);
        $cash = $cq->fetch();
        if (!$cash || (int)$cash['active'] !== 1) {
            throw new RuntimeException('حساب خزانه معتبر/فعال نیست.');
        }
        self::assertAssetGl($wid, $cid, $glAccountId, 'حساب دفتر کل نقد/بانک');
        $map = AccountingPostingDomain::companyMap($wid, $cid);
        if ($map && ($glAccountId === (int)$map['receivable_account_id'] || $glAccountId === (int)$map['revenue_account_id'])) {
            throw new RuntimeException('حساب نقد نباید با دریافتنی یا درآمد یکی باشد.');
        }
        $old = $cash['gl_account_id'] !== null ? (int)$cash['gl_account_id'] : null;
        pdo()->prepare('UPDATE acc_cash_accounts SET gl_account_id=?, updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?')
            ->execute([$glAccountId, $cashAccountId, $wid, $cid]);
        pdo()->prepare('INSERT INTO erp_ar_cash_gl_map_audit (workspace_id,company_id,cash_account_id,old_gl_account_id,new_gl_account_id,changed_by,changed_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$wid, $cid, $cashAccountId, $old, $glAccountId, $uid]);
        Audit::log('ar.cash_gl.map', 'acc_cash_accounts', $cashAccountId, 'نگاشت حساب خزانه به دفتر کل', null, ['old' => $old, 'new' => $glAccountId]);
    }

    /** @deprecated company-wide fallback — prefer setCashAccountGl */
    public static function setCashGlMap(int $wid, int $cid, int $uid, int $cashGlAccountId): void
    {
        Tenant::requirePermission('accounting.settings.manage');
        self::assertCompany($wid, $cid);
        $map = AccountingPostingDomain::companyMap($wid, $cid);
        if (!$map) {
            throw new RuntimeException('ابتدا نگاشت AR (دریافتنی/درآمد) را تنظیم کنید.');
        }
        self::assertAssetGl($wid, $cid, $cashGlAccountId, 'حساب نقد/بانک دفتر کل');
        if ($cashGlAccountId === (int)$map['receivable_account_id'] || $cashGlAccountId === (int)$map['revenue_account_id']) {
            throw new RuntimeException('حساب نقد نباید با دریافتنی یا درآمد یکی باشد.');
        }
        pdo()->prepare('UPDATE erp_ar_company_maps SET cash_gl_account_id=?, updated_by=?, updated_at=NOW() WHERE workspace_id=? AND company_id=?')
            ->execute([$cashGlAccountId, $uid, $wid, $cid]);
        Audit::log('ar.map.cash_gl', 'erp_ar_company_maps', $cid, 'تنظیم fallback حساب نقد شرکت (ترجیح: نگاشت هر حساب خزانه)');
    }

    public static function resolveCashGl(int $wid, int $cid, int $cashAccountId): int
    {
        $cq = pdo()->prepare('SELECT gl_account_id,active FROM acc_cash_accounts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $cq->execute([$cashAccountId, $wid, $cid]);
        $cash = $cq->fetch();
        if (!$cash || (int)$cash['active'] !== 1) {
            throw new RuntimeException('حساب خزانه معتبر نیست.');
        }
        $gl = (int)($cash['gl_account_id'] ?? 0);
        if ($gl > 0) {
            self::assertAssetGl($wid, $cid, $gl, 'حساب دفتر کل خزانه');
            return $gl;
        }
        // Backward-compatible company fallback only if per-account map missing.
        $map = AccountingPostingDomain::companyMap($wid, $cid);
        $fallback = (int)($map['cash_gl_account_id'] ?? 0);
        if ($fallback < 1) {
            throw new RuntimeException('برای این حساب خزانه، نگاشت دفتر کل تنظیم نشده است.');
        }
        self::assertAssetGl($wid, $cid, $fallback, 'حساب نقد fallback شرکت');
        return $fallback;
    }

    public static function normalizeMethod(string $raw): string
    {
        $m = strtolower(trim($raw));
        if ($m === 'bank') {
            $m = 'bank_transfer';
        }
        if ($m === 'cheque' || $m === 'check' || $m === 'sayad') {
            throw new RuntimeException('چک/صیاد چرخه جداگانه دارد و در این نسخه به‌عنوان دریافت نقدی ثبت نمی‌شود.');
        }
        if (!in_array($m, self::METHODS, true)) {
            throw new RuntimeException('روش پرداخت پشتیبانی‌شده نیست (cash|bank_transfer|pos).');
        }
        return $m;
    }

    public static function createDraftReceipt(int $wid, int $cid, int $uid, array $args): array
    {
        Tenant::requirePermission('accounting.treasury.manage');
        self::assertCompany($wid, $cid);
        $partyId = (int)($args['party_id'] ?? 0);
        $cashAccountId = (int)($args['cash_account_id'] ?? 0);
        $method = self::normalizeMethod((string)($args['payment_method'] ?? 'bank_transfer'));
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
        // Resolve GL now so draft cannot be created without a valid mapping.
        self::resolveCashGl($wid, $cid, $cashAccountId);
        $hash = hash('sha256', json_encode([
            'party' => $partyId, 'cash' => $cashAccountId, 'method' => $method, 'date' => $date,
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
            $db->prepare('INSERT INTO erp_ar_receipts (workspace_id,company_id,party_id,cash_account_id,payment_method,receipt_no,receipt_date,amount,status,idempotency_key,request_hash,description,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,\'draft\',?,?,?,?,NOW(),NOW())')
                ->execute([$wid, $cid, $partyId, $cashAccountId, $method, $no, $date, PurchaseMatchingDomain::fixed($amount, 2), $idem !== '' ? $idem : null, $hash, $desc, $uid]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            Audit::log('ar.receipt.draft', 'erp_ar_receipts', $id, 'پیش‌نویس دریافت مشتری');
            return ['id' => $id, 'receipt_no' => $no, 'status' => 'draft', 'existing' => false, 'payment_method' => $method];
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
                return ['id' => $receiptId, 'status' => 'posted', 'voucher_id' => (int)$r['voucher_id'], 'existing' => true, 'bank_reconciled' => false];
            }
            if ((string)$r['status'] !== 'approved') {
                throw new RuntimeException('فقط رسید تأییدشده قابل ثبت دفتر است.');
            }
            if ((int)$r['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده نمی‌تواند رسید را ثبت دفتر کند (Maker/Checker).');
            }
            $map = AccountingPostingDomain::companyMap($wid, $cid);
            if (!$map) {
                throw new RuntimeException('نگاشت AR شرکت تنظیم نشده است.');
            }
            $cashGl = self::resolveCashGl($wid, $cid, (int)$r['cash_account_id']);
            $arGl = (int)$map['receivable_account_id'];
            self::assertAssetGl($wid, $cid, $arGl, 'حساب دریافتنی');
            $date = (string)$r['receipt_date'];
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);
            $amount = PurchaseMatchingDomain::scaled((string)$r['amount'], 2, 10000000000);
            $method = self::normalizeMethod((string)($r['payment_method'] ?? 'bank_transfer'));

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

            $txNo = 'CTX-' . $r['receipt_no'];
            $db->prepare("INSERT INTO acc_cash_transactions (workspace_id,company_id,transaction_no,transaction_date,direction,cash_account_id,party_id,amount,method,status,description,source_type,source_id,created_by,created_at,updated_at)
                VALUES (?,?,?,?,'in',?,?,?,?, 'posted',?,'ar_receipt',?,?,NOW(),NOW())")
                ->execute([$wid, $cid, $txNo, $date, (int)$r['cash_account_id'], (int)$r['party_id'], PurchaseMatchingDomain::fixed($amount, 2), $method, 'دریافت مشتری (ثبت دفتر؛ بدون تسویه بانکی)', $receiptId, $uid]);
            $cashTxId = (int)$db->lastInsertId();

            $db->prepare("UPDATE erp_ar_receipts SET status='posted',voucher_id=?,cash_tx_id=?,cash_gl_account_id=?,ar_gl_account_id=?,payment_method=?,posted_by=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='approved'")
                ->execute([$voucherId, $cashTxId, $cashGl, $arGl, $method, $uid, $receiptId, $wid, $cid]);
            $db->commit();
            Audit::log('ar.receipt.post', 'acc_vouchers', $voucherId, 'ثبت دفتر دریافت مشتری');
            return [
                'id' => $receiptId, 'status' => 'posted', 'voucher_id' => $voucherId, 'existing' => false,
                'bank_reconciled' => false, 'cash_gl_account_id' => $cashGl, 'ar_gl_account_id' => $arGl, 'payment_method' => $method,
            ];
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

    /** Customer-level AR vs unapplied credit vs invoice opens (may diverge). */
    public static function customerBalanceBreakdown(int $wid, int $cid, int $partyId): array
    {
        self::assertCompany($wid, $cid);
        $ar = AccountingPostingDomain::customerOutstanding($wid, $cid, $partyId);
        $receipts = pdo()->prepare("SELECT id,amount FROM erp_ar_receipts WHERE workspace_id=? AND company_id=? AND party_id=? AND status='posted'");
        $receipts->execute([$wid, $cid, $partyId]);
        $unapplied = 0;
        foreach ($receipts->fetchAll() as $row) {
            $unapplied += self::unallocatedReceiptAmount($wid, $cid, (int)$row['id']);
        }
        $inv = pdo()->prepare('SELECT invoice_doc_id FROM erp_ar_postings p JOIN acc_sales_docs d ON d.id=p.invoice_doc_id AND d.workspace_id=p.workspace_id WHERE p.workspace_id=? AND p.company_id=? AND d.party_id=?');
        $inv->execute([$wid, $cid, $partyId]);
        $openInvoices = 0;
        $invoiceStates = [];
        foreach ($inv->fetchAll() as $row) {
            $st = self::invoiceSettlementStatus($wid, $cid, (int)$row['invoice_doc_id']);
            $invoiceStates[] = $st;
            $openInvoices += PurchaseMatchingDomain::scaled($st['open_amount'], 2, 10000000000);
        }
        return [
            'party_id' => $partyId,
            'customer_ar_balance' => $ar['outstanding'],
            'customer_ar_status' => $ar['status'] ?? null,
            'unapplied_receipt_credit' => PurchaseMatchingDomain::fixed($unapplied, 2),
            'open_invoice_total' => PurchaseMatchingDomain::fixed($openInvoices, 2),
            'invoices' => $invoiceStates,
            'note' => 'Zero customer AR balance does not imply all invoices are allocated/settled.',
            'bank_reconciled' => false,
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

    /**
     * Reverse using original voucher line accounts (immutable). Optional reverse_date for open period.
     */
    public static function reverseReceipt(int $wid, int $cid, int $uid, int $receiptId, string $reverseDate = ''): array
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
                return ['id' => $receiptId, 'status' => 'reversed', 'existing' => true, 'reversal_voucher_id' => (int)$r['reversal_voucher_id']];
            }
            if ((string)$r['status'] !== 'posted') {
                throw new RuntimeException('فقط رسید ثبت‌شده قابل برگشت است.');
            }
            $voucherId = (int)$r['voucher_id'];
            if ($voucherId < 1) {
                throw new RuntimeException('سند حسابداری اصلی یافت نشد.');
            }
            $vq = $db->prepare("SELECT * FROM acc_vouchers WHERE id=? AND workspace_id=? AND company_id=? AND source_type='o2c_ar_receipt' LIMIT 1 FOR UPDATE");
            $vq->execute([$voucherId, $wid, $cid]);
            $orig = $vq->fetch();
            if (!$orig) {
                throw new RuntimeException('سند اصلی دریافت معتبر نیست.');
            }
            $lq = $db->prepare('SELECT * FROM acc_voucher_lines WHERE workspace_id=? AND voucher_id=? ORDER BY line_no');
            $lq->execute([$wid, $voucherId]);
            $lines = $lq->fetchAll();
            if (count($lines) < 2) {
                throw new RuntimeException('آرتیکل‌های سند اصلی ناقص است.');
            }
            // Prefer snapshotted GL ids; else infer from original lines (Dr cash, Cr AR).
            $cashGl = (int)($r['cash_gl_account_id'] ?? 0);
            $arGl = (int)($r['ar_gl_account_id'] ?? 0);
            if ($cashGl < 1 || $arGl < 1) {
                foreach ($lines as $ln) {
                    if (PurchaseMatchingDomain::scaled((string)$ln['debit'], 2, 10000000000) > 0) {
                        $cashGl = (int)$ln['account_id'];
                    }
                    if (PurchaseMatchingDomain::scaled((string)$ln['credit'], 2, 10000000000) > 0) {
                        $arGl = (int)$ln['account_id'];
                    }
                }
            }
            if ($cashGl < 1 || $arGl < 1) {
                throw new RuntimeException('حساب‌های تاریخی سند اصلی قابل بازیابی نیست.');
            }

            $date = AccountingRepository::date($reverseDate) ?: date('Y-m-d');
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);
            $amount = PurchaseMatchingDomain::scaled((string)$r['amount'], 2, 10000000000);
            $method = self::normalizeMethod((string)($r['payment_method'] ?? 'bank_transfer'));

            $db->prepare("UPDATE erp_ar_allocations SET status='reversed',reversed_by=?,updated_at=NOW() WHERE workspace_id=? AND company_id=? AND receipt_id=? AND status='active'")
                ->execute([$uid, $wid, $cid, $receiptId]);

            $no = 'AR-RCPT-REV-' . $receiptId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'o2c_ar_receipt_reversal',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'برگشت دریافت ' . $r['receipt_no'] . ' (offset voucher #' . $voucherId . ')',
                    $receiptId, PurchaseMatchingDomain::fixed($amount, 2), PurchaseMatchingDomain::fixed($amount, 2), $uid,
                ]);
            $revId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            // Exact offset of original: Dr AR · Cr Cash (historical accounts)
            $ins->execute([$wid, $revId, 1, $arGl, (int)$r['party_id'], 'برگشت تسویه (تاریخی)', PurchaseMatchingDomain::fixed($amount, 2), '0.00']);
            $ins->execute([$wid, $revId, 2, $cashGl, null, 'برگشت نقد (تاریخی)', '0.00', PurchaseMatchingDomain::fixed($amount, 2)]);

            // Treasury sync: mark original posted tx reversed; insert compensating out tx
            if (!empty($r['cash_tx_id'])) {
                $db->prepare("UPDATE acc_cash_transactions SET status='reversed',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='posted'")
                    ->execute([(int)$r['cash_tx_id'], $wid, $cid]);
            }
            $revTxNo = 'CTX-REV-' . $r['receipt_no'];
            $db->prepare("INSERT INTO acc_cash_transactions (workspace_id,company_id,transaction_no,transaction_date,direction,cash_account_id,party_id,amount,method,status,description,source_type,source_id,created_by,created_at,updated_at)
                VALUES (?,?,?,?,'out',?,?,?,?, 'posted',?,'ar_receipt_reversal',?,?,NOW(),NOW())")
                ->execute([$wid, $cid, $revTxNo, $date, (int)$r['cash_account_id'], (int)$r['party_id'], PurchaseMatchingDomain::fixed($amount, 2), $method, 'برگشت دریافت (جبرانی؛ بدون تسویه بانکی)', $receiptId, $uid]);
            $revTxId = (int)$db->lastInsertId();

            $up = $db->prepare("UPDATE erp_ar_receipts SET status='reversed',reversed_by=?,reversal_voucher_id=?,reverse_cash_tx_id=?,reversal_date=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='posted'");
            $up->execute([$uid, $revId, $revTxId, $date, $receiptId, $wid, $cid]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('وضعیت رسید هم‌زمان تغییر کرده است.');
            }
            $db->commit();
            Audit::log('ar.receipt.reverse', 'acc_vouchers', $revId, 'برگشت دریافت مشتری با حساب‌های تاریخی');
            return [
                'id' => $receiptId, 'status' => 'reversed', 'reversal_voucher_id' => $revId,
                'cash_gl_account_id' => $cashGl, 'ar_gl_account_id' => $arGl,
                'original_voucher_id' => $voucherId, 'existing' => false, 'bank_reconciled' => false,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
