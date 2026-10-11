<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\Calendar;
use App\Csrf;
use App\DB;
use App\ReportParser;
use App\Reports;
use App\Scope;
use App\Settings;
use App\View;

$user = Auth::require();
$isAdmin = Auth::isAdmin($user);

$report = null;
if ((int) q('id') > 0) {
    $report = DB::one('SELECT * FROM reports WHERE id = ?', [(int) q('id')]);
    $bcId = $report ? (int) $report['bc_id'] : 0;
    $date = $report ? (string) $report['report_date'] : '';
} else {
    $bcId = (int) (q('bc_id') ?: p('bc_id'));
    $date = date_or(q('date') ?: p('date'), Calendar::today());
    if ($bcId) {
        $report = DB::one('SELECT * FROM reports WHERE bc_id = ? AND report_date = ?', [$bcId, $date]);
    }
}
if ($bcId && !Scope::canSeeBc($user, $bcId)) {
    http_response_code(403);
    exit('Not allowed');
}
$bc = $bcId ? DB::one('SELECT b.*, br.name branch FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id WHERE b.id = ?', [$bcId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only Admin can edit reports');
    }
    $reason = (string) p('reason');
    if (!$bc || !valid_date($date) || $date > Calendar::today()) {
        flash('danger', 'Select a BC and a valid (not future) date');
    } elseif (mb_strlen($reason) < 3) {
        flash('danger', 'Reason is required for the audit log / कारण लिखना ज़रूरी है');
    } elseif (p('action') === 'delete' && $report) {
        Reports::deleteByAdmin((int) $report['id'], (int) $user['id'], mb_substr($reason, 0, 255));
        flash('success', 'Report deleted (kept in history) / रिपोर्ट हटाई गई');
        redirect('/reports.php');
    } else {
        $vals = [];
        $max = max(1, Settings::int('max_value'));
        $bad = false;
        foreach (ReportParser::SCHEMES as $s) {
            $v = (string) p($s);
            $v = $v === '' ? '0' : $v;
            if (!preg_match('/^\d{1,6}$/', $v) || (int) $v > $max * 10) {
                $bad = true;
            }
            $vals[$s] = (int) $v;
        }
        if ($bad) {
            flash('danger', 'Whole numbers ≥ 0 only / केवल पूर्ण संख्या');
        } else {
            $res = Reports::saveByAdmin($bcId, $date, $vals, (int) $user['id'], mb_substr($reason, 0, 255));
            flash('success', 'Report saved (version ' . $res['version'] . ') / रिपोर्ट सेव हुई');
            redirect('/report_edit.php?bc_id=' . $bcId . '&date=' . $date);
        }
    }
    redirect('/report_edit.php?bc_id=' . $bcId . '&date=' . $date);
}

$versions = $bc ? DB::all('SELECT v.*, u.name AS user_name FROM report_versions v LEFT JOIN users u ON u.id = v.changed_by
                          WHERE v.bc_id = ? AND v.report_date = ? ORDER BY v.version DESC, v.id DESC', [$bcId, $date]) : [];

[$sw, $sp] = Scope::bc($user);
$bcList = DB::all("SELECT b.id, b.bc_code, b.name FROM bcs b WHERE {$sw} ORDER BY b.name", $sp);

View::header($isAdmin ? 'Edit report / रिपोर्ट बदलें' : 'Report history / रिपोर्ट इतिहास', 'reports');
?>
<form class="row g-2 align-items-end mb-3">
  <div class="col-md-4"><label class="form-label small">BC</label>
    <select name="bc_id" class="form-select form-select-sm" required><option value="">— select —</option>
    <?php foreach ($bcList as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $bcId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ' (' . $b['bc_code'] . ')') ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-md-2"><label class="form-label small"><?= L('Date', 'तारीख') ?></label><input type="date" name="date" value="<?= e($date) ?>" max="<?= e(Calendar::today()) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-outline-success w-100"><?= L('Open', 'खोलें') ?></button></div>
</form>

<?php if ($bc): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><?= e($bc['name']) ?> (<?= e($bc['bc_code']) ?>) – <?= e(fmt_date($date)) ?></div>
    <div class="card-body">
      <?php if (!$report): ?><div class="alert alert-warning py-2 small">No report yet for this date / इस तारीख की रिपोर्ट नहीं है</div><?php endif; ?>
      <?php if ($isAdmin): ?>
      <form method="post"><?= Csrf::field() ?>
        <input type="hidden" name="bc_id" value="<?= (int) $bcId ?>"><input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="row g-2">
        <?php foreach (ReportParser::LABELS as $k => $lbl): ?>
          <div class="col-6 col-md-3"><label class="form-label small <?= str_ends_with($k, '_off') ? 'text-warning-emphasis fw-semibold' : '' ?>"><?= $lbl ?></label>
            <input type="number" min="0" step="1" name="<?= $k ?>" class="form-control" required value="<?= e($report[$k] ?? 0) ?>"></div>
        <?php endforeach; ?>
        </div>
        <label class="form-label small mt-2"><?= L('Reason (audit log)', 'कारण (ऑडिट)') ?> *</label>
        <input name="reason" class="form-control mb-2" required minlength="3" maxlength="255" placeholder="e.g. BC called and corrected PMSBY">
        <button name="action" value="save" class="btn btn-success"><?= L('Save', 'सेव करें') ?></button>
        <?php if ($report): ?><button name="action" value="delete" class="btn btn-outline-danger" onclick="return confirm('Delete this report? It stays in history.')"><?= L('Delete', 'हटाएँ') ?></button><?php endif; ?>
      </form>
      <?php else: ?>
        <?php if ($report): ?><p class="mb-0"><?= e(Reports::summaryLine($report)) ?></p><?php endif; ?>
        <p class="small text-muted mt-2">Only Admin can edit reports. / केवल एडमिन रिपोर्ट बदल सकते हैं।</p>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><?= L('Version history (audit)', 'संस्करण इतिहास') ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>v</th><th>When</th><th>Source / by</th><?= App\Schemes::th() ?><th>Reason / message</th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
          <tr><td><?= (int) $v['version'] ?></td><td class="small text-nowrap"><?= e(fmt_dt($v['created_at'])) ?></td>
            <td class="small"><?= e($v['source']) ?><?= $v['user_name'] ? ' – ' . e($v['user_name']) : '' ?></td>
            <?= App\Schemes::td($v) ?>
            <td class="small"><?= e($v['reason']) ?><?php if ($v['raw_text']): ?><details><summary class="text-muted">message</summary><pre class="small mb-0 wa-pre"><?= e($v['raw_text']) ?></pre></details><?php endif; ?></td></tr>
        <?php endforeach; if (!$versions): ?><tr><td colspan="11" class="text-muted">No history</td></tr><?php endif; ?>
        </tbody></table></div></div>
  </div>
</div>
<?php endif; ?>
<?php View::footer();
