<?php
/** Standalone DB-free deterministic behavioral tests of the SAME transition planner. */
require_once dirname(__DIR__).'/app/Core/WorkflowDomain.php';
function ok(bool $value,string $name): void { if(!$value){fwrite(STDERR,"FAIL: $name\n");exit(1);} }
function mustFail(callable $f,string $name): void { try {$f();}catch(RuntimeException $e){return;} ok(false,$name); }
$templates=WorkflowDomain::templates();
ok(count($templates)===12,'template count');
ok($templates['purchase_approval'][1]==='procurement','procurement template');
$types=WorkflowDomain::subjectTypes();
ok(isset($types['sales'],$types['purchase'],$types['leave'],$types['expense'],$types['bom']),'canonical subject allowlist');
$case=['status'=>'open','opened_by'=>101,'current_step'=>1];
$step=['step_no'=>1,'assignee_user_id'=>202,'requires_note'=>1];
$next=['step_no'=>2,'due_hours'=>16];
$r=WorkflowDomain::planDecision($case,$step,$next,202,'approve','کنترل شد');
ok($r['status']==='open'&&$r['step']===2&&$r['due_hours']===16,'advance to next step');
$r=WorkflowDomain::planDecision($case,$step,null,202,'approve','تأیید نهایی');
ok($r['status']==='approved'&&$r['due_hours']===null,'approve final');
$r=WorkflowDomain::planDecision($case,$step,$next,202,'reject','علت مستند');
ok($r['status']==='rejected','rejection closes');
$r=WorkflowDomain::planDecision($case,null,null,101,'cancel','');
ok($r['status']==='cancelled','cancel');
mustFail(fn()=>WorkflowDomain::planDecision($case,$step,$next,101,'approve','تأیید'),'separation of duties');
mustFail(fn()=>WorkflowDomain::planDecision($case,$step,$next,203,'approve','تأیید'),'assignee enforcement');
mustFail(fn()=>WorkflowDomain::planDecision($case,$step,$next,202,'approve',''),'mandatory note');
mustFail(fn()=>WorkflowDomain::planDecision($case,$step,$next,202,'reject',''),'rejection reason');
mustFail(fn()=>WorkflowDomain::planDecision(['status'=>'approved','opened_by'=>101,'current_step'=>1],$step,$next,202,'approve','متن'),'closed case');
mustFail(fn()=>WorkflowDomain::planDecision($case,null,$next,202,'approve','تأیید'),'missing step');
mustFail(fn()=>WorkflowDomain::planDecision($case,$step,['step_no'=>5,'due_hours'=>1],202,'approve','تأیید'),'invalid next step');
$r=WorkflowDomain::planDecision($case,['step_no'=>1,'assignee_user_id'=>null,'requires_note'=>0],['step_no'=>2,'due_hours'=>0],203,'approve','');
ok($r['status']==='open'&&$r['due_hours']===1,'group approval with bounded SLA');
echo "WORKFLOW_BEHAVIOR=PASS; checks=16; DB=NOT_TOUCHED\n";
