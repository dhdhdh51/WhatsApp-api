<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\DB;
use App\Export\Exporter;
use App\Scope;
use App\View;

$user = Auth::require();
$isAdmin = Auth::isAdmin($user);

$from = date_or(q('from'), date('Y-m-01'));
$to = date_or(q('to'), date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

[$sw, $sp] = Scope::bc($user);
$where = ["r.report_date BETWEEN ? AND ?", $sw];
$params = array_merge([$from, $to], $sp);
if (q('branch_id') !== '') {
    $where[] = 'b.branch_id = ?';
    $params[] = (int) q('branch_id');
}
if (q('block') !== '') {
    $where[] = 'b.block = ?';
    $params[] = q('block');
}
if (q('bc') !== '') {
    $where[] = '(b.bc_code = ? OR b.name LIKE ? OR b.phone LIKE ?)';
    array_push($params, q('bc'), '%' . q('bc') . '%', '%' . q('bc') . '%');
}
if (in_array(q('source'), ['whatsapp', 'admin'], true)) {
    $where[] = 'r.source = ?';
    $params[] = q('source');
}
$w = implode(' AND ', $where);
$base = "FROM reports r JOIN bcs b ON b.id = r.bc_id LEFT JOIN branches br ON br.id = b.branch_id WHERE {$w}";

$export = q('export');
if (in_array($export, ['csv', 'xlsx', 'pdf'], true)) {
    $rows = DB::all("SELECT r.*, b.bc_code, b.name, b.block, br.name branch {$base} ORDER BY r.report_date, br.name, b.name LIMIT 100000", $params);
    $out = [];
    $t = ['apy' => 0, 'pmjjby' => 0, 'pmsby' => 0, 'pmjdy' => 0];
    foreach ($rows as $r) {
        $out[] = [fmt_date($r['report_date']), $r['bc_code'], $r['name'], (string) $r['branch'], (string) $r['block'],
            (int) $r['apy'], (int) $r['pmjjby'], (int) $r['pmsby'], (int) $r['pmjdy'], $r['source'], (int) $r['version'], fmt_dt($r['updated_at'])];
        foreach ($t as $k => $_) {
            $t[$k] += (int) $r[$k];
        }
    }
    $out[] = ['TOTAL', '', '', '', '', $t['apy'], $t['pmjjby'], $t['pmsby'], $t['pmjdy'], '', '', ''];
    Exporter::download($export, "reports_{$from}_{$to}", 'BC Daily Reports ' . fmt_date($from) . ' to ' . fmt_date($to),
        ['Date', 'BC ID', 'Name', 'Branch', 'Block', 'APY', 'PMJJBY', 'PMSBY', 'PMJDY', 'Source', 'Version', 'Updated'], $out);
}

$page = max(1, (int) q('page', 1));
$per = 100;
$agg = DB::one("SELECT COUNT(*) n, COALESCE(SUM(r.apy),0) apy, COALESCE(SUM(r.pmjjby),0) pmjjby, COALESCE(SUM(r.pmsby),0) pmsby, COALESCE(SUM(r.pmjdy),0) pmjdy {$base}", $params);
$rows = DB::all("SELECT r.*, b.bc_code, b.name, b.block, br.name branch {$base}
                 ORDER BY r.report_date DESC, br.name, b.name LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

View::header('Reports / रिपोर्ट', 'reports');
?>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('From', 'से') ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('To', 'तक') ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, q('branch_id')) ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Block', 'ब्लॉक') ?></label><select name="block" class="form-select form-select-sm"><?= View::blockOptions($user, q('block')) ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small">BC</label><input name="bc" value="<?= e(q('bc')) ?>" class="form-control form-control-sm" placeholder="BC ID / name"></div>
  <div class="col-6 col-md-1"><label class="form-label small"><?= L('Source', 'स्रोत') ?></label><select name="source" class="form-select form-select-sm">
    <option value="">All</option><option value="whatsapp" <?= q('source') === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option><option value="admin" <?= q('source') === 'admin' ? 'selected' : '' ?>>Admin edit</option></select></div>
  <div class="col-12 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<div class="d-flex flex-wrap gap-2 mb-2">
  <a class="btn btn-sm btn-outline-success" href="<?= e(qs(['export' => 'xlsx', 'page' => null])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(qs(['export' => 'csv', 'page' => null])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
  <a class="btn btn-sm btn-outline-danger" href="<?= e(qs(['export' => 'pdf', 'page' => null])) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
  <?php if ($isAdmin): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="/report_edit.php"><i class="bi bi-plus-lg"></i> <?= L('Add / edit report', 'रिपोर्ट जोड़ें / बदलें') ?></a><?php endif; ?>
</div>
<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
  <thead class="table-light"><tr><th><?= L('Date', 'तारीख') ?></th><th>BC</th><th><?= L('Branch', 'शाखा') ?></th>
    <th class="text-end">APY</th><th class="text-end">PMJJBY</th><th class="text-end">PMSBY</th><th class="text-end">PMJDY</th><th><?= L('Source', 'स्रोत') ?></th><th><?= L('Updated', 'अपडेट') ?></th><th></th></tr></thead>
  <tbody>
  <tr class="table-success fw-semibold"><td colspan="3">TOTAL / कुल (<?= (int) $agg['n'] ?> reports)</td>
    <td class="text-end"><?= (int) $agg['apy'] ?></td><td class="text-end"><?= (int) $agg['pmjjby'] ?></td><td class="text-end"><?= (int) $agg['pmsby'] ?></td><td class="text-end"><?= (int) $agg['pmjdy'] ?></td><td colspan="3"></td></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td class="text-nowrap"><?= e(fmt_date($r['report_date'])) ?></td>
    <td><?= e($r['name']) ?> <small class="text-muted"><?= e($r['bc_code']) ?></small></td>
    <td class="small"><?= e($r['branch']) ?> <span class="text-muted"><?= e($r['block']) ?></span></td>
    <td class="text-end"><?= (int) $r['apy'] ?></td><td class="text-end"><?= (int) $r['pmjjby'] ?></td><td class="text-end"><?= (int) $r['pmsby'] ?></td><td class="text-end"><?= (int) $r['pmjdy'] ?></td>
    <td><span class="badge text-bg-<?= $r['source'] === 'admin' ? 'warning' : 'success' ?>"><?= e($r['source']) ?></span><?= (int) $r['version'] > 1 ? ' <small class="text-muted">v' . (int) $r['version'] . '</small>' : '' ?></td>
    <td class="small text-nowrap"><?= e(fmt_dt($r['updated_at'])) ?></td>
    <td><a class="btn btn-sm btn-outline-secondary" href="/report_edit.php?id=<?= (int) $r['id'] ?>" title="<?= $isAdmin ? 'Edit / history' : 'History' ?>"><i class="bi bi-<?= $isAdmin ? 'pencil' : 'clock-history' ?>"></i></a></td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="text-muted">No reports / कोई रिपोर्ट नहीं</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= View::pager((int) $agg['n'], $page, $per) ?>
<?php View::footer();
