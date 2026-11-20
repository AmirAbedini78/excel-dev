<?php
final class TradeModule
{
    public static function handle(string $action): void
    {
        Tenant::requirePermission('trade.view');
        if($action==='trade_select_company'){AccountingRepository::companyId();redirect('index.php?page=trade');}
        Tenant::requirePermission('trade.manage');$wid=Tenant::id();$cid=AccountingRepository::companyId();$uid=(int)(Auth::user()['id']??0);
        try{
            if($action==='trade_save_case'){$r=TradeDomain::createCase($wid,$cid,$uid,$_POST);flash('پرونده بازرگانی '.$r['case_no'].' ایجاد شد.');redirect('index.php?page=trade&tab=foreign&case_id='.(int)$r['id']);}
            if($action==='trade_save_shipment'){$r=TradeDomain::createShipment($wid,$cid,$uid,$_POST);flash('محموله '.$r['shipment_no'].' ثبت شد.');redirect('index.php?page=trade&tab=foreign&case_id='.(int)$_POST['trade_case_id']);}
            if($action==='trade_save_cost'){TradeDomain::addCost($wid,$cid,$uid,$_POST);flash('هزینه بازرگانی ثبت شد.');redirect('index.php?page=trade&tab=foreign&case_id='.(int)$_POST['trade_case_id']);}
            if($action==='trade_save_clearance'){TradeDomain::updateClearance($wid,$cid,$uid,$_POST);flash('وضعیت گمرک/ترخیص به‌روزرسانی شد.');redirect('index.php?page=trade&tab=foreign&case_id='.(int)$_POST['trade_case_id']);}
            if($action==='trade_save_milestone'){TradeDomain::addMilestone($wid,$cid,$uid,$_POST);flash('Milestone ثبت شد.');redirect('index.php?page=trade&tab=foreign&case_id='.(int)$_POST['trade_case_id']);}
        }catch(Throwable $e){
            flash(TradingCockpitDomain::explainException($e),'danger');
            $back=isset($_POST['trade_case_id'])?'index.php?page=trade&tab=foreign&case_id='.(int)$_POST['trade_case_id']:'index.php?page=trade&tab=foreign';
            redirect($back);
        }
    }

    public static function render(): void
    {
        Tenant::requirePermission('trade.view');$cid=AccountingRepository::companyId();
        render_header('بازرگانی و توزیع','کوک‌پیت داخلی TR-1 · پرونده خارجی DRAFT');
        self::companyBar();
        if(!$cid){echo '<section class="card"><h2>شرکت فعالی وجود ندارد</h2></section>';render_footer();return;}
        $wid=Tenant::id();
        $tab=(string)($_GET['tab']??'domestic');
        if(!in_array($tab,['domestic','foreign'],true))$tab='domestic';
        echo '<section class="card"><div class="row-actions">';
        echo '<a class="btn tiny'.($tab==='domestic'?' primary':'').'" href="index.php?page=trade&tab=domestic">بازرگانی داخلی (TR-1)</a>';
        echo '<a class="btn tiny'.($tab==='foreign'?' primary':'').'" href="index.php?page=trade&tab=foreign">بازرگانی خارجی (DRAFT)</a>';
        echo '<a class="btn tiny" href="index.php?page=p2p">تطبیق سه‌طرفه</a>';
        echo '<a class="btn tiny" href="index.php?page=procurement">تأمین و خرید</a>';
        echo '</div></section>';
        if($tab==='domestic')self::domesticCockpit($wid,$cid);
        else self::foreignTab($wid,$cid);
        render_footer();
    }

    private static function companyBar(): void
    {
        $companies=AccountingRepository::companies();$current=AccountingRepository::companyId();
        echo '<section class="card acc-company-bar"><div><strong>شرکت فعال</strong></div><form method="post" class="acc-inline">'.csrf_field().'<input type="hidden" name="action" value="trade_select_company"><select name="company_id" onchange="this.form.submit()">';
        foreach($companies as $c)echo '<option value="'.(int)$c['id'].'" '.((int)$c['id']===$current?'selected':'').'>'.h($c['name']).'</option>';
        echo '</select></form></section>';
    }

