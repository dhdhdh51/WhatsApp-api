<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\App;
use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Phone;
use App\Settings;
use App\View;

/** Separate call + alarm for CBCs: rings a CBC's phone when any of their BCs is still pending. Own audio/voice + test. */
$user = Auth::requireAdmin();
$dir = __DIR__ . '/uploads';
$allowed = ['mp3', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'amr', '3gp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');
    if ($action === 'save') {
        $errs = [];
        foreach (['cbc_call_time', 'cbc_alarm_time'] as $k) {
            if (!Settings::validTime((string) p($k))) {
                $errs[] = "{$k}: HH:MM";
            }
        }
        $kinds = ['call' => 'cbc_call', 'alarm' => 'cbc_alarm'];
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
            redirect('/cbc_alarm.php');
        }
        Settings::set('cbc_alarm_enabled', p('cbc_alarm_enabled') === '1' ? '1' : '0');
        Settings::set('cbc_call_time', Settings::padTime((string) p('cbc_call_time')));
        Settings::set('cbc_alarm_time', Settings::padTime((string) p('cbc_alarm_time')));
        $version = Settings::int('cbc_alarm_version') + 1;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $changes = [];
        foreach ($kinds as $k => $pre) {
            Settings::set($pre . '_text', $texts[$pre]);
            $old = Settings::get($pre . '_audio');
            if (isset($uploads[$pre])) {
                [$tmp, $ext] = $uploads[$pre];
                $name = "cbc-{$k}-audio-{$version}.{$ext}";
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
        Settings::set('cbc_alarm_version', (string) $version);
        Audit::log((int) $user['id'], 'cbc_alarm_settings', 'settings', null, ['enabled' => p('cbc_alarm_enabled'),
            'call' => p('cbc_call_time'), 'alarm' => p('cbc_alarm_time'), 'audio' => $changes]);
        flash('success', 'Saved – CBC phones pick up the change at their next check (max ~15 min) / सेव हुआ');
        redirect('/cbc_alarm.php');
    }
    if ($action === 'test') {
        $mode = p('mode') === 'call' ? 'call' : 'alarm';
        $tid = (int) p('target');
        $ok = $tid && DB::val('SELECT COUNT(*) FROM cbc_app_tokens WHERE user_id = ?', [$tid]);
        if ($ok) {
            App::sendTestRingCbc($tid, $mode);
        }
        Audit::log((int) $user['id'], 'cbc_alarm_test', 'user', $tid ?: null, ['mode' => $mode]);
        flash($ok ? 'success' : 'warning', $ok
            ? "Test {$mode} sent to that CBC phone – rings within ~15 minutes / टेस्ट भेजा गया"
            : 'Select a CBC who has logged into the app / ऐप में लॉगिन किया हुआ सीबीसी चुनें');
        redirect('/cbc_alarm.php');
    }
}

$s = Settings::all();
$cbcUsers = DB::all("SELECT u.id, u.name, u.phone, (SELECT MAX(last_used_at) FROM cbc_app_tokens t WHERE t.user_id = u.id) last_seen
                     FROM users u WHERE u.role = 'cbc' AND u.is_active = 1
                       AND EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id)
                     ORDER BY last_seen DESC");
$audioBlock = static function (string $k, string $pre) use ($s): string {
    $file = $s[$pre . '_audio'];
    $h = $file !== ''
        ? '<audio controls src="/uploads/' . e(rawurlencode($file)) . '" class="w-100 mb-1"></audio><div class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="remove_' . $k . '_audio" value="1" id="r' . $k . '"><label class="form-check-label" for="r' . $k . '">Remove (phone reads the text instead)</label></div>'
        : '<div class="small text-muted mb-1">No audio – the phone reads the text below in Hindi voice. / ऑडियो नहीं है – फ़ोन टेक्स्ट पढ़ेगा।</div>';
    return $h . '<input type="file" name="' . $k . '_audio" accept="audio/*,.mp3,.m4a,.aac,.ogg,.opus,.wav,.amr" class="form-control form-control-sm">';
};

View::header('CBC call & alarm / सीबीसी कॉल और अलार्म', 'cbcalarm');
?>
<div class="alert alert-info small">
  Jab kisi CBC ke <strong>kisi bhi BC ki aaj ki report baaki</strong> hogi, us CBC ka phone <strong>call time</strong> par (incoming call jaisa – "Accept" par aawaz bajegi) aur phir <strong>alarm time</strong> par (alarm + aawaz) bajega.
  Ye BC wale alarm se <strong>alag</strong> hai – iski apni audio/voice, text aur time hai. Sirf un CBC pe chalega jinhone app mein login kiya hai.
