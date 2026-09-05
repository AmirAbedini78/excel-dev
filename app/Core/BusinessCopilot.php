<?php
final class BusinessCopilot
{
    public static function enabled(): bool
    {
        return Auth::check()&&ModuleRegistry::pageEnabled('ai')&&Tenant::can('ai.use');
    }

    public static function currentPageRefs(): array
    {
        if(!self::enabled())return[];$page=(string)($_GET['page']??'dashboard');$ref=null;
        if($page==='crm'&&(int)($_GET['party_id']??0)>0)$ref=['type'=>'party.customer','id'=>(int)$_GET['party_id']];
        elseif($page==='trade'&&(int)($_GET['case_id']??0)>0)$ref=['type'=>'trade.case','id'=>(int)$_GET['case_id']];
        elseif($page==='procurement'&&(int)($_GET['receive']??0)>0)$ref=['type'=>'purchase.document','id'=>(int)$_GET['receive']];
        return$ref?[$ref]:[];
    }

    public static function renderLauncher(): void
    {
        if(!self::enabled())return;echo '<button type="button" class="btn tiny copilot-launcher-top" data-copilot-open aria-controls="business-copilot">◉ دستیار</button>';
    }

    public static function renderShell(): void
    {
        if(!self::enabled())return;
        $company=AccountingRepository::company();$cid=(int)($company['id']??0);$uid=(int)(Auth::user()['id']??0);$pageRefs=self::currentPageRefs();$pageEntities=[];
        if($cid&&$pageRefs){try{$pageEntities=AiContextResolver::resolve(Tenant::id(),$cid,$pageRefs);}catch(Throwable $e){$pageEntities=[];}}
        $companies=[];foreach(AccountingRepository::companies() as $c)$companies[]=['id'=>(int)$c['id'],'name'=>(string)$c['name']];
        $config=[
            'workspace_id'=>Tenant::id(),'user_id'=>$uid,
            'company_id'=>$cid,'company_name'=>(string)($company['name']??''),'companies'=>$companies,
            'page'=>(string)($_GET['page']??'dashboard'),'current_page_refs'=>$pageRefs,'current_page_entities'=>$pageEntities,
            'csrf'=>csrf_token(),'skills'=>AiCapabilityRegistry::catalog(),'endpoint'=>'index.php?copilot_api=1'
        ];
        echo '<button type="button" class="copilot-fab" data-copilot-open aria-label="باز کردن Business Copilot">◉</button>';
        echo '<div class="copilot-backdrop" data-copilot-close></div><aside id="business-copilot" class="copilot-sidecar" aria-hidden="true" data-copilot-config="'.h(json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'">';
        echo '<header class="copilot-head"><div><span>ERPSMART</span><strong>Business Copilot</strong><small data-copilot-scope-name>گفتگو: '.h((string)($company['name']??'بدون شرکت')).'</small></div><div class="copilot-head-actions"><a class="btn tiny" href="index.php?page=ai">مرکز فرمان</a><button class="btn tiny" type="button" data-copilot-close>×</button></div></header>';
        echo '<div class="copilot-scopebar"><label><span>شرکت گفتگو</span><select data-copilot-company>';
        foreach($companies as $c)echo '<option value="'.(int)$c['id'].'" '.((int)$c['id']===$cid?'selected':'').'>'.h($c['name']).'</option>';
        echo '</select></label><label><span>گفتگو</span><select data-copilot-conversation><option value="0">گفتگوی جدید</option></select></label><button type="button" class="btn tiny" data-copilot-new>＋ جدید</button></div>';
        echo '<div class="copilot-page-context" data-copilot-page-context></div>';
        echo '<div class="copilot-thread" data-copilot-thread><div class="copilot-welcome"><b>دستیار آماده است.</b><span><code>@</code> در کل محیط کاری جست‌وجو می‌کند؛ اگر موجودیت متعلق به شرکت دیگری باشد، فقط زمینه گفتگوی Copilot به همان شرکت تغییر می‌کند.</span></div></div>';
        echo '<div class="copilot-preview" data-copilot-preview hidden></div>';
        echo '<div class="copilot-composer"><div class="copilot-chips" data-copilot-chips></div><div class="copilot-mention-menu" data-copilot-mention-menu hidden></div>';
        echo '<div class="copilot-quick-actions"><button type="button" data-copilot-template="برای این شرکت یک بریف مدیریتی کوتاه از ۵ موضوع مهم امروز در فروش، خرید، موجودی، مطالبات و بازرگانی بده؛ هر مورد را با داده ERP و اقدام پیشنهادی مشخص کن.">بریف مدیرعامل</button><button type="button" data-copilot-template="پرونده‌های بازرگانی، محموله‌ها، ETA، گمرک و Landed Cost این شرکت را بررسی کن و ریسک‌های فوری و اقدام بعدی را اولویت‌بندی کن.">ریسک بازرگانی</button><button type="button" data-copilot-template="موجودی، رزرو، ورودی مورد انتظار و ریسک کمبود این شرکت را بررسی کن و اقلام پرریسک را اولویت‌بندی کن.">ریسک موجودی</button><button type="button" data-copilot-template="وضعیت مطالبات، پرداخت‌ها و فشار نقدینگی این شرکت را بر اساس داده‌های موجود بررسی کن و موارد فوری را بگو.">نقدینگی و وصول</button></div>';
        echo '<textarea rows="3" data-copilot-input placeholder="مثلاً: وضعیت معاملاتمون با @کارخانه ... چطوره؟"></textarea><div class="copilot-compose-actions"><span class="muted">@ جست‌وجوی سراسری موجودیت • داده‌ها هر بار از ERP تازه خوانده می‌شوند</span><button class="btn primary" type="button" data-copilot-send>ارسال</button></div></div>';
        echo '</aside>';
        echo '<script src="assets/business-copilot-cycle12.js?v=10.9.0" defer></script>';
    }
}
