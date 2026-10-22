<?php
declare(strict_types=1);

namespace App;

/**
 * Video / voice meetings for BCs and CBC supervisors (Jitsi rooms).
 * Admins host from the panel; CBCs host from the app (group = their BCs, or 1:1 with one BC).
 */
final class Meetings
{
    public static function base(): string
    {
        return rtrim(Settings::get('meeting_base_url') ?: 'https://meet.jit.si/', '/') . '/';
    }

    /** Join URL for app users – our own LiveKit page when the SFU is configured. */
    public static function url(string $room, string $mode = 'video'): string
    {
        if (Livekit::enabled()) {
            return rtrim((string) Env::get('APP_URL', ''), '/') . '/app/meet.html?r=' . rawurlencode($room)
                . '&m=' . ($mode === 'voice' ? 'voice' : 'video');
        }
        $u = self::base() . rawurlencode($room); // fallback: external service
        if ($mode === 'voice') {
            $u .= '#config.startAudioOnly=true&config.startWithVideoMuted=true';
        }
        return $u;
    }

    /** Join URL for staff already logged into the web panel (session auth). */
    public static function panelUrl(int $meetingId, string $room, string $mode = 'video'): string
    {
        return Livekit::enabled() ? '/meet.php?id=' . $meetingId : self::url($room, $mode);
    }

    /** Admin-created meeting. */
    public static function create(string $title, string $audience, string $mode, ?int $branchId, ?int $by): int
    {
        $audience = in_array($audience, ['all', 'bc', 'cbc', 'branch'], true) ? $audience : 'all';
        $mode = $mode === 'voice' ? 'voice' : 'video';
        $room = 'BCReport-' . bin2hex(random_bytes(6));
        return DB::insert(
            'INSERT INTO meetings (title, room, audience, mode, branch_id, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
            [mb_substr($title, 0, 160), $room, $audience, $mode, $audience === 'branch' ? $branchId : null, $by]
        );
    }

    /**
     * CBC hosts a meeting from the app.
     * @param string $scope 'all' (all my BCs) or 'bc' (1:1 with $bcId)
     * @return array{id:int,url:string,mode:string}|null
     */
    /**
     * @param list<int> $bcIds when $scope is 'all': invite only these BCs (empty = every BC in reach)
     */
    public static function startByCbc(int $cbcUserId, string $mode, string $scope, ?int $bcId, bool $seesAll = false, array $bcIds = []): ?array
    {
        $mode = $mode === 'voice' ? 'voice' : 'video';
        $cbcName = (string) DB::val('SELECT name FROM users WHERE id = ?', [$cbcUserId]);
        if ($scope === 'bc') {
            $bc = $bcId
                ? ($seesAll
                    ? DB::one('SELECT id, name FROM bcs WHERE id = ? AND is_active = 1', [$bcId])
                    : DB::one('SELECT id, name FROM bcs WHERE id = ? AND cbc_id = ? AND is_active = 1', [$bcId, $cbcUserId]))
                : null;
            if (!$bc) {
                return null;
            }
            $audience = 'one_bc';
            $title = $cbcName . ' → ' . $bc['name'] . ' (' . ($mode === 'voice' ? 'voice' : 'video') . ' call)';
        } else {
            // a CBC invites their own BCs; an admin/supervisor invites every BC
            $audience = $seesAll ? 'bc' : 'cbc_bcs';
            $bcId = null;
            $title = $cbcName . ' – ' . ($mode === 'voice' ? 'voice' : 'video') . ' meeting';
            if ($bcIds) { // only the BCs the host ticked (and that are really in reach)
                $p = [];
                $in = DB::in(array_map('intval', $bcIds), $p);
                $scopeSql = $seesAll ? '' : ' AND cbc_id = ?';
                if (!$seesAll) {
                    $p[] = $cbcUserId;
                }
                $validIds = array_map('intval', array_column(
                    DB::all("SELECT id FROM bcs WHERE id IN ({$in}) AND is_active = 1{$scopeSql}", $p), 'id'
                ));
                if (!$validIds) {
                    return null;
                }
                $audience = 'selected';
                $title = $cbcName . ' – ' . ($mode === 'voice' ? 'voice' : 'video') . ' meeting (' . count($validIds) . ' BC)';
            }
        }
        self::endByCbc($cbcUserId); // one live meeting per CBC at a time
        $room = 'BCReport-' . bin2hex(random_bytes(6));
        $id = DB::insert(
            'INSERT INTO meetings (title, room, audience, mode, created_by, host_cbc_id, target_bc_id, is_active, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, 1, NOW())',
            [mb_substr($title, 0, 160), $room, $audience, $mode, $cbcUserId, $bcId]
        );
        if ($audience === 'selected') {
            foreach ($validIds as $vid) {
                DB::exec('INSERT IGNORE INTO meeting_invites (meeting_id, bc_id) VALUES (?, ?)', [$id, $vid]);
            }
        }
        self::notifyStart($id);
        return ['id' => $id, 'url' => self::url($room, $mode), 'mode' => $mode,
            'invited' => $audience === 'selected' ? count($validIds) : null];
    }

