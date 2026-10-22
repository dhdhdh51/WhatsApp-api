<?php
declare(strict_types=1);

/**
 * JSON API for the BC mobile app.  /api/app.php?a=<action>
 * Auth: "Authorization: Bearer <token>" (from a=login).
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\App;
use App\Calendar;
use App\Calls;
use App\DB;
use App\Fcm;
use App\Meetings;
use App\ReportParser;
use App\Reports;
use App\Settings;
use App\Targets;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['a'] ?? '');
$in = [];
if ($method === 'POST') {
    $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        $in = $_POST;
    }
}

$fields = [];
foreach (ReportParser::LABELS as $k => $label) {
    $fields[] = ['key' => $k, 'label' => $label, 'offline' => in_array($k, ReportParser::OFFLINE, true)];
}

// ------------------------------------------------------------------ public
if ($action === 'login') {
    if ($method !== 'POST') {
        fail('POST required', 405);
    }
    $r = App::login((string) ($in['phone'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['device'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if (!$r['ok']) {
        fail($r['error'], 401);
    }
    if (($r['type'] ?? 'bc') === 'cbc') {
        out(['ok' => true, 'type' => 'cbc', 'token' => $r['token'], 'must_change' => false, 'name' => $r['user']['name']]);
    }
    out(['ok' => true, 'type' => 'bc', 'token' => $r['token'], 'must_change' => $r['must_change'], 'name' => $r['bc']['name'],
        'last_notification' => (int) DB::val('SELECT COALESCE(MAX(id),0) FROM app_notifications WHERE bc_id = ?', [$r['bc']['id']])]);
}

// ------------------------------------------------------------------ authenticated
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) ($_GET['t'] ?? '');
$actor = App::actorFromToken($token);
if (!$actor) {
    fail('Login expired. Dobara login karein.', 401);
}

// ------------------------------------------------------------------ 1:1 calls (BC + CBC)
if (str_starts_with($action, 'call_')) {
    $meType = $actor['type'];
    $meId = (int) $actor['id'];
    $meName = (string) ($actor['row']['name'] ?? 'User');
    switch ($action) {
        case 'call_config':
            out(['ok' => true, 'ice' => Calls::iceServers()]);

        case 'call_invite':
            if ($method !== 'POST') {
                fail('POST required', 405);
            }
            $mode = ($in['mode'] ?? '') === 'voice' ? 'voice' : 'video';
            if ($meType === 'cbc') {
                $toId = (int) ($in['to_id'] ?? 0);
                $bcRow = App::staffSeesAll($actor['row'])
                    ? DB::one('SELECT id, name FROM bcs WHERE id = ? AND is_active = 1', [$toId])
                    : DB::one('SELECT id, name FROM bcs WHERE id = ? AND cbc_id = ? AND is_active = 1', [$toId, $meId]);
                if (!$bcRow) {
                    fail('Ye BC aapke under nahi hai ya active nahi.');
                }
                $toType = 'bc';
            } else {
                $toId = (int) ($actor['row']['cbc_id'] ?? 0);
                if (!$toId) {
                    fail('Aapka koi CBC assign nahi hai.');
                }
                $toType = 'cbc';
            }
            $r = Calls::invite($meType, $meId, $meName, $toType, $toId, $mode);
            try {
                $pl = ['id' => 0, 'kind' => 'call', 'title' => '📞 ' . $meName, 'body' => ($mode === 'voice' ? 'Voice' : 'Video') . ' call', 'call_id' => (string) $r['call_id']];
                if ($toType === 'bc') {
                    Fcm::pushToBc($toId, $pl);
                } else {
                    Fcm::pushToCbc($toId, $pl);
                }
            } catch (\Throwable $e) {
            }
            out(['ok' => true, 'call_id' => $r['call_id'], 'mode' => $r['mode'], 'ice' => Calls::iceServers()]);

        case 'call_incoming':
            out(['ok' => true, 'call' => Calls::incomingFor($meType, $meId)]);

        case 'call_state':
            $cid = (int) ($_GET['call_id'] ?? ($in['call_id'] ?? 0));
            $after = (int) ($_GET['after'] ?? 0);
            $c = Calls::get($cid);
            if (!$c) {
                fail('Call not found', 404);
            }
            $role = Calls::role($c, $meType, $meId);
            if (!$role) {
                fail('Not your call', 403);
            }
            out(['ok' => true, 'status' => $c['status'], 'mode' => $c['mode'], 'ice' => Calls::iceServers(), 'signals' => Calls::signalsSince($cid, $after, $role)]);

        case 'call_signal':
            if ($method !== 'POST') {
                fail('POST required', 405);
            }
            $cid = (int) ($in['call_id'] ?? 0);
            $c = Calls::get($cid);
            if (!$c) {
                fail('Call not found', 404);
            }
            $role = Calls::role($c, $meType, $meId);
            if (!$role) {
                fail('Not your call', 403);
            }
            Calls::addSignal($cid, $role, (string) ($in['data'] ?? ''));
            out(['ok' => true]);

        case 'call_accept':
        case 'call_reject':
        case 'call_end':
            if ($method !== 'POST') {
                fail('POST required', 405);
            }
            $cid = (int) ($in['call_id'] ?? 0);
            $c = Calls::get($cid);
            if (!$c || !Calls::role($c, $meType, $meId)) {
                fail('Not your call', 403);
            }
            Calls::setStatus($cid, $action === 'call_accept' ? 'accepted' : ($action === 'call_reject' ? 'rejected' : 'ended'));
            out(['ok' => true]);

        default:
            fail('Unknown call action', 404);
    }
}

// ------------------------------------------------------------------ CBC (staff) app
if ($actor['type'] === 'cbc') {
    $u = $actor['row'];
    $uid = (int) $actor['id'];
    $seesAll = App::staffSeesAll($u);                 // admin / supervisor -> every BC
    $bcFilter = $seesAll ? [] : ['cbc_id' => $uid];
    $today = Calendar::today();
    switch ($action) {
        case 'logout':
            DB::exec('DELETE FROM cbc_app_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
            out(['ok' => true]);

        case 'me':
            out(['ok' => true, 'type' => 'cbc', 'role' => $seesAll ? ucfirst((string) $u['role']) : 'CBC',
                'name' => $u['name'], 'phone' => $u['phone'],
                'bc_count' => (int) ($seesAll
                    ? DB::val('SELECT COUNT(*) FROM bcs WHERE is_active = 1')
                    : DB::val('SELECT COUNT(*) FROM bcs WHERE cbc_id = ? AND is_active = 1', [$uid]))]);

        case 'status':
            $rows = Calendar::dayRows($today, null, $bcFilter);
            $counts = ['submitted' => 0, 'pending' => 0, 'off' => 0];
            $bcs = [];
            foreach ($rows as $r) {
                $st = (string) $r['status'];
                if ($st === 'submitted') {
                    $counts['submitted']++;
                } elseif ($st === 'pending') {
                    $counts['pending']++;
                } else {
                    $counts['off']++;
                }
                $bcs[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'bc_code' => $r['bc_code'], 'phone' => $r['phone'],
                    'status' => $st, 'branch' => $r['branch_name'], 'block' => $r['block']];
            }
            usort($bcs, static function ($a, $b) {
                $rank = ['pending' => 0, 'submitted' => 1, 'leave' => 2, 'holiday' => 2, 'weekly_off' => 2];
                return (($rank[$a['status']] ?? 3) <=> ($rank[$b['status']] ?? 3)) ?: strcmp((string) $a['name'], (string) $b['name']);
            });
            out(['ok' => true, 'date_text' => date('d-m-Y'), 'open' => Calendar::isOpen($today),
                'cutoff' => fmt_time12(Settings::padTime(Settings::get('cutoff_time') ?: '23:59')),
                'counts' => $counts + ['total' => count($rows)], 'bcs' => $bcs, 'meeting' => Meetings::forCbc($uid)]);

        case 'start_meeting':
            if ($method !== 'POST') {
                fail('POST required', 405);
            }
            $mMode = ($in['mode'] ?? '') === 'voice' ? 'voice' : 'video';
            $mScope = ($in['scope'] ?? '') === 'bc' ? 'bc' : 'all';
            $mIds = is_array($in['bc_ids'] ?? null) ? array_slice(array_map('intval', $in['bc_ids']), 0, 300) : [];
            $mRes = Meetings::startByCbc($uid, $mMode, $mScope, isset($in['bc_id']) ? (int) $in['bc_id'] : null, $seesAll, $mIds);
            if (!$mRes) {
                fail('Ye BC aapke under nahi hai ya active nahi.');
            }
            out(['ok' => true] + $mRes);

        case 'end_meeting':
            Meetings::endByCbc($uid);
            out(['ok' => true]);

        case 'alarm_check':
            out(['ok' => true, 'ring' => App::shouldRingCbc($uid), 'config' => App::alarmConfigCbc()]);

        case 'report_done': // native uses this name; for a CBC "done" = no BC left pending
            out(['ok' => true, 'done' => !App::shouldRingCbc($uid)]);

        case 'poll':
            $th = hash('sha256', $token);
            $row = DB::one('SELECT poll_seq, test_mode FROM cbc_app_tokens WHERE token_hash = ?', [$th]);
            $seq = (int) ($row['poll_seq'] ?? 1);
            $notifs = [];
            if ($row && $row['test_mode']) {
                DB::exec('UPDATE cbc_app_tokens SET test_mode = NULL WHERE token_hash = ?', [$th]);
                $notifs[] = ['id' => $seq, 'kind' => $row['test_mode'] === 'call' ? 'ring_call' : 'ring_alarm',
                    'title' => 'Test', 'body' => 'Admin test'];
            }
            out(['ok' => true, 'alarm' => App::alarmConfigCbc(), 'last' => $seq, 'notifications' => $notifs]);

        case 'fcm':
            $ft = trim((string) ($in['token'] ?? ''));
            if ($method !== 'POST' || $ft === '' || strlen($ft) > 255) {
                fail('bad token');
            }
            DB::exec('UPDATE cbc_app_tokens SET fcm_token = ? WHERE token_hash = ?', [$ft, hash('sha256', $token)]);
            out(['ok' => true]);

        case 'mdm':
            out(['ok' => true, 'device_owner' => false]);

        default:
            fail('Unknown action', 404);
    }
}

$bc = $actor['row'];
$bcId = (int) $actor['id'];
$today = Calendar::today();

switch ($action) {
    case 'logout':
        DB::exec('DELETE FROM app_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        out(['ok' => true]);

    case 'password':
        $cur = (string) ($in['current'] ?? '');
        $new = (string) ($in['new'] ?? '');
        $valid = $bc['app_password_hash']
            ? password_verify($cur, (string) $bc['app_password_hash'])
            : (Settings::get('app_default_password_hash') !== '' && password_verify($cur, Settings::get('app_default_password_hash')));
        if (!$valid) {
            fail('Purana password galat hai.');
        }
        if (mb_strlen($new) < 6 || $new === $cur) {
            fail('Naya password kam se kam 6 akshar ka ho aur purane se alag ho.');
        }
        App::setPassword($bcId, $new);
        out(['ok' => true]);

    case 'me':
        $cbc = $bc['cbc_id'] ? DB::val('SELECT name FROM users WHERE id = ?', [$bc['cbc_id']]) : null;
        out(['ok' => true, 'name' => $bc['name'], 'bc_code' => $bc['bc_code'], 'phone' => $bc['phone'], 'cbc' => $cbc,
            'must_change' => !$bc['app_password_hash'] || (int) $bc['app_must_change'] === 1,
            'branch' => DB::val('SELECT name FROM branches WHERE id = ?', [$bc['branch_id']])]);

    case 'today':
        $rep = DB::one('SELECT * FROM reports WHERE bc_id = ? AND report_date = ?', [$bcId, $today]);
        $yday = date('Y-m-d', strtotime('-1 day'));
        $yrep = DB::one('SELECT * FROM reports WHERE bc_id = ? AND report_date = ?', [$bcId, $yday]);
        $month = date('Y-m-01');
        $t = DB::one('SELECT * FROM targets WHERE bc_id = ? AND month = ?', [$bcId, $month]);
        $ach = DB::one('SELECT ' . implode(', ', array_map(static fn ($k) => "COALESCE(SUM({$k}),0) {$k}", ReportParser::SCHEMES))
            . ' FROM reports WHERE bc_id = ? AND report_date >= ? AND report_date <= ?', [$bcId, $month, $today]);
        $monthly = [];
        foreach (ReportParser::SCHEMES as $k) {
            if ($t && (int) $t["{$k}_target"] > 0) {
                $monthly[] = ['key' => $k, 'label' => ReportParser::LABELS[$k], 'target' => (int) $t["{$k}_target"], 'done' => (int) $ach[$k]];
            }
        }
        $pick = static fn (?array $r) => $r ? array_map('intval', array_intersect_key($r, array_flip(ReportParser::SCHEMES))) : null;
        out(['ok' => true, 'date' => $today, 'date_text' => date('d-m-Y'), 'open' => Calendar::isOpen($today),
            'cutoff' => fmt_time12(Settings::padTime(Settings::get('cutoff_time') ?: '23:59')),
            'fields' => $fields, 'report' => $pick($rep), 'updated_at' => $rep ? date('h:i A', strtotime((string) $rep['updated_at'])) : null,
            'yesterday' => ['date_text' => date('d-m-Y', strtotime($yday)), 'report' => $pick($yrep)],
            'daily_target' => Targets::dailyTarget($t), 'monthly' => $monthly, 'month_text' => date('M Y'),
            'meeting' => Meetings::forBc($bc),
            'chat_unread' => (int) DB::val("SELECT COUNT(*) FROM chat_messages WHERE bc_id = ? AND sender = 'staff' AND read_at IS NULL", [$bcId])]);

    case 'report':
        if ($method !== 'POST') {
            fail('POST required', 405);
        }
        if (!Calendar::isOpen($today)) {
            fail('Aaj ki report ka samay khatam ho gaya hai. Ab badlav sirf Admin kar sakte hain.');
        }
        $max = max(1, Settings::int('max_value'));
        $vals = [];
        foreach (ReportParser::SCHEMES as $k) {
            $v = trim((string) ($in['values'][$k] ?? '0'));
            $v = $v === '' ? '0' : $v;
            if (!preg_match('/^\d{1,6}$/', $v)) {
                fail(ReportParser::LABELS[$k] . ': sirf poora number (0 ya usse zyada) likhein.');
            }
            if ((int) $v > $max) {
                fail(ReportParser::LABELS[$k] . " ka number bahut bada hai (max {$max}).");
            }
            $vals[$k] = (int) $v;
        }
        $th = Settings::int('high_value_threshold');
        $high = [];
        foreach ($vals as $k => $v) {
            if ($th > 0 && $v > $th) {
                $high[] = ReportParser::LABELS[$k] . ' ' . $v;
            }
        }
        if ($high && empty($in['confirm'])) {
            out(['ok' => false, 'need_confirm' => true, 'error' => implode(', ', $high) . ' – ye number zyada lag raha hai. Kya ye sahi hai?']);
        }
        $saved = Reports::saveFromApp($bcId, $today, $vals);
        DB::exec('DELETE FROM pending_confirmations WHERE bc_id = ?', [$bcId]);
        out(['ok' => true, 'updated' => $saved['updated'],
            'message' => ($saved['updated'] ? 'Report update ho gayi ✅ ' : 'Report mil gayi ✅ ') . Reports::summaryLine($vals)]);

    case 'history':
        $rows = DB::all('SELECT * FROM reports WHERE bc_id = ? ORDER BY report_date DESC LIMIT 60', [$bcId]);
        out(['ok' => true, 'fields' => $fields, 'rows' => array_map(static function ($r) {
            $o = ['date_text' => date('d-m-Y', strtotime((string) $r['report_date']))];
            foreach (ReportParser::SCHEMES as $k) {
                $o[$k] = (int) $r[$k];
            }
            return $o;
        }, $rows)]);

    case 'notices':
        out(['ok' => true, 'rows' => array_map(static fn ($n) => ['id' => (int) $n['id'], 'title' => $n['title'], 'body' => $n['body'],
            'time' => fmt_dt($n['created_at'])], DB::all('SELECT * FROM app_notifications WHERE bc_id = ? ORDER BY id DESC LIMIT 50', [$bcId]))]);

    case 'fcm':
        $ft = trim((string) ($in['token'] ?? ''));
        if ($method !== 'POST' || $ft === '' || strlen($ft) > 255) {
            fail('bad token');
        }
        DB::exec('UPDATE app_tokens SET fcm_token = NULL WHERE fcm_token = ? AND token_hash <> ?', [$ft, hash('sha256', $token)]);
        DB::exec('UPDATE app_tokens SET fcm_token = ? WHERE token_hash = ?', [$ft, hash('sha256', $token)]);
        out(['ok' => true]);

    case 'mdm':
        if ($method !== 'POST') {
            fail('POST required', 405);
        }
        $owner = !empty($in['device_owner']) ? 1 : 0;
        DB::exec('UPDATE app_tokens SET mdm_owner = ? WHERE token_hash = ?', [$owner, hash('sha256', $token)]);
        out(['ok' => true, 'device_owner' => (bool) $owner]);

    case 'alarm_check':
        out(['ok' => true, 'ring' => App::shouldRing($bcId), 'config' => App::alarmConfig()]);

    case 'report_done':
        out(['ok' => true, 'done' => (bool) DB::val('SELECT COUNT(*) FROM reports WHERE bc_id = ? AND report_date = ?', [$bcId, $today])]);

    case 'poll':
        $since = max(0, (int) ($_GET['since'] ?? 0));
        $rows = $since > 0
            ? DB::all('SELECT id, kind, title, body FROM app_notifications WHERE bc_id = ? AND id > ? ORDER BY id LIMIT 20', [$bcId, $since])
            : [];
        out(['ok' => true, 'alarm' => App::alarmConfig(), 'last' => (int) DB::val('SELECT COALESCE(MAX(id),0) FROM app_notifications WHERE bc_id = ?', [$bcId]),
            'notifications' => array_map(static fn ($n) => ['id' => (int) $n['id'], 'kind' => $n['kind'], 'title' => $n['title'],
                'body' => mb_strimwidth((string) $n['body'], 0, 300, '…')], $rows)]);

    case 'chat':
        if ($method === 'POST') {
            $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
            if ($body === '' || mb_strlen($body) > 2000) {
                fail('Message likhein (max 2000 akshar).');
            }
            App::chatSend($bcId, 'bc', null, $body);
        }
        $after = max(0, (int) ($_GET['after'] ?? 0));
        DB::exec("UPDATE chat_messages SET read_at = NOW() WHERE bc_id = ? AND sender = 'staff' AND read_at IS NULL", [$bcId]);
        $rows = DB::all('SELECT c.id, c.sender, c.body, c.created_at, c.read_at, u.name FROM chat_messages c LEFT JOIN users u ON u.id = c.user_id
                         WHERE c.bc_id = ? AND c.id > ? ORDER BY c.id DESC LIMIT 100', [$bcId, $after]);
        out(['ok' => true, 'rows' => array_reverse(array_map(static fn ($c) => ['id' => (int) $c['id'], 'mine' => $c['sender'] === 'bc',
            'name' => $c['sender'] === 'bc' ? null : ($c['name'] ?: App::SYSTEM_NAME), 'body' => $c['body'],
            'time' => date('d-m h:i A', strtotime((string) $c['created_at'])), 'read' => $c['read_at'] !== null], $rows))]);

    default:
        fail('Unknown action', 404);
}
