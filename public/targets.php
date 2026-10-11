<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Export\Exporter;
use App\ReportParser;
use App\Scope;
use App\View;

$user = Auth::require();

$month = preg_match('/^\d{4}-\d{2}$/', (string) q('month')) ? (string) q('month') : date('Y-m');
$monthStart = $month . '-01';
if (!valid_date($monthStart)) {
    $month = date('Y-m');
    $monthStart = $month . '-01';
}
$monthEnd = date('Y-m-t', strtotime($monthStart));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = 0;
    foreach ((array) ($_POST['t'] ?? []) as $bcId => $vals) {
        $bcId = (int) $bcId;
        if (!is_array($vals) || !Scope::canSeeBc($user, $bcId)) {
            continue;
        }
        $v = [];
        foreach (ReportParser::SCHEMES as $s) {
            $raw = trim((string) ($vals[$s] ?? '0'));
            $v[$s] = preg_match('/^\d{1,6}$/', $raw) ? (int) $raw : 0;
        }
        DB::exec(
            'INSERT INTO targets (bc_id, month, apy_target, pmjjby_target, pmsby_target, pmjdy_target, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE apy_target = VALUES(apy_target), pmjjby_target = VALUES(pmjjby_target),
               pmsby_target = VALUES(pmsby_target), pmjdy_target = VALUES(pmjdy_target), updated_by = VALUES(updated_by)',
            [$bcId, $monthStart, $v['apy'], $v['pmjjby'], $v['pmsby'], $v['pmjdy'], $user['id']]
        );
        $n++;
    }
    Audit::log((int) $user['id'], 'targets_save', 'targets', null, ['month' => $month, 'bcs' => $n]);
    flash('success', "Targets saved for {$n} BCs / लक्ष्य सेव हुए");
    redirect('/targets.php' . qs());
}

[$sw, $sp] = Scope::bc($user);
$params = array_merge([$monthStart, $monthEnd, $monthStart], $sp);
$extra = '';
if (q('branch_id') !== '') {
    $extra = ' AND b.branch_id = ?';
    $params[] = (int) q('branch_id');
}
$rows = DB::all(
    "SELECT b.id, b.bc_code, b.name, br.name branch,
            COALESCE(a.apy,0) apy, COALESCE(a.pmjjby,0) pmjjby, COALESCE(a.pmsby,0) pmsby, COALESCE(a.pmjdy,0) pmjdy,
            t.apy_target, t.pmjjby_target, t.pmsby_target, t.pmjdy_target
     FROM bcs b
     LEFT JOIN branches br ON br.id = b.branch_id
     LEFT JOIN (SELECT bc_id, SUM(apy) apy, SUM(pmjjby) pmjjby, SUM(pmsby) pmsby, SUM(pmjdy) pmjdy
                FROM reports WHERE report_date BETWEEN ? AND ? GROUP BY bc_id) a ON a.bc_id = b.id
     LEFT JOIN targets t ON t.bc_id = b.id AND t.month = ?
     WHERE b.is_active = 1 AND {$sw}{$extra}
     ORDER BY br.name, b.name",
    $params
);

$pct = static fn ($a, $t) => $t > 0 ? round(100 * $a / $t) : null;

if (q('export') !== '') {
    $out = [];
    foreach ($rows as $r) {
        $line = [$r['bc_code'], $r['name'], (string) $r['branch']];
        foreach (ReportParser::SCHEMES as $s) {
            $p = $pct((int) $r[$s], (int) $r[$s . '_target']);
            array_push($line, (int) $r[$s . '_target'], (int) $r[$s], $p === null ? '' : $p . '%');
        }
        $out[] = $line;
    }
    $head = ['BC ID', 'Name', 'Branch'];
    foreach (ReportParser::LABELS as $l) {
        array_push($head, "{$l} target", "{$l} achieved", "{$l} %");
    }
    Exporter::download(in_array(q('export'), ['xlsx', 'pdf'], true) ? q('export') : 'csv', "targets_{$month}", "Targets vs achieved {$month}", $head, $out);
}

View::header('Monthly targets / मासिक लक्ष्य', 'targets');
?>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Month', 'महीना') ?></label><input type="month" name="month" value="<?= e($month) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-3"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, q('branch_id')) ?></select></div>
  <div class="col-6 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
  <div class="col-6 col-md-3 d-flex gap-1">
    <a class="btn btn-sm btn-outline-success" href="<?= e(qs(['export' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i></a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(qs(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i></a>
    <a class="btn btn-sm btn-outline-danger" href="<?= e(qs(['export' => 'pdf'])) ?>"><i class="bi bi-file-earmark-pdf"></i></a></div>
</form>

<form method="post"><?= Csrf::field() ?>
<div class="card mb-2"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center small">
  <strong><?= L('Fill all rows', 'सभी में भरें') ?>:</strong>
  <?php foreach (ReportParser::LABELS as $k => $l): ?><input type="number" min="0" class="form-control form-control-sm" style="width:90px" placeholder="<?= $l ?>" data-fill="<?= $k ?>"><?php endforeach; ?>
  <button type="button" class="btn btn-sm btn-outline-primary" id="fillAll"><?= L('Apply', 'लागू') ?></button>
  <button class="btn btn-sm btn-success ms-auto"><i class="bi bi-save"></i> <?= L('Save targets', 'लक्ष्य सेव करें') ?></button>
</div></div>
<div class="table-responsive">
<table class="table table-sm table-bordered align-middle targets">
  <thead class="table-light"><tr><th rowspan="2">BC</th><?php foreach (ReportParser::LABELS as $l): ?><th colspan="2" class="text-center"><?= $l ?></th><?php endforeach; ?></tr>
    <tr><?php foreach (ReportParser::LABELS as $l): ?><th class="small"><?= L('Target', 'लक्ष्य') ?></th><th class="small"><?= L('Achieved', 'प्राप्त') ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="small"><?= e($r['name']) ?><br><span class="text-muted"><?= e($r['bc_code']) ?> · <?= e($r['branch']) ?></span></td>
    <?php foreach (ReportParser::SCHEMES as $s): $t = (int) $r[$s . '_target']; $a = (int) $r[$s]; $p = $pct($a, $t); ?>
      <td><input type="number" min="0" name="t[<?= (int) $r['id'] ?>][<?= $s ?>]" value="<?= $t ?>" class="form-control form-control-sm" data-col="<?= $s ?>" style="min-width:70px"></td>
      <td class="text-nowrap"><?= $a ?><?php if ($p !== null): ?><div class="progress" style="height:5px" title="<?= $p ?>%"><div class="progress-bar bg-<?= $p >= 100 ? 'success' : ($p >= 50 ? 'warning' : 'danger') ?>" style="width:<?= min(100, $p) ?>%"></div></div><small><?= $p ?>%</small><?php endif; ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-muted">No BCs</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</form>
<?php View::footer('<script>document.getElementById("fillAll").addEventListener("click",()=>{document.querySelectorAll("[data-fill]").forEach(f=>{if(f.value!==""){document.querySelectorAll("[data-col=\""+f.dataset.fill+"\"]").forEach(i=>i.value=f.value);}});});</script>');
