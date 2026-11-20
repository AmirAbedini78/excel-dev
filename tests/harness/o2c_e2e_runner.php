<?php
/**
 * CLI scenarios for real OrderToCashDomain / SalesDomain methods.
 * Usage: php o2c_e2e_runner.php <scenario> [json-args]
 */
declare(strict_types=1);
require_once __DIR__.'/o2c_e2e_bootstrap.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $args = json_decode($argv[2], true);
    if (!is_array($args)) {
        fwrite(STDERR, "invalid_json_args\n");
        exit(4);
    }
}

try {
    switch ($scenario) {
        case 'reset':
            o2c_e2e_reset_schema();
            // Re-run migrate to prove idempotent.
            OrderToCashDomain::migrate(pdo());
            SalesDomain::migrate(pdo());
            AccountingPostingDomain::migrate(pdo());
            o2c_json_out(['ok' => true, 'scenario' => 'reset', 'version' => OrderToCashDomain::VERSION, 'ar' => AccountingPostingDomain::VERSION]);
            break;

        case 'confirm_order':
            o2c_e2e_connect_reuse();
            $docId = (int)($args['sales_doc_id'] ?? 0);
            $uid = (int)($args['user_id'] ?? 2);
            Auth::setUser(['id' => $uid, 'is_platform_admin' => 1]);
            OrderToCashDomain::confirmOrder(10, 1, $uid, $docId);
            o2c_json_out(['ok' => true, 'sales_doc_id' => $docId, 'status' => 'approved']);
            break;

        case 'seed_order':
            o2c_e2e_connect_reuse();
            $id = o2c_e2e_create_draft_order((int)($args['created_by'] ?? 1), (string)($args['qty'] ?? '10.0000'), (string)($args['price'] ?? '100.00'));
            o2c_json_out(['ok' => true, 'sales_doc_id' => $id]);
            break;

        case 'reserve':
            o2c_e2e_connect_reuse();
            $uid = (int)($args['user_id'] ?? 2);
            Auth::setUser(['id' => $uid, 'is_platform_admin' => 1]);
            $out = SalesDomain::reserveStock(10, 1, $uid, $args);
            o2c_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'deliver':
            o2c_e2e_connect_reuse();
            $uid = (int)($args['user_id'] ?? 2);
            Auth::setUser(['id' => $uid, 'is_platform_admin' => 1]);
            $out = SalesDomain::deliverStock(10, 1, $uid, $args);
            o2c_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'invoice':
            o2c_e2e_connect_reuse();
            $uid = (int)($args['user_id'] ?? 2);
            Auth::setUser(['id' => $uid, 'is_platform_admin' => 1]);
            $lines = (array)($args['lines'] ?? []);
            $out = OrderToCashDomain::createInvoiceFromDelivery(
                10,
                1,
                $uid,
                (int)$args['sales_doc_id'],
                (int)$args['delivery_id'],
                $lines,
                (string)($args['idempotency_key'] ?? '')
            );
            o2c_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'confirm_billing_should_fail':
            o2c_e2e_connect_reuse();
            Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
            try {
                OrderToCashDomain::confirmOrder(10, 1, 2, (int)$args['sales_doc_id']);
                o2c_json_out(['ok' => false, 'error' => 'expected_failure']);
                exit(5);
            } catch (Throwable $e) {
                o2c_json_out(['ok' => true, 'blocked' => true, 'message' => $e->getMessage()]);
            }
            break;

        case 'reserve_billing_should_fail':
            o2c_e2e_connect_reuse();
            Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
            try {
                SalesDomain::reserveStock(10, 1, 2, $args);
                o2c_json_out(['ok' => false, 'error' => 'expected_failure']);
                exit(5);
            } catch (Throwable $e) {
                o2c_json_out(['ok' => true, 'blocked' => true, 'message' => $e->getMessage()]);
            }
            break;

        case 'query':
            o2c_e2e_connect_reuse();
            $sql = (string)($args['sql'] ?? '');
            if ($sql === '' || !preg_match('/^\s*SELECT\b/i', $sql)) {
                throw new RuntimeException('only_select_allowed');
            }
            $st = pdo()->query($sql);
            o2c_json_out(['ok' => true, 'rows' => $st->fetchAll()]);
            break;

        case 'line_id':
            o2c_e2e_connect_reuse();
            $st = pdo()->prepare('SELECT id FROM acc_sales_lines WHERE sales_doc_id=? ORDER BY line_no LIMIT 1');
            $st->execute([(int)$args['sales_doc_id']]);
            o2c_json_out(['ok' => true, 'sales_line_id' => (int)$st->fetchColumn()]);
            break;

        case 'ar_set_map':
            o2c_e2e_connect_reuse();
            Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
            AccountingPostingDomain::setCompanyMap(10, 1, 2, [
                'receivable_account_id' => 1,
                'revenue_account_id' => 2,
                'tax_account_id' => 3,
            ]);
            o2c_json_out(['ok' => true, 'readiness' => AccountingPostingDomain::assessReadiness(10, 1)]);
            break;

        case 'ar_voucher_balance':
            o2c_e2e_connect_reuse();
            $vid = (int)$args['voucher_id'];
            $st = pdo()->prepare('SELECT total_debit,total_credit,status,source_type FROM acc_vouchers WHERE id=? AND workspace_id=10');
            $st->execute([$vid]);
            $v = $st->fetch();
            $ls = pdo()->prepare('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c, COUNT(*) n FROM acc_voucher_lines WHERE voucher_id=? AND workspace_id=10');
            $ls->execute([$vid]);
            $l = $ls->fetch();
            $pc = pdo()->prepare('SELECT COUNT(*) FROM erp_ar_postings WHERE workspace_id=10 AND company_id=1 AND voucher_id=?');
            $pc->execute([$vid]);
            o2c_json_out(['ok' => true, 'voucher' => $v, 'lines' => $l, 'postings' => (int)$pc->fetchColumn()]);
            break;

        case 'ar_post':
            o2c_e2e_connect_reuse();
            $uid = (int)($args['user_id'] ?? 2);
            Auth::setUser(['id' => $uid, 'is_platform_admin' => 1]);
            $out = AccountingPostingDomain::postCustomerInvoice(
                10,
                1,
                $uid,
                (int)$args['invoice_doc_id'],
                (string)($args['idempotency_key'] ?? '')
            );
            o2c_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ar_outstanding':
            o2c_e2e_connect_reuse();
            o2c_json_out(['ok' => true, 'result' => AccountingPostingDomain::customerOutstanding(10, 1, (int)($args['party_id'] ?? 1))]);
            break;

        case 'concurrent_reserve':
            // True overlap: two PDO connections + pcntl not required; use mysqli-style sequential
            // start of transactions with GET_LOCK held across workers via persistent children.
            o2c_e2e_connect_reuse();
            $doc1 = (int)$args['sales_doc_id_1'];
            $doc2 = (int)$args['sales_doc_id_2'];
            $line1 = (int)$args['sales_line_id_1'];
            $line2 = (int)$args['sales_line_id_2'];
            $qty = (string)($args['quantity'] ?? '70.0000');
            global $host, $port, $user, $pass, $dbName;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
            $results = [null, null];
            $errors = [null, null];
            $barrier = 0;
            $lock = new stdClass();
            $workers = [];
            foreach ([[0, $doc1, $line1], [1, $doc2, $line2]] as [$idx, $doc, $line]) {
                $workers[] = static function () use ($idx, $doc, $line, $qty, $dsn, $user, $pass, &$results, &$errors, &$barrier, $lock) {
                    // Each worker uses its own PDO; bind into global DB carefully.
                    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                    // Spin-wait barrier so both enter reserve nearly together.
                    $barrier++;
                    $t0 = microtime(true);
                    while ($barrier < 2 && (microtime(true) - $t0) < 5.0) {
                        usleep(1000);
                    }
                    try {
                        // Temporarily swap global PDO for this worker.
                        $ref = new ReflectionClass('DB');
                        $prop = $ref->getProperty('pdo');
                        $prop->setAccessible(true);
                        $prop->setValue(null, $pdo);
                        Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
                        $out = SalesDomain::reserveStock(10, 1, 2, [
                            'sales_doc_id' => $doc,
                            'warehouse_id' => 1,
                            'lines' => [['sales_line_id' => $line, 'quantity' => $qty]],
                        ]);
                        $results[$idx] = $out;
                    } catch (Throwable $e) {
                        $errors[$idx] = $e->getMessage();
                    }
                };
            }
            // PHP without pcntl threads: run via parallel curl-like processes using proc_open to self.
            // Fallback: spawn two CLI children of this runner.
            $php = PHP_BINARY;
            $script = __DIR__.'/o2c_e2e_runner.php';
            $procs = [];
            $pipes = [];
            foreach ([[$doc1, $line1], [$doc2, $line2]] as $i => [$doc, $line]) {
                $payload = json_encode([
                    'sales_doc_id' => $doc,
                    'warehouse_id' => 1,
                    'user_id' => 2,
                    'lines' => [['sales_line_id' => $line, 'quantity' => $qty]],
                ], JSON_UNESCAPED_UNICODE);
                $cmd = escapeshellarg($php).' '.escapeshellarg($script).' reserve '.escapeshellarg($payload);
                $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $env = array_merge($_ENV, [
                    'O2C_DB_HOST' => $host,
                    'O2C_DB_PORT' => (string)$port,
                    'O2C_DB_USER' => $user,
                    'O2C_DB_PASS' => $pass,
                    'O2C_DB_NAME' => $dbName,
                ]);
                $procs[$i] = proc_open($cmd, $descriptors, $pipes[$i], dirname($script, 2), $env);
            }
            // Tiny delay then both already started — read outputs.
            $outs = [];
            foreach ($procs as $i => $proc) {
                $outs[$i] = stream_get_contents($pipes[$i][1]);
                fclose($pipes[$i][0]);
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                proc_close($proc);
            }
            $parsed = [];
            foreach ($outs as $raw) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$raw))));
                $parsed[] = $lines ? json_decode($lines[count($lines) - 1], true) : ['ok' => false, 'error' => 'no_output'];
            }
            $oks = array_values(array_filter($parsed, static fn($r) => !empty($r['ok'])));
            $fails = array_values(array_filter($parsed, static fn($r) => empty($r['ok'])));
            o2c_json_out(['ok' => true, 'results' => $parsed, 'ok_count' => count($oks), 'fail_count' => count($fails)]);
            break;

        default:
            fwrite(STDERR, "unknown_scenario\n");
            exit(4);
    }
} catch (Throwable $e) {
    o2c_json_out(['ok' => false, 'error' => $e->getMessage(), 'class' => get_class($e)]);
    exit(1);
}

function o2c_e2e_connect_reuse(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
}
