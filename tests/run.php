<?php
// اختبارات تكامل: يشغّل خادم PHP المدمج (8 عمليات متوازية) على قاعدة مؤقتة، وبوقت ثابت،
// ويجرب المسارات كما يفعل المتصفح، ومعه خادم تيليجرام مزيف يسجل التنبيهات.
// التشغيل: php tests/run.php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/bk-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
date_default_timezone_set('Asia/Riyadh');
$NOW  = (new DateTimeImmutable('2030-01-06 08:00'))->getTimestamp(); // الأحد 8 صباحاً
$port = 8097;
$tgPort = 8096;
file_put_contents("$tmp/config.php", "<?php return ['base_url' => 'http://127.0.0.1:$port', 'setup_token' => 'test-setup-token-123', 'secret' => 'test-secret', 'db_path' => '$tmp/b.sqlite', 'timezone' => 'Asia/Riyadh', 'now' => $NOW, 'telegram_token' => '1:T', 'telegram_chat_id' => '42', 'telegram_api' => 'http://127.0.0.1:$tgPort'];");
file_put_contents("$tmp/tg.php", '<?php file_put_contents(__DIR__ . "/tg.log", json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); echo "{\"ok\":true}";');

$null = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$tg   = proc_open([PHP_BINARY, '-S', "127.0.0.1:$tgPort", "$tmp/tg.php"], $null, $p1);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"], $null, $p2, null,
    ['BK_CONFIG' => "$tmp/config.php", 'PHP_CLI_SERVER_WORKERS' => '8']);
usleep(700000);

function opts(string $method, string $path, array $post, string $jar): array
{
    global $port;
    $o = [CURLOPT_URL => "http://127.0.0.1:$port$path", CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_PROXY => ''];
    if ($method === 'POST') {
        $o[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    return $o;
}
function parse($ch, string $raw): array
{
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $hs);
    preg_match('/^Location: (.+)$/mi', $head, $m);
    return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'head' => $head, 'body' => substr($raw, $hs), 'loc' => trim($m[1] ?? '')];
}
function req(string $method, string $path, array $post = [], ?string $jar = null): array
{
    global $tmp;
    $ch = curl_init();
    curl_setopt_array($ch, opts($method, $path, $post, $jar ?? "$tmp/cookies"));
    $r = parse($ch, (string) curl_exec($ch));
    curl_close($ch);
    return $r;
}
function token(string $html): string
{
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ($detail)" : '');
}
function at(string $local): int
{
    return (new DateTimeImmutable($local))->getTimestamp();
}
function db(): PDO
{
    global $tmp;
    return new PDO("sqlite:$tmp/b.sqlite");
}
// رمز CSRF لجلسة متصفح معين (صفحة الدخول تعرضه دائماً)
function csrf_of(string $jar): string
{
    return token(req('GET', '/admin/login', [], $jar)['body']);
}
$C = "$tmp/customer"; // متصفح العميل منفصل عن متصفح المدير
function book(int $s, int $t, array $f, ?string $jar = null): array
{
    global $C;
    $jar ??= $C;
    req('GET', "/?s=$s&t=$t", [], $jar);
    $tk = csrf_of($jar);
    return req('POST', '/book', $f + ['csrf' => $tk, 's' => $s, 't' => $t, 'name' => 'عميل', 'note' => ''], $jar);
}

// ===== الإعداد الأول =====
$r = req('GET', '/admin/login');
check('no admin yet -> /admin/login redirects to /setup', $r['code'] === 303 && $r['loc'] === '/setup');
$t = token(req('GET', '/setup')['body']);
$r = req('POST', '/setup', ['csrf' => $t, 'token' => 'wrong', 'username' => 'admin', 'password' => 'a-very-long-password']);
check('setup with wrong token is refused', str_contains($r['body'], 'رمز الإعداد غير صحيح'));
$r = req('POST', '/setup', ['csrf' => $t, 'token' => 'test-setup-token-123', 'username' => 'admin', 'password' => 'a-very-long-password']);
check('setup creates the admin and opens working hours', $r['code'] === 303 && $r['loc'] === '/admin/hours', "{$r['code']} {$r['loc']}");
check('setup page is closed afterwards', req('GET', '/setup')['code'] === 404);

