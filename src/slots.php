<?php
declare(strict_types=1);

function hm_ok(string $hm): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hm);
}

/** فترات العمل لكل يوم: [0 => [['09:00','13:00'], ['16:00','22:00']], ...] */
function week_hours(): array
{
    $out = array_fill(0, 7, []);
    foreach (db()->query('SELECT weekday, start, "end" FROM hours ORDER BY weekday, start') as $r) {
        $out[(int) $r['weekday']][] = [$r['start'], $r['end']];
    }
    return $out;
}

/**
 * يحفظ جدول الأسبوع من النموذج: لكل يوم فترتان اختياريتان.
 * فترة تنتهي قبل بدايتها (16:00 ← 01:00) تعني أنها تمتد بعد منتصف الليل.
 */
function save_week_hours(array $post): ?string
{
    $rows = [];
    for ($w = 0; $w < 7; $w++) {
        if (empty($post['open'][$w])) {
            continue;
        }
        for ($i = 0; $i < 2; $i++) {
            $s = latin_digits(trim((string) ($post['start'][$w][$i] ?? '')));
            $e = latin_digits(trim((string) ($post['end'][$w][$i] ?? '')));
            if ($s === '' && $e === '') {
                continue;
            }
            if (!hm_ok($s) || !hm_ok($e) || $s === $e) {
                return 'وقت غير صحيح في يوم ' . AR_DAYS[$w] . '. اكتب الوقت بصيغة 24 ساعة مثل 09:00 و 22:30.';
            }
            $rows[] = [$w, $s, $e];
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM hours');
    $st = $pdo->prepare('INSERT INTO hours (weekday, start, "end") VALUES (?, ?, ?)');
    foreach ($rows as $r) {
        $st->execute($r);
    }
    $pdo->commit();
    return null;
}

function closed_days(): array
{
    $st = db()->prepare('SELECT day, reason FROM closed_days WHERE day >= ? ORDER BY day');
    $st->execute([day_of(now())]);
    return $st->fetchAll();
}

function closed_reason(string $day): ?string
{
    $st = db()->prepare('SELECT reason FROM closed_days WHERE day = ?');
    $st->execute([$day]);
    $r = $st->fetchColumn();
    return $r === false ? null : (string) $r;
}

function add_closed_day(string $day, string $reason): ?string
{
    $day = latin_digits(trim($day));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day, tz());
    if (!$d || $d->format('Y-m-d') !== $day) {
        return 'التاريخ غير صحيح.';
    }
    db()->prepare('INSERT INTO closed_days (day, reason) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET reason = excluded.reason')
        ->execute([$day, mb_substr(trim($reason), 0, 80)]);
    return null;
}

function delete_closed_day(string $day): void
{
    db()->prepare('DELETE FROM closed_days WHERE day = ?')->execute([$day]);
}

function ts_at(string $day, string $hm): int
{
    return (new DateTimeImmutable("$day $hm", tz()))->getTimestamp();
}

/** المواعيد التي تشغل الوقت بين from و to (كل ما لم يُلغَ) */
function busy_ranges(int $from, int $to): array
{
    // start_ts > from - يوم: يبقي البحث في جزء صغير من الفهرس مهما كبر سجل الحجوزات
    $st = db()->prepare("SELECT start_ts, end_ts FROM bookings WHERE status <> 'cancelled' AND start_ts > ? AND start_ts < ? AND end_ts > ?");
    $st->execute([$from - 86400, $to, $from]);
    return $st->fetchAll(PDO::FETCH_NUM);
}

/**
 * الأوقات المتاحة لخدمة في يوم معين (طوابع زمنية).
 * المصدر الوحيد للحقيقة: صفحة الحجز تعرضه، والحجز نفسه يتحقق منه داخل المعاملة.
 */
function day_slots(array $service, string $day): array
{
    $today = day_of(now());
    $last  = (new DateTimeImmutable($today, tz()))->modify('+' . max(0, setting_int('max_days') - 1) . ' days')->format('Y-m-d');
    if ($day < $today || $day > $last || closed_reason($day) !== null) {
        return [];
    }
    $w      = (int) (new DateTimeImmutable($day, tz()))->format('w');
    $ranges = week_hours()[$w];
    if (!$ranges) {
        return [];
    }
    $dur      = (int) $service['duration'] * 60;
    $step     = max(5, setting_int('slot_step')) * 60;
    $buf      = max(0, setting_int('buffer')) * 60;
    $earliest = now() + setting_int('min_notice') * 3600;

    $spans = [];
    foreach ($ranges as [$s, $e]) {
        $from = ts_at($day, $s);
        $to   = ts_at($day, $e);
        if ($to <= $from) {
            $to += 86400; // فترة تمتد بعد منتصف الليل
        }
        $spans[] = [$from, $to];
    }
    $busy = busy_ranges(min(array_column($spans, 0)) - $buf, max(array_column($spans, 1)) + $buf);

    $out = [];
    foreach ($spans as [$from, $to]) {
        for ($t = $from; $t + $dur <= $to; $t += $step) {
            if ($t < $earliest) {
                continue;
            }
            foreach ($busy as [$bs, $be]) {
                if ($t < $be + $buf && $t + $dur + $buf > $bs) {
                    continue 2;
                }
            }
            $out[] = $t;
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

/** أيام الحجز القادمة مع عدد الأوقات المتاحة في كل يوم */
function bookable_days(array $service): array
{
    $days = [];
    $d = new DateTimeImmutable(day_of(now()), tz());
    for ($i = 0; $i < setting_int('max_days'); $i++, $d = $d->modify('+1 day')) {
        $day = $d->format('Y-m-d');
        $days[] = ['day' => $day, 'n' => count(day_slots($service, $day)), 'closed' => closed_reason($day)];
    }
    return $days;
}
