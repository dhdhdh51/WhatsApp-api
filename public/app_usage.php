<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\App;
use App\Auth;
use App\DB;
use App\Phone;
use App\Scope;
use App\View;

/**
 * App adoption screen. A direct APK download cannot be linked to a BC, so the definitive metric is
 * "app activated": the BC opened the app and completed at least one successful login.
 */
$user = Auth::require();
[$sw, $sp] = Scope::bc($user);
$status = in_array(q('status'), ['all', 'activated', 'active', 'not_activated', 'logged_out', 'mdm'], true) ? q('status') : 'all';
const LOGGED_OUT_SQL = 'b.app_first_seen IS NOT NULL AND NOT EXISTS (SELECT 1 FROM app_tokens lt WHERE lt.bc_id = b.id)';
$branch = q('branch_id');
$cbc = $user['role'] === 'cbc' ? '' : q('cbc_id');
$search = q('search');

$where = ['b.is_active = 1', $sw];
$params = $sp;
if ($branch !== '') {
    $where[] = 'b.branch_id = ?';
    $params[] = (int) $branch;
}
if ($cbc !== '') {
    $where[] = 'b.cbc_id = ?';
    $params[] = (int) $cbc;
}
if ($search !== '') {
    $where[] = '(b.name LIKE ? OR b.bc_code LIKE ? OR b.phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($status === 'activated') {
    $where[] = 'b.app_first_seen IS NOT NULL';
} elseif ($status === 'active') {
    $where[] = 'b.app_last_seen >= (NOW() - INTERVAL 7 DAY)';
} elseif ($status === 'not_activated') {
    $where[] = 'b.app_first_seen IS NULL';
} elseif ($status === 'logged_out') {
    $where[] = LOGGED_OUT_SQL;
} elseif ($status === 'mdm') {
    $where[] = 'EXISTS (SELECT 1 FROM app_tokens mt WHERE mt.bc_id = b.id AND mt.mdm_owner = 1)';
}
$w = implode(' AND ', $where);

