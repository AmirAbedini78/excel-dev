<?php
final class AiContextResolver
{
    public const MAX_REFS=12;

    public static function decodeRefs(string $raw): array
    {
        $raw=trim($raw);if($raw==='')return[];$items=json_decode($raw,true);if(!is_array($items))throw new RuntimeException('copilot_context_invalid');
        if(isset($items['type']))$items=[$items];if(count($items)>self::MAX_REFS)throw new RuntimeException('copilot_context_too_many_refs');$out=[];$seen=[];
        foreach($items as $r){
            if(!is_array($r))throw new RuntimeException('copilot_context_invalid');$type=trim((string)($r['type']??''));$id=(int)($r['id']??0);if($type===''||$id<=0)throw new RuntimeException('copilot_context_ref_invalid');
            // Browser-provided label/subtitle/company/source metadata is intentionally ignored for identity.
            $key=$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=['type'=>$type,'id'=>$id];
        }
        return$out;
    }

    public static function resolve(int $wid,int $cid,array $refs): array
    {
        $out=[];foreach(array_slice($refs,0,self::MAX_REFS) as $r){$e=AiEntityRegistry::resolve($wid,$cid,$r);unset($e['canonical']);$out[]=$e;}return$out;
    }
}
