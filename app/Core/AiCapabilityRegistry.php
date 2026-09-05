<?php
final class AiCapabilityRegistry
{
    public const VERSION='v1';

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
                'description'=>'خرید قطعی، مانده طرف‌حساب و ریسک پرونده‌های بازرگانی یک تأمین‌کننده را ترکیب می‌کند.',
                'entities'=>['party.supplier'],'example'=>'/supplier-review @تأمین‌کننده'
            ],
            [
                'id'=>'compare-suppliers','title'=>'مقایسه تأمین‌کننده‌ها','icon'=>'↔️','category'=>'procurement',
                'category_title'=>'خرید و تأمین','permission'=>'procurement.view','risk'=>'read',
                'description'=>'دو تأمین‌کننده را بر اساس خرید ثبت‌شده و سیگنال‌های واقعی Trade مقایسه می‌کند.',
                'entities'=>['party.supplier','party.supplier'],'example'=>'/compare-suppliers @تأمین‌کننده-اول @تأمین‌کننده-دوم'
            ],
            [
                'id'=>'trade-risk','title'=>'ریسک بازرگانی و محموله','icon'=>'🌐','category'=>'trade',
                'category_title'=>'بازرگانی و لجستیک','permission'=>'trade.view','risk'=>'read',
                'description'=>'ETA، حمل، گمرک، تأخیر و Landed Cost را برای پرونده یا کل شرکت بررسی می‌کند.',
                'entities'=>['trade.case','shipment'],'example'=>'/trade-risk @پرونده-بازرگانی'
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
