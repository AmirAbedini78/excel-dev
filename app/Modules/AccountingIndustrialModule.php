<?php
final class AccountingIndustrialModule
{
    private static array $sections=[
        'overview'=>'داشبورد','profile'=>'مشخصات شرکت','master'=>'اطلاعات پایه','accounts'=>'کدینگ حساب‌ها',
        'purchase'=>'خرید','sales'=>'فروش','vouchers'=>'اسناد حسابداری','production'=>'حسابداری صنعتی','treasury'=>'خزانه‌داری',
        'reports'=>'گزارش‌ها','settings'=>'تنظیمات ماژول',
    ];

    public static function handle(string $action): void
    {
        Tenant::requirePermission('accounting.view');
        if($action==='acc_select_company'){
            AccountingRepository::companyId();
            redirect('index.php?page=industrial&section='.urlencode((string)($_POST['section']??'overview')));
        }
        if($action==='acc_save_profile')self::saveProfile();
        if($action==='acc_save_master')self::saveMaster();
        if($action==='acc_delete_master')self::deleteMaster();
        if($action==='acc_save_purchase')self::savePurchase();
        if($action==='acc_delete_purchase')self::deletePurchase();
        if($action==='acc_save_sale')self::saveSale();
        if($action==='acc_delete_sale')self::deleteSale();
        if($action==='acc_save_voucher')self::saveVoucher();
        if($action==='acc_delete_voucher')self::deleteVoucher();
        if($action==='acc_save_bom')self::saveBom();
        if($action==='acc_save_production_order')self::saveProductionOrder();
        if($action==='acc_save_cash')self::saveCash();
        if($action==='acc_save_check')self::saveCheck();
        if($action==='acc_save_settings')self::saveSettings();
        if($action==='acc_close_fiscal')self::closeFiscal();
    }

    public static function render(): void
    {
        Tenant::requirePermission('accounting.view');
        $cid=AccountingRepository::companyId();
        render_header('حسابداری و مالی','');
        self::companyBar();
        if(!$cid){
            echo '<section class="card acc-empty"><h2>شرکتی برای عملیات حسابداری تعریف نشده است</h2><a class="btn primary" href="index.php?page=companies">تعریف شرکت</a></section>';
            render_footer();return;
        }
        self::tabs();
        $s=(string)($_GET['section']??'overview');if(!isset(self::$sections[$s]))$s='overview';
        match($s){
            'profile'=>self::profile(),'master'=>self::master(),'accounts'=>self::accounts(),'purchase'=>self::purchase(),'sales'=>self::sales(),
            'vouchers'=>self::vouchers(),'production'=>self::production(),'treasury'=>self::treasury(),
            'reports'=>self::reports(),'settings'=>self::settings(),default=>self::overview(),
        };
        render_footer();
    }

    private static function companyBar(): void
    {
        $companies=AccountingRepository::companies();
        $current=AccountingRepository::companyId();

        echo '<section class="card acc-company-bar"><div><strong>شرکت فعال</strong></div>';
        echo '<form method="post" class="acc-inline">'.csrf_field().
            '<input type="hidden" name="action" value="acc_select_company">'.
            '<input type="hidden" name="section" value="'.h((string)($_GET['section']??'overview')).'">'.
            '<select name="company_id" onchange="this.form.submit()">';

        foreach($companies as $c){
            echo '<option value="'.(int)$c['id'].'" '.((int)$c['id']===$current?'selected':'').'>'.h($c['name']).'</option>';
        }

        echo '</select></form></section>';
    }
    private static function tabs(): void
    {
        $s=(string)($_GET['section']??'overview');echo '<nav class="acc-tabs">';
        foreach(self::$sections as $k=>$v){
            $perm=match($k){
                'purchase'=>'accounting.purchase.view','sales'=>'accounting.sales.view','vouchers'=>'accounting.vouchers.view',
                'production'=>'accounting.production.view','treasury'=>'accounting.treasury.view',
                'reports'=>'accounting.reports.view','settings'=>'accounting.settings.manage',default=>'accounting.view'
            };
            if(Tenant::can($perm))echo '<a class="'.($s===$k?'active':'').'" href="index.php?page=industrial&section='.$k.'">'.h($v).'</a>';
        }
        echo '</nav>';
    }

    private static function overview(): void
    {
        $cid=AccountingRepository::companyId();
        $wid=Tenant::id();

        $metrics=[
            'کالا/خدمت'=>self::scalar("SELECT COUNT(*) FROM acc_items WHERE workspace_id=? AND company_id=? AND active=1",[$wid,$cid]),
            'طرف حساب'=>self::scalar("SELECT COUNT(*) FROM acc_parties WHERE workspace_id=? AND company_id=? AND active=1",[$wid,$cid]),
            'اسناد خرید'=>self::scalar("SELECT COUNT(*) FROM acc_purchase_docs WHERE workspace_id=? AND company_id=?",[$wid,$cid]),
            'اسناد فروش'=>self::scalar("SELECT COUNT(*) FROM acc_sales_docs WHERE workspace_id=? AND company_id=?",[$wid,$cid]),
            'اسناد حسابداری'=>self::scalar("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=? AND company_id=?",[$wid,$cid]),
            'دستور تولید'=>self::scalar("SELECT COUNT(*) FROM acc_production_orders WHERE workspace_id=? AND company_id=?",[$wid,$cid]),
        ];

        echo '<section class="acc-metrics">';
        foreach($metrics as $k=>$v){
            echo '<article class="card"><strong>'.number_format($v).'</strong><span>'.h($k).'</span></article>';
        }
        echo '</section>';

        echo '<section class="card">';
        echo '<div class="section-title"><h2>عملیات سریع</h2></div>';
        echo '<div class="acc-quick-actions">';

        if(Tenant::can('accounting.master.manage')){
            echo '<a class="btn" href="index.php?page=industrial&section=master&entity=items">کالا و خدمات</a>';
            echo '<a class="btn" href="index.php?page=industrial&section=master&entity=parties">طرف حساب‌ها</a>';
            echo '<a class="btn" href="index.php?page=industrial&section=accounts">کدینگ حساب‌ها</a>';
        }
        if(Tenant::can('accounting.purchase.manage')){
            echo '<a class="btn primary" href="index.php?page=industrial&section=purchase">ثبت سند خرید</a>';
        }
        if(Tenant::can('accounting.sales.manage')){
            echo '<a class="btn primary" href="index.php?page=industrial&section=sales">ثبت فاکتور فروش</a>';
        }
        if(Tenant::can('accounting.vouchers.manage')){
            echo '<a class="btn primary" href="index.php?page=industrial&section=vouchers">ثبت سند حسابداری</a>';
        }
        if(Tenant::can('accounting.production.manage')){
            echo '<a class="btn" href="index.php?page=industrial&section=production">عملیات تولید</a>';
        }
        if(Tenant::can('accounting.treasury.manage')){
            echo '<a class="btn" href="index.php?page=industrial&section=treasury">خزانه‌داری</a>';
        }

        echo '</div></section>';

        $recent=pdo()->prepare(
            "SELECT d.id,d.document_no,d.document_date,d.net_total,p.name party_name
             FROM acc_purchase_docs d
             LEFT JOIN acc_parties p ON p.id=d.party_id
             WHERE d.workspace_id=? AND d.company_id=?
             ORDER BY d.document_date DESC,d.id DESC
             LIMIT 8"
        );
        $recent->execute([$wid,$cid]);
        $rows=$recent->fetchAll();

        echo '<section class="card table-card">';
        echo '<div class="section-title"><h2>آخرین اسناد خرید</h2><a class="btn tiny" href="index.php?page=industrial&section=purchase">مشاهده همه</a></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>شماره</th><th>تاریخ</th><th>طرف حساب</th><th>مبلغ</th></tr></thead><tbody>';

        foreach($rows as $r){
            echo '<tr>'.
                '<td><a href="index.php?page=industrial&section=purchase&view='.(int)$r['id'].'">'.h($r['document_no']).'</a></td>'.
                '<td>'.h(AccountingRepository::faDate($r['document_date'])).'</td>'.
                '<td>'.h($r['party_name']??'—').'</td>'.
                '<td>'.number_format((float)$r['net_total']).'</td>'.
                '</tr>';
        }

        if(!$rows){
            echo '<tr><td colspan="4" class="muted">سندی ثبت نشده است.</td></tr>';
        }

        echo '</tbody></table></div></section>';
    }
    private static function profile(): void
    {
        $c=AccountingRepository::company();$cid=(int)$c['id'];
        $st=pdo()->prepare("SELECT * FROM acc_company_profiles WHERE workspace_id=? AND company_id=? LIMIT 1");
        $st->execute([Tenant::id(),$cid]);$p=$st->fetch()?:[];
        echo '<section class="card"><div class="section-title"><h2>مشخصات شرکت و پرونده مالیاتی</h2><a class="btn tiny" href="index.php?page=companies">ویرایش مشخصات شرکت</a></div>';
        echo '<div class="acc-profile-summary"><span>نام: <b>'.h($c['name']).'</b></span><span>شناسه ملی: <b>'.h($c['national_id']??'—').'</b></span><span>کد اقتصادی: <b>'.h($c['economic_code']??'—').'</b></span><span>شماره ثبت: <b>'.h($c['registration_number']??'—').'</b></span></div>';
        if(Tenant::can('accounting.master.manage')){
            echo '<form method="post" class="grid-form acc-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_profile">';
            self::input('activity_type','نوع فعالیت',$p['activity_type']??'','select',['خدماتی','بازرگانی','تولیدی','پیمانکاری']);
            self::input('tax_office_code','کد اداره مالیاتی',$p['tax_office_code']??'');self::input('tax_office_name','نام اداره مالیاتی',$p['tax_office_name']??'');self::input('tax_case_class','کلاسه پرونده',$p['tax_case_class']??'');
            self::input('trade_name','نام واحد شغلی / شهرت',$p['trade_name']??'');self::input('business_license_no','شماره پروانه کسب',$p['business_license_no']??'');self::input('business_license_date','تاریخ پروانه کسب',AccountingRepository::faDate($p['business_license_date']??null),'date');
            self::input('fax','فکس',$p['fax']??'');self::input('mobile','موبایل‌های تکمیلی',$p['mobile']??'');self::input('email','ایمیل مالی',$p['email']??'');self::input('messenger','واتس‌اپ / تلگرام / ایتا / بله',$p['messenger']??'');
            if(Tenant::can('accounting.taxkeys.manage')){
                self::input('taxpayer_token_type','نوع توکن مودیان',$p['taxpayer_token_type']??'','select',['نرم افزاری','سخت افزاری']);
                self::input('taxpayer_branch_code','کد شعبه سامانه مودیان',$p['taxpayer_branch_code']??'');self::input('tax_memory_uid','شناسه یکتای حافظه مالیاتی',$p['tax_memory_uid']??'');
                echo '<label class="span2">کلید عمومی<textarea name="taxpayer_public_key" rows="4">'.h($p['taxpayer_public_key']??'').'</textarea></label>';
                echo '<label class="span2">کلید خصوصی <small>خالی = حفظ مقدار قبلی؛ در DB رمزنگاری می‌شود.</small><textarea name="taxpayer_private_key" rows="4" autocomplete="off"></textarea></label>';
            }
            echo '<button class="btn primary">ذخیره پروفایل مالی</button></form>';
        }
        echo '</section>';
    }

