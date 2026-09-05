<?php
final class AiRepository
{
    public static function createConversation(?int $companyId,string $title=''): int
    {
        $uid=(int)Auth::user()['id'];$wid=Tenant::id();
        if($companyId && !self::companyOwned($wid,$companyId))$companyId=null;
        $st=pdo()->prepare("INSERT INTO ai_conversations (workspace_id,company_id,user_id,title,status,created_at,updated_at) VALUES (?,?,?,?, 'active',NOW(),NOW())");
        $st->execute([$wid,$companyId,$uid,$title?:'گفت‌وگوی جدید']);
        return (int)pdo()->lastInsertId();
    }

    public static function queueChat(string $prompt,?int $companyId=null,?int $conversationId=null,array $contextRefs=[]): int
    {
        $prompt=trim($prompt);if($prompt==='')throw new RuntimeException('متن درخواست خالی است.');
        if(mb_strlen($prompt)>12000)throw new RuntimeException('متن درخواست بیش از حد طولانی است.');
        $wid=Tenant::id();$uid=(int)Auth::user()['id'];
        if(!$companyId)$companyId=AccountingRepository::companyId()?:null;
        if($companyId && !self::companyOwned($wid,$companyId))throw new RuntimeException('شرکت انتخاب‌شده معتبر نیست.');
        $pageContext=AiPageContext::resolve($wid,$companyId,$contextRefs);
        $conversationId=self::conversationIdForQueue($wid,$uid,$companyId,$conversationId,$prompt);
        $context=AiToolRegistry::bootstrapContext($wid,$companyId);
        if($pageContext)$context['page_context']=$pageContext;
        $st=pdo()->prepare("INSERT INTO ai_jobs (workspace_id,company_id,conversation_id,requested_by,job_type,prompt,status,priority,required_capability,context_json,created_at,updated_at)
            VALUES (?,?,?,?, 'agent_chat',?,'queued',100,'llm',?,NOW(),NOW())");
        $st->execute([$wid,$companyId,$conversationId,$uid,$prompt,json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $id=(int)pdo()->lastInsertId();
        Audit::log('ai.job.queued','ai_jobs',$id,'ثبت درخواست برای موتور AI',null,null,['company_id'=>$companyId,'page_context_entities'=>count((array)($pageContext['entities']??[]))]);
        return $id;
    }

    private static function conversationIdForQueue(int $wid,int $uid,?int $companyId,?int $conversationId,string $prompt): int
    {
        if(!$conversationId)return self::createConversation($companyId,mb_substr($prompt,0,80));
        $st=pdo()->prepare("SELECT id,company_id FROM ai_conversations WHERE id=? AND workspace_id=? AND user_id=? AND status='active' LIMIT 1");$st->execute([$conversationId,$wid,$uid]);$r=$st->fetch();
        if(!$r)throw new RuntimeException('گفت‌وگوی انتخاب‌شده معتبر نیست.');$convCompany=$r['company_id']?(int)$r['company_id']:null;
        if($convCompany!==null&&$companyId!==null&&$convCompany!==$companyId)throw new RuntimeException('گفت‌وگو متعلق به شرکت دیگری است.');
        pdo()->prepare("UPDATE ai_conversations SET updated_at=NOW() WHERE id=?")->execute([$conversationId]);return$conversationId;
    }

    public static function queueCopilotChat(string $prompt,?int $companyId,?int $conversationId,array $currentPageRefs,array $attachedRefs): int
    {
        $prompt=trim($prompt);if($prompt==='')throw new RuntimeException('متن درخواست خالی است.');if(mb_strlen($prompt)>12000)throw new RuntimeException('متن درخواست بیش از حد طولانی است.');
        $wid=Tenant::id();$uid=(int)Auth::user()['id'];if(!$companyId)$companyId=AccountingRepository::companyId()?:null;if(!$companyId||!self::companyOwned($wid,$companyId))throw new RuntimeException('شرکت انتخاب‌شده معتبر نیست.');
        $envelope=AiContextEnvelope::build($wid,$companyId,$currentPageRefs,$attachedRefs);
        $conversationId=self::conversationIdForQueue($wid,$uid,$companyId,$conversationId,$prompt);
        $context=AiToolRegistry::bootstrapContext($wid,$companyId);$context['context_envelope']=$envelope;
        $history=self::conversationHistoryForQueue($wid,$uid,$companyId,$conversationId);
        if($history)$context['conversation_history']=$history;

        $legacy=AiContextEnvelope::legacyPageContext($envelope);if($legacy)$context['page_context']=$legacy;
        $st=pdo()->prepare("INSERT INTO ai_jobs (workspace_id,company_id,conversation_id,requested_by,job_type,prompt,status,priority,required_capability,context_json,created_at,updated_at) VALUES (?,?,?,?, 'agent_chat',?,'queued',100,'llm',?,NOW(),NOW())");
        $st->execute([$wid,$companyId,$conversationId,$uid,$prompt,json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$id=(int)pdo()->lastInsertId();
        Audit::log('ai.job.queued','ai_jobs',$id,'ثبت درخواست Business Copilot',null,null,['company_id'=>$companyId,'context_envelope_version'=>AiContextEnvelope::VERSION,'attached_entities'=>count((array)$envelope['attached_entities']),'page_entities'=>count((array)($envelope['current_page']['entities']??[]))]);return$id;
    }

    private static function boundedConversationText(string $value,int $limit): string
    {
        if($limit<=0)return'';
        return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
    }

    private static function conversationHistoryForQueue(int $wid,int $uid,int $companyId,int $conversationId): array
    {
        $st=pdo()->prepare("SELECT prompt,result_text,result_json FROM ai_jobs
            WHERE workspace_id=? AND requested_by=? AND company_id=? AND conversation_id=?
              AND status='succeeded' AND result_text IS NOT NULL AND result_text<>''
            ORDER BY id DESC LIMIT 3");
        $st->execute([$wid,$uid,$companyId,$conversationId]);
        $rows=array_reverse($st->fetchAll());$out=[];
        foreach($rows as $r){
            $meta=json_decode((string)($r['result_json']??''),true);if(!is_array($meta))$meta=[];
            $out[]=[
                'prompt'=>self::boundedConversationText((string)($r['prompt']??''),500),
                'result_text'=>self::boundedConversationText((string)($r['result_text']??''),1200),
                'mode'=>self::boundedConversationText((string)($meta['mode']??''),80),
                'tools_used'=>self::safeToolNames($meta['tools_used']??[]),
            ];
        }
        return$out;
    }

    public static function conversationJobsForUser(int $conversationId,int $limit=40,?int $companyId=null): array
    {
        $limit=max(1,min(100,$limit));$sql="SELECT id,prompt,status,result_text,error_text,created_at,completed_at FROM ai_jobs WHERE workspace_id=? AND requested_by=? AND conversation_id=?";$args=[Tenant::id(),(int)Auth::user()['id'],$conversationId];
        if($companyId!==null){$sql.=" AND company_id=?";$args[]=$companyId;}$sql.=" ORDER BY id ASC LIMIT $limit";$st=pdo()->prepare($sql);$st->execute($args);return$st->fetchAll();
    }

    public static function userJobs(int $limit=30): array
    {
        $limit=max(1,min(100,$limit));
        $st=pdo()->prepare("SELECT j.*,c.name company_name,n.node_name worker_name FROM ai_jobs j LEFT JOIN companies c ON c.id=j.company_id LEFT JOIN ai_worker_nodes n ON n.id=j.worker_node_id WHERE j.workspace_id=? AND j.requested_by=? ORDER BY j.id DESC LIMIT $limit");
        $st->execute([Tenant::id(),(int)Auth::user()['id']]);return $st->fetchAll();
    }

    public static function jobForUser(int $id): ?array
    {
        $st=pdo()->prepare("SELECT * FROM ai_jobs WHERE id=? AND workspace_id=? AND requested_by=? LIMIT 1");
        $st->execute([$id,Tenant::id(),(int)Auth::user()['id']]);$r=$st->fetch();return$r?:null;
    }

    public static function safeToolNames(mixed $value): array
    {
        if(!is_array($value))return[];$safe=[];
        foreach($value as $name){
            if(!is_string($name))continue;$name=trim($name);
            if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$name))continue;
            $safe[$name]=true;if(count($safe)>=32)break;
        }
        return array_keys($safe);
    }

    public static function safeModelMetrics(mixed $value): array
    {
        if(!is_array($value))return[];$safe=[];
        $allowed=['first_chunk_seconds','elapsed_seconds','prompt_eval_count','prompt_eval_duration','eval_count','eval_duration'];
        foreach($allowed as $key){
            if(!array_key_exists($key,$value)||!is_numeric($value[$key]))continue;
            $number=(float)$value[$key];if(!is_finite($number)||$number<0)continue;$safe[$key]=$number;
        }
        return $safe;
    }

    public static function liveJobStateForUser(int $id,?int $companyId=null): ?array
    {
        $st=pdo()->prepare(
            "SELECT j.id,j.status,j.result_text,j.result_json,j.error_text,
                    j.created_at,j.started_at,j.completed_at,j.updated_at,
                    n.node_name worker_name,c.name company_name
             FROM ai_jobs j
             LEFT JOIN ai_worker_nodes n ON n.id=j.worker_node_id
             LEFT JOIN companies c ON c.id=j.company_id
             WHERE j.id=? AND j.workspace_id=? AND j.requested_by=?".($companyId!==null?" AND j.company_id=?":"")."
             LIMIT 1"
        );
        $args=[$id,Tenant::id(),(int)Auth::user()['id']];if($companyId!==null)$args[]=$companyId;$st->execute($args);
        $row=$st->fetch();
        if(!$row)return null;
        $meta=json_decode((string)($row['result_json']??''),true);if(!is_array($meta))$meta=[];
        $live=(array)($meta['live']??[]);
        $trace=(array)($live['trace']??($meta['trace']??[]));
        $details=(array)($live['details']??[]);
        $status=(string)$row['status'];
        if(empty($live['stage']))$live['stage']=$status;
        if(empty($live['message'])){
            $live['message']=match($status){
                'queued'=>'در صف پردازش','leased'=>'Worker تخصیص داده شد','running'=>'در حال پردازش',
                'succeeded'=>'پردازش با موفقیت پایان یافت','failed'=>'پردازش ناموفق بود',default=>$status,
            };
        }
        $live['details']=$details;$live['trace']=array_slice($trace,-30);
        return [
            'id'=>(int)$row['id'],'status'=>$status,'worker_name'=>(string)($row['worker_name']??''),
            'company_name'=>(string)($row['company_name']??''),'result_text'=>(string)($row['result_text']??''),
            'error_text'=>(string)($row['error_text']??''),'created_at'=>(string)($row['created_at']??''),
            'started_at'=>(string)($row['started_at']??''),'completed_at'=>(string)($row['completed_at']??''),
            'updated_at'=>(string)($row['updated_at']??''),'live'=>$live,
            'metrics'=>array_replace(self::safeModelMetrics($meta['attempted_metrics']??[]),self::safeModelMetrics($meta['metrics']??[])),
            'mode'=>(string)($meta['mode']??''),'model'=>(string)($meta['model']??''),
            'tools_used'=>self::safeToolNames($meta['tools_used']??[]),
            'tools_attempted'=>self::safeToolNames($meta['tools_attempted']??[]),
            'commercial_hardening'=>(array)($meta['commercial_hardening']??[]),
            'terminal'=>in_array($status,['succeeded','failed'],true),
        ];
    }

    public static function proposalsForJob(int $jobId): array
    {
        $st=pdo()->prepare("SELECT * FROM ai_action_proposals WHERE workspace_id=? AND job_id=? ORDER BY id");
        $st->execute([Tenant::id(),$jobId]);return$st->fetchAll();
    }

    public static function suggestions(int $limit=30): array
    {
        $limit=max(1,min(100,$limit));$uid=(int)Auth::user()['id'];
        $st=pdo()->prepare("SELECT s.*,c.name company_name FROM ai_suggestions s LEFT JOIN companies c ON c.id=s.company_id WHERE s.workspace_id=? AND (s.user_id IS NULL OR s.user_id=?) AND s.status='new' ORDER BY COALESCE(s.due_at,'2999-12-31'),s.id DESC LIMIT $limit");
        $st->execute([Tenant::id(),$uid]);return$st->fetchAll();
    }

    public static function createWorkerToken(string $label,array $capabilities=['llm','rag','forecast']): string
    {
        Tenant::requirePermission('ai.workers.manage');$raw='aiw_'.bin2hex(random_bytes(24));
        $prefix=substr($raw,0,12);$hash=hash('sha256',$raw);
        $st=pdo()->prepare("INSERT INTO ai_worker_tokens (workspace_id,label,token_prefix,token_hash,capabilities_json,active,created_by,created_at) VALUES (?,?,?,?,?,1,?,NOW())");
        $st->execute([Tenant::id(),trim($label)?:'Local AI Worker',$prefix,$hash,json_encode(array_values($capabilities)),(int)Auth::user()['id']]);
        Audit::log('ai.worker_token.create','ai_worker_tokens',(int)pdo()->lastInsertId(),'ساخت توکن Worker AI');
        return $raw;
    }

    public static function revokeWorkerToken(int $id): void
    {
        Tenant::requirePermission('ai.workers.manage');
        $wid=Tenant::id();$pdo=pdo();$pdo->beginTransaction();
        try{
            $st=$pdo->prepare("UPDATE ai_worker_tokens SET active=0 WHERE id=? AND workspace_id=? AND active=1");
            $st->execute([$id,$wid]);
            if($st->rowCount()){
                $pdo->prepare("UPDATE ai_worker_nodes SET status='offline',updated_at=NOW() WHERE workspace_id=? AND token_id=?")->execute([$wid,$id]);
            }
            $pdo->commit();
            if($st->rowCount())Audit::log('ai.worker_token.revoke','ai_worker_tokens',$id,'لغو دسترسی توکن Worker AI');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function workerTokens(): array
    {
        $st=pdo()->prepare("SELECT id,label,token_prefix,capabilities_json,active,created_at,last_used_at FROM ai_worker_tokens WHERE workspace_id=? ORDER BY id DESC");
        $st->execute([Tenant::id()]);return$st->fetchAll();
    }

    public static function workers(): array
    {
        $st=pdo()->prepare("SELECT * FROM ai_worker_nodes WHERE workspace_id=? ORDER BY last_seen_at DESC,id DESC");
        $st->execute([Tenant::id()]);return$st->fetchAll();
    }

    public static function authenticateWorker(string $token): array
    {
        $hash=hash('sha256',$token);$st=pdo()->prepare("SELECT * FROM ai_worker_tokens WHERE token_hash=? AND active=1 LIMIT 1");$st->execute([$hash]);$r=$st->fetch();
        if(!$r)throw new RuntimeException('worker_token_invalid');
        pdo()->prepare("UPDATE ai_worker_tokens SET last_used_at=NOW() WHERE id=?")->execute([(int)$r['id']]);return$r;
    }

    public static function registerNode(array $token,array $payload): array
    {
        $wid=(int)$token['workspace_id'];$uid=trim((string)($payload['node_uid']??''));if($uid==='')throw new RuntimeException('node_uid_required');
        $name=trim((string)($payload['node_name']??$uid));
        $requestedCaps=array_values(array_unique(array_map('strval',(array)($payload['capabilities']??[]))));
        $tokenCaps=json_decode((string)($token['capabilities_json']??'[]'),true)?:[];
        $cap=$tokenCaps ? array_values(array_intersect($requestedCaps,array_map('strval',$tokenCaps))) : $requestedCaps;
        $models=array_values(array_map('strval',(array)($payload['models']??[])));
        $st=pdo()->prepare("INSERT INTO ai_worker_nodes (workspace_id,token_id,node_uid,node_name,status,os_name,cpu_model,cpu_cores,ram_mb,capabilities_json,models_json,metadata_json,current_jobs,last_seen_at,created_at,updated_at)
            VALUES (?,?,?,?,'online',?,?,?,?,?,?,?,0,NOW(),NOW(),NOW())
            ON DUPLICATE KEY UPDATE token_id=VALUES(token_id),node_name=VALUES(node_name),status='online',os_name=VALUES(os_name),cpu_model=VALUES(cpu_model),cpu_cores=VALUES(cpu_cores),ram_mb=VALUES(ram_mb),capabilities_json=VALUES(capabilities_json),models_json=VALUES(models_json),metadata_json=VALUES(metadata_json),last_seen_at=NOW(),updated_at=NOW()");
        $st->execute([$wid,(int)$token['id'],$uid,$name,trim((string)($payload['os_name']??'')),trim((string)($payload['cpu_model']??'')),max(1,(int)($payload['cpu_cores']??1)),max(0,(int)($payload['ram_mb']??0)),json_encode($cap),json_encode($models),json_encode((array)($payload['metadata']??[]))]);
        $q=pdo()->prepare("SELECT * FROM ai_worker_nodes WHERE workspace_id=? AND node_uid=? AND token_id=? LIMIT 1");$q->execute([$wid,$uid,(int)$token['id']]);$node=$q->fetch();
        if(!$node)throw new RuntimeException('worker_node_not_found');
        $c=pdo()->prepare("SELECT COUNT(*) FROM ai_jobs WHERE workspace_id=? AND worker_node_id=? AND status IN ('leased','running') AND (lease_expires_at IS NULL OR lease_expires_at>=NOW())");$c->execute([$wid,(int)$node['id']]);$jobs=(int)$c->fetchColumn();
        if((int)$node['current_jobs']!==$jobs){pdo()->prepare("UPDATE ai_worker_nodes SET current_jobs=? WHERE id=?")->execute([$jobs,(int)$node['id']]);$node['current_jobs']=$jobs;}
        return$node;
    }

    public static function leaseJob(array $token,array $node,int $seconds=180): ?array
    {
        $wid=(int)$token['workspace_id'];$nid=(int)$node['id'];$caps=json_decode($node['capabilities_json']??'[]',true)?:[];$seconds=max(30,min(900,$seconds));
        $pdo=pdo();$pdo->beginTransaction();
        try{
            // Requeue expired work before leasing. A stale result cannot complete without its lease secret.
            $pdo->prepare("UPDATE ai_jobs SET status='queued',worker_node_id=NULL,lease_hash=NULL,leased_at=NULL,lease_expires_at=NULL,updated_at=NOW() WHERE workspace_id=? AND status IN ('leased','running') AND lease_expires_at IS NOT NULL AND lease_expires_at<NOW()") ->execute([$wid]);
            $params=[$wid];$where="workspace_id=? AND status='queued' AND (required_capability IS NULL OR required_capability='')";
            if($caps){$ph=implode(',',array_fill(0,count($caps),'?'));$where="workspace_id=? AND status='queued' AND (required_capability IS NULL OR required_capability='' OR required_capability IN ($ph))";$params=array_merge([$wid],$caps);}
            $st=$pdo->prepare("SELECT * FROM ai_jobs WHERE $where ORDER BY priority ASC,id ASC LIMIT 1 FOR UPDATE");$st->execute($params);$job=$st->fetch()?:null;
            if(!$job){$pdo->commit();return null;}
            $lease=bin2hex(random_bytes(24));$hash=hash('sha256',$lease);
            $u=$pdo->prepare("UPDATE ai_jobs SET status='leased',worker_node_id=?,lease_hash=?,leased_at=NOW(),lease_expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=? AND status='queued'");
            $u->execute([$nid,$hash,$seconds,(int)$job['id']]);if($u->rowCount()!==1){$pdo->rollBack();return null;}
            $pdo->prepare("UPDATE ai_worker_nodes SET current_jobs=current_jobs+1,last_seen_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$nid]);$pdo->commit();
            $job['lease_token']=$lease;$job['worker_node_id']=$nid;$job['status']='leased';return$job;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function validateLease(array $token,int $jobId,int $nodeId,string $lease): array
    {
        $st=pdo()->prepare("SELECT * FROM ai_jobs WHERE id=? AND workspace_id=? AND worker_node_id=? AND status IN ('leased','running') LIMIT 1");$st->execute([$jobId,(int)$token['workspace_id'],$nodeId]);$j=$st->fetch();
        if(!$j||empty($j['lease_hash'])||!hash_equals($j['lease_hash'],hash('sha256',$lease)))throw new RuntimeException('lease_invalid');
        if($j['lease_expires_at'] && strtotime($j['lease_expires_at'])<time())throw new RuntimeException('lease_expired');return$j;
    }

    private static function lockJobForTerminalWrite(array $token,int $jobId,int $nodeId,string $lease): array
    {
        $st=pdo()->prepare("SELECT * FROM ai_jobs WHERE id=? AND workspace_id=? AND worker_node_id=? LIMIT 1 FOR UPDATE");
        $st->execute([$jobId,(int)$token['workspace_id'],$nodeId]);$j=$st->fetch();
        if(!$j||empty($j['lease_hash'])||!hash_equals((string)$j['lease_hash'],hash('sha256',$lease)))throw new RuntimeException('lease_invalid');
        $status=(string)$j['status'];
        if(in_array($status,['succeeded','failed'],true)){
            $completed=strtotime((string)($j['completed_at']??''));
            if(!$completed||$completed<time()-86400)throw new RuntimeException('lease_retry_window_expired');
            return$j;
        }
        if(!in_array($status,['leased','running'],true))throw new RuntimeException('lease_invalid');
        if($j['lease_expires_at']&&strtotime((string)$j['lease_expires_at'])<time())throw new RuntimeException('lease_expired');
        return$j;
    }

    public static function completeJob(array $token,array $node,int $jobId,string $lease,array $payload): bool
    {
        $pdo=pdo();$pdo->beginTransaction();
        try{
            $j=self::lockJobForTerminalWrite($token,$jobId,(int)$node['id'],$lease);
            if((string)$j['status']==='succeeded'){$pdo->commit();return true;}
            if((string)$j['status']==='failed')throw new RuntimeException('job_terminal_conflict');
            $text=trim((string)($payload['result_text']??''));$result=(array)($payload['result']??[]);
            $done=$pdo->prepare("UPDATE ai_jobs SET status='succeeded',result_text=?,result_json=?,completed_at=NOW(),updated_at=NOW() WHERE id=? AND status IN ('leased','running')");
            $done->execute([$text,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$jobId]);
            if($done->rowCount()!==1)throw new RuntimeException('job_terminal_conflict');
            foreach((array)($payload['proposals']??[]) as $p){AiToolRegistry::storeProposal((int)$j['workspace_id'],$j['company_id']?(int)$j['company_id']:null,$jobId,(string)($p['tool_name']??''),(array)($p['arguments']??[]),(string)($p['summary']??''));}
            foreach((array)($payload['suggestions']??[]) as $s){$pdo->prepare("INSERT INTO ai_suggestions (workspace_id,company_id,user_id,suggestion_type,title,body,evidence_json,score,status,due_at,source_job_id,created_at) VALUES (?,?,?,?,?,?,?,?, 'new',?,?,NOW())")
                ->execute([(int)$j['workspace_id'],$j['company_id']?(int)$j['company_id']:null,(int)$j['requested_by'],substr((string)($s['type']??'general'),0,60),substr((string)($s['title']??'پیشنهاد هوشمند'),0,190),(string)($s['body']??''),json_encode((array)($s['evidence']??[])),isset($s['score'])?(float)$s['score']:null,$s['due_at']??null,$jobId]);}
            $pdo->prepare("UPDATE ai_worker_nodes SET current_jobs=GREATEST(current_jobs-1,0),last_seen_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$node['id']]);$pdo->commit();
            return false;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function failJob(array $token,array $node,int $jobId,string $lease,string $error): bool
    {
        $pdo=pdo();$pdo->beginTransaction();
        try{
            $j=self::lockJobForTerminalWrite($token,$jobId,(int)$node['id'],$lease);
            if((string)$j['status']==='failed'){$pdo->commit();return true;}
            if((string)$j['status']==='succeeded')throw new RuntimeException('job_terminal_conflict');
            $done=$pdo->prepare("UPDATE ai_jobs SET status='failed',error_text=?,completed_at=NOW(),updated_at=NOW() WHERE id=? AND status IN ('leased','running')");
            $done->execute([mb_substr($error,0,10000),$jobId]);
            if($done->rowCount()!==1)throw new RuntimeException('job_terminal_conflict');
            $pdo->prepare("UPDATE ai_worker_nodes SET current_jobs=GREATEST(current_jobs-1,0),last_seen_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$node['id']]);
            $pdo->commit();return false;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function findNode(array $token,string $nodeUid): array
    {
        $st=pdo()->prepare("SELECT * FROM ai_worker_nodes WHERE workspace_id=? AND token_id=? AND node_uid=? LIMIT 1");
        $st->execute([(int)$token['workspace_id'],(int)$token['id'],trim($nodeUid)]);$node=$st->fetch();
        if(!$node)throw new RuntimeException('worker_node_not_found');
        return $node;
    }

    public static function touchLease(array $token,array $node,int $jobId,string $lease,int $seconds=300,array $progress=[]): array
    {
        $job=self::validateLease($token,$jobId,(int)$node['id'],$lease);$seconds=max(60,min(900,$seconds));
        if($progress){
            $safe=[
                'stage'=>mb_substr((string)($progress['stage']??''),0,80),
                'message'=>mb_substr((string)($progress['message']??''),0,500),
                'at'=>mb_substr((string)($progress['at']??''),0,80),
                'details'=>(array)($progress['details']??[]),
                'trace'=>array_slice((array)($progress['trace']??[]),-30),
            ];
            $live=json_encode(['live'=>$safe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            pdo()->prepare("UPDATE ai_jobs SET status='running',started_at=COALESCE(started_at,NOW()),lease_expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND),result_json=?,updated_at=NOW() WHERE id=?")
                ->execute([$seconds,$live,$jobId]);
        }else{
            pdo()->prepare("UPDATE ai_jobs SET status='running',started_at=COALESCE(started_at,NOW()),lease_expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=?")
                ->execute([$seconds,$jobId]);
        }
        pdo()->prepare("UPDATE ai_worker_nodes SET status='online',last_seen_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$node['id']]);
        $job['status']='running';return$job;
    }

    public static function approveProposal(int $proposalId): array
    {
        Tenant::requirePermission('ai.actions.approve');$wid=Tenant::id();$pdo=pdo();$pdo->beginTransaction();
        try{$st=$pdo->prepare("SELECT * FROM ai_action_proposals WHERE id=? AND workspace_id=? AND status='proposed' FOR UPDATE");$st->execute([$proposalId,$wid]);$p=$st->fetch();if(!$p)throw new RuntimeException('پیشنهاد در وضعیت قابل تایید نیست.');
            $pdo->prepare("UPDATE ai_action_proposals SET status='approved',approved_by=?,approved_at=NOW() WHERE id=?")->execute([(int)Auth::user()['id'],$proposalId]);
            $result=AiToolRegistry::executeProposal($p,(int)Auth::user()['id']);
            $pdo->prepare("UPDATE ai_action_proposals SET status='executed',result_json=?,executed_by=?,executed_at=NOW() WHERE id=?")->execute([json_encode($result,JSON_UNESCAPED_UNICODE),(int)Auth::user()['id'],$proposalId]);$pdo->commit();
            Audit::log('ai.action.execute','ai_action_proposals',$proposalId,'اجرای عملیات تاییدشده AI',null,null,['tool'=>$p['tool_name']]);return$result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function rejectProposal(int $proposalId): void
    {
        Tenant::requirePermission('ai.actions.approve');$st=pdo()->prepare("UPDATE ai_action_proposals SET status='rejected',rejected_by=?,rejected_at=NOW() WHERE id=? AND workspace_id=? AND status='proposed'");$st->execute([(int)Auth::user()['id'],$proposalId,Tenant::id()]);
    }

    private static function companyOwned(int $wid,int $cid): bool
    {
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1");$st->execute([$cid,$wid]);return(bool)$st->fetchColumn();
    }
}
