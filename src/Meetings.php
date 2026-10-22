<?php
declare(strict_types=1);

namespace App;

/**
 * Video meetings for BCs and CBC supervisors. A meeting is a Jitsi room;
 * the app opens the room URL (camera/mic in the phone browser / Jitsi app).
 */
final class Meetings
{
    public static function base(): string
    {
        return rtrim(Settings::get('meeting_base_url') ?: 'https://meet.jit.si/', '/') . '/';
    }

    public static function url(string $room): string
    {
        return self::base() . rawurlencode($room);
    }

    public static function create(string $title, string $audience, ?int $branchId, ?int $by): int
    {
        $audience = in_array($audience, ['all', 'bc', 'cbc', 'branch'], true) ? $audience : 'all';
        $room = 'BCReport-' . bin2hex(random_bytes(6));
        return DB::insert(
            'INSERT INTO meetings (title, room, audience, branch_id, created_by, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())',
            [mb_substr($title, 0, 160), $room, $audience, $audience === 'branch' ? $branchId : null, $by]
        );
    }

    public static function end(int $id): void
    {
        DB::exec('UPDATE meetings SET is_active = 0, ended_at = NOW() WHERE id = ? AND is_active = 1', [$id]);
    }

    /** Latest active meeting a BC can join, or null. */
    public static function forBc(array $bc): ?array
    {
        $row = DB::one(
            "SELECT title, room FROM meetings
             WHERE is_active = 1 AND (audience IN ('all','bc') OR (audience = 'branch' AND branch_id = ?))
             ORDER BY id DESC LIMIT 1",
            [$bc['branch_id'] ?? 0]
        );
        return $row ? ['title' => $row['title'], 'url' => self::url($row['room'])] : null;
    }

    /** Latest active meeting a CBC can join, or null. */
    public static function forCbc(): ?array
    {
        $row = DB::one("SELECT title, room FROM meetings WHERE is_active = 1 AND audience IN ('all','cbc') ORDER BY id DESC LIMIT 1");
        return $row ? ['title' => $row['title'], 'url' => self::url($row['room'])] : null;
    }

    /** Notify the audience that a meeting has started (in-app notice + instant Firebase). */
    public static function notifyStart(int $id): void
    {
        $m = DB::one('SELECT * FROM meetings WHERE id = ?', [$id]);
        if (!$m) {
            return;
        }
        $title = mb_substr('📹 Video meeting: ' . $m['title'], 0, 150);
        $body = 'Meeting shuru ho gayi hai. App kholkar "Join" dabayein.';

        if (in_array($m['audience'], ['all', 'bc', 'branch'], true)) {
            $w = 'is_active = 1';
            $p = [];
            if ($m['audience'] === 'branch') {
                $w .= ' AND branch_id = ?';
                $p[] = (int) $m['branch_id'];
            }
            DB::exec("INSERT INTO app_notifications (bc_id, kind, title, body, created_at) SELECT id, 'meeting', ?, ?, NOW() FROM bcs WHERE {$w}",
                array_merge([$title, $body], $p));
            foreach (DB::all("SELECT DISTINCT b.id FROM bcs b JOIN app_tokens t ON t.bc_id = b.id WHERE {$w} AND t.fcm_token IS NOT NULL LIMIT 500", $p) as $b) {
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
