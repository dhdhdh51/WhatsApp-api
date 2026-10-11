<?php
declare(strict_types=1);

namespace App;

/**
 * Runs every minute from cron. Each job runs at most once per day (scheduler_runs table).
 *  - form at form_time, reminders at reminder_times (last one = final reminder) → only pending BCs
 *  - daily summary to Admin / Managers at summary_time
 *  - escalation (BCs missing N days in a row) at escalation_time
 *  - nightly housekeeping
 */
final class Scheduler
{
    public function run(): void
    {
        $today = Calendar::today();
        $now = date('H:i');
        Queue::housekeeping();

        // ---- form + reminders
        $slots = [['job' => 'form', 'time' => Settings::padTime(Settings::get('form_time') ?: '18:00'), 'tpl' => 'form']];
        $rem = Settings::reminderTimes();
        foreach ($rem as $i => $t) {
            $slots[] = ['job' => 'reminder_' . ($i + 1), 'time' => $t, 'tpl' => $i === count($rem) - 1 ? 'final_reminder' : 'reminder'];
        }
        usort($slots, static fn ($a, $b) => strcmp($a['time'], $b['time']));

        $due = array_values(array_filter($slots, static fn ($s) => $s['time'] <= $now));
        foreach ($due as $i => $slot) {
            if (self::alreadyRan($today, $slot['job'])) {
                continue;
            }
            $isLatest = $i === count($due) - 1;
            if (!$isLatest) {
                self::markRun($today, $slot['job'], 'skipped', 'a later slot was already due');
                continue;
            }
            if (time() > Calendar::cutoffTs($today)) {
                self::markRun($today, $slot['job'], 'skipped', 'after cutoff');
                continue;
            }
            if (!self::markRun($today, $slot['job'], 'running', '')) {
                continue; // another process got it
            }
            $n = $this->enqueueBcMessages($today, $slot['tpl'], $slot['job']);
            self::finishRun($today, $slot['job'], 'done', "queued {$n}");
            Logger::info("Scheduler {$slot['job']}: queued {$n} messages");
        }

        // ---- daily summary
        $sumTime = Settings::padTime(Settings::get('summary_time') ?: '21:00');
        if (Settings::bool('send_summary') && $sumTime <= $now && self::markRun($today, 'summary', 'running', '')) {
            $n = Summary::enqueueDaily($today);
            self::finishRun($today, 'summary', 'done', "queued {$n}");
        }

        // ---- escalation
        $escTime = Settings::padTime(Settings::get('escalation_time') ?: '10:00');
        if (Settings::bool('send_escalation') && $escTime <= $now && self::markRun($today, 'escalation', 'running', '')) {
            $n = Summary::enqueueEscalation($today);
            self::finishRun($today, 'escalation', 'done', "queued {$n}");
        }

        // ---- housekeeping (once a day, after 02:00)
        if ($now >= '02:00' && self::markRun($today, 'cleanup', 'running', '')) {
            DB::exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
            DB::exec("DELETE FROM messages WHERE status = 'ignored' AND created_at < (NOW() - INTERVAL 30 DAY)");
            DB::exec('DELETE FROM pending_confirmations WHERE expires_at < NOW()');
            DB::exec('DELETE FROM scheduler_runs WHERE run_date < (CURDATE() - INTERVAL 90 DAY)');
            self::finishRun($today, 'cleanup', 'done', '');
        }
    }

    /** Queue the form/reminder for every BC still pending today. */
    public function enqueueBcMessages(string $date, string $tpl, string $job): int
    {
        $n = 0;
        foreach (Calendar::pendingForMessaging($date) as $bc) {
            if (Queue::hasPendingFor((int) $bc['id'], $date)) {
                continue; // previous form/reminder not sent yet (queue backlog) – don't stack messages
            }
            $body = Templates::build($tpl, $bc, $date);
            $kind = $tpl === 'form' ? 'form' : $tpl;
            $n += Queue::enqueue((int) $bc['id'], Queue::chatIdFor($bc), $body, $kind, $date, $tpl === 'form' ? 5 : 6,
                "{$job}:{$bc['id']}:{$date}") > 0 ? 1 : 0;
        }
        return $n;
    }

    public static function alreadyRan(string $date, string $job): bool
    {
        return (bool) DB::val('SELECT COUNT(*) FROM scheduler_runs WHERE run_date = ? AND job = ?', [$date, $job]);
    }

    /** Atomically claims a job for today. Returns false when it already ran. */
    public static function markRun(string $date, string $job, string $status, string $info): bool
    {
        return DB::exec(
            'INSERT IGNORE INTO scheduler_runs (run_date, job, status, info, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$date, $job, $status, $info]
        ) > 0;
    }

    public static function finishRun(string $date, string $job, string $status, string $info): void
    {
        DB::exec('UPDATE scheduler_runs SET status = ?, info = ? WHERE run_date = ? AND job = ?', [$status, $info, $date, $job]);
    }
}
