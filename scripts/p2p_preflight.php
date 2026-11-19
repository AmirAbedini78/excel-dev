<?php
/** CLI-only, read-only schema and source validation after staging deploy. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
$configFile=$root.'/app/config.php';
if(!is_file($configFile)){fwrite(STDERR,"P2P_PREFLIGHT=NO_CONFIG\n");exit(2);}
$cfg=require $configFile;
require_once $root.'/app/Core/DB.php';
try{
    $db=DB::connect($cfg['db']);
    $sql="SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('erp_p2p_vendor_bills','erp_p2p_vendor_bill_lines','acc_purchase_docs','acc_purchase_lines','acc_inventory_receipts','acc_inventory_receipt_lines','acc_stock_movements')";
    $tables=$db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $need=['erp_p2p_vendor_bills','erp_p2p_vendor_bill_lines','acc_purchase_docs','acc_purchase_lines','acc_inventory_receipts','acc_inventory_receipt_lines','acc_stock_movements'];
    $missing=array_values(array_diff($need,$tables));
    if($missing){fwrite(STDERR,'P2P_PREFLIGHT=MISSING '.implode(',',$missing)."\n");exit(3);}
    $q=$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='erp_p2p_vendor_bills' AND INDEX_NAME='uq_p2p_supplier_invoice'");
    if((int)$q->fetchColumn()<4){fwrite(STDERR,"P2P_PREFLIGHT=INDEX_MISSING\n");exit(4);}
    echo "P2P_PREFLIGHT=PASS; TABLES=7; WRITES=0\n";
}catch(Throwable $e){fwrite(STDERR,"P2P_PREFLIGHT=ERROR; check DB permission/logs without exposing secrets\n");exit(5);}
