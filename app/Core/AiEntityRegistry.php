<?php
final class AiEntityRegistry
{
    public const VERSION='v1';
    private const SEARCH_PER_TYPE=6;
    private const SEARCH_TOTAL=30;

    public static function definitions(): array
    {
        return [
            'party.customer'=>['title'=>'مشتری','group'=>'مشتریان','icon'=>'👤','permission'=>'crm.view','page'=>'crm'],
            'party.supplier'=>['title'=>'تأمین‌کننده','group'=>'تأمین‌کنندگان','icon'=>'🏭','permission'=>'procurement.view','page'=>'procurement'],
            'item'=>['title'=>'کالا / خدمت','group'=>'کالاها و خدمات','icon'=>'📦','permission'=>'inventory.view','page'=>'inventory'],
            'sales.document'=>['title'=>'سند فروش','group'=>'اسناد فروش','icon'=>'🧾','permission'=>'accounting.view','page'=>'industrial'],
            'purchase.document'=>['title'=>'سند خرید','group'=>'اسناد خرید','icon'=>'📥','permission'=>'procurement.view','page'=>'procurement'],
            'trade.case'=>['title'=>'پرونده بازرگانی','group'=>'پرونده‌های بازرگانی','icon'=>'🌐','permission'=>'trade.view','page'=>'trade'],
            'shipment'=>['title'=>'محموله','group'=>'محموله‌ها','icon'=>'🚚','permission'=>'trade.view','page'=>'trade'],
            'warehouse'=>['title'=>'انبار','group'=>'انبارها','icon'=>'🏬','permission'=>'inventory.view','page'=>'inventory'],
            'finance.voucher'=>['title'=>'سند حسابداری','group'=>'اسناد حسابداری','icon'=>'📒','permission'=>'accounting.view','page'=>'industrial'],
        ];
    }

    public static function supportedTypes(): array { return array_keys(self::definitions()); }

    private static function company(int $wid,int $cid): array
    {
        if($wid<=0||$cid<=0)throw new RuntimeException('copilot_company_required');
        $st=pdo()->prepare("SELECT id,name FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);$r=$st->fetch();if(!$r)throw new RuntimeException('copilot_company_not_found');return$r;
    }

    private static function definition(string $type): array
    {
        $defs=self::definitions();if(!isset($defs[$type]))throw new RuntimeException('copilot_entity_type_unsupported');return$defs[$type];
    }

    private static function assertAccess(array $def): void
    {
        if(!ModuleRegistry::pageEnabled((string)$def['page'])||!Tenant::can((string)$def['permission']))throw new RuntimeException('copilot_entity_forbidden');
    }

    private static function entity(string $type,int $id,string $code,string $label,string $subtitle,array $def,array $extra=[]): array
    {
        return array_merge([
            'type'=>$type,'id'=>$id,'code'=>$code,'label'=>$label,'subtitle'=>$subtitle,
            'icon'=>(string)$def['icon'],'group'=>(string)$def['group'],'source_page'=>(string)$def['page'],
        ],$extra);
    }

    private static function textSlice(string $value,int $limit): string
    {
        if($limit<=0)return'';
        return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit);
    }

    public static function searchDetailed(int $wid,int $cid,string $query='',array $types=[]): array
    {
        self::company($wid,$cid);$query=self::textSlice(trim($query),120);$defs=self::definitions();
        if($types){$allowed=[];foreach($types as $t)if(is_string($t)&&isset($defs[$t]))$allowed[$t]=true;$defs=array_intersect_key($defs,$allowed);}
        $out=[];$failedProviders=0;
        foreach($defs as $type=>$def){
            try{self::assertAccess($def);}catch(Throwable $e){continue;}
            try{$rows=self::searchType($wid,$cid,$type,$query,$def);}
            catch(Throwable $e){
                $failedProviders++;
                error_log('[ERPSMART Copilot] entity search provider failed: '.$type);
                continue;
            }
            foreach(array_slice($rows,0,self::SEARCH_PER_TYPE) as $r){$out[]=$r;if(count($out)>=self::SEARCH_TOTAL)break 2;}
        }
        return ['results'=>$out,'degraded'=>$failedProviders>0,'failed_provider_count'=>$failedProviders];
    }

    public static function search(int $wid,int $cid,string $query='',array $types=[]): array
    {
        return (array)(self::searchDetailed($wid,$cid,$query,$types)['results']??[]);
    }

