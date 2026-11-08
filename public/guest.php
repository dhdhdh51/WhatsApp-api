<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';   // session + CSRF on every POST; no login needed here

use App\GuestLinks;
use App\Livekit;
use App\Meetings;

/**
 * Join a live meeting through a guest link (outside officer, no login). The person types a name,
 * joins view-only as "guest-<id>", and gets mic + camera only when the host brings them onto the stage.
 * The link stops working when the meeting ends or the host closes it.
 */
$key = (string) q('k');
$self = '/guest.php?k=' . rawurlencode($key);
$isJson = q('chat') !== '' || q('log') !== '' || q('summary') !== '';
$link = GuestLinks::find($key);

function guest_json(int $code, array $out): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function guest_page(string $title, string $body): never
{
    header('Cache-Control: no-store');
    $logo = (string) App\Settings::get('bank_logo');
    ?><!doctype html>
<html lang="hi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/app/app.css?v=19">
<style>
.gl-head{text-align:center;margin:18px 0 22px}
.gl-head img{height:46px;max-width:220px;object-fit:contain}
.gl-head .bank{font-size:13px;color:var(--muted);margin-top:6px;letter-spacing:.2px}
.gl-title{font-size:20px;font-weight:800;color:var(--accent-ink);margin:0 0 6px;line-height:1.3}
.gl-host{font-size:14px;color:var(--muted);margin:0}
.gl-note{font-size:14px;line-height:1.55;color:var(--ink);margin:0}
.gl-foot{text-align:center;font-size:12px;color:var(--muted);margin-top:22px}
button,.btn{font-family:'Noto Sans Devanagari','Inter',system-ui,sans-serif}
.gl-note.mb{margin-bottom:18px}
</style></head><body><div class="login">
<div class="gl-head"><?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="Central Bank of India"><?php endif; ?><div class="bank">Central Bank of India · BC Monitoring</div></div>
<?= $body ?>
<div class="gl-foot">bc.bharatseo.site</div>
</div></body></html>
<?php
    exit;
}

if (!$link) {
    if ($isJson) {
        guest_json(410, ['ok' => false, 'ended' => true, 'error' => 'ended']);   // the meeting screen shows "khatam"
    }
    guest_page('लिंक उपलब्ध नहीं', '<div class="card"><p class="gl-title">यह लिंक अब उपलब्ध नहीं है</p>'
        . '<p class="gl-note">मीटिंग समाप्त हो चुकी है या आयोजक ने यह लिंक बंद कर दिया है। ज़रूरत हो तो आयोजक से नया लिंक माँग लें।</p></div>');
}

$meetingId = (int) $link['meeting_id'];
$linkId = (int) $link['link_id'];
$guestId = (int) ($_SESSION['guest_links'][$linkId] ?? 0);
$guest = $guestId ? App\DB::one('SELECT * FROM meeting_guests WHERE id = ? AND link_id = ?', [$guestId, $linkId]) : null;

// ---- step 1: the name form ----
if (!$guest) {
    if ($isJson) {
        guest_json(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $name = GuestLinks::cleanName((string) p('name'));
        if ($name === '') {
            $error = 'कृपया अपना नाम लिखें।';
        } elseif (GuestLinks::joinLimited($linkId, client_ip(), Meetings::isGuestBroadcast($link))) {
            $error = 'अभी बहुत लोग इस लिंक से जुड़ रहे हैं। कुछ मिनट बाद फिर से कोशिश करें।';
        } else {
            $id = App\DB::insert('INSERT INTO meeting_guests (link_id, meeting_id, name, ip, joined_at) VALUES (?, ?, ?, ?, NOW())',
                [$linkId, $meetingId, $name, client_ip()]);
            session_regenerate_id(true);
            $_SESSION['guest_links'][$linkId] = $id;
            App\Audit::log(null, 'meeting_guest_join', 'meeting', $meetingId, ['guest' => 'guest-' . $id, 'name' => $name]);
            redirect($self);
        }
    }
    $h = Meetings::hostInfo($link);
    $role = strcasecmp($h['role'], $h['name']) === 0 ? '' : $h['role'];   // "Admin, Admin" -> "Admin"
    $by = $h['label'] !== '' ? $h['label']   // a BC Supervisor: "DEEPAK SHARMA - BC SUPERVISOR AGRA RO"
        : trim($h['name'] . ($role !== '' ? ', ' . $role : '') . ($h['region'] !== '' ? ' (' . $h['region'] . ')' : ''));
    guest_page($link['title'],
        '<div class="card"><p class="gl-title">' . e($link['title']) . '</p>'
        . ($by !== '' ? '<p class="gl-host">आयोजक: ' . e($by) . '</p>' : '') . '</div>'
        . '<p class="gl-note mb">आपको इस मीटिंग में अतिथि के रूप में आमंत्रित किया गया है। जुड़ने के लिए अपना नाम और पद लिखें।</p>'
        . ($error ? '<div class="err">' . e($error) . '</div>' : '')
        . '<form method="post" action="' . e($self) . '">' . App\Csrf::field()
        . '<div class="field mb"><label for="gn">नाम और पद</label>'
        . '<input id="gn" name="name" maxlength="50" required autocomplete="name" placeholder="जैसे: आर.के. शर्मा, एजीएम" value="' . e((string) p('name')) . '"></div>'
        . '<button class="btn" type="submit">मीटिंग में शामिल हों</button></form>'
        . '<p class="muted" style="text-align:center;margin-top:16px">' . (Meetings::isGuestBroadcast($link)
            ? 'जुड़ते ही आपका कैमरा चालू होगा, ताकि सब एक-दूसरे को देख सकें। बोलने के लिए ✋ हाथ उठाएँ, आयोजक अनुमति देंगे।'
            : 'शुरुआत में आप मीटिंग देख और सुन सकेंगे। आयोजक के बुलाने पर आपका माइक और कैमरा चालू होगा।') . '</p>');
}

$actor = 'guest-' . (int) $guest['id'];
$name = $guest['name'] . ' (अतिथि)';

// ---- the meeting screen calls these (same shape as meet.php) ----
if (q('chat') !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true) ?: [];
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if ($body === '' || mb_strlen($body) > 2000) {
            guest_json(200, ['ok' => false, 'error' => 'empty']);
        }
        if (GuestLinks::chatLimited($meetingId, $actor)) {
            guest_json(429, ['ok' => false, 'error' => 'slow down']);
        }
        Meetings::chatPost($meetingId, $actor, $name, false, $body);
    }
    guest_json(200, ['ok' => true, 'rows' => Meetings::chatFormat(Meetings::chatSince($meetingId, (int) q('after', 0)), $actor)]);
}
if (q('log') !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true) ?: [];
    Meetings::clientLog($meetingId, $actor, $in);
    guest_json(200, ['ok' => true]);
}
if (q('summary') !== '') {
    guest_json(200, ['ok' => true, 'rows' => Meetings::schemeSummary()]);
}
if (q('left') !== '') {
    guest_page('मीटिंग छोड़ दी', '<div class="card"><p class="gl-title">आपने मीटिंग छोड़ दी है</p>'
        . '<p class="gl-note">मीटिंग अभी चल रही है तो आप फिर से शामिल हो सकते हैं।</p></div>'
        . '<a class="btn" style="text-align:center;text-decoration:none" href="' . e($self) . '">फिर से शामिल हों</a>');
}