    private static function domesticCockpit(int $wid,int $cid): void
    {
        $q=trim((string)($_GET['q']??''));
        $poId=(int)($_GET['po_id']??0);
        echo '<section class="card"><div class="section-title"><h2>کوک‌پیت بازرگانی داخلی</h2><span class="muted">PO → رسید → فاکتور تأمین‌کننده → سند AP → پرداخت → تخصیص · مبالغ به ریال (IRR)</span></div>';
        echo '<p class="muted">رسید انبار فقط عملیاتی است (بدون GRNI/GL). بدهی فقط با ثبت فاکتور تأمین‌کننده. چک/صیاد چرخه جداگانه دارد.</p>';
        echo '<form method="get" class="grid-form compact"><input type="hidden" name="page" value="trade"><input type="hidden" name="tab" value="domestic">';
        echo '<label>جستجو (شماره سفارش / فاکتور / تأمین‌کننده)<input name="q" value="'.h($q).'" maxlength="120" placeholder="مثلاً PO یا شماره فاکتور"></label>';
        echo '<button class="btn primary">جستجو</button></form></section>';

        try{$search=TradingCockpitDomain::searchDomestic($wid,$cid,$q,50);}catch(Throwable $e){
            echo '<section class="card alert danger">'.h(TradingCockpitDomain::explainException($e)).'</section>';return;
        }
        echo '<section class="card table-card"><div class="section-title"><h2>سفارش‌های خرید</h2></div><div class="table-wrap"><table><thead><tr><th>سفارش</th><th>تأمین‌کننده</th><th>وضعیت</th><th>مقدار پذیرفته</th><th>مبلغ PO (ریال)</th><th>فاکتورها</th><th></th></tr></thead><tbody>';
        foreach($search['rows'] as $r){
            $id=(int)$r['purchase_order_id'];
            echo '<tr'.($id===$poId?' class="active"':'').'><td>'.h($r['document_no']).'</td><td>'.h($r['supplier_name']??'—').'</td><td>'.h($r['workflow_status']).'</td><td>'.h($r['accepted_qty']).'</td><td>'.h($r['po_net_total_irr']).' IRR</td><td>'.(int)$r['bill_count'].'</td>';
            echo '<td><a class="btn tiny" href="index.php?page=trade&tab=domestic&po_id='.$id.($q!==''?'&q='.rawurlencode($q):'').'">خط زمانی</a></td></tr>';
        }
        if(!$search['rows'])echo '<tr><td colspan="7" class="muted">سفارشی یافت نشد.</td></tr>';
        echo '</tbody></table></div></section>';

        if($poId>0)self::lifecyclePanel($wid,$cid,$poId);
    }

    private static function lifecyclePanel(int $wid,int $cid,int $poId): void
    {
        try{$life=TradingCockpitDomain::poLifecycle($wid,$cid,$poId);}catch(Throwable $e){
            echo '<section class="card alert danger">'.h(TradingCockpitDomain::explainException($e)).'</section>';return;
        }
        $po=$life['purchase_order'];$tot=$life['totals'];$qty=$life['quantities'];
        echo '<section class="card"><div class="section-title"><div><h2>خط زمانی '.h($po['document_no']).'</h2>';
        echo '<span class="muted">'.h($po['supplier_name']).' · وضعیت سفارش: '.h($po['status']).' · ارز دفتر: IRR</span></div>';
        echo '<a class="btn tiny" href="index.php?page=p2p&po='.(int)$po['id'].'">تطبیق P2P</a></div>';
        echo '<div class="acc-metrics">';
        echo '<article><strong>'.h($qty['ordered_qty']).'</strong><span>مقدار سفارش</span></article>';
        echo '<article><strong>'.h($qty['accepted_qty']).'</strong><span>پذیرفته‌شده (GR)</span></article>';
        echo '<article><strong>'.h($qty['billed_qty']).'</strong><span>فاکتور شده</span></article>';
        echo '<article><strong>'.h($tot['ap_outstanding_irr']).' IRR</strong><span>مانده بدهی</span></article>';
        echo '</div>';
        echo '<p class="muted">AP ثبت‌شده: '.h($tot['ap_posted_irr']).' IRR · تخصیص‌یافته: '.h($tot['ap_allocated_irr']).' IRR · حرکت موجودی: '.(int)$tot['stock_movements'].' · GL روی رسید: '.(int)$tot['receipt_gl_vouchers'].'</p>';

        $supplierId=(int)$po['party_id'];
        $dupHint='';
        foreach($life['supplier_bills'] as $b){
            $dup=TradingCockpitDomain::findSupplierInvoiceDuplicates($wid,$cid,$supplierId,(string)$b['supplier_invoice_no']);
            if($dup['duplicate']){
                $dupHint.='کنترل تکرار برای «'.h($b['supplier_invoice_no']).'»: فعال (P2P unique + idempotency). ';
            }
        }
        if($dupHint!=='')echo '<p class="muted">'.h($dupHint).'</p>';

        echo '<div class="table-wrap"><table><thead><tr><th>گام</th><th>منبع</th><th>شناسه</th><th>وضعیت</th><th>مقدار</th><th>مبلغ (ریال)</th><th>زمان</th></tr></thead><tbody>';
        foreach($life['timeline'] as $t){
            $amt=$t['amount_irr']!==null?h($t['amount_irr']).' IRR':'—';
            $q=$t['qty']!==null?h($t['qty']):'—';
            echo '<tr><td>'.h($t['label']).'</td><td>'.h($t['source_type']).'</td><td>'.(int)$t['source_id'].'</td><td>'.h($t['status']).'</td><td>'.$q.'</td><td>'.$amt.'</td><td>'.h((string)$t['at']).'</td></tr>';
            if(!empty($t['allocations'])){
                foreach($t['allocations'] as $a){
                    echo '<tr><td class="muted">↳ تخصیص</td><td>erp_ap_allocations</td><td>'.(int)$a['id'].'</td><td>'.h($a['status']).'</td><td>—</td><td>'.h($a['amount_irr']).' IRR → inv #'.(int)$a['invoice_doc_id'].'</td><td>'.h((string)$a['created_at']).'</td></tr>';
                }
            }
        }
        echo '</tbody></table></div></section>';
    }

