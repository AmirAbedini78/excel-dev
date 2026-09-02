<?php
final class InventoryProcurementModule
{
    public static function handle(string $action): void
    {
        if($action==='inv_select_company'){
            $return=(string)($_POST['return_page']??'inventory');
            if(!in_array($return,['inventory','procurement'],true))$return='inventory';
            Tenant::requirePermission($return==='procurement'?'procurement.view':'inventory.view');
            AccountingRepository::companyId();
            redirect('index.php?page='.$return);
        }
        if($action!=='inv_post_receipt')throw new RuntimeException('عملیات انبار نامعتبر است.');
        Tenant::requirePermission('inventory.manage');$wid=Tenant::id();$cid=AccountingRepository::companyId();$docId=(int)($_POST['purchase_doc_id']??0);$warehouseId=(int)($_POST['warehouse_id']??0);$raw=$_POST['lines']??[];$lines=[];
        if(is_array($raw))foreach($raw as $lineId=>$l){if(!is_array($l))continue;$lines[]=['purchase_line_id'=>(int)$lineId,'accepted_qty'=>(float)($l['accepted_qty']??0),'rejected_qty'=>(float)($l['rejected_qty']??0),'notes'=>(string)($l['notes']??'')];}
        $result=InventoryDomain::createReceipt($wid,$cid,(int)Auth::user()['id'],['purchase_doc_id'=>$docId,'warehouse_id'=>$warehouseId,'receipt_date'=>(string)($_POST['receipt_date']??''),'lines'=>$lines,'notes'=>(string)($_POST['notes']??'')]);
        Audit::log('inventory.receipt.post','acc_inventory_receipts',(int)$result['id'],'ثبت رسید انبار از خرید',null,null,['receipt_no'=>$result['receipt_no'],'purchase_doc_id'=>$docId]);RuntimeCache::clearWorkspace($wid);flash('رسید انبار ثبت شد: '.$result['receipt_no']);redirect('index.php?page=inventory');
    }

    public static function render(): void
    {
        $page=(string)($_GET['page']??'inventory');if($page==='procurement')self::procurement();else self::inventory();
    }

    private static function header(string $title,string $subtitle): array
    {
        $cid=AccountingRepository::companyId();if(!$cid)throw new RuntimeException('شرکت فعال مشخص نیست.');
        render_header($title,$subtitle);
        self::companyBar((string)($_GET['page']??'inventory'));
        return [Tenant::id(),$cid];
    }

    private static function companyBar(string $page): void
    {
        $companies=AccountingRepository::companies();$current=AccountingRepository::companyId();
        echo '<section class="card acc-company-bar"><div><strong>شرکت فعال</strong></div><form method="post" class="acc-inline">'.csrf_field().'<input type="hidden" name="action" value="inv_select_company"><input type="hidden" name="return_page" value="'.h($page).'"><select name="company_id" onchange="this.form.submit()">';
        foreach($companies as $c)echo '<option value="'.(int)$c['id'].'" '.((int)$c['id']===$current?'selected':'').'>'.h($c['name']).'</option>';
        echo '</select></form><div class="row-actions"><a class="btn tiny" href="index.php?page=procurement">تأمین و خرید</a><a class="btn tiny" href="index.php?page=inventory">انبار و موجودی</a></div></section>';
    }