    public static function end(int $id): void
    {
        DB::exec('UPDATE meetings SET is_active = 0, ended_at = NOW() WHERE id = ? AND is_active = 1', [$id]);
    }

    public static function endByCbc(int $cbcUserId): void
    {
        DB::exec('UPDATE meetings SET is_active = 0, ended_at = NOW() WHERE host_cbc_id = ? AND is_active = 1', [$cbcUserId]);
    }

    /** Latest active meeting a BC can join, or null. */
    public static function forBc(array $bc): ?array
    {
        $row = DB::one(
            "SELECT title, room, mode FROM meetings
             WHERE is_active = 1 AND (
                 audience IN ('all','bc')
                 OR (audience = 'branch'   AND branch_id   = ?)
                 OR (audience = 'cbc_bcs'  AND host_cbc_id = ?)
                 OR (audience = 'one_bc'   AND target_bc_id = ?)
                 OR (audience = 'selected' AND EXISTS (SELECT 1 FROM meeting_invites mi WHERE mi.meeting_id = meetings.id AND mi.bc_id = ?))
             ) ORDER BY id DESC LIMIT 1",
            [(int) ($bc['branch_id'] ?? 0), (int) ($bc['cbc_id'] ?? 0), (int) ($bc['id'] ?? 0), (int) ($bc['id'] ?? 0)]
        );
        return $row ? ['title' => $row['title'], 'room' => $row['room'], 'url' => self::url($row['room'], $row['mode']), 'mode' => $row['mode']] : null;
    }

    /** Latest active meeting a CBC can join/host, or null. */
    public static function forCbc(int $cbcUserId): ?array
    {
        $row = DB::one(
            "SELECT id, title, room, mode, host_cbc_id FROM meetings
             WHERE is_active = 1 AND (audience IN ('all','cbc') OR host_cbc_id = ?)
             ORDER BY id DESC LIMIT 1",
            [$cbcUserId]
        );
        return $row ? ['id' => (int) $row['id'], 'title' => $row['title'], 'room' => $row['room'],
            'url' => self::url($row['room'], $row['mode']),
            'mode' => $row['mode'], 'is_host' => ((int) $row['host_cbc_id'] === $cbcUserId)] : null;
    }

    /** BC WHERE clause (uses alias b on the bcs table) for a meeting's audience; null = no BCs. */
    private static function bcWhere(array $m, array &$params): ?string
    {
        switch ($m['audience']) {
            case 'all':
            case 'bc':
                return 'b.is_active = 1';
            case 'branch':
                $params[] = (int) $m['branch_id'];
                return 'b.is_active = 1 AND b.branch_id = ?';
            case 'cbc_bcs':
                $params[] = (int) $m['host_cbc_id'];
                return 'b.is_active = 1 AND b.cbc_id = ?';
            case 'one_bc':
                $params[] = (int) $m['target_bc_id'];
                return 'b.is_active = 1 AND b.id = ?';
            case 'selected':
                $params[] = (int) $m['id'];
                return 'b.is_active = 1 AND EXISTS (SELECT 1 FROM meeting_invites mi WHERE mi.meeting_id = ? AND mi.bc_id = b.id)';
        }
        return null; // 'cbc' -> staff only
    }

    /** Remember that someone opened the meeting screen (used to stop nudging them). */
    public static function markJoined(int $meetingId, string $actor): void
    {
        DB::exec('INSERT IGNORE INTO meeting_joins (meeting_id, actor, joined_at) VALUES (?, ?, NOW())', [$meetingId, $actor]);
    }

