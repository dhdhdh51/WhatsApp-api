<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\View;

$user = Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = DB::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    $cur = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $rep = (string) ($_POST['repeat'] ?? '');
    if (!$row || !password_verify($cur, $row['password_hash'])) {
        flash('danger', 'Current password is wrong / मौजूदा पासवर्ड गलत है');
    } elseif (mb_strlen($new) < 10) {
        flash('danger', 'New password must be at least 10 characters / कम से कम 10 अक्षर');
    } elseif ($new !== $rep) {
        flash('danger', 'Passwords do not match / पासवर्ड मेल नहीं खाते');
    } else {
        DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        Audit::log((int) $user['id'], 'password_change', 'user', (int) $user['id']);
        session_regenerate_id(true);
        flash('success', 'Password changed / पासवर्ड बदल दिया गया');
    }
    redirect('/account.php');
}

View::header('My account / मेरा खाता', '');
?>
<div class="row"><div class="col-md-6 col-lg-4">
<div class="card"><div class="card-body">
  <p class="mb-1"><strong><?= e($user['name']) ?></strong> (<?= e($user['username']) ?>) – <?= e($user['role']) ?></p>
  <hr>
  <h6><?= L('Change password', 'पासवर्ड बदलें') ?></h6>
  <form method="post"><?= Csrf::field() ?>
    <input type="password" name="current" class="form-control mb-2" placeholder="Current password / मौजूदा पासवर्ड" required>
    <input type="password" name="new" class="form-control mb-2" placeholder="New password (min 10) / नया पासवर्ड" required minlength="10">
    <input type="password" name="repeat" class="form-control mb-2" placeholder="Repeat / दोबारा" required minlength="10">
    <button class="btn btn-success"><?= L('Save', 'सेव करें') ?></button>
  </form>
</div></div>
</div></div>
<?php View::footer();
