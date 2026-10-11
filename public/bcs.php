<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Export\Exporter;
use App\Phone;
use App\Scope;
use App\View;

$user = Auth::require();
$isAdmin = Auth::isAdmin($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Admin only');
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
    $rows = DB::all("SELECT b.*, br.name branch, br.area FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id WHERE {$w} ORDER BY b.bc_code", $params);
    $out = array_map(static fn ($r) => [$r['bc_code'], $r['name'], $r['phone'], $r['branch'], $r['area'], $r['block'],
        $r['is_active'], $r['opted_out'], $r['notes']], $rows);
    Exporter::download('csv', 'bcs_' . date('Ymd'), 'BCs',
        ['bc_code', 'name', 'phone', 'branch', 'area', 'block', 'is_active', 'opted_out', 'notes'], $out);
}

$page = max(1, (int) q('page', 1));
$per = 50;
$total = (int) DB::val("SELECT COUNT(*) FROM bcs b WHERE {$w}", $params);
$rows = DB::all("SELECT b.*, br.name branch FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id WHERE {$w}
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
  <div class="col-8 col-md-3"><input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="Name / BC ID / phone"></div>
  <div class="col-4 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>

<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light"><tr><th>BC ID</th><th><?= L('Name', 'नाम') ?></th><th>WhatsApp</th><th><?= L('Branch', 'शाखा') ?></th><th><?= L('Block', 'ब्लॉक') ?></th><th><?= L('Status', 'स्थिति') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= $r['is_active'] ? '' : 'table-secondary' ?>">
    <td><?= e($r['bc_code']) ?></td>
    <td><a href="/conversation.php?bc_id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
    <td class="small"><?= e(Phone::display($r['phone'])) ?></td>
    <td class="small"><?= e($r['branch']) ?></td>
    <td class="small"><?= e($r['block']) ?></td>
    <td><?= $r['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?>
        <?= $r['opted_out'] ? '<span class="badge text-bg-warning" title="' . e(fmt_dt($r['opted_out_at'])) . '">STOP – no reminders</span>' : '' ?></td>
    <td class="text-nowrap">
      <?php if ($isAdmin): ?>
      <a class="btn btn-sm btn-outline-secondary" href="/bc_edit.php?id=<?= (int) $r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
      <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button name="action" value="toggle_active" class="btn btn-sm btn-outline-<?= $r['is_active'] ? 'danger' : 'success' ?>" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"
          onclick="return confirm('Change active status?')"><i class="bi bi-<?= $r['is_active'] ? 'pause' : 'play' ?>-circle"></i></button>
        <?php if ($r['opted_out']): ?><button name="action" value="clear_stop" class="btn btn-sm btn-outline-warning" title="Clear STOP"><i class="bi bi-bell"></i></button><?php endif; ?>
      </form>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline-info" href="/leaves.php?bc_id=<?= (int) $r['id'] ?>" title="Leave"><i class="bi bi-calendar-x"></i></a>
    </td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-muted">No BCs / कोई बीसी नहीं</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= View::pager($total, $page, $per) ?>
<?php View::footer();
