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

    public static function url(string $room, string $mode = 'video'): string
    {
        $u = self::base() . rawurlencode($room);
        if ($mode === 'voice') {
            $u .= '#config.startAudioOnly=true&config.startWithVideoMuted=true';
        }
        return $u;
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
    public static function startByCbc(int $cbcUserId, string $mode, string $scope, ?int $bcId): ?array
    {
        $mode = $mode === 'voice' ? 'voice' : 'video';
        $cbcName = (string) DB::val('SELECT name FROM users WHERE id = ?', [$cbcUserId]);
        if ($scope === 'bc') {
            $bc = $bcId ? DB::one('SELECT id, name FROM bcs WHERE id = ? AND cbc_id = ? AND is_active = 1', [$bcId, $cbcUserId]) : null;
            if (!$bc) {
                return null;
            }
            $audience = 'one_bc';
            $title = $cbcName . ' → ' . $bc['name'] . ' (' . ($mode === 'voice' ? 'voice' : 'video') . ' call)';
        } else {
            $audience = 'cbc_bcs';
            $bcId = null;
            $title = $cbcName . ' – ' . ($mode === 'voice' ? 'voice' : 'video') . ' meeting';
        }
        self::endByCbc($cbcUserId); // one live meeting per CBC at a time
        $room = 'BCReport-' . bin2hex(random_bytes(6));
        $id = DB::insert(
            'INSERT INTO meetings (title, room, audience, mode, created_by, host_cbc_id, target_bc_id, is_active, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, 1, NOW())',
            [mb_substr($title, 0, 160), $room, $audience, $mode, $cbcUserId, $bcId]
        );
        self::notifyStart($id);
        return ['id' => $id, 'url' => self::url($room, $mode), 'mode' => $mode];
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
             ) ORDER BY id DESC LIMIT 1",
            [(int) ($bc['branch_id'] ?? 0), (int) ($bc['cbc_id'] ?? 0), (int) ($bc['id'] ?? 0)]
        );
        return $row ? ['title' => $row['title'], 'url' => self::url($row['room'], $row['mode']), 'mode' => $row['mode']] : null;
    }

    /** Latest active meeting a CBC can join/host, or null. */
    public static function forCbc(int $cbcUserId): ?array
    {
        $row = DB::one(
            "SELECT id, title, room, mode, host_cbc_id FROM meetings
             WHERE is_active = 1 AND (
                 audience IN ('all','cbc')
                 OR (audience IN ('cbc_bcs','one_bc') AND host_cbc_id = ?)
             ) ORDER BY id DESC LIMIT 1",
            [$cbcUserId]
        );
        return $row ? ['id' => (int) $row['id'], 'title' => $row['title'], 'url' => self::url($row['room'], $row['mode']),
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
        }
        return null; // 'cbc' -> staff only
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
                    Fcm::pushToBc((int) $b['id'], ['id' => 0, 'kind' => 'meeting', 'title' => $title, 'body' => $body]);
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
