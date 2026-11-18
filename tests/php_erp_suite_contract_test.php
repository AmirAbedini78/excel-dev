<?php
require_once dirname(__DIR__).'/app/Core/ErpSuiteDomain.php';
$entities=ErpSuiteDomain::entities();
$expected=['employees','leave','attendance','projects','tasks','timesheets','registers','carts','cart_lines','tickets','assets','expenses'];
foreach($expected as $name){if(!isset($entities[$name]))throw new RuntimeException('missing entity: '.$name);}
if(($entities['projects']['table']??'')!=='acc_projects'||empty($entities['projects']['canonical']))throw new RuntimeException('canonical projects not reused');
if(empty($entities['leave']['approval'])||empty($entities['expenses']['approval']))throw new RuntimeException('approval boundary missing');
$method=new ReflectionMethod('ErpSuiteDomain','validateValue');
$method->setAccessible(true);
$ok=0;
$check=function(string $field,array $meta,$v,$expected) use($method,&$ok){$r=$method->invoke(null,$field,$meta,$v,1,1);if($r!==$expected)throw new RuntimeException('parse mismatch '.$field);$ok++;};
$check('due_date',['type'=>'date','required'=>true,'label'=>'date'], '2026-11-18','2026-11-18');
$check('percent',['type'=>'percent','required'=>false,'label'=>'percent'], '12.50',12.5);
$check('quantity',['type'=>'quantity','required'=>true,'label'=>'quantity'], '1.25',1.25);
$bad=[
    ['field'=>'date','meta'=>['type'=>'date','required'=>true,'label'=>'date'],'value'=>'2026-02-30'],
    ['field'=>'status','meta'=>['type'=>'choice','required'=>true,'label'=>'status','choices'=>['open'=>'باز']],'value'=>'approved'],
    ['field'=>'x','meta'=>['type'=>'percent','required'=>true,'label'=>'percent'],'value'=>101],
    ['field'=>'x','meta'=>['type'=>'quantity','required'=>true,'label'=>'quantity'],'value'=>-1],
    ['field'=>'x','meta'=>['type'=>'code','required'=>true,'label'=>'code'],'value'=>'A B'],
    ['field'=>'x','meta'=>['type'=>'hours','required'=>true,'label'=>'hours'],'value'=>25],
];
foreach($bad as $test){$thrown=false;try{$method->invoke(null,$test['field'],$test['meta'],$test['value'],1,1);}catch(RuntimeException $e){$thrown=true;}if(!$thrown)throw new RuntimeException('invalid input accepted');$ok++;}
$pos=ErpSuiteDomain::posLineTotals(2,1000,10,9);
if($pos!==['base'=>2000.0,'discount'=>200.0,'tax'=>162.0,'total'=>1962.0])throw new RuntimeException('POS tax/discount calculation incorrect');$ok++;
foreach([[-1,100,0,0],[1,100,101,0],[1,100,0,110]] as $invalid){
    $thrown=false;try{ErpSuiteDomain::posLineTotals(...$invalid);}catch(RuntimeException $e){$thrown=true;}
    if(!$thrown)throw new RuntimeException('POS invalid line accepted');$ok++;
}
class ErpSuiteCapturePDO extends PDO {
    public array $statements=[];
    public function __construct() {}
    public function exec(string $statement): int|false {
        $this->statements[]=$statement;
        return 0;
    }
}
$capture=new ErpSuiteCapturePDO();
ErpSuiteDomain::migrate($capture);
if(count($capture->statements)!==11)throw new RuntimeException('Expected 11 new tables');
foreach($capture->statements as $sql){
    if(!str_contains($sql,'CREATE TABLE IF NOT EXISTS')||!str_contains($sql,'workspace_id INT NOT NULL')||!str_contains($sql,'company_id INT NOT NULL'))
        throw new RuntimeException('Unsafe migration table');
}
if(!str_contains(implode('',$capture->statements),'linked_sales_doc_id BIGINT NULL'))throw new RuntimeException('POS idempotency link missing');
$ok++;
echo 'ERP_SUITE_CONTRACT=PASS; cases='.$ok.'; entities='.count($entities)."\n";
