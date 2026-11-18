<?php
/** Simple bilingual-ready, RTL entity workspace; operations use validated domain services. */
final class ErpSuiteModule
{
    private const PAGES=[
        'hr'=>['employees','leave','attendance'],
        'projects'=>['projects','tasks','timesheets'],
        'pos'=>['registers','carts','cart_lines'],
        'service'=>['tickets'],
        'assets'=>['assets'],
        'expenses'=>['expenses'],
        'operations'=>[],
    ];
    private const TITLES=[
        'hr'=>'منابع انسانی','projects'=>'پروژه‌ها و زمان‌کار','pos'=>'پیش‌فاکتور صندوق فروش',
        'service'=>'خدمات مشتریان','assets'=>'دارایی‌های ثابت','expenses'=>'درخواست هزینه','operations'=>'مرکز عملیات ERP',
    ];
    private const KPI_LABELS=[
        'employees'=>['کارکنان فعال','hr'], 'overdue_tasks'=>['وظایف معوق','projects'],
        'open_tickets'=>['تیکت باز','service'], 'leave_pending'=>['مرخصی منتظر تأیید','hr'],
        'expenses_pending'=>['هزینه منتظر تأیید','expenses'],
        'draft_carts'=>['سبد فروش پیش‌نویس','pos'],'assets'=>['دارایی در بهره‌برداری','assets'],
    ];

    private static function page(string $page): string
    { return array_key_exists($page,self::PAGES)?$page:'operations'; }

    private static function entityForPage(string $page,string $entity): string
    {
        if(!in_array($entity,self::PAGES[$page]??[],true))throw new RuntimeException('این رکورد متعلق به صفحه انتخاب‌شده نیست.');
        return $entity;
    }

    private static function internalUrl(string $page,string $entity='',int $id=0): string
    {
        $url='index.php?page='.rawurlencode($page);
        if($entity!=='')$url.='&entity='.rawurlencode($entity);
        if($id>0)$url.='&edit='.$id;
        return $url;
    }

    public static function handle(string $action): void
    {
        if(!in_array($action,['erp_save','erp_archive','erp_review','erp_convert_cart'],true))throw new RuntimeException('عملیات ERP پشتیبانی نمی‌شود.');
        $page=self::page((string)($_POST['module']??''));
        $entity=(string)($_POST['entity']??'');
        $url=self::internalUrl($page,$entity);
        try {
            self::entityForPage($page,$entity);
            if(!ModuleRegistry::enabled($page))throw new RuntimeException('ماژول غیرفعال است.');
            $wid=Tenant::id();$cid=AccountingRepository::companyId();
            ErpSuiteDomain::assertScope($wid,$cid);
            $id=(int)($_POST['id']??0);
            if($action==='erp_convert_cart') {
                if($page!=='pos'||$entity!=='carts')throw new RuntimeException('عملیات نامعتبر است.');
                Tenant::requirePermission('pos.manage');
                Tenant::requirePermission('accounting.sales.manage');
                $out=ErpSuiteDomain::convertCartToSalesDraft($wid,$cid,$id,(int)Auth::user()['id']);
                flash('پیش‌فاکتور حسابداری '.$out['document_no'].' ایجاد شد؛ هنوز قطعی/پرداخت/تحویل نشده است.');
            }elseif($action==='erp_review') {
                Tenant::requirePermission($page.'.approve');
                ErpSuiteDomain::review($entity,$wid,$cid,$id,(string)($_POST['decision']??''));
                flash('درخواست بررسی شد؛ هیچ پرداخت یا سند مالی صادر نشده است.');
            }else{
                Tenant::requirePermission($page.'.manage');
                if($action==='erp_archive') {
                    ErpSuiteDomain::archive($entity,$wid,$cid,$id);
                    flash('رکورد بایگانی شد؛ اطلاعات تاریخی حذف نشد.');
                }else{
                    ErpSuiteDomain::save($entity,$wid,$cid,(int)Auth::user()['id'],$_POST,$id);
                    flash($id>0?'ویرایش ذخیره شد.':'رکورد جدید ایجاد شد.');
                }
            }
        }catch(Throwable $e){
            // No untrusted HTTP_REFERER redirects and no secret / SQL errors leaked to browser.
            flash($e instanceof PDOException?'ثبت رکورد انجام نشد؛ کد تکراری یا خطای پایگاه داده.':$e->getMessage(),'danger');
        }
        redirect($url);
    }

