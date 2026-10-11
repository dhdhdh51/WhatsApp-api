<?php
declare(strict_types=1);

namespace App;

/** Minimal client for the WAHA (devlikeapro/waha) HTTP API. */
final class WahaClient
{
    private string $base;
    private string $key;
    private string $session;

    public function __construct()
    {
        $this->base = rtrim(Env::get('WAHA_URL', 'http://127.0.0.1:3000'), '/');
        $this->key = (string) Env::get('WAHA_API_KEY', '');
        $this->session = (string) Env::get('WAHA_SESSION', 'default');
    }

    public function session(): string
    {
        return $this->session;
    }

    /**
     * @return array{status:int, json:mixed, raw:string, error:string}
     */
    public function request(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json'];
        if ($this->key !== '') {
            $headers[] = 'X-Api-Key: ' . $this->key;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $raw = is_string($raw) ? $raw : '';
        $json = $raw !== '' ? json_decode($raw, true) : null;
        return ['status' => $status, 'json' => $json, 'raw' => $raw, 'error' => $err];
    }

    private static function ok(array $r): bool
    {
        return $r['status'] >= 200 && $r['status'] < 300;
    }

    private static function errorText(array $r): string
    {
        if ($r['error'] !== '') {
            return 'HTTP error: ' . $r['error'];
        }
        $msg = is_array($r['json']) ? ($r['json']['message'] ?? $r['json']['error'] ?? '') : '';
        if (is_array($msg)) {
            $msg = implode('; ', array_map('strval', $msg));
        }
        return 'HTTP ' . $r['status'] . ($msg !== '' ? ': ' . $msg : ': ' . mb_substr($r['raw'], 0, 200));
    }

    // ---------------------------------------------------------------- session

    /** @return array{ok:bool, status:string, me:?array, raw:mixed, error:string} */
    public function sessionInfo(): array
    {
        $r = $this->request('GET', '/api/sessions/' . rawurlencode($this->session), null, 10);
        if ($r['status'] === 404) {
            return ['ok' => true, 'status' => 'NOT_CREATED', 'me' => null, 'raw' => $r['json'], 'error' => ''];
        }
        if (!self::ok($r)) {
            return ['ok' => false, 'status' => 'UNREACHABLE', 'me' => null, 'raw' => $r['json'], 'error' => self::errorText($r)];
        }
        return [
            'ok' => true,
            'status' => (string) ($r['json']['status'] ?? 'UNKNOWN'),
            'me' => $r['json']['me'] ?? null,
            'raw' => $r['json'],
            'error' => '',
        ];
    }

    public function webhookConfig(): array
    {
        $secret = (string) Env::get('WEBHOOK_SECRET', '');
        return [
            'webhooks' => [[
                'url' => rtrim((string) Env::get('APP_URL', ''), '/') . '/webhook.php',
                'events' => ['message', 'session.status'],
                'hmac' => ['key' => $secret],
                'customHeaders' => [['name' => 'X-Webhook-Secret', 'value' => $secret]],
                'retries' => ['policy' => 'constant', 'delaySeconds' => 5, 'attempts' => 5],
            ]],
        ];
    }

    /**
     * Creates the session if missing and starts it.
     * By default the webhook comes from the global WHATSAPP_HOOK_* env vars in docker-compose.yml.
     * Set WAHA_SESSION_WEBHOOK=1 in .env to store the webhook in the session config instead
     * (then remove WHATSAPP_HOOK_URL from docker-compose.yml, otherwise every event arrives twice).
     */
    public function ensureSession(): array
    {
        $info = $this->sessionInfo();
        if (!$info['ok']) {
            return ['ok' => false, 'error' => $info['error']];
        }
        $useSessionHook = Env::bool('WAHA_SESSION_WEBHOOK');
        if ($info['status'] === 'NOT_CREATED') {
            $body = ['name' => $this->session, 'start' => true];
            if ($useSessionHook) {
                $body['config'] = $this->webhookConfig();
            }
            $r = $this->request('POST', '/api/sessions', $body);
            return self::ok($r) ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => self::errorText($r)];
        }
        if ($useSessionHook) {
            $r = $this->request('PUT', '/api/sessions/' . rawurlencode($this->session), ['config' => $this->webhookConfig()]);
            if (!self::ok($r)) {
                return ['ok' => false, 'error' => self::errorText($r)];
            }
        }
        if (in_array($info['status'], ['STOPPED', 'FAILED'], true)) {
            return $this->sessionAction('start');
        }
        return ['ok' => true, 'error' => ''];
    }

