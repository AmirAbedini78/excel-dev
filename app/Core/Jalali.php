<?php
class Jalali
{
    private static array $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

    public static function monthName(int $m): string { return self::$months[$m-1] ?? ''; }
    public static function monthLength(int $y, int $m): int { return $m <= 6 ? 31 : ($m <= 11 ? 30 : (self::isLeap($y) ? 30 : 29)); }
    public static function isLeap(int $jy): bool
    {
        $breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
        $bl = count($breaks); $gy = $jy + 621; $leapJ = -14; $jp = $breaks[0];
        for ($i=1; $i<$bl; $i++) { $jm = $breaks[$i]; $jump = $jm - $jp; if ($jy < $jm) break; $leapJ += intdiv($jump,33)*8 + intdiv(($jump%33),4); $jp = $jm; }
        $n = $jy - $jp; $leapJ += intdiv($n,33)*8 + intdiv(($n%33)+3,4); if (($jump%33)==4 && $jump-$n==4) $leapJ++;
        $leapG = intdiv($gy,4) - intdiv((intdiv($gy,100)+1)*3,4) - 150;
        $march = 20 + $leapJ - $leapG;
        if ($jump-$n < 6) $n = $n - $jump + intdiv($jump+4,33)*33;
        $leap = (($n+1)%33 - 1) % 4;
        if ($leap == -1) $leap = 4;
        return $leap === 0;
    }

    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + intdiv($jy,33)*8 + intdiv((($jy%33)+3),4) + $jd;
        for ($i=1; $i<$jm; ++$i) $days += ($i < 7) ? 31 : 30;
        $gy = 400 * intdiv($days,146097); $days %= 146097;
        if ($days > 36524) { $gy += 100 * intdiv(--$days,36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * intdiv($days,1461); $days %= 1461;
        if ($days > 365) { $gy += intdiv($days-1,365); $days = ($days-1)%365; }
        $gd = $days + 1;
        $sal_a = [0,31, (($gy%4==0 && $gy%100!=0) || ($gy%400==0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
        for ($gm=1; $gm<=12 && $gd>$sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
        return [$gy, $gm, $gd];
    }

    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365*$gy) + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400) + $gd + $g_d_m[$gm-1];
        $jy = -1595 + 33 * intdiv($days,12053); $days %= 12053;
        $jy += 4 * intdiv($days,1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days-1,365); $days = ($days-1)%365; }
        if ($days < 186) { $jm = 1 + intdiv($days,31); $jd = 1 + ($days%31); }
        else { $jm = 7 + intdiv($days-186,30); $jd = 1 + (($days-186)%30); }
        return [$jy,$jm,$jd];
    }

    public static function parse(string $jalali): ?string
    {
        $jalali = trim(str_replace(['-', '.', ' '], '/', self::enDigits($jalali)));
        if (!preg_match('/^(13|14)\d{2}\/(\d{1,2})\/(\d{1,2})$/', $jalali, $m)) return null;
        [$y,$mo,$d] = array_map('intval', explode('/', $jalali));
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > self::monthLength($y,$mo)) return null;
        [$gy,$gm,$gd] = self::toGregorian($y,$mo,$d);
        return sprintf('%04d-%02d-%02d', $gy,$gm,$gd);
    }

    public static function fromGregorian(?string $date): string
    {
        if (!$date) return '';
        // Prefer date-only Y-m-d (no timezone shift). Datetime strings fall back to strtotime.
        $raw = trim(self::enDigits($date));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $m)) {
            $gy = (int)$m[1]; $gm = (int)$m[2]; $gd = (int)$m[3];
            if (!checkdate($gm, $gd, $gy)) return '';
            [$jy,$jm,$jd] = self::toJalali($gy, $gm, $gd);
            return sprintf('%04d/%02d/%02d', $jy,$jm,$jd);
        }
        $ts = strtotime($raw); if (!$ts) return '';
        [$jy,$jm,$jd] = self::toJalali((int)date('Y',$ts), (int)date('n',$ts), (int)date('j',$ts));
        return sprintf('%04d/%02d/%02d', $jy,$jm,$jd);
    }

    public static function today(): string { return self::fromGregorian(date('Y-m-d')); }

    public static function enDigits(string $s): string
    {
        return strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }
}
