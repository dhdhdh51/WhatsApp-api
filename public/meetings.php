<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\GuestLinks;
use App\Meetings;
use App\Scope;
use App\View;

/** Start / end broadcasts. Admin = org-wide; a BC Supervisor = only their own region. */
$user = Auth::require();
$isAdmin = Auth::isAdmin($user);
$isRM = ($user['role'] ?? '') === 'supervisor';
if (!$isAdmin && !$isRM) {
    http_response_code(403);
    View::header('Access denied', 'meetings');
    echo '<div class="alert alert-danger">Only Admin or a BC Supervisor can open this page. / यह पेज केवल एडमिन या बीसी सुपरवाइज़र के लिए है।</div>';
    View::footer();
    exit;
}
$myRegion = Scope::region($user);                 // 0 for admin / region-less supervisor
$regionName = $myRegion ? (string) DB::val('SELECT name FROM regions WHERE id = ?', [$myRegion]) : '';
$rmScoped = $isRM && $regionName !== '';
// The only things this page starts: Broadcast 1 ('broadcast'), Broadcast 2 ('channel', TV-channel stage)
// and Broadcast 3 ('guest', via link, rings nobody). Older meeting types still show in the lists.
$roLabel = $rmScoped ? $regionName . ', ' : '';
$startTypes = [
    'broadcast' => '📢 Broadcast — ' . $roLabel . 'sabka video',
    'channel'   => '📺 Broadcast 2 — ' . $roLabel . 'sabka video (TV मंच)',
    'guest'     => '🔗 Broadcast 3 — Other via link, sabka video (BC/CBC ko call nahi)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');
    if ($action === 'start') {
        $title = trim((string) p('title'));
        $audience = (string) p('audience');
        $regionId = $isRM ? ($myRegion ?: null) : null;   // a BC Supervisor's meeting is pinned to their region
        if ($title === '' || mb_strlen($title) > 160) {
            flash('danger', 'Meeting title 1–160 characters');
            redirect('/meetings.php');
        }
        // the panel starts only the three broadcasts (other meeting types were removed from this page)
        if (!isset($startTypes[$audience])) {
            flash('danger', 'Broadcast 1, 2 ya 3 chunein / ब्रॉडकास्ट 1, 2 या 3 चुनें');
            redirect('/meetings.php');
        }
        $mode = p('mode') === 'voice' ? 'voice' : 'video';
        $id = Meetings::create($title, $audience, $mode, null, (int) $user['id'], $regionId);
        Audit::log((int) $user['id'], 'meeting_start', 'meeting', $id, ['title' => $title, 'audience' => $audience, 'region_id' => $regionId]);
        if ($audience === 'guest') {
            // Broadcast 3: nobody is rung; the first guest link is ready at once (shown once below the meeting)
            $_SESSION['guest_link_new'] = ['meeting_id' => $id, 'url' => GuestLinks::create($id, (int) $user['id'])];
            Audit::log((int) $user['id'], 'meeting_guest_link', 'meeting', $id);
            flash('success', '🔗 Broadcast 3 shuru ho gaya – kisi BC/CBC ko call nahi gayi. Neeche ka link copy karke bhejein, aur aap "Join" se judein.');
            redirect('/meetings.php');
        }
        flash('success', ($audience === 'channel' ? '📺 Broadcast 2' : '📢 Broadcast') . ' shuru ho gaya – '
            . ($isRM && $regionName ? $regionName . ' ke' : 'sabke') . ' phone pe ring + notification ja raha hai. Aap bhi "Join" se judein.');
        // Answer the browser now, then fan out the ring in the background.
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
    if ($action === 'guest_link' || $action === 'guest_revoke') {
        // guest link for an outside officer: whoever may moderate this live meeting (Admin, its BC Supervisor, its host)
        $gm = DB::one('SELECT * FROM meetings WHERE id = ? AND is_active = 1', [(int) p('id')]);
        if (!$gm || !Meetings::canModerate($gm, $user)) {
            http_response_code(403);
            exit('Not allowed');
        }
        if ($action === 'guest_link') {
            $_SESSION['guest_link_new'] = ['meeting_id' => (int) $gm['id'], 'url' => GuestLinks::create((int) $gm['id'], (int) $user['id'])];
            Audit::log((int) $user['id'], 'meeting_guest_link', 'meeting', (int) $gm['id']);
            flash('success', 'Guest link ban gaya – neeche copy karke officer ko bhejein. Meeting khatam hote hi ye link band ho jayega.');
        } else {
            $n = GuestLinks::revoke((int) p('link_id'), $gm);
            Audit::log((int) $user['id'], 'meeting_guest_revoke', 'meeting', (int) $gm['id'], ['link_id' => (int) p('link_id'), 'removed' => $n]);
            flash('success', 'Guest link band kar diya' . ($n ? " – {$n} guest meeting se hata diye gaye" : ''));
        }
        redirect('/meetings.php');
    }
    if ($action === 'end') {
        $id = (int) p('id');
        if ($isRM && !DB::val('SELECT 1 FROM meetings WHERE id = ? AND region_id = ?', [$id, $myRegion])) {
            http_response_code(403);
            exit('Not allowed');          // a BC Supervisor can only end their own region's meeting
        }
        Meetings::end($id);
        Audit::log((int) $user['id'], 'meeting_end', 'meeting', $id, []);
        flash('success', 'Meeting end kar di');
        redirect('/meetings.php');
    }
}