// ===== الدوام والخدمات والإعدادات =====
$t = token(req('GET', '/admin/hours')['body']);
$h = ['csrf' => $t];
foreach ([0, 1, 2, 3, 4] as $w) { // الأحد إلى الخميس: فترتان
    $h['open'][$w] = 1; $h['start'][$w] = ['09:00', '16:00']; $h['end'][$w] = ['12:00', '22:00'];
}
$h['open'][6] = 1; $h['start'][6] = ['16:00', '']; $h['end'][6] = ['01:00', '']; // السبت حتى الواحدة بعد منتصف الليل
req('POST', '/admin/hours', ['csrf' => $t, 'open' => [1 => 1], 'start' => [1 => ['25:00']], 'end' => [1 => ['12:00']]]);
check('invalid time refused', str_contains(req('GET', '/admin/hours')['body'], 'وقت غير صحيح'));
req('POST', '/admin/hours', $h);
$hb = req('GET', '/admin/hours')['body'];
check('weekly hours saved (two shifts)', str_contains($hb, 'حُفظ جدول الدوام') && str_contains($hb, 'value="16:00"'));
req('POST', '/admin/services', ['csrf' => $t, 'name' => 'كشف', 'duration' => 30, 'price' => '١٥٠']);
req('POST', '/admin/services', ['csrf' => $t, 'name' => 'جلسة علاج', 'duration' => 60, 'price' => '']);
check('services created (Arabic digits in price accepted)', str_contains(req('GET', '/admin/services')['body'], '150 ريال'));
req('POST', '/admin/services', ['csrf' => $t, 'name' => 'x', 'duration' => 33]);
check('odd duration refused', str_contains(req('GET', '/admin/services')['body'], 'اختر مدة'));
req('POST', '/admin/services', ['csrf' => $t, 'name' => 'x', 'duration' => 30, 'price' => '10$']);
check('bad price refused', str_contains(req('GET', '/admin/services')['body'], 'السعر رقم فقط'));
$set = ['csrf' => $t, 'business_name' => 'عيادة الاختبار', 'intro' => 'مرحباً', 'contact_phone' => '0112345678', 'slot_step' => 30, 'buffer' => 0, 'min_notice' => 2, 'max_days' => 14, 'cancel_notice' => 3, 'max_per_phone' => 2, 'ip_limit' => 100];
req('POST', '/admin/settings', ['max_days' => 500] + $set);
check('out-of-range setting refused', str_contains(req('GET', '/admin/settings')['body'], 'من 1 إلى 90'));
req('POST', '/admin/settings', ['contact_phone' => '0551112222'] + $set);
check('settings saved', str_contains(req('GET', '/admin/settings')['body'], 'حُفظت الإعدادات'));
$set['contact_phone'] = '0551112222';
req('POST', '/admin/closed/add', ['csrf' => $t, 'day' => '2030-01-07', 'reason' => 'صيانة']);

// ===== صفحة الحجز =====
$home = req('GET', '/', [], $C)['body'];
check('home lists both services', str_contains($home, 'كشف') && str_contains($home, 'جلسة علاج') && str_contains($home, 'عيادة الاختبار'));
$p = req('GET', '/?s=1&d=2030-01-06', [], $C)['body'];
check('today: slots before now + 2h hidden (9:30 gone, 10:00 shown)', !str_contains($p, '>9:30 ص<') && str_contains($p, '>10:00 ص<'));
check('Hijri date shown when intl exists', !class_exists('IntlDateFormatter') || str_contains($p, 'هـ'));
check('closed day shows its reason', str_contains(req('GET', '/?s=1&d=2030-01-07', [], $C)['body'], 'مغلق هذا اليوم: صيانة'));
check('Friday (no hours) has no slots', str_contains(req('GET', '/?s=1&d=2030-01-11', [], $C)['body'], 'لا توجد أوقات متاحة'));
$sat = req('GET', '/?s=1&d=2030-01-12', [], $C)['body'];
check('Saturday shift crosses midnight (12:30 ص offered)', str_contains($sat, '>12:30 ص<') && !str_contains($sat, '>1:00 ص<'));
$far = req('GET', '/?s=1&d=2030-01-25', [], $C)['body'];
check('day beyond max_days not offered (falls back to the first open day)', !str_contains($far, '25 يناير') && str_contains($far, 'الأحد 6 يناير'));

