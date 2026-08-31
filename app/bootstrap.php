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
require_once APP_ROOT . '/app/Core/InventoryDomain.php';
require_once APP_ROOT . '/app/Core/TradeDomain.php';
require_once APP_ROOT . '/app/Core/SalesDomain.php';
require_once APP_ROOT . '/app/Core/CrmDomain.php';
require_once APP_ROOT . '/app/Core/AiSchema.php';
require_once APP_ROOT . '/app/Core/AiToolRegistry.php';
require_once APP_ROOT . '/app/Core/AiPageContext.php';
require_once APP_ROOT . '/app/Core/AiEntityRegistry.php';
require_once APP_ROOT . '/app/Core/AiContextResolver.php';
require_once APP_ROOT . '/app/Core/AiContextEnvelope.php';
require_once APP_ROOT . '/app/Core/AiRepository.php';
require_once APP_ROOT . '/app/Core/BusinessCopilot.php';
require_once APP_ROOT . '/app/Core/AiSuggestionEngine.php';
require_once APP_ROOT . '/app/Core/Xlsx.php';

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
        CrmDomain::migrate(pdo());
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
