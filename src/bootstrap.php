<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

$cfgFile = getenv('BK_CONFIG') ?: APP_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('config.php غير موجود: انسخ config.sample.php إلى config.php وعدّله.');
}
$CONFIG = require $cfgFile;
date_default_timezone_set((string) ($CONFIG['timezone'] ?? 'Asia/Riyadh'));

function cfg(string $key)
{
    global $CONFIG;
    return $CONFIG[$key] ?? null;
}

/** الوقت الحالي. يمكن تثبيته في config.php للاختبارات فقط */
function now(): int
{
    return (int) (cfg('now') ?? time());
}

function tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(date_default_timezone_get());
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . cfg('db_path'), null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: القراءة لا تنتظر الكتابة، والكتابات المتزامنة تنتظر دورها حتى 5 ثوانٍ بدل أن تفشل
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON; PRAGMA synchronous = NORMAL;');
    require_once __DIR__ . '/schema.php';
    migrate($pdo);
    return $pdo;
}

/** إعدادات النشاط المحفوظة في القاعدة (تُعدَّل من لوحة المدير) */
const SETTING_DEFAULTS = [
    'business_name' => 'احجز موعدك',
    'intro'         => 'اختر الخدمة ثم اليوم والوقت المناسب لك.',
    'contact_phone' => '',
    'slot_step'     => '30',  // كل كم دقيقة يبدأ موعد
    'buffer'        => '0',   // دقائق راحة بين موعدين
    'min_notice'    => '2',   // أقل مدة قبل الموعد بالساعات
    'max_days'      => '14',  // أبعد يوم يمكن حجزه
    'cancel_notice' => '3',   // يلغي العميل بنفسه حتى كم ساعة قبل الموعد
    'max_per_phone' => '2',   // مواعيد قادمة نشطة لكل رقم
    'ip_limit'      => '6',   // حجوزات في الساعة من الجهاز نفسه
];

function setting(string $key): string
{
    static $cache = null;
    if ($cache === null || $key === '__reload') {
        $cache = SETTING_DEFAULTS;
        foreach (db()->query('SELECT key, value FROM settings') as $r) {
            $cache[$r['key']] = $r['value'];
        }
        if ($key === '__reload') {
            return '';
        }
    }
    return (string) ($cache[$key] ?? '');
}

function setting_int(string $key): int
{
    return (int) setting($key);
}

function save_settings(array $values): void
{
    $st = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ($values as $k => $v) {
        if (array_key_exists($k, SETTING_DEFAULTS)) {
            $st->execute([$k, (string) $v]);
        }
    }
    setting('__reload');
}

/** هروب النص قبل طباعته في HTML */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('bk_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'");
}

/** تمويه عنوان IP: نفس العنوان يعطي نفس القيمة، ولا يمكن استرجاعه منها */
function ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash_hmac('sha256', $ip, (string) cfg('secret')), 0, 16);
}

function redirect_to(string $path): void
{
    header('Location: ' . $path, true, 303);
    exit;
}

function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require APP_ROOT . '/views/layout.php';
}

function not_found(): void
{
    http_response_code(404);
    render('404', ['title' => 'غير موجود']);
    exit;
}

function flash(?array $msg = null): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function base_url(string $path = ''): string
{
    return rtrim((string) cfg('base_url'), '/') . $path;
}

require_once __DIR__ . '/text.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/slots.php';
require_once __DIR__ . '/bookings.php';
require_once __DIR__ . '/notify.php';
