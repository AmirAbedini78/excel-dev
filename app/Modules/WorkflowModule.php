<?php
/** Workflow Studio: domain-mutating operations are deliberately NOT allowed here. */
final class WorkflowModule
{
    private static function link(string $filter='open',int $case=0,int $definition=0): string
    {
        $u='index.php?page=workflows&filter='.rawurlencode($filter);
        if($case>0)$u.='&case='.$case;
        if($definition>0)$u.='&definition='.$definition;
        return $u;
    }

    public static function handle(string $action): void
    {
        if(!in_array($action,['wf_define','wf_step','wf_status','wf_case','wf_decide'],true))throw new RuntimeException('عملیات گردش‌کار نامعتبر است.');
        if(!ModuleRegistry::enabled('workflows'))throw new RuntimeException('ماژول گردش‌کار غیرفعال است.');
        $wid=Tenant::id();$cid=AccountingRepository::companyId();
        $uid=(int)(Auth::user()['id']??0);
        $selectedDef=(int)($_POST['definition_id']??0);$selectedCase=(int)($_POST['case_id']??0);
        $destination=self::link('open',$selectedCase,$selectedDef);
        try{
            if($action==='wf_decide'){
                $decision=(string)($_POST['decision']??'');
                Tenant::requirePermission($decision==='cancel'?'workflow.manage':'workflow.approve');
                $res=WorkflowDomain::decide($wid,$cid,$uid,$selectedCase,(int)($_POST['revision']??0),$decision,(string)($_POST['note']??''),(string)($_POST['nonce']??''));
                flash($res['repeat']?'این درخواست قبلاً پردازش شده است.':'مرحله گردش‌کار ثبت شد. وضعیت جدید: '.$res['status']);
            }else{
                Tenant::requirePermission('workflow.manage');
                if($action==='wf_define'){
                    $id=WorkflowDomain::createDefinition($wid,$cid,$uid,$_POST);
                    $destination=self::link('open',0,$id);
                    flash('تعریف گردش‌کار ساخته شد. مراحل و مسئولان را قبل از فعال‌سازی بررسی کنید.');
                }elseif($action==='wf_step'){
                    WorkflowDomain::addStep($wid,$cid,$selectedDef,$_POST);
                    flash('مرحله به تعریف پیش‌نویس اضافه شد.');
                }elseif($action==='wf_status'){
                    WorkflowDomain::setDefinitionStatus($wid,$cid,$selectedDef,(string)($_POST['target']??''));
                    flash('وضعیت تعریف تغییر کرد؛ پرونده‌های جاری همچنان قابل بررسی‌اند.');
                }elseif($action==='wf_case'){
                    $id=WorkflowDomain::createCase($wid,$cid,$uid,$_POST);
                    $destination=self::link('open',$id);
                    flash('پرونده گردش‌کار باز شد؛ سند متصل هنوز تغییری نکرده است.');
                }
            }
        }catch(Throwable $e){
            flash($e instanceof PDOException?'ثبت انجام نشد؛ تکرار کد یا خطای دیتابیس.':$e->getMessage(),'danger');
        }
        redirect($destination);
    }

    private static function postStart(string $action): void
    {
        echo '<form method="post" class="wf-form">'.csrf_field().'<input type="hidden" name="action" value="'.h($action).'">';
    }

    private static function formatDate($raw): string
    {
        if(!$raw)return '—';
        return h((string)$raw);
    }

