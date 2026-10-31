<?php
/**
 * Branch timezone handling.
 *
 * The database runs in UTC and every timestamp column is "timestamp without time zone",
 * so a value like "2026-10-20 19:55:00" means 19:55 UTC (03:55 the next day in Manila).
 * PHP itself runs in Manila time (set below), so date(), "today" and "this month" follow
 * the branch's calendar. Any DB timestamp must therefore be read as UTC before it is
 * formatted or compared — use the helpers below instead of date(..., strtotime($dbValue)).
 *
 * Loaded by backend/config/database.php, so every page that talks to the database has it.
 */

if (!defined('BRANCH_TIMEZONE')) {
    define('BRANCH_TIMEZONE', 'Asia/Manila');
}

date_default_timezone_set(BRANCH_TIMEZONE);

if (!function_exists('utc_datetime_to_timestamp')) {
    /** DB timestamp (UTC wall-clock) -> Unix timestamp, or null when empty/unparseable. */
    function utc_datetime_to_timestamp(?string $utc): ?int
    {
        if ($utc === null || trim($utc) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('format_utc_datetime')) {
    /** DB timestamp (UTC wall-clock) -> branch-local display string. Returns $fallback when empty/unparseable. */
    function format_utc_datetime(?string $utc, string $format = 'M j, Y g:i A', string $fallback = ''): string
    {
        $ts = utc_datetime_to_timestamp($utc);
        if ($ts === null) {
            return $fallback;
        }
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(BRANCH_TIMEZONE))->format($format);
    }
}

if (!function_exists('utc_datetime_local_date')) {
    /** DB timestamp (UTC wall-clock) -> branch-local calendar date 'Y-m-d' (for grouping by day). */
    function utc_datetime_local_date(?string $utc): string
    {
        return format_utc_datetime($utc, 'Y-m-d');
    }
}

if (!function_exists('local_day_range_to_utc')) {
    /**
     * Branch-local calendar days [$from .. $to] (inclusive) -> [start, end) as UTC strings
     * for filtering DB timestamp columns:  col >= start AND col < end.
     */
    function local_day_range_to_utc(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $tz = new DateTimeZone(BRANCH_TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $tz);
        $end = (new DateTimeImmutable($to->format('Y-m-d') . ' 00:00:00', $tz))->modify('+1 day');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('sql_utc_to_local')) {
    /**
     * SQL expression turning a UTC "timestamp without time zone" column into branch-local wall-clock time,
     * for DATE(), date_trunc() and to_char() in SQL. $column must be a trusted identifier, never user input.
     */
    function sql_utc_to_local(string $column): string
    {
        return "(({$column}) AT TIME ZONE 'UTC' AT TIME ZONE '" . BRANCH_TIMEZONE . "')";
    }
}

if (!function_exists('sql_local_now')) {
    /** SQL expression for the current branch-local wall-clock time (use instead of CURRENT_DATE / NOW() for calendar logic). */
    function sql_local_now(): string
    {
        return "(NOW() AT TIME ZONE '" . BRANCH_TIMEZONE . "')";
    }
}
