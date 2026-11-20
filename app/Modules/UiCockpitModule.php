<?php
/**
 * RC1 Platform UX cockpits (T6). Read-only operational surfaces.
 * Mutations stay on domain-owned modules; unauthorized POST is refused here and server-side.
 */
final class UiCockpitModule
{
    public const VERSION = 'rc1-t6-ui-cockpit-module';

    public static function handle(string $action): void
    {
        if ($action === 'ui_cockpit_select_company') {
            if (!Tenant::can('procurement.view') && !Tenant::can('production.view') && !Tenant::can('accounting.purchase.view')) {
                Tenant::requirePermission('procurement.view');
            }
            AccountingRepository::companyId();
            $page = (string)($_POST['return_page'] ?? 'trading_cockpit');
            if (!in_array($page, ['trading_cockpit', 'manufacturing_cockpit'], true)) {
                $page = 'trading_cockpit';
            }
            redirect('index.php?page=' . $page);
        }

        // Cockpit never accepts stock/GL mutations — refuse explicitly for tests & safety.
        if (str_starts_with($action, 'ui_cockpit_mutate_') || in_array($action, [
            'ui_cockpit_post_stock',
            'ui_cockpit_post_gl',
            'ui_cockpit_ai_direct_execute',
        ], true)) {
            http_response_code(403);
            throw new RuntimeException('کابین UX اجازه ثبت مستقیم موجودی/دفتر یا اجرای آزاد AI را ندارد.');
        }

        throw new RuntimeException('عملیات کابین ناشناخته است.');
    }

