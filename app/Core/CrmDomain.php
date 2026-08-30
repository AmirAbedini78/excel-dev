<?php
final class CrmDomain
{
    public const VERSION='10.4.0';
    private const STAGES=['qualification','proposal','negotiation','won','lost'];
    private const TYPES=['call','meeting','email','message','task','note'];

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_party_contacts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL, party_id BIGINT NOT NULL,
            full_name VARCHAR(190) NOT NULL, job_title VARCHAR(190) NULL,
            mobile VARCHAR(80) NULL, phone VARCHAR(80) NULL, email VARCHAR(190) NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0, notes VARCHAR(1000) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_crm_contact_party (workspace_id,company_id,party_id,active,is_primary)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_opportunities (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL,
            opportunity_no VARCHAR(120) NOT NULL, party_id BIGINT NOT NULL,
            title VARCHAR(255) NOT NULL, stage VARCHAR(40) NOT NULL DEFAULT 'qualification',
            status VARCHAR(30) NOT NULL DEFAULT 'open', amount_irr DECIMAL(20,2) NOT NULL DEFAULT 0,
            probability DECIMAL(9,4) NOT NULL DEFAULT 50, expected_close_date DATE NULL,
            notes TEXT NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uniq_crm_opp_no (workspace_id,company_id,opportunity_no),
            INDEX idx_crm_opp_party (workspace_id,company_id,party_id,status,stage),
            INDEX idx_crm_opp_pipeline (workspace_id,company_id,status,stage,expected_close_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_activities (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL, company_id INT NOT NULL,
            activity_no VARCHAR(120) NOT NULL, party_id BIGINT NOT NULL,
            opportunity_id BIGINT NULL, activity_type VARCHAR(40) NOT NULL DEFAULT 'task',
            subject VARCHAR(255) NOT NULL, activity_date DATE NOT NULL, due_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'planned', outcome VARCHAR(1000) NULL,
            notes TEXT NULL, created_by INT NULL, completed_by INT NULL, completed_at DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uniq_crm_activity_no (workspace_id,company_id,activity_no),
            INDEX idx_crm_activity_party (workspace_id,company_id,party_id,status,activity_date),
            INDEX idx_crm_activity_due (workspace_id,company_id,status,due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $defs=[['crm.view','مشاهده CRM و Customer 360','crm',240],['crm.manage','مدیریت CRM','crm',241]];
        $ins=$pdo->prepare("INSERT INTO workspace_permissions (permission_key,title,group_key,sort_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),group_key=VALUES(group_key),sort_order=VALUES(sort_order)");
        foreach($defs as $d)$ins->execute($d);
        $sets=['owner'=>['crm.view','crm.manage'],'workspace_admin'=>['crm.view','crm.manage'],'manager'=>['crm.view','crm.manage'],'accountant'=>['crm.view'],'viewer'=>['crm.view']];
        $pid=$pdo->prepare("SELECT id FROM workspace_permissions WHERE permission_key=? LIMIT 1");
        $roles=$pdo->prepare("SELECT id,role_key FROM workspace_roles WHERE workspace_id=?");
        $rp=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) VALUES (?,?)");
        foreach($pdo->query("SELECT id FROM workspaces WHERE status='active'")->fetchAll() as $w){
            $roles->execute([(int)$w['id']]);
            foreach($roles->fetchAll() as $role)foreach($sets[(string)$role['role_key']]??[] as $key){
                $pid->execute([$key]);$permissionId=(int)$pid->fetchColumn();
                if($permissionId>0)$rp->execute([(int)$role['id'],$permissionId]);
            }
        }
    }

    private static function company(int $wid,int $cid): void
    {
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);if(!$st->fetchColumn())throw new RuntimeException('company_not_found');
    }

    private static function party(int $wid,int $cid,int $partyId): array
    {
        $st=pdo()->prepare("SELECT id,code,name,party_type,national_id,economic_code,registration_no,mobile,phone,email,address,credit_limit
            FROM acc_parties WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid,$partyId]);$r=$st->fetch();
        if(!$r)throw new RuntimeException('crm_party_not_found');return$r;
    }

    private static function d(string $v): ?string
    {
        $v=trim($v);return $v===''?null:AccountingRepository::date($v);
    }

