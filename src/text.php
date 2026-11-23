<?php
declare(strict_types=1);

const AR_DAYS   = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const AR_MONTHS = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

/** الأرقام العربية (٠١٢) والفارسية (۰۱۲) إلى أرقام لاتينية */
function latin_digits(string $s): string
{
    return strtr($s, [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
}

/**
 * توحيد رقم الجوال. الأرقام السعودية بأي صيغة تصبح 9665XXXXXXXX:
 * 0551234567 / 551234567 / +966551234567 / 00966551234567 / ٠٥٥١٢٣٤٥٦٧
 * الأرقام الدولية الأخرى تُقبل إن بدأت بـ + أو 00 (من 8 إلى 15 رقماً).
 */
function normalize_phone(string $raw): ?string
{
    $s = latin_digits(trim($raw));
    $intl = str_starts_with($s, '+') || str_starts_with($s, '00');
    $d = preg_replace('/\D+/', '', $s);
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (preg_match('/^(?:966|0)?(5\d{8})$/', $d, $m)) {
        return '966' . $m[1];
    }
    if ($intl && preg_match('/^[1-9]\d{7,14}$/', $d)) {
        return $d;
    }
    return null;
}

/** 966551234567 ← تُعرض 055 123 4567 */
function show_phone(string $p): string
{
    if (preg_match('/^966(5\d)(\d{3})(\d{4})$/', $p, $m)) {
        return "0{$m[1]} {$m[2]} {$m[3]}";
    }
    return '+' . $p;
}

function day_of(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format('Y-m-d');
}

/** 10:30 ص / 4:00 م */
function ar_time(int $ts): string
{
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(tz());
    return $d->format('g:i') . ' ' . ($d->format('A') === 'AM' ? 'ص' : 'م');
}

/** الأربعاء 25 نوفمبر */
function ar_date(string $day, bool $year = false): string
{
    $d = new DateTimeImmutable($day, tz());
    return AR_DAYS[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . AR_MONTHS[(int) $d->format('n')] . ($year ? ' ' . $d->format('Y') : '');
}

/** التاريخ الهجري بتقويم أم القرى، إن كانت إضافة intl موجودة */
function hijri(string $day): string
{
    if (!class_exists('IntlDateFormatter')) {
        return '';
    }
    $f = new IntlDateFormatter('ar@calendar=islamic-umalqura;numbers=latn', IntlDateFormatter::FULL, IntlDateFormatter::NONE,
        tz()->getName(), IntlDateFormatter::TRADITIONAL, 'd MMMM y');
    $out = $f->format(new DateTimeImmutable($day . ' 12:00', tz()));
    return $out === false ? '' : $out . 'هـ';
}

/** الدقائق بالعربية: 30 دقيقة، ساعة، ساعة ونصف، ساعتان */
function ar_minutes(int $m): string
{
    $map = [60 => 'ساعة', 90 => 'ساعة ونصف', 120 => 'ساعتان', 180 => '3 ساعات', 240 => '4 ساعات'];
    if (isset($map[$m])) {
        return $map[$m];
    }
    return $m . ' دقيقة';
}

function str_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./us', $s);
}