    /** Active meeting id for a room name (meet.php / token flows). */
    public static function idForRoom(string $room): int
    {
        return (int) DB::val('SELECT id FROM meetings WHERE room = ? ORDER BY id DESC LIMIT 1', [$room]);
    }

    /**
     * Called every minute from the scheduler: re-ring invited BCs who have not
     * joined a live meeting yet (every 2 min, at most 5 times per meeting).
     */
    public static function nudgeAbsent(): int
    {
        $sent = 0;
        $rows = DB::all("SELECT * FROM meetings
                         WHERE is_active = 1 AND nudges < 5
                           AND created_at >= (NOW() - INTERVAL 1 HOUR)
                           AND (last_nudge_at IS NULL OR last_nudge_at <= (NOW() - INTERVAL 2 MINUTE))");
        foreach ($rows as $m) {
            $params = [];
            $w = self::bcWhere($m, $params);
            if ($w === null) {
                DB::exec('UPDATE meetings SET last_nudge_at = NOW(), nudges = nudges + 1 WHERE id = ?', [$m['id']]);
                continue;
            }
            $params[] = (int) $m['id'];
            $absent = DB::all("SELECT b.id, b.name FROM bcs b
                               WHERE {$w} AND NOT EXISTS (SELECT 1 FROM meeting_joins j WHERE j.meeting_id = ? AND j.actor = CONCAT('bc-', b.id))
                               LIMIT 300", $params);
            $title = mb_substr('📹 ' . $m['title'], 0, 150);
            $body = 'Meeting chal rahi hai – app kholkar "Join" dabayein. (Reminder ' . ((int) $m['nudges'] + 1) . ')';
            foreach ($absent as $b) {
                DB::insert('INSERT INTO app_notifications (bc_id, kind, title, body, created_at) VALUES (?, ?, ?, ?, NOW())',
                    [(int) $b['id'], 'meeting', $title, $body]);
                try {
                    Fcm::pushToBc((int) $b['id'], ['id' => 0, 'kind' => 'meeting', 'title' => $title, 'body' => $body,
                        'meeting' => '1', 'ring' => '1']);
                } catch (\Throwable $e) {
                    Logger::warn('meeting nudge FCM failed: ' . $e->getMessage());
                }
                $sent++;
            }
            DB::exec('UPDATE meetings SET last_nudge_at = NOW(), nudges = nudges + 1 WHERE id = ?', [$m['id']]);
            Logger::info('Meeting nudge', ['meeting' => (int) $m['id'], 'absent' => count($absent)]);
        }
        return $sent;
    }

    /** Notify the audience that a meeting has started (in-app notice + instant Firebase). */
    public static function notifyStart(int $id): void
    {
        $m = DB::one('SELECT * FROM meetings WHERE id = ?', [$id]);
        if (!$m) {
            return;
        }
        $title = mb_substr('📹 ' . $m['title'], 0, 150);
        $body = ($m['mode'] === 'voice' ? 'Voice' : 'Video') . ' meeting shuru ho gayi hai. App kholkar "Join" dabayein.';

        $params = [];
        $w = self::bcWhere($m, $params);
        if ($w !== null) {
            DB::exec("INSERT INTO app_notifications (bc_id, kind, title, body, created_at) SELECT b.id, 'meeting', ?, ?, NOW() FROM bcs b WHERE {$w}",
                array_merge([$title, $body], $params));
            $fp = $params;
            foreach (DB::all("SELECT DISTINCT b.id FROM bcs b JOIN app_tokens t ON t.bc_id = b.id WHERE {$w} AND t.fcm_token IS NOT NULL LIMIT 500", $fp) as $b) {
                try {
                    Fcm::pushToBc((int) $b['id'], ['id' => 0, 'kind' => 'meeting', 'title' => $title, 'body' => $body,
                        'meeting' => '1', 'ring' => '1']);
                } catch (\Throwable $e) {
                    Logger::warn('meeting FCM(bc) failed: ' . $e->getMessage());
                }
            }
        }
        if (in_array($m['audience'], ['all', 'cbc'], true)) {
            foreach (DB::all("SELECT id FROM users WHERE role = 'cbc' AND is_active = 1") as $u) {
                try {
                    Fcm::pushToCbc((int) $u['id'], ['id' => 0, 'kind' => 'meeting', 'title' => $title, 'body' => $body]);
                } catch (\Throwable $e) {
                    Logger::warn('meeting FCM(cbc) failed: ' . $e->getMessage());
                }
            }
        }
    }
}
