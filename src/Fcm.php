<?php
declare(strict_types=1);

namespace App;

/**
 * Firebase Cloud Messaging (HTTP v1) – instant push to the BC app.
 * Service account JSON: FIREBASE_SA_FILE in .env (default storage/firebase-sa.json, never in git / public).
 */
final class Fcm
{
    private static ?array $sa = null;

    public static function file(): string
    {
        return (string) Env::get('FIREBASE_SA_FILE', BASE_PATH . '/storage/firebase-sa.json');
    }

    public static function enabled(): bool
    {
        return self::account() !== null;
    }

    private static function account(): ?array
    {
        if (self::$sa === null) {
            $f = self::file();
            $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
            self::$sa = (is_array($j) && !empty($j['private_key']) && !empty($j['client_email']) && !empty($j['project_id'])) ? $j : [];
        }
        return self::$sa ?: null;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** OAuth access token (cached ~50 min in settings). */
    public static function accessToken(bool $fresh = false): ?string
    {
        $sa = self::account();
        if (!$sa) {
            return null;
        }
        $cached = Settings::get('fcm_access_token');
        if (!$fresh && $cached !== '' && (int) Settings::get('fcm_access_exp') > time() + 60) {
            return $cached;
        }
        $now = time();
        $head = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = self::b64(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $sig = '';
        if (!openssl_sign("{$head}.{$claim}", $sig, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            Logger::error('FCM: could not sign JWT (bad private key?)');
            return null;
        }
        $jwt = "{$head}.{$claim}." . self::b64($sig);
        $r = self::http('POST', $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            ['Content-Type: application/x-www-form-urlencoded']);
        $tok = is_array($r['json']) ? ($r['json']['access_token'] ?? null) : null;
        if (!$tok) {
            Logger::error('FCM: token request failed', ['http' => $r['status'], 'body' => mb_substr($r['raw'], 0, 300)]);
            return null;
        }
        Settings::set('fcm_access_token', (string) $tok);
        Settings::set('fcm_access_exp', (string) ($now + (int) ($r['json']['expires_in'] ?? 3600) - 600));
        return (string) $tok;
    }

    /**
     * Data message to one device. Returns 'ok', 'invalid' (token dead – remove it) or 'error'.
     * @param array<string,string|int> $data
     */
    public static function send(string $deviceToken, array $data): string
    {
        $sa = self::account();
        $access = self::accessToken();
        if (!$sa || !$access) {
            return 'error';
        }
        $msg = ['message' => [
            'token' => $deviceToken,
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'HIGH', 'ttl' => '86400s'],
        ]];
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string) $sa['project_id']) . '/messages:send';
        $r = self::http('POST', $url, json_encode($msg, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json', 'Authorization: Bearer ' . $access]);
        if ($r['status'] === 401) {
            $access = self::accessToken(true);
            $r = $access ? self::http('POST', $url, json_encode($msg, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json', 'Authorization: Bearer ' . $access]) : $r;
        }
        if ($r['status'] === 200) {
            return 'ok';
        }
        $err = is_array($r['json']) ? json_encode($r['json']['error'] ?? []) : $r['raw'];
        if ($r['status'] === 404 || str_contains((string) $err, 'UNREGISTERED') || ($r['status'] === 400 && str_contains((string) $err, 'registration token'))) {
            return 'invalid';
        }
        Logger::warn('FCM send failed', ['http' => $r['status'], 'error' => mb_substr((string) $err, 0, 300)]);
        return 'error';
    }

    /** Push to every device of a BC; dead tokens are removed. Returns devices reached. */
    public static function pushToBc(int $bcId, array $data): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (DB::all('SELECT id, fcm_token FROM app_tokens WHERE bc_id = ? AND fcm_token IS NOT NULL', [$bcId]) as $t) {
            $res = self::send((string) $t['fcm_token'], $data);
            if ($res === 'ok') {
                $n++;
            } elseif ($res === 'invalid') {
                DB::exec('UPDATE app_tokens SET fcm_token = NULL WHERE id = ?', [$t['id']]);
            }
        }
        return $n;
    }

    /** Push to every device of a CBC staff user; dead tokens are removed. Returns devices reached. */
    public static function pushToCbc(int $userId, array $data): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (DB::all('SELECT id, fcm_token FROM cbc_app_tokens WHERE user_id = ? AND fcm_token IS NOT NULL', [$userId]) as $t) {
            $res = self::send((string) $t['fcm_token'], $data);
            if ($res === 'ok') {
                $n++;
            } elseif ($res === 'invalid') {
                DB::exec('UPDATE cbc_app_tokens SET fcm_token = NULL WHERE id = ?', [$t['id']]);
            }
        }
        return $n;
    }

    /** @return array{status:int, json:mixed, raw:string} */
    private static function http(string $method, string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $raw = is_string($raw) ? $raw : '';
        return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
    }
}
