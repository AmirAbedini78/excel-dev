<?php
/**
 * Read-only trading cockpit aggregator (TR-1: PO → GR → P2P → AP → payment).
 * Consumes domain read APIs; does not mutate stock/GL.
 */
final class DashboardTradingCockpit
{
    public const VERSION = 'rc1-t6-trading-cockpit';
    public const LIMIT = 40;

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت/محیط کاری برای کابین تجارت انتخاب نشده است.');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('شرکت انتخاب‌شده متعلق به محیط کاری نیست.');
        }
    }

    /**
     * Bounded PO→payment pipeline rows for the active company.
     * @return array{company_id:int,rows:array,counts:array,warnings:string[],version:string}
     */
    public static function pipeline(int $wid, int $cid, int $limit = self::LIMIT): array
    {
        self::assertCompany($wid, $cid);
        $limit = max(1, min(80, $limit));
        $orders = PurchaseMatchingDomain::orders($wid, $cid);
        $orders = array_slice($orders, 0, $limit);
        $billsByPo = [];
        foreach (PurchaseMatchingDomain::bills($wid, $cid) as $bill) {
            $poId = (int)$bill['purchase_order_id'];
            if (!isset($billsByPo[$poId])) {
                $billsByPo[$poId] = $bill;
            }
        }
        $invoiceByPo = self::invoiceDocsByPo($wid, $cid);
        $payments = self::recentPayments($wid, $cid, 50);
        $paymentsByParty = [];
        foreach ($payments as $p) {
            $pid = (int)$p['party_id'];
            if (!isset($paymentsByParty[$pid])) {
                $paymentsByParty[$pid] = $p;
            }
        }

        $rows = [];
        $counts = [
            'po' => 0,
            'awaiting_receipt' => 0,
            'awaiting_match' => 0,
            'awaiting_ap_post' => 0,
            'awaiting_payment' => 0,
            'allocated' => 0,
        ];
        $warnings = [];

        foreach ($orders as $o) {
            $poId = (int)$o['id'];
            $counts['po']++;
            $pipeline = InventoryDomain::purchasePipeline($wid, $cid, [
                'purchase_doc_id' => $poId,
                'open_only' => false,
                'limit' => 100,
            ]);
            $openQty = 0.0;
            $acceptedQty = 0.0;
            foreach ($pipeline['rows'] as $line) {
                $openQty += (float)($line['expected_inbound'] ?? 0);
                $acceptedQty += (float)($line['accepted_qty'] ?? 0);
            }
            $receiptCount = (int)($o['receipt_count'] ?? 0);
            $bill = $billsByPo[$poId] ?? null;
            $invoice = $invoiceByPo[$poId] ?? null;
            $partyId = (int)($invoice['party_id'] ?? 0);
            $payment = $partyId > 0 ? ($paymentsByParty[$partyId] ?? null) : null;
            $allocated = false;
            $apStatus = null;
            $outstanding = null;

            if ($invoice) {
                try {
                    $wf = ApPostingDomain::workflowStatus($wid, $cid, (int)$invoice['id']);
                    $apStatus = (string)($wf['status'] ?? 'draft');
                } catch (Throwable $e) {
                    $apStatus = 'unknown';
                    $warnings[] = 'AP workflow read failed for invoice #' . (int)$invoice['id'];
                }
                if ($apStatus === 'posted') {
                    try {
                        $out = ApPostingDomain::invoiceOutstanding($wid, $cid, (int)$invoice['id']);
                        $outstanding = $out['outstanding'] ?? null;
                        $allocUnits = self::activeAllocationTotal($wid, $cid, (int)$invoice['id']);
                        $allocated = $allocUnits > 0;
                    } catch (Throwable $e) {
                        $warnings[] = 'Outstanding read failed for invoice #' . (int)$invoice['id'];
                    }
                }
            }

            $stage = self::deriveStage(
                (string)$o['workflow_status'],
                $receiptCount,
                $openQty,
                $acceptedQty,
                $bill,
                $invoice,
                $apStatus,
                $payment,
                $allocated
            );
            if (isset($counts[$stage['count_key']])) {
                $counts[$stage['count_key']]++;
            }

            $rows[] = [
                'po_id' => $poId,
                'document_no' => $o['document_no'],
                'document_date' => $o['document_date'],
                'supplier_name' => $o['supplier_name'] ?? '—',
                'po_status' => $o['workflow_status'],
                'receipt_count' => $receiptCount,
                'open_qty' => $openQty,
                'accepted_qty' => $acceptedQty,
                'bill' => $bill ? [
                    'id' => (int)$bill['id'],
                    'status' => $bill['status'],
                    'invoice_no' => $bill['supplier_invoice_no'] ?? '',
                    'canonical_doc_id' => $bill['canonical_doc_id'] ? (int)$bill['canonical_doc_id'] : null,
                ] : null,
                'invoice' => $invoice ? [
                    'id' => (int)$invoice['id'],
                    'document_no' => $invoice['document_no'],
                    'workflow_status' => $invoice['workflow_status'],
                    'net_total' => $invoice['net_total'],
                    'party_id' => (int)$invoice['party_id'],
                    'ap_status' => $apStatus,
                    'outstanding' => $outstanding,
                ] : null,
                'payment' => $payment ? [
                    'id' => (int)$payment['id'],
                    'payment_no' => $payment['payment_no'],
                    'status' => $payment['status'],
                    'amount' => $payment['amount'],
                    // Never claim bank reconciliation from payment post alone.
                    'bank_reconciled' => false,
                    'claim' => self::paymentClaim((string)$payment['status'], $allocated),
                ] : null,
                'stage' => $stage['key'],
                'stage_label' => $stage['label'],
                'stage_tone' => $stage['tone'],
                'next_actions' => $stage['next_actions'],
                'links' => self::links($poId, $bill, $invoice),
            ];
        }

        return [
            'company_id' => $cid,
            'rows' => $rows,
            'counts' => $counts,
            'warnings' => array_values(array_unique($warnings)),
            'version' => self::VERSION,
            'claims' => [
                'paid_requires' => 'posted_payment_plus_active_allocation',
                'bank_reconciled' => 'never_from_this_cockpit',
            ],
        ];
    }

    /** Single-PO navigation snapshot. */
    public static function trace(int $wid, int $cid, int $poId): array
    {
        self::assertCompany($wid, $cid);
        if ($poId < 1) {
            throw new RuntimeException('شناسه سفارش خرید نامعتبر است.');
        }
        $pipe = self::pipeline($wid, $cid, 80);
        foreach ($pipe['rows'] as $row) {
            if ((int)$row['po_id'] === $poId) {
                return ['found' => true, 'row' => $row, 'version' => self::VERSION];
            }
        }
        return [
            'found' => false,
            'row' => null,
            'empty' => [
                'title' => 'سفارش در کابین یافت نشد',
                'detail' => 'ممکن است سفارش void باشد، متعلق به شرکت دیگر باشد، یا هنوز ثبت نشده باشد.',
            ],
            'version' => self::VERSION,
        ];
    }

    private static function invoiceDocsByPo(int $wid, int $cid): array
    {
        $st = pdo()->prepare("SELECT d.id,d.document_no,d.workflow_status,d.net_total,d.party_id,d.created_at,
            b.purchase_order_id
            FROM acc_purchase_docs d
            INNER JOIN erp_p2p_vendor_bills b ON b.canonical_doc_id=d.id AND b.workspace_id=d.workspace_id AND b.company_id=d.company_id
            WHERE d.workspace_id=? AND d.company_id=? AND d.doc_type IN ('purchase_invoice_goods','purchase_invoice_service')
              AND d.workflow_status<>'void'
            ORDER BY d.id DESC LIMIT 200");
        $st->execute([$wid, $cid]);
        $map = [];
        foreach ($st->fetchAll() as $r) {
            $po = (int)$r['purchase_order_id'];
            if ($po > 0 && !isset($map[$po])) {
                $map[$po] = $r;
            }
        }
        return $map;
    }

    private static function recentPayments(int $wid, int $cid, int $limit): array
    {
        try {
            $st = pdo()->prepare('SELECT id,party_id,payment_no,payment_date,amount,status FROM erp_ap_payments WHERE workspace_id=? AND company_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
            $st->execute([$wid, $cid]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function activeAllocationTotal(int $wid, int $cid, int $invoiceDocId): float
    {
        try {
            $st = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='active'");
            $st->execute([$wid, $cid, $invoiceDocId]);
            return (float)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    private static function paymentClaim(string $status, bool $allocated): string
    {
        if ($status !== 'posted') {
            return 'payment_' . $status;
        }
        if (!$allocated) {
            return 'posted_unallocated_not_paid';
        }
        return 'posted_and_allocated';
    }

    private static function deriveStage(
        string $poStatus,
        int $receiptCount,
        float $openQty,
        float $acceptedQty,
        ?array $bill,
        ?array $invoice,
        ?string $apStatus,
        ?array $payment,
        bool $allocated
    ): array {
        $nav = [];
        if ($poStatus === 'draft') {
            return [
                'key' => 'po_draft',
                'label' => 'پیش‌نویس سفارش',
                'tone' => 'warn',
                'count_key' => 'awaiting_match',
                'next_actions' => self::actionLinks([
                    ['label' => 'تأیید سفارش در P2P', 'href' => 'index.php?page=p2p', 'permission' => 'accounting.purchase.manage'],
                ]),
            ];
        }
        if ($receiptCount < 1 || $acceptedQty <= 0.000001) {
            return [
                'key' => 'awaiting_receipt',
                'label' => 'در انتظار رسید انبار',
                'tone' => 'warn',
                'count_key' => 'awaiting_receipt',
                'next_actions' => self::actionLinks([
                    ['label' => 'ثبت رسید', 'href' => 'index.php?page=inventory', 'permission' => 'inventory.manage'],
                    ['label' => 'مشاهده تأمین', 'href' => 'index.php?page=procurement', 'permission' => 'procurement.view'],
                ]),
            ];
        }
        if ($openQty > 0.000001) {
            $nav[] = ['label' => 'تکمیل رسید', 'href' => 'index.php?page=inventory', 'permission' => 'inventory.manage'];
        }
        if (!$bill) {
            return [
                'key' => 'awaiting_match',
                'label' => 'آماده تطبیق / فاکتور',
                'tone' => 'info',
                'count_key' => 'awaiting_match',
                'next_actions' => self::actionLinks(array_merge($nav, [
                    ['label' => 'تطبیق سه‌طرفه', 'href' => 'index.php?page=p2p', 'permission' => 'accounting.purchase.manage'],
                ])),
            ];
        }
        if (in_array((string)$bill['status'], ['pending', 'rejected'], true)) {
            return [
                'key' => 'bill_' . $bill['status'],
                'label' => $bill['status'] === 'pending' ? 'در انتظار تأیید فاکتور' : 'فاکتور ردشده — اصلاح',
                'tone' => $bill['status'] === 'pending' ? 'info' : 'danger',
                'count_key' => 'awaiting_match',
                'next_actions' => self::actionLinks([
                    ['label' => 'بررسی درخواست فاکتور', 'href' => 'index.php?page=p2p', 'permission' => 'accounting.purchase.manage'],
                ]),
            ];
        }
        if (!$invoice) {
            return [
                'key' => 'awaiting_invoice_doc',
                'label' => 'فاکتور حسابداری هنوز صادر نشده',
                'tone' => 'warn',
                'count_key' => 'awaiting_ap_post',
                'next_actions' => self::actionLinks([
                    ['label' => 'ادامه در P2P', 'href' => 'index.php?page=p2p', 'permission' => 'accounting.purchase.view'],
                ]),
            ];
        }
        if ($apStatus !== 'posted') {
            return [
                'key' => 'awaiting_ap_post',
                'label' => 'AP ثبت دفتر نشده (' . ($apStatus ?: 'draft') . ')',
                'tone' => 'info',
                'count_key' => 'awaiting_ap_post',
                'next_actions' => self::actionLinks([
                    ['label' => 'فاکتور خرید', 'href' => 'index.php?page=industrial&section=purchase&view=' . (int)$invoice['id'], 'permission' => 'accounting.purchase.view'],
                ]),
            ];
        }
        if (!$payment || (string)$payment['status'] !== 'posted' || !$allocated) {
            return [
                'key' => 'awaiting_payment',
                'label' => !$payment ? 'در انتظار پرداخت' : ((string)$payment['status'] !== 'posted' ? 'پرداخت ' . $payment['status'] : 'پرداخت بدون تخصیص (Paid ادعا نمی‌شود)'),
                'tone' => 'warn',
                'count_key' => 'awaiting_payment',
                'next_actions' => self::actionLinks([
                    ['label' => 'خزانه / پرداخت AP', 'href' => 'index.php?page=industrial&section=treasury', 'permission' => 'accounting.treasury.view'],
                ]),
            ];
        }
        return [
            'key' => 'allocated',
            'label' => 'پرداخت ثبت و تخصیص‌شده',
            'tone' => 'ok',
            'count_key' => 'allocated',
            'next_actions' => self::actionLinks([
                ['label' => 'مشاهده خزانه', 'href' => 'index.php?page=industrial&section=treasury', 'permission' => 'accounting.treasury.view'],
            ]),
        ];
    }

    private static function actionLinks(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $perm = (string)($item['permission'] ?? '');
            $allowed = $perm === '' || (class_exists('Tenant') && Tenant::can($perm));
            $out[] = [
                'label' => $item['label'],
                'href' => $item['href'],
                'permission' => $perm,
                'allowed' => $allowed,
            ];
        }
        return $out;
    }

    private static function links(int $poId, ?array $bill, ?array $invoice): array
    {
        $links = [
            'po' => 'index.php?page=p2p&po=' . $poId,
            'procurement' => 'index.php?page=procurement',
            'inventory' => 'index.php?page=inventory',
            'treasury' => 'index.php?page=industrial&section=treasury',
        ];
        if ($bill) {
            $links['p2p'] = 'index.php?page=p2p';
        }
        if ($invoice) {
            $links['invoice'] = 'index.php?page=industrial&section=purchase&view=' . (int)$invoice['id'];
        }
        return $links;
    }
}
