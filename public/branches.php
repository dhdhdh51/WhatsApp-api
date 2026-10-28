<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Scope;
use App\View;

$user = Auth::require();

// Branches are managed only by the Region Manager (supervisor) of a region.
// The Admin manages Regions instead, so send the Admin to the Regions page.
if (($user['role'] ?? '') !== 'supervisor') {
    if (Auth::isAdmin($user)) {
        flash('info', 'Branches ab Region Manager (supervisor) manage karte hain. Admin sirf Regions banata hai. / शाखाएँ अब रीजन मैनेजर संभालते हैं।');
        redirect('/regions.php');
    }
    http_response_code(403);
    View::header('Access denied', '');
    echo '<div class="alert alert-danger">Only a Region Manager can open this page. / यह पेज केवल रीजन मैनेजर के लिए है।</div>';
    View::footer();
    exit;
}

$myRegion = Scope::region($user);                  // 0 = no region assigned yet
$rmNoRegion = $myRegion === 0;
$regionName = $myRegion ? (string) DB::val('SELECT name FROM regions WHERE id = ?', [$myRegion]) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($rmNoRegion) {
        http_response_code(403);
        exit('No region assigned');
    }
    $action = (string) p('action');
    $id = (int) p('id');
    $name = trim((string) p('name'));
    $area = trim((string) p('area'));

    // A Region Manager may only ever touch branches inside their own region.
    if ($id && !DB::val('SELECT 1 FROM branches WHERE id = ? AND region_id = ?', [$id, $myRegion])) {
        http_response_code(403);
        exit('Not your branch');
    }

    if ($action === 'delete') {
        $n = (int) DB::val('SELECT COUNT(*) FROM bcs WHERE branch_id = ?', [$id]);
        if ($n > 0) {
            flash('danger', "Is branch me {$n} BC hai — pehle unhe hataayein/badlein. / पहले बीसी हटाएँ");
        } else {
            DB::exec('DELETE FROM branches WHERE id = ?', [$id]);
            Audit::log((int) $user['id'], 'branch_delete', 'branch', $id);
            flash('success', 'Branch deleted / शाखा हटाई');
        }
    } elseif ($name === '' || mb_strlen($name) > 120) {
        flash('danger', 'Branch name required / शाखा का नाम आवश्यक');
    } elseif (DB::val('SELECT COUNT(*) FROM branches WHERE name = ? AND id <> ?', [$name, $id])) {
        flash('danger', 'Branch name already exists / यह शाखा पहले से है');
    } else {
        if ($id) {
            DB::exec('UPDATE branches SET name = ?, area = ?, region_id = ? WHERE id = ?', [$name, $area ?: null, $myRegion, $id]);
        } else {
            $id = DB::insert('INSERT INTO branches (name, area, region_id) VALUES (?, ?, ?)', [$name, $area ?: null, $myRegion]);
        }
        Audit::log((int) $user['id'], 'branch_save', 'branch', $id, ['name' => $name, 'area' => $area, 'region_id' => $myRegion]);
        flash('success', 'Branch saved / शाखा सेव हुई');
    }
    redirect('/branches.php');
}

View::header('Branches · ' . $regionName, 'branches');

if ($rmNoRegion):
    ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    <?= L('You have not been assigned a region yet. Please ask the Admin (Shivam) to assign your region — then you can add your branches here.',
          'आपको अभी कोई रीजन नहीं दिया गया है। कृपया एडमिन (शिवम) से अपना रीजन assign करवाएँ — उसके बाद आप यहाँ अपनी शाखाएँ जोड़ पाएँगे।') ?>
  </div>
<?php
    View::footer();
    exit;
endif;

$rows = DB::all('SELECT br.*,
    (SELECT COUNT(*) FROM bcs b WHERE b.branch_id = br.id AND b.is_active = 1) AS bcs
    FROM branches br WHERE br.region_id = ? ORDER BY br.name', [$myRegion]);
?>
<div class="alert alert-info py-2 small">
  <i class="bi bi-diagram-3"></i>
  <?= L('You are the Region Manager of', 'आप इस रीजन के रीजन मैनेजर हैं:') ?> <b><?= e($regionName) ?></b>.
  <?= L('Add and manage the branches of your region here.', 'अपने रीजन की शाखाएँ यहाँ जोड़ें और संभालें।') ?>
</div>
<div class="card mb-3"><div class="card-header"><i class="bi bi-building"></i> <?= L('Add branch to', 'शाखा जोड़ें') ?> <?= e($regionName) ?></div><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?>
    <div class="col-md-5"><label class="form-label small"><?= L('Branch name', 'शाखा का नाम') ?></label><input name="name" class="form-control form-control-sm" required maxlength="120"></div>
    <div class="col-md-4"><label class="form-label small"><?= L('Area', 'क्षेत्र') ?></label><input name="area" class="form-control form-control-sm" maxlength="120"></div>
    <div class="col-md-3"><button class="btn btn-sm btn-success w-100"><?= L('Add', 'जोड़ें') ?></button></div>
  </form>
</div></div>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr><th><?= L('Branch', 'शाखा') ?></th><th><?= L('Area', 'क्षेत्र') ?></th><th>Active BCs</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
  <tr><td colspan="4">
    <form method="post" class="row g-2 align-items-center"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="col-md-4"><input name="name" value="<?= e($r['name']) ?>" class="form-control form-control-sm" required></div>
      <div class="col-md-4"><input name="area" value="<?= e($r['area']) ?>" class="form-control form-control-sm" placeholder="area"></div>
      <div class="col-md-1 small text-center"><?= (int) $r['bcs'] ?></div>
      <div class="col-md-3 text-nowrap"><button name="action" value="save" class="btn btn-sm btn-outline-success">Save</button>
        <button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete branch?')"><i class="bi bi-trash"></i></button></div>
    </form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="text-muted"><?= L('No branches yet — add your first branch above.', 'अभी कोई शाखा नहीं — ऊपर से पहली शाखा जोड़ें।') ?></td></tr><?php endif; ?></tbody>
</table></div>
<?php View::footer();
