<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\DB;
use App\Livekit;

/** Join a live meeting from the web panel (session auth) on our own LiveKit server. */
$user = Auth::require();
$id = (int) q('id');
$m = $id ? DB::one('SELECT * FROM meetings WHERE id = ? AND is_active = 1', [$id]) : null;
if (!$m) {
    flash('danger', 'Ye meeting live nahi hai / यह मीटिंग अब चालू नहीं है');
    redirect('/meetings.php');
}

$canModerate = in_array($user['role'], ['admin', 'supervisor'], true)
    || (int) ($m['host_cbc_id'] ?? 0) === (int) $user['id'];

// host moderation from the meeting screen (session auth; CSRF via X-CSRF-TOKEN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && q('mute') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canModerate) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'not host']);
        exit;
    }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true) ?: [];
    $muted = !empty($in['muted']);
    $who = trim((string) ($in['identity'] ?? ''));
    $room = (string) $m['room'];
    if ($who === '*') {
        $me = 'user-' . (int) $user['id'];
        $n = 0;
        foreach (Livekit::participants($room) as $p) {
            if ($p['identity'] !== $me && Livekit::setMuted($room, $p['identity'], $muted)) {
                $n++;
            }
        }
        echo json_encode(['ok' => true, 'changed' => $n]);
        exit;
    }
    echo json_encode(['ok' => $who !== '' && Livekit::setMuted($room, $who, $muted)]);
    exit;
}
$token = Livekit::token((string) $m['room'], 'user-' . (int) $user['id'], (string) $user['name']);
if (!$token) {
    flash('danger', 'Meeting server configure nahi hai (livekit settings)');
    redirect('/meetings.php');
}
$cfg = [
    'ok' => true,
    'url' => Livekit::url(),
    'token' => $token,
    'room' => $m['room'],
    'mode' => $m['mode'] ?? 'video',
    'title' => $m['title'],
    'name' => $user['name'],
    // panel admins/supervisors moderate; a CBC only in their own meeting
    'is_host' => $canModerate,
    'mute_url' => '/meet.php?id=' . $id . '&mute=1',
    'csrf' => App\Csrf::token(),
];
header('Cache-Control: no-store');
?><!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($m['title']) ?> · Meeting</title>
<link rel="stylesheet" href="/app/meet.css?v=2">
</head>
<body>
<div id="meet"></div>
<script>window.MEET_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
<script src="/app/meet.js?v=2"></script>
</body>
</html>
