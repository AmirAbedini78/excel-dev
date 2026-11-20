<?php
/**
 * ERPSMART 10.22.0 — Canonical Accounts Payable posting kernel.
 *
 * Valuation model: invoice-based AP liability (NO GRNI).
 * Stock movements never auto-post GL. Payment/settlement = NOT_IMPLEMENTED.
 *
 * Canonical path: eligible purchase invoice → validate → approve → balanced
 * acc_vouchers / acc_voucher_lines (source_type=p2p_ap) + erp_ap_postings.
 */
final class ApPostingDomain
{
    public const VERSION = '10.23.0-ap';
    public const PAYMENT = 'AVAILABLE';
    public const CURRENCY = 'IRR';
    public const VALUATION_MODEL = 'invoice_based';

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_ap_company_maps (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            payable_account_id BIGINT NOT NULL,
            purchase_account_id BIGINT NOT NULL,
            clearing_account_id BIGINT NULL,
            input_vat_account_id BIGINT NULL,
            ppv_account_id BIGINT NULL,
            valuation_model VARCHAR(40) NOT NULL DEFAULT 'invoice_based',
            updated_by INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ap_workflow (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            request_hash CHAR(64) NULL,
            validated_by INT NULL,
            validated_at DATETIME NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,invoice_doc_id),
            INDEX idx_ap_wf_status (workspace_id,company_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_ap_postings (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            invoice_doc_id BIGINT NOT NULL,
            voucher_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            net_total DECIMAL(20,2) NOT NULL,
            payable_account_id BIGINT NULL,
            purchase_account_id BIGINT NULL,
            input_vat_account_id BIGINT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'IRR',
            valuation_model VARCHAR(40) NOT NULL DEFAULT 'invoice_based',
            posted_by INT NOT NULL,
            posted_at DATETIME NOT NULL,
            reversed_by INT NULL,
            reversal_voucher_id BIGINT NULL,
            reversal_date DATE NULL,
            UNIQUE KEY uq_ap_invoice (workspace_id,company_id,invoice_doc_id),
            UNIQUE KEY uq_ap_idem (workspace_id,company_id,idempotency_key),
            UNIQUE KEY uq_ap_voucher (workspace_id,voucher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            "ALTER TABLE erp_ap_postings ADD COLUMN payable_account_id BIGINT NULL AFTER net_total",
            "ALTER TABLE erp_ap_postings ADD COLUMN purchase_account_id BIGINT NULL AFTER payable_account_id",
            "ALTER TABLE erp_ap_postings ADD COLUMN input_vat_account_id BIGINT NULL AFTER purchase_account_id",
            "ALTER TABLE erp_ap_postings ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'IRR' AFTER input_vat_account_id",
            "ALTER TABLE erp_ap_postings ADD COLUMN valuation_model VARCHAR(40) NOT NULL DEFAULT 'invoice_based' AFTER currency",
            "ALTER TABLE erp_ap_postings ADD COLUMN reversed_by INT NULL AFTER posted_at",
            "ALTER TABLE erp_ap_postings ADD COLUMN reversal_voucher_id BIGINT NULL AFTER reversed_by",
            "ALTER TABLE erp_ap_postings ADD COLUMN reversal_date DATE NULL AFTER reversal_voucher_id",
        ] as $sql) {
            try { $db->exec($sql); } catch (Throwable $e) { /* additive */ }
        }
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

    /** @return array account row */
    private static function assertAccountTyped(int $wid, int $cid, int $accountId, string $label, array $allowedTypes): array
    {
        if ($accountId < 1) {
            throw new RuntimeException($label . ' مشخص نشده است.');
        }
        $q = pdo()->prepare('SELECT id,code,name,account_type,nature,allow_posting,active FROM acc_accounts WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$accountId, $wid, $cid]);
        $row = $q->fetch();
        if (!$row) {
            throw new RuntimeException($label . ' متعلق به شرکت نیست.');
        }
        if ((int)$row['active'] !== 1) {
            throw new RuntimeException($label . ' غیرفعال است.');
        }
        if ((int)$row['allow_posting'] !== 1) {
            throw new RuntimeException($label . ' قابل ثبت سند نیست (allow_posting=0).');
        }
        $type = strtolower((string)$row['account_type']);
        if (!in_array($type, $allowedTypes, true)) {
            throw new RuntimeException($label . ' نوع حساب نامعتبر است (نیاز: ' . implode('|', $allowedTypes) . ').');
        }
        return $row;
    }

    public static function setCompanyMap(int $wid, int $cid, int $uid, array $map): void
    {
        Tenant::requirePermission('accounting.settings.manage');
        self::assertCompany($wid, $cid);
        $ap = (int)($map['payable_account_id'] ?? 0);
        $purchase = (int)($map['purchase_account_id'] ?? 0);
        $clearing = (int)($map['clearing_account_id'] ?? 0);
        $vat = (int)($map['input_vat_account_id'] ?? 0);
        $ppv = (int)($map['ppv_account_id'] ?? 0);
        self::assertAccountTyped($wid, $cid, $ap, 'حساب پرداختنی', ['liability']);
        // Purchase side may capitalize inventory (asset) or expense purchases.
        self::assertAccountTyped($wid, $cid, $purchase, 'حساب خرید/موجودی', ['expense', 'asset']);
        if ($clearing > 0) {
            self::assertAccountTyped($wid, $cid, $clearing, 'حساب واسط/معلق', ['liability', 'asset', 'expense']);
        } else {
            $clearing = null;
        }
        if ($vat > 0) {
            self::assertAccountTyped($wid, $cid, $vat, 'حساب مالیات ورودی', ['asset', 'liability']);
        } else {
            $vat = null;
        }
        if ($ppv > 0) {
            self::assertAccountTyped($wid, $cid, $ppv, 'حساب انحراف قیمت خرید', ['expense', 'income', 'revenue']);
        } else {
            $ppv = null;
        }
        // Never reuse AR control/revenue as AP accounts.
        $arMap = class_exists('AccountingPostingDomain') ? AccountingPostingDomain::companyMap($wid, $cid) : null;
        if ($arMap) {
            $arIds = array_filter([
                (int)$arMap['receivable_account_id'],
                (int)$arMap['revenue_account_id'],
                (int)($arMap['tax_account_id'] ?? 0),
            ]);
            foreach ([$ap, $purchase, (int)$vat, (int)$clearing] as $id) {
                if ($id > 0 && in_array($id, $arIds, true)) {
                    throw new RuntimeException('حساب‌های AP نباید با نگاشت AR یکی باشند.');
                }
            }
        }
        if ($ap === $purchase || ($vat !== null && ($ap === $vat || $purchase === $vat))) {
            throw new RuntimeException('حساب‌های پرداختنی، خرید و مالیات باید متمایز باشند.');
        }
        pdo()->prepare('INSERT INTO erp_ap_company_maps (workspace_id,company_id,payable_account_id,purchase_account_id,clearing_account_id,input_vat_account_id,ppv_account_id,valuation_model,updated_by,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE payable_account_id=VALUES(payable_account_id),purchase_account_id=VALUES(purchase_account_id),clearing_account_id=VALUES(clearing_account_id),input_vat_account_id=VALUES(input_vat_account_id),ppv_account_id=VALUES(ppv_account_id),valuation_model=VALUES(valuation_model),updated_by=VALUES(updated_by),updated_at=NOW()')
            ->execute([$wid, $cid, $ap, $purchase, $clearing, $vat, $ppv, self::VALUATION_MODEL, $uid]);
        Audit::log('ap.map.set', 'erp_ap_company_maps', $cid, 'تنظیم نگاشت حساب‌های AP');
    }

    public static function companyMap(int $wid, int $cid): ?array
    {
        $q = pdo()->prepare('SELECT * FROM erp_ap_company_maps WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid, $cid]);
        $row = $q->fetch();
        return $row ?: null;
    }

    public static function assessReadiness(int $wid, int $cid): array
    {
        self::assertCompany($wid, $cid);
        $map = self::companyMap($wid, $cid);
        $fy = pdo()->prepare("SELECT COUNT(*) FROM acc_fiscal_years WHERE workspace_id=? AND company_id=? AND status='open'");
        $fy->execute([$wid, $cid]);
        $openFy = (int)$fy->fetchColumn();
        $mapOk = false;
        $mapBlocker = 'account_map_missing';
        $modelOk = false;
        $modelBlocker = 'valuation_model_unresolved';
        if ($map) {
            try {
                self::assertAccountTyped($wid, $cid, (int)$map['payable_account_id'], 'حساب پرداختنی', ['liability']);
                self::assertAccountTyped($wid, $cid, (int)$map['purchase_account_id'], 'حساب خرید/موجودی', ['expense', 'asset']);
                if (!empty($map['input_vat_account_id'])) {
                    self::assertAccountTyped($wid, $cid, (int)$map['input_vat_account_id'], 'حساب مالیات ورودی', ['asset', 'liability']);
                }
                $mapOk = true;
                $mapBlocker = null;
            } catch (Throwable $e) {
                $mapBlocker = 'account_map_invalid';
            }
            if ((string)($map['valuation_model'] ?? '') === self::VALUATION_MODEL) {
                $modelOk = true;
                $modelBlocker = null;
            }
        }
        $ready = $mapOk && $modelOk && $openFy > 0;
        return [
            'ready' => $ready,
            'has_account_map' => $map !== null,
            'account_map_valid' => $mapOk,
            'valuation_model' => self::VALUATION_MODEL,
            'valuation_model_ok' => $modelOk,
            'open_fiscal_years' => $openFy,
            'payment' => self::PAYMENT,
            'blockers' => array_values(array_filter([
                $mapBlocker,
                $modelBlocker,
                $openFy < 1 ? 'fiscal_year_closed_or_missing' : null,
            ])),
        ];
    }

    public static function invoiceRequestHash(int $wid, int $cid, array $inv, array $lines, ?array $bill): string
    {
        $linePayload = [];
        foreach ($lines as $l) {
            $linePayload[] = [
                'id' => (int)$l['id'],
                'line_no' => (int)$l['line_no'],
                'item_id' => (int)$l['item_id'],
                'qty' => (string)$l['quantity'],
                'price' => (string)$l['unit_price'],
                'disc' => (string)($l['discount_amount'] ?? '0'),
                'total' => (string)$l['line_total'],
            ];
        }
        usort($linePayload, static fn($a, $b) => $a['line_no'] <=> $b['line_no']);
        return hash('sha256', json_encode([
            'workspace_id' => $wid,
            'company_id' => $cid,
            'invoice_doc_id' => (int)$inv['id'],
            'doc_type' => (string)$inv['doc_type'],
            'party_id' => (int)$inv['party_id'],
            'document_no' => (string)$inv['document_no'],
            'party_invoice_no' => (string)($inv['party_invoice_no'] ?? ''),
            'document_date' => (string)$inv['document_date'],
            'currency' => self::CURRENCY,
            'gross' => (string)$inv['total_before_discount'],
            'disc' => (string)$inv['discount_total'],
            'tax' => (string)$inv['tax_total'],
            'net' => (string)$inv['net_total'],
            'lines' => $linePayload,
            'p2p_bill_id' => $bill ? (int)$bill['id'] : null,
            'p2p_status' => $bill ? (string)$bill['status'] : null,
            'valuation_model' => self::VALUATION_MODEL,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function isDuplicateKey(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $info = $e->errorInfo ?? [];
        $sqlState = (string)($info[0] ?? '');
        $driver = (int)($info[1] ?? 0);
        $msg = $e->getMessage();
        return $sqlState === '23000' || $driver === 1062 || stripos($msg, 'Duplicate') !== false;
    }

    private static function loadInvoice(PDO $db, int $wid, int $cid, int $invoiceDocId, bool $lock = false): array
    {
        $q = $db->prepare('SELECT * FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$invoiceDocId, $wid, $cid]);
        $inv = $q->fetch();
        if (!$inv) {
            throw new RuntimeException('فاکتور خرید پیدا نشد.');
        }
        return $inv;
    }

    private static function assertEligibleInvoice(int $wid, int $cid, array $inv, ?PDO $db = null): ?array
    {
        $db = $db ?: pdo();
        $docType = (string)$inv['doc_type'];
        if (!in_array($docType, ['purchase_invoice_goods', 'purchase_invoice_service'], true)) {
            throw new RuntimeException('فقط فاکتور خرید کالا/خدمت برای ثبت AP مجاز است.');
        }
        if (in_array((string)$inv['workflow_status'], ['void', 'cancelled'], true)) {
            throw new RuntimeException('فاکتور باطل قابل ثبت نیست.');
        }
        $bill = null;
        if ($docType === 'purchase_invoice_goods') {
            $bq = $db->prepare("SELECT * FROM erp_p2p_vendor_bills WHERE workspace_id=? AND company_id=? AND canonical_doc_id=? AND status='matched' LIMIT 1");
            $bq->execute([$wid, $cid, (int)$inv['id']]);
            $bill = $bq->fetch() ?: null;
            if (PurchaseMatchingDomain::isGoodsMatchEnforced($wid, $cid)) {
                if (!$bill) {
                    throw new RuntimeException('فاکتور کالایی بدون تطبیق سه‌طرفه کامل قابل ثبت AP نیست.');
                }
            }
        } else {
            // Service invoices need explicit company policy (separate from goods 3WM).
            $policy = PurchaseMatchingDomain::companyPolicy($wid, $cid);
            if ((int)($policy['allow_service_manual'] ?? 0) !== 1) {
                throw new RuntimeException('ثبت AP فاکتور خدمات نیازمند سیاست اجازه خدمات دستی شرکت است.');
            }
        }
        return $bill ?: null;
    }

    private static function reconcileHeaderLines(array $inv, array $lines): array
    {
        $sumGross = 0;
        $sumDisc = 0;
        $sumNet = 0;
        foreach ($lines as $l) {
            $qty = PurchaseMatchingDomain::scaled((string)$l['quantity'], 4, 100000);
            $price = PurchaseMatchingDomain::scaled((string)$l['unit_price'], 2, 10000000000);
            $disc = PurchaseMatchingDomain::scaled((string)($l['discount_amount'] ?? '0'), 2, 10000000000);
            $lineTotal = PurchaseMatchingDomain::scaled((string)$l['line_total'], 2, 10000000000);
            $base = PurchaseMatchingDomain::cents($qty, $price);
            $expected = max(0, $base - $disc);
            if ($expected !== $lineTotal) {
                throw new RuntimeException('جمع ردیف خرید با اجزای قیمت/تخفیف سازگار نیست.');
            }
            $sumGross += $base;
            $sumDisc += $disc;
            $sumNet += $lineTotal;
        }
        $hdrGross = PurchaseMatchingDomain::scaled((string)$inv['total_before_discount'], 2, 10000000000);
        $hdrDisc = PurchaseMatchingDomain::scaled((string)$inv['discount_total'], 2, 10000000000);
        $hdrTax = PurchaseMatchingDomain::scaled((string)$inv['tax_total'], 2, 10000000000);
        $hdrNet = PurchaseMatchingDomain::scaled((string)$inv['net_total'], 2, 10000000000);
        if ($hdrGross !== $sumGross || $hdrDisc !== $sumDisc) {
            throw new RuntimeException('جمع سربرگ فاکتور خرید با ردیف‌ها بالانس نیست.');
        }
        // Lines are tax-exclusive; header net = line net + tax (P2P drafts use tax=0).
        if ($hdrNet !== $sumNet + $hdrTax) {
            throw new RuntimeException('جمع سربرگ فاکتور خرید با ردیف‌ها/مالیات بالانس نیست.');
        }
        if ($hdrNet <= 0) {
            throw new RuntimeException('فاکتور با مبلغ صفر قابل ثبت AP نیست.');
        }
        return ['gross' => $hdrGross, 'disc' => $hdrDisc, 'tax' => $hdrTax, 'net' => $hdrNet, 'line_net' => $sumNet];
    }

    private static function workflowRow(PDO $db, int $wid, int $cid, int $invoiceDocId, bool $lock = false): ?array
    {
        $q = $db->prepare('SELECT * FROM erp_ap_workflow WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$wid, $cid, $invoiceDocId]);
        $row = $q->fetch();
        return $row ?: null;
    }

    public static function workflowStatus(int $wid, int $cid, int $invoiceDocId): array
    {
        self::assertCompany($wid, $cid);
        $wf = self::workflowRow(pdo(), $wid, $cid, $invoiceDocId);
        $posted = pdo()->prepare('SELECT voucher_id,reversal_voucher_id FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
        $posted->execute([$wid, $cid, $invoiceDocId]);
        $p = $posted->fetch();
        return [
            'invoice_doc_id' => $invoiceDocId,
            'status' => $p ? ($p['reversal_voucher_id'] ? 'reversed' : 'posted') : ($wf['status'] ?? 'draft'),
            'voucher_id' => $p ? (int)$p['voucher_id'] : null,
            'payment' => self::PAYMENT,
        ];
    }

    /** draft → validated */
    public static function validateInvoice(int $wid, int $cid, int $uid, int $invoiceDocId): array
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $inv = self::loadInvoice($db, $wid, $cid, $invoiceDocId, true);
            $bill = self::assertEligibleInvoice($wid, $cid, $inv, $db);
            if ((int)$inv['created_by'] > 0 && (int)$inv['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده فاکتور نمی‌تواند آن را اعتبارسنجی کند (Maker/Checker).');
            }
            $lq = $db->prepare('SELECT * FROM acc_purchase_lines WHERE workspace_id=? AND purchase_doc_id=? ORDER BY line_no FOR UPDATE');
            $lq->execute([$wid, $invoiceDocId]);
            $lines = $lq->fetchAll();
            if (!$lines) {
                throw new RuntimeException('فاکتور بدون ردیف قابل اعتبارسنجی نیست.');
            }
            self::reconcileHeaderLines($inv, $lines);
            $reqHash = self::invoiceRequestHash($wid, $cid, $inv, $lines, $bill);
            $wf = self::workflowRow($db, $wid, $cid, $invoiceDocId, true);
            if ($wf && (string)$wf['status'] === 'validated') {
                if ((string)$wf['request_hash'] !== $reqHash) {
                    throw new RuntimeException('فاکتور پس از اعتبارسنجی تغییر کرده است.');
                }
                $db->commit();
                return ['invoice_doc_id' => $invoiceDocId, 'status' => 'validated', 'existing' => true];
            }
            if ($wf && !in_array((string)$wf['status'], ['draft'], true)) {
                throw new RuntimeException('وضعیت گردش‌کار برای اعتبارسنجی مجاز نیست.');
            }
            if ((string)$inv['workflow_status'] === 'final' && strpos((string)$inv['notes'], '[AP_POSTED') !== false) {
                throw new RuntimeException('فاکتور قبلاً ثبت AP شده است.');
            }
            $db->prepare('INSERT INTO erp_ap_workflow (workspace_id,company_id,invoice_doc_id,status,request_hash,validated_by,validated_at,updated_at)
                VALUES (?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status),request_hash=VALUES(request_hash),validated_by=VALUES(validated_by),validated_at=VALUES(validated_at),approved_by=NULL,approved_at=NULL,updated_at=NOW()')
                ->execute([$wid, $cid, $invoiceDocId, 'validated', $reqHash, $uid]);
            $db->prepare("UPDATE acc_purchase_docs SET workflow_status='validated',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status IN ('draft','validated')")
                ->execute([$invoiceDocId, $wid, $cid]);
            $db->commit();
            Audit::log('ap.invoice.validate', 'acc_purchase_docs', $invoiceDocId, 'اعتبارسنجی فاکتور خرید برای AP');
            return ['invoice_doc_id' => $invoiceDocId, 'status' => 'validated', 'existing' => false, 'request_hash' => $reqHash];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** validated → approved */
    public static function approveInvoice(int $wid, int $cid, int $uid, int $invoiceDocId): array
    {
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $inv = self::loadInvoice($db, $wid, $cid, $invoiceDocId, true);
            $bill = self::assertEligibleInvoice($wid, $cid, $inv, $db);
            $lq = $db->prepare('SELECT * FROM acc_purchase_lines WHERE workspace_id=? AND purchase_doc_id=? ORDER BY line_no FOR UPDATE');
            $lq->execute([$wid, $invoiceDocId]);
            $lines = $lq->fetchAll();
            if (!$lines) {
                throw new RuntimeException('فاکتور بدون ردیف قابل تأیید نیست.');
            }
            self::reconcileHeaderLines($inv, $lines);
            $reqHash = self::invoiceRequestHash($wid, $cid, $inv, $lines, $bill);
            $wf = self::workflowRow($db, $wid, $cid, $invoiceDocId, true);
            if (!$wf || (string)$wf['status'] === 'draft') {
                throw new RuntimeException('ابتدا فاکتور باید اعتبارسنجی شود.');
            }
            if ((string)$wf['status'] === 'approved') {
                if ((string)$wf['request_hash'] !== $reqHash) {
                    throw new RuntimeException('فاکتور پس از تأیید تغییر کرده است.');
                }
                $db->commit();
                return ['invoice_doc_id' => $invoiceDocId, 'status' => 'approved', 'existing' => true];
            }
            if ((string)$wf['status'] !== 'validated') {
                throw new RuntimeException('فقط فاکتور اعتبارسنجی‌شده قابل تأیید است.');
            }
            if ((string)$wf['request_hash'] !== $reqHash) {
                throw new RuntimeException('فاکتور پس از اعتبارسنجی تغییر کرده است؛ اعتبارسنجی مجدد لازم است.');
            }
            if ((int)$wf['validated_by'] === $uid) {
                throw new RuntimeException('اعتبارسنج نمی‌تواند همان فاکتور را تأیید کند (Maker/Checker).');
            }
            if ((int)$inv['created_by'] > 0 && (int)$inv['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده فاکتور نمی‌تواند آن را تأیید کند (Maker/Checker).');
            }
            $db->prepare("UPDATE erp_ap_workflow SET status='approved',approved_by=?,approved_at=NOW(),updated_at=NOW() WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='validated'")
                ->execute([$uid, $wid, $cid, $invoiceDocId]);
            $db->prepare("UPDATE acc_purchase_docs SET workflow_status='approved',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status IN ('validated','approved')")
                ->execute([$invoiceDocId, $wid, $cid]);
            $db->commit();
            Audit::log('ap.invoice.approve', 'acc_purchase_docs', $invoiceDocId, 'تأیید فاکتور خرید برای ثبت AP');
            return ['invoice_doc_id' => $invoiceDocId, 'status' => 'approved', 'existing' => false];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** approved → posted AP voucher */
    public static function postSupplierInvoice(int $wid, int $cid, int $uid, int $invoiceDocId, string $idempotencyKey = ''): array
    {
        Tenant::requirePermission('accounting.vouchers.manage');
        self::assertCompany($wid, $cid);
        if ($uid < 1 || $invoiceDocId < 1) {
            throw new RuntimeException('شناسه نامعتبر است.');
        }
        $idem = trim($idempotencyKey);
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('کلید idempotency نامعتبر است.');
        }
        if ($idem === '') {
            $idem = hash('sha256', 'ap-inv-' . $wid . '-' . $cid . '-' . $invoiceDocId);
        }

        $db = pdo();
        $db->beginTransaction();
        $reqHash = '';
        try {
            $inv = self::loadInvoice($db, $wid, $cid, $invoiceDocId, true);
            $bill = self::assertEligibleInvoice($wid, $cid, $inv, $db);
            if ((int)$inv['created_by'] > 0 && (int)$inv['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده فاکتور نمی‌تواند آن را به دفتر ثبت کند (Maker/Checker).');
            }

            $lq = $db->prepare('SELECT * FROM acc_purchase_lines WHERE workspace_id=? AND purchase_doc_id=? ORDER BY line_no FOR UPDATE');
            $lq->execute([$wid, $invoiceDocId]);
            $lines = $lq->fetchAll();
            if (!$lines) {
                throw new RuntimeException('فاکتور بدون ردیف قابل ثبت نیست.');
            }
            $amounts = self::reconcileHeaderLines($inv, $lines);
            $reqHash = self::invoiceRequestHash($wid, $cid, $inv, $lines, $bill);

            $q = $db->prepare('SELECT voucher_id,idempotency_key,request_hash,reversal_voucher_id FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$wid, $cid, $invoiceDocId]);
            $existing = $q->fetch();
            if ($existing) {
                if (!empty($existing['reversal_voucher_id'])) {
                    throw new RuntimeException('فاکتور معکوس‌شده است؛ ثبت مجدد مجاز نیست.');
                }
                if ((string)$existing['request_hash'] !== $reqHash) {
                    throw new RuntimeException('فاکتور پس از ثبت تغییر کرده است؛ ثبت مجدد مجاز نیست. اصلاح فقط با سند معکوس کنترل‌شده.');
                }
                $db->commit();
                return ['existing' => true, 'voucher_id' => (int)$existing['voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'posted', 'payment' => self::PAYMENT];
            }
            $q = $db->prepare('SELECT voucher_id,request_hash,invoice_doc_id FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1');
            $q->execute([$wid, $cid, $idem]);
            $byKey = $q->fetch();
            if ($byKey) {
                if ((int)$byKey['invoice_doc_id'] !== $invoiceDocId || (string)$byKey['request_hash'] !== $reqHash) {
                    throw new RuntimeException('کلید idempotency با فاکتور/محتوای متفاوت استفاده شده است.');
                }
                $db->commit();
                return ['existing' => true, 'voucher_id' => (int)$byKey['voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'posted', 'payment' => self::PAYMENT];
            }

            $wf = self::workflowRow($db, $wid, $cid, $invoiceDocId, true);
            if (!$wf || (string)$wf['status'] !== 'approved') {
                throw new RuntimeException('فقط فاکتور تأییدشده قابل ثبت AP است.');
            }
            if ((string)$wf['request_hash'] !== $reqHash) {
                throw new RuntimeException('فاکتور پس از تأیید تغییر کرده است.');
            }
            if ((int)$wf['approved_by'] === $uid) {
                throw new RuntimeException('تأییدکننده نمی‌تواند همان فاکتور را ثبت دفتر کند (Maker/Checker).');
            }

            $ready = self::assessReadiness($wid, $cid);
            if (empty($ready['ready'])) {
                throw new RuntimeException('مدل ارزیابی/نگاشت AP آماده نیست: ' . implode(',', $ready['blockers']));
            }
            $map = self::companyMap($wid, $cid);
            $apAcc = self::assertAccountTyped($wid, $cid, (int)$map['payable_account_id'], 'حساب پرداختنی', ['liability']);
            $purAcc = self::assertAccountTyped($wid, $cid, (int)$map['purchase_account_id'], 'حساب خرید/موجودی', ['expense', 'asset']);
            $tax = $amounts['tax'];
            $net = $amounts['net'];
            $purchaseAmt = $net - $tax;
            if ($purchaseAmt < 0) {
                throw new RuntimeException('مالیات نمی‌تواند از خالص فاکتور بیشتر باشد.');
            }
            $vatAccId = null;
            if ($tax > 0) {
                $vatAccId = (int)($map['input_vat_account_id'] ?? 0);
                if ($vatAccId < 1) {
                    throw new RuntimeException('حساب مالیات ورودی برای فاکتور دارای مالیات تنظیم نشده است.');
                }
                self::assertAccountTyped($wid, $cid, $vatAccId, 'حساب مالیات ورودی', ['asset', 'liability']);
            }

            $date = (string)$inv['document_date'];
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);

            $partyId = (int)$inv['party_id'];
            $q = $db->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
            $q->execute([$partyId, $wid, $cid]);
            if (!$q->fetchColumn()) {
                throw new RuntimeException('تأمین‌کننده فاکتور متعلق به شرکت نیست.');
            }

            $jl = [];
            if ($purchaseAmt > 0) {
                $jl[] = ['account_id' => (int)$purAcc['id'], 'party_id' => $partyId, 'debit' => $purchaseAmt, 'credit' => 0, 'desc' => 'خرید ' . $inv['document_no']];
            }
            if ($tax > 0) {
                $jl[] = ['account_id' => $vatAccId, 'party_id' => null, 'debit' => $tax, 'credit' => 0, 'desc' => 'مالیات ورودی ' . $inv['document_no']];
            }
            $jl[] = ['account_id' => (int)$apAcc['id'], 'party_id' => $partyId, 'debit' => 0, 'credit' => $net, 'desc' => 'پرداختنی تأمین‌کننده ' . $inv['document_no']];
            $debit = 0;
            $credit = 0;
            foreach ($jl as $l) {
                $debit += $l['debit'];
                $credit += $l['credit'];
            }
            if ($debit !== $credit) {
                throw new RuntimeException('آرتیکل‌های AP بالانس نیستند.');
            }

            $no = 'AP-' . $invoiceDocId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'p2p_ap',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'ثبت خودکار AP از فاکتور ' . $inv['document_no'],
                    $invoiceDocId, PurchaseMatchingDomain::fixed($debit, 2), PurchaseMatchingDomain::fixed($credit, 2), $uid,
                ]);
            $voucherId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $n = 1;
            foreach ($jl as $l) {
                $ins->execute([
                    $wid, $voucherId, $n++, $l['account_id'], $l['party_id'], $l['desc'],
                    PurchaseMatchingDomain::fixed((int)$l['debit'], 2), PurchaseMatchingDomain::fixed((int)$l['credit'], 2),
                ]);
            }
            $db->prepare("UPDATE acc_purchase_docs SET workflow_status='final',notes=CONCAT(COALESCE(notes,''),' [AP_POSTED voucher=',?,']'),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status IN ('approved','final')")
                ->execute([(string)$voucherId, $invoiceDocId, $wid, $cid]);
            $db->prepare("UPDATE erp_ap_workflow SET status='posted',updated_at=NOW() WHERE workspace_id=? AND company_id=? AND invoice_doc_id=?")
                ->execute([$wid, $cid, $invoiceDocId]);
            $db->prepare('INSERT INTO erp_ap_postings (workspace_id,company_id,invoice_doc_id,voucher_id,idempotency_key,request_hash,net_total,payable_account_id,purchase_account_id,input_vat_account_id,currency,valuation_model,posted_by,posted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([
                    $wid, $cid, $invoiceDocId, $voucherId, $idem, $reqHash, PurchaseMatchingDomain::fixed($net, 2),
                    (int)$apAcc['id'], (int)$purAcc['id'], $vatAccId, self::CURRENCY, self::VALUATION_MODEL, $uid,
                ]);
            $db->commit();
            Audit::log('ap.invoice.post', 'acc_vouchers', $voucherId, 'ثبت AP فاکتور ' . $inv['document_no']);
            return [
                'existing' => false, 'voucher_id' => $voucherId, 'voucher_no' => $no, 'invoice_doc_id' => $invoiceDocId,
                'status' => 'posted', 'net_total' => PurchaseMatchingDomain::fixed($net, 2), 'payment' => self::PAYMENT,
                'request_hash' => $reqHash, 'valuation_model' => self::VALUATION_MODEL,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (self::isDuplicateKey($e) && $reqHash !== '') {
                $q = pdo()->prepare('SELECT voucher_id,request_hash FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
                $q->execute([$wid, $cid, $invoiceDocId]);
                $row = $q->fetch();
                if ($row && (string)$row['request_hash'] === $reqHash) {
                    return ['existing' => true, 'voucher_id' => (int)$row['voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'posted', 'race_recovered' => true, 'payment' => self::PAYMENT];
                }
            }
            throw $e;
        }
    }

    /**
     * History-preserving reverse: compensating voucher using snapshotted accounts.
     * Does not delete posted history. Payment allocations (future) must be cleared first.
     */
    public static function reversePostedInvoice(int $wid, int $cid, int $uid, int $invoiceDocId, string $reversalDate = ''): array
    {
        Tenant::requirePermission('accounting.vouchers.manage');
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$wid, $cid, $invoiceDocId]);
            $p = $q->fetch();
            if (!$p) {
                throw new RuntimeException('ثبت AP یافت نشد.');
            }
            if (!empty($p['reversal_voucher_id'])) {
                $db->commit();
                return ['existing' => true, 'reversal_voucher_id' => (int)$p['reversal_voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'reversed'];
            }
            // Future payment allocations must not be ignored.
            try {
                $aq = $db->prepare("SELECT COUNT(*) FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='active'");
                $aq->execute([$wid, $cid, $invoiceDocId]);
                if ((int)$aq->fetchColumn() > 0) {
                    throw new RuntimeException('فاکتور دارای تخصیص پرداخت فعال است؛ ابتدا تخصیص را معکوس کنید.');
                }
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'erp_ap_allocations') === false) {
                    throw $e;
                }
            }
            $date = AccountingRepository::date($reversalDate) ?: date('Y-m-d');
            $fyId = AccountingPostingDomain::assertFiscalOpen($wid, $cid, $date, $db);
            $origId = (int)$p['voucher_id'];
            $lq = $db->prepare('SELECT * FROM acc_voucher_lines WHERE workspace_id=? AND voucher_id=? ORDER BY line_no');
            $lq->execute([$wid, $origId]);
            $lines = $lq->fetchAll();
            if (!$lines) {
                throw new RuntimeException('آرتیکل سند اصلی یافت نشد.');
            }
            $no = 'AP-REV-' . $invoiceDocId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $debit = 0;
            $credit = 0;
            foreach ($lines as $l) {
                $debit += PurchaseMatchingDomain::scaled((string)$l['credit'], 2, 10000000000);
                $credit += PurchaseMatchingDomain::scaled((string)$l['debit'], 2, 10000000000);
            }
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'p2p_ap_reversal',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'معکوس AP فاکتور #' . $invoiceDocId,
                    $invoiceDocId, PurchaseMatchingDomain::fixed($debit, 2), PurchaseMatchingDomain::fixed($credit, 2), $uid,
                ]);
            $revId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $n = 1;
            foreach ($lines as $l) {
                $ins->execute([
                    $wid, $revId, $n++, (int)$l['account_id'], $l['party_id'], 'معکوس: ' . $l['description'],
                    (string)$l['credit'], (string)$l['debit'],
                ]);
            }
            $db->prepare('UPDATE erp_ap_postings SET reversed_by=?,reversal_voucher_id=?,reversal_date=? WHERE workspace_id=? AND company_id=? AND invoice_doc_id=?')
                ->execute([$uid, $revId, $date, $wid, $cid, $invoiceDocId]);
            $db->prepare("UPDATE erp_ap_workflow SET status='reversed',updated_at=NOW() WHERE workspace_id=? AND company_id=? AND invoice_doc_id=?")
                ->execute([$wid, $cid, $invoiceDocId]);
            $db->prepare("UPDATE acc_purchase_docs SET notes=CONCAT(COALESCE(notes,''),' [AP_REVERSED voucher=',?,']'),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")
                ->execute([(string)$revId, $invoiceDocId, $wid, $cid]);
            $db->commit();
            Audit::log('ap.invoice.reverse', 'acc_vouchers', $revId, 'معکوس کنترل‌شده AP');
            return ['existing' => false, 'reversal_voucher_id' => $revId, 'invoice_doc_id' => $invoiceDocId, 'status' => 'reversed'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Supplier outstanding from historically snapshotted AP control accounts. */
    public static function supplierOutstanding(int $wid, int $cid, int $partyId): array
    {
        self::assertCompany($wid, $cid);
        if ($partyId < 1) {
            throw new RuntimeException('تأمین‌کننده نامعتبر است.');
        }
        $pq = pdo()->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $pq->execute([$partyId, $wid, $cid]);
        if (!$pq->fetchColumn()) {
            throw new RuntimeException('تأمین‌کننده متعلق به شرکت نیست.');
        }
        $hasPostings = pdo()->prepare('SELECT COUNT(*) FROM erp_ap_postings WHERE workspace_id=? AND company_id=?');
        $hasPostings->execute([$wid, $cid]);
        $postingCount = (int)$hasPostings->fetchColumn();
        $map = self::companyMap($wid, $cid);
        if ($postingCount < 1 && !$map) {
            return [
                'party_id' => $partyId,
                'outstanding' => null,
                'status' => 'UNAVAILABLE',
                'basis' => 'ap_map_missing',
                'note' => 'account_map_missing',
                'payment' => self::PAYMENT,
            ];
        }
        $fallbackAp = $map ? (int)$map['payable_account_id'] : 0;
        $q = pdo()->prepare("SELECT COALESCE(SUM(l.credit-l.debit),0) bal FROM acc_voucher_lines l
            JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final')
              AND l.party_id=? AND l.account_id IN (
                SELECT DISTINCT x.aid FROM (
                  SELECT payable_account_id AS aid FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND payable_account_id IS NOT NULL
                  UNION ALL SELECT ? AS aid
                ) x WHERE x.aid>0
              )");
        $q->execute([$wid, $cid, $partyId, $wid, $cid, $fallbackAp]);
        $balRaw = (string)$q->fetchColumn();
        if (!preg_match('/^-?\d+(\.\d+)?$/', $balRaw)) {
            $balRaw = number_format((float)$balRaw, 2, '.', '');
        }
        $units = AccountingPostingDomain::signedScaled($balRaw, 2);
        $outstanding = AccountingPostingDomain::signedFixed($units, 2);
        $act = pdo()->prepare("SELECT COUNT(*) FROM acc_voucher_lines l
            JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final') AND l.party_id=?
              AND l.account_id IN (
                SELECT DISTINCT x.aid FROM (
                  SELECT payable_account_id AS aid FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND payable_account_id IS NOT NULL
                  UNION ALL SELECT ? AS aid
                ) x WHERE x.aid>0
              )");
        $act->execute([$wid, $cid, $partyId, $wid, $cid, $fallbackAp]);
        $hasActivity = (int)$act->fetchColumn() > 0;
        if ($units > 0) {
            $status = 'OPEN';
        } elseif ($units < 0) {
            $status = 'CREDIT_BALANCE';
        } elseif ($hasActivity) {
            $status = 'SETTLED';
        } else {
            $status = 'OPEN';
        }
        return [
            'party_id' => $partyId,
            'outstanding' => $outstanding,
            'status' => $status,
            'basis' => 'posted_ap_account_voucher_lines_historical',
            'currency' => self::CURRENCY,
            'payment' => self::PAYMENT,
        ];
    }

    /** Per-invoice open AP from posting net minus future allocations (none in 10.22). */
    public static function invoiceOutstanding(int $wid, int $cid, int $invoiceDocId): array
    {
        self::assertCompany($wid, $cid);
        $p = pdo()->prepare('SELECT net_total,reversal_voucher_id FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
        $p->execute([$wid, $cid, $invoiceDocId]);
        $row = $p->fetch();
        if (!$row) {
            return [
                'invoice_doc_id' => $invoiceDocId,
                'outstanding' => null,
                'status' => 'NOT_POSTED',
                'payment' => self::PAYMENT,
            ];
        }
        if (!empty($row['reversal_voucher_id'])) {
            return [
                'invoice_doc_id' => $invoiceDocId,
                'outstanding' => '0.00',
                'status' => 'REVERSED',
                'payment' => self::PAYMENT,
            ];
        }
        $net = PurchaseMatchingDomain::scaled((string)$row['net_total'], 2, 10000000000);
        $allocated = 0;
        try {
            $a = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='active'");
            $a->execute([$wid, $cid, $invoiceDocId]);
            $allocated = PurchaseMatchingDomain::scaled((string)$a->fetchColumn(), 2, 10000000000);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'erp_ap_allocations') === false) {
                throw $e;
            }
        }
        $open = $net - $allocated;
        return [
            'invoice_doc_id' => $invoiceDocId,
            'outstanding' => PurchaseMatchingDomain::fixed($open, 2),
            'posted_net' => PurchaseMatchingDomain::fixed($net, 2),
            'allocated' => PurchaseMatchingDomain::fixed($allocated, 2),
            'status' => $open > 0 ? 'OPEN' : ($open < 0 ? 'OVER_ALLOCATED' : 'SETTLED'),
            'payment' => self::PAYMENT,
        ];
    }
}
