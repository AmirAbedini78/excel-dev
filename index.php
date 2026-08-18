<?php
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__.'/app/Modules/V4Module.php';
require_once __DIR__.'/app/Modules/ChoiceModule.php';
require_once __DIR__.'/app/Modules/V5Module.php';
require_once __DIR__.'/app/Modules/AccountingIndustrialModule.php';
require_once __DIR__.'/app/Modules/AiModule.php';
function q(string $sql, array $params=[]): array { $st=pdo()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function one(string $sql, array $params=[]): ?array { $st=pdo()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r ?: null; }
function scalarv(string $sql, array $params=[]): int { $st=pdo()->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
function json_out(array $data): never { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function input_date_to_sql(string $j): ?string {
    $j=trim($j); if (!$j) return null;
    return Jalali::parse($j) ?: (preg_match('/^\d{4}-\d{2}-\d{2}$/', Jalali::enDigits($j)) ? Jalali::enDigits($j) : null);
}
function fa_date(?string $d): string { return $d ? Jalali::fromGregorian($d) : ''; }
function day_name(?string $d): string {
    if(!$d) return '';
    $days=['Saturday'=>'شنبه','Sunday'=>'یکشنبه','Monday'=>'دوشنبه','Tuesday'=>'سه‌شنبه','Wednesday'=>'چهارشنبه','Thursday'=>'پنجشنبه','Friday'=>'جمعه'];
    return $days[date('l', strtotime($d))] ?? '';
}
function companies(bool $all=false): array {
    $wid=Tenant::id();
    $ttl=max(10,min(3600,(int)setting('cache_ttl_seconds','60')));
    $key=$all?'companies:all':'companies:active';
    return RuntimeCache::remember($key,$ttl,function()use($wid,$all){
        return q("SELECT * FROM companies WHERE workspace_id=? ".($all?'':'AND active=1')." ORDER BY name",[$wid]);
    },$wid);
}
function company_options($selected=null, bool $all=false): string {
    $html=$all?'<option value="">همه شرکت‌ها</option>':'';
    foreach (companies() as $c) {
        $html.='<option value="'.(int)$c['id'].'" '.((string)$selected===(string)$c['id']?'selected':'').'>'.h($c['name']).'</option>';
    }
    return $html;
}
function status_options($selected='', bool $all=false): string {
    return ChoiceRegistry::htmlOptions('monthly_status',(string)$selected,$all,'همه وضعیت‌ها',$all);
}
function month_options($selected='', bool $all=false): string {
    return ChoiceRegistry::htmlOptions('monthly_month',(string)$selected,$all,'همه ماه‌ها',$all);
}
function season_options($selected='', bool $all=false): string {
    return ChoiceRegistry::htmlOptions('monthly_season',(string)$selected,$all,'همه فصل‌ها',$all);
}
function work_types(): array {
    return ChoiceRegistry::labels('monthly_work_type','',true);
}
function work_type_options($selected='', bool $all=false): string {
    return ChoiceRegistry::htmlOptions('monthly_work_type',(string)$selected,$all,'همه نوع کارها',$all);
}

function portal_definitions(): array { return q("SELECT * FROM portal_definitions WHERE active=1 ORDER BY sort_order,id"); }
function custom_fields(string $entity): array { return q("SELECT * FROM custom_fields WHERE workspace_id=? AND entity_key=? AND active=1 ORDER BY sort_order,id", [Tenant::id(),$entity]); }
function extra_decode($json): array { if(!$json) return []; $a=json_decode((string)$json,true); return is_array($a)?$a:[]; }
function extra_encode(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE); }
function render_extra_inputs(string $entity, array $extra=[]): string {
    $html='';
    foreach(custom_fields($entity) as $f){
        $key=$f['field_key']; $label=$f['label']; $v=$extra[$key]??''; $type=$f['field_type']??'text';
        if($type==='select'){
            $html.='<label>'.h($label).'<select name="extra['.h($key).']"><option value=""></option>';
            foreach(array_filter(array_map('trim',preg_split('/[,،]/',$f['options']??''))) as $opt){
                $html.='<option '.((string)$v===(string)$opt?'selected':'').'>'.h($opt).'</option>';
            }
            $html.='</select></label>';
        } else {
            $cls=$type==='date'?' class="jalali-date"':'';
            $inputType=$type==='number'?'number':'text';
            $html.='<label>'.h($label).'<input'.$cls.' type="'.$inputType.'" name="extra['.h($key).']" value="'.h($v).'" placeholder="'.h($label).'"></label>';
        }
    }
    return $html;
}
function quick_filters(array $fields): string {
    $html='<form class="filters compact" method="get"><input type="hidden" name="page" value="'.h($_GET['page'] ?? '').'">';
    foreach($fields as $name=>$cfg){
        $val=$_GET[$name] ?? ''; $label=$cfg['label']; $type=$cfg['type'] ?? 'text';
        $html.='<label>'.h($label);
        if($type==='company') $html.='<select name="'.$name.'">'.company_options($val,true).'</select>';
        elseif($type==='status') $html.='<select name="'.$name.'">'.status_options($val,true).'</select>';
        elseif($type==='month') $html.='<select name="'.$name.'">'.month_options($val,true).'</select>';
        elseif($type==='season') $html.='<select name="'.$name.'">'.season_options($val,true).'</select>';
        elseif($type==='work_type') $html.='<select name="'.$name.'">'.work_type_options($val,true).'</select>';
        else $html.='<input name="'.$name.'" value="'.h($val).'" placeholder="'.h($label).'">';
        $html.='</label>';
    }
    $html.='<button class="btn primary tiny" type="submit">فیلتر</button><a class="btn tiny" href="index.php?page='.h($_GET['page']??'dashboard').'">پاک کردن</a></form>';
    return $html;
}
function log_activity(string $entity, int $id, string $action, string $summary='', array $payload=[]): void {    // V5_CACHE_INVALIDATION
    RuntimeCache::clearWorkspace(Tenant::id());

    Audit::log($action,$entity,$id,$summary,null,null,$payload);
    try{
        $uid=Auth::check() ? (int)Auth::user()['id'] : null;
        $st=pdo()->prepare("INSERT INTO activity_logs (workspace_id,user_id,entity_key,record_id,action,summary,payload,ip,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $st->execute([Tenant::id(),$uid,$entity,$id,$action,$summary,extra_encode($payload),$_SERVER['REMOTE_ADDR']??'']);
    }catch(Throwable $e){}
}
function editable_cell(string $field, $value, string $extraClass=''): string {
    return '<td class="editable-cell '.h($extraClass).'" data-field="'.h($field).'">'.h($value).'</td>';
}
function date_input_cell(string $field, $value): string {
    return '<td class="inline-input-cell"><input class="inline-date jalali-date row-edit-control" data-field="'.h($field).'" value="'.h($value).'" disabled autocomplete="off"></td>';
}
function select_inline(string $field, $selected, array $items): string {
    $html='<select data-field="'.h($field).'" class="inline-select row-edit-control" disabled>';
    foreach($items as $i) $html.='<option '.((string)$selected===(string)$i?'selected':'').'>'.h($i).'</option>';
    return $html.'</select>';
}
function company_select_inline($selected): string {
    return '<select data-field="company_id" class="inline-select row-edit-control" disabled>'.company_options($selected,false).'</select>';
}
function status_select_inline($selected): string {
    return '<select data-field="status" class="inline-select row-edit-control" disabled>'.status_options($selected,false).'</select>';
}
function row_actions(string $entity, int $id): string {
    return '<div class="row-actions"><button type="button" class="btn icon" data-edit-row>ویرایش</button><button type="button" class="btn icon danger" data-delete data-entity="'.h($entity).'" data-id="'.$id.'">حذف</button></div>';
}
function table_preferences_json(string $tableKey): string
{
    if(!Auth::check()) return '{}';
    try{
        $r=one("SELECT prefs_json FROM user_table_preferences WHERE workspace_id=? AND user_id=? AND table_key=? LIMIT 1",[Tenant::id(),(int)Auth::user()['id'],$tableKey]);
        $raw=(string)($r['prefs_json']??'{}');
        $decoded=json_decode($raw,true);
        return is_array($decoded) ? json_encode($decoded,JSON_UNESCAPED_UNICODE) : '{}';
    }catch(Throwable $e){ return '{}'; }
}
function smart_table_attrs(string $tableKey): string
{
    return ' data-table-key="'.h($tableKey).'" data-table-prefs="'.h(table_preferences_json($tableKey)).'"';
}
function list_th(string $key, string $label, string $class=''): string
{
    return '<th data-col-key="'.h($key).'"'.($class!==''?' class="'.h($class).'"':'').'>'.h($label).'</th>';
}

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'google_start') {
    $clientId = setting('google_client_id','');
    $redirect = setting('google_redirect_uri', base_url('index.php?page=google_callback'));
    if (!$clientId) { flash('ابتدا Google Client ID را در تنظیمات وارد کنید.','danger'); redirect('index.php?page=login'); }
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
        'client_id'=>$clientId,'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'openid email profile',
        'state'=>$_SESSION['google_oauth_state'],'access_type'=>'online','prompt'=>'select_account'
    ]);
    redirect($url);
}
if ($page === 'google_callback') {
    try {
        if (($_GET['state'] ?? '') !== ($_SESSION['google_oauth_state'] ?? '')) throw new RuntimeException('درخواست گوگل معتبر نیست.');
        $code = $_GET['code'] ?? ''; if (!$code) throw new RuntimeException('کد ورود گوگل دریافت نشد.');
        $redirect = setting('google_redirect_uri', base_url('index.php?page=google_callback'));
        $post = http_build_query(['code'=>$code,'client_id'=>setting('google_client_id',''),'client_secret'=>setting('google_client_secret',''),'redirect_uri'=>$redirect,'grant_type'=>'authorization_code']);
        $ch=curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_TIMEOUT=>25]);
        $token=json_decode(curl_exec($ch),true); $err=curl_error($ch); curl_close($ch);
        if ($err || empty($token['access_token'])) throw new RuntimeException('خطا در دریافت توکن گوگل.');
        $ch=curl_init('https://openidconnect.googleapis.com/v1/userinfo');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token['access_token']],CURLOPT_TIMEOUT=>25]);
        $info=json_decode(curl_exec($ch),true); curl_close($ch);
        $email=mb_strtolower($info['email'] ?? ''); if (!$email) throw new RuntimeException('ایمیل گوگل دریافت نشد.');
        $user = one("SELECT * FROM users WHERE email=? OR google_id=? LIMIT 1", [$email, $info['sub'] ?? '']);
        if (!$user) {
            if (setting('allow_google_signup','1') !== '1') throw new RuntimeException('ثبت‌نام با گوگل فعال نیست.');
            $st=pdo()->prepare("INSERT INTO users (name,email,google_id,avatar,role,status,created_at,updated_at) VALUES (?,?,?,?, 'accountant','active',NOW(),NOW())");
            $st->execute([$info['name'] ?? $email,$email,$info['sub'] ?? null,$info['picture'] ?? null]);
            $id=(int)pdo()->lastInsertId();
        } else {
            $id=(int)$user['id'];
            pdo()->prepare("UPDATE users SET google_id=COALESCE(google_id,?), avatar=COALESCE(?,avatar), updated_at=NOW() WHERE id=?")->execute([$info['sub'] ?? null,$info['picture'] ?? null,$id]);
        }
        Auth::login($id); flash('ورود با گوگل انجام شد.'); redirect('index.php');
    } catch (Throwable $e) { flash($e->getMessage(),'danger'); redirect('index.php?page=login'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        verify_csrf();
        if (Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) redirect('index.php');
        flash('ایمیل یا رمز عبور اشتباه است.','danger'); redirect('index.php?page=login');
    }
    if ($action === 'logout') { verify_csrf(); if(Auth::check()){Tenant::boot();Audit::log('auth.logout','users',(int)Auth::user()['id'],'خروج کاربر');} unset($_SESSION['_v4_login_audited']); Auth::logout(); redirect('index.php?page=login'); }

    Auth::require(); verify_csrf();
    try {
        if (str_starts_with($action,'v4_')) V4Module::handle($action);
        if (str_starts_with($action,'choice_')) ChoiceModule::handle($action);
        if (str_starts_with($action,'acc_')) AccountingIndustrialModule::handle($action);
        if (str_starts_with($action,'ai_')) AiModule::handle($action);
        if ($action === 'inline_update_batch') handle_inline_update_batch();
        if ($action === 'inline_update') handle_inline_update();
        if ($action === 'delete_record') handle_delete_record();
        if ($action === 'save_company') handle_save_company();
        if ($action === 'save_daily_plan') handle_save_daily_plan();
        if ($action === 'save_monthly_plan') handle_save_monthly_plan();
        if ($action === 'save_system_credentials') handle_save_system_credentials();
        if ($action === 'delete_system_credentials') handle_delete_system_credentials();
        if ($action === 'save_custom_field') handle_save_custom_field();
        if ($action === 'save_settings') handle_save_settings();
        if ($action === 'save_table_preferences') handle_save_table_preferences();
        if ($action === 'reset_table_preferences') handle_reset_table_preferences();
        if ($action === 'run_migration') { Schema::migrate(pdo()); flash('مایگریشن دیتابیس با موفقیت اجرا شد.'); redirect('index.php?page=settings'); }
    } catch (Throwable $e) {
        if (in_array($action,['inline_update','inline_update_batch','delete_record','save_system_credentials','delete_system_credentials','save_table_preferences','reset_table_preferences'],true)) json_out(['ok'=>false,'error'=>$e->getMessage()]);
        flash($e->getMessage(),'danger'); redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
    }
}

