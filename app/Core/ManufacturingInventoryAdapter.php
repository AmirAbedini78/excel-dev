<?php
/**
 * T1 bridge to T2 frozen stock APIs (RC1-SHARED-CONTRACTS §2).
 * Delegates to InventoryDomain when methods exist; otherwise a temporary
 * adapter posts to the SAME canonical ledger (acc_stock_movements /
 * acc_inventory_reservations). Not a second stock ledger.
 *
 * INTEGRATION: replace temporary path once T2 merges reserveForProduction,
 * issueMaterial, receiveFinishedGoods, reverseMovement into InventoryDomain.
 */
final class ManufacturingInventoryAdapter
{
    public const VERSION = 'rc1-t1-bridge';
    public const MODE_NATIVE = 'inventory_domain';
    public const MODE_TEMPORARY = 'temporary_canonical_ledger';

    public static function mode(): string
    {
        return self::nativeAvailable() ? self::MODE_NATIVE : self::MODE_TEMPORARY;
    }

    public static function nativeAvailable(): bool
    {
        return method_exists('InventoryDomain', 'reserveForProduction')
            && method_exists('InventoryDomain', 'issueMaterial')
            && method_exists('InventoryDomain', 'receiveFinishedGoods')
            && method_exists('InventoryDomain', 'reverseMovement');
    }

    public static function reserveForProduction(int $wid, int $cid, int $uid, array $args): array
    {
        if (method_exists('InventoryDomain', 'reserveForProduction')) {
            return InventoryDomain::reserveForProduction($wid, $cid, $uid, $args);
        }
        return self::tempReserve($wid, $cid, $uid, $args);
    }

    public static function issueMaterial(int $wid, int $cid, int $uid, array $args): array
    {
        if (method_exists('InventoryDomain', 'issueMaterial')) {
            return InventoryDomain::issueMaterial($wid, $cid, $uid, $args);
        }
        return self::tempIssue($wid, $cid, $uid, $args);
    }

    public static function receiveFinishedGoods(int $wid, int $cid, int $uid, array $args): array
    {
        if (method_exists('InventoryDomain', 'receiveFinishedGoods')) {
            return InventoryDomain::receiveFinishedGoods($wid, $cid, $uid, $args);
        }
        return self::tempReceiveFg($wid, $cid, $uid, $args);
    }

    public static function reverseMovement(int $wid, int $cid, int $uid, array $args): array
    {
        if (method_exists('InventoryDomain', 'reverseMovement')) {
            return InventoryDomain::reverseMovement($wid, $cid, $uid, $args);
        }
        return self::tempReverse($wid, $cid, $uid, $args);
    }

    private static function assertScope(int $wid, int $cid, int $uid): void
    {
        if ($wid < 1 || $cid < 1 || $uid < 1) {
            throw new RuntimeException('mf_inventory_scope_invalid');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('mf_inventory_company_not_found');
        }
    }

