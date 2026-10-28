<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\View;

$user = Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');

    // ---------------- Regions ----------------
    if ($action === 'region_add') {
        $name = trim((string) p('region_name'));
        if ($name === '' || mb_strlen($name) > 120) {
            flash('danger', 'Region name required / रीजन का नाम आवश्यक');
        } elseif (DB::val('SELECT COUNT(*) FROM regions WHERE name = ?', [$name])) {
            flash('danger', 'Region already exists / यह रीजन पहले से है');
        } else {
            $rid = DB::insert('INSERT INTO regions (name) VALUES (?)', [$name]);
            Audit::log((int) $user['id'], 'region_add', 'region', $rid, ['name' => $name]);
            flash('success', 'Region added / रीजन जोड़ा गया');
        }
        redirect('/branches.php');
    }
    if ($action === 'region_delete') {
        $rid = (int) p('region_id');
        $nb = (int) DB::val('SELECT COUNT(*) FROM branches WHERE region_id = ?', [$rid]);
        $nm = (int) DB::val("SELECT COUNT(*) FROM users WHERE region_id = ? AND role = 'supervisor'", [$rid]);
        if ($nb || $nm) {
            flash('danger', "Is region me {$nb} branch aur {$nm} manager hai — pehle unhe hataayein/badlein.");
        } else {
            DB::exec('DELETE FROM regions WHERE id = ?', [$rid]);
            Audit::log((int) $user['id'], 'region_delete', 'region', $rid);
            flash('success', 'Region deleted / रीजन हटाया');
        }
        redirect('/branches.php');
    }

    // ---------------- Branches ----------------
    $id = (int) p('id');
    $name = (string) p('name');
    $area = (string) p('area');
    $regionId = p('region_id') !== '' ? (int) p('region_id') : null;
    if ($regionId && !DB::val('SELECT 1 FROM regions WHERE id = ?', [$regionId])) {
        $regionId = null;
    }
    if ($action === 'delete') {
        $n = (int) DB::val('SELECT COUNT(*) FROM bcs WHERE branch_id = ?', [$id]);
        if ($n > 0) {
            flash('danger', "Branch has {$n} BCs – move them first / पहले बीसी हटाएँ");
        } else {
            DB::exec('DELETE FROM branches WHERE id = ?', [$id]);
            Audit::log((int) $user['id'], 'branch_delete', 'branch', $id);
            flash('success', 'Branch deleted');
        }
    } elseif ($name === '' || mb_strlen($name) > 120) {
        flash('danger', 'Name required');
    } elseif (DB::val('SELECT COUNT(*) FROM branches WHERE name = ? AND id <> ?', [$name, $id])) {
        flash('danger', 'Branch name already exists');
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

$regions = DB::all('SELECT r.*,
    (SELECT COUNT(*) FROM branches b WHERE b.region_id = r.id) AS branches,
    (SELECT COUNT(*) FROM users u WHERE u.region_id = r.id AND u.role = \'supervisor\') AS managers
    FROM regions r ORDER BY r.name');
$rows = DB::all('SELECT br.*, rg.name AS region_name,
    (SELECT COUNT(*) FROM bcs b WHERE b.branch_id = br.id AND b.is_active = 1) AS bcs
    FROM branches br LEFT JOIN regions rg ON rg.id = br.region_id ORDER BY rg.name IS NULL, rg.name, br.name');
$regOpts = static function ($sel) use ($regions): string {
    $h = '<option value="">— no region —</option>';
    foreach ($regions as $rg) {
        $h .= '<option value="' . (int) $rg['id'] . '"' . ((int) $sel === (int) $rg['id'] ? ' selected' : '') . '>' . e($rg['name']) . '</option>';
    }
    return $h;
};

View::header('Regions & Branches / रीजन और शाखाएँ', 'branches');
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><i class="bi bi-diagram-3"></i> <?= L('Regions', 'रीजन') ?></div><div class="card-body">
      <form method="post" class="row g-2 align-items-end mb-3"><?= Csrf::field() ?><input type="hidden" name="action" value="region_add">
        <div class="col-8"><label class="form-label small"><?= L('New region name', 'नया रीजन नाम') ?></label><input name="region_name" class="form-control form-control-sm" required maxlength="120" placeholder="e.g. RO Agra"></div>
        <div class="col-4"><button class="btn btn-sm btn-success w-100"><?= L('Add', 'जोड़ें') ?></button></div>
      </form>
      <ul class="list-group list-group-flush small">
        <?php foreach ($regions as $rg): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
          <span><b><?= e($rg['name']) ?></b> <span class="text-muted">· <?= (int) $rg['branches'] ?> branch · <?= (int) $rg['managers'] ?> manager</span></span>
          <form method="post" class="d-inline" onsubmit="return confirm('Delete this region?')"><?= Csrf::field() ?><input type="hidden" name="action" value="region_delete"><input type="hidden" name="region_id" value="<?= (int) $rg['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form>
        </li>
        <?php endforeach; if (!$regions): ?><li class="list-group-item text-muted px-0">Abhi koi region nahi. Upar se add karein.</li><?php endif; ?>
      </ul>
      <p class="small text-muted mt-2 mb-0"><?= L('A Region Manager manages a whole region (all its branches).', 'एक रीजन मैनेजर पूरे रीजन (सभी शाखाओं) को संभालता है।') ?></p>
    </div></div>
  </div>
  <div class="col-lg-8">
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
  </div>
</div>
<?php View::footer();
