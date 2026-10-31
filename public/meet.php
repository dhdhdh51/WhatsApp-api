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
    if (q('chat') !== '' || q('speak') !== '' || q('mute') !== '' || q('end') !== '' || q('summary') !== '' || q('log') !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(410);
        echo json_encode(['ok' => false, 'ended' => true, 'error' => 'ended']);   // the screen leaves the meeting
        exit;
    }
    flash('danger', 'Ye meeting live nahi hai / यह मीटिंग अब चालू नहीं है');
    redirect('/meetings.php');
}

$isBroadcast = Meetings::isBroadcast($m);
$meActor = 'user-' . (int) $user['id'];

// Region isolation. Admin + region-less staff may open any meeting. A BC Supervisor (role supervisor)
// may open their own region's meetings, and may view (not moderate) org-wide ones. A CBC host owns theirs.
$mRegion = (int) ($m['region_id'] ?? 0);
$seesAll = \App\App::staffSeesAll($user);
$sameRegion = $mRegion > 0 && ($user['role'] ?? '') === 'supervisor' && (int) ($user['region_id'] ?? 0) === $mRegion;
$isHostCbc = (int) ($m['host_cbc_id'] ?? 0) === (int) $user['id'];
if (!($seesAll || $mRegion === 0 || $sameRegion || $isHostCbc)) {
    if (q('chat') !== '' || q('speak') !== '' || q('mute') !== '' || q('summary') !== '' || q('end') !== '' || q('log') !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    flash('danger', 'Ye meeting aapki region ki nahi hai / यह मीटिंग आपके रीजन की नहीं है');
    redirect('/meetings.php');
}
$canModerate = Meetings::canModerate($m, $user);   // admin / region-less, the host, or this region's BC Supervisor

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
        Meetings::chatPost($id, $meActor, Meetings::displayName($user), true, $body);
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
    // a broadcast is a group video call: taking the mic back keeps the person's camera
    echo json_encode(['ok' => Livekit::setCanPublish((string) $m['room'], $who, $allow, $isBroadcast), 'allow' => $allow]);
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
    if ($who === '*') {   // everyone except me; in a broadcast, allowed speakers also lose the mic (camera stays)
        echo json_encode(['ok' => true, 'changed' => Meetings::muteAll($m, $meActor)]);
        exit;
    }
    echo json_encode(['ok' => $who !== '' && Livekit::setMuted($room, $who, $muted)]);
    exit;
}

// ---- host: end the meeting / broadcast for everyone (closes the room, every screen leaves) ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && q('end') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canModerate) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'not host']);
        exit;
    }
    Meetings::end($id);
    App\Audit::log((int) $user['id'], 'meeting_end', 'meeting', $id, ['from' => 'meeting_screen']);
    echo json_encode(['ok' => true]);
    exit;
}

// ---- a browser error on the meeting screen (so problems on phones show up in our log) ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && q('log') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true) ?: [];
    Meetings::clientLog($id, $meActor, $in);
    echo json_encode(['ok' => true]);
    exit;
}

// ---- scheme progress summary (broadcast "आज की प्रस्तुति" panel) ----
if (q('summary') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'rows' => Meetings::schemeSummary()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Broadcast = group video call: everyone shows their camera; the microphone is for the host,
// moderators and people the host allowed (raised hand). can_publish keeps its old meaning
// (camera + mic) so an older cached meet.js still works.
$canPublish = !$isBroadcast || $canModerate || Meetings::isSpeaker($id, $meActor);
$canVideo = ($m['mode'] ?? 'video') === 'video';
$meName = Meetings::displayName($user);            // "Deepak Sharma (RO Agra)"
$token = Livekit::token((string) $m['room'], $meActor, $meName, $canPublish || $canVideo, 7200, $canPublish ? null : ['camera']);
if (!$token) {
    flash('danger', 'Meeting server configure nahi hai (livekit settings)');
    redirect('/meetings.php');
}
$host = Meetings::hostInfo($m);
$cfg = [
    'ok' => true,
    'url' => Livekit::url(),
    'token' => $token,
    'room' => $m['room'],
    'mode' => $m['mode'] ?? 'video',
    'title' => $m['title'],
    'name' => $meName,
    'meeting_id' => $id,
    'is_broadcast' => $isBroadcast,
    'can_publish' => $canPublish,
    'can_video' => $canVideo,
    // panel admins/supervisors moderate; a CBC only in their own meeting
    'is_host' => $canModerate,
    'brand' => 'BC Monitoring App',
    'bank' => 'Central Bank of India',
    'subtitle' => 'APY, PMJJBY, PMSBY एवं PMJDY की प्रगति समीक्षा',
    'host_name' => $host['name'],
    'host_role' => $host['role'],
    'host_region' => $host['region'],
    'host_identity' => $host['identity'],
    'mute_url' => '/meet.php?id=' . $id . '&mute=1',
    'chat_url' => '/meet.php?id=' . $id . '&chat=1',
    'promote_url' => $canModerate ? '/meet.php?id=' . $id . '&speak=1' : null,
    'end_url' => $canModerate ? '/meet.php?id=' . $id . '&end=1' : null,
    'log_url' => '/meet.php?id=' . $id . '&log=1',
    'summary_url' => '/meet.php?id=' . $id . '&summary=1',
    // where "Leave" goes: the panel opens a meeting in a new tab, where history.back() does nothing
    'back_url' => in_array($user['role'], ['admin', 'supervisor'], true) ? '/meetings.php' : '/dashboard.php',
    'csrf' => App\Csrf::token(),
];
header('Cache-Control: no-store');
?><!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($m['title']) ?> · <?= $isBroadcast ? 'Broadcast' : 'Meeting' ?></title>
<link rel="stylesheet" href="/app/meet.css?v=10">
</head>
<body>
<div id="meet"></div>
<script>window.MEET_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>
<script src="/app/meet.js?v=10"></script>
</body>
</html>
