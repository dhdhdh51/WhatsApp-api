<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\Calendar;
use App\Csrf;
use App\Export\Exporter;
use App\Phone;
use App\Queue;
use App\ReportParser;
use App\Schemes;
use App\View;

$user = Auth::require();
$today = Calendar::today();
$date = date_or(q('date'), $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && p('action') === 'remind') {
    $ids = array_map('intval', (array) ($_POST['bc_ids'] ?? []));
    if (p('scope') === 'all') {
        $ids = [];
    } elseif (!$ids) {
        flash('warning', 'Select at least one BC / कम से कम एक बीसी चुनें');
        redirect('/status.php' . qs());
    }
    if (!Calendar::isOpen($today)) {
        flash('danger', 'Cutoff time has passed – reminders not sent / कटऑफ समय निकल गया');
    } else {
        $n = Queue::reminderNow($user, $ids);
        flash($n ? 'success' : 'info', $n
            ? "{$n} reminder(s) queued – they go out one by one with safe gaps / {$n} रिमाइंडर कतार में"
            : 'Nothing queued (no pending BCs, opted-out, or already waiting in queue) / कोई रिमाइंडर नहीं');
    }
    redirect('/status.php' . qs());
}

$filters = [
    'branch_id' => q('branch_id'),
    'block' => q('block'),
    'search' => q('search'),
    'status' => q('status'),
    'cbc_id' => q('cbc_id'),
];
$isCbc = $user['role'] === 'cbc';
$rows = Calendar::dayRows($date, $user, $filters);

if (q('export') === 'csv') {
    $out = [];
    foreach ($rows as $r) {
        $line = [$r['bc_code'], $r['name'], $r['phone'], $r['branch_name'], $r['block'], (string) $r['cbc_name'], $r['status']];
        foreach (ReportParser::SCHEMES as $k) {
            $line[] = $r['report_id'] ? (int) $r[$k] : '';
        }
        $line[] = $r['report_updated_at'];
        $out[] = $line;
    }
    Exporter::download(in_array(q('format'), ['xlsx', 'pdf'], true) ? q('format') : 'csv', 'status_' . $date, 'Status ' . $date,
        array_merge(['BC ID', 'Name', 'Phone', 'Branch', 'Block', 'CBC', 'Status'], array_values(ReportParser::LABELS), ['Updated']), $out);
}

$counts = array_count_values(array_column(Calendar::dayRows($date, $user, array_merge($filters, ['status' => ''])), 'status'));

View::header("Today's status / आज की स्थिति – " . fmt_date($date), 'status');
?>
<form class="row g-2 align-items-end mb-2">
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Date', 'तारीख') ?></label><input type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label><select name="branch_id" class="form-select form-select-sm"><?= View::branchOptions($user, $filters['branch_id']) ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Block', 'ब्लॉक') ?></label><select name="block" class="form-select form-select-sm"><?= View::blockOptions($user, $filters['block']) ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Status', 'स्थिति') ?></label>
    <select name="status" class="form-select form-select-sm">
      <?php foreach (['' => 'All / सभी', 'submitted' => 'Submitted / जमा', 'pending' => 'Pending / बाकी', 'not_submitted' => 'Not submitted (all) / जमा नहीं', 'leave' => 'Leave / छुट्टी', 'holiday' => 'Holiday / अवकाश'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <?php if (!$isCbc): ?><div class="col-6 col-md-2"><label class="form-label small">CBC</label><select name="cbc_id" class="form-select form-select-sm"><?= View::cbcOptions($user, $filters['cbc_id']) ?></select></div><?php endif; ?>
  <div class="col-8 col-md-2"><label class="form-label small"><?= L('Search', 'खोजें') ?></label><input name="search" value="<?= e($filters['search']) ?>" class="form-control form-control-sm" placeholder="Name / BC ID / phone"></div>
  <div class="col-4 col-md-2 d-flex gap-1"><button class="btn btn-sm btn-success flex-fill"><i class="bi bi-funnel"></i></button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(qs(['export' => 'csv', 'format' => 'xlsx'])) ?>" title="Export Excel"><i class="bi bi-file-earmark-excel"></i></a></div>
</form>

<div class="mb-2 d-flex flex-wrap gap-2 small">
  <span class="badge text-bg-success">Submitted: <?= (int) ($counts['submitted'] ?? 0) ?></span>
  <span class="badge text-bg-danger">Pending: <?= (int) ($counts['pending'] ?? 0) ?></span>
  <span class="badge text-bg-info">Leave: <?= (int) ($counts['leave'] ?? 0) ?></span>
  <span class="badge text-bg-secondary">Holiday/off: <?= (int) (($counts['holiday'] ?? 0) + ($counts['weekly_off'] ?? 0)) ?></span>
</div>

<?php $pendingRows = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'pending'));
if ($pendingRows):
  $copy = "⏳ Report pending – " . fmt_date($date) . "\n";
  foreach ($pendingRows as $i => $r) { $copy .= ($i + 1) . '. ' . $r['name'] . ' (' . $r['bc_code'] . ') ' . App\Phone::display($r['phone']) . "\n"; } ?>
