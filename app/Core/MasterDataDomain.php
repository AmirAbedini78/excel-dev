<?php
/**
 * Typed master-data mutations for parties (customer/supplier) and products (items).
 * Soft-archive only (active=0). No physical delete. No LLM SQL.
 * Intended for AiToolRegistry proposal → human approve → execute path.
 */
final class MasterDataDomain
{
    public const VERSION = 'master-data-ops-v1';

    private static function text($value, int $limit = 190): string
    {
        if (is_array($value) || is_object($value)) {
            throw new RuntimeException('مقدار متنی معتبر نیست.');
        }
        $value = trim((string)($value ?? ''));
        if (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') > $limit : strlen($value) > ($limit * 4)) {
            throw new RuntimeException('متن بیش از حد طولانی است.');
        }
        return $value;
    }

    public static function entities(): array
    {
        return [
            'customer' => [
                'table' => 'acc_parties',
                'title' => 'مشتری',
                'party_type' => 'customer',
                'permission' => 'accounting.master.manage',
                'fields' => ['code', 'name', 'national_id', 'economic_code', 'mobile', 'phone', 'credit_limit', 'address'],
            ],
            'supplier' => [
                'table' => 'acc_parties',
                'title' => 'تأمین‌کننده',
                'party_type' => 'supplier',
                'permission' => 'accounting.master.manage',
                'fields' => ['code', 'name', 'national_id', 'economic_code', 'mobile', 'phone', 'credit_limit', 'address'],
            ],
            'product' => [
                'table' => 'acc_items',
                'title' => 'کالا/خدمت',
                'permission' => 'accounting.master.manage',
                'fields' => ['code', 'name', 'item_type', 'barcode', 'taxpayer_goods_id', 'purchase_price_1', 'min_stock', 'max_stock'],
            ],
        ];
    }