    private static function procurement(): void
    {
        Tenant::requirePermission('procurement.view');[$wid,$cid]=self::header('تأمین و خرید','جریان خرید، ورودی مورد انتظار و پیشنهاد تأمین');$pipeline=InventoryDomain::purchasePipeline($wid,$cid,['open_only'=>true,'limit'=>200]);$risk=InventoryDomain::replenishmentRisk($wid,$cid,['limit'=>50]);
        echo '<section class="card"><div class="section-title"><div><h2>ورودی‌های مورد انتظار خرید</h2><span class="muted">سفارش/فاکتور خرید → دریافت انبار</span></div><a class="btn primary" href="index.php?page=industrial&section=purchase">اسناد خرید</a></div><div class="table-wrap"><table><thead><tr><th>سند</th><th>تأمین‌کننده</th><th>کالا</th><th>سفارش</th><th>پذیرفته</th><th>باز</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach($pipeline['rows'] as $r){echo '<tr><td>'.h($r['document_no']).'</td><td>'.h($r['supplier_name']??'—').'</td><td>'.h($r['item_code'].' • '.$r['item_name']).'</td><td>'.h($r['ordered_qty']).'</td><td>'.h($r['accepted_qty']).'</td><td><b>'.h($r['expected_inbound']).'</b></td><td>'.h($r['workflow_status']).'</td><td><a class="btn tiny" href="index.php?page=inventory&receive='.(int)$r['purchase_doc_id'].'">ثبت دریافت</a></td></tr>';}
        if(!$pipeline['rows'])echo '<tr><td colspan="8" class="muted">ورودی خرید بازی وجود ندارد.</td></tr>';echo '</tbody></table></div></section>';
        echo '<section class="card table-card"><div class="section-title"><h2>ریسک کمبود / پیشنهاد تأمین</h2><span class="muted">available + expected inbound در برابر حداقل/حداکثر کالا</span></div><div class="table-wrap"><table><thead><tr><th>کالا</th><th>در دسترس</th><th>ورودی</th><th>پیش‌بینی</th><th>حداقل</th><th>حداکثر</th><th>پیشنهاد خرید</th></tr></thead><tbody>';
        foreach($risk['rows'] as $r)echo '<tr><td>'.h($r['code'].' • '.$r['name']).'</td><td>'.h($r['available']).'</td><td>'.h($r['expected_inbound']).'</td><td>'.h($r['projected_available']).'</td><td>'.h($r['min_stock']).'</td><td>'.h($r['max_stock']).'</td><td><b>'.h($r['suggested_replenishment']).'</b></td></tr>';
        if(!$risk['rows'])echo '<tr><td colspan="7" class="muted">کمبود فعال بر اساس حداقل موجودی ثبت‌شده دیده نشد.</td></tr>';echo '</tbody></table></div></section>';render_footer();
    }

    private static function inventory(): void
    {
        Tenant::requirePermission('inventory.view');[$wid,$cid]=self::header('انبار و موجودی','موجودی Grounded بر پایه Stock Ledger و دریافت خرید');$positions=InventoryDomain::inventoryPosition($wid,$cid,['limit'=>200]);$receipts=InventoryDomain::receipts($wid,$cid,50);$receive=(int)($_GET['receive']??0);
        if($receive>0&&Tenant::can('inventory.manage'))self::receiptForm($wid,$cid,$receive);
        echo '<section class="card table-card"><div class="section-title"><div><h2>موجودی کالا</h2><span class="muted">On hand − Reserved = Available؛ ورودی مورد انتظار جداگانه نمایش داده می‌شود</span></div><a class="btn" href="index.php?page=procurement">جریان تأمین</a></div><div class="table-wrap"><table><thead><tr><th>کالا</th><th>موجود</th><th>رزرو</th><th>قابل استفاده</th><th>ورودی مورد انتظار</th><th>پیش‌بینی</th><th>حداقل</th><th>وضعیت</th></tr></thead><tbody>';
        foreach($positions['rows'] as $r){$state=!empty($r['shortage'])?'کمبود':'عادی';echo '<tr><td>'.h($r['code'].' • '.$r['name']).'</td><td>'.h($r['on_hand']).'</td><td>'.h($r['reserved']).'</td><td><b>'.h($r['available']).'</b></td><td>'.h($r['expected_inbound']).'</td><td>'.h($r['projected_available']).'</td><td>'.h($r['min_stock']).'</td><td>'.h($state).'</td></tr>';}
        echo '</tbody></table></div></section>';
        echo '<section class="card table-card"><div class="section-title"><h2>رسیدهای اخیر انبار</h2><span class="muted">فقط مقدار پذیرفته‌شده وارد Stock Ledger می‌شود</span></div><div class="table-wrap"><table><thead><tr><th>رسید</th><th>تاریخ</th><th>سند خرید</th><th>تأمین‌کننده</th><th>انبار</th><th>پذیرفته</th><th>ردشده</th></tr></thead><tbody>';
        foreach($receipts as $r)echo '<tr><td>'.h($r['receipt_no']).'</td><td>'.h(AccountingRepository::faDate($r['receipt_date'])).'</td><td>'.h($r['purchase_document_no']??'—').'</td><td>'.h($r['supplier_name']??'—').'</td><td>'.h($r['warehouse_name']).'</td><td>'.h($r['accepted_quantity']).'</td><td>'.h($r['rejected_quantity']).'</td></tr>';
        if(!$receipts)echo '<tr><td colspan="7" class="muted">هنوز رسید انباری ثبت نشده است.</td></tr>';echo '</tbody></table></div></section>';render_footer();
    }

