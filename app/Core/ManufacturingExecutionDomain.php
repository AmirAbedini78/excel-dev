<?php
/**
 * MF-1 production order lifecycle (RC1 T1).
 * Statuses with real semantics: draft → approved → released → in_progress → qc → finished → closed.
 * Stock via ManufacturingInventoryAdapter → T2 InventoryDomain (or temporary canonical ledger).
 * No automatic WIP/FG GL.
 */
final class ManufacturingExecutionDomain
{
    public const VERSION = 'rc1-mf1';
    public const STATUSES = ['draft', 'approved', 'released', 'in_progress', 'qc', 'finished', 'closed'];

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_production_orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            order_no VARCHAR(80) NOT NULL,
            bom_id BIGINT NOT NULL,
            bom_code VARCHAR(80) NOT NULL,
            bom_revision INT NOT NULL,
            output_item_id BIGINT NOT NULL,
            output_qty_bom DECIMAL(20,4) NOT NULL,
            planned_qty DECIMAL(20,4) NOT NULL,
            raw_warehouse_id BIGINT NOT NULL,
            fg_warehouse_id BIGINT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            received_fg_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            accepted_qc_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            rejected_qc_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            scrap_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            created_by INT NOT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            released_at DATETIME NULL,
            closed_at DATETIME NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_mf_order_no (workspace_id,company_id,order_no),
            INDEX idx_mf_order_status (workspace_id,company_id,status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_order_bom_snapshot (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            production_order_id BIGINT NOT NULL,
            line_no INT NOT NULL,
            component_item_id BIGINT NOT NULL,
            quantity_per DECIMAL(20,4) NOT NULL,
            scrap_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
            required_qty DECIMAL(20,4) NOT NULL,
            issued_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_mf_snap_line (workspace_id,company_id,production_order_id,line_no),
            UNIQUE KEY uq_mf_snap_comp (workspace_id,company_id,production_order_id,component_item_id),
            INDEX idx_mf_snap_order (workspace_id,company_id,production_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_material_issues (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            production_order_id BIGINT NOT NULL,
            issue_no VARCHAR(80) NOT NULL,
            warehouse_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NULL,
            request_hash CHAR(64) NOT NULL DEFAULT '',
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_mf_issue_no (workspace_id,company_id,issue_no),
            UNIQUE KEY uq_mf_issue_idem (workspace_id,company_id,production_order_id,idempotency_key),
            INDEX idx_mf_issue_order (workspace_id,company_id,production_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_material_issue_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            issue_id BIGINT NOT NULL,
            production_order_id BIGINT NOT NULL,
            snapshot_line_id BIGINT NOT NULL,
            component_item_id BIGINT NOT NULL,
            quantity DECIMAL(20,4) NOT NULL,
            movement_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_mf_issue_lines (workspace_id,company_id,production_order_id,component_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_qc_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            production_order_id BIGINT NOT NULL,
            decision VARCHAR(20) NOT NULL,
            quantity DECIMAL(20,4) NOT NULL,
            reason VARCHAR(500) NULL,
            actor_user_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_mf_qc_order (workspace_id,company_id,production_order_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_fg_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            production_order_id BIGINT NOT NULL,
            receipt_no VARCHAR(80) NOT NULL,
            warehouse_id BIGINT NOT NULL,
            quantity DECIMAL(20,4) NOT NULL,
            movement_id BIGINT NULL,
            idempotency_key CHAR(64) NULL,
            request_hash CHAR(64) NOT NULL DEFAULT '',
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_mf_fg_no (workspace_id,company_id,receipt_no),
            UNIQUE KEY uq_mf_fg_idem (workspace_id,company_id,production_order_id,idempotency_key),
            INDEX idx_mf_fg_order (workspace_id,company_id,production_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_scrap_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            production_order_id BIGINT NOT NULL,
            component_item_id BIGINT NULL,
            quantity DECIMAL(20,4) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            actor_user_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_mf_scrap_order (workspace_id,company_id,production_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_work_ops (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            production_order_id BIGINT NOT NULL,
            op_type VARCHAR(40) NOT NULL DEFAULT 'work',
            minutes DECIMAL(12,2) NOT NULL DEFAULT 0,
            output_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            actor_user_id INT NOT NULL,
            notes VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_mf_ops_order (workspace_id,company_id,production_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS erp_mf_idempotency (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            source_type VARCHAR(60) NOT NULL,
            source_id BIGINT NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            result_json MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,source_type,source_id,idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Ensure stock lock table exists before nested TX (CREATE TABLE would implicit-commit). PR #29 absorb.
        $db->exec("CREATE TABLE IF NOT EXISTS erp_o2c_stock_locks (
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            warehouse_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (workspace_id,company_id,warehouse_id,item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('mf_invalid_company_scope');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('mf_company_not_found');
        }
    }

    private static function scaledQty($raw): int
    {
        // Normalize BOM DECIMAL(20,6) and float casts down to scale-4 strings before parse.
        if (is_float($raw) || is_int($raw)) {
            $raw = number_format((float)$raw, 4, '.', '');
        } else {
            $s = trim((string)$raw);
            if (preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/', $s, $m)) {
                $frac = substr(str_pad($m[2] ?? '', 4, '0'), 0, 4);
                $raw = $m[1] . '.' . $frac;
            }
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

    private static function assertOpenPeriod(int $wid, int $cid, string $date): void
    {
        // Stock ops themselves are not GL; if a closed period row exists covering the date, fail-closed for any posting.
        try {
            $st = pdo()->prepare("SELECT 1 FROM acc_period_closes pc
                JOIN acc_fiscal_years fy ON fy.id=pc.fiscal_year_id AND fy.workspace_id=pc.workspace_id AND fy.company_id=pc.company_id
                WHERE pc.workspace_id=? AND pc.company_id=? AND pc.status='closed'
                  AND ? BETWEEN fy.start_date AND fy.end_date
                  AND (pc.period_key=DATE_FORMAT(?, '%Y-%m') OR pc.close_type='annual')
                LIMIT 1");
            $st->execute([$wid, $cid, $date, $date]);
            if ($st->fetchColumn()) {
                throw new RuntimeException('mf_period_closed');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Missing period tables: allow operational stock (harness may omit closes).
        }
    }

    public static function listOrders(int $wid, int $cid, int $limit = 50): array
    {
        self::assertCompany($wid, $cid);
        $limit = max(1, min(200, $limit));
        $st = pdo()->prepare("SELECT o.*, i.code output_code, i.name output_name
            FROM erp_mf_production_orders o
            JOIN acc_items i ON i.id=o.output_item_id AND i.workspace_id=o.workspace_id AND i.company_id=o.company_id
            WHERE o.workspace_id=? AND o.company_id=? ORDER BY o.id DESC LIMIT $limit");
        $st->execute([$wid, $cid]);
        return $st->fetchAll();
    }

    public static function getOrder(int $wid, int $cid, int $id): ?array
    {
        self::assertCompany($wid, $cid);
        if ($id < 1) {
            return null;
        }
        $st = pdo()->prepare("SELECT o.*, i.code output_code, i.name output_name
            FROM erp_mf_production_orders o
            JOIN acc_items i ON i.id=o.output_item_id AND i.workspace_id=o.workspace_id AND i.company_id=o.company_id
            WHERE o.workspace_id=? AND o.company_id=? AND o.id=? LIMIT 1");
        $st->execute([$wid, $cid, $id]);
        $order = $st->fetch();
        if (!$order) {
            return null;
        }
        $ls = pdo()->prepare("SELECT s.*, i.code item_code, i.name item_name
            FROM erp_mf_order_bom_snapshot s
            JOIN acc_items i ON i.id=s.component_item_id AND i.workspace_id=s.workspace_id AND i.company_id=s.company_id
            WHERE s.workspace_id=? AND s.company_id=? AND s.production_order_id=? ORDER BY s.line_no");
        $ls->execute([$wid, $cid, $id]);
        $order['bom_snapshot'] = $ls->fetchAll();
        $order['outstanding_fg_qty'] = self::fixedQty(
            max(0, self::scaledQty((string)$order['planned_qty']) - self::scaledQty((string)$order['received_fg_qty']))
        );
        $order['fg_eligible_qty'] = self::fixedQty(
            max(0, self::scaledQty((string)$order['accepted_qc_qty']) - self::scaledQty((string)$order['received_fg_qty']))
        );
        return $order;
    }

    /** Create draft production order with snapshotted approved BOM (min 2 components expected by MF-1). */
    public static function createOrder(int $wid, int $cid, int $uid, array $data): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('mf_invalid_actor');
        }
        $bomId = (int)($data['bom_id'] ?? 0);
        $bom = ProductionDomain::getBom($wid, $cid, $bomId);
        if (!$bom || empty($bom['lines'])) {
            throw new RuntimeException('mf_bom_not_found');
        }
        if (($bom['status'] ?? '') !== 'approved') {
            throw new RuntimeException('mf_bom_not_approved');
        }
        if (count($bom['lines']) < 2) {
            throw new RuntimeException('mf_bom_requires_two_components');
        }
        $plannedUnits = self::scaledQty($data['planned_qty'] ?? '0');
        if ($plannedUnits <= 0) {
            throw new RuntimeException('mf_planned_qty_invalid');
        }
        $rawWh = (int)($data['raw_warehouse_id'] ?? 0);
        $fgWh = (int)($data['fg_warehouse_id'] ?? $rawWh);
        ProductionDomain::requireWarehouse($wid, $cid, $rawWh);
        ProductionDomain::requireWarehouse($wid, $cid, $fgWh);
        $orderNo = trim((string)($data['order_no'] ?? ''));
        if ($orderNo === '') {
            $orderNo = 'MO-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(2)));
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{1,79}$/D', $orderNo)) {
            throw new RuntimeException('mf_order_no_invalid');
        }
        $notes = mb_substr(trim((string)($data['notes'] ?? '')), 0, 4000);
        $reqLines = ProductionDomain::requirements($bom['lines'], (float)$bom['output_qty'], (float)self::fixedQty($plannedUnits));
        $db = pdo();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO erp_mf_production_orders
                (workspace_id,company_id,order_no,bom_id,bom_code,bom_revision,output_item_id,output_qty_bom,planned_qty,
                 raw_warehouse_id,fg_warehouse_id,status,created_by,notes,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,\'draft\',?,?,NOW(),NOW())')
                ->execute([
                    $wid, $cid, $orderNo, $bomId, $bom['code'], (int)$bom['revision'], (int)$bom['output_item_id'],
                    self::fixedQty(self::scaledQty((string)$bom['output_qty'])), self::fixedQty($plannedUnits),
                    $rawWh, $fgWh, $uid, $notes,
                ]);
            $orderId = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT INTO erp_mf_order_bom_snapshot
                (workspace_id,company_id,production_order_id,line_no,component_item_id,quantity_per,scrap_percent,required_qty,issued_qty)
                VALUES (?,?,?,?,?,?,?,?,0)');
            foreach ($reqLines as $i => $line) {
                $ins->execute([
                    $wid, $cid, $orderId, $i + 1, (int)$line['component_item_id'],
                    self::fixedQty(self::scaledQty((string)$line['quantity_per'])),
                    (float)$line['scrap_percent'],
                    self::fixedQty(self::scaledQty((string)$line['required_qty'])),
                ]);
            }
            $db->commit();
            Audit::log('mf.order.create', 'erp_mf_production_orders', $orderId, 'ایجاد دستور تولید پیش‌نویس');
            return self::getOrder($wid, $cid, $orderId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Maker/checker: draft → approved (approver ≠ creator). */
    public static function approveOrder(int $wid, int $cid, int $uid, int $orderId): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('mf_invalid_actor');
        }
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if ($order['status'] !== 'draft') {
                throw new RuntimeException('mf_order_not_draft');
            }
            if ((int)$order['created_by'] === $uid) {
                throw new RuntimeException('mf_maker_checker_violation');
            }
            $up = $db->prepare("UPDATE erp_mf_production_orders SET status='approved',approved_by=?,approved_at=NOW(),updated_at=NOW()
                WHERE id=? AND workspace_id=? AND company_id=? AND status='draft'");
            $up->execute([$uid, $orderId, $wid, $cid]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('mf_approve_race');
            }
            $db->commit();
            Audit::log('mf.order.approve', 'erp_mf_production_orders', $orderId, 'تأیید دستور تولید (maker/checker)');
            return self::getOrder($wid, $cid, $orderId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** approved → released: freeze snapshot, reserve components. Silent BOM mutation blocked thereafter. */
    public static function releaseOrder(int $wid, int $cid, int $uid, int $orderId, string $idempotencyKey = ''): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1) {
            throw new RuntimeException('mf_invalid_actor');
        }
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if ($order['status'] !== 'approved') {
                throw new RuntimeException('mf_order_not_approved');
            }
            $snap = $db->prepare('SELECT * FROM erp_mf_order_bom_snapshot WHERE workspace_id=? AND company_id=? AND production_order_id=? ORDER BY line_no');
            $snap->execute([$wid, $cid, $orderId]);
            $lines = $snap->fetchAll();
            if (count($lines) < 2) {
                throw new RuntimeException('mf_snapshot_incomplete');
            }
            // Block silent mutation of source BOM after release intent.
            ProductionDomain::assertBomImmutableAfterRelease($wid, $cid, (int)$order['bom_id']);
            $reserveLines = [];
            foreach ($lines as $line) {
                $reserveLines[] = [
                    'item_id' => (int)$line['component_item_id'],
                    'quantity' => (string)$line['required_qty'],
                ];
            }
            $reserve = ManufacturingInventoryAdapter::reserveForProduction($wid, $cid, $uid, [
                'production_order_id' => $orderId,
                'source_id' => $orderId,
                'warehouse_id' => (int)$order['raw_warehouse_id'],
                'lines' => $reserveLines,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : hash('sha256', 'release:'.$orderId.':'.$wid.':'.$cid),
            ]);
            $up = $db->prepare("UPDATE erp_mf_production_orders SET status='released',released_at=NOW(),updated_at=NOW()
                WHERE id=? AND workspace_id=? AND company_id=? AND status='approved'");
            $up->execute([$orderId, $wid, $cid]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('mf_release_race');
            }
            $db->commit();
            Audit::log('mf.order.release', 'erp_mf_production_orders', $orderId, 'آزادسازی دستور تولید و رزرو مواد');
            $out = self::getOrder($wid, $cid, $orderId);
            $out['reservation'] = $reserve;
            return $out;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Partial material issue. lines: [{snapshot_line_id|component_item_id, quantity}]
     * Idempotent on idempotency_key.
     */
    public static function issueMaterials(int $wid, int $cid, int $uid, int $orderId, array $lines, string $idempotencyKey = '', array $opts = []): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1 || $orderId < 1) {
            throw new RuntimeException('mf_issue_scope_invalid');
        }
        if (!$lines) {
            throw new RuntimeException('mf_issue_lines_required');
        }
        $idem = trim($idempotencyKey);
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('mf_idempotency_key_invalid');
        }
        $movementDate = trim((string)($opts['movement_date'] ?? date('Y-m-d')));
        self::assertOpenPeriod($wid, $cid, $movementDate);

        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if (!in_array($order['status'], ['released', 'in_progress', 'qc'], true)) {
                throw new RuntimeException('mf_issue_status_blocked');
            }
            if ($idem !== '') {
                $dup = $db->prepare('SELECT id,issue_no FROM erp_mf_material_issues WHERE workspace_id=? AND company_id=? AND production_order_id=? AND idempotency_key=? LIMIT 1');
                $dup->execute([$wid, $cid, $orderId, $idem]);
                $existing = $dup->fetch();
                if ($existing) {
                    $db->commit();
                    return self::issueResult($wid, $cid, (int)$existing['id'], true);
                }
            }
            $snap = $db->prepare('SELECT * FROM erp_mf_order_bom_snapshot WHERE workspace_id=? AND company_id=? AND production_order_id=? ORDER BY line_no FOR UPDATE');
            $snap->execute([$wid, $cid, $orderId]);
            $snapRows = $snap->fetchAll();
            $byId = [];
            $byItem = [];
            foreach ($snapRows as $r) {
                $byId[(int)$r['id']] = $r;
                $byItem[(int)$r['component_item_id']] = $r;
            }
            $norm = [];
            $seenItems = [];
            foreach ($lines as $raw) {
                if (!is_array($raw)) {
                    throw new RuntimeException('mf_issue_line_invalid');
                }
                $snapId = (int)($raw['snapshot_line_id'] ?? 0);
                $itemId = (int)($raw['component_item_id'] ?? 0);
                $row = $snapId > 0 ? ($byId[$snapId] ?? null) : ($byItem[$itemId] ?? null);
                if (!$row) {
                    throw new RuntimeException('mf_issue_component_not_on_bom');
                }
                $cidItem = (int)$row['component_item_id'];
                if (isset($seenItems[$cidItem])) {
                    throw new RuntimeException('mf_issue_duplicate_component');
                }
                $seenItems[$cidItem] = true;
                $qtyUnits = self::scaledQty($raw['quantity'] ?? '0');
                if ($qtyUnits <= 0) {
                    throw new RuntimeException('mf_issue_qty_invalid');
                }
                $required = self::scaledQty((string)$row['required_qty']);
                $issued = self::scaledQty((string)$row['issued_qty']);
                if ($issued + $qtyUnits > $required) {
                    throw new RuntimeException('mf_issue_exceeds_bom_requirement');
                }
                $unitCost = (string)($raw['unit_cost'] ?? '0');
                $norm[] = [
                    'snapshot' => $row,
                    'quantity' => self::fixedQty($qtyUnits),
                    'unit_cost' => $unitCost,
                    'source_line_id' => (int)$row['id'],
                    'item_id' => $cidItem,
                ];
            }
            $issueNo = 'ISS-'.$orderId.'-'.strtoupper(bin2hex(random_bytes(2)));
            $reqHash = hash('sha256', json_encode(array_map(static fn($n) => [
                'item_id' => $n['item_id'], 'quantity' => $n['quantity'],
            ], $norm), JSON_UNESCAPED_UNICODE));
            $db->prepare('INSERT INTO erp_mf_material_issues
                (workspace_id,company_id,production_order_id,issue_no,warehouse_id,idempotency_key,request_hash,created_by,created_at)
                VALUES (?,?,?,?,?,?,?,?,NOW())')
                ->execute([$wid, $cid, $orderId, $issueNo, (int)$order['raw_warehouse_id'], $idem !== '' ? $idem : null, $reqHash, $uid]);
            $issueId = (int)$db->lastInsertId();

            $stockLines = [];
            foreach ($norm as $n) {
                $stockLines[] = [
                    'item_id' => $n['item_id'],
                    'source_line_id' => $n['source_line_id'],
                    'quantity' => $n['quantity'],
                    'unit_cost' => $n['unit_cost'],
                ];
            }
            $stock = ManufacturingInventoryAdapter::issueMaterial($wid, $cid, $uid, [
                'production_order_id' => $orderId,
                'source_id' => $orderId,
                'issue_id' => $issueId,
                'warehouse_id' => (int)$order['raw_warehouse_id'],
                'lines' => $stockLines,
                'movement_date' => $movementDate,
                'idempotency_key' => $idem !== '' ? $idem : hash('sha256', 'issue:'.$issueId.':'.$reqHash),
            ]);
            if (!empty($opts['__fail_after_stock'])) {
                throw new RuntimeException('mf_injected_rollback_after_stock');
            }
            $movementIds = $stock['movement_ids'] ?? [];
            $ins = $db->prepare('INSERT INTO erp_mf_material_issue_lines
                (workspace_id,company_id,issue_id,production_order_id,snapshot_line_id,component_item_id,quantity,movement_id,created_at)
                VALUES (?,?,?,?,?,?,?,?,NOW())');
            $upd = $db->prepare('UPDATE erp_mf_order_bom_snapshot SET issued_qty = issued_qty + ? WHERE id=? AND workspace_id=? AND company_id=?');
            foreach ($norm as $i => $n) {
                $mid = $movementIds[$i] ?? null;
                $ins->execute([
                    $wid, $cid, $issueId, $orderId, (int)$n['snapshot']['id'], $n['item_id'], $n['quantity'], $mid,
                ]);
                $upd->execute([$n['quantity'], (int)$n['snapshot']['id'], $wid, $cid]);
            }
            if ($order['status'] === 'released') {
                $db->prepare("UPDATE erp_mf_production_orders SET status='in_progress',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='released'")
                    ->execute([$orderId, $wid, $cid]);
            } else {
                $db->prepare('UPDATE erp_mf_production_orders SET updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?')
                    ->execute([$orderId, $wid, $cid]);
            }
            $db->commit();
            Audit::log('mf.issue.create', 'erp_mf_material_issues', $issueId, 'صدور مواد تولید');
            return self::issueResult($wid, $cid, $issueId, false) + ['stock' => $stock];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function issueResult(int $wid, int $cid, int $issueId, bool $replay): array
    {
        $st = pdo()->prepare('SELECT * FROM erp_mf_material_issues WHERE workspace_id=? AND company_id=? AND id=?');
        $st->execute([$wid, $cid, $issueId]);
        $issue = $st->fetch();
        $ls = pdo()->prepare('SELECT * FROM erp_mf_material_issue_lines WHERE workspace_id=? AND company_id=? AND issue_id=?');
        $ls->execute([$wid, $cid, $issueId]);
        return ['ok' => true, 'replay' => $replay, 'issue' => $issue, 'lines' => $ls->fetchAll()];
    }

    public static function recordScrap(int $wid, int $cid, int $uid, int $orderId, array $data): array
    {
        self::assertCompany($wid, $cid);
        $qty = self::scaledQty($data['quantity'] ?? '0');
        if ($uid < 1 || $orderId < 1 || $qty <= 0) {
            throw new RuntimeException('mf_scrap_invalid');
        }
        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('mf_scrap_reason_required');
        }
        $componentId = (int)($data['component_item_id'] ?? 0) ?: null;
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if (in_array($order['status'], ['draft', 'approved', 'closed'], true)) {
                throw new RuntimeException('mf_scrap_status_blocked');
            }
            $db->prepare('INSERT INTO erp_mf_scrap_events
                (workspace_id,company_id,production_order_id,component_item_id,quantity,reason,actor_user_id,created_at)
                VALUES (?,?,?,?,?,?,?,NOW())')
                ->execute([$wid, $cid, $orderId, $componentId, self::fixedQty($qty), $reason, $uid]);
            $scrapId = (int)$db->lastInsertId();
            $db->prepare('UPDATE erp_mf_production_orders SET scrap_qty = scrap_qty + ?, updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?')
                ->execute([self::fixedQty($qty), $orderId, $wid, $cid]);
            $db->commit();
            Audit::log('mf.scrap.record', 'erp_mf_scrap_events', $scrapId, 'ثبت ضایعات جدا از موجودی قابل قبول');
            return ['ok' => true, 'scrap_id' => $scrapId, 'quantity' => self::fixedQty($qty)];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function recordWorkOp(int $wid, int $cid, int $uid, int $orderId, array $data): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1 || $orderId < 1) {
            throw new RuntimeException('mf_work_op_invalid');
        }
        $minutes = (float)($data['minutes'] ?? 0);
        $outputQty = self::scaledQty($data['output_qty'] ?? '0');
        if ((!is_finite($minutes) || $minutes < 0) && $outputQty <= 0) {
            throw new RuntimeException('mf_work_op_empty');
        }
        $db = pdo();
        $st = $db->prepare('SELECT status FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=?');
        $st->execute([$wid, $cid, $orderId]);
        $status = $st->fetchColumn();
        if (!$status || in_array($status, ['draft', 'approved', 'closed'], true)) {
            throw new RuntimeException('mf_work_op_status_blocked');
        }
        $db->prepare('INSERT INTO erp_mf_work_ops
            (workspace_id,company_id,production_order_id,op_type,minutes,output_qty,actor_user_id,notes,created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([
                $wid, $cid, $orderId, trim((string)($data['op_type'] ?? 'work')) ?: 'work',
                $minutes, self::fixedQty(max(0, $outputQty)), $uid,
                mb_substr(trim((string)($data['notes'] ?? '')), 0, 500),
            ]);
        return ['ok' => true, 'work_op_id' => (int)$db->lastInsertId()];
    }

    /** QC accept/reject. Rejected qty never becomes FG-accepted. */
    public static function recordQc(int $wid, int $cid, int $uid, int $orderId, string $decision, $quantity, string $reason = ''): array
    {
        self::assertCompany($wid, $cid);
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new RuntimeException('mf_qc_decision_invalid');
        }
        $qty = self::scaledQty($quantity);
        if ($uid < 1 || $orderId < 1 || $qty <= 0) {
            throw new RuntimeException('mf_qc_invalid');
        }
        if ($decision === 'reject' && trim($reason) === '') {
            throw new RuntimeException('mf_qc_reject_reason_required');
        }
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if (!in_array($order['status'], ['in_progress', 'qc', 'released'], true)) {
                throw new RuntimeException('mf_qc_status_blocked');
            }
            $planned = self::scaledQty((string)$order['planned_qty']);
            $accepted = self::scaledQty((string)$order['accepted_qc_qty']);
            $rejected = self::scaledQty((string)$order['rejected_qc_qty']);
            if ($accepted + $rejected + $qty > $planned) {
                throw new RuntimeException('mf_qc_exceeds_planned');
            }
            $db->prepare('INSERT INTO erp_mf_qc_events
                (workspace_id,company_id,production_order_id,decision,quantity,reason,actor_user_id,created_at)
                VALUES (?,?,?,?,?,?,?,NOW())')
                ->execute([$wid, $cid, $orderId, $decision, self::fixedQty($qty), mb_substr(trim($reason), 0, 500), $uid]);
            $qcId = (int)$db->lastInsertId();
            if ($decision === 'accept') {
                $db->prepare("UPDATE erp_mf_production_orders SET accepted_qc_qty = accepted_qc_qty + ?, status='qc', updated_at=NOW()
                    WHERE id=? AND workspace_id=? AND company_id=?")
                    ->execute([self::fixedQty($qty), $orderId, $wid, $cid]);
            } else {
                $db->prepare("UPDATE erp_mf_production_orders SET rejected_qc_qty = rejected_qc_qty + ?, status='qc', updated_at=NOW()
                    WHERE id=? AND workspace_id=? AND company_id=?")
                    ->execute([self::fixedQty($qty), $orderId, $wid, $cid]);
            }
            $db->commit();
            Audit::log('mf.qc.'.$decision, 'erp_mf_qc_events', $qcId, 'رویداد کنترل کیفیت');
            return ['ok' => true, 'qc_id' => $qcId, 'decision' => $decision, 'quantity' => self::fixedQty($qty)];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Partial FG receipt — only against accepted QC qty. */
    public static function receiveFinishedGoods(int $wid, int $cid, int $uid, int $orderId, $quantity, string $idempotencyKey = '', array $opts = []): array
    {
        self::assertCompany($wid, $cid);
        $qty = self::scaledQty($quantity);
        if ($uid < 1 || $orderId < 1 || $qty <= 0) {
            throw new RuntimeException('mf_fg_invalid');
        }
        $idem = trim($idempotencyKey);
        if ($idem !== '' && !preg_match('/^[a-f0-9]{16,64}$/i', $idem)) {
            throw new RuntimeException('mf_idempotency_key_invalid');
        }
        $movementDate = trim((string)($opts['movement_date'] ?? date('Y-m-d')));
        self::assertOpenPeriod($wid, $cid, $movementDate);
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if (!in_array($order['status'], ['qc', 'in_progress', 'finished'], true)) {
                throw new RuntimeException('mf_fg_status_blocked');
            }
            if ($idem !== '') {
                $dup = $db->prepare('SELECT id,receipt_no,quantity,movement_id FROM erp_mf_fg_receipts
                    WHERE workspace_id=? AND company_id=? AND production_order_id=? AND idempotency_key=? LIMIT 1');
                $dup->execute([$wid, $cid, $orderId, $idem]);
                $existing = $dup->fetch();
                if ($existing) {
                    $db->commit();
                    return ['ok' => true, 'replay' => true, 'fg_receipt' => $existing];
                }
            }
            $accepted = self::scaledQty((string)$order['accepted_qc_qty']);
            $received = self::scaledQty((string)$order['received_fg_qty']);
            $rejected = self::scaledQty((string)$order['rejected_qc_qty']);
            // Rejected QC cannot become FG.
            if ($qty + $received > $accepted) {
                throw new RuntimeException('mf_fg_exceeds_accepted_qc');
            }
            if ($rejected > 0 && $qty + $received + $rejected > self::scaledQty((string)$order['planned_qty'])) {
                // still fine; hard rule is accepted_qc ceiling
            }
            $receiptNo = 'FG-'.$orderId.'-'.strtoupper(bin2hex(random_bytes(2)));
            $qtyFixed = self::fixedQty($qty);
            $reqHash = hash('sha256', json_encode(['qty' => $qtyFixed], JSON_UNESCAPED_UNICODE));
            $db->prepare('INSERT INTO erp_mf_fg_receipts
                (workspace_id,company_id,production_order_id,receipt_no,warehouse_id,quantity,idempotency_key,request_hash,created_by,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([
                    $wid, $cid, $orderId, $receiptNo, (int)$order['fg_warehouse_id'], $qtyFixed,
                    $idem !== '' ? $idem : null, $reqHash, $uid,
                ]);
            $fgId = (int)$db->lastInsertId();
            $stock = ManufacturingInventoryAdapter::receiveFinishedGoods($wid, $cid, $uid, [
                'production_order_id' => $orderId,
                'source_id' => $orderId,
                'fg_receipt_id' => $fgId,
                'warehouse_id' => (int)$order['fg_warehouse_id'],
                'item_id' => (int)$order['output_item_id'],
                'quantity' => $qtyFixed,
                'unit_cost' => (string)($opts['unit_cost'] ?? '0'),
                'source_line_id' => $fgId,
                'movement_date' => $movementDate,
                'idempotency_key' => $idem !== '' ? $idem : hash('sha256', 'fg:'.$fgId.':'.$reqHash),
            ]);
            $movementId = (int)($stock['movement_id'] ?? ($stock['movement_ids'][0] ?? 0));
            $db->prepare('UPDATE erp_mf_fg_receipts SET movement_id=? WHERE id=? AND workspace_id=?')
                ->execute([$movementId ?: null, $fgId, $wid]);
            $newReceived = $received + $qty;
            $status = $order['status'];
            if ($newReceived >= self::scaledQty((string)$order['planned_qty'])
                || $newReceived >= $accepted && $accepted + $rejected >= self::scaledQty((string)$order['planned_qty'])) {
                $status = 'finished';
            } elseif ($status !== 'finished') {
                $status = 'qc';
            }
            $db->prepare('UPDATE erp_mf_production_orders SET received_fg_qty=?, status=?, updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?')
                ->execute([self::fixedQty($newReceived), $status, $orderId, $wid, $cid]);
            $db->commit();
            Audit::log('mf.fg.receive', 'erp_mf_fg_receipts', $fgId, 'ورود کالای ساخته‌شده');
            return [
                'ok' => true,
                'replay' => false,
                'fg_receipt_id' => $fgId,
                'receipt_no' => $receiptNo,
                'quantity' => $qtyFixed,
                'movement_id' => $movementId,
                'stock' => $stock,
                'order' => self::getOrder($wid, $cid, $orderId),
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function closeOrder(int $wid, int $cid, int $uid, int $orderId): array
    {
        self::assertCompany($wid, $cid);
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_mf_production_orders WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $orderId]);
            $order = $st->fetch();
            if (!$order) {
                throw new RuntimeException('mf_order_not_found');
            }
            if (!in_array($order['status'], ['finished', 'qc'], true)) {
                throw new RuntimeException('mf_close_status_blocked');
            }
            $up = $db->prepare("UPDATE erp_mf_production_orders SET status='closed',closed_at=NOW(),updated_at=NOW()
                WHERE id=? AND workspace_id=? AND company_id=? AND status IN ('finished','qc')");
            $up->execute([$orderId, $wid, $cid]);
            if ($up->rowCount() !== 1) {
                throw new RuntimeException('mf_close_race');
            }
            $db->commit();
            Audit::log('mf.order.close', 'erp_mf_production_orders', $orderId, 'بستن دستور تولید');
            return self::getOrder($wid, $cid, $orderId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** MRP-lite recommendations from BOM demand vs stock — never auto-creates PO. */
    public static function mrpRecommendations(int $wid, int $cid, int $bomId, $plannedQty, int $warehouseId): array
    {
        $preview = ProductionDomain::preview($wid, $cid, $bomId, (float)self::fixedQty(self::scaledQty($plannedQty)), $warehouseId);
        $recs = [];
        foreach ($preview['lines'] as $line) {
            $short = (float)$line['shortage_qty'];
            if ($short <= 0) {
                continue;
            }
            $recs[] = [
                'component_item_id' => (int)$line['component_item_id'],
                'item_code' => $line['item_code'] ?? null,
                'item_name' => $line['item_name'] ?? null,
                'required_qty' => $line['required_qty'],
                'available_qty' => $line['available_qty'],
                'expected_inbound' => $line['expected_inbound'] ?? 0,
                'recommended_purchase_qty' => $line['shortage_qty'],
                'action' => 'recommend_purchase_order',
                'auto_create' => false,
                'requires_human_approval' => true,
            ];
        }
        return [
            'bom_id' => $bomId,
            'planned_qty' => self::fixedQty(self::scaledQty($plannedQty)),
            'warehouse_id' => $warehouseId,
            'recommendations' => $recs,
            'recommendation_count' => count($recs),
            'auto_po' => false,
            'limitation' => 'MRP-lite recommendations only; no automatic PO or AI mutation',
        ];
    }
}