</div>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><?= L('Timing and message', 'समय और संदेश') ?></div><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="save">
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="cbc_alarm_enabled" value="1" id="en" <?= $s['cbc_alarm_enabled'] === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="en"><?= L('CBC call and alarm on', 'सीबीसी कॉल और अलार्म चालू') ?></label></div></div>
      <div class="col-6"><label class="form-label small"><?= L('📞 Call time', 'कॉल का समय') ?></label><input type="time" name="cbc_call_time" value="<?= e($s['cbc_call_time']) ?>" class="form-control" required></div>
      <div class="col-6"><label class="form-label small"><?= L('⏰ Alarm time', 'अलार्म का समय') ?></label><input type="time" name="cbc_alarm_time" value="<?= e($s['cbc_alarm_time']) ?>" class="form-control" required></div>
      <div class="col-12 border-top pt-2"><h6 class="mb-1">📞 <?= L('Call – recorded audio', 'कॉल – रिकॉर्ड की हुई आवाज़') ?></h6><?= $audioBlock('call', 'cbc_call') ?>
        <label class="form-label small mt-1"><?= L('Call text (spoken when there is no audio)', 'कॉल टेक्स्ट') ?></label>
        <textarea name="cbc_call_text" rows="3" class="form-control form-control-sm" maxlength="1000" required><?= e($s['cbc_call_text']) ?></textarea></div>
      <div class="col-12 border-top pt-2"><h6 class="mb-1">⏰ <?= L('Alarm – recorded audio', 'अलार्म – रिकॉर्ड की हुई आवाज़') ?></h6><?= $audioBlock('alarm', 'cbc_alarm') ?>
        <label class="form-label small mt-1"><?= L('Alarm text (spoken when there is no audio)', 'अलार्म टेक्स्ट') ?></label>
        <textarea name="cbc_alarm_text" rows="3" class="form-control form-control-sm" maxlength="1000" required><?= e($s['cbc_alarm_text']) ?></textarea>
        <div class="form-text">MP3 / M4A / WhatsApp voice note (.opus/.ogg), max 5 MB each. The alarm repeats its audio until stopped.</div></div>
      <div class="col-12"><button class="btn btn-success"><i class="bi bi-save"></i> <?= L('Save', 'सेव करें') ?></button></div>
    </form></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card mb-3"><div class="card-header"><?= L('Send a test now (only to the selected CBC phone)', 'अभी टेस्ट भेजें (सिर्फ़ चुने हुए सीबीसी फ़ोन पर)') ?></div><div class="card-body">
      <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="test">
        <div class="col-12"><select name="target" class="form-select" required>
          <option value="">— select CBC (logged into app) —</option>
          <?php foreach ($cbcUsers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e(Phone::display($u['phone'])) ?>)</option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><button name="mode" value="call" class="btn btn-outline-success w-100"><i class="bi bi-telephone-inbound"></i> <?= L('Test call', 'टेस्ट कॉल') ?></button></div>
        <div class="col-6"><button name="mode" value="alarm" class="btn btn-outline-warning w-100"><i class="bi bi-alarm"></i> <?= L('Test alarm', 'टेस्ट अलार्म') ?></button></div>
      </form>
      <p class="small text-muted mt-2 mb-0">Test rings even if all BCs have reported. Phone rings at its next check (max ~15 min). The daily call/alarm is exact because the phone schedules it itself.</p>
    </div></div>
    <div class="card"><div class="card-header small"><?= L('CBCs logged into the app', 'ऐप में लॉगिन सीबीसी') ?> (<?= count($cbcUsers) ?>)</div>
      <ul class="list-group list-group-flush small" style="max-height:300px;overflow:auto">
        <?php foreach ($cbcUsers as $u): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($u['name']) ?> <span class="text-muted"><?= e(Phone::display($u['phone'])) ?></span></span><span class="text-muted"><?= $u['last_seen'] ? e(fmt_dt($u['last_seen'])) : '—' ?></span></li><?php endforeach; ?>
        <?php if (!$cbcUsers): ?><li class="list-group-item text-muted">Koi CBC ne abhi app login nahi kiya. Pehle "CBC App" page dekhein.</li><?php endif; ?>
      </ul></div>
  </div>
</div>
<?php View::footer();
