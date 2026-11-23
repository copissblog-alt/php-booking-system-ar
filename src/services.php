<?php
declare(strict_types=1);

const DURATIONS = [15, 20, 30, 45, 60, 90, 120, 180, 240];

function all_services(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM services' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id';
    return db()->query($sql)->fetchAll();
}

function find_service(int $id, bool $activeOnly = true): ?array
{
    $st = db()->prepare('SELECT * FROM services WHERE id = ?' . ($activeOnly ? ' AND active = 1' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** يرجع رسالة خطأ أو null عند النجاح */
function save_service(array $in, int $id = 0): ?string
{
    $name = trim((string) ($in['name'] ?? ''));
    $dur  = (int) ($in['duration'] ?? 0);
    $price = trim(latin_digits((string) ($in['price'] ?? '')));
    $desc = trim((string) ($in['description'] ?? ''));
    if ($name === '' || str_len($name) > 80) {
        return 'اسم الخدمة مطلوب، وحتى 80 حرفاً.';
    }
    if (!in_array($dur, DURATIONS, true)) {
        return 'اختر مدة الخدمة من القائمة.';
    }
    if ($price !== '' && !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $price)) {
        return 'السعر رقم فقط، مثل 150 أو 99.50، أو اتركه فارغاً.';
    }
    if (str_len($desc) > 300) {
        return 'الوصف حتى 300 حرف.';
    }
    $vals = [$name, $dur, $price, $desc, (int) ($in['sort'] ?? 0)];
    if ($id > 0) {
        db()->prepare('UPDATE services SET name = ?, duration = ?, price = ?, description = ?, sort = ? WHERE id = ?')->execute([...$vals, $id]);
    } else {
        db()->prepare('INSERT INTO services (name, duration, price, description, sort) VALUES (?, ?, ?, ?, ?)')->execute($vals);
    }
    return null;
}

function set_service_active(int $id, bool $on): void
{
    db()->prepare('UPDATE services SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
}

/** الحذف للخدمة التي لم تُحجز أبداً. ما له حجوزات يُوقف فقط، فيبقى سجل الحجوزات سليماً */
function delete_service(int $id): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM bookings WHERE service_id = ?');
    $st->execute([$id]);
    if ((int) $st->fetchColumn() > 0) {
        set_service_active($id, false);
        return false;
    }
    db()->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    return true;
}