    public static function renderTrading(): void
    {
        Tenant::requirePermission('procurement.view');
        $cid = AccountingRepository::companyId();
        $wid = Tenant::id();
        render_header('کابین تجارت (TR-1)', 'مسیر سفارش خرید تا پرداخت — وضعیت‌ها و اقدام بعدی');
        self::companyBar('trading_cockpit');
        if (!$cid) {
            echo UiRtlSupport::emptyState('شرکت فعالی نیست', 'ابتدا یک شرکت فعال انتخاب کنید.', [
                'index.php?page=companies' => 'شرکت‌ها',
            ]);
            render_footer();
            return;
        }

        echo '<section class="card ui-cockpit-hero"><div><span class="ui-kicker">Trading Cockpit • RTL</span><h2>از سفارش خرید تا پرداخت تأمین‌کننده</h2>';
        echo '<p class="muted">خلاصه داده‌محور؛ دکمه‌های نوشتن فقط با مجوز واقعی نمایش داده می‌شوند و سرور همچنان مسدود می‌کند.</p></div>';
        echo '<div class="ui-safety">بدون ادعای Paid از رسید تخصیص‌نشده • بدون Bank Reconciled</div></section>';

        try {
            $data = DashboardTradingCockpit::pipeline($wid, $cid);
        } catch (Throwable $e) {
            echo '<section class="card alert danger">' . h($e->getMessage()) . '</section>';
            render_footer();
            return;
        }

        self::metricStrip([
            ['سفارش‌ها', (string)$data['counts']['po']],
            ['منتظر رسید', (string)$data['counts']['awaiting_receipt']],
            ['منتظر تطبیق', (string)$data['counts']['awaiting_match']],
            ['منتظر AP', (string)$data['counts']['awaiting_ap_post']],
            ['منتظر پرداخت', (string)$data['counts']['awaiting_payment']],
            ['تخصیص‌شده', (string)$data['counts']['allocated']],
        ]);

        if ($data['warnings']) {
            echo '<section class="card alert warn"><ul>';
            foreach ($data['warnings'] as $w) {
                echo '<li>' . h($w) . '</li>';
            }
            echo '</ul></section>';
        }

        if (!$data['rows']) {
            echo UiRtlSupport::emptyState(
                'هنوز سفارش خریدی برای این شرکت نیست',
                'از تأمین/خرید یک سفارش کالایی بسازید؛ سپس وضعیت‌ها اینجا دیده می‌شوند.',
                [
                    'index.php?page=procurement' => 'تأمین و خرید',
                    'index.php?page=industrial&section=purchase' => 'اسناد خرید',
                ]
            );
            self::roleHint('TR-1');
            render_footer();
            return;
        }

        echo '<section class="card table-card"><div class="section-title"><h2>خط لوله PO → پرداخت</h2><span class="muted">حداکثر ' . (int)count($data['rows']) . ' سفارش اخیر</span></div>';
        echo '<div class="table-wrap"><table class="ui-cockpit-table"><thead><tr>';
        echo '<th>سفارش</th><th>تأمین‌کننده</th><th>وضعیت PO</th><th>رسید</th><th>تطبیق/فاکتور</th><th>AP</th><th>پرداخت</th><th>مرحله</th><th>اقدام بعدی</th>';
        echo '</tr></thead><tbody>';
        foreach ($data['rows'] as $r) {
            echo '<tr>';
            echo '<td><a href="' . h($r['links']['po']) . '">' . h($r['document_no']) . '</a><div class="muted">' . h(UiRtlSupport::faDate($r['document_date'])) . '</div></td>';
            echo '<td>' . h($r['supplier_name']) . '</td>';
            echo '<td>' . h($r['po_status']) . '</td>';
            echo '<td>' . (int)$r['receipt_count'] . ' رسید<br><span class="muted">باز: ' . h(UiRtlSupport::qty($r['open_qty'])) . '</span></td>';
            if ($r['bill']) {
                echo '<td>' . h($r['bill']['invoice_no'] ?: ('#' . $r['bill']['id'])) . '<br>' . UiRtlSupport::badge('info', (string)$r['bill']['status']) . '</td>';
            } else {
                echo '<td class="muted">—</td>';
            }
            if ($r['invoice']) {
                echo '<td><a href="' . h($r['links']['invoice'] ?? '#') . '">' . h($r['invoice']['document_no']) . '</a><br>' . UiRtlSupport::badge('info', (string)($r['invoice']['ap_status'] ?? '—'));
                if ($r['invoice']['outstanding'] !== null) {
                    echo '<div class="muted">مانده: ' . h(UiRtlSupport::moneyIrr($r['invoice']['outstanding'])) . '</div>';
                }
                echo '</td>';
            } else {
                echo '<td class="muted">—</td>';
            }
            if ($r['payment']) {
                echo '<td>' . h($r['payment']['payment_no']) . '<br>' . UiRtlSupport::badge('muted', (string)$r['payment']['status']);
                echo '<div class="muted">' . h($r['payment']['claim']) . '</div></td>';
            } else {
                echo '<td class="muted">—</td>';
            }
            echo '<td>' . UiRtlSupport::badge($r['stage_tone'], $r['stage_label']) . '</td>';
            echo '<td class="row-actions">';
            foreach ($r['next_actions'] as $a) {
                if (!empty($a['allowed'])) {
                    echo '<a class="btn tiny" href="' . h($a['href']) . '">' . h($a['label']) . '</a>';
                } else {
                    echo '<span class="muted" title="بدون مجوز">' . h($a['label']) . '</span> ';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
        self::roleHint('TR-1');
        render_footer();
    }

    public static function renderManufacturing(): void
    {
        Tenant::requirePermission('production.view');
        $cid = AccountingRepository::companyId();
        $wid = Tenant::id();
        render_header('کابین تولید (MF-1)', 'BOM، برنامه، مصرف، QC و شواهد کالای ساخته‌شده');
        self::companyBar('manufacturing_cockpit');
        if (!$cid) {
            echo UiRtlSupport::emptyState('شرکت فعالی نیست', 'ابتدا یک شرکت فعال انتخاب کنید.');
            render_footer();
            return;
        }

        echo '<section class="card ui-cockpit-hero"><div><span class="ui-kicker">Manufacturing Cockpit • RTL</span><h2>شواهد عملیاتی تولید</h2>';
        echo '<p class="muted">خواندنی و داده‌محور؛ ثبت مصرف/FG/QC در مالکیت دامنه باقی می‌ماند.</p></div>';
        echo '<div class="ui-safety">ارزش‌گذاری GL پیش‌فرض: BLOCKED</div></section>';

        try {
            $snap = DashboardManufacturingCockpit::snapshot($wid, $cid);
        } catch (Throwable $e) {
            echo '<section class="card alert danger">' . h($e->getMessage()) . '</section>';
            render_footer();
            return;
        }

        self::metricStrip([
            ['BOM', (string)$snap['counts']['boms']],
            ['برنامه', (string)$snap['counts']['plans']],
            ['مصرف', (string)$snap['counts']['issues']],
            ['رسید ساخته‌شده', (string)$snap['counts']['fg_receipts']],
        ]);

        foreach ($snap['blocks'] as $b) {
            echo UiRtlSupport::blockedState($b['title'], $b['detail'], (string)($b['owner'] ?? ''));
        }

        echo '<section class="card table-card"><div class="section-title"><h2>فرمول‌های ساخت</h2><a class="btn tiny" href="index.php?page=production">کارگاه تولید</a></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>کد</th><th>خروجی</th><th>مواد</th><th>وضعیت</th><th></th></tr></thead><tbody>';
        foreach ($snap['boms'] as $b) {
            echo '<tr><td>' . h($b['code'] . ' / R' . $b['revision']) . '</td><td>' . h(($b['output_code'] ?? '') . ' • ' . ($b['output_name'] ?? '')) . '</td><td>' . (int)$b['component_count'] . '</td><td>' . UiRtlSupport::badge('info', (string)$b['status']) . '</td>';
            echo '<td><a class="btn tiny" href="index.php?page=production&bom_id=' . (int)$b['id'] . '">سنجش مواد</a></td></tr>';
        }
        if (!$snap['boms']) {
            echo '<tr><td colspan="5" class="muted">BOM ثبت نشده — از کارگاه تولید یک فرمول پیش‌نویس بسازید.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<section class="card table-card"><div class="section-title"><h2>برنامه‌های تولید</h2></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>BOM</th><th>خروجی</th><th>انبار</th><th>مقدار</th><th>هدف</th><th>مرحله</th></tr></thead><tbody>';
        foreach ($snap['plans'] as $p) {
            echo '<tr><td>' . h($p['bom_code']) . '</td><td>' . h($p['output_name']) . '</td><td>' . h($p['warehouse_name']) . '</td><td>' . h(UiRtlSupport::qty($p['planned_qty'])) . '</td><td>' . h(UiRtlSupport::faDate($p['target_date'])) . '</td><td>' . UiRtlSupport::badge($p['stage_tone'], $p['stage_label']) . '</td></tr>';
        }
        if (!$snap['plans']) {
            echo '<tr><td colspan="6" class="muted">برنامه پیش‌نویسی وجود ندارد.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        $ev = $snap['evidence'];
        echo '<section class="card table-card"><div class="section-title"><h2>شواهد انبار تولید</h2><span class="muted">posted movements only</span></div>';
        if (empty($ev['rows'])) {
            echo '<p class="muted">حرکت production_issue / production_fg_receipt یافت نشد.</p>';
        } else {
            echo '<div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>نوع</th><th>جهت</th><th>کالا</th><th>مقدار</th><th>منبع</th><th>مرجع</th></tr></thead><tbody>';
            foreach ($ev['rows'] as $r) {
                echo '<tr><td>' . h(UiRtlSupport::faDate($r['movement_date'])) . '</td><td>' . h($r['movement_type']) . '</td><td>' . h($r['direction']) . '</td><td>#' . (int)$r['item_id'] . '</td><td>' . h(UiRtlSupport::qty($r['quantity'])) . '</td><td>' . h(($r['source_type'] ?? '') . ' #' . ($r['source_id'] ?? '')) . '</td><td>' . h($r['reference_no'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        $val = $ev['valuation'] ?? [];
        echo '<p class="muted">سیاست ارزش‌گذاری: <strong>' . h((string)($val['policy'] ?? 'BLOCKED')) . '</strong> — ' . h((string)($val['note'] ?? '')) . '</p></section>';

        $qc = $ev['qc'] ?? ['status' => 'blocked', 'detail' => ''];
        if (($qc['status'] ?? '') === 'blocked') {
            echo UiRtlSupport::blockedState('کنترل کیفیت (QC)', (string)($qc['detail'] ?? 'مسدود'), 'T1');
        }

        echo '<section class="card"><div class="section-title"><h2>اقدامات پیشنهادی</h2></div><div class="row-actions">';
        foreach ($snap['next_actions'] as $a) {
            if (!empty($a['allowed'])) {
                echo '<a class="btn tiny primary" href="' . h($a['href']) . '">' . h($a['label']) . '</a>';
            } else {
                echo '<span class="btn tiny disabled" title="مسدود یا بدون مجوز">' . h($a['label']) . '</span>';
            }
        }
        echo '</div></section>';

        self::roleHint('MF-1');
        self::aiGuardrailBanner();
        render_footer();
    }

    private static function companyBar(string $returnPage): void
    {
        $companies = AccountingRepository::companies();
        $current = AccountingRepository::companyId();
        echo '<section class="card acc-company-bar"><div><strong>شرکت فعال</strong></div>';
        echo '<form method="post" class="acc-inline">' . csrf_field() . '<input type="hidden" name="action" value="ui_cockpit_select_company">';
        echo '<input type="hidden" name="return_page" value="' . h($returnPage) . '">';
        echo '<select name="company_id" onchange="this.form.submit()">';
        foreach ($companies as $c) {
            echo '<option value="' . (int)$c['id'] . '" ' . ((int)$c['id'] === $current ? 'selected' : '') . '>' . h($c['name']) . '</option>';
        }
        echo '</select></form>';
        echo '<div class="row-actions">';
        if ($returnPage === 'trading_cockpit') {
            echo '<a class="btn tiny" href="index.php?page=p2p">P2P</a><a class="btn tiny" href="index.php?page=inventory">انبار</a><a class="btn tiny" href="index.php?page=industrial&section=treasury">خزانه</a>';
        } else {
            echo '<a class="btn tiny" href="index.php?page=production">کارگاه تولید</a><a class="btn tiny" href="index.php?page=inventory">موجودی</a>';
        }
        echo '</div></section>';
    }

    private static function metricStrip(array $items): void
    {
        echo '<section class="acc-metrics ui-metrics">';
        foreach ($items as $item) {
            echo '<article class="card"><strong>' . h($item[1]) . '</strong><span>' . h($item[0]) . '</span></article>';
        }
        echo '</section>';
    }

    private static function roleHint(string $workflow): void
    {
        $actions = array_values(array_filter(
            UiRoleActionMatrix::visibleActions(),
            static fn($a) => ($a['workflow'] ?? '') === $workflow || ($a['mutates'] ?? '') === 'none'
        ));
        echo '<section class="card"><div class="section-title"><h2>اقدامات مجاز نقش شما</h2><span class="muted">ماتریس مشاوره؛ مجوز دامنه قطعی است</span></div>';
        if (!$actions) {
            echo '<p class="muted">برای این گردش‌کار اقدامی با مجوز فعلی دیده نمی‌شود.</p></section>';
            return;
        }
        echo '<ul class="ui-role-list">';
        foreach (array_slice($actions, 0, 12) as $a) {
            echo '<li><strong>' . h($a['label']) . '</strong> <span class="muted">(' . h($a['role_title']) . ' • ' . h($a['permission']) . ')</span></li>';
        }
        echo '</ul></section>';
    }

    private static function aiGuardrailBanner(): void
    {
        $p = AiProposalGuardrails::policySummary();
        echo '<section class="card ui-ai-guard"><strong>Safe AI:</strong> ' . h($p['mutations']) . ' — تأیید انسانی الزامی؛ متن آزاد موجودی/دفتر را تغییر نمی‌دهد.</section>';
    }
}
