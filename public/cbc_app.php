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
 * CBC app adoption: who installed and logged into the mobile app, which app
 * version they run, who logged out / removed it, and who never installed it.
 */
$user = Auth::require();
$status = in_array(q('status'), ['all', 'logged_in', 'logged_out', 'never', 'active'], true) ? q('status') : 'all';
$search = (string) q('search');

// Visibility: admin (and a region-less supervisor) see every CBC; a regional supervisor / manager only CBCs of BCs they can see.
if (Scope::seesAll($user)) {
    $scopeWhere = '1=1';
    $scopeParams = [];
} else {
    [$sw, $sp] = Scope::bc($user);
    $scopeWhere = "EXISTS (SELECT 1 FROM bcs b WHERE b.cbc_id = u.id AND {$sw})";
    $scopeParams = $sp;
}

$where = ["u.role = 'cbc'", 'u.is_active = 1', $scopeWhere];
$params = $scopeParams;
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
$has = 'EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id)';
if ($status === 'logged_in') {
    $where[] = $has;
} elseif ($status === 'logged_out') {
    $where[] = "NOT {$has} AND u.app_first_seen IS NOT NULL";
} elseif ($status === 'never') {
    $where[] = 'u.app_first_seen IS NULL';
} elseif ($status === 'active') {
    $where[] = 'u.app_last_seen >= (NOW() - INTERVAL 7 DAY)';
}
$w = implode(' AND ', $where);

$rows = DB::all(
    "SELECT u.id, u.name, u.phone, u.app_first_seen, u.app_last_seen, u.app_version,
        (SELECT COUNT(*) FROM bcs b WHERE b.cbc_id = u.id AND b.is_active = 1) bcs,
        (SELECT COUNT(*) FROM cbc_app_tokens t WHERE t.user_id = u.id) devices,
        (SELECT COUNT(*) FROM cbc_app_tokens t WHERE t.user_id = u.id AND t.fcm_token IS NOT NULL) fcm,
        (SELECT t.device FROM cbc_app_tokens t WHERE t.user_id = u.id ORDER BY t.last_used_at DESC LIMIT 1) device
     FROM users u
     WHERE {$w}
     ORDER BY (u.app_last_seen IS NULL), u.app_last_seen DESC, u.name",
    $params
);

$sum = DB::one(
    "SELECT COUNT(*) total,
        SUM({$has}) logged_in,
        SUM(NOT {$has} AND u.app_first_seen IS NOT NULL) logged_out,
        SUM(u.app_first_seen IS NULL) never_inst,
        SUM(u.app_last_seen >= (NOW() - INTERVAL 7 DAY)) active7
     FROM users u WHERE u.role = 'cbc' AND u.is_active = 1 AND {$scopeWhere}",
    $scopeParams
) ?: ['total' => 0, 'logged_in' => 0, 'logged_out' => 0, 'never_inst' => 0, 'active7' => 0];

View::header('CBC App / सीबीसी ऐप', 'cbcapp');
?>
<div class="row g-2 mb-3 text-center">
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'all' ? 'border-success' : '' ?>" href="/cbc_app.php"><div class="card-body py-2"><div class="fs-4 fw-bold"><?= (int) $sum['total'] ?></div><div class="small text-muted">Total CBC</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'logged_in' ? 'border-success' : '' ?>" href="/cbc_app.php?status=logged_in"><div class="card-body py-2"><div class="fs-4 fw-bold text-success"><?= (int) $sum['logged_in'] ?></div><div class="small text-muted">Logged in</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'logged_out' ? 'border-danger' : '' ?>" href="/cbc_app.php?status=logged_out"><div class="card-body py-2"><div class="fs-4 fw-bold text-danger"><?= (int) $sum['logged_out'] ?></div><div class="small text-muted">Logout / hataya</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'never' ? 'border-warning' : '' ?>" href="/cbc_app.php?status=never"><div class="card-body py-2"><div class="fs-4 fw-bold text-secondary"><?= (int) $sum['never_inst'] ?></div><div class="small text-muted">Install nahi kiya</div></div></a></div>
  <div class="col-6 col-md"><a class="card text-decoration-none h-100 <?= $status === 'active' ? 'border-success' : '' ?>" href="/cbc_app.php?status=active"><div class="card-body py-2"><div class="fs-4 fw-bold text-primary"><?= (int) $sum['active7'] ?></div><div class="small text-muted">Active 7 days</div></div></a></div>
</div>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm">
    <?php foreach (['all' => 'All CBC', 'logged_in' => 'Logged in', 'logged_out' => 'Logout / hataya', 'never' => 'Install nahi kiya', 'active' => 'Active 7 days'] as $k => $label): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><label class="form-label small">Search</label><input name="search" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="CBC name / mobile"></div>
  <div class="col-6 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<div class="table-responsive"><table class="table table-sm table-hover align-middle">
  <thead class="table-light"><tr><th>CBC</th><th class="text-end">BCs</th><th>App status</th><th>App version</th><th>First login</th><th>Last active</th><th>Push</th><th class="text-end">Devices</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): [$lbl, $col] = App::appStatus($r); ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong><br><small class="text-muted"><?= $r['phone'] ? e(Phone::display($r['phone'])) : '<span class="text-danger">No mobile set</span>' ?></small></td>
      <td class="text-end"><?= (int) $r['bcs'] ?></td>
      <td><span class="badge text-bg-<?= $col ?>"><?= e($lbl) ?></span></td>
      <td><?= $r['app_version'] ? '<span class="badge text-bg-secondary">' . e($r['app_version']) . '</span>' : '<span class="text-muted" title="Abhi pata nahi – browser se login kiya ya app ne version nahi bheja. Agli baar app kholne par apne aap aa jayega.">—</span>' ?></td>
      <td class="small"><?= $r['app_first_seen'] ? e(fmt_dt($r['app_first_seen'])) : '—' ?></td>
      <td class="small"><?= $r['app_last_seen'] ? e(fmt_dt($r['app_last_seen'])) : '—' ?></td>
      <td><?= (int) $r['fcm'] ? '<span class="badge text-bg-success">🔥 on</span>' : ((int) $r['devices'] ? '<span class="badge text-bg-warning">poll only</span>' : '—') ?></td>
      <td class="text-end"><?= (int) $r['devices'] ?: '—' ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted">No CBC users</td></tr><?php endif; ?>
  </tbody>
</table></div>
<p class="small text-muted"><?= count($rows) ?> CBC shown. CBC login apne <strong>mobile number + panel password</strong> se hota hai – jinka number set nahi hai wo login nahi kar sakte.</p>
<?php View::footer();