// الحجز
$t10 = at('2030-01-06 10:00');
$r = book(1, $t10, ['phone' => '12345']);
check('bad phone refused', str_contains($r['body'], 'رقم الجوال غير صحيح'));
$r = book(1, $t10, ['phone' => '٠٥٥١٢٣٤٥٦٧', 'name' => 'سارة <b>x</b>']);
check('booking with Arabic-digit phone -> 303 to /b/<token>?new=1', $r['code'] === 303 && preg_match('#^/b/[a-f0-9]{32}\?new=1$#', $r['loc']) === 1, "{$r['code']} {$r['loc']}");
$tok = substr($r['loc'], 3, 32);
$bp = req('GET', "/b/$tok?new=1", [], $C)['body'];
check('confirmation page shows ref and time', str_contains($bp, 'تم الحجز') && preg_match('#class="ref" dir="ltr">([A-Z0-9]{6})<#', $bp, $rm) === 1 && str_contains($bp, '10:00 ص'));
$ref = $rm[1] ?? '';
check('phone normalized to 966 format', db()->query("SELECT phone FROM bookings WHERE ref = '$ref'")->fetchColumn() === '966551234567');
usleep(300000);
$log = (string) @file_get_contents("$tmp/tg.log");
check('owner notified on Telegram after the response', str_contains($log, 'حجز جديد') && str_contains($log, $ref) && str_contains($log, '"chat_id":"42"'));
$r = book(1, $t10, ['phone' => '0559999999']);
check('same slot again -> sent back to the slots with a message', $r['code'] === 303 && str_contains(req('GET', $r['loc'], [], $C)['body'], 'لم يعد متاحاً'));
check('booked slot no longer listed', !str_contains(req('GET', '/?s=1&d=2030-01-06', [], $C)['body'], '>10:00 ص<'));

// تداخل خدمات بمدد مختلفة، والراحة بين المواعيد
$r = book(2, at('2030-01-06 16:00'), ['phone' => '0550000001']);
$p = req('GET', '/?s=1&d=2030-01-06', [], $C)['body'];
check('60-min booking at 4:00 blocks 4:00 and 4:30, keeps 5:00', !str_contains($p, '>4:00 م<') && !str_contains($p, '>4:30 م<') && str_contains($p, '>5:00 م<'));
$r = book(1, at('2030-01-06 16:30'), ['phone' => '0550000002']);
check('booking 4:30 directly (overlap) is refused', str_starts_with($r['loc'], '/?s=1&t=') && (int) db()->query('SELECT COUNT(*) FROM bookings WHERE start_ts = ' . at('2030-01-06 16:30'))->fetchColumn() === 0);
req('POST', '/admin/settings', ['buffer' => 15] + $set);
$p = req('GET', '/?s=1&d=2030-01-06', [], $C)['body'];
check('15-min buffer removes 5:00, keeps 5:30', !str_contains($p, '>5:00 م<') && str_contains($p, '>5:30 م<'));
req('POST', '/admin/settings', $set);
check('Saturday 12:30 ص (after midnight) can be booked', str_contains(book(1, at('2030-01-13 00:30'), ['phone' => '0550000003'])['loc'], '/b/'));

// حد الرقم، وحقل البوت
book(1, at('2030-01-08 09:00'), ['phone' => '0551234567']);
$r = book(1, at('2030-01-08 09:30'), ['phone' => '+966 55 123 4567']);
check('3rd upcoming booking for the same phone refused (other format)', str_contains($r['body'], 'بالحد الأقصى'));
$r = book(1, at('2030-01-08 10:00'), ['phone' => '0557777777', 'website' => 'http://spam']);
check('honeypot filled -> refused', str_contains($r['body'], 'تعذر إتمام الحجز'));

// التقويم
$ics = req('GET', "/b/$tok.ics");
check('ICS file: text/calendar, UTC start, CRLF, alarm', str_contains($ics['head'], 'text/calendar') && str_contains($ics['body'], 'DTSTART:' . gmdate('Ymd\THis\Z', $t10)) && str_contains($ics['body'], "\r\nBEGIN:VALARM\r\n"));

// الإلغاء
check('cancel hidden when less than 3h left (10:00 at 08:00)', !str_contains(req('GET', "/b/$tok", [], $C)['body'], 'إلغاء الموعد'));
$tk = csrf_of($C);
req('POST', "/b/$tok/cancel", ['csrf' => $tk], $C);
check('late cancel POST refused', str_contains(req('GET', "/b/$tok", [], $C)['body'], 'لا يمكن الإلغاء الآن'));
$r = book(1, at('2030-01-09 11:00'), ['phone' => '0550000004']);
$tok2 = substr($r['loc'], 3, 32);
$tk = token(req('GET', "/b/$tok2", [], $C)['body']);
req('POST', "/b/$tok2/cancel", ['csrf' => $tk], $C);
check('customer cancels in time', str_contains(req('GET', "/b/$tok2", [], $C)['body'], 'أُلغي موعدك'));
check('cancelled slot is free again', str_contains(req('GET', '/?s=1&d=2030-01-09', [], $C)['body'], '>11:00 ص<'));
usleep(300000);
check('owner notified of the cancellation', str_contains((string) file_get_contents("$tmp/tg.log"), 'ألغى العميل'));
$r = book(1, at('2030-01-09 11:00'), ['phone' => '0550000005']);
check('someone else takes the freed slot', str_contains($r['loc'], '/b/'));

