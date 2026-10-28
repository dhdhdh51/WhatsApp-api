<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\Calendar;
use App\DB;
use App\Schemes;
use App\Scope;
use App\Settings;
use App\View;

$user = Auth::require();

$from = date_or(q('from'), date('Y-m-01'));
$to = date_or(q('to'), date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$branch = (string) q('branch_id');

[$sw, $sp] = Scope::bc($user);
$where = "r.report_date BETWEEN ? AND ? AND {$sw}";
$params = array_merge([$from, $to], $sp);
if ($branch !== '') {
    $where .= ' AND b.branch_id = ?';
    $params[] = (int) $branch;
}
$region = (string) q('region_id');   // drill the whole page into one RO (region)
if ($region !== '') {
    $where .= ' AND b.branch_id IN (SELECT id FROM branches WHERE region_id = ?)';
    $params[] = (int) $region;
}
$cbc = $user['role'] === 'cbc' ? '' : (string) q('cbc_id');
if ($cbc !== '') {
    $where .= ' AND b.cbc_id = ?';
    $params[] = (int) $cbc;
}
$S = Schemes::sumSql();
$T = Schemes::totalSql();

$tot = DB::one("SELECT {$S}, COUNT(*) reports
                FROM reports r JOIN bcs b ON b.id = r.bc_id WHERE {$where}", $params);

$trend = DB::all("SELECT r.report_date d, {$S}, COUNT(*) n
                  FROM reports r JOIN bcs b ON b.id = r.bc_id WHERE {$where} GROUP BY r.report_date ORDER BY r.report_date", $params);

$byBranch = DB::all("SELECT COALESCE(br.name, '—') name, COALESCE(rg.name, '—') region, COUNT(DISTINCT b.id) bcs, COUNT(*) reports, {$S}
                     FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN branches br ON br.id = b.branch_id LEFT JOIN regions rg ON rg.id = br.region_id
                     WHERE {$where} GROUP BY br.id, br.name, rg.name ORDER BY {$T} DESC", $params);

$byCbc = $user['role'] === 'cbc' ? [] : DB::all("SELECT COALESCE(cu.name, '— (no CBC)') name, COUNT(DISTINCT b.id) bcs, COUNT(*) reports, {$S}
                     FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN users cu ON cu.id = b.cbc_id
                     WHERE {$where} GROUP BY cu.id, cu.name ORDER BY cu.name", $params);

$byBc = DB::all("SELECT b.id, b.bc_code, b.name, COALESCE(br.name,'') branch, COUNT(*) days,
                        {$S}, {$T} total
                 FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN branches br ON br.id = b.branch_id
                 WHERE {$where} GROUP BY b.id, b.bc_code, b.name, br.name ORDER BY total DESC LIMIT 50", $params);

// RO-wise (region) summary: same filters as the cards above, shown for every RO the user may see (zero-activity ROs included).
$visibleRegions = Scope::regionsVisible($user);
$regionAgg = [];
foreach (DB::all("SELECT COALESCE(br.region_id, 0) rid, COUNT(DISTINCT b.branch_id) branches, COUNT(DISTINCT b.id) bcs, COUNT(*) reports, {$S}, {$T} total
                  FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN branches br ON br.id = b.branch_id
                  WHERE {$where} GROUP BY br.region_id", $params) as $row) {
    $regionAgg[(int) $row['rid']] = $row;
}
$mkRegion = static function (int $rid, string $name, int $branches, array $agg): array {
    $r = ['rid' => $rid, 'name' => $name, 'branches' => $branches,
          'bcs' => (int) ($agg['bcs'] ?? 0), 'reports' => (int) ($agg['reports'] ?? 0), 'total' => (int) ($agg['total'] ?? 0)];
    foreach (App\ReportParser::SCHEMES as $k) {
        $r[$k] = (int) ($agg[$k] ?? 0);
    }
    return $r;
};
$byRegion = [];
foreach ($visibleRegions as $rg) {
    if ($region !== '' && (int) $region !== (int) $rg['id']) {
        continue;
    }
    $byRegion[] = $mkRegion((int) $rg['id'], (string) $rg['name'], (int) $rg['branches'], $regionAgg[(int) $rg['id']] ?? []);
}
if ($region === '' && !empty($regionAgg[0])) {   // work whose branch has no RO assigned
    $byRegion[] = $mkRegion(0, '— no RO / बिना RO —', (int) $regionAgg[0]['branches'], $regionAgg[0]);
}
usort($byRegion, static fn ($a, $b) => ($b['total'] <=> $a['total']) ?: strcmp($a['name'], $b['name']));
$regionTot = ['branches' => 0, 'bcs' => 0, 'reports' => 0, 'total' => 0] + Schemes::zero();
foreach ($byRegion as $r) {
    foreach (['branches', 'bcs', 'reports', 'total'] as $k) {
        $regionTot[$k] += $r[$k];
    }
    foreach (App\ReportParser::SCHEMES as $k) {
        $regionTot[$k] += $r[$k];
    }
}

// today
$todayRows = Calendar::dayRows(Calendar::today(), $user, ['branch_id' => $branch, 'cbc_id' => $cbc, 'region_id' => $region]);
$todayByCbc = [];
foreach ($todayRows as $r) {
    $k = $r['cbc_name'] ?: '— (no CBC)';
    $todayByCbc[$k] ??= ['submitted' => 0, 'pending' => 0, 'cbc_id' => $r['cbc_id']];
    if (isset($todayByCbc[$k][$r['status']])) {
        $todayByCbc[$k][$r['status']]++;
    }
}
ksort($todayByCbc);
// App adoption in the current user's branch/CBC scope.
[$appScope, $appParams] = Scope::bc($user);
$appExtra = [];
if ($branch !== '') { $appExtra[] = 'b.branch_id = ?'; $appParams[] = (int) $branch; }
if ($region !== '') { $appExtra[] = 'b.branch_id IN (SELECT id FROM branches WHERE region_id = ?)'; $appParams[] = (int) $region; }
if ($cbc !== '') { $appExtra[] = 'b.cbc_id = ?'; $appParams[] = (int) $cbc; }
$appStats = DB::one('SELECT COUNT(*) total, SUM(b.app_first_seen IS NOT NULL) activated, SUM(b.app_last_seen >= (NOW() - INTERVAL 7 DAY)) active7 FROM bcs b WHERE b.is_active = 1 AND ' . $appScope . ($appExtra ? ' AND ' . implode(' AND ', $appExtra) : ''), $appParams) ?: ['total' => 0, 'activated' => 0, 'active7' => 0];

$cnt = ['submitted' => 0, 'pending' => 0, 'off' => 0];
foreach ($todayRows as $r) {
    if ($r['status'] === 'submitted') {
        $cnt['submitted']++;
    } elseif ($r['status'] === 'pending') {
        $cnt['pending']++;
    } else {
        $cnt['off']++;
    }
}

$branchIds = null;
if (!Scope::seesAll($user)) {   // branch managers, region managers and CBCs only escalate their own branches
    $branchIds = array_map('intval', array_column(Scope::branches($user), 'id'));
}
if ($region !== '') {           // drill escalations into the selected RO
    $regionBranchIds = array_map('intval', array_column(DB::all('SELECT id FROM branches WHERE region_id = ?', [(int) $region]), 'id'));
    $branchIds = $branchIds === null ? $regionBranchIds : array_values(array_intersect($branchIds, $regionBranchIds));
}
if ($branch !== '') {
    $branchIds = $branchIds === null ? [(int) $branch] : array_values(array_intersect($branchIds, [(int) $branch]));
}
$escalations = ($branchIds === null || $branchIds) ? Calendar::missStreaks(max(1, Settings::int('escalation_days')), $branchIds) : [];
$onlyCbc = $user['role'] === 'cbc' ? (int) $user['id'] : ($cbc !== '' ? (int) $cbc : 0);
if ($onlyCbc) {
    $escalations = array_values(array_filter($escalations, static fn ($x) => (int) $x['bc']['cbc_id'] === $onlyCbc));
}

View::header('Dashboard / डैशबोर्ड', 'dashboard', true);
?>
<form class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('From', 'से') ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('To', 'तक') ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
  <?php if (count($visibleRegions) > 1): ?>
  <div class="col-8 col-md-2"><label class="form-label small"><?= L('RO (Region)', 'रीजन (RO)') ?></label>
    <select name="region_id" class="form-select form-select-sm">
      <option value="">— <?= L('All ROs', 'सभी RO') ?> —</option>
      <?php foreach ($visibleRegions as $rg): ?><option value="<?= (int) $rg['id'] ?>" <?= (string) $region === (string) $rg['id'] ? 'selected' : '' ?>><?= e($rg['name']) ?> (<?= (int) $rg['branches'] ?>)</option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <div class="col-8 col-md-2"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, $branch) ?></select></div>
  <?php if ($user['role'] !== 'cbc'): ?><div class="col-8 col-md-2"><label class="form-label small">CBC</label><select name="cbc_id" class="form-select form-select-sm"><?= View::cbcOptions($user, $cbc) ?></select></div><?php endif; ?>
  <div class="col-4 col-md-2"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i> <?= L('Apply', 'लागू') ?></button></div>
</form>

<div class="row g-2 mb-3">
  <?php foreach (App\ReportParser::LABELS as $k => $lbl): ?>
  <div class="col-6 col-md-3 col-xl"><div class="card stat-card <?= str_ends_with($k, '_off') ? 'border-warning' : '' ?>"><div class="card-body py-2">
    <div class="small text-muted"><?= $lbl ?></div><div class="fs-3 fw-bold"><?= (int) $tot[$k] ?></div>
  </div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-2 mb-3">
  <div class="col-12 col-sm-4"><a href="/status.php?status=submitted" class="text-decoration-none"><div class="card border-success"><div class="card-body py-2 text-success">
    <div class="small"><?= L('Submitted today', 'आज जमा') ?></div><div class="fs-4 fw-bold"><?= $cnt['submitted'] ?></div></div></div></a></div>
  <div class="col-12 col-sm-4"><a href="/status.php?status=pending" class="text-decoration-none"><div class="card border-danger"><div class="card-body py-2 text-danger">
    <div class="small"><?= L('Pending today', 'आज बाकी') ?></div><div class="fs-4 fw-bold"><?= $cnt['pending'] ?></div></div></div></a></div>
  <div class="col-12 col-sm-4"><div class="card"><div class="card-body py-2 text-secondary">
    <div class="small"><?= L('Leave / holiday', 'छुट्टी / अवकाश') ?></div><div class="fs-4 fw-bold"><?= $cnt['off'] ?></div></div></div></div>
</div>
<div class="row g-2 mb-3">
  <div class="col-6"><a href="/app_usage.php?status=activated" class="text-decoration-none"><div class="card border-success"><div class="card-body py-2 text-success">
    <div class="small"><?= L('App activated', 'ऐप चालू') ?></div><div class="fs-4 fw-bold"><?= (int) $appStats['activated'] ?> <span class="fs-6">/ <?= (int) $appStats['total'] ?></span></div>
    <div class="small text-muted"><?= L('Installed + first login', 'इंस्टॉल + पहला लॉगिन') ?></div></div></div></a></div>
  <div class="col-6"><a href="/app_usage.php?status=active" class="text-decoration-none"><div class="card border-primary"><div class="card-body py-2 text-primary">
    <div class="small"><?= L('App active (7 days)', 'ऐप सक्रिय (7 दिन)') ?></div><div class="fs-4 fw-bold"><?= (int) $appStats['active7'] ?></div>
    <div class="small text-muted"><?= L('Open App users list', 'ऐप उपयोगकर्ता सूची') ?></div></div></div></a></div>
</div>

<?php if ($byRegion): ?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-1">
    <span><i class="bi bi-diagram-3"></i> <?= L('RO-wise — work done under each Regional Office', 'RO अनुसार — हर रीजनल ऑफिस के अंतर्गत कितना काम') ?></span>
    <span class="small text-muted"><?= e($from) ?> → <?= e($to) ?><?php if ($region !== ''): ?> · <a href="<?= e(qs(['region_id' => null])) ?>"><?= L('show all ROs', 'सभी RO दिखाएँ') ?></a><?php endif; ?></span>
  </div>
  <div class="table-responsive"><table class="table table-sm table-striped mb-0 align-middle">
    <thead><tr>
      <th><?= L('RO (Region)', 'रीजन (RO)') ?></th>
      <th class="text-end"><?= L('Branches', 'शाखाएँ') ?></th>
      <th class="text-end">BCs</th>
      <th class="text-end"><?= L('Reports', 'रिपोर्ट') ?></th>
      <?= Schemes::th() ?>
      <th class="text-end"><?= L('Total', 'कुल') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($byRegion as $r): ?>
      <tr>
        <td class="fw-semibold"><?= $r['rid'] ? '<a href="' . e(qs(['region_id' => $r['rid']])) . '">' . e($r['name']) . '</a>' : e($r['name']) ?></td>
        <td class="text-end"><?= (int) $r['branches'] ?></td>
        <td class="text-end"><?= (int) $r['bcs'] ?></td>
        <td class="text-end"><?= (int) $r['reports'] ?></td>
        <?= Schemes::td($r) ?>
        <td class="text-end fw-bold"><?= (int) $r['total'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if (count($byRegion) > 1): ?>
    <tfoot><tr class="table-active fw-bold">
      <td><?= L('All ROs (total)', 'सभी RO (कुल)') ?></td>
      <td class="text-end"><?= (int) $regionTot['branches'] ?></td>
      <td class="text-end"><?= (int) $regionTot['bcs'] ?></td>
      <td class="text-end"><?= (int) $regionTot['reports'] ?></td>
      <?= Schemes::td($regionTot) ?>
      <td class="text-end"><?= (int) $regionTot['total'] ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="card-header"><?= L('Daily trend', 'दैनिक रुझान') ?></div>
      <div class="card-body"><canvas id="trend" height="120"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> <?= L('Escalation: missed ' . max(1, Settings::int('escalation_days')) . '+ days in a row', 'लगातार रिपोर्ट नहीं') ?></div>
      <ul class="list-group list-group-flush small" style="max-height:320px;overflow:auto">
      <?php if (!$escalations): ?><li class="list-group-item text-muted">None / कोई नहीं 👍</li><?php endif; ?>
      <?php foreach ($escalations as $esc): ?>
        <li class="list-group-item d-flex justify-content-between">
          <span><a href="/conversation.php?bc_id=<?= (int) $esc['bc']['id'] ?>"><?= e($esc['bc']['name']) ?> <span class="text-muted">(<?= e($esc['bc']['bc_code']) ?>)</span></a> <?= View::contactButtons($esc['bc']['phone']) ?></span>
          <span class="badge text-bg-danger"><?= count($esc['days']) ?> days</span>
        </li>
      <?php endforeach; ?>
      </ul></div>
  </div>

  <?php if ($user['role'] !== 'cbc' && $todayByCbc): ?>
  <div class="col-12">
    <div class="card"><div class="card-header"><?= L('CBC-wise (today + selected period)', 'सीबीसी अनुसार') ?></div>
      <div class="table-responsive"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>CBC</th><th class="text-end text-success">Today submitted</th><th class="text-end text-danger">Today pending</th><th class="text-end">BCs</th><?= Schemes::th() ?></tr></thead>
        <tbody><?php $periodByCbc = array_column($byCbc, null, 'name'); foreach ($todayByCbc as $name => $c): $pr = $periodByCbc[$name] ?? []; ?>
          <tr><td><?= $c['cbc_id'] ? '<a href="/status.php?cbc_id=' . (int) $c['cbc_id'] . '">' . e($name) . '</a>' : e($name) ?></td>
            <td class="text-end text-success fw-semibold"><?= $c['submitted'] ?></td><td class="text-end text-danger fw-semibold"><?= $c['pending'] ?></td>
            <td class="text-end"><?= (int) ($pr['bcs'] ?? 0) ?></td><?= Schemes::td($pr) ?></tr>
        <?php endforeach; ?></tbody>
      </table></div></div>
  </div>
  <?php endif; ?>
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><?= L('Branch-wise', 'शाखा अनुसार') ?></div>
      <div class="table-responsive"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>Branch</th><th>RO</th><th class="text-end">BCs</th><?= Schemes::th() ?></tr></thead>
        <tbody><?php foreach ($byBranch as $r): ?>
          <tr><td><?= e($r['name']) ?></td><td class="small text-muted"><?= e($r['region']) ?></td><td class="text-end"><?= (int) $r['bcs'] ?></td><?= Schemes::td($r) ?></tr>
        <?php endforeach; if (!$byBranch): ?><tr><td colspan="10" class="text-muted">No data / डेटा नहीं</td></tr><?php endif; ?></tbody>
      </table></div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><?= L('BC-wise (top 50)', 'बीसी अनुसार') ?></div>
      <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>BC</th><th>Branch</th><th class="text-end" title="days reported">Days</th><?= Schemes::th() ?><th class="text-end">Total</th></tr></thead>
        <tbody><?php foreach ($byBc as $r): ?>
          <tr><td><a href="/reports.php?bc=<?= e($r['bc_code']) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><?= e($r['name']) ?></a> <small class="text-muted"><?= e($r['bc_code']) ?></small></td>
              <td><?= e($r['branch']) ?></td><td class="text-end"><?= (int) $r['days'] ?></td><?= Schemes::td($r) ?><td class="text-end fw-semibold"><?= (int) $r['total'] ?></td></tr>
        <?php endforeach; if (!$byBc): ?><tr><td colspan="11" class="text-muted">No data / डेटा नहीं</td></tr><?php endif; ?></tbody>
      </table></div></div>
  </div>
</div>
<?php
$chart = [
    'labels' => array_map(static fn ($r) => date('d-m', strtotime($r['d'])), $trend),
    'apy' => array_map('intval', array_column($trend, 'apy')),
    'pmjjby' => array_map('intval', array_column($trend, 'pmjjby')),
    'pmsby' => array_map('intval', array_column($trend, 'pmsby')),
    'pmjdy' => array_map('intval', array_column($trend, 'pmjdy')),
    'off' => array_map(static fn ($r) => (int) $r['apy_off'] + (int) $r['pmjjby_off'] + (int) $r['pmsby_off'], $trend),
    'n' => array_map('intval', array_column($trend, 'n')),
];
$js = '<script>const TREND = ' . json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP) . ';
new Chart(document.getElementById("trend"), {type: "line", data: {labels: TREND.labels, datasets: [
 {label: "APY", data: TREND.apy, tension: .3}, {label: "PMJJBY", data: TREND.pmjjby, tension: .3},
 {label: "PMSBY", data: TREND.pmsby, tension: .3}, {label: "PMJDY", data: TREND.pmjdy, tension: .3}, {label: "Offline (APY+JJBY+SBY)", data: TREND.off, tension: .3, borderDash: [5, 4]},
 {label: "Reports / रिपोर्ट", data: TREND.n, type: "bar", yAxisID: "y1", backgroundColor: "rgba(25,135,84,.15)"}]},
 options: {responsive: true, interaction: {mode: "index", intersect: false}, scales: {y: {beginAtZero: true}, y1: {beginAtZero: true, position: "right", grid: {drawOnChartArea: false}}}}});
</script>';
View::footer($js);
