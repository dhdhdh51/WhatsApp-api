<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\Calendar;
use App\DB;
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

$tot = DB::one("SELECT COALESCE(SUM(r.apy),0) apy, COALESCE(SUM(r.pmjjby),0) pmjjby, COALESCE(SUM(r.pmsby),0) pmsby,
                       COALESCE(SUM(r.pmjdy),0) pmjdy, COUNT(*) reports
                FROM reports r JOIN bcs b ON b.id = r.bc_id WHERE {$where}", $params);

$trend = DB::all("SELECT r.report_date d, SUM(r.apy) apy, SUM(r.pmjjby) pmjjby, SUM(r.pmsby) pmsby, SUM(r.pmjdy) pmjdy, COUNT(*) n
                  FROM reports r JOIN bcs b ON b.id = r.bc_id WHERE {$where} GROUP BY r.report_date ORDER BY r.report_date", $params);

$byBranch = DB::all("SELECT COALESCE(br.name, '—') name, COUNT(DISTINCT b.id) bcs, COUNT(*) reports,
                            SUM(r.apy) apy, SUM(r.pmjjby) pmjjby, SUM(r.pmsby) pmsby, SUM(r.pmjdy) pmjdy
                     FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN branches br ON br.id = b.branch_id
                     WHERE {$where} GROUP BY br.id, br.name ORDER BY (SUM(r.apy)+SUM(r.pmjjby)+SUM(r.pmsby)+SUM(r.pmjdy)) DESC", $params);

$byBc = DB::all("SELECT b.id, b.bc_code, b.name, COALESCE(br.name,'') branch, COUNT(*) days,
                        SUM(r.apy) apy, SUM(r.pmjjby) pmjjby, SUM(r.pmsby) pmsby, SUM(r.pmjdy) pmjdy,
                        SUM(r.apy)+SUM(r.pmjjby)+SUM(r.pmsby)+SUM(r.pmjdy) total
                 FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN branches br ON br.id = b.branch_id
                 WHERE {$where} GROUP BY b.id, b.bc_code, b.name, br.name ORDER BY total DESC LIMIT 50", $params);

// today
$todayRows = Calendar::dayRows(Calendar::today(), $user, $branch !== '' ? ['branch_id' => $branch] : []);
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
if ($user['role'] !== 'admin') {
    $branchIds = array_map('intval', array_column(Scope::branches($user), 'id'));
}
if ($branch !== '') {
    $branchIds = $branchIds === null ? [(int) $branch] : array_values(array_intersect($branchIds, [(int) $branch]));
}
$escalations = ($branchIds === null || $branchIds) ? Calendar::missStreaks(max(1, Settings::int('escalation_days')), $branchIds) : [];

View::header('Dashboard / डैशबोर्ड', 'dashboard', true);
?>
<form class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('From', 'से') ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('To', 'तक') ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
  <div class="col-8 col-md-3"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, $branch) ?></select></div>
  <div class="col-4 col-md-2"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i> <?= L('Apply', 'लागू') ?></button></div>
</form>

<div class="row g-2 mb-3">
  <?php foreach (['apy' => 'APY', 'pmjjby' => 'PMJJBY', 'pmsby' => 'PMSBY', 'pmjdy' => 'PMJDY'] as $k => $lbl): ?>
  <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body py-2">
    <div class="small text-muted"><?= $lbl ?></div><div class="fs-3 fw-bold"><?= (int) $tot[$k] ?></div>
  </div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-2 mb-3">
  <div class="col-4"><a href="/status.php?status=submitted" class="text-decoration-none"><div class="card border-success"><div class="card-body py-2 text-success">
    <div class="small"><?= L('Submitted today', 'आज जमा') ?></div><div class="fs-4 fw-bold"><?= $cnt['submitted'] ?></div></div></div></a></div>
  <div class="col-4"><a href="/status.php?status=pending" class="text-decoration-none"><div class="card border-danger"><div class="card-body py-2 text-danger">
    <div class="small"><?= L('Pending today', 'आज बाकी') ?></div><div class="fs-4 fw-bold"><?= $cnt['pending'] ?></div></div></div></a></div>
  <div class="col-4"><div class="card"><div class="card-body py-2 text-secondary">
    <div class="small"><?= L('Leave / holiday', 'छुट्टी / अवकाश') ?></div><div class="fs-4 fw-bold"><?= $cnt['off'] ?></div></div></div></div>
</div>

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
          <a href="/conversation.php?bc_id=<?= (int) $esc['bc']['id'] ?>"><?= e($esc['bc']['name']) ?> <span class="text-muted">(<?= e($esc['bc']['bc_code']) ?>)</span></a>
          <span class="badge text-bg-danger"><?= count($esc['days']) ?> days</span>
        </li>
      <?php endforeach; ?>
      </ul></div>
  </div>

  <div class="col-lg-5">
    <div class="card"><div class="card-header"><?= L('Branch-wise', 'शाखा अनुसार') ?></div>
      <div class="table-responsive"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>Branch</th><th class="text-end">BCs</th><th class="text-end">APY</th><th class="text-end">PMJJBY</th><th class="text-end">PMSBY</th><th class="text-end">PMJDY</th></tr></thead>
        <tbody><?php foreach ($byBranch as $r): ?>
          <tr><td><?= e($r['name']) ?></td><td class="text-end"><?= (int) $r['bcs'] ?></td><td class="text-end"><?= (int) $r['apy'] ?></td><td class="text-end"><?= (int) $r['pmjjby'] ?></td><td class="text-end"><?= (int) $r['pmsby'] ?></td><td class="text-end"><?= (int) $r['pmjdy'] ?></td></tr>
        <?php endforeach; if (!$byBranch): ?><tr><td colspan="6" class="text-muted">No data / डेटा नहीं</td></tr><?php endif; ?></tbody>
      </table></div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><?= L('BC-wise (top 50)', 'बीसी अनुसार') ?></div>
      <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>BC</th><th>Branch</th><th class="text-end" title="days reported">Days</th><th class="text-end">APY</th><th class="text-end">PMJJBY</th><th class="text-end">PMSBY</th><th class="text-end">PMJDY</th><th class="text-end">Total</th></tr></thead>
        <tbody><?php foreach ($byBc as $r): ?>
          <tr><td><a href="/reports.php?bc=<?= e($r['bc_code']) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><?= e($r['name']) ?></a> <small class="text-muted"><?= e($r['bc_code']) ?></small></td>
              <td><?= e($r['branch']) ?></td><td class="text-end"><?= (int) $r['days'] ?></td><td class="text-end"><?= (int) $r['apy'] ?></td><td class="text-end"><?= (int) $r['pmjjby'] ?></td><td class="text-end"><?= (int) $r['pmsby'] ?></td><td class="text-end"><?= (int) $r['pmjdy'] ?></td><td class="text-end fw-semibold"><?= (int) $r['total'] ?></td></tr>
        <?php endforeach; if (!$byBc): ?><tr><td colspan="8" class="text-muted">No data / डेटा नहीं</td></tr><?php endif; ?></tbody>
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
    'n' => array_map('intval', array_column($trend, 'n')),
];
$js = '<script>const TREND = ' . json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP) . ';
new Chart(document.getElementById("trend"), {type: "line", data: {labels: TREND.labels, datasets: [
 {label: "APY", data: TREND.apy, tension: .3}, {label: "PMJJBY", data: TREND.pmjjby, tension: .3},
 {label: "PMSBY", data: TREND.pmsby, tension: .3}, {label: "PMJDY", data: TREND.pmjdy, tension: .3},
 {label: "Reports / रिपोर्ट", data: TREND.n, type: "bar", yAxisID: "y1", backgroundColor: "rgba(25,135,84,.15)"}]},
 options: {responsive: true, interaction: {mode: "index", intersect: false}, scales: {y: {beginAtZero: true}, y1: {beginAtZero: true, position: "right", grid: {drawOnChartArea: false}}}}});
</script>';
View::footer($js);
