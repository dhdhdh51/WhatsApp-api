<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\Phone;
use App\Settings;
use App\View;

$user = Auth::requireAdmin();

$ints = [
    // key => [min, max, English, Hindi]
    'escalation_days'            => [1, 14, 'Escalate after N missed days in a row', 'लगातार कितने दिन चूकने पर सूचना'],
    'daily_limit'                => [1, 5000, 'Daily message limit (scheduled)', 'दैनिक संदेश सीमा'],
    'warmup_days'                => [0, 30, 'Warm-up days', 'वार्म-अप दिन'],
    'warmup_daily_limit'         => [1, 1000, 'Warm-up daily limit', 'वार्म-अप दैनिक सीमा'],
    'min_delay_sec'              => [5, 300, 'Min gap between messages (sec, incl. typing)', 'संदेशों के बीच न्यूनतम अंतर (सेकंड)'],
    'max_delay_sec'              => [5, 600, 'Max gap between messages (sec)', 'अधिकतम अंतर (सेकंड)'],
    'typing_min_sec'             => [0, 10, 'Typing indicator min (sec)', 'टाइपिंग न्यूनतम'],
    'typing_max_sec'             => [0, 15, 'Typing indicator max (sec)', 'टाइपिंग अधिकतम'],
    'max_retries'                => [0, 2, 'Retries on failure (max 2)', 'विफल होने पर पुनः प्रयास'],
    'max_value'                  => [1, 100000, 'Maximum allowed value per scheme', 'प्रति योजना अधिकतम संख्या'],
    'high_value_threshold'       => [0, 100000, 'Ask confirmation above (0 = off)', 'इससे ऊपर पुष्टि माँगें'],
    'unknown_reply_hours'        => [1, 720, 'Reply to the same number at most once every (hours)', 'एक नंबर को कितने घंटे में एक बार जवाब'],
    'unknown_reply_cooldown_min' => [0, 1440, 'Min minutes between "not understood" replies', '"समझ नहीं आया" जवाब का अंतर (मिनट)'],
];
$times = [
    'form_time' => ['Daily form time', 'फॉर्म भेजने का समय'],
    'cutoff_time' => ['Cutoff time (BC can edit until)', 'कटऑफ समय'],
    'summary_time' => ['Admin summary time', 'सारांश का समय'],
    'escalation_time' => ['Escalation check time', 'एस्केलेशन समय'],
    'target_msg_time' => ['Morning target message time', 'सुबह लक्ष्य संदेश का समय'],
];
$bools = ['send_summary' => ['Send daily summary', 'दैनिक सारांश भेजें'], 'send_escalation' => ['Send escalations', 'एस्केलेशन भेजें'],
    'warmup_enabled' => ['Warm-up mode (low limit for the first days)', 'वार्म-अप मोड'],
    'send_target_msg' => ['Send monthly target to BCs in the morning', 'सुबह बीसी को लक्ष्य भेजें'],
    'unknown_auto_reply' => ['Auto-reply to people who are not in the BC team', 'टीम से बाहर वालों को अपने-आप जवाब']];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = [];
    foreach ($times as $k => $_) {
        $v = (string) p($k);
        if (!Settings::validTime($v)) {
            $errors[] = "{$k}: invalid time";
        } else {
            $new[$k] = Settings::padTime($v);
        }
    }
    $rem = [];
    foreach (array_filter(array_map('trim', explode(',', (string) p('reminder_times')))) as $t) {
        if (!Settings::validTime($t)) {
            $errors[] = "Reminder time '{$t}' is invalid (use HH:MM, comma separated)";
        } else {
            $rem[] = Settings::padTime($t);
        }
    }
    $rem = array_values(array_unique($rem));
    sort($rem);
    if (count($rem) > 6) {
        $errors[] = 'Max 6 reminder times';
    }
    $new['reminder_times'] = implode(',', $rem);

    foreach ($ints as $k => [$min, $max]) {
        $v = (string) p($k);
        if (!preg_match('/^\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
            $errors[] = "{$k}: must be between {$min} and {$max}";
        } else {
            $new[$k] = (string) (int) $v;
        }
    }
    foreach ($bools as $k => $_) {
        $new[$k] = p($k) === '1' ? '1' : '0';
    }

    $phones = [];
    foreach (preg_split('/[\s,;]+/', (string) p('admin_phones')) ?: [] as $ph) {
        if ($ph === '') {
            continue;
        }
        $n = Phone::normalize($ph);
        if (!$n) {
            $errors[] = "Admin phone '{$ph}' is not a valid Indian mobile";
        } else {
            $phones[] = $n;
        }
    }
    $new['admin_phones'] = implode(',', array_unique($phones));

    $off = array_values(array_filter(array_map('intval', (array) ($_POST['weekly_off_days'] ?? [])), static fn ($d) => $d >= 0 && $d <= 6));
    $new['weekly_off_days'] = implode(',', array_unique($off));

    $ut = trim(str_replace("\r\n", "\n", (string) ($_POST['unknown_reply_text'] ?? '')));
    if ($ut === '' || mb_strlen($ut) > 1000) {
        $errors[] = 'Reply text for non-team numbers: 1–1000 characters';
    }
    $new['unknown_reply_text'] = $ut;

    $new['target_msg_mode'] = p('target_msg_mode') === 'daily' ? 'daily' : 'on_change';

    $ws = (string) p('warmup_start_date');
    if ($ws !== '' && !valid_date($ws)) {
        $errors[] = 'Warm-up start date invalid';
    }
    $new['warmup_start_date'] = $ws;

    if (!$errors) {
        if ((int) $new['max_delay_sec'] < (int) $new['min_delay_sec']) {
            $errors[] = 'Max gap must be ≥ min gap';
        }
        if ((int) $new['typing_max_sec'] < (int) $new['typing_min_sec']) {
            $errors[] = 'Typing max must be ≥ typing min';
        }
        if ($new['form_time'] >= $new['cutoff_time']) {
            $errors[] = 'Form time must be before cutoff time';
        }
        foreach ($rem as $t) {
            if ($t >= $new['cutoff_time']) {
                $errors[] = "Reminder {$t} is after the cutoff time";
            }
        }
    }

    if (!$errors) {
        $old = Settings::all();
        $changed = [];
        foreach ($new as $k => $v) {
            if (($old[$k] ?? null) !== $v) {
                $changed[$k] = ['old' => $old[$k] ?? null, 'new' => $v];
                Settings::set($k, $v);
            }
        }
        if ($changed) {
            Audit::log((int) $user['id'], 'settings_update', 'settings', null, $changed);
        }
        flash('success', 'Settings saved / सेटिंग्स सेव हुईं');
        redirect('/settings.php');
    }
}

