<?php
/**
 * ERPSMART 10.15 — typed enterprise business records, not a financial posting engine.
 * Company isolation, versioned writes, approved transitions and append-only events.
 * Never creates stock ledger, journal, payment, salary, invoice or third-party message.
 */
final class EnterpriseDomain
{
    public const VERSION = '10.15.0';
    private static array $records=[];
    private static array $groups=[];

    public static function groups(): array
    {
        if (!self::$groups) self::$groups=EnterpriseCatalog::groups();
        return self::$groups;
    }

    public static function records(): array
    {
        if (!self::$records) self::$records=EnterpriseCatalog::records();
        return self::$records;
    }

    public static function spec(string $key): array
    {
        $defs=self::records();
        if (!isset($defs[$key])) throw new RuntimeException('نوع رکورد تعریف نشده است.');
        return $defs[$key];
    }

    public static function groupFor(string $key): string
    { return self::spec($key)['group']; }

    public static function assertScope(int $wid,int $cid): void
    {
        if ($wid<1 || $cid<1) throw new RuntimeException('Workspace و شرکت باید انتخاب شوند.');
        $q=pdo()->prepare('SELECT 1 FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1');
        $q->execute([$cid,$wid]);
        if (!$q->fetchColumn()) throw new RuntimeException('شرکت در Workspace جاری فعال نیست.');
    }

    public static function permission(string $group,string $verb): string
    {
        if (!isset(self::groups()[$group]) || !in_array($verb,['view','manage','approve'],true))
            throw new RuntimeException('دسترسی تعریف نشده است.');
        return 'enterprise.'.$group.'.'.$verb;
    }

