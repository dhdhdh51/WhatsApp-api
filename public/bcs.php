<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Export\Exporter;
use App\Phone;
use App\Queue;
use App\Scope;
use App\View;

$user = Auth::require();
$isAdmin = Auth::isAdmin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Admin only');
    }
    if (p('action') === 'assign_cbc') {
        $ids = array_map('intval', (array) ($_POST['bc_ids'] ?? []));
        $cbcId = p('cbc_id') !== '' ? (int) p('cbc_id') : null;
        if ($cbcId && !DB::val("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'cbc'", [$cbcId])) {
            $cbcId = null;
        }
        if ($ids) {
            $params = [$cbcId];
            $n = DB::exec('UPDATE bcs SET cbc_id = ? WHERE id IN (' . DB::in($ids, $params) . ')', $params);
            Audit::log((int) $user['id'], 'bc_assign_cbc', 'bc', null, ['cbc_id' => $cbcId, 'bc_ids' => $ids]);
            flash('success', "{$n} BCs assigned / सीबीसी बदला गया");
        } else {
            flash('warning', 'Select BCs first / पहले बीसी चुनें');
        }
        redirect('/bcs.php' . qs());
    }
    $id = (int) p('id');
    $bc = DB::one('SELECT * FROM bcs WHERE id = ?', [$id]);
    if ($bc) {
        switch (p('action')) {
            case 'toggle_active':
                $new = (int) $bc['is_active'] === 1 ? 0 : 1;
                DB::exec('UPDATE bcs SET is_active = ? WHERE id = ?', [$new, $id]);
                if ($new === 0) {
                    DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'BC deactivated' WHERE bc_id = ? AND status = 'pending'", [$id]);
                }
                Audit::log((int) $user['id'], $new ? 'bc_activate' : 'bc_deactivate', 'bc', $id);
                flash('success', $new ? 'BC activated / सक्रिय' : 'BC deactivated / निष्क्रिय');
                break;
            case 'welcome':
                $ok = Queue::welcome($bc, (int) $user['id']);
                if ($ok) {
                    Audit::log((int) $user['id'], 'bc_welcome', 'bc', $id);
                }
                flash($ok ? 'success' : 'warning', $ok
                    ? 'Welcome message queued / स्वागत संदेश कतार में'
                    : 'Not sent – BC is inactive or sent STOP / नहीं भेजा (निष्क्रिय या STOP)');
                break;
            case 'clear_stop':
                DB::exec('UPDATE bcs SET opted_out = 0, opted_out_at = NULL WHERE id = ?', [$id]);
                Audit::log((int) $user['id'], 'bc_opt_in', 'bc', $id, ['via' => 'panel']);
                flash('success', 'STOP flag cleared – reminders will be sent again / रिमाइंडर फिर से चालू');
                break;
        }
    }
    redirect('/bcs.php' . qs());
}

[$sw, $sp] = Scope::bc($user);
$where = [$sw];
$params = $sp;
if (q('branch_id') !== '') {
    $where[] = 'b.branch_id = ?';
    $params[] = (int) q('branch_id');
}
if (q('block') !== '') {
    $where[] = 'b.block = ?';
    $params[] = q('block');
}
if (q('active') !== '') {
    $where[] = 'b.is_active = ?';
    $params[] = q('active') === '1' ? 1 : 0;
}
if (q('cbc_id') === 'none') {
    $where[] = 'b.cbc_id IS NULL';
} elseif (q('cbc_id') !== '') {
    $where[] = 'b.cbc_id = ?';
    $params[] = (int) q('cbc_id');
}
if (q('stop') === '1') {
    $where[] = 'b.opted_out = 1';
}
if (q('search') !== '') {
    $where[] = '(b.name LIKE ? OR b.bc_code LIKE ? OR b.phone LIKE ?)';
    $s = '%' . q('search') . '%';
    array_push($params, $s, $s, $s);
}
$w = implode(' AND ', $where);

if (q('export') === 'csv') {
    $rows = DB::all("SELECT b.*, br.name branch, cu.name AS cbc_name, br.area, cu.name cbc FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id LEFT JOIN users cu ON cu.id = b.cbc_id WHERE {$w} ORDER BY b.bc_code", $params);
    $out = array_map(static fn ($r) => [$r['bc_code'], $r['name'], $r['phone'], $r['branch'], $r['area'], $r['block'], (string) $r['cbc'],
        $r['is_active'], $r['opted_out'], $r['notes']], $rows);
    Exporter::download('csv', 'bcs_' . date('Ymd'), 'BCs',
        ['bc_code', 'name', 'phone', 'branch', 'area', 'block', 'cbc', 'is_active', 'opted_out', 'notes'], $out);
}

