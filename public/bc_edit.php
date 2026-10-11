<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Phone;
use App\Queue;
use App\View;

$user = Auth::requireAdmin();
$id = (int) (q('id') ?: p('id'));
$bc = $id ? DB::one('SELECT * FROM bcs WHERE id = ?', [$id]) : null;
if ($id && !$bc) {
    flash('danger', 'BC not found');
    redirect('/bcs.php');
}

$data = $bc ?? ['bc_code' => '', 'name' => '', 'phone' => '', 'branch_id' => '', 'block' => '', 'is_active' => 1, 'notes' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'bc_code' => strtoupper((string) p('bc_code')),
        'name' => (string) p('name'),
        'phone' => (string) p('phone'),
        'branch_id' => p('branch_id') !== '' ? (int) p('branch_id') : null,
        'block' => (string) p('block'),
        'is_active' => p('is_active') === '1' ? 1 : 0,
        'notes' => (string) p('notes'),
    ];
    if (!preg_match('/^[A-Z0-9_\-\/]{1,40}$/', $data['bc_code'])) {
        $errors[] = 'BC ID required (letters, digits, - _ /, max 40) / बीसी आईडी आवश्यक';
    }
    if ($data['name'] === '' || mb_strlen($data['name']) > 120) {
        $errors[] = 'Name required (max 120) / नाम आवश्यक';
    }
    $phone = Phone::normalize($data['phone']);
    if (!$phone) {
        $errors[] = 'Invalid Indian mobile number (10 digits starting 6-9, +91 optional) / गलत मोबाइल नंबर';
    }
    if ($data['branch_id'] && !DB::val('SELECT COUNT(*) FROM branches WHERE id = ?', [$data['branch_id']])) {
        $errors[] = 'Invalid branch';
    }
    if (!$errors) {
        if (DB::val('SELECT COUNT(*) FROM bcs WHERE bc_code = ? AND id <> ?', [$data['bc_code'], $id])) {
            $errors[] = 'BC ID already exists / यह बीसी आईडी पहले से है';
        }
        if (DB::val('SELECT COUNT(*) FROM bcs WHERE phone = ? AND id <> ?', [$phone, $id])) {
            $errors[] = 'This WhatsApp number is already used by another BC / यह नंबर पहले से दर्ज है';
        }
    }
    if (!$errors) {
        $vals = [$data['bc_code'], $data['name'], $phone, $data['branch_id'], $data['block'] ?: null, $data['is_active'], $data['notes'] ?: null];
        if ($bc) {
            $phoneChanged = $bc['phone'] !== $phone;
            DB::exec('UPDATE bcs SET bc_code = ?, name = ?, phone = ?, branch_id = ?, block = ?, is_active = ?, notes = ?' . ($phoneChanged ? ', wa_lid = NULL' : '') . ' WHERE id = ?',
                array_merge($vals, [$id]));
            if (!$data['is_active']) {
                DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'BC deactivated' WHERE bc_id = ? AND status = 'pending'", [$id]);
            }
            Audit::log((int) $user['id'], 'bc_update', 'bc', $id, ['old' => array_intersect_key($bc, $data + ['phone' => 1]), 'new' => $data + ['phone' => $phone]]);
            $msg = 'BC updated / बीसी अपडेट हुआ';
        } else {
            $id = DB::insert('INSERT INTO bcs (bc_code, name, phone, branch_id, block, is_active, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())', $vals);
            Audit::log((int) $user['id'], 'bc_create', 'bc', $id, $data + ['phone' => $phone]);
            $msg = 'BC added / बीसी जोड़ा गया';
        }
        if (p('send_welcome') === '1') {
            $fresh = DB::one('SELECT * FROM bcs WHERE id = ?', [$id]);
            $msg .= $fresh && Queue::welcome($fresh, (int) $user['id'])
                ? ' – welcome message queued on WhatsApp / व्हाट्सऐप पर स्वागत संदेश भेजा जा रहा है'
                : ' – welcome NOT sent (BC inactive or STOP) / स्वागत संदेश नहीं भेजा';
        }
        flash('success', $msg);
        redirect('/bcs.php');
    }
}

