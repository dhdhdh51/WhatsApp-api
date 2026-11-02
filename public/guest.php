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
    ?><!doctype html>
<html lang="hi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<style>
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#0b2545;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center}
.box{background:#fff;color:#1b1b1b;border-radius:14px;padding:26px 22px;max-width:380px;width:calc(100% - 32px);box-shadow:0 10px 30px rgba(0,0,0,.3)}
h1{font-size:20px;margin:0 0 6px}p{margin:6px 0 14px;color:#444;font-size:15px;line-height:1.45}
label{font-weight:600;font-size:14px}input{width:100%;box-sizing:border-box;font-size:17px;padding:11px;border:1px solid #bbb;border-radius:8px;margin:6px 0 14px}
button,.btn{display:block;width:100%;box-sizing:border-box;text-align:center;background:#0d6efd;color:#fff;border:0;border-radius:8px;padding:12px;font-size:17px;font-weight:600;text-decoration:none;cursor:pointer}
.err{background:#fde8e8;color:#9b1c1c;padding:9px 11px;border-radius:8px;font-size:14px;margin-bottom:12px}.sub{font-size:13px;color:#666}
</style></head><body><div class="box"><?= $body ?></div></body></html>
<?php
    exit;
}

if (!$link) {
    if ($isJson) {
        guest_json(410, ['ok' => false, 'ended' => true, 'error' => 'ended']);   // the meeting screen shows "khatam"
    }
    guest_page('Link band', '<h1>🔒 Ye link ab kaam nahi karta</h1><p>Meeting khatam ho chuki hai, ya host ne ye link band kar diya hai. / यह मीटिंग समाप्त हो चुकी है या लिंक बंद कर दिया गया है।</p>');
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
            $error = 'Apna poora naam likhein (kam se kam 2 akshar). / अपना नाम लिखें।';
        } elseif (GuestLinks::joinLimited($linkId, client_ip())) {
            $error = 'Bahut zyada koshish. Thodi der baad dobara kholein. / कृपया थोड़ी देर बाद प्रयास करें।';
        } else {
            $id = App\DB::insert('INSERT INTO meeting_guests (link_id, meeting_id, name, ip, joined_at) VALUES (?, ?, ?, ?, NOW())',
                [$linkId, $meetingId, $name, client_ip()]);
            session_regenerate_id(true);
            $_SESSION['guest_links'][$linkId] = $id;
            App\Audit::log(null, 'meeting_guest_join', 'meeting', $meetingId, ['guest' => 'guest-' . $id, 'name' => $name]);
            redirect($self);
        }
    }
    $kind = Meetings::isBroadcast($link) ? 'Broadcast' : 'Meeting';
    guest_page($link['title'] . ' · Guest',
        '<h1>📢 ' . e($link['title']) . '</h1>'
        . '<p>Aapko is ' . $kind . ' mein guest ke roop mein bulaya gaya hai. Pehle aap sirf dekh aur sun sakenge. Host jab aapko <b>मंच पर लाएँगे</b>, tab aapka mic aur camera chalu hoga.</p>'
        . ($error ? '<div class="err">' . e($error) . '</div>' : '')
        . '<form method="post" action="' . e($self) . '">' . App\Csrf::field()
        . '<label for="gn">Aapka naam aur pad / आपका नाम और पद</label>'
        . '<input id="gn" name="name" maxlength="50" required autocomplete="name" placeholder="जैसे: R.K. Sharma, AGM" value="' . e((string) p('name')) . '">'
        . '<button type="submit">Meeting mein judein</button></form>'
        . '<p class="sub">Aapka naam sabko "Guest" ke saath dikhega.</p>');
}

$actor = 'guest-' . (int) $guest['id'];
$name = $guest['name'] . ' (Guest)';

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
    guest_page('Meeting chhod di', '<h1>👋 Aap meeting se nikal gaye</h1><p>Meeting abhi chal rahi hai to dobara jud sakte hain.</p><a class="btn" href="' . e($self) . '">Dobara judein</a>');
}

// ---- the meeting screen: view-only, unless the host already brought this guest onto the stage ----
$onStage = Meetings::isSpeaker($meetingId, $actor);
$canVideo = $onStage && ($link['mode'] ?? 'video') === 'video';
$token = Livekit::token((string) $link['room'], $actor, $name, $onStage, 7200, null, ['role' => 'Guest · बाहरी अधिकारी']);
if (!$token) {
    guest_page('Error', '<h1>Meeting server abhi uplabdh nahi</h1><p>Thodi der baad dobara kholein.</p>');
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
<title><?= e($link['title']) ?> · Guest</title>
<link rel="stylesheet" href="/app/meet.css?v=12">
</head>
<body>
<div id="meet"></div>
<script>window.MEET_CONFIG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>
<script src="/app/meet.js?v=12"></script>
</body>
</html>
