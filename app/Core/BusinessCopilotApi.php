<?php
final class BusinessCopilotApi
{
    private static function requestId(): string
    {
        static $id=null;
        if($id!==null)return$id;
        try{$id=bin2hex(random_bytes(8));}
        catch(Throwable $e){$id=substr(sha1(uniqid('',true)),0,16);}
        return$id;
    }

    private static function json(array $payload,int $status=200): void
    {
        $payload['request_id']=$payload['request_id']??self::requestId();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-ID: '.self::requestId());

        $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
        if(defined('JSON_INVALID_UTF8_SUBSTITUTE'))$flags|=JSON_INVALID_UTF8_SUBSTITUTE;
        $json=json_encode($payload,$flags);

        if($json===false){
            http_response_code(500);
            $fallback=['ok'=>false,'error'=>'json_encode_failed','request_id'=>self::requestId()];
            $json=json_encode($fallback,JSON_UNESCAPED_SLASHES);
            if($json===false)$json='{"ok":false,"error":"json_encode_failed"}';
        }

        echo $json;
        exit;
    }

    private static function textSlice(string $value,int $limit): string
    {
        if($limit<=0)return'';
        return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
    }

    private static function safeError(Throwable $e): array
    {
        $msg=trim((string)$e->getMessage());
        $allowed=[
            'auth_required','forbidden','ai_module_disabled','method_not_allowed','csrf_mismatch',
            'job_not_found','unknown_action','copilot_company_required','copilot_company_not_found',
            'copilot_entity_type_unsupported','copilot_entity_id_invalid','copilot_entity_forbidden',
            'copilot_entity_not_found','copilot_context_invalid','copilot_context_too_many_refs',
            'copilot_context_ref_invalid'
        ];
        if(in_array($msg,$allowed,true))return[$msg,400];
        error_log('[ERPSMART Copilot API '.self::requestId().'] '.get_class($e).': '.$e->getMessage());
        return['server_error',500];
    }

    public static function handle(): void
    {
        if(!Auth::check())self::json(['ok'=>false,'error'=>'auth_required'],401);

        try{
            Tenant::requirePermission('ai.use');
            if(!ModuleRegistry::pageEnabled('ai'))throw new RuntimeException('ai_module_disabled');
        }catch(Throwable $e){
            self::json(['ok'=>false,'error'=>'forbidden'],403);
        }

        $action=trim((string)($_REQUEST['action']??''));

        try{
            $wid=Tenant::id();
            $cid=(int)($_REQUEST['company_id']??0);
            if($cid<=0)$cid=AccountingRepository::companyId();

            if($action==='search'){
                $q=self::textSlice(trim((string)($_GET['q']??'')),120);
                $search=AiEntityRegistry::searchDetailed($wid,$cid,$q);
                self::json([
                    'ok'=>true,
                    'results'=>$search['results'],
                    'query'=>$q,
                    'degraded'=>(bool)$search['degraded'],
                    'failed_provider_count'=>(int)$search['failed_provider_count']
                ]);
            }

            if($action==='preview'){
                $ref=['type'=>(string)($_GET['type']??''),'id'=>(int)($_GET['id']??0)];
                self::json(['ok'=>true,'entity'=>AiEntityRegistry::preview($wid,$cid,$ref)]);
            }

            if($action==='conversation'){
                $id=(int)($_GET['conversation_id']??0);
                self::json(['ok'=>true,'jobs'=>$id?AiRepository::conversationJobsForUser($id,40,$cid):[]]);
            }

            if($action==='job'){
                $id=(int)($_GET['job_id']??0);
                $job=AiRepository::liveJobStateForUser($id,$cid);
                if(!$job)self::json(['ok'=>false,'error'=>'job_not_found'],404);
                self::json(['ok'=>true,'job'=>$job]);
            }

            if($action==='queue'){
                if($_SERVER['REQUEST_METHOD']!=='POST')self::json(['ok'=>false,'error'=>'method_not_allowed'],405);
                if((string)($_POST['csrf']??'')!==(string)($_SESSION['_csrf']??''))self::json(['ok'=>false,'error'=>'csrf_mismatch'],419);

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
                self::json([
                    'ok'=>true,
                    'job_id'=>$jobId,
                    'conversation_id'=>(int)($j['conversation_id']??0),
                    'status'=>(string)($j['status']??'queued')
                ]);
            }

            self::json(['ok'=>false,'error'=>'unknown_action'],404);
        }catch(Throwable $e){
            [$code,$status]=self::safeError($e);
            self::json(['ok'=>false,'error'=>$code],$status);
        }
    }
}
