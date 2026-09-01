<?php
require __DIR__.'/app/bootstrap.php';

function copilot_json(array $d,int $status=200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function copilot_substr(string $value,int $limit): string
{
    return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
}

if(!Auth::check())copilot_json(['ok'=>false,'error'=>'auth_required'],401);
try{
    Tenant::requirePermission('ai.use');
    if(!ModuleRegistry::pageEnabled('ai'))throw new RuntimeException('ai_module_disabled');
}catch(Throwable $e){
    copilot_json(['ok'=>false,'error'=>'forbidden'],403);
}

$action=trim((string)($_REQUEST['action']??''));

try{
    $wid=Tenant::id();
    $cid=(int)($_REQUEST['company_id']??0);
    if($cid<=0)$cid=AccountingRepository::companyId();

    if($action==='search'){
        $q=copilot_substr(trim((string)($_GET['q']??'')),120);
        $search=AiEntityRegistry::searchDetailed($wid,$cid,$q);
        copilot_json([
            'ok'=>true,
            'results'=>$search['results'],
            'query'=>$q,
            'degraded'=>(bool)$search['degraded'],
            'failed_provider_count'=>(int)$search['failed_provider_count']
        ]);
    }

    if($action==='preview'){
        $ref=['type'=>(string)($_GET['type']??''),'id'=>(int)($_GET['id']??0)];
        copilot_json(['ok'=>true,'entity'=>AiEntityRegistry::preview($wid,$cid,$ref)]);
    }

    if($action==='conversation'){
        $id=(int)($_GET['conversation_id']??0);
        copilot_json(['ok'=>true,'jobs'=>$id?AiRepository::conversationJobsForUser($id,40,$cid):[]]);
    }

    if($action==='job'){
        $id=(int)($_GET['job_id']??0);
        $job=AiRepository::liveJobStateForUser($id,$cid);
        if(!$job)copilot_json(['ok'=>false,'error'=>'job_not_found'],404);
        copilot_json(['ok'=>true,'job'=>$job]);
    }

    if($action==='queue'){
        if($_SERVER['REQUEST_METHOD']!=='POST')copilot_json(['ok'=>false,'error'=>'method_not_allowed'],405);
        if((string)($_POST['csrf']??'')!==(string)($_SESSION['_csrf']??''))copilot_json(['ok'=>false,'error'=>'csrf_mismatch'],419);

        $attached=AiContextResolver::decodeRefs((string)($_POST['context_refs_json']??''));
        $pageRefs=AiContextResolver::decodeRefs((string)($_POST['page_context_refs_json']??''));

        $jobId=AiRepository::queueCopilotChat(
            (string)($_POST['prompt']??''),
            $cid,
            (int)($_POST['conversation_id']??0)?:null,
            $pageRefs,
            $attached
        );
        $j=AiRepository::jobForUser($jobId);

        copilot_json([
            'ok'=>true,
            'job_id'=>$jobId,
            'conversation_id'=>(int)($j['conversation_id']??0),
            'status'=>(string)($j['status']??'queued')
        ]);
    }

    copilot_json(['ok'=>false,'error'=>'unknown_action'],404);
}catch(Throwable $e){
    copilot_json(['ok'=>false,'error'=>copilot_substr($e->getMessage(),500)],400);
}
