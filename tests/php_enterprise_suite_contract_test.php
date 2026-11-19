<?php
/** PHP-only behavioral contracts; no MySQL credentials or runtime services required. */
require_once __DIR__.'/../app/Core/EnterpriseCatalog.php';
require_once __DIR__.'/../app/Core/EnterpriseDomain.php';
function assertTrueE($check,$label){if(!$check)throw new RuntimeException('FAIL '.$label);}
function rejectedE(callable $f,$label){try{$f();}catch(RuntimeException $e){return;}throw new RuntimeException('FAIL expected rejection: '.$label);}
$groups=EnterpriseCatalog::groups();$defs=EnterpriseCatalog::records();
assertTrueE(count($groups)===8,'eight enterprise workspaces');
assertTrueE(count($defs)===39,'39 bounded business record models');
$tables=[];$refs=[];
foreach($defs as $key=>$spec){
    assertTrueE($spec['group']&&isset($groups[$spec['group']]),'valid business group');
    assertTrueE(preg_match('/^erp_ext_[a-z_]+$/D',$spec['table'])===1,'allowlisted table');
    assertTrueE(!isset($tables[$spec['table']]),'distinct real tables');
    $tables[$spec['table']]=true;
    foreach($spec['fields'] as $field=>$f){
        assertTrueE(preg_match('/^[a-z][a-z_]+$/D',$field)===1,'safe SQL identifier');
        assertTrueE(in_array($f['type'],['ref','date','money','qty','percent','int','memo','select','text'],true),'typed field');
        if($f['type']==='ref')$refs[]=$f['ref'];
    }
}
foreach($refs as $ref)assertTrueE(isset($defs[$ref])||in_array($ref,['party','item','warehouse','project','employee','asset','ticket','production_plan','bom'],true),'reference target');
assertTrueE(EnterpriseDomain::nextStatuses('draft')===['active','submitted','cancelled'],'draft transitions');
assertTrueE(EnterpriseDomain::nextStatuses('submitted')===['approved','rejected'],'review transitions');
assertTrueE(EnterpriseDomain::nextStatuses('closed')===[],'terminal immutable');
assertTrueE(!in_array('active',EnterpriseDomain::nextStatusesFor('vendor_offers','draft'),true),'purchases must be reviewed');
assertTrueE(in_array('active',EnterpriseDomain::nextStatusesFor('price_lists','draft'),true),'price master may activate directly');
$price=EnterpriseDomain::prepare('price_lists',1,1,['code'=>'PRICE-10','title'=>'لیست عمومی','currency'=>'IRR']);
assertTrueE($price['code']==='PRICE-10'&&$price['currency']==='IRR','price list validation');
rejectedE(fn()=>EnterpriseDomain::prepare('price_lists',1,1,['code'=>'q','title'=>'test','currency'=>'IRR']),'short code');
rejectedE(fn()=>EnterpriseDomain::prepare('price_lists',1,1,['code'=>'PRICE-12','title'=>'test','currency'=>'BTC']),'unknown currency');
rejectedE(fn()=>EnterpriseDomain::prepare('price_lists',1,1,['code'=>'PRICE-12','title'=>'test','currency'=>'IRR','valid_from'=>'2026-12-01','valid_to'=>'2026-01-01']),'inverted date range');
rejectedE(fn()=>EnterpriseDomain::prepare('price_lists',1,1,['code'=>'PRICE-12','title'=>'test','currency'=>'IRR','valid_from'=>'2026-02-30']),'invalid date');
rejectedE(fn()=>EnterpriseDomain::prepare('positions',1,1,['code'=>'POSITION-1','title'=>'test','openings'=>'1.25']),'fractional staff count');
$pos=EnterpriseDomain::prepare('positions',1,1,['code'=>'POSITION-2','title'=>'Engineer','openings'=>'2','salary_budget'=>'1000000']);
assertTrueE($pos['salary_budget']==='1000000','salary input only - no payroll posting');
$fake=EnterpriseDomain::prepare('surveys',1,1,['code'=>'SURVEY-1','title'=>'Test','questions'=>'How was the product?']);
assertTrueE($fake['questions']==='How was the product?','survey text');
rejectedE(fn()=>EnterpriseDomain::prepare('esg_metrics',1,1,['code'=>'ESG-1','title'=>'Score','period_on'=>'2026-11-20','category'=>'energy','metric_name'=>'Power','metric_value'=>'20','unit_name'=>str_repeat('x',191)]),'input length');
// DDL grammar exercise without DB: a recording PDO double sees every generated CREATE TABLE.
class RecordingPDOSuite extends PDO {
    public array $sql=[];
    public function __construct(){}
    public function exec(string $statement): int|false {$this->sql[]=$statement;return 0;}
}
$db=new RecordingPDOSuite();EnterpriseDomain::migrate($db);
assertTrueE(count($db->sql)===41,'39 models + event + canonical link');
foreach($db->sql as $statement)assertTrueE(str_starts_with($statement,'CREATE TABLE IF NOT EXISTS'),'only additive DDL');
echo "ENTERPRISE_PHP=PASS; groups=8; models=39; ddl=41; no_mysql=true\n";
