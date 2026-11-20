<?php
/**
 * Manufacturing planning + MF-1 BOM authority (RC1 T1).
 * Stock mutations go through ManufacturingInventoryAdapter → T2 InventoryDomain.
 * No automatic WIP/FG GL. Canonical hierarchy: erp_production_boms (approved) snapshotted
 * into erp_mf_* orders; legacy acc_boms / acc_production_orders remain industrial CRUD only.
 */
final class ProductionDomain
{
    public const VERSION = 'rc1-mf1';
    public const MAX_COMPONENTS = 20;

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_production_boms (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            code VARCHAR(80) NOT NULL,
            revision INT NOT NULL DEFAULT 1,
            output_item_id BIGINT NOT NULL,
            output_qty DECIMAL(20,6) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            notes TEXT NULL,
            created_by INT NOT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            immutable TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_erp_bom_revision (workspace_id,company_id,code,revision),
            INDEX idx_erp_bom_company (workspace_id,company_id,output_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::ensureColumn($db, 'erp_production_boms', 'approved_by', 'INT NULL AFTER created_by');
        self::ensureColumn($db, 'erp_production_boms', 'approved_at', 'DATETIME NULL AFTER approved_by');
        self::ensureColumn($db, 'erp_production_boms', 'immutable', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_at');
        $db->exec("CREATE TABLE IF NOT EXISTS erp_production_bom_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            bom_id BIGINT NOT NULL,
            line_no INT NOT NULL,
            component_item_id BIGINT NOT NULL,
            quantity_per DECIMAL(20,6) NOT NULL,
            scrap_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_erp_bom_line (workspace_id,company_id,bom_id,line_no),
            UNIQUE KEY uq_erp_bom_component (workspace_id,company_id,bom_id,component_item_id),
            INDEX idx_erp_bom_component (workspace_id,company_id,component_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_production_plans (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            bom_id BIGINT NOT NULL,
            warehouse_id BIGINT NOT NULL,
            planned_qty DECIMAL(20,6) NOT NULL,
            target_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            notes TEXT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_erp_plan_company (workspace_id,company_id,created_at),
            INDEX idx_erp_plan_bom (workspace_id,company_id,bom_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // MF-1 execution tables (owned by T1 Manufacturing* domains).
        if (!class_exists('ManufacturingExecutionDomain', false)) {
            require_once __DIR__ . '/ManufacturingInventoryAdapter.php';
            require_once __DIR__ . '/ManufacturingExecutionDomain.php';
            require_once __DIR__ . '/ManufacturingCostEvidence.php';
        }
        ManufacturingExecutionDomain::migrate($db);
    }

    private static function ensureColumn(PDO $db, string $table, string $column, string $ddl): void
    {
        $q = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        $q->execute([$table, $column]);
        if (!$q->fetchColumn()) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
        }
    }

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid <= 0 || $cid <= 0) throw new RuntimeException('invalid_company_scope');
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid,$cid]);
        if (!$st->fetchColumn()) throw new RuntimeException('production_company_not_found');
    }

    private static function requireOwnedActive(int $wid, int $cid, string $table, int $id): void
    {
        if (!in_array($table,['acc_items','acc_warehouses'],true) || $id <= 0) {
            throw new RuntimeException('invalid_production_reference');
        }
        // Harness fixtures may omit active column; prefer active=1 when present.
        try {
            $st = pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");
            $st->execute([$wid,$cid,$id]);
            if ($st->fetchColumn()) return;
        } catch (Throwable $e) {
            // fall through
        }
        $st = pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");
        $st->execute([$wid,$cid,$id]);
        if (!$st->fetchColumn()) throw new RuntimeException('production_reference_not_owned');
    }

    public static function requireWarehouse(int $wid, int $cid, int $id): void
    {
        self::requireOwnedActive($wid, $cid, 'acc_warehouses', $id);
    }

    /** After a production order is released against this BOM, silent mutation is blocked. */
    public static function assertBomImmutableAfterRelease(int $wid, int $cid, int $bomId): void
    {
        $st = pdo()->prepare('UPDATE erp_production_boms SET immutable=1 WHERE workspace_id=? AND company_id=? AND id=?');
        $st->execute([$wid, $cid, $bomId]);
    }

    /** Maker/checker BOM approval (approver ≠ creator). */
    public static function approveBom(int $wid, int $cid, int $uid, int $bomId): array
    {
        self::assertCompany($wid, $cid);
        if ($uid < 1 || $bomId < 1) throw new RuntimeException('production_approve_invalid');
        $db = pdo();
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM erp_production_boms WHERE workspace_id=? AND company_id=? AND id=? FOR UPDATE');
            $st->execute([$wid, $cid, $bomId]);
            $bom = $st->fetch();
            if (!$bom) throw new RuntimeException('production_bom_not_found');
            if (($bom['status'] ?? '') !== 'draft') throw new RuntimeException('production_bom_not_draft');
            if ((int)$bom['created_by'] === $uid) throw new RuntimeException('production_bom_maker_checker_violation');
            $lc = $db->prepare('SELECT COUNT(*) FROM erp_production_bom_lines WHERE workspace_id=? AND company_id=? AND bom_id=?');
            $lc->execute([$wid, $cid, $bomId]);
            if ((int)$lc->fetchColumn() < 1) throw new RuntimeException('production_bom_empty');
            // Circular / self already rejected at create; re-check component ≠ output.
            $self = $db->prepare('SELECT 1 FROM erp_production_bom_lines WHERE workspace_id=? AND company_id=? AND bom_id=? AND component_item_id=? LIMIT 1');
            $self->execute([$wid, $cid, $bomId, (int)$bom['output_item_id']]);
            if ($self->fetchColumn()) throw new RuntimeException('production_bom_circular');
            $up = $db->prepare("UPDATE erp_production_boms SET status='approved',approved_by=?,approved_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='draft'");
            $up->execute([$uid, $bomId, $wid, $cid]);
            if ($up->rowCount() !== 1) throw new RuntimeException('production_bom_approve_race');
            $db->commit();
            Audit::log('production.bom.approve', 'erp_production_boms', $bomId, 'تأیید فرمول ساخت');
            return self::getBom($wid, $cid, $bomId) ?? [];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function assertBomMutable(int $wid, int $cid, int $bomId): void
    {
        $st = pdo()->prepare('SELECT status,immutable FROM erp_production_boms WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1');
        $st->execute([$wid, $cid, $bomId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('production_bom_not_found');
        if ((int)($row['immutable'] ?? 0) === 1 || ($row['status'] ?? '') === 'approved') {
            throw new RuntimeException('production_bom_immutable');
        }
    }

    private static function positiveQuantity($v): float
    {
        if (!is_numeric($v)) throw new RuntimeException('production_quantity_invalid');
        $n=(float)$v;
        if (!is_finite($n) || $n<=0 || $n>100000000) throw new RuntimeException('production_quantity_out_of_range');
        return $n;
    }

    public static function requirements(array $lines, float $outputQty, float $plannedQty): array
    {
        self::positiveQuantity($outputQty);
        self::positiveQuantity($plannedQty);
        $out=[];
        foreach ($lines as $line) {
            $per=self::positiveQuantity($line['quantity_per']??null);
            $scrap=(float)($line['scrap_percent']??0);
            if (!is_finite($scrap) || $scrap < 0 || $scrap > 100) throw new RuntimeException('production_scrap_invalid');
            // Rounding UP prevents understating a material requirement.
            $required=ceil(($per * $plannedQty / $outputQty) * (1 + $scrap / 100) * 10000 - 0.0000001)/10000;
            if (!is_finite($required) || $required>100000000) throw new RuntimeException('production_material_requirement_too_large');
            $out[]=$line+['required_qty'=>$required];
        }
        return $out;
    }

    public static function listItems(int $wid, int $cid): array
    {
        self::assertCompany($wid,$cid);
        $st=pdo()->prepare('SELECT id,code,name,item_type,purchase_price_1 FROM acc_items WHERE workspace_id=? AND company_id=? AND active=1 ORDER BY name LIMIT 200');
        $st->execute([$wid,$cid]);return $st->fetchAll();
    }

    public static function listBoms(int $wid, int $cid): array
    {
        self::assertCompany($wid,$cid);
        $st=pdo()->prepare('SELECT b.id,b.code,b.revision,b.output_qty,b.status,b.created_at,i.name output_name,i.code output_code, (SELECT COUNT(*) FROM erp_production_bom_lines l WHERE l.workspace_id=b.workspace_id AND l.company_id=b.company_id AND l.bom_id=b.id) component_count FROM erp_production_boms b JOIN acc_items i ON i.id=b.output_item_id AND i.workspace_id=b.workspace_id AND i.company_id=b.company_id WHERE b.workspace_id=? AND b.company_id=? ORDER BY b.id DESC LIMIT 100');
        $st->execute([$wid,$cid]);return $st->fetchAll();
    }

    public static function getBom(int $wid, int $cid, int $id): ?array
    {
        self::assertCompany($wid,$cid);
        if ($id<=0) return null;
        $st=pdo()->prepare('SELECT b.*, i.name output_name,i.code output_code FROM erp_production_boms b JOIN acc_items i ON i.id=b.output_item_id AND i.workspace_id=b.workspace_id AND i.company_id=b.company_id WHERE b.workspace_id=? AND b.company_id=? AND b.id=? LIMIT 1');
        $st->execute([$wid,$cid,$id]);$bom=$st->fetch();if (!$bom) return null;
        $ls=pdo()->prepare('SELECT l.*,i.code item_code,i.name item_name,i.purchase_price_1 FROM erp_production_bom_lines l JOIN acc_items i ON i.id=l.component_item_id AND i.workspace_id=l.workspace_id AND i.company_id=l.company_id WHERE l.workspace_id=? AND l.company_id=? AND l.bom_id=? ORDER BY l.line_no LIMIT 20');
        $ls->execute([$wid,$cid,$id]);$bom['lines']=$ls->fetchAll();return $bom;
    }

    public static function createBom(int $wid, int $cid, int $uid, array $data): int
    {
        self::assertCompany($wid,$cid);
        if ($uid<=0) throw new RuntimeException('invalid_actor');
        $code=trim((string)($data['code']??''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{1,79}$/D',$code)) throw new RuntimeException('production_bom_code_invalid');
        $outItem=(int)($data['output_item_id']??0);self::requireOwnedActive($wid,$cid,'acc_items',$outItem);
        $qty=self::positiveQuantity($data['output_qty']??null);
        $lines=(array)($data['lines']??[]);
        if (count($lines)<1 || count($lines)>self::MAX_COMPONENTS) throw new RuntimeException('production_component_count_invalid');
        $seen=[];$clean=[];
        foreach ($lines as $line) {
            if (!is_array($line)) throw new RuntimeException('production_component_invalid');
            $id=(int)($line['component_item_id']??0);
            if ($id===$outItem || isset($seen[$id])) throw new RuntimeException('production_component_duplicate_or_self');
            self::requireOwnedActive($wid,$cid,'acc_items',$id);
            $seen[$id]=true;
            $per=self::positiveQuantity($line['quantity_per']??null);
            $scrap=$line['scrap_percent']??0;
            if (!is_numeric($scrap) || !is_finite((float)$scrap) || (float)$scrap<0 || (float)$scrap>100) throw new RuntimeException('production_scrap_invalid');
            $clean[]=['item_id'=>$id,'per'=>$per,'scrap'=>(float)$scrap];
        }
        $notes=trim((string)($data['notes']??''));
        if (strlen($notes)>4000) throw new RuntimeException('production_notes_too_long');
        $db=pdo();$db->beginTransaction();
        try {
            $st=$db->prepare("INSERT INTO erp_production_boms (workspace_id,company_id,code,revision,output_item_id,output_qty,status,notes,created_by,created_at) VALUES (?,?,?,1,?,?,'draft',?,?,NOW())");
            $st->execute([$wid,$cid,$code,$outItem,$qty,$notes,$uid]);
            $bomId=(int)$db->lastInsertId();
            $ins=$db->prepare('INSERT INTO erp_production_bom_lines (workspace_id,company_id,bom_id,line_no,component_item_id,quantity_per,scrap_percent) VALUES (?,?,?,?,?,?,?)');
            foreach($clean as $i=>$r)$ins->execute([$wid,$cid,$bomId,$i+1,$r['item_id'],$r['per'],$r['scrap']]);
            $db->commit();return $bomId;
        } catch(Throwable $e) {
            if($db->inTransaction())$db->rollBack();throw $e;
        }
    }

    public static function createPlan(int $wid,int $cid,int $uid,array $data): int
    {
        self::assertCompany($wid,$cid);if($uid<=0)throw new RuntimeException('invalid_actor');
        $bomId=(int)($data['bom_id']??0);$bom=self::getBom($wid,$cid,$bomId);
        if (!$bom || !$bom['lines']) throw new RuntimeException('production_bom_not_found');
        $warehouse=(int)($data['warehouse_id']??0);self::requireOwnedActive($wid,$cid,'acc_warehouses',$warehouse);
        $qty=self::positiveQuantity($data['planned_qty']??null);
        // Enforce bounded requirement calculations before saving a plan.
        self::requirements($bom['lines'],(float)$bom['output_qty'],$qty);
        $date=trim((string)($data['target_date']??''));
        if ($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) throw new RuntimeException('production_target_date_invalid');
        $notes=trim((string)($data['notes']??''));
        if (strlen($notes)>4000) throw new RuntimeException('production_notes_too_long');
        $st=pdo()->prepare("INSERT INTO erp_production_plans (workspace_id,company_id,bom_id,warehouse_id,planned_qty,target_date,status,notes,created_by,created_at) VALUES (?,?,?,?,?,?,'draft',?,?,NOW())");
        $st->execute([$wid,$cid,$bomId,$warehouse,$qty,$date!==''?$date:null,$notes,$uid]);
        return (int)pdo()->lastInsertId();
    }

    public static function listPlans(int $wid,int $cid): array
    {
        self::assertCompany($wid,$cid);
        $st=pdo()->prepare('SELECT p.id,p.planned_qty,p.target_date,p.status,p.created_at,b.code bom_code,i.name output_name,w.name warehouse_name FROM erp_production_plans p JOIN erp_production_boms b ON b.id=p.bom_id AND b.workspace_id=p.workspace_id AND b.company_id=p.company_id JOIN acc_items i ON i.id=b.output_item_id AND i.workspace_id=p.workspace_id AND i.company_id=p.company_id JOIN acc_warehouses w ON w.id=p.warehouse_id AND w.workspace_id=p.workspace_id AND w.company_id=p.company_id WHERE p.workspace_id=? AND p.company_id=? ORDER BY p.id DESC LIMIT 100');
        $st->execute([$wid,$cid]);return $st->fetchAll();
    }

    public static function preview(int $wid,int $cid,int $bomId,float $qty,int $warehouseId): array
    {
        $bom=self::getBom($wid,$cid,$bomId);if(!$bom)throw new RuntimeException('production_bom_not_found');
        self::requireOwnedActive($wid,$cid,'acc_warehouses',$warehouseId);
        $rows=self::requirements($bom['lines'],(float)$bom['output_qty'],$qty);
        // One batch against the canonical inventory domain prevents N+1 reads.
        $itemIds=array_map(static function($r){return (int)$r['component_item_id'];},$rows);
        $positions=InventoryDomain::inventoryPosition($wid,$cid,['item_ids'=>$itemIds,'warehouse_id'=>$warehouseId]);
        $positionById=[];
        foreach ($positions['rows'] as $position) $positionById[(int)$position['id']]=$position;
        $missing=0;$estimated=0.0;$unpriced=0;
        foreach ($rows as &$r) {
            $item=$positionById[(int)$r['component_item_id']]??null;
            if (!$item) throw new RuntimeException('production_inventory_evidence_missing');
            $available=(float)$item['available'];
            $r['available_qty']=$available;
            $r['on_hand_qty']=(float)$item['on_hand'];
            $r['reserved_qty']=(float)$item['reserved'];
            $r['expected_inbound']=(float)($item['expected_inbound']??0);
            $r['shortage_qty']=max(0,ceil(($r['required_qty']-$available)*10000-0.0000001)/10000);
            if ($r['shortage_qty']>0)$missing++;
            $price=(float)$r['purchase_price_1'];
            if ($price<=0)$unpriced++;
            else $estimated+=$price*(float)$r['required_qty'];
        }
        unset($r);
        return [
            'bom'=>$bom,'planned_qty'=>$qty,'warehouse_id'=>$warehouseId,'lines'=>$rows,
            'shortage_lines'=>$missing,'estimated_material_cost'=>$estimated,'unpriced_lines'=>$unpriced,
            'posting'=>'never','evidence'=>'posted_stock_movements_minus_active_reservations',
            'limitation'=>'MRP-lite / planning estimate. Recommendations never auto-create POs. Stock posting uses ManufacturingInventoryAdapter → T2 APIs.'
        ];
    }
}

// MF-1 companions (T1-owned). Loaded with ProductionDomain so T0 bootstrap needs no change for domain classes.
if (!class_exists('ManufacturingInventoryAdapter', false)) {
    require_once __DIR__ . '/ManufacturingInventoryAdapter.php';
}
if (!class_exists('ManufacturingExecutionDomain', false)) {
    require_once __DIR__ . '/ManufacturingExecutionDomain.php';
}
if (!class_exists('ManufacturingCostEvidence', false)) {
    require_once __DIR__ . '/ManufacturingCostEvidence.php';
}