if ($isAdmin) {
    $active = DB::all("SELECT m.*, b.name branch, u.name by_name, rg.name region_name FROM meetings m LEFT JOIN branches b ON b.id = m.branch_id LEFT JOIN users u ON u.id = m.created_by LEFT JOIN regions rg ON rg.id = m.region_id WHERE m.is_active = 1 ORDER BY m.id DESC");
    $past = DB::all("SELECT m.*, b.name branch, u.name by_name, rg.name region_name FROM meetings m LEFT JOIN branches b ON b.id = m.branch_id LEFT JOIN users u ON u.id = m.created_by LEFT JOIN regions rg ON rg.id = m.region_id WHERE m.is_active = 0 ORDER BY m.id DESC LIMIT 15");
} else {
    // BC Supervisor: their own region's meetings, plus any org-wide (Admin) meeting they may just view.
    $active = DB::all("SELECT m.*, b.name branch, u.name by_name, rg.name region_name FROM meetings m LEFT JOIN branches b ON b.id = m.branch_id LEFT JOIN users u ON u.id = m.created_by LEFT JOIN regions rg ON rg.id = m.region_id WHERE m.is_active = 1 AND (m.region_id = ? OR m.region_id IS NULL) ORDER BY m.id DESC", [$myRegion]);
    $past = DB::all("SELECT m.*, b.name branch, u.name by_name, rg.name region_name FROM meetings m LEFT JOIN branches b ON b.id = m.branch_id LEFT JOIN users u ON u.id = m.created_by LEFT JOIN regions rg ON rg.id = m.region_id WHERE m.is_active = 0 AND (m.region_id = ? OR m.region_id IS NULL) ORDER BY m.id DESC LIMIT 15", [$myRegion]);
}
$newGuest = $_SESSION['guest_link_new'] ?? null;   // the new link is shown once, then only its hash exists
unset($_SESSION['guest_link_new']);
$audLabel = ['broadcast' => '📢 Broadcast', 'channel' => '📺 Broadcast 2', 'guest' => '🔗 Broadcast 3 (link)',
    'all' => 'Everyone', 'bc' => 'All BCs', 'cbc' => 'All CBC supervisors', 'branch' => 'Branch'];