$s = Settings::all();
if ($errors) {
    $s = array_merge($s, array_map('strval', array_filter(array_intersect_key($_POST, $s), 'is_string')));
}
$offDays = Settings::weeklyOffDays();
$dayNames = ['Sun / रवि', 'Mon / सोम', 'Tue / मंगल', 'Wed / बुध', 'Thu / गुरु', 'Fri / शुक्र', 'Sat / शनि'];

View::header('Settings / सेटिंग्स', 'settings');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post"><?= Csrf::field() ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><?= L('Schedule', 'समय-सारणी') ?></div><div class="card-body row g-2">
      <?php foreach ($times as $k => [$en, $hi]): ?>
        <div class="col-6"><label class="form-label small"><?= L($en, $hi) ?></label><input type="time" name="<?= $k ?>" value="<?= e($s[$k]) ?>" class="form-control form-control-sm" required></div>
      <?php endforeach; ?>
      <div class="col-12"><label class="form-label small"><?= L('Reminder times (comma separated, last = final reminder)', 'रिमाइंडर समय (कॉमा से अलग)') ?></label>
        <input name="reminder_times" value="<?= e($s['reminder_times']) ?>" class="form-control form-control-sm" placeholder="19:30,21:00"></div>
      <div class="col-12"><label class="form-label small"><?= L('Weekly off days (no messages)', 'साप्ताहिक अवकाश') ?></label><div>
        <?php foreach ($dayNames as $i => $dn): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekly_off_days[]" value="<?= $i ?>" id="wd<?= $i ?>" <?= in_array($i, $offDays, true) ? 'checked' : '' ?>><label class="form-check-label small" for="wd<?= $i ?>"><?= $dn ?></label></div><?php endforeach; ?>
      </div></div>
      <div class="col-12 border-top pt-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="send_target_msg" value="1" id="send_target_msg" <?= $s['send_target_msg'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="send_target_msg"><?= L(...$bools['send_target_msg']) ?></label></div>
        <select name="target_msg_mode" class="form-select form-select-sm mt-1">
          <option value="on_change" <?= $s['target_msg_mode'] !== 'daily' ? 'selected' : '' ?>>Only the morning after a target is set / changed – लक्ष्य बदलने के बाद अगली सुबह</option>
          <option value="daily" <?= $s['target_msg_mode'] === 'daily' ? 'selected' : '' ?>>Every working morning with progress – रोज़ सुबह प्रगति के साथ</option>
        </select></div>
      <div class="col-6"><label class="form-label small"><?= L($ints['escalation_days'][2], $ints['escalation_days'][3]) ?></label><input type="number" name="escalation_days" value="<?= e($s['escalation_days']) ?>" class="form-control form-control-sm"></div>
      <div class="col-6 pt-4">
        <?php foreach (['send_summary', 'send_escalation'] as $k): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1" id="<?= $k ?>" <?= $s[$k] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $k ?>"><?= L(...$bools[$k]) ?></label></div><?php endforeach; ?>
      </div>
      <div class="col-12"><label class="form-label small"><?= L('Admin WhatsApp number(s) for summaries & escalations', 'सारांश के लिए एडमिन नंबर') ?></label>
        <input name="admin_phones" value="<?= e($s['admin_phones']) ?>" class="form-control form-control-sm" placeholder="9876543210, 9123456789">
        <div class="form-text">Managers with a phone + "receive summary" (Users page) get a summary of their own branches.</div></div>
    </div></div>

    <div class="card mt-3"><div class="card-header"><?= L('Report validation', 'रिपोर्ट जाँच') ?></div><div class="card-body row g-2">
      <?php foreach (['max_value', 'high_value_threshold', 'unknown_reply_cooldown_min'] as $k): ?>
        <div class="col-md-4"><label class="form-label small"><?= L($ints[$k][2], $ints[$k][3]) ?></label><input type="number" name="<?= $k ?>" value="<?= e($s[$k]) ?>" class="form-control form-control-sm"></div>
      <?php endforeach; ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card"><div class="card-header"><?= L('Anti-ban safety', 'सुरक्षा (बैन से बचाव)') ?></div><div class="card-body row g-2">
      <?php foreach (['daily_limit', 'min_delay_sec', 'max_delay_sec', 'typing_min_sec', 'typing_max_sec', 'max_retries'] as $k): ?>
        <div class="col-6"><label class="form-label small"><?= L($ints[$k][2], $ints[$k][3]) ?></label><input type="number" name="<?= $k ?>" value="<?= e($s[$k]) ?>" class="form-control form-control-sm"></div>
      <?php endforeach; ?>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="warmup_enabled" value="1" id="warmup_enabled" <?= $s['warmup_enabled'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="warmup_enabled"><?= L(...$bools['warmup_enabled']) ?></label></div></div>
      <div class="col-4"><label class="form-label small"><?= L('Warm-up start', 'वार्म-अप शुरू') ?></label><input type="date" name="warmup_start_date" value="<?= e($s['warmup_start_date']) ?>" class="form-control form-control-sm"></div>
      <div class="col-4"><label class="form-label small"><?= L($ints['warmup_days'][2], $ints['warmup_days'][3]) ?></label><input type="number" name="warmup_days" value="<?= e($s['warmup_days']) ?>" class="form-control form-control-sm"></div>
      <div class="col-4"><label class="form-label small"><?= L($ints['warmup_daily_limit'][2], $ints['warmup_daily_limit'][3]) ?></label><input type="number" name="warmup_daily_limit" value="<?= e($s['warmup_daily_limit']) ?>" class="form-control form-control-sm"></div>
      <div class="col-12 small text-muted">Current effective limit: <strong><?= Settings::effectiveDailyLimit() ?></strong><?= Settings::isWarmup() ? ' (warm-up active)' : '' ?>.
        Warm-up start is set automatically the first time WhatsApp connects. Summaries/escalations to Admin are not counted. Direct replies to a BC's message are sent immediately and are not limited.</div>
    </div></div>
    <div class="card mt-3"><div class="card-header"><?= L('Messages from non-team numbers', 'टीम से बाहर के नंबर') ?></div><div class="card-body row g-2">
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="unknown_auto_reply" value="1" id="unknown_auto_reply" <?= $s['unknown_auto_reply'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="unknown_auto_reply"><?= L(...$bools['unknown_auto_reply']) ?></label></div></div>
      <div class="col-12"><label class="form-label small"><?= L('Reply text', 'जवाब का संदेश') ?></label>
        <textarea name="unknown_reply_text" rows="4" class="form-control form-control-sm" maxlength="1000"><?= e($s['unknown_reply_text']) ?></textarea></div>
      <div class="col-6"><label class="form-label small"><?= L($ints['unknown_reply_hours'][2], $ints['unknown_reply_hours'][3]) ?></label><input type="number" name="unknown_reply_hours" value="<?= e($s['unknown_reply_hours']) ?>" class="form-control form-control-sm"></div>
      <div class="col-12 small text-muted">Their messages are always saved in <a href="/inbox.php">Inbox</a> (also for inactive BCs). Groups are ignored. / उनके संदेश इनबॉक्स में सेव होते हैं।</div>
    </div></div>
    <div class="card mt-3"><div class="card-body small">
      <a href="/templates.php"><i class="bi bi-card-text"></i> <?= L('Edit message templates', 'संदेश टेम्पलेट बदलें') ?></a>
    </div></div>
  </div>
</div>
<button class="btn btn-success mt-3"><i class="bi bi-save"></i> <?= L('Save settings', 'सेटिंग्स सेव करें') ?></button>
</form>
<?php View::footer();
