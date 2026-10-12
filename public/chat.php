<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\App;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Phone;
use App\Scope;
use App\View;

/** App chat: staff (Admin / Supervisor / Manager / CBC) <-> BC. Each user sees only BCs in scope. */
$user = Auth::require();
[$sw, $sp] = Scope::bc($user);
$bcId = (int) (q('bc_id') ?: p('bc_id'));
$bc = null;
if ($bcId) {
    if (!Scope::canSeeBc($user, $bcId)) {
        http_response_code(404);
        exit('BC not found');
    }
    $bc = DB::one('SELECT b.*, br.name branch FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id WHERE b.id = ?', [$bcId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bc) {
    $text = trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? '')));
    if ($text === '' || mb_strlen($text) > 2000) {
        flash('danger', 'Write a message (max 2000) / संदेश लिखें');
    } else {
        App::chatSend($bcId, 'staff', (int) $user['id'], $text);
    }
    redirect('/chat.php?bc_id=' . $bcId);
}

if (q('ajax') === '1' && $bc) {
    DB::exec("UPDATE chat_messages SET read_at = NOW() WHERE bc_id = ? AND sender = 'bc' AND read_at IS NULL", [$bcId]);
    $after = max(0, (int) q('after', 0));
    header('Content-Type: application/json');
    echo json_encode(array_map(static fn ($m) => ['id' => (int) $m['id'], 'mine' => $m['sender'] === 'staff', 'name' => $m['sender'] === 'bc' ? $bc['name'] : ($m['uname'] ?: App::SYSTEM_NAME),
        'body' => $m['body'], 'time' => date('d-m h:i A', strtotime((string) $m['created_at']))],
        DB::all('SELECT c.*, u.name uname FROM chat_messages c LEFT JOIN users u ON u.id = c.user_id WHERE c.bc_id = ? AND c.id > ? ORDER BY c.id LIMIT 200', [$bcId, $after])),
        JSON_UNESCAPED_UNICODE);
    exit;
}

$search = (string) q('search');
$params = $sp;
$extra = '';
if ($search !== '') {
    $extra = ' AND (b.name LIKE ? OR b.bc_code LIKE ? OR b.phone LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}
$threads = DB::all("SELECT b.id, b.name, b.bc_code, b.app_last_seen,
        (SELECT MAX(c.id) FROM chat_messages c WHERE c.bc_id = b.id) last_id,
        (SELECT c.body FROM chat_messages c WHERE c.bc_id = b.id ORDER BY c.id DESC LIMIT 1) last_body,
        (SELECT c.created_at FROM chat_messages c WHERE c.bc_id = b.id ORDER BY c.id DESC LIMIT 1) last_at,
        (SELECT COUNT(*) FROM chat_messages c WHERE c.bc_id = b.id AND c.sender = 'bc' AND c.read_at IS NULL) unread
     FROM bcs b WHERE b.is_active = 1 AND {$sw}{$extra}
     ORDER BY (last_id IS NULL), last_id DESC, b.name LIMIT 300", $params);

if ($bc) {
    DB::exec("UPDATE chat_messages SET read_at = NOW() WHERE bc_id = ? AND sender = 'bc' AND read_at IS NULL", [$bcId]);
}

View::header('App chat / ऐप चैट', 'chat');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <form class="mb-2"><input name="search" value="<?= e($search) ?>" class="form-control form-control-sm" placeholder="Search BC / बीसी खोजें"></form>
    <div class="list-group small" style="max-height:72vh;overflow:auto">
    <?php foreach ($threads as $t): ?>
      <a href="/chat.php?bc_id=<?= (int) $t['id'] ?>" class="list-group-item list-group-item-action <?= $bcId === (int) $t['id'] ? 'active' : '' ?>">
        <div class="d-flex justify-content-between"><strong><?= e($t['name']) ?></strong>
          <?php if ((int) $t['unread'] > 0): ?><span class="badge text-bg-danger rounded-pill"><?= (int) $t['unread'] ?></span><?php endif; ?></div>
        <div class="text-truncate opacity-75"><?= $t['last_body'] !== null ? e(mb_strimwidth((string) $t['last_body'], 0, 70, '…')) : '<i>no messages</i>' ?></div>
        <div class="opacity-50"><?= e($t['bc_code']) ?><?= $t['app_last_seen'] ? ' · 📱 app ' . e(fmt_dt($t['app_last_seen'])) : ' · app not used yet' ?></div>
      </a>
    <?php endforeach; if (!$threads): ?><div class="list-group-item text-muted">No BCs</div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
  <?php if (!$bc): ?>
    <div class="alert alert-info small">Select a BC on the left to chat. Messages appear in the BC's mobile app with a notification (checked every ~15 min).
      <br>बाईं ओर बीसी चुनें। संदेश बीसी के ऐप में जाएगा।</div>
  <?php else: ?>
    <div class="card mb-2"><div class="card-body py-2 small d-flex flex-wrap gap-2 align-items-center">
      <strong class="me-auto"><?= e($bc['name']) ?> · <?= e($bc['bc_code']) ?> · <?= e(Phone::display($bc['phone'])) ?></strong>
      <?= View::contactButtons($bc['phone']) ?>
      <?= $bc['app_last_seen'] ? '<span class="badge text-bg-success">📱 app ' . e(fmt_dt($bc['app_last_seen'])) . '</span>' : '<span class="badge text-bg-secondary">app not installed / used</span>' ?>
    </div></div>
    <div class="chat card mb-2"><div class="card-body" id="chat" style="height:55vh"></div></div>
    <form method="post" class="d-flex gap-2"><?= Csrf::field() ?><input type="hidden" name="bc_id" value="<?= $bcId ?>">
      <textarea name="text" rows="2" class="form-control" maxlength="2000" required placeholder="Message / संदेश लिखें"></textarea>
      <button class="btn btn-success"><i class="bi bi-send"></i></button>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php
$js = '';
if ($bc) {
    $js = '<script>(function(){var box=document.getElementById("chat"),last=0;
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c];});}
function load(){fetch("/chat.php?ajax=1&bc_id=' . $bcId . '&after="+last,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(rows){
 if(!last&&!rows.length){box.innerHTML="<div class=\\"text-muted\\">No messages yet</div>";}
 rows.forEach(function(m){if(m.id<=last)return;if(!last)box.innerHTML="";last=m.id;var d=document.createElement("div");d.className="bubble "+(m.mine?"out":"in");
 d.innerHTML="<div class=\\"small fw-semibold text-success\\">"+esc(m.name)+"</div><div class=\\"wa-pre\\">"+esc(m.body)+"</div><div class=\\"meta\\">"+esc(m.time)+"</div>";box.appendChild(d);});
 if(rows.length)box.scrollTop=box.scrollHeight;});}
load();setInterval(load,8000);})();</script>';
}
View::footer($js);
