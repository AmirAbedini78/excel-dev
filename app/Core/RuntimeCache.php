<?php
final class RuntimeCache
{
    public const SCHEMA_VERSION = '7.0.0';

    private static string $root='';
    private static string $dbHash='default';
    private static bool $booted=false;

    public static function boot(string $storageRoot,array $dbConfig=[]): void
    {
        if(self::$booted)return;
        $fingerprint=implode('|',[
            (string)($dbConfig['host']??''),
            (string)($dbConfig['port']??''),
            (string)($dbConfig['database']??''),
            (string)($dbConfig['username']??''),
        ]);
        self::$dbHash=substr(hash('sha256',$fingerprint?:'default'),0,16);
        $base=rtrim($storageRoot,'/\\');
        self::mkdir($base);
        if(!is_dir($base)||!is_writable($base)){
            $base=rtrim(sys_get_temp_dir(),'/\\').DIRECTORY_SEPARATOR.'accounting_crm_cache';
            self::mkdir($base);
        }
        self::$root=$base.DIRECTORY_SEPARATOR.'runtime_cache'.DIRECTORY_SEPARATOR.self::$dbHash;
        self::mkdir(self::$root);
        self::$booted=true;
    }

    private static function mkdir(string $dir): void
    {
        if(!is_dir($dir))@mkdir($dir,0750,true);
    }

    private static function ensureBooted(): void
    {
        if(self::$booted)return;
        $root=defined('APP_ROOT')?APP_ROOT.'/storage/cache':sys_get_temp_dir().'/accounting_crm_cache';
        self::boot($root,[]);
    }

    private static function bucket(?int $workspaceId): string
    {
        self::ensureBooted();
        $dir=self::$root.DIRECTORY_SEPARATOR.($workspaceId && $workspaceId>0?'workspace_'.$workspaceId:'global');
        self::mkdir($dir);
        return $dir;
    }

    private static function path(string $key,?int $workspaceId): string
    {
        return self::bucket($workspaceId).DIRECTORY_SEPARATOR.hash('sha256',$key).'.cache';
    }

    public static function get(string $key,$default=null,?int $workspaceId=null)
    {
        $path=self::path($key,$workspaceId);
        if(!is_file($path))return $default;
        $raw=@file_get_contents($path);
        if($raw===false)return $default;
        $payload=@unserialize($raw,['allowed_classes'=>false]);
        if(!is_array($payload)||!array_key_exists('value',$payload))return $default;
        $expires=(int)($payload['expires']??0);
        if($expires>0 && $expires<time()){
            @unlink($path);
            return $default;
        }
        return $payload['value'];
    }

    public static function set(string $key,$value,int $ttl=60,?int $workspaceId=null): void
    {
        $ttl=max(1,min(86400,$ttl));
        $path=self::path($key,$workspaceId);
        $tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $payload=serialize(['expires'=>time()+$ttl,'created'=>time(),'value'=>$value]);
        if(@file_put_contents($tmp,$payload,LOCK_EX)!==false){
            @chmod($tmp,0640);
            @rename($tmp,$path);
        }else{
            @unlink($tmp);
        }
    }

    public static function remember(string $key,int $ttl,callable $factory,?int $workspaceId=null)
    {
        $miss=new stdClass();
        $cached=self::get($key,$miss,$workspaceId);
        if($cached!==$miss)return $cached;

        // Small stampede guard for concurrent PHP-FPM requests on shared hosting.
        $lockPath=self::path($key,$workspaceId).'.lock';
        $lock=@fopen($lockPath,'c');
        if($lock && @flock($lock,LOCK_EX)){
            $cached=self::get($key,$miss,$workspaceId);
            if($cached!==$miss){@flock($lock,LOCK_UN);@fclose($lock);return$cached;}
            $value=$factory();
            self::set($key,$value,$ttl,$workspaceId);
            @flock($lock,LOCK_UN);@fclose($lock);
            return$value;
        }
        if($lock)@fclose($lock);
        $value=$factory();self::set($key,$value,$ttl,$workspaceId);return$value;
    }

    public static function forget(string $key,?int $workspaceId=null): void
    {
        @unlink(self::path($key,$workspaceId));
    }

