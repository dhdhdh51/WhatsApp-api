<?php
declare(strict_types=1);

namespace App;

/**
 * Handles WAHA webhook events: incoming BC messages (reports, commands) and session status changes.
 * Replies to a BC's own message are sent immediately (not through the queue).
 */
final class BotHandler
{
    private WahaClient $waha;

    public function __construct(?WahaClient $waha = null)
    {
        $this->waha = $waha ?? new WahaClient();
    }

    public function handle(array $event): void
    {
        $type = (string) ($event['event'] ?? '');
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        if ($type === 'session.status') {
            self::updateStatus((string) ($payload['status'] ?? 'UNKNOWN'));
            return;
        }
        if ($type === 'message') {
            $this->onMessage($payload);
        }
    }

    public static function updateStatus(string $status): void
    {
        $prev = Settings::get('wa_status');
        Settings::set('wa_status', $status);
        Settings::set('wa_status_at', date('Y-m-d H:i:s'));
        if ($status === 'WORKING') {
            if (Settings::get('warmup_start_date') === '') {
                Settings::set('warmup_start_date', date('Y-m-d'));
            }
            if (Settings::get('queue_pause_reason') === 'disconnected') {
                Settings::set('queue_pause_reason', '');
                Logger::info('WhatsApp reconnected – queue resumed');
            }
        } elseif ($prev === 'WORKING') {
            Settings::set('queue_pause_reason', 'disconnected');
            Logger::warn("WhatsApp session status {$status} – queue paused");
        }
    }

    private function onMessage(array $p): void
    {
        if (!empty($p['fromMe'])) {
            return;
        }
        $from = (string) ($p['from'] ?? '');
        if ($from === '' || preg_match('/@(g\.us|broadcast|newsletter)$/', $from) || str_starts_with($from, 'status@')) {
            return;
        }
        $msgId = WahaClient::extractId($p) ?? ('noid_' . sha1(json_encode($p)));
        $body = (string) ($p['body'] ?? '');
        $replyTo = is_array($p['replyTo'] ?? null) ? $p['replyTo'] : null;
        $quotedId = $replyTo['id'] ?? null;
        if (is_array($quotedId)) {
            $quotedId = $quotedId['_serialized'] ?? null;
        }

        $bc = $this->resolveBc($from, $p);

        // Log first: the unique (direction, wa_message_id) key makes duplicate webhooks a no-op.
        $logId = MessageLog::add([
            'bc_id' => $bc['id'] ?? null,
            'direction' => 'in',
            'chat_id' => $from,
            'body' => $body !== '' ? $body : (!empty($p['hasMedia']) ? '[media]' : ''),
            'kind' => 'incoming',
            'status' => $bc ? 'received' : 'ignored',
            'wa_message_id' => $msgId,
            'reply_to_id' => is_string($quotedId) ? $quotedId : null,
        ]);
        if ($logId === 0) {
            return; // duplicate delivery
        }
        if (!$bc) {
            return; // unknown or inactive number – no reply
        }

        try {
            $this->process($bc, $from, $msgId, $body, !empty($p['hasMedia']), is_string($quotedId) ? $quotedId : null, $logId);
        } catch (\Throwable $e) {
            Logger::error('Bot processing failed: ' . $e->getMessage(), ['bc' => $bc['id'], 'msg' => $msgId]);
        }
    }

