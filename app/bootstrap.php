<?php
session_start();
define('APP_ROOT', dirname(__DIR__));
$configFile = APP_ROOT . '/app/config.php';
if (!file_exists($configFile)) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'install.php') { header('Location: install.php'); exit; }
    $CONFIG = require APP_ROOT . '/app/config.sample.php';
} else {
    $CONFIG = require $configFile;
}
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Tehran');
require_once APP_ROOT . '/app/Core/DB.php';
require_once APP_ROOT . '/app/Core/Jalali.php';
require_once APP_ROOT . '/app/Core/Helpers.php';
require_once APP_ROOT . '/app/Core/RuntimeCache.php';
require_once APP_ROOT . '/app/Core/Auth.php';
require_once APP_ROOT . '/app/Core/Schema.php';
require_once APP_ROOT . '/app/Core/Notify.php';
require_once APP_ROOT . '/app/Core/Tenant.php';
require_once APP_ROOT . '/app/Core/ModuleRegistry.php';
require_once APP_ROOT . '/app/Core/ChoiceRegistry.php';
require_once APP_ROOT . '/app/Core/Audit.php';
require_once APP_ROOT . '/app/Core/FileLibrary.php';
require_once APP_ROOT . '/app/Core/Sharing.php';
require_once APP_ROOT . '/app/Core/V5Schema.php';
require_once APP_ROOT . '/app/Core/AccountingSchema.php';
require_once APP_ROOT . '/app/Core/AccountingExtendedSchema.php';
require_once APP_ROOT . '/app/Core/AccountingRepository.php';
require_once APP_ROOT . '/app/Core/WarehouseStockApi.php';
require_once APP_ROOT . '/app/Core/InventoryDomain.php';
require_once APP_ROOT . '/app/Core/ProductionDomain.php';
require_once APP_ROOT . '/app/Core/ManufacturingInventoryAdapter.php';
require_once APP_ROOT . '/app/Core/ManufacturingCostEvidence.php';
require_once APP_ROOT . '/app/Core/ManufacturingExecutionDomain.php';
require_once APP_ROOT . '/app/Core/CostingAccountingDomain.php';
require_once APP_ROOT . '/app/Core/ErpSuiteDomain.php';
require_once APP_ROOT . '/app/Core/WorkflowDomain.php';
require_once APP_ROOT . '/app/Core/EnterpriseCatalog.php';
require_once APP_ROOT . '/app/Core/EnterpriseDomain.php';
require_once APP_ROOT . '/app/Core/PurchaseMatchingDomain.php';
require_once APP_ROOT . '/app/Core/TradeDomain.php';
require_once APP_ROOT . '/app/Core/TradingCockpitDomain.php';
require_once APP_ROOT . '/app/Core/SalesDomain.php';
require_once APP_ROOT . '/app/Core/OrderToCashDomain.php';
require_once APP_ROOT . '/app/Core/AccountingPostingDomain.php';
require_once APP_ROOT . '/app/Core/ArSettlementDomain.php';
require_once APP_ROOT . '/app/Core/ApPostingDomain.php';
require_once APP_ROOT . '/app/Core/ApSettlementDomain.php';
require_once APP_ROOT . '/app/Core/BusinessProfileDomain.php';
require_once APP_ROOT . '/app/Core/IranLocalizationDate.php';
require_once APP_ROOT . '/app/Core/IranLocalizationMoney.php';
require_once APP_ROOT . '/app/Core/IranLocalizationDocument.php';
require_once APP_ROOT . '/app/Core/BusinessIntelligenceDomain.php';
require_once APP_ROOT . '/app/Core/CrmDomain.php';
require_once APP_ROOT . '/app/Core/AiSchema.php';
require_once APP_ROOT . '/app/Core/AiToolRegistry.php';
require_once APP_ROOT . '/app/Core/AiCapabilityRegistry.php';
require_once APP_ROOT . '/app/Core/AiRagAcl.php';
require_once APP_ROOT . '/app/Core/AiPageContext.php';
require_once APP_ROOT . '/app/Core/AiEntityRegistry.php';
require_once APP_ROOT . '/app/Core/AiContextResolver.php';
require_once APP_ROOT . '/app/Core/AiContextEnvelope.php';
require_once APP_ROOT . '/app/Core/AiEnginePreferences.php';
require_once APP_ROOT . '/app/Core/AiAgentTaskState.php';
require_once APP_ROOT . '/app/Core/AiRepository.php';
require_once APP_ROOT . '/app/Core/BusinessCopilotApi.php';
require_once APP_ROOT . '/app/Core/BusinessCopilot.php';
require_once APP_ROOT . '/app/Core/AiSuggestionEngine.php';
require_once APP_ROOT . '/app/Core/UiRtlSupport.php';
require_once APP_ROOT . '/app/Core/UiRoleActionMatrix.php';
require_once APP_ROOT . '/app/Core/AiProposalGuardrails.php';
require_once APP_ROOT . '/app/Core/McpFacade.php';
require_once APP_ROOT . '/app/Core/AiProviderQualification.php';
require_once APP_ROOT . '/app/Core/DashboardTradingCockpit.php';
require_once APP_ROOT . '/app/Core/DashboardManufacturingCockpit.php';
require_once APP_ROOT . '/app/Core/Xlsx.php';

