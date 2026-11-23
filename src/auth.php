<?php
declare(strict_types=1);

const LOGIN_MAX_FAILS = 5;      // محاولات خاطئة
const LOGIN_WINDOW    = 900;    // خلال 15 دقيقة

/** عدد مرات حدث معين (دخول خاطئ، حجز) من الجهاز نفسه خلال نافذة زمنية */
function hits(string $kind, int $window): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM attempts WHERE kind = ? AND ip_hash = ? AND ts > ?');
    $st->execute([$kind, ip_hash(), now() - $window]);
    return (int) $st->fetchColumn();
}

function add_hit(string $kind): void
{
    db()->prepare('INSERT INTO attempts (kind, ip_hash, ts) VALUES (?, ?, ?)')->execute([$kind, ip_hash(), now()]);
    db()->prepare('DELETE FROM attempts WHERE ts < ?')->execute([now() - 86400]);
}

function has_admin(): bool
{
    return (bool) db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare('SELECT id, username FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect_to('/admin/login');
    }
    return $u;
}

function login_blocked(): bool
{
    return hits('login', LOGIN_WINDOW) >= LOGIN_MAX_FAILS;
}

function attempt_login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    // نتحقق دائماً حتى لو لم يوجد المستخدم، فلا يكشف زمن الرد وجود الاسم
    $hash = $u['password_hash'] ?? '$2y$10$CGc.vdlWIZbplv2jMFHY5ucZs6nWS/5XDUgtpqUx3zDcs2N3.IQfS';
    $ok   = password_verify($password, $hash) && $u;
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        db()->prepare("DELETE FROM attempts WHERE kind = 'login' AND ip_hash = ?")->execute([ip_hash()]);
        return true;
    }
    add_hit('login');
    return false;
}

function create_admin(string $username, string $password): void
{
    db()->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
}