View::header($bc ? 'Edit BC / बीसी बदलें' : 'Add BC / बीसी जोड़ें', 'bcs');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<div class="card"><div class="card-body">
<form method="post" class="row g-3">
  <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="col-md-3"><label class="form-label"><?= L('BC ID', 'बीसी आईडी') ?> *</label><input name="bc_code" class="form-control" required maxlength="40" value="<?= e($data['bc_code']) ?>"></div>
  <div class="col-md-5"><label class="form-label"><?= L('Name', 'नाम') ?> *</label><input name="name" class="form-control" required maxlength="120" value="<?= e($data['name']) ?>"></div>
  <div class="col-md-4"><label class="form-label"><?= L('WhatsApp number', 'व्हाट्सऐप नंबर') ?> *</label>
    <div class="input-group"><span class="input-group-text">+91</span><input name="phone" class="form-control" required inputmode="tel" maxlength="16" value="<?= e($data['phone']) ?>" placeholder="98765 43210"></div></div>
  <div class="col-md-4"><label class="form-label"><?= L('Branch / area', 'शाखा / क्षेत्र') ?></label>
    <select name="branch_id" class="form-select"><option value="">—</option><?= View::branchOptions($user, $data['branch_id'], false) ?></select></div>
  <div class="col-md-4"><label class="form-label"><?= L('Block', 'ब्लॉक') ?></label><input name="block" class="form-control" maxlength="120" value="<?= e($data['block']) ?>" list="blocks">
    <datalist id="blocks"><?php foreach (App\Scope::blocks($user) as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?></datalist></div>
  <div class="col-md-4"><label class="form-label"><?= L('Status', 'स्थिति') ?></label>
    <select name="is_active" class="form-select"><option value="1" <?= $data['is_active'] ? 'selected' : '' ?>>Active / सक्रिय</option><option value="0" <?= !$data['is_active'] ? 'selected' : '' ?>>Inactive / निष्क्रिय</option></select></div>
  <div class="col-12"><label class="form-label"><?= L('Notes', 'टिप्पणी') ?></label><input name="notes" class="form-control" maxlength="255" value="<?= e($data['notes']) ?>"></div>
  <div class="col-12"><div class="form-check">
    <input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="sw" <?= !$bc ? 'checked' : '' ?> data-auto-welcome="<?= $bc ? e($bc['phone']) : '' ?>">
    <label class="form-check-label" for="sw"><?= L('Send WhatsApp welcome message (how the daily report works)', 'व्हाट्सऐप पर स्वागत संदेश भेजें (रिपोर्ट कैसे भेजनी है)') ?></label>
    <?php if ($bc): ?><div class="form-text"><?= $bc['welcome_sent_at'] ? 'Welcome delivered on ' . e(fmt_dt($bc['welcome_sent_at'])) : 'Welcome not delivered yet / अभी नहीं भेजा गया' ?> · auto-ticked when the number changes</div><?php endif; ?>
  </div></div>
  <div class="col-12"><button class="btn btn-success"><?= L('Save', 'सेव करें') ?></button> <a href="/bcs.php" class="btn btn-outline-secondary"><?= L('Cancel', 'रद्द') ?></a></div>
</form>
</div></div>
<?php View::footer('<script>(function(){const cb=document.getElementById("sw"),ph=document.querySelector("input[name=phone]");
if(cb&&ph&&cb.dataset.autoWelcome){const old=cb.dataset.autoWelcome;ph.addEventListener("input",()=>{const d=ph.value.replace(/\D/g,"").slice(-10);if(d.length===10&&d!==old)cb.checked=true;});}})();</script>');