// Offline staging/ops migrate lock — block web until operator CLI completes.
// CLI runners and install.php are not gated here; never auto-clears production cache.
$rc1OfflineLock = APP_ROOT . '/storage/maintenance/rc1_offline_migrate.lock';
if (PHP_SAPI !== 'cli' && is_file($rc1OfflineLock)) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>تعمیرات</title></head>';
    echo '<body><h1>سامانه موقتاً در حالت مهاجرت آفلاین است</h1>';
    echo '<p>Offline schema migration in progress. Please retry shortly.</p></body></html>';
    exit;
}

if (file_exists($configFile)) {
    DB::connect($CONFIG['db']);
    RuntimeCache::boot(APP_ROOT . '/storage/cache', $CONFIG['db'] ?? []);

    // V5 Fast Schema Gate:
    // Heavy CREATE/ALTER/INFORMATION_SCHEMA work runs only once per DB + app schema version,
    // not on every request.
    if (!RuntimeCache::schemaReady(RuntimeCache::SCHEMA_VERSION)) {
        Schema::migrate(pdo());
        Tenant::ensureSchema();
        ChoiceRegistry::ensureSchema();
        Audit::ensureSchema();
        FileLibrary::ensureSchema();
        V5Schema::migrate(pdo());
        AccountingSchema::migrate(pdo());
        AccountingExtendedSchema::migrate(pdo());
        SalesDomain::migrate(pdo());
        OrderToCashDomain::migrate(pdo());
        AccountingPostingDomain::migrate(pdo());
        ArSettlementDomain::migrate(pdo());
        ApPostingDomain::migrate(pdo());
        ApSettlementDomain::migrate(pdo());
        BusinessProfileDomain::migrate(pdo());
        CrmDomain::migrate(pdo());
        ProductionDomain::migrate(pdo());
        InventoryDomain::migrate(pdo());
        ManufacturingExecutionDomain::migrate(pdo());
        CostingAccountingDomain::migrate(pdo());
        ErpSuiteDomain::migrate(pdo());
        WorkflowDomain::migrate(pdo());
        EnterpriseDomain::migrate(pdo());
        PurchaseMatchingDomain::migrate(pdo());
        AiSchema::migrate(pdo());
        ModuleRegistry::ensureSchema();
        RuntimeCache::markSchema(RuntimeCache::SCHEMA_VERSION);
    }

    enforce_data_persistence_guard();

    if (Auth::check()) {
        Tenant::boot();
        ModuleRegistry::boot();
        if (empty($_SESSION['_v4_login_audited'])) {
            Audit::log('auth.login','users',(int)Auth::user()['id'],'ورود کاربر');
            $_SESSION['_v4_login_audited']=1;
        }
    }
}
