<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\App;
use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Fcm;
use App\Settings;
use App\View;

/** App call (5 PM) + alarm (5:30 PM) for BCs whose report is pending; recorded audio; test sending. */
$user = Auth::requireAdmin();
$dir = __DIR__ . '/uploads';
$allowed = ['mp3', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'amr', '3gp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');
    if ($action === 'save') {
        $errs = [];
        foreach (['app_call_time', 'app_alarm_time'] as $k) {
            if (!Settings::validTime((string) p($k))) {
                $errs[] = "{$k}: HH:MM";
            }
        }
        $kinds = ['call' => 'app_call', 'alarm' => 'app_alarm'];
        $texts = [];
        $uploads = [];
        foreach ($kinds as $k => $pre) {
            $t = trim((string) ($_POST[$pre . '_text'] ?? ''));
            if ($t === '' || mb_strlen($t) > 1000) {
                $errs[] = ucfirst($k) . ' message text 1–1000 characters';
            }
            $texts[$pre] = str_replace("\r\n", "\n", $t);
            $f = $_FILES[$k . '_audio'] ?? null;
            if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
                $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
                if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024 || !in_array($ext, $allowed, true)) {
                    $errs[] = ucfirst($k) . ' audio: ' . implode('/', $allowed) . ', max 5 MB';
                } else {
                    $uploads[$pre] = [$f['tmp_name'], $ext, $k];
                }
            }
        }
        if ($errs) {
            flash('danger', implode(' | ', $errs));
            redirect('/app_alarm.php');
        }
        Settings::set('app_call_enabled', p('app_call_enabled') === '1' ? '1' : '0');
        Settings::set('app_call_time', Settings::padTime((string) p('app_call_time')));
        Settings::set('app_alarm_time', Settings::padTime((string) p('app_alarm_time')));
        $version = Settings::int('app_call_version') + 1;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $changes = [];
        foreach ($kinds as $k => $pre) {
            Settings::set($pre . '_text', $texts[$pre]);
            $old = Settings::get($pre . '_audio');
            if (isset($uploads[$pre])) {
                [$tmp, $ext] = $uploads[$pre];
                $name = "{$k}-audio-{$version}.{$ext}";
                move_uploaded_file($tmp, "{$dir}/{$name}");
                @chmod("{$dir}/{$name}", 0644);
                Settings::set($pre . '_audio', $name);
                if ($old !== '' && $old !== $name && is_file("{$dir}/{$old}")) {
                    @unlink("{$dir}/{$old}");
                }
                $changes[$k] = 'uploaded';
            } elseif (p('remove_' . $k . '_audio') === '1') {
                if ($old !== '' && is_file("{$dir}/{$old}")) {
                    @unlink("{$dir}/{$old}");
                }
                Settings::set($pre . '_audio', '');
                $changes[$k] = 'removed';
            }
        }
        Settings::set('app_call_version', (string) $version);
        Audit::log((int) $user['id'], 'app_alarm_settings', 'settings', null, ['enabled' => p('app_call_enabled'), 'call' => p('app_call_time'),
            'alarm' => p('app_alarm_time'), 'audio' => $changes]);
        flash('success', 'Saved – phones pick up the change at their next check (max ~15 min) / सेव हुआ');
        redirect('/app_alarm.php');
    }
    if ($action === 'test') {
        $mode = p('mode') === 'call' ? 'call' : 'alarm';
        $tid = (int) p('target'); // one phone only – tests never go to all BCs
        $ids = $tid && DB::val('SELECT COUNT(*) FROM bcs WHERE id = ? AND app_last_seen IS NOT NULL', [$tid]) ? [$tid] : [];
        foreach ($ids as $id) {
            App::sendTestRing($id, $mode);
        }
        Audit::log((int) $user['id'], 'app_alarm_test', 'bc', null, ['mode' => $mode, 'bcs' => $ids]);
        flash($ids ? 'success' : 'warning', $ids
            ? "Test {$mode} sent to 1 phone – rings within ~15 minutes (instant after Firebase) / टेस्ट भेजा गया"
            : 'Select a BC who uses the app / ऐप वाला बीसी चुनें');
        redirect('/app_alarm.php');
    }
}

