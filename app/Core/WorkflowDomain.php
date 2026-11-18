<?php
/**
 * ERPSMART 10.14.0 — company-scoped, human-approved process orchestration.
 * This system tracks approval of a case. It NEVER implicitly posts or mutates
 * an attached financial/stock/payroll/production business record.
 */
final class WorkflowDomain
{
    public const VERSION = '10.14.0-pilot';
    public const MAX_STEPS = 12;

    /** Names are original to ERPSMART. Research references inform coverage, not copied interfaces. */
    public static function templates(): array
    {
        return [
            'purchase_approval'=>['خرید و تدارکات: تأیید درخواست', 'procurement', ['کنترل نیاز و بودجه','تأیید مسئول خرید','تأیید مدیریت']],
            'sales_discount'=>['فروش: تأیید تخفیف ویژه', 'sales', ['ارزیابی حاشیه سود','تأیید مدیریت فروش']],
            'stock_adjustment'=>['انبار: بررسی مغایرت انبارگردانی', 'inventory', ['انباردار و مستندات','بازبینی مالی','تصویب مدیر']],
            'production_release'=>['تولید: بررسی آزادسازی دستور', 'production', ['کسری مواد و BOM','تأیید سرپرست تولید','بازبینی مالی']],
            'payment_request'=>['مالی: گردش درخواست پرداخت', 'finance', ['بررسی اسناد و ذی‌نفع','بازبینی خزانه','تأیید نهایی مدیر مالی']],
            'expense_review'=>['هزینه: بررسی درخواست بازپرداخت', 'expenses', ['کنترل رسید هزینه','تأیید مدیر واحد','بازبینی مالی']],
            'leave_approval'=>['منابع انسانی: درخواست مرخصی', 'hr', ['بررسی سرپرست','تأیید منابع انسانی']],
            'project_change'=>['پروژه: تأیید تغییر دامنه', 'projects', ['تحلیل اثر بر زمان و هزینه','تأیید مدیر پروژه']],
            'trade_clearance'=>['بازرگانی: تأیید محموله و ترخیص', 'trade', ['کنترل مدارک','بررسی هزینه ارزی','تأیید ترخیص']],
            'service_escalation'=>['خدمات: ارجاع شکایت مشتری', 'service', ['ارزیابی اولیه','بررسی مدیر خدمات']],
            'asset_disposal'=>['دارایی: بررسی خروج از خدمت', 'assets', ['تأیید امین اموال','بازبینی مالی','تصویب مدیر']],
            'crm_exception'=>['CRM: تأیید استثنا در قرارداد مشتری', 'crm', ['کنترل تعهد قراردادی','تأیید مدیر فروش']],
        ];
    }

