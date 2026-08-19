<?php
require __DIR__.'/app/bootstrap.php';
function ai_live_json(array $payload,int $status=200): never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function ai_live_flush(): void{if(function_exists('ob_flush')){@ob_flush();}flush();}
if(!Auth::check())ai_live_json(['ok'=>false,'error'=>'auth_required'],401);
try{Tenant::requirePermission('ai.use');}catch(Throwable $e){ai_live_json(['ok'=>false,'error'=>'forbidden'],403);}
$jobId=(int)($_GET['job_id']??0);if($jobId<1)ai_live_json(['ok'=>false,'error'=>'job_id_required'],400);$format=strtolower(trim((string)($_GET['format']??'sse')));
if($format==='json'){$job=AiRepository::liveJobStateForUser($jobId);if(!$job)ai_live_json(['ok'=>false,'error'=>'job_not_found'],404);ai_live_json(['ok'=>true,'job'=>$job,'server_time'=>date(DATE_ATOM)]);}
$first=AiRepository::liveJobStateForUser($jobId);if(!$first)ai_live_json(['ok'=>false,'error'=>'job_not_found'],404);
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();@set_time_limit(28);@ini_set('zlib.output_compression','0');@ini_set('output_buffering','0');while(ob_get_level()>0){@ob_end_flush();}
header('Content-Type: text/event-stream; charset=utf-8');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('X-Accel-Buffering: no');header('Connection: keep-alive');
echo "retry: 1500\n";echo ':'.str_repeat(' ',2048)."\n\n";ai_live_flush();$deadline=microtime(true)+24.0;$lastHash='';$lastPing=0.0;
while(microtime(true)<$deadline&&!connection_aborted()){$job=AiRepository::liveJobStateForUser($jobId);if(!$job){$payload=json_encode(['ok'=>false,'error'=>'job_not_found'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);echo "event: done\ndata: ".$payload."\n\n";ai_live_flush();break;}$json=json_encode(['ok'=>true,'job'=>$job,'server_time'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=sha1($json);if($hash!==$lastHash){echo 'event: '.($job['terminal']?'done':'job')."\n";echo 'id: '.str_replace(["\r","\n"],'',(string)$job['updated_at'])."\n";echo 'data: '.$json."\n\n";ai_live_flush();$lastHash=$hash;}if($job['terminal'])break;if(microtime(true)-$lastPing>8.0){echo ': ping '.time()."\n\n";ai_live_flush();$lastPing=microtime(true);}usleep(800000);}