    private static function receiptForm(int $wid,int $cid,int $docId): void
    {
        $doc=InventoryDomain::purchaseDocument($wid,$cid,$docId);if(!$doc){echo '<div class="alert danger">سند خرید کالایی معتبر پیدا نشد.</div>';return;}$pipeline=InventoryDomain::purchasePipeline($wid,$cid,['purchase_doc_id'=>$docId,'open_only'=>true,'limit'=>100]);$warehouses=InventoryDomain::searchWarehouses($wid,$cid,'');
        echo '<section class="card"><div class="section-title"><div><h2>ثبت دریافت از '.h($doc['document_no']).'</h2><span class="muted">'.h($doc['supplier_name']??'').'</span></div><a class="btn tiny" href="index.php?page=industrial&section=purchase&view='.(int)$docId.'">مشاهده خرید</a></div>';
        if(!$warehouses){echo '<div class="alert danger">ابتدا یک انبار فعال در اطلاعات پایه حسابداری تعریف کنید.</div></section>';return;}if(!$pipeline['rows']){echo '<div class="alert">این سند ورودی بازی برای دریافت ندارد.</div></section>';return;}
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="inv_post_receipt"><input type="hidden" name="purchase_doc_id" value="'.(int)$docId.'"><div class="grid-form compact"><label>تاریخ رسید<input class="jalali-date" name="receipt_date" value="'.h(Jalali::today()).'" required></label><label>انبار<select name="warehouse_id" required><option value="">انتخاب انبار</option>';
        foreach($warehouses as $w)echo '<option value="'.(int)$w['id'].'">'.h($w['code'].' • '.$w['name']).'</option>';echo '</select></label><label class="span2">توضیحات<textarea name="notes"></textarea></label></div><div class="table-wrap"><table><thead><tr><th>کالا</th><th>سفارش</th><th>قبلاً پذیرفته</th><th>باقیمانده</th><th>پذیرفته این رسید</th><th>ردشده</th></tr></thead><tbody>';
        foreach($pipeline['rows'] as $r){$id=(int)$r['purchase_line_id'];echo '<tr><td>'.h($r['item_code'].' • '.$r['item_name']).'</td><td>'.h($r['ordered_qty']).'</td><td>'.h($r['accepted_qty']).'</td><td><b>'.h($r['expected_inbound']).'</b></td><td><input type="number" step="0.0001" min="0" max="'.h($r['expected_inbound']).'" name="lines['.$id.'][accepted_qty]" value="0"></td><td><input type="number" step="0.0001" min="0" max="'.h($r['expected_inbound']).'" name="lines['.$id.'][rejected_qty]" value="0"></td></tr>';}
        echo '</tbody></table></div><button class="btn primary">ثبت و Post رسید انبار</button></form></section>';
    }
}
