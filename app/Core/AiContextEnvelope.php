<?php
final class AiContextEnvelope
{
    public const VERSION='v2';

    public static function build(int $wid,int $cid,array $currentPageRefs,array $attachedRefs): array
    {
        $current=AiContextResolver::resolve($wid,$cid,array_slice($currentPageRefs,0,2));$attached=AiContextResolver::resolve($wid,$cid,$attachedRefs);
        return ['version'=>self::VERSION,'validated'=>true,'workspace_id'=>$wid,'company_id'=>$cid,'current_page'=>['entities'=>$current],'attached_entities'=>$attached];
    }

    public static function legacyPageContext(array $envelope): array
    {
        $candidates=[];foreach((array)($envelope['attached_entities']??[]) as $e)if(in_array((string)($e['type']??''),['party.customer','party'],true))$candidates[]=$e;
        if(!$candidates)foreach((array)($envelope['current_page']['entities']??[]) as $e)if(in_array((string)($e['type']??''),['party.customer','party'],true))$candidates[]=$e;
        if(count($candidates)!==1)return[];$e=$candidates[0];
        return ['version'=>'v1','validated'=>true,'company_id'=>(int)($envelope['company_id']??0),'source_page'=>(string)($e['source_page']??'crm'),'entities'=>[['type'=>'party','id'=>(int)$e['id'],'code'=>(string)($e['code']??''),'label'=>(string)($e['label']??''),'source_page'=>(string)($e['source_page']??'crm')]]];
    }
}