    private static function options(string $ref,int $wid,int $cid,int $selected): string
    {
        $s='<option value="">— انتخاب کنید —</option>';
        foreach(ErpSuiteDomain::referenceOptions($ref,$wid,$cid) as $r){
            $label=(string)$r['label'];
            if($ref==='carts')$label='سبد #'.$r['id'];
            $s.='<option value="'.(int)$r['id'].'"'.((int)$r['id']===$selected?' selected':'').'>'.h($label).' (#'.(int)$r['id'].')</option>';
        }
        return $s;
    }

    private static function fieldInput(string $field,array $meta,$value,int $wid,int $cid): string
    {
        $type=$meta['type'];$v=(string)($value??'');$required=$meta['required']?' required':'';
        if($type==='ref')return '<select name="'.h($field).'"'.$required.'>'.self::options($meta['ref'],$wid,$cid,(int)$v).'</select>';
        if($type==='choice'){
            $out='<select name="'.h($field).'"'.$required.'>';
            foreach($meta['choices'] as $code=>$label)$out.='<option value="'.h($code).'"'.($v===$code?' selected':'').'>'.h($label).'</option>';
            return $out.'</select>';
        }
        if($type==='memo')return '<textarea name="'.h($field).'" rows="2" maxlength="2000">'.h($v).'</textarea>';
        if(in_array($type,['hours','estimate','quantity','money','percent','months'],true)){
            $min=($meta['required']?'0.0001':'0');$step=$type==='months'?'1':($type==='hours'||$type==='estimate'||$type==='quantity'?'0.0001':'0.01');
            return '<input name="'.h($field).'" type="number" min="'.$min.'" step="'.$step.'" value="'.h($v).'"'.$required.'>';
        }
        $attr=$type==='date'?'type="date"':($type==='code'?'type="text" pattern="[A-Za-z0-9][A-Za-z0-9_.-]*" maxlength="80"':'type="text" maxlength="190"');
        return '<input name="'.h($field).'" '.$attr.' value="'.h($v).'"'.$required.'>';
    }

    private static function displayValue(string $entity,string $field,array $meta,$value,array $references): string
    {
        if($meta['type']==='choice')return h($meta['choices'][(string)$value]??(string)$value);
        if($meta['type']==='ref')return h($references[$meta['ref']][(int)$value]??($value===null?'—':'#'.$value));
        if($meta['type']==='money')return number_format((float)$value,0).' ریال';
        if(in_array($meta['type'],['hours','estimate'],true))return number_format((float)$value,2).' ساعت';
        return h($value===null||$value===''?'—':$value);
    }

    private static function references(array $fields,int $wid,int $cid): array
    {
        $out=[];
        foreach($fields as $meta){
            if($meta['type']!=='ref'||isset($out[$meta['ref']]))continue;
            $out[$meta['ref']]=[];
            foreach(ErpSuiteDomain::referenceOptions($meta['ref'],$wid,$cid) as $r)$out[$meta['ref']][(int)$r['id']]=(string)$r['label'];
        }
        return $out;
    }