    private static function removeTree(string $dir): void
    {
        if(!is_dir($dir))return;
        $items=@scandir($dir)?:[];
        foreach($items as $item){
            if($item==='.'||$item==='..')continue;
            $p=$dir.DIRECTORY_SEPARATOR.$item;
            if(is_dir($p))self::removeTree($p); else @unlink($p);
        }
        @rmdir($dir);
    }

    public static function clearWorkspace(int $workspaceId): void
    {
        if($workspaceId<=0)return;
        self::ensureBooted();
        self::removeTree(self::$root.DIRECTORY_SEPARATOR.'workspace_'.$workspaceId);
    }

    public static function clearGlobal(): void
    {
        self::ensureBooted();
        self::removeTree(self::$root.DIRECTORY_SEPARATOR.'global');
    }

    public static function clearAll(): void
    {
        self::ensureBooted();
        if(!is_dir(self::$root))return;
        foreach(@scandir(self::$root)?:[] as $item){
            if($item==='.'||$item==='..'||$item==='schema.json')continue;
            $p=self::$root.DIRECTORY_SEPARATOR.$item;
            if(is_dir($p))self::removeTree($p);else @unlink($p);
        }
    }

    private static function schemaPath(): string
    {
        self::ensureBooted();
        return self::$root.DIRECTORY_SEPARATOR.'schema.json';
    }

    public static function schemaReady(?string $version=null): bool
    {
        $version=$version?:self::SCHEMA_VERSION;
        $path=self::schemaPath();
        if(!is_file($path))return false;
        $j=json_decode((string)@file_get_contents($path),true);
        return is_array($j)&&($j['version']??'')===$version;
    }

    public static function markSchema(string $version=self::SCHEMA_VERSION): void
    {
        $data=[
            'version'=>$version,
            'database_hash'=>self::$dbHash,
            'updated_at'=>date('c'),
            'php'=>PHP_VERSION,
        ];
        @file_put_contents(self::schemaPath(),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);
    }

    public static function schemaInfo(): array
    {
        $path=self::schemaPath();
        $j=is_file($path)?json_decode((string)@file_get_contents($path),true):null;
        return is_array($j)?$j:['version'=>'not-ready'];
    }

    public static function stats(?int $workspaceId=null): array
    {
        self::ensureBooted();
        $dirs=[];
        if($workspaceId && $workspaceId>0)$dirs[]=self::$root.DIRECTORY_SEPARATOR.'workspace_'.$workspaceId;
        else $dirs[] = self::$root;

        $files=0;$bytes=0;$expired=0;
        foreach($dirs as $dir){
            if(!is_dir($dir))continue;
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
            foreach($it as $f){
                if(!$f->isFile()||!str_ends_with($f->getFilename(),'.cache'))continue;
                $files++;$bytes+=$f->getSize();
                $raw=@file_get_contents($f->getPathname());
                $p=$raw!==false?@unserialize($raw,['allowed_classes'=>false]):null;
                if(is_array($p)&&isset($p['expires'])&&(int)$p['expires']<time())$expired++;
            }
        }
        return [
            'backend'=>'File cache',
            'entries'=>$files,
            'bytes'=>$bytes,
            'expired'=>$expired,
            'schema'=>self::schemaInfo(),
            'opcache'=>function_exists('opcache_get_status') && (bool)@opcache_get_status(false),
            'apcu'=>function_exists('apcu_enabled') && @apcu_enabled(),
            'php'=>PHP_VERSION,
        ];
    }

    public static function warmWorkspace(int $workspaceId): array
    {
        if($workspaceId<=0)return[];
        $ttl=max(10,min(3600,(int)(function_exists('setting')?setting('cache_ttl_seconds','60'):60)));
        $result=[];
        try{
            $result['companies']=self::remember('companies:active',$ttl,function()use($workspaceId){
                $st=pdo()->prepare("SELECT * FROM companies WHERE workspace_id=? AND active=1 ORDER BY name");
                $st->execute([$workspaceId]);return $st->fetchAll();
            },$workspaceId);
        }catch(Throwable $e){}
        return ['companies'=>count($result['companies']??[])];
    }
}