    /** Finds the active BC for a chat id (phone based, or hidden @lid resolved through WAHA). */
    private function resolveBc(string $from, array $p): ?array
    {
        $phone = Phone::fromChatId($from);
        $lid = str_ends_with($from, '@lid') ? $from : null;

        if (!$phone && $lid) {
            $bc = DB::one('SELECT * FROM bcs WHERE wa_lid = ?', [$lid]);
            if ($bc) {
                return (int) $bc['is_active'] === 1 ? $bc : null;
            }
            // some engines include the phone-number JID next to the LID
            foreach ([$p['_data']['key']['remoteJidAlt'] ?? null, $p['_data']['key']['senderPn'] ?? null,
                      $p['_data']['Info']['SenderAlt'] ?? null, $p['_data']['from'] ?? null] as $alt) {
                if (is_string($alt) && ($phone = Phone::fromChatId(preg_replace('/:\d+@/', '@', $alt)))) {
                    break;
                }
            }
            if (!$phone) {
                $phone = $this->waha->lidToPhone($lid);
            }
        }
        if (!$phone) {
            return null;
        }
        $bc = DB::one('SELECT * FROM bcs WHERE phone = ?', [$phone]);
        if (!$bc || (int) $bc['is_active'] !== 1) {
            return null;
        }
        if ($lid && $bc['wa_lid'] !== $lid) {
            DB::exec('UPDATE bcs SET wa_lid = NULL WHERE wa_lid = ?', [$lid]);
            DB::exec('UPDATE bcs SET wa_lid = ? WHERE id = ?', [$lid, $bc['id']]);
        }
        return $bc;
    }

    private function process(array $bc, string $chatId, string $msgId, string $body, bool $hasMedia, ?string $quotedId, int $logId): void
    {
        $today = Calendar::today();
        $parser = new ReportParser(max(1, Settings::int('max_value')));

        if (trim($body) === '' && $hasMedia) {
            MessageLog::setKind($logId, 'media');
            $this->reply($bc, $chatId, $msgId, "❌ Photo/file nahi, sirf text form bhejein.\n\n" . Templates::blankFormMessage($bc['name'], $today), 'reply_error');
            return;
        }

        $res = $parser->parse($body);
        $kind = match ($res['type']) {
            'report', 'error', 'empty_form' => 'report',
            'command' => 'command',
            'yes', 'no' => 'confirm',
            'ack' => 'ack',
            default => 'unknown',
        };
        MessageLog::setKind($logId, $kind);

        $pending = DB::one('SELECT * FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
        if ($pending && ($pending['report_date'] !== $today || strtotime((string) $pending['expires_at']) < time())) {
            DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
            $pending = null;
        }

        switch ($res['type']) {
            case 'command':
                $this->command($bc, $chatId, $msgId, $res['command'], $today);
                return;

            case 'yes':
                if ($pending) {
                    $this->confirmPending($bc, $chatId, $msgId, $pending);
                } else {
                    $this->replyUnknown($bc, $chatId, $msgId, $today);
                }
                return;

            case 'no':
                if ($pending) {
                    DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
                    $this->reply($bc, $chatId, $msgId, "Theek hai, report save nahi hui. Sahi numbers bharkar form dobara bhejein.\n\n"
                        . Templates::blankFormMessage($bc['name'], $today), 'reply_error');
                } else {
                    $this->replyUnknown($bc, $chatId, $msgId, $today);
                }
                return;

            case 'ack':
                return; // "ok", "thanks", 🙏 – no reply needed

            case 'unknown':
                $this->replyUnknown($bc, $chatId, $msgId, $today);
                return;
        }

        // ---- report / error / empty form
        if (time() > Calendar::cutoffTs($today)) {
            $this->reply($bc, $chatId, $msgId, '⛔ Aaj ki report ka samay (' . fmt_time12(Settings::padTime(Settings::get('cutoff_time'))) . ') khatam ho gaya hai. '
                . 'Ab badlav sirf Admin kar sakte hain.', 'reply_error');
            return;
        }

        if ($res['type'] === 'empty_form') {
            $this->reply($bc, $chatId, $msgId, "❌ Form khali hai – koi number nahi bhara.\n\n" . Templates::blankFormMessage($bc['name'], $today), 'reply_error');
            return;
        }
        if ($res['type'] === 'error') {
            $this->reply($bc, $chatId, $msgId, '❌ ' . implode('. ', $res['errors']) . ".\n\n" . Templates::blankFormMessage($bc['name'], $today), 'reply_error');
            return;
        }

        // date note (old form copied / reply to an old form)
        $formDate = $res['date'];
        if ($quotedId && ($sent = MessageLog::findSentForm($quotedId)) && $sent['report_date']) {
            $formDate = $sent['report_date'];
        }
        $note = '';
        if ($formDate && $formDate < $today) {
            $note = "\nℹ️ Form " . fmt_date($formDate) . ' ka tha – report aaj (' . fmt_date($today) . ') ki maani gayi.';
        }

        $threshold = Settings::int('high_value_threshold');
        $high = [];
        if ($threshold > 0) {
            foreach ($res['values'] as $s => $v) {
                if ($v > $threshold) {
                    $high[] = ReportParser::LABELS[$s] . ' ' . $v;
                }
            }
        }
        if ($high) {
            DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
            DB::insert(
                'INSERT INTO pending_confirmations (bc_id, report_date, apy, pmjjby, pmsby, pmjdy, blank_fields, raw_text, wa_message_id, note, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [$bc['id'], $today, $res['values']['apy'], $res['values']['pmjjby'], $res['values']['pmsby'], $res['values']['pmjdy'],
                    $res['blank'] ? implode(',', $res['blank']) : null, $body, $msgId, $note,
                    date('Y-m-d H:i:s', min(time() + 3 * 3600, Calendar::cutoffTs($today)))]
            );
            $this->reply($bc, $chatId, $msgId,
                '⚠️ ' . implode(', ', $high) . " – ye number zyada lag raha hai.\n"
                . Reports::summaryLine($res['values']) . "\n\n"
                . 'Sahi hai to *HAAN* likhein. Galat hai to sahi form dobara bhejein.', 'reply_confirm');
            return;
        }

        DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
        $saved = Reports::saveFromWhatsApp((int) $bc['id'], $today, $res['values'], $res['blank'], $body, $msgId);
        $this->reply($bc, $chatId, $msgId, self::confirmationText($res['values'], $res['blank'], $saved['updated']) . $note, 'reply_ok');
    }

