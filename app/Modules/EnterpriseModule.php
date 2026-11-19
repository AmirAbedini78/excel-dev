<?php
/** 8 integrated workspaces with 39 typed operational record screens. */
final class EnterpriseModule
{
    private static function groupForPage(string $page): string
    {
        foreach (EnterpriseDomain::groups() as $key=>$g)if($g['page']===$page)return $key;
        throw new RuntimeException('بخش سازمانی یافت نشد.');
    }

    private static function url(string $page,string $entity='',array $extra=[]): string
    {
        $params=['page'=>$page];if($entity!=='')$params['entity']=$entity;
        return 'index.php?'.http_build_query(array_merge($params,$extra));
    }

    public static function handle(string $action): void
    {
        if(!in_array($action,['enterprise_save','enterprise_archive','enterprise_transition','enterprise_purchase_draft'],true))throw new RuntimeException('عملیات تعریف نشده است.');
        $page=(string)($_POST['page']??'');$group=self::groupForPage($page);
        $entity=(string)($_POST['entity']??'');
        if(EnterpriseDomain::groupFor($entity)!==$group)throw new RuntimeException('دسته رکورد نامعتبر است.');
        $url=self::url($page,$entity);
        try {
            if(!ModuleRegistry::enabled('enterprise_'.$group))throw new RuntimeException('ماژول فعال نیست.');
            $wid=Tenant::id();$cid=AccountingRepository::companyId();$uid=(int)(Auth::user()['id']??0);
            EnterpriseDomain::assertScope($wid,$cid);
            $id=(int)($_POST['id']??0);$revision=(int)($_POST['revision']??0);
            if($action==='enterprise_purchase_draft'){
                if($entity!=='vendor_offers'||$group!=='commerce')throw new RuntimeException('عملیات نامعتبر.');
                $out=EnterpriseDomain::createPurchaseDraft($wid,$cid,$uid,$id);
                flash('سفارش خرید پیش‌نویس #'.$out['id'].' در حسابداری وجود دارد. پرداخت یا ورود انبار ثبت نشد.');
            }elseif($action==='enterprise_save'){
                $output=EnterpriseDomain::save($entity,$wid,$cid,$uid,$_POST,$id,$revision);
                flash(($id>0?'ویرایش':'ثبت').' موفق رکورد #'.$output);
            }elseif($action==='enterprise_transition'){
                EnterpriseDomain::transition($entity,$wid,$cid,$uid,$id,$revision,(string)($_POST['target']??''),(string)($_POST['note']??''));
                flash('وضعیت عملیاتی تغییر کرد؛ هیچ سند مالی یا انباری قطعی ثبت نشده است.');
            }else{
                EnterpriseDomain::archive($entity,$wid,$cid,$uid,$id,$revision);
                flash('رکورد بایگانی شد؛ حذف فیزیکی انجام نشد.');
            }
        }catch(Throwable $e){flash($e instanceof PDOException?'ثبت در پایگاه داده ناموفق بود (کد تکراری یا خطای ساختار).':$e->getMessage(),'danger');}
        redirect($url);
    }

    private static function input(string $field,array $meta,$value,int $wid,int $cid): string
    {
        $kind=$meta['type'];$str=(string)($value??'');$required=!empty($meta['required'])?' required':'';
        if($kind==='ref'){
            $html='<select name="'.h($field).'"'.$required.'><option value="">— انتخاب کنید —</option>';
            foreach(EnterpriseDomain::referenceOptions($meta['ref'],$wid,$cid) as $opt){
                $selected=(string)$opt['id']===$str?' selected':'';
                $html.='<option value="'.(int)$opt['id'].'"'.$selected.'>'.h((string)$opt['label']).' (#'.(int)$opt['id'].')</option>';
            }
            return $html.'</select>';
        }
        if($kind==='select'){
            $html='<select name="'.h($field).'"'.$required.'><option value="">— انتخاب کنید —</option>';
            foreach($meta['choices'] as $k=>$label)$html.='<option value="'.h($k).'"'.($str===$k?' selected':'').'>'.h($label).'</option>';
            return $html.'</select>';
        }
        if($kind==='memo')return '<textarea name="'.h($field).'" maxlength="3000" rows="3"'.$required.'>'.h($str).'</textarea>';
        if(in_array($kind,['money','qty','int','percent'],true)){
            $step=match($kind){'money'=>'0.01','qty'=>'0.0001','percent'=>'0.001',default=>'1'};
            $max=$kind==='percent'?' max="100"':'';
            return '<input type="number" name="'.h($field).'" min="0" step="'.$step.'"'.$max.' value="'.h($str).'"'.$required.'>';
        }
        $type=$kind==='date'?'date':'text';
        return '<input type="'.$type.'" name="'.h($field).'" maxlength="190" value="'.h($str).'"'.$required.'>';
    }

