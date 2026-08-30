<?php
final class CrmModule
{
    public static function handle(string $action): void
    {
        Tenant::requirePermission('crm.manage');$wid=Tenant::id();$cid=AccountingRepository::companyId();$uid=(int)(Auth::user()['id']??0);
        if(!$cid)throw new RuntimeException('شرکت فعال انتخاب نشده است.');
        if($action==='crm_add_contact'){$r=CrmDomain::createContact($wid,$cid,$uid,$_POST);flash('مخاطب ثبت شد.');redirect('index.php?page=crm&party_id='.(int)$r['party_id']);}
        if($action==='crm_add_opportunity'){$r=CrmDomain::createOpportunity($wid,$cid,$uid,$_POST);flash('فرصت فروش '.$r['opportunity_no'].' ثبت شد.');redirect('index.php?page=crm&party_id='.(int)$r['party_id']);}
        if($action==='crm_add_activity'){$r=CrmDomain::createActivity($wid,$cid,$uid,$_POST);flash('پیگیری '.$r['activity_no'].' ثبت شد.');redirect('index.php?page=crm&party_id='.(int)$r['party_id']);}
        if($action==='crm_complete_activity'){CrmDomain::completeActivity($wid,$cid,$uid,(int)($_POST['activity_id']??0));flash('پیگیری تکمیل شد.');redirect($_SERVER['HTTP_REFERER']??'index.php?page=crm&section=followups');}
        if($action==='crm_move_opportunity'){CrmDomain::moveOpportunity($wid,$cid,(int)($_POST['opportunity_id']??0),(string)($_POST['stage']??''));flash('مرحله فرصت به‌روزرسانی شد.');redirect($_SERVER['HTTP_REFERER']??'index.php?page=crm&section=pipeline');}
    }

    public static function render(): void
    {
        Tenant::requirePermission('crm.view');$section=(string)($_GET['section']??'customers');if(!in_array($section,['customers','pipeline','followups'],true))$section='customers';
        render_header('CRM و فروش','Customer 360 روی acc_parties؛ بدون master مشتری موازی.');
        echo '<nav class="acc-tabs"><a class="'.($section==='customers'?'active':'').'" href="index.php?page=crm">مشتریان</a><a class="'.($section==='pipeline'?'active':'').'" href="index.php?page=crm&section=pipeline">Pipeline</a><a class="'.($section==='followups'?'active':'').'" href="index.php?page=crm&section=followups">پیگیری‌ها</a></nav>';
        $partyId=(int)($_GET['party_id']??0);
        if($partyId)self::customer($partyId);elseif($section==='pipeline')self::pipeline();elseif($section==='followups')self::followups();else self::customers();
        render_footer();
    }

    private static function m($v): string{return number_format((float)$v).' ریال';}

    private static function customers(): void
    {
        $q=trim((string)($_GET['q']??''));$rows=CrmDomain::searchCustomers(Tenant::id(),AccountingRepository::companyId(),$q);
        echo '<section class="card"><form method="get" class="filters compact"><input type="hidden" name="page" value="crm"><input name="q" value="'.h($q).'" placeholder="نام، کد، موبایل..."><button class="btn">جستجو</button></form></section>';
        echo '<section class="card table-card"><div class="table-wrap"><table><thead><tr><th>کد</th><th>مشتری</th><th>موبایل</th><th>سقف اعتبار</th><th></th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td>'.h($r['code']??'—').'</td><td>'.h($r['name']).'</td><td>'.h($r['mobile']??'—').'</td><td>'.self::m($r['credit_limit']).'</td><td><a class="btn tiny" href="index.php?page=crm&party_id='.(int)$r['id'].'">Customer 360</a></td></tr>';
        if(!$rows)echo '<tr><td colspan="5">مشتری پیدا نشد.</td></tr>';echo '</tbody></table></div></section>';
    }