<div class="alert alert-warning py-2 small d-flex flex-wrap align-items-center gap-2">
  <span><i class="bi bi-telephone"></i> <strong><?= count($pendingRows) ?></strong> <?= L('BCs have not reported – call them using the green button', 'बीसी की रिपोर्ट नहीं आई – हरे बटन से कॉल करें') ?></span>
  <button type="button" class="btn btn-sm btn-outline-dark ms-auto" data-copy="<?= e($copy) ?>"><i class="bi bi-clipboard"></i> <?= L('Copy pending list', 'बाकी की लिस्ट कॉपी करें') ?></button>
</div>
<?php endif; ?>
<form method="post" id="remindForm">
<?= Csrf::field() ?>
<input type="hidden" name="action" value="remind">
<?php if ($date === $today): ?>
<div class="d-flex flex-wrap gap-2 mb-2">
  <button name="scope" value="selected" class="btn btn-sm btn-warning" onclick="return confirm('Send reminder to selected pending BCs?')"><i class="bi bi-bell"></i> <?= L('Send reminder to selected', 'चुने हुए को रिमाइंडर') ?></button>
  <button name="scope" value="all" class="btn btn-sm btn-danger" onclick="return confirm('Send reminder to ALL pending BCs now?')"><i class="bi bi-bell-fill"></i> <?= L('Send reminder now (all pending)', 'सभी बाकी को अभी रिमाइंडर') ?></button>
</div>
<?php endif; ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light"><tr>
    <th><input type="checkbox" class="form-check-input" data-check-all="bc_ids[]"></th>
    <th>BC</th><th><?= L('Call', 'कॉल') ?></th><th>Branch / Block<?= $isCbc ? '' : ' / CBC' ?></th><th><?= L('Status', 'स्थिति') ?></th>
    <?= Schemes::th() ?><th><?= L('Time', 'समय') ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?php if ($r['status'] === 'pending' && !$r['opted_out']): ?><input type="checkbox" class="form-check-input" name="bc_ids[]" value="<?= (int) $r['id'] ?>"><?php endif; ?></td>
      <td><a href="/conversation.php?bc_id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a> <small class="text-muted"><?= e($r['bc_code']) ?></small>
        <div class="small text-muted"><?= e(Phone::display($r['phone'])) ?><?= $r['opted_out'] ? ' <span class="badge text-bg-warning">STOP</span>' : '' ?></div></td>
      <td class="text-nowrap"><?= View::contactButtons($r['phone']) ?></td>
      <td class="small"><?= e($r['branch_name']) ?><br><span class="text-muted"><?= e($r['block']) ?></span><?= !$isCbc && $r['cbc_name'] ? '<br><span class="badge text-bg-info">CBC: ' . e($r['cbc_name']) . '</span>' : '' ?></td>
      <td><?= View::statusBadge($r['status']) ?><?= $r['report_id'] && (int) $r['version'] > 1 ? ' <span class="badge text-bg-light" title="versions">v' . (int) $r['version'] . '</span>' : '' ?></td>
      <?= Schemes::td($r, !$r['report_id']) ?>
      <td class="small"><?= $r['report_updated_at'] ? e(date('h:i A', strtotime($r['report_updated_at']))) : '' ?></td>
      <td><?php if (Auth::isAdmin($user)): ?><a class="btn btn-sm btn-outline-secondary" href="/report_edit.php?bc_id=<?= (int) $r['id'] ?>&date=<?= e($date) ?>" title="Edit"><i class="bi bi-pencil"></i></a><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="15" class="text-muted">No BCs / कोई बीसी नहीं</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</form>
<?php View::footer();
