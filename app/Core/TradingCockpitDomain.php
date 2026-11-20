<?php
/**
 * ERPSMART RC1 — Domestic trading cockpit / query domain (T3).
 *
 * Read-only assembly of PO → GR → supplier bill → AP voucher → payment → allocation.
 * Reuses P2P + ApPosting + ApSettlement records; does NOT post stock/GL or invent FX/VAT.
 * Money labels are always IRR (ledger). Toman display belongs to T4 helpers.
 */
final class TradingCockpitDomain
{
    public const VERSION = '10.24.0-rc1-trading-cockpit';
    public const LEDGER_CURRENCY = 'IRR';

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت/محیط کاری برای کوک‌پیت بازرگانی انتخاب نشده است.');
        }
        $q = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $q->execute([$wid, $cid]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست؛ کوک‌پیت بازرگانی نمایش داده نمی‌شود.');
        }
    }

    /** Map common domain exception codes / English stubs to operator-facing FA messages. */
    public static function explainException(Throwable $e): string
    {
        $msg = trim($e->getMessage());
        $map = [
            'company_not_found' => 'شرکت فعال یافت نشد یا غیرفعال است.',
            'trade_entity_invalid' => 'شناسه سند بازرگانی نامعتبر است.',
            'trade_entity_not_owned' => 'سند بازرگانی متعلق به شرکت فعال نیست.',
            'permission_denied' => 'مجوز انجام این عملیات را ندارید.',
            'pdo_not_connected' => 'اتصال پایگاه‌داده برقرار نیست.',
        ];
        if (isset($map[$msg])) {
            return $map[$msg];
        }
        if (str_starts_with($msg, 'permission_denied:')) {
            return 'مجوز لازم برای این عملیات وجود ندارد ('.$msg.').';
        }
        if ($msg === '') {
            return 'خطای ناشناخته در گردش بازرگانی رخ داد.';
        }
        return $msg;
    }

    /**
     * Search domestic purchase orders and related supplier docs by number / party / invoice.
     * Source-backed; does not invent totals.
     */
    public static function searchDomestic(int $wid, int $cid, string $query = '', int $limit = 50): array
    {
        self::assertCompany($wid, $cid);
        $limit = max(1, min(100, $limit));
        $query = trim($query);
        $params = [$wid, $cid];
        $where = "d.workspace_id=? AND d.company_id=? AND d.doc_type='purchase_order_goods'";
        if ($query !== '') {
            $like = '%'.$query.'%';
            $where .= ' AND (d.document_no LIKE ? OR d.party_invoice_no LIKE ? OR p.name LIKE ? OR p.code LIKE ?
                OR EXISTS (
                    SELECT 1 FROM erp_p2p_vendor_bills b
                    WHERE b.workspace_id=d.workspace_id AND b.company_id=d.company_id
                      AND b.purchase_order_id=d.id AND b.supplier_invoice_no LIKE ?
                ))';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT d.id AS purchase_order_id, d.document_no, d.document_date, d.workflow_status,
                d.party_id, d.net_total AS po_net_total_irr, p.name AS supplier_name, p.code AS supplier_code,
                COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl
                    JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id
                    WHERE r.workspace_id=d.workspace_id AND r.company_id=d.company_id
                      AND r.purchase_doc_id=d.id AND r.status='posted'),0) AS accepted_qty,
                COALESCE((SELECT COUNT(*) FROM erp_p2p_vendor_bills b
                    WHERE b.workspace_id=d.workspace_id AND b.company_id=d.company_id
                      AND b.purchase_order_id=d.id),0) AS bill_count
            FROM acc_purchase_docs d
            LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id AND p.company_id=d.company_id
            WHERE $where
            ORDER BY d.id DESC
            LIMIT $limit";
        $st = pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        foreach ($rows as &$row) {
            $row['currency'] = self::LEDGER_CURRENCY;
            $row['po_net_total_irr'] = self::moneyLabel((string)$row['po_net_total_irr']);
            $row['accepted_qty'] = self::qtyLabel((string)$row['accepted_qty']);
        }
        unset($row);
        return ['currency' => self::LEDGER_CURRENCY, 'query' => $query, 'rows' => $rows];
    }

    /**
     * Duplicate supplier invoice probe (read-only). Controls live in P2P unique key + submit path;
     * this surfaces them for cockpit UX without a parallel ledger.
     */
    public static function findSupplierInvoiceDuplicates(int $wid, int $cid, int $supplierId, string $invoiceNo): array
    {
        self::assertCompany($wid, $cid);
        $invoiceNo = trim($invoiceNo);
        if ($supplierId < 1 || $invoiceNo === '') {
            throw new RuntimeException('برای کنترل تکرار فاکتور، تأمین‌کننده و شماره فاکتور الزامی است.');
        }
        $bills = [];
        try {
            $q = pdo()->prepare('SELECT id,purchase_order_id,supplier_invoice_no,status,total_amount,canonical_doc_id,revision,created_at
                FROM erp_p2p_vendor_bills
                WHERE workspace_id=? AND company_id=? AND supplier_id=? AND supplier_invoice_no=?
                ORDER BY id');
            $q->execute([$wid, $cid, $supplierId, $invoiceNo]);
            $bills = $q->fetchAll() ?: [];
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'erp_p2p_vendor_bills') === false) {
                throw $e;
            }
        }
        $docs = [];
        $q = pdo()->prepare("SELECT id,document_no,party_invoice_no,doc_type,workflow_status,net_total,created_at
            FROM acc_purchase_docs
            WHERE workspace_id=? AND company_id=? AND party_id=?
              AND doc_type IN ('purchase_invoice_goods','purchase_invoice_service')
              AND party_invoice_no=? AND workflow_status<>'void'
            ORDER BY id");
        $q->execute([$wid, $cid, $supplierId, $invoiceNo]);
        $docs = $q->fetchAll() ?: [];
        return [
            'currency' => self::LEDGER_CURRENCY,
            'supplier_id' => $supplierId,
            'supplier_invoice_no' => $invoiceNo,
            'duplicate' => $bills !== [] || $docs !== [],
            'p2p_bills' => $bills,
            'purchase_docs' => $docs,
            'control' => 'P2P UNIQUE(workspace,company,supplier,supplier_invoice_no) + submit idempotency; no parallel ledger',
        ];
    }

    /** Full domestic trading timeline for one PO. */
    public static function poLifecycle(int $wid, int $cid, int $poId): array
    {
        self::assertCompany($wid, $cid);
        if ($poId < 1) {
            throw new RuntimeException('شناسه سفارش خرید برای کوک‌پیت نامعتبر است.');
        }
        $q = pdo()->prepare("SELECT d.*, p.name AS supplier_name, p.code AS supplier_code
            FROM acc_purchase_docs d
            LEFT JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id AND p.company_id=d.company_id
            WHERE d.id=? AND d.workspace_id=? AND d.company_id=? AND d.doc_type='purchase_order_goods' LIMIT 1");
        $q->execute([$poId, $wid, $cid]);
        $po = $q->fetch();
        if (!$po) {
            throw new RuntimeException('سفارش خرید در محدوده شرکت/محیط کاری یافت نشد.');
        }

        $lines = self::poLines($wid, $cid, $poId);
        $receipts = self::receiptsForPo($wid, $cid, $poId);
        $bills = self::billsForPo($wid, $cid, $poId);
        $invoices = self::invoicesForPo($wid, $cid, $poId, $bills);
        $payments = self::paymentsForInvoices($wid, $cid, $invoices);

        $orderedQty = '0.0000';
        $acceptedQty = '0.0000';
        $billedQty = '0.0000';
        foreach ($lines as $ln) {
            $orderedQty = self::addQty($orderedQty, (string)$ln['ordered_qty']);
            $acceptedQty = self::addQty($acceptedQty, (string)$ln['accepted_qty']);
            $billedQty = self::addQty($billedQty, (string)$ln['billed_qty']);
        }

        $apPostedTotal = '0.00';
        $apOutstandingTotal = '0.00';
        $apAllocatedTotal = '0.00';
        foreach ($invoices as $inv) {
            if (($inv['ap_status'] ?? '') === 'NOT_POSTED' || ($inv['ap_status'] ?? '') === 'REVERSED') {
                continue;
            }
            $apPostedTotal = self::addMoney($apPostedTotal, (string)($inv['posted_net_irr'] ?? '0.00'));
            $apOutstandingTotal = self::addMoney($apOutstandingTotal, (string)($inv['outstanding_irr'] ?? '0.00'));
            $apAllocatedTotal = self::addMoney($apAllocatedTotal, (string)($inv['allocated_irr'] ?? '0.00'));
        }

        $stockMoves = 0;
        $receiptGl = 0;
        try {
            $st = pdo()->prepare("SELECT COUNT(*) FROM acc_stock_movements
                WHERE workspace_id=? AND company_id=? AND source_type='inventory_receipt'
                  AND source_id IN (SELECT id FROM acc_inventory_receipts WHERE workspace_id=? AND company_id=? AND purchase_doc_id=?)");
            $st->execute([$wid, $cid, $wid, $cid, $poId]);
            $stockMoves = (int)$st->fetchColumn();
            $st = pdo()->prepare("SELECT COUNT(*) FROM acc_vouchers
                WHERE workspace_id=? AND company_id=? AND source_type LIKE '%receipt%'
                  AND source_id IN (SELECT id FROM acc_inventory_receipts WHERE workspace_id=? AND company_id=? AND purchase_doc_id=?)");
            $st->execute([$wid, $cid, $wid, $cid, $poId]);
            $receiptGl = (int)$st->fetchColumn();
        } catch (PDOException $e) {
            // tables may be absent in partial harnesses
        }

        $timeline = [];
        $timeline[] = [
            'step' => 'purchase_order',
            'source_type' => 'purchase_order_goods',
            'source_id' => (int)$po['id'],
            'status' => (string)$po['workflow_status'],
            'qty' => $orderedQty,
            'amount_irr' => self::moneyLabel((string)$po['net_total']),
            'label' => 'سفارش خرید',
            'at' => (string)($po['created_at'] ?? $po['document_date']),
        ];
        foreach ($receipts as $r) {
            $timeline[] = [
                'step' => 'goods_receipt',
                'source_type' => 'inventory_receipt',
                'source_id' => (int)$r['id'],
                'status' => (string)$r['status'],
                'qty' => self::qtyLabel((string)$r['accepted_qty']),
                'amount_irr' => null,
                'label' => 'رسید انبار (فقط عملیاتی؛ بدون GL)',
                'at' => (string)($r['posted_at'] ?? $r['receipt_date']),
                'note' => 'NO_GRNI',
            ];
        }
        foreach ($bills as $b) {
            $timeline[] = [
                'step' => 'supplier_bill',
                'source_type' => 'erp_p2p_vendor_bills',
                'source_id' => (int)$b['id'],
                'status' => (string)$b['status'],
                'qty' => null,
                'amount_irr' => self::moneyLabel((string)$b['total_amount']),
                'label' => 'درخواست فاکتور تأمین‌کننده',
                'supplier_invoice_no' => (string)$b['supplier_invoice_no'],
                'canonical_doc_id' => $b['canonical_doc_id'] !== null ? (int)$b['canonical_doc_id'] : null,
                'at' => (string)$b['created_at'],
            ];
        }
        foreach ($invoices as $inv) {
            $timeline[] = [
                'step' => 'ap_voucher',
                'source_type' => 'p2p_ap',
                'source_id' => (int)$inv['invoice_doc_id'],
                'voucher_id' => $inv['voucher_id'],
                'status' => (string)$inv['ap_status'],
                'qty' => self::qtyLabel((string)$inv['invoice_qty']),
                'amount_irr' => $inv['posted_net_irr'],
                'outstanding_irr' => $inv['outstanding_irr'],
                'label' => 'سند حسابداری بدهی تأمین‌کننده',
                'at' => (string)($inv['posted_at'] ?? $inv['document_date']),
            ];
        }
        foreach ($payments as $pay) {
            $timeline[] = [
                'step' => 'payment',
                'source_type' => 'erp_ap_payments',
                'source_id' => (int)$pay['id'],
                'status' => (string)$pay['status'],
                'qty' => null,
                'amount_irr' => self::moneyLabel((string)$pay['amount']),
                'label' => 'پرداخت تأمین‌کننده',
                'voucher_id' => $pay['voucher_id'] !== null ? (int)$pay['voucher_id'] : null,
                'at' => (string)($pay['updated_at'] ?? $pay['payment_date']),
                'allocations' => $pay['allocations'],
            ];
        }

        return [
            'currency' => self::LEDGER_CURRENCY,
            'purchase_order' => [
                'id' => (int)$po['id'],
                'document_no' => (string)$po['document_no'],
                'document_date' => (string)$po['document_date'],
                'status' => (string)$po['workflow_status'],
                'party_id' => (int)$po['party_id'],
                'supplier_name' => (string)($po['supplier_name'] ?? ''),
                'supplier_code' => (string)($po['supplier_code'] ?? ''),
                'net_total_irr' => self::moneyLabel((string)$po['net_total']),
            ],
            'quantities' => [
                'ordered_qty' => self::qtyLabel($orderedQty),
                'accepted_qty' => self::qtyLabel($acceptedQty),
                'billed_qty' => self::qtyLabel($billedQty),
                'note' => 'Qty is operational; GL valuation is invoice-based AP only',
            ],
            'lines' => $lines,
            'receipts' => $receipts,
            'supplier_bills' => $bills,
            'ap_invoices' => $invoices,
            'payments' => $payments,
            'totals' => [
                'ap_posted_irr' => self::moneyLabel($apPostedTotal),
                'ap_allocated_irr' => self::moneyLabel($apAllocatedTotal),
                'ap_outstanding_irr' => self::moneyLabel($apOutstandingTotal),
                'stock_movements' => $stockMoves,
                'receipt_gl_vouchers' => $receiptGl,
            ],
            'timeline' => $timeline,
            'nonclaims' => [
                'no_grni' => true,
                'no_fx_gl' => true,
                'no_import_vat_gl' => true,
                'no_customs_posting' => true,
                'toman_display_only_via_t4' => true,
            ],
        ];
    }

    /** Foreign-trade case assessment: read-only status; does not enable FX/customs GL. */
    public static function foreignTradeAssessment(int $wid, int $cid, ?int $caseId = null): array
    {
        self::assertCompany($wid, $cid);
        $case = null;
        $landed = null;
        if ($caseId !== null && $caseId > 0 && class_exists('TradeDomain')) {
            try {
                $case = TradeDomain::caseRow($wid, $cid, $caseId);
                if ($case) {
                    $landed = TradeDomain::landedCostSummary($wid, $cid, $caseId);
                }
            } catch (Throwable $e) {
                $case = null;
            }
        }
        return [
            'status' => 'DRAFT_READ_ONLY_FUTURE',
            'rc1_required' => false,
            'currency_ledger' => self::LEDGER_CURRENCY,
            'enabled' => [
                'trade_case_crud' => true,
                'shipment_milestones' => true,
                'estimated_actual_cost_records' => true,
                'landed_cost_report_bridge' => true,
            ],
            'blocked_until_verified' => [
                'fx_gl_posting' => 'No verified FX settlement kernel; do not post FX gains/losses from TradeDomain.',
                'customs_declaration_gl' => 'Customs fields are operational metadata only.',
                'import_vat_gl' => 'Import VAT GL not enabled without legal/policy evidence (T4/T5).',
                'stock_unit_cost_rewrite' => 'Trade costs must never rewrite historical stock movement unit_cost.',
            ],
            'case' => $case,
            'landed_cost_snapshot' => $landed,
            'note' => 'Domestic TR-1 (PO/GR/match/AP/pay) is the RC1 path. Foreign trade remains assessment/DRAFT.',
        ];
    }

    private static function poLines(int $wid, int $cid, int $poId): array
    {
        $q = pdo()->prepare("SELECT l.id AS purchase_line_id, l.item_id, l.description, l.quantity AS ordered_qty, l.unit_price, l.line_total,
                COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl
                    JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id
                    WHERE rl.workspace_id=l.workspace_id AND rl.purchase_line_id=l.id
                      AND r.company_id=? AND r.purchase_doc_id=? AND r.status='posted'),0) AS accepted_qty,
                COALESCE((SELECT SUM(bl.quantity) FROM erp_p2p_vendor_bill_lines bl
                    JOIN erp_p2p_vendor_bills b ON b.id=bl.bill_id AND b.workspace_id=bl.workspace_id
                    WHERE bl.workspace_id=l.workspace_id AND bl.purchase_line_id=l.id
                      AND b.company_id=? AND b.purchase_order_id=? AND b.status='matched'),0) AS billed_qty
            FROM acc_purchase_lines l
            WHERE l.workspace_id=? AND l.purchase_doc_id=?
            ORDER BY l.line_no, l.id");
        $q->execute([$cid, $poId, $cid, $poId, $wid, $poId]);
        $rows = $q->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['ordered_qty'] = self::qtyLabel((string)$r['ordered_qty']);
            $r['accepted_qty'] = self::qtyLabel((string)$r['accepted_qty']);
            $r['billed_qty'] = self::qtyLabel((string)$r['billed_qty']);
            $r['unit_price_irr'] = self::moneyLabel((string)$r['unit_price']);
            $r['line_total_irr'] = self::moneyLabel((string)$r['line_total']);
            $r['currency'] = self::LEDGER_CURRENCY;
        }
        unset($r);
        return $rows;
    }

    private static function receiptsForPo(int $wid, int $cid, int $poId): array
    {
        $q = pdo()->prepare("SELECT r.id, r.receipt_no, r.receipt_date, r.status, r.posted_at, r.warehouse_id,
                COALESCE((SELECT SUM(rl.accepted_qty) FROM acc_inventory_receipt_lines rl WHERE rl.workspace_id=r.workspace_id AND rl.receipt_id=r.id),0) AS accepted_qty,
                COALESCE((SELECT SUM(rl.rejected_qty) FROM acc_inventory_receipt_lines rl WHERE rl.workspace_id=r.workspace_id AND rl.receipt_id=r.id),0) AS rejected_qty
            FROM acc_inventory_receipts r
            WHERE r.workspace_id=? AND r.company_id=? AND r.purchase_doc_id=?
            ORDER BY r.id");
        $q->execute([$wid, $cid, $poId]);
        $rows = $q->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['accepted_qty'] = self::qtyLabel((string)$r['accepted_qty']);
            $r['rejected_qty'] = self::qtyLabel((string)$r['rejected_qty']);
            $r['gl_on_receipt'] = false;
        }
        unset($r);
        return $rows;
    }

    private static function billsForPo(int $wid, int $cid, int $poId): array
    {
        try {
            $q = pdo()->prepare('SELECT id,supplier_invoice_no,invoice_date,status,revision,total_amount,canonical_doc_id,submitted_by,reviewed_by,created_at
                FROM erp_p2p_vendor_bills
                WHERE workspace_id=? AND company_id=? AND purchase_order_id=?
                ORDER BY id');
            $q->execute([$wid, $cid, $poId]);
            $rows = $q->fetchAll() ?: [];
        } catch (PDOException $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['total_amount_irr'] = self::moneyLabel((string)$r['total_amount']);
            $r['currency'] = self::LEDGER_CURRENCY;
        }
        unset($r);
        return $rows;
    }

    private static function invoicesForPo(int $wid, int $cid, int $poId, array $bills): array
    {
        $ids = [];
        foreach ($bills as $b) {
            if (!empty($b['canonical_doc_id'])) {
                $ids[] = (int)$b['canonical_doc_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($ids as $invId) {
            $q = pdo()->prepare("SELECT id,document_no,party_invoice_no,document_date,workflow_status,net_total,party_id
                FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");
            $q->execute([$invId, $wid, $cid]);
            $doc = $q->fetch();
            if (!$doc) {
                continue;
            }
            $lq = pdo()->prepare('SELECT COALESCE(SUM(quantity),0) FROM acc_purchase_lines WHERE workspace_id=? AND purchase_doc_id=?');
            $lq->execute([$wid, $invId]);
            $invoiceQty = self::qtyLabel((string)$lq->fetchColumn());

            $voucherId = null;
            $postedAt = null;
            $postedNet = null;
            $apStatus = 'NOT_POSTED';
            $outstanding = null;
            $allocated = '0.00';
            try {
                $pq = pdo()->prepare('SELECT voucher_id,net_total,posted_at,reversal_voucher_id FROM erp_ap_postings WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? LIMIT 1');
                $pq->execute([$wid, $cid, $invId]);
                $post = $pq->fetch();
                if ($post) {
                    $voucherId = (int)$post['voucher_id'];
                    $postedAt = (string)$post['posted_at'];
                    $postedNet = self::moneyLabel((string)$post['net_total']);
                    if (!empty($post['reversal_voucher_id'])) {
                        $apStatus = 'REVERSED';
                        $outstanding = '0.00';
                    } else {
                        if (class_exists('ApPostingDomain')) {
                            $io = ApPostingDomain::invoiceOutstanding($wid, $cid, $invId);
                            $apStatus = (string)($io['status'] ?? 'OPEN');
                            $outstanding = $io['outstanding'] !== null ? self::moneyLabel((string)$io['outstanding']) : null;
                            $allocated = self::moneyLabel((string)($io['allocated'] ?? '0.00'));
                        }
                    }
                }
            } catch (PDOException $e) {
                // postings table may be absent
            }
            $out[] = [
                'invoice_doc_id' => $invId,
                'document_no' => (string)$doc['document_no'],
                'party_invoice_no' => (string)($doc['party_invoice_no'] ?? ''),
                'document_date' => (string)$doc['document_date'],
                'workflow_status' => (string)$doc['workflow_status'],
                'party_id' => (int)$doc['party_id'],
                'invoice_qty' => $invoiceQty,
                'document_net_irr' => self::moneyLabel((string)$doc['net_total']),
                'voucher_id' => $voucherId,
                'posted_at' => $postedAt,
                'posted_net_irr' => $postedNet,
                'allocated_irr' => $allocated,
                'outstanding_irr' => $outstanding,
                'ap_status' => $apStatus,
                'currency' => self::LEDGER_CURRENCY,
            ];
        }
        return $out;
    }

    private static function paymentsForInvoices(int $wid, int $cid, array $invoices): array
    {
        $invIds = array_map(static fn($i) => (int)$i['invoice_doc_id'], $invoices);
        $invIds = array_values(array_unique(array_filter($invIds)));
        if (!$invIds) {
            return [];
        }
        try {
            $in = implode(',', array_fill(0, count($invIds), '?'));
            $params = array_merge([$wid, $cid], $invIds);
            $q = pdo()->prepare("SELECT DISTINCT p.*
                FROM erp_ap_payments p
                JOIN erp_ap_allocations a ON a.payment_id=p.id AND a.workspace_id=p.workspace_id AND a.company_id=p.company_id
                WHERE p.workspace_id=? AND p.company_id=? AND a.invoice_doc_id IN ($in) AND a.status='active'
                ORDER BY p.id");
            $q->execute($params);
            $pays = $q->fetchAll() ?: [];
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($pays as $p) {
            $aq = pdo()->prepare("SELECT id,invoice_doc_id,amount,status,created_at
                FROM erp_ap_allocations
                WHERE workspace_id=? AND company_id=? AND payment_id=? AND status='active'
                ORDER BY id");
            $aq->execute([$wid, $cid, (int)$p['id']]);
            $allocs = $aq->fetchAll() ?: [];
            foreach ($allocs as &$a) {
                $a['amount_irr'] = self::moneyLabel((string)$a['amount']);
                $a['currency'] = self::LEDGER_CURRENCY;
            }
            unset($a);
            $out[] = [
                'id' => (int)$p['id'],
                'payment_no' => (string)$p['payment_no'],
                'payment_date' => (string)$p['payment_date'],
                'payment_method' => (string)$p['payment_method'],
                'status' => (string)$p['status'],
                'amount' => (string)$p['amount'],
                'amount_irr' => self::moneyLabel((string)$p['amount']),
                'voucher_id' => $p['voucher_id'] !== null ? (int)$p['voucher_id'] : null,
                'party_id' => (int)$p['party_id'],
                'updated_at' => (string)($p['updated_at'] ?? ''),
                'currency' => self::LEDGER_CURRENCY,
                'allocations' => $allocs,
            ];
        }
        return $out;
    }

    private static function moneyLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '0.00';
        }
        try {
            $u = PurchaseMatchingDomain::scaled($raw, 2, 10000000000);
            return PurchaseMatchingDomain::fixed($u, 2);
        } catch (Throwable $e) {
            if (preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
                return number_format((float)$raw, 2, '.', '');
            }
            return '0.00';
        }
    }

    private static function qtyLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '0.0000';
        }
        try {
            $u = PurchaseMatchingDomain::scaled($raw, 4, 1000000000);
            return PurchaseMatchingDomain::fixed($u, 4);
        } catch (Throwable $e) {
            if (preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
                return number_format((float)$raw, 4, '.', '');
            }
            return '0.0000';
        }
    }

    private static function addMoney(string $a, string $b): string
    {
        $ua = PurchaseMatchingDomain::scaled(self::moneyLabel($a), 2, 10000000000);
        $ub = PurchaseMatchingDomain::scaled(self::moneyLabel($b), 2, 10000000000);
        return PurchaseMatchingDomain::fixed($ua + $ub, 2);
    }

    private static function addQty(string $a, string $b): string
    {
        $ua = PurchaseMatchingDomain::scaled(self::qtyLabel($a), 4, 1000000000);
        $ub = PurchaseMatchingDomain::scaled(self::qtyLabel($b), 4, 1000000000);
        return PurchaseMatchingDomain::fixed($ua + $ub, 4);
    }
}
