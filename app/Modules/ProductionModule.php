<?php
/** Planning-only Manufacturing workspace. No material issue, finished-goods receipt, or GL posting. */
final class ProductionModule
{
    public static function handle(string $action): void
    {
        if (!in_array($action,['prod_create_bom','prod_create_plan','prod_select_company'],true)) throw new RuntimeException('production_action_invalid');
        if ($action==='prod_select_company') {
            Tenant::requirePermission('production.view');
            AccountingRepository::companyId();redirect('index.php?page=production');
        }
        Tenant::requirePermission('production.manage');
        if (!ModuleRegistry::enabled('production')) throw new RuntimeException('production_module_disabled');
        $wid=Tenant::id();$cid=AccountingRepository::companyId();$uid=(int)Auth::user()['id'];
        if ($action==='prod_create_bom') {
            $components=[];
            foreach ((array)($_POST['components']??[]) as $r) {
                if (!is_array($r) || trim((string)($r['component_item_id']??''))==='') continue;
                $components[]=$r;
            }
            $id=ProductionDomain::createBom($wid,$cid,$uid,[
                'code'=>$_POST['code']??'', 'output_item_id'=>$_POST['output_item_id']??0,
                'output_qty'=>$_POST['output_qty']??0, 'lines'=>$components, 'notes'=>$_POST['notes']??''
            ]);
            Audit::log('production.bom.draft','erp_production_boms',$id,'ایجاد فرمول ساخت پیش‌نویس');
            flash('فرمول ساخت پیش‌نویس ثبت شد؛ هنوز هیچ موجودی یا سند مالی تغییر نکرده است.');
            redirect('index.php?page=production&bom_id='.$id);
        }
        $id=ProductionDomain::createPlan($wid,$cid,$uid,[
            'bom_id'=>$_POST['bom_id']??0,'warehouse_id'=>$_POST['warehouse_id']??0,
            'planned_qty'=>$_POST['planned_qty']??0,'target_date'=>$_POST['target_date']??'',
            'notes'=>$_POST['notes']??''
        ]);
        Audit::log('production.plan.draft','erp_production_plans',$id,'ایجاد برنامه تولید پیش‌نویس');
        flash('برنامه تولید در حالت پیش‌نویس ثبت شد. رزرو و مصرف مواد انجام نشده است.');
        redirect('index.php?page=production');
    }

    private static function itemOptions(array $items,int $selected=0): string
    {
        $out='<option value="">انتخاب کالا...</option>';
        foreach ($items as $i) $out.='<option value="'.(int)$i['id'].'"'.((int)$i['id']===$selected?' selected':'').'>'.h($i['code'].' • '.$i['name']).'</option>';
        return $out;
    }

