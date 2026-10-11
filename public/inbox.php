<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\MessageLog;
use App\Phone;
use App\View;
use App\WahaClient;

/**
 * Inbox: messages from numbers that are not (or no longer) part of the BC team.
 */
$user = Auth::requireAdmin();
$chat = (string) (q('chat') ?: p('chat'));
$thread = $chat !== '' ? DB::one('SELECT * FROM inbox_threads WHERE chat_id = ?', [$chat]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $thread) {
    switch (p('action')) {
        case 'reply':
            $text = trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? '')));
            if ($text === '' || mb_strlen($text) > 2000) {
                flash('danger', 'Write a message (max 2000 chars) / संदेश लिखें');
                break;
            }
            $waha = new WahaClient();
            $waha->startTyping($thread['chat_id']);
            sleep(1);
            $waha->stopTyping($thread['chat_id']);
            $r = $waha->sendText($thread['chat_id'], $text);
            MessageLog::add([
                'bc_id' => $thread['bc_id'] ? (int) $thread['bc_id'] : null,
                'direction' => 'out',
                'chat_id' => $thread['chat_id'],
                'body' => $text,
                'kind' => 'admin_reply',
                'status' => $r['ok'] ? 'sent' : 'failed',
                'wa_message_id' => $r['id'],
                'error' => $r['ok'] ? null : $r['error'],
            ]);
            Audit::log((int) $user['id'], 'inbox_reply', 'inbox', null, ['chat' => $thread['chat_id'], 'ok' => $r['ok']]);
            flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Reply sent / जवाब भेजा गया' : 'Send failed: ' . $r['error']);
            break;
        case 'archive':
            DB::exec('UPDATE inbox_threads SET archived = 1, unread = 0 WHERE chat_id = ?', [$thread['chat_id']]);
            flash('success', 'Archived / संग्रहित');
            redirect('/inbox.php');
        case 'unarchive':
            DB::exec('UPDATE inbox_threads SET archived = 0 WHERE chat_id = ?', [$thread['chat_id']]);
            break;
    }
    redirect('/inbox.php?chat=' . rawurlencode($thread['chat_id']));
}

if ($thread) {
    DB::exec('UPDATE inbox_threads SET unread = 0 WHERE chat_id = ?', [$thread['chat_id']]);
    $msgs = array_reverse(DB::all('SELECT * FROM messages WHERE chat_id = ? ORDER BY id DESC LIMIT 200', [$thread['chat_id']]));
    $isBc = $thread['phone'] ? DB::one('SELECT id, name, is_active FROM bcs WHERE phone = ?', [$thread['phone']]) : null;
}

