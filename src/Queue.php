<?php
declare(strict_types=1);

namespace App;

/** Outgoing message queue (scheduled forms, reminders, summaries, escalations). */
final class Queue
{
    /** Kinds that need the BC to still be pending when the message is actually sent. */
    public const BC_KINDS = ['form', 'reminder', 'final_reminder', 'manual_reminder'];
    /** Kinds sent to Admin/Managers – high priority and not counted in the BC daily limit. */
    public const ADMIN_KINDS = ['summary', 'escalation'];

    public static function enqueue(
        ?int $bcId,
        string $chatId,
        string $body,
        string $kind,
        ?string $reportDate = null,
        int $priority = 5,
        ?string $dedupKey = null,
        ?int $createdBy = null,
        ?string $scheduledAt = null
    ): int {
        $stmt = DB::run(
            'INSERT IGNORE INTO message_queue
             (bc_id, chat_id, body, kind, priority, report_date, status, attempts, scheduled_at, dedup_key, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'pending\', 0, ?, ?, ?, NOW())',
            [$bcId, $chatId, $body, $kind, $priority, $reportDate, $scheduledAt ?? date('Y-m-d H:i:s'), $dedupKey, $createdBy]
        );
        return $stmt->rowCount() > 0 ? (int) DB::pdo()->lastInsertId() : 0;
    }

    public static function hasPendingFor(int $bcId, string $date): bool
    {
        return (bool) DB::val(
            "SELECT COUNT(*) FROM message_queue WHERE bc_id = ? AND report_date = ? AND status IN ('pending','sending')",
            [$bcId, $date]
        );
    }

    /** Scheduled BC messages already sent today (for the daily limit). */
    public static function sentTodayCount(): int
    {
        $params = [date('Y-m-d 00:00:00')];
        $in = DB::in(self::ADMIN_KINDS, $params);
        return (int) DB::val(
            "SELECT COUNT(*) FROM message_queue WHERE status = 'sent' AND sent_at >= ? AND kind NOT IN ({$in})",
            $params
        );
    }

    public static function stats(): array
    {
        $row = DB::one(
            "SELECT SUM(status = 'pending') AS pending, SUM(status = 'sending') AS sending,
                    SUM(status = 'failed' AND created_at >= CURDATE()) AS failed_today,
                    SUM(status = 'sent' AND sent_at >= CURDATE()) AS sent_today
             FROM message_queue"
        ) ?? [];
        return array_map('intval', $row + ['pending' => 0, 'sending' => 0, 'failed_today' => 0, 'sent_today' => 0]);
    }

    /** Messages for past report dates are useless – cancel them. Also unlock stuck "sending" rows. */
    public static function housekeeping(): void
    {
        DB::exec(
            "UPDATE message_queue SET status = 'cancelled', last_error = 'expired (report date passed)'
             WHERE status = 'pending' AND report_date IS NOT NULL AND report_date < CURDATE()"
        );
        DB::exec(
            "UPDATE message_queue SET status = 'pending', locked_at = NULL
             WHERE status = 'sending' AND locked_at < (NOW() - INTERVAL 10 MINUTE)"
        );
    }

    /**
     * Welcome / onboarding message for a newly added BC (goes through the queue like every scheduled message).
     * If today's form time has already passed, today's form follows right after the welcome.
     * @return bool true when queued
     */
    public static function welcome(array $bc, ?int $userId = null): bool
    {
        if ((int) ($bc['is_active'] ?? 0) !== 1 || (int) ($bc['opted_out'] ?? 0) === 1) {
            return false;
        }
        $id = (int) $bc['id'];
        $today = Calendar::today();
        $queued = self::enqueue($id, self::chatIdFor($bc), Templates::build('welcome', $bc, $today), 'welcome', null, 3,
            "welcome:{$id}:" . date('YmdHis'), $userId) > 0;

        $formTime = Settings::padTime(Settings::get('form_time') ?: '18:00');
        if ($queued && date('H:i') >= $formTime && Calendar::isOpen($today)
            && !self::hasPendingFor($id, $today) && Calendar::bcStillPending($id, $today)) {
            self::enqueue($id, self::chatIdFor($bc), Templates::build('form', $bc, $today), 'form', $today, 3,
                "form_late:{$id}:{$today}", $userId, date('Y-m-d H:i:s', time() + 60));
        }
        return $queued;
    }

    /** Enqueue a reminder for pending BCs right now (button in the panel). Returns number queued. */
    public static function reminderNow(array $user, array $bcIds = []): int
    {
        $date = Calendar::today();
        if (!Calendar::isOpen($date)) {
            return 0;
        }
        $filters = $bcIds ? ['bc_ids' => $bcIds] : [];
        $rows = array_filter(
            Calendar::dayRows($date, $user, $filters),
            static fn ($r) => $r['status'] === 'pending' && (int) $r['opted_out'] === 0
        );
        $n = 0;
        $stamp = date('His');
        foreach ($rows as $bc) {
            if (self::hasPendingFor((int) $bc['id'], $date)) {
                continue; // a form/reminder for today is already waiting in the queue
            }
            $body = Templates::build('reminder', $bc, $date);
            $n += self::enqueue((int) $bc['id'], self::chatIdFor($bc), $body, 'manual_reminder', $date, 4,
                "manual:{$bc['id']}:{$date}:{$stamp}", (int) $user['id']) > 0 ? 1 : 0;
        }
        if ($n > 0) {
            Audit::log((int) $user['id'], 'reminder_now', 'queue', null, ['count' => $n]);
        }
        return $n;
    }

    public static function chatIdFor(array $bc): string
    {
        return Phone::toChatId((string) $bc['phone']);
    }
}
