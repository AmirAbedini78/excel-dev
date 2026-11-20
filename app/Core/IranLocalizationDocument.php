<?php
/**
 * ERPSMART RC1 T4 — RTL / print document helpers for invoices & stock docs.
 *
 * Numbers and codes stay LTR inside RTL documents (unicode-bidi / dir=ltr).
 * Legal integrations (tax/Sayad) remain OFF; identifiers validated only when
 * a verified format scope exists — otherwise left as opaque display strings.
 */
final class IranLocalizationDocument
{
    public const VERSION = 'rc1-t4-doc-1';

    public static function wrapLtr(string $value): string
    {
        $safe = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span class="iran-ltr" dir="ltr">' . $safe . '</span>';
    }

    public static function wrapRtl(string $value): string
    {
        $safe = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span class="iran-rtl" dir="rtl">' . $safe . '</span>';
    }

    /**
     * Format a numeric/code cell for RTL tables: LTR embedding + tabular nums.
     */
    public static function numericCell(string $value, bool $persianDigits = false): string
    {
        $v = $persianDigits ? IranLocalizationMoney::faDigits($value) : $value;
        $safe = htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<td class="iran-num" dir="ltr"><bdi>' . $safe . '</bdi></td>';
    }

    public static function textCell(string $value): string
    {
        $safe = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<td class="iran-text" dir="rtl">' . $safe . '</td>';
    }

    public static function printCss(): string
    {
        return <<<'CSS'
.iran-doc{direction:rtl;font-family:Tahoma,"IranSans",sans-serif;color:#111}
.iran-doc table{width:100%;border-collapse:collapse}
.iran-doc th,.iran-doc td{border:1px solid #333;padding:6px 8px;vertical-align:top}
.iran-doc th{background:#f3f3f3}
.iran-doc .iran-num,.iran-doc .iran-ltr{direction:ltr;unicode-bidi:isolate;font-variant-numeric:tabular-nums;text-align:left}
.iran-doc .iran-text,.iran-doc .iran-rtl{direction:rtl;unicode-bidi:isolate;text-align:right}
.iran-doc .iran-meta{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}
.iran-doc .iran-meta div{min-width:160px}
.iran-doc .iran-footer{margin-top:16px;font-size:12px;color:#444}
@media print{
  .iran-doc{font-size:11pt}
  .no-print{display:none!important}
  .iran-doc table{page-break-inside:auto}
  .iran-doc tr{page-break-inside:avoid;page-break-after:auto}
}
CSS;
    }

    /**
     * Build printable invoice/stock document HTML (data-driven; no live tax claims).
     *
     * Expected $doc keys:
     *  title, company_name, document_no, document_date_jalali, party_name,
     *  display_currency, lines[{code,title,qty,unit_price_display,amount_display}],
     *  total_labeled, notes?, identity? (company identity display only)
     */
    public static function renderPrintableDocument(array $doc): string
    {
        $title = (string)($doc['title'] ?? 'سند');
        $company = (string)($doc['company_name'] ?? '');
        $docNo = (string)($doc['document_no'] ?? '');
        $dateJ = (string)($doc['document_date_jalali'] ?? '');
        $party = (string)($doc['party_name'] ?? '');
        $display = (string)($doc['display_currency'] ?? IranLocalizationMoney::DISPLAY_IRR);
        $total = (string)($doc['total_labeled'] ?? '');
        $notes = (string)($doc['notes'] ?? '');
        $identity = (string)($doc['identity'] ?? '');
        $prefix = (string)($doc['number_prefix'] ?? '');
        $lines = $doc['lines'] ?? [];
        if (!is_array($lines)) {
            $lines = [];
        }

        $css = self::printCss();
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
        $html .= '<title>' . $h($title) . '</title><style>' . $css . '</style></head><body class="iran-doc">';
        $html .= '<h1>' . $h($title) . '</h1>';
        $html .= '<div class="iran-meta">';
        $html .= '<div><strong>شرکت:</strong> ' . $h($company) . '</div>';
        if ($identity !== '') {
            $html .= '<div><strong>شناسه نمایشی:</strong> ' . self::wrapLtr($identity) . '</div>';
        }
        $html .= '<div><strong>شماره:</strong> ' . self::wrapLtr(($prefix !== '' ? $prefix : '') . $docNo) . '</div>';
        $html .= '<div><strong>تاریخ:</strong> ' . self::wrapLtr($dateJ) . '</div>';
        $html .= '<div><strong>طرف حساب:</strong> ' . $h($party) . '</div>';
        $html .= '<div><strong>واحد نمایش:</strong> ' . $h(IranLocalizationMoney::labelFa($display)) . ' <span class="muted">(' . $h(IranLocalizationMoney::labelCode($display)) . ')</span></div>';
        $html .= '</div>';

        $html .= '<table><thead><tr>';
        $html .= '<th>کد</th><th>شرح</th><th>مقدار</th><th>فی</th><th>مبلغ</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $html .= '<tr>';
            $html .= self::numericCell((string)($line['code'] ?? ''));
            $html .= self::textCell((string)($line['title'] ?? ''));
            $html .= self::numericCell((string)($line['qty'] ?? ''));
            $html .= self::numericCell((string)($line['unit_price_display'] ?? ''));
            $html .= self::numericCell((string)($line['amount_display'] ?? ''));
            $html .= '</tr>';
        }
        if (!$lines) {
            $html .= '<tr><td colspan="5" class="iran-text">ردیفی ثبت نشده است.</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<p><strong>جمع:</strong> ' . self::wrapLtr($total) . '</p>';
        if ($notes !== '') {
            $html .= '<p class="iran-footer">' . $h($notes) . '</p>';
        }
        $html .= '<p class="iran-footer">واحد دفتر کل: ریال (IRR). تبدیل تومان فقط نمایشی است. ارسال قانونی/مودیان/صیاد در RC1 غیرفعال است.</p>';
        $html .= '</body></html>';
        return $html;
    }

    /**
     * Optional document number prefix for company identity display (not a statutory claim).
     */
    public static function composeDocumentNumber(string $prefix, string $sequence): string
    {
        $prefix = trim($prefix);
        $sequence = trim($sequence);
        if ($sequence === '') {
            throw new RuntimeException('شماره سند الزامی است.');
        }
        return $prefix === '' ? $sequence : rtrim($prefix, '-/') . '-' . $sequence;
    }

    /**
     * Accessibility smoke attributes for RTL document root.
     */
    public static function accessibilityRootAttrs(): array
    {
        return [
            'lang' => 'fa',
            'dir' => 'rtl',
            'data-iran-localization' => self::VERSION,
        ];
    }
}
