<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\DB;
use App\View;

Auth::requireAdmin();

$where = ['1=1'];
$params = [];
if (q('action') !== '') {
    $where[] = 'a.action LIKE ?';
    $params[] = q('action') . '%';
}
if (q('entity') !== '') {
    $where[] = 'a.entity = ?';
    $params[] = q('entity');
}
if (valid_date(q('date'))) {
    $where[] = 'a.created_at >= ? AND a.created_at < (? + INTERVAL 1 DAY)';
    array_push($params, q('date'), q('date'));
}
$w = implode(' AND ', $where);
$page = max(1, (int) q('page', 1));
$per = 100;
$total = (int) DB::val("SELECT COUNT(*) FROM audit_log a WHERE {$w}", $params);
$rows = DB::all("SELECT a.*, u.name user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE {$w} ORDER BY a.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
$entities = array_column(DB::all('SELECT DISTINCT entity FROM audit_log ORDER BY entity'), 'entity');

View::header('Audit log / ऑडिट लॉग', 'audit');
?>
<form class="row g-2 mb-2">
  <div class="col-6 col-md-3"><input name="action" value="<?= e(q('action')) ?>" class="form-control form-control-sm" placeholder="action (e.g. report_edit)"></div>
  <div class="col-6 col-md-2"><select name="entity" class="form-select form-select-sm"><option value="">All entities</option>
    <?php foreach ($entities as $en): ?><option <?= q('entity') === $en ? 'selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><input type="date" name="date" value="<?= e(q('date')) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<div class="table-responsive"><table class="table table-sm table-striped small">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['user_name'] ?? ($r['user_id'] ? '#' . $r['user_id'] : 'system/BC')) ?></td>
      <td><?= e($r['action']) ?></td><td><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></td>
      <td><code class="small text-break"><?= e(mb_strimwidth((string) $r['details'], 0, 400, '…')) ?></code></td><td><?= e($r['ip']) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= View::pager($total, $page, $per) ?>
<?php View::footer();
