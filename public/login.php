<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Auth;
use App\Csrf;
use App\View;

if (Auth::user()) {
    redirect('/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = Auth::attempt((string) p('username'), (string) ($_POST['password'] ?? ''));
    if ($res['ok']) {
        $to = $_SESSION['after_login'] ?? '/dashboard.php';
        unset($_SESSION['after_login']);
        // only allow local redirects
        redirect(is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/dashboard.php');
    }
    $error = $res['error'];
}

View::header('Login / लॉगिन', '');
?>
<div class="row justify-content-center">
  <div class="col-12 col-sm-8 col-md-5 col-lg-4">
    <div class="card shadow-sm mt-4">
      <div class="card-body p-4">
        <div class="text-center mb-3"><i class="bi bi-whatsapp text-success" style="font-size:3rem"></i>
          <div class="fw-semibold"><?= e(App\Env::get('APP_NAME', 'BC Daily Report')) ?></div>
          <div class="small text-muted">Admin / Manager login · एडमिन / मैनेजर लॉगिन</div>
        </div>
        <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?= Csrf::field() ?>
          <div class="mb-3">
            <label class="form-label"><?= L('Username', 'यूज़रनेम') ?></label>
            <input name="username" class="form-control" required maxlength="60" value="<?= e(p('username')) ?>" autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= L('Password', 'पासवर्ड') ?></label>
            <input type="password" name="password" class="form-control" required maxlength="200">
          </div>
          <button class="btn btn-success w-100"><?= L('Login', 'लॉगिन') ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php View::footer();