// Aggregate (within the same visibility/filter scope, excluding only status so cards remain useful).
$statsWhere = array_filter($where, static fn ($x) => !in_array($x, [
    'b.app_first_seen IS NOT NULL', 'b.app_last_seen >= (NOW() - INTERVAL 7 DAY)', 'b.app_first_seen IS NULL',
    LOGGED_OUT_SQL,
    'EXISTS (SELECT 1 FROM app_tokens mt WHERE mt.bc_id = b.id AND mt.mdm_owner = 1)',
], true));
$stats = DB::one("SELECT COUNT(*) total,
    SUM(b.app_first_seen IS NOT NULL) activated,
    SUM(b.app_last_seen >= (NOW() - INTERVAL 7 DAY)) active7,
    SUM(b.app_last_seen >= (NOW() - INTERVAL 1 DAY)) active24,
    SUM(" . LOGGED_OUT_SQL . ") logged_out,
    SUM(EXISTS (SELECT 1 FROM app_tokens mt WHERE mt.bc_id = b.id AND mt.mdm_owner = 1)) mdm
    FROM bcs b WHERE " . implode(' AND ', $statsWhere), $params) ?? ['total' => 0, 'activated' => 0, 'active7' => 0, 'active24' => 0, 'logged_out' => 0, 'mdm' => 0];

$page = max(1, (int) q('page', 1));
$per = 100;
$total = (int) DB::val("SELECT COUNT(*) FROM bcs b WHERE {$w}", $params);
$rows = DB::all("SELECT b.id, b.name, b.bc_code, b.phone, b.app_first_seen, b.app_last_seen, b.app_version,
      br.name branch, cu.name cbc_name,
      (SELECT COUNT(*) FROM app_tokens at WHERE at.bc_id = b.id) devices,
      (SELECT COUNT(*) FROM app_tokens at WHERE at.bc_id = b.id AND at.fcm_token IS NOT NULL) fcm,
      (SELECT COUNT(*) FROM app_tokens at WHERE at.bc_id = b.id AND at.mdm_owner = 1) mdm
   FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id LEFT JOIN users cu ON cu.id = b.cbc_id
   WHERE {$w} ORDER BY (b.app_first_seen IS NULL), b.app_last_seen DESC, b.name LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

View::header('App users / ऐप उपयोगकर्ता', 'appusers');
?>
<div class="row g-2 mb-3 text-center">
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'all' ? 'border-success' : '' ?>" href="/app_usage.php"><div class="card-body py-2"><div class="fs-4 fw-bold"><?= (int) $stats['total'] ?></div><div class="small text-muted">Active BCs</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'activated' ? 'border-success' : '' ?>" href="/app_usage.php?status=activated"><div class="card-body py-2"><div class="fs-4 fw-bold text-success"><?= (int) $stats['activated'] ?></div><div class="small text-muted">App activated</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'active' ? 'border-success' : '' ?>" href="/app_usage.php?status=active"><div class="card-body py-2"><div class="fs-4 fw-bold text-primary"><?= (int) $stats['active7'] ?></div><div class="small text-muted">Active last 7 days</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'logged_out' ? 'border-warning' : '' ?>" href="/app_usage.php?status=logged_out"><div class="card-body py-2"><div class="fs-4 fw-bold text-warning"><?= (int) $stats['logged_out'] ?></div><div class="small text-muted">Logout / app hataya</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'not_activated' ? 'border-danger' : '' ?>" href="/app_usage.php?status=not_activated"><div class="card-body py-2"><div class="fs-4 fw-bold text-danger"><?= (int) $stats['total'] - (int) $stats['activated'] ?></div><div class="small text-muted">Not activated</div></div></a></div>
  <?php if (Auth::isAdmin($user)): ?><div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'mdm' ? 'border-success' : '' ?>" href="/app_usage.php?status=mdm"><div class="card-body py-2"><div class="fs-4 fw-bold text-success"><?= (int) $stats['mdm'] ?></div><div class="small text-muted">MDM enrolled</div></div></a></div><?php endif; ?>
</div>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm">
    <?php foreach (['all' => 'All BCs', 'activated' => 'App activated', 'active' => 'Active 7 days', 'logged_out' => 'Logout / app hataya', 'not_activated' => 'Not activated', 'mdm' => 'MDM enrolled'] as $k => $label): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small">Branch</label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, $branch) ?></select></div>
  <?php if ($user['role'] !== 'cbc'): ?><div class="col-6 col-md-2"><label class="form-label small">CBC</label><select name="cbc_id" class="form-select form-select-sm"><?= View::cbcOptions($user, $cbc) ?></select></div><?php endif; ?>
  <div class="col-6 col-md-3"><label class="form-label small">Search</label><input name="search" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="BC name / code / mobile"></div>
  <div class="col-6 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<div class="table-responsive"><table class="table table-sm table-hover align-middle">
  <thead class="table-light"><tr><th>BC</th><th>Branch / CBC</th><th>App status</th><th>App version</th><th>First app login</th><th>Last active</th><th>Push</th><th>MDM</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): [$slabel, $scolour] = App::appStatus($r); ?><tr>
    <td><strong><?= e($r['name']) ?></strong><br><small class="text-muted"><?= e($r['bc_code']) ?> · <?= e(Phone::display($r['phone'])) ?></small></td>
    <td class="small"><?= e($r['branch']) ?><br><span class="text-muted"><?= e($r['cbc_name']) ?></span></td>
    <td><span class="badge text-bg-<?= $scolour ?>"><?= e($slabel) ?></span></td>
    <td><?= $r['app_version'] ? '<span class="badge text-bg-secondary">' . e($r['app_version']) . '</span>' : '<span class="text-muted" title="Abhi pata nahi – browser se login kiya ya app ne version nahi bheja. Agli baar app kholne par apne aap aa jayega.">—</span>' ?></td>
    <td class="small"><?= $r['app_first_seen'] ? e(fmt_dt($r['app_first_seen'])) : '<span class="text-muted">—</span>' ?></td>
    <td class="small"><?= $r['app_last_seen'] ? e(fmt_dt($r['app_last_seen'])) : '—' ?></td>
    <td><?= (int) $r['fcm'] ? '<span class="badge text-bg-success">🔥 connected</span>' : ((int) $r['devices'] ? '<span class="badge text-bg-warning">poll only</span>' : '—') ?></td>
    <td><?= (int) $r['mdm'] ? '<span class="badge text-bg-success">blocked</span>' : '—' ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="/chat.php?bc_id=<?= (int) $r['id'] ?>"><i class="bi bi-chat-dots"></i></a></td>
  </tr><?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-muted">No BCs</td></tr><?php endif; ?></tbody>
</table></div>
<?= View::pager($total, $page, $per) ?>
<?php View::footer();
