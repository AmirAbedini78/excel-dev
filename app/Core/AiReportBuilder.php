<?php
/**
 * Allowlisted report DSL → CSV/Excel-compatible export.
 * No LLM SQL. Queries only go through typed domain adapters / AiUniversalOperations.
 */
final class AiReportBuilder
{
    public const VERSION = 'report-builder-dsl-v1';

    /** Allowed report kinds and their backing adapters. */
    public static function catalog(): array
    {
        return [
            'suite_entity' => [
                'title' => 'گزارش عملیاتی Suite',
                'adapter' => 'AiUniversalOperations::report',
                'params' => ['entity', 'query', 'status', 'date_field', 'date_from', 'date_to', 'group_by', 'sum_field', 'limit'],
                'defaults' => ['domain' => 'suite'],
            ],
            'enterprise_entity' => [
                'title' => 'گزارش سازمانی Enterprise',
                'adapter' => 'AiUniversalOperations::report',
                'params' => ['entity', 'query', 'status', 'date_field', 'date_from', 'date_to', 'group_by', 'sum_field', 'limit'],
                'defaults' => ['domain' => 'enterprise'],
            ],
            'document_analytics' => [
                'title' => 'تحلیل اسناد خرید/فروش',
                'adapter' => 'AiToolRegistry::document_analytics',
                'params' => ['doc_family', 'date_from', 'date_to', 'party_id', 'item_id', 'limit'],
                'defaults' => [],
            ],
            'master_parties' => [
                'title' => 'فهرست مشتری/تأمین‌کننده',
                'adapter' => 'MasterDataDomain::search',
                'params' => ['entity', 'query', 'limit'],
                'defaults' => [],
            ],
            'master_products' => [
                'title' => 'فهرست کالا/خدمت',
                'adapter' => 'MasterDataDomain::search',
                'params' => ['query', 'limit'],
                'defaults' => ['entity' => 'product'],
            ],
        ];
    }

    private static function text($value, int $limit = 190): string
    {
        if (is_array($value) || is_object($value)) {
            throw new RuntimeException('پارامتر متنی گزارش نامعتبر است.');
        }
        $value = trim((string)($value ?? ''));
        if (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') > $limit : strlen($value) > ($limit * 4)) {
            throw new RuntimeException('پارامتر گزارش بیش از حد طولانی است.');
        }
        return $value;
    }

    public static function validateDsl(array $dsl): array
    {
        $kind = self::text($dsl['kind'] ?? '', 64);
        $catalog = self::catalog();
        if (!isset($catalog[$kind])) {
            throw new RuntimeException('نوع گزارش در allowlist نیست.');
        }
        $format = strtolower(self::text($dsl['format'] ?? 'csv', 16));
        if (!in_array($format, ['csv', 'xlsx_csv'], true)) {
            throw new RuntimeException('فرمت خروجی فقط csv یا xlsx_csv (Excel-compatible) است.');
        }
        $args = is_array($dsl['args'] ?? null) ? $dsl['args'] : [];
        $allowed = array_values(array_map('strval', (array)$catalog[$kind]['params']));
        foreach ((array)($catalog[$kind]['defaults'] ?? []) as $dk => $dv) {
            if (!array_key_exists($dk, $args)) {
                $args[$dk] = $dv;
            }
            if (!in_array((string)$dk, $allowed, true)) {
                $allowed[] = (string)$dk;
            }
        }
        $clean = [];
        foreach ($args as $k => $v) {
            $k = (string)$k;
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            if (is_array($v) || is_object($v)) {
                throw new RuntimeException('آرگومان تودرتو در DSL مجاز نیست.');
            }
            $clean[$k] = $v;
        }
        return ['kind' => $kind, 'format' => $format, 'args' => $clean, 'title' => (string)$catalog[$kind]['title']];
    }

