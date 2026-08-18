<?php
final class AccountingRepository
{
    public static function companyId(): int
    {
        $wid=Tenant::id();
        $requested=(int)($_GET['company_id']??$_POST['company_id']??($_SESSION['acc_company_id']??0));
        if($requested>0){
            $st=pdo()->prepare("SELECT id FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1");
            $st->execute([$requested,$wid]);
            if($st->fetchColumn()){
                $_SESSION['acc_company_id']=$requested;
                AccountingSchema::seedCompany($wid,$requested);
                return $requested;
            }
        }
        $st=pdo()->prepare("SELECT id FROM companies WHERE workspace_id=? AND active=1 ORDER BY name LIMIT 1");
        $st->execute([$wid]);$id=(int)$st->fetchColumn();
        if($id){
            $_SESSION['acc_company_id']=$id;
            AccountingSchema::seedCompany($wid,$id);
        }
        return $id;
    }

    public static function company(): ?array
    {
        $id=self::companyId();if(!$id)return null;
        $st=pdo()->prepare("SELECT * FROM companies WHERE workspace_id=? AND id=? LIMIT 1");
        $st->execute([Tenant::id(),$id]);$r=$st->fetch();return$r?:null;
    }

    public static function companies(): array
    {
        return RuntimeCache::remember('acc:companies',60,function(){
            $st=pdo()->prepare("SELECT id,name,company_type,legal_personality,national_id,economic_code FROM companies
                WHERE workspace_id=? AND active=1 ORDER BY name");
            $st->execute([Tenant::id()]);return$st->fetchAll();
        },Tenant::id());
    }

    public static function options(string $table,bool $onlyActive=true): array
    {
        $allowed=['acc_accounts','acc_parties','acc_cost_centers','acc_projects','acc_units','acc_warehouses','acc_item_groups','acc_items','acc_fiscal_years','acc_boms','acc_cash_accounts'];
        if(!in_array($table,$allowed,true))return[];
        $cid=self::companyId();if(!$cid)return[];
        $active=$onlyActive && $table!=='acc_fiscal_years' ? " AND active=1" : "";
        $st=pdo()->prepare("SELECT * FROM `$table` WHERE workspace_id=? AND company_id=? $active ORDER BY id DESC");
        $st->execute([Tenant::id(),$cid]);return$st->fetchAll();
    }

    public static function owns(string $table,int $id): bool
    {
        $allowed=['acc_accounts','acc_parties','acc_cost_centers','acc_projects','acc_units','acc_warehouses','acc_item_groups','acc_items','acc_fiscal_years','acc_boms','acc_cash_accounts'];
        if($id<=0||!in_array($table,$allowed,true))return false;
        $st=pdo()->prepare("SELECT 1 FROM `$table` WHERE id=? AND workspace_id=? AND company_id=? LIMIT 1");
        $st->execute([$id,Tenant::id(),self::companyId()]);
        return(bool)$st->fetchColumn();
    }

    public static function settings(string $section=''): array
    {
        $cid=self::companyId();if(!$cid)return[];
        $sql="SELECT * FROM acc_module_settings WHERE workspace_id=? AND company_id=?";
        $p=[Tenant::id(),$cid];
        if($section!==''){$sql.=" AND section_key=?";$p[]=$section;}
        $sql.=" ORDER BY section_key,sort_order,id";
        $st=pdo()->prepare($sql);$st->execute($p);return$st->fetchAll();
    }

    public static function clear(): void { RuntimeCache::clearWorkspace(Tenant::id()); }

    public static function nextNumber(string $table,string $column,string $prefix): string
    {
        $allowed=['acc_purchase_docs'=>'document_no','acc_sales_docs'=>'document_no','acc_vouchers'=>'voucher_no','acc_production_orders'=>'order_no'];
        if(($allowed[$table]??'')!==$column)return $prefix.date('ymdHis');
        $st=pdo()->prepare("SELECT COUNT(*)+1 FROM `$table` WHERE workspace_id=? AND company_id=?");
        $st->execute([Tenant::id(),self::companyId()]);
        return $prefix.str_pad((string)$st->fetchColumn(),6,'0',STR_PAD_LEFT);
    }

    public static function date(?string $value): ?string
    {
        $v=trim((string)$value);if($v==='')return null;
        $parsed=Jalali::parse($v);if($parsed)return$parsed;
        $v=Jalali::enDigits($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;
    }

    public static function faDate(?string $value): string { return $value?Jalali::fromGregorian($value):''; }
}