// ---- the meeting screen. Mic + camera once the host allowed this guest (stage / raised hand).
//      Broadcast 3 is everyone's video: every guest shows their camera from the start, like a BC in
//      a broadcast. Elsewhere a guest is view-only until the host brings them onto the stage. ----
$onStage = Meetings::isSpeaker($meetingId, $actor);
$isVideo = ($link['mode'] ?? 'video') === 'video';
$canVideo = $isVideo && ($onStage || Meetings::isGuestBroadcast($link));
$token = Livekit::token((string) $link['room'], $actor, $name, $onStage || $canVideo, 7200, $onStage ? null : ['camera'], ['role' => 'अतिथि अधिकारी']);
if (!$token) {
    guest_page('मीटिंग', '<div class="card"><p class="gl-title">अभी मीटिंग से नहीं जुड़ पा रहे</p><p class="gl-note">कुछ देर बाद यह लिंक फिर से खोलें।</p></div>');
}
Meetings::markJoined($meetingId, $actor);
$host = Meetings::hostInfo($link);
$cfg = [
    'ok' => true,
    'url' => Livekit::url(),
    'token' => $token,
    'room' => $link['room'],
    'mode' => $link['mode'] ?? 'video',
    'title' => $link['title'],
    'name' => $name,
    'meeting_id' => $meetingId,
    'is_broadcast' => Meetings::isBroadcast($link),
    'guest_only' => Meetings::isGuestBroadcast($link),   // the screen counts guests instead of BCs
    'stage_style' => Meetings::isChannel($link) ? 'channel' : '',   // Broadcast 2: TV-channel stage
    'can_publish' => $onStage,
    'can_video' => $canVideo,
    'is_host' => false,
    'brand' => 'BC Monitoring App',
    'bank' => 'Central Bank of India',
    'subtitle' => 'APY, PMJJBY, PMSBY एवं PMJDY की प्रगति समीक्षा',
    'host_name' => $host['name'],
    'host_role' => $host['role'],
    'host_region' => $host['region'],
    'host_identity' => $host['identity'],
    'host_label' => $host['label'],   // BC Supervisor host: "DEEPAK SHARMA - BC SUPERVISOR AGRA RO"
    'logo' => App\Settings::get('bank_logo'),
    'headlines_tag' => App\Settings::get('broadcast_notice_title'),
    'headlines' => App\Settings::get('broadcast_notice'),
    'bc_total' => count(Meetings::isBroadcast($link) ? Meetings::expectedBcs($link) : []),
    'bc_roster' => null,
    'stage_url' => null,
    'mute_url' => null,
    'promote_url' => null,
    'end_url' => null,
    'chat_url' => $self . '&chat=1',
    'log_url' => $self . '&log=1',
    'summary_url' => $self . '&summary=1',
    'back_url' => $self . '&left=1',
    'csrf' => App\Csrf::token(),
];
header('Cache-Control: no-store');
?><!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($link['title']) ?></title>
<link rel="stylesheet" href="/app/meet.css?v=15">
</head>
<body>
<div id="meet"></div>
<script>window.MEET_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>
<script src="/app/meet.js?v=15"></script>
</body>
</html>