// لوحة المدير
$a = req('GET', '/admin?tab=upcoming')['body'];
check('admin list escapes HTML in names', str_contains($a, 'سارة &lt;b&gt;x&lt;/b&gt;') && !str_contains($a, 'سارة <b>'));
check('WhatsApp reminder link to the customer', str_contains($a, 'https://wa.me/966551234567?text='));
check('search by phone in another format', str_contains(req('GET', '/admin?tab=upcoming&q=' . urlencode('+966551234567'))['body'], 'سارة'));
check('search by ref', str_contains(req('GET', '/admin?tab=upcoming&q=' . strtolower($ref))['body'], $ref));
$id2 = (int) db()->query("SELECT id FROM bookings WHERE token = '$tok2'")->fetchColumn();
req('POST', '/admin/status', ['csrf' => $t, 'id' => $id2, 'status' => 'confirmed', 'back' => '?tab=cancelled']);
check('restoring a cancelled booking whose time was re-booked is refused', str_contains(req('GET', '/admin')['body'], 'وقته محجوز الآن'));
$id1 = (int) db()->query("SELECT id FROM bookings WHERE token = '$tok'")->fetchColumn();
req('POST', '/admin/status', ['csrf' => $t, 'id' => $id1, 'status' => 'done']);
check('mark as attended', db()->query("SELECT status FROM bookings WHERE id = $id1")->fetchColumn() === 'done');
book(2, at('2030-01-10 09:00'), ['phone' => '0550000006', 'name' => '=HYPERLINK("http://x")']);
$csv = req('GET', '/admin/export.csv');
check('CSV export with formula-injection guard', $csv['code'] === 200 && str_contains($csv['body'], "\"'=HYPERLINK") && str_contains($csv['body'], '055 123 4567'));

// ===== السباق: 20 عميلاً يضغطون «تأكيد» على الموعد نفسه في اللحظة نفسها =====
$race = at('2030-01-10 16:00');
$mh = curl_multi_init();
$hs = [];
for ($i = 0; $i < 20; $i++) {
    $jar = "$tmp/race$i";
    $tk  = token(req('GET', "/?s=1&t=$race", [], $jar)['body']);
    $ch  = curl_init();
    curl_setopt_array($ch, opts('POST', '/book', ['csrf' => $tk, 's' => 1, 't' => $race, 'name' => "متسابق $i", 'phone' => sprintf('05600000%02d', $i), 'note' => ''], $jar));
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
} while ($running);
$won = 0; $lost = 0; $other = 0;
foreach ($hs as $ch) {
    $r = parse($ch, (string) curl_multi_getcontent($ch));
    str_starts_with($r['loc'], '/b/') ? $won++ : (str_starts_with($r['loc'], '/?s=1&t=') ? $lost++ : $other++);
}
$rows = (int) db()->query("SELECT COUNT(*) FROM bookings WHERE start_ts = $race")->fetchColumn();
check('race: 20 parallel bookings for one slot -> exactly 1 wins', $won === 1 && $lost === 19 && $other === 0 && $rows === 1, "won=$won lost=$lost other=$other rows=$rows");

// حد الجهاز
req('POST', '/admin/settings', ['ip_limit' => 3] + $set);
$r = book(1, at('2030-01-14 09:00'), ['phone' => '0550000099']);
check('device limit per hour -> 429', $r['code'] === 429 && str_contains($r['body'], 'حجوزات كثيرة'), (string) $r['code']);

// ===== الحماية =====
$r = req('POST', '/admin/services', ['csrf' => 'forged', 'name' => 'x', 'duration' => 30]);
check('POST with a forged CSRF token -> 403', $r['code'] === 403);
check('unknown booking token -> 404', req('GET', '/b/' . str_repeat('a', 32))['code'] === 404);
check('security headers sent', str_contains(req('GET', '/')['head'], "frame-ancestors 'none'"));
req('POST', '/admin/logout', ['csrf' => $t]);
foreach (['/admin', '/admin/services', '/admin/hours', '/admin/settings', '/admin/export.csv'] as $u) {
    $lr = req('GET', $u);
    if ($lr['loc'] !== '/admin/login') {
        break;
    }
}
check('all admin pages need login', $lr['loc'] === '/admin/login', $u);
$t2 = token(req('GET', '/admin/login')['body']);
for ($i = 0; $i < 5; $i++) {
    req('POST', '/admin/login', ['csrf' => $t2, 'username' => 'admin', 'password' => 'wrong' . $i]);
}
$r = req('POST', '/admin/login', ['csrf' => $t2, 'username' => 'admin', 'password' => 'a-very-long-password']);
check('6th login after 5 wrong passwords is blocked (429)', $r['code'] === 429, (string) $r['code']);
check('database file not reachable from the web root', req('GET', '/../data/bookings.sqlite')['code'] === 404);

proc_terminate($proc);
proc_terminate($tg);
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