    public static function searchCustomers(int $wid,int $cid,string $query=''): array
    {
        self::company($wid,$cid);$params=[$wid,$cid];
        $where="workspace_id=? AND company_id=? AND active=1 AND party_type IN ('customer','both')";
        if(($query=trim($query))!==''){
            $like='%'.$query.'%';$where.=" AND (name LIKE ? OR code LIKE ? OR mobile LIKE ? OR national_id LIKE ?)";
            array_push($params,$like,$like,$like,$like);
        }
        $st=pdo()->prepare("SELECT id,code,name,party_type,national_id,mobile,phone,email,credit_limit FROM acc_parties WHERE $where ORDER BY name LIMIT 200");
        $st->execute($params);return$st->fetchAll();
    }

    private static function balance(int $wid,int $cid,int $partyId): float
    {
        $st=pdo()->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM acc_voucher_lines l
            JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id
            WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final') AND l.party_id=?");
        $st->execute([$wid,$cid,$partyId]);return(float)$st->fetchColumn();
    }

    private static function sales(int $wid,int $cid,int $partyId): array
    {
        $st=pdo()->prepare("SELECT COUNT(*) cnt,COALESCE(SUM(net_total),0) total,MAX(document_date) last_date
            FROM acc_sales_docs WHERE workspace_id=? AND company_id=? AND party_id=? AND doc_type IN ('invoice','preinvoice')");
        $st->execute([$wid,$cid,$partyId]);$r=$st->fetch()?:[];
        return ['count'=>(int)($r['cnt']??0),'total'=>(float)($r['total']??0),'last_date'=>$r['last_date']??null];
    }

    private static function outstandingQty(int $wid,int $cid,int $partyId): float
    {
        $st=pdo()->prepare("SELECT COALESCE(SUM(GREATEST(l.quantity-COALESCE(x.delivered,0),0)),0)
            FROM acc_sales_lines l JOIN acc_sales_docs s ON s.id=l.sales_doc_id AND s.workspace_id=l.workspace_id
            LEFT JOIN (
                SELECT dl.sales_line_id,SUM(dl.quantity) delivered FROM acc_sales_delivery_lines dl
                JOIN acc_sales_deliveries d ON d.id=dl.delivery_id AND d.workspace_id=dl.workspace_id
                WHERE d.company_id=? AND d.status='posted' GROUP BY dl.sales_line_id
            ) x ON x.sales_line_id=l.id
            WHERE l.workspace_id=? AND s.company_id=? AND s.party_id=? AND s.workflow_status<>'void'");
        $st->execute([$cid,$wid,$cid,$partyId]);return(float)$st->fetchColumn();
    }

    public static function customer360(int $wid,int $cid,int $partyId): array
    {
        self::company($wid,$cid);$party=self::party($wid,$cid,$partyId);$sales=self::sales($wid,$cid,$partyId);
        $contacts=pdo()->prepare("SELECT id,full_name,job_title,mobile,phone,email,is_primary,notes FROM crm_party_contacts WHERE workspace_id=? AND company_id=? AND party_id=? AND active=1 ORDER BY is_primary DESC,id DESC");
        $contacts->execute([$wid,$cid,$partyId]);$contacts=$contacts->fetchAll();
        $opps=pdo()->prepare("SELECT id,opportunity_no,title,stage,status,amount_irr,probability,expected_close_date,notes FROM crm_opportunities WHERE workspace_id=? AND company_id=? AND party_id=? ORDER BY id DESC LIMIT 50");
        $opps->execute([$wid,$cid,$partyId]);$opps=$opps->fetchAll();
        $acts=pdo()->prepare("SELECT id,activity_no,activity_type,subject,activity_date,due_date,status,outcome,notes FROM crm_activities WHERE workspace_id=? AND company_id=? AND party_id=? ORDER BY activity_date DESC,id DESC LIMIT 50");
        $acts->execute([$wid,$cid,$partyId]);$acts=$acts->fetchAll();
        $open=0;$amount=0.0;$weighted=0.0;foreach($opps as $o)if((string)$o['status']==='open'){$open++;$amount+=(float)$o['amount_irr'];$weighted+=(float)$o['amount_irr']*(float)$o['probability']/100;}
        $next=null;foreach($acts as $a)if((string)$a['status']==='planned'&&!empty($a['due_date'])&&($next===null||strcmp((string)$a['due_date'],(string)$next['due_date'])<0))$next=$a;
        $bal=self::balance($wid,$cid,$partyId);
        return ['party'=>$party,'financial'=>[
            'current_balance_irr'=>$bal,'balance_nature'=>$bal>0.01?'debtor':($bal<-0.01?'creditor':'settled'),
            'sales_document_count'=>$sales['count'],'recorded_sales_net_irr'=>$sales['total'],'last_sale_date'=>$sales['last_date'],
            'outstanding_sales_quantity'=>self::outstandingQty($wid,$cid,$partyId)
        ],'crm'=>['contact_count'=>count($contacts),'open_opportunity_count'=>$open,'open_pipeline_irr'=>$amount,'weighted_pipeline_irr'=>$weighted,'next_followup'=>$next],
            'contacts'=>$contacts,'opportunities'=>$opps,'activities'=>$acts];
    }

    public static function pipelineSummary(int $wid,int $cid): array
    {
        self::company($wid,$cid);
        $st=pdo()->prepare("SELECT stage,COUNT(*) opportunity_count,COALESCE(SUM(amount_irr),0) amount_irr,COALESCE(SUM(amount_irr*probability/100),0) weighted_irr
            FROM crm_opportunities WHERE workspace_id=? AND company_id=? AND status='open' GROUP BY stage ORDER BY stage");
        $st->execute([$wid,$cid]);$rows=$st->fetchAll();$count=0;$amount=0.0;$weighted=0.0;
        foreach($rows as $r){$count+=(int)$r['opportunity_count'];$amount+=(float)$r['amount_irr'];$weighted+=(float)$r['weighted_irr'];}
        $q=pdo()->prepare("SELECT o.*,p.name party_name FROM crm_opportunities o JOIN acc_parties p ON p.id=o.party_id AND p.workspace_id=o.workspace_id AND p.company_id=o.company_id WHERE o.workspace_id=? AND o.company_id=? ORDER BY FIELD(o.status,'open','won','lost'),o.id DESC LIMIT 100");
        $q->execute([$wid,$cid]);
        return ['open_count'=>$count,'open_amount_irr'=>$amount,'weighted_amount_irr'=>$weighted,'rows'=>$rows,'opportunities'=>$q->fetchAll()];
    }

    public static function followupQueue(int $wid,int $cid,int $days=7): array
    {
        self::company($wid,$cid);$days=max(0,min(60,$days));$today=date('Y-m-d');$until=date('Y-m-d',strtotime("+$days days"));
        $st=pdo()->prepare("SELECT a.id,a.activity_no,a.party_id,a.activity_type,a.subject,a.due_date,p.name party_name
            FROM crm_activities a JOIN acc_parties p ON p.id=a.party_id AND p.workspace_id=a.workspace_id AND p.company_id=a.company_id
            WHERE a.workspace_id=? AND a.company_id=? AND a.status='planned' AND a.due_date IS NOT NULL AND a.due_date<=? ORDER BY a.due_date,a.id LIMIT 200");
        $st->execute([$wid,$cid,$until]);$rows=$st->fetchAll();$over=0;$now=0;$up=0;
        foreach($rows as &$r){$r['bucket']=$r['due_date']<$today?'overdue':($r['due_date']===$today?'today':'upcoming');if($r['bucket']==='overdue')$over++;elseif($r['bucket']==='today')$now++;else$up++;}unset($r);
        return ['today'=>$today,'until'=>$until,'overdue_count'=>$over,'today_count'=>$now,'upcoming_count'=>$up,'rows'=>$rows];
    }

    public static function normalizeOpportunityArgs(int $wid,int $cid,array $a): array
    {
        self::company($wid,$cid);$partyId=(int)($a['party_id']??0);self::party($wid,$cid,$partyId);
        $title=trim((string)($a['title']??''));if($title===''||mb_strlen($title)>255)throw new RuntimeException('عنوان فرصت فروش نامعتبر است.');
        $stage=trim((string)($a['stage']??'qualification'));if(!in_array($stage,self::STAGES,true))throw new RuntimeException('مرحله فرصت فروش نامعتبر است.');
        $status=$stage==='won'?'won':($stage==='lost'?'lost':'open');
        return ['party_id'=>$partyId,'title'=>$title,'stage'=>$stage,'status'=>$status,'amount_irr'=>max(0,(float)($a['amount_irr']??0)),
            'probability'=>max(0,min(100,(float)($a['probability']??50))),'expected_close_date'=>self::d((string)($a['expected_close_date']??'')),
            'notes'=>mb_substr(trim((string)($a['notes']??'')),0,4000)];
    }

    public static function createOpportunity(int $wid,int $cid,int $userId,array $a): array
    {
        $n=self::normalizeOpportunityArgs($wid,$cid,$a);$no='OPP-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
        pdo()->prepare("INSERT INTO crm_opportunities (workspace_id,company_id,opportunity_no,party_id,title,stage,status,amount_irr,probability,expected_close_date,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([$wid,$cid,$no,$n['party_id'],$n['title'],$n['stage'],$n['status'],$n['amount_irr'],$n['probability'],$n['expected_close_date'],$n['notes']?:null,$userId]);
        $id=(int)pdo()->lastInsertId();Audit::logForWorkspace($wid,'crm.opportunity.create','crm_opportunities',$id,'ثبت فرصت فروش '.$no);
        return ['entity'=>'crm_opportunities','id'=>$id,'opportunity_no'=>$no,'party_id'=>$n['party_id'],'title'=>$n['title'],'stage'=>$n['stage'],'status'=>$n['status']];
    }

    public static function normalizeActivityArgs(int $wid,int $cid,array $a): array
    {
        self::company($wid,$cid);$partyId=(int)($a['party_id']??0);self::party($wid,$cid,$partyId);
        $type=trim((string)($a['activity_type']??'task'));if(!in_array($type,self::TYPES,true))throw new RuntimeException('نوع فعالیت CRM نامعتبر است.');
        $subject=trim((string)($a['subject']??''));if($subject===''||mb_strlen($subject)>255)throw new RuntimeException('موضوع فعالیت CRM نامعتبر است.');
        return ['party_id'=>$partyId,'activity_type'=>$type,'subject'=>$subject,'activity_date'=>self::d((string)($a['activity_date']??''))?:date('Y-m-d'),
            'due_date'=>self::d((string)($a['due_date']??'')),'status'=>'planned','notes'=>mb_substr(trim((string)($a['notes']??'')),0,4000)];
    }

    public static function createActivity(int $wid,int $cid,int $userId,array $a): array
    {
        $n=self::normalizeActivityArgs($wid,$cid,$a);$no='ACT-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
        pdo()->prepare("INSERT INTO crm_activities (workspace_id,company_id,activity_no,party_id,activity_type,subject,activity_date,due_date,status,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?, 'planned',?,?,NOW(),NOW())")->execute([$wid,$cid,$no,$n['party_id'],$n['activity_type'],$n['subject'],$n['activity_date'],$n['due_date'],$n['notes']?:null,$userId]);
        $id=(int)pdo()->lastInsertId();Audit::logForWorkspace($wid,'crm.activity.create','crm_activities',$id,'ثبت پیگیری '.$no);
        return ['entity'=>'crm_activities','id'=>$id,'activity_no'=>$no,'party_id'=>$n['party_id'],'subject'=>$n['subject'],'due_date'=>$n['due_date'],'status'=>'planned'];
    }

    public static function createContact(int $wid,int $cid,int $userId,array $a): array
    {
        self::company($wid,$cid);$partyId=(int)($a['party_id']??0);self::party($wid,$cid,$partyId);$name=trim((string)($a['full_name']??''));
        if($name==='')throw new RuntimeException('نام مخاطب الزامی است.');
        pdo()->prepare("INSERT INTO crm_party_contacts (workspace_id,company_id,party_id,full_name,job_title,mobile,phone,email,is_primary,notes,active,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,1,?,NOW(),NOW())")->execute([$wid,$cid,$partyId,$name,trim((string)($a['job_title']??''))?:null,trim((string)($a['mobile']??''))?:null,trim((string)($a['phone']??''))?:null,trim((string)($a['email']??''))?:null,!empty($a['is_primary'])?1:0,trim((string)($a['notes']??''))?:null,$userId]);
        return ['entity'=>'crm_party_contacts','id'=>(int)pdo()->lastInsertId(),'party_id'=>$partyId];
    }

    public static function completeActivity(int $wid,int $cid,int $userId,int $id): void
    {
        self::company($wid,$cid);$st=pdo()->prepare("UPDATE crm_activities SET status='completed',completed_by=?,completed_at=NOW(),updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=? AND status='planned'");
        $st->execute([$userId,$id,$wid,$cid]);if($st->rowCount()!==1)throw new RuntimeException('پیگیری باز پیدا نشد.');
    }

    public static function moveOpportunity(int $wid,int $cid,int $id,string $stage): void
    {
        self::company($wid,$cid);if(!in_array($stage,self::STAGES,true))throw new RuntimeException('مرحله نامعتبر است.');
        $status=$stage==='won'?'won':($stage==='lost'?'lost':'open');$st=pdo()->prepare("UPDATE crm_opportunities SET stage=?,status=?,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?");
        $st->execute([$stage,$status,$id,$wid,$cid]);if($st->rowCount()!==1)throw new RuntimeException('فرصت فروش پیدا نشد.');
    }
}
