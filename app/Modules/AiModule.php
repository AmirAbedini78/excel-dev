<?php
final class AiModule
{
    public static function handle(string $action): void
    {
        Tenant::requirePermission('ai.use');
        if($action==='ai_queue_chat'){
            $id=AiRepository::queueChat((string)($_POST['prompt']??''),(int)($_POST['company_id']??0)?:null,(int)($_POST['conversation_id']??0)?:null);
            flash('درخواست به صف موتور AI اضافه شد.');redirect('index.php?page=ai&job_id='.$id);
        }
        if($action==='ai_approve_proposal'){
            $result=AiRepository::approveProposal((int)($_POST['proposal_id']??0));flash('عملیات تایید و اجرا شد: '.(string)($result['document_no']??$result['voucher_no']??$result['id']??''));redirect('index.php?page=ai');
        }
        if($action==='ai_reject_proposal'){
            AiRepository::rejectProposal((int)($_POST['proposal_id']??0));flash('پیشنهاد ایجنت رد شد.','warn');redirect('index.php?page=ai');
        }
        if($action==='ai_create_worker_token'){
            Tenant::requirePermission('ai.workers.manage');$token=AiRepository::createWorkerToken((string)($_POST['label']??'Local AI Worker'));$_SESSION['ai_new_worker_token']=$token;flash('توکن Worker ساخته شد؛ فقط همین یک‌بار متن کامل آن نمایش داده می‌شود.');redirect('index.php?page=ai&section=workers');
        }
        if($action==='ai_revoke_worker_token'){
            Tenant::requirePermission('ai.workers.manage');AiRepository::revokeWorkerToken((int)($_POST['token_id']??0));flash('دسترسی توکن Worker لغو شد.','warn');redirect('index.php?page=ai&section=workers');
        }
        if($action==='ai_dismiss_suggestion'){
            pdo()->prepare("UPDATE ai_suggestions SET status='dismissed',acted_at=NOW() WHERE id=? AND workspace_id=?")->execute([(int)($_POST['suggestion_id']??0),Tenant::id()]);redirect('index.php?page=ai&section=suggestions');
        }
    }

    public static function render(): void
    {
        Tenant::requirePermission('ai.use');$section=(string)($_GET['section']??'assistant');if(!in_array($section,['assistant','suggestions','workers'],true))$section='assistant';
        render_header('دستیار هوشمند حسابداری','کنترل‌پلین AI؛ پردازش روی Workerهای محلی و عملیات مالی با تایید انسانی.');
        echo '<nav class="acc-tabs ai-tabs"><a class="'.($section==='assistant'?'active':'').'" href="index.php?page=ai">ایجنت</a><a class="'.($section==='suggestions'?'active':'').'" href="index.php?page=ai&section=suggestions">پیشنهادهای هوشمند</a>';
        if(Tenant::can('ai.workers.manage'))echo '<a class="'.($section==='workers'?'active':'').'" href="index.php?page=ai&section=workers">Workerها و موتور محلی</a>';echo '</nav>';
        match($section){'workers'=>self::workers(),'suggestions'=>self::suggestions(),default=>self::assistant()};render_footer();
    }