    private function confirmPending(array $bc, string $chatId, string $msgId, array $pending): void
    {
        $values = ['apy' => (int) $pending['apy'], 'pmjjby' => (int) $pending['pmjjby'], 'pmsby' => (int) $pending['pmsby'], 'pmjdy' => (int) $pending['pmjdy']];
        $blank = $pending['blank_fields'] ? explode(',', (string) $pending['blank_fields']) : [];
        if (time() > Calendar::cutoffTs((string) $pending['report_date'])) {
            DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
            $this->reply($bc, $chatId, $msgId, '⛔ Report ka samay khatam ho gaya hai. Ab badlav sirf Admin kar sakte hain.', 'reply_error');
            return;
        }
        $saved = Reports::saveFromWhatsApp((int) $bc['id'], (string) $pending['report_date'], $values, $blank,
            (string) $pending['raw_text'], $pending['wa_message_id']);
        DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bc['id']]);
        $this->reply($bc, $chatId, $msgId, self::confirmationText($values, $blank, $saved['updated']) . (string) $pending['note'], 'reply_ok');
    }

    public static function confirmationText(array $values, array $blank, bool $updated): string
    {
        $txt = ($updated ? 'Report update ho gayi ✅ ' : 'Report mil gayi ✅ ')
            . Reports::summaryLine($values) . '. Galat ho to form dobara bhej dein.';
        if ($blank) {
            $labels = array_map(static fn ($s) => ReportParser::LABELS[$s], $blank);
            $txt .= "\nℹ️ " . implode(', ', $labels) . (count($labels) > 1 ? ' khali the' : ' khali tha') . ', 0 maana gaya.';
        }
        return $txt;
    }

    private function command(array $bc, string $chatId, string $msgId, string $cmd, string $today): void
    {
        $report = DB::one('SELECT * FROM reports WHERE bc_id = ? AND report_date = ?', [$bc['id'], $today]);
        switch ($cmd) {
            case 'FORM':
                $prefix = $report ? "ℹ️ Aaj ki report mil chuki hai (" . Reports::summaryLine($report) . "). Badlav ke liye naya form bhejein.\n\n" : '';
                $this->reply($bc, $chatId, $msgId, $prefix . Templates::blankFormMessage($bc['name'], $today), 'reply_form', $today);
                return;

            case 'STATUS':
                if ($report) {
                    $txt = '📊 ' . fmt_date($today) . ": Report mil chuki hai ✅\n" . Reports::summaryLine($report)
                        . "\nLast update: " . date('h:i A', strtotime((string) $report['updated_at']));
                } else {
                    $txt = '⏳ ' . fmt_date($today) . ': Report pending hai. FORM likhkar form mangwa sakte hain.';
                }
                $this->reply($bc, $chatId, $msgId, $txt, 'reply_status');
                return;

            case 'STOP':
                DB::exec('UPDATE bcs SET opted_out = 1, opted_out_at = NOW() WHERE id = ?', [$bc['id']]);
                DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'BC sent STOP' WHERE bc_id = ? AND status = 'pending'", [$bc['id']]);
                Audit::log(null, 'bc_opt_out', 'bc', (int) $bc['id'], ['via' => 'whatsapp']);
                $this->reply($bc, $chatId, $msgId, "Theek hai, ab aapko reminder nahi bheje jayenge. 🙏\n"
                    . "Report bhejna jaari rakh sakte hain. Reminder dobara shuru karne ke liye START likhein.", 'reply_command');
                return;

            case 'START':
                DB::exec('UPDATE bcs SET opted_out = 0, opted_out_at = NULL WHERE id = ?', [$bc['id']]);
                Audit::log(null, 'bc_opt_in', 'bc', (int) $bc['id'], ['via' => 'whatsapp']);
                $this->reply($bc, $chatId, $msgId, '✅ Reminder dobara shuru kar diye gaye hain.', 'reply_command');
                return;

            case 'HELP':
            default:
                $this->reply($bc, $chatId, $msgId, self::helpText(), 'reply_help');
        }
    }

    public static function helpText(): string
    {
        return "ℹ️ *Madad / Help*\n\n"
            . "Roz ki report bhejne ke liye form copy karke numbers bharein aur bhej dein.\n"
            . "Chhota tareeka: 4 number is kram mein – APY PMJJBY PMSBY PMJDY\n"
            . "Jaise: 2 1 3 5\n\n"
            . "*FORM* – aaj ka form dobara mangwayein\n"
            . "*STATUS* – aaj ki report dekhein\n"
            . "*STOP* – reminder band karein\n"
            . "*START* – reminder dobara shuru karein\n"
            . "*HELP* – ye message";
    }

    private function replyUnknown(array $bc, string $chatId, string $msgId, string $today): void
    {
        $cool = max(0, Settings::int('unknown_reply_cooldown_min'));
        if ($cool > 0) {
            $recent = DB::val(
                "SELECT COUNT(*) FROM messages WHERE bc_id = ? AND direction = 'out' AND kind = 'reply_unknown'
                 AND created_at >= (NOW() - INTERVAL ? MINUTE)",
                [$bc['id'], $cool]
            );
            if ((int) $recent > 0) {
                return; // don't flood the BC with forms for chit-chat
            }
        }
        $this->reply($bc, $chatId, $msgId, "❓ Aapka message samajh nahi aaya. Neeche form copy karke numbers bharein (ya HELP likhein).\n\n"
            . Templates::blankFormMessage($bc['name'], $today), 'reply_unknown');
    }

    /** Immediate chat reply (seen → short typing → text) and log it. */
    private function reply(array $bc, string $chatId, string $inMsgId, string $text, string $kind, ?string $reportDate = null): void
    {
        $this->waha->sendSeen($chatId, $inMsgId);
        $this->waha->startTyping($chatId);
        usleep(random_int(800, 1500) * 1000);
        $this->waha->stopTyping($chatId);
        $r = $this->waha->sendText($chatId, $text);
        MessageLog::add([
            'bc_id' => (int) $bc['id'],
            'direction' => 'out',
            'chat_id' => $chatId,
            'body' => $text,
            'kind' => $kind,
            'status' => $r['ok'] ? 'sent' : 'failed',
            'wa_message_id' => $r['id'],
            'error' => $r['ok'] ? null : $r['error'],
            'report_date' => $reportDate,
        ]);
        if (!$r['ok']) {
            Logger::warn('Reply failed', ['bc' => $bc['id'], 'error' => $r['error']]);
        }
    }
}