    public static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS erp_ext_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,company_id INT NOT NULL,
            entity_key VARCHAR(64) NOT NULL,record_id BIGINT NOT NULL,
            actor_id INT NOT NULL,action VARCHAR(32) NOT NULL,
            from_status VARCHAR(32) NULL,to_status VARCHAR(32) NULL,
            revision INT NOT NULL,comment_text VARCHAR(1000) NULL,created_at DATETIME NOT NULL,
            INDEX idx_ext_event (workspace_id,company_id,entity_key,record_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS erp_ext_links (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,company_id INT NOT NULL,
            source_entity VARCHAR(64) NOT NULL,source_id BIGINT NOT NULL,
            target_table VARCHAR(64) NOT NULL,target_id BIGINT NOT NULL,
            created_by INT NOT NULL,created_at DATETIME NOT NULL,
            UNIQUE KEY uq_ext_source (workspace_id,company_id,source_entity,source_id),
            INDEX idx_ext_target (workspace_id,company_id,target_table,target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (self::records() as $key=>$def) {
            $columns=[];
            foreach ($def['fields'] as $field=>$meta) {
                $sqlType=match($meta['type']) {
                    'ref'=>'BIGINT NULL',
                    'date'=>'DATE NULL',
                    'money'=>'DECIMAL(20,2) NULL',
                    'qty'=>'DECIMAL(20,4) NULL',
                    'percent'=>'DECIMAL(7,3) NULL',
                    'int'=>'INT NULL',
                    'memo'=>'TEXT NULL',
                    default=>'VARCHAR(190) NULL'
                };
                $columns[]='`'.$field.'` '.$sqlType;
            }
            // Table and column names exclusively originate from hardcoded catalog.
            $db->exec('CREATE TABLE IF NOT EXISTS `'.$def['table'].'` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,company_id INT NOT NULL,
                code VARCHAR(80) NOT NULL,title VARCHAR(190) NOT NULL,
                '.implode(",\n",$columns).',
                status VARCHAR(32) NOT NULL DEFAULT \'draft\',
                revision INT NOT NULL DEFAULT 1,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NOT NULL,reviewed_by INT NULL,
                created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_company_code (workspace_id,company_id,code),
                INDEX idx_company_state (workspace_id,company_id,status,is_archived,updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    private static function chars(string $s): int
    { if(preg_match('//u',$s)!==1)throw new RuntimeException('متن UTF-8 نامعتبر است.'); if(function_exists('mb_strlen'))return mb_strlen($s,'UTF-8'); return (int)preg_match_all('/./us',$s); }

    private static function clip(string $s,int $n): string
    { if(function_exists('mb_substr'))return mb_substr($s,0,$n,'UTF-8');return implode('',array_slice(preg_split('//u',$s,-1,PREG_SPLIT_NO_EMPTY),0,$n)); }

    private static function nullableText($value,int $limit,bool $required,string $label): ?string
    {
        if (is_array($value)||is_object($value)) throw new RuntimeException('متن نامعتبر: '.$label);
        $s=trim((string)($value??''));
        if ($s==='' && $required) throw new RuntimeException('مقدار لازم است: '.$label);
        if (self::chars($s)>$limit) throw new RuntimeException('متن بیش از حد طولانی: '.$label);
        return $s===''?null:$s;
    }

    private static function decimal($raw,int $scale,string $label,bool $required=false): ?string
    {
        if ($raw===''||$raw===null) {
            if ($required) throw new RuntimeException('عدد لازم است: '.$label);
            return null;
        }
        if (!is_string($raw) && !is_numeric($raw)) throw new RuntimeException('عدد نامعتبر: '.$label);
        $v=trim((string)$raw);
        if (!preg_match('/^\d{1,15}(?:\.\d{1,4})?$/D',$v))throw new RuntimeException('محدوده عدد نامعتبر: '.$label);
        $parts=explode('.',$v);
        if (isset($parts[1]) && strlen($parts[1])>$scale)throw new RuntimeException('دقت اعشار نامعتبر: '.$label);
        return $v;
    }

    private static function date($raw,bool $required,string $label): ?string
    {
        $s=self::nullableText($raw,20,$required,$label);
        if ($s===null)return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s))throw new RuntimeException('تاریخ باید میلادی YYYY-MM-DD باشد: '.$label);
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);
        if (!$d||$d->format('Y-m-d')!==$s)throw new RuntimeException('تاریخ معتبر نیست: '.$label);
        return $s;
    }

    private static function refs(): array
    {
        return [
            'party'=>['acc_parties','name','active','accounting.view'],
            'item'=>['acc_items','name','active','accounting.view'],
            'warehouse'=>['acc_warehouses','name','active','accounting.view'],
            'project'=>['acc_projects','name','active','projects.view'],
            'employee'=>['erp_employees','full_name','is_archived','hr.view'],
            'asset'=>['erp_fixed_assets','name','is_archived','assets.view'],
            'ticket'=>['erp_service_tickets','subject','is_archived','service.view'],
            'production_plan'=>['erp_production_plans','id','available','production.view'],
            'bom'=>['erp_production_boms','id','available','production.view'],
        ];
    }

    private static function refDef(string $ref): array
    {
        $list=self::refs();
        if (isset($list[$ref]))return $list[$ref];
        $spec=self::spec($ref);
        return [$spec['table'],'title','is_archived',self::permission($spec['group'],'view')];
    }

    private static function validateRef(string $ref,int $wid,int $cid,$raw): ?int
    {
        if ($raw===null||$raw==='')return null;
        if (is_array($raw)||is_object($raw)||!preg_match('/^[1-9]\d{0,14}$/D',(string)$raw))throw new RuntimeException('شناسه مرجع نامعتبر است.');
        $id=(int)$raw;
        [$table,$label,$state,$permission]=self::refDef($ref);
        Tenant::requirePermission($permission);
        $flag=$state==='active'?'`active`=1':($state==='available'?'1=1':'`is_archived`=0');
        $q=pdo()->prepare("SELECT 1 FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? AND $flag LIMIT 1");
        $q->execute([$id,$wid,$cid]);
        if (!$q->fetchColumn())throw new RuntimeException('مرجع خارج از شرکت، ناموجود یا غیرفعال است.');
        return $id;
    }

    public static function referenceOptions(string $ref,int $wid,int $cid): array
    {
        [$table,$label,$state,$permission]=self::refDef($ref);
        if (!Tenant::can($permission))return [];
        $flag=$state==='active'?'`active`=1':($state==='available'?'1=1':'`is_archived`=0');
        $q=pdo()->prepare("SELECT id,`$label` AS label FROM `$table` WHERE workspace_id=? AND company_id=? AND $flag ORDER BY id DESC LIMIT 250");
        $q->execute([$wid,$cid]);return $q->fetchAll();
    }

    public static function prepare(string $key,int $wid,int $cid,array $input): array
    {
        $spec=self::spec($key);
        $code=self::nullableText($input['code']??null,80,true,'کد');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{1,79}$/D',$code))throw new RuntimeException('کد باید لاتین و ۲ تا ۸۰ کاراکتر باشد.');
        $out=['code'=>$code,'title'=>self::nullableText($input['title']??null,190,true,'عنوان')];
        foreach ($spec['fields'] as $field=>$meta) {
            $kind=$meta['type'];$label=$meta['label'];$needed=(bool)$meta['required'];$v=$input[$field]??null;
            if ($kind==='ref') {
                $out[$field]=self::validateRef($meta['ref'],$wid,$cid,$v);
                if ($needed && $out[$field]===null)throw new RuntimeException('مرجع لازم است: '.$label);
            } elseif($kind==='date') $out[$field]=self::date($v,$needed,$label);
            elseif(in_array($kind,['money','qty','percent'],true)){
                $out[$field]=self::decimal($v,$kind==='qty'?4:($kind==='percent'?3:2),$label,$needed);
                if ($kind==='percent' && $out[$field]!==null && (float)$out[$field]>100)throw new RuntimeException('درصد نمی‌تواند بیشتر از ۱۰۰ باشد.');
            } elseif($kind==='int') {
                $out[$field]=self::decimal($v,0,$label,$needed);
                if ($out[$field]!==null && (float)$out[$field]>1000000)throw new RuntimeException('عدد بیش از حد مجاز است.');
            } elseif($kind==='select') {
                $s=self::nullableText($v,190,$needed,$label);
                if($s!==null&&!isset($meta['choices'][$s]))throw new RuntimeException('گزینه نامعتبر: '.$label);
                $out[$field]=$s;
            } else $out[$field]=self::nullableText($v,$kind==='memo'?3000:190,$needed,$label);
        }
        if (($out['valid_from']??null)!==null && ($out['valid_to']??null)!==null && $out['valid_from']>$out['valid_to'])throw new RuntimeException('تاریخ اعتبار نامعتبر است.');
        if (($out['starts_on']??null)!==null && ($out['ends_on']??null)!==null && $out['starts_on']>$out['ends_on'])throw new RuntimeException('تاریخ پایان قبل از شروع است.');
        if (isset($out['min_qty'],$out['target_qty']) && (float)$out['min_qty']>(float)$out['target_qty'])throw new RuntimeException('حداقل موجودی از هدف بیشتر است.');
        if (isset($out['source_warehouse_id'],$out['target_warehouse_id']) && $out['source_warehouse_id']===$out['target_warehouse_id'])throw new RuntimeException('مبدأ و مقصد یکسان هستند.');
        if ($key==='locations' && !empty($out['parent_id']) && $out['parent_id']===(int)($input['id']??0))throw new RuntimeException('مکان نمی‌تواند والد خودش باشد.');
        if ($key==='shifts')foreach(['start_time','end_time'] as $time)if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',(string)$out[$time]))throw new RuntimeException('ساعت نامعتبر است.');
        return $out;
    }

    private static function event(PDO $db,string $key,int $wid,int $cid,int $rid,int $uid,string $action,?string $from,?string $to,int $rev,?string $note=null): void
    {
        $q=$db->prepare('INSERT INTO erp_ext_events (workspace_id,company_id,entity_key,record_id,actor_id,action,from_status,to_status,revision,comment_text,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
        $q->execute([$wid,$cid,$key,$rid,$uid,$action,$from,$to,$rev,$note]);
    }

    public static function save(string $key,int $wid,int $cid,int $uid,array $input,int $id=0,int $expectedRevision=0): int
    {
        $spec=self::spec($key);Tenant::requirePermission(self::permission($spec['group'],'manage'));
        self::assertScope($wid,$cid);
        if($uid<1)throw new RuntimeException('کاربر نامعتبر.');
        $values=self::prepare($key,$wid,$cid,$input);
        $db=pdo();$db->beginTransaction();
        try {
            $table=$spec['table'];$columns=array_keys($values);$params=array_values($values);
            if($id>0){
                if($expectedRevision<1)throw new RuntimeException('نسخه رکورد لازم است.');
                $q=$db->prepare("SELECT status,revision,is_archived FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? FOR UPDATE");
                $q->execute([$id,$wid,$cid]);$old=$q->fetch();
                if(!$old||$old['is_archived']||!in_array($old['status'],['draft','active','rejected'],true))throw new RuntimeException('رکورد در این وضعیت قابل ویرایش نیست.');
                if((int)$old['revision']!==$expectedRevision)throw new RuntimeException('ویرایش هم‌زمان؛ فرم را دوباره بارگذاری کنید.');
                $assign=implode(',',array_map(fn($field)=>'`'.$field.'`=?',$columns));
                $q=$db->prepare("UPDATE `$table` SET $assign,revision=revision+1,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND revision=?");
                $q->execute(array_merge($params,[$id,$wid,$cid,$expectedRevision]));
                if($q->rowCount()!==1)throw new RuntimeException('ویرایش هم‌زمان.');
                self::event($db,$key,$wid,$cid,$id,$uid,'update',$old['status'],$old['status'],$expectedRevision+1);
            } else {
                $columns=array_merge(['workspace_id','company_id'], $columns,['created_by']);
                $valuesAll=array_merge([$wid,$cid],$params,[$uid]);
                $fields=implode(',',array_map(fn($f)=>'`'.$f.'`',$columns));
                $marks=implode(',',array_fill(0,count($columns),'?'));
                $q=$db->prepare("INSERT INTO `$table` ($fields,created_at,updated_at) VALUES ($marks,NOW(),NOW())");
                $q->execute($valuesAll);$id=(int)$db->lastInsertId();
                self::event($db,$key,$wid,$cid,$id,$uid,'create',null,'draft',1);
            }
            $db->commit();Audit::log('enterprise.'.($expectedRevision?'update':'create'),$table,$id,$spec['title']);
            return $id;
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function find(string $key,int $wid,int $cid,int $id): ?array
    {
        if($id<1)return null;$table=self::spec($key)['table'];
        $q=pdo()->prepare("SELECT * FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? AND is_archived=0 LIMIT 1");
        $q->execute([$id,$wid,$cid]);return $q->fetch()?:null;
    }

    public static function listRecords(string $key,int $wid,int $cid,string $search='',string $status='',int $limit=50): array
    {
        $table=self::spec($key)['table'];$limit=max(1,min($limit,100));
        $sql="SELECT * FROM `$table` WHERE workspace_id=? AND company_id=? AND is_archived=0";$params=[$wid,$cid];
        if($search!==''){$search=self::clip($search,80);$sql.=' AND (code LIKE ? OR title LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
        if($status!==''&&in_array($status,['draft','submitted','approved','rejected','active','closed','cancelled'],true)){$sql.=' AND status=?';$params[]=$status;}
        $sql.=" ORDER BY id DESC LIMIT $limit";
        $q=pdo()->prepare($sql);$q->execute($params);return $q->fetchAll();
    }

    public static function nextStatuses(string $state): array
    {
        return [
            'draft'=>['active','submitted','cancelled'],
            'submitted'=>['approved','rejected'],
            'approved'=>['active','closed'],
            'active'=>['closed'],
            'rejected'=>['draft','cancelled'],
        ][$state]??[];
    }

    public static function nextStatusesFor(string $key,string $state): array
    {
        $possible=self::nextStatuses($state);
        // Risk-bearing operational records MUST go through separate human review.
        $reviewed=['vendor_rfqs','vendor_offers','transfers','cycle_counts','work_orders',
            'engineering_changes','contracts','contract_register','repair_orders','signature_requests'];
        if($state==='draft' && in_array($key,$reviewed,true))
            return array_values(array_diff($possible,['active']));
        return $possible;
    }

    public static function transition(string $key,int $wid,int $cid,int $uid,int $id,int $revision,string $target,string $note=''): void
    {
        $spec=self::spec($key);$group=$spec['group'];
        $review=in_array($target,['approved','rejected'],true);
        Tenant::requirePermission(self::permission($group,$review?'approve':'manage'));
        $note=self::nullableText($note,1000,$target==='rejected','شرح تصمیم');
        if($revision<1)throw new RuntimeException('نسخه رکورد لازم است.');
        $db=pdo();$db->beginTransaction();
        try{
            $table=$spec['table'];
            $q=$db->prepare("SELECT status,revision,created_by,is_archived FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$id,$wid,$cid]);$old=$q->fetch();
            if(!$old||$old['is_archived'])throw new RuntimeException('رکورد در دسترس نیست.');
            if((int)$old['revision']!==$revision)throw new RuntimeException('نسخه رکورد تغییر کرده است.');
            if(!in_array($target,self::nextStatusesFor($key,$old['status']),true))throw new RuntimeException('گذار وضعیت مجاز نیست.');
            if($review && (int)$old['created_by']===$uid)throw new RuntimeException('سازنده رکورد نمی‌تواند تأییدکننده همان رکورد باشد.');
            $q=$db->prepare("UPDATE `$table` SET status=?,revision=revision+1,reviewed_by=IF(?=1,?,reviewed_by),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND revision=?");
            $q->execute([$target,$review?1:0,$review?$uid:null,$id,$wid,$cid,$revision]);
            if($q->rowCount()!==1)throw new RuntimeException('تغییر هم‌زمان وضعیت.');
            self::event($db,$key,$wid,$cid,$id,$uid,'transition',$old['status'],$target,$revision+1,$note);
            $db->commit();Audit::log('enterprise.transition',$table,$id,$old['status'].' > '.$target);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function archive(string $key,int $wid,int $cid,int $uid,int $id,int $revision): void
    {
        $spec=self::spec($key);Tenant::requirePermission(self::permission($spec['group'],'manage'));
        if($revision<1)throw new RuntimeException('نسخه نامعتبر.');
        $db=pdo();$db->beginTransaction();
        try{
            $table=$spec['table'];$q=$db->prepare("SELECT status,revision,is_archived FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$id,$wid,$cid]);$old=$q->fetch();
            if(!$old||$old['is_archived']||(int)$old['revision']!==$revision||!in_array($old['status'],['draft','rejected','cancelled','closed'],true))throw new RuntimeException('فقط رکورد پیش‌نویس یا بسته‌شده با نسخه معتبر بایگانی می‌شود.');
            foreach(self::records() as $child)foreach($child['fields'] as $field=>$meta)if(($meta['type']??'')==='ref'&&($meta['ref']??'')===$key){
                $q=$db->prepare("SELECT 1 FROM `".$child['table']."` WHERE workspace_id=? AND company_id=? AND `$field`=? AND is_archived=0 LIMIT 1");
                $q->execute([$wid,$cid,$id]);if($q->fetchColumn())throw new RuntimeException('رکورد دارای وابستگی فعال است و بایگانی نمی‌شود.');
            }
            $q=$db->prepare("UPDATE `$table` SET is_archived=1,revision=revision+1,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND revision=?");
            $q->execute([$id,$wid,$cid,$revision]);if($q->rowCount()!==1)throw new RuntimeException('تغییر هم‌زمان.');
            self::event($db,$key,$wid,$cid,$id,$uid,'archive',$old['status'],$old['status'],$revision+1);
            $db->commit();Audit::log('enterprise.archive',$table,$id,'بایگانی بدون حذف');
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

    public static function history(string $key,int $wid,int $cid,int $id): array
    {
        $q=pdo()->prepare('SELECT action,from_status,to_status,revision,comment_text,actor_id,created_at FROM erp_ext_events WHERE workspace_id=? AND company_id=? AND entity_key=? AND record_id=? ORDER BY id DESC LIMIT 80');
        $q->execute([$wid,$cid,$key,$id]);return $q->fetchAll();
    }
    /**
     * Approved supplier offer -> existing accounting purchase order, DRAFT ONLY.
     * Transactional and idempotent by unique source link; never posts payment,
     * stock receipt, VAT submission or journal entry.
     */
    public static function createPurchaseDraft(int $wid,int $cid,int $uid,int $offerId): array
    {
        Tenant::requirePermission(self::permission('commerce','manage'));
        Tenant::requirePermission('accounting.purchase.manage');
        self::assertScope($wid,$cid);
        if($uid<1||$offerId<1)throw new RuntimeException('پیشنهاد خرید معتبر نیست.');
        $db=pdo();$db->beginTransaction();
        try {
            $q=$db->prepare('SELECT * FROM erp_ext_vendor_offers WHERE id=? AND workspace_id=? AND company_id=? AND is_archived=0 FOR UPDATE');
            $q->execute([$offerId,$wid,$cid]);$offer=$q->fetch();
            if (!$offer||!in_array($offer['status'],['approved','active'],true))throw new RuntimeException('پیشنهاد تأمین‌کننده باید تأییدشده باشد.');
            $q=$db->prepare('SELECT target_id FROM erp_ext_links WHERE workspace_id=? AND company_id=? AND source_entity=? AND source_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$wid,$cid,'vendor_offers',$offerId]);$linked=$q->fetchColumn();
            if($linked){
                $q=$db->prepare('SELECT 1 FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1');
                $q->execute([(int)$linked,$wid,$cid]);
                if(!$q->fetchColumn())throw new RuntimeException('پیوند سفارش قبلی وجود دارد اما سند حذف شده است؛ نیاز به بررسی مدیر مالی.');
                $db->commit();return ['id'=>(int)$linked,'existing'=>true,'posted'=>false];
            }
            $q=$db->prepare('SELECT * FROM erp_ext_vendor_rfqs WHERE id=? AND workspace_id=? AND company_id=? AND is_archived=0 FOR UPDATE');
            $q->execute([(int)$offer['rfq_id'],$wid,$cid]);$rfq=$q->fetch();
            if(!$rfq||!in_array($rfq['status'],['approved','active'],true))throw new RuntimeException('استعلام مرجع تأیید نشده است.');
            self::validateRef('party',$wid,$cid,$offer['vendor_id']);
            self::validateRef('item',$wid,$cid,$rfq['item_id']);
            $warehouse=self::validateRef('warehouse',$wid,$cid,$rfq['warehouse_id']);
            if($warehouse===null)throw new RuntimeException('انبار مقصد لازم است.');
            // The draft bridge deliberately refuses unusually large amounts rather than overflow/round unsafely.
            $qty=(float)$rfq['requested_qty'];$price=(float)$offer['unit_price'];
            if(!is_finite($qty)||!is_finite($price)||$qty<=0||$qty>100000||$price<0||$price>1000000000)
                throw new RuntimeException('مقدار/قیمت خارج از محدوده قابل تبدیل است.');
            $total=round($qty*$price,2);
            if(!is_finite($total)||$total>1000000000000)throw new RuntimeException('مبلغ سفارش خارج از محدوده است.');
            $number='RFQ-'.$offerId.'-'.strtoupper(bin2hex(random_bytes(3)));
            $q=$db->prepare("INSERT INTO acc_purchase_docs (workspace_id,company_id,doc_type,document_no,document_date,party_id,warehouse_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at) VALUES (?,?,'purchase_order_goods',?,CURDATE(),?,?,'منشأ: پیشنهاد تأمین‌کننده — سفارش پیش‌نویس','draft','not_sent',?,0,0,?,?,NOW(),NOW())");
            $q->execute([$wid,$cid,$number,(int)$offer['vendor_id'],$warehouse,$total,$total,$uid]);$docId=(int)$db->lastInsertId();
            $q=$db->prepare('INSERT INTO acc_purchase_lines (workspace_id,purchase_doc_id,line_no,item_id,warehouse_id,description,quantity,unit_price,line_total,created_at) VALUES (?,?,1,?,?,?,?,?,?,NOW())');
            $q->execute([$wid,$docId,(int)$rfq['item_id'],$warehouse,self::clip((string)$rfq['title'],500),$qty,$price,$total]);
            $q=$db->prepare('INSERT INTO erp_ext_links (workspace_id,company_id,source_entity,source_id,target_table,target_id,created_by,created_at) VALUES (?,?,?, ?,?,?,?,NOW())');
            $q->execute([$wid,$cid,'vendor_offers',$offerId,'acc_purchase_docs',$docId,$uid]);
            $db->commit();
            Audit::log('enterprise.purchase_draft','acc_purchase_docs',$docId,'سفارش خرید پیش‌نویس از پیشنهاد #'.$offerId);
            return ['id'=>$docId,'document_no'=>$number,'existing'=>false,'posted'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }

}
