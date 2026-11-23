<?php
declare(strict_types=1);

/**
 * يرسل الرد للمتصفح فوراً، ويكمل PHP ما بعده في الخلفية.
 * يعمل مع PHP-FPM (Nginx) ومع LiteSpeed (أغلب الاستضافات المشتركة).
 */
function finish_response(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

/** تنبيه صاحب النشاط على تيليجرام (اختياري). الفشل لا يوقف شيئاً */
function notify_owner(string $text): void
{
    $token = (string) cfg('telegram_token');
    $chat  = (string) cfg('telegram_chat_id');
    if ($token === '' || $chat === '') {
        return;
    }
    $url  = rtrim((string) (cfg('telegram_api') ?: 'https://api.telegram.org'), '/') . '/bot' . $token . '/sendMessage';
    $body = http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']);
    try {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false || !str_contains($res, '"ok":true')) {
            error_log('booking: telegram notify failed');
        }
    } catch (Throwable $e) {
        error_log('booking: telegram notify failed: ' . $e->getMessage());
    }
}

function booking_message(array $b, string $head): string
{
    return $head . "\n"
        . $b['service_name'] . "\n"
        . ar_date(day_of((int) $b['start_ts'])) . ' — ' . ar_time((int) $b['start_ts']) . "\n"
        . $b['name'] . ' · ' . show_phone($b['phone']) . "\n"
        . 'رقم الحجز: ' . $b['ref']
        . ($b['note'] !== '' ? "\nملاحظة: " . $b['note'] : '');
}