    public function sessionAction(string $action): array
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'logout'], true)) {
            return ['ok' => false, 'error' => 'bad action'];
        }
        $r = $this->request('POST', '/api/sessions/' . rawurlencode($this->session) . '/' . $action, [], 60);
        return self::ok($r) ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => self::errorText($r)];
    }

    /** @return array{mimetype:string,data:string}|null base64 PNG */
    public function qr(): ?array
    {
        $r = $this->request('GET', '/api/' . rawurlencode($this->session) . '/auth/qr?format=image', null, 15);
        if (!self::ok($r)) {
            return null;
        }
        if (is_array($r['json']) && isset($r['json']['data'])) {
            return ['mimetype' => (string) ($r['json']['mimetype'] ?? 'image/png'), 'data' => (string) $r['json']['data']];
        }
        // some versions return the PNG directly
        if (str_starts_with($r['raw'], "\x89PNG")) {
            return ['mimetype' => 'image/png', 'data' => base64_encode($r['raw'])];
        }
        return null;
    }

    /**
     * Link without scanning: WhatsApp → Linked devices → Link with phone number → enter this code.
     * @return array{ok:bool, code:?string, error:string}
     */
    public function requestPairingCode(string $tenDigits): array
    {
        $r = $this->request('POST', '/api/' . rawurlencode($this->session) . '/auth/request-code', ['phoneNumber' => '91' . $tenDigits], 30);
        if (!self::ok($r)) {
            return ['ok' => false, 'code' => null, 'error' => self::errorText($r)];
        }
        $code = is_array($r['json']) ? (string) ($r['json']['code'] ?? '') : '';
        return $code !== '' ? ['ok' => true, 'code' => $code, 'error' => ''] : ['ok' => false, 'code' => null, 'error' => 'No code returned'];
    }

    // ---------------------------------------------------------------- messaging

    /** @return array{ok:bool, id:?string, error:string, http:int} */
    public function sendText(string $chatId, string $text, ?string $replyTo = null): array
    {
        $body = ['session' => $this->session, 'chatId' => $chatId, 'text' => $text, 'linkPreview' => false];
        if ($replyTo) {
            $body['reply_to'] = $replyTo;
        }
        $r = $this->request('POST', '/api/sendText', $body, 45);
        if (!self::ok($r)) {
            return ['ok' => false, 'id' => null, 'error' => self::errorText($r), 'http' => $r['status']];
        }
        return ['ok' => true, 'id' => self::extractId($r['json']), 'error' => '', 'http' => $r['status']];
    }

    public function startTyping(string $chatId): void
    {
        $this->request('POST', '/api/startTyping', ['session' => $this->session, 'chatId' => $chatId], 10);
    }

    public function stopTyping(string $chatId): void
    {
        $this->request('POST', '/api/stopTyping', ['session' => $this->session, 'chatId' => $chatId], 10);
    }

    public function sendSeen(string $chatId, ?string $messageId = null): void
    {
        $body = ['session' => $this->session, 'chatId' => $chatId];
        if ($messageId) {
            $body['messageIds'] = [$messageId];
        }
        $this->request('POST', '/api/sendSeen', $body, 10);
    }

    /** Resolve a hidden "@lid" id to a 10-digit phone number (null if unknown). */
    public function lidToPhone(string $lid): ?string
    {
        $r = $this->request('GET', '/api/' . rawurlencode($this->session) . '/lids/' . rawurlencode($lid), null, 10);
        if (!self::ok($r) || !is_array($r['json'])) {
            return null;
        }
        return Phone::fromChatId($r['json']['pn'] ?? null);
    }

    /** Message ids differ per engine: string, {_serialized}, or {key:{id}}. */
    public static function extractId(mixed $msg): ?string
    {
        if (!is_array($msg)) {
            return null;
        }
        $id = $msg['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }
        if (is_array($id)) {
            if (!empty($id['_serialized'])) {
                return (string) $id['_serialized'];
            }
            if (!empty($id['id'])) {
                return (string) $id['id'];
            }
        }
        if (!empty($msg['key']['id'])) {
            return (string) $msg['key']['id'];
        }
        if (!empty($msg['_data']['id']['_serialized'])) {
            return (string) $msg['_data']['id']['_serialized'];
        }
        return null;
    }

    /** "true_919876543210@c.us_3EB0ABCD" -> "3EB0ABCD" (engines disagree on the prefix, the tail is stable). */
    public static function shortId(?string $id): ?string
    {
        if (!$id) {
            return null;
        }
        $pos = strrpos($id, '_');
        return $pos === false ? $id : substr($id, $pos + 1);
    }
}
