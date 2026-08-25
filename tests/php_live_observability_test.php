<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

require dirname(__DIR__).'/app/Core/AiRepository.php';
require dirname(__DIR__).'/app/Modules/AiModule.php';

function expect(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$toolInput=['search_parties','party_ledger','bad-tool','search_parties',['secret']];
expect(AiRepository::safeToolNames($toolInput)===['search_parties','party_ledger'],'unsafe_tool_name_boundary');

$manyTools=[];for($i=0;$i<40;$i++)$manyTools[]='tool_'.$i;
expect(count(AiRepository::safeToolNames($manyTools))===32,'tool_name_limit');

$metricInput=[
    'first_chunk_seconds'=>1.25,
    'elapsed_seconds'=>'5.5',
    'prompt_eval_count'=>100,
    'prompt_eval_duration'=>2000000000,
    'eval_count'=>20,
    'eval_duration'=>4000000000,
    'free_form_secret'=>'must-not-cross-boundary',
];
$safeMetrics=AiRepository::safeModelMetrics($metricInput);
expect(count($safeMetrics)===6,'metric_allowlist_count');
expect(!array_key_exists('free_form_secret',$safeMetrics),'metric_allowlist_leak');

$job=['result_json'=>json_encode([
    'mode'=>'accounting_action_blocked',
    'model'=>'qwen3.5:0.8b',
    'tools_used'=>['search_parties','party_ledger','trial_balance'],
    'tools_attempted'=>['search_parties','party_ledger','trial_balance'],
    'attempted_metrics'=>$metricInput,
    'commercial_hardening'=>[
        'end_to_end_seconds'=>24.7,
        'latency_status'=>'within_budget',
        'risk_class'=>'high',
    ],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];

$method=(new ReflectionClass(AiModule::class))->getMethod('metricsHtml');
$html=(string)$method->invoke(null,$job);
foreach([
    'اقدام حسابداری مسدودشده',
    'qwen3.5:0.8b',
    'search_parties، party_ledger، trial_balance',
    'اولین خروجی: 1.3s',
    'زمان مدل: 5.5s',
    'زمان کل: 24.7s',
    'بودجه زمان: پاس',
    'ریسک مسیر: بالا',
] as $needle)expect(str_contains($html,$needle),'missing_rendered_value:'.$needle);

foreach(['bad-tool','must-not-cross-boundary','tool_arguments','tool_results','call_id'] as $forbidden){
    expect(!str_contains($html,$forbidden),'unsafe_rendered_value:'.$forbidden);
}

echo "PHP_LIVE_ATTEMPT_OBSERVABILITY: PASS\n";
