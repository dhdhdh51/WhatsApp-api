<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\App;
use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
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
        $text = trim((string) ($_POST['app_call_text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 1000) {
            $errs[] = 'Message text 1–1000 characters';
        }
        $f = $_FILES['audio'] ?? null;
        $newFile = null;
        if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024 || !in_array($ext, $allowed, true)) {
                $errs[] = 'Audio: ' . implode('/', $allowed) . ', max 5 MB';
            } else {
                $newFile = [$f['tmp_name'], $ext];
            }
        }
        if ($errs) {
            flash('danger', implode(' | ', $errs));
            redirect('/app_alarm.php');
        }
        Settings::set('app_call_enabled', p('app_call_enabled') === '1' ? '1' : '0');
        Settings::set('app_call_time', Settings::padTime((string) p('app_call_time')));
        Settings::set('app_alarm_time', Settings::padTime((string) p('app_alarm_time')));
        Settings::set('app_call_text', str_replace("\r\n", "\n", $text));
        $version = Settings::int('app_call_version') + 1;
        if ($newFile) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $old = Settings::get('app_call_audio');
            $name = "call-audio-{$version}.{$newFile[1]}";
            move_uploaded_file($newFile[0], "{$dir}/{$name}");
            @chmod("{$dir}/{$name}", 0644);
            Settings::set('app_call_audio', $name);
            if ($old !== '' && $old !== $name && is_file("{$dir}/{$old}")) {
                @unlink("{$dir}/{$old}");
            }
        }
        if (p('remove_audio') === '1' && !$newFile) {
            $old = Settings::get('app_call_audio');
            if ($old !== '' && is_file("{$dir}/{$old}")) {
                @unlink("{$dir}/{$old}");
            }
            Settings::set('app_call_audio', '');
        }
        Settings::set('app_call_version', (string) $version);
        Audit::log((int) $user['id'], 'app_alarm_settings', 'settings', null, ['enabled' => p('app_call_enabled'), 'call' => p('app_call_time'),
            'alarm' => p('app_alarm_time'), 'audio' => $newFile ? 'uploaded' : (p('remove_audio') === '1' ? 'removed' : 'unchanged')]);
        flash('success', 'Saved – phones pick up the change at their next check (max ~15 min) / सेव हुआ');
        redirect('/app_alarm.php');
    }
    if ($action === 'test') {
        $mode = p('mode') === 'call' ? 'call' : 'alarm';
        $target = (string) p('target');
        $ids = $target === 'all'
            ? array_map('intval', array_column(DB::all('SELECT id FROM bcs WHERE is_active = 1 AND app_last_seen IS NOT NULL'), 'id'))
            : [(int) $target];
        $ids = array_values(array_filter($ids));
        foreach ($ids as $id) {
            App::sendTestRing($id, $mode);
        }
        Audit::log((int) $user['id'], 'app_alarm_test', 'bc', null, ['mode' => $mode, 'bcs' => $ids]);
        flash($ids ? 'success' : 'warning', $ids
            ? count($ids) . " phone(s): test {$mode} sent – rings within ~15 minutes (instant after Firebase) / टेस्ट भेजा गया"
            : 'Select a BC who uses the app / ऐप वाला बीसी चुनें');
        redirect('/app_alarm.php');
    }
}

$s = Settings::all();
$appUsers = DB::all('SELECT id, name, bc_code, app_last_seen FROM bcs WHERE is_active = 1 AND app_last_seen IS NOT NULL ORDER BY app_last_seen DESC');
$tests = DB::all("SELECT n.created_at, n.kind, b.name FROM app_notifications n JOIN bcs b ON b.id = n.bc_id WHERE n.kind IN ('ring_call','ring_alarm') ORDER BY n.id DESC LIMIT 10");
$audio = $s['app_call_audio'];

View::header('App call & alarm / ऐप कॉल और अलार्म', 'alarm');
?>
<div class="alert alert-info small">
  Every day the BC's phone itself rings at the <strong>call time</strong> (looks like an incoming call – on "Accept" your message plays) and again at the
  <strong>alarm time</strong> (alarm sound + message) – <strong>only if today's report is still pending</strong> (not on leave / holiday / weekly off).
  Works only for BCs who installed the app and logged in once. / जिनकी रिपोर्ट बाकी है, उनका फ़ोन तय समय पर कॉल और अलार्म की तरह बजेगा।
