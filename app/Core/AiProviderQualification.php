<?php
/**
 * Admin-facing provider discovery / qualification report surface for AiModule.
 * Does not call cloud providers itself; displays worker/gateway reports only.
 */
final class AiProviderQualification
{
    public const REPORT_KEY = 'ai_provider_qualification';

    public static function defaultReport(): array
    {
        return [
            'schema_version' => 'provider-qual-v1',
            'updated_at' => null,
            'mcp' => McpFacade::policySummary(),
            'providers' => [],
            'privacy' => [
                'local_only_blocks_cloud' => true,
                'cloud_fallback_ignores_privacy' => false,
            ],
            'status' => 'not_run',
        ];
    }

    public static function current(): array
    {
        $defaults = self::defaultReport();
        try {
            $st = pdo()->prepare('SELECT settings_json FROM workspaces WHERE id=? LIMIT 1');
            $st->execute([Tenant::id()]);
            $data = json_decode((string)$st->fetchColumn(), true);
            $saved = is_array($data) ? ($data[self::REPORT_KEY] ?? null) : null;
            if (!is_array($saved)) {
                return $defaults;
            }
            return array_replace_recursive($defaults, $saved);
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Persist a gateway-produced qualification report (no secrets).
     * @param array<string,mixed> $report
     */
    public static function saveReport(array $report): void
    {
        Tenant::requirePermission('ai.workers.manage');
        $clean = self::sanitizeReport($report);
        $clean['updated_at'] = date(DATE_ATOM);
        $clean['mcp'] = McpFacade::policySummary();
        $st = pdo()->prepare(
            "UPDATE workspaces SET settings_json=JSON_SET(COALESCE(settings_json,JSON_OBJECT()),'$." . self::REPORT_KEY . "',CAST(? AS JSON)),updated_at=NOW() WHERE id=?"
        );
        $st->execute([json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), Tenant::id()]);
        Audit::log('ai.provider.qualification', 'workspaces', Tenant::id(), 'به‌روزرسانی گزارش احراز مدل/ارائه‌دهنده', null, null, [
            'status' => $clean['status'] ?? null,
            'providers' => array_keys((array)($clean['providers'] ?? [])),
        ]);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function sanitizeReport(array $report): array
    {
        $out = self::defaultReport();
        $out['schema_version'] = 'provider-qual-v1';
        $out['status'] = in_array((string)($report['status'] ?? ''), ['not_run', 'passed', 'degraded', 'failed'], true)
            ? (string)$report['status']
            : 'not_run';
        $providers = $report['providers'] ?? [];
        if (!is_array($providers)) {
            $providers = [];
        }
        $safeProviders = [];
        foreach ($providers as $name => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9._-]/i', '', (string)$name);
            if ($key === '') {
                continue;
            }
            $models = [];
            foreach ((array)($row['models'] ?? []) as $m) {
                $m = trim((string)$m);
                if ($m !== '' && strlen($m) <= 120) {
                    $models[] = $m;
                }
            }
            $safeProviders[$key] = [
                'connection' => (string)($row['connection'] ?? 'unknown'),
                'inference' => (string)($row['inference'] ?? 'unknown'),
                'models' => array_values(array_unique($models)),
                'reason' => substr(trim((string)($row['reason'] ?? '')), 0, 240),
            ];
        }
        $out['providers'] = $safeProviders;
        $privacy = is_array($report['privacy'] ?? null) ? $report['privacy'] : [];
        $out['privacy'] = [
            'local_only_blocks_cloud' => (bool)($privacy['local_only_blocks_cloud'] ?? true),
            'cloud_fallback_ignores_privacy' => false,
            'provider_strategy' => substr(trim((string)($privacy['provider_strategy'] ?? '')), 0, 40),
        ];
        return $out;
    }

    public static function discoveryCatalog(): array
    {
        $models = class_exists('AiEnginePreferences') ? AiEnginePreferences::models() : [];
        $catalog = [];
        foreach ($models as $provider => $list) {
            $catalog[(string)$provider] = [
                'configured_models' => array_values(array_map('strval', (array)$list)),
                'source' => 'AiEnginePreferences::models',
            ];
        }
        return $catalog;
    }
}
