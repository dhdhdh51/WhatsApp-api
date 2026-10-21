<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Phone;
use App\View;

$me = Auth::requireAdmin();
$editId = (int) q('edit');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(p('action'), ['delete', 'delete_all_cbc'], true)) {
    $ids = p('action') === 'delete'
        ? [(int) p('id')]
        : array_map('intval', array_column(DB::all("SELECT id FROM users WHERE role = 'cbc'"), 'id'));
    $deleted = [];
    foreach ($ids as $did) {
        $u = DB::one('SELECT id, name, username, role FROM users WHERE id = ?', [$did]);
        if (!$u) {
            continue;
        }
        if ($did === (int) $me['id']) {
            flash('danger', 'You cannot delete yourself / आप खुद को नहीं हटा सकते');
            continue;
        }
        if ($u['role'] === 'admin' && (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1") <= 1) {
            flash('danger', 'Cannot delete the last admin');
            continue;
        }
        $bcs = (int) DB::val('SELECT COUNT(*) FROM bcs WHERE cbc_id = ?', [$did]);
        DB::tx(static function () use ($did): void {
            DB::exec('UPDATE bcs SET cbc_id = NULL WHERE cbc_id = ?', [$did]);   // BCs stay, only unassigned
            DB::exec('DELETE FROM user_branches WHERE user_id = ?', [$did]);
            DB::exec('DELETE FROM users WHERE id = ?', [$did]);
        });
        Audit::log((int) $me['id'], 'user_delete', 'user', $did, ['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'bcs_unassigned' => $bcs]);
        $deleted[] = $u['name'];
    }
    if ($deleted) {
        flash('success', count($deleted) . ' user(s) deleted / हटाए गए: ' . implode(', ', $deleted) . '. Their BCs are kept (now without CBC).');
    }
    redirect('/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) p('id');
    $name = (string) p('name');
    $username = strtolower((string) p('username'));
    $role = in_array(p('role'), ['admin', 'supervisor', 'manager', 'cbc'], true) ? (string) p('role') : 'manager';
    $phoneRaw = (string) p('phone');
    $phone = $phoneRaw !== '' ? Phone::normalize($phoneRaw) : null;
    $active = p('is_active') === '1' ? 1 : 0;
    $summary = p('receive_summary') === '1' ? 1 : 0;
    $pass = (string) ($_POST['password'] ?? '');
    $branches = array_map('intval', (array) ($_POST['branches'] ?? []));

    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'Name required';
    }
    if (!preg_match('/^[a-z0-9_.-]{3,60}$/', $username)) {
        $errors[] = 'Username: 3-60 chars (a-z 0-9 _ . -)';
    }
    if ($phoneRaw !== '' && !$phone) {
        $errors[] = 'Invalid Indian mobile number';
    }
    if ($role === 'cbc' && !$phone) {
        $errors[] = 'CBC ke liye mobile number zaroori hai (app login) / सीबीसी के लिए मोबाइल नंबर आवश्यक है';
    }
    if ($role === 'cbc' && $phone && DB::val('SELECT COUNT(*) FROM bcs WHERE phone = ? AND is_active = 1', [$phone])) {
        $errors[] = 'Ye number pehle se ek active BC ka hai — CBC ko alag number dein (ek number sirf ek login) / यह नंबर पहले से एक बीसी का है';
    }
    if (!$id && mb_strlen($pass) < 10) {
        $errors[] = 'Password required (min 10 chars)';
    }
    if ($id && $pass !== '' && mb_strlen($pass) < 10) {
        $errors[] = 'Password min 10 chars';
    }
    if (DB::val('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$username, $id])) {
        $errors[] = 'Username already taken';
    }
    if ($id === (int) $me['id'] && ($role !== 'admin' || !$active)) {
        $errors[] = 'You cannot demote or deactivate yourself';
    }
    if ($role === 'manager' && !$branches) {
        $errors[] = 'Assign at least one branch to a manager';
    }

    if (!$errors) {
        DB::tx(static function () use (&$id, $name, $username, $role, $phone, $summary, $active, $pass, $branches): void {
            if ($id) {
                DB::exec('UPDATE users SET name = ?, username = ?, role = ?, phone = ?, receive_summary = ?, is_active = ? WHERE id = ?',
                    [$name, $username, $role, $phone, $summary, $active, $id]);
                if ($pass !== '') {
                    DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                }
            } else {
                $id = DB::insert('INSERT INTO users (name, username, password_hash, role, phone, receive_summary, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$name, $username, password_hash($pass, PASSWORD_DEFAULT), $role, $phone, $summary, $active]);
            }
            DB::exec('DELETE FROM user_branches WHERE user_id = ?', [$id]);
            if ($role === 'manager') {
                foreach (array_unique($branches) as $b) {
                    DB::exec('INSERT IGNORE INTO user_branches (user_id, branch_id) SELECT ?, id FROM branches WHERE id = ?', [$id, $b]);
                }
            }
        });
        Audit::log((int) $me['id'], 'user_save', 'user', $id, ['username' => $username, 'role' => $role, 'active' => $active,
            'branches' => $branches, 'password_changed' => $pass !== '']);
        flash('success', 'User saved / यूज़र सेव हुआ');
        redirect('/users.php');
    }
    $editId = $id;
}

$users = DB::all("SELECT u.*, (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ')
                               FROM user_branches ub JOIN branches b ON b.id = ub.branch_id WHERE ub.user_id = u.id) AS branches,
                         (SELECT COUNT(*) FROM bcs WHERE bcs.cbc_id = u.id AND bcs.is_active = 1) AS cbc_bcs
                  FROM users u ORDER BY FIELD(u.role,'admin','supervisor','manager','cbc'), u.name");
$edit = $editId ? DB::one('SELECT * FROM users WHERE id = ?', [$editId]) : null;
$editBranches = $edit ? array_map('intval', array_column(DB::all('SELECT branch_id FROM user_branches WHERE user_id = ?', [$editId]), 'branch_id')) : [];
if ($errors) {
    $edit = array_merge($edit ?? [], ['name' => p('name'), 'username' => p('username'), 'role' => p('role'), 'phone' => p('phone'),
        'receive_summary' => p('receive_summary'), 'is_active' => p('is_active')]);
    $editBranches = array_map('intval', (array) ($_POST['branches'] ?? []));
}
$allBranches = DB::all('SELECT * FROM branches ORDER BY name');

View::header('Users / यूज़र (Admin & Managers)', 'users');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<div class="row g-3">
<div class="col-lg-7">
  <?php $cbcCount = (int) DB::val("SELECT COUNT(*) FROM users WHERE role = 'cbc'"); if ($cbcCount): ?>
  <form method="post" class="mb-2"><?= Csrf::field() ?>
    <button name="action" value="delete_all_cbc" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete ALL <?= $cbcCount ?> CBC logins? BCs are kept, only unassigned.')"><i class="bi bi-trash"></i> <?= L("Delete all CBC logins ({$cbcCount})", 'सभी सीबीसी लॉगिन हटाएँ') ?></button>
  </form>
  <?php endif; ?>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle">
    <thead><tr><th><?= L('Name', 'नाम') ?></th><th>Username</th><th>Role</th><th><?= L('Branches', 'शाखाएँ') ?></th><th>Phone</th><th>Last login</th><th></th></tr></thead>
    <tbody><?php foreach ($users as $u): ?>
      <tr class="<?= $u['is_active'] ? '' : 'table-secondary' ?>"><td><?= e($u['name']) ?></td><td><?= e($u['username']) ?></td>
        <td><span class="badge text-bg-<?= ['admin' => 'danger', 'supervisor' => 'warning', 'manager' => 'primary', 'cbc' => 'info'][$u['role']] ?? 'secondary' ?>"><?= e(Auth::roleLabel($u['role'])) ?></span><?= $u['is_active'] ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?></td>
        <td class="small"><?php if (in_array($u['role'], ['admin', 'supervisor'], true)): ?>All BCs<?php elseif ($u['role'] === 'cbc'): ?><a href="/bcs.php?cbc_id=<?= (int) $u['id'] ?>"><?= (int) $u['cbc_bcs'] ?> BCs</a><?php else: ?><?= e($u['branches']) ?><?php endif; ?></td>
        <td class="small"><?= e(Phone::display($u['phone'])) ?><?= $u['receive_summary'] ? ' 📊' : '' ?></td>
        <td class="small"><?= e(fmt_dt($u['last_login_at'])) ?></td>
        <td class="text-nowrap"><a href="/users.php?edit=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
          <?php if ((int) $u['id'] !== (int) $me['id']): ?><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete login of <?= e(addslashes($u['name'])) ?>? BCs are kept, only unassigned.')"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<div class="col-lg-5">
  <div class="card"><div class="card-header"><?= $edit && !empty($edit['id']) ? L('Edit user', 'यूज़र बदलें') : L('Add user', 'यूज़र जोड़ें') ?></div><div class="card-body">
  <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="col-6"><label class="form-label small"><?= L('Name', 'नाम') ?></label><input name="name" class="form-control form-control-sm" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div class="col-6"><label class="form-label small">Username</label><input name="username" class="form-control form-control-sm" required value="<?= e($edit['username'] ?? '') ?>"></div>
    <div class="col-6"><label class="form-label small">Role</label><select name="role" class="form-select form-select-sm">
      <?php foreach (['cbc' => 'CBC (only own BCs)', 'supervisor' => 'BC Supervisor (all BCs)', 'manager' => 'Manager (branches)', 'admin' => 'Admin (everything)'] as $rk => $rl): ?>
        <option value="<?= $rk ?>" <?= ($edit['role'] ?? 'cbc') === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
      <?php endforeach; ?></select></div>
    <div class="col-6"><label class="form-label small"><?= L('Password', 'पासवर्ड') ?></label><input type="password" name="password" class="form-control form-control-sm" minlength="10" placeholder="<?= !empty($edit['id']) ? 'leave empty = unchanged' : 'min 10 chars' ?>"></div>
    <div class="col-6"><label class="form-label small"><?= L('Mobile number', 'मोबाइल नंबर') ?> <span class="text-danger" id="phoneReq" style="display:none">*</span></label>
      <div class="input-group input-group-sm"><span class="input-group-text">+91</span><input name="phone" class="form-control form-control-sm" value="<?= e($edit['phone'] ?? '') ?>" inputmode="tel" maxlength="16" placeholder="98765 43210"></div>
      <div class="form-text small"><?= L('CBC logs into the app with this number + password. WhatsApp summary bhi isi par.', 'सीबीसी इसी नंबर + पासवर्ड से ऐप में लॉगिन करता है।') ?></div></div>
    <div class="col-6 pt-3">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="receive_summary" value="1" id="rs" <?= !empty($edit['receive_summary']) ? 'checked' : '' ?>><label class="form-check-label small" for="rs">WhatsApp summary 9 PM (pending list of own BCs)</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ia" <?= !isset($edit['is_active']) || $edit['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="ia">Active</label></div></div>
    <div class="col-12"><label class="form-label small"><?= L('Branches (only for Manager)', 'शाखाएँ') ?></label>
      <select name="branches[]" multiple class="form-select form-select-sm" size="6">
      <?php foreach ($allBranches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= in_array((int) $b['id'], $editBranches, true) ? 'selected' : '' ?>><?= e($b['name']) ?><?= $b['area'] ? ' (' . e($b['area']) . ')' : '' ?></option><?php endforeach; ?>
      </select><div class="form-text">Ctrl/Cmd-click to select several. CBC: assign BCs on the BCs page (Bulk "Assign CBC") or in Edit BC.</div></div>
    <div class="col-12"><button class="btn btn-success btn-sm"><?= L('Save', 'सेव') ?></button> <a href="/users.php" class="btn btn-outline-secondary btn-sm">New</a></div>
  </form></div></div>
</div>
</div>
<?php View::footer('<script>(function(){var r=document.querySelector("select[name=role]"),req=document.getElementById("phoneReq");if(r&&req){var t=function(){req.style.display=r.value==="cbc"?"inline":"none";};r.addEventListener("change",t);t();}})();</script>');