    private static function master(): void
    {
        $entity=(string)($_GET['entity']??'items');$configs=self::masterConfigs();if(!isset($configs[$entity]))$entity='items';$cfg=$configs[$entity];
        echo '<div class="acc-subtabs">';foreach($configs as $k=>$x)echo '<a class="'.($entity===$k?'active':'').'" href="index.php?page=industrial&section=master&entity='.$k.'">'.h($x['title']).'</a>';echo '</div>';
        if(Tenant::can('accounting.master.manage'))self::masterForm($entity,$cfg);self::masterList($entity,$cfg);
    }

    private static function masterConfigs(): array
    {
        return [
            'items'=>['title'=>'کالا و خدمت','table'=>'acc_items','fields'=>[['code','کد کالا','text'],['name','نام کالا/خدمت','text'],['item_type','نوع','select',['material'=>'مواد اولیه','product'=>'محصول','service'=>'خدمت','wip'=>'در جریان ساخت']],['taxpayer_goods_id','شناسه مودیان','text'],['base_unit_id','واحد اصلی','fk','acc_units'],['purchase_price_1','قیمت خرید ۱','number'],['purchase_price_2','قیمت خرید ۲','number'],['purchase_price_3','قیمت خرید ۳','number'],['min_stock','حداقل موجودی','number'],['max_stock','حداکثر موجودی','number'],['barcode','بارکد','text']],'cols'=>['code'=>'کد','name'=>'نام','item_type'=>'نوع','taxpayer_goods_id'=>'شناسه مودیان','purchase_price_1'=>'قیمت خرید ۱']],
            'parties'=>['title'=>'طرف حساب','table'=>'acc_parties','fields'=>[['code','کد','text'],['name','نام طرف حساب','text'],['party_type','نوع','select',['supplier'=>'تامین‌کننده','customer'=>'مشتری','both'=>'هر دو']],['national_id','شناسه ملی','text'],['economic_code','کد اقتصادی','text'],['mobile','موبایل','text'],['phone','تلفن','text'],['credit_limit','سقف اعتبار','number'],['address','آدرس','textarea']],'cols'=>['code'=>'کد','name'=>'نام','party_type'=>'نوع','national_id'=>'شناسه ملی','mobile'=>'موبایل']],
            'cost_centers'=>['title'=>'مرکز هزینه','table'=>'acc_cost_centers','fields'=>[['code','کد','text'],['name','نام مرکز هزینه','text']],'cols'=>['code'=>'کد','name'=>'نام']],
            'projects'=>['title'=>'پروژه','table'=>'acc_projects','fields'=>[['code','کد','text'],['name','نام پروژه','text'],['start_date','شروع','date'],['end_date','پایان','date']],'cols'=>['code'=>'کد','name'=>'نام','start_date'=>'شروع','end_date'=>'پایان']],
            'warehouses'=>['title'=>'انبار','table'=>'acc_warehouses','fields'=>[['code','کد','text'],['name','نام انبار','text'],['warehouse_type','نوع','select',['general'=>'عمومی','raw'=>'مواد اولیه','wip'=>'در جریان ساخت','finished'=>'محصول نهایی','scrap'=>'ضایعات']],['address','آدرس','textarea']],'cols'=>['code'=>'کد','name'=>'نام','warehouse_type'=>'نوع']],
            'units'=>['title'=>'واحد شمارش','table'=>'acc_units','fields'=>[['code','کد','text'],['name','نام واحد','text'],['decimal_places','تعداد اعشار','number']],'cols'=>['code'=>'کد','name'=>'نام','decimal_places'=>'اعشار']],
            'item_groups'=>['title'=>'گروه کالا','table'=>'acc_item_groups','fields'=>[['code','کد گروه','text'],['name','نام گروه','text']],'cols'=>['code'=>'کد','name'=>'نام']],
            'fiscal_years'=>['title'=>'سال مالی / عملیات دوره','table'=>'acc_fiscal_years','fields'=>[['title','عنوان سال مالی','text'],['start_date','تاریخ شروع','date'],['end_date','تاریخ پایان','date']],'cols'=>['title'=>'عنوان','start_date'=>'شروع','end_date'=>'پایان','status'=>'وضعیت']],
        ];
    }

    private static function masterForm(string $entity,array $cfg): void
    {
        echo '<section class="card"><details><summary>+ تعریف '.h($cfg['title']).'</summary><form method="post" class="grid-form acc-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_master"><input type="hidden" name="entity" value="'.h($entity).'">';
        foreach($cfg['fields'] as $f){
            [$name,$label,$type]=$f;$extra=$f[3]??null;
            if($type==='fk'){echo '<label>'.h($label).'<select name="'.h($name).'"><option value=""></option>';foreach(AccountingRepository::options((string)$extra) as $r)echo '<option value="'.(int)$r['id'].'">'.h(trim(($r['code']??'').' '.($r['name']??$r['title']??''))).'</option>';echo '</select></label>';}
            elseif($type==='select'){echo '<label>'.h($label).'<select name="'.h($name).'">';foreach((array)$extra as $v=>$t){if(is_int($v))$v=$t;echo '<option value="'.h($v).'">'.h($t).'</option>';}echo '</select></label>';}
            elseif($type==='textarea')echo '<label class="span2">'.h($label).'<textarea name="'.h($name).'"></textarea></label>';
            else self::input($name,$label,'',$type);
        }
        echo '<button class="btn primary">ذخیره</button></form></details></section>';
    }

