<?php
final class ModuleCenterModule
{
    public static function handle(string $action): void
    {
        if($action!=='module_toggle')return;
        if(!Tenant::isPlatformAdmin())throw new RuntimeException('مدیریت ماژول‌ها فقط برای مدیر کل پلتفرم مجاز است.');
        $key=trim((string)($_POST['module_key']??''));$enabled=(int)($_POST['enabled']??0)===1;
        ModuleRegistry::setEnabled($key,$enabled);
        flash($enabled?'ماژول فعال شد.':'ماژول غیرفعال شد.');redirect('index.php?page=modules');
    }

    public static function render(): void
    {
        if(!Tenant::isPlatformAdmin()){http_response_code(403);throw new RuntimeException('مرکز ماژول‌ها فقط برای مدیر کل پلتفرم در دسترس است.');}
        render_header('مرکز ماژول‌ها','هر Workspace فقط ماژول‌های فعال خود را بارگذاری و نمایش می‌دهد.');
        echo '<section class="card"><div class="section-title"><div><h2>ERPSMART Modular Platform</h2><p class="muted">معماری Wide Platform / Deep Modules — ماژول‌های Planned فقط نقشه محصول هستند و تا زمان Pilot قابل فعال‌سازی نیستند.</p></div></div></section>';
        echo '<section class="acc-grid2">';
        foreach(ModuleRegistry::states() as $key=>$m){
            $effective=!empty($m['enabled_effective']);$implemented=!empty($m['implemented']);$locked=!empty($m['locked']);
            $stage=(string)($m['stage']??'planned');$state=$effective?'فعال':($implemented?'غیرفعال':'برنامه‌ریزی‌شده');
            echo '<article class="card"><div class="section-title"><div><h2>'.h($m['title']).'</h2><p class="muted">'.h($m['description']).'</p></div><span class="badge '.($effective?'done':'open').'">'.h($state).'</span></div>';
            echo '<p><small>کلید: <code>'.h($key).'</code> • مرحله: '.h($stage).'</small></p>';
            $deps=(array)($m['depends']??[]);if($deps){$titles=[];foreach($deps as $dep)$titles[]=ModuleRegistry::definitions()[$dep]['title']??$dep;echo '<p class="muted">وابستگی: '.h(implode('، ',$titles)).'</p>';}
            if($implemented&&!$locked){
                echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="module_toggle"><input type="hidden" name="module_key" value="'.h($key).'"><input type="hidden" name="enabled" value="'.($effective?'0':'1').'"><button class="btn tiny '.($effective?'danger':'primary').'">'.($effective?'غیرفعال‌سازی':'فعال‌سازی').'</button></form>';
            }elseif($locked)echo '<span class="muted">هسته زیرساختی همیشه فعال است.</span>';
            else echo '<span class="muted">در Cycleهای بعدی v10 وارد Pilot می‌شود.</span>';
            echo '</article>';
        }
        echo '</section>';render_footer();
    }
}