    private static function assistant(): void
    {
        $companies=AccountingRepository::companies();$current=AccountingRepository::companyId();
        echo '<section class="card ai-hero"><div><span class="ai-kicker">Agent Control Plane</span><h2>به نرم‌افزار دستور بده، نه به منوها</h2><p>مثال: «برای مشتری فلانی از این سه کالا فاکتور پیش‌نویس بساز» یا «تراز آزمایشی را بررسی کن و مغایرت‌های مهم را بگو».</p></div><div class="ai-safety">عملیات نوشتنی → Proposal → تایید انسانی → اجرا</div></section>';
        echo '<section class="card"><form method="post" class="ai-chat-form">'.csrf_field().'<input type="hidden" name="action" value="ai_queue_chat"><div class="ai-chat-context"><label>شرکت<select name="company_id">';foreach($companies as $c)echo '<option value="'.(int)$c['id'].'" '.((int)$c['id']===$current?'selected':'').'>'.h($c['name']).'</option>';echo '</select></label><span class="muted">پردازش LLM/RAG بیرون از cPanel انجام می‌شود.</span></div><textarea name="prompt" rows="4" required placeholder="مثلاً: آخرین خرید و فروش شرکت را تحلیل کن و اگر لازم است پیش‌نویس اقدام پیشنهاد بده..."></textarea><button class="btn primary">ارسال به ایجنت</button></form></section>';
        $jobs=AiRepository::userJobs(25);$liveJobs=array_values(array_filter($jobs,fn($x)=>in_array((string)$x['status'],['queued','leased','running'],true)));
        if($liveJobs){
            echo '<section class="card" id="ai-live-panel"><div class="section-title"><div><h2>گزارش زنده موتور AI</h2><p class="muted">بدون رفرش صفحه؛ اتصال زنده SSE و در صورت محدودیت هاست، Polling سبک خودکار.</p></div><span class="badge" data-ai-transport>در حال اتصال…</span></div>';
            foreach($liveJobs as $lj){
                $meta=json_decode((string)($lj['result_json']??''),true);if(!is_array($meta))$meta=[];$live=(array)($meta['live']??[]);$trace=(array)($live['trace']??[]);$details=(array)($live['details']??[]);$jid=(int)$lj['id'];
                echo '<article class="ai-suggestion" data-ai-live-job="'.$jid.'"><div style="width:100%"><b>Job #'.$jid.' — <span data-ai-live-status>'.h(self::statusText((string)$lj['status'])).'</span></b>';
                echo '<p data-ai-live-message>'.h((string)($live['message']??'در انتظار گزارش Worker...')).'</p><small>مرحله: <code data-ai-live-stage>'.h((string)($live['stage']??$lj['status'])).'</code> • Worker: <span data-ai-live-worker>'.h((string)($lj['worker_name']??'در انتظار تخصیص')).'</span> • <span data-ai-live-elapsed>'.(isset($details['elapsed_seconds'])?'زمان: '.h((string)$details['elapsed_seconds']).'s':'').'</span></small>';
                echo '<div class="muted" data-ai-live-metrics style="margin-top:6px"></div><details '.($trace?'':'hidden').' data-ai-live-trace-box><summary>ردپای اجرای اخیر</summary><ol style="margin:10px 18px" data-ai-live-trace>';
                foreach($trace as $ev)echo '<li><code>'.h((string)($ev['stage']??'')).'</code> — '.h((string)($ev['message']??'')).'</li>';
                echo '</ol></details></div></article>';
            }
            echo '</section>';
        }
        echo '<section class="ai-thread">';
        if(!$jobs)echo '<article class="card acc-empty"><h3>هنوز درخواستی برای ایجنت ثبت نشده است.</h3><p>بعد از اجرای Worker محلی، درخواست‌های این صفحه به یکی از سیستم‌های شما Lease می‌شوند.</p></article>';
        foreach($jobs as $j){$jid=(int)$j['id'];$proposals=AiRepository::proposalsForJob($jid);echo '<article class="card ai-message" data-ai-job="'.$jid.'"><div class="ai-message-head"><div><b>'.h($j['company_name']??'بدون شرکت').'</b><small data-ai-job-status>#'.$jid.' • '.h($j['status']).($j['worker_name']?' • '.h($j['worker_name']):'').'</small></div><time>'.h($j['created_at']).'</time></div><div class="ai-user-prompt">'.nl2br(h($j['prompt'])).'</div><div data-ai-result="'.$jid.'">';
            if($j['result_text'])echo '<div class="ai-answer"><strong>پاسخ ایجنت</strong><div>'.nl2br(h($j['result_text'])).'</div>'.self::metricsHtml($j).'</div>';elseif($j['error_text'])echo '<div class="alert danger">'.nl2br(h($j['error_text'])).'</div>';else echo '<div class="ai-pending">'.self::statusText((string)$j['status']).'</div>';
            echo '</div>';foreach($proposals as $p)self::proposalCard($p);echo '</article>';
        }
        echo '</section>';if($liveJobs)echo '<script src="assets/ai-live.js?v=10.1.0"></script>';
    }

