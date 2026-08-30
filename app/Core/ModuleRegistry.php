<?php
final class ModuleRegistry
{
    public const VERSION='10.4.0';
    private static array $cache=[];

    public static function definitions(): array
    {
        return [
            'core_workbench'=>[
                'title'=>'محیط کاری و ابزارهای پایه','description'=>'تقویم، برنامه روزانه/ماهانه، کانبان، شرکت‌ها، سامانه‌ها، نوت، دفترچه تلفن و فایل‌ها.',
                'stage'=>'legacy','implemented'=>true,'default_enabled'=>true,'locked'=>true,'depends'=>[],
                'pages'=>['dashboard','monthly','daily','kanban','companies','systems','phonebook','notes','library','custom_fields','choices'],
            ],
            'finance'=>[
                'title'=>'مالی و حسابداری','description'=>'هسته مالی، خرید، فروش، رزرو/تحویل، حاشیه سود، اسناد حسابداری، خزانه، گزارش و قابلیت‌های مالی AI.',
                'stage'=>'pilot','implemented'=>true,'default_enabled'=>true,'locked'=>false,'depends'=>[],
                'pages'=>['industrial'],
            ],
            'ai'=>[
                'title'=>'هوش مصنوعی و Agent','description'=>'دستیار، Jobها، تحلیل، پیش‌بینی، Proposal/Approval و اتوماسیون هوشمند.',
                'stage'=>'pilot','implemented'=>true,'default_enabled'=>true,'locked'=>false,'depends'=>['finance'],
                'pages'=>['ai'],
            ],
            'inventory'=>[
                'title'=>'انبار و موجودی','description'=>'رسید خرید، Stock Ledger، موجودی، رزرو، Available و Reorder Intelligence.',
                'stage'=>'pilot','implemented'=>true,'default_enabled'=>true,'locked'=>false,'depends'=>[], 'pages'=>['inventory'],
            ],
            'procurement'=>[
                'title'=>'تأمین و خرید','description'=>'جریان اسناد خرید، Expected Inbound، دریافت و Replenishment Intelligence.',
                'stage'=>'pilot','implemented'=>true,'default_enabled'=>true,'locked'=>false,'depends'=>['inventory'], 'pages'=>['procurement'],
            ],
            'crm'=>[
                'title'=>'CRM و فروش','description'=>'Customer 360، Contact، Opportunity، Pipeline، Activity و پیگیری فروش روی طرف‌حساب‌های واقعی.',
                'stage'=>'pilot','implemented'=>true,'default_enabled'=>true,'locked'=>false,'depends'=>['finance'], 'pages'=>['crm'],
            ],
            'trade'=>[
                'title'=>'بازرگانی و لجستیک','description'=>'Trade Case، Shipment، ETA، گمرک، Estimated/Actual Landed Cost و Trade Risk.',
                'stage'=>'pilot','implemented'=>true,'default_enabled'=>true,'locked'=>false,'depends'=>['inventory','procurement'], 'pages'=>['trade'],
            ],
            'production'=>[
                'title'=>'تولید','description'=>'BOM، دستور تولید، مصرف، رسید تولید، بهای تمام‌شده و MRP-lite.',
                'stage'=>'planned','implemented'=>false,'default_enabled'=>false,'locked'=>false,'depends'=>['inventory'], 'pages'=>[],
            ],
            'projects'=>[
                'title'=>'مدیریت پروژه','description'=>'پروژه، Task، زمان، هزینه، بودجه و سودآوری.',
                'stage'=>'planned','implemented'=>false,'default_enabled'=>false,'locked'=>false,'depends'=>[], 'pages'=>[],
            ],
            'hr'=>[
                'title'=>'منابع انسانی','description'=>'پرسنل، قرارداد، حضور و غیاب، مرخصی و حقوق.',
                'stage'=>'planned','implemented'=>false,'default_enabled'=>false,'locked'=>false,'depends'=>[], 'pages'=>[],
            ],
            'service'=>[
                'title'=>'خدمات و پشتیبانی','description'=>'Ticket، SLA، Warranty و خدمات پس از فروش.',
                'stage'=>'planned','implemented'=>false,'default_enabled'=>false,'locked'=>false,'depends'=>['crm'], 'pages'=>[],
            ],
            'marketing'=>[
                'title'=>'بازاریابی و محتوا','description'=>'Campaign، Segment، Content/Social Agent و Lead Capture.',
                'stage'=>'planned','implemented'=>false,'default_enabled'=>false,'locked'=>false,'depends'=>['crm'], 'pages'=>[],
            ],
            'administration'=>[
                'title'=>'مدیریت و زیرساخت','description'=>'کاربران، اشتراک داده، عملکرد، تنظیمات و مدیریت پلتفرم.',
                'stage'=>'core','implemented'=>true,'default_enabled'=>true,'locked'=>true,'depends'=>[],
                'pages'=>['modules','shares','access','performance','settings','platform'],
            ],
        ];
    }