    public static function spec(string $entity): array
    {
        $all = self::entities();
        if (!isset($all[$entity])) {
            throw new RuntimeException('موجودیت اطلاعات پایه پشتیبانی نمی‌شود.');
        }
        return $all[$entity];
    }

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت فعال مشخص نیست.');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('شرکت فعال یافت نشد.');
        }
    }

    public static function find(string $entity, int $wid, int $cid, int $id): ?array
    {
        self::assertCompany($wid, $cid);
        $spec = self::spec($entity);
        $table = $spec['table'];
        $st = pdo()->prepare("SELECT * FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");
        $st->execute([$id, $wid, $cid]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        if ($table === 'acc_parties' && isset($spec['party_type'])) {
            $pt = (string)($row['party_type'] ?? '');
            $want = (string)$spec['party_type'];
            if ($pt !== $want && $pt !== 'both') {
                return null;
            }
        }
        return $row;
    }

    public static function search(string $entity, int $wid, int $cid, string $query, int $limit = 30): array
    {
        self::assertCompany($wid, $cid);
        Tenant::requirePermission('accounting.master.view');
        $spec = self::spec($entity);
        $limit = max(1, min(50, $limit));
        $q = self::text($query, 120);
        $like = '%' . $q . '%';
        if ($spec['table'] === 'acc_parties') {
            $want = (string)$spec['party_type'];
            $st = pdo()->prepare(
                "SELECT id,code,name,party_type,national_id,mobile,active FROM acc_parties
                 WHERE workspace_id=? AND company_id=? AND active=1
                   AND (party_type=? OR party_type='both')
                   AND (name LIKE ? OR code LIKE ? OR national_id LIKE ? OR mobile LIKE ?)
                 ORDER BY name LIMIT $limit"
            );
            $st->execute([$wid, $cid, $want, $like, $like, $like, $like]);
            return $st->fetchAll();
        }
        $st = pdo()->prepare(
            "SELECT id,code,name,item_type,barcode,active FROM acc_items
             WHERE workspace_id=? AND company_id=? AND active=1
               AND (name LIKE ? OR code LIKE ? OR barcode LIKE ?)
             ORDER BY name LIMIT $limit"
        );
        $st->execute([$wid, $cid, $like, $like, $like]);
        return $st->fetchAll();
    }

    private static function normalize(string $entity, array $data): array
    {
        $spec = self::spec($entity);
        $out = [];
        foreach ($spec['fields'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $raw = $data[$field];
            if (in_array($field, ['credit_limit', 'purchase_price_1', 'min_stock', 'max_stock'], true)) {
                $out[$field] = (float)$raw;
            } elseif ($field === 'item_type') {
                $v = self::text($raw, 30);
                if (!in_array($v, ['goods', 'service', ''], true)) {
                    throw new RuntimeException('نوع کالا/خدمت نامعتبر است.');
                }
                $out[$field] = $v !== '' ? $v : 'goods';
            } else {
                $out[$field] = self::text($raw, $field === 'address' ? 1000 : 190);
            }
        }
        if ($entity !== 'product' && isset($spec['party_type'])) {
            $out['party_type'] = (string)$spec['party_type'];
        }
        if (!isset($out['name']) || $out['name'] === '') {
            // create requires name; update may omit if not changing
            if (!empty($data['__require_name'])) {
                throw new RuntimeException('نام الزامی است.');
            }
        }
        return $out;
    }

    public static function prepareProposal(int $wid, int $cid, array $args): array
    {
        self::assertCompany($wid, $cid);
        Tenant::requirePermission('accounting.master.manage');
        $entity = self::text($args['entity'] ?? '', 32);
        $operation = strtolower(self::text($args['operation'] ?? '', 20));
        if (!in_array($operation, ['create', 'update', 'archive'], true)) {
            throw new RuntimeException('عملیات اطلاعات پایه پشتیبانی نمی‌شود.');
        }
        $spec = self::spec($entity);
        $out = [
            'domain' => 'master',
            'entity' => $entity,
            'entity_title' => (string)$spec['title'],
            'operation' => $operation,
            'note' => self::text($args['note'] ?? '', 1000),
            'data' => [],
            'physical_delete' => false,
        ];
        if ($operation === 'create') {
            $data = $args['data'] ?? null;
            if (!is_array($data) || !$data) {
                throw new RuntimeException('برای ایجاد، فیلدها لازم است.');
            }
            $data['__require_name'] = true;
            $out['data'] = self::normalize($entity, $data);
            if (($out['data']['name'] ?? '') === '') {
                throw new RuntimeException('نام الزامی است.');
            }
            $out['target_label'] = (string)$spec['title'] . ' — ' . (string)$out['data']['name'];
            return $out;
        }
        $id = (int)($args['record_id'] ?? 0);
        if ($id < 1) {
            $query = self::text($args['target_query'] ?? '', 190);
            $rows = self::search($entity, $wid, $cid, $query, 5);
            if (count($rows) !== 1) {
                throw new RuntimeException(count($rows) ? 'چند رکورد پیدا شد؛ دقیق‌تر جست‌وجو کن.' : 'رکورد پیدا نشد.');
            }
            $id = (int)$rows[0]['id'];
        }
        $row = self::find($entity, $wid, $cid, $id);
        if (!$row || (int)($row['active'] ?? 0) !== 1) {
            throw new RuntimeException('رکورد فعال در دسترس نیست.');
        }
        $out['record_id'] = $id;
        $out['target_label'] = (string)($row['name'] ?? ('#' . $id));
        $out['expected_updated_at'] = (string)($row['updated_at'] ?? '');
        if ($operation === 'update') {
            $data = $args['data'] ?? null;
            if (!is_array($data) || !$data) {
                throw new RuntimeException('برای ویرایش حداقل یک فیلد لازم است.');
            }
            $out['data'] = self::normalize($entity, $data);
            if (!$out['data']) {
                throw new RuntimeException('هیچ فیلد مجازی برای ویرایش ارسال نشده است.');
            }
        }
        return $out;
    }

    public static function validateStoredProposal(int $wid, int $cid, array $args): void
    {
        Tenant::requirePermission('accounting.master.manage');
        $entity = (string)($args['entity'] ?? '');
        $operation = (string)($args['operation'] ?? '');
        self::spec($entity);
        if ($operation === 'create') {
            if (!is_array($args['data'] ?? null) || !$args['data']) {
                throw new RuntimeException('پیش‌نویس ایجاد معتبر نیست.');
            }
            return;
        }
        $row = self::find($entity, $wid, $cid, (int)($args['record_id'] ?? 0));
        if (!$row || (int)($row['active'] ?? 0) !== 1) {
            throw new RuntimeException('رکورد هدف دیگر فعال نیست.');
        }
        if ((string)($args['expected_updated_at'] ?? '') !== (string)($row['updated_at'] ?? '')) {
            throw new RuntimeException('رکورد پس از ساخت پیشنهاد تغییر کرده است.');
        }
    }

    public static function executeProposal(int $wid, int $cid, int $userId, array $args): array
    {
        self::validateStoredProposal($wid, $cid, $args);
        $entity = (string)$args['entity'];
        $operation = (string)$args['operation'];
        $spec = self::spec($entity);
        $table = $spec['table'];
        $db = pdo();
        $owns = !$db->inTransaction();
        if ($owns) {
            $db->beginTransaction();
        }
        try {
            $resultId = (int)($args['record_id'] ?? 0);
            if ($operation === 'create') {
                $data = (array)$args['data'];
                $cols = array_keys($data);
                $cols[] = 'active';
                $data['active'] = 1;
                $marks = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO `$table` (workspace_id,company_id," . implode(',', $cols) . ",created_at,updated_at) VALUES (?,?," . $marks . ",NOW(),NOW())";
                $vals = [$wid, $cid];
                foreach ($cols as $c) {
                    $vals[] = $data[$c];
                }
                $db->prepare($sql)->execute($vals);
                $resultId = (int)$db->lastInsertId();
                Audit::log('ai.master.create', $table, $resultId, 'ایجاد اطلاعات پایه از Proposal', ['entity' => $entity]);
            } elseif ($operation === 'update') {
                $lock = $db->prepare("SELECT * FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");
                $lock->execute([$resultId, $wid, $cid]);
                $current = $lock->fetch();
                if (!$current || (int)($current['active'] ?? 0) !== 1) {
                    throw new RuntimeException('رکورد قفل‌شده در دسترس نیست.');
                }
                if ((string)($args['expected_updated_at'] ?? '') !== (string)($current['updated_at'] ?? '')) {
                    throw new RuntimeException('رکورد هم‌زمان تغییر کرده است.');
                }
                $sets = [];
                $vals = [];
                foreach ((array)$args['data'] as $k => $v) {
                    $sets[] = "`$k`=?";
                    $vals[] = $v;
                }
                $vals[] = $resultId;
                $vals[] = $wid;
                $vals[] = $cid;
                $db->prepare("UPDATE `$table` SET " . implode(',', $sets) . ",updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")
                    ->execute($vals);
                Audit::log('ai.master.update', $table, $resultId, 'ویرایش اطلاعات پایه از Proposal', ['entity' => $entity]);
            } elseif ($operation === 'archive') {
                $db->prepare("UPDATE `$table` SET active=0,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND active=1")
                    ->execute([$resultId, $wid, $cid]);
                Audit::log('ai.master.archive', $table, $resultId, 'بایگانی نرم اطلاعات پایه', ['entity' => $entity]);
            } else {
                throw new RuntimeException('عملیات نامعتبر است.');
            }
            if ($owns) {
                $db->commit();
            }
            return [
                'domain' => 'master',
                'entity' => $entity,
                'id' => $resultId,
                'operation' => $operation,
                'executed' => true,
                'physical_delete' => false,
                'status' => $operation === 'archive' ? 'archived' : 'saved',
            ];
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
