<?php
/**
 * ERPSMART v10.9.2 — deterministic cross-module intelligence reads.
 *
 * These methods assemble typed business evidence from canonical ERP domains.
 * They do not call an LLM and never mutate business state.
 */
final class BusinessIntelligenceDomain
{
    public const VERSION='10.9.3';

    private static function assertCompany(int $wid,int $cid): void
    {
        $st=pdo()->prepare("SELECT 1 FROM companies WHERE workspace_id=? AND id=? AND active=1 LIMIT 1");
        $st->execute([$wid,$cid]);
        if(!$st->fetchColumn())throw new RuntimeException('company_not_found');
    }

    private static function boundedPartyIds(array $raw): array
    {
        $ids=[];
        foreach($raw as $v){$id=(int)$v;if($id>0)$ids[$id]=$id;}
        return array_values(array_slice($ids,0,8,true));
    }

    public static function supplierPerformanceSummary(int $wid,int $cid,array $args=[]): array
    {
        self::assertCompany($wid,$cid);
        $months=max(1,min(12,(int)($args['months']??6)));
        $limit=max(1,min(8,(int)($args['limit']??5)));
        $partyIds=self::boundedPartyIds((array)($args['party_ids']??[]));
        $start=date('Y-m-d',strtotime('-'.$months.' months'));
        $end=date('Y-m-d');

        // Canonical purchase-history semantics intentionally match document_analytics:
        // confirmed = approved + final across ALL purchase document types. Receipt/acceptance
        // metrics are narrowed to physical item lines separately below.
        $where=["d.workspace_id=?","d.company_id=?","d.workflow_status IN ('approved','final')","d.document_date>=?","d.document_date<=?"];
        $params=[$wid,$cid,$start,$end];
        if($partyIds){$ph=implode(',',array_fill(0,count($partyIds),'?'));$where[]="d.party_id IN ($ph)";array_push($params,...$partyIds);}
        $whereSql=implode(' AND ',$where);

        $st=pdo()->prepare("SELECT d.party_id,p.code,p.name,COUNT(DISTINCT d.id) purchase_document_count,COALESCE(SUM(d.net_total),0) purchase_net_total
            FROM acc_purchase_docs d
            JOIN acc_parties p ON p.id=d.party_id AND p.workspace_id=d.workspace_id AND p.company_id=d.company_id
            WHERE $whereSql
            GROUP BY d.party_id,p.code,p.name
            ORDER BY purchase_net_total DESC,purchase_document_count DESC
            LIMIT $limit");
        $st->execute($params);$base=$st->fetchAll();
        if(!$base)return ['period'=>['months'=>$months,'start_date'=>$start,'end_date'=>$end],'rows'=>[],'limitations'=>['no_confirmed_purchase_history']];

        $selected=array_map(static fn($r)=>(int)$r['party_id'],$base);$ph=implode(',',array_fill(0,count($selected),'?'));
        $lineParams=array_merge([$wid,$cid,$start,$end],$selected);
        $lineSql="SELECT d.party_id,
            COALESCE(SUM(l.quantity),0) ordered_qty,
            COALESCE(SUM(COALESCE(rr.accepted_qty,0)),0) accepted_qty,
            COALESCE(SUM(COALESCE(rr.rejected_qty,0)),0) rejected_qty,
            COALESCE(SUM(GREATEST(l.quantity-COALESCE(rr.accepted_qty,0),0)),0) open_qty
            FROM acc_purchase_lines l
            JOIN acc_purchase_docs d ON d.id=l.purchase_doc_id AND d.workspace_id=l.workspace_id
            JOIN acc_items i ON i.id=l.item_id AND i.workspace_id=l.workspace_id AND i.company_id=d.company_id
            LEFT JOIN (
                SELECT rl.workspace_id,rl.purchase_line_id,SUM(rl.accepted_qty) accepted_qty,SUM(rl.rejected_qty) rejected_qty
                FROM acc_inventory_receipt_lines rl
                JOIN acc_inventory_receipts r ON r.id=rl.receipt_id AND r.workspace_id=rl.workspace_id AND r.status='posted'
                GROUP BY rl.workspace_id,rl.purchase_line_id
            ) rr ON rr.workspace_id=l.workspace_id AND rr.purchase_line_id=l.id
            WHERE d.workspace_id=? AND d.company_id=?
              AND d.workflow_status IN ('approved','final') AND d.document_date>=? AND d.document_date<=?
              AND d.party_id IN ($ph) AND COALESCE(i.item_type,'material')<>'service'
            GROUP BY d.party_id";
        $st=pdo()->prepare($lineSql);$st->execute($lineParams);$lineBy=[];foreach($st->fetchAll() as $r)$lineBy[(int)$r['party_id']]=$r;

        $cases=TradeDomain::searchCases($wid,$cid,'');
        $risk=TradeDomain::riskSummary($wid,$cid,100);$riskRows=(array)($risk['rows']??[]);$riskByCase=[];foreach($riskRows as $r)$riskByCase[(string)($r['case_no']??'')]=$r;
        $trade=[];
        foreach($cases as $c){
            $pid=(int)($c['supplier_id']??0);if(!in_array($pid,$selected,true))continue;
            if(!isset($trade[$pid]))$trade[$pid]=['trade_case_count'=>0,'active_trade_case_count'=>0,'high_risk_cases'=>0,'medium_risk_cases'=>0,'max_delay_days'=>0.0,'arrived_sample'=>0,'on_time_arrivals'=>0,'late_arrivals'=>0,'cost_overrun_cases'=>0];
            $t=&$trade[$pid];$t['trade_case_count']++;
            if(!in_array((string)($c['status']??''),['closed','canceled'],true))$t['active_trade_case_count']++;
            $rr=$riskByCase[(string)($c['case_no']??'')]??null;
            if(is_array($rr)){
                $level=strtolower((string)($rr['risk_level']??''));if($level==='high')$t['high_risk_cases']++;elseif($level==='medium')$t['medium_risk_cases']++;
                $t['max_delay_days']=max((float)$t['max_delay_days'],(float)($rr['delay_days']??0));
                if(in_array('cost_overrun',(array)($rr['reasons']??[]),true))$t['cost_overrun_cases']++;
            }
            $eta=(string)($c['latest_eta']??'');$ata=(string)($c['latest_ata']??'');
            if($eta!==''&&$ata!==''){$t['arrived_sample']++;if($ata<=$eta)$t['on_time_arrivals']++;else$t['late_arrivals']++;}
            unset($t);
        }

        $rows=[];
        foreach($base as $b){
            $pid=(int)$b['party_id'];$ln=$lineBy[$pid]??[];$tr=$trade[$pid]??[];
            $ordered=(float)($ln['ordered_qty']??0);$accepted=(float)($ln['accepted_qty']??0);$rejected=(float)($ln['rejected_qty']??0);$inspected=$accepted+$rejected;$arrived=(int)($tr['arrived_sample']??0);
            $rows[]=[
                'party_id'=>$pid,'code'=>(string)($b['code']??''),'name'=>(string)($b['name']??''),
                'purchase_document_count'=>(int)$b['purchase_document_count'],'purchase_net_total'=>(float)$b['purchase_net_total'],
                'ordered_qty'=>$ordered,'accepted_qty'=>$accepted,'rejected_qty'=>$rejected,'open_qty'=>(float)($ln['open_qty']??0),
                'receipt_fill_rate'=>$ordered>0?$accepted/$ordered:null,'receipt_acceptance_rate'=>$inspected>0?$accepted/$inspected:null,
                'trade_case_count'=>(int)($tr['trade_case_count']??0),'active_trade_case_count'=>(int)($tr['active_trade_case_count']??0),
                'high_risk_cases'=>(int)($tr['high_risk_cases']??0),'medium_risk_cases'=>(int)($tr['medium_risk_cases']??0),
                'max_delay_days'=>(float)($tr['max_delay_days']??0),'cost_overrun_cases'=>(int)($tr['cost_overrun_cases']??0),
                'arrival_sample'=>$arrived,'on_time_arrivals'=>(int)($tr['on_time_arrivals']??0),'late_arrivals'=>(int)($tr['late_arrivals']??0),
                'on_time_rate'=>$arrived>0?((int)($tr['on_time_arrivals']??0)/$arrived):null,
            ];
        }
        return [
            'period'=>['months'=>$months,'start_date'=>$start,'end_date'=>$end,'status_scope'=>'confirmed'],
            'rows'=>$rows,
            'limitations'=>array_filter([
                'supplier_quality_score_not_enabled'=>'کیفیت/SLA و زمان پاسخ تامین‌کننده در مدل داده فعلی کامل نیست؛ رتبه کیفی مصنوعی ساخته نمی‌شود.',
                'price_normalization_not_enabled'=>'مقایسه قیمت بدون نرمال‌سازی کالا/ارز انجام نمی‌شود.',
                'goods_scope'=>'حجم خرید قطعی همه اسناد approved/final را پوشش می‌دهد؛ شاخص دریافت/پذیرش فقط خطوط کالایی غیرخدمت را می‌سنجد و خدمات را به‌عنوان عدم‌دریافت جریمه نمی‌کند.',
                'trade_sample_bound'=>'سیگنال Trade روی حداکثر 100 پرونده اخیر شرکت محاسبه می‌شود.',
                'portfolio_scope'=>!$partyIds?'بدون @، مقایسه روی حداکثر '.$limit.' تأمین‌کننده با بیشترین خرید قطعی دوره انجام می‌شود.':null,
            ])
        ];
    }

    public static function shipmentCommitmentImpact(int $wid,int $cid,array $args=[]): array
    {
        self::assertCompany($wid,$cid);
        $caseId=(int)($args['trade_case_id']??0);$shipmentId=(int)($args['shipment_id']??0);$limit=max(1,min(30,(int)($args['limit']??15)));
        if($caseId<=0&&$shipmentId>0){$st=pdo()->prepare("SELECT trade_case_id FROM acc_trade_shipments WHERE workspace_id=? AND company_id=? AND id=? LIMIT 1");$st->execute([$wid,$cid,$shipmentId]);$caseId=(int)($st->fetchColumn()?:0);}
        if($caseId<=0)throw new RuntimeException('trade_case_required');
        $requestedShipmentId=$shipmentId;
        $snapshot=TradeDomain::caseSnapshot($wid,$cid,$caseId);$landed=(array)($snapshot['landed_cost']??[]);$alloc=(array)($landed['allocations']??[]);
        $selectedShipment=null;
        foreach((array)($snapshot['shipments']??[]) as $candidate){
            if(!is_array($candidate))continue;
            if($requestedShipmentId>0 && (int)($candidate['id']??0)===$requestedShipmentId){$selectedShipment=$candidate;break;}
        }
        if(!$selectedShipment)$selectedShipment=$snapshot['shipments'][0]??null;
        if(is_array($selectedShipment)){
            $eta=(string)($selectedShipment['eta']??'');$ata=(string)($selectedShipment['ata']??'');$status=(string)($selectedShipment['status']??'');$delay=0;
            if($eta!==''&&$ata===''&&!in_array($status,['arrived','cleared','delivered','canceled'],true)&&$eta<date('Y-m-d'))$delay=max(0,(int)floor((time()-strtotime($eta))/86400));
            $selectedShipment['delay_days']=$delay;
        }
        $itemIds=[];$purchaseItems=[];
        foreach($alloc as $a){$iid=(int)($a['item_id']??0);if($iid<=0)continue;$itemIds[$iid]=$iid;$purchaseItems[]=['item_id'=>$iid,'item_code'=>$a['item_code']??'','item_name'=>$a['item_name']??'','ordered_qty'=>(float)($a['ordered_qty']??0),'accepted_qty'=>(float)($a['accepted_qty']??0)];}
        if(!$itemIds)return ['trade_case_id'=>$caseId,'case'=>$snapshot['case']??[],'shipment'=>$selectedShipment,'purchase_items'=>[],'item_exposure'=>[],'affected_sales'=>[],'summary'=>['affected_sales_count'=>0,'affected_customer_count'=>0],'limitations'=>['no_purchase_items']];

        $caseOpen=[];foreach($purchaseItems as $pi){$iid=(int)$pi['item_id'];$caseOpen[$iid]=($caseOpen[$iid]??0)+max(0,(float)$pi['ordered_qty']-(float)$pi['accepted_qty']);}
        $positions=[];foreach($itemIds as $iid){$p=InventoryDomain::inventoryPosition($wid,$cid,['item_id'=>$iid,'limit'=>1]);$positions[$iid]=($p['rows'][0]??[]);}
        $ph=implode(',',array_fill(0,count($itemIds),'?'));$params=array_merge([$wid,$cid],array_values($itemIds));
        $st=pdo()->prepare("SELECT DISTINCT d.id FROM acc_sales_docs d JOIN acc_sales_lines l ON l.sales_doc_id=d.id AND l.workspace_id=d.workspace_id
            WHERE d.workspace_id=? AND d.company_id=? AND d.doc_type IN ('invoice','preinvoice') AND d.workflow_status IN ('approved','final') AND l.item_id IN ($ph)
            ORDER BY d.document_date,d.id LIMIT ".$limit);
        $st->execute($params);$docIds=array_map('intval',array_column($st->fetchAll(),'id'));

        $affected=[];$perItem=[];$customers=[];
        foreach($itemIds as $iid)$perItem[$iid]=['item_id'=>$iid,'item_code'=>(string)($positions[$iid]['code']??''),'item_name'=>(string)($positions[$iid]['name']??''),'on_hand'=>(float)($positions[$iid]['on_hand']??0),'reserved'=>(float)($positions[$iid]['reserved']??0),'available'=>(float)($positions[$iid]['available']??0),'expected_inbound'=>(float)($positions[$iid]['expected_inbound']??0),'trade_case_open_inbound'=>(float)($caseOpen[$iid]??0),'outstanding_sales_qty'=>0.0,'reserved_for_affected_sales'=>0.0,'unreserved_outstanding_qty'=>0.0];
        foreach($docIds as $docId){
            $ful=SalesDomain::fulfillment($wid,$cid,['sales_doc_id'=>$docId]);$overlap=[];$docOutstanding=0.0;$docReserved=0.0;
            foreach((array)($ful['rows']??[]) as $r){$iid=(int)($r['item_id']??0);if(!isset($itemIds[$iid]))continue;$out=(float)($r['outstanding_qty']??0);if($out<=0.000001)continue;$res=(float)($r['reserved_qty']??0);$un=max(0,$out-$res);$overlap[]=['item_id'=>$iid,'item_code'=>$r['item_code']??'','item_name'=>$r['item_name']??'','outstanding_qty'=>$out,'reserved_qty'=>$res,'unreserved_qty'=>$un];$docOutstanding+=$out;$docReserved+=$res;$perItem[$iid]['outstanding_sales_qty']+=$out;$perItem[$iid]['reserved_for_affected_sales']+=$res;$perItem[$iid]['unreserved_outstanding_qty']+=$un;}
            if(!$overlap)continue;$customerId=(int)($ful['customer_id']??0);if($customerId)$customers[$customerId]=$customerId;
            $affected[]=['sales_doc_id'=>$docId,'document_no'=>$ful['document_no']??'','document_date'=>$ful['document_date']??'','workflow_status'=>$ful['workflow_status']??'','customer_id'=>$customerId,'customer_name'=>$ful['customer_name']??'','outstanding_quantity'=>$docOutstanding,'reserved_quantity'=>$docReserved,'unreserved_quantity'=>max(0,$docOutstanding-$docReserved),'items'=>$overlap];
        }
        foreach($perItem as &$r){$r['uncovered_after_current_available']=max(0,$r['unreserved_outstanding_qty']-max(0,$r['available']));$r['depends_on_future_inbound']=$r['uncovered_after_current_available']>0.000001;$r['potential_dependency_on_this_trade_case']=$r['depends_on_future_inbound']&&$r['trade_case_open_inbound']>0.000001;}unset($r);

        $risk=TradeDomain::riskSummary($wid,$cid,100);$riskRow=null;foreach((array)($risk['rows']??[]) as $r)if((int)($r['trade_case_id']??0)===$caseId){$riskRow=$r;break;}
        return [
            'trade_case_id'=>$caseId,'case'=>$snapshot['case']??[],'shipment'=>$selectedShipment,'trade_risk'=>$riskRow,
            'purchase_items'=>$purchaseItems,'item_exposure'=>array_values($perItem),'affected_sales'=>$affected,
            'summary'=>['affected_sales_count'=>count($affected),'affected_customer_count'=>count($customers),'items_with_future_inbound_dependency'=>count(array_filter($perItem,static fn($r)=>!empty($r['depends_on_future_inbound']))),'items_with_trade_case_dependency_signal'=>count(array_filter($perItem,static fn($r)=>!empty($r['potential_dependency_on_this_trade_case'])))],
            'limitations'=>['impact_is_exposure_not_causality'=>'سندهای فروش بر اساس هم‌پوشانی کالا و تعهد باز شناسایی می‌شوند؛ اثر قطعی تأخیر فقط وقتی ادعا می‌شود که پوشش موجودی/رزرو کافی نباشد.']
        ];
    }
}
