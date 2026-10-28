<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\View;

$user = Auth::requireAdmin();   // only the Admin (Shivam) creates regions

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) p('id');
    $name = trim((string) p('name'));
    if (p('action') === 'delete') {
        $nb = (int) DB::val('SELECT COUNT(*) FROM branches WHERE region_id = ?', [$id]);
        $nm = (int) DB::val("SELECT COUNT(*) FROM users WHERE region_id = ? AND role = 'supervisor'", [$id]);
        if ($nb || $nm) {
            flash('danger', "Is region me {$nb} branch aur {$nm} manager hai — pehle unhe hataayein/badlein.");
        } else {
            DB::exec('DELETE FROM regions WHERE id = ?', [$id]);
            Audit::log((int) $user['id'], 'region_delete', 'region', $id);
            flash('success', 'Region deleted / रीजन हटाया');
        }
    } elseif ($name === '' || mb_strlen($name) > 120) {
        flash('danger', 'Region name required / रीजन का नाम आवश्यक');
    } elseif (DB::val('SELECT COUNT(*) FROM regions WHERE name = ? AND id <> ?', [$name, $id])) {
        flash('danger', 'Region already exists / यह रीजन पहले से है');
    } else {
        if ($id) {
            DB::exec('UPDATE regions SET name = ? WHERE id = ?', [$name, $id]);
        } else {
            $id = DB::insert('INSERT INTO regions (name) VALUES (?)', [$name]);
        }
        Audit::log((int) $user['id'], 'region_save', 'region', $id, ['name' => $name]);
        flash('success', 'Region saved / रीजन सेव हुआ');
    }
    redirect('/regions.php');
}

$rows = DB::all('SELECT r.*,
    (SELECT COUNT(*) FROM branches b WHERE b.region_id = r.id) AS branches,
    (SELECT COUNT(*) FROM bcs bc JOIN branches b2 ON b2.id = bc.branch_id WHERE b2.region_id = r.id AND bc.is_active = 1) AS bcs,
    (SELECT GROUP_CONCAT(u.name SEPARATOR ", ") FROM users u WHERE u.region_id = r.id AND u.role = \'supervisor\') AS managers
    FROM regions r ORDER BY r.name');

View::header('Regions / रीजन', 'regions');
?>
<div class="card mb-3"><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?>
    <div class="col-md-6"><label class="form-label small"><?= L('New region name', 'नया रीजन नाम') ?></label><input name="name" class="form-control form-control-sm" required maxlength="120" placeholder="e.g. RO Agra"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-success w-100"><?= L('Add region', 'रीजन जोड़ें') ?></button></div>
  </form>
  <p class="small text-muted mt-2 mb-0"><?= L('Admin creates regions here. Branches inside a region are added by that region\'s Region Manager. Assign a Region Manager on the Users page.', 'एडमिन यहाँ रीजन बनाता है। रीजन की शाखाएँ उस रीजन का रीजन मैनेजर जोड़ता है। रीजन मैनेजर Users पेज से assign करें।') ?></p>
</div></div>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr><th><?= L('Region', 'रीजन') ?></th><th>Branches</th><th>Active BCs</th><th><?= L('Region Manager(s)', 'रीजन मैनेजर') ?></th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
  <tr>
    <td style="min-width:220px"><form method="post" class="d-flex gap-1" id="rf<?= (int) $r['id'] ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input name="name" value="<?= e($r['name']) ?>" class="form-control form-control-sm" required></td>
    <td><?= (int) $r['branches'] ?></td>
    <td><?= (int) $r['bcs'] ?></td>
    <td class="small"><?= $r['managers'] ? e($r['managers']) : '<span class="text-muted">—</span>' ?></td>
    <td class="text-nowrap"><button name="action" value="save" class="btn btn-sm btn-outline-success">Save</button>
      <button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete region <?= e(addslashes($r['name'])) ?>?')"><i class="bi bi-trash"></i></button></form></td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-muted">Abhi koi region nahi. Upar se add karein.</td></tr><?php endif; ?></tbody>
</table></div>
<?php View::footer();
