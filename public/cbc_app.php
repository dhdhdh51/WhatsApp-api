<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\DB;
use App\Phone;
use App\Scope;
use App\View;

/**
 * CBC app adoption: which CBC supervisors have logged into the mobile app
 * (and are therefore getting the "a BC has not reported" alarm) and which have not.
 */
$user = Auth::require();
$status = in_array(q('status'), ['all', 'logged_in', 'not', 'active'], true) ? q('status') : 'all';
$search = (string) q('search');

// Visibility: admins/supervisors see every CBC; others only CBCs of BCs they can see.
if (in_array($user['role'], ['admin', 'supervisor'], true)) {
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
if ($status === 'logged_in') {
    $where[] = 'EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id)';
} elseif ($status === 'not') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id)';
} elseif ($status === 'active') {
    $where[] = 'EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id AND t.last_used_at >= (NOW() - INTERVAL 7 DAY))';
}
$w = implode(' AND ', $where);

$rows = DB::all(
    "SELECT u.id, u.name, u.phone,
        (SELECT COUNT(*) FROM bcs b WHERE b.cbc_id = u.id AND b.is_active = 1) bcs,
        (SELECT MIN(created_at) FROM cbc_app_tokens t WHERE t.user_id = u.id) first_login,
        (SELECT MAX(last_used_at) FROM cbc_app_tokens t WHERE t.user_id = u.id) last_seen,
        (SELECT COUNT(*) FROM cbc_app_tokens t WHERE t.user_id = u.id) devices
     FROM users u
     WHERE {$w}
     ORDER BY (last_seen IS NULL), last_seen DESC, u.name",
    $params
);

// Summary counts over the full (unfiltered-by-status) scope.
$sumWhere = ["u.role = 'cbc'", 'u.is_active = 1', $scopeWhere];
$sum = DB::one(
    "SELECT COUNT(*) total,
        SUM(EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id)) logged_in,
        SUM(EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id AND t.last_used_at >= (NOW() - INTERVAL 7 DAY))) active7
     FROM users u WHERE " . implode(' AND ', $sumWhere),
    $scopeParams
) ?: ['total' => 0, 'logged_in' => 0, 'active7' => 0];
$notLoggedIn = (int) $sum['total'] - (int) $sum['logged_in'];

View::header('CBC App / सीबीसी ऐप', 'cbcapp');
?>
<div class="alert alert-info small">
  Ye un <strong>CBC</strong> ki list hai jo app mein login kar chuke hain (aur jinhe "BC ne report nahi ki" ka alarm milta hai) aur jo abhi tak login nahi kar paaye.
  CBC apne <strong>mobile number + panel password</strong> se usi app mein login karte hain.
</div>
<div class="row g-2 mb-3 text-center">
  <div class="col-6 col-md-3"><a class="card text-decoration-none h-100 <?= $status === 'all' ? 'border-success' : '' ?>" href="/cbc_app.php"><div class="card-body py-2"><div class="fs-4 fw-bold"><?= (int) $sum['total'] ?></div><div class="small text-muted">Total CBC</div></div></a></div>
  <div class="col-6 col-md-3"><a class="card text-decoration-none h-100 <?= $status === 'logged_in' ? 'border-success' : '' ?>" href="/cbc_app.php?status=logged_in"><div class="card-body py-2"><div class="fs-4 fw-bold text-success"><?= (int) $sum['logged_in'] ?></div><div class="small text-muted">Logged in / लॉगिन किया</div></div></a></div>
  <div class="col-6 col-md-3"><a class="card text-decoration-none h-100 <?= $status === 'not' ? 'border-danger' : '' ?>" href="/cbc_app.php?status=not"><div class="card-body py-2"><div class="fs-4 fw-bold text-danger"><?= $notLoggedIn ?></div><div class="small text-muted">Not logged in / नहीं किया</div></div></a></div>
  <div class="col-6 col-md-3"><a class="card text-decoration-none h-100 <?= $status === 'active' ? 'border-success' : '' ?>" href="/cbc_app.php?status=active"><div class="card-body py-2"><div class="fs-4 fw-bold text-primary"><?= (int) $sum['active7'] ?></div><div class="small text-muted">Active 7 days / सक्रिय</div></div></a></div>
</div>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm">
    <?php foreach (['all' => 'All CBC', 'logged_in' => 'Logged in', 'not' => 'Not logged in', 'active' => 'Active 7 days'] as $k => $label): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><label class="form-label small">Search</label><input name="search" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="CBC name / mobile"></div>
  <div class="col-6 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<div class="table-responsive"><table class="table table-sm table-hover align-middle">
  <thead class="table-light"><tr><th>CBC</th><th class="text-end">BCs</th><th>App status</th><th>First login</th><th>Last active</th><th class="text-end">Devices</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $in = (int) $r['devices'] > 0; ?>
    <tr>
      <td><strong><?= e($r['name']) ?></strong><br><small class="text-muted"><?= e(Phone::display($r['phone'])) ?: '<span class="text-danger">No mobile set</span>' ?></small></td>
      <td class="text-end"><?= (int) $r['bcs'] ?></td>
      <td><?= $in
            ? '<span class="badge text-bg-success"><i class="bi bi-check-circle"></i> Logged in</span>'
            : '<span class="badge text-bg-danger"><i class="bi bi-x-circle"></i> Not logged in</span>' ?></td>
      <td class="small"><?= $r['first_login'] ? e(fmt_dt($r['first_login'])) : '—' ?></td>
      <td class="small"><?= $r['last_seen'] ? e(fmt_dt($r['last_seen'])) : '—' ?></td>
      <td class="text-end"><?= (int) $r['devices'] ?: '—' ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-muted">No CBC users</td></tr><?php endif; ?>
  </tbody>
</table></div>
<p class="small text-muted"><?= count($rows) ?> CBC shown. CBC jinka mobile number set nahi hai wo app login nahi kar sakte — pehle Users page se number set karein.</p>
<?php View::footer();
