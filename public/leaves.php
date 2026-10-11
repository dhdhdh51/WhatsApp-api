<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Scope;
use App\View;

$user = Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (p('action') === 'add') {
        $bcId = (int) p('bc_id');
        $from = (string) p('from_date');
        $to = (string) p('to_date') ?: $from;
        if (!$bcId || !Scope::canSeeBc($user, $bcId)) {
            flash('danger', 'Select a BC / बीसी चुनें');
        } elseif (!valid_date($from) || !valid_date($to) || $to < $from) {
            flash('danger', 'Invalid dates / गलत तारीख');
        } else {
            $id = DB::insert('INSERT INTO bc_leaves (bc_id, from_date, to_date, reason, created_by) VALUES (?, ?, ?, ?, ?)',
                [$bcId, $from, $to, mb_substr((string) p('reason'), 0, 255) ?: null, $user['id']]);
            DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'on leave' WHERE bc_id = ? AND status = 'pending' AND report_date BETWEEN ? AND ?",
                [$bcId, $from, $to]);
            Audit::log((int) $user['id'], 'leave_add', 'bc', $bcId, ['leave_id' => $id, 'from' => $from, 'to' => $to]);
            flash('success', 'Leave saved / छुट्टी सेव हुई');
        }
    } elseif (p('action') === 'delete') {
        $l = DB::one('SELECT * FROM bc_leaves WHERE id = ?', [(int) p('id')]);
        if ($l && Scope::canSeeBc($user, (int) $l['bc_id'])) {
            DB::exec('DELETE FROM bc_leaves WHERE id = ?', [$l['id']]);
            Audit::log((int) $user['id'], 'leave_delete', 'bc', (int) $l['bc_id'], $l);
            flash('success', 'Leave removed / छुट्टी हटाई');
        }
    }
    redirect('/leaves.php' . qs());
}

[$sw, $sp] = Scope::bc($user);
$bcList = DB::all("SELECT b.id, b.bc_code, b.name FROM bcs b WHERE b.is_active = 1 AND {$sw} ORDER BY b.name", $sp);
$params = $sp;
$extra = '';
if ((int) q('bc_id') > 0) {
    $extra = ' AND l.bc_id = ?';
    $params[] = (int) q('bc_id');
}
$rows = DB::all("SELECT l.*, b.name, b.bc_code, u.name AS by_name FROM bc_leaves l JOIN bcs b ON b.id = l.bc_id LEFT JOIN users u ON u.id = l.created_by
                 WHERE {$sw} AND l.to_date >= (CURDATE() - INTERVAL 60 DAY){$extra} ORDER BY l.from_date DESC LIMIT 500", $params);

View::header('BC leave / बीसी छुट्टी', 'leaves');
?>
<div class="card mb-3"><div class="card-body">
<form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?><input type="hidden" name="action" value="add">
  <div class="col-md-4"><label class="form-label small">BC</label><select name="bc_id" class="form-select form-select-sm" required><option value="">— select —</option>
    <?php foreach ($bcList as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) q('bc_id') === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ' (' . $b['bc_code'] . ')') ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('From', 'से') ?></label><input type="date" name="from_date" class="form-control form-control-sm" required value="<?= e(date('Y-m-d')) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('To', 'तक') ?></label><input type="date" name="to_date" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>"></div>
  <div class="col-md-2"><label class="form-label small"><?= L('Reason', 'कारण') ?></label><input name="reason" class="form-control form-control-sm" maxlength="255"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-success w-100"><?= L('Mark on leave', 'छुट्टी दर्ज करें') ?></button></div>
</form>
</div></div>
<div class="table-responsive"><table class="table table-sm table-striped">
  <thead><tr><th>BC</th><th><?= L('From', 'से') ?></th><th><?= L('To', 'तक') ?></th><th><?= L('Reason', 'कारण') ?></th><th>By</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['name']) ?> <small class="text-muted"><?= e($r['bc_code']) ?></small></td><td><?= e(fmt_date($r['from_date'])) ?></td><td><?= e(fmt_date($r['to_date'])) ?></td>
    <td><?= e($r['reason']) ?></td><td class="small"><?= e($r['by_name']) ?></td>
    <td><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove leave?')"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-muted">No leave records</td></tr><?php endif; ?></tbody>
</table></div>
<?php View::footer();
