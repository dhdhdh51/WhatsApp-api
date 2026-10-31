<?php
declare(strict_types=1);

namespace App;

/**
 * Self-hosted group video/voice (LiveKit SFU on this server).
 * Credentials live in the settings table (livekit_url / livekit_key / livekit_secret),
 * never in git. Access tokens are plain JWTs signed with the API secret.
 */
final class Livekit
{
    public static function url(): string
    {
        return Settings::get('livekit_url');
    }

    public static function enabled(): bool
    {
        return self::url() !== '' && Settings::get('livekit_key') !== '' && Settings::get('livekit_secret') !== '';
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Join token for one participant in one room.
     * @param string $identity unique per participant (e.g. "bc-12" / "user-3")
     * @param list<string>|null $sources limit what may be published, e.g. ['camera'] = camera but no
     *                                   microphone (broadcast audience). null = no limit.
     */
    public static function token(string $room, string $identity, string $name, bool $canPublish = true, int $ttl = 7200, ?array $sources = null): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $key = Settings::get('livekit_key');
        $secret = Settings::get('livekit_secret');
        $now = time();
        $video = [
            'room' => $room,
            'roomJoin' => true,
            'canPublish' => $canPublish,
            'canSubscribe' => true,
            'canPublishData' => true,
        ];
        if ($canPublish && $sources) {
            $video['canPublishSources'] = array_values($sources);
        }
        $payload = [
            'iss' => $key,
            'sub' => $identity,
            'nbf' => $now - 10,
            'exp' => $now + max(300, $ttl),
            'name' => mb_substr($name, 0, 80),
            'video' => $video,
        ];
        return self::sign($payload);
    }

    /** Short-lived admin token for the server-side RoomService API ($create: DeleteRoom needs roomCreate). */
    private static function adminToken(string $room, bool $create = false): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $now = time();
        return self::sign([
            'iss' => Settings::get('livekit_key'),
            'sub' => 'server',
            'nbf' => $now - 10,
            'exp' => $now + 120,
            'video' => ['room' => $room, 'roomAdmin' => true] + ($create ? ['roomCreate' => true] : []),
        ]);
    }

    private static function sign(array $payload): string
    {
        $secret = Settings::get('livekit_secret');
        $head = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
        return "{$head}.{$body}.{$sig}";
    }

    /**
     * Close a meeting's room: everyone still in it is disconnected (reason ROOM_DELETED), so every
     * screen leaves the meeting. A room nobody is in any more is fine (404 = already closed).
     */
    public static function deleteRoom(string $room): bool
    {
        if ($room === '' || !self::enabled()) {
            return false;
        }
        return self::rpc('DeleteRoom', $room, ['room' => $room], true, [404]) !== null;
    }

    /** Call the local LiveKit RoomService (Twirp). Returns decoded JSON or null. $quiet = HTTP codes that are not an error. */
    private static function rpc(string $method, string $room, array $body, bool $create = false, array $quiet = []): ?array
    {
        $tok = self::adminToken($room, $create);
        if (!$tok) {
            return null;
        }
        $ch = curl_init('http://127.0.0.1:7880/twirp/livekit.RoomService/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $tok],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (in_array($code, $quiet, true)) {
            return [];
        }
        if ($code !== 200) {
            Logger::warn('LiveKit RPC failed', ['method' => $method, 'http' => $code, 'body' => mb_substr((string) $raw, 0, 200)]);
            return null;
        }
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : [];
    }

    /** @return list<array{identity:string,name:string,muted:bool}> */
    public static function participants(string $room): array
    {
        $r = self::rpc('ListParticipants', $room, ['room' => $room]);
        $out = [];
        foreach ($r['participants'] ?? [] as $p) {
            $muted = true;
            foreach ($p['tracks'] ?? [] as $t) {
                if (($t['type'] ?? '') === 'AUDIO') {
                    $muted = !empty($t['muted']);
                }
            }
            $out[] = ['identity' => (string) ($p['identity'] ?? ''), 'name' => (string) ($p['name'] ?? ''), 'muted' => $muted];
        }
        return $out;
    }

    /**
     * Host action (broadcast): let a participant speak (microphone + camera), or take the
     * microphone back. With $keepCamera the participant keeps their camera when the mic is taken
     * back (a broadcast is a group video call: everyone is seen, only allowed people talk).
     * canPublishData / canSubscribe stay on so chat and watching keep working either way.
     */
    public static function setCanPublish(string $room, string $identity, bool $canPublish, bool $keepCamera = false): bool
    {
        $perm = [
            'can_subscribe' => true,
            'can_publish' => $canPublish || $keepCamera,
            'can_publish_data' => true,
        ];
        if (!$canPublish && $keepCamera) {
            $perm['can_publish_sources'] = ['CAMERA'];   // empty list = every source allowed
        }
        $r = self::rpc('UpdateParticipant', $room, ['room' => $room, 'identity' => $identity, 'permission' => $perm]);
        return $r !== null;
    }

    /** Host action: mute / unmute one participant's microphone. */
    public static function setMuted(string $room, string $identity, bool $muted): bool
    {
        $r = self::rpc('ListParticipants', $room, ['room' => $room]);
        foreach ($r['participants'] ?? [] as $p) {
            if ((string) ($p['identity'] ?? '') !== $identity) {
                continue;
            }
            $ok = false;
            foreach ($p['tracks'] ?? [] as $t) {
                if (($t['type'] ?? '') !== 'AUDIO' || empty($t['sid'])) {
                    continue;
                }
                $res = self::rpc('MutePublishedTrack', $room, [
                    'room' => $room, 'identity' => $identity, 'track_sid' => $t['sid'], 'muted' => $muted,
                ]);
                $ok = $ok || $res !== null;
            }
            return $ok;
        }
        return false;
    }
}