$s = Settings::all();
$appUsers = DB::all('SELECT b.id, b.name, b.bc_code, b.app_last_seen, (SELECT COUNT(*) FROM app_tokens t WHERE t.bc_id = b.id AND t.fcm_token IS NOT NULL) fcm
                     FROM bcs b WHERE b.is_active = 1 AND b.app_last_seen IS NOT NULL ORDER BY b.app_last_seen DESC');
$tests = DB::all("SELECT n.created_at, n.kind, b.name FROM app_notifications n JOIN bcs b ON b.id = n.bc_id WHERE n.kind IN ('ring_call','ring_alarm') ORDER BY n.id DESC LIMIT 10");
$audioBlock = static function (string $k, string $pre) use ($s): string {
    $file = $s[$pre . '_audio'];
    $h = $file !== ''
        ? '<audio controls src="/uploads/' . e(rawurlencode($file)) . '" class="w-100 mb-1"></audio><div class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="remove_' . $k . '_audio" value="1" id="r' . $k . '"><label class="form-check-label" for="r' . $k . '">Remove (phone reads the text instead)</label></div>'
        : '<div class="small text-muted mb-1">No audio – the phone reads the text below in Hindi voice. / ऑडियो नहीं है – फ़ोन टेक्स्ट पढ़ेगा।</div>';
    return $h . '<input type="file" name="' . $k . '_audio" accept="audio/*,.mp3,.m4a,.aac,.ogg,.opus,.wav,.amr" class="form-control form-control-sm">';
};

View::header('App call & alarm / ऐप कॉल और अलार्म', 'alarm');
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><?= L('Timing and message', 'समय और संदेश') ?></div><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="save">
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="app_call_enabled" value="1" id="en" <?= $s['app_call_enabled'] === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="en"><?= L('Call and alarm on', 'कॉल और अलार्म चालू') ?></label></div></div>
      <div class="col-6"><label class="form-label small"><?= L('📞 Call time', 'कॉल का समय') ?></label><input type="time" name="app_call_time" value="<?= e($s['app_call_time']) ?>" class="form-control" required></div>
      <div class="col-6"><label class="form-label small"><?= L('⏰ Alarm time', 'अलार्म का समय') ?></label><input type="time" name="app_alarm_time" value="<?= e($s['app_alarm_time']) ?>" class="form-control" required></div>
      <div class="col-12 border-top pt-2"><h6 class="mb-1">📞 <?= L('Call – recorded audio', 'कॉल – रिकॉर्ड की हुई आवाज़') ?></h6><?= $audioBlock('call', 'app_call') ?>
        <label class="form-label small mt-1"><?= L('Call text (spoken when there is no audio)', 'कॉल टेक्स्ट') ?></label>
        <textarea name="app_call_text" rows="3" class="form-control form-control-sm" maxlength="1000" required><?= e($s['app_call_text']) ?></textarea></div>
      <div class="col-12 border-top pt-2"><h6 class="mb-1">⏰ <?= L('Alarm – recorded audio', 'अलार्म – रिकॉर्ड की हुई आवाज़') ?></h6><?= $audioBlock('alarm', 'app_alarm') ?>
        <label class="form-label small mt-1"><?= L('Alarm text (spoken when there is no audio)', 'अलार्म टेक्स्ट') ?></label>
        <textarea name="app_alarm_text" rows="3" class="form-control form-control-sm" maxlength="1000" required><?= e($s['app_alarm_text']) ?></textarea>
        <div class="form-text">MP3 / M4A / WhatsApp voice note (.opus/.ogg), max 5 MB each. The alarm repeats its audio until the BC stops it.</div></div>
      <div class="col-12"><button class="btn btn-success"><i class="bi bi-save"></i> <?= L('Save', 'सेव करें') ?></button></div>
    </form></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card mb-3"><div class="card-header"><?= L('Send a test now (only to the selected phone)', 'अभी टेस्ट भेजें (सिर्फ़ चुने हुए फ़ोन पर)') ?></div><div class="card-body">
      <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="test">
        <div class="col-12"><select name="target" class="form-select" required>
          <option value="">— select phone / BC (tip: your own test login) —</option>
          <?php foreach ($appUsers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name'] . ' (' . $u['bc_code'] . ')') ?><?= $u['fcm'] ? ' 🔥' : '' ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><button name="mode" value="call" class="btn btn-outline-success w-100"><i class="bi bi-telephone-inbound"></i> <?= L('Test call', 'टेस्ट कॉल') ?></button></div>
        <div class="col-6"><button name="mode" value="alarm" class="btn btn-outline-warning w-100"><i class="bi bi-alarm"></i> <?= L('Test alarm', 'टेस्ट अलार्म') ?></button></div>
      </form>
      <p class="small text-muted mt-2 mb-0">The test rings even if the report is already submitted.
        <?= Fcm::enabled() ? '🔥 Firebase is on – phones with app 1.0.5+ ring within seconds; older apps at their next check (max ~15 min).' : 'Firebase is off – arrives with the app\'s next check (max ~15 min).' ?>
        The daily call / alarm is exact because the phone schedules it itself.</p>
    </div></div>
    <div class="card"><div class="card-header small"><?= L('App users', 'ऐप इस्तेमाल करने वाले') ?> (<?= count($appUsers) ?>) · <?= L('last tests', 'पिछले टेस्ट') ?></div>
      <ul class="list-group list-group-flush small" style="max-height:300px;overflow:auto">
        <?php foreach ($tests as $t): ?><li class="list-group-item"><?= e(fmt_dt($t['created_at'])) ?> – <?= $t['kind'] === 'ring_call' ? '📞' : '⏰' ?> <?= e($t['name']) ?></li><?php endforeach; ?>
        <?php if (!$tests): ?><li class="list-group-item text-muted">No tests yet</li><?php endif; ?>
      </ul></div>
  </div>
</div>
<?php View::footer();
