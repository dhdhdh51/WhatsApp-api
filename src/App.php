<?php
declare(strict_types=1);

namespace App;

/**
 * Mobile app support: BC login tokens, in-app notifications and chat.
 * The Android app polls /api/app.php?a=poll every ~15 minutes and shows new notifications.
 */
final class App
{
    /** Kinds of queued WhatsApp messages that are mirrored into the app. */
    public const MIRROR = [
        'form'            => '📋 Aaj ki report bharein',
        'reminder'        => '⏰ Report abhi baaki hai',
        'final_reminder'  => '⚠️ Aakhri reminder – report bharein',
        'manual_reminder' => '⏰ Report bharein',
        'welcome'         => '🙏 BC Daily Report mein swagat hai',
        'target'          => '🎯 Is mahine ka target',
        'morning'         => '📊 Kal ka pradarshan + aaj ka lakshya',
    ];

    /** Sender name shown in the app chat for automatic (template) messages. */
    public const SYSTEM_NAME = '📢 BC Report';

    /**
     * A scheduled template message (morning, form, reminder, target, welcome) goes into the BC's app chat
     * + one phone notification. Form/reminder notifications open the Report tab, the rest open the chat.
     */
    public static function deliver(int $bcId, string $kind, string $body): void
    {
        $isForm = in_array($kind, ['form', 'reminder', 'final_reminder', 'manual_reminder'], true);
        if ($isForm) {
            $cut = fmt_time12(Settings::padTime(Settings::get('cutoff_time') ?: '23:59'));
            $body = (self::MIRROR[$kind] ?? '📋 Report bharein') . "\n\nApp mein *Report* tab kholkar aaj ke number bharein aur bhejein.\nAntim samay: {$cut}.";
        }
        DB::insert('INSERT INTO chat_messages (bc_id, sender, user_id, body, created_at) VALUES (?, \'staff\', NULL, ?, NOW())', [$bcId, $body]);
        self::notify($bcId, $isForm ? 'form' : 'chat', self::MIRROR[$kind] ?? '💬 BC Report', mb_strimwidth($body, 0, 300, '…'));
    }

    public static function notify(int $bcId, string $kind, string $title, ?string $body = null): int
    {
        $id = DB::insert(
            'INSERT INTO app_notifications (bc_id, kind, title, body, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$bcId, $kind, mb_substr($title, 0, 150), $body]
        );
        try { // instant push; the 15-min poll stays as backup
            Fcm::pushToBc($bcId, ['id' => $id, 'kind' => $kind, 'title' => mb_substr($title, 0, 150),
                'body' => mb_strimwidth((string) $body, 0, 900, '…')]);
        } catch (\Throwable $e) {
            Logger::warn('FCM push failed: ' . $e->getMessage());
        }
        return $id;
    }

    /**
     * One-line app status for the panel, from the row's token/activity counters.
     * $r needs: devices, fcm, app_first_seen, app_last_seen
     *
     * @return array{0:string,1:string} [label, bootstrap colour]
     */
    public static function appStatus(array $r): array
    {
        $devices = (int) ($r['devices'] ?? 0);
        $fcm = (int) ($r['fcm'] ?? 0);
        $first = $r['app_first_seen'] ?? null;
        $last = $r['app_last_seen'] ?? null;
        $stale = $last ? (strtotime((string) $last) < time() - 3 * 86400) : true;

        if ($devices > 0) {
            // push token gone + quiet for days usually means the app was removed
            if ($fcm === 0 && $stale) {
                return ['Logged in (app hataya?)', 'warning'];
            }
            return [$stale ? 'Logged in (purana)' : 'Logged in', 'success'];
        }
        if ($first) {
            return ['Logout / app hataya', 'danger'];
        }
        return ['Install nahi kiya', 'secondary'];
    }

    /** Is this BC actively using the app (opened it in the last 3 days)? */
    public static function isActiveUser(int $bcId): bool
    {
        return (bool) DB::val('SELECT COUNT(*) FROM bcs WHERE id = ? AND app_last_seen >= (NOW() - INTERVAL 3 DAY)', [$bcId]);
    }

    // ------------------------------------------------------------------ auth

