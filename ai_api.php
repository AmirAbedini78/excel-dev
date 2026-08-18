<?php
/**
 * AI Worker Control Plane API
 *
 * Local workers connect OUTBOUND to this endpoint. They never receive database
 * credentials and they cannot execute arbitrary SQL. Worker tokens are scoped to
 * one workspace, and mutating accounting tools only create approval proposals.
 */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ai_json(array $payload, int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_body(): array
{
    $len=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if($len>4*1024*1024) throw new RuntimeException('payload_too_large');
    $raw=file_get_contents('php://input');
    if($raw===false || trim($raw)==='') return [];
    $data=json_decode($raw,true);
    if(!is_array($data)) throw new RuntimeException('invalid_json');
    return $data;
}

function ai_worker_token(): string
{
    $token=trim((string)($_SERVER['HTTP_X_AI_WORKER_TOKEN'] ?? ''));
    if($token!=='') return $token;
    $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if(preg_match('/^Bearer\s+(.+)$/i',$auth,$m)) return trim($m[1]);
    return '';
}

function ai_node(array $token,array $body): array
{
    $uid=trim((string)($body['node_uid'] ?? ''));
    if($uid==='') throw new RuntimeException('node_uid_required');
    return AiRepository::findNode($token,$uid);
}

if($_SERVER['REQUEST_METHOD']!=='POST') ai_json(['ok'=>false,'error'=>'method_not_allowed'],405);

try {
    $rawToken=ai_worker_token();
    if($rawToken==='') ai_json(['ok'=>false,'error'=>'worker_token_required'],401);
    $token=AiRepository::authenticateWorker($rawToken);
    $body=ai_body();
    $action=trim((string)($_GET['action'] ?? $body['action'] ?? ''));

    if($action==='register'){
        $node=AiRepository::registerNode($token,$body);
        ai_json(['ok'=>true,'node'=>[
            'id'=>(int)$node['id'],'node_uid'=>$node['node_uid'],'node_name'=>$node['node_name'],
            'capabilities'=>json_decode((string)($node['capabilities_json']??'[]'),true)?:[],
            'current_jobs'=>(int)$node['current_jobs']
        ],'tools'=>AiToolRegistry::descriptors(),'server_time'=>date(DATE_ATOM)]);
    }

    if($action==='lease'){
        // A lease call doubles as heartbeat/resource refresh, making nodes self-healing.
        $node=AiRepository::registerNode($token,$body);
        $job=AiRepository::leaseJob($token,$node,(int)($body['lease_seconds']??300));
        if(!$job) ai_json(['ok'=>true,'job'=>null,'poll_after_seconds'=>max(2,min(60,(int)($body['idle_seconds']??8)))]);
        $context=json_decode((string)($job['context_json']??'{}'),true);if(!is_array($context))$context=[];
        ai_json(['ok'=>true,'job'=>[
            'id'=>(int)$job['id'],'job_type'=>$job['job_type'],'company_id'=>$job['company_id']?(int)$job['company_id']:null,
            'conversation_id'=>$job['conversation_id']?(int)$job['conversation_id']:null,'prompt'=>$job['prompt'],
            'context'=>$context,'lease_token'=>$job['lease_token'],'lease_expires_at'=>$job['lease_expires_at']??null
        ],'tools'=>AiToolRegistry::descriptors()]);
    }

    if($action==='heartbeat'){
        $node=AiRepository::registerNode($token,$body);
        if(!empty($body['job_id']) && !empty($body['lease_token'])){
            AiRepository::touchLease($token,$node,(int)$body['job_id'],(string)$body['lease_token'],(int)($body['lease_seconds']??300));
        }
        ai_json(['ok'=>true,'server_time'=>date(DATE_ATOM)]);
    }

    if($action==='tool'){
        $node=ai_node($token,$body);
        $job=AiRepository::touchLease($token,$node,(int)($body['job_id']??0),(string)($body['lease_token']??''),(int)($body['lease_seconds']??300));
        $tool=trim((string)($body['tool_name']??''));
        $args=$body['arguments']??[];if(!is_array($args))throw new RuntimeException('tool_arguments_invalid');
        $callId=trim((string)($body['tool_call_id']??''));
        $result=AiToolRegistry::executeForWorker($job,$tool,$args,$callId);
        ai_json(['ok'=>true,'result'=>$result]);
    }

    if($action==='complete'){
        $node=ai_node($token,$body);
        AiRepository::completeJob($token,$node,(int)($body['job_id']??0),(string)($body['lease_token']??''),$body);
        ai_json(['ok'=>true]);
    }

    if($action==='fail'){
        $node=ai_node($token,$body);
        AiRepository::failJob($token,$node,(int)($body['job_id']??0),(string)($body['lease_token']??''),(string)($body['error']??'worker_failed'));
        ai_json(['ok'=>true]);
    }

    if($action==='tools') ai_json(['ok'=>true,'tools'=>AiToolRegistry::descriptors()]);
    ai_json(['ok'=>false,'error'=>'unknown_action'],404);
} catch(Throwable $e) {
    $msg=$e->getMessage();
    $status=in_array($msg,['worker_token_invalid','worker_token_required'],true)?401:400;
    ai_json(['ok'=>false,'error'=>$msg],$status);
}
