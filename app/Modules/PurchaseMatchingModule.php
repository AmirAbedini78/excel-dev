<?php
/** Explicitly opt-in procurement three-way matching. */
final class PurchaseMatchingModule
{
    public static function handle(string $action): void
    {
        if(!ModuleRegistry::enabled('p2p'))throw new RuntimeException('ماژول تطبیق خرید فعال نیست.');
        Tenant::requirePermission('procurement.view');
        Tenant::requirePermission('accounting.purchase.manage');
        $wid=Tenant::id();$cid=AccountingRepository::companyId();$uid=(int)Auth::user()['id'];
        if($action==='p2p_approve_order'){
            PurchaseMatchingDomain::approveOrder($wid,$cid,$uid,(int)($_POST['po_id']??0));
            RuntimeCache::clearWorkspace($wid);flash('سفارش خرید تأیید شد.');
            redirect('index.php?page=p2p&po='.(int)($_POST['po_id']??0));
        }
        if($action==='p2p_submit'){
            $lines=$_POST['lines']??[];
            if(!is_array($lines))throw new RuntimeException('ردیف‌های فاکتور نامعتبر است.');
            $result=PurchaseMatchingDomain::submit($wid,$cid,$uid,(int)($_POST['po_id']??0),
                (string)($_POST['supplier_invoice_no']??''),(string)($_POST['invoice_date']??''),$lines);
            RuntimeCache::clearWorkspace($wid);
            flash(($result['existing']?'فاکتور قبلاً ثبت شده است: #':'درخواست فاکتور ثبت شد: #').$result['id']);
            redirect('index.php?page=p2p');
        }
        if($action==='p2p_approve_bill'){
            $result=PurchaseMatchingDomain::approve($wid,$cid,$uid,(int)($_POST['bill_id']??0));
            RuntimeCache::clearWorkspace($wid);
            flash(($result['existing']?'فاکتور پیش‌نویس قبلاً ایجاد شده است: #':'فاکتور خرید پیش‌نویس ایجاد شد: #').$result['id']);
            redirect('index.php?page=p2p');
        }
        if($action==='p2p_reject_bill'){
            PurchaseMatchingDomain::reject($wid,$cid,$uid,(int)($_POST['bill_id']??0),(string)($_POST['reason']??''));
            RuntimeCache::clearWorkspace($wid);flash('درخواست فاکتور با دلیل ثبت‌شده رد شد.');
            redirect('index.php?page=p2p');
        }
        throw new RuntimeException('عملیات تطبیق خرید ناشناخته است.');
    }

