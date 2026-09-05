<?php
final class AiEntityRegistry
{
    public const VERSION='v2';
    private const SEARCH_PER_TYPE=6;
    private const SEARCH_TOTAL=120;

    public static function categories(): array
    {
        return [
            'organization'=>['title'=>'سازمان و شرکت‌ها','icon'=>'🏢','sort'=>10],
            'parties'=>['title'=>'اشخاص و CRM','icon'=>'👥','sort'=>20],
            'commerce'=>['title'=>'خرید و فروش','icon'=>'🧾','sort'=>30],
            'trade'=>['title'=>'بازرگانی و لجستیک','icon'=>'🌐','sort'=>40],
            'inventory'=>['title'=>'کالا و انبار','icon'=>'📦','sort'=>50],
            'finance'=>['title'=>'مالی','icon'=>'💳','sort'=>60],
        ];
    }

    public static function definitions(): array
    {
        return [
            'company'=>['title'=>'شرکت','group'=>'شرکت‌ها','icon'=>'🏢','permission'=>'','page'=>'companies','category'=>'organization','sort'=>10],
            'party.customer'=>['title'=>'مشتری','group'=>'مشتریان','icon'=>'👤','permission'=>'crm.view','page'=>'crm','category'=>'parties','sort'=>20],
            'party.supplier'=>['title'=>'تأمین‌کننده','group'=>'تأمین‌کنندگان','icon'=>'🏭','permission'=>'procurement.view','page'=>'procurement','category'=>'parties','sort'=>30],
            'contact'=>['title'=>'مخاطب','group'=>'مخاطبان','icon'=>'📇','permission'=>'crm.view','page'=>'crm','category'=>'parties','sort'=>40],
            'crm.opportunity'=>['title'=>'فرصت فروش','group'=>'فرصت‌های فروش','icon'=>'🎯','permission'=>'crm.view','page'=>'crm','category'=>'parties','sort'=>50],
            'crm.activity'=>['title'=>'فعالیت CRM','group'=>'فعالیت‌ها و پیگیری‌ها','icon'=>'📌','permission'=>'crm.view','page'=>'crm','category'=>'parties','sort'=>60],
            'sales.document'=>['title'=>'سند فروش','group'=>'اسناد فروش','icon'=>'🧾','permission'=>'accounting.view','page'=>'industrial','category'=>'commerce','sort'=>70],
            'delivery'=>['title'=>'تحویل فروش','group'=>'تحویل‌ها','icon'=>'📤','permission'=>'accounting.view','page'=>'industrial','category'=>'commerce','sort'=>80],
            'purchase.document'=>['title'=>'سند خرید','group'=>'اسناد خرید','icon'=>'📥','permission'=>'procurement.view','page'=>'procurement','category'=>'commerce','sort'=>90],
            'trade.case'=>['title'=>'پرونده بازرگانی','group'=>'پرونده‌های بازرگانی','icon'=>'🌐','permission'=>'trade.view','page'=>'trade','category'=>'trade','sort'=>100],
            'shipment'=>['title'=>'محموله','group'=>'محموله‌ها','icon'=>'🚚','permission'=>'trade.view','page'=>'trade','category'=>'trade','sort'=>110],
            'item'=>['title'=>'کالا / خدمت','group'=>'کالاها و خدمات','icon'=>'📦','permission'=>'inventory.view','page'=>'inventory','category'=>'inventory','sort'=>120],
            'warehouse'=>['title'=>'انبار','group'=>'انبارها','icon'=>'🏬','permission'=>'inventory.view','page'=>'inventory','category'=>'inventory','sort'=>130],
            'inventory.receipt'=>['title'=>'رسید انبار','group'=>'رسیدهای انبار','icon'=>'📦','permission'=>'inventory.view','page'=>'inventory','category'=>'inventory','sort'=>140],
            'finance.voucher'=>['title'=>'سند حسابداری','group'=>'اسناد حسابداری','icon'=>'📒','permission'=>'accounting.view','page'=>'industrial','category'=>'finance','sort'=>150],
            'cash.account'=>['title'=>'بانک / صندوق','group'=>'بانک و صندوق','icon'=>'🏦','permission'=>'accounting.view','page'=>'industrial','category'=>'finance','sort'=>160],
            'check'=>['title'=>'چک','group'=>'چک‌ها','icon'=>'💳','permission'=>'accounting.view','page'=>'industrial','category'=>'finance','sort'=>170],
        ];
    }

