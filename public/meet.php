<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\DB;
use App\Livekit;
use App\Meetings;

/** Join a live meeting / broadcast from the web panel (session auth) on our own LiveKit server. */
$user = Auth::require();
$id = (int) q('id');
$m = $id ? DB::one('SELECT * FROM meetings WHERE id = ? AND is_active = 1', [$id]) : null;
if (!$m) {
    if (q('chat') !== '' || q('speak') !== '' || q('mute') !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(410);
        echo json_encode(['ok' => false, 'error' => 'ended']);
        exit;
    }
    flash('danger', 'Ye meeting live nahi hai / यह मीटिंग अब चालू नहीं है');
    redirect('/meetings.php');
}

$isBroadcast = Meetings::isBroadcast($m);
$meActor = 'user-' . (int) $user['id'];
$canModerate = in_array($user['role'], ['admin', 'supervisor'], true)
    || (int) ($m['host_cbc_id'] ?? 0) === (int) $user['id'];

// ---- persistent in-meeting group chat (session auth; CSRF on POST handled by web.php) ----
if (q('chat') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true) ?: [];
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if ($body === '' || mb_strlen($body) > 2000) {
            echo json_encode(['ok' => false, 'error' => 'empty']);
            exit;
        }
        Meetings::chatPost($id, $meActor, (string) $user['name'], true, $body);
    }
    $after = (int) q('after', 0);
    echo json_encode(['ok' => true, 'rows' => Meetings::chatFormat(Meetings::chatSince($id, $after), $meActor)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- host promote / demote a participant to speak (broadcast) ----
if (q('speak') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canModerate) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'not host']);
        exit;
    }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true) ?: [];
    $who = trim((string) ($in['identity'] ?? ''));
    $allow = !empty($in['allow']);
    if ($who === '' || $who === $meActor) {
        echo json_encode(['ok' => false, 'error' => 'bad identity']);
        exit;
    }
    if ($allow) {
        Meetings::allowSpeak($id, $who);
    } else {
        Meetings::revokeSpeak($id, $who);
    }
    echo json_encode(['ok' => Livekit::setCanPublish((string) $m['room'], $who, $allow), 'allow' => $allow]);
    exit;
}

// ---- host moderation: mute / unmute (session auth; CSRF via X-CSRF-TOKEN) ----
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
        $n = 0;
        foreach (Livekit::participants($room) as $p) {
            if ($p['identity'] !== $meActor && Livekit::setMuted($room, $p['identity'], $muted)) {
                $n++;
            }
        }
        echo json_encode(['ok' => true, 'changed' => $n]);
        exit;
    }
    echo json_encode(['ok' => $who !== '' && Livekit::setMuted($room, $who, $muted)]);
    exit;
}

// ---- scheme progress summary (broadcast "आज की प्रस्तुति" panel) ----
if (q('summary') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'rows' => Meetings::schemeSummary()], JSON_UNESCAPED_UNICODE);
    exit;
}

// broadcast audience is view-only unless you moderate or were promoted to speak
$canPublish = !$isBroadcast || $canModerate || Meetings::isSpeaker($id, $meActor);
$token = Livekit::token((string) $m['room'], $meActor, (string) $user['name'], $canPublish);
if (!$token) {
    flash('danger', 'Meeting server configure nahi hai (livekit settings)');
    redirect('/meetings.php');
}
$hostU = DB::one('SELECT name, role FROM users WHERE id = ?', [(int) (($m['created_by'] ?? 0) ?: ($m['host_cbc_id'] ?? 0))]);
$cfg = [
    'ok' => true,
    'url' => Livekit::url(),
    'token' => $token,
    'room' => $m['room'],
    'mode' => $m['mode'] ?? 'video',
    'title' => $m['title'],
    'name' => $user['name'],
    'meeting_id' => $id,
    'is_broadcast' => $isBroadcast,
    'can_publish' => $canPublish,
    // panel admins/supervisors moderate; a CBC only in their own meeting
    'is_host' => $canModerate,
    'brand' => 'BC Monitoring App',
    'bank' => 'Central Bank of India',
    'subtitle' => 'APY, PMJJBY, PMSBY एवं PMJDY की प्रगति समीक्षा',
    'host_name' => $hostU['name'] ?? 'Host',
    'host_role' => $hostU ? Auth::roleLabel((string) $hostU['role']) : '',
    'mute_url' => '/meet.php?id=' . $id . '&mute=1',
    'chat_url' => '/meet.php?id=' . $id . '&chat=1',
    'promote_url' => $canModerate ? '/meet.php?id=' . $id . '&speak=1' : null,
    'summary_url' => '/meet.php?id=' . $id . '&summary=1',
    'csrf' => App\Csrf::token(),
];
header('Cache-Control: no-store');
?><!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($m['title']) ?> · <?= $isBroadcast ? 'Broadcast' : 'Meeting' ?></title>
<link rel="stylesheet" href="/app/meet.css?v=6">
</head>
<body>
<div id="meet"></div>
<script>window.MEET_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
<script src="/app/meet.js?v=6"></script>
</body>
</html>
