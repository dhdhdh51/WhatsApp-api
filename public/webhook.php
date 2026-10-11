<?php
declare(strict_types=1);

/**
 * WAHA webhook endpoint.  POST https://your-domain/webhook.php
 *
 * Authentication (either is enough, both are configured by docker-compose.yml):
 *   - X-Webhook-Hmac: HMAC-SHA512 of the raw body with WEBHOOK_SECRET (WHATSAPP_HOOK_HMAC_KEY)
 *   - X-Webhook-Secret: WEBHOOK_SECRET (WHATSAPP_HOOK_CUSTOM_HEADERS)
 */

require __DIR__ . '/../src/bootstrap.php';

use App\BotHandler;
use App\Env;
use App\Logger;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{"error":"method not allowed"}');
}

$secret = (string) Env::get('WEBHOOK_SECRET', '');
$raw = (string) file_get_contents('php://input', false, null, 0, 1024 * 1024);

if (strlen($secret) < 16) {
    Logger::error('WEBHOOK_SECRET missing or too short – rejecting webhook');
    http_response_code(500);
    exit('{"error":"not configured"}');
}

$hmacHeader = (string) ($_SERVER['HTTP_X_WEBHOOK_HMAC'] ?? '');
$secretHeader = (string) ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
$valid = false;
if ($hmacHeader !== '') {
    $algo = strtolower((string) ($_SERVER['HTTP_X_WEBHOOK_HMAC_ALGORITHM'] ?? 'sha512'));
    $algo = in_array($algo, ['sha512', 'sha256'], true) ? $algo : 'sha512';
    $valid = hash_equals(hash_hmac($algo, $raw, $secret), strtolower($hmacHeader));
} elseif ($secretHeader !== '') {
    $valid = hash_equals($secret, $secretHeader);
}
if (!$valid) {
    Logger::warn('Webhook rejected: bad signature', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    http_response_code(401);
    exit('{"error":"unauthorized"}');
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('{"error":"bad json"}');
}

$expectedSession = Env::get('WAHA_SESSION', 'default');
if (isset($event['session']) && $event['session'] !== $expectedSession) {
    exit('{"ok":true,"ignored":"other session"}');
}

// Answer WAHA immediately, then do the work (the BC reply is still sent right away).
echo '{"ok":true}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();          // PHP-FPM (Nginx)
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();        // LiteSpeed / OpenLiteSpeed (aaPanel)
}
ignore_user_abort(true);
set_time_limit(60);

try {
    (new BotHandler())->handle($event);
} catch (Throwable $e) {
    Logger::error('Webhook handling failed: ' . $e->getMessage(), ['event' => $event['event'] ?? '']);
}
