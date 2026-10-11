<?php
declare(strict_types=1);

namespace App;

/**
 * Morning WhatsApp message with the BC's monthly target and progress.
 * Modes (setting target_msg_mode):
 *   on_change – next morning after the Admin sets / changes the target (default)
 *   daily     – every working morning to every BC that has a target
 */
final class Targets
{
    /** Queue target messages for today. Returns number queued. */
    public static function enqueueMorning(string $date, bool $force = false, ?int $userId = null): int
    {
        if (Settings::get('target_msg_mode') === 'daily_perf') {
            return self::enqueueDailyPerf($date, $force, $userId);
        }
        $month = date('Y-m-01', strtotime($date));
        $daily = Settings::get('target_msg_mode') === 'daily';

        // BCs expected to work today (active, no leave / holiday / weekly off, no STOP)
        $rows = array_filter(Calendar::dayRows($date, null),
            static fn ($r) => in_array($r['status'], ['pending', 'submitted'], true) && (int) $r['opted_out'] === 0);
        if (!$rows) {
            return 0;
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $params = [$month];
        $targets = [];
        foreach (DB::all('SELECT * FROM targets WHERE month = ? AND bc_id IN (' . DB::in($ids, $params) . ')', $params) as $t) {
            $targets[(int) $t['bc_id']] = $t;
        }

        $params = [$month, $date];
        $achieved = [];
        foreach (DB::all('SELECT bc_id, ' . implode(', ', array_map(static fn ($k) => "SUM({$k}) {$k}", ReportParser::SCHEMES))
            . ' FROM reports WHERE report_date >= ? AND report_date < ? AND bc_id IN (' . DB::in($ids, $params) . ') GROUP BY bc_id', $params) as $a) {
            $achieved[(int) $a['bc_id']] = $a;
        }

        $n = 0;
        foreach ($rows as $bc) {
            $t = $targets[(int) $bc['id']] ?? null;
            if (!$t) {
                continue;
            }
            $hasTarget = false;
            foreach (ReportParser::SCHEMES as $k) {
                $hasTarget = $hasTarget || (int) $t["{$k}_target"] > 0;
            }
            $changed = !$t['notified_at'] || strtotime((string) $t['updated_at']) > strtotime((string) $t['notified_at']);
            if (!$hasTarget) {
                if ($changed) {
                    DB::exec('UPDATE targets SET notified_at = NOW() WHERE id = ?', [$t['id']]); // all zero – nothing to tell
                }
                continue;
            }
            if (!$force && !$daily && !$changed) {
                continue;
            }
            $body = Templates::build('target', $bc, $date, self::vars($t, $achieved[(int) $bc['id']] ?? [], $date));
            if (Queue::enqueue((int) $bc['id'], Queue::chatIdFor($bc), $body, 'target', $date, 5,
                "target:{$bc['id']}:{$date}" . ($force ? ':' . date('His') : ''), $userId) > 0) {
                DB::exec('UPDATE targets SET notified_at = NOW() WHERE id = ?', [$t['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** Template variables: {month}, {target_lines}, {days_left}. */
    public static function vars(array $t, array $a, string $date): array
    {
        $lines = [];
        foreach (ReportParser::LABELS as $k => $label) {
            $target = (int) $t["{$k}_target"];
            if ($target <= 0) {
                continue;
            }
            $done = (int) ($a[$k] ?? 0);
            $pct = (int) round(100 * $done / $target);
            $lines[] = "{$label}: {$done} / {$target} ({$pct}%)" . ($done >= $target ? ' ✅' : '');
        }
        $daysLeft = (int) date('t', strtotime($date)) - (int) date('j', strtotime($date)) + 1;
        return [
            'month' => date('M Y', strtotime($date)),
            'target_lines' => implode("\n", $lines),
            'days_left' => $daysLeft,
        ];
    }

    public const DAILY = ['apy', 'pmjjby', 'pmsby', 'pmjdy'];

    /** Today's target for a BC: per-BC override (targets.d_*) or the default from Settings. */
    public static function dailyTarget(?array $t): array
    {
        $out = [];
        foreach (self::DAILY as $k) {
            $v = $t["d_{$k}"] ?? null;
            $out[$k] = $v !== null ? (int) $v : max(0, Settings::int("daily_target_{$k}"));
        }
        return $out;
    }

    /**
     * Every working morning: yesterday's numbers + today's target to every expected BC
     * (template 'morning'). Yesterday without a report shows 00.
     */
    public static function enqueueDailyPerf(string $date, bool $force = false, ?int $userId = null): int
    {
        $rows = array_filter(Calendar::dayRows($date, null),
            static fn ($r) => in_array($r['status'], ['pending', 'submitted'], true) && (int) $r['opted_out'] === 0);
        if (!$rows) {
            return 0;
        }
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);

        $params = [$yesterday];
        $rep = [];
        foreach (DB::all('SELECT * FROM reports WHERE report_date = ? AND bc_id IN (' . DB::in($ids, $params) . ')', $params) as $r) {
            $rep[(int) $r['bc_id']] = $r;
        }
        $params = [date('Y-m-01', strtotime($date))];
        $tg = [];
        foreach (DB::all('SELECT * FROM targets WHERE month = ? AND bc_id IN (' . DB::in($ids, $params) . ')', $params) as $t) {
            $tg[(int) $t['bc_id']] = $t;
        }

        $n = 0;
        foreach ($rows as $bc) {
            $id = (int) $bc['id'];
            $vars = ['yesterday' => date('d-m-Y', strtotime($yesterday))];
            foreach (ReportParser::SCHEMES as $k) {
                $vars["y_{$k}"] = sprintf('%02d', (int) ($rep[$id][$k] ?? 0));
            }
            foreach (self::dailyTarget($tg[$id] ?? null) as $k => $v) {
                $vars["t_{$k}"] = sprintf('%02d', $v);
            }
            $body = Templates::build('morning', $bc, $date, $vars);
            $n += Queue::enqueue($id, Queue::chatIdFor($bc), $body, 'morning', $date, 5,
                "morning:{$id}:{$date}" . ($force ? ':' . date('His') : ''), $userId) > 0 ? 1 : 0;
        }
        return $n;
    }
}
