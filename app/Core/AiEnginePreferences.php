<?php
/** Workspace-scoped, non-secret provider/model policy for ERPSMART. */
final class AiEnginePreferences
{
    public static function models(): array
    {
        return [
            'ollama'=>['qwen3.5:0.8b','qwen3:1.7b','gemma3:4b'],
            'gemini'=>['gemini-3.8-flash','gemini-3.6-flash','gemini-3.5-flash-lite'],
        ];
    }

    public static function current(): array
    {
        $defaults=['provider'=>'ollama','model'=>'qwen3.5:0.8b'];
        $st=pdo()->prepare('SELECT settings_json FROM workspaces WHERE id=? LIMIT 1');
        $st->execute([Tenant::id()]);
        $data=json_decode((string)$st->fetchColumn(),true);
        $saved=is_array($data)?($data['ai_engine']??null):null;
        if(!is_array($saved))return $defaults;
        try{return self::validated((string)($saved['provider']??''),(string)($saved['model']??''));}
        catch(Throwable $e){return $defaults;}
    }

    public static function validated(string $provider,string $model): array
    {
        $models=self::models();
        if(!isset($models[$provider])||!in_array($model,$models[$provider],true))
            throw new RuntimeException('ai_engine_selection_invalid');
        return ['provider'=>$provider,'model'=>$model];
    }

    public static function choice(?string $provider=null,?string $model=null): array
    {
        if(($provider===null||$provider==='')&&($model===null||$model===''))return self::current();
        if($provider===null||$provider===''||$model===null||$model==='')
            throw new RuntimeException('ai_engine_selection_incomplete');
        return self::validated($provider,$model);
    }

    public static function save(string $provider,string $model): void
    {
        Tenant::requirePermission('ai.workers.manage');
        $choice=self::validated($provider,$model);
        $st=pdo()->prepare("UPDATE workspaces SET settings_json=JSON_SET(COALESCE(settings_json,JSON_OBJECT()),'$.ai_engine',JSON_OBJECT('provider',?,'model',?)),updated_at=NOW() WHERE id=?");
        $st->execute([$choice['provider'],$choice['model'],Tenant::id()]);
        if($st->rowCount()===0){
            $check=pdo()->prepare('SELECT id FROM workspaces WHERE id=?');$check->execute([Tenant::id()]);
            if(!$check->fetchColumn())throw new RuntimeException('workspace_not_found');
        }
        Audit::log('ai.engine.settings','workspaces',Tenant::id(),'تغییر انتخاب موتور هوشمند',null,null,$choice);
    }
}
