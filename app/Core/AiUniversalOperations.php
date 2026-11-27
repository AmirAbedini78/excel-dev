<?php
/**
 * Universal AI operations over the existing typed ERP domains.
 * This class deliberately does not execute arbitrary SQL, delete rows, post GL,
 * move stock, pay suppliers, run payroll, or bypass domain services.
 */
final class AiUniversalOperations
{
    public const VERSION = 'universal-erp-ops-v1';

    private static function text($value, int $limit = 190): string
    {
        if (is_array($value) || is_object($value)) {
            throw new RuntimeException('مقدار متنی عملیات معتبر نیست.');
        }
        $value = trim((string)($value ?? ''));
        if (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') > $limit : strlen($value) > ($limit * 4)) {
            throw new RuntimeException('متن عملیات بیش از حد طولانی است.');
        }
        return $value;
    }

    private static function norm(string $value): string
    {
        $value = str_replace(["ي", "ك", "\u{200c}", "\u{200f}", "\u{202a}", "\u{202c}"], ["ی", "ک", " ", "", "", ""], $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function assertCompany(int $wid, int $cid): void
    {
        if ($wid < 1 || $cid < 1) {
            throw new RuntimeException('شرکت فعال برای عملیات دستیار مشخص نیست.');
        }
        $st = pdo()->prepare('SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1');
        $st->execute([$wid, $cid]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('شرکت انتخاب‌شده در Workspace جاری فعال نیست.');
        }
    }

    private static function spec(string $domain, string $entity): array
    {
        if ($domain === 'suite') {
            return ErpSuiteDomain::spec($entity);
        }
        if ($domain === 'enterprise') {
            return EnterpriseDomain::spec($entity);
        }
        throw new RuntimeException('دامنه این عملیات فقط suite یا enterprise است.');
    }

    private static function access(string $domain, string $entity, string $verb, int $wid, int $cid): array
    {
        self::assertCompany($wid, $cid);
        if (!in_array($verb, ['view', 'manage', 'approve'], true)) {
            throw new RuntimeException('سطح دسترسی عملیات نامعتبر است.');
        }
        $spec = self::spec($domain, $entity);
        if ($domain === 'suite') {
            $module = (string)($spec['module'] ?? '');
            if ($module === '' || !ModuleRegistry::enabled($module)) {
                throw new RuntimeException('ماژول عملیاتی مربوطه غیرفعال است.');
            }
            Tenant::requirePermission($module . '.' . $verb);
            return $spec;
        }
        $group = (string)($spec['group'] ?? '');
        if ($group === '' || !ModuleRegistry::enabled('enterprise_' . $group)) {
            throw new RuntimeException('ماژول سازمانی مربوطه غیرفعال است.');
        }
        Tenant::requirePermission(EnterpriseDomain::permission($group, $verb));
        return $spec;
    }

    private static function label(array $row): string
    {
        foreach (['title', 'name', 'full_name', 'subject', 'document_no', 'code', 'case_no', 'receipt_no'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return '#' . (int)($row['id'] ?? 0);
    }

    private static function safeRow(array $row, array $spec, string $domain): array
    {
        $allowed = ['id', 'status', 'revision', 'is_archived', 'active', 'created_at', 'updated_at'];
        if ($domain === 'enterprise') $allowed = array_merge($allowed, ['code', 'title'], array_keys((array)($spec['fields'] ?? [])));
        else $allowed = array_merge($allowed, array_keys((array)($spec['fields'] ?? [])));
        $out = [];
        foreach (array_unique($allowed) as $key) {
            if (array_key_exists($key, $row)) $out[$key] = $row[$key];
        }
        $out['label'] = self::label($row);
        return $out;
    }

    private static function rawRows(string $domain, string $entity, int $wid, int $cid, string $query = '', string $status = '', int $limit = 100): array
    {
        $spec = self::access($domain, $entity, 'view', $wid, $cid);
        $limit = max(1, min(200, $limit));
        if ($domain === 'enterprise') {
            if ($status !== '' && !in_array($status, ['draft', 'submitted', 'approved', 'rejected', 'active', 'closed', 'cancelled'], true)) {
                throw new RuntimeException('فیلتر وضعیت برای رکورد سازمانی معتبر نیست.');
            }
            return EnterpriseDomain::listRecords($entity, $wid, $cid, $query, $status, min(100, $limit));
        }
        return ErpSuiteDomain::searchRecords($entity, $wid, $cid, $query, $status, $limit);
    }

    private static function find(string $domain, string $entity, int $wid, int $cid, int $id): ?array
    {
        self::access($domain, $entity, 'view', $wid, $cid);
        if ($id < 1) return null;
        $row = $domain === 'enterprise'
            ? EnterpriseDomain::find($entity, $wid, $cid, $id)
            : ErpSuiteDomain::find($entity, $wid, $cid, $id);
        if (!$row) return null;
        if (!empty($row['is_archived']) || (array_key_exists('active', $row) && (int)$row['active'] !== 1)) return null;
        return $row;
    }

    private static function resolveTarget(string $domain, string $entity, int $wid, int $cid, string $query): array
    {
        $query = self::text($query, 190);
        if ($query === '') throw new RuntimeException('برای ویرایش یا بایگانی، نام یا کد رکورد لازم است.');
        if (ctype_digit($query) && (int)$query > 0) {
            $row = self::find($domain, $entity, $wid, $cid, (int)$query);
            if ($row) return $row;
        }
        $rows = self::rawRows($domain, $entity, $wid, $cid, $query, '', 100);
        $needle = self::norm($query);
        $exact = [];
        foreach ($rows as $row) {
            foreach (['code', 'title', 'name', 'full_name', 'subject', 'document_no', 'case_no', 'receipt_no'] as $key) {
                if (isset($row[$key]) && self::norm((string)$row[$key]) === $needle) {
                    $exact[(int)$row['id']] = $row;
                }
            }
        }
        if (count($exact) === 1) return reset($exact);
        if (count($exact) > 1) $rows = array_values($exact);
        if (count($rows) === 1) return $rows[0];
        if (!$rows) throw new RuntimeException('رکوردی با این مشخصات در شرکت فعال پیدا نشد.');
        $choices = [];
        foreach (array_slice($rows, 0, 8) as $row) {
            $choices[] = ['label' => self::label($row), 'id' => (int)$row['id'], 'status' => (string)($row['status'] ?? '')];
        }
        throw new RuntimeException('چند رکورد مشابه پیدا شد؛ لطفاً نام یا کد دقیق‌تری بده: ' . json_encode($choices, JSON_UNESCAPED_UNICODE));
    }

    private static function referenceOptions(string $domain, string $ref, int $wid, int $cid): array
    {
        return $domain === 'enterprise'
            ? EnterpriseDomain::referenceOptions($ref, $wid, $cid)
            : ErpSuiteDomain::referenceOptions($ref, $wid, $cid);
    }

    private static function resolveReferenceLabels(string $domain, string $entity, array $data, int $wid, int $cid): array
    {
        $spec = self::spec($domain, $entity);
        foreach ((array)($spec['fields'] ?? []) as $field => $meta) {
            if (($meta['type'] ?? '') !== 'ref' || !array_key_exists($field, $data)) continue;
            $raw = $data[$field];
            if ($raw === null || $raw === '') continue;
            $options = self::referenceOptions($domain, (string)($meta['ref'] ?? ''), $wid, $cid);
            $matches = [];
            if ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int)$raw > 0) {
                foreach ($options as $option) if ((int)$option['id'] === (int)$raw) $matches[] = $option;
            } else {
                $needle = self::norm(self::text($raw, 190));
                foreach ($options as $option) {
                    $candidate = self::norm((string)($option['label'] ?? ''));
                    if ($candidate === $needle) $matches[] = $option;
                }
                if (!$matches) {
                    foreach ($options as $option) {
                        $candidate = self::norm((string)($option['label'] ?? ''));
                        if ($needle !== '' && str_contains($candidate, $needle)) $matches[] = $option;
                    }
                }
            }
            if (count($matches) !== 1) {
                throw new RuntimeException('مرجع فیلد «' . (string)($meta['label'] ?? $field) . '» یکتا یا معتبر نیست؛ شناسه/نام دقیق را مشخص کن.');
            }
            $data[$field] = (int)$matches[0]['id'];
        }
        return $data;
    }

    private static function normalizeData(string $domain, string $entity, array $data, int $wid, int $cid): array
    {
        $spec = self::spec($domain, $entity);
        $allowed = array_keys((array)($spec['fields'] ?? []));
        if ($domain === 'enterprise') $allowed = array_merge(['code', 'title'], $allowed);
        foreach (array_keys($data) as $field) {
            if (!in_array((string)$field, $allowed, true)) {
                throw new RuntimeException('فیلد غیرمجاز برای این رکورد: ' . (string)$field);
            }
        }
        $data = self::resolveReferenceLabels($domain, $entity, $data, $wid, $cid);
        if ($domain === 'enterprise') return EnterpriseDomain::prepare($entity, $wid, $cid, $data);
        return ErpSuiteDomain::prepareForAi($entity, $wid, $cid, $data);
    }

    private static function baseInput(string $domain, string $entity, array $row): array
    {
        $spec = self::spec($domain, $entity);
        $input = [];
        if ($domain === 'enterprise') {
            $input['code'] = $row['code'] ?? null;
            $input['title'] = $row['title'] ?? null;
        }
        foreach ((array)($spec['fields'] ?? []) as $field => $_meta) $input[$field] = $row[$field] ?? null;
        return $input;
    }

    private static function stableHash(array $row): string
    {
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function assertSuiteArchiveSafe(string $entity, int $wid, int $cid, int $id): void
    {
        $dependencies = [
            'employees' => [
                ['erp_leave_requests','employee_id'], ['erp_attendance','employee_id'], ['erp_timesheets','employee_id'],
                ['erp_project_tasks','assignee_employee_id'], ['erp_service_tickets','assignee_employee_id'],
                ['erp_ext_contracts','employee_id'], ['erp_ext_shifts','employee_id'], ['erp_ext_appraisals','employee_id'],
                ['erp_ext_appraisals','reviewer_id'], ['erp_ext_leave_allocations','employee_id'], ['erp_ext_field_visits','technician_id'],
                ['erp_ext_appointments','employee_id'], ['erp_ext_visitors','host_employee_id'], ['erp_ext_signature_requests','signer_id'],
                ['erp_ext_documents','owner_id'], ['erp_ext_knowledge_articles','author_id'], ['erp_ext_fleet','driver_id'],
                ['erp_ext_maintenance','technician_id'], ['erp_ext_work_centers','operator_id'], ['erp_ext_quality_checks','inspector_id'],
            ],
            'tasks' => [['erp_timesheets','task_id']],
            'registers' => [['erp_pos_carts','register_id']],
            'carts' => [['erp_pos_cart_lines','cart_id']],
            'assets' => [['erp_ext_maintenance','asset_id']],
            'tickets' => [['erp_ext_field_visits','ticket_id']],
        ];
        foreach ($dependencies[$entity] ?? [] as [$table, $field]) {
            $exists = pdo()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
            $exists->execute([$table]);
            if (!$exists->fetchColumn()) continue;
            $q = pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND `$field`=? AND is_archived=0 LIMIT 1");
            $q->execute([$wid, $cid, $id]);
            if ($q->fetchColumn()) throw new RuntimeException('این رکورد دارای وابستگی فعال است؛ ابتدا وابستگی‌های آن را تعیین تکلیف کن.');
        }
    }

    public static function catalog(int $wid, int $cid, string $domain = 'all', bool $includeSchema = false): array
    {
        self::assertCompany($wid, $cid);
        if (!in_array($domain, ['all', 'core', 'suite', 'enterprise'], true)) $domain = 'all';
        $entities = [];
        if (in_array($domain, ['all', 'suite'], true)) {
            foreach (ErpSuiteDomain::entities() as $key => $spec) {
                $module = (string)($spec['module'] ?? '');
                if ($module === '' || !ModuleRegistry::enabled($module) || !Tenant::can($module . '.view')) continue;
                $ops = ['catalog', 'search', 'read', 'report', 'navigate'];
                if (Tenant::can($module . '.manage')) {
                    $ops = array_merge($ops, ['create', 'update']);
                    if ($key !== 'projects') $ops[] = 'archive';
                }
                if (!empty($spec['approval']) && Tenant::can($module . '.approve')) $ops[] = 'review';
                $entities[] = [
                    'domain' => 'suite', 'entity' => (string)$key, 'title' => (string)$spec['title'],
                    'module' => $module,
                    'operations' => array_values($ops),
                    'fields' => $includeSchema ? (array)$spec['fields'] : array_keys((array)$spec['fields']),
                ];
            }
        }
        if (in_array($domain, ['all', 'enterprise'], true)) {
            foreach (EnterpriseDomain::records() as $key => $spec) {
                $group = (string)($spec['group'] ?? '');
                if ($group === '' || !ModuleRegistry::enabled('enterprise_' . $group) || !Tenant::can(EnterpriseDomain::permission($group, 'view'))) continue;
                $ops = ['catalog', 'search', 'read', 'report', 'navigate', 'history'];
                if (Tenant::can(EnterpriseDomain::permission($group, 'manage'))) {
                    $ops = array_merge($ops, ['create', 'update', 'archive', 'transition']);
                } elseif (Tenant::can(EnterpriseDomain::permission($group, 'approve'))) {
                    $ops[] = 'transition';
                }
                $entities[] = [
                    'domain' => 'enterprise', 'entity' => (string)$key, 'title' => (string)$spec['title'],
                    'module' => 'enterprise_' . $group, 'group' => $group,
                    'operations' => array_values($ops),
                    'fields' => $includeSchema ? array_merge(['code' => ['label' => 'کد', 'type' => 'code', 'required' => true], 'title' => ['label' => 'عنوان', 'type' => 'text', 'required' => true]], (array)$spec['fields']) : array_merge(['code', 'title'], array_keys((array)$spec['fields'])),
                ];
            }
        }
        if (in_array($domain, ['all', 'core'], true) && class_exists('AiEntityRegistry')) {
            $core = AiEntityRegistry::catalog();
            foreach ((array)$core as $category) {
                foreach ((array)($category['types'] ?? []) as $item) {
                    if (!is_array($item)) continue;
                    $entities[] = [
                        'domain' => 'core', 'entity' => (string)($item['type'] ?? ''),
                        'title' => (string)($item['title'] ?? ''),
                        'module' => (string)($category['key'] ?? ''),
                        'operations' => ['read', 'search', 'navigate'],
                        'fields' => [],
                    ];
                }
            }
        }
        return ['version' => self::VERSION, 'scope' => 'active_company_only', 'count' => count($entities), 'entities' => $entities,
            'limitations' => ['Core financial mutations use dedicated domain tools; no generic SQL or physical deletion.', 'Payroll, statutory tax filing, bank reconciliation, signatures, and physical document storage are not inferred from metadata-only modules.']];
    }

    public static function search(int $wid, int $cid, array $args): array
    {
        $domain = strtolower(self::text($args['domain'] ?? 'suite', 20));
        $entity = self::text($args['entity'] ?? '', 64);
        $query = self::text($args['query'] ?? '', 190);
        $status = self::text($args['status'] ?? '', 32);
        $limit = max(1, min(50, (int)($args['limit'] ?? 20)));
        if ($domain === 'core') {
            self::assertCompany($wid, $cid);
            $result = AiEntityRegistry::searchDetailed($wid, $cid, $query, $entity !== '' ? [$entity] : []);
            return ['domain' => 'core', 'entity' => $entity, 'count' => count((array)($result['results'] ?? [])), 'rows' => array_slice((array)($result['results'] ?? []), 0, $limit), 'scope' => 'active_company_only'];
        }
        if ($entity === '') throw new RuntimeException('نوع رکورد برای جست‌وجو لازم است.');
        $spec = self::access($domain, $entity, 'view', $wid, $cid);
        $rows = self::rawRows($domain, $entity, $wid, $cid, $query, $status, $limit);
        return ['domain' => $domain, 'entity' => $entity, 'count' => count($rows), 'rows' => array_map(static fn($row) => self::safeRow($row, $spec, $domain), $rows),
            'scope' => 'active_company_only', 'sample_limit' => $limit, 'truncated_possible' => count($rows) >= $limit];
    }

    public static function read(int $wid, int $cid, array $args): array
    {
        $domain = strtolower(self::text($args['domain'] ?? 'suite', 20));
        $entity = self::text($args['entity'] ?? '', 64);
        $query = self::text($args['query'] ?? '', 190);
        if ($domain === 'core') {
            $search = AiEntityRegistry::searchDetailed($wid, $cid, $query, $entity !== '' ? [$entity] : []);
            $rows = (array)($search['results'] ?? []);
            if (count($rows) !== 1) throw new RuntimeException(count($rows) ? 'چند موجودیت پیدا شد؛ جست‌وجو را دقیق‌تر کن.' : 'موجودیت پیدا نشد.');
            return AiEntityRegistry::preview($wid, $cid, ['type' => (string)$rows[0]['type'], 'id' => (int)$rows[0]['id']]);
        }
        if ($entity === '') throw new RuntimeException('نوع رکورد برای خواندن لازم است.');
        $row = self::resolveTarget($domain, $entity, $wid, $cid, $query);
        $spec = self::access($domain, $entity, 'view', $wid, $cid);
        $safe = self::safeRow($row, $spec, $domain);
        $safe['navigation'] = self::navigation($wid, $cid, ['domain' => $domain, 'entity' => $entity, 'query' => (string)$row['id']]);
        return $safe;
    }

    public static function report(int $wid, int $cid, array $args): array
    {
        $domain = strtolower(self::text($args['domain'] ?? 'suite', 20));
        $entity = self::text($args['entity'] ?? '', 64);
        if (!in_array($domain, ['suite', 'enterprise'], true) || $entity === '') throw new RuntimeException('گزارش عمومی فقط برای رکوردهای عملیاتی تایپ‌شده فعال است.');
        $spec = self::access($domain, $entity, 'view', $wid, $cid);
        $limit = max(1, min($domain === 'enterprise' ? 100 : 200, (int)($args['limit'] ?? 200)));
        $rows = self::rawRows($domain, $entity, $wid, $cid, self::text($args['query'] ?? '', 190), self::text($args['status'] ?? '', 32), $limit);
        $dateField = self::text($args['date_field'] ?? '', 64);
        $dateFrom = self::text($args['date_from'] ?? '', 20);
        $dateTo = self::text($args['date_to'] ?? '', 20);
        if ($dateFrom !== '') $dateFrom = AccountingRepository::date($dateFrom) ?? $dateFrom;
        if ($dateTo !== '') $dateTo = AccountingRepository::date($dateTo) ?? $dateTo;
        if ($dateField !== '') {
            $fields = (array)($spec['fields'] ?? []);
            if (($fields[$dateField]['type'] ?? '') !== 'date') throw new RuntimeException('فیلد تاریخ انتخاب‌شده برای این گزارش معتبر نیست.');
            if ($dateFrom !== '' && !self::validDate($dateFrom)) throw new RuntimeException('تاریخ شروع گزارش معتبر نیست.');
            if ($dateTo !== '' && !self::validDate($dateTo)) throw new RuntimeException('تاریخ پایان گزارش معتبر نیست.');
            if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) throw new RuntimeException('بازه تاریخ گزارش معکوس است.');
            $rows = array_values(array_filter($rows, static function ($row) use ($dateField, $dateFrom, $dateTo) {
                $value = (string)($row[$dateField] ?? '');
                return $value !== '' && ($dateFrom === '' || $value >= $dateFrom) && ($dateTo === '' || $value <= $dateTo);
            }));
        }
        $groupBy = self::text($args['group_by'] ?? '', 64);
        $sumField = self::text($args['sum_field'] ?? '', 64);
        $fields = (array)($spec['fields'] ?? []);
        if ($groupBy !== '' && $groupBy !== 'status' && !array_key_exists($groupBy, $fields)) throw new RuntimeException('فیلد گروه‌بندی گزارش مجاز نیست.');
        if ($sumField !== '' && !in_array(($fields[$sumField]['type'] ?? ''), ['money', 'qty', 'quantity', 'hours', 'estimate', 'percent', 'int', 'months'], true)) throw new RuntimeException('فیلد جمع گزارش عددی یا مجاز نیست.');
        $groups = [];
        foreach ($rows as $row) {
            $key = $groupBy !== '' ? (string)($row[$groupBy] ?? 'بدون مقدار') : 'all';
            if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'count' => 0, 'sum' => 0.0];
            $groups[$key]['count']++;
            if ($sumField !== '') $groups[$key]['sum'] += (float)($row[$sumField] ?? 0);
        }
        return ['domain' => $domain, 'entity' => $entity, 'sample_rows' => count($rows), 'sample_limit' => $limit,
            'truncated_possible' => count($rows) >= $limit, 'group_by' => $groupBy ?: null, 'sum_field' => $sumField ?: null,
            'groups' => array_values($groups), 'scope' => 'active_company_only',
            'note' => 'این گزارش فقط رکوردهای نمونه‌برداری‌شده در سقف اعلام‌شده را خلاصه می‌کند؛ برای گزارش مالی قطعی از ابزار تخصصی همان دامنه استفاده شود.'];
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $value;
    }

    public static function history(int $wid, int $cid, array $args): array
    {
        $entity = self::text($args['entity'] ?? '', 64);
        $query = self::text($args['query'] ?? '', 190);
        if ($entity === '' || $query === '') {
            throw new RuntimeException('برای تاریخچه، نوع و نام/کد رکورد Enterprise لازم است.');
        }
        self::access('enterprise', $entity, 'view', $wid, $cid);
        $row = self::resolveTarget('enterprise', $entity, $wid, $cid, $query);
        $events = EnterpriseDomain::history($entity, $wid, $cid, (int)$row['id']);
        return [
            'domain' => 'enterprise',
            'entity' => $entity,
            'record_id' => (int)$row['id'],
            'label' => self::label($row),
            'count' => count($events),
            'events' => $events,
            'scope' => 'active_company_only',
            'note' => 'این تاریخچه فقط رویدادهای کنترل‌شده Enterprise است و جایگزین دفترکل یا audit مالی نیست.',
        ];
    }

    public static function navigation(int $wid, int $cid, array $args): array
    {
        $domain = strtolower(self::text($args['domain'] ?? 'suite', 20));
        $entity = self::text($args['entity'] ?? '', 64);
        $query = self::text($args['query'] ?? '', 190);
        if ($domain === 'core') {
            $search = AiEntityRegistry::searchDetailed($wid, $cid, $query, $entity !== '' ? [$entity] : []);
            $rows = (array)($search['results'] ?? []);
            if (count($rows) !== 1) throw new RuntimeException(count($rows) ? 'برای هدایت، رکورد دقیق‌تری لازم است.' : 'رکوردی برای هدایت پیدا نشد.');
            $preview = AiEntityRegistry::preview($wid, $cid, ['type' => (string)$rows[0]['type'], 'id' => (int)$rows[0]['id']]);
            return ['label' => (string)($preview['label'] ?? ''), 'url' => (string)($preview['deep_link'] ?? 'index.php'), 'domain' => 'core'];
        }
        if ($entity === '') throw new RuntimeException('نوع رکورد برای هدایت لازم است.');
        $spec = self::access($domain, $entity, 'view', $wid, $cid);
        $page = '';
        if ($domain === 'suite') $page = (string)($spec['module'] ?? '');
        else $page = (string)(EnterpriseDomain::groups()[(string)$spec['group']]['page'] ?? '');
        if ($page === '') throw new RuntimeException('مسیر صفحه برای این موجودیت تعریف نشده است.');
        $url = 'index.php?' . http_build_query(['page' => $page, 'entity' => $entity]);
        $label = (string)($spec['title'] ?? $entity);
        if ($query !== '') {
            $row = self::resolveTarget($domain, $entity, $wid, $cid, $query);
            $url .= '&edit=' . (int)$row['id'];
            $label = self::label($row);
        }
        return ['domain' => $domain, 'entity' => $entity, 'label' => $label, 'url' => $url, 'scope' => 'active_company_only'];
    }

    public static function prepareProposal(int $wid, ?int $cid, array $args): array
    {
        if (!$cid) throw new RuntimeException('برای عملیات نوشتنی باید شرکت فعال انتخاب شود.');
        self::assertCompany($wid, $cid);
        $domain = strtolower(self::text($args['domain'] ?? '', 20));
        $entity = self::text($args['entity'] ?? '', 64);
        $operation = strtolower(self::text($args['operation'] ?? '', 20));
        if (!in_array($domain, ['suite', 'enterprise'], true)) throw new RuntimeException('عملیات نوشتنی عمومی فقط برای suite و enterprise مجاز است.');
        if (!in_array($operation, ['create', 'update', 'archive', 'transition', 'review'], true)) throw new RuntimeException('نوع عملیات نوشتنی پشتیبانی نمی‌شود.');
        $spec = self::spec($domain, $entity);
        if ($operation === 'transition' && $domain !== 'enterprise') throw new RuntimeException('تغییر وضعیت عمومی فقط برای رکوردهای Enterprise پشتیبانی می‌شود.');
        if ($operation === 'review' && ($domain !== 'suite' || empty($spec['approval']))) throw new RuntimeException('این رکورد گردش تأیید مستقل ندارد.');
        $targetStatus = self::text($args['target_status'] ?? '', 32);
        $verb = ($operation === 'review' || ($operation === 'transition' && in_array($targetStatus, ['approved', 'rejected'], true))) ? 'approve' : 'manage';
        self::access($domain, $entity, $verb, $wid, $cid);
        $out = ['domain' => $domain, 'entity' => $entity, 'entity_title' => (string)($spec['title'] ?? $entity), 'operation' => $operation, 'target_status' => $targetStatus,
            'note' => self::text($args['note'] ?? '', 1000), 'data' => []];

        if ($operation === 'create') {
            $data = $args['data'] ?? null;
            if (!is_array($data) || !$data) throw new RuntimeException('برای ایجاد رکورد، مقادیر فیلدهای لازم را مشخص کن.');
            $out['data'] = self::normalizeData($domain, $entity, $data, $wid, $cid);
            $label = self::label($out['data']);
            $out['target_label'] = $label === '#0' ? ((string)$spec['title'] . ' (پیش‌نویس جدید)') : ((string)$spec['title'] . ' — ' . $label);
            return $out;
        }

        $query = self::text($args['target_query'] ?? '', 190);
        $row = self::resolveTarget($domain, $entity, $wid, $cid, $query);
        $id = (int)$row['id'];
        $out['record_id'] = $id;
        $out['target_label'] = self::label($row);
        if ($domain === 'enterprise') $out['expected_revision'] = (int)($row['revision'] ?? 0);
        else $out['expected_snapshot_hash'] = self::stableHash($row);

        if ($operation === 'update') {
            if ($domain === 'enterprise' && !in_array((string)($row['status'] ?? ''), ['draft', 'active', 'rejected'], true)) throw new RuntimeException('این رکورد در وضعیت فعلی قابل ویرایش نیست.');
            if ($domain === 'suite' && !empty($spec['approval']) && (string)($row['status'] ?? '') !== 'pending') throw new RuntimeException('درخواست بررسی‌شده قابل ویرایش نیست.');
            $data = $args['data'] ?? null;
            if (!is_array($data) || !$data) throw new RuntimeException('برای ویرایش، حداقل یک فیلد جدید لازم است.');
            $out['data'] = self::normalizeData($domain, $entity, array_merge(self::baseInput($domain, $entity, $row), $data), $wid, $cid);
        } elseif ($operation === 'archive') {
            if ($domain === 'enterprise' && !in_array((string)($row['status'] ?? ''), ['draft', 'rejected', 'cancelled', 'closed'], true)) throw new RuntimeException('فقط رکورد بسته/پیش‌نویس قابل بایگانی است.');
            if ($domain === 'suite' && $entity === 'projects') throw new RuntimeException('بایگانی پروژه به دلیل ارجاعات مالی از دستیار مجاز نیست.');
            if ($domain === 'suite') self::assertSuiteArchiveSafe($entity, $wid, $cid, $id);
        } elseif ($operation === 'transition') {
            $allowed = EnterpriseDomain::nextStatusesFor($entity, (string)($row['status'] ?? ''));
            if (!in_array($targetStatus, $allowed, true)) throw new RuntimeException('گذار وضعیت انتخاب‌شده از وضعیت فعلی مجاز نیست.');
            if (in_array($targetStatus, ['approved', 'rejected'], true) && $out['note'] === '' && $targetStatus === 'rejected') throw new RuntimeException('برای رد رکورد، علت تصمیم لازم است.');
        } elseif ($operation === 'review') {
            if (!in_array($targetStatus, ['approved', 'rejected'], true) || (string)($row['status'] ?? '') !== 'pending') throw new RuntimeException('فقط درخواست منتظر بررسی قابل تأیید یا رد است.');
            if ($targetStatus === 'rejected' && $out['note'] === '') throw new RuntimeException('برای رد درخواست، علت تصمیم لازم است.');
        }
        return $out;
    }

    public static function validateStoredProposal(int $wid, ?int $cid, array $args): void
    {
        if (!$cid) throw new RuntimeException('شرکت عملیات مشخص نیست.');
        $domain = (string)($args['domain'] ?? '');
        $entity = (string)($args['entity'] ?? '');
        $operation = (string)($args['operation'] ?? '');
        $targetStatus = (string)($args['target_status'] ?? '');
        $verb = ($operation === 'review' || ($operation === 'transition' && in_array($targetStatus, ['approved', 'rejected'], true))) ? 'approve' : 'manage';
        self::access($domain, $entity, $verb, $wid, $cid);
        if ($operation === 'create') {
            if (!is_array($args['data'] ?? null) || !$args['data']) throw new RuntimeException('پیش‌نویس ایجاد معتبر نیست.');
            self::normalizeData($domain, $entity, (array)$args['data'], $wid, $cid);
            return;
        }
        $row = self::find($domain, $entity, $wid, $cid, (int)($args['record_id'] ?? 0));
        if (!$row) throw new RuntimeException('رکورد هدف دیگر در دسترس نیست.');
        if ($domain === 'enterprise' && (int)($row['revision'] ?? 0) !== (int)($args['expected_revision'] ?? 0)) throw new RuntimeException('نسخه رکورد تغییر کرده است؛ پیشنهاد قدیمی منقضی شد.');
        if ($domain === 'suite' && !hash_equals((string)($args['expected_snapshot_hash'] ?? ''), self::stableHash($row))) throw new RuntimeException('اطلاعات رکورد بعد از ساخت پیشنهاد تغییر کرده است؛ پیشنهاد قدیمی منقضی شد.');
        if ($operation === 'update') self::normalizeData($domain, $entity, (array)($args['data'] ?? []), $wid, $cid);
        if ($operation === 'archive' && $domain === 'suite') self::assertSuiteArchiveSafe($entity, $wid, $cid, (int)$row['id']);
        if ($operation === 'transition' && !in_array($targetStatus, EnterpriseDomain::nextStatusesFor($entity, (string)($row['status'] ?? '')), true)) throw new RuntimeException('گذار وضعیت دیگر مجاز نیست.');
        if ($operation === 'review' && (!in_array($targetStatus, ['approved', 'rejected'], true) || (string)($row['status'] ?? '') !== 'pending')) throw new RuntimeException('درخواست دیگر در وضعیت قابل بررسی نیست.');
    }

    public static function executeProposal(int $wid, int $cid, int $userId, array $args): array
    {
        self::validateStoredProposal($wid, $cid, $args);
        $domain = (string)$args['domain'];
        $entity = (string)$args['entity'];
        $operation = (string)$args['operation'];
        $id = (int)($args['record_id'] ?? 0);
        $data = (array)($args['data'] ?? []);
        $resultId = $id;

        if ($domain === 'enterprise') {
            if ($operation === 'create') $resultId = EnterpriseDomain::save($entity, $wid, $cid, $userId, $data, 0, 0);
            elseif ($operation === 'update') $resultId = EnterpriseDomain::save($entity, $wid, $cid, $userId, $data, $id, (int)$args['expected_revision']);
            elseif ($operation === 'archive') EnterpriseDomain::archive($entity, $wid, $cid, $userId, $id, (int)$args['expected_revision']);
            elseif ($operation === 'transition') EnterpriseDomain::transition($entity, $wid, $cid, $userId, $id, (int)$args['expected_revision'], (string)$args['target_status'], (string)($args['note'] ?? ''));
            else throw new RuntimeException('عملیات Enterprise پشتیبانی نمی‌شود.');
        } else {
            $spec = ErpSuiteDomain::spec($entity);
            $db = pdo();
            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();
            try {
                if (in_array($operation, ['update', 'archive', 'review'], true)) {
                    $table = (string)$spec['table'];
                    $lock = $db->prepare("SELECT * FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");
                    $lock->execute([$id, $wid, $cid]);
                    $current = $lock->fetch();
                    if (!$current || !empty($current['is_archived']) || (array_key_exists('active', (array)$current) && (int)$current['active'] !== 1)) throw new RuntimeException('رکورد هدف در دسترس نیست.');
                    if (!hash_equals((string)($args['expected_snapshot_hash'] ?? ''), self::stableHash($current))) throw new RuntimeException('رکورد هم‌زمان تغییر کرده است؛ دوباره جست‌وجو و پیشنهاد بساز.');
                    if ($operation === 'archive') self::assertSuiteArchiveSafe($entity, $wid, $cid, $id);
                }
                if ($operation === 'create') $resultId = ErpSuiteDomain::save($entity, $wid, $cid, $userId, $data, 0);
                elseif ($operation === 'update') $resultId = ErpSuiteDomain::save($entity, $wid, $cid, $userId, $data, $id);
                elseif ($operation === 'archive') ErpSuiteDomain::archive($entity, $wid, $cid, $id);
                elseif ($operation === 'review') {
                    $module = (string)$spec['module'];
                    Tenant::requirePermission($module . '.approve');
                    if ((int)($current['created_by'] ?? 0) === $userId) throw new RuntimeException('سازنده درخواست نمی‌تواند تأییدکننده همان درخواست باشد.');
                    ErpSuiteDomain::review($entity, $wid, $cid, $id, (string)$args['target_status']);
                    if ((string)($args['note'] ?? '') !== '') Audit::log('ai.erp.review.note', (string)$spec['table'], $id, (string)$args['note']);
                } else throw new RuntimeException('عملیات suite پشتیبانی نمی‌شود.');
                if ($ownsTransaction) $db->commit();
            } catch (Throwable $e) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        $archived = $operation === 'archive';
        $navigation = null;
        $actualStatus = $archived ? 'archived' : (($operation === 'transition' || $operation === 'review') ? (string)$args['target_status'] : 'saved');
        if (!$archived) {
            try {
                $fresh = self::find($domain, $entity, $wid, $cid, $resultId);
                if ($fresh && isset($fresh['status'])) $actualStatus = (string)$fresh['status'];
                elseif ($fresh && isset($fresh['active'])) $actualStatus = ((int)$fresh['active'] === 1) ? 'active' : 'inactive';
                $navigation = self::navigation($wid, $cid, ['domain' => $domain, 'entity' => $entity, 'query' => (string)$resultId]);
            } catch (Throwable $e) { $navigation = null; }
        }
        return ['domain' => $domain, 'entity' => $entity, 'id' => $resultId, 'operation' => $operation,
            'status' => $actualStatus, 'executed' => true, 'navigation' => $navigation, 'physical_delete' => false];
    }
}
