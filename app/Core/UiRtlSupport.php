<?php
/**
 * Shared Persian/RTL presentation helpers for RC1 cockpits (T6).
 * Display-only; never authorizes mutations.
 */
final class UiRtlSupport
{
    public const VERSION = 'rc1-t6-ui-rtl';

    private static function esc($value): string
    {
        if (function_exists('h')) {
            return h((string)$value);
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function moneyIrr($amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        if (!is_numeric($amount)) {
            return (string)$amount;
        }
        return number_format((float)$amount, 0) . ' ریال';
    }

    public static function qty($qty, int $decimals = 4): string
    {
        if ($qty === null || $qty === '') {
            return '—';
        }
        if (!is_numeric($qty)) {
            return (string)$qty;
        }
        $n = (float)$qty;
        $formatted = rtrim(rtrim(number_format($n, $decimals, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    public static function faDate(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') {
            return '—';
        }
        if (class_exists('AccountingRepository')) {
            return (string)AccountingRepository::faDate($ymd);
        }
        return $ymd;
    }

    public static function badge(string $tone, string $label): string
    {
        $tone = preg_replace('/[^a-z0-9_-]/i', '', $tone) ?: 'muted';
        return '<span class="ui-badge ui-badge-' . self::esc($tone) . '">' . self::esc($label) . '</span>';
    }

    public static function emptyState(string $title, string $detail, array $links = []): string
    {
        $html = '<section class="card ui-empty" role="status"><h2>' . self::esc($title) . '</h2><p class="muted">' . self::esc($detail) . '</p>';
        if ($links) {
            $html .= '<div class="row-actions">';
            foreach ($links as $href => $label) {
                $html .= '<a class="btn tiny" href="' . self::esc($href) . '">' . self::esc($label) . '</a>';
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    public static function blockedState(string $title, string $reason, string $owner = ''): string
    {
        $extra = $owner !== '' ? ' مالک بعدی: ' . $owner : '';
        return '<section class="card ui-blocked alert warn" role="status"><h2>' . self::esc($title) . '</h2><p>' . self::esc($reason . $extra) . '</p></section>';
    }
}