    public static function ensureSchema(): void
    {
        pdo()->exec("CREATE TABLE IF NOT EXISTS workspace_modules (
            workspace_id INT NOT NULL,
            module_key VARCHAR(80) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            config_json JSON NULL,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (workspace_id,module_key),
            INDEX idx_workspace_modules_enabled (workspace_id,enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function boot(): void
    {
        if(!Auth::check())return;
        self::seedWorkspace(Tenant::id());
    }

    private static function seedWorkspace(int $wid): void
    {
        if($wid<=0)return;
        $defs=self::definitions();
        $st=pdo()->prepare("INSERT IGNORE INTO workspace_modules (workspace_id,module_key,enabled,updated_at) VALUES (?,?,?,NOW())");
        foreach($defs as $key=>$def)$st->execute([$wid,$key,!empty($def['default_enabled'])?1:0]);
        // New default-enabled Pilot modules are enabled for untouched rows only.
        // An explicit admin toggle sets updated_by and is never overwritten here.
        $up=pdo()->prepare("UPDATE workspace_modules SET enabled=1,updated_at=NOW() WHERE workspace_id=? AND module_key=? AND updated_by IS NULL");
        foreach($defs as $key=>$def)if(!empty($def['implemented'])&&!empty($def['default_enabled']))$up->execute([$wid,$key]);
    }

    public static function enabled(string $key,?int $wid=null,array $trail=[]): bool
    {
        $defs=self::definitions();if(!isset($defs[$key]))return false;
        $wid=$wid??Tenant::id();if($wid<=0)return false;
        if(isset($trail[$key]))return false;$trail[$key]=true;
        $cacheKey=$wid.':'.$key;
        if(array_key_exists($cacheKey,self::$cache))$enabled=(bool)self::$cache[$cacheKey];
        else{
            self::seedWorkspace($wid);
            $st=pdo()->prepare("SELECT enabled FROM workspace_modules WHERE workspace_id=? AND module_key=? LIMIT 1");$st->execute([$wid,$key]);
            $raw=$st->fetchColumn();$enabled=$raw===false?!empty($defs[$key]['default_enabled']):(bool)$raw;
            self::$cache[$cacheKey]=$enabled;
        }
        if(!$enabled)return false;
        foreach((array)($defs[$key]['depends']??[]) as $dep)if(!self::enabled((string)$dep,$wid,$trail))return false;
        return true;
    }

    public static function pageModule(string $page): ?string
    {
        foreach(self::definitions() as $key=>$def)if(in_array($page,(array)($def['pages']??[]),true))return $key;
        return null;
    }

    public static function pageEnabled(string $page): bool
    {
        $module=self::pageModule($page);return $module===null?true:self::enabled($module);
    }

    public static function states(?int $wid=null): array
    {
        $wid=$wid??Tenant::id();$out=[];
        foreach(self::definitions() as $key=>$def){
            $raw=false;
            if($wid>0){$st=pdo()->prepare("SELECT enabled FROM workspace_modules WHERE workspace_id=? AND module_key=? LIMIT 1");$st->execute([$wid,$key]);$v=$st->fetchColumn();$raw=$v===false?!empty($def['default_enabled']):(bool)$v;}
            $out[$key]=$def+['key'=>$key,'enabled_raw'=>$raw,'enabled_effective'=>self::enabled($key,$wid)];
        }
        return $out;
    }

    public static function setEnabled(string $key,bool $enabled): void
    {
        if(!Tenant::isPlatformAdmin())throw new RuntimeException('مدیریت ماژول‌ها فقط برای مدیر کل پلتفرم مجاز است.');
        $defs=self::definitions();if(!isset($defs[$key]))throw new RuntimeException('ماژول نامعتبر است.');$def=$defs[$key];
        if(!empty($def['locked'])&&!$enabled)throw new RuntimeException('این ماژول زیرساختی قابل غیرفعال‌سازی نیست.');
        if($enabled&&!($def['implemented']??false))throw new RuntimeException('این ماژول هنوز وارد نسخه Pilot نشده است.');
        $wid=Tenant::id();self::seedWorkspace($wid);
        if($enabled){foreach((array)($def['depends']??[]) as $dep)if(!self::enabled((string)$dep,$wid))throw new RuntimeException('ابتدا ماژول وابسته «'.(self::definitions()[$dep]['title']??$dep).'» را فعال کنید.');}
        else{
            foreach(self::definitions() as $otherKey=>$other){
                if(in_array($key,(array)($other['depends']??[]),true)&&self::enabled($otherKey,$wid))throw new RuntimeException('ابتدا ماژول وابسته «'.$other['title'].'» را غیرفعال کنید.');
            }
        }
        pdo()->prepare("UPDATE workspace_modules SET enabled=?,updated_by=?,updated_at=NOW() WHERE workspace_id=? AND module_key=?")
            ->execute([$enabled?1:0,(int)(Auth::user()['id']??0),$wid,$key]);
        self::$cache=[];
        Audit::log('module.toggle','workspace_modules',0,($enabled?'فعال‌سازی ':'غیرفعال‌سازی ').$def['title'],null,['module_key'=>$key,'enabled'=>$enabled]);
    }
}