    private static function idempotencyKey(array $args): string
    {
        $idem = trim((string)($args['idempotency_key'] ?? ''));
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('mf_idempotency_key_invalid');
        }
        return $idem;
    }

    private static function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function lockTargets(PDO $db, int $wid, int $cid, int $warehouseId, array $itemIds): void
    {
        // erp_o2c_stock_locks must already exist (created in ManufacturingExecutionDomain::migrate).
        // Never CREATE TABLE here — MySQL DDL would implicit-commit an outer transaction.
        if (class_exists('OrderToCashDomain') && method_exists('OrderToCashDomain', 'lockStockTargets')) {
            OrderToCashDomain::lockStockTargets($db, $wid, $cid, $warehouseId, $itemIds);
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($x) => $x > 0)));
        sort($ids);
        foreach ($ids as $itemId) {
            $db->prepare('INSERT INTO erp_o2c_stock_locks (workspace_id,company_id,warehouse_id,item_id,updated_at) VALUES (?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)')->execute([$wid, $cid, $warehouseId, $itemId]);
            $name = sprintf('mf_stk_%d_%d_%d_%d', $wid, $cid, $warehouseId, $itemId);
            $st = $db->query('SELECT GET_LOCK(' . $db->quote($name) . ',30)');
            if ((int)$st->fetchColumn() !== 1) {
                throw new RuntimeException('mf_stock_lock_unavailable');
            }
        }
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$wid, $cid, $warehouseId], $ids);
        $st = $db->prepare("SELECT item_id FROM erp_o2c_stock_locks WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id IN ($in) ORDER BY item_id FOR UPDATE");
        $st->execute($params);
        $st->fetchAll();
    }

    private static function scaledQty($raw): int
    {
        if (is_float($raw) || is_int($raw)) {
            $raw = number_format((float)$raw, 4, '.', '');
        } else {
            $s = trim((string)$raw);
            if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $s, $m)) {
                $frac = substr(str_pad($m[3] ?? '', 4, '0'), 0, 4);
                $whole = ltrim($m[2], '0');
                if ($whole === '') {
                    $whole = '0';
                }
                $raw = ($m[1] === '-' ? '-' : '').$whole.'.'.$frac;
            }
        }
        if (class_exists('OrderToCashDomain')) {
            return OrderToCashDomain::scaledQty($raw);
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

    private static function onHandUnits(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId): int
    {
        // Locking read so REPEATABLE READ sees latest committed movements after waiters wake.
        $lock = $db->prepare("SELECT id,direction,quantity FROM acc_stock_movements
            WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='posted'
            ORDER BY id FOR UPDATE");
        $lock->execute([$wid, $cid, $warehouseId, $itemId]);
        $units = 0;
        foreach ($lock->fetchAll() as $row) {
            $q = self::scaledQty((string)$row['quantity']);
            $units += ($row['direction'] === 'in') ? $q : -$q;
        }
        return $units;
    }

    private static function reservedUnits(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId): int
    {
        $r = $db->prepare("SELECT id,quantity FROM acc_inventory_reservations
            WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='active'
            ORDER BY id FOR UPDATE");
        $r->execute([$wid, $cid, $warehouseId, $itemId]);
        $units = 0;
        foreach ($r->fetchAll() as $row) {
            $units += self::scaledQty((string)$row['quantity']);
        }
        return $units;
    }

    private static function availableUnits(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId): int
    {
        return self::onHandUnits($db, $wid, $cid, $warehouseId, $itemId)
            - self::reservedUnits($db, $wid, $cid, $warehouseId, $itemId);
    }

    private static function loadIdempotency(PDO $db, int $wid, int $cid, string $sourceType, int $sourceId, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $st = $db->prepare('SELECT request_hash, result_json FROM erp_mf_idempotency
            WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
        $st->execute([$wid, $cid, $sourceType, $sourceId, $key]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function storeIdempotency(PDO $db, int $wid, int $cid, string $sourceType, int $sourceId, string $key, string $hash, array $result): void
    {
        if ($key === '') {
            return;
        }
        $db->prepare('INSERT INTO erp_mf_idempotency (workspace_id,company_id,source_type,source_id,idempotency_key,request_hash,result_json,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())')
            ->execute([$wid, $cid, $sourceType, $sourceId, $key, $hash, json_encode($result, JSON_UNESCAPED_UNICODE)]);
    }

    private static function tempReserve(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertScope($wid, $cid, $uid);
        $orderId = (int)($args['production_order_id'] ?? $args['source_id'] ?? 0);
        $warehouseId = (int)($args['warehouse_id'] ?? 0);
        $lines = (array)($args['lines'] ?? []);
        if ($orderId < 1 || $warehouseId < 1 || !$lines) {
            throw new RuntimeException('mf_reserve_args_invalid');
        }
        $idem = self::idempotencyKey($args);
        $normLines = [];
        foreach ($lines as $raw) {
            $itemId = (int)($raw['item_id'] ?? 0);
            $qtyUnits = self::scaledQty($raw['quantity'] ?? '0');
            if ($itemId < 1 || $qtyUnits <= 0) {
                throw new RuntimeException('mf_reserve_line_invalid');
            }
            $normLines[] = ['item_id' => $itemId, 'quantity' => self::fixedQty($qtyUnits)];
        }
        $hash = self::payloadHash(['warehouse_id' => $warehouseId, 'lines' => $normLines]);
        $db = pdo();
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->beginTransaction();
        }
        try {
            $db->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? FOR UPDATE')->execute([$wid, $cid]);
            $prior = self::loadIdempotency($db, $wid, $cid, 'mf_reserve', $orderId, $idem);
            if ($prior) {
                if ($prior['request_hash'] !== $hash) {
                    throw new RuntimeException('mf_idempotency_payload_mismatch');
                }
                $out = json_decode((string)$prior['result_json'], true) ?: [];
                if ($owns) {
                    $db->commit();
                }
                return $out;
            }
            $itemIds = array_column($normLines, 'item_id');
            self::lockTargets($db, $wid, $cid, $warehouseId, $itemIds);
            $reservationIds = [];
            foreach ($normLines as $line) {
                $need = self::scaledQty($line['quantity']);
                $avail = self::availableUnits($db, $wid, $cid, $warehouseId, (int)$line['item_id']);
                if ($avail < $need) {
                    throw new RuntimeException('mf_insufficient_stock_for_reserve');
                }
                $db->prepare("INSERT INTO acc_inventory_reservations
                    (workspace_id,company_id,warehouse_id,item_id,source_type,source_id,quantity,status,notes,created_by,created_at,updated_at)
                    VALUES (?,?,?,?, 'production_order', ?,?,'active','mf_reserve',?,NOW(),NOW())")
                    ->execute([$wid, $cid, $warehouseId, (int)$line['item_id'], $orderId, $line['quantity'], $uid]);
                $reservationIds[] = (int)$db->lastInsertId();
            }
            $out = [
                'ok' => true,
                'mode' => self::MODE_TEMPORARY,
                'production_order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'reservation_ids' => $reservationIds,
                'lines' => $normLines,
            ];
            if ($idem !== '') {
                self::storeIdempotency($db, $wid, $cid, 'mf_reserve', $orderId, $idem, $hash, $out);
            }
            if ($owns) {
                $db->commit();
            }
            return $out;
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function tempIssue(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertScope($wid, $cid, $uid);
        $orderId = (int)($args['production_order_id'] ?? $args['source_id'] ?? 0);
        $warehouseId = (int)($args['warehouse_id'] ?? 0);
        $issueDocId = (int)($args['issue_id'] ?? $args['source_document_id'] ?? 0);
        $lines = (array)($args['lines'] ?? []);
        $movementDate = trim((string)($args['movement_date'] ?? date('Y-m-d')));
        if ($orderId < 1 || $warehouseId < 1 || !$lines) {
            throw new RuntimeException('mf_issue_args_invalid');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $movementDate)) {
            throw new RuntimeException('mf_issue_date_invalid');
        }
        $idem = self::idempotencyKey($args);
        $normLines = [];
        foreach ($lines as $i => $raw) {
            $itemId = (int)($raw['item_id'] ?? 0);
            $lineId = (int)($raw['source_line_id'] ?? ($i + 1));
            $qtyUnits = self::scaledQty($raw['quantity'] ?? '0');
            $unitCost = (string)($raw['unit_cost'] ?? '0');
            if ($itemId < 1 || $qtyUnits <= 0) {
                throw new RuntimeException('mf_issue_line_invalid');
            }
            $normLines[] = [
                'item_id' => $itemId,
                'source_line_id' => $lineId,
                'quantity' => self::fixedQty($qtyUnits),
                'unit_cost' => $unitCost,
            ];
        }
        $hash = self::payloadHash(['warehouse_id' => $warehouseId, 'issue_id' => $issueDocId, 'lines' => $normLines]);
        $db = pdo();
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->beginTransaction();
        }
        try {
            $db->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? FOR UPDATE')->execute([$wid, $cid]);
            $prior = self::loadIdempotency($db, $wid, $cid, 'mf_issue', $orderId, $idem);
            if ($prior) {
                if ($prior['request_hash'] !== $hash) {
                    throw new RuntimeException('mf_idempotency_payload_mismatch');
                }
                $out = json_decode((string)$prior['result_json'], true) ?: [];
                if ($owns) {
                    $db->commit();
                }
                return $out;
            }
            $itemIds = array_column($normLines, 'item_id');
            self::lockTargets($db, $wid, $cid, $warehouseId, $itemIds);
            // Consume own production reservations first, then validate remaining available.
            foreach ($normLines as $line) {
                $need = self::scaledQty($line['quantity']);
                $rs = $db->prepare("SELECT id,quantity FROM acc_inventory_reservations
                    WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND source_type='production_order'
                      AND source_id=? AND status='active' ORDER BY id FOR UPDATE");
                $rs->execute([$wid, $cid, $warehouseId, (int)$line['item_id'], $orderId]);
                foreach ($rs->fetchAll() as $res) {
                    if ($need <= 0) {
                        break;
                    }
                    $have = self::scaledQty((string)$res['quantity']);
                    $take = min($have, $need);
                    $left = $have - $take;
                    if ($left <= 0) {
                        $db->prepare("UPDATE acc_inventory_reservations SET status='consumed',quantity=0,updated_at=NOW() WHERE id=? AND workspace_id=?")
                            ->execute([(int)$res['id'], $wid]);
                    } else {
                        $db->prepare('UPDATE acc_inventory_reservations SET quantity=?,updated_at=NOW() WHERE id=? AND workspace_id=?')
                            ->execute([self::fixedQty($left), (int)$res['id'], $wid]);
                    }
                    $need -= $take;
                }
                $onHand = self::onHandUnits($db, $wid, $cid, $warehouseId, (int)$line['item_id']);
                $reserved = self::reservedUnits($db, $wid, $cid, $warehouseId, (int)$line['item_id']);
                $issueQty = self::scaledQty($line['quantity']);
                if ($onHand < $issueQty || ($onHand - $issueQty) < $reserved) {
                    throw new RuntimeException('mf_insufficient_stock');
                }
            }
            $movementIds = [];
            $ref = 'MF-ISSUE-'.$orderId.'-'.($issueDocId ?: 'X');
            $srcId = $issueDocId > 0 ? $issueDocId : $orderId;
            foreach ($normLines as $line) {
                $db->prepare("INSERT INTO acc_stock_movements
                    (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
                    VALUES (?,?,?,'production_issue','out',?,?,?,?, 'production_order',?,?,?,'posted',?,NOW())")
                    ->execute([
                        $wid, $cid, $movementDate, $warehouseId, (int)$line['item_id'], $line['quantity'], $line['unit_cost'],
                        $srcId, (int)$line['source_line_id'], $ref, $uid,
                    ]);
                $movementIds[] = (int)$db->lastInsertId();
            }
            $out = [
                'ok' => true,
                'mode' => self::MODE_TEMPORARY,
                'production_order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'movement_ids' => $movementIds,
                'movement_type' => 'production_issue',
                'lines' => $normLines,
            ];
            if ($idem !== '') {
                self::storeIdempotency($db, $wid, $cid, 'mf_issue', $orderId, $idem, $hash, $out);
            }
            if ($owns) {
                $db->commit();
            }
            return $out;
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function tempReceiveFg(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertScope($wid, $cid, $uid);
        $orderId = (int)($args['production_order_id'] ?? $args['source_id'] ?? 0);
        $warehouseId = (int)($args['warehouse_id'] ?? 0);
        $receiptId = (int)($args['fg_receipt_id'] ?? $args['source_document_id'] ?? 0);
        $itemId = (int)($args['item_id'] ?? 0);
        $sourceLineId = (int)($args['source_line_id'] ?? 1);
        $qtyUnits = self::scaledQty($args['quantity'] ?? '0');
        $unitCost = (string)($args['unit_cost'] ?? '0');
        $movementDate = trim((string)($args['movement_date'] ?? date('Y-m-d')));
        if ($orderId < 1 || $warehouseId < 1 || $itemId < 1 || $qtyUnits <= 0) {
            throw new RuntimeException('mf_fg_args_invalid');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $movementDate)) {
            throw new RuntimeException('mf_fg_date_invalid');
        }
        $idem = self::idempotencyKey($args);
        $qty = self::fixedQty($qtyUnits);
        $hash = self::payloadHash([
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'quantity' => $qty,
            'fg_receipt_id' => $receiptId,
            'source_line_id' => $sourceLineId,
        ]);
        $db = pdo();
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->beginTransaction();
        }
        try {
            $db->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? FOR UPDATE')->execute([$wid, $cid]);
            $prior = self::loadIdempotency($db, $wid, $cid, 'mf_fg', $orderId, $idem);
            if ($prior) {
                if ($prior['request_hash'] !== $hash) {
                    throw new RuntimeException('mf_idempotency_payload_mismatch');
                }
                $out = json_decode((string)$prior['result_json'], true) ?: [];
                if ($owns) {
                    $db->commit();
                }
                return $out;
            }
            self::lockTargets($db, $wid, $cid, $warehouseId, [$itemId]);
            $srcId = $receiptId > 0 ? $receiptId : $orderId;
            $ref = 'MF-FG-'.$orderId.'-'.($receiptId ?: 'X');
            $db->prepare("INSERT INTO acc_stock_movements
                (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
                VALUES (?,?,?,'production_fg_receipt','in',?,?,?,?, 'production_order',?,?,?,'posted',?,NOW())")
                ->execute([$wid, $cid, $movementDate, $warehouseId, $itemId, $qty, $unitCost, $srcId, $sourceLineId, $ref, $uid]);
            $movementId = (int)$db->lastInsertId();
            $out = [
                'ok' => true,
                'mode' => self::MODE_TEMPORARY,
                'production_order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'movement_id' => $movementId,
                'movement_ids' => [$movementId],
                'movement_type' => 'production_fg_receipt',
                'item_id' => $itemId,
                'quantity' => $qty,
            ];
            if ($idem !== '') {
                self::storeIdempotency($db, $wid, $cid, 'mf_fg', $orderId, $idem, $hash, $out);
            }
            if ($owns) {
                $db->commit();
            }
            return $out;
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function tempReverse(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertScope($wid, $cid, $uid);
        $movementId = (int)($args['movement_id'] ?? 0);
        if ($movementId < 1) {
            throw new RuntimeException('mf_reverse_args_invalid');
        }
        $idem = self::idempotencyKey($args);
        $db = pdo();
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->beginTransaction();
        }
        try {
            $db->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? FOR UPDATE')->execute([$wid, $cid]);
            $st = $db->prepare("SELECT * FROM acc_stock_movements WHERE id=? AND workspace_id=? AND company_id=? AND status='posted' LIMIT 1 FOR UPDATE");
            $st->execute([$movementId, $wid, $cid]);
            $src = $st->fetch();
            if (!$src) {
                throw new RuntimeException('mf_movement_not_found');
            }
            $hash = self::payloadHash(['movement_id' => $movementId]);
            $orderId = (int)$src['source_id'];
            $prior = self::loadIdempotency($db, $wid, $cid, 'mf_reverse', $orderId, $idem);
            if ($prior) {
                if ($prior['request_hash'] !== $hash) {
                    throw new RuntimeException('mf_idempotency_payload_mismatch');
                }
                $out = json_decode((string)$prior['result_json'], true) ?: [];
                if ($owns) {
                    $db->commit();
                }
                return $out;
            }
            self::lockTargets($db, $wid, $cid, (int)$src['warehouse_id'], [(int)$src['item_id']]);
            $revDir = $src['direction'] === 'in' ? 'out' : 'in';
            if ($revDir === 'out') {
                $avail = self::availableUnits($db, $wid, $cid, (int)$src['warehouse_id'], (int)$src['item_id']);
                if ($avail < self::scaledQty((string)$src['quantity'])) {
                    throw new RuntimeException('mf_reverse_insufficient_stock');
                }
            }
            $db->prepare("INSERT INTO acc_stock_movements
                (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
                VALUES (?,?,?,? ,?, ?,?,?,?, ?,?,?,?,'posted',?,NOW())")
                ->execute([
                    $wid, $cid, date('Y-m-d'),
                    $src['movement_type'] === 'production_issue' ? 'production_return' : 'production_fg_receipt_reversal',
                    $revDir, (int)$src['warehouse_id'], (int)$src['item_id'], $src['quantity'], $src['unit_cost'],
                    $src['source_type'], (int)$src['source_id'], -1 * (int)$src['source_line_id'],
                    'REV-'.$movementId, $uid,
                ]);
            $revId = (int)$db->lastInsertId();
            $out = [
                'ok' => true,
                'mode' => self::MODE_TEMPORARY,
                'reversed_movement_id' => $movementId,
                'reversal_movement_id' => $revId,
            ];
            if ($idem !== '') {
                self::storeIdempotency($db, $wid, $cid, 'mf_reverse', $orderId, $idem, $hash, $out);
            }
            if ($owns) {
                $db->commit();
            }
            return $out;
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