</div>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><?= L('Timing and message', 'समय और संदेश') ?></div><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="save">
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="app_call_enabled" value="1" id="en" <?= $s['app_call_enabled'] === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="en"><?= L('Call and alarm on', 'कॉल और अलार्म चालू') ?></label></div></div>
      <div class="col-6"><label class="form-label small"><?= L('📞 Call time', 'कॉल का समय') ?></label><input type="time" name="app_call_time" value="<?= e($s['app_call_time']) ?>" class="form-control" required></div>
      <div class="col-6"><label class="form-label small"><?= L('⏰ Alarm time', 'अलार्म का समय') ?></label><input type="time" name="app_alarm_time" value="<?= e($s['app_alarm_time']) ?>" class="form-control" required></div>
      <div class="col-12"><label class="form-label small"><?= L('Recorded audio (your voice)', 'रिकॉर्ड की हुई आवाज़') ?></label>
        <?php if ($audio !== ''): ?>
          <audio controls src="/uploads/<?= e(rawurlencode($audio)) ?>" class="w-100 mb-1"></audio>
          <div class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="remove_audio" value="1" id="ra"><label class="form-check-label" for="ra">Remove audio (use the text below with phone voice)</label></div>
        <?php else: ?><div class="small text-muted mb-1">No audio uploaded – the phone reads the text below in Hindi voice. / ऑडियो नहीं है – फ़ोन नीचे का टेक्स्ट पढ़ेगा।</div><?php endif; ?>
        <input type="file" name="audio" accept="audio/*,.mp3,.m4a,.aac,.ogg,.opus,.wav,.amr" class="form-control form-control-sm">
        <div class="form-text">MP3 / M4A / WhatsApp voice note (.opus/.ogg), max 5 MB.</div></div>
      <div class="col-12"><label class="form-label small"><?= L('Message text (spoken when there is no audio)', 'संदेश (ऑडियो न हो तो फ़ोन बोलेगा)') ?></label>
        <textarea name="app_call_text" rows="4" class="form-control form-control-sm" maxlength="1000" required><?= e($s['app_call_text']) ?></textarea></div>
      <div class="col-12"><button class="btn btn-success"><i class="bi bi-save"></i> <?= L('Save', 'सेव करें') ?></button></div>
    </form></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card mb-3"><div class="card-header"><?= L('Send a test now', 'अभी टेस्ट भेजें') ?></div><div class="card-body">
      <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="test">
        <div class="col-12"><select name="target" class="form-select" required>
          <option value="">— select BC (app users) —</option>
          <option value="all">All app users (<?= count($appUsers) ?>) / सभी</option>
          <?php foreach ($appUsers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name'] . ' (' . $u['bc_code'] . ')') ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><button name="mode" value="call" class="btn btn-outline-success w-100"><i class="bi bi-telephone-inbound"></i> <?= L('Test call', 'टेस्ट कॉल') ?></button></div>
        <div class="col-6"><button name="mode" value="alarm" class="btn btn-outline-warning w-100"><i class="bi bi-alarm"></i> <?= L('Test alarm', 'टेस्ट अलार्म') ?></button></div>
      </form>
      <p class="small text-muted mt-2 mb-0">The test rings even if the report is already submitted. Without Firebase it arrives with the app's next check (max ~15 min); the daily 5:00 / 5:30 ring is exact because the phone schedules it itself.</p>
    </div></div>
    <div class="card"><div class="card-header small"><?= L('App users', 'ऐप इस्तेमाल करने वाले') ?> (<?= count($appUsers) ?>) · <?= L('last tests', 'पिछले टेस्ट') ?></div>
      <ul class="list-group list-group-flush small" style="max-height:300px;overflow:auto">
        <?php foreach ($tests as $t): ?><li class="list-group-item"><?= e(fmt_dt($t['created_at'])) ?> – <?= $t['kind'] === 'ring_call' ? '📞' : '⏰' ?> <?= e($t['name']) ?></li><?php endforeach; ?>
        <?php if (!$tests): ?><li class="list-group-item text-muted">No tests yet</li><?php endif; ?>
      </ul></div>
  </div>
</div>
<?php View::footer();
