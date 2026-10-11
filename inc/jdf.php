<?php
/*
 * JDF - Jalali Date Function
 * Lightweight Persian Date Library for PHP
 * Compatible with PHP 5.x and 7.x, 8.x
 */

function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa') {
    $timestamp = ($timestamp === '') ? time() : $timestamp;
    if ($time_zone != 'local') date_default_timezone_set($time_zone);
    
    $T_BJ = date('Y-m-d H:i:s', $timestamp);
    $d = date('d', $timestamp);
    $m = date('m', $timestamp);
    $y = date('Y', $timestamp);
    
    $res = gregorian_to_jalali($y, $m, $d);
    $jy = $res[0];
    $jm = $res[1];
    $jd = $res[2];
    
    $months = array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
    
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $sub = $format[$i];
        if ($sub == '\\') {
            $out .= $format[++$i];
            continue;
        }
        switch ($sub) {
            case 'd': $out .= ($jd < 10) ? "0$jd" : $jd; break;
            case 'j': $out .= $jd; break;
            case 'm': $out .= ($jm < 10) ? "0$jm" : $jm; break;
            case 'n': $out .= $jm; break;
            case 'M': 
            case 'F': $out .= $months[$jm-1]; break;
            case 'y': $out .= substr($jy, 2, 2); break;
            case 'Y': $out .= $jy; break;
            case 'H': $out .= date('H', $timestamp); break;
            case 'i': $out .= date('i', $timestamp); break;
            case 's': $out .= date('s', $timestamp); break;
            default: $out .= $sub;
        }
    }
    
    return $out;
}

function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    if ($gy > 1600) {
        $jy = 979;
        $gy -= 1600;
    } else {
        $jy = 0;
        $gy -= 621;
    }
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * ((int)($days / 12053));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = ($days < 186) ? (1 + (int)($days / 31)) : (7 + (int)(($days - 186) / 30));
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    return array($jy, $jm, $jd);
}
