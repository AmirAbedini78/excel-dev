<?php
/**
 * ERPSMART 10.13.0 — typed operational records sharing canonical ERP parties,
 * items, warehouses and projects. No duplicate ledgers, payroll or POS posting.
 * The allowlisted schema drives inputs, validation, writes and UI, not client SQL.
 */
final class ErpSuiteDomain
{
    public const VERSION = '10.13.0-mvp';

    private static function f(string $label, string $type='text', bool $required=false, string $ref='', array $choices=[]): array
    { return compact('label','type','required','ref','choices'); }

    public static function entities(): array
    {
        return [
            'employees'=>['module'=>'hr','title'=>'کارکنان','table'=>'erp_employees','fields'=>[
                'code'=>self::f('کد پرسنلی','code',true),'full_name'=>self::f('نام و نام خانوادگی','text',true),
                'department'=>self::f('واحد سازمانی'),'job_title'=>self::f('عنوان شغلی'),
                'hire_date'=>self::f('تاریخ استخدام','date'),
                'status'=>self::f('وضعیت','choice',true,'',['active'=>'فعال','on_leave'=>'مرخصی','inactive'=>'غیرفعال']),
            ]],
            'leave'=>['module'=>'hr','title'=>'درخواست‌های مرخصی','table'=>'erp_leave_requests','approval'=>'hr.approve','fields'=>[
                'employee_id'=>self::f('کارمند','ref',true,'employees'),
                'leave_type'=>self::f('نوع مرخصی','choice',true,'',['annual'=>'استحقاقی','sick'=>'استعلاجی','unpaid'=>'بدون حقوق']),
                'starts_on'=>self::f('از تاریخ','date',true),'ends_on'=>self::f('تا تاریخ','date',true),
                'notes'=>self::f('توضیحات','memo'),
            ]],
            'attendance'=>['module'=>'hr','title'=>'کارکرد و حضور','table'=>'erp_attendance','fields'=>[
                'employee_id'=>self::f('کارمند','ref',true,'employees'),
                'work_date'=>self::f('تاریخ کارکرد','date',true),'hours'=>self::f('ساعت کارکرد','hours',true),
                'notes'=>self::f('شرح کارکرد','memo'),
            ]],
            'projects'=>['module'=>'projects','title'=>'پروژه‌ها (مرجع مشترک حسابداری)','table'=>'acc_projects','canonical'=>true,'fields'=>[
                'code'=>self::f('کد پروژه','code',true),'name'=>self::f('عنوان پروژه','text',true),
                'start_date'=>self::f('تاریخ شروع','date'),'end_date'=>self::f('تاریخ پایان','date'),
                'status'=>self::f('وضعیت','choice',true,'',['active'=>'فعال','paused'=>'متوقف','completed'=>'تکمیل‌شده']),
            ]],
            'tasks'=>['module'=>'projects','title'=>'وظایف پروژه','table'=>'erp_project_tasks','fields'=>[
                'project_id'=>self::f('پروژه','ref',true,'projects'),
                'title'=>self::f('عنوان وظیفه','text',true),'assignee_employee_id'=>self::f('مسئول','ref',false,'employees'),
                'due_date'=>self::f('سررسید','date'), 'estimated_hours'=>self::f('ساعت برآوردی','estimate'),
                'priority'=>self::f('اولویت','choice',true,'',['normal'=>'عادی','high'=>'بالا','urgent'=>'فوری']),
                'status'=>self::f('وضعیت','choice',true,'',['open'=>'باز','in_progress'=>'در جریان','done'=>'انجام‌شده','cancelled'=>'لغوشده']),
            ]],
            'timesheets'=>['module'=>'projects','title'=>'ثبت زمان پروژه','table'=>'erp_timesheets','fields'=>[
                'task_id'=>self::f('وظیفه','ref',true,'tasks'),'employee_id'=>self::f('کارمند','ref',true,'employees'),
                'work_date'=>self::f('تاریخ','date',true),'hours'=>self::f('ساعت واقعی','hours',true),
                'notes'=>self::f('شرح فعالیت','memo'),
            ]],
            'registers'=>['module'=>'pos','title'=>'صندوق‌ها','table'=>'erp_pos_registers','fields'=>[
                'code'=>self::f('کد صندوق','code',true),'name'=>self::f('نام صندوق','text',true),
                'warehouse_id'=>self::f('انبار مرجع','ref',true,'warehouses'),
                'status'=>self::f('وضعیت','choice',true,'',['active'=>'فعال','inactive'=>'غیرفعال']),
            ]],
            'carts'=>['module'=>'pos','title'=>'سبد فروش صندوق (بدون ثبت مالی)','table'=>'erp_pos_carts','fields'=>[
                'register_id'=>self::f('صندوق','ref',true,'registers'),
                'customer_id'=>self::f('مشتری','ref',false,'parties'),
                'notes'=>self::f('یادداشت','memo'),
            ]],
            'cart_lines'=>['module'=>'pos','title'=>'اقلام سبد فروش','table'=>'erp_pos_cart_lines','fields'=>[
                'cart_id'=>self::f('سبد فروش','ref',true,'carts'),'item_id'=>self::f('کالا','ref',true,'items'),
                'quantity'=>self::f('تعداد','quantity',true),'unit_price'=>self::f('قیمت پیشنهادی واحد (ریال)','money',true),
                'discount_percent'=>self::f('تخفیف درصد','percent'),'tax_percent'=>self::f('مالیات درصد','percent'),
            ]],
            'tickets'=>['module'=>'service','title'=>'تیکت خدمات و پشتیبانی','table'=>'erp_service_tickets','fields'=>[
                'customer_id'=>self::f('مشتری','ref',true,'parties'),
                'subject'=>self::f('موضوع','text',true),
                'assignee_employee_id'=>self::f('کارشناس مسئول','ref',false,'employees'),
                'priority'=>self::f('اولویت','choice',true,'',['normal'=>'عادی','high'=>'بالا','urgent'=>'فوری']),
                'due_date'=>self::f('مهلت پاسخ','date'),
                'status'=>self::f('وضعیت','choice',true,'',['open'=>'باز','working'=>'در حال رسیدگی','resolved'=>'حل‌شده','closed'=>'بسته']),
                'notes'=>self::f('شرح درخواست','memo'),
            ]],
            'assets'=>['module'=>'assets','title'=>'دارایی‌های ثابت','table'=>'erp_fixed_assets','fields'=>[
                'code'=>self::f('کد دارایی','code',true),'name'=>self::f('شرح دارایی','text',true),
                'purchase_date'=>self::f('تاریخ تحصیل','date',true),'purchase_cost'=>self::f('بهای خرید (ریال)','money',true),
                'life_months'=>self::f('عمر مفید بر حسب ماه','months',true),
                'status'=>self::f('وضعیت','choice',true,'',['in_use'=>'در بهره‌برداری','retired'=>'خارج از بهره‌برداری']),
            ]],
            'expenses'=>['module'=>'expenses','title'=>'درخواست هزینه','table'=>'erp_expense_claims','approval'=>'expenses.approve','fields'=>[
                'employee_id'=>self::f('کارمند','ref',true,'employees'),
                'project_id'=>self::f('پروژه','ref',false,'projects'),
                'category'=>self::f('نوع هزینه','text',true),'claim_date'=>self::f('تاریخ هزینه','date',true),
                'amount'=>self::f('مبلغ (ریال)','money',true),
                'notes'=>self::f('شرح / شماره رسید (بدون فایل)','memo'),
            ]],
        ];
    }