    public static function supportedTypes(): array { return array_keys(self::definitions()); }

    public static function catalog(): array
    {
        $categories=self::categories();$defs=self::definitions();$out=[];
        foreach($categories as $key=>$cat)$out[$key]=['key'=>$key,'title'=>$cat['title'],'icon'=>$cat['icon'],'sort'=>$cat['sort'],'types'=>[]];
        foreach($defs as $type=>$def){
            try{self::assertAccess($def);}catch(Throwable $e){continue;}
            $cat=(string)$def['category'];if(!isset($out[$cat]))continue;
            $out[$cat]['types'][]=['type'=>$type,'title'=>$def['title'],'group'=>$def['group'],'icon'=>$def['icon'],'sort'=>$def['sort']];
        }
        foreach($out as &$cat)usort($cat['types'],fn($a,$b)=>(int)$a['sort']<=>(int)$b['sort']);unset($cat);
        return array_values(array_filter($out,fn($cat)=>!empty($cat['types'])));
    }

    private static function company(int $wid,int $cid): array
    {
        if($wid<=0||$cid<=0)throw new RuntimeException('copilot_company_required');
        $st=pdo()->prepare("SELECT id,name,company_type,legal_personality,national_id,economic_code FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);$r=$st->fetch();if(!$r)throw new RuntimeException('copilot_company_not_found');return$r;
    }

    private static function workspaceCompanies(int $wid,string $query=''): array
    {
        if($wid<=0)throw new RuntimeException('copilot_company_required');
        $sql="SELECT id,name,company_type,legal_personality,national_id,economic_code FROM companies WHERE workspace_id=? AND active=1";$args=[$wid];
        if($query!==''){$like='%'.$query.'%';$sql.=" AND (name LIKE ? OR national_id LIKE ? OR economic_code LIKE ?)";array_push($args,$like,$like,$like);}
        $sql.=" ORDER BY name,id LIMIT 60";$st=pdo()->prepare($sql);$st->execute($args);return$st->fetchAll();
    }

    private static function definition(string $type): array
    {
        $defs=self::definitions();if(!isset($defs[$type]))throw new RuntimeException('copilot_entity_type_unsupported');return$defs[$type];
    }

    private static function assertAccess(array $def): void
    {
        $permission=(string)($def['permission']??'');
        if($permission==='')return;
        if(!ModuleRegistry::pageEnabled((string)$def['page'])||!Tenant::can($permission))throw new RuntimeException('copilot_entity_forbidden');
    }

    private static function filteredDefinitions(array $types=[]): array
    {
        $defs=self::definitions();if(!$types)return$defs;$allowed=[];
        foreach($types as $t)if(is_string($t)&&isset($defs[$t]))$allowed[$t]=true;
        return array_intersect_key($defs,$allowed);
    }

    private static function entity(string $type,int $id,string $code,string $label,string $subtitle,array $def,int $cid,string $companyName,array $extra=[]): array
    {
        $categories=self::categories();$catKey=(string)$def['category'];$cat=$categories[$catKey]??['title'=>'سایر','icon'=>'•'];
        return array_merge([
            'type'=>$type,'id'=>$id,'code'=>$code,'label'=>$label,'subtitle'=>$subtitle,
            'icon'=>(string)$def['icon'],'group'=>(string)$def['group'],'type_title'=>(string)$def['title'],
            'category'=>$catKey,'category_title'=>(string)$cat['title'],'category_icon'=>(string)$cat['icon'],
            'source_page'=>(string)$def['page'],'company_id'=>$cid,'company_name'=>$companyName,
        ],$extra);
    }

    private static function companyEntity(array $row,array $def,bool $withCanonical=false): array
    {
        $id=(int)$row['id'];$name=(string)$row['name'];$subtitle=implode(' • ',array_values(array_filter([(string)($row['company_type']??''),(string)($row['economic_code']??'')],fn($v)=>$v!=='')));$extra=$withCanonical?['canonical'=>$row]:[];
        return self::entity('company',$id,(string)($row['economic_code']??''),$name,$subtitle,$def,$id,$name,$extra);
    }

    private static function textSlice(string $value,int $limit): string
    {
        if($limit<=0)return'';
        return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
    }

    public static function searchWorkspaceDetailed(int $wid,int $preferredCid,string $query='',array $types=[]): array
    {
        $query=self::textSlice(trim($query),120);$defs=self::filteredDefinitions($types);$companies=self::workspaceCompanies($wid);
        if($preferredCid>0)self::company($wid,$preferredCid);
        usort($companies,function($a,$b)use($preferredCid){
            $ap=(int)$a['id']===$preferredCid?0:1;$bp=(int)$b['id']===$preferredCid?0:1;
            return $ap!==$bp?$ap<=>$bp:strcmp((string)$a['name'],(string)$b['name']);
        });
        $out=[];$failed=[];$perCompany=$query===''?2:3;
        foreach($defs as $type=>$def){
            try{self::assertAccess($def);}catch(Throwable $e){continue;}
            $typeRows=[];
            if($type==='company'){
                foreach(array_slice(self::workspaceCompanies($wid,$query),0,self::SEARCH_PER_TYPE) as $r)$typeRows[]=self::companyEntity($r,$def);
            }else{
                foreach($companies as $company){
                    $cid=(int)$company['id'];$name=(string)$company['name'];
                    try{$rows=self::searchType($wid,$cid,$type,$query,$def,$name);}
                    catch(Throwable $e){$failed[$type.':'.$cid]=true;error_log('[ERPSMART Copilot] workspace entity search provider failed: '.$type.' company '.$cid);continue;}
                    foreach(array_slice($rows,0,$perCompany) as $r){$typeRows[]=$r;if(count($typeRows)>=self::SEARCH_PER_TYPE)break 2;}
                }
            }
            foreach($typeRows as $r){$out[]=$r;if(count($out)>=self::SEARCH_TOTAL)break 2;}
        }
        return ['results'=>$out,'catalog'=>self::catalog(),'query'=>$query,'scope'=>'workspace','degraded'=>!empty($failed),'failed_provider_count'=>count($failed)];
    }

    public static function searchDetailed(int $wid,int $cid,string $query='',array $types=[]): array
    {
        $company=self::company($wid,$cid);$query=self::textSlice(trim($query),120);$defs=self::filteredDefinitions($types);$out=[];$failedProviders=0;
        foreach($defs as $type=>$def){
            try{self::assertAccess($def);}catch(Throwable $e){continue;}
            try{
                $rows=$type==='company'?[self::companyEntity($company,$def)]:self::searchType($wid,$cid,$type,$query,$def,(string)$company['name']);
                if($type==='company'&&$query!==''&&!self::companyMatches($company,$query))$rows=[];
            }catch(Throwable $e){$failedProviders++;error_log('[ERPSMART Copilot] entity search provider failed: '.$type);continue;}
            foreach(array_slice($rows,0,self::SEARCH_PER_TYPE) as $r){$out[]=$r;if(count($out)>=self::SEARCH_TOTAL)break 2;}
        }
        return ['results'=>$out,'catalog'=>self::catalog(),'query'=>$query,'scope'=>'company','degraded'=>$failedProviders>0,'failed_provider_count'=>$failedProviders];
    }

    private static function companyMatches(array $company,string $query): bool
    {
        $needle=self::lower($query);if($needle==='')return true;
        foreach(['name','national_id','economic_code'] as $key)if(str_contains(self::lower((string)($company[$key]??'')),$needle))return true;
        return false;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    public static function search(int $wid,int $cid,string $query='',array $types=[]): array
    {
        return (array)(self::searchDetailed($wid,$cid,$query,$types)['results']??[]);
    }

    private static function searchType(int $wid,int $cid,string $type,string $query,array $def,string $companyName): array
    {
        $like='%'.$query.'%';$rows=[];
        if($type==='party.customer'){
            $rows=CrmDomain::searchCustomers($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['mobile']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='party.supplier'){
            $sql="SELECT id,code,name,mobile FROM acc_parties WHERE workspace_id=? AND company_id=? AND active=1 AND party_type IN ('supplier','both')";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (name LIKE ? OR code LIKE ? OR mobile LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY name LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['mobile']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='item'){
            $sql="SELECT id,code,name,item_type,barcode FROM acc_items WHERE workspace_id=? AND company_id=? AND active=1";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (name LIKE ? OR code LIKE ? OR barcode LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY name LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],(string)($r['item_type']??''),$def,$cid,$companyName),$rows);
        }
        if($type==='contact'){
            $sql="SELECT c.id,c.full_name,c.job_title,c.mobile,c.email,c.party_id,p.name party_name FROM crm_party_contacts c JOIN acc_parties p ON p.id=c.party_id AND p.workspace_id=c.workspace_id AND p.company_id=c.company_id WHERE c.workspace_id=? AND c.company_id=? AND c.active=1";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (c.full_name LIKE ? OR c.job_title LIKE ? OR c.mobile LIKE ? OR c.email LIKE ? OR p.name LIKE ?)";array_push($args,$like,$like,$like,$like,$like);} $sql.=" ORDER BY c.is_primary DESC,c.full_name LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],'CONTACT-'.(int)$r['id'],(string)$r['full_name'],trim((string)($r['party_name']??'').' • '.(string)($r['job_title']??'').' • '.(string)($r['mobile']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='crm.opportunity'){
            $sql="SELECT o.id,o.opportunity_no,o.party_id,o.title,o.stage,o.status,o.amount_irr,o.probability,o.expected_close_date,p.name party_name FROM crm_opportunities o JOIN acc_parties p ON p.id=o.party_id AND p.workspace_id=o.workspace_id AND p.company_id=o.company_id WHERE o.workspace_id=? AND o.company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (o.opportunity_no LIKE ? OR o.title LIKE ? OR p.name LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY FIELD(o.status,'open','won','lost'),o.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['opportunity_no'],(string)$r['title'],trim((string)($r['party_name']??'').' • '.(string)($r['stage']??'').' • '.number_format((float)($r['amount_irr']??0)).' ریال'),$def,$cid,$companyName),$rows);
        }
        if($type==='crm.activity'){
            $sql="SELECT a.id,a.activity_no,a.party_id,a.opportunity_id,a.activity_type,a.subject,a.activity_date,a.due_date,a.status,p.name party_name FROM crm_activities a JOIN acc_parties p ON p.id=a.party_id AND p.workspace_id=a.workspace_id AND p.company_id=a.company_id WHERE a.workspace_id=? AND a.company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (a.activity_no LIKE ? OR a.subject LIKE ? OR p.name LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY a.activity_date DESC,a.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['activity_no'],(string)$r['subject'],trim((string)($r['party_name']??'').' • '.(string)($r['activity_type']??'').' • '.(string)($r['status']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='sales.document'){
            $rows=SalesDomain::searchDocuments($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['document_no'],(string)$r['document_no'],trim((string)($r['party_name']??'').' • '.(string)($r['workflow_status']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='delivery'){
            $sql="SELECT d.id,d.delivery_no,d.delivery_date,d.sales_doc_id,d.warehouse_id,d.status,s.document_no,p.name party_name,w.name warehouse_name FROM acc_sales_deliveries d JOIN acc_sales_docs s ON s.id=d.sales_doc_id AND s.workspace_id=d.workspace_id AND s.company_id=d.company_id LEFT JOIN acc_parties p ON p.id=s.party_id AND p.workspace_id=s.workspace_id AND p.company_id=s.company_id LEFT JOIN acc_warehouses w ON w.id=d.warehouse_id AND w.workspace_id=d.workspace_id WHERE d.workspace_id=? AND d.company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (d.delivery_no LIKE ? OR s.document_no LIKE ? OR p.name LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY d.delivery_date DESC,d.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['delivery_no'],(string)$r['delivery_no'],trim((string)($r['party_name']??'').' • '.(string)($r['document_no']??'').' • '.(string)($r['status']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='purchase.document'){
            $rows=InventoryDomain::searchPurchaseDocuments($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['document_no'],(string)$r['document_no'],trim((string)($r['supplier_name']??'').' • '.(string)($r['workflow_status']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='trade.case'){
            $rows=TradeDomain::searchCases($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['case_no'],(string)$r['case_no'],trim((string)($r['supplier_name']??'').' • '.(string)($r['status']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='shipment'){
            $sql="SELECT s.id,s.trade_case_id,s.shipment_no,s.status,s.eta,s.carrier,s.tracking_no,c.case_no FROM acc_trade_shipments s JOIN acc_trade_cases c ON c.id=s.trade_case_id AND c.workspace_id=s.workspace_id WHERE s.workspace_id=? AND s.company_id=? AND s.status<>'canceled'";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (s.shipment_no LIKE ? OR s.tracking_no LIKE ? OR c.case_no LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY s.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['shipment_no'],(string)$r['shipment_no'],trim((string)($r['case_no']??'').' • '.(string)($r['status']??'').' • ETA '.(string)($r['eta']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='warehouse'){
            $rows=InventoryDomain::searchWarehouses($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['warehouse_type']??'').' • '.(string)($r['address']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='inventory.receipt'){
            $sql="SELECT r.id,r.receipt_no,r.receipt_date,r.status,r.purchase_doc_id,r.warehouse_id,d.document_no,p.name supplier_name,w.name warehouse_name FROM acc_inventory_receipts r LEFT JOIN acc_purchase_docs d ON d.id=r.purchase_doc_id AND d.workspace_id=r.workspace_id AND d.company_id=r.company_id LEFT JOIN acc_parties p ON p.id=r.supplier_id AND p.workspace_id=r.workspace_id AND p.company_id=r.company_id LEFT JOIN acc_warehouses w ON w.id=r.warehouse_id AND w.workspace_id=r.workspace_id WHERE r.workspace_id=? AND r.company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (r.receipt_no LIKE ? OR d.document_no LIKE ? OR p.name LIKE ? OR w.name LIKE ?)";array_push($args,$like,$like,$like,$like);} $sql.=" ORDER BY r.receipt_date DESC,r.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['receipt_no'],(string)$r['receipt_no'],trim((string)($r['supplier_name']??'').' • '.(string)($r['document_no']??'').' • '.(string)($r['status']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='finance.voucher'){
            $sql="SELECT id,voucher_no,voucher_date,status,description FROM acc_vouchers WHERE workspace_id=? AND company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (voucher_no LIKE ? OR description LIKE ?)";array_push($args,$like,$like);} $sql.=" ORDER BY voucher_date DESC,id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['voucher_no'],(string)$r['voucher_no'],trim((string)($r['status']??'').' • '.(string)($r['voucher_date']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='cash.account'){
            $sql="SELECT id,code,name,account_kind,bank_name,account_no,iban,opening_balance FROM acc_cash_accounts WHERE workspace_id=? AND company_id=? AND active=1";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (name LIKE ? OR code LIKE ? OR bank_name LIKE ? OR account_no LIKE ? OR iban LIKE ?)";array_push($args,$like,$like,$like,$like,$like);} $sql.=" ORDER BY name,id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['account_kind']??'').' • '.(string)($r['bank_name']??'').' • '.(string)($r['account_no']??'')),$def,$cid,$companyName),$rows);
        }
        if($type==='check'){
            $sql="SELECT c.id,c.check_no,c.direction,c.amount,c.due_date,c.status,c.party_id,c.cash_account_id,p.name party_name,a.name cash_name FROM acc_checks c LEFT JOIN acc_parties p ON p.id=c.party_id AND p.workspace_id=c.workspace_id AND p.company_id=c.company_id LEFT JOIN acc_cash_accounts a ON a.id=c.cash_account_id AND a.workspace_id=c.workspace_id AND a.company_id=c.company_id WHERE c.workspace_id=? AND c.company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (c.check_no LIKE ? OR p.name LIKE ? OR a.name LIKE ? OR c.notes LIKE ?)";array_push($args,$like,$like,$like,$like);} $sql.=" ORDER BY c.due_date,c.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['check_no'],(string)$r['check_no'],trim((string)($r['party_name']??'').' • '.(string)($r['status']??'').' • '.number_format((float)($r['amount']??0)).' ریال'),$def,$cid,$companyName),$rows);
        }
        return[];
    }

    public static function resolve(int $wid,int $cid,array $ref): array
    {
        $company=self::company($wid,$cid);$type=trim((string)($ref['type']??''));$id=(int)($ref['id']??0);if($id<=0)throw new RuntimeException('copilot_entity_id_invalid');
        $def=self::definition($type);self::assertAccess($def);$st=null;$row=false;$companyName=(string)$company['name'];
        if($type==='company'){
            if($id!==$cid)throw new RuntimeException('copilot_context_company_mismatch');
            return self::companyEntity($company,$def,true);
        }
        if($type==='party.customer'||$type==='party.supplier'){
            $partyTypes=$type==='party.customer'?["customer","both"]:["supplier","both"];$ph=implode(',',array_fill(0,count($partyTypes),'?'));
            $st=pdo()->prepare("SELECT id,code,name,party_type,mobile,phone,email,credit_limit FROM acc_parties WHERE workspace_id=? AND company_id=? AND id=? AND active=1 AND party_type IN ($ph) LIMIT 1");$st->execute(array_merge([$wid,$cid,$id],$partyTypes));$row=$st->fetch();
            if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],trim((string)($row['party_type']??'').' • '.(string)($row['mobile']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='contact'){
            $st=pdo()->prepare("SELECT c.id,c.full_name,c.job_title,c.mobile,c.phone,c.email,c.party_id,c.is_primary,c.notes,p.name party_name FROM crm_party_contacts c JOIN acc_parties p ON p.id=c.party_id AND p.workspace_id=c.workspace_id AND p.company_id=c.company_id WHERE c.workspace_id=? AND c.company_id=? AND c.id=? AND c.active=1 LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,'CONTACT-'.$id,(string)$row['full_name'],trim((string)($row['party_name']??'').' • '.(string)($row['job_title']??'').' • '.(string)($row['mobile']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='crm.opportunity'){
            $st=pdo()->prepare("SELECT o.*,p.name party_name FROM crm_opportunities o JOIN acc_parties p ON p.id=o.party_id AND p.workspace_id=o.workspace_id AND p.company_id=o.company_id WHERE o.workspace_id=? AND o.company_id=? AND o.id=? LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['opportunity_no'],(string)$row['title'],trim((string)($row['party_name']??'').' • '.(string)($row['stage']??'').' • '.(string)($row['status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='crm.activity'){
            $st=pdo()->prepare("SELECT a.*,p.name party_name FROM crm_activities a JOIN acc_parties p ON p.id=a.party_id AND p.workspace_id=a.workspace_id AND p.company_id=a.company_id WHERE a.workspace_id=? AND a.company_id=? AND a.id=? LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['activity_no'],(string)$row['subject'],trim((string)($row['party_name']??'').' • '.(string)($row['activity_type']??'').' • '.(string)($row['status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='item'){
            $st=pdo()->prepare("SELECT id,code,name,item_type,barcode,min_stock,max_stock FROM acc_items WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],(string)($row['item_type']??''),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='sales.document'){
            $row=SalesDomain::document($wid,$cid,$id);if($row)return self::entity($type,$id,(string)$row['document_no'],(string)$row['document_no'],trim((string)($row['party_name']??'').' • '.(string)($row['workflow_status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='delivery'){
            $st=pdo()->prepare("SELECT d.*,s.document_no,s.party_id,p.name party_name,w.name warehouse_name FROM acc_sales_deliveries d JOIN acc_sales_docs s ON s.id=d.sales_doc_id AND s.workspace_id=d.workspace_id AND s.company_id=d.company_id LEFT JOIN acc_parties p ON p.id=s.party_id AND p.workspace_id=s.workspace_id AND p.company_id=s.company_id LEFT JOIN acc_warehouses w ON w.id=d.warehouse_id AND w.workspace_id=d.workspace_id WHERE d.workspace_id=? AND d.company_id=? AND d.id=? LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['delivery_no'],(string)$row['delivery_no'],trim((string)($row['party_name']??'').' • '.(string)($row['document_no']??'').' • '.(string)($row['status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='purchase.document'){
            $row=InventoryDomain::purchaseDocument($wid,$cid,$id);if($row)return self::entity($type,$id,(string)$row['document_no'],(string)$row['document_no'],trim((string)($row['supplier_name']??'').' • '.(string)($row['workflow_status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='trade.case'){
            $row=TradeDomain::caseRow($wid,$cid,$id);if($row)return self::entity($type,$id,(string)$row['case_no'],(string)$row['case_no'],trim((string)($row['supplier_name']??'').' • '.(string)($row['status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='shipment'){
            $st=pdo()->prepare("SELECT s.*,c.case_no FROM acc_trade_shipments s JOIN acc_trade_cases c ON c.id=s.trade_case_id AND c.workspace_id=s.workspace_id WHERE s.workspace_id=? AND s.company_id=? AND s.id=? AND s.status<>'canceled' LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['shipment_no'],(string)$row['shipment_no'],trim((string)($row['case_no']??'').' • '.(string)($row['status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='warehouse'){
            $st=pdo()->prepare("SELECT id,code,name,warehouse_type,address FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],(string)($row['warehouse_type']??''),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='inventory.receipt'){
            $st=pdo()->prepare("SELECT r.*,d.document_no,p.name supplier_name,w.name warehouse_name FROM acc_inventory_receipts r LEFT JOIN acc_purchase_docs d ON d.id=r.purchase_doc_id AND d.workspace_id=r.workspace_id AND d.company_id=r.company_id LEFT JOIN acc_parties p ON p.id=r.supplier_id AND p.workspace_id=r.workspace_id AND p.company_id=r.company_id LEFT JOIN acc_warehouses w ON w.id=r.warehouse_id AND w.workspace_id=r.workspace_id WHERE r.workspace_id=? AND r.company_id=? AND r.id=? LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['receipt_no'],(string)$row['receipt_no'],trim((string)($row['supplier_name']??'').' • '.(string)($row['document_no']??'').' • '.(string)($row['status']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='finance.voucher'){
            $st=pdo()->prepare("SELECT id,voucher_no,voucher_date,status,description,total_debit,total_credit FROM acc_vouchers WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['voucher_no'],(string)$row['voucher_no'],trim((string)($row['status']??'').' • '.(string)($row['voucher_date']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='cash.account'){
            $st=pdo()->prepare("SELECT id,code,name,account_kind,bank_name,account_no,iban,opening_balance FROM acc_cash_accounts WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],trim((string)($row['account_kind']??'').' • '.(string)($row['bank_name']??'').' • '.(string)($row['account_no']??'')),$def,$cid,$companyName,['canonical'=>$row]);
        }elseif($type==='check'){
            $st=pdo()->prepare("SELECT c.*,p.name party_name,a.name cash_name FROM acc_checks c LEFT JOIN acc_parties p ON p.id=c.party_id AND p.workspace_id=c.workspace_id AND p.company_id=c.company_id LEFT JOIN acc_cash_accounts a ON a.id=c.cash_account_id AND a.workspace_id=c.workspace_id AND a.company_id=c.company_id WHERE c.workspace_id=? AND c.company_id=? AND c.id=? LIMIT 1");
            $st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['check_no'],(string)$row['check_no'],trim((string)($row['party_name']??'').' • '.(string)($row['status']??'').' • '.number_format((float)($row['amount']??0)).' ریال'),$def,$cid,$companyName,['canonical'=>$row]);
        }
        throw new RuntimeException('copilot_entity_not_found');
    }

    public static function preview(int $wid,int $cid,array $ref): array
    {
        $e=self::resolve($wid,$cid,$ref);$type=(string)$e['type'];$row=(array)($e['canonical']??[]);$facts=[];
        if($type==='company'){
            foreach([['نوع شرکت',$row['company_type']??''],['شناسه ملی',$row['national_id']??''],['کد اقتصادی',$row['economic_code']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
            $counts=[];
            foreach([
                ['مشتری/تأمین‌کننده','acc_parties','active=1'],['کالا/خدمت','acc_items','active=1'],['اسناد فروش','acc_sales_docs','1=1'],['پرونده بازرگانی','acc_trade_cases','1=1']
            ] as $cfg){
                [$label,$table,$extra]=$cfg;$st=pdo()->prepare("SELECT COUNT(*) FROM `$table` WHERE workspace_id=? AND company_id=? AND $extra");$st->execute([$wid,$cid]);$counts[]=['label'=>$label,'value'=>(string)(int)$st->fetchColumn()];
            }
            $facts=array_merge($facts,$counts);
        }elseif($type==='party.customer'){
            $d=CrmDomain::customer360($wid,$cid,(int)$e['id']);$f=(array)$d['financial'];$c=(array)$d['crm'];
            $facts=[['label'=>'مانده','value'=>number_format((float)$f['current_balance_irr']).' ریال'],['label'=>'فروش','value'=>number_format((float)$f['recorded_sales_net_irr']).' ریال'],['label'=>'تحویل‌نشده','value'=>(string)$f['outstanding_sales_quantity']],['label'=>'Pipeline باز','value'=>number_format((float)$c['open_pipeline_irr']).' ریال']];
        }elseif($type==='contact'){
            foreach([['طرف حساب',$row['party_name']??''],['سمت',$row['job_title']??''],['موبایل',$row['mobile']??''],['ایمیل',$row['email']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='crm.opportunity'){
            foreach([['مشتری',$row['party_name']??''],['مرحله',$row['stage']??''],['وضعیت',$row['status']??''],['مبلغ',number_format((float)($row['amount_irr']??0)).' ریال'],['احتمال',number_format((float)($row['probability']??0),1).'%'],['بستن مورد انتظار',$row['expected_close_date']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='crm.activity'){
            foreach([['طرف حساب',$row['party_name']??''],['نوع',$row['activity_type']??''],['وضعیت',$row['status']??''],['تاریخ',$row['activity_date']??''],['سررسید',$row['due_date']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='delivery'){
            foreach([['مشتری',$row['party_name']??''],['سند فروش',$row['document_no']??''],['تاریخ',$row['delivery_date']??''],['انبار',$row['warehouse_name']??''],['وضعیت',$row['status']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='inventory.receipt'){
            foreach([['تأمین‌کننده',$row['supplier_name']??''],['سند خرید',$row['document_no']??''],['تاریخ',$row['receipt_date']??''],['انبار',$row['warehouse_name']??''],['وضعیت',$row['status']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='cash.account'){
            foreach([['نوع',$row['account_kind']??''],['بانک',$row['bank_name']??''],['شماره حساب',$row['account_no']??''],['شبا',$row['iban']??''],['مانده افتتاحیه',number_format((float)($row['opening_balance']??0)).' ریال']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='check'){
            foreach([['طرف حساب',$row['party_name']??''],['جهت',$row['direction']??''],['مبلغ',number_format((float)($row['amount']??0)).' ریال'],['سررسید',$row['due_date']??''],['وضعیت',$row['status']??''],['بانک / صندوق',$row['cash_name']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='shipment'){
            foreach([['وضعیت',$row['status']??''],['ETA',$row['eta']??''],['حمل‌کننده',$row['carrier']??''],['رهگیری',$row['tracking_no']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='item'){
            $pos=InventoryDomain::inventoryPosition($wid,$cid,['item_id'=>(int)$e['id'],'limit'=>1]);$r=(array)($pos['rows'][0]??[]);foreach([['موجودی',$r['on_hand']??0],['رزرو',$r['reserved']??0],['قابل استفاده',$r['available']??0],['ورودی مورد انتظار',$r['expected_inbound']??0]] as $x)$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }else{
            foreach([['کد',$e['code']??''],['نوع',$e['subtitle']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }
        unset($e['canonical']);$e['facts']=$facts;$e['deep_link']=self::deepLink($type,(int)$e['id'],$cid,$row);return$e;
    }

    public static function deepLink(string $type,int $id,int $cid,array $row=[]): string
    {
        return match($type){
            'company'=>'index.php?page=industrial&company_id='.$cid,
            'party.customer'=>'index.php?page=crm&company_id='.$cid.'&party_id='.$id,
            'party.supplier'=>'index.php?page=procurement&company_id='.$cid,
            'contact'=>'index.php?page=crm&company_id='.$cid.'&party_id='.(int)($row['party_id']??0),
            'crm.opportunity'=>'index.php?page=crm&company_id='.$cid.'&party_id='.(int)($row['party_id']??0),
            'crm.activity'=>'index.php?page=crm&company_id='.$cid.'&party_id='.(int)($row['party_id']??0),
            'item'=>'index.php?page=inventory&company_id='.$cid,
            'sales.document'=>'index.php?page=industrial&company_id='.$cid.'&section=sales&view='.$id,
            'delivery'=>'index.php?page=industrial&company_id='.$cid.'&section=sales&view='.(int)($row['sales_doc_id']??0),
            'purchase.document'=>'index.php?page=procurement&company_id='.$cid,
            'trade.case'=>'index.php?page=trade&company_id='.$cid.'&case_id='.$id,
            'shipment'=>'index.php?page=trade&company_id='.$cid.'&case_id='.(int)($row['trade_case_id']??0),
            'warehouse'=>'index.php?page=inventory&company_id='.$cid,
            'inventory.receipt'=>'index.php?page=inventory&company_id='.$cid,
            'finance.voucher'=>'index.php?page=industrial&company_id='.$cid.'&section=vouchers&id='.$id,
            'cash.account'=>'index.php?page=industrial&company_id='.$cid.'&section=treasury',
            'check'=>'index.php?page=industrial&company_id='.$cid.'&section=treasury',
            default=>'index.php',
        };
    }
}
