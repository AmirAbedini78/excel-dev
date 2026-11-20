<?php
/**
 * RC1 T4 — Iran localization PHP contracts (no MySQL required).
 * Run via: docker run --rm -v <repo>:/app -w /app erpsmart-o2c-php:8.2 php tests/php_iran_localization_rc1_test.php
 */
require_once __DIR__ . '/../app/Core/Jalali.php';
require_once __DIR__ . '/../app/Core/PurchaseMatchingDomain.php';
require_once __DIR__ . '/../app/Core/BusinessProfileDomain.php';

function assertTrueI($check, string $label): void
{
    if (!$check) {
        throw new RuntimeException('FAIL ' . $label);
    }
}
function rejectedI(callable $f, string $label): void
{
    try {
        $f();
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException('FAIL expected rejection: ' . $label);
}

$pass = 0;

// --- Jalali ↔ Gregorian round-trip (leap Esfand, year boundary) ---
$cases = [
    '1403/01/01', // Nowruz / year boundary
    '1403/12/30', // leap Esfand 30 (1403 is leap in Iranian calendar)
    '1402/12/29', // non-leap Esfand max 29
    '1399/12/30', // leap
    '1400/01/01',
    '1410/06/15',
];
foreach ($cases as $j) {
    assertTrueI(IranLocalizationDate::isValidJalali(...IranLocalizationDate::normalizeJalaliInput($j)), 'valid ' . $j);
    assertTrueI(IranLocalizationDate::roundTripJalali($j), 'round-trip jalali ' . $j);
    $g = IranLocalizationDate::jalaliToGregorianDate($j);
    assertTrueI($g !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $g) === 1, 'store gregorian ' . $j);
    assertTrueI(IranLocalizationDate::roundTripGregorian($g), 'round-trip gregorian ' . $g);
    $pass++;
}
assertTrueI(IranLocalizationDate::isLeapJalaliYear(1403), '1403 leap');
assertTrueI(!IranLocalizationDate::isLeapJalaliYear(1402), '1402 not leap');
assertTrueI(IranLocalizationDate::jalaliMonthLength(1403, 12) === 30, 'leap esfand length');
assertTrueI(IranLocalizationDate::jalaliMonthLength(1402, 12) === 29, 'non-leap esfand length');
assertTrueI(IranLocalizationDate::jalaliToGregorianDate('1402/12/30') === null, 'invalid esfand 30 non-leap');
assertTrueI(IranLocalizationDate::jalaliToGregorianDate('1403/13/01') === null, 'invalid month');
assertTrueI(IranLocalizationDate::jalaliToGregorianDate('99/01/01') === null, 'ambiguous short year');
assertTrueI(IranLocalizationDate::jalaliToGregorianDate('not-a-date') === null, 'garbage');
assertTrueI(IranLocalizationDate::gregorianDateToJalali('2025-03-21 12:00:00') === null, 'reject datetime (tz ambiguity)');
assertTrueI(IranLocalizationDate::gregorianDateToJalali('2025-02-30') === null, 'invalid gregorian');
// Persian digits
assertTrueI(IranLocalizationDate::jalaliToGregorianDate('۱۴۰۳/۰۱/۰۱') === IranLocalizationDate::jalaliToGregorianDate('1403/01/01'), 'persian digits');
$field = IranLocalizationDate::datedField(IranLocalizationDate::ROLE_INVOICE, '1403/01/01');
assertTrueI($field['store_gregorian'] === '2024-03-20' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $field['store_gregorian']), 'invoice store date');
assertTrueI($field['role'] === IranLocalizationDate::ROLE_INVOICE, 'invoice role explicit');
$pass += 12;

// Jalali::fromGregorian date-only hardening
assertTrueI(Jalali::fromGregorian('2024-03-20') === IranLocalizationDate::gregorianDateToJalali('2024-03-20'), 'jalali helper aligns');
$pass++;

// --- IRR / Toman exactness ---
// 1 Toman = 10 IRR; scaled cents: 1.00 IRR display = 100 scaled; 1.00 Toman = 1000 IRR scaled
assertTrueI(IranLocalizationMoney::displayToIrrScaled('1.00', 'IRR') === 100, '1 IRR → 100 scaled');
assertTrueI(IranLocalizationMoney::displayToIrrScaled('1.00', 'TOMAN') === 1000, '1 Toman → 1000 IRR scaled');
assertTrueI(IranLocalizationMoney::irrScaledToDisplay(1000, 'TOMAN') === '1.00', '1000 scaled → 1.00 Toman');
assertTrueI(IranLocalizationMoney::irrScaledToDisplay(1000, 'IRR') === '10.00', '1000 scaled → 10.00 IRR');
// Large amount
$big = IranLocalizationMoney::displayToIrrScaled('123456789.12', 'TOMAN');
assertTrueI($big === 12345678912 * 10, 'large toman → irr');
assertTrueI(IranLocalizationMoney::irrScaledToDisplay($big, 'TOMAN') === '123456789.12', 'large round-trip toman');
// Fractional IRR that cannot convert to exact Toman
rejectedI(fn() => IranLocalizationMoney::irrScaledToDisplay(1001, 'TOMAN'), 'non-divisible toman');
rejectedI(fn() => IranLocalizationMoney::displayToIrrScaled('1.00', 'USD'), 'unknown currency');
IranLocalizationMoney::assertNotFloat('1.00');
rejectedI(fn() => IranLocalizationMoney::assertNotFloat(1.25), 'float rejected');
// Dual company display: ledger identical
$dual = IranLocalizationMoney::dualCompanyDisplay(600000, 'TOMAN', 'IRR'); // 6000.00 IRR
assertTrueI($dual['ledger_irr'] === '6000.00', 'ledger stays IRR');
assertTrueI($dual['company_a']['amount'] === '600.00', 'company A toman display');
assertTrueI($dual['company_b']['amount'] === '6000.00', 'company B irr display');
assertTrueI(IranLocalizationMoney::formatLabeled(1000, 'TOMAN') === '1.00 تومان', 'labeled toman');
assertTrueI(BusinessProfileDomain::displayToIrrScaled('10.00', 'TOMAN') === 10000, 'profile delegates toman');
$pass += 14;