    private static function searchType(int $wid,int $cid,string $type,string $query,array $def): array
    {
        $like='%'.$query.'%';$rows=[];
        if($type==='party.customer'){
            $rows=CrmDomain::searchCustomers($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['mobile']??'')),$def),$rows);
        }
        if($type==='party.supplier'){
            $sql="SELECT id,code,name,mobile FROM acc_parties WHERE workspace_id=? AND company_id=? AND active=1 AND party_type IN ('supplier','both')";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (name LIKE ? OR code LIKE ? OR mobile LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY name LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['mobile']??'')),$def),$rows);
        }
        if($type==='item'){
            $sql="SELECT id,code,name,item_type,barcode FROM acc_items WHERE workspace_id=? AND company_id=? AND active=1";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (name LIKE ? OR code LIKE ? OR barcode LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY name LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],(string)($r['item_type']??''),$def),$rows);
        }
        if($type==='sales.document'){
            $rows=SalesDomain::searchDocuments($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['document_no'],(string)$r['document_no'],trim((string)($r['party_name']??'').' • '.(string)($r['workflow_status']??'')),$def),$rows);
        }
        if($type==='purchase.document'){
            $rows=InventoryDomain::searchPurchaseDocuments($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['document_no'],(string)$r['document_no'],trim((string)($r['supplier_name']??'').' • '.(string)($r['workflow_status']??'')),$def),$rows);
        }
        if($type==='trade.case'){
            $rows=TradeDomain::searchCases($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['case_no'],(string)$r['case_no'],trim((string)($r['supplier_name']??'').' • '.(string)($r['status']??'')),$def),$rows);
        }
        if($type==='shipment'){
            $sql="SELECT s.id,s.shipment_no,s.status,s.eta,c.case_no FROM acc_trade_shipments s JOIN acc_trade_cases c ON c.id=s.trade_case_id AND c.workspace_id=s.workspace_id WHERE s.workspace_id=? AND s.company_id=? AND s.status<>'canceled'";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (s.shipment_no LIKE ? OR s.tracking_no LIKE ? OR c.case_no LIKE ?)";array_push($args,$like,$like,$like);} $sql.=" ORDER BY s.id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['shipment_no'],(string)$r['shipment_no'],trim((string)($r['case_no']??'').' • '.(string)($r['status']??'').' • ETA '.(string)($r['eta']??'')),$def),$rows);
        }
        if($type==='warehouse'){
            $rows=InventoryDomain::searchWarehouses($wid,$cid,$query);
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)($r['code']??''),(string)$r['name'],trim((string)($r['warehouse_type']??'').' • '.(string)($r['address']??'')),$def),$rows);
        }
        if($type==='finance.voucher'){
            $sql="SELECT id,voucher_no,voucher_date,status,description FROM acc_vouchers WHERE workspace_id=? AND company_id=?";$args=[$wid,$cid];
            if($query!==''){$sql.=" AND (voucher_no LIKE ? OR description LIKE ?)";array_push($args,$like,$like);} $sql.=" ORDER BY voucher_date DESC,id DESC LIMIT 30";
            $st=pdo()->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
            return array_map(fn($r)=>self::entity($type,(int)$r['id'],(string)$r['voucher_no'],(string)$r['voucher_no'],trim((string)($r['status']??'').' • '.(string)($r['voucher_date']??'')),$def),$rows);
        }
        return[];
    }

    public static function resolve(int $wid,int $cid,array $ref): array
    {
        self::company($wid,$cid);$type=trim((string)($ref['type']??''));$id=(int)($ref['id']??0);if($id<=0)throw new RuntimeException('copilot_entity_id_invalid');
        $def=self::definition($type);self::assertAccess($def);$st=null;$row=false;
        if($type==='party.customer'||$type==='party.supplier'){
            $partyTypes=$type==='party.customer'?["customer","both"]:["supplier","both"];$ph=implode(',',array_fill(0,count($partyTypes),'?'));
            $st=pdo()->prepare("SELECT id,code,name,party_type,mobile,phone,email,credit_limit FROM acc_parties WHERE workspace_id=? AND company_id=? AND id=? AND active=1 AND party_type IN ($ph) LIMIT 1");$st->execute(array_merge([$wid,$cid,$id],$partyTypes));$row=$st->fetch();
            if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],trim((string)($row['party_type']??'').' • '.(string)($row['mobile']??'')),$def,['canonical'=>$row]);
        }elseif($type==='item'){
            $st=pdo()->prepare("SELECT id,code,name,item_type,barcode,min_stock,max_stock FROM acc_items WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],(string)($row['item_type']??''),$def,['canonical'=>$row]);
        }elseif($type==='sales.document'){
            $row=SalesDomain::document($wid,$cid,$id);if($row)return self::entity($type,$id,(string)$row['document_no'],(string)$row['document_no'],trim((string)($row['party_name']??'').' • '.(string)($row['workflow_status']??'')),$def,['canonical'=>$row]);
        }elseif($type==='purchase.document'){
            $row=InventoryDomain::purchaseDocument($wid,$cid,$id);if($row)return self::entity($type,$id,(string)$row['document_no'],(string)$row['document_no'],trim((string)($row['supplier_name']??'').' • '.(string)($row['workflow_status']??'')),$def,['canonical'=>$row]);
        }elseif($type==='trade.case'){
            $row=TradeDomain::caseRow($wid,$cid,$id);if($row)return self::entity($type,$id,(string)$row['case_no'],(string)$row['case_no'],trim((string)($row['supplier_name']??'').' • '.(string)($row['status']??'')),$def,['canonical'=>$row]);
        }elseif($type==='shipment'){
            $st=pdo()->prepare("SELECT s.*,c.case_no FROM acc_trade_shipments s JOIN acc_trade_cases c ON c.id=s.trade_case_id AND c.workspace_id=s.workspace_id WHERE s.workspace_id=? AND s.company_id=? AND s.id=? AND s.status<>'canceled' LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['shipment_no'],(string)$row['shipment_no'],trim((string)($row['case_no']??'').' • '.(string)($row['status']??'')),$def,['canonical'=>$row]);
        }elseif($type==='warehouse'){
            $st=pdo()->prepare("SELECT id,code,name,warehouse_type,address FROM acc_warehouses WHERE workspace_id=? AND company_id=? AND id=? AND active=1 LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)($row['code']??''),(string)$row['name'],(string)($row['warehouse_type']??''),$def,['canonical'=>$row]);
        }elseif($type==='finance.voucher'){
            $st=pdo()->prepare("SELECT id,voucher_no,voucher_date,status,description,total_debit,total_credit FROM acc_vouchers WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");$st->execute([$wid,$cid,$id]);$row=$st->fetch();if($row)return self::entity($type,$id,(string)$row['voucher_no'],(string)$row['voucher_no'],trim((string)($row['status']??'').' • '.(string)($row['voucher_date']??'')),$def,['canonical'=>$row]);
        }
        throw new RuntimeException('copilot_entity_not_found');
    }

    public static function preview(int $wid,int $cid,array $ref): array
    {
        $e=self::resolve($wid,$cid,$ref);$type=(string)$e['type'];$row=(array)($e['canonical']??[]);$facts=[];
        if($type==='party.customer'){
            $d=CrmDomain::customer360($wid,$cid,(int)$e['id']);$f=(array)$d['financial'];$c=(array)$d['crm'];
            $facts=[['label'=>'مانده','value'=>number_format((float)$f['current_balance_irr']).' ریال'],['label'=>'فروش','value'=>number_format((float)$f['recorded_sales_net_irr']).' ریال'],['label'=>'تحویل‌نشده','value'=>(string)$f['outstanding_sales_quantity']],['label'=>'Pipeline باز','value'=>number_format((float)$c['open_pipeline_irr']).' ریال']];
        }elseif($type==='shipment'){
            foreach([['وضعیت',$row['status']??''],['ETA',$row['eta']??''],['حمل‌کننده',$row['carrier']??''],['رهگیری',$row['tracking_no']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }elseif($type==='item'){
            $pos=InventoryDomain::inventoryPosition($wid,$cid,['item_id'=>(int)$e['id'],'limit'=>1]);$r=(array)($pos['rows'][0]??[]);foreach([['موجودی', $r['on_hand']??0],['رزرو', $r['reserved']??0],['قابل استفاده',$r['available']??0],['ورودی مورد انتظار',$r['expected_inbound']??0]] as $x)$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }else{
            foreach([['کد',$e['code']??''],['نوع',$e['subtitle']??'']] as $x)if((string)$x[1]!=='')$facts[]=['label'=>$x[0],'value'=>(string)$x[1]];
        }
        unset($e['canonical']);$e['facts']=$facts;$e['deep_link']=self::deepLink($type,(int)$e['id'],$cid,$row);return$e;
    }

    public static function deepLink(string $type,int $id,int $cid,array $row=[]): string
    {
        return match($type){
            'party.customer'=>'index.php?page=crm&company_id='.$cid.'&party_id='.$id,
            'party.supplier'=>'index.php?page=procurement&company_id='.$cid,
            'item'=>'index.php?page=inventory&company_id='.$cid,
            'sales.document'=>'index.php?page=industrial&company_id='.$cid.'&section=sales',
            'purchase.document'=>'index.php?page=procurement&company_id='.$cid,
            'trade.case'=>'index.php?page=trade&company_id='.$cid.'&case_id='.$id,
            'shipment'=>'index.php?page=trade&company_id='.$cid.'&case_id='.(int)($row['trade_case_id']??0),
            'warehouse'=>'index.php?page=inventory&company_id='.$cid,
            'finance.voucher'=>'index.php?page=industrial&company_id='.$cid.'&section=vouchers&id='.$id,
            default=>'index.php',
        };
    }
}