    public static function render(): void
    {
        Tenant::requirePermission('workflow.view');
        $wid=Tenant::id();$cid=AccountingRepository::companyId();
        render_header('موتور فرآیند و تأییدیه','تعریف مراحل واقعی، کنترل مسئول، سررسید، سوابق و جلوگیری از تصمیم تکراری');
        echo '<section class="card wf-banner"><div><span class="wf-pill">Workflow Studio • Pilot</span><h2>مرکز گردش‌کار سازمانی</h2><p>فرآیندها از شرکت‌های دیگر جدا هستند. تأیید یک پرونده به معنی صدور فاکتور، برداشت پول، تغییر موجودی یا محاسبه حقوق نیست.</p></div><div class="wf-alert">تفکیک وظایف فعال است: ثبت‌کننده نمی‌تواند پرونده خود را تأیید کند.</div></section>';
        echo '<section class="card"><form method="get" class="wf-company"><input type="hidden" name="page" value="workflows"><label>شرکت فعال <select name="company_id" onchange="this.form.submit()">';
        foreach(AccountingRepository::companies() as $company)echo '<option value="'.(int)$company['id'].'"'.((int)$company['id']===$cid?' selected':'').'>'.h($company['name']).'</option>';
        echo '</select></label><button class="btn tiny">تغییر شرکت</button></form></section>';
        if($cid<1){echo '<section class="card">ابتدا در بخش اطلاعات شرکت‌ها یک شرکت فعال انتخاب کنید.</section>';render_footer();return;}
        $canManage=Tenant::can('workflow.manage');$canApprove=Tenant::can('workflow.approve');
        $definitions=WorkflowDomain::definitions($wid,$cid);
        $stats=WorkflowDomain::stats($wid,$cid,(int)Auth::user()['id']);
        $filter=(string)($_GET['filter']??'open');if(!in_array($filter,['open','approved','rejected','cancelled','all'],true))$filter='open';
        $cases=WorkflowDomain::cases($wid,$cid,$filter);
        echo '<section class="wf-metrics">';
        foreach(['open'=>'در جریان','overdue'=>'گذشته از سررسید','mine'=>'ایجادشده توسط من','reviewable'=>'در صف بررسی من/گروه'] as $key=>$label)
            echo '<article class="card"><div class="wf-kpi">'.(int)$stats[$key].'</div><span>'.h($label).'</span></article>';
        echo '</section>';
        echo '<section class="card"><h2>پرونده‌های فرآیند</h2><div class="wf-tabs">';
        foreach(['open'=>'باز','approved'=>'تأییدشده','rejected'=>'ردشده','cancelled'=>'لغوشده','all'=>'همه'] as $key=>$label)
            echo '<a class="btn tiny'.($filter===$key?' primary':'').'" href="'.h(self::link($key)).'">'.h($label).'</a>';
        echo '</div><div class="wf-table"><table><thead><tr><th>کد / عنوان</th><th>فرآیند</th><th>وضعیت</th><th>مرحله</th><th>سررسید</th><th>مدرک</th><th>اقدام</th></tr></thead><tbody>';
        foreach($cases as $c){
            $overdue=!(empty($c['overdue']));
            echo '<tr><td><strong>'.h($c['code']).'</strong><small>'.h($c['title']).'</small></td><td>'.h($c['process_title']).'</td><td><span class="wf-state '.h($c['status']).'">'.h($c['status']).'</span></td><td>'.h($c['step_title']??'—').'</td><td class="'.($overdue?'wf-late':'').'">'.self::formatDate($c['due_at']).'</td><td>'.h($c['reference_type']).' #'.(int)$c['reference_id'].'</td><td><a class="btn tiny" href="'.h(self::link($filter,(int)$c['id'])).'">جزئیات / تصمیم</a></td></tr>';
        }
        if(!$cases)echo '<tr><td colspan="7">پرونده‌ای در این وضعیت ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
        $caseId=max(0,(int)($_GET['case']??0));
        if($caseId>0){
            $case=WorkflowDomain::getCase($wid,$cid,$caseId);
            if($case){
                $steps=WorkflowDomain::steps($wid,$cid,(int)$case['definition_id']);
                $current=null;foreach($steps as $s)if((int)$s['step_no']===(int)$case['current_step'])$current=$s;
                echo '<section class="card" id="wf-review"><h2>جزئیات پرونده '.h($case['code']).'</h2><p>'.h($case['description']??'').'</p><p><strong>مبلغ مرجع (ریال):</strong> '.number_format((float)$case['amount'],2).' | <strong>نسخه پرونده:</strong> '.(int)$case['revision'].' | <strong>مدرک:</strong> '.h($case['reference_type']).' #'.(int)$case['reference_id'].'</p>';
                echo '<ol class="wf-steps">';
                foreach($steps as $s){$active=(int)$s['step_no']===(int)$case['current_step']&&$case['status']==='open';
                    echo '<li'.($active?' class="wf-current"':'').'>'.h($s['title']).' <small>('.(int)$s['due_hours'].' ساعت • '.h($s['assignee_name']??'گروه دارای مجوز').')</small></li>';
                }
                echo '</ol>';
                if($case['status']==='open'){
                    if($canApprove && (int)$case['opened_by']!==(int)Auth::user()['id'] && (!$current || $current['assignee_user_id']===null||(int)$current['assignee_user_id']===(int)Auth::user()['id'])){
                        echo '<div class="wf-decisions">';
                        foreach(['approve'=>'تأیید مرحله','reject'=>'رد پرونده'] as $action=>$label){
                            self::postStart('wf_decide');
                            echo '<input type="hidden" name="case_id" value="'.$caseId.'"><input type="hidden" name="revision" value="'.(int)$case['revision'].'"><input type="hidden" name="nonce" value="'.bin2hex(random_bytes(16)).'"><input type="hidden" name="decision" value="'.h($action).'">';
                            echo '<label>توضیح / علت تصمیم<textarea name="note" maxlength="2000" rows="2"'.($action==='reject'||!empty($current['requires_note'])?' required':'').'></textarea></label><button class="btn '.($action==='approve'?'primary':'danger').'">'.h($label).'</button></form>';
                        }
                        echo '</div>';
                    }
                    if($canManage&&((int)$case['opened_by']===(int)Auth::user()['id']||Tenant::isPlatformAdmin())){
                        self::postStart('wf_decide');
                        echo '<input type="hidden" name="case_id" value="'.$caseId.'"><input type="hidden" name="revision" value="'.(int)$case['revision'].'"><input type="hidden" name="nonce" value="'.bin2hex(random_bytes(16)).'"><input type="hidden" name="decision" value="cancel"><label>علت لغو<input name="note" maxlength="2000"></label><button class="btn tiny">لغو پرونده</button></form>';
                    }
                }
                echo '<h3>تاریخچه غیرقابل ویرایش تصمیم‌ها</h3><div class="wf-table"><table><thead><tr><th>زمان</th><th>کاربر</th><th>عملیات</th><th>وضعیت جدید</th><th>توضیح</th></tr></thead><tbody>';
                foreach(WorkflowDomain::history($wid,$cid,$caseId) as $event)echo '<tr><td>'.h($event['created_at']).'</td><td>'.h($event['actor_name']??('#'.$event['actor_id'])).'</td><td>'.h($event['action']).'</td><td>'.h($event['next_status']).'</td><td>'.h($event['note']).'</td></tr>';
                echo '</tbody></table></div></section>';
            }
        }
        if($canManage){
            echo '<section class="card"><details><summary><strong>ایجاد پرونده جدید</strong></summary>';
            self::postStart('wf_case');
            echo '<div class="wf-grid"><label>فرآیند فعال<select name="definition_id" required><option value="">انتخاب فرآیند</option>';
            foreach($definitions as $def)if($def['status']==='active')echo '<option value="'.(int)$def['id'].'">'.h($def['title']).'</option>';
            echo '</select></label><label>کد یکتا<input name="code" placeholder="WF-1001" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{1,79}" required></label><label>عنوان پرونده<input name="title" maxlength="190" required></label><label>مبلغ مرجع (ریال)<input name="amount" type="number" min="0" step="0.01" value="0"></label><label>نوع مدرک مرتبط<select name="reference_type">';
            $defaultSubject=(string)($_GET['ref']??'none');
            foreach(WorkflowDomain::subjectTypes() as $code=>$meta)if($meta[2]===''||Tenant::can($meta[2]))echo '<option value="'.h($code).'"'.($code===$defaultSubject?' selected':'').'>'.h($meta[0]).'</option>';
            echo '</select></label><label>شناسه مدرک واقعی (اختیاری برای بدون سند)<input name="reference_id" type="number" min="0" value="'.max(0,(int)($_GET['ref_id']??0)).'" placeholder="شناسه موجود در صفحه مرجع"></label></div><label>شرح درخواست<textarea name="description" rows="2" maxlength="2000"></textarea></label><button class="btn primary">ثبت پرونده و شروع مرحله نخست</button></form></details></section>';
        }
        echo '<section class="card"><h2>فرآیندهای تعریف‌شده در شرکت</h2><div class="wf-table"><table><thead><tr><th>کد</th><th>عنوان</th><th>گروه</th><th>تعداد مراحل</th><th>وضعیت</th><th>ویرایش / کنترل</th></tr></thead><tbody>';
        foreach($definitions as $d){
            echo '<tr><td>'.h($d['code']).'</td><td>'.h($d['title']).'</td><td>'.h($d['category']).'</td><td>'.(int)$d['steps_count'].'</td><td>'.h($d['status']).'</td><td><a class="btn tiny" href="'.h(self::link($filter,0,(int)$d['id'])).'#wf-definition">مراحل</a></td></tr>';
        }
        if(!$definitions)echo '<tr><td colspan="6">هنوز فرآیندی برای این شرکت تعریف نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
        $selectedDef=max(0,(int)($_GET['definition']??0));
        if($selectedDef){
            $definition=WorkflowDomain::getDefinition($wid,$cid,$selectedDef);
            if($definition){
                echo '<section class="card" id="wf-definition"><h2>مراحل «'.h($definition['title']).'»</h2><p>'.h($definition['description']).'</p><ol class="wf-steps">';
                foreach(WorkflowDomain::steps($wid,$cid,$selectedDef) as $s)echo '<li>'.h($s['title']).' <small>زمان معیار '.(int)$s['due_hours'].' ساعت | '.h($s['assignee_name']??'گروه تأییدکنندگان').' | '.(!empty($s['requires_note'])?'توضیح الزامی':'توضیح اختیاری').'</small></li>';
                echo '</ol>';
                if($canManage&&$definition['status']==='draft'){
                    self::postStart('wf_step');
                    echo '<input type="hidden" name="definition_id" value="'.$selectedDef.'"><div class="wf-grid"><label>عنوان مرحله<input name="title" maxlength="190" required></label><label>مهلت مرحله (ساعت)<input name="due_hours" type="number" min="1" max="720" value="72" required></label><label>تأییدکننده مشخص<select name="assignee_user_id"><option value="">هر کاربر دارای workflow.approve</option>';
                    foreach(WorkflowDomain::activeUsers($wid) as $u)echo '<option value="'.(int)$u['id'].'">'.h($u['name']).'</option>';
                    echo '</select></label><label class="wf-checkbox"><input type="checkbox" name="requires_note" value="1"> توضیح برای تأیید الزامی باشد</label></div><button class="btn">افزودن مرحله</button></form>';
                    self::postStart('wf_status');
                    echo '<input type="hidden" name="definition_id" value="'.$selectedDef.'"><input type="hidden" name="target" value="active"><button class="btn primary">فعال‌سازی و قفل تعریف فرآیند</button></form>';
                }elseif($canManage&&$definition['status']==='active'){
                    self::postStart('wf_status');
                    echo '<input type="hidden" name="definition_id" value="'.$selectedDef.'"><input type="hidden" name="target" value="retired"><button class="btn">بازنشست‌کردن تعریف (پرونده‌های باز باقی می‌مانند)</button></form>';
                }
                echo '</section>';
            }
        }
        if($canManage){
            echo '<section class="card"><details><summary><strong>طراحی فرآیند جدید / ساخت از الگوی آماده</strong></summary>';
            self::postStart('wf_define');
            echo '<div class="wf-grid"><label>الگوی شروع<select name="template"><option value="">فرآیند سفارشی (مراحل را جدا وارد کنید)</option>';
            foreach(WorkflowDomain::templates() as $code=>$data)echo '<option value="'.h($code).'">'.h($data[0]).' ('.count($data[2]).' مرحله)</option>';
            echo '</select></label><label>کد فرآیند<input name="code" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{1,79}" placeholder="APPROVAL-01" required></label><label>عنوان فرآیند<input name="title" maxlength="190" required></label><label>گروه فرآیند (سفارشی)<select name="category">';
            foreach(['finance'=>'مالی','sales'=>'فروش','procurement'=>'خرید','inventory'=>'انبار','production'=>'تولید','hr'=>'منابع انسانی','projects'=>'پروژه','trade'=>'بازرگانی','service'=>'خدمات','crm'=>'CRM','expenses'=>'هزینه','assets'=>'دارایی','other'=>'سایر'] as $key=>$label)echo '<option value="'.h($key).'">'.h($label).'</option>';
            echo '</select></label></div><label>توضیحات<textarea name="description" maxlength="2000" rows="2"></textarea></label><button class="btn primary">ایجاد تعریف پیش‌نویس</button></form></details></section>';
        }
        render_footer();
    }
}
