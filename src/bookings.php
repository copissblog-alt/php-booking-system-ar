<?php
declare(strict_types=1);

const STATUS = ['confirmed' => 'مؤكد', 'done' => 'حضر', 'no_show' => 'لم يحضر', 'cancelled' => 'ملغى'];

/** رقم حجز قصير يُقرأ بالهاتف بلا لبس (لا 0 و O ولا 1 و I) */
function new_ref(): string
{
    $abc = 'ACDEFGHJKMNPQRTUVWXY34679';
    $s = '';
    for ($i = 0; $i < 6; $i++) {
        $s .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return $s;
}

/** هل ما زال هذا الوقت متاحاً؟ نفحص يومه واليوم السابق (لفترات ما بعد منتصف الليل) */
function slot_is_free(array $service, int $start): bool
{
    return in_array($start, day_slots($service, day_of($start)), true)
        || in_array($start, day_slots($service, day_of($start - 86400)), true);
}

/**
 * ينشئ الحجز. يرجع [الحجز، رسالة الخطأ، هل أُخذ الموعد].
 * BEGIN IMMEDIATE يحجز قفل الكتابة قبل الفحص: طلبان على الموعد نفسه في اللحظة نفسها
 * لا يمكن أن يمرا معاً، فالثاني ينتظر الأول ثم يجد الموعد محجوزاً.
 */
function create_booking(array $service, int $start, array $in): array
{
    $name  = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
    $phone = normalize_phone((string) ($in['phone'] ?? ''));
    $note  = trim((string) ($in['note'] ?? ''));
    if (($in['website'] ?? '') !== '') {
        return [null, 'تعذر إتمام الحجز.', false]; // حقل مخفي لا يملؤه إلا البوت
    }
    if (str_len($name) < 2 || str_len($name) > 60) {
        return [null, 'اكتب الاسم (من حرفين إلى 60 حرفاً).', false];
    }
    if ($phone === null) {
        return [null, 'رقم الجوال غير صحيح. مثال: 0551234567', false];
    }
    if (str_len($note) > 300) {
        return [null, 'الملاحظة حتى 300 حرف.', false];
    }
    if (hits('book', 3600) >= max(1, setting_int('ip_limit'))) {
        http_response_code(429);
        return [null, 'حجوزات كثيرة من هذا الجهاز خلال ساعة. حاول لاحقاً أو اتصل بنا.', false];
    }

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (!slot_is_free($service, $start)) {
            $pdo->exec('ROLLBACK');
            return [null, 'هذا الموعد لم يعد متاحاً، سبقك إليه غيرك. اختر وقتاً آخر.', true];
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE phone = ? AND status = 'confirmed' AND start_ts > ?");
        $st->execute([$phone, now()]);
        if ((int) $st->fetchColumn() >= max(1, setting_int('max_per_phone'))) {
            $pdo->exec('ROLLBACK');
            return [null, 'لديك مواعيد قادمة بالحد الأقصى لهذا الرقم. ألغِ أحدها أو انتظر حتى يمر.', false];
        }
        $b = [
            'ref'          => new_ref(),
            'token'        => bin2hex(random_bytes(16)),
            'service_id'   => (int) $service['id'],
            'service_name' => $service['name'],
            'start_ts'     => $start,
            'end_ts'       => $start + (int) $service['duration'] * 60,
            'name'         => $name,
            'phone'        => $phone,
            'note'         => $note,
            'ip_hash'      => ip_hash(),
            'created_at'   => now(),
        ];
        $pdo->prepare('INSERT INTO bookings (' . implode(', ', array_keys($b)) . ') VALUES (' . rtrim(str_repeat('?, ', count($b)), ', ') . ')')
            ->execute(array_values($b));
        add_hit('book');
        $pdo->exec('COMMIT');
        return [$b, null, false];
    } catch (PDOException $e) {
        $pdo->exec('ROLLBACK');
        if ($e->getCode() === '23000') { // الفهرس الفريد: موعد نشط آخر يبدأ في الدقيقة نفسها
            return [null, 'هذا الموعد لم يعد متاحاً. اختر وقتاً آخر.', true];
        }
        throw $e;
    }
}

function find_booking(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM bookings WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** يستطيع العميل الإلغاء بنفسه حتى cancel_notice ساعة قبل الموعد */
function can_cancel(array $b): bool
{
    return $b['status'] === 'confirmed' && (int) $b['start_ts'] - now() >= setting_int('cancel_notice') * 3600;
}

function cancel_booking(array $b): bool
{
    if (!can_cancel($b)) {
        return false;
    }
    db()->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status = 'confirmed'")->execute([now(), $b['id']]);
    return true;
}

function set_status(int $id, string $status): void
{
    if (!isset(STATUS[$status])) {
        return;
    }
    $st = db()->prepare('UPDATE bookings SET status = ?, cancelled_at = ? WHERE id = ?');
    try {
        $st->execute([$status, $status === 'cancelled' ? now() : null, $id]);
    } catch (PDOException $e) {
        // إرجاع موعد ملغى بعد أن حُجز وقته لشخص آخر يصطدم بالفهرس الفريد: نتركه ملغى
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        flash(['bad', 'لا يمكن إرجاع هذا الموعد: وقته محجوز الآن لعميل آخر.']);
    }
}

/** قائمة لوحة المدير: today | upcoming | past | cancelled، مع بحث بالاسم أو الجوال أو رقم الحجز */
function list_bookings(string $tab, string $q = ''): array
{
    $dayStart = ts_at(day_of(now()), '00:00');
    $where = [];
    $args  = [];
    switch ($tab) {
        case 'today':
            $where[] = "start_ts >= ? AND start_ts < ? AND status <> 'cancelled'";
            array_push($args, $dayStart, $dayStart + 86400);
            $order = 'start_ts';
            break;
        case 'past':
            $where[] = "start_ts < ? AND status <> 'cancelled'";
            $args[] = $dayStart;
            $order = 'start_ts DESC';
            break;
        case 'cancelled':
            $where[] = "status = 'cancelled'";
            $order = 'start_ts DESC';
            break;
        default:
            $where[] = "start_ts >= ? AND status <> 'cancelled'";
            $args[] = $dayStart;
            $order = 'start_ts';
    }
    $q = trim(latin_digits($q));
    if ($q !== '') {
        $phone = normalize_phone($q) ?? preg_replace('/\D+/', '', $q);
        $where[] = '(name LIKE ? OR ref = ? OR (? <> \'\' AND phone LIKE ?))';
        array_push($args, '%' . $q . '%', strtoupper($q), $phone, '%' . $phone . '%');
    }
    $st = db()->prepare('SELECT * FROM bookings WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT 300");
    $st->execute($args);
    return $st->fetchAll();
}

function tab_counts(): array
{
    $dayStart = ts_at(day_of(now()), '00:00');
    $st = db()->prepare("SELECT
        SUM(start_ts >= ? AND start_ts < ? AND status <> 'cancelled') today,
        SUM(start_ts >= ? AND status <> 'cancelled') upcoming
        FROM bookings WHERE start_ts >= ?");
    $st->execute([$dayStart, $dayStart + 86400, $dayStart, $dayStart]);
    $r = $st->fetch();
    return ['today' => (int) $r['today'], 'upcoming' => (int) $r['upcoming']];
}

/** ملف تقويم .ics: يفتح في تقويم الجوال ويضيف الموعد مع تذكير قبله بساعة */
function booking_ics(array $b): string
{
    $esc = fn(string $s) => addcslashes(str_replace(["\r\n", "\n"], '\n', $s), ',;\\');
    $utc = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//php-booking-system-ar//AR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $b['ref'] . '@' . $host,
        'DTSTAMP:' . $utc((int) $b['created_at']),
        'DTSTART:' . $utc((int) $b['start_ts']),
        'DTEND:' . $utc((int) $b['end_ts']),
        'SUMMARY:' . $esc($b['service_name'] . ' - ' . setting('business_name')),
        'DESCRIPTION:' . $esc('رقم الحجز: ' . $b['ref'] . "\nإدارة الموعد: " . base_url('/b/' . $b['token'])),
        'STATUS:' . ($b['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
        'BEGIN:VALARM', 'TRIGGER:-PT1H', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc('تذكير بموعدك'), 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR',
    ];
    return implode("\r\n", $lines) . "\r\n";
}

function google_calendar_url(array $b): string
{
    $utc = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'  => 'TEMPLATE',
        'text'    => $b['service_name'] . ' - ' . setting('business_name'),
        'dates'   => $utc((int) $b['start_ts']) . '/' . $utc((int) $b['end_ts']),
        'details' => 'رقم الحجز: ' . $b['ref'] . "\n" . base_url('/b/' . $b['token']),
    ]);
}

/** رابط واتساب للعميل برسالة تذكير جاهزة */
function whatsapp_url(array $b): string
{
    $text = 'السلام عليكم ' . $b['name'] . "،\nنذكّرك بموعدك (" . $b['service_name'] . ') يوم '
        . ar_date(day_of((int) $b['start_ts'])) . ' الساعة ' . ar_time((int) $b['start_ts'])
        . "\nرقم الحجز: " . $b['ref'] . "\n" . setting('business_name');
    return 'https://wa.me/' . $b['phone'] . '?text=' . rawurlencode($text);
}

function export_bookings_csv(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bookings-' . day_of(now()) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // ليقرأ Excel العربية
    fputcsv($out, ['ref', 'date', 'time', 'service', 'name', 'phone', 'status', 'note']);
    foreach (db()->query('SELECT * FROM bookings ORDER BY start_ts') as $r) {
        $cells = [$r['ref'], day_of((int) $r['start_ts']), date('H:i', (int) $r['start_ts']), $r['service_name'], $r['name'], show_phone($r['phone']), STATUS[$r['status']] ?? $r['status'], $r['note']];
        // نمنع حقن الصيغ في Excel: خلية تبدأ بـ = أو + أو - أو @ تُكتب نصاً
        $cells = array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $cells);
        fputcsv($out, $cells);
    }
    fclose($out);
    exit;
}
