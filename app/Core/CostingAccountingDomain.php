<?php
/**
 * ERPSMART RC1 T5 — Costing / valuation integrity (no parallel ledger).
 *
 * AP model remains invoice-based (NO GRNI). Purchase receipts never post GL.
 * WIP/FG GL valuation is BLOCKED for RC1 unless a future release proves a
 * balanced, period-safe, non-duplicative bridge against invoice recognition.
 *
 * Classes: POSTED_ACTUAL | PROVISIONAL | UNVALUED | BLOCKED
 * Estimates are never labeled as actual.
 */
final class CostingAccountingDomain
{
    public const VERSION = 'rc1-t5-costing';
    public const CURRENCY = 'IRR';

    public const CLASS_POSTED_ACTUAL = 'POSTED_ACTUAL';
    public const CLASS_PROVISIONAL = 'PROVISIONAL';
    public const CLASS_UNVALUED = 'UNVALUED';
    public const CLASS_BLOCKED = 'BLOCKED';

    /** RC1 decision: WIP/FG journal bridge is not enabled. */
    public const WIP_FG_GL_POLICY = 'BLOCKED';
    public const WIP_FG_GL_ENABLED = false;

    public const SOURCE_PRODUCTION_ORDER = 'production_order';
    public const MOVE_PRODUCTION_ISSUE = 'production_issue';
    public const MOVE_PRODUCTION_FG = 'production_fg_receipt';

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_costing_company_policy (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            wip_fg_gl_policy VARCHAR(20) NOT NULL DEFAULT 'BLOCKED',
            valuation_model VARCHAR(40) NOT NULL DEFAULT 'invoice_based_operational',
            notes VARCHAR(500) NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id, company_id)
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

