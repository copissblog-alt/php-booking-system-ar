<?php
declare(strict_types=1);

function migrate(PDO $pdo): void
{
    $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= 1) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at    INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS attempts (
            kind    TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            ts      INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS attempts_ip ON attempts(kind, ip_hash, ts);
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS services (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL,
            duration    INTEGER NOT NULL,
            price       TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            active      INTEGER NOT NULL DEFAULT 1,
            sort        INTEGER NOT NULL DEFAULT 0
        );
        -- فترات العمل: يمكن أن يكون لليوم فترتان (صباحية ومسائية)
        CREATE TABLE IF NOT EXISTS hours (
            weekday INTEGER NOT NULL,          -- 0 الأحد ... 6 السبت
            start   TEXT NOT NULL,             -- 09:00
            "end"   TEXT NOT NULL              -- 13:00
        );
        CREATE TABLE IF NOT EXISTS closed_days (
            day    TEXT PRIMARY KEY,           -- 2026-12-02
            reason TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS bookings (
            id           INTEGER PRIMARY KEY,
            ref          TEXT NOT NULL UNIQUE,
            token        TEXT NOT NULL UNIQUE,
            service_id   INTEGER NOT NULL REFERENCES services(id),
            service_name TEXT NOT NULL,
            start_ts     INTEGER NOT NULL,
            end_ts       INTEGER NOT NULL,
            name         TEXT NOT NULL,
            phone        TEXT NOT NULL,
            note         TEXT NOT NULL DEFAULT '',
            status       TEXT NOT NULL DEFAULT 'confirmed',  -- confirmed | done | no_show | cancelled
            ip_hash      TEXT NOT NULL DEFAULT '',
            created_at   INTEGER NOT NULL,
            cancelled_at INTEGER
        );
        CREATE INDEX IF NOT EXISTS bookings_time  ON bookings(start_ts, end_ts);
        CREATE INDEX IF NOT EXISTS bookings_phone ON bookings(phone, start_ts);
        -- خط دفاع أخير: لا موعدان نشطان يبدآن في الدقيقة نفسها
        CREATE UNIQUE INDEX IF NOT EXISTS bookings_one_start ON bookings(start_ts) WHERE status <> 'cancelled';
        PRAGMA user_version = 1;
    SQL);
}
