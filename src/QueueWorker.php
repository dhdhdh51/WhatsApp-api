<?php
declare(strict_types=1);

namespace App;

/**
 * Sends queued messages one at a time with anti-ban safety:
 * random 8-20 s gaps, 1-3 s typing indicator, daily limit + warm-up, max 2 retries,
 * pause while WhatsApp is disconnected (auto-resume when the session is WORKING again).
 */
final class QueueWorker
{
    private WahaClient $waha;
    private int $deadline;

    public function __construct(int $maxRuntimeSec, ?WahaClient $waha = null)
    {
        $this->waha = $waha ?? new WahaClient();
        $this->deadline = time() + max(30, $maxRuntimeSec);
    }

    public function run(): void
    {
        Queue::housekeeping();
        $idleRounds = 0;

        while (time() < $this->deadline) {
            Settings::refresh();

            if (Settings::bool('queue_paused')) {
                Logger::info('Queue paused manually – exiting');
                return;
            }
            if (!$this->sessionWorking()) {
                return;
            }

            $job = $this->claimNext();
            if (!$job) {
                // nothing due – wait a little for new jobs (scheduler runs every minute), then exit
                if (++$idleRounds > 6) {
                    return;
                }
                sleep(10);
                continue;
            }
            $idleRounds = 0;

            $isAdminMsg = in_array($job['kind'], Queue::ADMIN_KINDS, true);
            if (!$isAdminMsg && Queue::sentTodayCount() >= Settings::effectiveDailyLimit()) {
                DB::exec("UPDATE message_queue SET status = 'pending', locked_at = NULL WHERE id = ?", [$job['id']]);
                Settings::set('queue_pause_reason', 'daily_limit');
                Logger::warn('Daily message limit reached (' . Settings::effectiveDailyLimit() . ') – waiting till tomorrow');
                return;
            }
            if (Settings::get('queue_pause_reason') === 'daily_limit') {
                Settings::set('queue_pause_reason', '');
            }

            $skip = $this->skipReason($job);
            if ($skip !== null) {
                DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = ?, locked_at = NULL WHERE id = ?", [$skip, $job['id']]);
                continue; // no delay needed, nothing was sent
            }

            $tMin = max(0, Settings::int('typing_min_sec'));
            $typing = random_int($tMin, max($tMin, Settings::int('typing_max_sec')));
            $this->waitGap($typing);
            $this->send($job, $typing);
        }
    }

    private function sessionWorking(): bool
    {
        $info = $this->waha->sessionInfo();
        $status = $info['status'];
        if ($status !== Settings::get('wa_status')) {
            BotHandler::updateStatus($status);
        }
        if ($status !== 'WORKING') {
            if (Settings::get('queue_pause_reason') !== 'disconnected') {
                Settings::set('queue_pause_reason', 'disconnected');
            }
            Logger::warn("WhatsApp not connected ({$status}) – queue paused" . ($info['error'] ? ': ' . $info['error'] : ''));
            return false;
        }
        if (Settings::get('queue_pause_reason') === 'disconnected') {
            Settings::set('queue_pause_reason', '');
        }
        return true;
    }

    private function claimNext(): ?array
    {
        for ($i = 0; $i < 5; $i++) {
            $job = DB::one(
                "SELECT * FROM message_queue WHERE status = 'pending' AND scheduled_at <= NOW()
                 ORDER BY priority ASC, scheduled_at ASC, id ASC LIMIT 1"
            );
            if (!$job) {
                return null;
            }
            $ok = DB::exec("UPDATE message_queue SET status = 'sending', locked_at = NOW() WHERE id = ? AND status = 'pending'", [$job['id']]);
            if ($ok === 1) {
                return $job;
            }
        }
        return null;
    }

    private function skipReason(array $job): ?string
    {
        if ($job['kind'] === 'target' && (string) $job['report_date'] !== Calendar::today()) {
            return 'expired (report date passed)';
        }
        if ($job['kind'] === 'welcome' || $job['kind'] === 'target') {
            $bc = $job['bc_id'] ? DB::one('SELECT is_active, opted_out FROM bcs WHERE id = ?', [$job['bc_id']]) : null;
            return (!$bc || (int) $bc['is_active'] !== 1 || (int) $bc['opted_out'] === 1) ? 'BC inactive / STOP' : null;
        }
        if (!in_array($job['kind'], Queue::BC_KINDS, true)) {
            return null;
        }
        $date = (string) $job['report_date'];
        if ($date !== Calendar::today()) {
            return 'expired (report date passed)';
        }
        if (time() > Calendar::cutoffTs($date)) {
            return 'cutoff passed';
        }
        if (!$job['bc_id'] || !Calendar::bcStillPending((int) $job['bc_id'], $date)) {
            return 'not needed (submitted / leave / holiday / inactive / STOP)';
        }
        return null;
    }