    /** Company-scoped policy; RC1 refuses enabling WIP/FG GL. */
    public static function ensureCompanyPolicy(int $wid, int $cid, int $uid = 0): array
    {
        self::assertCompany($wid, $cid);
        self::migrate(pdo());
        $q = pdo()->prepare('SELECT * FROM erp_costing_company_policy WHERE workspace_id=? AND company_id=? LIMIT 1');
        $q->execute([$wid, $cid]);
        $row = $q->fetch();
        if (!$row) {
            pdo()->prepare('INSERT INTO erp_costing_company_policy (workspace_id,company_id,wip_fg_gl_policy,valuation_model,notes,updated_by,updated_at)
                VALUES (?,?,?,?,?,?,NOW())')
                ->execute([
                    $wid, $cid, self::WIP_FG_GL_POLICY, 'invoice_based_operational',
                    'RC1 default: operational cost evidence only; WIP/FG GL blocked',
                    $uid > 0 ? $uid : null,
                ]);
            $q->execute([$wid, $cid]);
            $row = $q->fetch();
        }
        return [
            'workspace_id' => $wid,
            'company_id' => $cid,
            'wip_fg_gl_policy' => (string)$row['wip_fg_gl_policy'],
            'wip_fg_gl_enabled' => false,
            'valuation_model' => (string)$row['valuation_model'],
            'notes' => (string)($row['notes'] ?? ''),
            'currency' => self::CURRENCY,
        ];
    }

    public static function wipFgGlPolicy(int $wid, int $cid): array
    {
        $pol = self::ensureCompanyPolicy($wid, $cid);
        return [
            'policy' => self::WIP_FG_GL_POLICY,
            'enabled' => self::WIP_FG_GL_ENABLED,
            'company_row' => $pol,
            'reason' => 'Invoice-based AP already debits purchase/inventory on posted supplier invoice; auto WIP/FG GL would double-recognize inventory asset without a proven clearing/offset map.',
            'acceptable_rc1' => 'Operational stock + verified cost rollup report with BLOCKED GL valuation.',
        ];
    }

    /**
     * Explicit gate: never posts WIP/FG vouchers in RC1.
     * @return array always classification=BLOCKED
     */
    public static function attemptWipFgGlPosting(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertCompany($wid, $cid);
        $pol = self::wipFgGlPolicy($wid, $cid);
        return [
            'posted' => false,
            'classification' => self::CLASS_BLOCKED,
            'gl_valuation' => self::WIP_FG_GL_POLICY,
            'voucher_id' => null,
            'production_order_id' => (int)($args['production_order_id'] ?? 0),
            'reason' => $pol['reason'],
            'policy' => $pol,
        ];
    }

    /**
     * Resolve valuation for a stock movement (component issue / receipt / FG).
     * Evidence always cites movement / invoice / voucher IDs when present.
     */
    public static function resolveMovementValuation(int $wid, int $cid, int $movementId): array
    {
        self::assertCompany($wid, $cid);
        $q = pdo()->prepare('SELECT * FROM acc_stock_movements WHERE id=? AND workspace_id=? AND company_id=? AND status=\'posted\' LIMIT 1');
        $q->execute([$movementId, $wid, $cid]);
        $m = $q->fetch();
        if (!$m) {
            return self::valuationResult(self::CLASS_UNVALUED, [
                'movement_id' => $movementId,
                'reason' => 'movement_not_found_or_not_posted',
            ]);
        }

        $moveType = (string)$m['movement_type'];
        if ($moveType === self::MOVE_PRODUCTION_FG || $moveType === 'production_wip') {
            return self::valuationResult(self::CLASS_BLOCKED, [
                'movement_id' => $movementId,
                'item_id' => (int)$m['item_id'],
                'movement_type' => $moveType,
                'quantity' => (string)$m['quantity'],
                'reason' => 'wip_fg_gl_blocked_rc1',
                'gl_valuation' => self::WIP_FG_GL_POLICY,
                'source_type' => (string)$m['source_type'],
                'source_id' => (int)$m['source_id'],
                'source_line_id' => $m['source_line_id'] !== null ? (int)$m['source_line_id'] : null,
            ]);
        }

        return self::resolveItemQtyCost(
            $wid,
            $cid,
            (int)$m['item_id'],
            (string)$m['quantity'],
            [
                'movement_id' => $movementId,
                'movement_type' => $moveType,
                'movement_unit_cost' => (string)$m['unit_cost'],
                'source_type' => (string)$m['source_type'],
                'source_id' => (int)$m['source_id'],
                'source_line_id' => $m['source_line_id'] !== null ? (int)$m['source_line_id'] : null,
            ]
        );
    }

    /**
     * Resolve cost basis for an item quantity using posted AP invoice evidence first.
     */
    public static function resolveItemQtyCost(int $wid, int $cid, int $itemId, string $qtyRaw, array $context = []): array
    {
        self::assertCompany($wid, $cid);
        if ($itemId < 1) {
            return self::valuationResult(self::CLASS_UNVALUED, array_merge($context, [
                'item_id' => $itemId,
                'reason' => 'invalid_item',
            ]));
        }
        $qtyUnits = PurchaseMatchingDomain::scaled((string)$qtyRaw, 4, 100000);
        if ($qtyUnits <= 0) {
            return self::valuationResult(self::CLASS_UNVALUED, array_merge($context, [
                'item_id' => $itemId,
                'quantity' => $qtyRaw,
                'reason' => 'non_positive_qty',
            ]));
        }

        $posted = self::findPostedInvoiceCostForItem($wid, $cid, $itemId);
        if ($posted !== null) {
            $unitCents = $posted['unit_cents'];
            $lineCents = PurchaseMatchingDomain::cents($qtyUnits, $unitCents);
            return self::valuationResult(self::CLASS_POSTED_ACTUAL, array_merge($context, [
                'item_id' => $itemId,
                'quantity' => PurchaseMatchingDomain::fixed($qtyUnits, 4),
                'unit_cost_irr' => PurchaseMatchingDomain::fixed($unitCents, 2),
                'total_cost_irr' => PurchaseMatchingDomain::fixed($lineCents, 2),
                'cost_label' => 'posted_actual',
                'invoice_doc_id' => $posted['invoice_doc_id'],
                'voucher_id' => $posted['voucher_id'],
                'ap_posting_id' => $posted['ap_posting_id'],
                'purchase_line_id' => $posted['purchase_line_id'],
                'evidence' => $posted['evidence'],
                'reason' => 'posted_supplier_invoice_unit_price',
            ]));
        }

        $moveUnit = isset($context['movement_unit_cost']) ? trim((string)$context['movement_unit_cost']) : '';
        if ($moveUnit !== '' && preg_match('/^(0|[1-9]\d*)(\.\d{1,2})?$/', $moveUnit) && PurchaseMatchingDomain::scaled($moveUnit, 2, 10000000000) > 0) {
            $unitCents = PurchaseMatchingDomain::scaled($moveUnit, 2, 10000000000);
            $lineCents = PurchaseMatchingDomain::cents($qtyUnits, $unitCents);
            return self::valuationResult(self::CLASS_PROVISIONAL, array_merge($context, [
                'item_id' => $itemId,
                'quantity' => PurchaseMatchingDomain::fixed($qtyUnits, 4),
                'unit_cost_irr' => PurchaseMatchingDomain::fixed($unitCents, 2),
                'total_cost_irr' => PurchaseMatchingDomain::fixed($lineCents, 2),
                'cost_label' => 'provisional_movement_unit_cost',
                'reason' => 'movement_unit_cost_without_posted_ap_invoice',
                'invoice_doc_id' => null,
                'voucher_id' => null,
            ]));
        }

        $po = self::findOpenPoUnitPrice($wid, $cid, $itemId);
        if ($po !== null) {
            $unitCents = $po['unit_cents'];
            $lineCents = PurchaseMatchingDomain::cents($qtyUnits, $unitCents);
            return self::valuationResult(self::CLASS_PROVISIONAL, array_merge($context, [
                'item_id' => $itemId,
                'quantity' => PurchaseMatchingDomain::fixed($qtyUnits, 4),
                'unit_cost_irr' => PurchaseMatchingDomain::fixed($unitCents, 2),
                'total_cost_irr' => PurchaseMatchingDomain::fixed($lineCents, 2),
                'cost_label' => 'provisional_po_price',
                'purchase_doc_id' => $po['purchase_doc_id'],
                'purchase_line_id' => $po['purchase_line_id'],
                'reason' => 'po_unit_price_without_posted_ap_invoice',
                'invoice_doc_id' => null,
                'voucher_id' => null,
            ]));
        }

        return self::valuationResult(self::CLASS_UNVALUED, array_merge($context, [
            'item_id' => $itemId,
            'quantity' => PurchaseMatchingDomain::fixed($qtyUnits, 4),
            'unit_cost_irr' => null,
            'total_cost_irr' => null,
            'cost_label' => 'unvalued',
            'reason' => 'no_posted_invoice_or_provisional_source',
            'invoice_doc_id' => null,
            'voucher_id' => null,
        ]));
    }

    /**
     * Manufacturing cost rollup: sum verified material issue costs only.
     * Never labels estimates as actual. GL valuation remains BLOCKED.
     */
    public static function rollupProductionMaterialCost(int $wid, int $cid, int $productionOrderId): array
    {
        self::assertCompany($wid, $cid);
        if ($productionOrderId < 1) {
            throw new RuntimeException('شناسه دستور تولید نامعتبر است.');
        }

        $st = pdo()->prepare("SELECT * FROM acc_stock_movements
            WHERE workspace_id=? AND company_id=? AND status='posted'
              AND movement_type=? AND source_type=? AND source_id=?
            ORDER BY id");
        $st->execute([$wid, $cid, self::MOVE_PRODUCTION_ISSUE, self::SOURCE_PRODUCTION_ORDER, $productionOrderId]);
        $moves = $st->fetchAll();

        $lines = [];
        $actualCents = 0;
        $provisionalCents = 0;
        $unvaluedCount = 0;
        $allActual = true;
        $anyProvisional = false;

        foreach ($moves as $m) {
            $resolved = self::resolveMovementValuation($wid, $cid, (int)$m['id']);
            $cls = (string)$resolved['classification'];
            $line = [
                'movement_id' => (int)$m['id'],
                'item_id' => (int)$m['item_id'],
                'quantity' => (string)$m['quantity'],
                'classification' => $cls,
                'unit_cost_irr' => $resolved['unit_cost_irr'] ?? null,
                'total_cost_irr' => $resolved['total_cost_irr'] ?? null,
                'cost_label' => $resolved['cost_label'] ?? null,
                'invoice_doc_id' => $resolved['invoice_doc_id'] ?? null,
                'voucher_id' => $resolved['voucher_id'] ?? null,
                'reason' => $resolved['reason'] ?? null,
            ];
            $lines[] = $line;

            if ($cls === self::CLASS_POSTED_ACTUAL && isset($resolved['total_cost_irr'])) {
                $actualCents += PurchaseMatchingDomain::scaled((string)$resolved['total_cost_irr'], 2, 10000000000);
            } elseif ($cls === self::CLASS_PROVISIONAL && isset($resolved['total_cost_irr'])) {
                $anyProvisional = true;
                $allActual = false;
                $provisionalCents += PurchaseMatchingDomain::scaled((string)$resolved['total_cost_irr'], 2, 10000000000);
            } else {
                $allActual = false;
                $unvaluedCount++;
            }
        }

        if (!$moves) {
            $rollupClass = self::CLASS_UNVALUED;
            $summaryLabel = 'unvalued_no_issues';
        } elseif ($unvaluedCount > 0) {
            $rollupClass = self::CLASS_UNVALUED;
            $summaryLabel = 'unvalued_incomplete_evidence';
        } elseif ($allActual) {
            $rollupClass = self::CLASS_POSTED_ACTUAL;
            $summaryLabel = 'posted_actual_material_only';
        } elseif ($anyProvisional) {
            $rollupClass = self::CLASS_PROVISIONAL;
            $summaryLabel = 'provisional_includes_estimates';
        } else {
            $rollupClass = self::CLASS_UNVALUED;
            $summaryLabel = 'unvalued';
        }

        $fg = pdo()->prepare("SELECT id,quantity,unit_cost FROM acc_stock_movements
            WHERE workspace_id=? AND company_id=? AND status='posted'
              AND movement_type=? AND source_type=? AND source_id=?
            ORDER BY id");
        $fg->execute([$wid, $cid, self::MOVE_PRODUCTION_FG, self::SOURCE_PRODUCTION_ORDER, $productionOrderId]);
        $fgRows = $fg->fetchAll();
        $fgQtyUnits = 0;
        foreach ($fgRows as $f) {
            $fgQtyUnits += PurchaseMatchingDomain::scaled((string)$f['quantity'], 4, 100000);
        }

        $verifiedTotal = $rollupClass === self::CLASS_POSTED_ACTUAL
            ? PurchaseMatchingDomain::fixed($actualCents, 2)
            : null;
        // Never expose provisional totals under an "actual" key.
        $provisionalTotal = $anyProvisional
            ? PurchaseMatchingDomain::fixed($provisionalCents, 2)
            : null;

        $unitActual = null;
        if ($verifiedTotal !== null && $fgQtyUnits > 0) {
            // Integer half-up: (actualCents * 10000 + fgQty/2) / fgQty → unit cents
            $unitActual = PurchaseMatchingDomain::fixed(
                intdiv($actualCents * 10000 + intdiv($fgQtyUnits, 2), $fgQtyUnits),
                2
            );
        }

        return [
            'production_order_id' => $productionOrderId,
            'classification' => $rollupClass,
            'cost_label' => $summaryLabel,
            'gl_valuation' => self::WIP_FG_GL_POLICY,
            'gl_posted' => false,
            'currency' => self::CURRENCY,
            'issue_count' => count($moves),
            'unvalued_issue_count' => $unvaluedCount,
            'posted_actual_total_irr' => $verifiedTotal,
            'provisional_total_irr' => $provisionalTotal,
            'fg_quantity' => PurchaseMatchingDomain::fixed($fgQtyUnits, 4),
            'fg_unit_cost_posted_actual_irr' => $unitActual,
            'fg_movement_ids' => array_map(static fn ($r) => (int)$r['id'], $fgRows),
            'lines' => $lines,
            'note' => 'Provisional amounts are never labeled actual. WIP/FG GL remains BLOCKED for RC1.',
        ];
    }

    /**
     * Reconciliation helper: AP/AR allocations vs voucher Dr/Cr — exact signed IRR diffs.
     */
    public static function reconcileSubledgerVsGl(int $wid, int $cid, array $opts = []): array
    {
        self::assertCompany($wid, $cid);
        $partyId = (int)($opts['party_id'] ?? 0);
        $rows = [];
        $maxAbsDiff = 0;

        // Voucher-level Dr/Cr balance check
        $vq = pdo()->prepare("SELECT v.id,v.source_type,v.source_id,v.total_debit,v.total_credit,
            COALESCE(SUM(l.debit),0) line_debit, COALESCE(SUM(l.credit),0) line_credit
            FROM acc_vouchers v
            LEFT JOIN acc_voucher_lines l ON l.voucher_id=v.id AND l.workspace_id=v.workspace_id
            WHERE v.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final')
            GROUP BY v.id,v.source_type,v.source_id,v.total_debit,v.total_credit
            ORDER BY v.id");
        $vq->execute([$wid, $cid]);
        foreach ($vq->fetchAll() as $v) {
            $hdrDr = AccountingPostingDomain::signedScaled((string)$v['total_debit'], 2);
            $hdrCr = AccountingPostingDomain::signedScaled((string)$v['total_credit'], 2);
            $lineDr = AccountingPostingDomain::signedScaled((string)$v['line_debit'], 2);
            $lineCr = AccountingPostingDomain::signedScaled((string)$v['line_credit'], 2);
            $imbalance = $lineDr - $lineCr;
            $hdrDiff = ($hdrDr - $lineDr);
            $rows[] = [
                'scope' => 'voucher_balance',
                'voucher_id' => (int)$v['id'],
                'source_type' => (string)$v['source_type'],
                'source_id' => $v['source_id'] !== null ? (int)$v['source_id'] : null,
                'line_debit_irr' => AccountingPostingDomain::signedFixed($lineDr, 2),
                'line_credit_irr' => AccountingPostingDomain::signedFixed($lineCr, 2),
                'imbalance_dr_minus_cr_irr' => AccountingPostingDomain::signedFixed($imbalance, 2),
                'header_vs_lines_debit_diff_irr' => AccountingPostingDomain::signedFixed($hdrDiff, 2),
                'ok' => $imbalance === 0 && $hdrDr === $lineDr && $hdrCr === $lineCr,
            ];
            $maxAbsDiff = max($maxAbsDiff, abs($imbalance), abs($hdrDiff));
        }

        // AP invoice: posted net vs voucher AP credit; allocations vs payment coverage
        $apSql = 'SELECT p.id ap_posting_id, p.invoice_doc_id, p.voucher_id, p.net_total, p.payable_account_id, p.reversal_voucher_id
            FROM erp_ap_postings p WHERE p.workspace_id=? AND p.company_id=?';
        $apParams = [$wid, $cid];
        if ($partyId > 0) {
            $apSql .= ' AND EXISTS (SELECT 1 FROM acc_purchase_docs d WHERE d.id=p.invoice_doc_id AND d.workspace_id=p.workspace_id AND d.party_id=?)';
            $apParams[] = $partyId;
        }
        $apSql .= ' ORDER BY p.id';
        $ap = pdo()->prepare($apSql);
        $ap->execute($apParams);
        foreach ($ap->fetchAll() as $p) {
            if (!empty($p['reversal_voucher_id'])) {
                continue;
            }
            $net = PurchaseMatchingDomain::scaled((string)$p['net_total'], 2, 10000000000);
            $vc = pdo()->prepare('SELECT COALESCE(SUM(credit-debit),0) FROM acc_voucher_lines WHERE workspace_id=? AND voucher_id=? AND account_id=?');
            $vc->execute([$wid, (int)$p['voucher_id'], (int)$p['payable_account_id']]);
            $apCredit = AccountingPostingDomain::signedScaled((string)$vc->fetchColumn(), 2);
            $alloc = 0;
            try {
                $aq = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ap_allocations WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='active'");
                $aq->execute([$wid, $cid, (int)$p['invoice_doc_id']]);
                $alloc = PurchaseMatchingDomain::scaled((string)$aq->fetchColumn(), 2, 10000000000);
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'erp_ap_allocations') === false) {
                    throw $e;
                }
            }
            $voucherDiff = $net - $apCredit;
            $open = $net - $alloc;
            $rows[] = [
                'scope' => 'ap_invoice',
                'ap_posting_id' => (int)$p['ap_posting_id'],
                'invoice_doc_id' => (int)$p['invoice_doc_id'],
                'voucher_id' => (int)$p['voucher_id'],
                'posted_net_irr' => PurchaseMatchingDomain::fixed($net, 2),
                'voucher_ap_credit_irr' => AccountingPostingDomain::signedFixed($apCredit, 2),
                'allocated_irr' => PurchaseMatchingDomain::fixed($alloc, 2),
                'outstanding_irr' => AccountingPostingDomain::signedFixed($open, 2),
                'diff_posted_net_minus_voucher_ap_irr' => AccountingPostingDomain::signedFixed($voucherDiff, 2),
                'ok' => $voucherDiff === 0,
            ];
            $maxAbsDiff = max($maxAbsDiff, abs($voucherDiff));
        }

        // AR invoice: posted net vs voucher AR debit; allocations
        try {
            $arSql = 'SELECT p.id ar_posting_id, p.invoice_doc_id, p.voucher_id, p.net_total, p.receivable_account_id
                FROM erp_ar_postings p WHERE p.workspace_id=? AND p.company_id=?';
            $arParams = [$wid, $cid];
            if ($partyId > 0) {
                $arSql .= ' AND EXISTS (SELECT 1 FROM acc_sales_docs d WHERE d.id=p.invoice_doc_id AND d.workspace_id=p.workspace_id AND d.party_id=?)';
                $arParams[] = $partyId;
            }
            $arSql .= ' ORDER BY p.id';
            $ar = pdo()->prepare($arSql);
            $ar->execute($arParams);
            foreach ($ar->fetchAll() as $p) {
                $net = PurchaseMatchingDomain::scaled((string)$p['net_total'], 2, 10000000000);
                $vd = pdo()->prepare('SELECT COALESCE(SUM(debit-credit),0) FROM acc_voucher_lines WHERE workspace_id=? AND voucher_id=? AND account_id=?');
                $vd->execute([$wid, (int)$p['voucher_id'], (int)$p['receivable_account_id']]);
                $arDebit = AccountingPostingDomain::signedScaled((string)$vd->fetchColumn(), 2);
                $alloc = 0;
                try {
                    $aq = pdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_ar_allocations WHERE workspace_id=? AND company_id=? AND invoice_doc_id=? AND status='active'");
                    $aq->execute([$wid, $cid, (int)$p['invoice_doc_id']]);
                    $alloc = PurchaseMatchingDomain::scaled((string)$aq->fetchColumn(), 2, 10000000000);
                } catch (PDOException $e) {
                    if (stripos($e->getMessage(), 'erp_ar_allocations') === false) {
                        throw $e;
                    }
                }
                $voucherDiff = $net - $arDebit;
                $open = $net - $alloc;
                $rows[] = [
                    'scope' => 'ar_invoice',
                    'ar_posting_id' => (int)$p['ar_posting_id'],
                    'invoice_doc_id' => (int)$p['invoice_doc_id'],
                    'voucher_id' => (int)$p['voucher_id'],
                    'posted_net_irr' => PurchaseMatchingDomain::fixed($net, 2),
                    'voucher_ar_debit_irr' => AccountingPostingDomain::signedFixed($arDebit, 2),
                    'allocated_irr' => PurchaseMatchingDomain::fixed($alloc, 2),
                    'outstanding_irr' => AccountingPostingDomain::signedFixed($open, 2),
                    'diff_posted_net_minus_voucher_ar_irr' => AccountingPostingDomain::signedFixed($voucherDiff, 2),
                    'ok' => $voucherDiff === 0,
                ];
                $maxAbsDiff = max($maxAbsDiff, abs($voucherDiff));
            }
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'erp_ar_postings') === false && stripos($e->getMessage(), 'acc_sales_docs') === false) {
                throw $e;
            }
        }

        $failures = array_values(array_filter($rows, static fn ($r) => empty($r['ok'])));
        return [
            'workspace_id' => $wid,
            'company_id' => $cid,
            'currency' => self::CURRENCY,
            'row_count' => count($rows),
            'failure_count' => count($failures),
            'max_abs_diff_irr' => AccountingPostingDomain::signedFixed($maxAbsDiff, 2),
            'balanced' => count($failures) === 0,
            'rows' => $rows,
            'failures' => $failures,
        ];
    }

