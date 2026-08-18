<?php
/** Deterministic proactive suggestions. LLM enrichment can be layered on later. */
final class AiSuggestionEngine
{
    public static function refreshCurrentUser(): int
    {
        if(!Auth::check() || Tenant::id()<=0)return 0;
        return self::refreshUser(Tenant::id(),(int)Auth::user()['id']);
    }

    public static function refreshAll(): int
    {
        $rows=pdo()->query("SELECT workspace_id,user_id FROM workspace_members WHERE status='active' ORDER BY workspace_id,user_id")->fetchAll();$n=0;
        foreach($rows as $r)$n+=self::refreshUser((int)$r['workspace_id'],(int)$r['user_id']);
        return$n;
    }

    public static function refreshUser(int $wid,int $uid): int
    {
        if($wid<=0||$uid<=0)return 0;$n=0;
        // Upcoming checks: this is deterministic and explainable, no model required.
        $st=pdo()->prepare("SELECT c.id,c.company_id,c.check_no,c.amount,c.due_date,c.direction,co.name company_name FROM acc_checks c LEFT JOIN companies co ON co.id=c.company_id AND co.workspace_id=c.workspace_id WHERE c.workspace_id=? AND c.status='open' AND c.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY c.due_date LIMIT 50");$st->execute([$wid]);
        foreach($st->fetchAll() as $r){$dir=$r['direction']==='payable'?'پرداختنی':'دریافتنی';$n+=self::insert($wid,$uid,(int)$r['company_id'],'due_check','check:'.$r['id'].':'.$r['due_date'],'سررسید چک نزدیک است',"چک {$dir} شماره {$r['check_no']} برای {$r['company_name']} با مبلغ ".number_format((float)$r['amount'])." در تاریخ {$r['due_date']} سررسید می‌شود.",.92,['check_id'=>(int)$r['id'],'due_date'=>$r['due_date']]);}
        // Stale drafts: useful for accountants supervising many client companies.
        $st=pdo()->prepare("SELECT d.id,d.company_id,d.document_no,d.net_total,d.created_at,c.name company_name FROM acc_sales_docs d JOIN companies c ON c.id=d.company_id AND c.workspace_id=d.workspace_id WHERE d.workspace_id=? AND d.workflow_status='draft' AND d.created_at<DATE_SUB(NOW(),INTERVAL 2 DAY) ORDER BY d.created_at LIMIT 50");$st->execute([$wid]);
        foreach($st->fetchAll() as $r)$n+=self::insert($wid,$uid,(int)$r['company_id'],'stale_draft','sale_draft:'.$r['id'],'پیش‌نویس فروش تعیین تکلیف نشده است',"پیش‌نویس {$r['document_no']} برای {$r['company_name']} از ".substr((string)$r['created_at'],0,10)." باقی مانده؛ آن را بررسی، نهایی یا حذف کنید.",.78,['sales_doc_id'=>(int)$r['id'],'net_total'=>(float)$r['net_total']]);
        $st=pdo()->prepare("SELECT v.id,v.company_id,v.voucher_no,v.created_at,c.name company_name FROM acc_vouchers v JOIN companies c ON c.id=v.company_id AND c.workspace_id=v.workspace_id WHERE v.workspace_id=? AND v.status='draft' AND v.created_at<DATE_SUB(NOW(),INTERVAL 2 DAY) ORDER BY v.created_at LIMIT 50");$st->execute([$wid]);
        foreach($st->fetchAll() as $r)$n+=self::insert($wid,$uid,(int)$r['company_id'],'stale_draft','voucher_draft:'.$r['id'],'سند حسابداری موقت قدیمی است',"سند {$r['voucher_no']} مربوط به {$r['company_name']} هنوز موقت است؛ قبل از گزارش‌گیری دوره آن را بررسی کنید.",.83,['voucher_id'=>(int)$r['id']]);
        // Habit mining from audit logs: discover repeated accounting actions and their common hour.
        $st=pdo()->prepare("SELECT action,HOUR(created_at) hour_slot,COUNT(*) cnt,MAX(summary) summary FROM audit_logs WHERE workspace_id=? AND user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 60 DAY) AND action LIKE 'acc.%' GROUP BY action,HOUR(created_at) HAVING COUNT(*)>=4 ORDER BY cnt DESC LIMIT 8");$st->execute([$wid,$uid]);
        foreach($st->fetchAll() as $r){$label=self::actionLabel((string)$r['action'],(string)$r['summary']);$hour=(int)$r['hour_slot'];$n+=self::insert($wid,$uid,null,'behavior_habit','habit:'.$r['action'].':'.$hour,"الگوی تکرارشونده: {$label}","در ۶۰ روز اخیر این کار را {$r['cnt']} بار، بیشتر حوالی ساعت {$hour} انجام داده‌اید. بعداً می‌توانیم برای همین الگو Draft خودکار بسازیم و فقط تایید شما را بگیریم.",.65,['action'=>$r['action'],'hour'=>$hour,'count'=>(int)$r['cnt']]);}
        return$n;
    }

    private static function insert(int $wid,int $uid,?int $cid,string $type,string $key,string $title,string $body,float $score,array $evidence): int
    {
        $st=pdo()->prepare("INSERT IGNORE INTO ai_suggestions (workspace_id,company_id,user_id,suggestion_type,dedupe_key,title,body,evidence_json,score,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,'new',NOW())");
        $st->execute([$wid,$cid,$uid,$type,mb_substr($key,0,190),mb_substr($title,0,190),$body,json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$score]);return$st->rowCount();
    }

    private static function actionLabel(string $action,string $summary): string
    {
        return match($action){'acc.voucher.create'=>'ثبت سند حسابداری','acc.purchase.create'=>'ثبت سند خرید','acc.sale.create'=>'ثبت سند فروش','acc.check.create'=>'ثبت چک','acc.production.create'=>'ثبت دستور تولید',default=>trim($summary)!==''?$summary:$action};
    }
}
