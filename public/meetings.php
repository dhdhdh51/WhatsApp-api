<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Meetings;
use App\View;

/** Start / end video meetings (Jitsi) for BCs and CBC supervisors. */
$user = Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');
    if ($action === 'start') {
        $title = trim((string) p('title'));
        $audience = (string) p('audience');
        $branchId = p('branch_id') !== '' ? (int) p('branch_id') : null;
        if ($title === '' || mb_strlen($title) > 160) {
            flash('danger', 'Meeting title 1–160 characters');
            redirect('/meetings.php');
        }
        if ($audience === 'branch' && !$branchId) {
            flash('danger', 'Select a branch');
            redirect('/meetings.php');
        }
        $mode = p('mode') === 'voice' ? 'voice' : 'video';
        $id = Meetings::create($title, $audience, $mode, $branchId, (int) $user['id']);
        Audit::log((int) $user['id'], 'meeting_start', 'meeting', $id, ['title' => $title, 'audience' => $audience, 'branch_id' => $branchId]);
        flash('success', $audience === 'broadcast'
            ? '📢 Broadcast shuru ho gaya – sabke phone pe ring + notification ja raha hai. Aap bhi "Join" se judein.'
            : 'Meeting shuru ho gayi – audience ko notification bhej diya. Aap bhi "Join" se judein.');
        // Answer the browser now, then fan out the ring in the background. A broadcast rings
        // every BC and every staff member, so this keeps the "Start" click snappy.
        header('Location: /meetings.php');
        http_response_code(302);
        session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();            // PHP-FPM
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();          // LiteSpeed / OpenLiteSpeed (this server)
        }
        @set_time_limit(300);
        Meetings::notifyStart($id);
        exit;
    }
    if ($action === 'end') {
        $id = (int) p('id');
        Meetings::end($id);
        Audit::log((int) $user['id'], 'meeting_end', 'meeting', $id, []);
        flash('success', 'Meeting end kar di');
        redirect('/meetings.php');
    }
}

$branches = DB::all('SELECT id, name, area FROM branches ORDER BY name');
$active = DB::all("SELECT m.*, b.name branch, u.name by_name FROM meetings m LEFT JOIN branches b ON b.id = m.branch_id LEFT JOIN users u ON u.id = m.created_by WHERE m.is_active = 1 ORDER BY m.id DESC");
$past = DB::all("SELECT m.*, b.name branch, u.name by_name FROM meetings m LEFT JOIN branches b ON b.id = m.branch_id LEFT JOIN users u ON u.id = m.created_by WHERE m.is_active = 0 ORDER BY m.id DESC LIMIT 15");
$audLabel = ['broadcast' => '📢 Broadcast (everyone)', 'all' => 'Everyone', 'bc' => 'All BCs', 'cbc' => 'All CBC supervisors', 'branch' => 'Branch'];

View::header('Meetings / मीटिंग', 'meetings');
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><i class="bi bi-camera-video"></i> <?= L('Start a video meeting', 'वीडियो मीटिंग शुरू करें') ?></div><div class="card-body">
      <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="start">
        <div class="col-12"><label class="form-label small"><?= L('Title', 'शीर्षक') ?></label><input name="title" class="form-control form-control-sm" required maxlength="160" placeholder="e.g. Monthly review / मासिक समीक्षा"></div>
        <div class="col-7"><label class="form-label small"><?= L('Who can join', 'कौन जुड़ सकता है') ?></label>
          <select name="audience" id="aud" class="form-select form-select-sm">
            <option value="broadcast">📢 Broadcast — everyone, view-only</option>
            <option value="all">Everyone (BC + CBC)</option>
            <option value="cbc">All CBC supervisors</option>
            <option value="bc">All BCs</option>
            <option value="branch">One branch's BCs</option>
          </select></div>
        <div class="col-12"><label class="form-label small"><?= L('Type', 'प्रकार') ?></label>
          <select name="mode" class="form-select form-select-sm"><option value="video">📹 Video meeting</option><option value="voice">🎙️ Voice only</option></select></div>
        <div class="col-5" id="branchWrap" style="display:none"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label>
          <select name="branch_id" class="form-select form-select-sm"><option value="">—</option>
            <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= $b['area'] ? ' (' . e($b['area']) . ')' : '' ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12"><button class="btn btn-success btn-sm"><i class="bi bi-camera-video-fill"></i> <?= L('Start & notify', 'शुरू करें और सूचित करें') ?></button></div>
      </form>
      <p class="small text-muted mt-2 mb-0">Start karte hi audience ke phone pe <b>ring + notification</b> jaata hai aur app mein "Join" banner dikhta hai. Call hamare apne server (LiveKit) par khulti hai (camera/mic allow karein).<br>
        <b>📢 Broadcast</b> = sabko (har BC + staff) ring jaata hai aur sab <b>view-only</b> judte hain; aap present karte hain. Kisi ka haath uthe (✋) to use "bolne" ki permission de sakte hain.</p>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header"><?= L('Live meetings', 'चल रही मीटिंग') ?> (<?= count($active) ?>)</div>
      <div class="list-group list-group-flush">
      <?php foreach ($active as $m):
          $url = Meetings::panelUrl((int) $m['id'], (string) $m['room'], $m['mode'] ?? 'video');
          $url = str_starts_with($url, 'http') ? $url : rtrim((string) App\Env::get('APP_URL', ''), '/') . $url; ?>
        <div class="list-group-item">
          <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <div><div class="fw-semibold"><i class="bi bi-broadcast text-success"></i> <?= e($m['title']) ?></div>
              <div class="small text-muted"><?= e($audLabel[$m['audience']] ?? $m['audience']) ?><?= $m['branch'] ? ' – ' . e($m['branch']) : '' ?> · <?= e(fmt_dt($m['created_at'])) ?><?= $m['by_name'] ? ' · ' . e($m['by_name']) : '' ?></div></div>
            <div class="d-flex gap-1">
              <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= e($url) ?>"><i class="bi bi-camera-video"></i> Join</a>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="<?= e($url) ?>"><i class="bi bi-clipboard"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('End this meeting?')"><?= Csrf::field() ?><input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> End</button></form>
            </div>
          </div>
        </div>
      <?php endforeach; if (!$active): ?><div class="list-group-item text-muted">Koi live meeting nahi. Left se nayi shuru karein.</div><?php endif; ?>
      </div></div>
    <div class="card"><div class="card-header small"><?= L('Recent (ended)', 'पिछली मीटिंग') ?></div>
      <ul class="list-group list-group-flush small" style="max-height:280px;overflow:auto">
        <?php foreach ($past as $m): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($m['title']) ?> <span class="text-muted">· <?= e($audLabel[$m['audience']] ?? $m['audience']) ?></span></span><span class="text-muted"><?= e(fmt_dt($m['created_at'])) ?></span></li><?php endforeach; ?>
        <?php if (!$past): ?><li class="list-group-item text-muted">—</li><?php endif; ?>
      </ul></div>
  </div>
</div>
<?php View::footer('<script>(function(){var a=document.getElementById("aud"),w=document.getElementById("branchWrap");function t(){w.style.display=a.value==="branch"?"block":"none";}a.addEventListener("change",t);t();})();</script>');