    private static function masterList(string $entity,array $cfg): void
    {
        $cid=AccountingRepository::companyId();$wid=Tenant::id();$table=$cfg['table'];$where="workspace_id=? AND company_id=?";if($entity!=='fiscal_years')$where.=" AND active=1";
        $st=pdo()->prepare("SELECT * FROM `$table` WHERE $where ORDER BY id DESC LIMIT 300");$st->execute([$wid,$cid]);$rows=$st->fetchAll();
        echo '<section class="card table-card"><div class="section-title"><h2>'.h($cfg['title']).'</h2><span class="muted">'.count($rows).' رکورد</span></div><div class="table-wrap"><table><thead><tr>';foreach($cfg['cols'] as $v)echo '<th>'.h($v).'</th>';echo '<th>عملیات</th></tr></thead><tbody>';
        foreach($rows as $r){echo '<tr>';foreach($cfg['cols'] as $k=>$v){$val=$r[$k]??'';if(str_ends_with($k,'_date'))$val=AccountingRepository::faDate($val);echo '<td>'.h($val).'</td>';}echo '<td class="row-actions">';
            if($entity==='fiscal_years' && ($r['status']??'')==='open' && Tenant::can('accounting.settings.manage'))echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="action" value="acc_close_fiscal"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny">بستن سال</button></form>';
            if(Tenant::can('accounting.master.manage'))echo '<form method="post" class="inline-form" onsubmit="return confirm(\'غیرفعال شود؟\')">'.csrf_field().'<input type="hidden" name="action" value="acc_delete_master"><input type="hidden" name="entity" value="'.h($entity).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny danger">حذف</button></form>';
            echo '</td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="'.(count($cfg['cols'])+1).'">هنوز رکوردی ثبت نشده است.</td></tr>';echo '</tbody></table></div></section>';
    }

    private static function accounts(): void
    {
        if(Tenant::can('accounting.master.manage')){
            echo '<section class="card"><details><summary>+ حساب جدید</summary><form method="post" class="grid-form acc-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_master"><input type="hidden" name="entity" value="accounts">';
            self::input('code','کد حساب','');self::input('name','نام حساب','');echo '<label>حساب والد<select name="parent_id"><option value=""></option>';foreach(AccountingRepository::options('acc_accounts') as $a)echo '<option value="'.(int)$a['id'].'">'.h($a['code'].' - '.$a['name']).'</option>';echo '</select></label>';
            self::input('nature','ماهیت','','select',['debit'=>'بدهکار','credit'=>'بستانکار','both'=>'دو ماهیت']);self::input('account_type','نوع حساب','','select',['asset'=>'دارایی','liability'=>'بدهی','equity'=>'حقوق مالکانه','revenue'=>'درآمد','expense'=>'هزینه','contra'=>'کاهنده']);echo '<button class="btn primary">ذخیره حساب</button></form></details></section>';
        }
        $st=pdo()->prepare("SELECT a.*,p.code parent_code,p.name parent_name FROM acc_accounts a LEFT JOIN acc_accounts p ON p.id=a.parent_id WHERE a.workspace_id=? AND a.company_id=? AND a.active=1 ORDER BY a.code");$st->execute([Tenant::id(),AccountingRepository::companyId()]);
        echo '<section class="card table-card"><div class="section-title"><h2>کدینگ حساب‌ها</h2></div><div class="table-wrap"><table><thead><tr><th>کد</th><th>نام</th><th>والد</th><th>ماهیت</th><th>نوع</th></tr></thead><tbody>';
        foreach($st->fetchAll() as $r)echo '<tr><td>'.h($r['code']).'</td><td>'.h($r['name']).'</td><td>'.h(trim(($r['parent_code']??'').' '.($r['parent_name']??''))).'</td><td>'.h($r['nature']).'</td><td>'.h($r['account_type']).'</td></tr>';echo '</tbody></table></div></section>';
    }

    private static function purchase(): void
    {
        Tenant::requirePermission('accounting.purchase.view');
        if(Tenant::can('accounting.purchase.manage'))self::purchaseForm();
        $st=pdo()->prepare("SELECT d.*,p.name party_name,w.name warehouse_name FROM acc_purchase_docs d
            LEFT JOIN acc_parties p ON p.id=d.party_id LEFT JOIN acc_warehouses w ON w.id=d.warehouse_id
            WHERE d.workspace_id=? AND d.company_id=? ORDER BY d.document_date DESC,d.id DESC LIMIT 300");
        $st->execute([Tenant::id(),AccountingRepository::companyId()]);$rows=$st->fetchAll();
        echo '<section class="card table-card"><div class="section-title"><h2>اسناد خرید</h2><span class="muted">پیش‌فاکتور، قرارداد، سفارش، فاکتور و برگشت کالا/خدمات</span></div><div class="table-wrap"><table><thead><tr><th>نوع</th><th>شماره</th><th>تاریخ</th><th>طرف حساب</th><th>انبار</th><th>وضعیت</th><th>مودیان</th><th>خالص</th><th></th></tr></thead><tbody>';
        foreach($rows as $r){
            echo '<tr><td>'.h(self::docTypeLabel($r['doc_type'])).'</td><td>'.h($r['document_no']).'</td><td>'.h(AccountingRepository::faDate($r['document_date'])).'</td><td>'.h($r['party_name']).'</td><td>'.h($r['warehouse_name']??'—').'</td><td>'.h($r['workflow_status']).'</td><td>'.h($r['taxpayer_status']).'</td><td>'.number_format((float)$r['net_total']).'</td><td class="row-actions"><a class="btn tiny" href="index.php?page=industrial&section=purchase&view='.(int)$r['id'].'">مشاهده</a>';
            if(Tenant::can('accounting.purchase.manage'))echo '<form method="post" class="inline-form" onsubmit="return confirm(\'سند حذف شود؟\')">'.csrf_field().'<input type="hidden" name="action" value="acc_delete_purchase"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny danger">حذف</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';if(($view=(int)($_GET['view']??0))>0)self::purchaseView($view);
    }

    private static function purchaseForm(): void
    {
        $types=[
            'purchase_preinvoice_goods'=>'پیش‌فاکتور خرید کالا','purchase_contract_goods'=>'قرارداد خرید کالا','purchase_order_goods'=>'سفارش خرید کالا',
            'purchase_invoice_goods'=>'فاکتور خرید کالا','purchase_return_goods'=>'برگشت از خرید کالا',
            'purchase_preinvoice_service'=>'پیش‌فاکتور خرید خدمات','purchase_contract_service'=>'قرارداد خرید خدمات','purchase_order_service'=>'سفارش خرید خدمات',
            'purchase_invoice_service'=>'فاکتور خرید خدمات','purchase_return_service'=>'برگشت از خرید خدمات'
        ];
        $parties=AccountingRepository::options('acc_parties');$warehouses=AccountingRepository::options('acc_warehouses');$cc=AccountingRepository::options('acc_cost_centers');$projects=AccountingRepository::options('acc_projects');$items=AccountingRepository::options('acc_items');$units=AccountingRepository::options('acc_units');
        echo '<section class="card"><details><summary>+ سند خرید جدید</summary><form method="post" class="acc-purchase-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_purchase"><div class="grid-form compact">';
        self::selectAssoc('doc_type','نوع سند',$types);self::input('document_no','شماره',AccountingRepository::nextNumber('acc_purchase_docs','document_no','PUR-'));self::input('party_invoice_no','شماره فاکتور طرف حساب','');self::input('document_date','تاریخ',Jalali::today(),'date');
        self::selectRows('party_id','طرف حساب *',$parties,true);self::selectRows('cost_center_id','مرکز هزینه',$cc);self::selectRows('project_id','پروژه',$projects);self::selectRows('warehouse_id','انبار',$warehouses,false,'acc-warehouse-field');
        self::input('workflow_status','وضعیت','','select',['draft'=>'موقت','approved'=>'تایید','final'=>'تصویب','void'=>'باطل']);self::input('taxpayer_status','وضعیت مودیان','','select',['not_sent'=>'ارسال نشده','approved'=>'تایید شده','rejected'=>'رد شده','canceled'=>'ابطال','waiting'=>'در انتظار واکنش']);
        echo '<label class="span2">توضیحات<textarea name="notes"></textarea></label></div><div class="acc-lines-head"><h3>اقلام سند</h3><button type="button" class="btn tiny primary" data-acc-add-line>+ ردیف</button></div>';
        echo '<div class="table-wrap"><table class="acc-entry-table"><thead><tr><th>کالا/خدمت</th><th>واحد</th><th>شرح</th><th>مقدار</th><th>قیمت واحد</th><th>تخفیف</th><th>%تخفیف</th><th>%ویزیتور</th><th>مرکز هزینه</th><th>انبار</th><th>پروژه</th><th>جمع</th><th></th></tr></thead><tbody data-acc-lines></tbody></table></div>';
        echo '<script type="application/json" id="accPurchaseMeta">'.json_encode(['items'=>$items,'units'=>$units,'cost_centers'=>$cc,'warehouses'=>$warehouses,'projects'=>$projects],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).'</script>';
        echo '<div class="acc-form-total"><span>جمع قبل تخفیف: <b data-acc-gross>0</b></span><span>تخفیف: <b data-acc-discount>0</b></span><span>خالص: <b data-acc-net>0</b></span></div><button class="btn primary">ذخیره سند خرید</button></form></details></section>';
    }

    private static function purchaseView(int $id): void
    {
        $st=pdo()->prepare("SELECT d.*,p.name party_name FROM acc_purchase_docs d LEFT JOIN acc_parties p ON p.id=d.party_id WHERE d.id=? AND d.workspace_id=? AND d.company_id=?");
        $st->execute([$id,Tenant::id(),AccountingRepository::companyId()]);$d=$st->fetch();if(!$d)return;
        $st=pdo()->prepare("SELECT l.*,i.code item_code,i.name item_name,u.name unit_name FROM acc_purchase_lines l LEFT JOIN acc_items i ON i.id=l.item_id LEFT JOIN acc_units u ON u.id=l.unit_id WHERE l.workspace_id=? AND l.purchase_doc_id=? ORDER BY l.line_no");
        $st->execute([Tenant::id(),$id]);$lines=$st->fetchAll();
        echo '<section class="card"><div class="section-title"><div><h2>'.h(self::docTypeLabel($d['doc_type']).' '.$d['document_no']).'</h2><div class="muted">'.h($d['party_name']).' • '.h(AccountingRepository::faDate($d['document_date'])).'</div></div><b>'.number_format((float)$d['net_total']).'</b></div><div class="table-wrap"><table><thead><tr><th>#</th><th>کد</th><th>کالا/خدمت</th><th>مقدار</th><th>واحد</th><th>فی</th><th>تخفیف</th><th>جمع</th></tr></thead><tbody>';
        foreach($lines as $l)echo '<tr><td>'.(int)$l['line_no'].'</td><td>'.h($l['item_code']).'</td><td>'.h($l['item_name']).'</td><td>'.h($l['quantity']).'</td><td>'.h($l['unit_name']).'</td><td>'.number_format((float)$l['unit_price']).'</td><td>'.number_format((float)$l['discount_amount']).'</td><td>'.number_format((float)$l['line_total']).'</td></tr>';echo '</tbody></table></div></section>';
    }

    private static function sales(): void
    {
        Tenant::requirePermission('accounting.sales.view');
        if(Tenant::can('accounting.sales.manage'))self::salesForm();
        $st=pdo()->prepare("SELECT d.*,p.name party_name,w.name warehouse_name FROM acc_sales_docs d LEFT JOIN acc_parties p ON p.id=d.party_id LEFT JOIN acc_warehouses w ON w.id=d.warehouse_id WHERE d.workspace_id=? AND d.company_id=? ORDER BY d.document_date DESC,d.id DESC LIMIT 300");
        $st->execute([Tenant::id(),AccountingRepository::companyId()]);$rows=$st->fetchAll();
        echo '<section class="card table-card"><div class="section-title"><h2>فروش و صورتحساب</h2><span class="muted">پیش‌فاکتور، فاکتور، برگشت از فروش و پیش‌نویس‌های ساخته‌شده توسط ایجنت</span></div><div class="table-wrap"><table><thead><tr><th>نوع</th><th>شماره</th><th>تاریخ</th><th>مشتری</th><th>وضعیت</th><th>مودیان</th><th>خالص</th><th></th></tr></thead><tbody>';
        foreach($rows as $r){echo '<tr><td>'.h(self::salesTypeLabel($r['doc_type'])).'</td><td>'.h($r['document_no']).'</td><td>'.h(AccountingRepository::faDate($r['document_date'])).'</td><td>'.h($r['party_name']??'—').'</td><td>'.h($r['workflow_status']).'</td><td>'.h($r['taxpayer_status']).'</td><td>'.number_format((float)$r['net_total']).'</td><td class="row-actions"><a class="btn tiny" href="index.php?page=industrial&section=sales&view='.(int)$r['id'].'">مشاهده</a>';
            if(Tenant::can('accounting.sales.manage') && ($r['workflow_status']??'draft')==='draft')echo '<form method="post" class="inline-form" onsubmit="return confirm(\'پیش‌نویس حذف شود؟\')">'.csrf_field().'<input type="hidden" name="action" value="acc_delete_sale"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny danger">حذف</button></form>';echo '</td></tr>';}
        if(!$rows)echo '<tr><td colspan="8" class="muted">هنوز سند فروشی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';if(($view=(int)($_GET['view']??0))>0)self::salesView($view);
    }

    private static function salesForm(): void
    {
        $parties=AccountingRepository::options('acc_parties');$warehouses=AccountingRepository::options('acc_warehouses');$cc=AccountingRepository::options('acc_cost_centers');$projects=AccountingRepository::options('acc_projects');$items=AccountingRepository::options('acc_items');$units=AccountingRepository::options('acc_units');
        echo '<section class="card"><details><summary>+ سند فروش جدید</summary><form method="post" class="acc-sales-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_sale"><div class="grid-form compact">';
        self::input('doc_type','نوع سند','','select',['invoice'=>'فاکتور فروش','preinvoice'=>'پیش‌فاکتور فروش','return'=>'برگشت از فروش']);self::input('document_no','شماره',AccountingRepository::nextNumber('acc_sales_docs','document_no','SAL-'));self::input('document_date','تاریخ',Jalali::today(),'date');self::input('due_date','سررسید','','date');self::selectRows('party_id','مشتری *',$parties,true);self::selectRows('warehouse_id','انبار',$warehouses);self::selectRows('cost_center_id','مرکز هزینه',$cc);self::selectRows('project_id','پروژه',$projects);self::input('workflow_status','وضعیت','','select',['draft'=>'موقت','approved'=>'تایید','final'=>'قطعی','void'=>'باطل']);self::input('taxpayer_status','وضعیت مودیان','','select',['not_sent'=>'ارسال نشده','waiting'=>'در انتظار','approved'=>'تایید شده','rejected'=>'رد شده','canceled'=>'ابطال']);echo '<label class="span2">توضیحات<textarea name="notes"></textarea></label></div>';
        echo '<div class="acc-lines-head"><h3>اقلام فروش</h3><button type="button" class="btn tiny primary" data-acc-add-sale-line>+ ردیف</button></div><div class="table-wrap"><table class="acc-entry-table"><thead><tr><th>کالا/خدمت</th><th>واحد</th><th>شرح</th><th>مقدار</th><th>قیمت واحد</th><th>تخفیف</th><th>% مالیات</th><th>انبار</th><th>مرکز هزینه</th><th>پروژه</th><th>جمع</th><th></th></tr></thead><tbody data-acc-sale-lines></tbody></table></div>';
        echo '<script type="application/json" id="accSalesMeta">'.json_encode(['items'=>$items,'units'=>$units,'warehouses'=>$warehouses,'cost_centers'=>$cc,'projects'=>$projects],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).'</script><div class="acc-form-total"><span>جمع: <b data-sale-gross>0</b></span><span>تخفیف: <b data-sale-discount>0</b></span><span>مالیات: <b data-sale-tax>0</b></span><span>خالص: <b data-sale-net>0</b></span></div><button class="btn primary">ذخیره پیش‌نویس فروش</button></form></details></section>';
    }

    private static function salesView(int $id): void
    {
        $st=pdo()->prepare("SELECT d.*,p.name party_name FROM acc_sales_docs d LEFT JOIN acc_parties p ON p.id=d.party_id WHERE d.id=? AND d.workspace_id=? AND d.company_id=? LIMIT 1");$st->execute([$id,Tenant::id(),AccountingRepository::companyId()]);$d=$st->fetch();if(!$d)return;
        $st=pdo()->prepare("SELECT l.*,i.code item_code,i.name item_name,u.name unit_name FROM acc_sales_lines l LEFT JOIN acc_items i ON i.id=l.item_id LEFT JOIN acc_units u ON u.id=l.unit_id WHERE l.workspace_id=? AND l.sales_doc_id=? ORDER BY l.line_no");$st->execute([Tenant::id(),$id]);$lines=$st->fetchAll();
        echo '<section class="card"><div class="section-title"><div><h2>'.h(self::salesTypeLabel($d['doc_type']).' '.$d['document_no']).'</h2><div class="muted">'.h($d['party_name']).' • '.h(AccountingRepository::faDate($d['document_date'])).' • '.h($d['workflow_status']).'</div></div><b>'.number_format((float)$d['net_total']).'</b></div><div class="table-wrap"><table><thead><tr><th>#</th><th>کد</th><th>کالا/خدمت</th><th>مقدار</th><th>واحد</th><th>فی</th><th>تخفیف</th><th>مالیات</th><th>جمع</th></tr></thead><tbody>';
        foreach($lines as $l)echo '<tr><td>'.(int)$l['line_no'].'</td><td>'.h($l['item_code']).'</td><td>'.h($l['item_name']).'</td><td>'.h($l['quantity']).'</td><td>'.h($l['unit_name']).'</td><td>'.number_format((float)$l['unit_price']).'</td><td>'.number_format((float)$l['discount_amount']).'</td><td>'.number_format((float)$l['tax_amount']).'</td><td>'.number_format((float)$l['line_total']).'</td></tr>';echo '</tbody></table></div></section>';
        try{
            $wid=Tenant::id();$cid=AccountingRepository::companyId();$f=SalesDomain::fulfillment($wid,$cid,['sales_doc_id'=>$id]);$m=SalesDomain::marginSummary($wid,$cid,$id);$deliveries=SalesDomain::deliveries($wid,$cid,$id,20);
            echo '<section class="card"><div class="section-title"><div><h3>Fulfillment / Margin</h3><span class="muted">Sales → Reservation → Delivery → Landed Cost → Margin</span></div></div>';
            echo '<div class="acc-metrics"><article><strong>'.number_format((float)$f['ordered_quantity'],4).'</strong><span>سفارش</span></article><article><strong>'.number_format((float)$f['reserved_quantity'],4).'</strong><span>رزرو</span></article><article><strong>'.number_format((float)$f['delivered_quantity'],4).'</strong><span>تحویل</span></article><article><strong>'.number_format((float)$f['outstanding_quantity'],4).'</strong><span>باقیمانده</span></article></div>';
            echo '<div class="acc-form-total"><span>فروش بدون مالیات: <b>'.number_format((float)$m['revenue_ex_tax_irr']).'</b></span><span>COGS: <b>'.number_format((float)$m['cogs_irr']).'</b></span><span>سود ناخالص: <b>'.number_format((float)$m['gross_margin_irr']).'</b></span><span>حاشیه: <b>'.number_format((float)$m['gross_margin_pct'],1).'%</b></span><span>مبنا: <b>'.h($m['margin_basis']).'</b></span></div>';
            if($deliveries){echo '<div class="table-wrap"><table><thead><tr><th>تحویل</th><th>تاریخ</th><th>انبار</th><th>مقدار</th><th>COGS</th><th>وضعیت</th></tr></thead><tbody>';foreach($deliveries as $x)echo '<tr><td>'.h($x['delivery_no']).'</td><td>'.h(AccountingRepository::faDate($x['delivery_date'])).'</td><td>'.h($x['warehouse_name']).'</td><td>'.number_format((float)$x['delivered_quantity'],4).'</td><td>'.number_format((float)$x['cogs_irr']).'</td><td>'.h($x['status']).'</td></tr>';echo '</tbody></table></div>';}
            echo '</section>';
        }catch(Throwable $e){echo '<section class="card alert warning">Fulfillment/Margin: '.h($e->getMessage()).'</section>';}
    }

    private static function vouchers(): void
    {
        Tenant::requirePermission('accounting.vouchers.view');if(Tenant::can('accounting.vouchers.manage'))self::voucherForm();
        $st=pdo()->prepare("SELECT * FROM acc_vouchers WHERE workspace_id=? AND company_id=? ORDER BY voucher_date DESC,id DESC LIMIT 300");$st->execute([Tenant::id(),AccountingRepository::companyId()]);
        echo '<section class="card table-card"><div class="section-title"><h2>اسناد حسابداری</h2></div><div class="table-wrap"><table><thead><tr><th>شماره</th><th>تاریخ</th><th>نوع</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach($st->fetchAll() as $r){echo '<tr><td>'.h($r['voucher_no']).'</td><td>'.h(AccountingRepository::faDate($r['voucher_date'])).'</td><td>'.h($r['voucher_type']).'</td><td>'.h($r['description']).'</td><td>'.number_format((float)$r['total_debit']).'</td><td>'.number_format((float)$r['total_credit']).'</td><td>'.h($r['status']).'</td><td class="row-actions"><a class="btn tiny" href="index.php?page=industrial&section=vouchers&view='.(int)$r['id'].'">مشاهده</a>';
            if(Tenant::can('accounting.vouchers.manage') && (string)$r['status']==='draft')echo '<form method="post" class="inline-form" onsubmit="return confirm(\'پیش‌نویس سند حذف شود؟\')">'.csrf_field().'<input type="hidden" name="action" value="acc_delete_voucher"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny danger">حذف</button></form>';echo '</td></tr>';}
        echo '</tbody></table></div></section>';if(($view=(int)($_GET['view']??0))>0)self::voucherView($view);
    }

    private static function voucherView(int $id): void
    {
        $wid=Tenant::id();$cid=AccountingRepository::companyId();
        $st=pdo()->prepare("SELECT * FROM acc_vouchers WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");$st->execute([$id,$wid,$cid]);$v=$st->fetch();if(!$v)return;
        $st=pdo()->prepare("SELECT l.*,a.code account_code,a.name account_name,p.code party_code,p.name party_name,cc.code cost_center_code,cc.name cost_center_name,pr.code project_code,pr.name project_name FROM acc_voucher_lines l LEFT JOIN acc_accounts a ON a.id=l.account_id AND a.workspace_id=l.workspace_id LEFT JOIN acc_parties p ON p.id=l.party_id AND p.workspace_id=l.workspace_id LEFT JOIN acc_cost_centers cc ON cc.id=l.cost_center_id AND cc.workspace_id=l.workspace_id LEFT JOIN acc_projects pr ON pr.id=l.project_id AND pr.workspace_id=l.workspace_id WHERE l.workspace_id=? AND l.voucher_id=? ORDER BY l.line_no");$st->execute([$wid,$id]);$lines=$st->fetchAll();
        $source=trim((string)($v['source_type']??''));$sourceLabel=$source==='ai_agent'?'ایجادشده توسط AI':($source!==''?$source:'ثبت دستی');
        $difference=(float)$v['total_debit']-(float)$v['total_credit'];
        echo '<section class="card"><div class="section-title"><div><h2>سند حسابداری '.h($v['voucher_no']).'</h2><div class="muted">'.h(AccountingRepository::faDate($v['voucher_date'])).' • '.h($v['voucher_type']).' • '.h($v['status']).' • '.h($sourceLabel).'</div></div><a class="btn tiny" href="index.php?page=industrial&section=vouchers">بستن جزئیات</a></div>';
        if(trim((string)($v['description']??''))!=='')echo '<p>'.h($v['description']).'</p>';
        echo '<div class="acc-form-total"><span>بدهکار: <b>'.number_format((float)$v['total_debit']).'</b></span><span>بستانکار: <b>'.number_format((float)$v['total_credit']).'</b></span><span>اختلاف: <b>'.number_format($difference).'</b></span></div>';
        echo '<div class="table-wrap"><table class="acc-entry-table"><thead><tr><th>#</th><th>حساب</th><th>طرف حساب</th><th>مرکز هزینه</th><th>پروژه</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th></tr></thead><tbody>';
        foreach($lines as $l){
            $account=trim((string)($l['account_code']??'').' - '.(string)($l['account_name']??''),' -');
            $party=trim((string)($l['party_code']??'').' - '.(string)($l['party_name']??''),' -');
            $costCenter=trim((string)($l['cost_center_code']??'').' - '.(string)($l['cost_center_name']??''),' -');
            $project=trim((string)($l['project_code']??'').' - '.(string)($l['project_name']??''),' -');
            echo '<tr><td>'.(int)$l['line_no'].'</td><td>'.h($account?:'—').'</td><td>'.h($party?:'—').'</td><td>'.h($costCenter?:'—').'</td><td>'.h($project?:'—').'</td><td>'.h($l['description']??'').'</td><td>'.number_format((float)$l['debit']).'</td><td>'.number_format((float)$l['credit']).'</td></tr>';
        }
        if(!$lines)echo '<tr><td colspan="8" class="muted">برای این سند آرتیکلی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function voucherForm(): void
    {
        $accounts=AccountingRepository::options('acc_accounts');$parties=AccountingRepository::options('acc_parties');$cc=AccountingRepository::options('acc_cost_centers');$projects=AccountingRepository::options('acc_projects');
        echo '<section class="card"><details><summary>+ سند حسابداری جدید</summary><form method="post" class="acc-voucher-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_voucher"><div class="grid-form compact">';
        self::input('voucher_no','شماره سند',AccountingRepository::nextNumber('acc_vouchers','voucher_no','JV-'));self::input('voucher_date','تاریخ',Jalali::today(),'date');self::input('voucher_type','نوع','','select',['general'=>'عمومی','opening'=>'افتتاحیه','closing'=>'اختتامیه','adjustment'=>'اصلاحی']);self::input('status','وضعیت','','select',['draft'=>'موقت','approved'=>'تایید','final'=>'قطعی']);echo '<label class="span2">شرح<input name="description"></label></div>';
        echo '<div class="acc-lines-head"><h3>آرتیکل‌ها</h3><button type="button" class="btn tiny primary" data-acc-add-voucher-line>+ آرتیکل</button></div><div class="table-wrap"><table class="acc-entry-table"><thead><tr><th>حساب</th><th>طرف حساب</th><th>مرکز هزینه</th><th>پروژه</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th></th></tr></thead><tbody data-acc-voucher-lines></tbody></table></div>';
        echo '<script type="application/json" id="accVoucherMeta">'.json_encode(['accounts'=>$accounts,'parties'=>$parties,'cost_centers'=>$cc,'projects'=>$projects],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).'</script><div class="acc-form-total"><span>بدهکار: <b data-acc-debit>0</b></span><span>بستانکار: <b data-acc-credit>0</b></span><span>اختلاف: <b data-acc-balance>0</b></span></div><button class="btn primary">ذخیره سند</button></form></details></section>';
    }

    private static function production(): void
    {
        Tenant::requirePermission('accounting.production.view');
        echo '<section class="acc-grid2"><article class="card"><h2>فرمول ساخت (BOM)</h2><p class="muted">مواد مستقیم، ضایعات و مراحل ساخت.</p>';if(Tenant::can('accounting.production.manage'))self::bomForm();
        $st=pdo()->prepare("SELECT b.*,i.name product_name FROM acc_boms b LEFT JOIN acc_items i ON i.id=b.product_item_id WHERE b.workspace_id=? AND b.company_id=? AND b.active=1 ORDER BY b.id DESC LIMIT 100");$st->execute([Tenant::id(),AccountingRepository::companyId()]);
        foreach($st->fetchAll() as $b)echo '<div class="acc-list-row"><span><b>'.h($b['code']).'</b> — '.h($b['product_name']).'</span><small>نسخه '.h($b['version_no']?:'—').' / خروجی '.h($b['output_qty']).'</small></div>';echo '</article>';
        echo '<article class="card"><h2>دستور تولید و بهای تمام‌شده</h2><p class="muted">مواد، دستمزد، سربار، پیمانکار و ضایعات.</p>';if(Tenant::can('accounting.production.manage'))self::productionForm();
        $st=pdo()->prepare("SELECT o.*,i.name product_name FROM acc_production_orders o LEFT JOIN acc_items i ON i.id=o.product_item_id WHERE o.workspace_id=? AND o.company_id=? ORDER BY o.id DESC LIMIT 100");$st->execute([Tenant::id(),AccountingRepository::companyId()]);
        foreach($st->fetchAll() as $o){$total=(float)$o['actual_total_cost'];$unit=(float)$o['actual_qty']>0?$total/(float)$o['actual_qty']:0;echo '<div class="acc-list-row"><span><b>'.h($o['order_no']).'</b> — '.h($o['product_name']).' <em>'.h($o['status']).'</em></span><small>بهای کل '.number_format($total).' • بهای واحد '.number_format($unit).'</small></div>';}echo '</article></section>';
        echo '<section class="card"><h2>فرمول هزینه‌یابی</h2><div class="acc-cost-formula"><span>مواد مستقیم</span><b>+</b><span>دستمزد مستقیم</span><b>+</b><span>سربار ساخت</span><b>+</b><span>پیمانکار</span><b>+</b><span>ضایعات</span><b>=</b><strong>بهای واقعی تولید</strong></div></section>';
    }

    private static function bomForm(): void
    {
        $products=array_values(array_filter(AccountingRepository::options('acc_items'),fn($x)=>in_array($x['item_type'],['product','wip'],true)));$materials=array_values(array_filter(AccountingRepository::options('acc_items'),fn($x)=>$x['item_type']!=='service'));$units=AccountingRepository::options('acc_units');
        echo '<details><summary>+ BOM جدید</summary><form method="post" class="acc-bom-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_bom"><div class="grid-form compact">';self::input('code','کد BOM','BOM-'.date('ymdHis'));self::input('name','نام فرمول','');self::selectRows('product_item_id','محصول',$products,true);self::input('version_no','نسخه','1');self::input('output_qty','مقدار خروجی','1','number');echo '</div><div class="acc-lines-head"><h3>مواد و مراحل</h3><button type="button" class="btn tiny primary" data-acc-add-bom-line>+ ماده</button></div><div class="table-wrap"><table class="acc-entry-table"><thead><tr><th>ماده</th><th>واحد</th><th>مقدار</th><th>%ضایعات</th><th>مرحله</th><th></th></tr></thead><tbody data-acc-bom-lines></tbody></table></div><script type="application/json" id="accBomMeta">'.json_encode(['items'=>$materials,'units'=>$units],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP).'</script><button class="btn primary">ذخیره BOM</button></form></details>';
    }

    private static function productionForm(): void
    {
        $products=array_values(array_filter(AccountingRepository::options('acc_items'),fn($x)=>in_array($x['item_type'],['product','wip'],true)));$boms=AccountingRepository::options('acc_boms');$warehouses=AccountingRepository::options('acc_warehouses');$cc=AccountingRepository::options('acc_cost_centers');$projects=AccountingRepository::options('acc_projects');
        echo '<details><summary>+ دستور تولید جدید</summary><form method="post" class="grid-form compact acc-form">'.csrf_field().'<input type="hidden" name="action" value="acc_save_production_order">';
        self::input('order_no','شماره دستور',AccountingRepository::nextNumber('acc_production_orders','order_no','PRD-'));self::selectRows('product_item_id','محصول',$products,true);self::selectRows('bom_id','BOM',$boms);self::input('planned_qty','مقدار برنامه','0','number');self::input('actual_qty','مقدار واقعی','0','number');self::selectRows('raw_warehouse_id','انبار مواد',$warehouses);self::selectRows('finished_warehouse_id','انبار محصول',$warehouses);self::selectRows('cost_center_id','مرکز هزینه',$cc);self::selectRows('project_id','پروژه',$projects);self::input('start_date','شروع',Jalali::today(),'date');self::input('end_date','پایان','','date');self::input('status','وضعیت','','select',['planned'=>'برنامه‌ریزی','in_progress'=>'در جریان','completed'=>'تکمیل','canceled'=>'لغو']);self::input('material_cost','مواد مستقیم','0','number');self::input('labor_cost','دستمزد مستقیم','0','number');self::input('overhead_cost','سربار','0','number');self::input('subcontract_cost','پیمانکار','0','number');self::input('scrap_cost','ضایعات','0','number');echo '<button class="btn primary">ذخیره دستور تولید</button></form></details>';
    }

    private static function treasury(): void
    {
        Tenant::requirePermission('accounting.treasury.view');
        echo '<section class="acc-grid2"><article class="card"><h2>بانک و صندوق</h2>';
        if(Tenant::can('accounting.treasury.manage')){
            echo '<details><summary>+ حساب بانک/صندوق</summary><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="acc_save_cash">';
            self::input('account_kind','نوع','','select',['bank'=>'بانک','cash'=>'صندوق','pos'=>'کارتخوان']);self::input('code','کد','');self::input('name','عنوان','');self::input('bank_name','نام بانک','');self::input('account_no','شماره حساب','');self::input('iban','شبا','');self::input('opening_balance','مانده اول دوره','0','number');echo '<button class="btn primary">ذخیره</button></form></details>';
        }
        foreach(AccountingRepository::options('acc_cash_accounts') as $x)echo '<div class="acc-list-row"><span><b>'.h($x['name']).'</b> — '.h($x['bank_name']).'</span><small>'.h($x['account_kind']).' • '.number_format((float)$x['opening_balance']).'</small></div>';
        echo '</article><article class="card"><h2>چک‌های دریافتنی و پرداختنی</h2>';
        if(Tenant::can('accounting.treasury.manage')){
            echo '<details><summary>+ ثبت چک</summary><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="acc_save_check">';
            self::input('direction','نوع','','select',['receivable'=>'دریافتنی','payable'=>'پرداختنی']);self::input('check_no','شماره چک','');self::input('amount','مبلغ','0','number');self::input('due_date','سررسید','','date');self::selectRows('party_id','طرف حساب',AccountingRepository::options('acc_parties'));self::selectRows('cash_account_id','بانک/صندوق',AccountingRepository::options('acc_cash_accounts'));self::input('status','وضعیت','','select',['open'=>'باز','received'=>'وصول','paid'=>'پرداخت','bounced'=>'برگشتی','canceled'=>'باطل']);echo '<button class="btn primary">ذخیره</button></form></details>';
        }
        $st=pdo()->prepare("SELECT c.*,p.name party_name FROM acc_checks c LEFT JOIN acc_parties p ON p.id=c.party_id WHERE c.workspace_id=? AND c.company_id=? ORDER BY c.due_date,c.id DESC LIMIT 100");$st->execute([Tenant::id(),AccountingRepository::companyId()]);
        foreach($st->fetchAll() as $x)echo '<div class="acc-list-row"><span><b>'.h($x['check_no']).'</b> — '.h($x['party_name']).'</span><small>'.h(AccountingRepository::faDate($x['due_date'])).' • '.number_format((float)$x['amount']).' • '.h($x['status']).'</small></div>';echo '</article></section>';
    }

    private static function reports(): void
    {
        Tenant::requirePermission('accounting.reports.view');

        $cid=AccountingRepository::companyId();
        $wid=Tenant::id();

        echo '<section class="acc-grid2"><article class="card"><h2>تراز آزمایشی</h2>';

        $st=pdo()->prepare(
            "SELECT a.code,a.name,
                    COALESCE(SUM(l.debit),0) debit,
                    COALESCE(SUM(l.credit),0) credit
             FROM acc_accounts a
             LEFT JOIN acc_voucher_lines l
               ON l.account_id=a.id AND l.workspace_id=a.workspace_id
             LEFT JOIN acc_vouchers v
               ON v.id=l.voucher_id
              AND v.workspace_id=a.workspace_id
              AND v.company_id=a.company_id
             WHERE a.workspace_id=? AND a.company_id=? AND a.active=1
             GROUP BY a.id,a.code,a.name
             ORDER BY a.code
             LIMIT 500"
        );
        $st->execute([$wid,$cid]);

        echo '<div class="table-wrap"><table><thead><tr><th>کد</th><th>حساب</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>';

        foreach($st->fetchAll() as $r){
            $bal=(float)$r['debit']-(float)$r['credit'];
            echo '<tr>'.
                '<td>'.h($r['code']).'</td>'.
                '<td>'.h($r['name']).'</td>'.
                '<td>'.number_format((float)$r['debit']).'</td>'.
                '<td>'.number_format((float)$r['credit']).'</td>'.
                '<td>'.number_format($bal).'</td>'.
                '</tr>';
        }

        echo '</tbody></table></div></article>';

        echo '<article class="card"><h2>خرید به تفکیک طرف حساب</h2>';

        $st=pdo()->prepare(
            "SELECT p.name,COUNT(d.id) doc_count,COALESCE(SUM(d.net_total),0) total
             FROM acc_purchase_docs d
             LEFT JOIN acc_parties p ON p.id=d.party_id
             WHERE d.workspace_id=? AND d.company_id=?
             GROUP BY d.party_id,p.name
             ORDER BY total DESC
             LIMIT 50"
        );
        $st->execute([$wid,$cid]);

        foreach($st->fetchAll() as $r){
            echo '<div class="acc-list-row">'.
                '<span>'.h($r['name']?:'بدون طرف حساب').'</span>'.
                '<small>'.number_format((float)$r['total']).' • '.(int)$r['doc_count'].' سند</small>'.
                '</div>';
        }

        echo '</article></section>';
    }
    private static function settings(): void
    {
        Tenant::requirePermission('accounting.settings.manage');

        $section=(string)($_GET['settings_section']??'accounting');
        $titles=[
            'general'=>'عمومی',
            'purchase'=>'خرید',
            'inventory'=>'انبار',
            'sales'=>'فروش',
            'payroll'=>'حقوق و دستمزد',
            'tax'=>'مالیاتی/مودیان',
            'treasury'=>'خزانه‌داری',
            'accounting'=>'حسابداری'
        ];

        if(!isset($titles[$section]))$section='accounting';

        echo '<div class="acc-subtabs">';
        foreach($titles as $k=>$v){
            echo '<a class="'.($section===$k?'active':'').'" href="index.php?page=industrial&section=settings&settings_section='.$k.'">'.h($v).'</a>';
        }
        echo '</div>';

        echo '<section class="card">';
        echo '<div class="section-title"><h2>تنظیمات '.h($titles[$section]).'</h2></div>';

        echo '<form method="post" class="acc-settings-form">'.
            csrf_field().
            '<input type="hidden" name="action" value="acc_save_settings">'.
            '<input type="hidden" name="settings_section" value="'.h($section).'">'.
            '<div class="acc-settings-grid">';

        foreach(AccountingRepository::settings($section) as $s){
            $opts=$s['options_json']?json_decode($s['options_json'],true):null;

            echo '<label class="acc-setting"><span>'.h($s['label']).'</span>';

            if($s['control_type']==='bool'){
                echo '<select name="settings['.h($s['setting_key']).']">'.
                    '<option value="1" '.($s['value_text']==='1'?'selected':'').'>بله</option>'.
                    '<option value="0" '.($s['value_text']!=='1'?'selected':'').'>خیر</option>'.
                    '</select>';
            }elseif($s['control_type']==='select'){
                echo '<select name="settings['.h($s['setting_key']).']">';
                foreach((array)$opts as $v=>$label){
                    echo '<option value="'.h($v).'" '.((string)$s['value_text']===(string)$v?'selected':'').'>'.h($label).'</option>';
                }
                echo '</select>';
            }else{
                echo '<input type="'.($s['control_type']==='number'?'number':'text').'" '.
                    'name="settings['.h($s['setting_key']).']" value="'.h($s['value_text']).'">';
            }

            echo '</label>';
        }

        echo '</div><button class="btn primary">ذخیره تنظیمات</button></form></section>';
    }
    private static function saveProfile(): void
    {
        Tenant::requirePermission('accounting.master.manage');$cid=AccountingRepository::companyId();if(!$cid)throw new RuntimeException('شرکت فعال انتخاب نشده است.');
        $fields=['activity_type','tax_office_code','tax_office_name','tax_case_class','trade_name','business_license_no','fax','mobile','email','messenger','taxpayer_token_type','taxpayer_branch_code','tax_memory_uid','taxpayer_public_key'];$data=[];foreach($fields as $f)$data[$f]=trim((string)($_POST[$f]??''));
        $st=pdo()->prepare("SELECT taxpayer_private_key_enc FROM acc_company_profiles WHERE workspace_id=? AND company_id=? LIMIT 1");$st->execute([Tenant::id(),$cid]);$old=$st->fetch();$private=trim((string)($_POST['taxpayer_private_key']??''));$enc=$private!==''?encrypt_value($private):($old['taxpayer_private_key_enc']??null);
        pdo()->prepare("INSERT INTO acc_company_profiles (workspace_id,company_id,activity_type,tax_office_code,tax_office_name,tax_case_class,trade_name,business_license_no,business_license_date,fax,mobile,email,messenger,taxpayer_token_type,taxpayer_branch_code,tax_memory_uid,taxpayer_private_key_enc,taxpayer_public_key,updated_at,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE activity_type=VALUES(activity_type),tax_office_code=VALUES(tax_office_code),tax_office_name=VALUES(tax_office_name),tax_case_class=VALUES(tax_case_class),trade_name=VALUES(trade_name),business_license_no=VALUES(business_license_no),business_license_date=VALUES(business_license_date),fax=VALUES(fax),mobile=VALUES(mobile),email=VALUES(email),messenger=VALUES(messenger),taxpayer_token_type=VALUES(taxpayer_token_type),taxpayer_branch_code=VALUES(taxpayer_branch_code),tax_memory_uid=VALUES(tax_memory_uid),taxpayer_private_key_enc=VALUES(taxpayer_private_key_enc),taxpayer_public_key=VALUES(taxpayer_public_key),updated_at=NOW()")
            ->execute([Tenant::id(),$cid,$data['activity_type'],$data['tax_office_code'],$data['tax_office_name'],$data['tax_case_class'],$data['trade_name'],$data['business_license_no'],AccountingRepository::date($_POST['business_license_date']??''),$data['fax'],$data['mobile'],$data['email'],$data['messenger'],$data['taxpayer_token_type'],$data['taxpayer_branch_code'],$data['tax_memory_uid'],$enc,$data['taxpayer_public_key']]);
        self::audit('acc.profile.save','acc_company_profiles',$cid,'ذخیره پروفایل مالی');self::back('profile');
    }

    private static function saveMaster(): void
    {
        Tenant::requirePermission('accounting.master.manage');$entity=(string)($_POST['entity']??'');$cid=AccountingRepository::companyId();$wid=Tenant::id();
        $map=[
            'items'=>['acc_items',['code','name','item_type','taxpayer_goods_id','base_unit_id','purchase_price_1','purchase_price_2','purchase_price_3','min_stock','max_stock','barcode']],
            'parties'=>['acc_parties',['code','name','party_type','national_id','economic_code','mobile','phone','credit_limit','address']],
            'cost_centers'=>['acc_cost_centers',['code','name']],'projects'=>['acc_projects',['code','name','start_date','end_date']],
            'warehouses'=>['acc_warehouses',['code','name','warehouse_type','address']],'units'=>['acc_units',['code','name','decimal_places']],
            'item_groups'=>['acc_item_groups',['code','name']],'fiscal_years'=>['acc_fiscal_years',['title','start_date','end_date']],
            'accounts'=>['acc_accounts',['code','name','parent_id','nature','account_type']],
        ];if(!isset($map[$entity]))throw new RuntimeException('بخش نامعتبر است.');[$table,$fields]=$map[$entity];$vals=[];
        foreach($fields as $f){$v=$_POST[$f]??null;if(str_ends_with($f,'_date'))$v=AccountingRepository::date((string)$v);elseif(str_ends_with($f,'_id'))$v=(int)$v?:null;elseif(in_array($f,['purchase_price_1','purchase_price_2','purchase_price_3','min_stock','max_stock','credit_limit'],true))$v=(float)$v;elseif($f==='decimal_places')$v=max(0,min(6,(int)$v));else$v=trim((string)$v);$vals[]=$v;}
        if($entity==='items' && ($unit=(int)($_POST['base_unit_id']??0))>0 && !AccountingRepository::owns('acc_units',$unit))throw new RuntimeException('واحد انتخاب‌شده متعلق به شرکت فعال نیست.');
        if($entity==='accounts' && ($parent=(int)($_POST['parent_id']??0))>0 && !AccountingRepository::owns('acc_accounts',$parent))throw new RuntimeException('حساب والد متعلق به شرکت فعال نیست.');
        if(in_array('name',$fields,true)&&trim((string)($_POST['name']??''))==='')throw new RuntimeException('نام الزامی است.');if(in_array($entity,['items','cost_centers','projects','warehouses','accounts'],true)&&trim((string)($_POST['code']??''))==='')throw new RuntimeException('کد الزامی است.');
        $cols=implode(',',$fields);$marks=implode(',',array_fill(0,count($fields),'?'));$extraCols='';$extraVals=[];if($entity==='fiscal_years'){$extraCols=',status,is_active';$extraVals=['open',1];}
        pdo()->prepare("INSERT INTO `$table` (workspace_id,company_id,$cols$extraCols,created_at,updated_at) VALUES (?,?,$marks".($extraCols?',?,?':'').",NOW(),NOW())")->execute([$wid,$cid,...$vals,...$extraVals]);self::audit('acc.master.create',$table,(int)pdo()->lastInsertId(),'ایجاد اطلاعات پایه',['entity'=>$entity]);self::back($entity==='accounts'?'accounts':'master','entity='.$entity);
    }

    private static function deleteMaster(): void
    {
        Tenant::requirePermission('accounting.master.manage');$entity=(string)($_POST['entity']??'');$id=(int)($_POST['id']??0);$map=['items'=>'acc_items','parties'=>'acc_parties','cost_centers'=>'acc_cost_centers','projects'=>'acc_projects','warehouses'=>'acc_warehouses','units'=>'acc_units','item_groups'=>'acc_item_groups','accounts'=>'acc_accounts'];
        if($entity==='fiscal_years'){pdo()->prepare("UPDATE acc_fiscal_years SET is_active=0,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")->execute([$id,Tenant::id(),AccountingRepository::companyId()]);self::back('master','entity=fiscal_years');}
        if(!isset($map[$entity]))throw new RuntimeException('بخش نامعتبر است.');pdo()->prepare("UPDATE `{$map[$entity]}` SET active=0,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")->execute([$id,Tenant::id(),AccountingRepository::companyId()]);self::audit('acc.master.disable',$map[$entity],$id,'غیرفعال‌سازی');self::back($entity==='accounts'?'accounts':'master','entity='.$entity);
    }

    private static function savePurchase(): void
    {
        Tenant::requirePermission('accounting.purchase.manage');$cid=AccountingRepository::companyId();$wid=Tenant::id();$docType=trim((string)($_POST['doc_type']??''));$no=trim((string)($_POST['document_no']??''));$date=AccountingRepository::date($_POST['document_date']??'');$party=(int)($_POST['party_id']??0);
        if(!$docType||!$no||!$date||!$party)throw new RuntimeException('نوع سند، شماره، تاریخ و طرف حساب الزامی است.');
        if(!AccountingRepository::owns('acc_parties',$party))throw new RuntimeException('طرف حساب متعلق به شرکت فعال نیست.');
        $isService=str_contains($docType,'service');$warehouse=$isService?null:self::scopedId('acc_warehouses',$_POST['warehouse_id']??0);$costCenter=self::scopedId('acc_cost_centers',$_POST['cost_center_id']??0);$project=self::scopedId('acc_projects',$_POST['project_id']??0);$lines=$_POST['lines']??[];if(!is_array($lines)||!$lines)throw new RuntimeException('حداقل یک ردیف ثبت کنید.');
        $pdo=pdo();$pdo->beginTransaction();
        try{
            $pdo->prepare("INSERT INTO acc_purchase_docs (workspace_id,company_id,doc_type,document_no,party_invoice_no,document_date,party_id,cost_center_id,project_id,warehouse_id,notes,workflow_status,taxpayer_status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                ->execute([$wid,$cid,$docType,$no,trim((string)($_POST['party_invoice_no']??'')),$date,$party,$costCenter,$project,$warehouse,trim((string)($_POST['notes']??'')),trim((string)($_POST['workflow_status']??'draft')),trim((string)($_POST['taxpayer_status']??'not_sent')),(int)Auth::user()['id']]);
            $id=(int)$pdo->lastInsertId();$gross=0;$discount=0;$net=0;$lineNo=1;
            $ins=$pdo->prepare("INSERT INTO acc_purchase_lines (workspace_id,purchase_doc_id,line_no,item_id,unit_id,description,quantity,unit_price,discount_amount,discount_percent,commission_percent,cost_center_id,warehouse_id,project_id,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            foreach($lines as $l){$item=(int)($l['item_id']??0);$qty=(float)($l['quantity']??0);$price=(float)($l['unit_price']??0);if(!$item||$qty==0)continue;if(!AccountingRepository::owns('acc_items',$item))throw new RuntimeException('کالا/خدمت انتخاب‌شده متعلق به شرکت فعال نیست.');$disc=(float)($l['discount_amount']??0);$dp=(float)($l['discount_percent']??0);$base=$qty*$price;$pd=$base*max(0,min(100,$dp))/100;$ld=$disc+$pd;$total=max(0,$base-$ld);$unit=self::scopedId('acc_units',$l['unit_id']??0);$lineCC=self::scopedId('acc_cost_centers',$l['cost_center_id']??0);$lineProject=self::scopedId('acc_projects',$l['project_id']??0);$lineWarehouse=$isService?null:(self::scopedId('acc_warehouses',$l['warehouse_id']??0)?:$warehouse);$ins->execute([$wid,$id,$lineNo++,$item,$unit,trim((string)($l['description']??'')),$qty,$price,$disc,$dp,(float)($l['commission_percent']??0),$lineCC,$lineWarehouse,$lineProject,$total]);$gross+=$base;$discount+=$ld;$net+=$total;}
            if($lineNo===1)throw new RuntimeException('ردیف معتبر وجود ندارد.');$pdo->prepare("UPDATE acc_purchase_docs SET total_before_discount=?,discount_total=?,net_total=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$gross,$discount,$net,$id,$wid]);$pdo->commit();self::audit('acc.purchase.create','acc_purchase_docs',$id,'ایجاد سند خرید',['net_total'=>$net]);self::back('purchase');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private static function deletePurchase(): void
    {
        Tenant::requirePermission('accounting.purchase.manage');$id=(int)($_POST['id']??0);$wid=Tenant::id();$cid=AccountingRepository::companyId();
        $chk=pdo()->prepare("SELECT id FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");$chk->execute([$id,$wid,$cid]);if(!$chk->fetchColumn())throw new RuntimeException('سند خرید متعلق به شرکت فعال نیست یا وجود ندارد.');
        $pdo=pdo();$pdo->beginTransaction();
        try{$pdo->prepare("DELETE FROM acc_purchase_lines WHERE workspace_id=? AND purchase_doc_id=?")->execute([$wid,$id]);$pdo->prepare("DELETE FROM acc_purchase_docs WHERE id=? AND workspace_id=? AND company_id=?")->execute([$id,$wid,$cid]);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
        self::audit('acc.purchase.delete','acc_purchase_docs',$id,'حذف سند خرید');self::back('purchase');
    }

    private static function saveSale(): void
    {
        Tenant::requirePermission('accounting.sales.manage');$wid=Tenant::id();$cid=AccountingRepository::companyId();$type=trim((string)($_POST['doc_type']??'invoice'));$no=trim((string)($_POST['document_no']??''));$date=AccountingRepository::date($_POST['document_date']??'');$party=(int)($_POST['party_id']??0);$lines=$_POST['lines']??[];
        if(!$no||!$date||!$party||!is_array($lines)||!$lines)throw new RuntimeException('شماره، تاریخ، مشتری و حداقل یک ردیف فروش الزامی است.');if(!AccountingRepository::owns('acc_parties',$party))throw new RuntimeException('مشتری متعلق به شرکت فعال نیست.');
        $warehouse=self::scopedId('acc_warehouses',$_POST['warehouse_id']??0);$cc=self::scopedId('acc_cost_centers',$_POST['cost_center_id']??0);$project=self::scopedId('acc_projects',$_POST['project_id']??0);$pdo=pdo();$pdo->beginTransaction();
        try{$pdo->prepare("INSERT INTO acc_sales_docs (workspace_id,company_id,doc_type,document_no,document_date,due_date,party_id,warehouse_id,cost_center_id,project_id,notes,workflow_status,taxpayer_status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([$wid,$cid,$type,$no,$date,AccountingRepository::date($_POST['due_date']??''),$party,$warehouse,$cc,$project,trim((string)($_POST['notes']??'')),trim((string)($_POST['workflow_status']??'draft')),trim((string)($_POST['taxpayer_status']??'not_sent')),(int)Auth::user()['id']]);$id=(int)$pdo->lastInsertId();$gross=0;$discount=0;$tax=0;$net=0;$n=1;
            $ins=$pdo->prepare("INSERT INTO acc_sales_lines (workspace_id,sales_doc_id,line_no,item_id,unit_id,warehouse_id,cost_center_id,project_id,description,quantity,unit_price,discount_amount,tax_percent,tax_amount,line_total,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            foreach($lines as $l){$item=(int)($l['item_id']??0);$qty=(float)($l['quantity']??0);$price=max(0,(float)($l['unit_price']??0));if(!$item||$qty<=0)continue;if(!AccountingRepository::owns('acc_items',$item))throw new RuntimeException('کالا/خدمت متعلق به شرکت فعال نیست.');$base=$qty*$price;$disc=max(0,min($base,(float)($l['discount_amount']??0)));$taxPct=max(0,min(100,(float)($l['tax_percent']??0)));$taxAmt=max(0,($base-$disc)*$taxPct/100);$total=$base-$disc+$taxAmt;$ins->execute([$wid,$id,$n++,$item,self::scopedId('acc_units',$l['unit_id']??0),self::scopedId('acc_warehouses',$l['warehouse_id']??0)?:$warehouse,self::scopedId('acc_cost_centers',$l['cost_center_id']??0)?:$cc,self::scopedId('acc_projects',$l['project_id']??0)?:$project,trim((string)($l['description']??'')),$qty,$price,$disc,$taxPct,$taxAmt,$total]);$gross+=$base;$discount+=$disc;$tax+=$taxAmt;$net+=$total;}
            if($n===1)throw new RuntimeException('ردیف معتبر فروش وجود ندارد.');$pdo->prepare("UPDATE acc_sales_docs SET total_before_discount=?,discount_total=?,tax_total=?,net_total=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$gross,$discount,$tax,$net,$id,$wid]);$pdo->commit();self::audit('acc.sale.create','acc_sales_docs',$id,'ایجاد سند فروش',['net_total'=>$net]);self::back('sales');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private static function deleteSale(): void
    {
        Tenant::requirePermission('accounting.sales.manage');$id=(int)($_POST['id']??0);$wid=Tenant::id();$cid=AccountingRepository::companyId();$st=pdo()->prepare("SELECT workflow_status FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");$st->execute([$id,$wid,$cid]);$status=$st->fetchColumn();if($status===false)throw new RuntimeException('سند فروش پیدا نشد.');if($status!=='draft')throw new RuntimeException('فقط پیش‌نویس فروش قابل حذف است.');$pdo=pdo();$pdo->beginTransaction();try{$pdo->prepare("DELETE FROM acc_sales_lines WHERE workspace_id=? AND sales_doc_id=?")->execute([$wid,$id]);$pdo->prepare("DELETE FROM acc_sales_docs WHERE id=? AND workspace_id=? AND company_id=?")->execute([$id,$wid,$cid]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}self::audit('acc.sale.delete','acc_sales_docs',$id,'حذف پیش‌نویس فروش');self::back('sales');
    }

    private static function saveVoucher(): void
    {
        Tenant::requirePermission('accounting.vouchers.manage');$cid=AccountingRepository::companyId();$wid=Tenant::id();$no=trim((string)($_POST['voucher_no']??''));$date=AccountingRepository::date($_POST['voucher_date']??'');$lines=$_POST['lines']??[];if(!$no||!$date||!is_array($lines)||!$lines)throw new RuntimeException('شماره، تاریخ و آرتیکل‌ها الزامی است.');
        $debit=0;$credit=0;$valid=[];foreach($lines as $l){$acc=(int)($l['account_id']??0);$d=max(0,(float)($l['debit']??0));$c=max(0,(float)($l['credit']??0));if(!$acc||($d==0&&$c==0)||($d>0&&$c>0))continue;if(!AccountingRepository::owns('acc_accounts',$acc))throw new RuntimeException('حساب انتخاب‌شده متعلق به شرکت فعال نیست.');$debit+=$d;$credit+=$c;$valid[]=[$l,$d,$c];}
        if(!$valid)throw new RuntimeException('آرتیکل معتبر وجود ندارد.');if(abs($debit-$credit)>0.01&&self::settingValue('voucher_balance_control','error')==='error')throw new RuntimeException('سند حسابداری بالانس نیست.');
        $pdo=pdo();$pdo->beginTransaction();try{
            $pdo->prepare("INSERT INTO acc_vouchers (workspace_id,company_id,voucher_no,voucher_date,voucher_type,status,description,total_debit,total_credit,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([$wid,$cid,$no,$date,trim((string)($_POST['voucher_type']??'general')),trim((string)($_POST['status']??'draft')),trim((string)($_POST['description']??'')),$debit,$credit,(int)Auth::user()['id']]);
            $id=(int)$pdo->lastInsertId();$ins=$pdo->prepare("INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,cost_center_id,project_id,description,debit,credit,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");$n=1;foreach($valid as [$l,$d,$c]){$party=self::scopedId('acc_parties',$l['party_id']??0);$cc=self::scopedId('acc_cost_centers',$l['cost_center_id']??0);$project=self::scopedId('acc_projects',$l['project_id']??0);$ins->execute([$wid,$id,$n++,(int)$l['account_id'],$party,$cc,$project,trim((string)($l['description']??'')),$d,$c]);}$pdo->commit();self::audit('acc.voucher.create','acc_vouchers',$id,'ایجاد سند حسابداری',['debit'=>$debit,'credit'=>$credit]);self::back('vouchers');
        }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private static function deleteVoucher(): void
    {
        Tenant::requirePermission('accounting.vouchers.manage');$id=(int)($_POST['id']??0);$wid=Tenant::id();$cid=AccountingRepository::companyId();
        $st=pdo()->prepare("SELECT status FROM acc_vouchers WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");$st->execute([$id,$wid,$cid]);$status=$st->fetchColumn();if($status===false)throw new RuntimeException('سند حسابداری پیدا نشد.');if($status!=='draft')throw new RuntimeException('فقط پیش‌نویس سند حسابداری قابل حذف است.');
        $pdo=pdo();$pdo->beginTransaction();try{$pdo->prepare("DELETE FROM acc_voucher_lines WHERE workspace_id=? AND voucher_id=?")->execute([$wid,$id]);$pdo->prepare("DELETE FROM acc_vouchers WHERE id=? AND workspace_id=? AND company_id=? AND status='draft'")->execute([$id,$wid,$cid]);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}self::audit('acc.voucher.delete','acc_vouchers',$id,'حذف پیش‌نویس سند حسابداری');self::back('vouchers');
    }

    private static function saveBom(): void
    {
        Tenant::requirePermission('accounting.production.manage');$wid=Tenant::id();$cid=AccountingRepository::companyId();$lines=$_POST['lines']??[];$code=trim((string)($_POST['code']??''));$name=trim((string)($_POST['name']??''));$product=(int)($_POST['product_item_id']??0);if(!$code||!$name||!$product||!is_array($lines)||!$lines)throw new RuntimeException('اطلاعات BOM کامل نیست.');
        if(!AccountingRepository::owns('acc_items',$product))throw new RuntimeException('محصول متعلق به شرکت فعال نیست.');
        $pdo=pdo();$pdo->beginTransaction();try{$pdo->prepare("INSERT INTO acc_boms (workspace_id,company_id,product_item_id,code,name,version_no,output_qty,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW())")->execute([$wid,$cid,$product,$code,$name,trim((string)($_POST['version_no']??'')),max(.0001,(float)($_POST['output_qty']??1))]);$id=(int)$pdo->lastInsertId();$ins=$pdo->prepare("INSERT INTO acc_bom_lines (workspace_id,bom_id,material_item_id,unit_id,quantity,waste_percent,stage_no,created_at) VALUES (?,?,?,?,?,?,?,NOW())");foreach($lines as $l){$item=(int)($l['material_item_id']??0);$qty=(float)($l['quantity']??0);if(!$item||$qty<=0)continue;if(!AccountingRepository::owns('acc_items',$item))throw new RuntimeException('ماده اولیه متعلق به شرکت فعال نیست.');$ins->execute([$wid,$id,$item,(int)($l['unit_id']??0)?:null,$qty,max(0,(float)($l['waste_percent']??0)),max(1,(int)($l['stage_no']??1))]);}$pdo->commit();self::audit('acc.bom.create','acc_boms',$id,'ایجاد فرمول ساخت');self::back('production');}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private static function saveProductionOrder(): void
    {
        Tenant::requirePermission('accounting.production.manage');$wid=Tenant::id();$cid=AccountingRepository::companyId();$no=trim((string)($_POST['order_no']??''));$product=(int)($_POST['product_item_id']??0);if(!$no||!$product)throw new RuntimeException('شماره دستور و محصول الزامی است.');
        if(!AccountingRepository::owns('acc_items',$product))throw new RuntimeException('محصول متعلق به شرکت فعال نیست.');
        $bom=self::scopedId('acc_boms',$_POST['bom_id']??0);$rawWarehouse=self::scopedId('acc_warehouses',$_POST['raw_warehouse_id']??0);$finishedWarehouse=self::scopedId('acc_warehouses',$_POST['finished_warehouse_id']??0);$costCenter=self::scopedId('acc_cost_centers',$_POST['cost_center_id']??0);$project=self::scopedId('acc_projects',$_POST['project_id']??0);
        $costs=['material_cost','labor_cost','overhead_cost','subcontract_cost','scrap_cost'];$v=[];$total=0;foreach($costs as $k){$v[$k]=max(0,(float)($_POST[$k]??0));$total+=$v[$k];}
        pdo()->prepare("INSERT INTO acc_production_orders (workspace_id,company_id,order_no,product_item_id,bom_id,planned_qty,actual_qty,raw_warehouse_id,finished_warehouse_id,cost_center_id,project_id,start_date,end_date,status,material_cost,labor_cost,overhead_cost,subcontract_cost,scrap_cost,actual_total_cost,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$wid,$cid,$no,$product,$bom,(float)($_POST['planned_qty']??0),(float)($_POST['actual_qty']??0),$rawWarehouse,$finishedWarehouse,$costCenter,$project,AccountingRepository::date($_POST['start_date']??''),AccountingRepository::date($_POST['end_date']??''),trim((string)($_POST['status']??'planned')),$v['material_cost'],$v['labor_cost'],$v['overhead_cost'],$v['subcontract_cost'],$v['scrap_cost'],$total,(int)Auth::user()['id']]);self::audit('acc.production.create','acc_production_orders',(int)pdo()->lastInsertId(),'ایجاد دستور تولید',['actual_total_cost'=>$total]);self::back('production');
    }

    private static function saveCash(): void
    {
        Tenant::requirePermission('accounting.treasury.manage');$name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('عنوان بانک/صندوق الزامی است.');
        pdo()->prepare("INSERT INTO acc_cash_accounts (workspace_id,company_id,account_kind,code,name,bank_name,account_no,iban,opening_balance,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute([Tenant::id(),AccountingRepository::companyId(),trim((string)($_POST['account_kind']??'bank')),trim((string)($_POST['code']??'')),$name,trim((string)($_POST['bank_name']??'')),trim((string)($_POST['account_no']??'')),trim((string)($_POST['iban']??'')),(float)($_POST['opening_balance']??0)]);self::audit('acc.cash.create','acc_cash_accounts',(int)pdo()->lastInsertId(),'ایجاد بانک/صندوق');self::back('treasury');
    }

    private static function saveCheck(): void
    {
        Tenant::requirePermission('accounting.treasury.manage');$no=trim((string)($_POST['check_no']??''));if(!$no)throw new RuntimeException('شماره چک الزامی است.');$party=self::scopedId('acc_parties',$_POST['party_id']??0);$cash=self::scopedId('acc_cash_accounts',$_POST['cash_account_id']??0);
        pdo()->prepare("INSERT INTO acc_checks (workspace_id,company_id,direction,check_no,amount,due_date,party_id,cash_account_id,status,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([Tenant::id(),AccountingRepository::companyId(),trim((string)($_POST['direction']??'receivable')),$no,(float)($_POST['amount']??0),AccountingRepository::date($_POST['due_date']??''),$party,$cash,trim((string)($_POST['status']??'open')),trim((string)($_POST['notes']??''))]);self::audit('acc.check.create','acc_checks',(int)pdo()->lastInsertId(),'ثبت چک');self::back('treasury');
    }

    private static function saveSettings(): void
    {
        Tenant::requirePermission('accounting.settings.manage');$values=$_POST['settings']??[];if(!is_array($values))$values=[];$st=pdo()->prepare("UPDATE acc_module_settings SET value_text=?,updated_at=NOW() WHERE workspace_id=? AND company_id=? AND setting_key=?");foreach($values as $k=>$v)$st->execute([is_scalar($v)?(string)$v:json_encode($v,JSON_UNESCAPED_UNICODE),Tenant::id(),AccountingRepository::companyId(),(string)$k]);self::audit('acc.settings.update','acc_module_settings',AccountingRepository::companyId(),'ویرایش تنظیمات');self::back('settings','settings_section='.urlencode((string)($_POST['settings_section']??'accounting')));
    }

    private static function closeFiscal(): void
    {
        Tenant::requirePermission('accounting.settings.manage');$id=(int)($_POST['id']??0);$cid=AccountingRepository::companyId();$st=pdo()->prepare("SELECT start_date,end_date FROM acc_fiscal_years WHERE id=? AND workspace_id=? AND company_id=? AND status='open'");$st->execute([$id,Tenant::id(),$cid]);$fy=$st->fetch();if(!$fy)throw new RuntimeException('سال مالی باز پیدا نشد.');$st=pdo()->prepare("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=? AND company_id=? AND voucher_date BETWEEN ? AND ? AND status='draft'");$st->execute([Tenant::id(),$cid,$fy['start_date'],$fy['end_date']]);if((int)$st->fetchColumn()>0)throw new RuntimeException('اسناد موقت این دوره را تعیین تکلیف کنید.');pdo()->prepare("UPDATE acc_fiscal_years SET status='closed',is_active=0,updated_at=NOW() WHERE id=? AND workspace_id=? AND company_id=?")->execute([$id,Tenant::id(),$cid]);self::audit('acc.fiscal.close','acc_fiscal_years',$id,'بستن سال مالی');self::back('master','entity=fiscal_years');
    }

    private static function settingValue(string $key,string $default=''): string
    {
        $st=pdo()->prepare("SELECT value_text FROM acc_module_settings WHERE workspace_id=? AND company_id=? AND setting_key=? LIMIT 1");$st->execute([Tenant::id(),AccountingRepository::companyId(),$key]);$v=$st->fetchColumn();return$v===false?$default:(string)$v;
    }

    private static function scalar(string $sql,array $p): int {$st=pdo()->prepare($sql);$st->execute($p);return(int)$st->fetchColumn();}

    private static function salesTypeLabel(string $v): string
    {
        return ['invoice'=>'فاکتور فروش','preinvoice'=>'پیش‌فاکتور فروش','return'=>'برگشت از فروش'][$v]??$v;
    }

    private static function docTypeLabel(string $v): string
    {
        return ['purchase_preinvoice_goods'=>'پیش‌فاکتور خرید کالا','purchase_contract_goods'=>'قرارداد خرید کالا','purchase_order_goods'=>'سفارش خرید کالا','purchase_invoice_goods'=>'فاکتور خرید کالا','purchase_return_goods'=>'برگشت از خرید کالا','purchase_preinvoice_service'=>'پیش‌فاکتور خرید خدمات','purchase_contract_service'=>'قرارداد خرید خدمات','purchase_order_service'=>'سفارش خرید خدمات','purchase_invoice_service'=>'فاکتور خرید خدمات','purchase_return_service'=>'برگشت از خرید خدمات'][$v]??$v;
    }

    private static function input(string $name,string $label,$value='',string $type='text',$options=[]): void
    {
        if($type==='select'){echo '<label>'.h($label).'<select name="'.h($name).'">';foreach((array)$options as $v=>$t){if(is_int($v))$v=$t;echo '<option value="'.h($v).'" '.((string)$value===(string)$v?'selected':'').'>'.h($t).'</option>';}echo '</select></label>';return;}
        $cls=$type==='date'?' class="jalali-date"':'';$htmlType=$type==='number'?'number':'text';echo '<label>'.h($label).'<input'.$cls.' type="'.$htmlType.'" '.($htmlType==='number'?'step="any"':'').' name="'.h($name).'" value="'.h($value).'"></label>';
    }

    private static function selectAssoc(string $name,string $label,array $items): void
    {
        echo '<label>'.h($label).'<select name="'.h($name).'">';foreach($items as $v=>$t)echo '<option value="'.h($v).'">'.h($t).'</option>';echo '</select></label>';
    }

    private static function selectRows(string $name,string $label,array $rows,bool $required=false,string $class=''): void
    {
        echo '<label class="'.h($class).'">'.h($label).'<select name="'.h($name).'" '.($required?'required':'').'><option value=""></option>';foreach($rows as $r)echo '<option value="'.(int)$r['id'].'">'.h(trim(($r['code']??'').' '.($r['name']??$r['title']??''))).'</option>';echo '</select></label>';
    }

    private static function scopedId(string $table,$value): ?int
    {
        $id=(int)$value;if($id<=0)return null;
        if(!AccountingRepository::owns($table,$id))throw new RuntimeException('شناسه انتخاب‌شده متعلق به شرکت یا محیط کاری فعال نیست.');
        return$id;
    }

    private static function audit(string $action,string $entity,int $id,string $summary,array $meta=[]): void
    {
        AccountingRepository::clear();Audit::log($action,$entity,$id,$summary,null,null,$meta);
    }

    private static function back(string $section,string $extra=''): never
    {
        redirect('index.php?page=industrial&section='.$section.($extra!==''?'&'.$extra:''));
    }
}
