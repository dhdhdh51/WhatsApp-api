<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\View;

$user = Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (p('action') === 'add') {
        $date = (string) p('holiday_date');
        $title = (string) p('title');
        $branch = p('branch_id') !== '' ? (int) p('branch_id') : null;
        if (!valid_date($date) || $title === '') {
            flash('danger', 'Date and title required / तारीख और नाम आवश्यक');
        } elseif (DB::val('SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND branch_id <=> ?', [$date, $branch])) {
            flash('warning', 'Holiday already exists / पहले से दर्ज');
        } else {
            $id = DB::insert('INSERT INTO holidays (holiday_date, title, branch_id, created_by) VALUES (?, ?, ?, ?)',
                [$date, mb_substr($title, 0, 120), $branch, $user['id']]);
            if ($date === date('Y-m-d')) {
                // stop today's scheduled messages for affected BCs
                $params = [$date];
                $extra = $branch ? ' AND bc_id IN (SELECT id FROM bcs WHERE branch_id = ?)' : '';
                if ($branch) {
                    $params[] = $branch;
                }
                DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'holiday' WHERE status = 'pending' AND report_date = ?{$extra}", $params);
            }
            Audit::log((int) $user['id'], 'holiday_add', 'holiday', $id, ['date' => $date, 'title' => $title, 'branch_id' => $branch]);
            flash('success', 'Holiday saved / अवकाश सेव हुआ');
        }
    } elseif (p('action') === 'delete') {
        $h = DB::one('SELECT * FROM holidays WHERE id = ?', [(int) p('id')]);
        if ($h) {
            DB::exec('DELETE FROM holidays WHERE id = ?', [$h['id']]);
            Audit::log((int) $user['id'], 'holiday_delete', 'holiday', (int) $h['id'], $h);
            flash('success', 'Holiday removed / अवकाश हटाया');
        }
    }
    redirect('/holidays.php');
}

$rows = DB::all('SELECT h.*, br.name branch FROM holidays h LEFT JOIN branches br ON br.id = h.branch_id
                 WHERE h.holiday_date >= (CURDATE() - INTERVAL 60 DAY) ORDER BY h.holiday_date DESC');

View::header('Holidays / अवकाश', 'holidays');
?>
<div class="card mb-3"><div class="card-body">
<form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?><input type="hidden" name="action" value="add">
  <div class="col-md-2"><label class="form-label small"><?= L('Date', 'तारीख') ?></label><input type="date" name="holiday_date" class="form-control form-control-sm" required></div>
  <div class="col-md-4"><label class="form-label small"><?= L('Title', 'नाम') ?></label><input name="title" class="form-control form-control-sm" required maxlength="120" placeholder="Diwali"></div>
  <div class="col-md-3"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, '', true) ?></select></div>
  <div class="col-md-2"><button class="btn btn-sm btn-success w-100"><?= L('Mark holiday', 'अवकाश जोड़ें') ?></button></div>
</form>
<p class="small text-muted mb-0 mt-2">No form / reminder is sent on a holiday and it does not count as a missed day. "All branches" = whole organisation.
 Weekly offs (e.g. Sunday) are set in Settings. / अवकाश के दिन कोई संदेश नहीं जाता।</p>
</div></div>
<div class="table-responsive"><table class="table table-sm table-striped">
  <thead><tr><th><?= L('Date', 'तारीख') ?></th><th><?= L('Title', 'नाम') ?></th><th><?= L('Branch', 'शाखा') ?></th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
  <tr><td><?= e(fmt_date($r['holiday_date'])) ?> <small class="text-muted"><?= e(date('D', strtotime($r['holiday_date']))) ?></small></td><td><?= e($r['title']) ?></td><td><?= e($r['branch'] ?? 'All / सभी') ?></td>
    <td><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove holiday?')"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="text-muted">No holidays</td></tr><?php endif; ?></tbody>
</table></div>
<?php View::footer();