    private static function metricsHtml(array $job): string
    {
        $meta=json_decode((string)($job['result_json']??''),true);
        if(!is_array($meta))return'';

        $m=array_replace(AiRepository::safeModelMetrics($meta['attempted_metrics']??[]),AiRepository::safeModelMetrics($meta['metrics']??[]));
        $parts=[];

        $mode=(string)($meta['mode']??'');
        $modeLabels=[
            'deterministic_financial_report'=>'گزارش سریع قطعی',
            'deep_financial_analysis'=>'تحلیل عمیق محلی',
            'deep_financial_analysis_fallback'=>'گزارش قطعی؛ تحلیل عمیق تکمیل نشد',
            'tool_agent'=>'ایجنت ابزارمحور',
            'fast_read_analysis'=>'تحلیل سریع',
            'grounded_multi_read'=>'خواندن چندبخشی Grounded',
            'accounting_workflow_read'=>'Workflow حسابداری Grounded',
            'accounting_workflow_partial'=>'Workflow حسابداری با نتیجه جزئی',
            'accounting_workflow_blocked'=>'Workflow حسابداری مسدودشده',
            'accounting_action_proposal'=>'Proposal اقدام حسابداری',
            'accounting_action_noop'=>'اقدام حسابداری؛ شرط برقرار نبود',
            'accounting_action_blocked'=>'اقدام حسابداری مسدودشده',
            'accounting_action_rejected'=>'اقدام حسابداری ردشده',
            'guarded_sales_invoice_proposal'=>'Proposal فاکتور فروش',
            'guarded_sales_invoice_blocked'=>'فاکتور فروش مسدودشده',
            'guarded_purchase_invoice_proposal'=>'Proposal فاکتور خرید',
            'guarded_purchase_invoice_blocked'=>'فاکتور خرید مسدودشده',
            'guarded_check_proposal'=>'Proposal چک',
            'guarded_check_blocked'=>'عملیات چک مسدودشده',
            'treasury_check_read'=>'گزارش Grounded چک‌ها',
            'treasury_check_read_blocked'=>'گزارش چک‌ها مسدودشده',
            'inventory_warehouses_read'=>'فهرست Grounded انبارها',
            'inventory_position_read'=>'وضعیت Grounded موجودی',
            'inventory_replenishment_read'=>'ریسک تأمین و نقطه سفارش',
            'procurement_pipeline_read'=>'جریان خرید و ورودی مورد انتظار',
            'guarded_inventory_receipt_proposal'=>'Proposal رسید انبار',
            'guarded_inventory_receipt_blocked'=>'رسید انبار مسدودشده',
            'financial_intelligence'=>'هوشمندی مالی',
            'financial_intelligence_blocked'=>'هوشمندی مالی مسدودشده',
            'forecast_risk_anomaly'=>'پیش‌بینی، ریسک و ناهنجاری',
            'forecast_risk_blocked'=>'پیش‌بینی مسدودشده',
            'proactive_accounting'=>'پایش پیش‌دستانه حسابداری',
            'proactive_accounting_no_action'=>'پایش پیش‌دستانه؛ اقدام مهمی نبود',
            'proactive_accounting_blocked'=>'پایش پیش‌دستانه مسدودشده',
            'adaptive_cache_read'=>'خواندن تطبیقی از Plan معتبر',
            'adaptive_llm_read'=>'خواندن تطبیقی با Planner',
        ];
        if($mode!=='')$parts[]='مسیر: '.($modeLabels[$mode]??$mode);

        $model=(string)($meta['model']??'');
        if($model!=='')$parts[]='مدل: '.($model==='none'?'بدون LLM':$model);

        $toolsUsed=AiRepository::safeToolNames($meta['tools_used']??[]);
        $toolsAttempted=AiRepository::safeToolNames($meta['tools_attempted']??[]);
        if($toolsAttempted){
            $same=count($toolsAttempted)===count($toolsUsed) && !array_diff($toolsAttempted,$toolsUsed);
            if($same)$parts[]='ابزارها: '.implode('، ',$toolsAttempted);
            else{
                $parts[]='ابزارهای تلاش‌شده: '.implode('، ',$toolsAttempted);
                if($toolsUsed)$parts[]='ابزارهای موفق: '.implode('، ',$toolsUsed);
            }
        }elseif($toolsUsed)$parts[]='ابزارهای موفق: '.implode('، ',$toolsUsed);

        if(isset($m['first_chunk_seconds'])&&is_numeric($m['first_chunk_seconds']))$parts[]='اولین خروجی: '.number_format((float)$m['first_chunk_seconds'],1).'s';
        if(isset($m['elapsed_seconds'])&&is_numeric($m['elapsed_seconds']))$parts[]='زمان مدل: '.number_format((float)$m['elapsed_seconds'],1).'s';

        $pc=(float)($m['prompt_eval_count']??0);
        $pd=(float)($m['prompt_eval_duration']??0);
        if($pc>0&&$pd>0)$parts[]='Prompt: '.number_format($pc/($pd/1000000000),1).' tok/s';

        $ec=(float)($m['eval_count']??0);
        $ed=(float)($m['eval_duration']??0);
        if($ec>0&&$ed>0)$parts[]='Generation: '.number_format($ec/($ed/1000000000),1).' tok/s';

        $hard=(array)($meta['commercial_hardening']??[]);
        if(isset($hard['end_to_end_seconds'])&&is_numeric($hard['end_to_end_seconds']))$parts[]='زمان کل: '.number_format((float)$hard['end_to_end_seconds'],1).'s';
        if(!empty($hard['latency_status']))$parts[]='بودجه زمان: '.($hard['latency_status']==='within_budget'?'پاس':'بیش‌ازحد');
        $riskLabels=['low'=>'کم','medium'=>'متوسط','high'=>'بالا'];$risk=(string)($hard['risk_class']??'');
        if($risk!=='')$parts[]='ریسک مسیر: '.($riskLabels[$risk]??$risk);

        return $parts?'<div class="muted" style="margin-top:10px">'.h(implode(' • ',$parts)).'</div>':'';
    }

