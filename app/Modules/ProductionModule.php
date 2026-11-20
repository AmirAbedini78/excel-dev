<?php
/**
 * Manufacturing workspace — MF-1 operator UI (BOM, MRP-lite, production orders, issue, QC, FG).
 * Stock via ManufacturingInventoryAdapter; no automatic WIP/FG GL.
 */
final class ProductionModule
{
    public static function handle(string $action): void
    {
        $allowed = [
            'prod_create_bom','prod_create_plan','prod_select_company','prod_approve_bom',
            'prod_create_order','prod_approve_order','prod_release_order','prod_issue',
            'prod_scrap','prod_qc','prod_fg','prod_close_order','prod_work_op',
        ];
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException('production_action_invalid');
        }
        if ($action === 'prod_select_company') {
            Tenant::requirePermission('production.view');
            AccountingRepository::companyId();
            redirect('index.php?page=production');
        }
        Tenant::requirePermission('production.manage');
        if (!ModuleRegistry::enabled('production')) {
            throw new RuntimeException('production_module_disabled');
        }
        $wid = Tenant::id();
        $cid = AccountingRepository::companyId();
        $uid = (int)Auth::user()['id'];

        if ($action === 'prod_create_bom') {
            $components = [];
            foreach ((array)($_POST['components'] ?? []) as $r) {
                if (!is_array($r) || trim((string)($r['component_item_id'] ?? '')) === '') {
                    continue;
                }
                $components[] = $r;
            }
            $id = ProductionDomain::createBom($wid, $cid, $uid, [
                'code' => $_POST['code'] ?? '', 'output_item_id' => $_POST['output_item_id'] ?? 0,
                'output_qty' => $_POST['output_qty'] ?? 0, 'lines' => $components, 'notes' => $_POST['notes'] ?? '',
            ]);
            Audit::log('production.bom.draft', 'erp_production_boms', $id, 'ایجاد فرمول ساخت پیش‌نویس');
            flash('فرمول ساخت پیش‌نویس ثبت شد.');
            redirect('index.php?page=production&bom_id='.$id);
        }
        if ($action === 'prod_approve_bom') {
            $id = (int)($_POST['bom_id'] ?? 0);
            ProductionDomain::approveBom($wid, $cid, $uid, $id);
            flash('فرمول ساخت تأیید شد (maker/checker).');
            redirect('index.php?page=production&bom_id='.$id);
        }
        if ($action === 'prod_create_plan') {
            $id = ProductionDomain::createPlan($wid, $cid, $uid, [
                'bom_id' => $_POST['bom_id'] ?? 0, 'warehouse_id' => $_POST['warehouse_id'] ?? 0,
                'planned_qty' => $_POST['planned_qty'] ?? 0, 'target_date' => $_POST['target_date'] ?? '',
                'notes' => $_POST['notes'] ?? '',
            ]);
            Audit::log('production.plan.draft', 'erp_production_plans', $id, 'ایجاد برنامه تولید پیش‌نویس');
            flash('برنامه تولید پیش‌نویس ثبت شد.');
            redirect('index.php?page=production');
        }
        if ($action === 'prod_create_order') {
            $order = ManufacturingExecutionDomain::createOrder($wid, $cid, $uid, [
                'bom_id' => $_POST['bom_id'] ?? 0,
                'planned_qty' => $_POST['planned_qty'] ?? 0,
                'raw_warehouse_id' => $_POST['raw_warehouse_id'] ?? 0,
                'fg_warehouse_id' => $_POST['fg_warehouse_id'] ?? ($_POST['raw_warehouse_id'] ?? 0),
                'notes' => $_POST['notes'] ?? '',
            ]);
            flash('دستور تولید پیش‌نویس #'.(int)$order['id'].' ایجاد شد.');
            redirect('index.php?page=production&order_id='.(int)$order['id']);
        }
        if ($action === 'prod_approve_order') {
            $id = (int)($_POST['order_id'] ?? 0);
            ManufacturingExecutionDomain::approveOrder($wid, $cid, $uid, $id);
            flash('دستور تولید تأیید شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_release_order') {
            $id = (int)($_POST['order_id'] ?? 0);
            ManufacturingExecutionDomain::releaseOrder($wid, $cid, $uid, $id);
            flash('دستور آزاد و مواد رزرو شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_issue') {
            $id = (int)($_POST['order_id'] ?? 0);
            $lines = [];
            foreach ((array)($_POST['issue_lines'] ?? []) as $r) {
                if (!is_array($r) || trim((string)($r['quantity'] ?? '')) === '' || (float)$r['quantity'] <= 0) {
                    continue;
                }
                $lines[] = [
                    'snapshot_line_id' => (int)($r['snapshot_line_id'] ?? 0),
                    'quantity' => $r['quantity'],
                    'unit_cost' => $r['unit_cost'] ?? '0',
                ];
            }
            $key = trim((string)($_POST['idempotency_key'] ?? ''));
            if ($key === '') {
                $key = hash('sha256', 'ui-issue:'.$id.':'.microtime(true).':'.$uid);
            }
            ManufacturingExecutionDomain::issueMaterials($wid, $cid, $uid, $id, $lines, $key);
            flash('صدور مواد ثبت شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_scrap') {
            $id = (int)($_POST['order_id'] ?? 0);
            ManufacturingExecutionDomain::recordScrap($wid, $cid, $uid, $id, [
                'quantity' => $_POST['quantity'] ?? 0,
                'reason' => $_POST['reason'] ?? '',
                'component_item_id' => $_POST['component_item_id'] ?? 0,
            ]);
            flash('ضایعات جداگانه ثبت شد (وارد موجودی قابل قبول نمی‌شود).');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_qc') {
            $id = (int)($_POST['order_id'] ?? 0);
            ManufacturingExecutionDomain::recordQc(
                $wid, $cid, $uid, $id,
                (string)($_POST['decision'] ?? ''),
                $_POST['quantity'] ?? 0,
                (string)($_POST['reason'] ?? '')
            );
            flash('رویداد QC ثبت شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_fg') {
            $id = (int)($_POST['order_id'] ?? 0);
            $key = trim((string)($_POST['idempotency_key'] ?? ''));
            if ($key === '') {
                $key = hash('sha256', 'ui-fg:'.$id.':'.microtime(true).':'.$uid);
            }
            ManufacturingExecutionDomain::receiveFinishedGoods($wid, $cid, $uid, $id, $_POST['quantity'] ?? 0, $key);
            flash('ورود کالای ساخته‌شده ثبت شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_close_order') {
            $id = (int)($_POST['order_id'] ?? 0);
            ManufacturingExecutionDomain::closeOrder($wid, $cid, $uid, $id);
            flash('دستور تولید بسته شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
        if ($action === 'prod_work_op') {
            $id = (int)($_POST['order_id'] ?? 0);
            ManufacturingExecutionDomain::recordWorkOp($wid, $cid, $uid, $id, [
                'minutes' => $_POST['minutes'] ?? 0,
                'output_qty' => $_POST['output_qty'] ?? 0,
                'notes' => $_POST['notes'] ?? '',
            ]);
            flash('شواهد عملیات ثبت شد.');
            redirect('index.php?page=production&order_id='.$id);
        }
    }

    private static function itemOptions(array $items, int $selected = 0): string
    {
        $out = '<option value="">انتخاب کالا...</option>';
        foreach ($items as $i) {
            $out .= '<option value="'.(int)$i['id'].'"'.((int)$i['id'] === $selected ? ' selected' : '').'>'.h($i['code'].' • '.$i['name']).'</option>';
        }
        return $out;
    }

    private static function statusFa(string $s): string
    {
        return [
            'draft' => 'پیش‌نویس', 'approved' => 'تأییدشده', 'released' => 'آزادشده',
            'in_progress' => 'در جریان', 'qc' => 'کنترل کیفیت', 'finished' => 'تمام‌شده', 'closed' => 'بسته‌شده',
        ][$s] ?? $s;
    }

    public static function render(): void
    {
        Tenant::requirePermission('production.view');
        $cid = AccountingRepository::companyId();
        $wid = Tenant::id();
        render_header('تولید و اجرا', 'فرمول ساخت، MRP-lite، دستور تولید، صدور مواد، QC و ورود محصول');
        $companies = AccountingRepository::companies();
        echo '<section class="card"><div class="production-head"><div><span class="production-tag">Manufacturing Execution • MF-1</span><h2>کارگاه تولید</h2><p class="muted">چرخه دستور تولید با موجودی واقعی؛ بدون ثبت خودکار GL</p></div><div class="production-safe">Stock: ManufacturingInventoryAdapter<br><small>GL WIP/FG: BLOCKED</small></div></div><form class="production-scope" method="post">'.csrf_field().'<input type="hidden" name="action" value="prod_select_company"><label>شرکت فعال <select name="company_id" onchange="this.form.submit()">';
        foreach ($companies as $c) {
            echo '<option value="'.(int)$c['id'].'"'.((int)$c['id'] === $cid ? ' selected' : '').'>'.h($c['name']).'</option>';
        }
        echo '</select></label><a class="btn tiny" href="index.php?page=inventory">موجودی</a></form></section>';
        if (!$cid) {
            echo '<section class="card"><p>ابتدا یک شرکت فعال تعریف کنید.</p></section>';
            render_footer();
            return;
        }

        $items = ProductionDomain::listItems($wid, $cid);
        $boms = ProductionDomain::listBoms($wid, $cid);
        $plans = ProductionDomain::listPlans($wid, $cid);
        $orders = ManufacturingExecutionDomain::listOrders($wid, $cid);
        $warehouses = InventoryDomain::searchWarehouses($wid, $cid);
        $canManage = Tenant::can('production.manage');
        $adapterMode = ManufacturingInventoryAdapter::mode();

        echo '<section class="production-metrics"><div class="card"><span>فرمول‌ها</span><strong>'.count($boms).'</strong></div><div class="card"><span>دستورات تولید</span><strong>'.count($orders).'</strong></div><div class="card"><span>Stock adapter</span><strong>'.h($adapterMode === ManufacturingInventoryAdapter::MODE_NATIVE ? 'T2 native' : 'temporary').'</strong><small>'.h($adapterMode).'</small></div></section>';

        if ($canManage) {
            echo '<section class="card"><details><summary class="production-toggle">افزودن فرمول ساخت (BOM)</summary><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_create_bom"><div class="production-grid"><label>کد<input name="code" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{1,79}" required></label><label>کالای تولیدی<select name="output_item_id" required>'.self::itemOptions($items).'</select></label><label>مقدار خروجی<input name="output_qty" type="number" step="0.0001" min="0.0001" value="1" required></label></div><div class="production-lines"><div class="production-line production-line-head"><b>ماده</b><b>مصرف</b><b>ضایعات٪</b></div>';
            for ($i = 0; $i < 6; $i++) {
                echo '<div class="production-line"><select name="components['.$i.'][component_item_id]">'.self::itemOptions($items).'</select><input name="components['.$i.'][quantity_per]" type="number" step="0.0001" min="0.0001"><input name="components['.$i.'][scrap_percent]" type="number" step="0.01" min="0" max="100" value="0"></div>';
            }
            echo '</div><button class="btn primary" type="submit">ثبت پیش‌نویس BOM</button></form></details></section>';
        }

        echo '<section class="card"><div class="section-title"><h2>فرمول‌های ساخت</h2></div><div class="table-wrap"><table><thead><tr><th>کد</th><th>محصول</th><th>مواد</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach ($boms as $b) {
            echo '<tr><td>'.h($b['code'].' / R'.$b['revision']).'</td><td>'.h($b['output_code'].' • '.$b['output_name']).'</td><td>'.(int)$b['component_count'].'</td><td>'.h(self::statusFa((string)$b['status'])).'</td><td><a class="btn tiny" href="index.php?page=production&bom_id='.(int)$b['id'].'">جزئیات</a></td></tr>';
        }
        if (!$boms) {
            echo '<tr><td colspan="5" class="muted">فرمولی نیست.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        $selectedBomId = max(0, (int)($_GET['bom_id'] ?? 0));
        if ($selectedBomId) {
            $bom = ProductionDomain::getBom($wid, $cid, $selectedBomId);
            if (!$bom) {
                echo '<section class="card alert danger">BOM یافت نشد.</section>';
            } else {
                $qty = max(0, (float)($_GET['plan_qty'] ?? $bom['output_qty']));
                $warehouseId = max(0, (int)($_GET['warehouse_id'] ?? ($warehouses[0]['id'] ?? 0)));
                echo '<section class="card"><h2>BOM '.h($bom['code']).' — '.h(self::statusFa((string)$bom['status'])).'</h2>';
                if ($canManage && $bom['status'] === 'draft') {
                    echo '<form method="post" style="margin-bottom:1rem">'.csrf_field().'<input type="hidden" name="action" value="prod_approve_bom"><input type="hidden" name="bom_id" value="'.(int)$bom['id'].'"><button class="btn primary" type="submit">تأیید BOM (کاربر دیگر)</button></form>';
                }
                echo '<form method="get" class="production-preview"><input type="hidden" name="page" value="production"><input type="hidden" name="bom_id" value="'.(int)$selectedBomId.'"><label>مقدار تولید<input type="number" name="plan_qty" min="0.0001" step="0.0001" value="'.h($qty).'"></label><label>انبار<select name="warehouse_id">';
                foreach ($warehouses as $w) {
                    echo '<option value="'.(int)$w['id'].'"'.((int)$w['id'] === $warehouseId ? ' selected' : '').'>'.h($w['name']).'</option>';
                }
                echo '</select></label><button class="btn" type="submit">MRP-lite</button></form>';
                if ($warehouseId > 0 && $qty > 0) {
                    $mrp = ManufacturingExecutionDomain::mrpRecommendations($wid, $cid, $selectedBomId, $qty, $warehouseId);
                    echo '<p class="muted">پیشنهاد خرید: '.(int)$mrp['recommendation_count'].' (بدون ایجاد خودکار PO)</p>';
                    $preview = ProductionDomain::preview($wid, $cid, $selectedBomId, $qty, $warehouseId);
                    echo '<div class="table-wrap"><table><thead><tr><th>ماده</th><th>نیاز</th><th>قابل‌استفاده</th><th>کسری</th></tr></thead><tbody>';
                    foreach ($preview['lines'] as $r) {
                        echo '<tr><td>'.h($r['item_code'].' • '.$r['item_name']).'</td><td>'.h($r['required_qty']).'</td><td>'.h($r['available_qty']).'</td><td>'.h($r['shortage_qty']).'</td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
                if ($canManage && $bom['status'] === 'approved' && $warehouses) {
                    echo '<h3>ایجاد دستور تولید</h3><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_create_order"><input type="hidden" name="bom_id" value="'.(int)$bom['id'].'"><div class="production-grid"><label>مقدار برنامه<input name="planned_qty" type="number" step="0.0001" min="0.0001" required></label><label>انبار مواد<select name="raw_warehouse_id" required>';
                    foreach ($warehouses as $w) {
                        echo '<option value="'.(int)$w['id'].'">'.h($w['name']).'</option>';
                    }
                    echo '</select></label><label>انبار محصول<select name="fg_warehouse_id" required>';
                    foreach ($warehouses as $w) {
                        echo '<option value="'.(int)$w['id'].'">'.h($w['name']).'</option>';
                    }
                    echo '</select></label></div><button class="btn primary" type="submit">ایجاد دستور پیش‌نویس</button></form>';
                }
                echo '</section>';
            }
        }

        echo '<section class="card"><div class="section-title"><h2>دستورات تولید</h2></div><div class="table-wrap"><table><thead><tr><th>شماره</th><th>محصول</th><th>برنامه</th><th>FG</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach ($orders as $o) {
            echo '<tr><td>'.h($o['order_no']).'</td><td>'.h(($o['output_code'] ?? '').' • '.($o['output_name'] ?? '')).'</td><td>'.h($o['planned_qty']).'</td><td>'.h($o['received_fg_qty']).'</td><td>'.h(self::statusFa((string)$o['status'])).'</td><td><a class="btn tiny" href="index.php?page=production&order_id='.(int)$o['id'].'">اجرا</a></td></tr>';
        }
        if (!$orders) {
            echo '<tr><td colspan="6" class="muted">دستوری نیست.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        $orderId = max(0, (int)($_GET['order_id'] ?? 0));
        if ($orderId) {
            $order = ManufacturingExecutionDomain::getOrder($wid, $cid, $orderId);
            if (!$order) {
                echo '<section class="card alert danger">دستور یافت نشد.</section>';
            } else {
                echo '<section class="card"><h2>دستور '.h($order['order_no']).' — '.h(self::statusFa((string)$order['status'])).'</h2>';
                echo '<p class="muted">BOM '.h($order['bom_code']).' R'.(int)$order['bom_revision'].' • QC قبول '.h($order['accepted_qc_qty']).' / رد '.h($order['rejected_qc_qty']).' • ضایعات '.h($order['scrap_qty']).' • باقی FG '.h($order['outstanding_fg_qty']).'</p>';
                if ($canManage) {
                    if ($order['status'] === 'draft') {
                        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="prod_approve_order"><input type="hidden" name="order_id" value="'.$orderId.'"><button class="btn primary" type="submit">تأیید دستور (کاربر دیگر)</button></form>';
                    }
                    if ($order['status'] === 'approved') {
                        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="prod_release_order"><input type="hidden" name="order_id" value="'.$orderId.'"><button class="btn primary" type="submit">آزادسازی + رزرو مواد</button></form>';
                    }
                    if (in_array($order['status'], ['released', 'in_progress', 'qc'], true)) {
                        echo '<h3>صدور مواد (جزئی مجاز)</h3><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_issue"><input type="hidden" name="order_id" value="'.$orderId.'">';
                        foreach ($order['bom_snapshot'] as $line) {
                            $remain = (float)$line['required_qty'] - (float)$line['issued_qty'];
                            echo '<div class="production-line"><span>'.h(($line['item_code'] ?? '').' • نیاز '.h($line['required_qty']).' / صادر '.h($line['issued_qty'])).'</span><input type="hidden" name="issue_lines['.(int)$line['id'].'][snapshot_line_id]" value="'.(int)$line['id'].'"><input name="issue_lines['.(int)$line['id'].'][quantity]" type="number" step="0.0001" min="0" max="'.h($remain).'" placeholder="0"><input name="issue_lines['.(int)$line['id'].'][unit_cost]" type="number" step="0.01" min="0" placeholder="بهای واحد"></div>';
                        }
                        echo '<button class="btn primary" type="submit">ثبت صدور</button></form>';
                        echo '<h3>ضایعات (جدا)</h3><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_scrap"><input type="hidden" name="order_id" value="'.$orderId.'"><label>مقدار<input name="quantity" type="number" step="0.0001" min="0.0001" required></label><label>دلیل<input name="reason" required maxlength="500"></label><button class="btn" type="submit">ثبت ضایعات</button></form>';
                        echo '<h3>QC</h3><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_qc"><input type="hidden" name="order_id" value="'.$orderId.'"><label>تصمیم<select name="decision"><option value="accept">قبول</option><option value="reject">رد</option></select></label><label>مقدار<input name="quantity" type="number" step="0.0001" min="0.0001" required></label><label>دلیل (برای رد)<input name="reason" maxlength="500"></label><button class="btn primary" type="submit">ثبت QC</button></form>';
                    }
                    if (in_array($order['status'], ['qc', 'in_progress', 'finished'], true) && (float)$order['fg_eligible_qty'] > 0) {
                        echo '<h3>ورود محصول (جزئی)</h3><form method="post" class="production-form">'.csrf_field().'<input type="hidden" name="action" value="prod_fg"><input type="hidden" name="order_id" value="'.$orderId.'"><label>مقدار (حداکثر '.h($order['fg_eligible_qty']).')<input name="quantity" type="number" step="0.0001" min="0.0001" max="'.h($order['fg_eligible_qty']).'" required></label><button class="btn primary" type="submit">ثبت ورود FG</button></form>';
                    }
                    if (in_array($order['status'], ['finished', 'qc'], true)) {
                        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="prod_close_order"><input type="hidden" name="order_id" value="'.$orderId.'"><button class="btn" type="submit">بستن دستور</button></form>';
                    }
                    try {
                        $cost = ManufacturingCostEvidence::rollup($wid, $cid, $orderId);
                        echo '<h3>شواهد بهای تمام‌شده</h3><p>کلاس: <strong>'.h($cost['resolver_class']).'</strong> • GL: <strong>'.h($cost['gl_posting']).'</strong> • مواد: '.h($cost['material']['amount_irr']).' IRR</p>';
                    } catch (Throwable $e) {
                        echo '<p class="muted">Cost rollup: '.h($e->getMessage()).'</p>';
                    }
                }
                echo '</section>';
            }
        }

        echo '<section class="card"><div class="section-title"><h2>برنامه‌های پیش‌نویس</h2></div><div class="table-wrap"><table><thead><tr><th>#</th><th>فرمول</th><th>تعداد</th><th>وضعیت</th></tr></thead><tbody>';
        foreach ($plans as $p) {
            echo '<tr><td>'.(int)$p['id'].'</td><td>'.h($p['bom_code']).'</td><td>'.h($p['planned_qty']).'</td><td>'.h($p['status']).'</td></tr>';
        }
        if (!$plans) {
            echo '<tr><td colspan="4" class="muted">—</td></tr>';
        }
        echo '</tbody></table></div></section>';
        render_footer();
    }
}