    /** Detect duplicate AP posting keys / voucher source collisions for a company. */
    public static function detectDuplicatePostingKeys(int $wid, int $cid): array
    {
        self::assertCompany($wid, $cid);
        $dupInvoice = self::countGroupedDupes(
            'SELECT invoice_doc_id AS k FROM erp_ap_postings WHERE workspace_id=? AND company_id=? GROUP BY invoice_doc_id HAVING COUNT(*)>1',
            [$wid, $cid]
        );
        $dupIdem = self::countGroupedDupes(
            'SELECT idempotency_key AS k FROM erp_ap_postings WHERE workspace_id=? AND company_id=? GROUP BY idempotency_key HAVING COUNT(*)>1',
            [$wid, $cid]
        );
        $dupVoucher = self::countGroupedDupes(
            'SELECT voucher_id AS k FROM erp_ap_postings WHERE workspace_id=? AND company_id=? GROUP BY voucher_id HAVING COUNT(*)>1',
            [$wid, $cid]
        );
        $st = pdo()->prepare("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=? AND company_id=? AND (
            source_type LIKE '%receipt%' OR source_type IN ('inventory_receipt','purchase_receipt','grni')
        )");
        $st->execute([$wid, $cid]);
        $receiptGl = (int)$st->fetchColumn();

        return [
            'duplicate_ap_invoice_keys' => $dupInvoice,
            'duplicate_ap_idempotency_keys' => $dupIdem,
            'duplicate_ap_voucher_keys' => $dupVoucher,
            'receipt_gl_voucher_count' => $receiptGl,
            'ok' => $dupInvoice === 0 && $dupIdem === 0 && $dupVoucher === 0 && $receiptGl === 0,
        ];
    }