    public static function subjectTypes(): array
    {
        return [
            'none'=>['بدون سند متصل', '', ''],
            'purchase'=>['سند خرید', 'acc_purchase_docs', 'accounting.purchase.view'],
            'sales'=>['سند فروش', 'acc_sales_docs', 'accounting.view'],
            'bom'=>['فرمول ساخت', 'erp_production_boms', 'production.view'],
            'production_plan'=>['برنامه تولید', 'erp_production_plans', 'production.view'],
            'project'=>['پروژه', 'acc_projects', 'projects.view'],
            'task'=>['وظیفه پروژه', 'erp_project_tasks', 'projects.view'],
            'leave'=>['درخواست مرخصی', 'erp_leave_requests', 'hr.view'],
            'expense'=>['درخواست هزینه', 'erp_expense_claims', 'expenses.view'],
            'service'=>['تیکت خدمات', 'erp_service_tickets', 'service.view'],
            'asset'=>['دارایی ثابت', 'erp_fixed_assets', 'assets.view'],
            'customer'=>['طرف‌حساب', 'acc_parties', 'accounting.view'],
        ];
    }

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_workflow_definitions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL,
            code VARCHAR(80) NOT NULL, title VARCHAR(190) NOT NULL,
            category VARCHAR(40) NOT NULL, template_key VARCHAR(60) NOT NULL DEFAULT '', effect_type VARCHAR(30) NOT NULL DEFAULT 'none', description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_workflow_code (workspace_id,company_id,code),
            INDEX idx_workflow_defs (workspace_id,company_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_workflow_steps (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL, definition_id BIGINT NOT NULL,
            step_no INT NOT NULL, title VARCHAR(190) NOT NULL,
            assignee_user_id INT NULL, due_hours INT NOT NULL DEFAULT 72,
            requires_note TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_workflow_step (workspace_id,company_id,definition_id,step_no),
            INDEX idx_workflow_steps (workspace_id,company_id,definition_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_workflow_cases (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL, definition_id BIGINT NOT NULL,
            code VARCHAR(80) NOT NULL, title VARCHAR(190) NOT NULL, description TEXT NULL,
            reference_type VARCHAR(40) NOT NULL DEFAULT 'none', reference_id BIGINT NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            current_step INT NOT NULL DEFAULT 1, revision INT NOT NULL DEFAULT 1,
            due_at DATETIME NULL, opened_by INT NOT NULL, closed_at DATETIME NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_workflow_case_code (workspace_id,company_id,code),
            INDEX idx_workflow_case_queue (workspace_id,company_id,status,due_at),
            INDEX idx_workflow_case_process (workspace_id,company_id,definition_id,status),
            INDEX idx_workflow_subject (workspace_id,company_id,reference_type,reference_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_workflow_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL, case_id BIGINT NOT NULL,
            step_no INT NOT NULL, action_key CHAR(64) NOT NULL,
            actor_id INT NOT NULL, action VARCHAR(30) NOT NULL,
            previous_status VARCHAR(20) NOT NULL, next_status VARCHAR(20) NOT NULL,
            note TEXT NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY uq_workflow_action (workspace_id,company_id,case_id,action_key),
            INDEX idx_workflow_history (workspace_id,company_id,case_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function checkScope(int $wid,int $cid): void
    {
        if($wid<1||$cid<1)throw new RuntimeException('شرکت انتخاب نشده است.');
        $q=pdo()->prepare('SELECT 1 FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1');
        $q->execute([$cid,$wid]);
        if(!$q->fetchColumn())throw new RuntimeException('شرکت به محیط کاری تعلق ندارد.');
    }

    private static function requiredText($raw,int $length,string $label): string
    {
        if(is_array($raw)||is_object($raw))throw new RuntimeException('مقدار نامعتبر: '.$label);
        $s=trim((string)($raw??''));
        if($s===''||mb_strlen($s,'UTF-8')>$length)throw new RuntimeException('طول یا مقدار نامعتبر: '.$label);
        return $s;
    }

    private static function optionalText($raw,int $length): string
    {
        if(is_array($raw)||is_object($raw))throw new RuntimeException('متن نامعتبر است.');
        $s=trim((string)($raw??''));
        if(mb_strlen($s,'UTF-8')>$length)throw new RuntimeException('شرح بیش از حد طولانی است.');
        return $s;
    }

    private static function code($raw): string
    {
        $s=self::requiredText($raw,80,'کد');
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{1,79}$/D',$s))throw new RuntimeException('کد باید لاتین، ۲ تا ۸۰ کاراکتر باشد.');
        return $s;
    }

    public static function definitions(int $wid,int $cid): array
    {
        self::checkScope($wid,$cid);
        $q=pdo()->prepare('SELECT d.*, (SELECT COUNT(*) FROM erp_workflow_steps s WHERE s.definition_id=d.id AND s.workspace_id=d.workspace_id AND s.company_id=d.company_id) AS steps_count FROM erp_workflow_definitions d WHERE d.workspace_id=? AND d.company_id=? ORDER BY d.id DESC LIMIT 100');
        $q->execute([$wid,$cid]);return $q->fetchAll();
    }

    public static function getDefinition(int $wid,int $cid,int $id,bool $forUpdate=false): ?array
    {
        $q=pdo()->prepare('SELECT * FROM erp_workflow_definitions WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
        $q->execute([$wid,$cid,$id]);return $q->fetch()?:null;
    }

    public static function steps(int $wid,int $cid,int $defId): array
    {
        $q=pdo()->prepare('SELECT s.*,u.name assignee_name FROM erp_workflow_steps s LEFT JOIN users u ON u.id=s.assignee_user_id WHERE s.workspace_id=? AND s.company_id=? AND s.definition_id=? ORDER BY s.step_no');
        $q->execute([$wid,$cid,$defId]);return $q->fetchAll();
    }

    public static function activeUsers(int $wid): array
    {
        $q=pdo()->prepare("SELECT u.id,u.name FROM users u JOIN workspace_members wm ON wm.user_id=u.id WHERE wm.workspace_id=? AND wm.status='active' AND u.status='active' ORDER BY u.name LIMIT 200");
        $q->execute([$wid]);return $q->fetchAll();
    }

    private static function assertActiveMember(int $wid,int $uid): void
    {
        if($uid<1)throw new RuntimeException('تأییدکننده نامعتبر است.');
        $q=pdo()->prepare("SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id WHERE wm.workspace_id=? AND wm.user_id=? AND wm.status='active' AND u.status='active' LIMIT 1");
        $q->execute([$wid,$uid]);if(!$q->fetchColumn())throw new RuntimeException('کاربر عضو فعال Workspace نیست.');
    }

    /** Explicit template provisioning, never automatic. Atomic definition+steps. */
    public static function createDefinition(int $wid,int $cid,int $uid,array $input): int
    {
        Tenant::requirePermission('workflow.manage');
        if($uid<1)throw new RuntimeException('کاربر نامعتبر است.');
        self::checkScope($wid,$cid);
        $code=self::code($input['code']??'');
        $name=self::requiredText($input['title']??'',190,'عنوان');
        $category=self::requiredText($input['category']??'other',40,'دسته');
        if(!preg_match('/^[a-z][a-z_]{1,39}$/D',$category))throw new RuntimeException('دسته نامعتبر است.');
        $description=self::optionalText($input['description']??'',2000);
        $tmpl=(string)($input['template']??'');
        $steps=[];$effect='none';
        if($tmpl!==''){
            $templates=self::templates();if(!isset($templates[$tmpl]))throw new RuntimeException('الگوی ناشناخته.');
            $category=$templates[$tmpl][1];
            $steps=$templates[$tmpl][2];
            if($tmpl==='leave_approval')$effect='leave_request';
            if($tmpl==='expense_review')$effect='expense_claim';
        }
        $db=pdo();$db->beginTransaction();
        try{
            $q=$db->prepare("INSERT INTO erp_workflow_definitions (workspace_id,company_id,code,title,category,template_key,effect_type,description,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'draft',?,NOW(),NOW())");
            $q->execute([$wid,$cid,$code,$name,$category,$tmpl,$effect,$description,$uid]);$id=(int)$db->lastInsertId();
            $q=$db->prepare('INSERT INTO erp_workflow_steps (workspace_id,company_id,definition_id,step_no,title,due_hours,created_at) VALUES (?,?,?,?,?,72,NOW())');
            foreach($steps as $i=>$step)$q->execute([$wid,$cid,$id,$i+1,$step]);
            $db->commit();
            Audit::log('workflow.definition.create','erp_workflow_definitions',$id,'ساخت گردش‌کار');
            return $id;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function addStep(int $wid,int $cid,int $defId,array $input): int
    {
        Tenant::requirePermission('workflow.manage');
        self::checkScope($wid,$cid);
        $title=self::requiredText($input['title']??'',190,'مرحله');
        $due=self::intRange($input['due_hours']??72,1,720,'SLA');
        $assignee=($input['assignee_user_id']??'')===''?null:self::intRange($input['assignee_user_id'],1,2147483647,'کاربر');
        if($assignee!==null)self::assertActiveMember($wid,$assignee);
        $noteRequired=!empty($input['requires_note'])?1:0;
        $db=pdo();$db->beginTransaction();
        try{
            $d=self::getDefinition($wid,$cid,$defId,true);
            if(!$d || $d['status']!=='draft')throw new RuntimeException('فقط فرآیند پیش‌نویس قابل ویرایش است.');
            $q=$db->prepare('SELECT COALESCE(MAX(step_no),0) FROM erp_workflow_steps WHERE workspace_id=? AND company_id=? AND definition_id=?');
            $q->execute([$wid,$cid,$defId]);$no=1+(int)$q->fetchColumn();
            if($no>self::MAX_STEPS)throw new RuntimeException('حداکثر ۱۲ مرحله مجاز است.');
            $q=$db->prepare('INSERT INTO erp_workflow_steps (workspace_id,company_id,definition_id,step_no,title,assignee_user_id,due_hours,requires_note,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $q->execute([$wid,$cid,$defId,$no,$title,$assignee,$due,$noteRequired]);
            $id=(int)$db->lastInsertId();$db->commit();
            Audit::log('workflow.step.create','erp_workflow_steps',$id,'افزودن مرحله');return $id;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function setDefinitionStatus(int $wid,int $cid,int $defId,string $target): void
    {
        Tenant::requirePermission('workflow.manage');
        if(!in_array($target,['active','retired'],true))throw new RuntimeException('وضعیت تعریف نامعتبر است.');
        $db=pdo();$db->beginTransaction();
        try{
            $d=self::getDefinition($wid,$cid,$defId,true);
            if(!$d||!($target==='active'&&$d['status']==='draft'||$target==='retired'&&$d['status']==='active'))throw new RuntimeException('گذار تعریف مجاز نیست.');
            if($target==='active'){
                $s=self::steps($wid,$cid,$defId);
                if(count($s)<1||count($s)>self::MAX_STEPS)throw new RuntimeException('فرآیند باید ۱ تا ۱۲ مرحله داشته باشد.');
                foreach($s as $step)if($step['assignee_user_id']!==null)self::assertActiveMember($wid,(int)$step['assignee_user_id']);
            }
            $q=$db->prepare('UPDATE erp_workflow_definitions SET status=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status=?');
            $q->execute([$target,$defId,$wid,$cid,$d['status']]);
            if($q->rowCount()!==1)throw new RuntimeException('وضعیت هم‌زمان تغییر کرده است.');
            $db->commit();Audit::log('workflow.definition.status','erp_workflow_definitions',$defId,$target);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    private static function intRange($raw,int $min,int $max,string $label): int
    {
        if(is_array($raw)||is_object($raw)||!preg_match('/^\d+$/D',(string)$raw))throw new RuntimeException('عدد نامعتبر: '.$label);
        $n=(int)$raw;if($n<$min||$n>$max)throw new RuntimeException('محدوده نامعتبر: '.$label);return $n;
    }

    private static function money($raw): string
    {
        $s=trim((string)($raw??'0'));
        if(!preg_match('/^\d{1,15}(?:\.\d{1,2})?$/D',$s))throw new RuntimeException('مبلغ ریالی باید غیرمنفی و حداکثر دو رقم اعشار باشد.');
        return $s;
    }

    public static function validateSubject(string $type,int $wid,int $cid,int $id): void
    {
        $types=self::subjectTypes();
        if(!isset($types[$type]))throw new RuntimeException('نوع مدرک نامعتبر است.');
        if($type==='none'){
            if($id!==0)throw new RuntimeException('برای بدون سند، شناسه باید خالی باشد.');
            return;
        }
        if($id<1)throw new RuntimeException('شناسه سند پیوست لازم است.');
        Tenant::requirePermission($types[$type][2]);
        $table=$types[$type][1]; // allowlisted internal identifier only
        $q=pdo()->prepare("SELECT 1 FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");
        $q->execute([$id,$wid,$cid]);
        if(!$q->fetchColumn())throw new RuntimeException('سند به شرکت جاری تعلق ندارد یا یافت نشد.');
    }

    public static function createCase(int $wid,int $cid,int $uid,array $input): int
    {
        Tenant::requirePermission('workflow.manage');
        if($uid<1)throw new RuntimeException('کاربر نامعتبر است.');
        self::checkScope($wid,$cid);
        $defId=self::intRange($input['definition_id']??0,1,2147483647,'فرآیند');
        $code=self::code($input['code']??'');
        $title=self::requiredText($input['title']??'',190,'عنوان');
        $description=self::optionalText($input['description']??'',2000);
        $type=(string)($input['reference_type']??'none');
        $reference=($input['reference_id']??'')===''?0:self::intRange($input['reference_id'],0,2147483647,'سند');
        $amount=self::money($input['amount']??'0');
        self::validateSubject($type,$wid,$cid,$reference);
        $db=pdo();$db->beginTransaction();
        try{
            $def=self::getDefinition($wid,$cid,$defId,true);
            if(!$def||$def['status']!=='active')throw new RuntimeException('تعریف گردش‌کار فعال نیست.');
            $steps=self::steps($wid,$cid,$defId);
            if(!$steps)throw new RuntimeException('مراحل گردش‌کار تعریف نشده است.');
            if($def['effect_type']!=='none'){
                $effectType=$def['effect_type']==='leave_request'?'leave':'expense';
                if($type!==$effectType||$reference<1)throw new RuntimeException('فرآیند تأیید اختصاصی باید به سند سازگار متصل شود.');
                Tenant::requirePermission($effectType==='leave'?'hr.manage':'expenses.manage');
                $table=$effectType==='leave'?'erp_leave_requests':'erp_expense_claims';
                $q=$db->prepare("SELECT status,is_archived FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");
                $q->execute([$reference,$wid,$cid]);$source=$q->fetch();
                if(!$source||(int)$source['is_archived']!==0||$source['status']!=='pending')throw new RuntimeException('درخواست متصل در وضعیت منتظر بررسی نیست.');
                $q=$db->prepare("SELECT 1 FROM erp_workflow_cases WHERE workspace_id=? AND company_id=? AND reference_type=? AND reference_id=? AND status='open' LIMIT 1");
                $q->execute([$wid,$cid,$type,$reference]);
                if($q->fetchColumn())throw new RuntimeException('برای این درخواست یک فرآیند باز وجود دارد.');
            }
            $due=max(1,(int)$steps[0]['due_hours']);
            $q=$db->prepare("INSERT INTO erp_workflow_cases (workspace_id,company_id,definition_id,code,title,description,reference_type,reference_id,amount,status,current_step,revision,due_at,opened_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'open',1,1,DATE_ADD(NOW(),INTERVAL $due HOUR),?,NOW(),NOW())");
            $q->execute([$wid,$cid,$defId,$code,$title,$description,$type,$reference?:null,$amount,$uid]);
            $id=(int)$db->lastInsertId();
            $q=$db->prepare("INSERT INTO erp_workflow_events (workspace_id,company_id,case_id,step_no,action_key,actor_id,action,previous_status,next_status,note,created_at) VALUES (?,?,?,1,?,?,'open','none','open',?,NOW())");
            $q->execute([$wid,$cid,$id,hash('sha256','open:'.$id),$uid,'پرونده ایجاد شد']);
            $db->commit();Audit::log('workflow.case.create','erp_workflow_cases',$id,'ایجاد پرونده گردش‌کار');return $id;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function cases(int $wid,int $cid,string $filter='open'): array
    {
        self::checkScope($wid,$cid);
        $allowed=['open','approved','rejected','cancelled','all'];
        if(!in_array($filter,$allowed,true))$filter='open';
        $sql="SELECT c.*,d.title process_title,s.title step_title,s.assignee_user_id, (c.status='open' AND c.due_at<NOW()) AS overdue FROM erp_workflow_cases c JOIN erp_workflow_definitions d ON d.id=c.definition_id AND d.workspace_id=c.workspace_id AND d.company_id=c.company_id LEFT JOIN erp_workflow_steps s ON s.definition_id=c.definition_id AND s.workspace_id=c.workspace_id AND s.company_id=c.company_id AND s.step_no=c.current_step WHERE c.workspace_id=? AND c.company_id=?";
        $params=[$wid,$cid];if($filter!=='all'){$sql.=' AND c.status=?';$params[]=$filter;}
        $sql.=' ORDER BY c.id DESC LIMIT 150';
        $q=pdo()->prepare($sql);$q->execute($params);return $q->fetchAll();
    }

    public static function getCase(int $wid,int $cid,int $id): ?array
    {
        $q=pdo()->prepare('SELECT * FROM erp_workflow_cases WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1');
        $q->execute([$wid,$cid,$id]);return $q->fetch()?:null;
    }

    public static function history(int $wid,int $cid,int $caseId): array
    {
        $q=pdo()->prepare('SELECT e.*,u.name actor_name FROM erp_workflow_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.workspace_id=? AND e.company_id=? AND e.case_id=? ORDER BY e.id DESC LIMIT 100');
        $q->execute([$wid,$cid,$caseId]);return $q->fetchAll();
    }

    /** Pure and testable transition policy. Persistence and authorization are enforced by decide(). */
    public static function planDecision(array $case, ?array $step, ?array $following,int $uid,string $action,string $note): array
    {
        if(($case['status']??'')!=='open')throw new RuntimeException('پرونده بسته شده است.');
        $no=(int)($case['current_step']??0);
        if($no<1)throw new RuntimeException('شماره مرحله نامعتبر است.');
        if($action==='cancel')return ['status'=>'cancelled','step'=>$no,'due_hours'=>null];
        if(!in_array($action,['approve','reject'],true))throw new RuntimeException('اقدام نامعتبر است.');
        if($uid===(int)($case['opened_by']??0))throw new RuntimeException('ثبت‌کننده نمی‌تواند پرونده خود را تأیید کند.');
        if(!$step || (int)$step['step_no']!==$no)throw new RuntimeException('مرحله جاری موجود نیست.');
        if($step['assignee_user_id']!==null && (int)$step['assignee_user_id']!==$uid)throw new RuntimeException('مرحله به کاربر دیگری تخصیص یافته است.');
        if(($action==='reject'||(int)$step['requires_note']===1)&&trim($note)==='')throw new RuntimeException('توضیح این تصمیم الزامی است.');
        if($action==='reject')return ['status'=>'rejected','step'=>$no,'due_hours'=>null];
        if(!$following)return ['status'=>'approved','step'=>$no,'due_hours'=>null];
        if((int)$following['step_no']!==$no+1)throw new RuntimeException('ترتیب مراحل نامعتبر است.');
        return ['status'=>'open','step'=>$no+1,'due_hours'=>max(1,(int)$following['due_hours'])];
    }

    /** Step transition is transactional, optimistic and idempotent. No business-posting side effects. */
    public static function decide(int $wid,int $cid,int $uid,int $caseId,int $revision,string $action,string $note,string $nonce): array
    {
        self::checkScope($wid,$cid);
        if(!in_array($action,['approve','reject','cancel'],true))throw new RuntimeException('عملیات نامعتبر است.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new RuntimeException('شناسه درخواست معتبر نیست.');
        $note=self::optionalText($note,2000);
        if($action==='reject'&&$note==='')throw new RuntimeException('دلیل رد الزامی است.');
        $key=hash('sha256',$nonce);
        $db=pdo();$db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM erp_workflow_cases WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE');
            $s->execute([$caseId,$wid,$cid]);$case=$s->fetch();
            if(!$case)throw new RuntimeException('پرونده یافت نشد.');
            $q=$db->prepare('SELECT * FROM erp_workflow_events WHERE workspace_id=? AND company_id=? AND case_id=? AND action_key=? LIMIT 1');
            $q->execute([$wid,$cid,$caseId,$key]);$prior=$q->fetch();
            if($prior){
                if($prior['actor_id']!=$uid||$prior['action']!==$action)throw new RuntimeException('شناسه درخواست قبلاً برای اقدام دیگری استفاده شده است.');
                $db->commit();return ['status'=>$prior['next_status'],'repeat'=>true,'case_id'=>$caseId];
            }
            if($case['status']!=='open')throw new RuntimeException('این پرونده پیش‌تر بسته شده است.');
            if((int)$case['revision']!==$revision)throw new RuntimeException('پرونده تغییر کرده است؛ صفحه را بازخوانی کنید.');
            $step=null;$following=null;
            if($action==='cancel'){
                Tenant::requirePermission('workflow.manage');
                if($uid!==(int)$case['opened_by']&&!Tenant::isPlatformAdmin())throw new RuntimeException('فقط ثبت‌کننده یا مدیر کل می‌تواند لغو کند.');
            }else{
                Tenant::requirePermission('workflow.approve');
                $s=$db->prepare('SELECT * FROM erp_workflow_steps WHERE workspace_id=? AND company_id=? AND definition_id=? AND step_no=? LIMIT 1');
                $s->execute([$wid,$cid,$case['definition_id'],$case['current_step']]);$step=$s->fetch()?:null;
                if($action==='approve'){
                    $s=$db->prepare('SELECT * FROM erp_workflow_steps WHERE workspace_id=? AND company_id=? AND definition_id=? AND step_no>? ORDER BY step_no ASC LIMIT 1');
                    $s->execute([$wid,$cid,$case['definition_id'],$case['current_step']]);$following=$s->fetch()?:null;
                }
            }
            $plan=self::planDecision($case,$step,$following,$uid,$action,$note);
            $nextStatus=$plan['status'];$nextStep=$plan['step'];$due=$plan['due_hours'];
            if(in_array($nextStatus,['approved','rejected'],true)){
                $def=self::getDefinition($wid,$cid,(int)$case['definition_id'],false);
                if(!$def)throw new RuntimeException('تعریف فرآیند حذف شده است.');
                $effect=(string)($def['effect_type']??'none');
                if($effect!=='none'){
                    $type=$effect==='leave_request'?'leave':($effect==='expense_claim'?'expense':'');
                    if($type===''||$case['reference_type']!==$type||(int)$case['reference_id']<1)throw new RuntimeException('ارجاع پرونده ناسازگار است.');
                    Tenant::requirePermission($type==='leave'?'hr.approve':'expenses.approve');
                    $table=$type==='leave'?'erp_leave_requests':'erp_expense_claims';
                    $q=$db->prepare("UPDATE `$table` SET status=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND is_archived=0 AND status='pending'");
                    $q->execute([$nextStatus,(int)$case['reference_id'],$wid,$cid]);
                    if($q->rowCount()!==1)throw new RuntimeException('درخواست اصلی قبلاً تصمیم‌گیری شده؛ فرآیند بدون تغییر ماند.');
                }
            }
            $sql='UPDATE erp_workflow_cases SET status=?,current_step=?,revision=revision+1,due_at='.($due===null?'NULL':'DATE_ADD(NOW(),INTERVAL '.(int)$due.' HOUR)').',closed_at='.($nextStatus==='open'?'NULL':'NOW()').',updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status=\'open\' AND revision=?';
            $s=$db->prepare($sql);$s->execute([$nextStatus,$nextStep,$caseId,$wid,$cid,$revision]);
            if($s->rowCount()!==1)throw new RuntimeException('تغییر هم‌زمان پرونده.');
            $q=$db->prepare('INSERT INTO erp_workflow_events (workspace_id,company_id,case_id,step_no,action_key,actor_id,action,previous_status,next_status,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
            $q->execute([$wid,$cid,$caseId,(int)$case['current_step'],$key,$uid,$action,$case['status'],$nextStatus,$note]);
            $db->commit();
            Audit::log('workflow.case.'.$action,'erp_workflow_cases',$caseId,'تصمیم فرآیند؛ فقط وضعیت درخواست‌های مرخصی/هزینه تخصصی همگام می‌شود، بدون پرداخت/سند مالی');
            return ['status'=>$nextStatus,'repeat'=>false,'case_id'=>$caseId,'current_step'=>$nextStep];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function stats(int $wid,int $cid,int $uid): array
    {
        self::checkScope($wid,$cid);
        $q=pdo()->prepare("SELECT COUNT(*) open_count, SUM(due_at<NOW()) overdue_count, SUM(opened_by=?) opened_by_me FROM erp_workflow_cases WHERE workspace_id=? AND company_id=? AND status='open'");
        $q->execute([$uid,$wid,$cid]);$counts=$q->fetch()?:[];
        $q=pdo()->prepare("SELECT COUNT(*) FROM erp_workflow_cases c JOIN erp_workflow_steps s ON s.definition_id=c.definition_id AND s.workspace_id=c.workspace_id AND s.company_id=c.company_id AND s.step_no=c.current_step WHERE c.workspace_id=? AND c.company_id=? AND c.status='open' AND (s.assignee_user_id=? OR s.assignee_user_id IS NULL) AND c.opened_by<>?");
        $q->execute([$wid,$cid,$uid,$uid]);
        return ['open'=>(int)($counts['open_count']??0),'overdue'=>(int)($counts['overdue_count']??0),'mine'=>(int)($counts['opened_by_me']??0),'reviewable'=>(int)$q->fetchColumn()];
    }
}