    private static function renderSection(string $page,string $entity,int $wid,int $cid): void
    {
        $spec=ErpSuiteDomain::spec($entity);
        $canManage=Tenant::can($page.'.manage');$canApprove=Tenant::can($page.'.approve');
        $editId=((string)($_GET['entity']??'')===$entity)?max(0,(int)($_GET['edit']??0)):0;
        $edit=$editId?ErpSuiteDomain::find($entity,$wid,$cid,$editId):null;
        if($edit && ((!empty($spec['canonical']) && (int)$edit['active']===0) || (empty($spec['canonical']) && (int)$edit['is_archived']===1)))$edit=null;
        if($editId && !$edit){echo '<p class="muted">رکورد برای ویرایش یافت نشد.</p>'; $editId=0;}
        $rows=ErpSuiteDomain::listRecords($entity,$wid,$cid,60);
        $refs=self::references($spec['fields'],$wid,$cid);
        echo '<section class="card erp-suite-section" id="erp-'.h($entity).'"><div class="erp-suite-section-head"><div><h2>'.h($spec['title']).'</h2><p class="muted">'.count($rows).' رکورد اخیر • محدوده شرکت انتخاب‌شده</p></div>';
        if($canManage)echo '<a class="btn tiny" href="'.h(self::internalUrl($page,$entity)).'#erp-'.h($entity).'">+ رکورد جدید</a>';
        echo '</div>';
        if($canManage && (($_GET['entity']??'')===$entity)){
            $values=$edit?:[];
            echo '<details class="erp-suite-editor" open><summary>'.($edit?'ویرایش #'.$editId:'ثبت جدید').' — '.h($spec['title']).'</summary><form method="post" class="erp-suite-form">'.csrf_field().'<input type="hidden" name="action" value="erp_save"><input type="hidden" name="module" value="'.h($page).'"><input type="hidden" name="entity" value="'.h($entity).'"><input type="hidden" name="id" value="'.($edit?$editId:0).'">';
            foreach($spec['fields'] as $field=>$meta){
                $value=$values[$field]??($meta['type']==='choice'?array_key_first($meta['choices']):null);
                echo '<label>'.h($meta['label']).self::fieldInput($field,$meta,$value,$wid,$cid).'</label>';
            }
            echo '<div class="erp-suite-actions"><button type="submit" class="btn primary">'.($edit?'ذخیره تغییرات':'ایجاد رکورد').'</button><a class="btn" href="'.h(self::internalUrl($page)).'">انصراف</a></div></form></details>';
        }
        echo '<div class="erp-suite-table-wrap"><table class="erp-suite-table"><thead><tr><th>شناسه</th>';
        $columns=array_slice($spec['fields'],0,5,true);
        foreach($columns as $meta)echo '<th>'.h($meta['label']).'</th>';
        if(!empty($spec['approval']))echo '<th>تصمیم</th>';
        if($canManage)echo '<th>عملیات</th>';
        echo '</tr></thead><tbody>';
        foreach($rows as $r){
            echo '<tr><td>#'.(int)$r['id'].'</td>';
            foreach($columns as $field=>$meta)echo '<td>'.self::displayValue($entity,$field,$meta,$r[$field]??null,$refs).'</td>';
            if(!empty($spec['approval'])){
                $status=$r['status']??'pending';echo '<td>'.h(['pending'=>'در انتظار','approved'=>'تأییدشده','rejected'=>'ردشده'][$status]??$status);
                if($status==='pending'&&$canApprove){
                    foreach(['approved'=>'تأیید','rejected'=>'رد'] as $decision=>$label){
                        echo '<form method="post" class="erp-suite-inline">'.csrf_field().'<input type="hidden" name="action" value="erp_review"><input type="hidden" name="module" value="'.h($page).'"><input type="hidden" name="entity" value="'.h($entity).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="decision" value="'.$decision.'"><button class="btn tiny">'.$label.'</button></form>';
                    }
                }
                echo '</td>';
            }
            if($canManage){
                echo '<td><a class="btn tiny" href="'.h(self::internalUrl($page,$entity,(int)$r['id'])).'#erp-'.h($entity).'">ویرایش</a>';
                $workflowSubject=['projects'=>'project','tasks'=>'task','leave'=>'leave','expenses'=>'expense','tickets'=>'service','assets'=>'asset'][$entity]??null;
                if($workflowSubject && ModuleRegistry::enabled('workflows') && Tenant::can('workflow.manage')){
                    echo '<a class="btn tiny" href="index.php?page=workflows&ref='.h($workflowSubject).'&ref_id='.(int)$r['id'].'">ارجاع به فرآیند</a>';
                }
                $locked=!empty($spec['canonical']) || (!empty($spec['approval'])&&$r['status']!=='pending');
                if(!$locked){
                    echo '<form method="post" class="erp-suite-inline">'.csrf_field().'<input type="hidden" name="action" value="erp_archive"><input type="hidden" name="module" value="'.h($page).'"><input type="hidden" name="entity" value="'.h($entity).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny danger" onclick="return confirm(\'این رکورد بایگانی شود؟\')">بایگانی</button></form>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        if(!$rows)echo '<tr><td colspan="8" class="muted">رکوردی موجود نیست. برای شروع از «رکورد جدید» استفاده کنید.</td></tr>';
        echo '</tbody></table></div>';
        if($entity==='carts'){
            echo '<p class="muted">سبدها قابل تبدیل به پیش‌فاکتور پیش‌نویس حسابداری هستند؛ این تبدیل هرگز ثبت قطعی فروش، دریافت پول یا تغییر موجودی نیست.</p>';
            foreach(array_slice($rows,0,10) as $cart){
                $sum=ErpSuiteDomain::cartSummary($wid,$cid,(int)$cart['id']);
                echo '<div class="erp-suite-cart"><span>سبد #'.(int)$cart['id'].' — '.(int)$sum['count'].' قلم، جمع پیشنهادی '.number_format($sum['gross'],0).' ریال · '.h($cart['status']).'</span>';
                if((string)$cart['status']==='converted')echo '<strong>پیش‌فاکتور #'.(int)$cart['linked_sales_doc_id'].'</strong>';
                elseif($canManage&&Tenant::can('accounting.sales.manage')&&$sum['count']>0&&(int)$cart['customer_id']>0){
                    echo '<form method="post" class="erp-suite-inline">'.csrf_field().'<input type="hidden" name="action" value="erp_convert_cart"><input type="hidden" name="module" value="pos"><input type="hidden" name="entity" value="carts"><input type="hidden" name="id" value="'.(int)$cart['id'].'"><button class="btn tiny primary" onclick="return confirm(\'سبد به پیش‌فاکتور حسابداری (فقط پیش‌نویس) تبدیل شود؟\')">ساخت پیش‌فاکتور حسابداری</button></form>';
                }
                echo '</div>';
            }
        }
        if($entity==='assets')echo '<p class="muted">بهای خرید در این بخش صرفاً شناسنامه دارایی است؛ استهلاک مالیاتی/قانونی و سند حسابداری به‌صورت خودکار محاسبه یا صادر نمی‌شود.</p>';
        if($entity==='expenses')echo '<p class="muted">تأیید درخواست هزینه به معنای پرداخت وجه یا ثبت سند حسابداری نیست.</p>';
        echo '</section>';
    }

    public static function render(string $page): void
    {
        $page=self::page($page);Tenant::requirePermission($page.'.view');
        $wid=Tenant::id();$cid=AccountingRepository::companyId();
        render_header(self::TITLES[$page],'ERP عملیاتی • شرکت‌محور، قابل ویرایش و دارای کنترل دسترسی');
        echo '<section class="card erp-suite-intro"><div><strong>ERPSMART • Operations Suite</strong><p>عملیات این صفحه در شرکت انتخاب‌شده ثبت می‌شود؛ برای هر عملیات تاریخچه و کنترل دسترسی برقرار است.</p></div><div class="erp-suite-pill">نسخه MVP • سند مالی مستقل صادر نمی‌شود</div></section>';
        echo '<section class="card"><form method="get" class="erp-suite-company"><input type="hidden" name="page" value="'.h($page).'"><label>شرکت فعال <select name="company_id" onchange="this.form.submit()">';
        foreach(AccountingRepository::companies() as $c)echo '<option value="'.(int)$c['id'].'"'.((int)$c['id']===$cid?' selected':'').'>'.h($c['name']).'</option>';
        echo '</select></label><button class="btn tiny" type="submit">تغییر شرکت</button></form></section>';
        if(!$cid){echo '<section class="card">ابتدا یک شرکت فعال بسازید.</section>';render_footer();return;}
        $navigation=[];
        foreach(self::TITLES as $key=>$title){if(ModuleRegistry::enabled($key)&&Tenant::can($key.'.view'))$navigation[$key]=$title;}
        echo '<nav class="erp-suite-tabs">';foreach($navigation as $key=>$title)echo '<a class="'.($key===$page?'active':'').'" href="'.h(self::internalUrl($key)).'">'.h($title).'</a>';echo '</nav>';
        if($page==='operations'){
            $kpi=ErpSuiteDomain::dashboard($wid,$cid);
            echo '<section class="erp-suite-metrics">';
            foreach($kpi as $key=>$count){[$label,$module]=self::KPI_LABELS[$key];if(!ModuleRegistry::enabled($module)||!Tenant::can($module.'.view'))continue;echo '<a class="card" href="'.h(self::internalUrl($module)).'"><small>'.h($label).'</small><strong>'.number_format($count).'</strong></a>';}
            echo '</section><section class="card"><h2>پیشنهاد ترتیب رسیدگی</h2><p>این شمارنده‌ها از رکوردهای ثبت‌شده ERP محاسبه شده‌اند. برای رسیدگی روی هر کارت کلیک کن؛ هیچ تغییر خودکاری توسط Agent انجام نمی‌شود.</p></section>';
        }else{
            echo '<section class="erp-suite-anchor">';
            foreach(self::PAGES[$page] as $entity){$spec=ErpSuiteDomain::spec($entity);echo '<a href="#erp-'.h($entity).'">'.h($spec['title']).'</a>';}
            echo '</section>';
            foreach(self::PAGES[$page] as $entity)self::renderSection($page,$entity,$wid,$cid);
        }
        render_footer();
    }
}