    public static function run(int $wid, int $cid, array $dsl): array
    {
        Tenant::requirePermission('ai.use');
        $spec = self::validateDsl($dsl);
        $kind = $spec['kind'];
        $args = $spec['args'];
        $rows = [];
        $meta = ['kind' => $kind, 'title' => $spec['title'], 'scope' => 'active_company_only'];

        if ($kind === 'suite_entity' || $kind === 'enterprise_entity') {
            $payload = array_merge($args, ['domain' => $kind === 'suite_entity' ? 'suite' : 'enterprise']);
            $report = AiUniversalOperations::report($wid, $cid, $payload);
            foreach ((array)($report['groups'] ?? []) as $g) {
                $rows[] = [
                    'group' => (string)($g['key'] ?? ''),
                    'count' => (int)($g['count'] ?? 0),
                    'sum' => (float)($g['sum'] ?? 0),
                ];
            }
            $meta['truncated_possible'] = !empty($report['truncated_possible']);
            $meta['sample_rows'] = (int)($report['sample_rows'] ?? 0);
        } elseif ($kind === 'document_analytics') {
            // Reuse existing tool path via reflection-free public API on registry if available.
            if (!class_exists('AiToolRegistry')) {
                throw new RuntimeException('رجیستری ابزار در دسترس نیست.');
            }
            $toolArgs = [
                'doc_family' => self::text($args['doc_family'] ?? 'sales', 32),
                'date_from' => self::text($args['date_from'] ?? '', 20),
                'date_to' => self::text($args['date_to'] ?? '', 20),
                'party_id' => (int)($args['party_id'] ?? 0),
                'item_id' => (int)($args['item_id'] ?? 0),
                'limit' => max(1, min(200, (int)($args['limit'] ?? 100))),
            ];
            $result = AiToolRegistry::executeReadForReportBuilder($wid, $cid, 'document_analytics', $toolArgs);
            foreach ((array)($result['rows'] ?? $result['items'] ?? []) as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rows[] = self::flattenRow($r);
            }
            $meta['source'] = 'document_analytics';
        } elseif ($kind === 'master_parties') {
            $entity = self::text($args['entity'] ?? 'customer', 32);
            if (!in_array($entity, ['customer', 'supplier'], true)) {
                throw new RuntimeException('entity باید customer یا supplier باشد.');
            }
            foreach (MasterDataDomain::search($entity, $wid, $cid, self::text($args['query'] ?? '', 120), (int)($args['limit'] ?? 50)) as $r) {
                $rows[] = self::flattenRow($r);
            }
        } elseif ($kind === 'master_products') {
            foreach (MasterDataDomain::search('product', $wid, $cid, self::text($args['query'] ?? '', 120), (int)($args['limit'] ?? 50)) as $r) {
                $rows[] = self::flattenRow($r);
            }
        }

        $export = self::toCsvExcel($rows, $spec['format']);
        return [
            'version' => self::VERSION,
            'ok' => true,
            'meta' => $meta,
            'row_count' => count($rows),
            'format' => $spec['format'],
            'filename' => $export['filename'],
            'mime' => $export['mime'],
            'content_base64' => base64_encode($export['content']),
            'preview_rows' => array_slice($rows, 0, 20),
            'note' => 'خروجی Excel-compatible از DSL allowlist؛ SQL آزاد یا LLM SQL ندارد.',
        ];
    }

    private static function flattenRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $out[(string)$k] = $v;
        }
        return $out;
    }

    private static function toCsvExcel(array $rows, string $format): array
    {
        $headers = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $h) {
                if (!in_array($h, $headers, true)) {
                    $headers[] = $h;
                }
            }
        }
        if (!$headers) {
            $headers = ['empty'];
        }
        $lines = [];
        // UTF-8 BOM so Excel opens Persian correctly.
        $bom = "\xEF\xBB\xBF";
        $lines[] = self::csvLine($headers);
        foreach ($rows as $r) {
            $vals = [];
            foreach ($headers as $h) {
                $vals[] = $r[$h] ?? '';
            }
            $lines[] = self::csvLine($vals);
        }
        $body = $bom . implode("\r\n", $lines) . "\r\n";
        $stamp = gmdate('Ymd\THis\Z');
        return [
            'content' => $body,
            'filename' => 'erpsmart_report_' . $stamp . '.csv',
            'mime' => 'text/csv; charset=utf-8',
            'format' => $format,
        ];
    }

    private static function csvLine(array $vals): string
    {
        $parts = [];
        foreach ($vals as $v) {
            $s = (string)$v;
            $s = str_replace('"', '""', $s);
            if (strpbrk($s, ",\"\r\n") !== false) {
                $s = '"' . $s . '"';
            }
            $parts[] = $s;
        }
        return implode(',', $parts);
    }
}