    private static function proposalCard(array $p): void
    {
        $args=json_decode((string)$p['arguments_json'],true)?:[];$risk=(string)$p['risk_level'];$riskLabels=['low'=>'کم','medium'=>'متوسط','high'=>'بالا'];$statusLabels=['proposed'=>'منتظر تایید انسانی','approved'=>'تاییدشده','executed'=>'اجراشده','rejected'=>'ردشده'];
        echo '<div class="ai-proposal"><div><span class="badge">ریسک '.h($riskLabels[$risk]??$risk).'</span><b>Proposal #'.(int)$p['id'].' — '.h($p['summary']).'</b><small>وضعیت: '.h($statusLabels[$p['status']]??$p['status']).' • Tool: '.h($p['tool_name']).'</small></div><details><summary>پارامترهای پیشنهادی Grounded</summary><pre>'.h(json_encode($args,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>';
        if($p['status']==='proposed' && Tenant::can('ai.actions.approve'))echo '<div class="row-actions"><form method="post">'.csrf_field().'<input type="hidden" name="action" value="ai_approve_proposal"><input type="hidden" name="proposal_id" value="'.(int)$p['id'].'"><button class="btn tiny primary" onclick="return confirm(\'Proposal #'.(int)$p['id'].' با ریسک '.h($riskLabels[$risk]??$risk).' پس از تایید روی دیتابیس اجرا شود؟\')">تایید انسانی و اجرا</button></form><form method="post">'.csrf_field().'<input type="hidden" name="action" value="ai_reject_proposal"><input type="hidden" name="proposal_id" value="'.(int)$p['id'].'"><button class="btn tiny danger">رد Proposal</button></form></div>';else echo '<small>این Proposal قابل اجرا نیست یا مجوز تایید در دسترس نیست.</small>';
        echo '</div>';
    }

    private static function suggestions(): void
    {
        AiSuggestionEngine::refreshCurrentUser();$rows=AiRepository::suggestions();echo '<section class="card"><div class="section-title"><div><h2>Next Best Action</h2><p class="muted">پیشنهادهایی که از رفتار، سررسیدها و داده‌های مالی استخراج می‌شوند.</p></div></div>';
        foreach($rows as $r)echo '<article class="ai-suggestion"><div><b>'.h($r['title']).'</b><p>'.nl2br(h($r['body'])).'</p><small>'.h($r['company_name']??'').($r['score']!==null?' • score '.h($r['score']):'').'</small></div><form method="post">'.csrf_field().'<input type="hidden" name="action" value="ai_dismiss_suggestion"><input type="hidden" name="suggestion_id" value="'.(int)$r['id'].'"><button class="btn tiny">بستن</button></form></article>';
        if(!$rows)echo '<div class="acc-empty">پیشنهاد فعالی وجود ندارد.</div>';echo '</section>';
    }

    private static function workers(): void
    {
        Tenant::requirePermission('ai.workers.manage');$new=$_SESSION['ai_new_worker_token']??'';unset($_SESSION['ai_new_worker_token']);
        if($new)echo '<section class="card ai-token"><h2>توکن Worker — فقط همین بار</h2><code>'.h($new).'</code><p>این مقدار را در فایل تنظیمات Worker محلی قرار بده و داخل Git ذخیره نکن.</p></section>';
        echo '<section class="card"><div class="section-title"><h2>ساخت Worker Token</h2></div><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="ai_create_worker_token"><input name="label" value="Local AI Worker" placeholder="نام توکن"><button class="btn primary">ساخت توکن</button></form></section>';
        echo '<section class="card table-card"><div class="section-title"><h2>نودهای متصل</h2><span class="muted">هر سیستم Worker خودش را اجرا می‌کند؛ صف مرکزی کار را بین نودهای آنلاین تقسیم می‌کند.</span></div><div class="table-wrap"><table><thead><tr><th>نام</th><th>CPU</th><th>RAM</th><th>قابلیت‌ها</th><th>مدل‌ها</th><th>Job فعال</th><th>آخرین Heartbeat</th></tr></thead><tbody>';
        foreach(AiRepository::workers() as $w){$caps=json_decode($w['capabilities_json']??'[]',true)?:[];$models=json_decode($w['models_json']??'[]',true)?:[];echo '<tr><td><b>'.h($w['node_name']).'</b><small>'.h($w['os_name']).'</small></td><td>'.(int)$w['cpu_cores'].'<small>'.h($w['cpu_model']).'</small></td><td>'.number_format(((int)$w['ram_mb'])/1024,1).' GB</td><td>'.h(implode(', ',$caps)).'</td><td>'.h(implode(', ',$models)).'</td><td>'.(int)$w['current_jobs'].'</td><td>'.h($w['last_seen_at']).'</td></tr>';}
        echo '</tbody></table></div></section>';
        echo '<section class="card table-card"><h2>توکن‌ها</h2><div class="table-wrap"><table><thead><tr><th>عنوان</th><th>Prefix</th><th>قابلیت‌ها</th><th>آخرین استفاده</th><th>وضعیت</th></tr></thead><tbody>';foreach(AiRepository::workerTokens() as $t){echo '<tr><td>'.h($t['label']).'</td><td><code>'.h($t['token_prefix']).'…</code></td><td>'.h(implode(', ',json_decode($t['capabilities_json']??'[]',true)?:[])).'</td><td>'.h($t['last_used_at']).'</td><td>'.((int)$t['active']?'فعال':'غیرفعال');if((int)$t['active'])echo '<form method="post" class="inline-form" style="margin-top:6px">'.csrf_field().'<input type="hidden" name="action" value="ai_revoke_worker_token"><input type="hidden" name="token_id" value="'.(int)$t['id'].'"><button class="btn tiny danger" onclick="return confirm(\'دسترسی این Worker Token لغو شود؟\')">لغو دسترسی</button></form>';echo '</td></tr>';}echo '</tbody></table></div></section>';
    }

    private static function statusText(string $s): string{return match($s){'queued'=>'در صف؛ منتظر Worker آنلاین','leased'=>'به Worker اختصاص داده شد','running'=>'در حال پردازش','succeeded'=>'موفق','failed'=>'ناموفق',default=>$s};}
}
