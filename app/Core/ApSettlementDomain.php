<?php
/**
 * ERPSMART 10.23.0 — Supplier payment + AP allocation (domestic trading).
 *
 * Payment GL: Dr AP (snapshotted) · Cr Cash/Bank GL (per treasury cash account).
 * Cheque/Sayad = separate lifecycle; NOT accepted as posted cash settlement here.
 * Bank reconciliation is NEVER claimed by posting a payment.
 */
final class ApSettlementDomain
{
    public const VERSION = '10.23.0-ap-settlement';
    public const SETTLEMENT = 'AVAILABLE';
    public const METHODS = ['cash', 'bank_transfer'];

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_ap_payments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            party_id BIGINT NOT NULL,
            cash_account_id BIGINT NOT NULL,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'bank_transfer',
            payment_no VARCHAR(120) NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(20,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            voucher_id BIGINT NULL,
            cash_tx_id BIGINT NULL,
            reverse_cash_tx_id BIGINT NULL,
            cash_gl_account_id BIGINT NULL,
            ap_gl_account_id BIGINT NULL,
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
            UNIQUE KEY uq_ap_payment_no (workspace_id,company_id,payment_no),
            UNIQUE KEY uq_ap_payment_idem (workspace_id,company_id,idempotency_key),
            INDEX idx_ap_payment_party (workspace_id,company_id,party_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ap_allocations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            payment_id BIGINT NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            amount DECIMAL(20,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            idempotency_key CHAR(64) NULL,
            request_hash CHAR(64) NOT NULL,
            created_by INT NOT NULL,
            reversed_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_ap_alloc_idem (workspace_id,company_id,idempotency_key),
            INDEX idx_ap_alloc_payment (workspace_id,payment_id,status),
            INDEX idx_ap_alloc_invoice (workspace_id,invoice_doc_id,status)
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

    public static function normalizeMethod(string $raw): string
    {
        $m = strtolower(trim($raw));
        if ($m === 'bank') {
            $m = 'bank_transfer';
        }
        if ($m === 'cheque' || $m === 'check' || $m === 'sayad' || $m === 'pos') {
            throw new RuntimeException('چک/صیاد/POS چرخه جداگانه دارد و در این نسخه به‌عنوان پرداخت نقدی تأمین‌کننده ثبت نمی‌شود.');
        }
        if (!in_array($m, self::METHODS, true)) {
            throw new RuntimeException('روش پرداخت پشتیبانی‌شده نیست (cash|bank_transfer).');
        }
        return $m;
    }

    public static function createDraftPayment(int $wid, int $cid, int $uid, array $args): array
    {
        Tenant::requirePermission('accounting.treasury.manage');
        self::assertCompany($wid, $cid);
        $partyId = (int)($args['party_id'] ?? 0);
        $cashAccountId = (int)($args['cash_account_id'] ?? 0);
        $method = self::normalizeMethod((string)($args['payment_method'] ?? 'bank_transfer'));
        $date = AccountingRepository::date((string)($args['payment_date'] ?? '')) ?: '';
        $amountRaw = trim((string)($args['amount'] ?? ''));
        $desc = mb_substr(trim((string)($args['description'] ?? '')), 0, 500);
        $idem = trim((string)($args['idempotency_key'] ?? ''));
        if ($partyId < 1 || $cashAccountId < 1 || $date === '' || $amountRaw === '') {
            throw new RuntimeException('تأمین‌کننده، حساب نقد، تاریخ و مبلغ الزامی است.');
        }
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('کلید idempotency نامعتبر است.');
        }
        $amount = PurchaseMatchingDomain::scaled($amountRaw, 2, 10000000000);
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ پرداخت باید مثبت باشد.');
        }
        $pq = pdo()->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $pq->execute([$partyId, $wid, $cid]);
        if (!$pq->fetchColumn()) {
            throw new RuntimeException('تأمین‌کننده متعلق به شرکت نیست.');
        }
        ArSettlementDomain::resolveCashGl($wid, $cid, $cashAccountId);
        $hash = hash('sha256', json_encode([
            'party' => $partyId, 'cash' => $cashAccountId, 'method' => $method, 'date' => $date,
            'amount' => PurchaseMatchingDomain::fixed($amount, 2), 'desc' => $desc,
        ], JSON_UNESCAPED_UNICODE));

        $db = pdo();
        $db->beginTransaction();
        try {
            if ($idem !== '') {
                $ex = $db->prepare('SELECT id,status,request_hash FROM erp_ap_payments WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
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
            $no = 'APP-' . strtoupper(bin2hex(random_bytes(3)));
            $db->prepare('INSERT INTO erp_ap_payments (workspace_id,company_id,party_id,cash_account_id,payment_method,payment_no,payment_date,amount,status,idempotency_key,request_hash,description,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,\'draft\',?,?,?,?,NOW(),NOW())')
                ->execute([$wid, $cid, $partyId, $cashAccountId, $method, $no, $date, PurchaseMatchingDomain::fixed($amount, 2), $idem !== '' ? $idem : null, $hash, $desc, $uid]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            Audit::log('ap.payment.draft', 'erp_ap_payments', $id, 'پیش‌نویس پرداخت تأمین‌کننده');
            return ['id' => $id, 'payment_no' => $no, 'status' => 'draft', 'existing' => false, 'payment_method' => $method];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function approvePayment(int $wid, int $cid, int $uid, int $paymentId): array
    {
        Tenant::requirePermission('accounting.treasury.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM erp_ap_payments WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$paymentId, $wid, $cid]);
            $r = $q->fetch();
            if (!$r) {
                throw new RuntimeException('پرداخت پیدا نشد.');
            }
            if ((string)$r['status'] === 'approved') {
                $db->commit();
                return ['id' => $paymentId, 'status' => 'approved', 'existing' => true];
            }
            if ((string)$r['status'] !== 'draft') {
                throw new RuntimeException('فقط پیش‌نویس قابل تأیید است.');
            }
            if ((int)$r['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده نمی‌تواند همان پرداخت را تأیید کند (Maker/Checker).');
            }
            $db->prepare("UPDATE erp_ap_payments SET status='approved',approved_by=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='draft'")
                ->execute([$uid, $paymentId, $wid, $cid]);
            $db->commit();
            Audit::log('ap.payment.approve', 'erp_ap_payments', $paymentId, 'تأیید پرداخت تأمین‌کننده');
            return ['id' => $paymentId, 'status' => 'approved', 'existing' => false];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function postPayment(int $wid, int $cid, int $uid, int $paymentId): array
    {
        Tenant::requirePermission('accounting.vouchers.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM erp_ap_payments WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$paymentId, $wid, $cid]);
            $r = $q->fetch();
            if (!$r) {
                throw new RuntimeException('پرداخت پیدا نشد.');
            }
            if ((string)$r['status'] === 'posted') {
                $db->commit();
                return ['id' => $paymentId, 'status' => 'posted', 'voucher_id' => (int)$r['voucher_id'], 'existing' => true, 'bank_reconciled' => false];
            }
            if ((string)$r['status'] !== 'approved') {
                throw new RuntimeException('فقط پرداخت تأییدشده قابل ثبت دفتر است.');
            }
            if ((int)$r['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده نمی‌تواند پرداخت را ثبت دفتر کند (Maker/Checker).');
            }
            $map = ApPostingDomain::companyMap($wid, $cid);
            if (!$map) {
                throw new RuntimeException('نگاشت AP شرکت تنظیم نشده است.');
            }
            $cashGl = ArSettlementDomain::resolveCashGl($wid, $cid, (int)$r['cash_account_id']);
            $apGl = (int)$map['payable_account_id'];
            $accQ = $db->prepare('SELECT account_type,allow_posting,active FROM acc_accounts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
            $accQ->execute([$apGl, $wid, $cid]);
            $apAcc = $accQ->fetch();
            if (!$apAcc || (int)$apAcc['active'] !== 1 || (int)$apAcc['allow_posting'] !== 1 || strtolower((string)$apAcc['account_type']) !== 'liability') {
                throw new RuntimeException('حساب پرداختنی باید بدهی فعال و قابل ثبت باشد.');
            }
            $date = (string)$r['payment_date'];
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);
            $amount = PurchaseMatchingDomain::scaled((string)$r['amount'], 2, 10000000000);
            $method = self::normalizeMethod((string)($r['payment_method'] ?? 'bank_transfer'));

            $no = 'AP-PAY-' . $paymentId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'p2p_ap_payment',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'ثبت پرداخت تأمین‌کننده ' . $r['payment_no'],
                    $paymentId, PurchaseMatchingDomain::fixed($amount, 2), PurchaseMatchingDomain::fixed($amount, 2), $uid,
                ]);
            $voucherId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $ins->execute([$wid, $voucherId, 1, $apGl, (int)$r['party_id'], 'تسویه پرداختنی ' . $r['payment_no'], PurchaseMatchingDomain::fixed($amount, 2), '0.00']);
            $ins->execute([$wid, $voucherId, 2, $cashGl, null, 'نقد/بانک ' . $r['payment_no'], '0.00', PurchaseMatchingDomain::fixed($amount, 2)]);

            $txNo = 'CTX-' . $r['payment_no'];
            $db->prepare("INSERT INTO acc_cash_transactions (workspace_id,company_id,transaction_no,transaction_date,direction,cash_account_id,party_id,amount,method,status,description,source_type,source_id,created_by,created_at,updated_at)
                VALUES (?,?,?,?,'out',?,?,?,?, 'posted',?,'ap_payment',?,?,NOW(),NOW())")
                ->execute([$wid, $cid, $txNo, $date, (int)$r['cash_account_id'], (int)$r['party_id'], PurchaseMatchingDomain::fixed($amount, 2), $method, 'پرداخت تأمین‌کننده (ثبت دفتر؛ بدون تسویه بانکی)', $paymentId, $uid]);
            $cashTxId = (int)$db->lastInsertId();

            $db->prepare("UPDATE erp_ap_payments SET status='posted',voucher_id=?,cash_tx_id=?,cash_gl_account_id=?,ap_gl_account_id=?,payment_method=?,posted_by=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='approved'")
                ->execute([$voucherId, $cashTxId, $cashGl, $apGl, $method, $uid, $paymentId, $wid, $cid]);
            $db->commit();
            Audit::log('ap.payment.post', 'acc_vouchers', $voucherId, 'ثبت دفتر پرداخت تأمین‌کننده');
            return [
                'id' => $paymentId, 'status' => 'posted', 'voucher_id' => $voucherId, 'existing' => false,
                'bank_reconciled' => false, 'cash_gl_account_id' => $cashGl, 'ap_gl_account_id' => $apGl, 'payment_method' => $method,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function unallocatedPaymentAmount(int $wid, int $cid, int $paymentId): int
    {
        $q = pdo()->prepare("SELECT amount FROM erp_ap_payments WHERE id=? AND workspace_id=? AND company_id=? AND status='posted' LIMIT 1");
        $q->execute([$paymentId, $wid, $cid]);
        $amt = $q->fetchColumn();
        if ($amt === false) {
            throw new RuntimeException('پرداخت ثبت‌شده پیدا نشد.');
        }
        $total = PurchaseMatchingDomain::scaled((string)$amt, 2, 10000000000);
        $a = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND payment_id=? AND status='active'");
        $a->execute([$wid, $cid, $paymentId]);
        $used = PurchaseMatchingDomain::scaled((string)$a->fetchColumn(), 2, 10000000000);
        return $total - $used;
    }

    public static function allocate(int $wid, int $cid, int $uid, int $paymentId, int $invoiceDocId, string $amountRaw, string $idempotencyKey = ''): array
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
        $hash = hash('sha256', json_encode(['payment' => $paymentId, 'invoice' => $invoiceDocId, 'amount' => PurchaseMatchingDomain::fixed($amount, 2)], JSON_UNESCAPED_UNICODE));

        $db = pdo();
        $db->beginTransaction();
        try {
            if ($idem !== '') {
                $ex = $db->prepare('SELECT id,request_hash,status FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
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
            $rq = $db->prepare("SELECT * FROM erp_ap_payments WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $rq->execute([$paymentId, $wid, $cid]);
            $payment = $rq->fetch();
            if (!$payment || (string)$payment['status'] !== 'posted') {
                throw new RuntimeException('فقط پرداخت ثبت‌شده قابل تخصیص است.');
            }
            $iq = $db->prepare('SELECT p.*, d.party_id FROM erp_ap_postings p JOIN acc_purchase_docs d ON d.id=p.invoice_doc_id AND d.workspace_id=p.workspace_id WHERE p.workspace_id=? AND p.company_id=? AND p.invoice_doc_id=? LIMIT 1 FOR UPDATE');
            $iq->execute([$wid, $cid, $invoiceDocId]);
            $inv = $iq->fetch();
            if (!$inv) {
                throw new RuntimeException('فاکتور AP ثبت‌شده پیدا نشد.');
            }
            if (!empty($inv['reversal_voucher_id'])) {
                throw new RuntimeException('فاکتور معکوس‌شده قابل تخصیص نیست.');
            }
            if ((int)$inv['party_id'] !== (int)$payment['party_id']) {
                throw new RuntimeException('تأمین‌کننده پرداخت و فاکتور یکسان نیست.');
            }
            $db->prepare('SELECT id FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND (payment_id=? OR invoice_doc_id=?) FOR UPDATE')
                ->execute([$wid, $cid, $paymentId, $invoiceDocId]);

            $unalloc = self::unallocatedPaymentAmount($wid, $cid, $paymentId);
            $openRow = ApPostingDomain::invoiceOutstanding($wid, $cid, $invoiceDocId);
            $open = PurchaseMatchingDomain::scaled((string)$openRow['outstanding'], 2, 10000000000);
            if ($amount > $unalloc) {
                throw new RuntimeException('مبلغ تخصیص از مانده پرداخت بیشتر است.');
            }
            if ($amount > $open) {
                throw new RuntimeException('مبلغ تخصیص از مانده فاکتور بیشتر است.');
            }
            $db->prepare('INSERT INTO erp_ap_allocations (workspace_id,company_id,payment_id,invoice_doc_id,amount,status,idempotency_key,request_hash,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,\'active\',?,?,?,NOW(),NOW())')
                ->execute([$wid, $cid, $paymentId, $invoiceDocId, PurchaseMatchingDomain::fixed($amount, 2), $idem !== '' ? $idem : null, $hash, $uid]);
            $id = (int)$db->lastInsertId();
            $db->commit();
            Audit::log('ap.allocation.create', 'erp_ap_allocations', $id, 'تخصیص پرداخت به فاکتور خرید');
            return [
                'id' => $id, 'status' => 'active', 'existing' => false,
                'invoice' => ApPostingDomain::invoiceOutstanding($wid, $cid, $invoiceDocId),
                'payment_unallocated' => PurchaseMatchingDomain::fixed(self::unallocatedPaymentAmount($wid, $cid, $paymentId), 2),
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