    private static function val(array $row,string $key,array $meta,array $refs): string
    {
        $v=$row[$key]??null;
        if($v===null||$v==='')return '—';
        if($meta['type']==='select')return h($meta['choices'][(string)$v]??(string)$v);
        if($meta['type']==='ref')return h($refs[$meta['ref']][(int)$v]??('#'.$v));
        if($meta['type']==='money')return number_format((float)$v,0).' ریال';
        if($meta['type']==='memo')return h(function_exists('mb_substr')?mb_substr((string)$v,0,80,'UTF-8'):substr((string)$v,0,80));
        return h((string)$v);
    }

    private static function hidden(string $page,string $entity,int $id,int $revision,string $action): string
    {
        return csrf_field().'<input type="hidden" name="action" value="'.h($action).'">'
            .'<input type="hidden" name="page" value="'.h($page).'">'
            .'<input type="hidden" name="entity" value="'.h($entity).'">'
            .'<input type="hidden" name="id" value="'.$id.'">'
            .'<input type="hidden" name="revision" value="'.$revision.'">';
    }

    public static function render(string $page): void
    {
        $group=self::groupForPage($page);
        Tenant::requirePermission(EnterpriseDomain::permission($group,'view'));
        $g=EnterpriseDomain::groups()[$group];$wid=Tenant::id();$cid=AccountingRepository::companyId();
        render_header($g['title'],'ماژول‌های عملیاتی جدید • رکوردهای واقعی شرکت جاری؛ پرداخت، سند مالی و جابه‌جایی موجودی انجام نمی‌شود.');
        if(!$cid){echo '<section class="card">ابتدا شرکت فعال انتخاب کنید.</section>';render_footer();return;}
        $keys=$g['entities'];$selected=(string)($_GET['entity']??$keys[0]);
        if(!in_array($selected,$keys,true))$selected=$keys[0];
        $spec=EnterpriseDomain::spec($selected);$canManage=Tenant::can(EnterpriseDomain::permission($group,'manage'));
        $canApprove=Tenant::can(EnterpriseDomain::permission($group,'approve'));
        echo '<section class="card"><p><strong>'.h($g['title']).'</strong> — '.count($keys).' زیربخش عملیاتی</p>';
        echo '<div class="erp-ext-tabs">';foreach($keys as $key)$d=EnterpriseDomain::spec($key);
        foreach($keys as $key){$d=EnterpriseDomain::spec($key);echo '<a class="btn tiny '.($key===$selected?'primary':'').'" href="'.h(self::url($page,$key)).'">'.h($d['title']).'</a>';}
        echo '</div></section>';
        $editId=max(0,(int)($_GET['edit']??0));$old=$editId?EnterpriseDomain::find($selected,$wid,$cid,$editId):null;
        if($editId && !$old)$editId=0;
        $search=trim((string)($_GET['search']??''));$filter=(string)($_GET['state']??'');
        $rows=EnterpriseDomain::listRecords($selected,$wid,$cid,$search,$filter);
        $refs=[];
        foreach($spec['fields'] as $f){if($f['type']!=='ref'||isset($refs[$f['ref']]))continue;
            $refs[$f['ref']]=[];foreach(EnterpriseDomain::referenceOptions($f['ref'],$wid,$cid) as $ref)$refs[$f['ref']][(int)$ref['id']]=(string)$ref['label'];
        }
        $statusLabels=['draft'=>'پیش‌نویس','submitted'=>'ارسال برای بررسی','approved'=>'تأیید داخلی','rejected'=>'رد شده','active'=>'فعال','closed'=>'بسته','cancelled'=>'لغوشده'];
        echo '<section class="card"><div class="section-title"><h2>'.h($spec['title']).'</h2><span class="muted">نمایش '.count($rows).' رکورد اخیر</span></div>';
        echo '<form class="erp-ext-filters" method="get"><input type="hidden" name="page" value="'.h($page).'"><input type="hidden" name="entity" value="'.h($selected).'">'
            .'<input type="search" name="search" maxlength="80" placeholder="جست‌وجوی کد یا عنوان" value="'.h($search).'">'
            .'<select name="state"><option value="">همه وضعیت‌ها</option>';
        foreach($statusLabels as $code=>$label)echo '<option value="'.$code.'"'.($filter===$code?' selected':'').'>'.h($label).'</option>';
        echo '</select><button class="btn tiny">اعمال فیلتر</button><a class="btn tiny" href="'.h(self::url($page,$selected)).'">پاک کردن</a></form>';
        if($canManage){
            echo '<details class="erp-ext-editor"'.(($editId||isset($_GET['new']))?' open':'').'><summary>'.($old?'ویرایش رکورد #'.$editId:'ایجاد رکورد جدید').' — '.h($spec['title']).'</summary>';
            echo '<form method="post" class="erp-ext-form">'.self::hidden($page,$selected,$editId,(int)($old['revision']??0),'enterprise_save');
            echo '<label>کد یکتا (لاتین)<input name="code" maxlength="80" required pattern="[A-Za-z0-9][A-Za-z0-9_.-]*" value="'.h((string)($old['code']??'')).'"></label>';
            echo '<label>عنوان<input name="title" maxlength="190" required value="'.h((string)($old['title']??'')).'"></label>';
            foreach($spec['fields'] as $field=>$meta)echo '<label>'.h($meta['label']).self::input($field,$meta,$old[$field]??null,$wid,$cid).'</label>';
            echo '<div class="erp-ext-form-footer"><button type="submit" class="btn primary">'.($old?'ذخیره ویرایش':'ایجاد رکورد').'</button>'
                .'<a class="btn" href="'.h(self::url($page,$selected)).'">انصراف</a></div></form></details>';
            echo '<p><a class="btn tiny" href="'.h(self::url($page,$selected,['new'=>1])).'">+ ثبت '.h($spec['title']).'</a></p>';
        }
        echo '<div class="erp-ext-scroll"><table><thead><tr><th>کد / عنوان</th><th>وضعیت</th><th>ویرایش</th>';
        $shown=array_slice($spec['fields'],0,3,true);foreach($shown as $meta)echo '<th>'.h($meta['label']).'</th>';
        echo '<th>مدیریت / فرآیند</th></tr></thead><tbody>';
        foreach($rows as $r){$id=(int)$r['id'];$rev=(int)$r['revision'];
            echo '<tr><td><b>'.h($r['code']).'</b><div>'.h($r['title']).'</div><small>#'.$id.'</small></td>';
            echo '<td><span class="badge">'.h($statusLabels[$r['status']]??$r['status']).'</span></td>';
            echo '<td><a class="btn tiny" href="'.h(self::url($page,$selected,['edit'=>$id])).'">'.($canManage?'ویرایش':'مشاهده').'</a></td>';
            foreach($shown as $field=>$meta)echo '<td>'.self::val($r,$field,$meta,$refs).'</td>';
            echo '<td class="erp-ext-actions">';
            foreach(EnterpriseDomain::nextStatusesFor($selected,(string)$r['status']) as $target){
                $approve=in_array($target,['approved','rejected'],true);
                if($approve&&!$canApprove||!$approve&&!$canManage)continue;
                echo '<form method="post">'.self::hidden($page,$selected,$id,$rev,'enterprise_transition')
                    .'<input type="hidden" name="target" value="'.h($target).'">';
                if($target==='rejected')echo '<input name="note" required maxlength="1000" placeholder="دلیل رد">';
                echo '<button class="btn tiny" title="این عملیات ثبت حسابداری/انبار نیست">'.h($statusLabels[$target]??$target).'</button></form>';
            }
            if($selected==='vendor_offers'&&$canManage&&Tenant::can('accounting.purchase.manage')&&in_array($r['status'],['approved','active'],true)){
                echo '<form method="post">'.self::hidden($page,$selected,$id,$rev,'enterprise_purchase_draft')
                    .'<button class="btn tiny primary">سفارش خرید پیش‌نویس</button></form>';
            }
            if($canManage&&in_array($r['status'],['draft','rejected','cancelled','closed'],true)){
                echo '<form method="post">'.self::hidden($page,$selected,$id,$rev,'enterprise_archive')
                    .'<button class="btn tiny danger" onclick="return confirm(\'بایگانی این رکورد؟\')">بایگانی</button></form>';
            }
            echo '<a class="btn tiny" href="'.h(self::url($page,$selected,['history'=>$id])).'">تاریخچه</a></td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="9">رکوردی در شرکت جاری یافت نشد.</td></tr>';
        echo '</tbody></table></div></section>';
        $historyId=max(0,(int)($_GET['history']??0));
        if($historyId && EnterpriseDomain::find($selected,$wid,$cid,$historyId)){
            echo '<section class="card"><h2>تاریخچه رکورد #'.$historyId.'</h2><ol>';
            foreach(EnterpriseDomain::history($selected,$wid,$cid,$historyId) as $evt){
                echo '<li>'.h((string)$evt['created_at']).' — '.h($evt['action']).' | '.h($evt['from_status']??'—').' → '.h($evt['to_status']??'—')
                    .' (نسخه '.(int)$evt['revision'].') '.h($evt['comment_text']??'').'</li>';
            }echo '</ol></section>';
        }
        render_footer();
    }
}
