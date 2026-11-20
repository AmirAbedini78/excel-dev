<?php
/**
 * MF-1 cost rollup evidence. No automatic WIP/FG GL.
 * Classes: POSTED_ACTUAL | PROVISIONAL | UNVALUED | BLOCKED (RC1 contracts §5).
 * Uses T5 resolver when present; otherwise marks UNVALUED/BLOCKED with source citations.
 */
final class ManufacturingCostEvidence
{
    public const VERSION = 'rc1-t1';

    public static function rollup(int $wid, int $cid, int $productionOrderId): array
    {
        if ($wid < 1 || $cid < 1 || $productionOrderId < 1) {
            throw new RuntimeException('mf_cost_scope_invalid');
        }
        if (class_exists('CostingAccountingResolver') && method_exists('CostingAccountingResolver', 'resolveProductionOrder')) {
            $resolved = CostingAccountingResolver::resolveProductionOrder($wid, $cid, $productionOrderId);
            if (is_array($resolved)) {
                return $resolved + ['resolver' => 'CostingAccountingResolver', 'gl_posting' => 'BLOCKED'];
            }
        }

        $order = ManufacturingExecutionDomain::getOrder($wid, $cid, $productionOrderId);
        if (!$order) {
            throw new RuntimeException('mf_order_not_found');
        }

        $db = pdo();
        $st = $db->prepare("SELECT m.id AS movement_id, m.item_id, m.quantity, m.unit_cost, m.movement_type, m.direction, m.source_id, m.source_line_id
            FROM acc_stock_movements m
            WHERE m.workspace_id=? AND m.company_id=? AND m.source_type='production_order'
              AND m.status='posted'
              AND (m.source_id=? OR m.source_id IN (
                    SELECT id FROM erp_mf_material_issues WHERE workspace_id=? AND company_id=? AND production_order_id=?
                  ) OR m.source_id IN (
                    SELECT id FROM erp_mf_fg_receipts WHERE workspace_id=? AND company_id=? AND production_order_id=?
                  ))
            ORDER BY m.id");
        $st->execute([$wid, $cid, $productionOrderId, $wid, $cid, $productionOrderId, $wid, $cid, $productionOrderId]);
        $moves = $st->fetchAll();

        $materialCents = 0;
        $materialSources = [];
        $unvaluedLines = 0;
        foreach ($moves as $m) {
            if ($m['movement_type'] !== 'production_issue' || $m['direction'] !== 'out') {
                continue;
            }
            $mid = (int)$m['movement_id'];
            $qtyUnits = self::scaledQty((string)$m['quantity']);
            $costUnits = self::scaledMoney((string)$m['unit_cost']);
            if ($costUnits <= 0) {
                $unvaluedLines++;
                $materialSources[] = [
                    'movement_id' => $mid,
                    'item_id' => (int)$m['item_id'],
                    'quantity' => (string)$m['quantity'],
                    'class' => 'UNVALUED',
                    'note' => 'unit_cost missing or zero; not labeled actual',
                ];
                continue;
            }
            $line = intdiv($qtyUnits * $costUnits + 5000, 10000); // qty scale4 * money scale2 → cents
            $materialCents += $line;
            $materialSources[] = [
                'movement_id' => $mid,
                'item_id' => (int)$m['item_id'],
                'quantity' => (string)$m['quantity'],
                'unit_cost' => (string)$m['unit_cost'],
                'line_amount_irr' => self::fixedMoney($line),
                'class' => 'PROVISIONAL',
                'note' => 'movement unit_cost present; not AP-invoice-proven POSTED_ACTUAL',
            ];
        }

        $labor = self::verifiedJournalEvidence($wid, $cid, $productionOrderId, 'labor');
        $overhead = self::verifiedJournalEvidence($wid, $cid, $productionOrderId, 'overhead');

        $fgQty = self::scaledQty((string)($order['received_fg_qty'] ?? '0'));
        $totalCents = $materialCents + (int)$labor['cents'] + (int)$overhead['cents'];
        $unitCost = null;
        if ($fgQty > 0 && $totalCents > 0 && $unvaluedLines === 0) {
            $unitCost = self::fixedMoney(intdiv($totalCents * 10000 + intdiv($fgQty, 2), $fgQty));
        }

        $resolverClass = 'BLOCKED';
        if ($unvaluedLines > 0 || $totalCents <= 0) {
            $resolverClass = 'UNVALUED';
        } elseif ($labor['class'] === 'POSTED_ACTUAL' || $overhead['class'] === 'POSTED_ACTUAL') {
            $resolverClass = 'PROVISIONAL';
        } else {
            $resolverClass = 'PROVISIONAL';
        }

        return [
            'production_order_id' => $productionOrderId,
            'order_no' => $order['order_no'] ?? null,
            'resolver' => 'ManufacturingCostEvidence',
            'resolver_class' => $resolverClass,
            'gl_posting' => 'BLOCKED',
            'gl_note' => 'RC1 default: no automatic WIP/FG GL until T5 proves balanced invoice-safe mapping',
            'material' => [
                'amount_irr' => self::fixedMoney($materialCents),
                'sources' => $materialSources,
                'unvalued_lines' => $unvaluedLines,
            ],
            'labor' => $labor,
            'overhead' => $overhead,
            'total_amount_irr' => self::fixedMoney($totalCents),
            'received_fg_qty' => self::fixedQty($fgQty),
            'unit_cost_irr' => $unitCost,
            'never_label_as_actual_without_proof' => true,
            'inventory_adapter_mode' => ManufacturingInventoryAdapter::mode(),
        ];
    }

    private static function verifiedJournalEvidence(int $wid, int $cid, int $orderId, string $kind): array
    {
        // Labor/overhead only when controlled journal source exists with production_order link.
        if (!self::tableExists('acc_vouchers')) {
            return ['cents' => 0, 'class' => 'UNVALUED', 'sources' => [], 'note' => 'no_voucher_table'];
        }
        try {
            $st = pdo()->prepare("SELECT v.id voucher_id, vl.debit, vl.credit
                FROM acc_vouchers v
                JOIN acc_voucher_lines vl ON vl.voucher_id=v.id AND vl.workspace_id=v.workspace_id
                WHERE v.workspace_id=? AND v.company_id=? AND v.status='posted'
                  AND v.source_type=? AND v.source_id=?");
            $st->execute([$wid, $cid, 'production_'.$kind, $orderId]);
            $rows = $st->fetchAll();
        } catch (Throwable $e) {
            return ['cents' => 0, 'class' => 'UNVALUED', 'sources' => [], 'note' => 'journal_query_unavailable'];
        }
        if (!$rows) {
            return ['cents' => 0, 'class' => 'UNVALUED', 'sources' => [], 'note' => 'no_posted_'.$kind.'_evidence'];
        }
        $cents = 0;
        $sources = [];
        foreach ($rows as $r) {
            $amt = self::scaledMoney((string)$r['debit']) - self::scaledMoney((string)$r['credit']);
            if ($amt < 0) {
                $amt = -$amt;
            }
            $cents += $amt;
            $sources[] = ['voucher_id' => (int)$r['voucher_id'], 'amount_irr' => self::fixedMoney($amt)];
        }
        return ['cents' => $cents, 'class' => 'POSTED_ACTUAL', 'sources' => $sources, 'amount_irr' => self::fixedMoney($cents)];
    }

    private static function tableExists(string $table): bool
    {
        $st = pdo()->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }

    private static function scaledQty($raw): int
    {
        if (class_exists('OrderToCashDomain')) {
            return OrderToCashDomain::scaledQty($raw);
        }
        if (is_float($raw) || is_int($raw)) {
            $raw = number_format((float)$raw, 4, '.', '');
        }
        return PurchaseMatchingDomain::scaled((string)$raw, 4, 100000);
    }

    private static function fixedQty(int $units): string
    {
        if (class_exists('OrderToCashDomain')) {
            return OrderToCashDomain::fixedQty($units);
        }
        return PurchaseMatchingDomain::fixed($units, 4);
    }

    private static function scaledMoney($raw): int
    {
        if (is_float($raw) || is_int($raw)) {
            $raw = number_format((float)$raw, 2, '.', '');
        }
        return PurchaseMatchingDomain::scaled((string)$raw, 2, 10000000000);
    }

    private static function fixedMoney(int $cents): string
    {
        return PurchaseMatchingDomain::fixed($cents, 2);
    }
}