    private static function foreignTab(int $wid,int $cid): void
    {
        $caseId=(int)($_GET['case_id']??0);
        $assess=TradingCockpitDomain::foreignTradeAssessment($wid,$cid,$caseId?:null);
        echo '<section class="card"><div class="section-title"><h2>بازرگانی خارجی — ارزیابی آینده</h2><span class="muted">وضعیت: '.h($assess['status']).' · RC1 الزامی نیست</span></div>';
        echo '<p class="alert">ثبت GL برای ارز، اظهارنامه گمرکی و مالیات واردات فعال نشده است. پرونده/حمل/هزینه فقط عملیاتی و قابل ممیزی است.</p>';
        echo '<ul class="muted">';
        foreach($assess['blocked_until_verified'] as $k=>$v)echo '<li><strong>'.h($k).':</strong> '.h($v).'</li>';
        echo '</ul></section>';
        self::metrics($wid,$cid);
        if(Tenant::can('trade.manage'))self::caseForm($wid,$cid);
        self::caseList($wid,$cid,$caseId);
        if($caseId)self::caseDetail($wid,$cid,$caseId);
    }

    private static function metrics(int $wid,int $cid): void
    {
        $cases=TradeDomain::searchCases($wid,$cid,'');$risk=TradeDomain::riskSummary($wid,$cid,100);
        $open=count(array_filter($cases,static fn($r)=>!in_array($r['status'],['closed','canceled'],true)));
        $transit=count(array_filter($cases,static fn($r)=>($r['latest_shipment_status']??'')==='in_transit'));
        $high=count(array_filter($risk['rows'],static fn($r)=>$r['risk_level']==='high'));
        $projected=0;foreach($risk['rows'] as $r)$projected+=(float)$r['projected_landed_total_irr'];
        echo '<section class="acc-metrics"><article class="card"><strong>'.number_format($open).'</strong><span>پرونده باز</span></article><article class="card"><strong>'.number_format($transit).'</strong><span>در حال حمل</span></article><article class="card"><strong>'.number_format($high).'</strong><span>ریسک بالا</span></article><article class="card"><strong>'.number_format($projected).'</strong><span>Projected Landed Cost (ریال)</span></article></section>';
    }

