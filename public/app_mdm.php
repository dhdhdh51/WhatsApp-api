<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\DB;
use App\View;

/**
 * Admin-only enrollment page for employer-owned / consented phones.
 * Android Device Owner enrollment is the only supported way to prevent a normal uninstall.
 */
$user = Auth::requireAdmin();
$downloads = __DIR__ . '/downloads';
$configFile = $downloads . '/mdm-provisioning.json';
$config = is_file($configFile) ? (string) file_get_contents($configFile) : '';
$qrExists = is_file($downloads . '/mdm-provisioning.png');
$apk = is_file($downloads . '/bc-report.apk');
$rows = DB::all("SELECT b.id, b.name, b.bc_code, b.phone, b.app_last_seen,
    (SELECT COUNT(*) FROM app_tokens t WHERE t.bc_id = b.id) app_logins,
    (SELECT MAX(t.mdm_owner) FROM app_tokens t WHERE t.bc_id = b.id) mdm_owner
    FROM bcs b WHERE b.is_active = 1 AND b.app_last_seen IS NOT NULL
    ORDER BY (mdm_owner = 1) DESC, b.app_last_seen DESC LIMIT 300");

View::header('Managed phones (MDM) / मैनेज्ड फ़ोन', 'mdm');
?>
<div class="alert alert-warning small">
  <strong>Consent + company phone required.</strong> "App uninstall block" works only after Android <strong>Device Owner</strong> enrollment on an organisation-managed phone.
  A normal installed app cannot legally or technically stop a user from uninstalling it. Device Owner enrollment needs a <strong>factory-reset phone</strong> (or an IT technician using ADB before the phone is handed over).
  <br><strong>लिखित सहमति और कंपनी का फ़ोन ज़रूरी है।</strong> सामान्य फोन पर ऐप हटाना नहीं रोका जा सकता; Device Owner enrollment में फैक्टरी रीसेट लगता है।
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header">1. QR enrollment / QR से enrollment</div><div class="card-body">
      <?php if ($qrExists): ?>
        <div class="text-center"><img src="/downloads/mdm-provisioning.png" alt="MDM enrollment QR" class="img-fluid border rounded" style="max-width:300px">
          <p class="small text-muted mt-2">This QR contains only the app download URL, package name and APK checksum – no password or Firebase key.</p></div>
      <?php else: ?>
        <div class="alert alert-danger">Provisioning QR has not been generated. Rebuild the signed APK / contact Admin.</div>
      <?php endif; ?>
      <ol class="small mb-0">
        <li>Back up any permitted data, then <strong>factory reset</strong> the company phone.</li>
        <li>At Android's first Welcome screen, follow that phone's Android Enterprise QR setup method (usually tap the Welcome screen six times), then scan this QR.</li>
        <li>Connect Wi-Fi when asked. Android downloads BC Report, installs it as <strong>Device Owner</strong>, and normal uninstall is blocked.</li>
        <li>Open the app, login with the BC's phone/password, and give the normal notification permission.</li>
      </ol>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header">2. IT / ADB enrollment (test device)</div><div class="card-body small">
      <p>Only on a fresh/reset phone with no personal accounts. Enable USB debugging, connect it to the IT computer, then run:</p>
      <pre class="bg-light border rounded p-2 small mb-2">adb install -r bc-report.apk
adb shell dpm set-device-owner site.bharatseo.bcreport/.MdmAdminReceiver</pre>
      <p class="mb-0 text-muted">After this, BC Report becomes Device Owner and blocks normal uninstall. Removing Device Owner requires authorized organization action / factory reset.</p>
    </div></div>
    <div class="card mt-3"><div class="card-header">Provisioning config</div><div class="card-body">
      <?php if ($config): ?><details><summary class="small">Show QR JSON / JSON देखें</summary><pre class="small mb-0 mt-2 wa-pre"><?= e($config) ?></pre></details><?php endif; ?>
      <a class="btn btn-sm btn-outline-success mt-2" href="/downloads/bc-report.apk"><i class="bi bi-download"></i> Download current signed APK</a>
    </div></div>
  </div>
</div>

<div class="card mt-3"><div class="card-header">Enrolled app devices / ऐप वाले फोन</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>BC</th><th>BC ID</th><th>Phone</th><th>Last app use</th><th>Device Owner / uninstall block</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td><?= e($r['name']) ?></td><td><?= e($r['bc_code']) ?></td><td><?= e(App\Phone::display($r['phone'])) ?></td>
      <td><?= e(fmt_dt($r['app_last_seen'])) ?></td>
      <td><?= (int) $r['mdm_owner'] ? '<span class="badge text-bg-success">✅ Device Owner – blocked</span>' : '<span class="badge text-bg-secondary">Normal app – removable</span>' ?></td>
    </tr><?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-muted">No app login yet</td></tr><?php endif; ?></tbody>
  </table></div>
</div>
<?php View::footer();
