<?php
declare(strict_types=1);

namespace App;

/** Key/value settings stored in the `settings` table, with code defaults. */
final class Settings
{
    public const DEFAULTS = [
        'form_time'                  => '18:00',
        'reminder_times'             => '20:00',
        'cutoff_time'                => '23:59',
        'summary_time'               => '21:00',
        'escalation_time'            => '10:00',
        'escalation_days'            => '2',
        'send_summary'               => '1',
        'send_escalation'            => '1',
        'admin_phones'               => '',
        'weekly_off_days'            => '',
        'daily_limit'                => '300',
        'warmup_enabled'             => '1',
        'warmup_start_date'          => '',
        'warmup_days'                => '7',
        'warmup_daily_limit'         => '40',
        'min_delay_sec'              => '8',
        'max_delay_sec'              => '20',
        'typing_min_sec'             => '1',
        'typing_max_sec'             => '3',
        'max_retries'                => '2',
        'max_value'                  => '500',
        'high_value_threshold'       => '50',
        'unknown_reply_cooldown_min' => '10',
        'queue_paused'               => '0',
        // morning target message to BCs
        'send_target_msg'            => '1',
        'target_msg_time'            => '09:00',
        'target_msg_mode'            => 'daily_perf',
        'daily_target_apy'           => '5',
        'daily_target_pmjjby'        => '5',
        'daily_target_pmsby'         => '10',
        'daily_target_pmjdy'         => '5',
        // people who are not in the BC team
        'unknown_auto_reply'         => '1',
        'unknown_reply_hours'        => '24',
        'unknown_reply_text'         => "🙏 Namaste! Ye number *BC Daily Report* team ke liye hai, aur aapka number hamari team mein registered nahi hai.\n\nAapko jo bhi baat kehni hai, yahin likh kar bhej dein – Admin aapka message dekh kar sampark karenge. Dhanyavad 🙏",
        // runtime state (written by the system)
        'wa_status'                  => 'UNKNOWN',
        'wa_status_at'               => '',
        'queue_pause_reason'         => '',
        'last_sent_at'               => '',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = DB::all('SELECT `key`, `value` FROM settings');
            $vals = self::DEFAULTS;
            foreach ($rows as $r) {
                $vals[$r['key']] = (string) $r['value'];
            }
            self::$cache = $vals;
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string
    {
        return self::all()[$key] ?? ($default ?? '');
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function set(string $key, string $value): void
    {
        DB::exec(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            [$key, $value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function refresh(): void
    {
        self::$cache = null;
    }

    /** Sorted list of HH:MM reminder times. */
    public static function reminderTimes(): array
    {
        $out = [];
        foreach (explode(',', self::get('reminder_times')) as $t) {
            $t = trim($t);
            if (self::validTime($t)) {
                $out[] = self::padTime($t);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** @return list<string> 10-digit admin phone numbers */
    public static function adminPhones(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', self::get('admin_phones')) ?: [] as $p) {
            $n = Phone::normalize($p);
            if ($n) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<int> 0 = Sunday … 6 = Saturday */
    public static function weeklyOffDays(): array
    {
        $out = [];
        foreach (explode(',', self::get('weekly_off_days')) as $d) {
            $d = trim($d);
            if ($d !== '' && ctype_digit($d) && (int) $d <= 6) {
                $out[] = (int) $d;
            }
        }
        return $out;
    }

    public static function validTime(string $t): bool
    {
        return (bool) preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($t));
    }

    public static function padTime(string $t): string
    {
        [$h, $m] = explode(':', trim($t));
        return sprintf('%02d:%02d', (int) $h, (int) $m);
    }

    /** Effective daily limit for scheduled messages (warm-up aware). */
    public static function effectiveDailyLimit(): int
    {
        if (self::isWarmup()) {
            return max(1, self::int('warmup_daily_limit'));
        }
        return max(1, self::int('daily_limit'));
    }

    public static function isWarmup(): bool
    {
        if (!self::bool('warmup_enabled')) {
            return false;
        }
        $start = self::get('warmup_start_date');
        if ($start === '') {
            return true; // not connected yet -> be careful
        }
        $end = date('Y-m-d', strtotime($start . ' +' . max(0, self::int('warmup_days')) . ' days'));
        return date('Y-m-d') < $end;
    }
}