View::header('Meetings / मीटिंग', 'meetings');
?>
<?php if ($isRM): ?>
<div class="alert alert-info py-2 small"><i class="bi bi-diagram-3"></i>
  <?= $rmScoped
      ? L('Your broadcasts & meetings reach only your region: ', 'आपके ब्रॉडकास्ट/मीटिंग सिर्फ आपके रीजन तक जाते हैं: ') . '<b>' . e($regionName) . '</b>'
      : L('No region assigned yet — please ask the Admin to set your region.', 'अभी कोई रीजन असाइन नहीं है — कृपया एडमिन से अपना रीजन सेट करवाएँ।') ?>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><i class="bi bi-broadcast"></i> <?= L('Start a broadcast', 'ब्रॉडकास्ट शुरू करें') ?></div><div class="card-body">
      <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="start">
        <div class="col-12"><label class="form-label small"><?= L('Title', 'शीर्षक') ?></label><input name="title" class="form-control form-control-sm" required maxlength="160" placeholder="e.g. Monthly review / मासिक समीक्षा"></div>
        <div class="col-12"><label class="form-label small"><?= L('Which broadcast', 'कौन सा ब्रॉडकास्ट') ?></label>
          <select name="audience" class="form-select form-select-sm">
            <?php foreach ($startTypes as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12"><label class="form-label small"><?= L('Type', 'प्रकार') ?></label>
          <select name="mode" class="form-select form-select-sm"><option value="video">📹 Video meeting</option><option value="voice">🎙️ Voice only</option></select></div>
        <div class="col-12"><button class="btn btn-success btn-sm"><i class="bi bi-broadcast"></i> <?= L('Start broadcast', 'ब्रॉडकास्ट शुरू करें') ?></button></div>
      </form>
      <p class="small text-muted mt-2 mb-0">Teeno video call jaise hain: aap bade screen (मंच) par, aur <b>sabka camera</b> niche dikhta hai. Mic sirf aapka; kisi ka haath uthe (✋) to "🎤 Allow" se use bolne dein. Call hamare apne server (LiveKit) par khulti hai (camera/mic allow karein).<br>
        <b>📢 Broadcast</b> = <?= $rmScoped ? e($regionName) . ' ke' : 'har' ?> BC + staff ke phone pe <b>ring + notification</b>, aur app mein "Join" banner.<br>
        <b>📺 Broadcast 2</b> = Broadcast jaisa hi (ring sabko), lekin मंच TV news channel jaisa: bank logo, स्वागत संदेश, APY / PMJJBY / PMSBY ke card aur सूचना पट्टी.<br>
        <b>🔗 Broadcast 3</b> = kisi BC/CBC ko call nahi jaati. Start karte hi ek link banta hai; jise link bhejenge wahi apna naam likh kar judega aur uska camera chalu rahega. Zarurat ho to "Guest link" se aur link banayein.</p>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header"><?= L('Live meetings', 'चल रही मीटिंग') ?> (<?= count($active) ?>)</div>
      <div class="list-group list-group-flush">
      <?php foreach ($active as $m):
          $url = Meetings::panelUrl((int) $m['id'], (string) $m['room'], $m['mode'] ?? 'video');
          $url = str_starts_with($url, 'http') ? $url : rtrim((string) App\Env::get('APP_URL', ''), '/') . $url;
          $canEnd = $isAdmin || (int) ($m['region_id'] ?? 0) === $myRegion;
          $canGuest = Meetings::canModerate($m, $user); ?>
        <div class="list-group-item">
          <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <div><div class="fw-semibold"><i class="bi bi-broadcast text-success"></i> <?= e($m['title']) ?><?= (int) ($m['region_id'] ?? 0) === 0 && $isRM ? ' <span class="badge text-bg-secondary">org-wide</span>' : '' ?></div>
              <div class="small text-muted"><?= $m['region_name'] ? '<span class="badge text-bg-primary">' . e($m['region_name']) . '</span> ' : '' ?><?= e($audLabel[$m['audience']] ?? $m['audience']) ?><?= $m['branch'] ? ' – ' . e($m['branch']) : '' ?> · <?= e(fmt_dt($m['created_at'])) ?><?= $m['by_name'] ? ' · ' . e($m['by_name']) : '' ?></div></div>
            <div class="d-flex gap-1">
              <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="<?= e($url) ?>"><i class="bi bi-camera-video"></i> Join</a>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="<?= e($url) ?>"><i class="bi bi-clipboard"></i></button>
              <?php if ($canGuest): ?><form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="guest_link"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm btn-outline-primary" title="<?= e(L('Link for an outside officer (no login)', 'बाहरी अधिकारी के लिए लिंक (बिना लॉगिन)')) ?>"><i class="bi bi-person-plus"></i> Guest link</button></form><?php endif; ?>
              <?php if ($canEnd): ?><form method="post" class="d-inline" onsubmit="return confirm('End this meeting?')"><?= Csrf::field() ?><input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> End</button></form><?php endif; ?>
            </div>
          </div>
          <?php if ($canGuest && $newGuest && (int) $newGuest['meeting_id'] === (int) $m['id']):
              $wa = 'https://wa.me/?text=' . rawurlencode("नमस्ते,\nआपको \"{$m['title']}\" मीटिंग में शामिल होने के लिए आमंत्रित किया गया है। नीचे दिए लिंक पर अपना नाम लिखकर जुड़ें:\n{$newGuest['url']}\n\nयह लिंक मीटिंग समाप्त होने पर बंद हो जाएगा।"); ?>
            <div class="alert alert-primary small mt-2 mb-0 p-2">
              <div class="fw-semibold mb-1"><i class="bi bi-link-45deg"></i> <?= L('New guest link (shown once – copy it now)', 'नया गेस्ट लिंक (सिर्फ़ एक बार दिखेगा – अभी कॉपी करें)') ?></div>
              <div class="input-group input-group-sm"><input class="form-control" readonly value="<?= e($newGuest['url']) ?>" onclick="this.select()">
                <button type="button" class="btn btn-outline-secondary" data-copy="<?= e($newGuest['url']) ?>"><i class="bi bi-clipboard"></i> Copy</button>
                <a class="btn btn-success" target="_blank" rel="noopener" href="<?= e($wa) ?>"><i class="bi bi-whatsapp"></i> WhatsApp</a></div>
              <div class="text-muted mt-1"><?= Meetings::isGuestBroadcast($m)
                  ? 'Link kholne wala naam likh kar judega aur uska camera chalu rahega. Bolne ke liye ✋ par <b>🎤 Allow</b>, ya naam par tap karke <b>⭐ मंच पर लाएँ</b>.'
                  : 'Officer naam likh kar judega, pehle sirf dekh-sun sakega. Mic/camera ke liye meeting screen mein uske naam par tap karke <b>⭐ मंच पर लाएँ</b>.' ?></div>
            </div>
          <?php endif; ?>
          <?php if ($canGuest): foreach (GuestLinks::forMeeting((int) $m['id']) as $gl): ?>
            <div class="d-flex justify-content-between align-items-center gap-2 small border-top mt-2 pt-1">
              <span><i class="bi bi-person-badge"></i> Guest link #<?= (int) $gl['id'] ?> · <?= e(fmt_dt($gl['created_at'])) ?><?= $gl['by_name'] ? ' · ' . e($gl['by_name']) : '' ?>
                · <?= $gl['guests'] ? e(count($gl['guests']) . ' jude: ' . implode(', ', array_slice($gl['guests'], 0, 6))) : '<span class="text-muted">abhi koi nahi juda</span>' ?></span>
              <form method="post" class="d-inline" onsubmit="return confirm('Ye guest link band karein? Is link se jude log meeting se hat jayenge.')"><?= Csrf::field() ?><input type="hidden" name="action" value="guest_revoke"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="link_id" value="<?= (int) $gl['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-slash-circle"></i> Band karein</button></form>
            </div>
          <?php endforeach; endif; ?>
        </div>
      <?php endforeach; if (!$active): ?><div class="list-group-item text-muted">Koi live meeting nahi. Left se nayi shuru karein.</div><?php endif; ?>
      </div></div>
    <div class="card"><div class="card-header small"><?= L('Recent (ended)', 'पिछली मीटिंग') ?></div>
      <ul class="list-group list-group-flush small" style="max-height:280px;overflow:auto">
        <?php foreach ($past as $m): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($m['title']) ?> <?= $m['region_name'] ? '<span class="badge text-bg-light border">' . e($m['region_name']) . '</span>' : '' ?> <span class="text-muted">· <?= e($audLabel[$m['audience']] ?? $m['audience']) ?></span></span><span class="text-muted"><?= e(fmt_dt($m['created_at'])) ?></span></li><?php endforeach; ?>
        <?php if (!$past): ?><li class="list-group-item text-muted">—</li><?php endif; ?>
      </ul></div>
  </div>
</div>
<?php View::footer();
