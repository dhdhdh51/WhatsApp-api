<?php
declare(strict_types=1);

namespace App;

/**
 * Guest links for live meetings. An Admin or BC Supervisor who moderates a meeting creates a link
 * for an outside officer. The officer opens /guest.php?k=..., types a name and joins view-only as
 * "guest-<id>". Mic and camera come only when the host brings them onto the stage. A link dies
 * when its meeting ends or when the host closes it (everyone who came through it is removed).
 */
final class GuestLinks
{
    /** Joins per IP in 15 minutes, joins per link in one hour, chat messages per guest in one minute. */
    public const JOINS_PER_IP = 10;
    public const JOINS_PER_LINK = 40;
    public const CHAT_PER_MIN = 15;
    /** A guest broadcast's link is meant for many people, often from one office network. */
    public const GUEST_BROADCAST_JOINS_PER_IP = 30;
    public const GUEST_BROADCAST_JOINS_PER_LINK = 300;

    /** Creates a link and returns the URL. The key is shown once; only its hash is stored. */
    public static function create(int $meetingId, int $userId): string
    {
        $key = bin2hex(random_bytes(24));
        DB::insert('INSERT INTO meeting_guest_links (meeting_id, token_hash, created_by, created_at) VALUES (?, ?, ?, NOW())',
            [$meetingId, hash('sha256', $key), $userId]);
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/guest.php?k=' . $key;
    }

    /** The link + its meeting, only while both are usable. */
    public static function find(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $key)) {
            return null;
        }
        return DB::one(
            'SELECT l.id link_id, l.meeting_id, m.* FROM meeting_guest_links l JOIN meetings m ON m.id = l.meeting_id
             WHERE l.token_hash = ? AND l.revoked_at IS NULL AND m.is_active = 1',
            [hash('sha256', $key)]
        );
    }

    /** Live links of one meeting with the people who joined through them. */
    public static function forMeeting(int $meetingId): array
    {
        $links = DB::all(
            'SELECT l.id, l.created_at, u.name by_name FROM meeting_guest_links l LEFT JOIN users u ON u.id = l.created_by
             WHERE l.meeting_id = ? AND l.revoked_at IS NULL ORDER BY l.id',
            [$meetingId]
        );
        foreach ($links as &$l) {
            $l['guests'] = array_column(DB::all('SELECT name FROM meeting_guests WHERE link_id = ? ORDER BY id', [$l['id']]), 'name');
        }
        return $links;
    }

    /** Closes a link and removes everyone who joined through it from the room. Returns how many were removed. */
    public static function revoke(int $linkId, array $m): int
    {
        if (!DB::exec('UPDATE meeting_guest_links SET revoked_at = NOW() WHERE id = ? AND meeting_id = ? AND revoked_at IS NULL', [$linkId, (int) $m['id']])) {
            return 0;
        }
        $n = 0;
        foreach (DB::all('SELECT id FROM meeting_guests WHERE link_id = ?', [$linkId]) as $g) {
            $actor = 'guest-' . (int) $g['id'];
            Meetings::revokeSpeak((int) $m['id'], $actor);
            if (Meetings::isBroadcast($m)) {
                Meetings::setStage($m, $actor, false);   // frees the stage slot if they were on it
            }
            if (Livekit::removeParticipant((string) $m['room'], $actor)) {
                $n++;
            }
        }
        return $n;
    }

    /** Too many joins from this IP or through this link? Records the attempt. $guestBroadcast = the link's meeting is a guest broadcast. */
    public static function joinLimited(int $linkId, string $ip, bool $guestBroadcast = false): bool
    {
        $byIp = (int) DB::val("SELECT COUNT(*) FROM login_attempts WHERE username LIKE 'guest:%' AND ip = ? AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)", [$ip]);
        $byLink = (int) DB::val('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at >= (NOW() - INTERVAL 1 HOUR)', ['guest:' . $linkId]);
        $maxIp = $guestBroadcast ? self::GUEST_BROADCAST_JOINS_PER_IP : self::JOINS_PER_IP;
        $maxLink = $guestBroadcast ? self::GUEST_BROADCAST_JOINS_PER_LINK : self::JOINS_PER_LINK;
        if ($byIp >= $maxIp || $byLink >= $maxLink) {
            return true;
        }
        DB::insert('INSERT INTO login_attempts (ip, username, success, attempted_at) VALUES (?, ?, 1, NOW())', [$ip, 'guest:' . $linkId]);
        return false;
    }

    public static function chatLimited(int $meetingId, string $actor): bool
    {
        return (int) DB::val('SELECT COUNT(*) FROM meeting_messages WHERE meeting_id = ? AND actor = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)',
            [$meetingId, $actor]) >= self::CHAT_PER_MIN;
    }

    /** "Ramesh Kumar" -> safe display name, or '' when unusable. */
    public static function cleanName(string $raw): string
    {
        $n = trim((string) preg_replace('/[\p{C}<>"\'`\\\\]+/u', '', $raw));
        $n = (string) preg_replace('/\s+/u', ' ', $n);
        return mb_strlen($n) >= 2 ? mb_substr($n, 0, 50) : '';
    }
}