    private static function caseForm(int $wid,int $cid): void
    {
        $docs=InventoryDomain::searchPurchaseDocuments($wid,$cid,'');
        echo '<section class="card"><details><summary>+ پرونده بازرگانی جدید (بدون GL ارزی)</summary><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="trade_save_case"><label>سند خرید<select name="purchase_doc_id" required><option value=""></option>';
        foreach($docs as $d)echo '<option value="'.(int)$d['id'].'">'.h($d['document_no'].' — '.($d['supplier_name']??'')).'</option>';
        echo '</select></label>';
        self::input('proforma_no','شماره Proforma');self::input('proforma_date','تاریخ Proforma','','date');
        self::input('origin_country','کشور مبدا');self::input('destination_country','کشور مقصد','ایران');
        self::select('incoterm','Incoterm',['EXW','FCA','FAS','FOB','CFR','CIF','CPT','CIP','DAP','DPU','DDP']);
        self::input('currency_code','ارز (ثبت عملیاتی)','USD');self::input('fx_rate_to_irr','نرخ تبدیل به ریال (گزارشی)','1','number');
        echo '<label class="span2">توضیحات<textarea name="notes"></textarea></label><button class="btn primary">ایجاد پرونده</button></form></details></section>';
    }

    private static function caseList(int $wid,int $cid,int $selected): void
    {
        $q=trim((string)($_GET['fq']??''));
        $rows=TradeDomain::searchCases($wid,$cid,$q);$risk=TradeDomain::riskSummary($wid,$cid,100);$rm=[];
        foreach($risk['rows'] as $x)$rm[(int)$x['trade_case_id']]=$x;
        echo '<section class="card table-card"><div class="section-title"><h2>پرونده‌های بازرگانی</h2><span class="muted">Search · بدون فعال‌سازی FX GL</span></div>';
        echo '<form method="get" class="grid-form compact"><input type="hidden" name="page" value="trade"><input type="hidden" name="tab" value="foreign"><label>جستجو<input name="fq" value="'.h($q).'" maxlength="120"></label><button class="btn tiny">جستجو</button></form>';
        echo '<div class="table-wrap"><table><thead><tr><th>پرونده</th><th>خرید</th><th>تأمین‌کننده</th><th>Incoterm</th><th>وضعیت</th><th>حمل</th><th>ETA</th><th>ریسک</th><th></th></tr></thead><tbody>';
        foreach($rows as $r){$rr=$rm[(int)$r['id']]??null;
            echo '<tr'.((int)$r['id']===$selected?' class="active"':'').'><td>'.h($r['case_no']).'</td><td>'.h($r['purchase_document_no']).'</td><td>'.h($r['supplier_name']).'</td><td>'.h($r['incoterm']).'</td><td>'.h($r['status']).'</td><td>'.h(($r['latest_shipment_mode']??'—').' / '.($r['latest_shipment_status']??'—')).'</td><td>'.h(AccountingRepository::faDate($r['latest_eta']??null)).'</td><td>'.h($rr['risk_level']??'low').'</td><td><a class="btn tiny" href="index.php?page=trade&tab=foreign&case_id='.(int)$r['id'].'">مشاهده</a></td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="9" class="muted">هنوز پرونده بازرگانی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function caseDetail(int $wid,int $cid,int $caseId): void
    {
        try{$d=TradeDomain::caseSnapshot($wid,$cid,$caseId);}catch(Throwable $e){echo '<section class="card alert danger">'.h(TradingCockpitDomain::explainException($e)).'</section>';return;}
        $c=$d['case'];$l=$d['landed_cost'];
        echo '<section class="card"><div class="section-title"><div><h2>'.h($c['case_no']).'</h2><span class="muted">'.h($c['purchase_document_no'].' • '.$c['supplier_name'].' • '.$c['incoterm']).'</span></div><a class="btn tiny" href="index.php?page=industrial&section=purchase&view='.(int)$c['purchase_doc_id'].'">سند خرید</a></div>';
        echo '<p class="muted">مبالغ Landed Cost گزارشی‌اند و جایگزین ثبت GL واردات نیستند.</p>';
        echo '<div class="acc-metrics"><article><strong>'.number_format((float)$l['purchase_base_irr']).'</strong><span>خرید پایه (ریال)</span></article><article><strong>'.number_format((float)$l['estimated_landed_total_irr']).'</strong><span>Estimated Landed</span></article><article><strong>'.number_format((float)$l['projected_landed_total_irr']).'</strong><span>Projected Landed</span></article><article><strong>'.number_format((float)$l['actual_recorded_landed_total_irr']).'</strong><span>Actual Recorded</span></article></div></section>';
        self::allocationTable($l);self::shipmentsSection($d['shipments'],$caseId);self::costSection($d['costs'],$caseId,$d['shipments']);self::clearanceSection($c,$caseId);self::milestoneSection($d['milestones'],$caseId,$d['shipments']);
    }

    private static function allocationTable(array $l): void
    {
        echo '<section class="card table-card"><div class="section-title"><h2>Landed Cost Allocation</h2><span class="muted">Stock Movement پایه تغییر نمی‌کند؛ Uplift بازرگانی جدا و قابل ممیزی است</span></div><div class="table-wrap"><table><thead><tr><th>کالا</th><th>تعداد</th><th>دریافت</th><th>Base Unit</th><th>Trade Allocation</th><th>Projected Unit</th><th>Received Value</th></tr></thead><tbody>';
        foreach($l['allocations'] as $a)echo '<tr><td>'.h($a['item_code'].' • '.$a['item_name']).'</td><td>'.number_format((float)$a['ordered_qty'],4).'</td><td>'.number_format((float)$a['accepted_qty'],4).'</td><td>'.number_format((float)$a['base_unit_cost_irr']).'</td><td>'.number_format((float)$a['projected_trade_allocated_irr']).'</td><td><b>'.number_format((float)$a['projected_landed_unit_cost_irr']).'</b></td><td>'.number_format((float)$a['received_inventory_value_projected_irr']).'</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function shipmentsSection(array $rows,int $caseId): void
    {
        echo '<section class="card table-card"><div class="section-title"><h2>Shipment</h2></div>';
        if(Tenant::can('trade.manage')){echo '<details><summary>+ محموله</summary><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="trade_save_shipment"><input type="hidden" name="trade_case_id" value="'.$caseId.'">';self::selectAssoc('mode','روش حمل',['sea'=>'دریایی','air'=>'هوایی','road'=>'جاده‌ای','rail'=>'ریلی','courier'=>'Courier']);self::input('shipment_no','شماره محموله');self::input('carrier','Carrier');self::input('forwarder','Forwarder');self::input('tracking_no','Tracking');self::input('origin_location','مبدا');self::input('destination_location','مقصد');self::input('etd','ETD','','date');self::input('eta','ETA','','date');self::selectAssoc('status','وضعیت',['planned'=>'planned','booked'=>'booked','in_transit'=>'in_transit','arrived'=>'arrived','customs'=>'customs','cleared'=>'cleared','delivered'=>'delivered']);self::input('package_count','تعداد بسته','0','number');self::input('gross_weight_kg','وزن ناخالص kg','0','number');echo '<button class="btn primary">ثبت محموله</button></form></details>';}
        echo '<div class="table-wrap"><table><thead><tr><th>شماره</th><th>روش</th><th>Forwarder</th><th>Tracking</th><th>ETD</th><th>ETA</th><th>ATA</th><th>وضعیت</th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td>'.h($r['shipment_no']).'</td><td>'.h($r['mode']).'</td><td>'.h($r['forwarder']).'</td><td>'.h($r['tracking_no']).'</td><td>'.h(AccountingRepository::faDate($r['etd'])).'</td><td>'.h(AccountingRepository::faDate($r['eta'])).'</td><td>'.h(AccountingRepository::faDate($r['ata'])).'</td><td>'.h($r['status']).'</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function costSection(array $rows,int $caseId,array $shipments): void
    {
        echo '<section class="card table-card"><div class="section-title"><h2>Trade Costs</h2><span class="muted">Estimated / Actual — بدون ثبت GL گمرک/ارز</span></div>';
        if(Tenant::can('trade.manage')){echo '<details><summary>+ هزینه</summary><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="trade_save_cost"><input type="hidden" name="trade_case_id" value="'.$caseId.'">';self::selectAssoc('basis','مبنا',['estimated'=>'برآوردی','actual'=>'واقعی']);self::selectAssoc('cost_type','نوع',['freight'=>'حمل','insurance'=>'بیمه','customs_duty'=>'حقوق گمرکی','import_tax'=>'مالیات واردات','brokerage'=>'ترخیص/کارگزاری','handling'=>'Handling','storage'=>'انبارداری','inspection'=>'بازرسی','bank_fee'=>'کارمزد بانکی','other'=>'سایر']);echo '<label>محموله<select name="shipment_id"><option value=""></option>';foreach($shipments as $s)echo '<option value="'.(int)$s['id'].'">'.h($s['shipment_no']).'</option>';echo '</select></label>';self::input('amount','مبلغ','0','number');self::input('currency_code','ارز','IRR');self::input('fx_rate_to_irr','نرخ به ریال','1','number');self::input('reference_no','مرجع');echo '<button class="btn primary">ثبت هزینه</button></form></details>';}
        echo '<div class="table-wrap"><table><thead><tr><th>مبنا</th><th>نوع</th><th>مبلغ</th><th>ارز</th><th>نرخ</th><th>ریال</th><th>محموله</th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td>'.h($r['basis']).'</td><td>'.h($r['cost_type']).'</td><td>'.number_format((float)$r['amount'],2).'</td><td>'.h($r['currency_code']).'</td><td>'.number_format((float)$r['fx_rate_to_irr'],2).'</td><td>'.number_format((float)$r['amount_irr']).'</td><td>'.h($r['shipment_no']).'</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function clearanceSection(array $c,int $caseId): void
    {
        echo '<section class="card"><div class="section-title"><h2>Customs / Clearance (metadata)</h2><span class="muted">'.h($c['clearance_status']).' · بدون GL</span></div>';
        if(Tenant::can('trade.manage')){echo '<form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="trade_save_clearance"><input type="hidden" name="trade_case_id" value="'.$caseId.'">';self::input('customs_declaration_no','شماره اظهارنامه',$c['customs_declaration_no']??'');self::input('customs_office','گمرک',$c['customs_office']??'');self::selectAssoc('clearance_status','وضعیت',['not_started'=>'not_started','docs_pending'=>'docs_pending','submitted'=>'submitted','assessed'=>'assessed','duties_pending'=>'duties_pending','release_pending'=>'release_pending','released'=>'released','hold'=>'hold'],$c['clearance_status']??'not_started');self::input('customs_entry_date','ورود گمرکی',AccountingRepository::faDate($c['customs_entry_date']??null),'date');self::input('customs_release_date','تاریخ ترخیص',AccountingRepository::faDate($c['customs_release_date']??null),'date');echo '<button class="btn primary">ذخیره وضعیت ترخیص</button></form>';}
        echo '</section>';
    }

    private static function milestoneSection(array $rows,int $caseId,array $shipments): void
    {
        echo '<section class="card table-card"><div class="section-title"><h2>Milestones</h2></div>';
        if(Tenant::can('trade.manage')){echo '<details><summary>+ Milestone</summary><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="trade_save_milestone"><input type="hidden" name="trade_case_id" value="'.$caseId.'">';self::selectAssoc('milestone_type','نوع',['booking'=>'booking','departed'=>'departed','arrived'=>'arrived','customs_entry'=>'customs_entry','customs_assessment'=>'customs_assessment','customs_paid'=>'customs_paid','customs_release'=>'customs_release','warehouse_received'=>'warehouse_received']);echo '<label>محموله<select name="shipment_id"><option value=""></option>';foreach($shipments as $s)echo '<option value="'.(int)$s['id'].'">'.h($s['shipment_no']).'</option>';echo '</select></label>';self::input('planned_date','برنامه','','date');self::input('actual_date','واقعی','','date');self::input('reference_no','مرجع');echo '<button class="btn primary">ثبت Milestone</button></form></details>';}
        echo '<div class="table-wrap"><table><thead><tr><th>نوع</th><th>محموله</th><th>برنامه</th><th>واقعی</th><th>وضعیت</th><th>مرجع</th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td>'.h($r['milestone_type']).'</td><td>'.h($r['shipment_no']).'</td><td>'.h(AccountingRepository::faDate($r['planned_date'])).'</td><td>'.h(AccountingRepository::faDate($r['actual_date'])).'</td><td>'.h($r['status']).'</td><td>'.h($r['reference_no']).'</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function input(string $name,string $label,$value='',string $type='text'): void
    {
        $cls=$type==='date'?' class="jalali-date"':'';$html=$type==='number'?'number':'text';
        echo '<label>'.h($label).'<input'.$cls.' type="'.$html.'" '.($html==='number'?'step="any"':'').' name="'.h($name).'" value="'.h((string)$value).'"></label>';
    }
    private static function select(string $name,string $label,array $values,string $selected=''): void{echo '<label>'.h($label).'<select name="'.h($name).'">';foreach($values as $v)echo '<option value="'.h($v).'" '.($v===$selected?'selected':'').'>'.h($v).'</option>';echo '</select></label>';}
    private static function selectAssoc(string $name,string $label,array $values,string $selected=''): void{echo '<label>'.h($label).'<select name="'.h($name).'">';foreach($values as $v=>$t)echo '<option value="'.h($v).'" '.((string)$v===(string)$selected?'selected':'').'>'.h($t).'</option>';echo '</select></label>';}
}
