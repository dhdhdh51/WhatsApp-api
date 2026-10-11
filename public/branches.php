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
    $id = (int) p('id');
    $name = (string) p('name');
    $area = (string) p('area');
    if (p('action') === 'delete') {
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
            DB::exec('UPDATE branches SET name = ?, area = ? WHERE id = ?', [$name, $area ?: null, $id]);
        } else {
            $id = DB::insert('INSERT INTO branches (name, area) VALUES (?, ?)', [$name, $area ?: null]);
        }
        Audit::log((int) $user['id'], 'branch_save', 'branch', $id, ['name' => $name, 'area' => $area]);
        flash('success', 'Branch saved / शाखा सेव हुई');
    }
    redirect('/branches.php');
}

$rows = DB::all('SELECT br.*, (SELECT COUNT(*) FROM bcs b WHERE b.branch_id = br.id AND b.is_active = 1) bcs FROM branches br ORDER BY br.name');

View::header('Branches / शाखाएँ', 'branches');
?>
<div class="card mb-3"><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= Csrf::field() ?>
    <div class="col-md-4"><label class="form-label small"><?= L('Branch name', 'शाखा का नाम') ?></label><input name="name" class="form-control form-control-sm" required maxlength="120"></div>
    <div class="col-md-4"><label class="form-label small"><?= L('Area', 'क्षेत्र') ?></label><input name="area" class="form-control form-control-sm" maxlength="120"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-success w-100"><?= L('Add', 'जोड़ें') ?></button></div>
  </form>
</div></div>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr><th><?= L('Branch', 'शाखा') ?></th><th><?= L('Area', 'क्षेत्र') ?></th><th>Active BCs</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
  <tr><td colspan="4">
    <form method="post" class="row g-2 align-items-center"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <div class="col-md-4"><input name="name" value="<?= e($r['name']) ?>" class="form-control form-control-sm" required></div>
      <div class="col-md-4"><input name="area" value="<?= e($r['area']) ?>" class="form-control form-control-sm"></div>
      <div class="col-md-1 small"><?= (int) $r['bcs'] ?></div>
      <div class="col-md-3"><button name="action" value="save" class="btn btn-sm btn-outline-success">Save</button>
        <button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete branch?')">Delete</button></div>
    </form></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php View::footer();
