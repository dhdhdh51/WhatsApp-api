<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\Calendar;
use App\Csrf;
use App\DB;
use App\Phone;
use App\Queue;
use App\Scope;
use App\Templates;
use App\View;

$user = Auth::require();
$bcId = (int) (q('bc_id') ?: p('bc_id'));
if (!$bcId || !Scope::canSeeBc($user, $bcId)) {
    http_response_code(404);
    exit('BC not found');
}
$bc = DB::one('SELECT b.*, br.name branch FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id WHERE b.id = ?', [$bcId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && p('action') === 'send_form') {
    $today = Calendar::today();
    if (!(int) $bc['is_active']) {
        flash('danger', 'BC is inactive / बीसी निष्क्रिय है');
    } elseif (!Calendar::isOpen($today)) {
        flash('danger', 'Cutoff passed / कटऑफ निकल गया');
    } elseif (Queue::hasPendingFor($bcId, $today)) {
        flash('info', 'A form/reminder for today is already waiting in the queue / पहले से कतार में है');
    } else {
        Queue::enqueue($bcId, Queue::chatIdFor($bc), Templates::build('form', $bc, $today), 'manual_reminder', $today, 3,
            "manual:{$bcId}:{$today}:" . date('His'), (int) $user['id']);
        flash('success', 'Form queued – it will be sent shortly / फॉर्म कतार में');
    }
    redirect('/conversation.php?bc_id=' . $bcId);
}

$msgs = array_reverse(DB::all('SELECT * FROM messages WHERE bc_id = ? ORDER BY id DESC LIMIT 200', [$bcId]));
$recent = DB::all('SELECT * FROM reports WHERE bc_id = ? ORDER BY report_date DESC LIMIT 10', [$bcId]);

View::header('Conversation / बातचीत – ' . $bc['name'], 'messages');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-body small">
      <div class="fw-semibold fs-6"><?= e($bc['name']) ?></div>
      <div>BC ID: <?= e($bc['bc_code']) ?></div>
      <div>WhatsApp: <?= e(Phone::display($bc['phone'])) ?></div>
      <div><?= L('Branch', 'शाखा') ?>: <?= e($bc['branch']) ?> · <?= L('Block', 'ब्लॉक') ?>: <?= e($bc['block']) ?></div>
      <div class="mt-1"><?= $bc['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?>
        <?= $bc['opted_out'] ? '<span class="badge text-bg-warning">STOP – no reminders</span>' : '' ?></div>
      <form method="post" class="mt-2"><?= Csrf::field() ?><input type="hidden" name="bc_id" value="<?= $bcId ?>">
        <button name="action" value="send_form" class="btn btn-sm btn-success"><i class="bi bi-send"></i> <?= L("Send today's form", 'आज का फॉर्म भेजें') ?></button></form>
    </div></div>
    <div class="card"><div class="card-header small"><?= L('Recent reports', 'हाल की रिपोर्ट') ?></div>
      <table class="table table-sm mb-0 small"><thead><tr><th>Date</th><th>APY</th><th>JJBY</th><th>SBY</th><th>JDY</th></tr></thead><tbody>
      <?php foreach ($recent as $r): ?><tr><td><a href="/report_edit.php?id=<?= (int) $r['id'] ?>"><?= e(fmt_date($r['report_date'])) ?></a></td><td><?= (int) $r['apy'] ?></td><td><?= (int) $r['pmjjby'] ?></td><td><?= (int) $r['pmsby'] ?></td><td><?= (int) $r['pmjdy'] ?></td></tr><?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="5" class="text-muted">None</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>
  <div class="col-lg-8">
    <div class="chat card"><div class="card-body" id="chat">
    <?php $lastDay = ''; foreach ($msgs as $m): $day = substr((string) $m['created_at'], 0, 10); ?>
      <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="text-center my-2"><span class="badge text-bg-light"><?= e(fmt_date($day)) ?></span></div><?php endif; ?>
      <div class="bubble <?= $m['direction'] === 'in' ? 'in' : 'out' ?> <?= $m['status'] === 'failed' ? 'failed' : '' ?>">
        <div class="wa-pre"><?= e($m['body']) ?></div>
        <div class="meta"><?= e($m['kind']) ?> · <?= e(date('h:i A', strtotime((string) $m['created_at']))) ?><?= $m['status'] === 'failed' ? ' · ❌ ' . e($m['error']) : '' ?></div>
      </div>
    <?php endforeach; if (!$msgs): ?><div class="text-muted">No messages yet / अभी कोई संदेश नहीं</div><?php endif; ?>
    </div></div>
  </div>
</div>
<?php View::footer('<script>const c=document.getElementById("chat");c.scrollTop=c.scrollHeight;</script>');
