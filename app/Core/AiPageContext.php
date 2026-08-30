<?php
final class AiPageContext
{
    public const VERSION='v1';
    private const MAX_REFS=8;

    public static function decodeRefs(string $raw): array
    {
        $raw=trim($raw);if($raw==='')return[];
        $refs=json_decode($raw,true);
        if(!is_array($refs))throw new RuntimeException('زمینه صفحه AI نامعتبر است.');
        if(isset($refs['type']))$refs=[$refs];
        if(count($refs)>self::MAX_REFS)throw new RuntimeException('تعداد زمینه‌های انتخابی بیش از حد مجاز است.');
        $out=[];
        foreach($refs as $r){
            if(!is_array($r))throw new RuntimeException('زمینه صفحه AI نامعتبر است.');
            $type=trim((string)($r['type']??''));$id=(int)($r['id']??0);$source=trim((string)($r['source_page']??''));
            if($type===''||$id<=0||$source==='')throw new RuntimeException('مرجع زمینه AI ناقص است.');
            $out[]=['type'=>$type,'id'=>$id,'source_page'=>$source];
        }
        return$out;
    }

    public static function queryRefs(): array
    {
        $type=trim((string)($_GET['context_type']??''));
        if($type==='')return[];
        return [[
            'type'=>$type,
            'id'=>(int)($_GET['context_id']??0),
            'source_page'=>trim((string)($_GET['context_source']??''))
        ]];
    }

    public static function resolve(int $wid,?int $cid,array $refs): array
    {
        if(!$refs)return[];
        if($wid<=0||!$cid)throw new RuntimeException('برای زمینه صفحه، شرکت فعال معتبر لازم است.');
        $co=pdo()->prepare("SELECT id,name FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1");
        $co->execute([$cid,$wid]);$company=$co->fetch();
        if(!$company)throw new RuntimeException('شرکت زمینه AI معتبر نیست.');

        $entities=[];$seen=[];
        foreach(array_slice($refs,0,self::MAX_REFS) as $r){
            $type=trim((string)($r['type']??''));$id=(int)($r['id']??0);$source=trim((string)($r['source_page']??''));
            $key=$type.':'.$id.':'.$source;if(isset($seen[$key]))continue;$seen[$key]=true;

            if($source!=='crm'||$type!=='party')throw new RuntimeException('این نوع زمینه صفحه هنوز در نسخه جاری پشتیبانی نمی‌شود.');
            if(!ModuleRegistry::pageEnabled('crm')||!Tenant::can('crm.view'))throw new RuntimeException('دسترسی CRM برای این زمینه وجود ندارد.');

            $st=pdo()->prepare("SELECT id,code,name,party_type FROM acc_parties
                WHERE workspace_id=? AND company_id=? AND id=? AND active=1 AND party_type IN ('customer','both') LIMIT 1");
            $st->execute([$wid,$cid,$id]);$party=$st->fetch();
            if(!$party)throw new RuntimeException('مشتری انتخاب‌شده در شرکت فعال معتبر نیست.');

            $entities[]=[
                'type'=>'party','id'=>(int)$party['id'],'code'=>(string)($party['code']??''),
                'label'=>(string)$party['name'],'source_page'=>'crm'
            ];
        }
        if(!$entities)throw new RuntimeException('زمینه صفحه معتبر پیدا نشد.');
        return [
            'version'=>self::VERSION,'validated'=>true,'company_id'=>$cid,'company_name'=>(string)$company['name'],
            'source_page'=>'crm','entities'=>$entities
        ];
    }
}