    public static function render(): void
    {
        Tenant::requirePermission('procurement.view');
        Tenant::requirePermission('accounting.purchase.view');
        $wid=Tenant::id();$cid=AccountingRepository::companyId();
        render_header('تطبیق سه‌طرفه خرید','Order → Posted Receipt → Supplier Invoice (Draft). بدون ثبت خودکار سند مالی یا پرداخت.');
        echo '<section class="card"><div class="section-title"><h2>سفارش‌های خرید</h2><a class="btn tiny" href="index.php?page=procurement">برگشت به تأمین</a></div>';
        echo '<p class="muted">ابتدا سفارش باید توسط شخصی غیر از ایجادکننده تأیید شود. فقط مقدار دریافت‌شده و پذیرفته‌شده قابل فاکتور است.</p>';
        echo '<div class="table-wrap"><table><thead><tr><th>سفارش</th><th>تأمین‌کننده</th><th>وضعیت</th><th>رسیدهای قطعی</th><th>عملیات</th></tr></thead><tbody>';
        foreach(PurchaseMatchingDomain::orders($wid,$cid) as $o){
            $poId=(int)$o['id'];
            echo '<tr><td>'.h($o['document_no']).'</td><td>'.h($o['supplier_name']??'—').'</td><td>'.h($o['workflow_status']).'</td><td>'.(int)$o['receipt_count'].'</td><td><a class="btn tiny" href="index.php?page=p2p&po='.$poId.'">مشاهده / تطبیق</a></td></tr>';
        }
        echo '</tbody></table></div></section>';
        $selected=(int)($_GET['po']??0);
        if($selected>0)self::orderForm($wid,$cid,$selected);
        echo '<section class="card"><div class="section-title"><h2>درخواست‌های فاکتور تأمین‌کننده</h2></div><div class="table-wrap"><table><thead><tr><th>شماره فاکتور</th><th>سفارش</th><th>تأمین‌کننده</th><th>مبلغ</th><th>وضعیت</th><th>خروجی حسابداری</th><th>کنترل دوم</th></tr></thead><tbody>';
        foreach(PurchaseMatchingDomain::bills($wid,$cid) as $bill){
            $id=(int)$bill['id'];$pending=$bill['status']==='pending';$different=(int)$bill['submitted_by']!==(int)Auth::user()['id'];
            echo '<tr><td>'.h($bill['supplier_invoice_no']).'</td><td>'.h($bill['order_no']).'</td><td>'.h($bill['supplier_name']??'—').'</td><td>'.h($bill['total_amount']).'</td><td>'.h($bill['status']).'</td>';
            echo '<td>'.($bill['canonical_doc_id']?'<a href="index.php?page=industrial&section=purchase&view='.(int)$bill['canonical_doc_id'].'">پیش‌نویس #'.(int)$bill['canonical_doc_id'].'</a>':'—').'</td><td>';
            if($pending&&$different&&Tenant::can('accounting.purchase.manage')){
                echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="p2p_approve_bill"><input type="hidden" name="bill_id" value="'.$id.'"><button class="btn tiny primary" onclick="return confirm(\'تطبیق مجدد و ایجاد فاکتور پیش‌نویس؟\')">تأیید و صدور پیش‌نویس</button></form>';
                echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="p2p_reject_bill"><input type="hidden" name="bill_id" value="'.$id.'"><input name="reason" minlength="5" maxlength="1000" required placeholder="دلیل رد"><button class="btn tiny danger">رد</button></form>';
            }elseif($pending)echo 'در انتظار تأییدکننده دیگر';
            else echo h($bill['review_note']??'—');
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted">در این نسخه درخواست ردشده به‌صورت تاریخی باقی می‌ماند و اصلاح/ارسال مجدد همان شماره فاکتور هنوز پشتیبانی نمی‌شود.</p></section>';
        render_footer();
    }

    private static function orderForm(int $wid,int $cid,int $poId): void
    {
        $info=PurchaseMatchingDomain::preview($wid,$cid,$poId);$po=$info['order'];$rows=$info['lines'];
        echo '<section class="card"><div class="section-title"><h2>سفارش '.h($po['document_no']).'</h2><a class="btn tiny" href="index.php?page=industrial&section=purchase&view='.$poId.'">مشاهده در حسابداری</a></div>';
        if($po['workflow_status']==='draft'){
            echo '<p class="muted">سفارش هنوز موقت است؛ درخواست فاکتور فقط پس از تأیید قابل ثبت است.</p>';
            if(Tenant::can('accounting.purchase.manage')&&(int)$po['created_by']!==(int)Auth::user()['id']){
                echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="p2p_approve_order"><input type="hidden" name="po_id" value="'.$poId.'"><button class="btn primary">تأیید سفارش خرید (کنترل دوم)</button></form>';
            }
            echo '</section>';return;
        }
        if(!in_array($po['workflow_status'],['approved','final'],true)){
            echo '<p class="alert danger">این سفارش قابل تطبیق نیست.</p></section>';return;
        }
        if(!Tenant::can('accounting.purchase.manage')){
            echo '<p class="muted">مجوز ثبت درخواست فاکتور ندارید.</p></section>';return;
        }
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="p2p_submit"><input type="hidden" name="po_id" value="'.$poId.'">';
        echo '<div class="grid-form compact"><label>شماره فاکتور تأمین‌کننده<input name="supplier_invoice_no" maxlength="120" required></label><label>تاریخ فاکتور<input class="jalali-date" name="invoice_date" value="'.h(Jalali::today()).'" required></label></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>کالا</th><th>سفارش</th><th>پذیرفته‌شده</th><th>قبلاً فاکتور شده</th><th>مقدار قابل فاکتور</th><th>مقدار این فاکتور</th><th>قیمت واحد (بدون تغییر)</th></tr></thead><tbody>';
        $has=false;
        foreach($rows as $r){
            $remaining=(string)$r['invoiceable_qty'];if((float)$remaining>0)$has=true;
            $id=(int)$r['id'];
            echo '<tr><td>#'.(int)$r['item_id'].' — '.h($r['description']??'').'</td><td>'.h($r['quantity']).'</td><td>'.h($r['accepted_qty']).'</td><td>'.h($r['billed_qty']).'</td><td><strong>'.h($remaining).'</strong></td>';
            echo '<td><input name="lines['.$id.'][quantity]" type="number" min="0" max="'.h($remaining).'" step="0.0001" value="0" '.((float)$remaining<=0?'disabled':'').'></td>';
            echo '<td>'.h($r['unit_price']).'<input type="hidden" name="lines['.$id.'][unit_price]" value="'.h($r['unit_price']).'"></td></tr>';
        }
        echo '</tbody></table></div>';
        if($has)echo '<button class="btn primary">ارسال درخواست تطبیق به تأییدکننده دوم</button>';
        else echo '<p class="muted">هنوز موجودی پذیرفته‌شده و فاکتورنشده‌ای برای این سفارش وجود ندارد.</p>';
        echo '</form></section>';
    }
}