$page = max(1, (int) q('page', 1));
$per = 50;
$total = (int) DB::val("SELECT COUNT(*) FROM bcs b WHERE {$w}", $params);
$rows = DB::all("SELECT b.*, br.name branch, cu.name AS cbc_name,
                   (SELECT q.status FROM message_queue q WHERE q.bc_id = b.id AND q.kind = 'welcome' ORDER BY q.id DESC LIMIT 1) AS welcome_status
                 FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id LEFT JOIN users cu ON cu.id = b.cbc_id WHERE {$w}
                 ORDER BY b.is_active DESC, br.name, b.name LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

View::header('BC management / बीसी प्रबंधन', 'bcs');
?>
<div class="d-flex flex-wrap gap-2 mb-2">
  <?php if ($isAdmin): ?>
  <a href="/bc_edit.php" class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i> <?= L('Add BC', 'बीसी जोड़ें') ?></a>
  <a href="/bc_import.php" class="btn btn-sm btn-outline-success"><i class="bi bi-upload"></i> <?= L('CSV import', 'CSV इम्पोर्ट') ?></a>
  <?php endif; ?>
  <a href="<?= e(qs(['export' => 'csv', 'page' => null])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> <?= L('CSV export', 'CSV एक्सपोर्ट') ?></a>
</div>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, q('branch_id')) ?></select></div>
  <div class="col-6 col-md-2"><select name="block" class="form-select form-select-sm"><?= View::blockOptions($user, q('block')) ?></select></div>
  <div class="col-6 col-md-2"><select name="active" class="form-select form-select-sm">
    <option value="">Active + inactive / सभी</option>
    <option value="1" <?= q('active') === '1' ? 'selected' : '' ?>>Active / सक्रिय</option>
    <option value="0" <?= q('active') === '0' ? 'selected' : '' ?>>Inactive / निष्क्रिय</option></select></div>
  <div class="col-6 col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="stop" value="1" id="stop" <?= q('stop') === '1' ? 'checked' : '' ?>><label for="stop" class="form-check-label small">Sent STOP only</label></div></div>
  <?php if ($user['role'] !== 'cbc'): ?><div class="col-6 col-md-2"><select name="cbc_id" class="form-select form-select-sm"><?= str_replace('<option value="">All CBCs / सभी सीबीसी</option>', '<option value="">All CBCs / सभी सीबीसी</option><option value="none"' . (q('cbc_id') === 'none' ? ' selected' : '') . '>— No CBC / सीबीसी नहीं</option>', View::cbcOptions($user, q('cbc_id'), 'All CBCs / सभी सीबीसी', $isAdmin)) ?></select></div><?php endif; ?>
  <div class="col-8 col-md-2"><input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="Name / BC ID / phone"></div>
  <div class="col-4 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>

<?php if ($isAdmin): ?>
<form method="post" id="bulk" class="d-flex flex-wrap gap-2 align-items-center mb-2 small"><?= Csrf::field() ?><input type="hidden" name="action" value="assign_cbc">
  <span><?= L('Selected BCs → assign CBC', 'चुने हुए बीसी → सीबीसी') ?>:</span>
  <select name="cbc_id" class="form-select form-select-sm" style="max-width:240px"><?= View::cbcOptions($user, '', '— remove CBC / हटाएँ —', true) ?></select>
  <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Assign CBC to selected BCs?')"><i class="bi bi-person-check"></i> <?= L('Assign', 'असाइन') ?></button>
  <a href="/users.php" class="small">+ CBC login</a>
</form>
<?php endif; ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light"><tr><?php if ($isAdmin): ?><th><input type="checkbox" class="form-check-input" data-check-all="bc_ids[]"></th><?php endif; ?><th>BC ID</th><th><?= L('Name', 'नाम') ?></th><th>WhatsApp</th><th><?= L('Branch', 'शाखा') ?></th><th><?= L('Block', 'ब्लॉक') ?></th><th>CBC</th><th><?= L('Status', 'स्थिति') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= $r['is_active'] ? '' : 'table-secondary' ?>">
    <?php if ($isAdmin): ?><td><input type="checkbox" class="form-check-input" name="bc_ids[]" value="<?= (int) $r['id'] ?>" form="bulk"></td><?php endif; ?>
    <td><?= e($r['bc_code']) ?></td>
    <td><a href="/conversation.php?bc_id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
    <td class="small"><?= e(Phone::display($r['phone'])) ?></td>
    <td class="small"><?= e($r['branch']) ?></td>
    <td class="small"><?= e($r['block']) ?></td>
    <td class="small"><?= e($r['cbc_name']) ?></td>
    <td><?= $r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?>
        <?= $r['opted_out'] ? '<span class="badge text-bg-warning" title="' . e(fmt_dt($r['opted_out_at'])) . '">STOP – no reminders</span>' : '' ?>
        <?php if ($r['welcome_sent_at']): ?><span class="badge text-bg-light border" title="<?= e(fmt_dt($r['welcome_sent_at'])) ?>">👋 welcomed</span>
        <?php elseif (in_array($r['welcome_status'], ['pending', 'sending'], true)): ?><span class="badge text-bg-info">👋 welcome queued</span>
        <?php elseif ($r['welcome_status'] === 'failed'): ?><span class="badge text-bg-danger">👋 welcome failed</span>
        <?php endif; ?></td>
    <td class="text-nowrap">
      <?php if ($isAdmin): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/bc_edit.php?id=<?= (int) $r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
      <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button name="action" value="toggle_active" class="btn btn-sm btn-outline-<?= $r['is_active'] ? 'danger' : 'success' ?>" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"
          onclick="return confirm('Change active status?')"><i class="bi bi-<?= $r['is_active'] ? 'pause' : 'play' ?>-circle"></i></button>
        <?php if ($r['is_active'] && !$r['opted_out']): ?><button name="action" value="welcome" class="btn btn-sm btn-outline-success" title="Send welcome / how-to message" onclick="return confirm('Send the welcome message to this BC?')"><i class="bi bi-envelope-heart"></i></button><?php endif; ?>
        <?php if ($r['opted_out']): ?><button name="action" value="clear_stop" class="btn btn-sm btn-outline-warning" title="Clear STOP"><i class="bi bi-bell"></i></button><?php endif; ?>
      </form>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-info" href="/leaves.php?bc_id=<?= (int) $r['id'] ?>" title="Leave"><i class="bi bi-calendar-x"></i></a>
    </td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-muted">No BCs / कोई बीसी नहीं</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= View::pager($total, $page, $per) ?>
<?php View::footer();
