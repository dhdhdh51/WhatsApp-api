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
    public static function create(string $title, string $audience, string $mode, ?int $branchId, ?int $by, ?int $regionId = null): int
    {
        $audience = in_array($audience, ['all', 'bc', 'cbc', 'branch', 'broadcast'], true) ? $audience : 'all';
        $mode = $mode === 'voice' ? 'voice' : 'video';
        $room = 'BCReport-' . bin2hex(random_bytes(6));
        return DB::insert(
            'INSERT INTO meetings (title, room, audience, mode, branch_id, region_id, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())',
            [mb_substr($title, 0, 160), $room, $audience, $mode, $audience === 'branch' ? $branchId : null, $regionId ?: null, $by]
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
            "SELECT id, title, room, mode, audience FROM meetings
             WHERE is_active = 1 AND (
                 audience IN ('all','bc','broadcast')
                 OR (audience = 'branch'   AND branch_id   = ?)
                 OR (audience = 'cbc_bcs'  AND host_cbc_id = ?)
                 OR (audience = 'one_bc'   AND target_bc_id = ?)
                 OR (audience = 'selected' AND EXISTS (SELECT 1 FROM meeting_invites mi WHERE mi.meeting_id = meetings.id AND mi.bc_id = ?))
             )
             AND (region_id IS NULL OR region_id = (SELECT region_id FROM branches WHERE id = ?))
             ORDER BY id DESC LIMIT 1",
            [(int) ($bc['branch_id'] ?? 0), (int) ($bc['cbc_id'] ?? 0), (int) ($bc['id'] ?? 0), (int) ($bc['id'] ?? 0), (int) ($bc['branch_id'] ?? 0)]
        );
        return $row ? ['id' => (int) $row['id'], 'title' => $row['title'], 'room' => $row['room'],
            'url' => self::url($row['room'], $row['mode']), 'mode' => $row['mode'],
            'is_broadcast' => $row['audience'] === 'broadcast'] : null;
    }

    /** Latest active meeting a CBC can join/host, or null. */
    public static function forCbc(int $cbcUserId): ?array
    {
        $row = DB::one(
            "SELECT id, title, room, mode, audience, host_cbc_id FROM meetings
             WHERE is_active = 1 AND (audience IN ('all','cbc','broadcast') OR host_cbc_id = ?)
             AND (region_id IS NULL OR region_id IN (SELECT DISTINCT br.region_id FROM bcs b JOIN branches br ON br.id = b.branch_id WHERE b.cbc_id = ?))
             ORDER BY id DESC LIMIT 1",
            [$cbcUserId, $cbcUserId]
        );
        return $row ? ['id' => (int) $row['id'], 'title' => $row['title'], 'room' => $row['room'],
            'url' => self::url($row['room'], $row['mode']),
            'mode' => $row['mode'], 'is_broadcast' => $row['audience'] === 'broadcast',
            'is_host' => ((int) $row['host_cbc_id'] === $cbcUserId)] : null;
    }

    /** BC WHERE clause (uses alias b on the bcs table) for a meeting's audience; null = no BCs. */
    private static function bcWhere(array $m, array &$params): ?string
    {
        switch ($m['audience']) {
            case 'all':
            case 'bc':
            case 'broadcast':   // broadcast reaches every active BC
                $w = 'b.is_active = 1';
                break;
            case 'branch':
                $params[] = (int) $m['branch_id'];
                $w = 'b.is_active = 1 AND b.branch_id = ?';
                break;
            case 'cbc_bcs':
                $params[] = (int) $m['host_cbc_id'];
                $w = 'b.is_active = 1 AND b.cbc_id = ?';
                break;
            case 'one_bc':
                $params[] = (int) $m['target_bc_id'];
                $w = 'b.is_active = 1 AND b.id = ?';
                break;
            case 'selected':
                $params[] = (int) $m['id'];
                $w = 'b.is_active = 1 AND EXISTS (SELECT 1 FROM meeting_invites mi WHERE mi.meeting_id = ? AND mi.bc_id = b.id)';
                break;
            default:
                return null; // 'cbc' -> staff only
        }
        if ((int) ($m['region_id'] ?? 0) > 0) {   // region-scoped meeting -> only BCs whose branch is in that region
            $w .= ' AND b.branch_id IN (SELECT id FROM branches WHERE region_id = ?)';
            $params[] = (int) $m['region_id'];
        }
        return $w;
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
        $isBroadcast = ($m['audience'] ?? '') === 'broadcast';
        $title = mb_substr(($isBroadcast ? '📢 ' : '📹 ') . $m['title'], 0, 150);
        $body = $isBroadcast
            ? 'LIVE broadcast shuru ho gaya hai. App kholkar "Join" dabayein.'
            : (($m['mode'] === 'voice' ? 'Voice' : 'Video') . ' meeting shuru ho gayi hai. App kholkar "Join" dabayein.');

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
        // Staff ring. Org-wide: a broadcast rings EVERY active staff member; a normal 'all'/'cbc'
        // meeting rings only CBC supervisors. Region-scoped (region_id set): ring only the CBCs
        // tied to that region (keeps one RO's meeting inside that RO).
        $region = (int) ($m['region_id'] ?? 0);
        $staffSql = null;
        $staffParams = [];
        if ($region > 0) {
            if ($isBroadcast || in_array($m['audience'], ['all', 'cbc'], true)) {
                $staffSql = "SELECT DISTINCT b.cbc_id AS id FROM bcs b JOIN branches br ON br.id = b.branch_id
                             WHERE br.region_id = ? AND b.cbc_id IS NOT NULL
                               AND EXISTS (SELECT 1 FROM users u WHERE u.id = b.cbc_id AND u.is_active = 1)";
                $staffParams = [$region];
            }
        } else {
            $staffSql = $isBroadcast
                ? 'SELECT id FROM users WHERE is_active = 1'
                : (in_array($m['audience'], ['all', 'cbc'], true) ? "SELECT id FROM users WHERE role = 'cbc' AND is_active = 1" : null);
        }
        if ($staffSql !== null) {
            foreach (DB::all($staffSql, $staffParams) as $u) {
                try {
                    Fcm::pushToCbc((int) $u['id'], ['id' => 0, 'kind' => 'meeting', 'title' => $title, 'body' => $body,
                        'meeting' => '1', 'ring' => '1']);
                } catch (\Throwable $e) {
                    Logger::warn('meeting FCM(staff) failed: ' . $e->getMessage());
                }
            }
        }
    }

    // ------------------------------------------------------------------ broadcast helpers

    public static function isBroadcast(array $m): bool
    {
        return ($m['audience'] ?? '') === 'broadcast';
    }

    public static function byId(int $id): ?array
    {
        return $id ? DB::one('SELECT * FROM meetings WHERE id = ?', [$id]) : null;
    }

    public static function byRoom(string $room): ?array
    {
        return $room !== '' ? DB::one('SELECT * FROM meetings WHERE room = ? ORDER BY id DESC LIMIT 1', [$room]) : null;
    }

    /** May this actor join / read the chat of this meeting? Server-side audience enforcement. */
    public static function canAccess(array $m, string $type, int $id, array $row): bool
    {
        if ((int) ($m['is_active'] ?? 0) !== 1) {
            return false;
        }
        $aud = (string) ($m['audience'] ?? '');
        $mRegion = (int) ($m['region_id'] ?? 0);
        if ($type === 'bc') {
            switch ($aud) {
                case 'all':
                case 'bc':
                case 'broadcast':
                    $ok = true;
                    break;
                case 'branch':
                    $ok = (int) ($row['branch_id'] ?? 0) === (int) $m['branch_id'];
                    break;
                case 'cbc_bcs':
                    $ok = (int) ($row['cbc_id'] ?? 0) === (int) $m['host_cbc_id'];
                    break;
                case 'one_bc':
                    $ok = (int) ($row['id'] ?? 0) === (int) $m['target_bc_id'];
                    break;
                case 'selected':
                    $ok = (bool) DB::val('SELECT 1 FROM meeting_invites WHERE meeting_id = ? AND bc_id = ?', [(int) $m['id'], (int) ($row['id'] ?? 0)]);
                    break;
                default:
                    $ok = false; // 'cbc' => staff only
            }
            if ($ok && $mRegion > 0) {   // region-scoped: the BC's branch must be in that region
                $ok = (int) DB::val('SELECT region_id FROM branches WHERE id = ?', [(int) ($row['branch_id'] ?? 0)]) === $mRegion;
            }
            return $ok;
        }
        if (App::staffSeesAll($row)) {
            return true;   // admin / region-less supervisor
        }
        if ($mRegion > 0) {   // region-scoped meeting: only staff who belong to that region, or the CBC host
            $belongs = ((($row['role'] ?? '') === 'supervisor') && (int) ($row['region_id'] ?? 0) === $mRegion)
                || (int) ($m['host_cbc_id'] ?? 0) === $id
                || (bool) DB::val('SELECT 1 FROM bcs b JOIN branches br ON br.id = b.branch_id WHERE br.region_id = ? AND b.cbc_id = ? LIMIT 1', [$mRegion, $id]);
            return $belongs && (in_array($aud, ['all', 'cbc', 'broadcast'], true) || (int) ($m['host_cbc_id'] ?? 0) === $id);
        }
        return in_array($aud, ['all', 'cbc', 'broadcast'], true) || (int) ($m['host_cbc_id'] ?? 0) === $id;
    }

    /**
     * May this actor publish audio/video in this meeting?
     * Normal meeting: everyone. Broadcast: only moderators (admin/supervisor or the host) and
     * participants the host has promoted (meeting_speakers). Everyone else is view-only.
     */
    public static function canPublishFor(array $m, string $type, int $id, array $row, string $actor): bool
    {
        if (!self::isBroadcast($m)) {
            return true;
        }
        $isMod = $type !== 'bc' && (App::staffSeesAll($row) || (int) ($m['host_cbc_id'] ?? 0) === $id);
        return $isMod || self::isSpeaker((int) $m['id'], $actor);
    }

    public static function isSpeaker(int $meetingId, string $actor): bool
    {
        return (bool) DB::val('SELECT 1 FROM meeting_speakers WHERE meeting_id = ? AND actor = ?', [$meetingId, $actor]);
    }

    public static function allowSpeak(int $meetingId, string $actor): void
    {
        DB::exec('INSERT IGNORE INTO meeting_speakers (meeting_id, actor, granted_at) VALUES (?, ?, NOW())', [$meetingId, mb_substr($actor, 0, 24)]);
    }

    public static function revokeSpeak(int $meetingId, string $actor): void
    {
        DB::exec('DELETE FROM meeting_speakers WHERE meeting_id = ? AND actor = ?', [$meetingId, $actor]);
    }

    // ------------------------------------------------------------------ in-meeting group chat (persistent)

    public static function chatPost(int $meetingId, string $actor, string $name, bool $isStaff, string $body): int
    {
        return DB::insert(
            'INSERT INTO meeting_messages (meeting_id, actor, name, is_staff, body, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$meetingId, mb_substr($actor, 0, 24), mb_substr($name, 0, 80), $isStaff ? 1 : 0, mb_substr($body, 0, 2000)]
        );
    }

    /**
     * Messages for a meeting. $afterId <= 0 returns the latest $limit (oldest-first);
     * $afterId > 0 returns messages newer than that id (for polling).
     * @return list<array>
     */
    public static function chatSince(int $meetingId, int $afterId, int $limit = 80): array
    {
        $limit = max(1, min(100, $limit));
        if ($afterId <= 0) {
            $rows = DB::all(
                'SELECT id, actor, name, is_staff, body, created_at FROM meeting_messages WHERE meeting_id = ? ORDER BY id DESC LIMIT ' . $limit,
                [$meetingId]
            );
            return array_reverse($rows);
        }
        return DB::all(
            'SELECT id, actor, name, is_staff, body, created_at FROM meeting_messages WHERE meeting_id = ? AND id > ? ORDER BY id ASC LIMIT ' . $limit,
            [$meetingId, $afterId]
        );
    }

    /** Format chat rows for a specific viewer (adds "mine"); strips nothing else. */
    public static function chatFormat(array $rows, string $viewerActor): array
    {
        return array_map(static function (array $r) use ($viewerActor): array {
            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'body' => (string) $r['body'],
                'staff' => (int) $r['is_staff'] === 1,
                'mine' => (string) $r['actor'] === $viewerActor,
                'time' => date('h:i A', strtotime((string) $r['created_at'])),
            ];
        }, $rows);
    }

    /** Org-wide per-scheme target vs achievement for the current month (broadcast "आज की प्रस्तुति" panel). */
    public static function schemeSummary(): array
    {
        $month = date('Y-m-01');
        $today = Calendar::today();
        $schemes = array_values(array_diff(ReportParser::SCHEMES, ReportParser::OFFLINE)); // apy, pmjjby, pmsby, pmjdy
        $labels = ReportParser::LABELS;
        $tgtCols = implode(', ', array_map(static fn ($k) => "COALESCE(SUM({$k}_target),0) {$k}", $schemes));
        $doneCols = implode(', ', array_map(static fn ($k) => "COALESCE(SUM({$k}),0) {$k}", $schemes));
        $tgt = DB::one("SELECT {$tgtCols} FROM targets WHERE month = ?", [$month]) ?: [];
        $done = DB::one("SELECT {$doneCols} FROM reports WHERE report_date >= ? AND report_date <= ?", [$month, $today]) ?: [];
        $rows = [];
        foreach ($schemes as $k) {
            $t = (int) ($tgt[$k] ?? 0);
            $d = (int) ($done[$k] ?? 0);
            $rows[] = ['key' => $k, 'label' => $labels[$k] ?? strtoupper((string) $k), 'target' => $t, 'done' => $d,
                'pct' => $t > 0 ? (int) round($d * 100 / $t) : 0, 'remaining' => max(0, $t - $d)];
        }
        return $rows;
    }
}
