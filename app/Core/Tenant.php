<?php
final class Tenant
{
    private static ?array $workspace=null;
    private static ?array $membership=null;
    private static array $permissions=[];

    public static function ensureSchema(): void
    {
        if(class_exists('RuntimeCache') && RuntimeCache::schemaReady(RuntimeCache::SCHEMA_VERSION)) return;
        $pdo=pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS workspaces (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL UNIQUE,
            owner_user_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            plan_key VARCHAR(60) NOT NULL DEFAULT 'starter',
            subscription_status VARCHAR(40) NOT NULL DEFAULT 'trial',
            trial_ends_at DATETIME NULL,
            settings_json JSON NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_workspace_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            role_key VARCHAR(80) NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uniq_workspace_role (workspace_id,role_key),
            INDEX idx_role_workspace (workspace_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            user_id INT NOT NULL,
            role_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            joined_at DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uniq_workspace_user (workspace_id,user_id),
            INDEX idx_member_user (user_id,status),
            INDEX idx_member_workspace (workspace_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(120) NOT NULL UNIQUE,
            title VARCHAR(190) NOT NULL,
            group_key VARCHAR(80) NOT NULL,
            sort_order INT NOT NULL DEFAULT 100
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id,permission_id),
            INDEX idx_rp_permission (permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            email VARCHAR(190) NOT NULL,
            role_id INT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            INDEX idx_invite_workspace (workspace_id,email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL UNIQUE,
            provider VARCHAR(60) NULL,
            external_customer_id VARCHAR(190) NULL,
            external_subscription_id VARCHAR(190) NULL,
            plan_key VARCHAR(60) NOT NULL DEFAULT 'starter',
            status VARCHAR(40) NOT NULL DEFAULT 'trial',
            current_period_start DATETIME NULL,
            current_period_end DATETIME NULL,
            cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
            meta_json JSON NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensurePlatformAdminColumn($pdo);
        self::ensureTenantColumns($pdo);
        self::normalizeTenantIndexes($pdo);
        self::seedPermissions($pdo);
        self::bootstrapExistingData($pdo);
    }

    private static function ensurePlatformAdminColumn(PDO $pdo): void
    {
        if(!self::columnExists($pdo,'users','is_platform_admin')){
            $pdo->exec("ALTER TABLE users ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0");
        }

        $ownerId=0;
        try{
            $st=$pdo->prepare("SELECT `value` FROM settings WHERE `key`='platform_owner_user_id' LIMIT 1");
            $st->execute();
            $candidate=(int)$st->fetchColumn();
            if($candidate){
                $chk=$pdo->prepare("SELECT id FROM users WHERE id=? AND status='active' LIMIT 1");
                $chk->execute([$candidate]);
                if($chk->fetchColumn())$ownerId=$candidate;
            }
        }catch(Throwable $e){}

        if(!$ownerId){
            $ownerId=(int)$pdo->query("SELECT id FROM users WHERE is_platform_admin=1 AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
        }
        if(!$ownerId){
            $ownerId=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
        }
        if(!$ownerId){
            $ownerId=(int)$pdo->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
        }

        if($ownerId){
            // Exactly one global Platform Owner. Workspace Owner is NOT a platform-wide role.
            $pdo->prepare("UPDATE users SET is_platform_admin=CASE WHEN id=? THEN 1 ELSE 0 END")->execute([$ownerId]);
            try{
                $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES ('platform_owner_user_id',?,0,NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),updated_at=NOW()")
                    ->execute([(string)$ownerId]);
            }catch(Throwable $e){}
        }
    }

    private static function ensureTenantColumns(PDO $pdo): void
    {
        $tables=['companies','daily_plans','monthly_plans','custom_fields','portal_credentials','user_table_preferences','activity_logs','imports','remote_services'];
        foreach($tables as $t){
            if(!table_exists($pdo,$t)) continue;
            if(!self::columnExists($pdo,$t,'workspace_id')){
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN workspace_id INT NULL");
                try{$pdo->exec("ALTER TABLE `$t` ADD INDEX `idx_{$t}_workspace` (workspace_id)");}catch(Throwable $e){}
            }
        }
    }

    private static function columnExists(PDO $pdo,string $table,string $column): bool
    {
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
        $st->execute([$table,$column]);
        return (bool)$st->fetchColumn();
    }


    private static function normalizeTenantIndexes(PDO $pdo): void
    {
        // companies.name was globally unique in the single-tenant version.
        try {
            $st=$pdo->prepare("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='name' AND NON_UNIQUE=0");
            $st->execute();
            foreach(array_unique(array_column($st->fetchAll(),'INDEX_NAME')) as $idx){
                if($idx && $idx!=='PRIMARY' && $idx!=='uniq_workspace_company_name') $pdo->exec("ALTER TABLE companies DROP INDEX `$idx`");
            }
            $pdo->exec("ALTER TABLE companies ADD UNIQUE KEY uniq_workspace_company_name (workspace_id,name)");
        } catch(Throwable $e) {}

        try {
            $st=$pdo->prepare("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='custom_fields' AND NON_UNIQUE=0");
            $st->execute();
            foreach(array_unique(array_column($st->fetchAll(),'INDEX_NAME')) as $idx){
                if($idx && $idx!=='PRIMARY' && $idx!=='uniq_workspace_entity_field') $pdo->exec("ALTER TABLE custom_fields DROP INDEX `$idx`");
            }
            $pdo->exec("ALTER TABLE custom_fields ADD UNIQUE KEY uniq_workspace_entity_field (workspace_id,entity_key,field_key)");
        } catch(Throwable $e) {}

        try {
            $st=$pdo->prepare("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_table_preferences' AND NON_UNIQUE=0");
            $st->execute();
            foreach(array_unique(array_column($st->fetchAll(),'INDEX_NAME')) as $idx){
                if($idx && $idx!=='PRIMARY' && $idx!=='uniq_workspace_user_table_pref') $pdo->exec("ALTER TABLE user_table_preferences DROP INDEX `$idx`");
            }
            $pdo->exec("ALTER TABLE user_table_preferences ADD UNIQUE KEY uniq_workspace_user_table_pref (workspace_id,user_id,table_key)");
        } catch(Throwable $e) {}
    }

    private static function seedPermissions(PDO $pdo): void
    {
        $defs=[
            ['dashboard.view','مشاهده تقویم','dashboard',10],
            ['companies.view','مشاهده شرکت‌ها','companies',20],['companies.create','افزودن شرکت','companies',21],['companies.update','ویرایش شرکت','companies',22],['companies.delete','حذف شرکت','companies',23],
            ['systems.view','مشاهده سامانه‌ها','systems',30],['systems.update','ویرایش سامانه‌ها','systems',31],['systems.secrets','مشاهده رمز سامانه‌ها','systems',32],
            ['daily.view','مشاهده برنامه روزانه','daily',40],['daily.create','افزودن برنامه روزانه','daily',41],['daily.update','ویرایش برنامه روزانه','daily',42],['daily.delete','حذف برنامه روزانه','daily',43],
            ['monthly.view','مشاهده برنامه ماهانه','monthly',50],['monthly.create','افزودن برنامه ماهانه','monthly',51],['monthly.update','ویرایش برنامه ماهانه','monthly',52],['monthly.delete','حذف برنامه ماهانه','monthly',53],
            ['kanban.view','مشاهده کانبان','kanban',60],['kanban.update','تغییر کانبان','kanban',61],
            ['notes.view','مشاهده نوت‌ها','notes',70],['notes.create','افزودن نوت','notes',71],['notes.update','ویرایش نوت','notes',72],['notes.delete','حذف نوت','notes',73],
            ['files.view','مشاهده لایبرری','files',80],['files.upload','آپلود فایل','files',81],['files.delete','حذف فایل','files',82],['files.attach','اتچ فایل','files',83],
            ['custom_fields.manage','مدیریت فیلدهای اضافه','custom_fields',90],
            ['members.view','مشاهده کاربران','members',100],['members.manage','مدیریت کاربران و نقش‌ها','members',101],
            ['audit.view','مشاهده لاگ‌ها','audit',110],
            ['workspace.manage','مدیریت محیط کاری','workspace',120],['billing.manage','مدیریت اشتراک','billing',130],
            ['settings.manage','مدیریت تنظیمات','settings',140],['api.manage','مدیریت API','api',150],
            ['phonebook.view','مشاهده دفترچه تلفن','phonebook',160],['phonebook.create','ثبت تماس و پیگیری','phonebook',161],['phonebook.update','ویرایش تماس و پیگیری','phonebook',162],['phonebook.delete','حذف تماس و پیگیری','phonebook',163],
            ['shares.view','مشاهده داده‌های اشتراکی','shares',170],['shares.manage','اشتراک‌گذاری داده با محیط دیگر','shares',171],
            ['cache.manage','مدیریت کش و عملکرد','performance',180],
            ['accounting.view','مشاهده ماژول حسابداری صنعتی','accounting',200],
            ['accounting.master.manage','مدیریت اطلاعات پایه حسابداری','accounting',201],
            ['accounting.purchase.view','مشاهده خرید','accounting',202],['accounting.purchase.manage','مدیریت خرید','accounting',203],
            ['accounting.vouchers.view','مشاهده اسناد حسابداری','accounting',204],['accounting.vouchers.manage','مدیریت اسناد حسابداری','accounting',205],
            ['accounting.production.view','مشاهده حسابداری صنعتی','accounting',206],['accounting.production.manage','مدیریت حسابداری صنعتی','accounting',207],
            ['accounting.treasury.view','مشاهده خزانه داری مالی','accounting',208],['accounting.treasury.manage','مدیریت خزانه داری مالی','accounting',209],
            ['accounting.reports.view','مشاهده گزارش های مالی','accounting',210],
            ['accounting.settings.manage','مدیریت تنظیمات ماژول حسابداری','accounting',211],
            ['accounting.taxkeys.manage','مدیریت کلیدهای سامانه مودیان','accounting',212],
            ['production.view','مشاهده برنامه تولید و فرمول ساخت','production',220],
            ['production.manage','مدیریت پیش‌نویس فرمول و برنامه تولید','production',221],
            ['hr.view','مشاهده منابع انسانی','hr',230],['hr.manage','مدیریت کارکنان و کارکرد','hr',231],['hr.approve','تأیید مرخصی','hr',232],
            ['projects.view','مشاهده پروژه‌ها','projects',240],['projects.manage','مدیریت پروژه و ثبت ساعت','projects',241],
            ['pos.view','مشاهده صندوق','pos',250],['pos.manage','مدیریت پیش‌سبد فروش','pos',251],
            ['service.view','مشاهده تیکت خدمات','service',260],['service.manage','مدیریت تیکت خدمات','service',261],
            ['assets.view','مشاهده دارایی‌های ثابت','assets',270],['assets.manage','مدیریت دارایی‌های ثابت','assets',271],
            ['expenses.view','مشاهده درخواست هزینه','expenses',280],['expenses.manage','ایجاد و ویرایش درخواست هزینه','expenses',281],['expenses.approve','تأیید درخواست هزینه','expenses',282],
            ['operations.view','نمای مدیریتی ERP','operations',290],
            ['workflow.view','مشاهده گردش‌کارها و تاریخچه تأیید','workflow',300],
            ['workflow.manage','مدیریت تعریف و پرونده فرآیند','workflow',301],
            ['workflow.approve','تأیید یا رد مرحله فرایند','workflow',302],
            ['enterprise.commerce.view','مشاهده فروش پیشرفته و تجاری','enterprise_commerce',350],
            ['enterprise.commerce.manage','مدیریت فروش پیشرفته و تجاری','enterprise_commerce',351],
            ['enterprise.commerce.approve','بررسی و تأیید فروش پیشرفته و تجاری','enterprise_commerce',352],
            ['enterprise.warehouse.view','مشاهده انبارداری و WMS','enterprise_warehouse',355],
            ['enterprise.warehouse.manage','مدیریت انبارداری و WMS','enterprise_warehouse',356],
            ['enterprise.warehouse.approve','بررسی و تأیید انبارداری و WMS','enterprise_warehouse',357],
            ['enterprise.factory.view','مشاهده تولید، کیفیت، نگهداری','enterprise_factory',360],
            ['enterprise.factory.manage','مدیریت تولید، کیفیت، نگهداری','enterprise_factory',361],
            ['enterprise.factory.approve','بررسی و تأیید تولید، کیفیت، نگهداری','enterprise_factory',362],
            ['enterprise.people.view','مشاهده منابع انسانی پیشرفته','enterprise_people',365],
            ['enterprise.people.manage','مدیریت منابع انسانی پیشرفته','enterprise_people',366],
            ['enterprise.people.approve','بررسی و تأیید منابع انسانی پیشرفته','enterprise_people',367],
            ['enterprise.service.view','مشاهده خدمات در محل و تعمیر','enterprise_service',370],
            ['enterprise.service.manage','مدیریت خدمات در محل و تعمیر','enterprise_service',371],
            ['enterprise.service.approve','بررسی و تأیید خدمات در محل و تعمیر','enterprise_service',372],
            ['enterprise.growth.view','مشاهده بازاریابی، رویداد و نظرسنجی','enterprise_growth',375],
            ['enterprise.growth.manage','مدیریت بازاریابی، رویداد و نظرسنجی','enterprise_growth',376],
            ['enterprise.growth.approve','بررسی و تأیید بازاریابی، رویداد و نظرسنجی','enterprise_growth',377],
            ['enterprise.knowledge.view','مشاهده اسناد، قرارداد و دانش','enterprise_knowledge',380],
            ['enterprise.knowledge.manage','مدیریت اسناد، قرارداد و دانش','enterprise_knowledge',381],
            ['enterprise.knowledge.approve','بررسی و تأیید اسناد، قرارداد و دانش','enterprise_knowledge',382],
            ['enterprise.governance.view','مشاهده ناوگان، پایداری و پذیرش','enterprise_governance',385],
            ['enterprise.governance.manage','مدیریت ناوگان، پایداری و پذیرش','enterprise_governance',386],
            ['enterprise.governance.approve','بررسی و تأیید ناوگان، پایداری و پذیرش','enterprise_governance',387],
        ];
        $st=$pdo->prepare("INSERT INTO workspace_permissions (permission_key,title,group_key,sort_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),group_key=VALUES(group_key),sort_order=VALUES(sort_order)");
        foreach($defs as $d)$st->execute($d);
        // Existing workspaces: grant the NEW, opt-in manufacturing permissions only
        // to their owner role. Other roles require explicit administrator grants.
        $grant=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id)
            SELECT r.id,p.id FROM workspace_roles r JOIN workspace_permissions p
            ON p.permission_key IN ('production.view','production.manage','hr.view','hr.manage','hr.approve','projects.view','projects.manage','pos.view','pos.manage','service.view','service.manage','assets.view','assets.manage','expenses.view','expenses.manage','expenses.approve','operations.view','workflow.view','workflow.manage','workflow.approve','enterprise.commerce.view','enterprise.commerce.manage','enterprise.commerce.approve','enterprise.warehouse.view','enterprise.warehouse.manage','enterprise.warehouse.approve','enterprise.factory.view','enterprise.factory.manage','enterprise.factory.approve','enterprise.people.view','enterprise.people.manage','enterprise.people.approve','enterprise.service.view','enterprise.service.manage','enterprise.service.approve','enterprise.growth.view','enterprise.growth.manage','enterprise.growth.approve','enterprise.knowledge.view','enterprise.knowledge.manage','enterprise.knowledge.approve','enterprise.governance.view','enterprise.governance.manage','enterprise.governance.approve')
            WHERE r.role_key='owner'");
        $grant->execute();
    }

    private static function bootstrapExistingData(PDO $pdo): void
    {
        $count=(int)$pdo->query("SELECT COUNT(*) FROM workspaces")->fetchColumn();

        $platformOwner=(int)$pdo->query("SELECT id FROM users WHERE is_platform_admin=1 AND status='active' ORDER BY id LIMIT 1")->fetchColumn();

        if($count===0){
            $slug='workspace-main';
            $pdo->prepare("INSERT INTO workspaces (name,slug,owner_user_id,status,plan_key,subscription_status,trial_ends_at,created_at,updated_at) VALUES (?,?,?,'active','pro','active',DATE_ADD(NOW(),INTERVAL 3650 DAY),NOW(),NOW())")
                ->execute(['محیط کاری اصلی',$slug,$platformOwner?:null]);
        }

        $st=$pdo->prepare("SELECT id,owner_user_id FROM workspaces WHERE slug='workspace-main' ORDER BY id LIMIT 1");
        $st->execute();
        $main=$st->fetch();
        if(!$main){
            $main=$pdo->query("SELECT id,owner_user_id FROM workspaces ORDER BY id LIMIT 1")->fetch();
        }
        if(!$main)return;

        $wid=(int)$main['id'];
        self::seedDefaultRoles($pdo,$wid);

        if($platformOwner){
            $ownerRole=self::roleId($pdo,$wid,'owner');
            $pdo->prepare("UPDATE workspaces SET owner_user_id=?,updated_at=NOW() WHERE id=?")
                ->execute([$platformOwner,$wid]);

            // The main workspace is private to the Platform Owner.
            $pdo->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at)
                VALUES (?,?,?,'active',NOW(),NOW(),NOW())
                ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),status='active',updated_at=NOW()")
                ->execute([$wid,$platformOwner,$ownerRole]);

            $pdo->prepare("UPDATE workspace_members SET status='removed',updated_at=NOW()
                WHERE workspace_id=? AND user_id<>? AND status='active'")
                ->execute([$wid,$platformOwner]);
        }

        // Legacy single-tenant data backfill must run ONCE only.
        $legacyDone='0';
        try{
            $st=$pdo->prepare("SELECT `value` FROM settings WHERE `key`='saas_legacy_backfill_done' LIMIT 1");
            $st->execute();$legacyDone=(string)($st->fetchColumn()?:'0');
        }catch(Throwable $e){}

        if($legacyDone!=='1'){
            foreach(['companies','daily_plans','monthly_plans','custom_fields','portal_credentials','user_table_preferences','activity_logs','imports','remote_services'] as $t){
                if(table_exists($pdo,$t) && self::columnExists($pdo,$t,'workspace_id')){
                    try{$pdo->prepare("UPDATE `$t` SET workspace_id=? WHERE workspace_id IS NULL")->execute([$wid]);}catch(Throwable $e){}
                }
            }
            try{
                $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES ('saas_legacy_backfill_done','1',0,NOW()) ON DUPLICATE KEY UPDATE `value`='1',updated_at=NOW()")->execute();
            }catch(Throwable $e){}
        }

        $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES ('saas_schema_version','4.2.0',0,NOW()) ON DUPLICATE KEY UPDATE `value`='4.2.0',updated_at=NOW()")->execute();
        $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES ('saas_self_service_workspace','0',0,NOW()) ON DUPLICATE KEY UPDATE `value`=`value`")->execute();

        try {
            $pdo->prepare("UPDATE custom_fields SET active=0,updated_at=NOW() WHERE workspace_id=? AND entity_key='daily_plans' AND field_key='duration'")
                ->execute([$wid]);
        } catch (Throwable $e) {}
    }

    public static function mainWorkspaceId(): int
    {
        $st=pdo()->prepare("SELECT id FROM workspaces WHERE slug='workspace-main' LIMIT 1");
        $st->execute();return (int)$st->fetchColumn();
    }

    public static function isMainWorkspace(?int $workspaceId=null): bool
    {
        $workspaceId=$workspaceId??self::id();
        $main=self::mainWorkspaceId();
        return $main>0 && $workspaceId===$main;
    }

    public static function isWorkspaceOwner(): bool
    {
        if(self::isPlatformAdmin())return true;
        $m=self::membership();
        return ($m['role_key']??'')==='owner';
    }

    public static function seedDefaultRoles(PDO $pdo,int $wid): void
    {
        $roles=[['owner','مالک محیط',1],['workspace_admin','ادمین محیط کاری',1],['manager','مدیر داخلی',1],['accountant','حسابدار',1],['viewer','مشاهده‌گر',1]];
        $st=$pdo->prepare("INSERT INTO workspace_roles (workspace_id,name,role_key,is_system,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name)");
        foreach($roles as $r)$st->execute([$wid,$r[1],$r[0],$r[2]]);

        $all=$pdo->query("SELECT id,permission_key FROM workspace_permissions")->fetchAll();
        $byKey=[];foreach($all as $p)$byKey[$p['permission_key']]=(int)$p['id'];
        $sets=[
            'owner'=>array_keys($byKey),
            'workspace_admin'=>array_values(array_filter(array_keys($byKey),fn($x)=>!in_array($x,['billing.manage'],true))),
            'manager'=>array_values(array_filter(array_keys($byKey),fn($x)=>!in_array($x,['billing.manage','workspace.manage','members.manage','settings.manage','api.manage','shares.manage','cache.manage','accounting.settings.manage','accounting.taxkeys.manage'],true))),
            'accountant'=>array_values(array_filter(array_keys($byKey),fn($x)=>preg_match('/^(dashboard|companies|systems|daily|monthly|kanban|notes|files|phonebook)\./',$x) || $x==='shares.view')),
            'viewer'=>array_values(array_filter(array_keys($byKey),fn($x)=>str_ends_with($x,'.view'))),
        ];
        foreach($sets as $key=>$perms){
            $rid=self::roleId($pdo,$wid,$key);if(!$rid)continue;
            $ins=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) VALUES (?,?)");
            foreach($perms as $pk)if(isset($byKey[$pk]))$ins->execute([$rid,$byKey[$pk]]);
        }
    }

    private static function roleId(PDO $pdo,int $wid,string $key): int
    {
        $st=$pdo->prepare("SELECT id FROM workspace_roles WHERE workspace_id=? AND role_key=? LIMIT 1");$st->execute([$wid,$key]);return (int)$st->fetchColumn();
    }

    public static function boot(): void
    {
        if(!Auth::check())return;
        // V5_IDEMPOTENT_TENANT_BOOT
        if(self::$workspace!==null && self::$membership!==null)return;
        $uid=(int)Auth::user()['id'];

        if(self::isPlatformAdmin()){
            $requested=(int)($_SESSION['workspace_id']??0);
            $row=null;
            if($requested){
                $st=pdo()->prepare("SELECT * FROM workspaces WHERE id=? AND status='active' LIMIT 1");
                $st->execute([$requested]);$row=$st->fetch()?:null;
            }
            if(!$row)$row=pdo()->query("SELECT * FROM workspaces WHERE status='active' ORDER BY id LIMIT 1")->fetch()?:null;
            if($row){
                $_SESSION['workspace_id']=(int)$row['id'];
                self::$workspace=['id'=>(int)$row['id'],'name'=>$row['name'],'slug'=>$row['slug'],'status'=>$row['status'],'plan_key'=>$row['plan_key'],'subscription_status'=>$row['subscription_status']];
                self::$membership=['workspace_id'=>(int)$row['id'],'user_id'=>$uid,'role_id'=>0,'role_name'=>'Platform Super Admin','role_key'=>'platform_super_admin','status'=>'active'];
                self::$permissions=array_column(pdo()->query("SELECT permission_key FROM workspace_permissions")->fetchAll(),'permission_key');
            }
            return;
        }

        $members=self::memberships($uid);
        if(!$members && setting('saas_self_service_workspace','0')==='1'){
            self::provisionWorkspaceForUser($uid, Auth::user()['name'] ?? 'محیط کاری من');
            $members=self::memberships($uid);
        }
        if(!$members)return;

        $requested=(int)($_SESSION['workspace_id']??0);
        $selected=null;
        foreach($members as $m)if((int)$m['workspace_id']===$requested){$selected=$m;break;}
        if(!$selected)$selected=$members[0];
        $_SESSION['workspace_id']=(int)$selected['workspace_id'];
        self::$workspace=[
            'id'=>(int)$selected['workspace_id'],'name'=>$selected['workspace_name'],'slug'=>$selected['workspace_slug'],
            'status'=>$selected['workspace_status'],'plan_key'=>$selected['plan_key'],'subscription_status'=>$selected['subscription_status']
        ];
        self::$membership=$selected;
        self::$permissions=self::loadPermissions((int)$selected['role_id']);
    }

    public static function memberships(?int $uid=null): array
    {
        $uid=$uid??(int)(Auth::user()['id']??0);if(!$uid)return[];
        $st=pdo()->prepare("SELECT wm.*,w.name workspace_name,w.slug workspace_slug,w.status workspace_status,w.plan_key,w.subscription_status,wr.name role_name,wr.role_key
            FROM workspace_members wm
            JOIN workspaces w ON w.id=wm.workspace_id
            LEFT JOIN workspace_roles wr ON wr.id=wm.role_id
            WHERE wm.user_id=? AND wm.status='active' AND w.status='active'
            ORDER BY w.id");
        $st->execute([$uid]);return $st->fetchAll();
    }

    private static function loadPermissions(int $roleId): array
    {
        if(!$roleId)return[];
        $st=pdo()->prepare("SELECT p.permission_key FROM workspace_role_permissions rp JOIN workspace_permissions p ON p.id=rp.permission_id WHERE rp.role_id=?");
        $st->execute([$roleId]);return array_column($st->fetchAll(),'permission_key');
    }

    public static function id(): int
    {
        if(!self::$workspace)self::boot();
        return (int)(self::$workspace['id']??0);
    }

    public static function current(): ?array
    {
        if(!self::$workspace)self::boot();return self::$workspace;
    }

    public static function membership(): ?array
    {
        if(!self::$membership)self::boot();return self::$membership;
    }

    public static function isPlatformAdmin(): bool
    {
        if(!Auth::check())return false;
        return (int)(Auth::user()['is_platform_admin']??0)===1;
    }

    public static function can(string $permission): bool
    {
        if(self::isPlatformAdmin())return true;
        if(!self::$membership)self::boot();
        return in_array($permission,self::$permissions,true);
    }

    public static function requirePermission(string $permission): void
    {
        if(!self::can($permission)){http_response_code(403);throw new RuntimeException('دسترسی لازم برای این عملیات را ندارید.');}
    }

    public static function switch(int $workspaceId): void
    {
        if(self::isPlatformAdmin()){
            $st=pdo()->prepare("SELECT id FROM workspaces WHERE id=? AND status='active' LIMIT 1");$st->execute([$workspaceId]);
            if($st->fetchColumn()){
                $_SESSION['workspace_id']=$workspaceId;self::$workspace=null;self::$membership=null;self::$permissions=[];self::boot();return;
            }
        } else {
            foreach(self::memberships() as $m){
                if((int)$m['workspace_id']===$workspaceId){
                    $_SESSION['workspace_id']=$workspaceId;self::$workspace=null;self::$membership=null;self::$permissions=[];self::boot();return;
                }
            }
        }
        throw new RuntimeException('به این محیط کاری دسترسی ندارید.');
    }

    public static function workspaceOptions(): array
    {
        if(self::isPlatformAdmin()){
            return pdo()->query("SELECT id workspace_id,name workspace_name,slug workspace_slug,status workspace_status,plan_key,subscription_status,0 role_id,'Platform Super Admin' role_name,'platform_super_admin' role_key FROM workspaces WHERE status='active' ORDER BY name")->fetchAll();
        }
        return self::memberships();
    }

    private static function provisionWorkspaceForUser(int $uid,string $userName): int
    {
        $name='محیط کاری '.trim($userName);
        $slug=self::slug($name).'-'.substr(bin2hex(random_bytes(4)),0,8);
        pdo()->prepare("INSERT INTO workspaces (name,slug,owner_user_id,status,plan_key,subscription_status,trial_ends_at,created_at,updated_at) VALUES (?,?,?,'active','starter','trial',DATE_ADD(NOW(),INTERVAL 14 DAY),NOW(),NOW())")
            ->execute([$name,$slug,$uid]);
        $wid=(int)pdo()->lastInsertId();self::seedDefaultRoles(pdo(),$wid);
        $rid=self::roleId(pdo(),$wid,'owner');
        pdo()->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at) VALUES (?,?,?,'active',NOW(),NOW(),NOW())")
            ->execute([$wid,$uid,$rid]);
        return $wid;
    }

    public static function addWorkspace(string $name,int $ownerUserId): int
    {
        if(!self::isPlatformAdmin())throw new RuntimeException('ساخت محیط کاری جدید فقط برای مدیر کل پلتفرم مجاز است.');
        $slug=self::slug($name).'-'.substr(bin2hex(random_bytes(4)),0,8);
        pdo()->prepare("INSERT INTO workspaces (name,slug,owner_user_id,status,plan_key,subscription_status,trial_ends_at,created_at,updated_at) VALUES (?,? ,?,'active','starter','trial',DATE_ADD(NOW(),INTERVAL 14 DAY),NOW(),NOW())")
            ->execute([$name,$slug,$ownerUserId]);
        $wid=(int)pdo()->lastInsertId();self::seedDefaultRoles(pdo(),$wid);
        $rid=self::roleId(pdo(),$wid,'owner');
        pdo()->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at) VALUES (?,?,?,'active',NOW(),NOW(),NOW())")->execute([$wid,$ownerUserId,$rid]);
        return $wid;
    }

    public static function allWorkspaces(): array
    {
        if(!self::isPlatformAdmin())throw new RuntimeException('دسترسی مدیر کل لازم است.');
        return pdo()->query("SELECT w.*,u.name owner_name,u.email owner_email,u.is_platform_admin owner_is_platform_admin,
            (SELECT wr.name
             FROM workspace_members wm
             LEFT JOIN workspace_roles wr ON wr.id=wm.role_id
             WHERE wm.workspace_id=w.id AND wm.user_id=w.owner_user_id AND wm.status='active'
             LIMIT 1) owner_role_name,
            (SELECT wr.role_key
             FROM workspace_members wm
             LEFT JOIN workspace_roles wr ON wr.id=wm.role_id
             WHERE wm.workspace_id=w.id AND wm.user_id=w.owner_user_id AND wm.status='active'
             LIMIT 1) owner_role_key,
            (SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id=w.id AND wm.status='active') member_count
            FROM workspaces w
            LEFT JOIN users u ON u.id=w.owner_user_id
            ORDER BY w.id DESC")->fetchAll();
    }

    public static function workspaceRoleId(int $workspaceId,string $roleKey): int
    {
        return self::roleId(pdo(),$workspaceId,$roleKey);
    }

    private static function slug(string $s): string
    {
        $s=mb_strtolower(trim($s));$s=preg_replace('/[^\pL\pN]+/u','-',$s);return trim($s,'-')?:'workspace';
    }
}

function workspace_id(): int { return Tenant::id(); }
function can(string $permission): bool { return Tenant::can($permission); }
