<?php
/**
 * Server-side accounting tools exposed to AI workers.
 * Rule: the model never receives database credentials and never runs arbitrary SQL.
 * Read tools execute immediately; mutating tools become proposals and are executed
 * only after a permitted human approves them in the web application.
 */
final class AiToolRegistry
{
    public const AI_ROUTE_CONTRACT_VERSION='erp-read-v86.1';
    public const AI_ROUTE_PLANNER_VERSION='adaptive-read-v1';

    public static function descriptors(): array
    {
        return [
            ['name'=>'company_snapshot','mode'=>'read','risk'=>'low','description'=>'خلاصه وضعیت شرکت شامل تعداد اسناد، فروش، خرید و مانده‌های کلیدی','parameters'=>['type'=>'object','properties'=>[]]],
            ['name'=>'financial_analysis_bundle','mode'=>'read','risk'=>'low','description'=>'بسته فشرده تحلیل مالی برای گزارش مدیریتی خواندنی','parameters'=>['type'=>'object','properties'=>[]]],
            ['name'=>'search_parties','mode'=>'read','risk'=>'low','description'=>'جستجوی مشتری یا تامین‌کننده بر اساس نام، کد، شناسه ملی یا موبایل','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],'required'=>['query']]],
            ['name'=>'search_items','mode'=>'read','risk'=>'low','description'=>'جستجوی کالا یا خدمت بر اساس نام، کد یا بارکد','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],'required'=>['query']]],
            ['name'=>'trial_balance','mode'=>'read','risk'=>'low','description'=>'تراز آزمایشی حساب‌ها برای شرکت فعال','parameters'=>['type'=>'object','properties'=>[]]],
            ['name'=>'party_ledger','mode'=>'read','risk'=>'low','description'=>'گردش و مانده یک طرف حساب بر اساس آرتیکل‌های حسابداری','parameters'=>['type'=>'object','properties'=>['party_id'=>['type'=>'integer']],'required'=>['party_id']]],
            ['name'=>'recent_sales','mode'=>'read','risk'=>'low','description'=>'آخرین صورتحساب‌های فروش','parameters'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer']]]],
            ['name'=>'recent_purchases','mode'=>'read','risk'=>'low','description'=>'آخرین اسناد خرید','parameters'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer']]]],
            ['name'=>'document_analytics','mode'=>'read','risk'=>'low','description'=>'گزارش پارامتریک امن فروش/خرید با بازه، دامنه معنایی وضعیت و فیلتر Entity','parameters'=>['type'=>'object','properties'=>[
                'kind'=>['type'=>'string','enum'=>['sales','purchases']],
                'period'=>['type'=>'string','enum'=>['all','current_jalali_month','previous_jalali_month','current_jalali_year','previous_jalali_year','rolling_jalali_months','custom','custom_jalali_month']],
                'months'=>['type'=>'integer'],
                'date_from'=>['type'=>'string'],'date_to'=>['type'=>'string'],
                'jalali_year'=>['type'=>'integer'],'jalali_month'=>['type'=>'integer'],
                'workflow_status'=>['type'=>'string','enum'=>['all','draft','approved','final']],
                'status_scope'=>['type'=>'string','enum'=>['all','confirmed','draft','approved','final']],
                'party_id'=>['type'=>'integer'],'item_id'=>['type'=>'integer'],
                'group_by'=>['type'=>'string','enum'=>['none','party','item','jalali_month','status']],
                'limit'=>['type'=>'integer']
            ],'required'=>['kind']]],
            ['name'=>'search_cash_accounts','mode'=>'read','risk'=>'low','description'=>'جستجوی حساب بانک/صندوق/کارتخوان شرکت فعال','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],'required'=>['query']]],
            ['name'=>'check_analytics','mode'=>'read','risk'=>'low','description'=>'گزارش امن چک‌های دریافتنی/پرداختنی با وضعیت و دامنه سررسید','parameters'=>['type'=>'object','properties'=>[
                'direction'=>['type'=>'string','enum'=>['all','receivable','payable']],
                'status'=>['type'=>'string','enum'=>['all','open','received','paid','bounced','canceled']],
                'due_scope'=>['type'=>'string','enum'=>['all','overdue','upcoming_7']],
                'limit'=>['type'=>'integer']
            ]]],
            ['name'=>'search_warehouses','mode'=>'read','risk'=>'low','description'=>'جستجو یا فهرست انبارهای فعال شرکت','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']]]],
            ['name'=>'search_purchase_documents','mode'=>'read','risk'=>'low','description'=>'جستجوی سفارش/فاکتور خرید کالایی برای اتصال به دریافت','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']]]],
            ['name'=>'purchase_pipeline','mode'=>'read','risk'=>'low','description'=>'جریان خرید و مقدار ورودی مورد انتظار بر اساس خرید منهای دریافت پذیرفته‌شده','parameters'=>['type'=>'object','properties'=>['purchase_doc_id'=>['type'=>'integer'],'warehouse_id'=>['type'=>'integer'],'open_only'=>['type'=>'boolean'],'limit'=>['type'=>'integer']]]],
            ['name'=>'inventory_position','mode'=>'read','risk'=>'low','description'=>'موجودی Grounded: on-hand، reserved، available و expected inbound','parameters'=>['type'=>'object','properties'=>['item_id'=>['type'=>'integer'],'warehouse_id'=>['type'=>'integer'],'limit'=>['type'=>'integer']]]],
            ['name'=>'replenishment_risk','mode'=>'read','risk'=>'low','description'=>'کالاهای زیر حداقل موجودی و مقدار پیشنهادی تأمین','parameters'=>['type'=>'object','properties'=>['warehouse_id'=>['type'=>'integer'],'limit'=>['type'=>'integer']]]],
            ['name'=>'create_warehouse_receipt','mode'=>'proposal','risk'=>'high','description'=>'آماده‌سازی رسید انبار از سند خرید؛ فقط پس از تأیید انسانی Post و Stock Movement ایجاد می‌شود','parameters'=>['type'=>'object','properties'=>[
                'purchase_doc_id'=>['type'=>'integer'],'warehouse_id'=>['type'=>'integer'],'receipt_date'=>['type'=>'string'],'notes'=>['type'=>'string'],
                'lines'=>['type'=>'array','items'=>['type'=>'object','properties'=>['purchase_line_id'=>['type'=>'integer'],'accepted_qty'=>['type'=>'number'],'rejected_qty'=>['type'=>'number'],'notes'=>['type'=>'string']],'required'=>['purchase_line_id','accepted_qty']]]
            ],'required'=>['purchase_doc_id','warehouse_id','lines']]],
            ['name'=>'search_trade_cases','mode'=>'read','risk'=>'low','description'=>'جستجوی پرونده بازرگانی بر اساس شماره پرونده، سند خرید، تامین‌کننده یا Proforma','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']]]],\n            ['name'=>'trade_case_snapshot','mode'=>'read','risk'=>'low','description'=>'نمای کامل Grounded پرونده بازرگانی، حمل، هزینه، گمرک و Landed Cost','parameters'=>['type'=>'object','properties'=>['case_id'=>['type'=>'integer']],'required'=>['case_id']]],\n            ['name'=>'landed_cost_summary','mode'=>'read','risk'=>'low','description'=>'محاسبه deterministic بهای تمام‌شده واردات و allocation به خطوط خرید/موجودی','parameters'=>['type'=>'object','properties'=>['case_id'=>['type'=>'integer']],'required'=>['case_id']]],\n            ['name'=>'trade_risk_summary','mode'=>'read','risk'=>'low','description'=>'ریسک حمل، ETA، گمرک و انحراف هزینه پرونده‌های بازرگانی','parameters'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer']]]],\n            ['name'=>'create_trade_case','mode'=>'proposal','risk'=>'medium','description'=>'آماده‌سازی پرونده بازرگانی متصل به سند خرید؛ فقط پس از تایید انسانی','parameters'=>['type'=>'object','properties'=>['purchase_doc_id'=>['type'=>'integer'],'proforma_no'=>['type'=>'string'],'proforma_date'=>['type'=>'string'],'origin_country'=>['type'=>'string'],'destination_country'=>['type'=>'string'],'incoterm'=>['type'=>'string'],'currency_code'=>['type'=>'string'],'fx_rate_to_irr'=>['type'=>'number'],'notes'=>['type'=>'string']],'required'=>['purchase_doc_id','incoterm','currency_code','fx_rate_to_irr']]],\n            ['name'=>'create_trade_shipment','mode'=>'proposal','risk'=>'medium','description'=>'آماده‌سازی Shipment برای پرونده بازرگانی؛ فقط پس از تایید انسانی','parameters'=>['type'=>'object','properties'=>['trade_case_id'=>['type'=>'integer'],'shipment_no'=>['type'=>'string'],'mode'=>['type'=>'string','enum'=>['sea','air','road','rail','courier']],'carrier'=>['type'=>'string'],'forwarder'=>['type'=>'string'],'tracking_no'=>['type'=>'string'],'origin_location'=>['type'=>'string'],'destination_location'=>['type'=>'string'],'etd'=>['type'=>'string'],'eta'=>['type'=>'string'],'ata'=>['type'=>'string'],'status'=>['type'=>'string'],'package_count'=>['type'=>'integer'],'gross_weight_kg'=>['type'=>'number'],'notes'=>['type'=>'string']],'required'=>['trade_case_id','mode']]],\n            ['name'=>'add_trade_cost','mode'=>'proposal','risk'=>'high','description'=>'ثبت هزینه برآوردی/واقعی بازرگانی موثر بر Landed Cost؛ فقط پس از تایید انسانی','parameters'=>['type'=>'object','properties'=>['trade_case_id'=>['type'=>'integer'],'shipment_id'=>['type'=>'integer'],'cost_type'=>['type'=>'string'],'basis'=>['type'=>'string','enum'=>['estimated','actual']],'amount'=>['type'=>'number'],'currency_code'=>['type'=>'string'],'fx_rate_to_irr'=>['type'=>'number'],'reference_no'=>['type'=>'string'],'notes'=>['type'=>'string']],'required'=>['trade_case_id','cost_type','basis','amount','currency_code','fx_rate_to_irr']]],\n            ['name'=>'create_sales_invoice_draft','mode'=>'proposal','risk'=>'medium','description'=>'آماده‌سازی پیش‌نویس فاکتور فروش؛ بدون تایید انسانی ثبت نهایی نمی‌شود','parameters'=>['type'=>'object','properties'=>[
                'party_id'=>['type'=>'integer'],'document_date'=>['type'=>'string'],'due_date'=>['type'=>'string'],'notes'=>['type'=>'string'],
                'lines'=>['type'=>'array','items'=>['type'=>'object','properties'=>['item_id'=>['type'=>'integer'],'quantity'=>['type'=>'number'],'unit_price'=>['type'=>'number'],'discount_amount'=>['type'=>'number'],'tax_percent'=>['type'=>'number'],'description'=>['type'=>'string']],'required'=>['item_id','quantity','unit_price']]]
            ],'required'=>['party_id','lines']]],
            ['name'=>'create_purchase_invoice_draft','mode'=>'proposal','risk'=>'medium','description'=>'آماده‌سازی پیش‌نویس فاکتور خرید؛ فقط پس از تایید انسانی اجرا می‌شود','parameters'=>['type'=>'object','properties'=>[
                'party_id'=>['type'=>'integer'],'doc_type'=>['type'=>'string','enum'=>['purchase_invoice_goods','purchase_invoice_service']],
                'document_date'=>['type'=>'string'],'notes'=>['type'=>'string'],
                'lines'=>['type'=>'array','items'=>['type'=>'object','properties'=>['item_id'=>['type'=>'integer'],'quantity'=>['type'=>'number'],'unit_price'=>['type'=>'number'],'discount_amount'=>['type'=>'number'],'description'=>['type'=>'string']],'required'=>['item_id','quantity','unit_price']]]
            ],'required'=>['party_id','lines']]],
            ['name'=>'create_check','mode'=>'proposal','risk'=>'high','description'=>'آماده‌سازی ثبت چک دریافتنی/پرداختنی؛ فقط پس از تایید انسانی ایجاد می‌شود','parameters'=>['type'=>'object','properties'=>[
                'direction'=>['type'=>'string','enum'=>['receivable','payable']], 'check_no'=>['type'=>'string'], 'amount'=>['type'=>'number'], 'due_date'=>['type'=>'string'],
                'party_id'=>['type'=>'integer'],'cash_account_id'=>['type'=>'integer'],'notes'=>['type'=>'string']
            ],'required'=>['direction','check_no','amount','due_date']]],
            ['name'=>'create_voucher_draft','mode'=>'proposal','risk'=>'high','description'=>'آماده‌سازی سند حسابداری بالانس به صورت پیش‌نویس','parameters'=>['type'=>'object','properties'=>[
                'voucher_date'=>['type'=>'string'],'description'=>['type'=>'string'],
                'lines'=>['type'=>'array','items'=>['type'=>'object','properties'=>['account_id'=>['type'=>'integer'],'party_id'=>['type'=>'integer'],'description'=>['type'=>'string'],'debit'=>['type'=>'number'],'credit'=>['type'=>'number']],'required'=>['account_id']]]
            ],'required'=>['lines']]],
        ];
    }

    public static function bootstrapContext(int $wid,?int $cid): array
    {
        $ctx=['workspace_id'=>$wid,'company_id'=>$cid,'today'=>date('Y-m-d'),'jalali_today'=>class_exists('Jalali')?Jalali::today():null,
            'ai_route_contract_version'=>self::AI_ROUTE_CONTRACT_VERSION];
        if($cid)$ctx['company']=self::companySnapshot($wid,$cid);
        $ctx['safety']=[
            'mutations'=>'proposal_only',
            'human_approval_required'=>true,
            'financial_posting'=>'never_direct',
            'instruction'=>'برای اطلاعات مالی عددی از ابزارهای خواندنی استفاده کن و برای تغییر داده فقط proposal بساز.'
        ];
        return $ctx;
    }

    public static function executeForWorker(array $job,string $tool,array $args,string $idempotencyKey=''): array
    {
        $wid=(int)$job['workspace_id'];$cid=$job['company_id']?(int)$job['company_id']:null;
        $d=self::descriptor($tool);if(!$d)throw new RuntimeException('tool_not_allowed');
        if(($d['mode']??'')==='proposal'){
            $id=self::storeProposal($wid,$cid,(int)$job['id'],$tool,$args,self::proposalSummary($tool,$args),$idempotencyKey);
            return ['proposal_id'=>$id,'status'=>'awaiting_human_approval','tool'=>$tool];
        }
        if(($d['mode']??'')==='internal'){
            return match($tool){
                'semantic_route_lookup'=>self::semanticRouteLookup($wid,$args),
                'semantic_route_remember'=>self::semanticRouteRemember($wid,$args),
                'semantic_route_feedback'=>self::semanticRouteFeedback($wid,$args),
                default=>throw new RuntimeException('internal_tool_not_implemented')
            };
        }
        if(!$cid)throw new RuntimeException('company_context_required');
        return match($tool){
            'company_snapshot'=>self::companySnapshot($wid,$cid),
            'financial_analysis_bundle'=>self::financialAnalysisBundle($wid,$cid),
            'search_parties'=>self::searchParties($wid,$cid,(string)($args['query']??'')),
            'search_items'=>self::searchItems($wid,$cid,(string)($args['query']??'')),
            'trial_balance'=>self::trialBalance($wid,$cid),
            'party_ledger'=>self::partyLedger($wid,$cid,(int)($args['party_id']??0)),
            'search_cash_accounts'=>self::searchCashAccounts($wid,$cid,(string)($args['query']??'')),
            'check_analytics'=>self::checkAnalytics($wid,$cid,$args),
            'search_warehouses'=>InventoryDomain::searchWarehouses($wid,$cid,(string)($args['query']??'')),
            'search_purchase_documents'=>InventoryDomain::searchPurchaseDocuments($wid,$cid,(string)($args['query']??'')),
            'purchase_pipeline'=>InventoryDomain::purchasePipeline($wid,$cid,$args),
            'inventory_position'=>InventoryDomain::inventoryPosition($wid,$cid,$args),
            'replenishment_risk'=>InventoryDomain::replenishmentRisk($wid,$cid,$args),
            'search_trade_cases'=>TradeDomain::searchCases($wid,$cid,(string)($args['query']??'')),
            'trade_case_snapshot'=>TradeDomain::caseSnapshot($wid,$cid,(int)($args['case_id']??0)),
            'landed_cost_summary'=>TradeDomain::landedCostSummary($wid,$cid,(int)($args['case_id']??0)),
            'trade_risk_summary'=>TradeDomain::riskSummary($wid,$cid,(int)($args['limit']??50)),
            'recent_sales'=>self::recentSales($wid,$cid,(int)($args['limit']??20)),
            'recent_purchases'=>self::recentPurchases($wid,$cid,(int)($args['limit']??20)),
            'document_analytics'=>self::documentAnalytics($wid,$cid,$args),
            default=>throw new RuntimeException('tool_not_implemented')
        };
    }

    private static function semanticRouteIdentity(array $args): array
    {
        $key=strtolower(trim((string)($args['route_key']??'')));
        $planner=trim((string)($args['planner_version']??''));
        $contract=trim((string)($args['contract_version']??''));
        if(!preg_match('/^[a-f0-9]{64}$/',$key))throw new RuntimeException('semantic_route_key_invalid');
        if(!preg_match('/^[A-Za-z0-9._-]{1,80}$/',$planner))throw new RuntimeException('semantic_route_planner_invalid');
        if(!preg_match('/^[A-Za-z0-9._-]{1,80}$/',$contract))throw new RuntimeException('semantic_route_contract_invalid');
        return [$key,$planner,$contract];
    }

    private static function validateSemanticPlan(array $plan): array
    {
        $allowedIntents=[
            'company_snapshot','sales_total','purchase_total','totals',
            'recent_sales','recent_purchases','recent_both','trial_balance',
            'party_search','party_ledger','item_search','document_analytics','compare_periods'
        ];
        $allowedKeys=[
            'intent','query','limit','kind','period','months','date_from','date_to',
            'jalali_year','jalali_month','status_scope','group_by',
            'party_query','item_query','left_period','right_period','needs_entity_parse'
        ];
        foreach(array_keys($plan) as $k){
            if(!in_array((string)$k,$allowedKeys,true))throw new RuntimeException('semantic_plan_key_not_allowed');
            if(str_ends_with((string)$k,'_id'))throw new RuntimeException('semantic_plan_id_forbidden');
        }
        $intent=trim((string)($plan['intent']??''));
        if(!in_array($intent,$allowedIntents,true))throw new RuntimeException('semantic_plan_intent_invalid');
        $out=['intent'=>$intent];

        if(in_array($intent,['party_search','party_ledger','item_search'],true)){
            $q=trim((string)($plan['query']??''));
            if($q===''||mb_strlen($q)>190)throw new RuntimeException('semantic_plan_query_invalid');
            $out['query']=$q;$out['limit']=max(1,min(20,(int)($plan['limit']??5)));
            return $out;
        }
        if(in_array($intent,['recent_sales','recent_purchases','recent_both'],true)){
            $out['query']='';$out['limit']=max(1,min(20,(int)($plan['limit']??5)));return$out;
        }
        if(in_array($intent,['company_snapshot','sales_total','purchase_total','totals','trial_balance'],true)){
            return $out+['query'=>'','limit'=>5];
        }

        $kind=trim((string)($plan['kind']??''));
        if(!in_array($kind,['sales','purchases'],true))throw new RuntimeException('semantic_plan_kind_invalid');
        $out['kind']=$kind;
        $scope=trim((string)($plan['status_scope']??'all'));
        if(!in_array($scope,['all','confirmed','draft','approved','final'],true))throw new RuntimeException('semantic_plan_scope_invalid');
        $out['status_scope']=$scope;
        $period=trim((string)($plan['period']??'all'));
        $periods=['all','current_jalali_month','previous_jalali_month','current_jalali_year','previous_jalali_year','rolling_jalali_months','custom','custom_jalali_month'];
        if(!in_array($period,$periods,true))throw new RuntimeException('semantic_plan_period_invalid');
        $out['period']=$period;

        foreach(['party_query','item_query'] as $k){
            $q=trim((string)($plan[$k]??''));
            if(mb_strlen($q)>190)throw new RuntimeException('semantic_plan_entity_too_long');
            $out[$k]=$q;
        }

        if($period==='rolling_jalali_months')$out['months']=max(1,min(24,(int)($plan['months']??3)));
        if($period==='custom'){
            $a=trim((string)($plan['date_from']??''));$b=trim((string)($plan['date_to']??''));
            if($a===''||$b===''||mb_strlen($a)>20||mb_strlen($b)>20)throw new RuntimeException('semantic_plan_custom_dates_invalid');
            $out['date_from']=$a;$out['date_to']=$b;
        }
        if($period==='custom_jalali_month'){
            $y=(int)($plan['jalali_year']??0);$m=(int)($plan['jalali_month']??0);
            if($y<1300||$y>1499||$m<1||$m>12)throw new RuntimeException('semantic_plan_jalali_month_invalid');
            $out['jalali_year']=$y;$out['jalali_month']=$m;
        }

        if($intent==='document_analytics'){
            $group=trim((string)($plan['group_by']??'none'));
            if(!in_array($group,['none','party','item','jalali_month','status'],true))throw new RuntimeException('semantic_plan_group_invalid');
            $out['group_by']=$group;$out['limit']=max(1,min(50,(int)($plan['limit']??10)));
            $out['needs_entity_parse']=false;
            return$out;
        }

        $left=trim((string)($plan['left_period']??''));$right=trim((string)($plan['right_period']??''));
        if(!in_array($left,$periods,true)||!in_array($right,$periods,true)||$left==='all'||$right==='all')throw new RuntimeException('semantic_plan_compare_period_invalid');
        $out['left_period']=$left;$out['right_period']=$right;
        return$out;
    }

    private static function semanticRouteLookup(int $wid,array $args): array
    {
        [$key,$planner,$contract]=self::semanticRouteIdentity($args);
        if($contract!==self::AI_ROUTE_CONTRACT_VERSION)return ['hit'=>false,'reason'=>'contract_mismatch'];
        $st=pdo()->prepare("SELECT id,plan_json,confidence,hit_count,success_count,failure_count
            FROM ai_semantic_routes
            WHERE workspace_id=? AND route_key=? AND planner_version=? AND contract_version=? AND status='active'
            LIMIT 1");
        $st->execute([$wid,$key,$planner,$contract]);$r=$st->fetch();
        if(!$r)return ['hit'=>false];
        $plan=json_decode((string)$r['plan_json'],true);
        if(!is_array($plan)){
            pdo()->prepare("UPDATE ai_semantic_routes SET status='disabled',updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([(int)$r['id'],$wid]);
            return ['hit'=>false,'reason'=>'invalid_plan_json'];
        }
        try{$plan=self::validateSemanticPlan($plan);}
        catch(Throwable $e){
            pdo()->prepare("UPDATE ai_semantic_routes SET status='disabled',failure_count=failure_count+1,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([(int)$r['id'],$wid]);
            return ['hit'=>false,'reason'=>'invalid_plan'];
        }
        pdo()->prepare("UPDATE ai_semantic_routes SET hit_count=hit_count+1,last_used_at=NOW(),updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([(int)$r['id'],$wid]);
        return ['hit'=>true,'route_id'=>(int)$r['id'],'plan'=>$plan,'confidence'=>(float)$r['confidence'],
            'hit_count'=>(int)$r['hit_count']+1,'success_count'=>(int)$r['success_count'],'failure_count'=>(int)$r['failure_count']];
    }

    private static function semanticRouteRemember(int $wid,array $args): array
    {
        [$key,$planner,$contract]=self::semanticRouteIdentity($args);
        if($contract!==self::AI_ROUTE_CONTRACT_VERSION)throw new RuntimeException('semantic_route_contract_mismatch');
        $plan=$args['plan']??null;if(!is_array($plan))throw new RuntimeException('semantic_route_plan_required');
        $plan=self::validateSemanticPlan($plan);
        $source=trim((string)($args['source']??'llm_validated'));
        if(!in_array($source,['llm_validated','admin'],true))throw new RuntimeException('semantic_route_source_invalid');
        $confidence=max(0.50,min(0.99,(float)($args['confidence']??0.90)));
        $json=json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $st=pdo()->prepare("INSERT INTO ai_semantic_routes
            (workspace_id,route_key,planner_version,contract_version,plan_json,source,status,confidence,hit_count,success_count,failure_count,last_used_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,'active',?,0,1,0,NOW(),NOW(),NOW())
            ON DUPLICATE KEY UPDATE success_count=success_count+1,last_used_at=NOW(),updated_at=NOW()");
        $st->execute([$wid,$key,$planner,$contract,$json,$source,$confidence]);
        $q=pdo()->prepare("SELECT id,status,confidence,success_count,failure_count FROM ai_semantic_routes
            WHERE workspace_id=? AND route_key=? AND planner_version=? AND contract_version=? LIMIT 1");
        $q->execute([$wid,$key,$planner,$contract]);$r=$q->fetch()?:[];
        return ['stored'=>true,'route_id'=>(int)($r['id']??0),'status'=>(string)($r['status']??'active'),
            'confidence'=>(float)($r['confidence']??$confidence),'success_count'=>(int)($r['success_count']??1),'failure_count'=>(int)($r['failure_count']??0)];
    }

    private static function semanticRouteFeedback(int $wid,array $args): array
    {
        [$key,$planner,$contract]=self::semanticRouteIdentity($args);
        if($contract!==self::AI_ROUTE_CONTRACT_VERSION)return ['updated'=>false,'reason'=>'contract_mismatch'];
        $success=filter_var($args['success']??false,FILTER_VALIDATE_BOOLEAN);
        if($success){
            $st=pdo()->prepare("UPDATE ai_semantic_routes SET success_count=success_count+1,
                confidence=LEAST(0.99900,confidence+0.01000),last_used_at=NOW(),updated_at=NOW()
                WHERE workspace_id=? AND route_key=? AND planner_version=? AND contract_version=? AND status='active'");
        }else{
            $st=pdo()->prepare("UPDATE ai_semantic_routes SET failure_count=failure_count+1,
                confidence=GREATEST(0.00000,confidence-0.20000),
                status=IF(failure_count+1>=3 OR confidence-0.20000<0.50000,'disabled',status),
                last_used_at=NOW(),updated_at=NOW()
                WHERE workspace_id=? AND route_key=? AND planner_version=? AND contract_version=?");
        }
        $st->execute([$wid,$key,$planner,$contract]);
        return ['updated'=>$st->rowCount()>0,'success'=>$success];
    }

    public static function storeProposal(int $wid,?int $cid,int $jobId,string $tool,array $args,string $summary='',string $idempotencyKey=''): int
    {
        $d=self::descriptor($tool);if(!$d||($d['mode']??'')!=='proposal')throw new RuntimeException('proposal_tool_not_allowed');
        self::validateProposalArgs($wid,$cid,$tool,$args);
        $summary=trim($summary)?:self::proposalSummary($tool,$args);$idempotencyKey=trim($idempotencyKey);
        $key=$idempotencyKey!==''?mb_substr($idempotencyKey,0,190):null;
        $values=[$wid,$cid,$jobId,$tool,$key,json_encode($args,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),mb_substr($summary,0,500),(string)$d['risk']];
        if($idempotencyKey!==''){
            // Atomic under concurrent worker/API retries. LAST_INSERT_ID(id) makes
            // PDO return the existing Proposal id on the duplicate-key branch.
            $st=pdo()->prepare("INSERT INTO ai_action_proposals (workspace_id,company_id,job_id,tool_name,idempotency_key,arguments_json,summary,risk_level,requires_approval,status,proposed_at) VALUES (?,?,?,?,?,?,?,?,1,'proposed',NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $st->execute($values);return(int)pdo()->lastInsertId();
        }
        $st=pdo()->prepare("INSERT INTO ai_action_proposals (workspace_id,company_id,job_id,tool_name,idempotency_key,arguments_json,summary,risk_level,requires_approval,status,proposed_at) VALUES (?,?,?,?,?,?,?,?,1,'proposed',NOW())");
        $st->execute($values);
        return (int)pdo()->lastInsertId();
    }

    public static function executeProposal(array $proposal,int $userId): array
    {
        $args=json_decode((string)$proposal['arguments_json'],true);if(!is_array($args))throw new RuntimeException('پارامترهای عملیات نامعتبر است.');
        $wid=(int)$proposal['workspace_id'];$cid=$proposal['company_id']?(int)$proposal['company_id']:null;if(!$cid)throw new RuntimeException('شرکت عملیات مشخص نیست.');
        self::validateProposalArgs($wid,$cid,(string)$proposal['tool_name'],$args);
        return match($proposal['tool_name']){
            'create_sales_invoice_draft'=>self::createSalesDraft($wid,$cid,$userId,$args),
            'create_purchase_invoice_draft'=>self::createPurchaseDraft($wid,$cid,$userId,$args),
            'create_warehouse_receipt'=>InventoryDomain::createReceipt($wid,$cid,$userId,$args),
            'create_trade_case'=>TradeDomain::createCase($wid,$cid,$userId,$args),
            'create_trade_shipment'=>TradeDomain::createShipment($wid,$cid,$userId,$args),
            'add_trade_cost'=>TradeDomain::addCost($wid,$cid,$userId,$args),
            'create_check'=>self::createCheck($wid,$cid,$userId,$args),
            'create_voucher_draft'=>self::createVoucherDraft($wid,$cid,$userId,$args),
            default=>throw new RuntimeException('اجرای این Tool هنوز پیاده‌سازی نشده است.')
        };
    }

    private static function descriptor(string $name): ?array
    {
        foreach(self::descriptors() as $d)if($d['name']===$name)return$d;
        foreach(self::internalDescriptors() as $d)if($d['name']===$name)return$d;
        return null;
    }

    private static function internalDescriptors(): array
    {
        return [
            ['name'=>'semantic_route_lookup','mode'=>'internal','risk'=>'low'],
            ['name'=>'semantic_route_remember','mode'=>'internal','risk'=>'low'],
            ['name'=>'semantic_route_feedback','mode'=>'internal','risk'=>'low'],
        ];
    }

    private static function companySnapshot(int $wid,int $cid): array
    {
        self::assertCompany($wid,$cid);
        $st=pdo()->prepare("SELECT id,name,company_type,legal_personality,national_id,economic_code FROM companies WHERE workspace_id=? AND id=? LIMIT 1");$st->execute([$wid,$cid]);$company=$st->fetch();
        $counts=[];foreach(['acc_parties'=>'parties','acc_items'=>'items','acc_vouchers'=>'vouchers','acc_purchase_docs'=>'purchases','acc_sales_docs'=>'sales','acc_checks'=>'checks'] as $t=>$k){$q=pdo()->prepare("SELECT COUNT(*) FROM `$t` WHERE workspace_id=? AND company_id=?");$q->execute([$wid,$cid]);$counts[$k]=(int)$q->fetchColumn();}
        $sales=self::sum($wid,$cid,'acc_sales_docs','net_total');$purchases=self::sum($wid,$cid,'acc_purchase_docs','net_total');
        $q=pdo()->prepare("SELECT COALESCE(SUM(total_debit),0),COALESCE(SUM(total_credit),0) FROM acc_vouchers WHERE workspace_id=? AND company_id=? AND status IN ('approved','final')");$q->execute([$wid,$cid]);[$debit,$credit]=$q->fetch(PDO::FETCH_NUM)?:[0,0];
        return ['company'=>$company,'counts'=>$counts,'totals'=>['sales'=>(float)$sales,'purchases'=>(float)$purchases,'voucher_debit'=>(float)$debit,'voucher_credit'=>(float)$credit]];
    }

    private static function financialAnalysisBundle(int $wid,int $cid): array
    {
        $snapshot=self::companySnapshot($wid,$cid);
        $sales=self::recentSales($wid,$cid,4);
        $purchases=self::recentPurchases($wid,$cid,4);
        $trial=self::trialBalance($wid,$cid);

        $nonzero=array_values(array_filter($trial,static fn($r)=>
            abs((float)($r['debit']??0))>0.01 ||
            abs((float)($r['credit']??0))>0.01 ||
            abs((float)($r['balance']??0))>0.01
        ));
        usort($nonzero,static fn($a,$b)=>abs((float)($b['balance']??0))<=>abs((float)($a['balance']??0)));
        $top=array_slice($nonzero,0,8);

        $totalDebit=0.0;$totalCredit=0.0;
        foreach($trial as $row){$totalDebit+=(float)($row['debit']??0);$totalCredit+=(float)($row['credit']??0);}

        $salesRows=array_map(static fn($r)=>[
            $r['document_date']??null,$r['document_no']??null,$r['party_name']??null,
            (float)($r['net_total']??0),$r['workflow_status']??null
        ],$sales);
        $purchaseRows=array_map(static fn($r)=>[
            $r['document_date']??null,$r['document_no']??null,$r['party_name']??null,
            (float)($r['net_total']??0),$r['workflow_status']??null
        ],$purchases);
        $accountRows=array_map(static fn($r)=>[
            $r['code']??null,$r['name']??null,$r['account_type']??null,
            (float)($r['debit']??0),(float)($r['credit']??0),(float)($r['balance']??0)
        ],$top);

        return [
            'company'=>[
                'name'=>$snapshot['company']['name']??null,
                'counts'=>$snapshot['counts']??[],
                'totals'=>$snapshot['totals']??[],
            ],
            'sales'=>['fields'=>['date','no','party','net','status'],'rows'=>$salesRows],
            'purchases'=>['fields'=>['date','no','party','net','status'],'rows'=>$purchaseRows],
            'trial_balance'=>[
                'account_count'=>count($trial),
                'nonzero_count'=>count($nonzero),
                'total_debit'=>$totalDebit,
                'total_credit'=>$totalCredit,
                'difference'=>$totalDebit-$totalCredit,
                'fields'=>['code','name','type','debit','credit','balance'],
                'top_accounts'=>$accountRows,
            ],
        ];
    }

    private static function searchParties(int $wid,int $cid,string $query): array
    {
        $query=trim($query);if($query==='')return[];$like='%'.$query.'%';$st=pdo()->prepare("SELECT id,code,name,party_type,national_id,economic_code,mobile,credit_limit FROM acc_parties WHERE workspace_id=? AND company_id=? AND active=1 AND (name LIKE ? OR code LIKE ? OR national_id LIKE ? OR mobile LIKE ?) ORDER BY name LIMIT 30");$st->execute([$wid,$cid,$like,$like,$like,$like]);return$st->fetchAll();
    }

    private static function searchItems(int $wid,int $cid,string $query): array
    {
        $query=trim($query);if($query==='')return[];$like='%'.$query.'%';$st=pdo()->prepare("SELECT id,code,name,item_type,barcode,taxpayer_goods_id,purchase_price_1,min_stock,max_stock FROM acc_items WHERE workspace_id=? AND company_id=? AND active=1 AND (name LIKE ? OR code LIKE ? OR barcode LIKE ?) ORDER BY name LIMIT 30");$st->execute([$wid,$cid,$like,$like,$like]);return$st->fetchAll();
    }

    private static function trialBalance(int $wid,int $cid): array
    {
        $st=pdo()->prepare("SELECT a.id,a.code,a.name,a.account_type,COALESCE(SUM(CASE WHEN v.status IN ('approved','final') THEN l.debit ELSE 0 END),0) debit,COALESCE(SUM(CASE WHEN v.status IN ('approved','final') THEN l.credit ELSE 0 END),0) credit,COALESCE(SUM(CASE WHEN v.status IN ('approved','final') THEN l.debit-l.credit ELSE 0 END),0) balance FROM acc_accounts a LEFT JOIN acc_voucher_lines l ON l.account_id=a.id AND l.workspace_id=a.workspace_id LEFT JOIN acc_vouchers v ON v.id=l.voucher_id AND v.company_id=a.company_id AND v.workspace_id=a.workspace_id WHERE a.workspace_id=? AND a.company_id=? AND a.active=1 GROUP BY a.id,a.code,a.name,a.account_type ORDER BY a.code LIMIT 1000");$st->execute([$wid,$cid]);return$st->fetchAll();
    }

    private static function partyLedger(int $wid,int $cid,int $partyId): array
    {
        self::assertOwned($wid,$cid,'acc_parties',$partyId);$st=pdo()->prepare("SELECT v.id voucher_id,v.voucher_no,v.voucher_date,v.description voucher_description,l.description,l.debit,l.credit,(l.debit-l.credit) movement FROM acc_voucher_lines l JOIN acc_vouchers v ON v.id=l.voucher_id AND v.workspace_id=l.workspace_id WHERE l.workspace_id=? AND v.company_id=? AND v.status IN ('approved','final') AND l.party_id=? ORDER BY v.voucher_date,v.id,l.line_no LIMIT 1000");$st->execute([$wid,$cid,$partyId]);$rows=$st->fetchAll();$balance=0;foreach($rows as &$r){$balance+=(float)$r['movement'];$r['running_balance']=$balance;}unset($r);return['party_id'=>$partyId,'balance'=>$balance,'rows'=>$rows];
    }

    private static function searchCashAccounts(int $wid,int $cid,string $query): array
    {
        $query=trim($query);if($query==='')return[];$like='%'.$query.'%';
        $st=pdo()->prepare("SELECT id,code,name,bank_name,account_kind,account_no,iban,opening_balance FROM acc_cash_accounts WHERE workspace_id=? AND company_id=? AND active=1 AND (name LIKE ? OR code LIKE ? OR bank_name LIKE ? OR account_no LIKE ? OR iban LIKE ?) ORDER BY name LIMIT 30");
        $st->execute([$wid,$cid,$like,$like,$like,$like,$like]);return$st->fetchAll();
    }

    private static function checkAnalytics(int $wid,int $cid,array $args): array
    {
        $direction=trim((string)($args['direction']??'all'));if(!in_array($direction,['all','receivable','payable'],true))$direction='all';
        $status=trim((string)($args['status']??'all'));if(!in_array($status,['all','open','received','paid','bounced','canceled'],true))$status='all';
        $due=trim((string)($args['due_scope']??'all'));if(!in_array($due,['all','overdue','upcoming_7'],true))$due='all';
        $limit=max(1,min(100,(int)($args['limit']??30)));
        $where=['c.workspace_id=?','c.company_id=?'];$params=[$wid,$cid];
        if($direction!=='all'){$where[]='c.direction=?';$params[]=$direction;}
        if($status!=='all'){$where[]='c.status=?';$params[]=$status;}
        if($due==='overdue'){$where[]="c.status='open'";$where[]='c.due_date IS NOT NULL';$where[]='c.due_date<CURDATE()';}
        elseif($due==='upcoming_7'){$where[]="c.status='open'";$where[]='c.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)';}
        $sql="SELECT c.id,c.direction,c.check_no,c.amount,c.due_date,c.status,c.notes,p.name party_name,a.name cash_name,a.bank_name
              FROM acc_checks c
              LEFT JOIN acc_parties p ON p.id=c.party_id AND p.workspace_id=c.workspace_id AND p.company_id=c.company_id
              LEFT JOIN acc_cash_accounts a ON a.id=c.cash_account_id AND a.workspace_id=c.workspace_id AND a.company_id=c.company_id
              WHERE ".implode(' AND ',$where)." ORDER BY (c.due_date IS NULL),c.due_date,c.id DESC LIMIT ".$limit;
        $st=pdo()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();$total=0;
        foreach($rows as &$r){$total+=(float)($r['amount']??0);$r['due_date_fa']=AccountingRepository::faDate($r['due_date']??null);}unset($r);
        return ['direction'=>$direction,'status'=>$status,'due_scope'=>$due,'total_count'=>count($rows),'total_amount'=>$total,'rows'=>$rows];
    }

    private static function recentSales(int $wid,int $cid,int $limit): array
    {
        $limit=max(1,min(100,$limit));$st=pdo()->prepare("SELECT d.id,d.doc_type,d.document_no,d.document_date,d.due_date,d.net_total,d.workflow_status,d.taxpayer_status,p.name party_name FROM acc_sales_docs d LEFT JOIN acc_parties p ON p.id=d.party_id WHERE d.workspace_id=? AND d.company_id=? ORDER BY d.document_date DESC,d.id DESC LIMIT $limit");$st->execute([$wid,$cid]);return$st->fetchAll();
    }

    private static function recentPurchases(int $wid,int $cid,int $limit): array
    {
        $limit=max(1,min(100,$limit));$st=pdo()->prepare("SELECT d.id,d.doc_type,d.document_no,d.document_date,d.net_total,d.workflow_status,d.taxpayer_status,p.name party_name FROM acc_purchase_docs d LEFT JOIN acc_parties p ON p.id=d.party_id WHERE d.workspace_id=? AND d.company_id=? ORDER BY d.document_date DESC,d.id DESC LIMIT $limit");$st->execute([$wid,$cid]);return$st->fetchAll();
    }

    private static function documentAnalytics(int $wid,int $cid,array $args): array
    {
        self::assertCompany($wid,$cid);
        $kind=(string)($args['kind']??'');
        if(!in_array($kind,['sales','purchases'],true))throw new RuntimeException('analytics_kind_invalid');

        $period=self::resolveAnalyticsPeriod($args);
        $scope=self::analyticsStatusScope($args);
        $group=(string)($args['group_by']??'none');
        if(!in_array($group,['none','party','item','jalali_month','status'],true))throw new RuntimeException('analytics_group_invalid');
        $limit=max(1,min(50,(int)($args['limit']??10)));

        $partyId=(int)($args['party_id']??0);
        $itemId=(int)($args['item_id']??0);
        $partyName=null;$itemName=null;
        if($partyId>0){
            self::assertOwned($wid,$cid,'acc_parties',$partyId);
            $partyName=self::ownedEntityName($wid,$cid,'acc_parties',$partyId);
        }
        if($itemId>0){
            self::assertOwned($wid,$cid,'acc_items',$itemId);
            $itemName=self::ownedEntityName($wid,$cid,'acc_items',$itemId);
        }

        $isSales=$kind==='sales';
        $docTable=$isSales?'acc_sales_docs':'acc_purchase_docs';
        $lineTable=$isSales?'acc_sales_lines':'acc_purchase_lines';
        $lineFk=$isSales?'sales_doc_id':'purchase_doc_id';

        $where=['d.workspace_id=?','d.company_id=?'];$params=[$wid,$cid];
        if(!empty($period['start_date'])){$where[]='d.document_date>=?';$params[]=$period['start_date'];}
        if(!empty($period['end_date'])){$where[]='d.document_date<=?';$params[]=$period['end_date'];}
        if($scope==='confirmed'){
            $where[]="d.workflow_status IN ('approved','final')";
        }elseif($scope!=='all'){
            $where[]='d.workflow_status=?';$params[]=$scope;
        }
        if($partyId>0){$where[]='d.party_id=?';$params[]=$partyId;}

        $from="`$docTable` d";
        if($itemId>0){
            $from.=" JOIN `$lineTable` l ON l.`$lineFk`=d.id AND l.workspace_id=d.workspace_id";
            $where[]='l.item_id=?';$params[]=$itemId;
        }
        $whereSql=implode(' AND ',$where);

        if($itemId>0){
            $taxExpr=$isSales?'COALESCE(SUM(l.tax_amount),0)':'0';
            $st=pdo()->prepare("SELECT COUNT(DISTINCT d.id) document_count,
                COALESCE(SUM(l.quantity*l.unit_price),0) total_before_discount,
                COALESCE(SUM(l.discount_amount),0) discount_total,
                $taxExpr tax_total,
                COALESCE(SUM(l.line_total),0) net_total,
                COALESCE(SUM(l.quantity),0) quantity_total
                FROM $from WHERE $whereSql");
        }else{
            $st=pdo()->prepare("SELECT COUNT(*) document_count,
                COALESCE(SUM(d.total_before_discount),0) total_before_discount,
                COALESCE(SUM(d.discount_total),0) discount_total,
                COALESCE(SUM(d.tax_total),0) tax_total,
                COALESCE(SUM(d.net_total),0) net_total
                FROM $from WHERE $whereSql");
        }
        $st->execute($params);$summary=$st->fetch()?:[];
        $summary=[
            'document_count'=>(int)($summary['document_count']??0),
            'total_before_discount'=>(float)($summary['total_before_discount']??0),
            'discount_total'=>(float)($summary['discount_total']??0),
            'tax_total'=>(float)($summary['tax_total']??0),
            'net_total'=>(float)($summary['net_total']??0),
        ]+($itemId>0?['quantity_total'=>(float)($summary['quantity_total']??0)]:[]);

        $recentLimit=max(1,min(20,$limit));
        $distinct=$itemId>0?'DISTINCT ':'';
        $st=pdo()->prepare("SELECT {$distinct}d.id,d.document_no,d.document_date,d.net_total,d.workflow_status,p.name party_name
            FROM $from LEFT JOIN acc_parties p
              ON p.id=d.party_id AND p.workspace_id=d.workspace_id AND p.company_id=d.company_id
            WHERE $whereSql ORDER BY d.document_date DESC,d.id DESC LIMIT $recentLimit");
        $st->execute($params);$recent=$st->fetchAll();
        foreach($recent as &$r){
            $r['id']=(int)$r['id'];$r['net_total']=(float)$r['net_total'];
            $r['jalali_date']=Jalali::fromGregorian((string)$r['document_date']);
        }unset($r);

        $groups=[];
        if($group==='party'){
            $amountExpr=$itemId>0?'COALESCE(SUM(l.line_total),0)':'COALESCE(SUM(d.net_total),0)';
            $st=pdo()->prepare("SELECT d.party_id,p.name label,COUNT(DISTINCT d.id) document_count,$amountExpr net_total
                FROM $from LEFT JOIN acc_parties p
                  ON p.id=d.party_id AND p.workspace_id=d.workspace_id AND p.company_id=d.company_id
                WHERE $whereSql
                GROUP BY d.party_id,p.name
                ORDER BY net_total DESC,document_count DESC LIMIT $limit");
            $st->execute($params);$groups=$st->fetchAll();
            foreach($groups as &$r){$r['party_id']=(int)$r['party_id'];$r['document_count']=(int)$r['document_count'];$r['net_total']=(float)$r['net_total'];}unset($r);
        }elseif($group==='status'){
            $amountExpr=$itemId>0?'COALESCE(SUM(l.line_total),0)':'COALESCE(SUM(d.net_total),0)';
            $st=pdo()->prepare("SELECT d.workflow_status `key`,d.workflow_status label,COUNT(DISTINCT d.id) document_count,$amountExpr net_total
                FROM $from WHERE $whereSql
                GROUP BY d.workflow_status ORDER BY net_total DESC");
            $st->execute($params);$groups=$st->fetchAll();
            foreach($groups as &$r){$r['document_count']=(int)$r['document_count'];$r['net_total']=(float)$r['net_total'];}unset($r);
        }elseif($group==='item'){
            $groupFrom="`$docTable` d JOIN `$lineTable` l ON l.`$lineFk`=d.id AND l.workspace_id=d.workspace_id";
            $st=pdo()->prepare("SELECT l.item_id,i.name label,i.code,COUNT(DISTINCT d.id) document_count,
                    COALESCE(SUM(l.quantity),0) quantity,COALESCE(SUM(l.line_total),0) line_total
                FROM $groupFrom
                LEFT JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=d.workspace_id AND i.company_id=d.company_id
                WHERE $whereSql
                GROUP BY l.item_id,i.name,i.code
                ORDER BY line_total DESC,quantity DESC LIMIT $limit");
            $st->execute($params);$groups=$st->fetchAll();
            foreach($groups as &$r){
                $r['item_id']=(int)$r['item_id'];$r['document_count']=(int)$r['document_count'];
                $r['quantity']=(float)$r['quantity'];$r['line_total']=(float)$r['line_total'];
            }unset($r);
        }elseif($group==='jalali_month'){
            $amountExpr=$itemId>0?'l.line_total':'d.net_total';
            $st=pdo()->prepare("SELECT d.id,d.document_date,$amountExpr metric_amount FROM $from
                WHERE $whereSql ORDER BY d.document_date,d.id");
            $st->execute($params);$bucket=[];
            foreach($st->fetchAll() as $r){
                $j=Jalali::fromGregorian((string)$r['document_date']);$key=substr($j,0,7);
                if(!isset($bucket[$key]))$bucket[$key]=['key'=>$key,'label'=>$key,'document_count'=>0,'net_total'=>0.0,'_docs'=>[]];
                $bucket[$key]['_docs'][(int)$r['id']]=1;
                $bucket[$key]['net_total']+=(float)$r['metric_amount'];
            }
            foreach($bucket as &$b){$b['document_count']=count($b['_docs']);unset($b['_docs']);}unset($b);
            ksort($bucket,SORT_STRING);$groups=array_values($bucket);
            if(count($groups)>$limit)$groups=array_slice($groups,-$limit);
        }

        return [
            'kind'=>$kind,
            'period'=>$period,
            'filters'=>[
                'status_scope'=>$scope,
                'status_label'=>self::analyticsScopeLabel($scope),
                'group_by'=>$group,
                'party_id'=>$partyId?:null,'party_name'=>$partyName,
                'item_id'=>$itemId?:null,'item_name'=>$itemName,
            ],
            'summary'=>$summary,
            'groups'=>$groups,
            'recent'=>$recent,
            'notes'=>[
                'amount_unit'=>'IRR',
                'scope_semantics'=>'all شامل draft/approved/final است؛ confirmed فقط approved+final است.',
                'item_scope'=>'در فیلتر کالا، summary از ردیف‌های همان کالا محاسبه می‌شود نه کل مبلغ فاکتور.',
                'safety'=>'Entityها با مالکیت workspace/company اعتبارسنجی می‌شوند و SQL identifiers از allowlist ثابت هستند.',
            ],
        ];
    }

    private static function analyticsStatusScope(array $args): string
    {
        $legacy=(string)($args['workflow_status']??'all');
        if(!in_array($legacy,['all','draft','approved','final'],true))throw new RuntimeException('analytics_status_invalid');
        $scope=trim((string)($args['status_scope']??''));
        if($scope==='')$scope=$legacy;
        if(!in_array($scope,['all','confirmed','draft','approved','final'],true))throw new RuntimeException('analytics_status_scope_invalid');
        if($legacy!=='all' && $scope!==$legacy)throw new RuntimeException('analytics_status_conflict');
        return $scope;
    }

    private static function analyticsScopeLabel(string $scope): string
    {
        return match($scope){
            'confirmed'=>'قطعی (approved + final)',
            'draft'=>'فقط draft',
            'approved'=>'فقط approved',
            'final'=>'فقط final',
            default=>'همه وضعیت‌ها (draft + approved + final)',
        };
    }

    private static function ownedEntityName(int $wid,int $cid,string $table,int $id): ?string
    {
        if(!in_array($table,['acc_parties','acc_items'],true))throw new RuntimeException('analytics_entity_table_invalid');
        $st=pdo()->prepare("SELECT name FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");
        $st->execute([$wid,$cid,$id]);$name=$st->fetchColumn();
        return $name!==false?(string)$name:null;
    }

    private static function resolveAnalyticsPeriod(array $args): array
    {
        $period=(string)($args['period']??'all');
        $allowed=['all','current_jalali_month','previous_jalali_month','current_jalali_year','previous_jalali_year','rolling_jalali_months','custom','custom_jalali_month'];
        if(!in_array($period,$allowed,true))throw new RuntimeException('analytics_period_invalid');

        [$jy,$jm,$jd]=Jalali::toJalali((int)date('Y'),(int)date('n'),(int)date('j'));
        $start=null;$end=null;$label='همه دوره‌ها';

        if($period==='current_jalali_month'){
            $start=self::jalaliDate($jy,$jm,1);$end=date('Y-m-d');
            $label=Jalali::monthName($jm).' '.$jy.' (تا '.Jalali::today().')';
        }elseif($period==='previous_jalali_month'){
            [$y,$m]=self::shiftJalaliMonth($jy,$jm,-1);
            $start=self::jalaliDate($y,$m,1);$end=self::jalaliDate($y,$m,Jalali::monthLength($y,$m));
            $label=Jalali::monthName($m).' '.$y;
        }elseif($period==='current_jalali_year'){
            $start=self::jalaliDate($jy,1,1);$end=date('Y-m-d');$label='سال '.$jy.' (تا '.Jalali::today().')';
        }elseif($period==='previous_jalali_year'){
            $y=$jy-1;$start=self::jalaliDate($y,1,1);$end=self::jalaliDate($y,12,Jalali::monthLength($y,12));$label='سال '.$y;
        }elseif($period==='rolling_jalali_months'){
            $months=max(1,min(24,(int)($args['months']??3)));[$y,$m]=self::shiftJalaliMonth($jy,$jm,-($months-1));
            $start=self::jalaliDate($y,$m,1);$end=date('Y-m-d');$label=$months.' ماه اخیر تا '.Jalali::today();
        }elseif($period==='custom_jalali_month'){
            $y=(int)($args['jalali_year']??0);$m=(int)($args['jalali_month']??0);
            if($y<1300||$y>1499||$m<1||$m>12)throw new RuntimeException('analytics_jalali_month_invalid');
            $start=self::jalaliDate($y,$m,1);$end=self::jalaliDate($y,$m,Jalali::monthLength($y,$m));$label=Jalali::monthName($m).' '.$y;
        }elseif($period==='custom'){
            $start=AccountingRepository::date((string)($args['date_from']??''));$end=AccountingRepository::date((string)($args['date_to']??''));
            if(!$start||!$end)throw new RuntimeException('analytics_custom_dates_required');
            if(!self::validIsoDate($start)||!self::validIsoDate($end))throw new RuntimeException('analytics_custom_date_invalid');
            if($start>$end)throw new RuntimeException('analytics_date_order_invalid');
            $days=(int)floor((strtotime($end)-strtotime($start))/86400);
            if($days>3660)throw new RuntimeException('analytics_range_too_large');
            $label='از '.Jalali::fromGregorian($start).' تا '.Jalali::fromGregorian($end);
        }

        return [
            'key'=>$period,'start_date'=>$start,'end_date'=>$end,'label'=>$label,
            'jalali_start'=>$start?Jalali::fromGregorian($start):null,
            'jalali_end'=>$end?Jalali::fromGregorian($end):null,
        ];
    }

    private static function validIsoDate(string $date): bool
    {
        $dt=DateTime::createFromFormat('!Y-m-d',$date);
        return $dt instanceof DateTime && $dt->format('Y-m-d')===$date;
    }

    private static function shiftJalaliMonth(int $y,int $m,int $delta): array
    {
        $index=$y*12+($m-1)+$delta;
        $ny=intdiv($index,12);$nm=($index%12)+1;
        if($nm<=0){$nm+=12;$ny--;}
        return [$ny,$nm];
    }

    private static function jalaliDate(int $y,int $m,int $d): string
    {
        [$gy,$gm,$gd]=Jalali::toGregorian($y,$m,$d);
        return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
    }

    private static function validateProposalArgs(int $wid,?int $cid,string $tool,array $args): void
    {
        if(!$cid)throw new RuntimeException('company_context_required');self::assertCompany($wid,$cid);
        if($tool==='create_sales_invoice_draft'){
            self::assertOwned($wid,$cid,'acc_parties',(int)($args['party_id']??0));$lines=(array)($args['lines']??[]);if(!$lines)throw new RuntimeException('حداقل یک ردیف فاکتور لازم است.');
            foreach($lines as $l){self::assertOwned($wid,$cid,'acc_items',(int)($l['item_id']??0));if((float)($l['quantity']??0)<=0)throw new RuntimeException('مقدار فاکتور باید بیشتر از صفر باشد.');if((float)($l['unit_price']??0)<0)throw new RuntimeException('قیمت واحد نامعتبر است.');}
        }elseif($tool==='create_purchase_invoice_draft'){
            self::assertOwned($wid,$cid,'acc_parties',(int)($args['party_id']??0));
            $docType=trim((string)($args['doc_type']??'purchase_invoice_goods'));
            if(!in_array($docType,['purchase_invoice_goods','purchase_invoice_service'],true))throw new RuntimeException('نوع فاکتور خرید نامعتبر است.');
            $lines=(array)($args['lines']??[]);if(!$lines)throw new RuntimeException('حداقل یک ردیف فاکتور خرید لازم است.');
            foreach($lines as $l){
                self::assertOwned($wid,$cid,'acc_items',(int)($l['item_id']??0));
                if((float)($l['quantity']??0)<=0)throw new RuntimeException('مقدار خرید باید بیشتر از صفر باشد.');
                if((float)($l['unit_price']??0)<0)throw new RuntimeException('قیمت واحد خرید نامعتبر است.');
                if((float)($l['discount_amount']??0)<0)throw new RuntimeException('تخفیف خرید نامعتبر است.');
            }
        }elseif($tool==='create_warehouse_receipt'){
            InventoryDomain::validateReceiptArgs($wid,$cid,$args);
        }elseif($tool==='create_trade_case'){
            TradeDomain::normalizeCaseArgs($wid,$cid,$args,true);
        }elseif($tool==='create_trade_shipment'){
            TradeDomain::normalizeShipmentArgs($wid,$cid,$args);
        }elseif($tool==='add_trade_cost'){
            TradeDomain::normalizeCostArgs($wid,$cid,$args);
        }elseif($tool==='create_check'){
            $direction=trim((string)($args['direction']??''));if(!in_array($direction,['receivable','payable'],true))throw new RuntimeException('نوع چک نامعتبر است.');
            $checkNo=trim((string)($args['check_no']??''));if($checkNo===''||mb_strlen($checkNo)>100)throw new RuntimeException('شماره چک نامعتبر است.');
            if((float)($args['amount']??0)<=0)throw new RuntimeException('مبلغ چک باید بیشتر از صفر باشد.');
            $due=AccountingRepository::date((string)($args['due_date']??''));if(!$due)throw new RuntimeException('تاریخ سررسید چک نامعتبر است.');
            if(!empty($args['party_id']))self::assertOwned($wid,$cid,'acc_parties',(int)$args['party_id']);
            if(!empty($args['cash_account_id']))self::assertOwned($wid,$cid,'acc_cash_accounts',(int)$args['cash_account_id']);
        }elseif($tool==='create_voucher_draft'){
            $lines=(array)($args['lines']??[]);if(count($lines)<2)throw new RuntimeException('سند حداقل دو آرتیکل لازم دارد.');$d=0;$c=0;
            foreach($lines as $l){self::assertOwned($wid,$cid,'acc_accounts',(int)($l['account_id']??0));$x=max(0,(float)($l['debit']??0));$y=max(0,(float)($l['credit']??0));if(($x>0&&$y>0)||($x<=0&&$y<=0))throw new RuntimeException('هر آرتیکل باید فقط بدهکار یا بستانکار باشد.');$d+=$x;$c+=$y;if(!empty($l['party_id']))self::assertOwned($wid,$cid,'acc_parties',(int)$l['party_id']);}
            if(abs($d-$c)>0.01)throw new RuntimeException('سند پیشنهادی بالانس نیست.');
        }
    }

    private static function proposalSummary(string $tool,array $args): string
    {
        return match($tool){
            'create_sales_invoice_draft'=>'ایجاد پیش‌نویس فاکتور فروش با '.count((array)($args['lines']??[])).' ردیف',
            'create_purchase_invoice_draft'=>'ایجاد پیش‌نویس فاکتور خرید با '.count((array)($args['lines']??[])).' ردیف',
            'create_warehouse_receipt'=>'ثبت رسید انبار از سند خرید #'.(int)($args['purchase_doc_id']??0).' با '.count((array)($args['lines']??[])).' ردیف',
            'create_trade_case'=>'ایجاد پرونده بازرگانی برای سند خرید #'.(int)($args['purchase_doc_id']??0),
            'create_trade_shipment'=>'ثبت Shipment برای پرونده #'.(int)($args['trade_case_id']??0),
            'add_trade_cost'=>'ثبت هزینه '.(string)($args['basis']??'').' '.(string)($args['cost_type']??'').' برای پرونده #'.(int)($args['trade_case_id']??0),
            'create_check'=>'ثبت چک '.((string)($args['direction']??'')==='payable'?'پرداختنی':'دریافتنی').' شماره '.(string)($args['check_no']??''),
            'create_voucher_draft'=>'ایجاد پیش‌نویس سند حسابداری با '.count((array)($args['lines']??[])).' آرتیکل',
            default=>'عملیات پیشنهادی ایجنت'
        };
    }

    private static function createSalesDraft(int $wid,int $cid,int $userId,array $args): array
    {
        $date=AccountingRepository::date((string)($args['document_date']??''))?:date('Y-m-d');$due=AccountingRepository::date((string)($args['due_date']??''));$party=(int)$args['party_id'];$lines=(array)$args['lines'];
        $no='AI-SAL-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
        $gross=0;$discount=0;$tax=0;$valid=[];foreach($lines as $l){$qty=(float)$l['quantity'];$price=(float)$l['unit_price'];$base=$qty*$price;$disc=max(0,min($base,(float)($l['discount_amount']??0)));$taxPct=max(0,min(100,(float)($l['tax_percent']??0)));$taxAmt=max(0,($base-$disc)*$taxPct/100);$total=$base-$disc+$taxAmt;$gross+=$base;$discount+=$disc;$tax+=$taxAmt;$valid[]=[$l,$taxAmt,$total];}
        $net=$gross-$discount+$tax;$pdo=pdo();
        $pdo->prepare("INSERT INTO acc_sales_docs (workspace_id,company_id,doc_type,document_no,document_date,due_date,party_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,tax_total,net_total,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?, 'draft','not_sent',?,?,?,?,?,NOW(),NOW())")
            ->execute([$wid,$cid,'invoice',$no,$date,$due,$party,trim((string)($args['notes']??'')),$gross,$discount,$tax,$net,$userId]);$id=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT INTO acc_sales_lines (workspace_id,sales_doc_id,line_no,item_id,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");$n=1;
        foreach($valid as [$l,$taxAmt,$total])$ins->execute([$wid,$id,$n++,(int)$l['item_id'],trim((string)($l['description']??'')),(float)$l['quantity'],(float)$l['unit_price'],max(0,(float)($l['discount_amount']??0)),max(0,(float)($l['tax_percent']??0)),$taxAmt,$total]);
        return ['entity'=>'acc_sales_docs','id'=>$id,'document_no'=>$no,'net_total'=>$net,'status'=>'draft'];
    }

    private static function createPurchaseDraft(int $wid,int $cid,int $userId,array $args): array
    {
        $date=AccountingRepository::date((string)($args['document_date']??''))?:date('Y-m-d');$party=(int)$args['party_id'];$lines=(array)$args['lines'];
        $docType=trim((string)($args['doc_type']??'purchase_invoice_goods'));$no='AI-PUR-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
        $gross=0;$discount=0;$net=0;$valid=[];
        foreach($lines as $l){
            $qty=(float)$l['quantity'];$price=max(0,(float)$l['unit_price']);$base=$qty*$price;
            $disc=max(0,min($base,(float)($l['discount_amount']??0)));$total=max(0,$base-$disc);
            $gross+=$base;$discount+=$disc;$net+=$total;$valid[]=[$l,$total];
        }
        $pdo=pdo();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
        try{
            $pdo->prepare("INSERT INTO acc_purchase_docs (workspace_id,company_id,doc_type,document_no,document_date,party_id,notes,workflow_status,taxpayer_status,total_before_discount,discount_total,net_total,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'draft','not_sent',?,?,?,?,NOW(),NOW())")
                ->execute([$wid,$cid,$docType,$no,$date,$party,trim((string)($args['notes']??'ایجادشده توسط AI و تاییدشده توسط کاربر')),$gross,$discount,$net,$userId]);
            $id=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare("INSERT INTO acc_purchase_lines (workspace_id,purchase_doc_id,line_no,item_id,description,quantity,unit_price,discount_amount,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");$n=1;
            foreach($valid as [$l,$total])$ins->execute([$wid,$id,$n++,(int)$l['item_id'],trim((string)($l['description']??'')),(float)$l['quantity'],(float)$l['unit_price'],max(0,(float)($l['discount_amount']??0)),$total]);
            if($ownsTransaction)$pdo->commit();return ['entity'=>'acc_purchase_docs','id'=>$id,'document_no'=>$no,'doc_type'=>$docType,'net_total'=>$net,'status'=>'draft'];
        }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private static function createCheck(int $wid,int $cid,int $userId,array $args): array
    {
        $direction=trim((string)$args['direction']);$checkNo=trim((string)$args['check_no']);$amount=(float)$args['amount'];
        $due=AccountingRepository::date((string)$args['due_date']);$party=!empty($args['party_id'])?(int)$args['party_id']:null;$cash=!empty($args['cash_account_id'])?(int)$args['cash_account_id']:null;
        $dupe=pdo()->prepare("SELECT id FROM acc_checks WHERE workspace_id=? AND company_id=? AND direction=? AND check_no=? LIMIT 1");$dupe->execute([$wid,$cid,$direction,$checkNo]);
        if($dupe->fetchColumn())throw new RuntimeException('چکی با این شماره و نوع قبلاً ثبت شده است.');
        $notes=trim((string)($args['notes']??''));if($notes==='')$notes='ایجادشده توسط AI پس از تایید انسانی';
        pdo()->prepare("INSERT INTO acc_checks (workspace_id,company_id,direction,check_no,amount,due_date,party_id,cash_account_id,status,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?, 'open',?,NOW(),NOW())")
            ->execute([$wid,$cid,$direction,$checkNo,$amount,$due,$party,$cash,$notes]);
        $id=(int)pdo()->lastInsertId();return ['entity'=>'acc_checks','id'=>$id,'check_no'=>$checkNo,'direction'=>$direction,'amount'=>$amount,'due_date'=>$due,'status'=>'open'];
    }

    private static function createVoucherDraft(int $wid,int $cid,int $userId,array $args): array
    {
        $date=AccountingRepository::date((string)($args['voucher_date']??''))?:date('Y-m-d');$no='AI-VCH-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));$d=0;$c=0;
        foreach((array)$args['lines'] as $l){$d+=max(0,(float)($l['debit']??0));$c+=max(0,(float)($l['credit']??0));}
        pdo()->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,voucher_no,voucher_date,voucher_type,status,description,source_type,auto_generated,total_debit,total_credit,created_by,created_at,updated_at) VALUES (?,?,?,?, 'general','draft',?,'ai_agent',1,?,?,?,NOW(),NOW())")
            ->execute([$wid,$cid,$no,$date,trim((string)($args['description']??'پیش‌نویس پیشنهادی AI')),$d,$c,$userId]);$id=(int)pdo()->lastInsertId();$ins=pdo()->prepare("INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");$n=1;
        foreach((array)$args['lines'] as $l)$ins->execute([$wid,$id,$n++,(int)$l['account_id'],!empty($l['party_id'])?(int)$l['party_id']:null,trim((string)($l['description']??'')),max(0,(float)($l['debit']??0)),max(0,(float)($l['credit']??0))]);
        return ['entity'=>'acc_vouchers','id'=>$id,'voucher_no'=>$no,'total_debit'=>$d,'total_credit'=>$c,'status'=>'draft'];
    }

    private static function sum(int $wid,int $cid,string $table,string $column): float
    {
        $st=pdo()->prepare("SELECT COALESCE(SUM(`$column`),0) FROM `$table` WHERE workspace_id=? AND company_id=?");$st->execute([$wid,$cid]);return(float)$st->fetchColumn();
    }
    private static function assertCompany(int $wid,int $cid): void{$st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");$st->execute([$wid,$cid]);if(!$st->fetchColumn())throw new RuntimeException('company_not_found');}
    private static function assertOwned(int $wid,int $cid,string $table,int $id): void{if($id<=0||!in_array($table,['acc_parties','acc_items','acc_accounts','acc_cash_accounts'],true))throw new RuntimeException('entity_invalid');$st=pdo()->prepare("SELECT 1 FROM `$table` WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");$st->execute([$wid,$cid,$id]);if(!$st->fetchColumn())throw new RuntimeException('entity_not_owned');}
}
