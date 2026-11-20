<?php
/**
 * RC1 T2 — shared warehouse stock posting API for manufacturing (and reusable by trading).
 *
 * Ledger truth remains acc_stock_movements + acc_inventory_reservations.
 * No GL posting. Qty via OrderToCashDomain::scaledQty/fixedQty (scale 4).
 * Lock order: company → lockStockTargets → reservation FOR UPDATE → revalidate → write.
 */
final class WarehouseStockApi
{
    public const VERSION = 'rc1-t2.1';
    public const SOURCE_PRODUCTION_ORDER = 'production_order';
    public const EVENT_SOURCE_TYPE = 'wms_stock_event';

    public static function migrate(PDO $db): void
    {
        // Reuse O2C stock lock targets (phantom-gap safe). Additive; idempotent.
        if (class_exists('OrderToCashDomain', false) || class_exists('OrderToCashDomain')) {
            OrderToCashDomain::migrate($db);
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_stock_locks (
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                warehouse_id BIGINT NOT NULL,
                item_id BIGINT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (workspace_id,company_id,warehouse_id,item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        $db->exec("CREATE TABLE IF NOT EXISTS erp_wms_stock_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            production_order_id BIGINT NULL,
            warehouse_id BIGINT NOT NULL,
            movement_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'posted',
            reversal_of_event_id BIGINT NULL,
            reversed_by_event_id BIGINT NULL,
            notes VARCHAR(1000) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_wms_event_co (workspace_id,company_id,event_type,status),
            INDEX idx_wms_event_po (workspace_id,company_id,production_order_id),
            INDEX idx_wms_event_rev (workspace_id,reversal_of_event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_wms_idempotency (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            source_type VARCHAR(60) NOT NULL,
            source_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            result_json MEDIUMTEXT NOT NULL,
            event_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,source_type,source_id,idempotency_key),
            INDEX idx_wms_idem_event (workspace_id,event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت/محیط کاری انتخاب نشده است.');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('company_not_found');
        }
    }

    private static function assertWarehouseItem(int $wid, int $cid, int $warehouseId, int $itemId): void
    {
        if ($warehouseId < 1 || $itemId < 1) {
            throw new RuntimeException('warehouse_or_item_invalid');
        }
        $w = pdo()->prepare('SELECT 1 FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1');
        $w->execute([$wid, $cid, $warehouseId]);
        if (!$w->fetchColumn()) {
            throw new RuntimeException('inventory_entity_not_owned');
        }
        $i = pdo()->prepare('SELECT 1 FROM acc_items WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1');
        $i->execute([$wid, $cid, $itemId]);
        if (!$i->fetchColumn()) {
            throw new RuntimeException('inventory_entity_not_owned');
        }
    }

    private static function normalizeIdempotencyKey(string $raw): string
    {
        $idem = trim($raw);
        if ($idem === '') {
            return '';
        }
        if (!preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('کلید idempotency نامعتبر است.');
        }
        return $idem;
    }

    private static function payloadHash(array $payload): string
    {
        return OrderToCashDomain::requestHash($payload);
    }

    /**
     * @return array|null existing decoded result when hit
     */
    private static function idempotencyLookup(PDO $db, int $wid, int $cid, string $sourceType, int $sourceId, string $key, string $hash): ?array
    {
        if ($key === '') {
            return null;
        }
        $q = $db->prepare('SELECT payload_hash,result_json,event_id FROM erp_wms_idempotency WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
        $q->execute([$wid, $cid, $sourceType, $sourceId, $key]);
        $ex = $q->fetch();
        if (!$ex) {
            return null;
        }
        if ((string)$ex['payload_hash'] !== $hash) {
            throw new RuntimeException('کلید idempotency با محتوای درخواست متفاوت استفاده شده است.');
        }
        $decoded = json_decode((string)$ex['result_json'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('idempotency_result_corrupt');
        }
        $decoded['existing'] = true;
        return $decoded;
    }

    private static function idempotencyStore(PDO $db, int $wid, int $cid, string $sourceType, int $sourceId, string $key, string $hash, array $result, ?int $eventId): void
    {
        if ($key === '') {
            return;
        }
        $db->prepare('INSERT INTO erp_wms_idempotency (workspace_id,company_id,source_type,source_id,idempotency_key,payload_hash,result_json,event_id,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([$wid, $cid, $sourceType, $sourceId, $key, $hash, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $eventId]);
    }

    private static function onHandUnits(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId): int
    {
        $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN quantity ELSE -quantity END),0) FROM acc_stock_movements WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='posted'");
        $st->execute([$wid, $cid, $warehouseId, $itemId]);
        return OrderToCashDomain::scaledQty((string)$st->fetchColumn());
    }

    private static function reservedOthersUnits(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId, string $ownSourceType, int $ownSourceId): int
    {
        $st = $db->prepare("SELECT id,quantity,source_type,source_id FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='active' FOR UPDATE");
        $st->execute([$wid, $cid, $warehouseId, $itemId]);
        $others = 0;
        foreach ($st->fetchAll() as $r) {
            if ((string)$r['source_type'] === $ownSourceType && (int)$r['source_id'] === $ownSourceId) {
                continue;
            }
            $others += OrderToCashDomain::scaledQty((string)$r['quantity']);
        }
        return $others;
    }

    private static function ownReservedUnits(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId, string $sourceType, int $sourceId): int
    {
        $st = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND source_type=? AND source_id=? AND status='active'");
        $st->execute([$wid, $cid, $warehouseId, $itemId, $sourceType, $sourceId]);
        return OrderToCashDomain::scaledQty((string)$st->fetchColumn());
    }

    private static function consumeReservation(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId, string $sourceType, int $sourceId, int $needUnits): void
    {
        $st = $db->prepare("SELECT id,quantity FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND warehouse_id=? AND item_id=? AND status='active' ORDER BY id FOR UPDATE");
        $st->execute([$wid, $cid, $sourceType, $sourceId, $warehouseId, $itemId]);
        $remaining = $needUnits;
        foreach ($st->fetchAll() as $r) {
            if ($remaining <= 0) {
                break;
            }
            $have = OrderToCashDomain::scaledQty((string)$r['quantity']);
            $take = min($remaining, $have);
            $new = $have - $take;
            $remaining -= $take;
            if ($new <= 0) {
                $db->prepare("UPDATE acc_inventory_reservations SET quantity=0,status='consumed',updated_at=NOW() WHERE id=? AND workspace_id=?")
                    ->execute([(int)$r['id'], $wid]);
            } else {
                $db->prepare('UPDATE acc_inventory_reservations SET quantity=?,updated_at=NOW() WHERE id=? AND workspace_id=?')
                    ->execute([OrderToCashDomain::fixedQty($new), (int)$r['id'], $wid]);
            }
        }
        if ($remaining > 0) {
            throw new RuntimeException('رزرو همزمان تغییر کرده است؛ صدور مواد متوقف شد.');
        }
    }

    private static function restoreReservation(PDO $db, int $wid, int $cid, int $warehouseId, int $itemId, string $sourceType, int $sourceId, int $units, int $userId, string $notes): void
    {
        if ($units <= 0) {
            return;
        }
        $st = $db->prepare("SELECT id,quantity FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND warehouse_id=? AND item_id=? AND status='active' ORDER BY id FOR UPDATE");
        $st->execute([$wid, $cid, $sourceType, $sourceId, $warehouseId, $itemId]);
        $row = $st->fetch();
        if ($row) {
            $new = OrderToCashDomain::scaledQty((string)$row['quantity']) + $units;
            $db->prepare('UPDATE acc_inventory_reservations SET quantity=?,updated_at=NOW() WHERE id=? AND workspace_id=?')
                ->execute([OrderToCashDomain::fixedQty($new), (int)$row['id'], $wid]);
            return;
        }
        // Prefer restoring a consumed row for audit continuity.
        $st = $db->prepare("SELECT id FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND warehouse_id=? AND item_id=? AND status='consumed' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $st->execute([$wid, $cid, $sourceType, $sourceId, $warehouseId, $itemId]);
        $cidRow = $st->fetchColumn();
        if ($cidRow) {
            $db->prepare("UPDATE acc_inventory_reservations SET quantity=?,status='active',updated_at=NOW() WHERE id=? AND workspace_id=?")
                ->execute([OrderToCashDomain::fixedQty($units), (int)$cidRow, $wid]);
            return;
        }
        $db->prepare("INSERT INTO acc_inventory_reservations (workspace_id,company_id,warehouse_id,item_id,source_type,source_id,quantity,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'active',?,?,NOW(),NOW())")
            ->execute([$wid, $cid, $warehouseId, $itemId, $sourceType, $sourceId, OrderToCashDomain::fixedQty($units), $notes ?: 'بازیابی رزرو پس از برگشت صدور', $userId]);
    }

    private static function parseLines(array $rawLines): array
    {
        if (!$rawLines) {
            throw new RuntimeException('حداقل یک ردیف لازم است.');
        }
        $lines = [];
        $byItem = [];
        foreach ($rawLines as $raw) {
            $itemId = (int)($raw['item_id'] ?? 0);
            $qtyUnits = OrderToCashDomain::scaledQty((string)($raw['quantity'] ?? '0'));
            $sourceLineId = max(0, (int)($raw['source_line_id'] ?? 0));
            $unitCost = (float)($raw['unit_cost'] ?? 0);
            if ($itemId < 1 || $qtyUnits <= 0) {
                throw new RuntimeException('ردیف مقدار/کالا نامعتبر است.');
            }
            $qty = OrderToCashDomain::fixedQty($qtyUnits);
            $lines[] = [
                'item_id' => $itemId,
                'quantity' => $qty,
                'quantity_units' => $qtyUnits,
                'source_line_id' => $sourceLineId,
                'unit_cost' => $unitCost,
            ];
            $byItem[$itemId] = ($byItem[$itemId] ?? 0) + $qtyUnits;
        }
        return ['lines' => $lines, 'by_item' => $byItem];
    }

    /**
     * Reserve stock for a production order. source_type=production_order.
     * Replaces active reservations for the same (order, warehouse) with the requested lines.
     * No oversell: need ≤ on_hand − other active reservations.
     *
     * args: production_order_id, warehouse_id, lines[{item_id,quantity,source_line_id?}], notes?, idempotency_key?
     */
    public static function reserveForProduction(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('actor_invalid');
        }
        $pdo = pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $poId = (int)($args['production_order_id'] ?? 0);
            $warehouseId = (int)($args['warehouse_id'] ?? 0);
            if ($poId < 1 || $warehouseId < 1) {
                throw new RuntimeException('production_order_or_warehouse_invalid');
            }
            $lockCo = $pdo->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1 FOR UPDATE');
            $lockCo->execute([$wid, $cid]);
            if (!$lockCo->fetchColumn()) {
                throw new RuntimeException('company_not_found');
            }

            $parsed = self::parseLines((array)($args['lines'] ?? []));
            foreach (array_keys($parsed['by_item']) as $itemId) {
                self::assertWarehouseItem($wid, $cid, $warehouseId, (int)$itemId);
            }

            $idem = self::normalizeIdempotencyKey((string)($args['idempotency_key'] ?? ''));
            $fpLines = [];
            foreach ($parsed['lines'] as $l) {
                $fpLines[] = ['item_id' => $l['item_id'], 'quantity' => $l['quantity'], 'source_line_id' => $l['source_line_id']];
            }
            usort($fpLines, static fn($a, $b) => [$a['item_id'], $a['source_line_id']] <=> [$b['item_id'], $b['source_line_id']]);
            $hash = self::payloadHash([
                'op' => 'reserveForProduction',
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'lines' => $fpLines,
            ]);
            $hit = self::idempotencyLookup($pdo, $wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $idem, $hash);
            if ($hit !== null) {
                if ($owns) {
                    $pdo->commit();
                }
                return $hit;
            }

            OrderToCashDomain::lockStockTargets($pdo, $wid, $cid, $warehouseId, array_keys($parsed['by_item']));

            foreach ($parsed['by_item'] as $itemId => $need) {
                $itemId = (int)$itemId;
                $need = (int)$need;
                $others = self::reservedOthersUnits($pdo, $wid, $cid, $warehouseId, $itemId, self::SOURCE_PRODUCTION_ORDER, $poId);
                $onHand = self::onHandUnits($pdo, $wid, $cid, $warehouseId, $itemId);
                $available = $onHand - $others;
                if ($need > $available) {
                    throw new RuntimeException('موجودی قابل رزرو برای یکی از اقلام کافی نیست.');
                }
            }

            $pdo->prepare("DELETE FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND warehouse_id=? AND status='active'")
                ->execute([$wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $warehouseId]);

            $notes = mb_substr(trim((string)($args['notes'] ?? '')), 0, 500);
            $ins = $pdo->prepare("INSERT INTO acc_inventory_reservations (workspace_id,company_id,warehouse_id,item_id,source_type,source_id,quantity,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'active',?,?,NOW(),NOW())");
            $qtyUnits = 0;
            foreach ($parsed['by_item'] as $itemId => $units) {
                $qty = OrderToCashDomain::fixedQty((int)$units);
                $ins->execute([
                    $wid, $cid, $warehouseId, (int)$itemId, self::SOURCE_PRODUCTION_ORDER, $poId, $qty,
                    $notes !== '' ? $notes : ('رزرو تولید #'.$poId), $uid,
                ]);
                $qtyUnits += (int)$units;
            }

            $result = [
                'entity' => 'acc_inventory_reservations',
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'reserved_quantity' => OrderToCashDomain::fixedQty($qtyUnits),
                'status' => 'active',
                'source_type' => self::SOURCE_PRODUCTION_ORDER,
                'existing' => false,
            ];
            self::idempotencyStore($pdo, $wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $idem, $hash, $result, null);
            if ($owns) {
                $pdo->commit();
            }
            if (class_exists('Audit', false)) {
                Audit::logForWorkspace($wid, 'wms.reserve_production', 'production_order', $poId, 'رزرو تولید', null, $result);
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Issue materials (OUT). movement_type=production_issue.
     * Consumes/reduces production_order reservation. Partial OK. Idempotent.
     *
     * args: production_order_id, warehouse_id, lines[{item_id,quantity,unit_cost?,source_line_id?}],
     *       movement_date?, notes?, idempotency_key (recommended)
     */
    public static function issueMaterial(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('actor_invalid');
        }
        $pdo = pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $poId = (int)($args['production_order_id'] ?? 0);
            $warehouseId = (int)($args['warehouse_id'] ?? 0);
            if ($poId < 1 || $warehouseId < 1) {
                throw new RuntimeException('production_order_or_warehouse_invalid');
            }
            $lockCo = $pdo->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1 FOR UPDATE');
            $lockCo->execute([$wid, $cid]);
            if (!$lockCo->fetchColumn()) {
                throw new RuntimeException('company_not_found');
            }

            $date = class_exists('AccountingRepository', false)
                ? (AccountingRepository::date((string)($args['movement_date'] ?? '')) ?: date('Y-m-d'))
                : (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($args['movement_date'] ?? ''))) ? trim((string)$args['movement_date']) : date('Y-m-d'));

            $parsed = self::parseLines((array)($args['lines'] ?? []));
            foreach (array_keys($parsed['by_item']) as $itemId) {
                self::assertWarehouseItem($wid, $cid, $warehouseId, (int)$itemId);
            }

            $idem = self::normalizeIdempotencyKey((string)($args['idempotency_key'] ?? ''));
            $fpLines = [];
            foreach ($parsed['lines'] as $l) {
                $fpLines[] = ['item_id' => $l['item_id'], 'quantity' => $l['quantity'], 'unit_cost' => number_format((float)$l['unit_cost'], 2, '.', '')];
            }
            usort($fpLines, static fn($a, $b) => $a['item_id'] <=> $b['item_id']);
            $hash = self::payloadHash([
                'op' => 'issueMaterial',
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'movement_date' => $date,
                'lines' => $fpLines,
            ]);
            $hit = self::idempotencyLookup($pdo, $wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $idem, $hash);
            if ($hit !== null) {
                if ($owns) {
                    $pdo->commit();
                }
                return $hit;
            }

            $resLock = $pdo->prepare("SELECT id FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND status='active' FOR UPDATE");
            $resLock->execute([$wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId]);
            $resLock->fetchAll();

            OrderToCashDomain::lockStockTargets($pdo, $wid, $cid, $warehouseId, array_keys($parsed['by_item']));

            foreach ($parsed['by_item'] as $itemId => $need) {
                $itemId = (int)$itemId;
                $need = (int)$need;
                $own = self::ownReservedUnits($pdo, $wid, $cid, $warehouseId, $itemId, self::SOURCE_PRODUCTION_ORDER, $poId);
                if ($need > $own) {
                    throw new RuntimeException('صدور فقط از موجودی رزروشده همین سفارش تولید مجاز است.');
                }
                $onHand = self::onHandUnits($pdo, $wid, $cid, $warehouseId, $itemId);
                if ($need > $onHand) {
                    throw new RuntimeException('موجودی فیزیکی برای صدور کافی نیست.');
                }
                // Reject insufficient *available* after accounting for foreign reservations.
                $others = self::reservedOthersUnits($pdo, $wid, $cid, $warehouseId, $itemId, self::SOURCE_PRODUCTION_ORDER, $poId);
                // Own reservation covers this issue; available to others would be on_hand - others - own.
                // Issuing from own reservation must leave on_hand - need >= others (nonnegative free stock for others' holds).
                if ($onHand - $need < $others) {
                    throw new RuntimeException('موجودی قابل استفاده برای صدور کافی نیست.');
                }
            }

            $notes = mb_substr(trim((string)($args['notes'] ?? '')), 0, 1000);
            $pdo->prepare("INSERT INTO erp_wms_stock_events (workspace_id,company_id,event_type,production_order_id,warehouse_id,movement_date,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,'posted',?,?,NOW(),NOW())")
                ->execute([$wid, $cid, 'production_issue', $poId, $warehouseId, $date, $notes !== '' ? $notes : null, $uid]);
            $eventId = (int)$pdo->lastInsertId();

            $move = $pdo->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at) VALUES (?,?,?,'production_issue','out',?,?,?,?,?,?,?,?,'posted',?,NOW())");
            $lineNo = 1;
            $qtyTotal = 0;
            $movementIds = [];
            $costByItem = [];
            foreach ($parsed['lines'] as $l) {
                $itemId = (int)$l['item_id'];
                $costByItem[$itemId] = (float)($costByItem[$itemId] ?? $l['unit_cost']);
            }
            foreach ($parsed['by_item'] as $itemId => $units) {
                $qty = OrderToCashDomain::fixedQty((int)$units);
                $unitCost = (float)($costByItem[(int)$itemId] ?? 0);
                $ref = 'PI-'.$eventId.'-'.$lineNo;
                $move->execute([$wid, $cid, $date, $warehouseId, (int)$itemId, $qty, $unitCost, self::EVENT_SOURCE_TYPE, $eventId, $lineNo, $ref, $uid]);
                $movementIds[] = (int)$pdo->lastInsertId();
                self::consumeReservation($pdo, $wid, $cid, $warehouseId, (int)$itemId, self::SOURCE_PRODUCTION_ORDER, $poId, (int)$units);
                $qtyTotal += (int)$units;
                $lineNo++;
            }

            $result = [
                'entity' => 'erp_wms_stock_events',
                'id' => $eventId,
                'event_type' => 'production_issue',
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'issued_quantity' => OrderToCashDomain::fixedQty($qtyTotal),
                'movement_ids' => $movementIds,
                'movement_type' => 'production_issue',
                'direction' => 'out',
                'status' => 'posted',
                'existing' => false,
            ];
            self::idempotencyStore($pdo, $wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $idem, $hash, $result, $eventId);
            if ($owns) {
                $pdo->commit();
            }
            if (class_exists('Audit', false)) {
                Audit::logForWorkspace($wid, 'wms.issue_material', 'erp_wms_stock_events', $eventId, 'صدور مواد تولید', null, $result);
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Receive finished goods (IN). movement_type=production_fg_receipt. Partial OK. Idempotent.
     *
     * args: production_order_id, warehouse_id, lines[{item_id,quantity,unit_cost?}],
     *       movement_date?, notes?, idempotency_key?
     */
    public static function receiveFinishedGoods(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('actor_invalid');
        }
        $pdo = pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $poId = (int)($args['production_order_id'] ?? 0);
            $warehouseId = (int)($args['warehouse_id'] ?? 0);
            if ($poId < 1 || $warehouseId < 1) {
                throw new RuntimeException('production_order_or_warehouse_invalid');
            }
            $lockCo = $pdo->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1 FOR UPDATE');
            $lockCo->execute([$wid, $cid]);
            if (!$lockCo->fetchColumn()) {
                throw new RuntimeException('company_not_found');
            }

            $date = class_exists('AccountingRepository', false)
                ? (AccountingRepository::date((string)($args['movement_date'] ?? '')) ?: date('Y-m-d'))
                : (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($args['movement_date'] ?? ''))) ? trim((string)$args['movement_date']) : date('Y-m-d'));

            $parsed = self::parseLines((array)($args['lines'] ?? []));
            foreach (array_keys($parsed['by_item']) as $itemId) {
                self::assertWarehouseItem($wid, $cid, $warehouseId, (int)$itemId);
            }

            $idem = self::normalizeIdempotencyKey((string)($args['idempotency_key'] ?? ''));
            $fpLines = [];
            foreach ($parsed['lines'] as $l) {
                $fpLines[] = ['item_id' => $l['item_id'], 'quantity' => $l['quantity'], 'unit_cost' => number_format((float)$l['unit_cost'], 2, '.', '')];
            }
            usort($fpLines, static fn($a, $b) => $a['item_id'] <=> $b['item_id']);
            $hash = self::payloadHash([
                'op' => 'receiveFinishedGoods',
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'movement_date' => $date,
                'lines' => $fpLines,
            ]);
            $hit = self::idempotencyLookup($pdo, $wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $idem, $hash);
            if ($hit !== null) {
                if ($owns) {
                    $pdo->commit();
                }
                return $hit;
            }

            OrderToCashDomain::lockStockTargets($pdo, $wid, $cid, $warehouseId, array_keys($parsed['by_item']));

            $notes = mb_substr(trim((string)($args['notes'] ?? '')), 0, 1000);
            $pdo->prepare("INSERT INTO erp_wms_stock_events (workspace_id,company_id,event_type,production_order_id,warehouse_id,movement_date,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,'posted',?,?,NOW(),NOW())")
                ->execute([$wid, $cid, 'production_fg_receipt', $poId, $warehouseId, $date, $notes !== '' ? $notes : null, $uid]);
            $eventId = (int)$pdo->lastInsertId();

            $move = $pdo->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at) VALUES (?,?,?,'production_fg_receipt','in',?,?,?,?,?,?,?,?,'posted',?,NOW())");
            $lineNo = 1;
            $qtyTotal = 0;
            $movementIds = [];
            $costByItem = [];
            foreach ($parsed['lines'] as $l) {
                $costByItem[(int)$l['item_id']] = (float)$l['unit_cost'];
            }
            foreach ($parsed['by_item'] as $itemId => $units) {
                $qty = OrderToCashDomain::fixedQty((int)$units);
                $unitCost = (float)($costByItem[(int)$itemId] ?? 0);
                $ref = 'FG-'.$eventId.'-'.$lineNo;
                $move->execute([$wid, $cid, $date, $warehouseId, (int)$itemId, $qty, $unitCost, self::EVENT_SOURCE_TYPE, $eventId, $lineNo, $ref, $uid]);
                $movementIds[] = (int)$pdo->lastInsertId();
                $qtyTotal += (int)$units;
                $lineNo++;
            }

            $result = [
                'entity' => 'erp_wms_stock_events',
                'id' => $eventId,
                'event_type' => 'production_fg_receipt',
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'received_quantity' => OrderToCashDomain::fixedQty($qtyTotal),
                'movement_ids' => $movementIds,
                'movement_type' => 'production_fg_receipt',
                'direction' => 'in',
                'status' => 'posted',
                'existing' => false,
            ];
            self::idempotencyStore($pdo, $wid, $cid, self::SOURCE_PRODUCTION_ORDER, $poId, $idem, $hash, $result, $eventId);
            if ($owns) {
                $pdo->commit();
            }
            if (class_exists('Audit', false)) {
                Audit::logForWorkspace($wid, 'wms.fg_receipt', 'erp_wms_stock_events', $eventId, 'رسید کالای ساخته‌شده', null, $result);
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reverse a posted WMS stock event (or resolve movement → event).
     * Inserts opposite-direction posted movements; nonnegative on_hand guard; restores issue reservations.
     *
     * args: event_id? | movement_id?, notes?, idempotency_key?
     */
    public static function reverseMovement(int $wid, int $cid, int $uid, array $args): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('actor_invalid');
        }
        $pdo = pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $lockCo = $pdo->prepare('SELECT id FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1 FOR UPDATE');
            $lockCo->execute([$wid, $cid]);
            if (!$lockCo->fetchColumn()) {
                throw new RuntimeException('company_not_found');
            }

            $eventId = (int)($args['event_id'] ?? 0);
            $movementId = (int)($args['movement_id'] ?? 0);
            if ($eventId < 1 && $movementId > 0) {
                $mq = $pdo->prepare("SELECT source_id FROM acc_stock_movements WHERE id=? AND workspace_id=? AND company_id=? AND source_type=? AND status='posted' LIMIT 1");
                $mq->execute([$movementId, $wid, $cid, self::EVENT_SOURCE_TYPE]);
                $eventId = (int)$mq->fetchColumn();
            }
            if ($eventId < 1) {
                throw new RuntimeException('event_or_movement_required');
            }

            $idem = self::normalizeIdempotencyKey((string)($args['idempotency_key'] ?? ''));
            $hash = self::payloadHash(['op' => 'reverseMovement', 'event_id' => $eventId, 'movement_id' => $movementId]);
            $hit = self::idempotencyLookup($pdo, $wid, $cid, 'wms_reversal', $eventId, $idem, $hash);
            if ($hit !== null) {
                if ($owns) {
                    $pdo->commit();
                }
                return $hit;
            }

            $eq = $pdo->prepare('SELECT * FROM erp_wms_stock_events WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $eq->execute([$eventId, $wid, $cid]);
            $event = $eq->fetch();
            if (!$event) {
                throw new RuntimeException('wms_event_not_found');
            }
            if ((string)$event['status'] === 'reversed') {
                $result = [
                    'entity' => 'erp_wms_stock_events',
                    'id' => (int)$event['reversed_by_event_id'],
                    'reversal_of_event_id' => $eventId,
                    'status' => 'reversed',
                    'existing' => true,
                ];
                if ($owns) {
                    $pdo->commit();
                }
                return $result;
            }
            if ((string)$event['event_type'] === 'movement_reversal') {
                throw new RuntimeException('نمی‌توان سند برگشت را دوباره برگشت زد.');
            }

            $warehouseId = (int)$event['warehouse_id'];
            $poId = (int)($event['production_order_id'] ?? 0);
            $moves = $pdo->prepare("SELECT * FROM acc_stock_movements WHERE workspace_id=? AND company_id=? AND source_type=? AND source_id=? AND status='posted' ORDER BY source_line_id,id FOR UPDATE");
            $moves->execute([$wid, $cid, self::EVENT_SOURCE_TYPE, $eventId]);
            $rows = $moves->fetchAll();
            if (!$rows) {
                throw new RuntimeException('wms_event_movements_missing');
            }

            $itemIds = array_values(array_unique(array_map(static fn($r) => (int)$r['item_id'], $rows)));
            OrderToCashDomain::lockStockTargets($pdo, $wid, $cid, $warehouseId, $itemIds);

            // Nonnegative guard for reversing inbound (FG) or any net stock reduction.
            foreach ($rows as $r) {
                if ((string)$r['direction'] !== 'in') {
                    continue;
                }
                $itemId = (int)$r['item_id'];
                $qty = OrderToCashDomain::scaledQty((string)$r['quantity']);
                $onHand = self::onHandUnits($pdo, $wid, $cid, $warehouseId, $itemId);
                $reserved = 0;
                $rs = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM acc_inventory_reservations WHERE workspace_id=? AND company_id=? AND warehouse_id=? AND item_id=? AND status='active'");
                $rs->execute([$wid, $cid, $warehouseId, $itemId]);
                $reserved = OrderToCashDomain::scaledQty((string)$rs->fetchColumn());
                if ($onHand - $qty < 0) {
                    throw new RuntimeException('برگشت باعث موجودی منفی می‌شود.');
                }
                if ($onHand - $qty < $reserved) {
                    throw new RuntimeException('برگشت باعث نقض رزروهای فعال می‌شود.');
                }
            }

            $date = date('Y-m-d');
            $notes = mb_substr(trim((string)($args['notes'] ?? '')), 0, 1000);
            $pdo->prepare("INSERT INTO erp_wms_stock_events (workspace_id,company_id,event_type,production_order_id,warehouse_id,movement_date,status,reversal_of_event_id,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,'posted',?,?,?,NOW(),NOW())")
                ->execute([$wid, $cid, 'movement_reversal', $poId ?: null, $warehouseId, $date, $eventId, $notes !== '' ? $notes : ('برگشت رویداد #'.$eventId), $uid]);
            $revEventId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'posted',?,NOW())");
            $movementIds = [];
            $lineNo = 1;
            foreach ($rows as $r) {
                $origDir = (string)$r['direction'];
                $revDir = $origDir === 'in' ? 'out' : 'in';
                $qty = (string)$r['quantity'];
                $itemId = (int)$r['item_id'];
                $unitCost = (float)$r['unit_cost'];
                $mType = (string)$r['movement_type'];
                if ($mType === 'production_issue') {
                    $mType = 'production_return';
                } elseif ($mType === 'production_fg_receipt') {
                    $mType = 'production_fg_reversal';
                } else {
                    $mType = 'movement_reversal';
                }
                $ref = 'REV-'.$revEventId.'-'.$lineNo;
                $ins->execute([$wid, $cid, $date, $mType, $revDir, $warehouseId, $itemId, $qty, $unitCost, self::EVENT_SOURCE_TYPE, $revEventId, $lineNo, $ref, $uid]);
                $movementIds[] = (int)$pdo->lastInsertId();

                if ($origDir === 'out' && (string)$r['movement_type'] === 'production_issue' && $poId > 0) {
                    self::restoreReservation(
                        $pdo, $wid, $cid, $warehouseId, $itemId,
                        self::SOURCE_PRODUCTION_ORDER, $poId,
                        OrderToCashDomain::scaledQty($qty), $uid,
                        'بازیابی رزرو پس از برگشت صدور #'.$eventId
                    );
                }
                $lineNo++;
            }

            $pdo->prepare("UPDATE erp_wms_stock_events SET status='reversed',reversed_by_event_id=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='posted'")
                ->execute([$revEventId, $eventId, $wid, $cid]);

            $result = [
                'entity' => 'erp_wms_stock_events',
                'id' => $revEventId,
                'event_type' => 'movement_reversal',
                'reversal_of_event_id' => $eventId,
                'production_order_id' => $poId,
                'warehouse_id' => $warehouseId,
                'movement_ids' => $movementIds,
                'status' => 'posted',
                'existing' => false,
            ];
            self::idempotencyStore($pdo, $wid, $cid, 'wms_reversal', $eventId, $idem, $hash, $result, $revEventId);
            if ($owns) {
                $pdo->commit();
            }
            if (class_exists('Audit', false)) {
                Audit::logForWorkspace($wid, 'wms.reverse_movement', 'erp_wms_stock_events', $revEventId, 'برگشت حرکت انبار', null, $result);
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
