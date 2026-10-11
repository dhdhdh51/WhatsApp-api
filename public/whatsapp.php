<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\BotHandler;
use App\Csrf;
use App\Env;
use App\Queue;
use App\Settings;
use App\View;
use App\WahaClient;

$user = Auth::requireAdmin();
$waha = new WahaClient();

if (q('ajax') === 'status') {
    $info = $waha->sessionInfo();
    if ($info['ok'] && $info['status'] !== Settings::get('wa_status')) {
        BotHandler::updateStatus($info['status']);
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => $info['status'], 'error' => $info['error'], 'me' => $info['me']]);
    exit;
}
if (q('ajax') === 'qr') {
    $qr = $waha->qr();
    if (!$qr || !in_array($qr['mimetype'], ['image/png', 'image/jpeg'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $qr['mimetype']);
    echo base64_decode($qr['data']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');
    $res = ['ok' => true, 'error' => ''];
    switch ($action) {
        case 'ensure':
            $res = $waha->ensureSession();
            break;
        case 'restart':
        case 'stop':
        case 'logout':
            $res = $waha->sessionAction($action);
            break;
        case 'pair_code':
            $phone = App\Phone::normalize((string) p('pair_phone'));
            if (!$phone) {
                $res = ['ok' => false, 'error' => 'Invalid Indian mobile number / गलत मोबाइल नंबर'];
                break;
            }
            $pc = $waha->requestPairingCode($phone);
            if ($pc['ok']) {
                $_SESSION['pair_code'] = ['code' => $pc['code'], 'phone' => $phone, 'at' => time()];
            }
            $res = ['ok' => $pc['ok'], 'error' => $pc['error']];
            break;
        case 'pause':
            Settings::set('queue_paused', '1');
            break;
        case 'resume':
            Settings::set('queue_paused', '0');
            break;
        default:
            $res = ['ok' => false, 'error' => 'unknown action'];
    }
    Audit::log((int) $user['id'], 'whatsapp_' . $action, 'whatsapp', null, ['ok' => $res['ok'], 'error' => $res['error']]);
    flash($res['ok'] ? 'success' : 'danger', $res['ok'] ? "Done: {$action}" : "WAHA error: {$res['error']}");
    redirect('/whatsapp.php');
}

$info = $waha->sessionInfo();
if ($info['ok'] && $info['status'] !== Settings::get('wa_status')) {
    BotHandler::updateStatus($info['status']);
}
$stats = Queue::stats();

View::header('WhatsApp connection / व्हाट्सऐप कनेक्शन', 'whatsapp');
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center">
        <div><div class="small text-muted">Session: <?= e($waha->session()) ?></div>
          <div class="fs-4 fw-bold" id="waStatus"><?= e($info['status']) ?></div>
          <div class="small" id="waMe"><?= $info['me'] ? e(($info['me']['pushName'] ?? '') . ' · ' . ($info['me']['id'] ?? '')) : '' ?></div>
          <?php if ($info['error']): ?><div class="small text-danger"><?= e($info['error']) ?></div><?php endif; ?></div>
        <i class="bi bi-whatsapp text-success" style="font-size:2.5rem"></i>
      </div>
      <div id="qrBox" class="text-center my-3 <?= $info['status'] === 'SCAN_QR_CODE' ? '' : 'd-none' ?>">
        <img id="qrImg" alt="QR" class="img-fluid border rounded" style="max-width:280px" src="<?= $info['status'] === 'SCAN_QR_CODE' ? '/whatsapp.php?ajax=qr&t=' . time() : '' ?>">
        <div class="small text-muted mt-1">WhatsApp → Settings → Linked devices → Link a device. QR refreshes automatically.<br>व्हाट्सऐप → लिंक्ड डिवाइस → डिवाइस लिंक करें</div>
      </div>
      <?php $pair = $_SESSION['pair_code'] ?? null; if ($pair && time() - $pair['at'] > 180) { unset($_SESSION['pair_code']); $pair = null; } ?>
      <?php if ($info['status'] === 'SCAN_QR_CODE' || $pair): ?>
      <div class="border rounded p-2 mb-3 bg-light">
        <div class="fw-semibold small mb-1"><i class="bi bi-phone"></i> <?= L('No scanner? Link with phone number', 'QR स्कैन नहीं हो रहा? फ़ोन नंबर से लिंक करें') ?></div>
        <?php if ($pair): ?>
          <div class="text-center my-2"><div class="small text-muted">Code for +91 <?= e($pair['phone']) ?></div>
            <div class="display-6 fw-bold font-monospace text-success"><?= e($pair['code']) ?></div></div>
          <ol class="small mb-2">
            <li>WhatsApp → ⋮ / Settings → <b>Linked devices</b> → <b>Link a device</b></li>
            <li>Tap <b>"Link with phone number instead"</b> / "फ़ोन नंबर से लिंक करें"</li>
            <li>Enter the code above (valid ~2-3 minutes) / ऊपर वाला कोड डालें</li>
          </ol>
        <?php endif; ?>
        <form method="post" class="d-flex gap-2"><?= Csrf::field() ?>
          <div class="input-group input-group-sm"><span class="input-group-text">+91</span>
            <input name="pair_phone" class="form-control" placeholder="WhatsApp number of this phone" inputmode="tel" maxlength="16" required></div>
          <button name="action" value="pair_code" class="btn btn-sm btn-primary text-nowrap"><?= L('Get code', 'कोड पाएँ') ?></button>
        </form>
      </div>
      <?php endif; ?>
      <form method="post" class="d-flex flex-wrap gap-2"><?= Csrf::field() ?>
        <button name="action" value="ensure" class="btn btn-success btn-sm"><i class="bi bi-play-circle"></i> <?= L('Create / start session', 'सत्र शुरू करें') ?></button>
        <button name="action" value="restart" class="btn btn-warning btn-sm" onclick="return confirm('Restart the WhatsApp session?')"><i class="bi bi-arrow-clockwise"></i> <?= L('Restart session', 'सत्र रीस्टार्ट') ?></button>
        <button name="action" value="stop" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Stop the session?')"><i class="bi bi-stop-circle"></i> Stop</button>
        <button name="action" value="logout" class="btn btn-outline-danger btn-sm" onclick="return confirm('Logout will unlink this WhatsApp number. You will need to scan the QR again. Continue?')"><i class="bi bi-box-arrow-right"></i> Logout / unlink</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header"><?= L('Send queue', 'भेजने की कतार') ?></div><div class="card-body">
      <div class="row text-center">
        <div class="col"><div class="fs-4 fw-bold"><?= $stats['pending'] ?></div><div class="small text-muted">pending</div></div>
        <div class="col"><div class="fs-4 fw-bold"><?= $stats['sent_today'] ?></div><div class="small text-muted">sent today</div></div>
        <div class="col"><div class="fs-4 fw-bold"><?= Settings::effectiveDailyLimit() ?></div><div class="small text-muted">daily limit<?= Settings::isWarmup() ? ' (warm-up)' : '' ?></div></div>
        <div class="col"><div class="fs-4 fw-bold text-danger"><?= $stats['failed_today'] ?></div><div class="small text-muted">failed today</div></div>
      </div>
      <hr>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if (Settings::bool('queue_paused')): ?>
          <span class="badge text-bg-dark">Paused manually</span>
          <form method="post"><?= Csrf::field() ?><button name="action" value="resume" class="btn btn-sm btn-success"><i class="bi bi-play"></i> <?= L('Resume queue', 'कतार चालू') ?></button></form>
        <?php else: ?>
          <span class="badge text-bg-success">Running</span>
          <form method="post"><?= Csrf::field() ?><button name="action" value="pause" class="btn btn-sm btn-outline-dark"><i class="bi bi-pause"></i> <?= L('Pause queue', 'कतार रोकें') ?></button></form>
        <?php endif; ?>
        <?php if (Settings::get('queue_pause_reason')): ?><span class="badge text-bg-warning">Auto-paused: <?= e(Settings::get('queue_pause_reason')) ?></span><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="/messages.php?tab=queue"><?= L('Open queue', 'कतार देखें') ?></a>
      </div>
      <p class="small text-muted mt-2 mb-0">Messages go out one at a time with a random <?= Settings::int('min_delay_sec') ?>–<?= Settings::int('max_delay_sec') ?> s gap and a typing indicator.
        If WhatsApp disconnects the queue pauses automatically and resumes after reconnect.<?= Settings::get('warmup_start_date') ? ' Warm-up started ' . e(fmt_date(Settings::get('warmup_start_date'))) . '.' : '' ?></p>
    </div></div>
    <div class="card"><div class="card-header">Webhook</div><div class="card-body small">
      <div>URL: <code><?= e(rtrim((string) Env::get('APP_URL'), '/')) ?>/webhook.php</code></div>
      <div>Events: <code>message, session.status</code> · Auth: HMAC-SHA512 (<code>WEBHOOK_SECRET</code>)</div>
      <div class="text-muted">Configured in <code>docker-compose.yml</code> via <code>WHATSAPP_HOOK_*</code> env vars<?= Env::bool('WAHA_SESSION_WEBHOOK') ? ' and in the session config (WAHA_SESSION_WEBHOOK=1)' : '' ?>.
        Last status update: <?= e(fmt_dt(Settings::get('wa_status_at'))) ?></div>
    </div></div>
  </div>
</div>
<?php
View::footer('<script>
(function(){
  const st=document.getElementById("waStatus"), box=document.getElementById("qrBox"), img=document.getElementById("qrImg"), me=document.getElementById("waMe");
  async function tick(){
    try{
      const r=await fetch("/whatsapp.php?ajax=status",{credentials:"same-origin"}); const j=await r.json();
      st.textContent=j.status;
      if(j.me){me.textContent=(j.me.pushName||"")+" · "+(j.me.id||"");}
      if(j.status==="SCAN_QR_CODE"){box.classList.remove("d-none"); img.src="/whatsapp.php?ajax=qr&t="+Date.now();}
      else{box.classList.add("d-none");}
    }catch(e){}
  }
  setInterval(tick, 8000);
})();
</script>');