    private static function customer(int $partyId): void
    {
        $d=CrmDomain::customer360(Tenant::id(),AccountingRepository::companyId(),$partyId);$p=$d['party'];$f=$d['financial'];$c=$d['crm'];
        echo '<section class="card"><div class="section-title"><div><h2>'.h($p['name']).'</h2><p class="muted">'.h(($p['code']??'').' • '.($p['mobile']??'')).'</p></div><a class="btn tiny" href="index.php?page=crm">بازگشت</a></div>';
        echo '<div class="acc-kpis"><div><b>'.self::m($f['current_balance_irr']).'</b><small>مانده • '.h($f['balance_nature']).'</small></div><div><b>'.self::m($f['recorded_sales_net_irr']).'</b><small>فروش ثبت‌شده</small></div><div><b>'.(int)$f['sales_document_count'].'</b><small>سند فروش</small></div><div><b>'.number_format((float)$f['outstanding_sales_quantity'],4).'</b><small>تحویل‌نشده</small></div><div><b>'.self::m($c['open_pipeline_irr']).'</b><small>Pipeline باز</small></div><div><b>'.self::m($c['weighted_pipeline_irr']).'</b><small>Pipeline وزنی</small></div></div></section>';

        echo '<section class="grid-2"><article class="card"><h3>مخاطبان</h3>';
        foreach($d['contacts'] as $r)echo '<div class="acc-list-row"><span><b>'.h($r['full_name']).'</b><br><small>'.h(($r['job_title']??'').' '.($r['mobile']??'').' '.($r['email']??'')).'</small></span></div>';
        if(Tenant::can('crm.manage'))echo '<details><summary>+ مخاطب</summary><form method="post" class="grid-form acc-form">'.csrf_field().'<input type="hidden" name="action" value="crm_add_contact"><input type="hidden" name="party_id" value="'.$partyId.'"><label>نام<input name="full_name" required></label><label>سمت<input name="job_title"></label><label>موبایل<input name="mobile"></label><label>ایمیل<input name="email"></label><button class="btn primary">ثبت</button></form></details>';
        echo '</article><article class="card"><h3>فرصت‌ها</h3>';
        foreach(array_slice($d['opportunities'],0,10) as $o)echo '<div class="acc-list-row"><span><b>'.h($o['title']).'</b><br><small>'.h($o['stage']).' • '.number_format((float)$o['probability']).'%</small></span><b>'.self::m($o['amount_irr']).'</b></div>';
        if(Tenant::can('crm.manage'))echo '<details><summary>+ فرصت فروش</summary><form method="post" class="grid-form acc-form">'.csrf_field().'<input type="hidden" name="action" value="crm_add_opportunity"><input type="hidden" name="party_id" value="'.$partyId.'"><label class="span2">عنوان<input name="title" required></label><label>مبلغ<input type="number" name="amount_irr" value="0"></label><label>احتمال<input type="number" min="0" max="100" name="probability" value="50"></label><label>مرحله<select name="stage"><option value="qualification">Qualification</option><option value="proposal">Proposal</option><option value="negotiation">Negotiation</option></select></label><label>تاریخ هدف<input class="jalali-date" name="expected_close_date"></label><button class="btn primary">ثبت</button></form></details>';
        echo '</article></section><section class="card"><h3>Activity / Follow-up</h3>';
        foreach(array_slice($d['activities'],0,15) as $a)echo '<div class="acc-list-row"><span><b>'.h($a['subject']).'</b><br><small>'.h($a['activity_type']).' • '.h(AccountingRepository::faDate($a['due_date']??null)).' • '.h($a['status']).'</small></span></div>';
        if(Tenant::can('crm.manage'))echo '<details><summary>+ پیگیری</summary><form method="post" class="grid-form acc-form">'.csrf_field().'<input type="hidden" name="action" value="crm_add_activity"><input type="hidden" name="party_id" value="'.$partyId.'"><label>نوع<select name="activity_type"><option value="call">تماس</option><option value="meeting">جلسه</option><option value="email">ایمیل</option><option value="message">پیام</option><option value="task">کار</option></select></label><label class="span2">موضوع<input name="subject" required></label><label>سررسید<input class="jalali-date" name="due_date"></label><button class="btn primary">ثبت</button></form></details>';
        echo '</section>';
    }

    private static function pipeline(): void
    {
        $d=CrmDomain::pipelineSummary(Tenant::id(),AccountingRepository::companyId());
        echo '<section class="card"><div class="acc-kpis"><div><b>'.(int)$d['open_count'].'</b><small>فرصت باز</small></div><div><b>'.self::m($d['open_amount_irr']).'</b><small>Pipeline</small></div><div><b>'.self::m($d['weighted_amount_irr']).'</b><small>Weighted</small></div></div></section>';
        echo '<section class="card table-card"><div class="table-wrap"><table><thead><tr><th>فرصت</th><th>مشتری</th><th>مرحله</th><th>مبلغ</th><th>احتمال</th><th>عملیات</th></tr></thead><tbody>';
        foreach($d['opportunities'] as $o){echo '<tr><td>'.h($o['opportunity_no'].' - '.$o['title']).'</td><td><a href="index.php?page=crm&party_id='.(int)$o['party_id'].'">'.h($o['party_name']).'</a></td><td>'.h($o['stage']).'</td><td>'.self::m($o['amount_irr']).'</td><td>'.number_format((float)$o['probability']).'%</td><td>';
            if(Tenant::can('crm.manage')&&(string)$o['status']==='open')echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="crm_move_opportunity"><input type="hidden" name="opportunity_id" value="'.(int)$o['id'].'"><select name="stage"><option value="qualification">Qualification</option><option value="proposal">Proposal</option><option value="negotiation">Negotiation</option><option value="won">Won</option><option value="lost">Lost</option></select><button class="btn tiny">تغییر</button></form>';echo '</td></tr>';}
        echo '</tbody></table></div></section>';
    }

    private static function followups(): void
    {
        $d=CrmDomain::followupQueue(Tenant::id(),AccountingRepository::companyId(),7);
        echo '<section class="card"><div class="acc-kpis"><div><b>'.(int)$d['overdue_count'].'</b><small>عقب‌افتاده</small></div><div><b>'.(int)$d['today_count'].'</b><small>امروز</small></div><div><b>'.(int)$d['upcoming_count'].'</b><small>آینده</small></div></div></section>';
        echo '<section class="card table-card"><div class="table-wrap"><table><thead><tr><th>سررسید</th><th>مشتری</th><th>موضوع</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach($d['rows'] as $a){echo '<tr><td>'.h(AccountingRepository::faDate($a['due_date'])).'</td><td><a href="index.php?page=crm&party_id='.(int)$a['party_id'].'">'.h($a['party_name']).'</a></td><td>'.h($a['subject']).'</td><td>'.h($a['bucket']).'</td><td>';
            if(Tenant::can('crm.manage'))echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="crm_complete_activity"><input type="hidden" name="activity_id" value="'.(int)$a['id'].'"><button class="btn tiny">انجام شد</button></form>';echo '</td></tr>';}
        echo '</tbody></table></div></section>';
    }
}
