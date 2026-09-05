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
            $json=json_encode(['ok'=>false,'error'=>'json_encode_failed','request_id'=>self::requestId()],JSON_UNESCAPED_SLASHES);
            if($json===false)$json='{"ok":false,"error":"json_encode_failed"}';
        }
        echo $json;exit;
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
            'copilot_context_ref_invalid','copilot_context_company_mismatch'
        ];
        if(in_array($msg,$allowed,true))return[$msg,400];
        error_log('[ERPSMART Copilot API '.self::requestId().'] '.get_class($e).': '.$e->getMessage());
        return['server_error',500];
    }

    private static function assertCompany(int $wid,int $cid): void
    {
        if($wid<=0||$cid<=0)throw new RuntimeException('copilot_company_required');
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);if(!$st->fetchColumn())throw new RuntimeException('copilot_company_not_found');
    }

    private static function recentConversations(int $wid,int $uid,int $cid,int $limit=12): array
    {
        self::assertCompany($wid,$cid);$limit=max(1,min(30,$limit));
        $sql="SELECT c.id,c.title,c.company_id,c.updated_at,
                    (SELECT j.prompt FROM ai_jobs j WHERE j.workspace_id=c.workspace_id AND j.conversation_id=c.id AND j.requested_by=c.user_id ORDER BY j.id DESC LIMIT 1) last_prompt,
                    (SELECT j.status FROM ai_jobs j WHERE j.workspace_id=c.workspace_id AND j.conversation_id=c.id AND j.requested_by=c.user_id ORDER BY j.id DESC LIMIT 1) last_status
              FROM ai_conversations c
              WHERE c.workspace_id=? AND c.user_id=? AND c.company_id=? AND c.status='active'
              ORDER BY c.updated_at DESC,c.id DESC LIMIT $limit";
        $st=pdo()->prepare($sql);$st->execute([$wid,$uid,$cid]);return$st->fetchAll();
    }

    private static function typesFromRequest(): array
    {
        $raw=trim((string)($_GET['types']??''));if($raw==='')return[];$out=[];
        foreach(explode(',',$raw) as $type){$type=trim($type);if($type!==''&&in_array($type,AiEntityRegistry::supportedTypes(),true))$out[]=$type;}
        return array_values(array_unique($out));
    }

    public static function handle(): void
    {
        if(!Auth::check())self::json(['ok'=>false,'error'=>'auth_required'],401);
        try{
            Tenant::requirePermission('ai.use');
            if(!ModuleRegistry::pageEnabled('ai'))throw new RuntimeException('ai_module_disabled');
        }catch(Throwable $e){self::json(['ok'=>false,'error'=>'forbidden'],403);}

        $action=trim((string)($_REQUEST['action']??''));
        try{
            $wid=Tenant::id();$uid=(int)Auth::user()['id'];$cid=(int)($_REQUEST['company_id']??0);
            if($cid<=0)$cid=AccountingRepository::companyId();

            if($action==='search'){
                $q=self::textSlice(trim((string)($_GET['q']??'')),120);
                $search=AiEntityRegistry::searchWorkspaceDetailed($wid,$cid,$q,self::typesFromRequest());
                self::json(['ok'=>true]+$search);
            }

            if($action==='catalog')self::json(['ok'=>true,'catalog'=>AiEntityRegistry::catalog(),'scope'=>'workspace']);

            if($action==='preview'){
                $ref=['type'=>(string)($_GET['type']??''),'id'=>(int)($_GET['id']??0)];
                self::json(['ok'=>true,'entity'=>AiEntityRegistry::preview($wid,$cid,$ref)]);
            }

            if($action==='conversations'){
                $rows=self::recentConversations($wid,$uid,$cid,(int)($_GET['limit']??12));
                self::json(['ok'=>true,'company_id'=>$cid,'conversations'=>$rows]);
            }

            if($action==='conversation'){
                $id=(int)($_GET['conversation_id']??0);
                self::json(['ok'=>true,'jobs'=>$id?AiRepository::conversationJobsForUser($id,60,$cid):[]]);
            }

            if($action==='job'){
                $id=(int)($_GET['job_id']??0);$job=AiRepository::liveJobStateForUser($id,$cid);
                if(!$job)self::json(['ok'=>false,'error'=>'job_not_found'],404);
                self::json(['ok'=>true,'job'=>$job]);
            }

            if($action==='queue'){
                if($_SERVER['REQUEST_METHOD']!=='POST')self::json(['ok'=>false,'error'=>'method_not_allowed'],405);
                if((string)($_POST['csrf']??'')!==(string)($_SESSION['_csrf']??''))self::json(['ok'=>false,'error'=>'csrf_mismatch'],419);
                self::assertCompany($wid,$cid);
                $attached=AiContextResolver::decodeRefs((string)($_POST['context_refs_json']??''));
                $pageRefs=AiContextResolver::decodeRefs((string)($_POST['page_context_refs_json']??''));
                $jobId=AiRepository::queueCopilotChat(
                    (string)($_POST['prompt']??''),$cid,(int)($_POST['conversation_id']??0)?:null,$pageRefs,$attached
                );
                $j=AiRepository::jobForUser($jobId);
                self::json(['ok'=>true,'job_id'=>$jobId,'conversation_id'=>(int)($j['conversation_id']??0),'status'=>(string)($j['status']??'queued')]);
            }

            self::json(['ok'=>false,'error'=>'unknown_action'],404);
        }catch(Throwable $e){[$code,$status]=self::safeError($e);self::json(['ok'=>false,'error'=>$code],$status);}
    }
}
