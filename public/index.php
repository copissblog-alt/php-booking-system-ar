<?php
declare(strict_types=1);

// خادم PHP المدمج (للتجربة المحلية): الملفات الموجودة تُخدم كما هي
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__ . '/../src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

security_headers();

// ملف التقويم: بلا جلسة
if ($method === 'GET' && preg_match('#^/b/([a-f0-9]{32})\.ics$#', $path, $m)) {
    $b = find_booking($m[1]) ?? not_found();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="booking-' . $b['ref'] . '.ics"');
    echo booking_ics($b);
    exit;
}

start_session();

switch (true) {
    // ===== صفحات العميل =====
    case $path === '/' && $method === 'GET':
        $services = all_services(true);
        $service  = isset($_GET['s']) ? find_service((int) $_GET['s']) : (count($services) === 1 ? $services[0] : null);
        if (!$service) {
            render('home', ['title' => setting('business_name'), 'services' => $services]);
            exit;
        }
        $t = (int) ($_GET['t'] ?? 0);
        if ($t > 0 && slot_is_free($service, $t)) {
            render('form', ['title' => 'تأكيد الحجز', 'service' => $service, 't' => $t, 'error' => null, 'old' => []]);
            exit;
        }
        $days = bookable_days($service);
        $day  = (string) ($_GET['d'] ?? ($t > 0 ? day_of($t) : ''));
        if (!in_array($day, array_column($days, 'day'), true)) {
            $open = array_values(array_filter($days, fn($d) => $d['n'] > 0));
            $day  = $open[0]['day'] ?? $days[0]['day'];
        }
        render('slots', [
            'title'   => $service['name'],
            'service' => $service,
            'single'  => count($services) === 1,
            'days'    => $days,
            'day'     => $day,
            'slots'   => day_slots($service, $day),
            'error'   => $t > 0 ? 'هذا الوقت لم يعد متاحاً. اختر وقتاً آخر.' : null,
        ]);
        exit;

    case $path === '/book' && $method === 'POST':
        csrf_check();
        $service = find_service((int) ($_POST['s'] ?? 0)) ?? not_found();
        $t = (int) ($_POST['t'] ?? 0);
        [$b, $error, $taken] = create_booking($service, $t, $_POST);
        if ($b) {
            header('Location: /b/' . $b['token'] . '?new=1', true, 303);
            finish_response(); // العميل يرى صفحة التأكيد، والتنبيه يُرسل بعدها
            notify_owner(booking_message($b, '🗓 حجز جديد'));
            exit;
        }
        if ($taken) {
            redirect_to('/?s=' . $service['id'] . '&t=' . $t);
        }
        render('form', ['title' => 'تأكيد الحجز', 'service' => $service, 't' => $t, 'error' => $error, 'old' => $_POST]);
        exit;

    case (bool) preg_match('#^/b/([a-f0-9]{32})$#', $path, $m) && $method === 'GET':
        $b = find_booking($m[1]) ?? not_found();
        header('Cache-Control: private, no-store');
        render('booking', ['title' => 'موعدك ' . $b['ref'], 'b' => $b, 'new' => isset($_GET['new']), 'flash' => flash()]);
        exit;

    case (bool) preg_match('#^/b/([a-f0-9]{32})/cancel$#', $path, $m) && $method === 'POST':
        csrf_check();
        $b = find_booking($m[1]) ?? not_found();
        if (cancel_booking($b)) {
            flash(['ok', 'أُلغي موعدك. يمكنك حجز موعد جديد في أي وقت.']);
            header('Location: /b/' . $b['token'], true, 303);
            finish_response();
            notify_owner(booking_message($b, '❌ ألغى العميل موعده'));
            exit;
        }
        flash(['bad', 'لا يمكن الإلغاء الآن. تواصل معنا مباشرة.']);
        redirect_to('/b/' . $b['token']);

    // ===== الإعداد والدخول =====
    case $path === '/setup':
        if (has_admin()) {
            not_found(); // الإعداد يُغلق نهائياً بعد إنشاء المدير
        }
        $error = null;
        if ($method === 'POST') {
            csrf_check();
            $token = (string) ($_POST['token'] ?? '');
            $user  = trim((string) ($_POST['username'] ?? ''));
            $pass  = (string) ($_POST['password'] ?? '');
            if (cfg('setup_token') === null || str_starts_with((string) cfg('setup_token'), 'CHANGE-ME') || !hash_equals((string) cfg('setup_token'), $token)) {
                $error = 'رمز الإعداد غير صحيح. غيّر setup_token في config.php واكتبه هنا.';
            } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $user)) {
                $error = 'اسم المستخدم من 3 إلى 32 حرفاً إنجليزياً أو رقماً.';
            } elseif (strlen($pass) < 12) {
                $error = 'كلمة المرور 12 حرفاً على الأقل.';
            } else {
                create_admin($user, $pass);
                attempt_login($user, $pass);
                redirect_to('/admin/hours');
            }
        }
        render('setup', ['title' => 'الإعداد الأول', 'error' => $error]);
        exit;

    case $path === '/admin/login':
        if (!has_admin()) {
            redirect_to('/setup');
        }
        $error = null;
        if ($method === 'POST') {
            csrf_check();
            if (login_blocked()) {
                http_response_code(429);
                $error = 'محاولات خاطئة كثيرة. انتظر 15 دقيقة.';
            } elseif (attempt_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
                redirect_to('/admin');
            } else {
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
            }
        }
        render('login', ['title' => 'دخول', 'error' => $error]);
        exit;

    case $path === '/admin/logout' && $method === 'POST':
        csrf_check();
        $_SESSION = [];
        session_destroy();
        redirect_to('/admin/login');

    // ===== لوحة المدير =====
    case $path === '/admin':
        $user = require_login();
        $tab  = in_array($_GET['tab'] ?? '', ['today', 'upcoming', 'past', 'cancelled'], true) ? $_GET['tab'] : 'upcoming';
        $q    = (string) ($_GET['q'] ?? '');
        render('admin/bookings', ['title' => 'المواعيد', 'user' => $user, 'tab' => $tab, 'q' => $q,
            'rows' => list_bookings($tab, $q), 'counts' => tab_counts(), 'flash' => flash(), 'nav' => 'bookings']);
        exit;

    case $path === '/admin/status' && $method === 'POST':
        require_login();
        csrf_check();
        set_status((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
        $back = (string) ($_POST['back'] ?? '');
        redirect_to('/admin' . (preg_match('/^\?[\w=&%+.-]*$/', $back) ? $back : ''));

    case $path === '/admin/export.csv':
        require_login();
        export_bookings_csv();

    case $path === '/admin/services':
        $user = require_login();
        if ($method === 'POST') {
            csrf_check();
            $err = save_service($_POST, (int) ($_POST['id'] ?? 0));
            flash($err ? ['bad', $err] : ['ok', 'حُفظت الخدمة.']);
            redirect_to('/admin/services');
        }
        render('admin/services', ['title' => 'الخدمات', 'user' => $user, 'services' => all_services(), 'flash' => flash(), 'nav' => 'services']);
        exit;

    case in_array($path, ['/admin/services/toggle', '/admin/services/delete'], true) && $method === 'POST':
        require_login();
        csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        if ($path === '/admin/services/delete') {
            flash(delete_service($id) ? ['ok', 'حُذفت الخدمة.'] : ['ok', 'للخدمة حجوزات سابقة، فأوقفناها بدل حذفها ليبقى السجل سليماً.']);
        } else {
            set_service_active($id, ($_POST['on'] ?? '') === '1');
        }
        redirect_to('/admin/services');

    case $path === '/admin/hours':
        $user = require_login();
        if ($method === 'POST') {
            csrf_check();
            $err = save_week_hours($_POST);
            flash($err ? ['bad', $err] : ['ok', 'حُفظ جدول الدوام.']);
            redirect_to('/admin/hours');
        }
        render('admin/hours', ['title' => 'الدوام', 'user' => $user, 'week' => week_hours(), 'closed' => closed_days(), 'flash' => flash(), 'nav' => 'hours']);
        exit;

    case in_array($path, ['/admin/closed/add', '/admin/closed/delete'], true) && $method === 'POST':
        require_login();
        csrf_check();
        if ($path === '/admin/closed/add') {
            $err = add_closed_day((string) ($_POST['day'] ?? ''), (string) ($_POST['reason'] ?? ''));
            flash($err ? ['bad', $err] : ['ok', 'أُضيف يوم الإغلاق. المواعيد المحجوزة فيه سابقاً تبقى، راجعها من قائمة المواعيد.']);
        } else {
            delete_closed_day((string) ($_POST['day'] ?? ''));
        }
        redirect_to('/admin/hours');

    case $path === '/admin/settings':
        $user = require_login();
        if ($method === 'POST') {
            csrf_check();
            $err = save_settings_form($_POST);
            flash($err ? ['bad', $err] : ['ok', 'حُفظت الإعدادات.']);
            redirect_to('/admin/settings');
        }
        render('admin/settings', ['title' => 'الإعدادات', 'user' => $user, 'flash' => flash(), 'nav' => 'settings']);
        exit;

    default:
        not_found();
}

/** يتحقق من نموذج الإعدادات ثم يحفظه */
function save_settings_form(array $in): ?string
{
    $ranges = [
        'slot_step'     => [5, 240, 'بداية المواعيد كل'],
        'buffer'        => [0, 120, 'الراحة بين موعدين'],
        'min_notice'    => [0, 168, 'أقل مدة قبل الموعد'],
        'max_days'      => [1, 90, 'أبعد يوم للحجز'],
        'cancel_notice' => [0, 168, 'مهلة الإلغاء'],
        'max_per_phone' => [1, 20, 'المواعيد لكل رقم'],
        'ip_limit'      => [1, 100, 'الحجوزات في الساعة من الجهاز نفسه'],
    ];
    $vals = [];
    foreach ($ranges as $k => [$min, $max, $label]) {
        $v = latin_digits(trim((string) ($in[$k] ?? '')));
        if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
            return "«{$label}» رقم من $min إلى $max.";
        }
        $vals[$k] = (int) $v;
    }
    $name  = trim((string) ($in['business_name'] ?? ''));
    $intro = trim((string) ($in['intro'] ?? ''));
    $tel   = trim((string) ($in['contact_phone'] ?? ''));
    if ($name === '' || str_len($name) > 80) {
        return 'اسم النشاط مطلوب، وحتى 80 حرفاً.';
    }
    if (str_len($intro) > 300) {
        return 'النبذة حتى 300 حرف.';
    }
    if ($tel !== '' && normalize_phone($tel) === null) {
        return 'رقم التواصل غير صحيح.';
    }
    save_settings($vals + ['business_name' => $name, 'intro' => $intro, 'contact_phone' => $tel === '' ? '' : normalize_phone($tel)]);
    return null;
}
