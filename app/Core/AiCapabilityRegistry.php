<?php
final class AiCapabilityRegistry
{
    public const VERSION='v2';

    public static function definitions(): array
    {
        return [
            [
                'id'=>'customer-review','title'=>'بررسی ۳۶۰ مشتری','icon'=>'👤','category'=>'crm',
                'category_title'=>'مشتری و CRM','permission'=>'crm.view','risk'=>'read',
                'description'=>'فروش، مانده، تعهد تحویل و Pipeline یک مشتری را یکجا بررسی می‌کند.',
                'entities'=>['party.customer'],'example'=>'/customer-review @مشتری'
            ],
            [
                'id'=>'compare-customers','title'=>'مقایسه مشتری‌ها','icon'=>'⚖️','category'=>'crm',
                'category_title'=>'مشتری و CRM','permission'=>'crm.view','risk'=>'read',
                'description'=>'دو مشتری را با داده ۳۶۰ مقایسه می‌کند و تفاوت‌های قابل اتکا را نشان می‌دهد.',
                'entities'=>['party.customer','party.customer'],'example'=>'/compare-customers @مشتری-اول @مشتری-دوم'
            ],
            [
                'id'=>'supplier-review','title'=>'بررسی عملکرد تأمین‌کننده','icon'=>'🏭','category'=>'procurement',
                'category_title'=>'خرید و تأمین','permission'=>'procurement.view','risk'=>'read',
                'description'=>'بدون رتبه‌سازی مصنوعی، خرید قطعی، دریافت/پذیرش، تأخیر و Trade Risk تأمین‌کننده‌ها را کنار هم تحلیل می‌کند.',
                'entities'=>['party.supplier'],'example'=>'/supplier-review @تأمین‌کننده'
            ],
            [
                'id'=>'compare-suppliers','title'=>'مقایسه تأمین‌کننده‌ها','icon'=>'↔️','category'=>'procurement',
                'category_title'=>'خرید و تأمین','permission'=>'procurement.view','risk'=>'read',
                'description'=>'دو تأمین‌کننده را با Evidence Pack خرید قطعی، دریافت/پذیرش، تأخیر و Trade Risk مقایسه می‌کند.',
                'entities'=>['party.supplier','party.supplier'],'example'=>'/compare-suppliers @تأمین‌کننده-اول @تأمین‌کننده-دوم'
            ],
            [
                'id'=>'trade-risk','title'=>'ریسک بازرگانی و محموله','icon'=>'🌐','category'=>'trade',
                'category_title'=>'بازرگانی و لجستیک','permission'=>'trade.view','risk'=>'read',
                'description'=>'ETA، حمل، گمرک، تأخیر و Landed Cost را برای پرونده یا کل شرکت بررسی می‌کند.',
                'entities'=>['trade.case','shipment'],'example'=>'/trade-risk @پرونده-بازرگانی'
            ],
            [
                'id'=>'shipment-impact','title'=>'اثر محموله بر تعهدهای فروش','icon'=>'🔗','category'=>'trade',
                'category_title'=>'بازرگانی و لجستیک','permission'=>'trade.view','risk'=>'read',
                'description'=>'پرونده/محموله را به کالا، موجودی و تعهدهای فروش وصل می‌کند و Exposure شناخته‌شده را نشان می‌دهد.',
                'entities'=>['trade.case','shipment'],'example'=>'/shipment-impact @پرونده-بازرگانی'
            ],
            [
                'id'=>'inventory-risk','title'=>'ریسک موجودی و کمبود','icon'=>'📦','category'=>'inventory',
                'category_title'=>'کالا و انبار','permission'=>'inventory.view','risk'=>'read',
                'description'=>'کمبود، موجودی قابل دسترس و نیاز تأمین را از داده انبار بررسی می‌کند.',
                'entities'=>['item','warehouse'],'example'=>'/inventory-risk'
            ],
            [
                'id'=>'executive-brief','title'=>'بریف مدیریتی','icon'=>'🧭','category'=>'management',
                'category_title'=>'مدیریت','permission'=>'ai.use','risk'=>'read',
                'description'=>'ریسک‌های مهم Trade، موجودی و فروش را در یک بریف کوتاه مدیریتی جمع می‌کند.',
                'entities'=>['company'],'example'=>'/executive-brief'
            ],
            [
                'id'=>'explain-previous','title'=>'توضیح مبنای پاسخ قبلی','icon'=>'🔎','category'=>'conversation',
                'category_title'=>'گفتگو','permission'=>'ai.use','risk'=>'read',
                'description'=>'بر اساس metadata و Toolهای پاسخ قبلی توضیح می‌دهد نتیجه از چه داده‌ای آمده است.',
                'entities'=>[],'example'=>'/explain-previous'
            ],
            [
                'id'=>'sales-fulfillment','title'=>'وضعیت تأمین و تحویل فروش','icon'=>'🚚','category'=>'sales',
                'category_title'=>'فروش و تحویل','permission'=>'sales.view','risk'=>'read',
                'description'=>'وضعیت رزرو، تحویل و باقیمانده سفارش/فاکتور فروش را از داده ERP گزارش می‌کند.',
                'entities'=>['sales.document','delivery'],'example'=>'/sales-fulfillment @مشتری'
            ],
            [
                'id'=>'sales-margin','title'=>'حاشیه سود فروش','icon'=>'📈','category'=>'sales',
                'category_title'=>'فروش و تحویل','permission'=>'sales.view','risk'=>'read',
                'description'=>'حاشیه سود تحویل‌شده و COGS را برای سند فروش تحلیل می‌کند.',
                'entities'=>['sales.document'],'example'=>'/sales-margin'
            ],
            [
                'id'=>'sales-invoice-review','title'=>'بررسی فاکتور فروش','icon'=>'🧾','category'=>'sales',
                'category_title'=>'فروش و تحویل','permission'=>'sales.view','risk'=>'read',
                'description'=>'جزئیات فاکتور فروش، خطوط، وضعیت و در صورت نیاز تحویل/رزرو را می‌خواند.',
                'entities'=>['sales.document','party.customer'],'example'=>'فاکتور فروش شماره ۱۰۲۳ را بررسی کن'
            ],
            [
                'id'=>'purchase-invoice-review','title'=>'بررسی فاکتور/سند خرید','icon'=>'📥','category'=>'procurement',
                'category_title'=>'خرید و تأمین','permission'=>'procurement.view','risk'=>'read',
                'description'=>'جزئیات سند خرید و خطوط کالا را با جستجوی شماره یا تأمین‌کننده برمی‌گرداند.',
                'entities'=>['purchase.document','party.supplier'],'example'=>'آخرین فاکتور خرید @تأمین‌کننده'
            ],
            [
                'id'=>'financial-report','title'=>'گزارش مدیریتی دوره‌ای','icon'=>'📊','category'=>'finance',
                'category_title'=>'مالی و گزارش','permission'=>'accounting.view','risk'=>'read',
                'description'=>'بسته گزارش: خلاصه شرکت، تراز، فروش/خرید ماه جاری و چک‌های باز.',
                'entities'=>['company'],'example'=>'گزارش مالی این ماه را بده'
            ],
            [
                'id'=>'cashflow-checks','title'=>'نقدینگی و چک','icon'=>'💳','category'=>'finance',
                'category_title'=>'مالی و گزارش','permission'=>'accounting.view','risk'=>'read',
                'description'=>'چک‌های باز، سررسید و حساب‌های نقد/بانک را برای ارزیابی فشار نقدینگی بررسی می‌کند.',
                'entities'=>['party.customer','party.supplier'],'example'=>'چک‌های سررسید گذشته را بگو'
            ],
            [
                'id'=>'create-sales-invoice','title'=>'پیش‌نویس فاکتور فروش','icon'=>'✍️','category'=>'sales',
                'category_title'=>'فروش و تحویل','permission'=>'sales.edit','risk'=>'proposal',
                'description'=>'از زبان طبیعی طرف و اقلام را پیدا می‌کند و پیش‌نویس فاکتور می‌سازد؛ اجرا فقط پس از تأیید انسانی.',
                'entities'=>['party.customer','item'],'example'=>'برای @مشتری فاکتور فروش ۵ عدد کالای X بزن'
            ],
            [
                'id'=>'crm-followup','title'=>'پیگیری‌های CRM','icon'=>'📞','category'=>'crm',
                'category_title'=>'مشتری و CRM','permission'=>'crm.view','risk'=>'read',
                'description'=>'صف پیگیری‌های سررسید و عقب‌افتاده CRM.',
                'entities'=>['party.customer','crm.activity'],'example'=>'/crm-followup'
            ],
            [
                'id'=>'crm-pipeline','title'=>'Pipeline فروش','icon'=>'🎯','category'=>'crm',
                'category_title'=>'مشتری و CRM','permission'=>'crm.view','risk'=>'read',
                'description'=>'خلاصه فرصت‌های فروش و ارزش Pipeline.',
                'entities'=>['crm.opportunity'],'example'=>'/crm-pipeline'
            ],
            [
                'id'=>'assistant-help','title'=>'راهنمای دستیار','icon'=>'❓','category'=>'conversation',
                'category_title'=>'گفتگو','permission'=>'ai.use','risk'=>'read',
                'description'=>'فهرست مهارت‌ها و نمونه درخواست‌های محاوره‌ای (فاکتور، گزارش، موجودی، CRM).',
                'entities'=>[],'example'=>'چه کارهایی می‌توانی انجام بدهی؟'
            ],
        ];
    }

    public static function catalog(): array
    {
        $out=[];
        foreach(self::definitions() as $d){
            $permission=(string)($d['permission']??'ai.use');
            if($permission!==''&&!Tenant::can($permission))continue;
            $out[]=[
                'id'=>(string)$d['id'],
                'title'=>(string)$d['title'],
                'icon'=>(string)$d['icon'],
                'category'=>(string)$d['category'],
                'category_title'=>(string)$d['category_title'],
                'description'=>(string)$d['description'],
                'entities'=>array_values((array)$d['entities']),
                'example'=>(string)$d['example'],
                'risk'=>(string)$d['risk'],
            ];
        }
        return$out;
    }
}
