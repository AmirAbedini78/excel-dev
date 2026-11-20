<?php
/**
 * RC1 TR-1 full scenario in one PHP process (avoids N× Docker startup on Windows).
 * PO 10×1000 IRR → GR6 → inv 4+2 → AP 6000 → pay 2500+3500 → outstanding 0.
 */
declare(strict_types=1);

require_once __DIR__ . '/ap_e2e_bootstrap.php';

function rc1_tr1_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
}

try {
    ap_e2e_reset_schema();
    ApPostingDomain::migrate(pdo());

    $po = ap_seed_po(1, '10.0000', '1000.00');
    PurchaseMatchingDomain::approveOrder(10, 1, 2, $po);
    ap_post_receipt($po, '6.0000', 1);

    $st = pdo()->prepare('SELECT id FROM acc_purchase_lines WHERE workspace_id=10 AND purchase_doc_id=? ORDER BY id LIMIT 1');
    $st->execute([$po]);
    $line = (int)$st->fetchColumn();

    $b1 = PurchaseMatchingDomain::submit(10, 1, 1, $po, 'RC1-TR1-INV-4', '2026-11-20', [
        (string)$line => ['quantity' => '4.0000', 'unit_price' => '1000.00'],
    ]);
    $inv4 = (int)PurchaseMatchingDomain::approve(10, 1, 2, (int)$b1['id'])['id'];

    $b2 = PurchaseMatchingDomain::submit(10, 1, 1, $po, 'RC1-TR1-INV-2', '2026-11-20', [
        (string)$line => ['quantity' => '2.0000', 'unit_price' => '1000.00'],
    ]);
    $inv2 = (int)PurchaseMatchingDomain::approve(10, 1, 2, (int)$b2['id'])['id'];

    ApPostingDomain::setCompanyMap(10, 1, 2, [
        'payable_account_id' => 8,
        'purchase_account_id' => 9,
        'input_vat_account_id' => 10,
        'clearing_account_id' => 0,
        'ppv_account_id' => 0,
    ]);

    foreach ([[$inv4, str_repeat('r', 32)], [$inv2, str_repeat('s', 32)]] as [$inv, $key]) {
        ApPostingDomain::validateInvoice(10, 1, 3, $inv);
        ApPostingDomain::approveInvoice(10, 1, 1, $inv);
        ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv, $key);
    }

    $out6000 = ApPostingDomain::supplierOutstanding(10, 1, 1);
    if (($out6000['outstanding'] ?? '') !== '6000.00') {
        throw new RuntimeException('expected_ap_6000 got=' . json_encode($out6000));
    }

    $pay = function (string $amount, string $key): int {
        $draft = ApSettlementDomain::createDraftPayment(10, 1, 1, [
            'party_id' => 1,
            'cash_account_id' => 1,
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-11-20',
            'amount' => $amount,
            'idempotency_key' => $key,
        ]);
        $rid = (int)$draft['id'];
        ApSettlementDomain::approvePayment(10, 1, 2, $rid);
        ApSettlementDomain::postPayment(10, 1, 2, $rid);
        return $rid;
    };

    $pay1 = $pay('2500.00', str_repeat('t', 32));
    ApSettlementDomain::allocate(10, 1, 1, $pay1, $inv4, '2500.00', str_repeat('u', 32));
    $mid = ApPostingDomain::invoiceOutstanding(10, 1, $inv4);
    if (($mid['outstanding'] ?? '') !== '1500.00') {
        throw new RuntimeException('expected_inv4_1500 got=' . json_encode($mid));
    }

    $pay2 = $pay('3500.00', str_repeat('v', 32));
    ApSettlementDomain::allocate(10, 1, 1, $pay2, $inv4, '1500.00', str_repeat('w', 32));
    ApSettlementDomain::allocate(10, 1, 1, $pay2, $inv2, '2000.00', str_repeat('x', 32));

    $final = ApPostingDomain::supplierOutstanding(10, 1, 1);
    if (($final['outstanding'] ?? '') !== '0.00') {
        throw new RuntimeException('expected_outstanding_0 got=' . json_encode($final));
    }

    $stock = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=1 AND source_type='inventory_receipt'")->fetchColumn();
    $grV = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND source_type LIKE '%receipt%'")->fetchColumn();
    if ($stock !== 1) {
        throw new RuntimeException('expected_one_stock_movement got=' . $stock);
    }
    if ($grV !== 0) {
        throw new RuntimeException('expected_zero_receipt_gl got=' . $grV);
    }

    rc1_tr1_out([
        'ok' => true,
        'contract' => 'TR-1',
        'status' => 'PASS',
        'po_id' => $po,
        'invoices' => [$inv4, $inv2],
        'outstanding_after_ap' => $out6000['outstanding'],
        'outstanding_final' => $final['outstanding'],
        'stock_movements' => $stock,
        'receipt_gl_vouchers' => $grV,
        'tax_note' => 'No VAT invented; omitted tax documented as fixture out-of-scope.',
        'currency' => 'IRR',
    ]);
} catch (Throwable $e) {
    rc1_tr1_out(['ok' => false, 'contract' => 'TR-1', 'status' => 'FAIL', 'error' => $e->getMessage(), 'type' => get_class($e)]);
    exit(1);
}