    /**
     * Random gap (min_delay_sec..max_delay_sec) between two sent messages, measured send-to-send.
     * The typing indicator that follows is counted inside the gap, so a 5 s setting really means ~5 s.
     * Persisted via last_sent_at, so it also holds across worker runs.
     */
    private function waitGap(int $typing): void
    {
        $min = max(1, Settings::int('min_delay_sec'));
        $max = max($min, Settings::int('max_delay_sec'));
        $gap = random_int($min, $max);
        $last = Settings::get('last_sent_at');
        $elapsed = $last !== '' ? time() - (int) strtotime($last) : PHP_INT_MAX;
        $wait = $gap - $typing - $elapsed;
        if ($wait > 0) {
            sleep($wait);
        }
    }

    private function send(array $job, int $typing): void
    {
        $chat = (string) $job['chat_id'];

        $this->waha->startTyping($chat);
        sleep($typing);
        $this->waha->stopTyping($chat);

        $r = $this->waha->sendText($chat, (string) $job['body']);
        Settings::set('last_sent_at', date('Y-m-d H:i:s'));

        if ($r['ok']) {
            DB::exec(
                "UPDATE message_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, wa_message_id = ?, last_error = NULL, locked_at = NULL WHERE id = ?",
                [$r['id'], $job['id']]
            );
            MessageLog::add([
                'bc_id' => $job['bc_id'] ? (int) $job['bc_id'] : null,
                'direction' => 'out',
                'chat_id' => $chat,
                'body' => (string) $job['body'],
                'kind' => (string) $job['kind'],
                'status' => 'sent',
                'wa_message_id' => $r['id'],
                'queue_id' => (int) $job['id'],
                'report_date' => $job['report_date'],
            ]);
            if ($job['kind'] === 'welcome' && $job['bc_id']) {
                DB::exec('UPDATE bcs SET welcome_sent_at = NOW() WHERE id = ?', [$job['bc_id']]);
            }
            Logger::info("Sent #{$job['id']} {$job['kind']} to {$chat}");
            return;
        }

        // failure
        $attempts = (int) $job['attempts'] + 1;
        $maxRetries = max(0, Settings::int('max_retries'));
        $sessionDown = $r['http'] === 0 || !$this->stillWorkingAfterError();

        if ($sessionDown) {
            // connection problem – don't burn a retry; the queue is paused until reconnect
            DB::exec("UPDATE message_queue SET status = 'pending', last_error = ?, locked_at = NULL WHERE id = ?", [mb_substr($r['error'], 0, 500), $job['id']]);
            $this->deadline = 0;
            return;
        }
        if ($attempts > $maxRetries) {
            DB::exec(
                "UPDATE message_queue SET status = 'failed', attempts = ?, last_error = ?, locked_at = NULL WHERE id = ?",
                [$attempts, mb_substr($r['error'], 0, 500), $job['id']]
            );
            MessageLog::add([
                'bc_id' => $job['bc_id'] ? (int) $job['bc_id'] : null,
                'direction' => 'out',
                'chat_id' => $chat,
                'body' => (string) $job['body'],
                'kind' => (string) $job['kind'],
                'status' => 'failed',
                'queue_id' => (int) $job['id'],
                'error' => $r['error'],
                'report_date' => $job['report_date'],
            ]);
            Logger::error("Failed #{$job['id']} after {$attempts} attempts: {$r['error']}");
        } else {
            DB::exec(
                "UPDATE message_queue SET status = 'pending', attempts = ?, last_error = ?, locked_at = NULL,
                        scheduled_at = (NOW() + INTERVAL ? MINUTE) WHERE id = ?",
                [$attempts, mb_substr($r['error'], 0, 500), 2 * $attempts, $job['id']]
            );
            Logger::warn("Retry #{$job['id']} later (attempt {$attempts}): {$r['error']}");
        }
    }

    private function stillWorkingAfterError(): bool
    {
        $info = $this->waha->sessionInfo();
        if ($info['status'] !== 'WORKING') {
            BotHandler::updateStatus($info['status']);
            Settings::set('queue_pause_reason', 'disconnected');
            return false;
        }
        return true;
    }
}