    /** Extract the native app version from WebView user agent; normal browsers deliberately return null. */
    public static function versionFromDevice(string $device): ?string
    {
        return preg_match('/BCReportApp\/([0-9]+(?:\.[0-9]+){1,2})/i', $device, $m) ? $m[1] : null;
    }

    /**
     * Version of the app making *this* request. The APK appends "BCReportApp/<name>" to the end of
     * the UA, so a truncated stored device string usually loses it - the live HTTP header never does.
     * Falling back to the stored string keeps older rows working.
     */
    public static function liveVersion(?string $storedDevice = null): ?string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return self::versionFromDevice($ua) ?? ($storedDevice !== null ? self::versionFromDevice($storedDevice) : null);
    }

    /**
     * Mobile app login. A phone number may belong to a BC (bcs table) or to a
     * CBC staff user (users table, role = cbc). BC is tried first, then CBC.
     *
     * @return array{ok:bool, error?:string, type?:string, token?:string, must_change?:bool, bc?:array, user?:array}
     */
    public static function login(string $phoneRaw, string $password, string $device): array
    {
        $phone = Phone::normalize($phoneRaw);
        $key = 'app:' . ($phone ?? mb_substr($phoneRaw, 0, 20));
        $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
        $fails = (int) DB::val(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE) AND (username = ? OR ip = ?)',
            [$key, $ip]
        );
        if ($fails >= 8) {
            return ['ok' => false, 'error' => 'Bahut baar galat password. 15 minute baad koshish karein.'];
        }
        $bc = $phone ? DB::one('SELECT * FROM bcs WHERE phone = ? AND is_active = 1', [$phone]) : null;
        $cbc = null;
        $ok = false;
        $usedDefault = false;
        if ($bc) {
            if ($bc['app_password_hash']) {
                $ok = password_verify($password, (string) $bc['app_password_hash']);
            } else {
                $def = Settings::get('app_default_password_hash');
                $ok = $def !== '' && password_verify($password, $def);
                $usedDefault = $ok;
            }
        } else {
            // No BC with this phone – maybe a staff member (CBC / supervisor / admin) using their panel password.
            $cbc = $phone ? DB::one("SELECT * FROM users WHERE role IN ('cbc','supervisor','admin') AND phone = ? AND is_active = 1", [$phone]) : null;
            if ($cbc) {
                $ok = password_verify($password, (string) $cbc['password_hash']);
            } else {
                password_verify($password, '$2y$10$' . str_repeat('a', 53)); // timing
            }
        }
        DB::insert('INSERT INTO login_attempts (ip, username, success, attempted_at) VALUES (?, ?, ?, NOW())', [$ip, $key, $ok ? 1 : 0]);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Mobile number ya password galat hai.'];
        }
        $token = bin2hex(random_bytes(32));
        $safeDevice = mb_substr($device, 0, 255);
        $version = self::liveVersion($device);

        if ($bc) {
            DB::insert('INSERT INTO app_tokens (bc_id, token_hash, device, created_at, last_used_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$bc['id'], hash('sha256', $token), $safeDevice]);
            DB::exec('UPDATE bcs SET app_first_seen = COALESCE(app_first_seen, NOW()), app_last_seen = NOW(), app_version = COALESCE(?, app_version) WHERE id = ?',
                [$version, $bc['id']]);
            Audit::log(null, 'app_login', 'bc', (int) $bc['id'], ['device' => mb_substr($device, 0, 60)]);
            return ['ok' => true, 'type' => 'bc', 'token' => $token, 'must_change' => $usedDefault || (int) $bc['app_must_change'] === 1, 'bc' => $bc];
        }

        DB::insert('INSERT INTO cbc_app_tokens (user_id, token_hash, device, created_at, last_used_at) VALUES (?, ?, ?, NOW(), NOW())',
            [$cbc['id'], hash('sha256', $token), $safeDevice]);
        DB::exec('UPDATE users SET app_first_seen = COALESCE(app_first_seen, NOW()), app_last_seen = NOW(), app_version = COALESCE(?, app_version) WHERE id = ?',
            [$version, $cbc['id']]);
        Audit::log(null, 'app_login', 'user', (int) $cbc['id'], ['device' => mb_substr($device, 0, 60), 'role' => $cbc['role']]);
        return ['ok' => true, 'type' => 'cbc', 'token' => $token, 'must_change' => false, 'user' => $cbc];
    }

    /**
     * Resolve a bearer token to an actor (a BC or a CBC staff user).
     *
     * @return array{type:string, id:int, row:array}|null
     */
    public static function actorFromToken(string $token): ?array
    {
        $bc = self::bcFromToken($token);
        if ($bc) {
            return ['type' => 'bc', 'id' => (int) $bc['id'], 'row' => $bc];
        }
        $cbc = self::cbcFromToken($token);
        if ($cbc) {
            return ['type' => 'cbc', 'id' => (int) $cbc['id'], 'row' => $cbc];
        }
        return null;
    }

    /** CBC staff user for a bearer token, or null. */
    public static function cbcFromToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $t = DB::one("SELECT t.id tid, t.device, u.* FROM cbc_app_tokens t JOIN users u ON u.id = t.user_id
                      WHERE t.token_hash = ? AND u.is_active = 1 AND u.role IN ('cbc','supervisor','admin')", [hash('sha256', $token)]);
        if (!$t) {
            return null;
        }
        DB::exec('UPDATE cbc_app_tokens SET last_used_at = NOW() WHERE id = ?', [$t['tid']]);
        DB::exec('UPDATE users SET app_first_seen = COALESCE(app_first_seen, NOW()), app_last_seen = NOW(), app_version = COALESCE(?, app_version) WHERE id = ?',
            [self::liveVersion((string) $t['device']), $t['id']]);
        return $t;
    }

    /** Call/alarm config for a CBC phone (its own audio/text/time, separate from BC). */
    public static function alarmConfigCbc(): array
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/') . '/uploads/';
        $call = Settings::get('cbc_call_audio');
        $alarm = Settings::get('cbc_alarm_audio');
        return [
            'alarm_text' => Settings::get('cbc_alarm_text'),
            'alarm_audio_url' => $alarm !== '' ? $base . rawurlencode($alarm) : '',
            'enabled' => Settings::bool('cbc_alarm_enabled'),
            'call_time' => Settings::padTime(Settings::get('cbc_call_time') ?: '18:00'),
            'alarm_time' => Settings::padTime(Settings::get('cbc_alarm_time') ?: '18:30'),
            'text' => Settings::get('cbc_call_text'),
            'audio_url' => $call !== '' ? $base . rawurlencode($call) : '',
            'version' => (int) Settings::get('cbc_alarm_version'),
        ];
    }

    /**
     * Admin test: ring a CBC's phone. Instant via Firebase when available; otherwise it
     * arrives at the phone's next poll (max ~15 min).
     */
    public static function sendTestRingCbc(int $cbcUserId, string $mode): void
    {
        $kind = $mode === 'call' ? 'ring_call' : 'ring_alarm';
        $reached = 0;
        try {
            $reached = Fcm::pushToCbc($cbcUserId, ['id' => 0, 'kind' => $kind, 'title' => 'Test', 'body' => 'Admin test']);
        } catch (\Throwable $e) {
            Logger::warn('CBC FCM test failed: ' . $e->getMessage());
        }
        if ($reached === 0) { // no instant push delivered -> poll fallback
            DB::exec('UPDATE cbc_app_tokens SET poll_seq = poll_seq + 1, test_mode = ? WHERE user_id = ?',
                [$mode === 'call' ? 'call' : 'alarm', $cbcUserId]);
        }
    }

    /** Does this staff user see every BC (admin / supervisor) instead of only their own? */
    public static function staffSeesAll(array $row): bool
    {
        if (($row['role'] ?? '') === 'admin') {
            return true;
        }
        return ($row['role'] ?? '') === 'supervisor' && !Scope::hasBranches($row);
    }

    /**
     * Should a CBC's phone ring now? True when any of their BCs is still pending today.
     * Only real CBCs get this alarm – admins/supervisors would ring every day.
     */
    public static function shouldRingCbc(int $cbcUserId): bool
    {
        $today = Calendar::today();
        if (!Settings::bool('cbc_alarm_enabled') || !Calendar::isOpen($today)) {
            return false;
        }
        if (DB::val('SELECT role FROM users WHERE id = ?', [$cbcUserId]) !== 'cbc') {
            return false;
        }
        foreach (Calendar::dayRows($today, null, ['cbc_id' => $cbcUserId]) as $r) {
            if ($r['status'] === 'pending') {
                return true;
            }
        }
        return false;
    }

    /** BC for a bearer token, or null. */
    public static function bcFromToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $t = DB::one('SELECT t.id tid, t.device, b.* FROM app_tokens t JOIN bcs b ON b.id = t.bc_id WHERE t.token_hash = ? AND b.is_active = 1',
            [hash('sha256', $token)]);
        if (!$t) {
            return null;
        }
        DB::exec('UPDATE app_tokens SET last_used_at = NOW() WHERE id = ?', [$t['tid']]);
        DB::exec('UPDATE bcs SET app_first_seen = COALESCE(app_first_seen, NOW()), app_last_seen = NOW(), app_version = COALESCE(?, app_version) WHERE id = ?',
            [self::liveVersion((string) $t['device']), $t['id']]);
        return $t;
    }

    public static function setPassword(int $bcId, string $new): void
    {
        DB::exec('UPDATE bcs SET app_password_hash = ?, app_must_change = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $bcId]);
    }

    /** Admin: back to the default password, log out all devices. */
    public static function resetPassword(int $bcId): void
    {
        DB::exec('UPDATE bcs SET app_password_hash = NULL, app_must_change = 1 WHERE id = ?', [$bcId]);
        DB::exec('DELETE FROM app_tokens WHERE bc_id = ?', [$bcId]);
    }

    // ------------------------------------------------------------------ chat

    public static function chatSend(int $bcId, string $sender, ?int $userId, string $body): int
    {
        $id = DB::insert('INSERT INTO chat_messages (bc_id, sender, user_id, body, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$bcId, $sender, $userId, $body]);
        if ($sender === 'staff') {
            $name = $userId ? (string) DB::val('SELECT name FROM users WHERE id = ?', [$userId]) : 'Admin';
            self::notify($bcId, 'chat', '💬 ' . $name, mb_strimwidth($body, 0, 200, '…'));
        }
        return $id;
    }

    /** Unread messages from BCs, for the staff menu badge. */
    public static function staffUnread(array $user): int
    {
        [$w, $p] = Scope::bc($user);
        return (int) DB::val("SELECT COUNT(*) FROM chat_messages c JOIN bcs b ON b.id = c.bc_id WHERE c.sender = 'bc' AND c.read_at IS NULL AND {$w}", $p);
    }

    // ------------------------------------------------------------------ call / alarm

    /** Config the phone uses to schedule its own 5:00 call and 5:30 alarm. */
    public static function alarmConfig(): array
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/') . '/uploads/';
        $file = Settings::get('app_call_audio');
        $afile = Settings::get('app_alarm_audio');
        return [
            'alarm_text' => Settings::get('app_alarm_text'),
            'alarm_audio_url' => $afile !== '' ? $base . rawurlencode($afile) : '',
            'enabled' => Settings::bool('app_call_enabled'),
            'call_time' => Settings::padTime(Settings::get('app_call_time') ?: '17:00'),
            'alarm_time' => Settings::padTime(Settings::get('app_alarm_time') ?: '17:30'),
            'text' => Settings::get('app_call_text'),
            'audio_url' => $file !== '' ? rtrim((string) Env::get('APP_URL', ''), '/') . '/uploads/' . rawurlencode($file) : '',
            'version' => (int) Settings::get('app_call_version'),
        ];
    }

    /** Should the phone ring now? Only when today's report is still pending (not leave / holiday / submitted). */
    public static function shouldRing(int $bcId): bool
    {
        $today = Calendar::today();
        if (!Settings::bool('app_call_enabled') || !Calendar::isOpen($today)) {
            return false;
        }
        $rows = Calendar::dayRows($today, null, ['bc_ids' => [$bcId]]);
        return $rows && $rows[0]['status'] === 'pending';
    }

    /** Admin test: make the phone ring (arrives with the next app check, max ~15 min). */
    public static function sendTestRing(int $bcId, string $mode): void
    {
        self::notify($bcId, $mode === 'call' ? 'ring_call' : 'ring_alarm', $mode === 'call' ? '📞 Test call' : '⏰ Test alarm', 'Admin test');
        // settings changed? phones refresh their alarm config on any push (handled in the app)
    }
}
