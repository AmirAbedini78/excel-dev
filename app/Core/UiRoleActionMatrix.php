<?php
/**
 * Role-aware action matrix for RC1 TR-1 / MF-1 cockpits (T6).
 * UI visibility is advisory; Tenant::can / domain requirePermission remain authoritative.
 */
final class UiRoleActionMatrix
{
    public const VERSION = 'rc1-t6-role-matrix';

    /**
     * @return array<string,array{title:string,permissions:string[],actions:array<int,array{key:string,label:string,permission:string,workflow:string,mutates:string}>>}
     */
    public static function roles(): array
    {
        return [
            'buyer' => [
                'title' => 'خریدار / تأمین',
                'permissions' => ['procurement.view', 'accounting.purchase.view', 'accounting.purchase.manage'],
                'actions' => [
                    ['key' => 'po_create', 'label' => 'ایجاد سفارش خرید', 'permission' => 'accounting.purchase.manage', 'workflow' => 'TR-1', 'mutates' => 'purchase_doc'],
                    ['key' => 'po_approve', 'label' => 'تأیید سفارش (Maker≠Checker)', 'permission' => 'accounting.purchase.manage', 'workflow' => 'TR-1', 'mutates' => 'purchase_doc'],
                    ['key' => 'p2p_submit', 'label' => 'ثبت درخواست فاکتور تأمین‌کننده', 'permission' => 'accounting.purchase.manage', 'workflow' => 'TR-1', 'mutates' => 'p2p_bill'],
                    ['key' => 'cockpit_view', 'label' => 'مشاهده کابین تجارت', 'permission' => 'procurement.view', 'workflow' => 'TR-1', 'mutates' => 'none'],
                ],
            ],
            'seller' => [
                'title' => 'فروشنده / سفارش فروش',
                'permissions' => ['accounting.sales.view', 'accounting.sales.manage'],
                'actions' => [
                    ['key' => 'so_confirm', 'label' => 'تأیید سفارش فروش', 'permission' => 'accounting.sales.manage', 'workflow' => 'TR-2', 'mutates' => 'sales_doc'],
                    ['key' => 'cockpit_view_trade', 'label' => 'پیوند به اسناد فروش (خارج از کابین TR-1)', 'permission' => 'accounting.sales.view', 'workflow' => 'TR-2', 'mutates' => 'none'],
                ],
            ],
            'warehouse_operator' => [
                'title' => 'اپراتور انبار',
                'permissions' => ['inventory.view', 'inventory.manage'],
                'actions' => [
                    ['key' => 'gr_post', 'label' => 'رسید خرید (عملیاتی، بدون GL)', 'permission' => 'inventory.manage', 'workflow' => 'TR-1', 'mutates' => 'stock'],
                    ['key' => 'mf_issue', 'label' => 'مصرف مواد تولید (پس از API تی۲)', 'permission' => 'inventory.manage', 'workflow' => 'MF-1', 'mutates' => 'stock'],
                    ['key' => 'mf_fg', 'label' => 'رسید کالای ساخته‌شده (پس از API تی۲)', 'permission' => 'inventory.manage', 'workflow' => 'MF-1', 'mutates' => 'stock'],
                ],
            ],
            'production_planner' => [
                'title' => 'برنامه‌ریز تولید',
                'permissions' => ['production.view', 'production.manage'],
                'actions' => [
                    ['key' => 'bom_draft', 'label' => 'فرمول ساخت پیش‌نویس', 'permission' => 'production.manage', 'workflow' => 'MF-1', 'mutates' => 'bom'],
                    ['key' => 'plan_draft', 'label' => 'برنامه تولید پیش‌نویس', 'permission' => 'production.manage', 'workflow' => 'MF-1', 'mutates' => 'plan'],
                    ['key' => 'mf_cockpit', 'label' => 'مشاهده کابین تولید', 'permission' => 'production.view', 'workflow' => 'MF-1', 'mutates' => 'none'],
                ],
            ],
            'qc_operator' => [
                'title' => 'اپراتور کنترل کیفیت',
                'permissions' => ['production.view', 'inventory.view'],
                'actions' => [
                    ['key' => 'qc_evidence', 'label' => 'ثبت شواهد QC (مسدود تا T1)', 'permission' => 'production.manage', 'workflow' => 'MF-1', 'mutates' => 'qc'],
                ],
            ],
            'accountant' => [
                'title' => 'حسابدار',
                'permissions' => ['accounting.view', 'accounting.purchase.manage', 'accounting.vouchers.manage'],
                'actions' => [
                    ['key' => 'ap_validate', 'label' => 'اعتبارسنجی فاکتور تأمین‌کننده', 'permission' => 'accounting.purchase.manage', 'workflow' => 'TR-1', 'mutates' => 'ap_workflow'],
                    ['key' => 'ap_post', 'label' => 'ثبت دفتر AP', 'permission' => 'accounting.vouchers.manage', 'workflow' => 'TR-1', 'mutates' => 'gl'],
                ],
            ],
            'treasurer' => [
                'title' => 'خزانه‌دار',
                'permissions' => ['accounting.treasury.view', 'accounting.treasury.manage'],
                'actions' => [
                    ['key' => 'ap_pay_draft', 'label' => 'پیش‌نویس پرداخت تأمین‌کننده', 'permission' => 'accounting.treasury.manage', 'workflow' => 'TR-1', 'mutates' => 'ap_payment'],
                    ['key' => 'ap_allocate', 'label' => 'تخصیص پرداخت به فاکتور', 'permission' => 'accounting.treasury.manage', 'workflow' => 'TR-1', 'mutates' => 'ap_allocation'],
                ],
            ],
            'manager' => [
                'title' => 'مدیر / تأییدکننده',
                'permissions' => ['ai.actions.approve', 'accounting.purchase.manage', 'production.view'],
                'actions' => [
                    ['key' => 'ai_approve', 'label' => 'تأیید انسانی Proposal AI', 'permission' => 'ai.actions.approve', 'workflow' => 'SYS-1', 'mutates' => 'via_domain_executor'],
                    ['key' => 'maker_checker', 'label' => 'کنترل دوم اسناد', 'permission' => 'accounting.purchase.manage', 'workflow' => 'TR-1', 'mutates' => 'workflow'],
                ],
            ],
        ];
    }

    /** Actions visible for current Tenant permissions (advisory UI only). */
    public static function visibleActions(?callable $can = null): array
    {
        $can = $can ?? static fn(string $p): bool => class_exists('Tenant') && Tenant::can($p);
        $out = [];
        foreach (self::roles() as $roleKey => $role) {
            foreach ($role['actions'] as $action) {
                if ($can($action['permission'])) {
                    $out[] = $action + ['role' => $roleKey, 'role_title' => $role['title']];
                }
            }
        }
        return $out;
    }

    public static function requiresPermission(string $actionKey): ?string
    {
        foreach (self::roles() as $role) {
            foreach ($role['actions'] as $action) {
                if ($action['key'] === $actionKey) {
                    return $action['permission'];
                }
            }
        }
        return null;
    }
}