$filter = in_array(q('show'), ['unread', 'archived'], true) ? q('show') : 'open';
$where = match ($filter) {
    'unread' => 'archived = 0 AND unread > 0',
    'archived' => 'archived = 1',
    default => 'archived = 0',
};
$threads = DB::all("SELECT t.*, (SELECT m.body FROM messages m WHERE m.chat_id = t.chat_id AND m.direction = 'in' ORDER BY m.id DESC LIMIT 1) AS last_body
                    FROM inbox_threads t WHERE {$where} ORDER BY t.last_at DESC LIMIT 300");

View::header('Inbox – non-team numbers / इनबॉक्स', 'inbox');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <ul class="nav nav-pills nav-fill mb-2 small">
      <?php foreach (['open' => 'Open / खुले', 'unread' => 'Unread / नए', 'archived' => 'Archived / संग्रहित'] as $k => $lbl): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $filter === $k ? 'active' : '' ?>" href="/inbox.php?show=<?= $k ?>"><?= e($lbl) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="list-group small" style="max-height:72vh;overflow:auto">
      <?php foreach ($threads as $t): ?>
        <a href="/inbox.php?show=<?= e($filter) ?>&chat=<?= e(rawurlencode($t['chat_id'])) ?>" class="list-group-item list-group-item-action <?= $thread && $thread['chat_id'] === $t['chat_id'] ? 'active' : '' ?>">
          <div class="d-flex justify-content-between">
            <strong><?= e($t['name'] ?: ($t['phone'] ? Phone::display($t['phone']) : $t['chat_id'])) ?></strong>
            <?php if ((int) $t['unread'] > 0): ?><span class="badge text-bg-danger rounded-pill"><?= (int) $t['unread'] ?></span><?php endif; ?>
          </div>
          <div class="text-truncate opacity-75"><?= e(mb_strimwidth((string) $t['last_body'], 0, 80, '…')) ?></div>
          <div class="opacity-50"><?= e(fmt_dt($t['last_at'])) ?><?= $t['bc_id'] ? ' · inactive BC' : '' ?></div>
        </a>
      <?php endforeach; if (!$threads): ?><div class="list-group-item text-muted">No messages / कोई संदेश नहीं</div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
  <?php if (!$thread): ?>
    <div class="alert alert-info small mb-0">People who are not in the BC team (or inactive BCs) can write to the WhatsApp number.
      They get an automatic reply (Settings → "Messages from non-team numbers") and their messages appear here.
      <br>जो लोग बीसी टीम में नहीं हैं, उनके संदेश यहाँ दिखते हैं। बाईं ओर किसी पर क्लिक करें।</div>
  <?php else: ?>
    <div class="card mb-2"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center small">
      <div class="me-auto"><strong><?= e($thread['name'] ?: '—') ?></strong>
        · <?= e($thread['phone'] ? Phone::display($thread['phone']) : $thread['chat_id']) ?>
        · <?= (int) $thread['message_count'] ?> msgs
        <?= $thread['auto_replied_at'] ? ' · auto-reply ' . e(fmt_dt($thread['auto_replied_at'])) : '' ?></div>
      <?php if ($isBc): ?>
        <a class="btn btn-sm btn-outline-secondary" href="/bc_edit.php?id=<?= (int) $isBc['id'] ?>"><i class="bi bi-person"></i> BC: <?= e($isBc['name']) ?><?= $isBc['is_active'] ? '' : ' (inactive)' ?></a>
      <?php elseif ($thread['phone']): ?>
        <a class="btn btn-sm btn-success" href="/bc_edit.php?phone=<?= e($thread['phone']) ?>&name=<?= e(rawurlencode((string) $thread['name'])) ?>"><i class="bi bi-person-plus"></i> <?= L('Add as BC', 'बीसी बनाएँ') ?></a>
      <?php endif; ?>
      <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="chat" value="<?= e($thread['chat_id']) ?>">
        <?php if ($thread['archived']): ?><button name="action" value="unarchive" class="btn btn-sm btn-outline-secondary"><?= L('Unarchive', 'वापस लाएँ') ?></button>
        <?php else: ?><button name="action" value="archive" class="btn btn-sm btn-outline-secondary"><i class="bi bi-archive"></i> <?= L('Archive', 'संग्रहित') ?></button><?php endif; ?>
      </form>
    </div></div>
    <div class="chat card mb-2"><div class="card-body" id="chat">
      <?php $lastDay = ''; foreach ($msgs as $m): $day = substr((string) $m['created_at'], 0, 10); ?>
        <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="text-center my-2"><span class="badge text-bg-light"><?= e(fmt_date($day)) ?></span></div><?php endif; ?>
        <div class="bubble <?= $m['direction'] === 'in' ? 'in' : 'out' ?> <?= $m['status'] === 'failed' ? 'failed' : '' ?>">
          <div class="wa-pre"><?= e($m['body']) ?></div>
          <div class="meta"><?= e($m['kind'] === 'reply_unknown_sender' ? 'auto-reply' : ($m['kind'] === 'admin_reply' ? 'admin' : '')) ?> <?= e(date('h:i A', strtotime((string) $m['created_at']))) ?><?= $m['status'] === 'failed' ? ' · ❌ ' . e($m['error']) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div></div>
    <form method="post" class="d-flex gap-2"><?= Csrf::field() ?><input type="hidden" name="chat" value="<?= e($thread['chat_id']) ?>">
      <textarea name="text" rows="2" class="form-control" maxlength="2000" required placeholder="Reply on WhatsApp / व्हाट्सऐप पर जवाब लिखें"></textarea>
      <button name="action" value="reply" class="btn btn-success"><i class="bi bi-send"></i></button>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php View::footer($thread ? '<script>const c=document.getElementById("chat");c.scrollTop=c.scrollHeight;</script>' : '');