function handle_inline_update_batch(): never
{
    $entity=(string)($_POST['entity']??'');
    $id=(int)($_POST['id']??0);
    $changes=json_decode((string)($_POST['changes']??'{}'),true);
    if(!$id||!is_array($changes)||!$changes)throw new RuntimeException('داده ویرایش نامعتبر است.');

    $pm=['companies'=>'companies.update','daily_plans'=>'daily.update','monthly_plans'=>'monthly.update','custom_fields'=>'custom_fields.manage'];
    if(isset($pm[$entity]))Tenant::requirePermission($pm[$entity]);

    $map=[
        'companies'=>['table'=>'companies','fields'=>['name','company_type','legal_personality','national_id','economic_code','registration_number','address','postal_code','phone','ceo_name','ceo_national_id','ceo_mobile','software']],
        'daily_plans'=>['table'=>'daily_plans','fields'=>['plan_date','day_name','company_id','work_description','notes']],
        'monthly_plans'=>['table'=>'monthly_plans','fields'=>['company_id','month_name','season','work_type','legal_deadline','status','work_day','completed_date']],
        'custom_fields'=>['table'=>'custom_fields','fields'=>['entity_key','label','field_type','options','sort_order']],
    ];
    if(!isset($map[$entity]))throw new RuntimeException('بخش قابل ویرایش نیست.');
    $table=$map[$entity]['table'];$sets=[];$vals=[];$extraChanges=[];

    foreach($changes as $field=>$raw){
        $value=is_string($raw)?trim($raw):$raw;
        if(str_starts_with((string)$field,'extra.')){
            if(!in_array($entity,['companies','daily_plans','monthly_plans'],true))continue;
            $extraChanges[substr((string)$field,6)]=$value;continue;
        }
        if(!in_array($field,$map[$entity]['fields'],true))continue;
        if(in_array($field,['plan_date','legal_deadline','completed_date'],true))$value=input_date_to_sql((string)$value);
        if($field==='company_id')$value=$value?(int)$value:null;
        if($field==='sort_order')$value=(int)$value;
        $sets[]="`$field`=?";$vals[]=$value;
        if($entity==='daily_plans'&&$field==='plan_date'&&!array_key_exists('day_name',$changes)){
            $sets[]="`day_name`=?";$vals[]=day_name($value);
        }
    }

    if($extraChanges){
        $row=one("SELECT extra_json FROM `$table` WHERE id=? AND workspace_id=?",[$id,Tenant::id()]);
        if(!$row)throw new RuntimeException('رکورد پیدا نشد.');
        $extra=extra_decode($row['extra_json']??'');
        foreach($extraChanges as $k=>$v)$extra[$k]=$v;
        $sets[]="`extra_json`=?";$vals[]=extra_encode($extra);
    }
    if(!$sets)json_out(['ok'=>true,'changed'=>0]);

    $sets[]='updated_at=NOW()';$vals[]=$id;$vals[]=Tenant::id();
    pdo()->prepare("UPDATE `$table` SET ".implode(',',$sets)." WHERE id=? AND workspace_id=?")->execute($vals);
    log_activity($entity,$id,'inline_update_batch','ویرایش گروهی ردیف',['fields'=>array_keys($changes)]);
    json_out(['ok'=>true,'changed'=>count($changes)]);
}
function handle_inline_update(): never
{
    $entity=$_POST['entity']??'';
    $pm=['companies'=>'companies.update','daily_plans'=>'daily.update','monthly_plans'=>'monthly.update','custom_fields'=>'custom_fields.manage']; if(isset($pm[$entity])) Tenant::requirePermission($pm[$entity]); $id=(int)($_POST['id']??0); $field=$_POST['field']??''; $value=trim((string)($_POST['value']??''));
    if(!$id) throw new RuntimeException('شناسه نامعتبر است.');
    $map = [
        'companies'=>['table'=>'companies','fields'=>['name','company_type','legal_personality','national_id','economic_code','registration_number','address','postal_code','phone','ceo_name','ceo_national_id','ceo_mobile','software']],
        'daily_plans'=>['table'=>'daily_plans','fields'=>['plan_date','day_name','company_id','work_description','notes']],
        'monthly_plans'=>['table'=>'monthly_plans','fields'=>['company_id','month_name','season','work_type','legal_deadline','status','work_day','completed_date']],
        'custom_fields'=>['table'=>'custom_fields','fields'=>['entity_key','label','field_type','options','sort_order']],
    ];
    if(!isset($map[$entity])) throw new RuntimeException('بخش قابل ویرایش نیست.');
    $table=$map[$entity]['table'];

    if (str_starts_with($field,'extra.')) {
        if(!in_array($entity,['companies','daily_plans','monthly_plans'],true)) throw new RuntimeException('فیلد اضافی این بخش قابل ویرایش نیست.');
        $key=substr($field,6);
        $row=one("SELECT extra_json FROM `$table` WHERE id=? AND workspace_id=?",[$id,Tenant::id()]);
        $extra=extra_decode($row['extra_json']??''); $extra[$key]=$value;
        pdo()->prepare("UPDATE `$table` SET extra_json=?, updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([extra_encode($extra),$id,Tenant::id()]);
    } else {
        if(!in_array($field,$map[$entity]['fields'],true)) throw new RuntimeException('فیلد مجاز نیست.');
        if(in_array($field,['plan_date','legal_deadline','completed_date'],true)) $value=input_date_to_sql($value);
        if($field==='company_id') $value=$value ? (int)$value : null;
        if($field==='sort_order') $value=(int)$value;
        if($entity==='daily_plans' && $field==='plan_date') {
            pdo()->prepare("UPDATE daily_plans SET plan_date=?,day_name=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$value,day_name($value),$id,Tenant::id()]);
        } else {
            pdo()->prepare("UPDATE `$table` SET `$field`=?, updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$value,$id,Tenant::id()]);
        }
    }
    log_activity($entity,$id,'inline_update',$field,['value'=>$value]);
    json_out(['ok'=>true,'value'=>$value]);
}
function handle_delete_record(): never
{
    $entity=$_POST['entity']??'';
    $pm=['companies'=>'companies.delete','daily_plans'=>'daily.delete','monthly_plans'=>'monthly.delete','custom_fields'=>'custom_fields.manage']; if(isset($pm[$entity])) Tenant::requirePermission($pm[$entity]); $id=(int)($_POST['id']??0); if(!$id) throw new RuntimeException('شناسه نامعتبر است.');
    $map=['companies'=>'companies','daily_plans'=>'daily_plans','monthly_plans'=>'monthly_plans','custom_fields'=>'custom_fields'];
    if(!isset($map[$entity])) throw new RuntimeException('حذف این بخش مجاز نیست.');
    if($entity==='companies') pdo()->prepare("UPDATE companies SET active=0, updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);
    elseif($entity==='custom_fields') pdo()->prepare("UPDATE custom_fields SET active=0, updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);
    else pdo()->prepare("DELETE FROM `{$map[$entity]}` WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);
    log_activity($entity,$id,'delete','حذف رکورد');
    json_out(['ok'=>true]);
}
function handle_save_company(): void
{
    $id=(int)($_POST['id']??0); $id?Tenant::requirePermission('companies.update'):Tenant::requirePermission('companies.create'); $name=trim($_POST['name']??''); if(!$name) throw new RuntimeException('نام شرکت الزامی است.');
    $data=[
        $name,trim($_POST['company_type']??''),trim($_POST['legal_personality']??''),trim($_POST['national_id']??''),
        trim($_POST['economic_code']??''),trim($_POST['registration_number']??''),trim($_POST['address']??''),
        trim($_POST['postal_code']??''),trim($_POST['phone']??''),trim($_POST['ceo_name']??''),
        trim($_POST['ceo_national_id']??''),trim($_POST['ceo_mobile']??''),trim($_POST['software']??''),
        extra_encode($_POST['extra']??[])
    ];
    if($id) {
        pdo()->prepare("UPDATE companies SET name=?,company_type=?,legal_personality=?,national_id=?,economic_code=?,registration_number=?,address=?,postal_code=?,phone=?,ceo_name=?,ceo_national_id=?,ceo_mobile=?,software=?,extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([...$data,$id,Tenant::id()]);
    } else {
        pdo()->prepare("INSERT INTO companies (workspace_id,name,company_type,legal_personality,national_id,economic_code,registration_number,address,postal_code,phone,ceo_name,ceo_national_id,ceo_mobile,software,extra_json,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute([Tenant::id(),...$data]);
        $id=(int)pdo()->lastInsertId();
    }
    FileLibrary::syncFromPost('companies',$id);
    log_activity('companies',$id,'save','ذخیره شرکت');
    RuntimeCache::clearWorkspace(Tenant::id());
    if(!empty($_POST['_ajax'])) json_out(['ok'=>true,'id'=>$id,'row_html'=>V5Module::coreRowHtml('companies',$id),'message'=>'اطلاعات شرکت ذخیره شد.']);
    flash('اطلاعات شرکت ذخیره شد.'); redirect('index.php?page=companies');
}
function handle_save_daily_plan(): void
{
    $id=(int)($_POST['id']??0); $id?Tenant::requirePermission('daily.update'):Tenant::requirePermission('daily.create');
    $date=input_date_to_sql($_POST['plan_date']??'');
    $day=trim($_POST['day_name']??'') ?: day_name($date);
    $desc=trim($_POST['work_description']??''); if(!$desc) throw new RuntimeException('شرح کار الزامی است.');
    $data=[$date,$day,(int)($_POST['company_id']?:0)?:null,$desc,trim($_POST['notes']??''),extra_encode($_POST['extra']??[])];
    if($id) pdo()->prepare("UPDATE daily_plans SET plan_date=?,day_name=?,company_id=?,work_description=?,notes=?,extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([...$data,$id,Tenant::id()]);
    else {
        pdo()->prepare("INSERT INTO daily_plans (workspace_id,plan_date,day_name,company_id,work_description,status,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'باز',?,?,?,NOW(),NOW())")->execute([Tenant::id(),...$data,Auth::user()['id']]);
        $id=(int)pdo()->lastInsertId();
    }
    FileLibrary::syncFromPost('daily_plans',$id);
    log_activity('daily_plans',$id,'save','ذخیره برنامه روزانه');
    RuntimeCache::clearWorkspace(Tenant::id());
    if(!empty($_POST['_ajax'])) json_out(['ok'=>true,'id'=>$id,'row_html'=>V5Module::coreRowHtml('daily_plans',$id),'message'=>'برنامه روزانه ذخیره شد.']);
    flash('برنامه روزانه ذخیره شد.'); redirect('index.php?page=daily');
}
function handle_save_monthly_plan(): void
{
    $id=(int)($_POST['id']??0); $id?Tenant::requirePermission('monthly.update'):Tenant::requirePermission('monthly.create'); $work=trim($_POST['work_type']??''); if(!$work) throw new RuntimeException('نوع کار الزامی است.');
    $data=[
        (int)($_POST['company_id']?:0)?:null,(int)($_POST['jalali_year']??1405),trim($_POST['month_name']??''),
        trim($_POST['season']??''),$work,input_date_to_sql($_POST['legal_deadline']??''),trim($_POST['status']??'باز'),
        trim($_POST['work_day']??''),input_date_to_sql($_POST['completed_date']??''),trim($_POST['notes']??''),
        extra_encode($_POST['extra']??[])
    ];
    if($id) pdo()->prepare("UPDATE monthly_plans SET company_id=?,jalali_year=?,month_name=?,season=?,work_type=?,legal_deadline=?,status=?,work_day=?,completed_date=?,notes=?,extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([...$data,$id,Tenant::id()]);
    else {
        pdo()->prepare("INSERT INTO monthly_plans (workspace_id,company_id,jalali_year,month_name,season,work_type,legal_deadline,status,work_day,completed_date,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([Tenant::id(),...$data,Auth::user()['id']]);
        $id=(int)pdo()->lastInsertId();
    }
    FileLibrary::syncFromPost('monthly_plans',$id);
    log_activity('monthly_plans',$id,'save','ذخیره برنامه ماهانه');
    RuntimeCache::clearWorkspace(Tenant::id());
    if(!empty($_POST['_ajax'])) json_out(['ok'=>true,'id'=>$id,'row_html'=>V5Module::coreRowHtml('monthly_plans',$id),'message'=>'برنامه ماهانه ذخیره شد.']);
    flash('برنامه ماهانه ذخیره شد.'); redirect('index.php?page=monthly');
}
function handle_save_system_credentials(): never
{
    Tenant::requirePermission('systems.update');

    $companyId=(int)($_POST['company_id']??0);
    if(!$companyId) throw new RuntimeException('شرکت نامعتبر است.');

    // DATA_PERSISTENCE_GUARD_SYSTEM_CREDENTIALS_V6_0_4
    // Credentials are business data. Application updates must never erase or overwrite them implicitly.
    $company=one(
        "SELECT id FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1",
        [$companyId,Tenant::id()]
    );
    if(!$company) throw new RuntimeException('شرکت در محیط کاری فعال پیدا نشد.');

    $payload=json_decode($_POST['credentials']??'{}',true);
    if(!is_array($payload)) throw new RuntimeException('داده سامانه‌ها نامعتبر است.');

    $portals=portal_definitions();
    $allowed=[];
    foreach($portals as $p)$allowed[$p['portal_key']]=true;

    $existingRows=q(
        "SELECT portal_key,username,password_enc
         FROM portal_credentials
         WHERE workspace_id=? AND company_id=?",
        [Tenant::id(),$companyId]
    );
    $existing=[];
    foreach($existingRows as $r)$existing[$r['portal_key']]=$r;

    $pdo=pdo();
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare(
            "INSERT INTO portal_credentials
             (workspace_id,company_id,portal_key,username,password_enc,updated_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                username=VALUES(username),
                password_enc=VALUES(password_enc),
                updated_at=NOW()"
        );

        foreach($payload as $key=>$cred){
            if(!isset($allowed[$key]) || !is_array($cred)) continue;

            $username=trim((string)($cred['username']??''));
            $password=(string)($cred['password']??'');
            $old=$existing[$key]??null;

            // IMPORTANT:
            // Blank password means "keep the password already stored in DB".
            // It must never mean delete/overwrite password.
            // Explicit deletion remains a separate action.
            $passwordEnc=$password!=='' ? encrypt_value($password) : (string)($old['password_enc']??'');

            // For a portal with no previous row, do not create a completely empty record.
            if(!$old && $username==='' && $passwordEnc==='') continue;

            $st->execute([
                Tenant::id(),
                $companyId,
                $key,
                $username,
                $passwordEnc
            ]);
        }

        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    log_activity('portal_credentials',$companyId,'save','ذخیره دسترسی سامانه‌ها');
    json_out(['ok'=>true]);
}
function handle_delete_system_credentials(): never
{
    Tenant::requirePermission('systems.update');

    $companyId=(int)($_POST['company_id']??0);
    if(!$companyId) throw new RuntimeException('شرکت نامعتبر است.');

    // DATA_PERSISTENCE_GUARD_DELETE_CREDENTIALS_V6_0_4
    $company=one(
        "SELECT id FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1",
        [$companyId,Tenant::id()]
    );
    if(!$company) throw new RuntimeException('شرکت در محیط کاری فعال پیدا نشد.');

    // This is the ONLY supported destructive path for system credentials.
    pdo()->prepare(
        "DELETE FROM portal_credentials WHERE workspace_id=? AND company_id=?"
    )->execute([Tenant::id(),$companyId]);

    log_activity('portal_credentials',$companyId,'delete','پاک‌کردن دسترسی سامانه‌ها');
    json_out(['ok'=>true]);
}
function handle_save_custom_field(): void
{
    Tenant::requirePermission('custom_fields.manage');
    $label=trim($_POST['label']??''); $entity=trim($_POST['entity_key']??'');
    if(!$label || !$entity) throw new RuntimeException('عنوان و بخش الزامی است.');
    if(!in_array($entity,['companies','daily_plans','monthly_plans'],true)) throw new RuntimeException('بخش نامعتبر است.');
    $key=trim($_POST['field_key']??'') ?: 'field_'.time();
    $key=preg_replace('/[^a-zA-Z0-9_]+/','_',strtolower($key)); if(!$key) $key='field_'.time();
    pdo()->prepare("INSERT INTO custom_fields (workspace_id,entity_key,field_key,label,field_type,options,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),options=VALUES(options),sort_order=VALUES(sort_order),active=1,updated_at=NOW()")
        ->execute([Tenant::id(),$entity,$key,$label,trim($_POST['field_type']??'text'),trim($_POST['options']??''),(int)($_POST['sort_order']??100)]);
    $fid=one("SELECT id FROM custom_fields WHERE workspace_id=? AND entity_key=? AND field_key=? LIMIT 1",[Tenant::id(),$entity,$key]); if($fid) FileLibrary::syncFromPost('custom_fields',(int)$fid['id']);
    flash('فیلد اضافی ذخیره شد.'); redirect('index.php?page=custom_fields');
}
function handle_save_table_preferences(): never
{
    $key=trim((string)($_POST['table_key']??''));
    if(!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/',$key)) throw new RuntimeException('کلید لیست نامعتبر است.');
    $raw=(string)($_POST['prefs']??'{}');
    if(strlen($raw)>120000) throw new RuntimeException('تنظیمات لیست بیش از حد بزرگ است.');
    $prefs=json_decode($raw,true);
    if(!is_array($prefs)) throw new RuntimeException('ساختار تنظیمات لیست معتبر نیست.');

    $clean=['order'=>[],'hidden'=>[],'widths'=>[]];
    foreach(($prefs['order']??[]) as $v) if(is_string($v) && strlen($v)<=160) $clean['order'][]=$v;
    foreach(($prefs['hidden']??[]) as $v) if(is_string($v) && strlen($v)<=160) $clean['hidden'][]=$v;
    foreach(($prefs['widths']??[]) as $k=>$v) {
        if(!is_string($k) || strlen($k)>160) continue;
        $w=(int)$v;
        if($w>=56 && $w<=900) $clean['widths'][$k]=$w;
    }
    $clean['order']=array_values(array_unique($clean['order']));
    $clean['hidden']=array_values(array_unique($clean['hidden']));
    $json=json_encode($clean,JSON_UNESCAPED_UNICODE);
    $uid=(int)Auth::user()['id'];
    pdo()->prepare("INSERT INTO user_table_preferences (workspace_id,user_id,table_key,prefs_json,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE prefs_json=VALUES(prefs_json),updated_at=NOW()")
        ->execute([Tenant::id(),$uid,$key,$json]);
    json_out(['ok'=>true,'prefs'=>$clean]);
}
function handle_reset_table_preferences(): never
{
    $key=trim((string)($_POST['table_key']??''));
    if(!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/',$key)) throw new RuntimeException('کلید لیست نامعتبر است.');
    pdo()->prepare("DELETE FROM user_table_preferences WHERE workspace_id=? AND user_id=? AND table_key=?")->execute([Tenant::id(),(int)Auth::user()['id'],$key]);
    json_out(['ok'=>true]);
}

function handle_save_settings(): void
{
    Tenant::requirePermission('settings.manage'); if(!Tenant::isPlatformAdmin()) throw new RuntimeException('تنظیمات زیرساخت فقط برای مدیر کل پلتفرم مجاز است.');
    $plain=['notifications_email_to','notifications_sms_to','ghasedak_line_number','google_client_id','google_redirect_uri','allow_google_signup','smtp_host','smtp_port','smtp_encryption','smtp_username','mail_from_name','edge_service_url','cache_ttl_seconds','api_enabled'];
    $secret=['smtp_password','ghasedak_api_key','google_client_secret','edge_service_token'];
    foreach($plain as $k) setting_set($k, trim((string)($_POST[$k]??'')),0);
    foreach($secret as $k) if(isset($_POST[$k]) && $_POST[$k] !== '') setting_set($k, (string)$_POST[$k],1);
    pdo()->prepare("INSERT INTO remote_services (service_key,title,base_url,api_key,enabled,notes,updated_at) VALUES ('edge_worker','سرویس جانبی/خانگی',?,?,?, 'آی‌پی/دامنه سرویس بیرونی برای کش، RAG یا پردازش سنگین', NOW()) ON DUPLICATE KEY UPDATE base_url=VALUES(base_url),api_key=VALUES(api_key),enabled=VALUES(enabled),updated_at=NOW()")
        ->execute([trim($_POST['edge_service_url']??''), trim($_POST['edge_service_token']??''), isset($_POST['edge_enabled'])?1:0]);
    flash('تنظیمات ذخیره شد.'); redirect('index.php?page=settings');
}

function render_header(string $title, string $subtitle=''): void
{
    $nav = [
        'dashboard'=>'تقویم',
        'monthly'=>'برنامه ماهانه',
        'daily'=>'برنامه روزانه',
        'kanban'=>'کانبان',
        'companies'=>'اطلاعات شرکت‌ها',
        'systems'=>'سامانه‌ها',
        'phonebook'=>'دفترچه تلفن',
        'notes'=>'نوت‌ها و کارها',
        'library'=>'لایبرری',
        'custom_fields'=>'فیلدهای اضافه',
        'choices'=>'مقادیر انتخابی',
        'industrial'=>'حسابداری و مالی',
        'ai'=>'دستیار هوشمند',
        'shares'=>'اشتراک داده‌ها',
        'access'=>'کاربران و دسترسی‌ها',
        'platform'=>'مدیریت SaaS',
        'performance'=>'عملکرد و کش',
        'settings'=>'تنظیمات',
    ];
    $navGroups = [
        'dashboard'=>'ماژول مدیریت امور حسابداران',
        'industrial'=>'ماژول حسابداری و مالی',
        'ai'=>'هوش مصنوعی و اتوماسیون',
        'shares'=>'مدیریت و زیرساخت',
    ];
    $navPerm=['dashboard'=>'dashboard.view','companies'=>'companies.view','systems'=>'systems.view','monthly'=>'monthly.view','daily'=>'daily.view','custom_fields'=>'custom_fields.manage',
        'choices'=>'choices.manage','industrial'=>'accounting.view','ai'=>'ai.use','kanban'=>'kanban.view','notes'=>'notes.view','phonebook'=>'phonebook.view','shares'=>'shares.view','library'=>'files.view','access'=>'members.view','performance'=>'cache.manage','settings'=>'settings.manage'];
    foreach($navPerm as $nk=>$np) if(isset($nav[$nk]) && !Tenant::can($np)) unset($nav[$nk]);
    if(!Tenant::isPlatformAdmin()) unset($nav['platform']);
    if(!Tenant::isPlatformAdmin()) unset($nav['settings']);
    ?><!doctype html><html lang="fa" dir="rtl"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=h($title)?> - Accounting CRM</title>
    <link rel="stylesheet" href="assets/style.css?v=6.0"><link rel="stylesheet" href="assets/v4.css?v=6.0"><link rel="stylesheet" href="assets/choices.css?v=6.0"><link rel="stylesheet" href="assets/v5.css?v=6.0"><link rel="stylesheet" href="assets/accounting.css?v=7.0">
    </head><body><div class="app">
    <aside class="sidebar compact"><div class="brand">Accounting CRM<span>سامانه سبک حسابداران</span></div><nav>
    <?php foreach($nav as $k=>$v): ?><?php if(isset($navGroups[$k])):?><span class="v5-nav-group"><?=h($navGroups[$k])?></span><?php endif;?><a class="<?=($_GET['page']??'dashboard')===$k?'active':''?>" href="index.php?page=<?=$k?>"><?=h($v)?></a><?php endforeach; ?>
    </nav></aside>
    <main class="main"><header class="topbar"><div><h1><?=h($title)?></h1><?php if($subtitle): ?><p><?=h($subtitle)?></p><?php endif; ?></div>
    <div class="top-actions"><a class="btn tiny" href="index.php?page=dashboard">امروز: <?=h(Jalali::today())?></a>
    <form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button class="btn tiny" type="submit">خروج</button></form></div></header>
    <?php foreach(flashes() as $f): ?><div class="alert <?=h($f['type'])?>"><?=h($f['msg'])?></div><?php endforeach; ?><?php
}
function render_footer(): void { ?></main></div><script>window.CSRF='<?=h(csrf_token())?>';window.JALALI_TODAY='<?=h(Jalali::today())?>';window.V4_WORKSPACE_ID=<?=Tenant::id()?>;window.V4_WORKSPACES=<?=json_encode(Tenant::workspaceOptions(),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP)?>;</script><script src="assets/app.js?v=6.0"></script><script src="assets/v4.js?v=6.0"></script><script src="assets/v5.js?v=6.0"></script><script src="assets/accounting.js?v=7.0"></script></body></html><?php }

if ($page === 'login') { render_login(); exit; }
Auth::require(); // Tenant already booted by bootstrap; V5 schema is migration-gated.
if($_SERVER['REQUEST_METHOD']==='GET' && $page!=='login' && setting('audit_page_views','0')==='1') Audit::log('page.view','page',0,$page);
$pagePermission=['dashboard'=>'dashboard.view','companies'=>'companies.view','systems'=>'systems.view','monthly'=>'monthly.view','daily'=>'daily.view','kanban'=>'kanban.view','custom_fields'=>'custom_fields.manage',
        'choices'=>'choices.manage','industrial'=>'accounting.view','ai'=>'ai.use','phonebook'=>'phonebook.view','shares'=>'shares.view','performance'=>'cache.manage','settings'=>'settings.manage'];
if(isset($pagePermission[$page])) Tenant::requirePermission($pagePermission[$page]);
if($page==='settings' && !Tenant::isPlatformAdmin()) { http_response_code(403); throw new RuntimeException('تنظیمات زیرساخت فقط برای مدیر کل پلتفرم در دسترس است.'); }

if ($page === 'dashboard') render_calendar();
elseif($page === 'companies') render_companies();
elseif($page === 'systems') render_systems();
elseif($page === 'monthly') render_monthly();
elseif($page === 'daily') render_daily();
elseif($page === 'custom_fields') render_custom_fields();
elseif($page === 'choices') ChoiceModule::render();
elseif($page === 'kanban') render_kanban();
elseif($page === 'notes') V5Module::renderNotes();
elseif($page === 'industrial') AccountingIndustrialModule::render();
elseif($page === 'ai') AiModule::render();
elseif($page === 'phonebook') V5Module::renderPhonebook();
elseif($page === 'shares') V5Module::renderSharing();
elseif($page === 'performance') V5Module::renderPerformance();
elseif($page === 'library') V4Module::renderLibrary();
elseif($page === 'access') V4Module::renderAccess();
elseif($page === 'platform') V4Module::renderPlatform();
elseif($page === 'settings') render_settings();
else render_calendar();

function render_login(): void
{
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود</title><link rel="stylesheet" href="assets/style.css?v=6.0"><link rel="stylesheet" href="assets/v4.css?v=6.0"><link rel="stylesheet" href="assets/choices.css?v=6.0"><link rel="stylesheet" href="assets/v5.css?v=6.0"><link rel="stylesheet" href="assets/accounting.css?v=7.0"></head>
    <body class="login-page"><main class="login-card"><h1>ورود به سامانه حسابداران</h1><p>تقویم کاری، شرکت‌ها، سامانه‌ها و برنامه‌های حسابداری</p>
    <?php foreach(flashes() as $f): ?><div class="alert <?=h($f['type'])?>"><?=h($f['msg'])?></div><?php endforeach; ?>
    <form method="post" class="grid-form autosave" data-form-key="login"><?=csrf_field()?><input type="hidden" name="action" value="login">
    <label>ایمیل<input type="email" name="email" required></label><label>رمز عبور<input type="password" name="password" required></label>
    <button class="btn primary" type="submit">ورود</button><a class="btn google" href="index.php?page=google_start">ورود یا ثبت‌نام با گوگل</a></form></main>
    <script src="assets/app.js?v=6.0"></script><script src="assets/v4.js?v=6.0"></script><script src="assets/v5.js?v=6.0"></script><script src="assets/accounting.js?v=7.0"></script></body></html><?php
}
function render_calendar(): void
{
    $todayJ=explode('/',Jalali::today()); $defaultY=(int)$todayJ[0]; $defaultM=(int)$todayJ[1];
    $jy=(int)($_GET['jy']??$defaultY); $jm=(int)($_GET['jm']??$defaultM);
    if($jy<1300 || $jy>1600) $jy=$defaultY; if($jm<1 || $jm>12) $jm=$defaultM;
    $days=Jalali::monthLength($jy,$jm);
    $start=Jalali::parse(sprintf('%04d/%02d/01',$jy,$jm));
    $end=Jalali::parse(sprintf('%04d/%02d/%02d',$jy,$jm,$days));
    $company=(int)($_GET['company_id']??0);

    $params=[Tenant::id(),$start,$end]; $where="d.workspace_id=? AND d.plan_date BETWEEN ? AND ?";
    if($company){$where.=" AND d.company_id=?";$params[]=$company;}
    $daily=q("SELECT d.id,d.plan_date event_date,d.work_description title,'' detail,d.status,d.notes,c.name company_name,'daily' source FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE $where ORDER BY d.plan_date,d.id",$params);

    $params=[Tenant::id(),$start,$end]; $where="m.workspace_id=? AND m.legal_deadline BETWEEN ? AND ?";
    if($company){$where.=" AND m.company_id=?";$params[]=$company;}
    $monthly=q("SELECT m.id,m.legal_deadline event_date,m.work_type title,CONCAT(COALESCE(m.month_name,''),' - ',COALESCE(m.season,'')) detail,m.status,m.notes,c.name company_name,'monthly' source FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE $where ORDER BY m.legal_deadline,m.id",$params);

    $events=[]; foreach(array_merge($daily,$monthly) as $e){
        $events[$e['event_date']][]=[
            'source'=>$e['source'],'title'=>$e['title'],'company'=>$e['company_name']??'','detail'=>$e['detail']??'',
            'status'=>$e['status']??'','notes'=>$e['notes']??'','date'=>fa_date($e['event_date'])
        ];
    }

    $prevM=$jm-1; $prevY=$jy; if($prevM<1){$prevM=12;$prevY--;}
    $nextM=$jm+1; $nextY=$jy; if($nextM>12){$nextM=1;$nextY++;}
    $suffix=$company?'&company_id='.$company:'';

    render_header('تقویم کاری','نمای ماهانه شمسی؛ برای دیدن جزئیات کارهای هر روز روی همان روز کلیک کنید.');
    echo '<section class="calendar-toolbar"><div class="calendar-nav"><a class="btn tiny" href="index.php?page=dashboard&jy='.$prevY.'&jm='.$prevM.$suffix.'">ماه قبل</a><strong>'.h(Jalali::monthName($jm)).' '.$jy.'</strong><a class="btn tiny" href="index.php?page=dashboard&jy='.$nextY.'&jm='.$nextM.$suffix.'">ماه بعد</a></div>';
    echo '<form method="get" class="calendar-filter"><input type="hidden" name="page" value="dashboard"><input type="hidden" name="jy" value="'.$jy.'"><input type="hidden" name="jm" value="'.$jm.'"><label>شرکت<select name="company_id">'.company_options($company,true).'</select></label><button class="btn tiny primary">اعمال</button></form></section>';

    $headers=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
    echo '<section class="calendar-shell"><div class="calendar-grid calendar-head">';
    foreach($headers as $hday) echo '<div>'.$hday.'</div>';
    echo '</div><div class="calendar-grid calendar-body">';
    $offset=((int)date('w',strtotime($start))+1)%7;
    $slots=(int)(ceil(($offset+$days)/7)*7);
    for($slot=0;$slot<$slots;$slot++){
        $day=$slot-$offset+1;
        if($day<1 || $day>$days){ echo '<div class="calendar-day empty"></div>'; continue; }
        $g=Jalali::parse(sprintf('%04d/%02d/%02d',$jy,$jm,$day));
        $dayEvents=$events[$g]??[]; $isToday=($g===date('Y-m-d'));
        echo '<button type="button" class="calendar-day '.($isToday?'today':'').'" data-calendar-date="'.h($g).'" data-jalali="'.sprintf('%04d/%02d/%02d',$jy,$jm,$day).'">';
        echo '<span class="calendar-date">'.$day.'</span>';
        if($dayEvents){
            echo '<span class="calendar-count">'.count($dayEvents).' کار</span><div class="calendar-preview">';
            foreach(array_slice($dayEvents,0,3) as $ev) echo '<span class="calendar-event '.h($ev['source']).'">'.h($ev['title']).'<small>'.h($ev['company']).'</small></span>';
            if(count($dayEvents)>3) echo '<span class="calendar-more">+'.(count($dayEvents)-3).' مورد دیگر</span>';
            echo '</div>';
        }
        echo '</button>';
    }
    echo '</div></section>';
    echo '<script id="calendarEvents" type="application/json">'.json_encode($events,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
    echo '<div class="modal-backdrop" id="calendarModal" hidden><section class="calendar-modal" role="dialog" aria-modal="true"><div class="modal-head"><div><h2 id="calendarModalTitle">کارهای روز</h2><p id="calendarModalSubtitle"></p></div><button type="button" class="btn icon" data-close-calendar>بستن</button></div><div id="calendarModalBody" class="calendar-modal-body"></div></section></div>';
    render_footer();
}
function render_companies(): void
{
    render_header('اطلاعات شرکت‌ها','اطلاعات ثبتی و مدیریتی؛ هر ردیف فقط بعد از زدن دکمه ویرایش قابل تغییر است.');
    echo '<section class="card"><details><summary>افزودن شرکت</summary><form method="post" class="grid-form compact autosave" data-form-key="company">'.csrf_field().'<input type="hidden" name="action" value="save_company">
    <label>نام شرکت<input name="name" required></label>
    <label>نوع شرکت<select name="company_type">'.ChoiceRegistry::htmlOptions('company_type').'</select></label>
    <label>شخصیت<select name="legal_personality">'.ChoiceRegistry::htmlOptions('legal_personality').'</select></label>
    <label>شناسه ملی<input name="national_id"></label>
    <label>کد اقتصادی<input name="economic_code"></label>
    <label>شماره ثبت<input name="registration_number"></label>
    <label class="span2">آدرس<input name="address"></label>
    <label>کدپستی<input name="postal_code"></label>
    <label>شماره تلفن<input name="phone"></label>
    <label>مدیرعامل<input name="ceo_name"></label>
    <label>کدملی مدیرعامل<input name="ceo_national_id"></label>
    <label>شماره تماس مدیرعامل<input name="ceo_mobile"></label>
    <label>نرم‌افزار<select name="software">'.ChoiceRegistry::htmlOptions('accounting_software').'</select></label>'.
    render_extra_inputs('companies').'<button class="btn primary">ذخیره</button></form></details>
    </section>';

    echo '<form class="filters compact" method="get"><input type="hidden" name="page" value="companies">
    <label>جستجو<input name="q" value="'.h($_GET['q']??'').'" placeholder="نام، شناسه ملی، کد اقتصادی..."></label>
    <label>نوع شرکت<select name="company_type">'.ChoiceRegistry::htmlOptions('company_type',(string)($_GET['company_type']??''),true,'همه نوع‌ها',true).'</select></label>
    <label>شخصیت<select name="legal_personality">'.ChoiceRegistry::htmlOptions('legal_personality',(string)($_GET['legal_personality']??''),true,'همه',true).'</select></label>
    <label>نرم‌افزار<select name="software">'.ChoiceRegistry::htmlOptions('accounting_software',(string)($_GET['software']??''),true,'همه نرم‌افزارها',true).'</select></label>
    <button class="btn primary tiny">فیلتر</button><a class="btn tiny" href="index.php?page=companies">پاک کردن</a></form>';

    $where=['workspace_id=?','active=1'];$params=[Tenant::id()];
    if($v=trim($_GET['q']??'')){ $where[]="(name LIKE ? OR national_id LIKE ? OR economic_code LIKE ? OR registration_number LIKE ? OR ceo_name LIKE ? OR phone LIKE ?)"; $l="%$v%"; array_push($params,$l,$l,$l,$l,$l,$l); }
    if($v=trim($_GET['company_type']??'')){ $where[]="company_type LIKE ?";$params[]="%$v%"; }
    if($v=trim($_GET['legal_personality']??'')){ $where[]="legal_personality=?";$params[]=$v; }
    if($v=trim($_GET['software']??'')){ $where[]="software LIKE ?";$params[]="%$v%"; }
    $rows=q("SELECT * FROM companies WHERE ".implode(' AND ',$where)." ORDER BY name",$params); $fields=custom_fields('companies');

    echo '<section class="card table-card"><div class="section-title"><h2>لیست شرکت‌ها</h2><span class="muted">ویرایش ردیفی کنترل‌شده</span></div><div class="table-wrap"><table class="data-table compact-table companies-table smart-table" data-entity="companies"'.smart_table_attrs('companies').'><thead><tr>
    <th data-col-key="actions">عملیات</th><th data-col-key="name">نام شرکت</th><th data-col-key="company_type">نوع شرکت</th><th data-col-key="legal_personality">شخصیت</th><th data-col-key="national_id">شناسه ملی</th><th data-col-key="economic_code">کد اقتصادی</th><th data-col-key="registration_number">شماره ثبت</th><th data-col-key="address">آدرس</th><th data-col-key="postal_code">کدپستی</th><th data-col-key="phone">شماره تلفن</th><th data-col-key="ceo_name">مدیرعامل</th><th data-col-key="ceo_national_id">کدملی مدیرعامل</th><th data-col-key="ceo_mobile">شماره تماس مدیرعامل</th><th data-col-key="software">نرم‌افزار</th>';
    foreach($fields as $f) echo '<th data-col-key="extra.'.h($f['field_key']).'">'.h($f['label']).'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r){
        $extra=extra_decode($r['extra_json']??'');
        echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('companies',$r['id']).'</td>';
        echo editable_cell('name',$r['name']);
        echo '<td>'.select_inline('company_type',$r['company_type']?:$r['type'],ChoiceRegistry::labels('company_type',(string)($r['company_type']?:$r['type']),true)).'</td>';
        echo '<td>'.select_inline('legal_personality',$r['legal_personality'],ChoiceRegistry::labels('legal_personality',(string)$r['legal_personality'],true)).'</td>';
        echo editable_cell('national_id',$r['national_id']);
        echo editable_cell('economic_code',$r['economic_code']);
        echo editable_cell('registration_number',$r['registration_number']);
        echo editable_cell('address',$r['address'],'wide-cell');
        echo editable_cell('postal_code',$r['postal_code']);
        echo editable_cell('phone',$r['phone']);
        echo editable_cell('ceo_name',$r['ceo_name']?:$r['manager_name']);
        echo editable_cell('ceo_national_id',$r['ceo_national_id']);
        echo editable_cell('ceo_mobile',$r['ceo_mobile']);
        echo '<td>'.select_inline('software',$r['software'],ChoiceRegistry::labels('accounting_software',(string)$r['software'],true)).'</td>';
        foreach($fields as $f) echo editable_cell('extra.'.$f['field_key'],$extra[$f['field_key']]??'');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_systems(): void
{
    render_header('سامانه‌ها','دسترسی سامانه‌های هر شرکت؛ رمزها در دیتابیس به‌صورت رمزگذاری‌شده نگهداری می‌شوند.');
    $qv=trim($_GET['q']??''); $where='workspace_id=? AND active=1';$params=[Tenant::id()]; if($qv!==''){$where.=' AND name LIKE ?';$params[]="%$qv%";}
    $companies=q("SELECT * FROM companies WHERE $where ORDER BY name",$params);
    $portals=portal_definitions();

    echo '<form class="filters compact" method="get"><input type="hidden" name="page" value="systems"><label>جستجوی شرکت<input name="q" value="'.h($qv).'"></label><button class="btn primary tiny">فیلتر</button><a class="btn tiny" href="index.php?page=systems">پاک کردن</a></form>';
    echo '<section class="card table-card"><div class="section-title"><h2>دسترسی سامانه‌ها</h2><span class="muted">برای تغییر، ابتدا «ویرایش» را بزنید. تنظیم ستون‌ها از آیکن چرخ‌دنده در دسترس است.</span></div><div class="table-wrap"><table class="systems-table smart-table"'.smart_table_attrs('systems').'><thead><tr><th data-col-key="actions">عملیات</th><th data-col-key="company">نام شرکت</th>';
    foreach($portals as $p) {
        echo '<th data-col-key="portal.'.h($p['portal_key']).'.username"><span class="portal-head"><a href="'.h($p['url']).'" target="_blank" rel="noopener">'.h($p['url']).'</a><small>نام کاربری</small></span></th>';
        echo '<th data-col-key="portal.'.h($p['portal_key']).'.password"><span class="portal-head"><a href="'.h($p['url']).'" target="_blank" rel="noopener">'.h($p['url']).'</a><small>کلمه عبور</small></span></th>';
    }
    echo '</tr></thead><tbody>';

    $credRows=q("SELECT * FROM portal_credentials WHERE workspace_id=?",[Tenant::id()]); $creds=[];
    foreach($credRows as $cr) $creds[(int)$cr['company_id']][$cr['portal_key']]=$cr;
    foreach($companies as $c){
        echo '<tr data-system-row data-company-id="'.(int)$c['id'].'"><td><div class="row-actions"><button type="button" class="btn icon" data-edit-system>ویرایش</button><button type="button" class="btn icon danger" data-delete-system>حذف</button></div></td><td class="system-company">'.h($c['name']).'</td>';
        foreach($portals as $p){
            $cr=$creds[(int)$c['id']][$p['portal_key']]??null;
            $u=$cr['username']??''; $pw=$cr?decrypt_value((string)$cr['password_enc']):'';
            echo '<td><input class="system-input" data-portal="'.h($p['portal_key']).'" data-kind="username" value="'.h($u).'" disabled autocomplete="off"></td>';
            echo '<td><div class="password-wrap"><input type="password" class="system-input" data-portal="'.h($p['portal_key']).'" data-kind="password" value="'.h($pw).'" disabled autocomplete="new-password"><button type="button" class="password-toggle" data-toggle-password title="نمایش/مخفی‌کردن">◉</button></div></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_daily(): void
{
    render_header('برنامه روزانه','تاریخ، روز، شرکت، شرح کار و توضیحات');
    echo '<section class="card"><details><summary>افزودن برنامه روزانه</summary><form method="post" class="grid-form compact autosave" data-form-key="daily">'.csrf_field().'<input type="hidden" name="action" value="save_daily_plan">
    <label>تاریخ<input class="jalali-date" name="plan_date" placeholder="1405/01/01"></label>
    <label>روز<input name="day_name"></label>
    <label>شرکت<select name="company_id">'.company_options().'</select></label>
    <label class="span2">شرح کار<input name="work_description" required></label>

    <label class="span2">توضیحات<input name="notes"></label>'.
    render_extra_inputs('daily_plans').'<button class="btn primary">ذخیره</button></form></details></section>'.
    quick_filters(['q'=>['label'=>'جستجو'],'company_id'=>['label'=>'شرکت','type'=>'company'],'from'=>['label'=>'از تاریخ'],'to'=>['label'=>'تا تاریخ']]);

    $where=['d.workspace_id=?','1=1'];$params=[Tenant::id()];
    if($v=trim($_GET['q']??'')){ $where[]="(work_description LIKE ? OR notes LIKE ?)";$l="%$v%";array_push($params,$l,$l);}
    if($v=$_GET['company_id']??''){ $where[]='d.company_id=?';$params[]=$v;}

    if($v=trim($_GET['from']??'')){ $where[]='d.plan_date>=?';$params[]=input_date_to_sql($v);}
    if($v=trim($_GET['to']??'')){ $where[]='d.plan_date<=?';$params[]=input_date_to_sql($v);}
    $rows=q("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE ".implode(' AND ',$where)." ORDER BY plan_date DESC,id DESC LIMIT 500",$params);
    $fields=custom_fields('daily_plans');

    echo '<section class="card table-card"><div class="table-wrap"><table class="data-table compact-table smart-table" data-entity="daily_plans"'.smart_table_attrs('daily_plans').'><thead><tr><th data-col-key="actions">عملیات</th><th data-col-key="plan_date">تاریخ</th><th data-col-key="day_name">روز</th><th data-col-key="company_id">شرکت</th><th data-col-key="work_description">شرح کار</th><th data-col-key="notes">توضیحات</th>';
    foreach($fields as $f) echo '<th data-col-key="extra.'.h($f['field_key']).'">'.h($f['label']).'</th>'; echo '</tr></thead><tbody>';
    foreach($rows as $r){
        $extra=extra_decode($r['extra_json']??'');
        echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('daily_plans',$r['id']).'</td>';
        echo date_input_cell('plan_date',fa_date($r['plan_date']));
        echo editable_cell('day_name',$r['day_name']);
        echo '<td>'.company_select_inline($r['company_id']).'</td>';
        echo editable_cell('work_description',$r['work_description'],'wide-cell');
        echo editable_cell('notes',$r['notes'],'wide-cell');
        foreach($fields as $f) echo editable_cell('extra.'.$f['field_key'],$extra[$f['field_key']]??'');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_monthly(): void
{
    render_header('برنامه ماهانه','نام شرکت، ماه، فصل، نوع کار، مهلت قانونی، وضعیت، روز انجام و تاریخ انجام');
    echo '<section class="card"><details><summary>افزودن برنامه ماهانه</summary><form method="post" class="grid-form compact autosave" data-form-key="monthly">'.csrf_field().'<input type="hidden" name="action" value="save_monthly_plan">
    <label>شرکت<select name="company_id">'.company_options().'</select></label>
    <label>سال<input name="jalali_year" value="1405"></label>
    <label>ماه<select name="month_name">'.month_options().'</select></label>
    <label>فصل<select name="season">'.season_options().'</select></label>
    <label>نوع کار<select name="work_type">'.work_type_options().'</select></label>
    <label>مهلت قانونی<input class="jalali-date" name="legal_deadline"></label>
    <label>وضعیت<select name="status">'.status_options('باز').'</select></label>
    <label>روز انجام<input name="work_day"></label>
    <label>تاریخ انجام<input class="jalali-date" name="completed_date"></label>
    <label class="span2">یادداشت داخلی<input name="notes"></label>'.
    render_extra_inputs('monthly_plans').'<button class="btn primary">ذخیره</button></form></details></section>'.
    quick_filters(['q'=>['label'=>'جستجو'],'company_id'=>['label'=>'شرکت','type'=>'company'],'status'=>['label'=>'وضعیت','type'=>'status'],'month'=>['label'=>'ماه','type'=>'month'],'season'=>['label'=>'فصل','type'=>'season'],'work_type'=>['label'=>'نوع کار','type'=>'work_type']]);

    $where=['m.workspace_id=?','1=1'];$params=[Tenant::id()];
    if($v=trim($_GET['q']??'')){ $where[]="(m.notes LIKE ? OR m.work_day LIKE ? OR m.work_type LIKE ?)";$l="%$v%";array_push($params,$l,$l,$l);}
    if($v=$_GET['company_id']??''){ $where[]='m.company_id=?';$params[]=$v;}
    if($v=$_GET['status']??''){ $where[]='m.status=?';$params[]=$v;}
    if($v=$_GET['month']??''){ $where[]='m.month_name=?';$params[]=$v;}
    if($v=$_GET['season']??''){ $where[]='m.season=?';$params[]=$v;}
    if($v=$_GET['work_type']??''){ $where[]='m.work_type=?';$params[]=$v;}
    $rows=q("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE ".implode(' AND ',$where)." ORDER BY legal_deadline IS NULL,legal_deadline ASC,id DESC LIMIT 700",$params);
    $fields=custom_fields('monthly_plans');

    echo '<section class="card table-card"><div class="table-wrap"><table class="data-table compact-table smart-table" data-entity="monthly_plans"'.smart_table_attrs('monthly_plans').'><thead><tr><th data-col-key="actions">عملیات</th><th data-col-key="company_id">نام شرکت</th><th data-col-key="month_name">ماه</th><th data-col-key="season">فصل</th><th data-col-key="work_type">نوع کار</th><th data-col-key="legal_deadline">مهلت قانونی</th><th data-col-key="status">وضعیت</th><th data-col-key="work_day">روز انجام</th><th data-col-key="completed_date">تاریخ انجام</th>';
    foreach($fields as $f) echo '<th data-col-key="extra.'.h($f['field_key']).'">'.h($f['label']).'</th>'; echo '</tr></thead><tbody>';
    foreach($rows as $r){
        $extra=extra_decode($r['extra_json']??'');
        echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('monthly_plans',$r['id']).'</td><td>'.company_select_inline($r['company_id']).'</td>';
        echo '<td>'.select_inline('month_name',$r['month_name'],ChoiceRegistry::labels('monthly_month',(string)$r['month_name'],true)).'</td>';
        echo '<td>'.select_inline('season',$r['season'],ChoiceRegistry::labels('monthly_season',(string)$r['season'],true)).'</td>';
        echo '<td>'.select_inline('work_type',$r['work_type'],work_types()).'</td>';
        echo date_input_cell('legal_deadline',fa_date($r['legal_deadline']));
        echo '<td>'.status_select_inline($r['status']).'</td>';
        echo editable_cell('work_day',$r['work_day']);
        echo date_input_cell('completed_date',fa_date($r['completed_date']));
        foreach($fields as $f) echo editable_cell('extra.'.$f['field_key'],$extra[$f['field_key']]??'');
        echo '</tr>';
    }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_custom_fields(): void
{
    render_header('فیلدهای اضافه','برای اطلاعات شرکت‌ها، برنامه روزانه و برنامه ماهانه ستون دلخواه تعریف کنید.');
    $entities=['companies'=>'اطلاعات شرکت‌ها','daily_plans'=>'برنامه روزانه','monthly_plans'=>'برنامه ماهانه'];
    echo '<section class="card"><details><summary>افزودن فیلد جدید</summary><form method="post" class="grid-form compact autosave" data-form-key="custom-field">'.csrf_field().'<input type="hidden" name="action" value="save_custom_field">
    <label>بخش<select name="entity_key">'; foreach($entities as $k=>$v) echo '<option value="'.h($k).'">'.h($v).'</option>'; echo '</select></label>
    <label>عنوان ستون<input name="label" required></label><label>کلید انگلیسی<input name="field_key" placeholder="tracking_no"></label>
    <label>نوع فیلد<select name="field_type"><option value="text">متن</option><option value="number">عدد</option><option value="date">تاریخ</option><option value="select">لیست</option></select></label>
    <label>گزینه‌ها<input name="options" placeholder="برای لیست با ، جدا شود"></label><label>ترتیب<input name="sort_order" value="100"></label><button class="btn primary">افزودن فیلد</button></form></details></section>';

    $rows=q("SELECT * FROM custom_fields WHERE workspace_id=? AND active=1 AND entity_key IN ('companies','daily_plans','monthly_plans') ORDER BY entity_key,sort_order,id",[Tenant::id()]);
    echo '<section class="card table-card"><div class="table-wrap"><table class="data-table compact-table smart-table" data-entity="custom_fields"'.smart_table_attrs('custom_fields').'><thead><tr><th data-col-key="actions">عملیات</th><th data-col-key="entity_key">بخش</th><th data-col-key="field_key">کلید</th><th data-col-key="label">عنوان</th><th data-col-key="field_type">نوع</th><th data-col-key="options">گزینه‌ها</th><th data-col-key="sort_order">ترتیب</th></tr></thead><tbody>';
    foreach($rows as $r){
        echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('custom_fields',$r['id']).'</td>';
        echo '<td>'.select_inline('entity_key',$r['entity_key'],array_keys($entities)).'</td>';
        echo '<td class="readonly-cell">'.h($r['field_key']).'</td>';
        echo editable_cell('label',$r['label']);
        echo '<td>'.select_inline('field_type',$r['field_type'],['text','number','date','select']).'</td>';
        echo editable_cell('options',$r['options']);
        echo editable_cell('sort_order',$r['sort_order']).'</tr>';
    }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_kanban(): void
{
    render_header('کانبان','نمای بصری برنامه ماهانه بر اساس وضعیت');
    echo quick_filters(['company_id'=>['label'=>'شرکت','type'=>'company'],'work_type'=>['label'=>'نوع کار','type'=>'work_type']]);
    $company=$_GET['company_id']??''; $type=$_GET['work_type']??'';
    $statuses=ChoiceRegistry::workflowStatuses();
    echo '<section class="kanban" data-kanban-board><div class="kanban-help">کارت‌ها را بگیرید و بین ستون‌ها جابه‌جا کنید؛ وضعیت بلافاصله در دیتابیس ذخیره می‌شود.</div>';
    foreach($statuses as $s){
        $params=[Tenant::id(),$s]; $where='m.workspace_id=? AND m.status=?';
        if($company){$where.=' AND m.company_id=?';$params[]=$company;}
        if($type){$where.=' AND m.work_type=?';$params[]=$type;}
        $rows=q("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE $where ORDER BY m.legal_deadline IS NULL,m.legal_deadline LIMIT 80",$params);
        echo '<div class="kanban-col" data-kanban-status="'.h($s).'"><h3>'.h($s).' <span data-kanban-count>'.count($rows).'</span></h3><div class="kanban-dropzone">';
        foreach($rows as $r){
            echo '<article class="kanban-card" draggable="true" data-kanban-id="'.(int)$r['id'].'" data-company-id="'.(int)$r['company_id'].'" tabindex="0"><b>'.h($r['work_type']).'</b><small>'.h($r['company_name']).' — '.h($r['month_name']).'</small><time>'.h(fa_date($r['legal_deadline'])).'</time><a class="kanban-open" href="index.php?page=monthly&company_id='.(int)$r['company_id'].'&status='.urlencode($r['status']).'">باز کردن</a></article>';
        }
        echo '</div></div>';
    }
    echo '</section>'; render_footer();
}
function render_settings(): void
{
    render_header('تنظیمات','SMTP، پیامک، Google OAuth، کش سبک و سرویس جانبی/AI آینده');
    $base=base_url('cron.php?secret='.setting('cron_secret',''));
    echo '<section class="card"><form method="post" class="grid-form compact autosave" data-form-key="settings">'.csrf_field().'<input type="hidden" name="action" value="save_settings">
    <h2 class="span4">ایمیل Gmail SMTP</h2>
    <label>SMTP Host<input name="smtp_host" value="'.h(setting('smtp_host','smtp.gmail.com')).'"></label>
    <label>SMTP Port<input name="smtp_port" value="'.h(setting('smtp_port','587')).'"></label>
    <label>Encryption<select name="smtp_encryption"><option value="tls" '.(setting('smtp_encryption','tls')==='tls'?'selected':'').'>TLS</option><option value="ssl" '.(setting('smtp_encryption','tls')==='ssl'?'selected':'').'>SSL</option></select></label>
    <label>SMTP Username<input name="smtp_username" value="'.h(setting('smtp_username','')).'"></label>
    <label>SMTP Password جدید<input name="smtp_password" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label>
    <label>نام فرستنده<input name="mail_from_name" value="'.h(setting('mail_from_name','Accounting CRM')).'"></label>
    <label>ایمیل‌های اعلان<input name="notifications_email_to" value="'.h(setting('notifications_email_to','')).'"></label>

    <h2 class="span4">پیامک قاصدک</h2>
    <label>Ghasedak API Key<input name="ghasedak_api_key" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label>
    <label>Line Number<input name="ghasedak_line_number" value="'.h(setting('ghasedak_line_number','')).'"></label>
    <label>شماره‌های پیامک<input name="notifications_sms_to" value="'.h(setting('notifications_sms_to','')).'"></label>

    <h2 class="span4">Google OAuth</h2>
    <label>Client ID<input name="google_client_id" value="'.h(setting('google_client_id','')).'"></label>
    <label>Client Secret جدید<input name="google_client_secret" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label>
    <label class="span2">Redirect URI<input name="google_redirect_uri" value="'.h(setting('google_redirect_uri',base_url('index.php?page=google_callback'))).'"></label>

    <h2 class="span4">سرویس جانبی / API آینده</h2>
    <label>Cache TTL ثانیه<input name="cache_ttl_seconds" value="'.h(setting('cache_ttl_seconds','30')).'"></label>
    <label class="span2">آدرس سرویس خانگی/Edge<input name="edge_service_url" placeholder="https://public-host:8443" value="'.h(setting('edge_service_url','')).'"></label>
    <label>توکن سرویس جانبی<input name="edge_service_token" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label>
    <label class="check"><input type="checkbox" name="edge_enabled"> فعال‌سازی سرویس جانبی</label>
    <label class="check"><input type="checkbox" name="api_enabled" value="1" '.(setting('api_enabled','1')==='1'?'checked':'').'> API داخلی فعال باشد</label>
    <button class="btn primary">ذخیره تنظیمات</button></form></section>
    <section class="card"><h2>مایگریشن و Cron</h2><form method="post">'.csrf_field().'<input type="hidden" name="action" value="run_migration"><button class="btn">اجرای مایگریشن دیتابیس</button></form>
    <p class="muted">Cron Job پیشنهادی در cPanel:</p><code class="code">/usr/local/bin/php -q '.h(APP_ROOT).'/cron.php</code>
    <p class="muted">یا URL:</p><code class="code">'.h($base).'</code></section>';
    render_footer();
}
