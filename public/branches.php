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
$isAdmin = Auth::isAdmin($user);
$isRM = ($user['role'] ?? '') === 'supervisor';   // Region Manager
$myRegion = Scope::region($user);                  // 0 for admin / region-less supervisor

// Only the Admin (superadmin) and a Region Manager may open this page.
if (!$isAdmin && !$isRM) {
    http_response_code(403);
    View::header('Access denied', '');
    echo '<div class="alert alert-danger">Only Admin or a Region Manager can open this page. / यह पेज केवल एडमिन या रीजन मैनेजर के लिए है।</div>';
    View::footer();
    exit;
}

// A Region Manager who has no region yet cannot add branches.
$rmNoRegion = $isRM && $myRegion === 0;
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

    // Which region does this branch belong to?
    if ($isAdmin) {
        $regionId = p('region_id') !== '' ? (int) p('region_id') : null;
        if ($regionId !== null && !DB::val('SELECT 1 FROM regions WHERE id = ?', [$regionId])) {
            $regionId = null;
        }
    } else {
        $regionId = $myRegion;   // a Region Manager can only ever touch their own region
    }

    // When editing/deleting, a Region Manager may only act on a branch inside their own region.
    if ($id && !$isAdmin && !DB::val('SELECT 1 FROM branches WHERE id = ? AND region_id = ?', [$id, $myRegion])) {
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
    } elseif (!$regionId) {
        // Admin may leave it blank only if they picked none; a Region Manager always has one.
        flash('danger', $isAdmin ? 'Pick a region / रीजन चुनें' : 'No region assigned / कोई रीजन नहीं');
    } else {
        if ($id) {
            DB::exec('UPDATE branches SET name = ?, area = ?, region_id = ? WHERE id = ?', [$name, $area ?: null, $regionId, $id]);
        } else {
            $id = DB::insert('INSERT INTO branches (name, area, region_id) VALUES (?, ?, ?)', [$name, $area ?: null, $regionId]);
        }
        Audit::log((int) $user['id'], 'branch_save', 'branch', $id, ['name' => $name, 'area' => $area, 'region_id' => $regionId]);
        flash('success', 'Branch saved / शाखा सेव हुई');
    }
    redirect('/branches.php');
}

View::header($isAdmin ? 'Branches / शाखाएँ' : ('Branches · ' . $regionName), 'branches');

// ---- Region Manager with no region: nothing to manage yet ----
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

if ($isAdmin):
    // ---------------- Admin (superadmin): all branches across every region ----------------
    $regions = DB::all('SELECT id, name FROM regions ORDER BY name');
    $rows = DB::all('SELECT br.*, rg.name AS region_name,
        (SELECT COUNT(*) FROM bcs b WHERE b.branch_id = br.id AND b.is_active = 1) AS bcs
        FROM branches br LEFT JOIN regions rg ON rg.id = br.region_id
        ORDER BY rg.name IS NULL, rg.name, br.name');
    $regOpts = static function ($sel) use ($regions): string {
        $h = '<option value="">— no region —</option>';
        foreach ($regions as $rg) {
            $h .= '<option value="' . (int) $rg['id'] . '"' . ((int) $sel === (int) $rg['id'] ? ' selected' : '') . '>' . e($rg['name']) . '</option>';
        }
        return $h;
    };
    ?>
  <div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle"></i>
    <?= L('Regions are created on the', 'रीजन बनाने के लिए') ?> <a href="/regions.php"><?= L('Regions page', 'Regions पेज') ?></a>.
    <?= L('Normally each Region Manager adds the branches inside their own region. As Admin you can view and fix any branch here.',
          'आम तौर पर हर रीजन मैनेजर अपने रीजन की शाखाएँ खुद जोड़ता है। एडमिन होने के नाते आप यहाँ किसी भी शाखा को देख/ठीक कर सकते हैं।') ?>
  </div>
  <div class="card mb-3"><div class="card-header"><i class="bi bi-building"></i> <?= L('Add branch', 'शाखा जोड़ें') ?></div><div class="card-body">
    <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?>
      <div class="col-md-4"><label class="form-label small"><?= L('Branch name', 'शाखा का नाम') ?></label><input name="name" class="form-control form-control-sm" required maxlength="120"></div>
      <div class="col-md-3"><label class="form-label small"><?= L('Area', 'क्षेत्र') ?></label><input name="area" class="form-control form-control-sm" maxlength="120"></div>
      <div class="col-md-3"><label class="form-label small"><?= L('Region', 'रीजन') ?></label><select name="region_id" class="form-select form-select-sm"><?= $regOpts('') ?></select></div>
      <div class="col-md-2"><button class="btn btn-sm btn-success w-100"><?= L('Add', 'जोड़ें') ?></button></div>
    </form>
  </div></div>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th><?= L('Branch', 'शाखा') ?></th><th><?= L('Area', 'क्षेत्र') ?></th><th><?= L('Region', 'रीजन') ?></th><th>Active BCs</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
    <tr><td colspan="5">
      <form method="post" class="row g-2 align-items-center"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <div class="col-md-3"><input name="name" value="<?= e($r['name']) ?>" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><input name="area" value="<?= e($r['area']) ?>" class="form-control form-control-sm" placeholder="area"></div>
        <div class="col-md-3"><select name="region_id" class="form-select form-select-sm"><?= $regOpts($r['region_id']) ?></select></div>
        <div class="col-md-1 small text-center"><?= (int) $r['bcs'] ?></div>
        <div class="col-md-2 text-nowrap"><button name="action" value="save" class="btn btn-sm btn-outline-success">Save</button>
          <button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete branch?')"><i class="bi bi-trash"></i></button></div>
      </form></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-muted">Abhi koi branch nahi.</td></tr><?php endif; ?></tbody>
  </table></div>
<?php else:
    // ---------------- Region Manager: only their own region's branches ----------------
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
<?php endif;
View::footer();
