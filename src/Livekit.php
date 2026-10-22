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
     */
    public static function token(string $room, string $identity, string $name, bool $canPublish = true, int $ttl = 7200): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $key = Settings::get('livekit_key');
        $secret = Settings::get('livekit_secret');
        $now = time();
        $payload = [
            'iss' => $key,
            'sub' => $identity,
            'nbf' => $now - 10,
            'exp' => $now + max(300, $ttl),
            'name' => mb_substr($name, 0, 80),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];
        $head = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
        return "{$head}.{$body}.{$sig}";
    }
}
