<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Templates;
use App\View;

$user = Auth::requireAdmin();
const MAX_VARIANTS = 6;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string) p('type');
    if (!isset(Templates::TYPES[$type])) {
        redirect('/templates.php');
    }
    $action = (string) p('action');
    if ($action === 'save') {
        $id = (int) p('id');
        $body = str_replace("\r\n", "\n", (string) ($_POST['body'] ?? ''));
        $errs = Templates::validate($body, $type);
        if ($errs) {
            flash('danger', implode(' | ', $errs));
        } else {
            $active = p('is_active') === '1' ? 1 : 0;
            if ($id) {
                DB::exec('UPDATE templates SET body = ?, is_active = ? WHERE id = ? AND type = ?', [$body, $active, $id, $type]);
            } else {
                $count = (int) DB::val('SELECT COUNT(*) FROM templates WHERE type = ?', [$type]);
                if ($count >= MAX_VARIANTS) {
                    flash('danger', 'Max ' . MAX_VARIANTS . ' variants');
                    redirect('/templates.php#' . $type);
                }
                $next = (int) DB::val('SELECT COALESCE(MAX(variant_no), 0) + 1 FROM templates WHERE type = ?', [$type]);
                $id = DB::insert('INSERT INTO templates (type, variant_no, body, is_active) VALUES (?, ?, ?, ?)', [$type, $next, $body, $active]);
            }
            Audit::log((int) $user['id'], 'template_save', 'template', $id, ['type' => $type, 'active' => $active]);
            flash('success', 'Template saved / टेम्पलेट सेव हुआ');
        }
    } elseif ($action === 'delete') {
        $id = (int) p('id');
        $active = (int) DB::val('SELECT COUNT(*) FROM templates WHERE type = ? AND is_active = 1 AND id <> ?', [$type, $id]);
        if ($active < 1) {
            flash('danger', 'Keep at least one active variant / कम से कम एक सक्रिय टेम्पलेट रखें');
        } else {
            DB::exec('DELETE FROM templates WHERE id = ? AND type = ?', [$id, $type]);
            Audit::log((int) $user['id'], 'template_delete', 'template', $id, ['type' => $type]);
            flash('success', 'Variant deleted');
        }
    }
    redirect('/templates.php#' . $type);
}

$sampleBc = ['id' => 1, 'name' => 'Ramesh Kumar', 'bc_code' => 'BC001'];
$all = [];
foreach (DB::all('SELECT * FROM templates ORDER BY type, variant_no') as $t) {
    $all[$t['type']][] = $t;
}

View::header('Message templates / संदेश टेम्पलेट', 'templates');
?>
<?php foreach (Templates::TYPES as $type => [$en, $hi]): $list = $all[$type] ?? []; ?>
<h5 id="<?= $type ?>" class="mt-4"><?= L($en, $hi) ?> <small class="text-muted">(<?= count(array_filter($list, static fn ($t) => $t['is_active'])) ?> active)</small></h5>
<div class="row g-3">
  <?php foreach ($list as $t): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
      <div class="d-flex justify-content-between mb-1"><strong class="small">Variant <?= (int) $t['variant_no'] ?></strong>
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?> id="a<?= (int) $t['id'] ?>"><label class="form-check-label small" for="a<?= (int) $t['id'] ?>">Active</label></div></div>
      <textarea name="body" rows="7" class="form-control form-control-sm font-monospace" required><?= e($t['body']) ?></textarea>
      <details class="mt-1"><summary class="small text-muted">Preview / पूर्वावलोकन</summary><div class="bubble out mt-1"><div class="wa-pre"><?= e(Templates::render($t['body'], $sampleBc, date('Y-m-d'))) ?></div></div></details>
      <div class="mt-2 d-flex gap-2"><button name="action" value="save" class="btn btn-sm btn-success"><?= L('Save', 'सेव') ?></button>
        <button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this variant?')"><?= L('Delete', 'हटाएँ') ?></button></div>
    </form>
  </div></div></div>
  <?php endforeach; ?>
  <?php if (count($list) < MAX_VARIANTS): ?>
  <div class="col-lg-6"><div class="card h-100 border-dashed"><div class="card-body">
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="is_active" value="1">
      <strong class="small"><?= L('Add variant', 'नया टेम्पलेट') ?></strong>
      <textarea name="body" rows="7" class="form-control form-control-sm font-monospace mt-1" required><?= e(Templates::FALLBACK[$type]) ?></textarea>
      <button name="action" value="save" class="btn btn-sm btn-outline-success mt-2"><?= L('Add', 'जोड़ें') ?></button>
    </form>
  </div></div></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php View::footer();
