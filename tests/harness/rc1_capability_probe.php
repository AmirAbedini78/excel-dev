<?php
/**
 * RC1 capability probe — reports which domain methods exist (source reflection).
 * Does not invent PASS for missing manufacturing/warehouse APIs.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$ROOT = dirname(__DIR__, 2);
$scenario = $argv[1] ?? 'probe';

function rc1_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
}

function method_exists_in_file(string $file, string $method): bool
{
    if (!is_file($file)) {
        return false;
    }
    $src = file_get_contents($file);
    return $src !== false && preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src) === 1;
}

if ($scenario === 'probe' || $scenario === 'mf1') {
    $inv = $ROOT . '/app/Core/InventoryDomain.php';
    $prod = $ROOT . '/app/Core/ProductionDomain.php';
    $cost = glob($ROOT . '/app/Core/CostingAccounting*.php') ?: [];
    $missing = [];
    foreach ([
        'InventoryDomain::reserveForProduction' => [$inv, 'reserveForProduction'],
        'InventoryDomain::issueMaterial' => [$inv, 'issueMaterial'],
        'InventoryDomain::receiveFinishedGoods' => [$inv, 'receiveFinishedGoods'],
        'InventoryDomain::reverseMovement' => [$inv, 'reverseMovement'],
    ] as $label => $pair) {
        if (!method_exists_in_file($pair[0], $pair[1])) {
            $missing[] = $label;
        }
    }
    // Production order / issue / scrap / QC / FG beyond pilot BOM/plan
    foreach ([
        'createProductionOrder',
        'issueMaterials',
        'recordScrap',
        'recordQcDisposition',
        'receiveFinishedGoods',
        'verifiedCostRollup',
    ] as $m) {
        if (!method_exists_in_file($prod, $m)) {
            $missing[] = 'ProductionDomain::' . $m;
        }
    }
    if ($cost === []) {
        $missing[] = 'CostingAccounting* (T5 valuation resolver)';
    }
    $pilot = [
        'createBom' => method_exists_in_file($prod, 'createBom'),
        'createPlan' => method_exists_in_file($prod, 'createPlan'),
        'preview' => method_exists_in_file($prod, 'preview'),
        'requirements' => method_exists_in_file($prod, 'requirements'),
    ];
    rc1_json([
        'ok' => true,
        'contract' => 'MF-1',
        'status' => $missing === [] ? 'CAPABLE' : 'BLOCKED',
        'missing' => $missing,
        'pilot_present' => $pilot,
        'note' => 'ProductionDomain is planning pilot only; stock issue/FG APIs not implemented at G0.',
    ]);
    exit(0);
}

if ($scenario === 'sys1_profiles') {
    $bp = $ROOT . '/app/Core/BusinessProfileDomain.php';
    $profiles = [];
    if (is_file($bp)) {
        require_once $bp;
        // Class may need no PDO for listProfiles
        if (class_exists('BusinessProfileDomain')) {
            $profiles = array_keys(BusinessProfileDomain::PROFILES);
        }
    }
    $hasService = in_array('service', $profiles, true);
    $hasRetail = in_array('retail', $profiles, true);
    $forcedMfg = false;
    if (is_file($bp)) {
        $src = file_get_contents($bp);
        // service/retail must not require production module
        if (preg_match("/'service'\\s*=>\\s*\\[(.*?)\\]/s*'hybrid'/s", $src, $m)) {
            $forcedMfg = str_contains($m[1], "'production'");
        }
    }
    rc1_json([
        'ok' => true,
        'contract' => 'SYS-1',
        'profiles' => $profiles,
        'has_service' => $hasService,
        'has_retail' => $hasRetail,
        'service_forces_production' => $forcedMfg,
        'business_profile_file' => is_file($bp),
    ]);
    exit(0);
}

if ($scenario === 'ops1_schema') {
    $runtime = $ROOT . '/app/Core/RuntimeCache.php';
    $version = null;
    if (is_file($runtime)) {
        $src = file_get_contents($runtime);
        if (preg_match("/SCHEMA_VERSION\\s*=\\s*'([^']+)'/", $src, $m)) {
            $version = $m[1];
        }
    }
    $prodProvenance = getenv('RC1_PROD_SCHEMA_PROVENANCE') ?: '';
    rc1_json([
        'ok' => true,
        'contract' => 'OPS-1',
        'repo_schema_version' => $version,
        'production_schema_provenance' => $prodProvenance !== '' ? $prodProvenance : null,
        'status' => $prodProvenance !== '' ? 'PROVENANCE_SET' : 'BLOCKED',
        'note' => 'Never pretend repo main is production schema. Set RC1_PROD_SCHEMA_PROVENANCE when staging dump available.',
    ]);
    exit(0);
}

rc1_json(['ok' => false, 'error' => 'unknown_scenario', 'scenario' => $scenario]);
exit(1);