    /** @param list<int|string> $params */
    private static function countGroupedDupes(string $sql, array $params): int
    {
        $st = pdo()->prepare($sql);
        $st->execute($params);
        return count($st->fetchAll());
    }

    /** @return array{unit_cents:int,invoice_doc_id:int,voucher_id:int,ap_posting_id:int,purchase_line_id:int,evidence:array}|null */
    private static function findPostedInvoiceCostForItem(int $wid, int $cid, int $itemId): ?array
    {
        $q = pdo()->prepare("SELECT p.id ap_posting_id, p.invoice_doc_id, p.voucher_id, pl.id purchase_line_id, pl.unit_price, pl.quantity
            FROM erp_ap_postings p
            JOIN acc_purchase_lines pl ON pl.purchase_doc_id=p.invoice_doc_id AND pl.workspace_id=p.workspace_id
            WHERE p.workspace_id=? AND p.company_id=? AND pl.item_id=?
              AND (p.reversal_voucher_id IS NULL OR p.reversal_voucher_id=0)
            ORDER BY p.id DESC, pl.id DESC
            LIMIT 1");
        $q->execute([$wid, $cid, $itemId]);
        $row = $q->fetch();
        if (!$row) {
            return null;
        }
        $unitCents = PurchaseMatchingDomain::scaled((string)$row['unit_price'], 2, 10000000000);
        if ($unitCents <= 0) {
            return null;
        }
        return [
            'unit_cents' => $unitCents,
            'invoice_doc_id' => (int)$row['invoice_doc_id'],
            'voucher_id' => (int)$row['voucher_id'],
            'ap_posting_id' => (int)$row['ap_posting_id'],
            'purchase_line_id' => (int)$row['purchase_line_id'],
            'evidence' => [
                'invoice_doc_id' => (int)$row['invoice_doc_id'],
                'voucher_id' => (int)$row['voucher_id'],
                'ap_posting_id' => (int)$row['ap_posting_id'],
                'purchase_line_id' => (int)$row['purchase_line_id'],
                'invoice_unit_price_irr' => PurchaseMatchingDomain::fixed($unitCents, 2),
            ],
        ];
    }

    /** @return array{unit_cents:int,purchase_doc_id:int,purchase_line_id:int}|null */
    private static function findOpenPoUnitPrice(int $wid, int $cid, int $itemId): ?array
    {
        $q = pdo()->prepare("SELECT d.id purchase_doc_id, pl.id purchase_line_id, pl.unit_price
            FROM acc_purchase_docs d
            JOIN acc_purchase_lines pl ON pl.purchase_doc_id=d.id AND pl.workspace_id=d.workspace_id
            WHERE d.workspace_id=? AND d.company_id=? AND d.doc_type='purchase_order_goods'
              AND pl.item_id=? AND pl.unit_price>0
            ORDER BY d.id DESC, pl.id DESC
            LIMIT 1");
        $q->execute([$wid, $cid, $itemId]);
        $row = $q->fetch();
        if (!$row) {
            return null;
        }
        return [
            'unit_cents' => PurchaseMatchingDomain::scaled((string)$row['unit_price'], 2, 10000000000),
            'purchase_doc_id' => (int)$row['purchase_doc_id'],
            'purchase_line_id' => (int)$row['purchase_line_id'],
        ];
    }

    private static function valuationResult(string $classification, array $payload): array
    {
        $base = [
            'classification' => $classification,
            'currency' => self::CURRENCY,
            'gl_valuation' => self::WIP_FG_GL_POLICY,
            'is_actual' => $classification === self::CLASS_POSTED_ACTUAL,
            'is_estimate' => $classification === self::CLASS_PROVISIONAL,
            'unit_cost_irr' => null,
            'total_cost_irr' => null,
            'invoice_doc_id' => null,
            'voucher_id' => null,
            'movement_id' => null,
        ];
        return array_merge($base, $payload, [
            'classification' => $classification,
            // Hard invariant: provisional never reports is_actual=true
            'is_actual' => $classification === self::CLASS_POSTED_ACTUAL,
            'is_estimate' => $classification === self::CLASS_PROVISIONAL,
        ]);
    }
}