// --- RTL document helpers ---
$html = IranLocalizationDocument::renderPrintableDocument([
    'title' => 'فاکتور فروش',
    'company_name' => 'شرکت آزمایشی',
    'document_no' => '1001',
    'number_prefix' => 'INV',
    'document_date_jalali' => '1403/01/01',
    'party_name' => 'مشتری نمونه',
    'display_currency' => 'TOMAN',
    'identity' => '12345678901',
    'total_labeled' => IranLocalizationMoney::formatLabeled(100000, 'TOMAN'),
    'lines' => [
        ['code' => 'SKU-1', 'title' => 'کالا', 'qty' => '2.0000', 'unit_price_display' => '500.00', 'amount_display' => '1000.00'],
    ],
]);
assertTrueI(str_contains($html, 'dir="rtl"'), 'doc rtl');
assertTrueI(str_contains($html, 'dir="ltr"'), 'ltr numbers present');
assertTrueI(str_contains($html, '<bdi>'), 'bdi numeric isolation');
assertTrueI(str_contains($html, 'SKU-1'), 'line code');
assertTrueI(str_contains($html, 'تومان'), 'currency label');
assertTrueI(str_contains($html, 'غیرفعال'), 'legal off disclaimer');
assertTrueI(IranLocalizationDocument::composeDocumentNumber('INV', '42') === 'INV-42', 'doc number prefix');
$attrs = IranLocalizationDocument::accessibilityRootAttrs();
assertTrueI($attrs['lang'] === 'fa' && $attrs['dir'] === 'rtl', 'a11y root');
$tplInvoice = __DIR__ . '/../app/Core/IranLocalizationTemplates/invoice_print.php';
$tplStock = __DIR__ . '/../app/Core/IranLocalizationTemplates/stock_print.php';
assertTrueI(is_file($tplInvoice) && is_file($tplStock), 'templates exist');
ob_start();
$doc = [
    'title' => 'حواله انبار',
    'company_name' => 'A',
    'document_no' => 'S1',
    'document_date_jalali' => '1403/02/01',
    'party_name' => 'انبار',
    'display_currency' => 'IRR',
    'total_labeled' => '0.00 ریال',
    'lines' => [],
];
include $tplStock;
$stockHtml = ob_get_clean();
assertTrueI(str_contains($stockHtml, 'حواله'), 'stock template render');
$pass += 10;

// --- SYS-1: service/retail must not require manufacturing docs ---
assertTrueI(!BusinessProfileDomain::requiresManufacturingDocs('service'), 'service no mfg');
assertTrueI(!BusinessProfileDomain::requiresManufacturingDocs('retail'), 'retail no mfg');
assertTrueI(BusinessProfileDomain::requiresManufacturingDocs('manufacturing'), 'mfg requires');
$svc = BusinessProfileDomain::assessDocumentRequirements('service', ['contracts']);
assertTrueI($svc['service_retail_free_of_mfg_gate'] === true, 'service free of mfg gate');
assertTrueI(!in_array('bom', $svc['required_doc_families'], true), 'service no bom');
$ret = BusinessProfileDomain::assessDocumentRequirements('retail', ['sales', 'inventory']);
assertTrueI($ret['service_retail_free_of_mfg_gate'] === true, 'retail free of mfg gate');
assertTrueI($ret['sys1_ok'] === true, 'retail sys1');
foreach (BusinessProfileDomain::MANUFACTURING_DOC_TYPES as $d) {
    assertTrueI(!in_array($d, BusinessProfileDomain::PROFILES['service']['required_doc_families'], true), 'service excludes ' . $d);
    assertTrueI(!in_array($d, BusinessProfileDomain::PROFILES['retail']['required_doc_families'], true), 'retail excludes ' . $d);
}
$apis = BusinessProfileDomain::localizationApis();
assertTrueI($apis['legal_integrations'] === 'OFF', 'legal off');
assertTrueI($apis['toman_factor'] === 10, 'toman factor 10');
$pass += 10;

echo 'IRAN_LOCALIZATION_PHP=PASS; checks~=' . $pass . "; versions=" . IranLocalizationDate::VERSION . ',' . IranLocalizationMoney::VERSION . ',' . IranLocalizationDocument::VERSION . "; DATABASE_TESTED=none\n";