    private static function sqlType(string $kind): string
    {
        return match ($kind) {
            'ref' => 'BIGINT NULL',
            'date' => 'DATE NULL',
            'hours','quantity','estimate' => 'DECIMAL(18,4) NOT NULL DEFAULT 0',
            'money' => 'DECIMAL(20,2) NOT NULL DEFAULT 0',
            'percent' => 'DECIMAL(7,3) NOT NULL DEFAULT 0',
            'months' => 'INT NOT NULL DEFAULT 1',
            'memo' => 'TEXT NULL',
            'code' => 'VARCHAR(80) NOT NULL',
            'choice' => 'VARCHAR(30) NOT NULL',
            default => 'VARCHAR(190) NOT NULL DEFAULT \'\'',
        };
    }

    public static function migrate(PDO $db): void
    {
        foreach(self::entities() as $key=>$def) {
            if(!empty($def['canonical']))continue; // Never create a second projects ledger.
            $cols=[];
            foreach($def['fields'] as $field=>$meta)$cols[]="`$field` ".self::sqlType($meta['type']);
            if(!empty($def['approval']))$cols[]="status VARCHAR(20) NOT NULL DEFAULT 'pending'";
            if($key==='carts'){
                $cols[]="status VARCHAR(20) NOT NULL DEFAULT 'draft'";
                $cols[]="linked_sales_doc_id BIGINT NULL";
            }
            if(in_array($key,['employees','registers','assets'],true))$cols[]="UNIQUE KEY uq_code (workspace_id,company_id,code)";
            if($key==='attendance')$cols[]="UNIQUE KEY uq_employee_day (workspace_id,company_id,employee_id,work_date)";
            $db->exec('CREATE TABLE IF NOT EXISTS `'.$def['table'].'` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                '.implode(",\n",$cols).',
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_scope (workspace_id,company_id,is_archived,id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        // Existing acc_projects is canonical for project-level financial linkage.
    }

    public static function spec(string $entity): array
    {
        $defs=self::entities();
        if(!isset($defs[$entity]))throw new RuntimeException('بخش نامعتبر است.');
        return $defs[$entity];
    }

    public static function assertScope(int $wid,int $cid): void
    {
        if($wid<=0||$cid<=0)throw new RuntimeException('ابتدا شرکت را انتخاب کنید.');
        $s=pdo()->prepare('SELECT 1 FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1');
        $s->execute([$cid,$wid]);
        if(!$s->fetchColumn())throw new RuntimeException('شرکت فعال یافت نشد.');
    }

    public static function listRecords(string $entity,int $wid,int $cid,int $limit=60): array
    {
        $spec=self::spec($entity);self::assertScope($wid,$cid);
        $where=!empty($spec['canonical'])?'active=1':'is_archived=0';
        $limit=max(1,min(200,$limit));
        $s=pdo()->prepare('SELECT * FROM `'.$spec['table'].'` WHERE workspace_id=? AND company_id=? AND '.$where.' ORDER BY id DESC LIMIT '.$limit);
        $s->execute([$wid,$cid]);return $s->fetchAll();
    }

    public static function find(string $entity,int $wid,int $cid,int $id): ?array
    {
        if($id<1)return null;
        $spec=self::spec($entity);self::assertScope($wid,$cid);
        $s=pdo()->prepare('SELECT * FROM `'.$spec['table'].'` WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
        $s->execute([$id,$wid,$cid]);return $s->fetch()?:null;
    }

    private static function resolveReference(string $ref,int $wid,int $cid,int $id): bool
    {
        if($id<1)return false;
        $map=[
            'employees'=>['erp_employees',"is_archived=0 AND status='active'"],
            'projects'=>['acc_projects','active=1'],
            'tasks'=>['erp_project_tasks','is_archived=0'],
            'registers'=>['erp_pos_registers',"is_archived=0 AND status='active'"],
            'carts'=>['erp_pos_carts',"is_archived=0 AND status='draft'"],
            'warehouses'=>['acc_warehouses','active=1'],
            'parties'=>['acc_parties','active=1'],
            'items'=>['acc_items','active=1'],
        ];
        if(!isset($map[$ref]))throw new RuntimeException('مرجع تعریف نشده است.');
        [$table,$active]=$map[$ref];
        $s=pdo()->prepare("SELECT 1 FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? AND $active LIMIT 1");
        $s->execute([$id,$wid,$cid]);return(bool)$s->fetchColumn();
    }

    private static function validateValue(string $name,array $meta,$raw,int $wid,int $cid)
    {
        $type=$meta['type'];$required=(bool)$meta['required'];
        if(is_array($raw))throw new RuntimeException('مقدار فیلد معتبر نیست: '.$name);
        $v=trim((string)($raw??''));
        if($required && $v==='')throw new RuntimeException('فیلد ضروری خالی است: '.$meta['label']);
        if($v===''){
            if($type==='ref'||$type==='date')return null;
            if(in_array($type,['hours','estimate','quantity','money','percent'],true))return 0;
            return '';
        }
        if($type==='ref'){
            if(!ctype_digit($v)||(int)$v<=0||!self::resolveReference($meta['ref'],$wid,$cid,(int)$v))throw new RuntimeException('مرجع به شرکت تعلق ندارد: '.$meta['label']);
            return (int)$v;
        }
        if($type==='choice'){
            if(!array_key_exists($v,$meta['choices']))throw new RuntimeException('وضعیت نامعتبر: '.$meta['label']);
            return $v;
        }
        if($type==='date'){
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v) || !checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))throw new RuntimeException('تاریخ را میلادی YYYY-MM-DD وارد کنید: '.$meta['label']);
            return $v;
        }
        if(in_array($type,['hours','quantity','money','percent','months'],true)){
            if(!is_numeric($v)||!is_finite((float)$v))throw new RuntimeException('عدد معتبر نیست: '.$meta['label']);
            $n=(float)$v;
            $max=match($type){'hours'=>24,'estimate'=>100000,'quantity'=>1000000,'percent'=>100,'months'=>1200,default=>100000000000000};
            if($n<0||$n>$max || ($required&&$n<=0) || ($type==='months' && floor($n)!==$n))throw new RuntimeException('عدد خارج از محدوده: '.$meta['label']);
            return $type==='months'?(int)$n:$n;
        }
        $max=$type==='memo'?2000:($type==='code'?80:190);
        if(strlen($v)>$max*4)throw new RuntimeException('متن طولانی است: '.$meta['label']);
        if($type==='code'&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D',$v))throw new RuntimeException('کد باید لاتین و بدون فاصله باشد.');
        if(mb_strlen($v,'UTF-8')>$max)throw new RuntimeException('متن طولانی است: '.$meta['label']);
        return $v;
    }

    public static function save(string $entity,int $wid,int $cid,int $uid,array $input,int $id=0): int
    {
        $spec=self::spec($entity);self::assertScope($wid,$cid);
        if($uid<1)throw new RuntimeException('کاربر نامعتبر است.');
        if($id<0)throw new RuntimeException('شناسه نامعتبر است.');
        $existing=$id?self::find($entity,$wid,$cid,$id):null;
        if($id && (!$existing || (!empty($spec['canonical'])?(int)$existing['active']!==1:(int)$existing['is_archived']!==0)))throw new RuntimeException('رکورد برای ویرایش موجود نیست.');
        if(!empty($spec['approval'])&&$existing&&$existing['status']!=='pending')throw new RuntimeException('درخواست بررسی‌شده قابل ویرایش نیست.');
        $values=[];
        foreach($spec['fields'] as $field=>$meta){
            if($id && !array_key_exists($field,$input))throw new RuntimeException('فرم ناقص است.');
            $values[$field]=self::validateValue($field,$meta,$input[$field]??null,$wid,$cid);
        }
        if(isset($values['starts_on'],$values['ends_on'])&&$values['ends_on']<$values['starts_on'])throw new RuntimeException('پایان پیش از شروع است.');
        if(isset($values['start_date'],$values['end_date'])&&$values['end_date']<$values['start_date'])throw new RuntimeException('پایان پروژه پیش از شروع است.');
        if($entity==='timesheets' && $values['task_id'] && $values['employee_id']){
            $task=self::find('tasks',$wid,$cid,$values['task_id']);
            if(!$task || $task['status']==='cancelled')throw new RuntimeException('وظیفه بسته/نامعتبر است.');
        }
        if($entity==='timesheets' && $values['employee_id'] && $values['work_date']){
            $s=pdo()->prepare('SELECT COALESCE(SUM(hours),0) FROM erp_timesheets WHERE workspace_id=? AND company_id=? AND employee_id=? AND work_date=? AND is_archived=0 AND id<>?');
            $s->execute([$wid,$cid,$values['employee_id'],$values['work_date'],$id]);
            if((float)$s->fetchColumn()+(float)$values['hours']>24.00001)throw new RuntimeException('ساعات ثبت‌شده کارمند در یک روز نمی‌تواند از ۲۴ ساعت بیشتر شود.');
        }
        if($entity==='cart_lines'&&$existing){
            $original=self::find('carts',$wid,$cid,(int)$existing['cart_id']);
            if(!$original || $original['status']!=='draft' || (int)$existing['cart_id']!==(int)$values['cart_id'])
                throw new RuntimeException('تغییر سبد مبنای یک ردیف یا ویرایش سبد تبدیل‌شده مجاز نیست.');
        }
        if($entity==='cart_lines'){
            $cart=self::find('carts',$wid,$cid,$values['cart_id']);
            if(!$cart || $cart['status']!=='draft')throw new RuntimeException('فقط سبد پیش‌نویس قابل تغییر است.');
        }
        if($entity==='carts' && $existing && $existing['status']!=='draft')throw new RuntimeException('سبد بسته قابل ویرایش نیست.');
        $db=pdo();$table=$spec['table'];$fields=array_keys($values);
        if($id){
            $set=implode(',',array_map(static fn($x)=>"`$x`=?",$fields));
            $where=!empty($spec['canonical'])?'active=1':'is_archived=0';
            $s=$db->prepare("UPDATE `$table` SET $set,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND $where");
            $s->execute([...array_values($values),$id,$wid,$cid]);
        }else{
            $columns=array_merge(['workspace_id','company_id'],$fields);
            $valuesToInsert=array_merge([$wid,$cid],array_values($values));
            if(!empty($spec['approval'])){$columns[]='status';$valuesToInsert[]='pending';}
            if(empty($spec['canonical'])){$columns[]='created_by';$valuesToInsert[]=$uid;}
            $cols=implode(',',array_map(static fn($x)=>"`$x`",$columns));
            $marks=implode(',',array_fill(0,count($columns),'?'));
            $s=$db->prepare("INSERT INTO `$table` ($cols,created_at,updated_at) VALUES ($marks,NOW(),NOW())");
            $s->execute($valuesToInsert);$id=(int)$db->lastInsertId();
        }
        Audit::log($existing?'erp.record.update':'erp.record.create',$table,$id,$spec['title']);
        return $id;
    }

    public static function archive(string $entity,int $wid,int $cid,int $id): void
    {
        $spec=self::spec($entity);$existing=self::find($entity,$wid,$cid,$id);
        if(!$existing)throw new RuntimeException('رکورد پیدا نشد.');
        if(!empty($spec['approval'])&&$existing['status']!=='pending')throw new RuntimeException('درخواست تأییدشده را نمی‌توان حذف کرد.');
        if($entity==='projects')throw new RuntimeException('برای حفظ ارجاعات مالی و کارکرد، بایگانی پروژه از این پنل غیرفعال است.');
        if($entity==='carts'&&$existing['status']!=='draft')throw new RuntimeException('سبد غیرپیش‌نویس قابل حذف نیست.');
        if($entity==='cart_lines'){
            $parent=self::find('carts',$wid,$cid,(int)$existing['cart_id']);
            if(!$parent || $parent['status']!=='draft')throw new RuntimeException('ردیف سبد تبدیل‌شده قابل تغییر نیست.');
        }
        $table=$spec['table'];$field=!empty($spec['canonical'])?'active':'is_archived';$val=!empty($spec['canonical'])?0:1;
        $s=pdo()->prepare("UPDATE `$table` SET `$field`=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?");
        $s->execute([$val,$id,$wid,$cid]);
        Audit::log('erp.record.archive',$table,$id,$spec['title']);
    }

    public static function review(string $entity,int $wid,int $cid,int $id,string $decision): void
    {
        $spec=self::spec($entity);
        if(empty($spec['approval'])||!in_array($decision,['approved','rejected'],true))throw new RuntimeException('تصمیم نامعتبر است.');
        $s=pdo()->prepare('UPDATE `'.$spec['table'].'` SET status=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND is_archived=0 AND status=\'pending\'');
        $s->execute([$decision,$id,$wid,$cid]);
        if($s->rowCount()!==1)throw new RuntimeException('درخواست قبلاً بررسی شده یا خارج از محدوده است.');
        Audit::log('erp.record.review',$spec['table'],$id,$decision);
    }

    public static function referenceOptions(string $ref,int $wid,int $cid): array
    {
        $map=[
            'employees'=>['erp_employees','full_name',"is_archived=0 AND status='active'"],
            'projects'=>['acc_projects','name','active=1'],
            'tasks'=>['erp_project_tasks','title','is_archived=0'],
            'registers'=>['erp_pos_registers','name',"is_archived=0 AND status='active'"],
            'carts'=>['erp_pos_carts','id',"is_archived=0 AND status='draft'"],
            'warehouses'=>['acc_warehouses','name','active=1'],
            'parties'=>['acc_parties','name','active=1'],
            'items'=>['acc_items','name','active=1'],
        ];
        if(!isset($map[$ref]))throw new RuntimeException('مرجع تعریف نشده است.');
        [$table,$name,$active]=$map[$ref];
        $s=pdo()->prepare("SELECT id,`$name` AS label FROM `$table` WHERE workspace_id=? AND company_id=? AND $active ORDER BY id DESC LIMIT 200");
        $s->execute([$wid,$cid]);return $s->fetchAll();
    }

    /** The sole POS-to-sales bridge. Creates a canonical PREINVOICE DRAFT, never a posted sale. */
    public static function convertCartToSalesDraft(int $wid,int $cid,int $cartId,int $uid): array
    {
        self::assertScope($wid,$cid);
        if($uid<1||$cartId<1)throw new RuntimeException('درخواست نامعتبر است.');
        $db=pdo();$db->beginTransaction();
        try{
            $st=$db->prepare("SELECT * FROM erp_pos_carts WHERE id=? AND workspace_id=? AND company_id=? AND is_archived=0 FOR UPDATE");
            $st->execute([$cartId,$wid,$cid]);$cart=$st->fetch();
            if(!$cart || $cart['status']!=='draft')throw new RuntimeException('این سبد قبلاً تبدیل شده یا در دسترس نیست.');
            $party=(int)$cart['customer_id'];$reg=(int)$cart['register_id'];
            if(!self::resolveReference('parties',$wid,$cid,$party))throw new RuntimeException('برای پیش‌فاکتور مشتری فعال انتخاب کنید.');
            if(!self::resolveReference('registers',$wid,$cid,$reg))throw new RuntimeException('صندوق معتبر نیست.');
            $st=$db->prepare("SELECT warehouse_id,status FROM erp_pos_registers WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");
            $st->execute([$reg,$wid,$cid]);$register=$st->fetch();
            $warehouse=(int)($register['warehouse_id']??0);
            if(!$register || $register['status']!=='active' || !self::resolveReference('warehouses',$wid,$cid,$warehouse))throw new RuntimeException('صندوق یا انبار غیرفعال است.');
            $st=$db->prepare("SELECT l.*,i.name item_name FROM erp_pos_cart_lines l JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=l.workspace_id AND i.company_id=l.company_id AND i.active=1 WHERE l.workspace_id=? AND l.company_id=? AND l.cart_id=? AND l.is_archived=0 ORDER BY l.id FOR UPDATE");
            $st->execute([$wid,$cid,$cartId]);$lines=$st->fetchAll();
            if(!$lines||count($lines)>100)throw new RuntimeException('سبد باید ۱ تا ۱۰۰ قلم فعال داشته باشد.');
            $gross=0.;$discount=0.;$tax=0.;$net=0.;$prepared=[];
            foreach($lines as $line){
                $calc=self::posLineTotals((float)$line['quantity'],(float)$line['unit_price'],(float)$line['discount_percent'],(float)$line['tax_percent']);
                $gross+=$calc['base'];$discount+=$calc['discount'];$tax+=$calc['tax'];$net+=$calc['total'];
                $prepared[]=[$line,$calc];
            }
            if($net>100000000000000)throw new RuntimeException('مبلغ سبد از محدوده مجاز بیشتر است.');
            $number='POS-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(4)));
            $doc=$db->prepare("INSERT INTO acc_sales_docs (workspace_id,company_id,doc_type,document_no,document_date,party_id,warehouse_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at) VALUES (?,?,'preinvoice',?,CURDATE(),?,?,'منشأ: سبد فروش صندوق - پیش‌نویس','draft','not_sent',?,?,?,?,?,NOW(),NOW())");
            $doc->execute([$wid,$cid,$number,$party,$warehouse,round($gross,2),round($discount,2),round($tax,2),round($net,2),$uid]);
            $docId=(int)$db->lastInsertId();
            $insert=$db->prepare('INSERT INTO acc_sales_lines (workspace_id,sales_doc_id,line_no,item_id,warehouse_id,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            $n=1;
            foreach($prepared as [$line,$c]){
                $insert->execute([$wid,$docId,$n++,(int)$line['item_id'],$warehouse,$line['item_name'],(float)$line['quantity'],(float)$line['unit_price'],$c['discount'],(float)$line['tax_percent'],$c['tax'],$c['total']]);
            }
            $up=$db->prepare("UPDATE erp_pos_carts SET status='converted',linked_sales_doc_id=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='draft'");
            $up->execute([$docId,$cartId,$wid,$cid]);
            if($up->rowCount()!==1)throw new RuntimeException('تبدیل سبد هم‌زمان تغییر کرده است.');
            $db->commit();
            Audit::log('erp.pos.convert_draft','acc_sales_docs',$docId,'تبدیل سبد #'.$cartId.' به پیش‌فاکتور پیش‌نویس',null,['cart_id'=>$cartId,'document_no'=>$number]);
            return ['id'=>$docId,'document_no'=>$number,'net_total'=>round($net,2),'posted'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function posLineTotals(float $quantity,float $unitPrice,float $discountPercent,float $taxPercent): array
    {
        foreach([$quantity,$unitPrice,$discountPercent,$taxPercent] as $x)if(!is_finite($x))throw new RuntimeException('عدد غیرمجاز در سبد فروش.');
        if($quantity<=0||$quantity>1000000||$unitPrice<0||$unitPrice>100000000000000||$discountPercent<0||$discountPercent>100||$taxPercent<0||$taxPercent>100)throw new RuntimeException('قیمت/تعداد/درصد نامعتبر است.');
        $base=round($quantity*$unitPrice,2);$disc=round($base*$discountPercent/100,2);
        $tax=round(($base-$disc)*$taxPercent/100,2);
        return ['base'=>$base,'discount'=>$disc,'tax'=>$tax,'total'=>round($base-$disc+$tax,2)];
    }

    public static function cartSummary(int $wid,int $cid,int $cartId): array
    {
        $cart=self::find('carts',$wid,$cid,$cartId);
        if(!$cart || (int)$cart['is_archived']!==0)throw new RuntimeException('سبد نامعتبر است.');
        $s=pdo()->prepare('SELECT COUNT(*) n,COALESCE(SUM(quantity*unit_price*(1-discount_percent/100)),0) net,COALESCE(SUM(quantity*unit_price*(1-discount_percent/100)*tax_percent/100),0) tax FROM erp_pos_cart_lines WHERE workspace_id=? AND company_id=? AND cart_id=? AND is_archived=0');
        $s->execute([$wid,$cid,$cartId]);$r=$s->fetch();
        return ['count'=>(int)$r['n'],'net'=>(float)$r['net'],'tax'=>(float)$r['tax'],'gross'=>(float)$r['net']+(float)$r['tax'], 'posted'=>false];
    }

    public static function dashboard(int $wid,int $cid): array
    {
        self::assertScope($wid,$cid);
        $queries=[
            'employees'=>"SELECT COUNT(*) FROM erp_employees WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status='active'",
            'overdue_tasks'=>"SELECT COUNT(*) FROM erp_project_tasks WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status NOT IN ('done','cancelled') AND due_date<CURDATE()",
            'open_tickets'=>"SELECT COUNT(*) FROM erp_service_tickets WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status IN ('open','working')",
            'leave_pending'=>"SELECT COUNT(*) FROM erp_leave_requests WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status='pending'",
            'expenses_pending'=>"SELECT COUNT(*) FROM erp_expense_claims WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status='pending'",
            'draft_carts'=>"SELECT COUNT(*) FROM erp_pos_carts WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status='draft'",
            'assets'=>"SELECT COUNT(*) FROM erp_fixed_assets WHERE workspace_id=? AND company_id=? AND is_archived=0 AND status='in_use'",
        ];
        $out=[];foreach($queries as $key=>$sql){$s=pdo()->prepare($sql);$s->execute([$wid,$cid]);$out[$key]=(int)$s->fetchColumn();}
        return $out;
    }
}
