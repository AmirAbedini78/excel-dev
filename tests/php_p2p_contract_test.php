<?php
require_once dirname(__DIR__).'/app/Core/PurchaseMatchingDomain.php';

function ok(bool $cond,string $name): void {
    if(!$cond){fwrite(STDERR,"P2P_CONTRACT_FAIL=$name\n");exit(1);}
}
function fail(callable $fn,string $name): void {
    try{$fn();fwrite(STDERR,"P2P_CONTRACT_FAIL=$name\n");exit(1);}catch(RuntimeException $e){}
}
ok(PurchaseMatchingDomain::scaled('12.3400',4,100000)===123400,'qty_exact');
ok(PurchaseMatchingDomain::scaled('0.0001',4,100000)===1,'min_qty');
ok(PurchaseMatchingDomain::scaled('10000000000.00',2,10000000000)===1000000000000,'price_cap');
ok(PurchaseMatchingDomain::fixed(123400,4)==='12.3400','qty_format');
ok(PurchaseMatchingDomain::fixed(123456789,2)==='1234567.89','money_format');
ok(PurchaseMatchingDomain::cents(15000,12345)===18518,'half_up_currency');
ok(PurchaseMatchingDomain::cents(1,15000)===2,'half_up_micro_qty');
ok(PurchaseMatchingDomain::cents(10000,15000)===15000,'full_unit');
fail(static fn()=>PurchaseMatchingDomain::scaled('1e3',4,100000),'scientific_not_allowed');
fail(static fn()=>PurchaseMatchingDomain::scaled('-1',4,100000),'negative_not_allowed');
fail(static fn()=>PurchaseMatchingDomain::scaled('1.00001',4,100000),'too_many_decimals');
fail(static fn()=>PurchaseMatchingDomain::scaled('1.001',2,10000000000),'fractional_price');
fail(static fn()=>PurchaseMatchingDomain::scaled('100001.0',4,100000),'quantity_too_large');
fail(static fn()=>PurchaseMatchingDomain::scaled([],4,100000),'array_injection');
fail(static fn()=>PurchaseMatchingDomain::cents(0,100),'zero_quantity');
fail(static fn()=>PurchaseMatchingDomain::cents(1000,-5),'negative_price');
$domain=file_get_contents(dirname(__DIR__).'/app/Core/PurchaseMatchingDomain.php');
ok(str_contains($domain,'FOR UPDATE'),'order_lock');
ok(str_contains($domain,"r.status='posted'"),'only_posted_receipts');
ok(str_contains($domain,"b.status='matched'"),'only_matched_claims_consume_balance');
ok(str_contains($domain,"'draft','not_sent'"),'no_auto_post');
ok(!str_contains($domain,'INSERT INTO acc_vouchers'),'no_auto_voucher');
ok(!str_contains($domain,'INSERT INTO acc_stock_movements'),'no_second_stock_post');
ok(!str_contains($domain,'INSERT INTO acc_checks'),'no_auto_pay');
echo "P2P_CONTRACT=PASS\n";
