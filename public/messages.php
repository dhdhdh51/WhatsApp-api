<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Queue;
use App\Scope;
use App\Settings;
use App\View;

$user = Auth::require();
$isAdmin = Auth::isAdmin($user);
$tab = q('tab') === 'queue' ? 'queue' : 'log';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Admin only');
    }
    $id = (int) p('id');
    switch (p('action')) {
        case 'cancel':
            DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'cancelled by admin' WHERE id = ? AND status = 'pending'", [$id]);
            break;
        case 'retry':
            DB::exec("UPDATE message_queue SET status = 'pending', attempts = 0, scheduled_at = NOW(), last_error = NULL WHERE id = ? AND status IN ('failed','cancelled')", [$id]);
            break;
        case 'cancel_all':
            $n = DB::exec("UPDATE message_queue SET status = 'cancelled', last_error = 'cancelled by admin (all)' WHERE status = 'pending'");
            Audit::log((int) $user['id'], 'queue_cancel_all', 'queue', null, ['count' => $n]);
            flash('success', "{$n} pending messages cancelled");
            break;
    }
    redirect('/messages.php' . qs());
}

[$sw, $sp] = Scope::bc($user);
$page = max(1, (int) q('page', 1));
$per = 100;
$date = date_or(q('date'), '');

if ($tab === 'log') {
    $where = [$isAdmin ? '1=1' : "m.bc_id IS NOT NULL AND {$sw}"];
    $params = $isAdmin ? [] : $sp;
    if (in_array(q('direction'), ['in', 'out'], true)) {
        $where[] = 'm.direction = ?';
        $params[] = q('direction');
    }
    if (in_array(q('status'), ['sent', 'failed', 'received', 'ignored'], true)) {
        $where[] = 'm.status = ?';
        $params[] = q('status');
    }
    if ($date !== '') {
        $where[] = 'm.created_at >= ? AND m.created_at < (? + INTERVAL 1 DAY)';
        array_push($params, $date, $date);
    }
    if (q('search') !== '') {
        $where[] = '(b.name LIKE ? OR b.bc_code LIKE ? OR m.chat_id LIKE ? OR m.body LIKE ?)';
        $s = '%' . q('search') . '%';
        array_push($params, $s, $s, $s, $s);
    }
    $w = implode(' AND ', $where);
    $total = (int) DB::val("SELECT COUNT(*) FROM messages m LEFT JOIN bcs b ON b.id = m.bc_id WHERE {$w}", $params);
    $rows = DB::all("SELECT m.*, b.name, b.bc_code FROM messages m LEFT JOIN bcs b ON b.id = m.bc_id WHERE {$w}
                     ORDER BY m.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
} else {
    $where = [$isAdmin ? '1=1' : "q.bc_id IS NOT NULL AND {$sw}"];
    $params = $isAdmin ? [] : $sp;
    if (in_array(q('status'), ['pending', 'sending', 'sent', 'failed', 'cancelled'], true)) {
        $where[] = 'q.status = ?';
        $params[] = q('status');
    }
    if ($date !== '') {
        $where[] = 'q.created_at >= ? AND q.created_at < (? + INTERVAL 1 DAY)';
        array_push($params, $date, $date);
    }
    $w = implode(' AND ', $where);
    $total = (int) DB::val("SELECT COUNT(*) FROM message_queue q LEFT JOIN bcs b ON b.id = q.bc_id WHERE {$w}", $params);
    $rows = DB::all("SELECT q.*, b.name, b.bc_code FROM message_queue q LEFT JOIN bcs b ON b.id = q.bc_id WHERE {$w}
                     ORDER BY FIELD(q.status,'sending','pending','failed','sent','cancelled'), q.priority, q.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
}
$stats = Queue::stats();

View::header('Messages / संदेश', 'messages');
?>
<ul class="nav nav-tabs mb-2">
  <li class="nav-item"><a class="nav-link <?= $tab === 'log' ? 'active' : '' ?>" href="/messages.php"><?= L('Message log', 'संदेश लॉग') ?></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'queue' ? 'active' : '' ?>" href="/messages.php?tab=queue"><?= L('Send queue', 'भेजने की कतार') ?>
    <span class="badge text-bg-warning"><?= $stats['pending'] ?></span></a></li>
</ul>
<div class="small mb-2 d-flex flex-wrap gap-2">
  <span class="badge text-bg-success">Sent today: <?= $stats['sent_today'] ?> (limit <?= Settings::effectiveDailyLimit() ?><?= Settings::isWarmup() ? ', warm-up' : '' ?>)</span>
  <span class="badge text-bg-danger">Failed today: <?= $stats['failed_today'] ?></span>
  <?php if (Settings::bool('queue_paused')): ?><span class="badge text-bg-dark">Queue paused manually</span><?php endif; ?>
  <?php if (Settings::get('queue_pause_reason')): ?><span class="badge text-bg-dark">Paused: <?= e(Settings::get('queue_pause_reason')) ?></span><?php endif; ?>
</div>

<form class="row g-2 align-items-end mb-2"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php if ($tab === 'log'): ?>
  <div class="col-6 col-md-2"><select name="direction" class="form-select form-select-sm"><option value="">In + out</option>
    <option value="in" <?= q('direction') === 'in' ? 'selected' : '' ?>>Incoming / आए</option><option value="out" <?= q('direction') === 'out' ? 'selected' : '' ?>>Outgoing / भेजे</option></select></div>
  <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm"><option value="">All status</option>
    <?php foreach (['sent', 'failed', 'received', 'ignored'] as $s): ?><option <?= q('status') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <?php else: ?>
  <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm"><option value="">All status</option>
    <?php foreach (['pending', 'sending', 'sent', 'failed', 'cancelled'] as $s): ?><option <?= q('status') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-6 col-md-2"><input type="date" name="date" value="<?= e($date) ?>" class="form-control form-control-sm"></div>
  <?php if ($tab === 'log'): ?><div class="col-6 col-md-3"><input name="search" value="<?= e(q('search')) ?>" class="form-control form-control-sm" placeholder="BC / number / text"></div><?php endif; ?>
  <div class="col-6 col-md-1"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i></button></div>
</form>
<?php if ($tab === 'queue' && $isAdmin && $stats['pending'] > 0): ?>
<form method="post" class="mb-2"><?= Csrf::field() ?><button name="action" value="cancel_all" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel ALL pending messages?')"><i class="bi bi-x-octagon"></i> <?= L('Cancel all pending', 'सभी रद्द करें') ?></button></form>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-sm table-hover align-middle small">
<?php if ($tab === 'log'): ?>
  <thead class="table-light"><tr><th><?= L('Time', 'समय') ?></th><th></th><th>BC / chat</th><th><?= L('Kind', 'प्रकार') ?></th><th><?= L('Status', 'स्थिति') ?></th><th><?= L('Message', 'संदेश') ?></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?= e(fmt_dt($r['created_at'])) ?></td>
      <td><?= $r['direction'] === 'in' ? '<i class="bi bi-arrow-down-left text-primary" title="in"></i>' : '<i class="bi bi-arrow-up-right text-success" title="out"></i>' ?></td>
      <td><?= $r['bc_id'] ? '<a href="/conversation.php?bc_id=' . (int) $r['bc_id'] . '">' . e($r['name']) . '</a> <span class="text-muted">' . e($r['bc_code']) . '</span>' : '<span class="text-muted">' . e($r['chat_id']) . '</span>' ?></td>
      <td><?= e($r['kind']) ?></td><td><?= View::statusBadge($r['status']) ?></td>
      <td><div class="msg-preview"><?= nl2br(e(mb_strimwidth((string) $r['body'], 0, 300, '…'))) ?></div><?= $r['error'] ? '<div class="text-danger">' . e($r['error']) . '</div>' : '' ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-muted">No messages</td></tr><?php endif; ?></tbody>
<?php else: ?>
  <thead class="table-light"><tr><th>#</th><th><?= L('Scheduled', 'निर्धारित') ?></th><th>To</th><th><?= L('Kind', 'प्रकार') ?></th><th><?= L('Status', 'स्थिति') ?></th><th>Tries</th><th><?= L('Sent', 'भेजा') ?></th><th>Error</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td><?= (int) $r['id'] ?></td><td class="text-nowrap"><?= e(fmt_dt($r['scheduled_at'])) ?></td>
      <td><?= $r['bc_id'] ? e($r['name']) . ' <span class="text-muted">' . e($r['bc_code']) . '</span>' : e($r['chat_id']) ?></td>
      <td><?= e($r['kind']) ?></td><td><?= View::statusBadge($r['status']) ?></td><td><?= (int) $r['attempts'] ?></td>
      <td class="text-nowrap"><?= e(fmt_dt($r['sent_at'])) ?></td><td class="text-danger"><?= e($r['last_error']) ?></td>
      <td class="text-nowrap"><?php if ($isAdmin): ?><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <?php if ($r['status'] === 'pending'): ?><button name="action" value="cancel" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="bi bi-x"></i></button><?php endif; ?>
        <?php if (in_array($r['status'], ['failed', 'cancelled'], true)): ?><button name="action" value="retry" class="btn btn-sm btn-outline-primary" title="Retry"><i class="bi bi-arrow-repeat"></i></button><?php endif; ?>
      </form><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-muted">Queue is empty</td></tr><?php endif; ?></tbody>
<?php endif; ?>
</table>
</div>
<?= View::pager($total, $page, $per) ?>
<?php View::footer();
