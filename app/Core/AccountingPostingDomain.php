<?php
/**
 * ERPSMART 10.19.1 — AR posting integrity hardening.
 *
 * Canonical path: O2C billing invoice → balanced acc_vouchers / acc_voucher_lines.
 * Settlement remains NOT_IMPLEMENTED.
 */
final class AccountingPostingDomain
{
    public const VERSION = '10.23.0-ar';
    public const SETTLEMENT = 'AVAILABLE';
    public const CURRENCY = 'IRR';

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
            receivable_account_id BIGINT NULL,
            revenue_account_id BIGINT NULL,
            tax_account_id BIGINT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'IRR',
            posted_by INT NOT NULL,
            posted_at DATETIME NOT NULL,
            UNIQUE KEY uq_ar_invoice (workspace_id,company_id,invoice_doc_id),
            UNIQUE KEY uq_ar_idem (workspace_id,company_id,idempotency_key),
            UNIQUE KEY uq_ar_voucher (workspace_id,voucher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            "ALTER TABLE erp_ar_postings ADD COLUMN receivable_account_id BIGINT NULL AFTER net_total",
            "ALTER TABLE erp_ar_postings ADD COLUMN revenue_account_id BIGINT NULL AFTER receivable_account_id",
            "ALTER TABLE erp_ar_postings ADD COLUMN tax_account_id BIGINT NULL AFTER revenue_account_id",
            "ALTER TABLE erp_ar_postings ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'IRR' AFTER tax_account_id",
        ] as $sql) {
            try { $db->exec($sql); } catch (Throwable $e) { /* additive / already present */ }
        }
    }

    public static function signedScaled(string $raw, int $scale = 2): int
    {
        $s = trim($raw);
        $neg = false;
        if ($s !== '' && $s[0] === '-') {
            $neg = true;
            $s = substr($s, 1);
        }
        if ($s === '') {
            throw new RuntimeException('عدد نامعتبر است.');
        }
        $v = PurchaseMatchingDomain::scaled($s, $scale, 10000000000);
        return $neg ? -$v : $v;
    }

    public static function signedFixed(int $units, int $scale = 2): string
    {
        $sign = $units < 0 ? '-' : '';
        return $sign . PurchaseMatchingDomain::fixed(abs($units), $scale);
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

    /**
     * Validate postable leaf account with required account_type semantics.
     * @return array account row
     */
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
        $ar = (int)($map['receivable_account_id'] ?? 0);
        $rev = (int)($map['revenue_account_id'] ?? 0);
        $tax = (int)($map['tax_account_id'] ?? 0);
        self::assertAccountTyped($wid, $cid, $ar, 'حساب دریافتنی', ['asset']);
        self::assertAccountTyped($wid, $cid, $rev, 'حساب درآمد', ['income', 'revenue']);
        if ($tax > 0) {
            self::assertAccountTyped($wid, $cid, $tax, 'حساب مالیات', ['liability']);
        } else {
            $tax = null;
        }
        if ($ar === $rev || ($tax !== null && ($ar === $tax || $rev === $tax))) {
            throw new RuntimeException('حساب‌های دریافتنی، درآمد و مالیات باید متمایز باشند.');
        }
        pdo()->prepare('INSERT INTO erp_ar_company_maps (workspace_id,company_id,receivable_account_id,revenue_account_id,tax_account_id,updated_by,updated_at)
            VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE receivable_account_id=VALUES(receivable_account_id),revenue_account_id=VALUES(revenue_account_id),tax_account_id=VALUES(tax_account_id),updated_by=VALUES(updated_by),updated_at=NOW()')
            ->execute([$wid, $cid, $ar, $rev, $tax, $uid]);
        Audit::log('ar.map.set', 'erp_ar_company_maps', $cid, 'تنظیم نگاشت حساب‌های AR');
    }

    public static function companyMap(int $wid, int $cid): ?array
    {
        $q = pdo()->prepare('SELECT * FROM erp_ar_company_maps WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid, $cid]);
        $row = $q->fetch();
        return $row ?: null;
    }

    /**
     * Concurrency-safe fiscal + period-close check. Locks fiscal year row.
     * period_key convention: YYYY-MM (calendar month of voucher date).
     */
    public static function assertFiscalOpen(int $wid, int $cid, string $date, ?PDO $db = null): int
    {
        $db = $db ?: pdo();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('تاریخ سند نامعتبر است.');
        }
        $q = $db->prepare("SELECT id,status,start_date,end_date FROM acc_fiscal_years
            WHERE workspace_id=? AND company_id=? AND start_date<=? AND end_date>=?
            ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$wid, $cid, $date, $date]);
        $fy = $q->fetch();
        if (!$fy) {
            throw new RuntimeException('برای تاریخ سند، سال مالی وجود ندارد.');
        }
        if ((string)$fy['status'] !== 'open') {
            throw new RuntimeException('سال مالی بسته است.');
        }
        $periodKey = substr($date, 0, 7);
        // Ensure period-close table exists (extended schema); fail closed if closed/locked.
        try {
            $pq = $db->prepare("SELECT id,status FROM acc_period_closes
                WHERE workspace_id=? AND company_id=? AND fiscal_year_id=? AND period_key=?
                LIMIT 1 FOR UPDATE");
            $pq->execute([$wid, $cid, (int)$fy['id'], $periodKey]);
            $pc = $pq->fetch();
            if ($pc && in_array((string)$pc['status'], ['closed', 'locked'], true)) {
                throw new RuntimeException('دوره مالی ' . $periodKey . ' بسته/قفل است.');
            }
        } catch (PDOException $e) {
            // Table missing in minimal harness — treat as no period close (year check still applies).
            if (stripos($e->getMessage(), 'acc_period_closes') === false) {
                throw $e;
            }
        }
        return (int)$fy['id'];
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
        if ($map) {
            try {
                self::assertAccountTyped($wid, $cid, (int)$map['receivable_account_id'], 'حساب دریافتنی', ['asset']);
                self::assertAccountTyped($wid, $cid, (int)$map['revenue_account_id'], 'حساب درآمد', ['income', 'revenue']);
                if (!empty($map['tax_account_id'])) {
                    self::assertAccountTyped($wid, $cid, (int)$map['tax_account_id'], 'حساب مالیات', ['liability']);
                }
                $mapOk = true;
                $mapBlocker = null;
            } catch (Throwable $e) {
                $mapBlocker = 'account_map_invalid';
            }
        }
        $ready = $mapOk && $openFy > 0;
        return [
            'ready' => $ready,
            'has_account_map' => $map !== null,
            'account_map_valid' => $mapOk,
            'open_fiscal_years' => $openFy,
            'settlement' => self::SETTLEMENT,
            'blockers' => array_values(array_filter([
                $mapBlocker,
                $openFy < 1 ? 'fiscal_year_closed_or_missing' : null,
            ])),
        ];
    }

    /** Immutable fingerprint of invoice identity + lines + O2C links. */
    public static function invoiceRequestHash(int $wid, int $cid, array $inv, array $lines, array $links): string
    {
        $linePayload = [];
        foreach ($lines as $l) {
            $linePayload[] = [
                'id' => (int)$l['id'],
                'line_no' => (int)$l['line_no'],
                'item_id' => (int)$l['item_id'],
                'qty' => (string)$l['quantity'],
                'price' => (string)$l['unit_price'],
                'disc' => (string)$l['discount_amount'],
                'tax' => (string)$l['tax_amount'],
                'total' => (string)$l['line_total'],
            ];
        }
        usort($linePayload, static fn($a, $b) => $a['line_no'] <=> $b['line_no']);
        $linkPayload = [];
        foreach ($links as $lk) {
            $linkPayload[] = [
                'delivery_id' => (int)$lk['delivery_id'],
                'sales_line_id' => (int)$lk['sales_line_id'],
                'item_id' => (int)$lk['item_id'],
                'qty' => (string)$lk['quantity'],
                'line_total' => (string)$lk['line_total'],
                'source' => (int)$lk['source_sales_doc_id'],
            ];
        }
        usort($linkPayload, static fn($a, $b) => [$a['delivery_id'], $a['sales_line_id']] <=> [$b['delivery_id'], $b['sales_line_id']]);
        return hash('sha256', json_encode([
            'workspace_id' => $wid,
            'company_id' => $cid,
            'invoice_doc_id' => (int)$inv['id'],
            'party_id' => (int)$inv['party_id'],
            'document_no' => (string)$inv['document_no'],
            'document_date' => (string)$inv['document_date'],
            'currency' => self::CURRENCY,
            'fx_rate' => '1',
            'gross' => (string)$inv['total_before_discount'],
            'disc' => (string)$inv['discount_total'],
            'tax' => (string)$inv['tax_total'],
            'net' => (string)$inv['net_total'],
            'lines' => $linePayload,
            'o2c_links' => $linkPayload,
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

    /**
     * Post O2C billing invoice to AR/GL as a balanced final voucher.
     */
    public static function postCustomerInvoice(int $wid, int $cid, int $uid, int $invoiceDocId, string $idempotencyKey = ''): array
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
            $idem = hash('sha256', 'ar-inv-' . $wid . '-' . $cid . '-' . $invoiceDocId);
        }

        $db = pdo();
        $db->beginTransaction();
        $reqHash = '';
        try {
            $q = $db->prepare('SELECT * FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$invoiceDocId, $wid, $cid]);
            $inv = $q->fetch();
            if (!$inv) {
                throw new RuntimeException('فاکتور پیدا نشد.');
            }
            if ((string)$inv['doc_type'] !== 'invoice') {
                throw new RuntimeException('فقط فاکتور فروش قابل ثبت AR است.');
            }
            if (!OrderToCashDomain::isBillingDocument($wid, $cid, $invoiceDocId)) {
                throw new RuntimeException('فقط فاکتور صورتحساب O2C برای ثبت AR مجاز است.');
            }
            if (in_array((string)$inv['workflow_status'], ['void', 'cancelled'], true)) {
                throw new RuntimeException('فاکتور باطل قابل ثبت نیست.');
            }
            if ((int)$inv['created_by'] > 0 && (int)$inv['created_by'] === $uid) {
                throw new RuntimeException('ایجادکننده فاکتور نمی‌تواند آن را به دفتر ثبت کند (Maker/Checker).');
            }

            $lq = $db->prepare('SELECT * FROM acc_sales_lines WHERE workspace_id=? AND sales_doc_id=? ORDER BY line_no FOR UPDATE');
            $lq->execute([$wid, $invoiceDocId]);
            $lines = $lq->fetchAll();
            if (!$lines) {
                throw new RuntimeException('فاکتور بدون ردیف قابل ثبت نیست.');
            }
            $lk = $db->prepare("SELECT * FROM erp_o2c_invoice_links WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND COALESCE(status,'active')='active' ORDER BY id FOR UPDATE");
            $lk->execute([$wid, $cid, $invoiceDocId]);
            $links = $lk->fetchAll();
            if (!$links) {
                throw new RuntimeException('فاکتور بدون پیوند O2C فعال قابل ثبت AR نیست.');
            }

            // Header ↔ lines reconciliation
            $sumGross = 0;
            $sumDisc = 0;
            $sumTax = 0;
            $sumNet = 0;
            foreach ($lines as $l) {
                $qty = OrderToCashDomain::scaledQty((string)$l['quantity']);
                $price = PurchaseMatchingDomain::scaled((string)$l['unit_price'], 2, 10000000000);
                $disc = PurchaseMatchingDomain::scaled((string)$l['discount_amount'], 2, 10000000000);
                $taxAmt = PurchaseMatchingDomain::scaled((string)$l['tax_amount'], 2, 10000000000);
                $lineTotal = PurchaseMatchingDomain::scaled((string)$l['line_total'], 2, 10000000000);
                $base = PurchaseMatchingDomain::cents($qty, $price);
                $expected = max(0, $base - $disc + $taxAmt);
                if ($expected !== $lineTotal) {
                    throw new RuntimeException('جمع ردیف با اجزای قیمت/تخفیف/مالیات سازگار نیست.');
                }
                $sumGross += $base;
                $sumDisc += $disc;
                $sumTax += $taxAmt;
                $sumNet += $lineTotal;
            }
            $hdrGross = PurchaseMatchingDomain::scaled((string)$inv['total_before_discount'], 2, 10000000000);
            $hdrDisc = PurchaseMatchingDomain::scaled((string)$inv['discount_total'], 2, 10000000000);
            $hdrTax = PurchaseMatchingDomain::scaled((string)$inv['tax_total'], 2, 10000000000);
            $hdrNet = PurchaseMatchingDomain::scaled((string)$inv['net_total'], 2, 10000000000);
            if ($hdrGross !== $sumGross || $hdrDisc !== $sumDisc || $hdrTax !== $sumTax || $hdrNet !== $sumNet) {
                throw new RuntimeException('جمع سربرگ فاکتور با ردیف‌ها بالانس نیست.');
            }

            $reqHash = self::invoiceRequestHash($wid, $cid, $inv, $lines, $links);

            $q = $db->prepare('SELECT voucher_id,idempotency_key,request_hash FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$wid, $cid, $invoiceDocId]);
            $existing = $q->fetch();
            if ($existing) {
                if ((string)$existing['request_hash'] !== $reqHash) {
                    throw new RuntimeException('فاکتور پس از ثبت تغییر کرده است؛ ثبت مجدد مجاز نیست. اصلاح فقط با سند معکوس کنترل‌شده.');
                }
                $db->commit();
                return ['existing' => true, 'voucher_id' => (int)$existing['voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'posted'];
            }
            $q = $db->prepare('SELECT voucher_id,request_hash,invoice_doc_id FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND idempotency_key=? LIMIT 1');
            $q->execute([$wid, $cid, $idem]);
            $byKey = $q->fetch();
            if ($byKey) {
                if ((int)$byKey['invoice_doc_id'] !== $invoiceDocId || (string)$byKey['request_hash'] !== $reqHash) {
                    throw new RuntimeException('کلید idempotency با فاکتور/محتوای متفاوت استفاده شده است.');
                }
                $db->commit();
                return ['existing' => true, 'voucher_id' => (int)$byKey['voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'posted'];
            }

            if ((string)$inv['workflow_status'] === 'final' && strpos((string)$inv['notes'], '[AR_POSTED') !== false) {
                throw new RuntimeException('فاکتور قبلاً ثبت AR شده اما پیوند posting یافت نشد.');
            }

            $map = self::companyMap($wid, $cid);
            if (!$map) {
                throw new RuntimeException('نگاشت حساب‌های AR برای شرکت تنظیم نشده است.');
            }
            $arAcc = self::assertAccountTyped($wid, $cid, (int)$map['receivable_account_id'], 'حساب دریافتنی', ['asset']);
            $revAcc = self::assertAccountTyped($wid, $cid, (int)$map['revenue_account_id'], 'حساب درآمد', ['income', 'revenue']);
            $taxAccId = null;
            if ($hdrTax > 0) {
                $taxAccId = (int)($map['tax_account_id'] ?? 0);
                if ($taxAccId < 1) {
                    throw new RuntimeException('حساب مالیات برای فاکتور دارای مالیات تنظیم نشده است.');
                }
                self::assertAccountTyped($wid, $cid, $taxAccId, 'حساب مالیات', ['liability']);
            }

            $date = (string)$inv['document_date'];
            $fyId = self::assertFiscalOpen($wid, $cid, $date, $db);

            $gross = $hdrGross;
            $disc = $hdrDisc;
            $tax = $hdrTax;
            $net = $hdrNet;
            $revenue = max(0, $gross - $disc);
            if ($net !== $revenue + $tax) {
                throw new RuntimeException('جمع خالص فاکتور با اجزای درآمد/مالیات بالانس نیست.');
            }
            if ($net <= 0) {
                throw new RuntimeException('فاکتور با مبلغ صفر قابل ثبت AR نیست.');
            }

            $partyId = (int)$inv['party_id'];
            $q = $db->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
            $q->execute([$partyId, $wid, $cid]);
            if (!$q->fetchColumn()) {
                throw new RuntimeException('مشتری فاکتور متعلق به شرکت نیست.');
            }

            $jl = [];
            $jl[] = ['account_id' => (int)$arAcc['id'], 'party_id' => $partyId, 'debit' => $net, 'credit' => 0, 'desc' => 'دریافتنی مشتری ' . $inv['document_no']];
            $jl[] = ['account_id' => (int)$revAcc['id'], 'party_id' => $partyId, 'debit' => 0, 'credit' => $revenue, 'desc' => 'درآمد فروش ' . $inv['document_no']];
            if ($tax > 0) {
                $jl[] = ['account_id' => $taxAccId, 'party_id' => null, 'debit' => 0, 'credit' => $tax, 'desc' => 'مالیات فروش ' . $inv['document_no']];
            }
            $debit = 0;
            $credit = 0;
            foreach ($jl as $l) {
                $debit += $l['debit'];
                $credit += $l['credit'];
            }
            if ($debit !== $credit) {
                throw new RuntimeException('آرتیکل‌های AR بالانس نیستند.');
            }

            $no = 'AR-' . $invoiceDocId . '-' . strtoupper(bin2hex(random_bytes(2)));
            $db->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,fiscal_year_id,voucher_no,voucher_date,voucher_type,status,description,source_type,source_id,auto_generated,total_debit,total_credit,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,'general','final',?,'o2c_ar',?,1,?,?,?,NOW(),NOW())")
                ->execute([
                    $wid, $cid, $fyId, $no, $date, 'ثبت خودکار AR از فاکتور ' . $inv['document_no'],
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
            $db->prepare("UPDATE acc_sales_docs SET workflow_status='final',notes=CONCAT(COALESCE(notes,''),' [AR_POSTED voucher=',?,']'),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND workflow_status IN ('draft','approved')")
                ->execute([(string)$voucherId, $invoiceDocId, $wid, $cid]);
            $db->prepare('INSERT INTO erp_ar_postings (workspace_id,company_id,invoice_doc_id,voucher_id,idempotency_key,request_hash,net_total,receivable_account_id,revenue_account_id,tax_account_id,currency,posted_by,posted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([
                    $wid, $cid, $invoiceDocId, $voucherId, $idem, $reqHash, PurchaseMatchingDomain::fixed($net, 2),
                    (int)$arAcc['id'], (int)$revAcc['id'], $taxAccId, self::CURRENCY, $uid,
                ]);
            $db->commit();
            Audit::log('ar.invoice.post', 'acc_vouchers', $voucherId, 'ثبت AR فاکتور ' . $inv['document_no']);
            return [
                'existing' => false, 'voucher_id' => $voucherId, 'voucher_no' => $no, 'invoice_doc_id' => $invoiceDocId,
                'status' => 'posted', 'net_total' => PurchaseMatchingDomain::fixed($net, 2), 'settlement' => self::SETTLEMENT,
                'request_hash' => $reqHash,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Only recover duplicate-key races when immutable request evidence matches.
            if (self::isDuplicateKey($e) && $reqHash !== '') {
                $q = pdo()->prepare('SELECT voucher_id,request_hash FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
                $q->execute([$wid, $cid, $invoiceDocId]);
                $row = $q->fetch();
                if ($row && (string)$row['request_hash'] === $reqHash) {
                    return ['existing' => true, 'voucher_id' => (int)$row['voucher_id'], 'invoice_doc_id' => $invoiceDocId, 'status' => 'posted', 'race_recovered' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Outstanding from posted o2c_ar voucher lines on historically snapshotted AR accounts.
     * Never claims zero when mapping is missing and no postings exist — status UNAVAILABLE.
     */
    public static function customerOutstanding(int $wid, int $cid, int $partyId): array
    {
        self::assertCompany($wid, $cid);
        if ($partyId < 1) {
            throw new RuntimeException('مشتری نامعتبر است.');
        }
        $pq = pdo()->prepare('SELECT 1 FROM acc_parties WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $pq->execute([$partyId, $wid, $cid]);
        if (!$pq->fetchColumn()) {
            throw new RuntimeException('مشتری متعلق به شرکت نیست.');
        }

        $hasPostings = pdo()->prepare('SELECT COUNT(*) FROM erp_ar_postings WHERE workspace_id=? AND company_id=?');
        $hasPostings->execute([$wid, $cid]);
        $postingCount = (int)$hasPostings->fetchColumn();
        $map = self::companyMap($wid, $cid);

        if ($postingCount < 1 && !$map) {
            return [
                'party_id' => $partyId,
                'outstanding' => null,
                'status' => 'UNAVAILABLE',
                'basis' => 'ar_map_missing',
                'note' => 'account_map_missing',
            ];
        }

        // All posted lines on AR control account(s): invoices (Dr) + receipts/reversals (Cr/Dr).
        $fallbackAr = $map ? (int)$map['receivable_account_id'] : 0;
        $q = pdo()->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) bal FROM acc_voucher_lines l
            JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final')
              AND l.party_id=? AND l.account_id IN (
                SELECT DISTINCT x.aid FROM (
                  SELECT receivable_account_id AS aid FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND receivable_account_id IS NOT NULL
                  UNION ALL SELECT ? AS aid
                ) x WHERE x.aid>0
              )");
        $q->execute([$wid, $cid, $partyId, $wid, $cid, $fallbackAr]);
        $balRaw = (string)$q->fetchColumn();
        // Normalize MySQL decimal to signed string without scientific notation.
        if (!preg_match('/^-?\d+(\.\d+)?$/', $balRaw)) {
            $balRaw = number_format((float)$balRaw, 2, '.', '');
        }
        $units = self::signedScaled($balRaw, 2);
        $outstanding = self::signedFixed($units, 2);
        $act = pdo()->prepare("SELECT COUNT(*) FROM acc_voucher_lines l
            JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final') AND l.party_id=?
              AND l.account_id IN (
                SELECT DISTINCT x.aid FROM (
                  SELECT receivable_account_id AS aid FROM erp_ar_postings WHERE workspace_id=? AND company_id=? AND receivable_account_id IS NOT NULL
                  UNION ALL SELECT ? AS aid
                ) x WHERE x.aid>0
              )");
        $act->execute([$wid, $cid, $partyId, $wid, $cid, $fallbackAr]);
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
            'basis' => 'posted_ar_account_voucher_lines_historical',
            'currency' => self::CURRENCY,
        ];
    }
}