    public static function render(): void
    {
        Tenant::requirePermission('production.view');
        $cid=AccountingRepository::companyId();$wid=Tenant::id();
        render_header('تولید و برنامه‌ریزی مواد','فرمول ساخت، برنامه تولید و بررسی کسری مواد از اطلاعات واقعی انبار');
        $companies=AccountingRepository::companies();
        echo '<section class="card"><div class="production-head"><div><span class="production-tag">Manufacturing Planning • Pilot</span><h2>کارگاه برنامه‌ریزی تولید</h2><p class="muted">فرمول ساخت و سنجش مواد اولیه با اتصال به موجودی واقعی هر شرکت</p></div><div class="production-safe">حالت امن: فقط پیش‌نویس<br><small>بدون تغییر موجودی، سند مالی یا رزرو</small></div></div><form class="production-scope" method="post">'.csrf_field().'<input type="hidden" name="action" value="prod_select_company"><label>شرکت فعال <select name="company_id" onchange="this.form.submit()">';
        foreach($companies as $c)echo '<option value="'.(int)$c['id'].'"'.((int)$c['id']===$cid?' selected':'').'>'.h($c['name']).'</option>';
        echo '</select></label><a class="btn tiny" href="index.php?page=inventory">مشاهده موجودی واقعی</a><a class="btn tiny" href="index.php?page=procurement">تأمین مواد</a></form></section>';
        if (!$cid) {echo '<section class="card"><p>ابتدا یک شرکت فعال تعریف کنید.</p></section>';render_footer();return;}
        $items=ProductionDomain::listItems($wid,$cid);$boms=ProductionDomain::listBoms($wid,$cid);$plans=ProductionDomain::listPlans($wid,$cid);
        $warehouses=InventoryDomain::searchWarehouses($wid,$cid);
        echo '<section class="production-metrics"><div class="card"><span>فرمول‌های ساخت</span><strong>'.count($boms).'</strong><small>حداکثر ۱۰۰ مورد اخیر</small></div><div class="card"><span>برنامه‌های پیش‌نویس</span><strong>'.count($plans).'</strong><small>بدون ثبت قطعی</small></div><div class="card"><span>کالاهای قابل مشاهده</span><strong>'.count($items).'</strong><small>حداکثر ۲۰۰ کالا</small></div></section>';
        if (Tenant::can('production.manage')) {
            echo '<section class="card"><details><summary class="production-toggle">افزودن فرمول ساخت (BOM)</summary><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_create_bom"><div class="production-grid"><label>کد یکتای فرمول<input name="code" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{1,79}" placeholder="BOM-1001" required></label><label>کالای تولیدی<select name="output_item_id" required>'.self::itemOptions($items).'</select></label><label>مقدار خروجی فرمول<input name="output_qty" type="number" step="0.0001" min="0.0001" value="1" required></label></div><h3>مواد اولیه و ضریب مصرف</h3><p class="muted">برای هر ردیف، مقدار مصرف به ازای خروجی فرمول و درصد ضایعات را وارد کنید. حداقل یک ردیف لازم است.</p><div class="production-lines"><div class="production-line production-line-head"><b>کالای مصرفی</b><b>مقدار مصرف</b><b>ضایعات ٪</b></div>';
            for($i=0;$i<6;$i++)echo '<div class="production-line"><select name="components['.$i.'][component_item_id]">'.self::itemOptions($items).'</select><input name="components['.$i.'][quantity_per]" type="number" step="0.0001" min="0.0001" placeholder="مثلاً ۲"><input name="components['.$i.'][scrap_percent]" type="number" step="0.01" min="0" max="100" value="0"></div>';
            echo '</div><label>یادداشت<textarea name="notes" rows="2" maxlength="1000" placeholder="مشخصات و مفروضات ساخت"></textarea></label><button class="btn primary" type="submit">ثبت فرمول ساخت پیش‌نویس</button></form></details></section>';
        }
        echo '<section class="card"><div class="section-title"><h2>فرمول‌های ساخت</h2><span class="muted">هر فرمول به یک شرکت و نسخه تعلق دارد</span></div><div class="table-wrap"><table><thead><tr><th>کد</th><th>کالای خروجی</th><th>مقدار</th><th>مواد</th><th>وضعیت</th><th>تحلیل</th></tr></thead><tbody>';
        foreach($boms as $b)echo '<tr><td>'.h($b['code'].' / R'.$b['revision']).'</td><td>'.h($b['output_code'].' • '.$b['output_name']).'</td><td>'.h($b['output_qty']).'</td><td>'.(int)$b['component_count'].'</td><td>پیش‌نویس</td><td><a class="btn tiny" href="index.php?page=production&bom_id='.(int)$b['id'].'">مشاهده و سنجش مواد</a></td></tr>';
        if(!$boms)echo '<tr><td colspan="6" class="muted">هنوز فرمول ساختی برای این شرکت ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
        if (Tenant::can('production.manage') && $boms && $warehouses) {
            echo '<section class="card"><details><summary class="production-toggle">ثبت برنامه تولید پیش‌نویس</summary><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_create_plan"><div class="production-grid"><label>فرمول ساخت<select name="bom_id" required><option value="">انتخاب...</option>';
            foreach($boms as $b)echo '<option value="'.(int)$b['id'].'">'.h($b['code'].' • '.$b['output_name']).'</option>';
            echo '</select></label><label>انبار مبنا<select name="warehouse_id" required><option value="">انتخاب...</option>';
            foreach($warehouses as $w)echo '<option value="'.(int)$w['id'].'">'.h($w['code'].' • '.$w['name']).'</option>';
            echo '</select></label><label>تعداد خروجی برنامه<input type="number" name="planned_qty" step="0.0001" min="0.0001" required></label><label>تاریخ هدف (میلادی)<input name="target_date" type="date"></label></div><label>یادداشت<textarea name="notes" maxlength="1000" rows="2"></textarea></label><button class="btn primary" type="submit">ثبت برنامه پیش‌نویس</button></form></details></section>';
        }
        $selectedId=max(0,(int)($_GET['bom_id']??0));
        if ($selectedId) {
            $bom=ProductionDomain::getBom($wid,$cid,$selectedId);
            if (!$bom)echo '<section class="card alert danger">فرمول ساخت برای این شرکت پیدا نشد.</section>';
            else {
                $qty=max(0,(float)($_GET['plan_qty']??$bom['output_qty']));
                $warehouseId=max(0,(int)($_GET['warehouse_id']??($warehouses[0]['id']??0)));
                echo '<section class="card"><h2>سنجش مواد: '.h($bom['code']).'</h2><p class="muted">'.h($bom['output_name']).'؛ فقط برآورد آنی، بدون تخصیص موجودی</p><form method="get" class="production-preview"><input type="hidden" name="page" value="production"><input type="hidden" name="bom_id" value="'.(int)$selectedId.'"><label>مقدار تولید<input type="number" name="plan_qty" min="0.0001" step="0.0001" value="'.h($qty).'" required></label><label>انبار<select name="warehouse_id">';
                foreach($warehouses as $w)echo '<option value="'.(int)$w['id'].'"'.((int)$w['id']===$warehouseId?' selected':'').'>'.h($w['name']).'</option>';
                echo '</select></label><button class="btn primary" type="submit">محاسبه کسری مواد</button></form>';
                if ($warehouseId>0 && $qty>0) {
                    $preview=ProductionDomain::preview($wid,$cid,$selectedId,$qty,$warehouseId);
                    echo '<div class="production-summary">'.($preview['shortage_lines']?'⚠️ '.(int)$preview['shortage_lines'].' ردیف کسری':'✓ کسری آشکار در این انبار دیده نشد').'</div><div class="table-wrap"><table><thead><tr><th>ماده اولیه</th><th>نیاز</th><th>موجود</th><th>رزرو دیگر</th><th>قابل استفاده</th><th>کسری</th></tr></thead><tbody>';
                    foreach($preview['lines'] as $r)echo '<tr><td>'.h($r['item_code'].' • '.$r['item_name']).'</td><td><strong>'.h($r['required_qty']).'</strong></td><td>'.h($r['on_hand_qty']).'</td><td>'.h($r['reserved_qty']).'</td><td>'.h($r['available_qty']).'</td><td class="'.($r['shortage_qty']>0?'production-shortage':'').'">'.h($r['shortage_qty']).'</td></tr>';
                    echo '</tbody></table></div><p class="muted">بهای مواد با نرخ خرید ثبت‌شده (برآورد، نه بهای تمام‌شده): '.h(number_format((float)$preview['estimated_material_cost'],0)).' • اقلام بدون نرخ: '.(int)$preview['unpriced_lines'].'</p><p class="muted">این محاسبه سایر برنامه‌های تولید را رزرو نمی‌کند و زمان‌بندی، ضایعات واقعی و موجودی در راه را قطعی فرض نمی‌کند.</p>';
                }
                echo '</section>';
            }
        }
        echo '<section class="card"><div class="section-title"><h2>برنامه‌های تولید</h2><span class="muted">پیش‌نویس‌های فاقد اثر انبار و حسابداری</span></div><div class="table-wrap"><table><thead><tr><th>شناسه</th><th>فرمول</th><th>محصول</th><th>تعداد</th><th>انبار</th><th>تاریخ هدف</th><th>وضعیت</th></tr></thead><tbody>';
        foreach($plans as $p)echo '<tr><td>#'.(int)$p['id'].'</td><td>'.h($p['bom_code']).'</td><td>'.h($p['output_name']).'</td><td>'.h($p['planned_qty']).'</td><td>'.h($p['warehouse_name']).'</td><td>'.h($p['target_date']??'—').'</td><td>پیش‌نویس</td></tr>';
        if(!$plans)echo '<tr><td colspan="7" class="muted">هنوز برنامه‌ای ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
        render_footer();
    }
}
