<?php
declare(strict_types=1);

namespace App;

/** Layout + small UI helpers (Bootstrap 5, mobile friendly, English + Hindi labels). */
final class View
{
    private const NAV = [
        // key => [url, English, Hindi, icon, adminOnly]
        'dashboard' => ['/dashboard.php', 'Dashboard', 'डैशबोर्ड', 'speedometer2', false],
        'status'    => ['/status.php', "Today's Status", 'आज की स्थिति', 'list-check', false],
        'reports'   => ['/reports.php', 'Reports', 'रिपोर्ट', 'table', false],
        'bcs'       => ['/bcs.php', 'BCs', 'बीसी', 'people', false],
        'targets'   => ['/targets.php', 'Targets', 'लक्ष्य', 'bullseye', false],
        'leaves'    => ['/leaves.php', 'Leave', 'छुट्टी', 'calendar-x', false],
        'holidays'  => ['/holidays.php', 'Holidays', 'अवकाश', 'calendar-event', true],
        'messages'  => ['/messages.php', 'Messages', 'संदेश', 'chat-dots', false],
        'chat'      => ['/chat.php', 'App Chat', 'ऐप चैट', 'phone', false],
        'inbox'     => ['/inbox.php', 'Inbox', 'इनबॉक्स', 'inbox', true],
        'whatsapp'  => ['/whatsapp.php', 'WhatsApp', 'व्हाट्सऐप', 'whatsapp', true],
        'settings'  => ['/settings.php', 'Settings', 'सेटिंग्स', 'gear', true],
        'templates' => ['/templates.php', 'Templates', 'टेम्पलेट', 'card-text', true],
        'users'     => ['/users.php', 'Users', 'यूज़र', 'person-badge', true],
        'branches'  => ['/branches.php', 'Branches', 'शाखाएँ', 'diagram-3', true],
        'audit'     => ['/audit.php', 'Audit Log', 'ऑडिट लॉग', 'shield-check', true],
    ];

    public static function header(string $title, string $active, bool $charts = false): void
    {
        $user = Auth::user();
        $app = Env::get('APP_NAME', 'BC Daily Report');
        $wa = Settings::get('wa_status');
        $inboxUnread = ($user && $user['role'] === 'admin')
            ? (int) DB::val('SELECT COUNT(*) FROM inbox_threads WHERE archived = 0 AND unread > 0') : 0;
        $chatUnread = $user ? App::staffUnread($user) : 0;
        ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/app.css" rel="stylesheet">
<?php if ($charts): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script><?php endif; ?>
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-success sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="/dashboard.php"><i class="bi bi-whatsapp"></i> <?= e($app) ?></a>
    <span class="badge <?= $wa === 'WORKING' ? 'bg-light text-success' : 'bg-danger' ?> d-lg-none" title="WhatsApp"><?= e($wa) ?></span>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto flex-wrap">
      <?php foreach (self::NAV as $key => [$url, $en, $hi, $icon, $adminOnly]):
          if ($adminOnly && $user['role'] !== 'admin') continue; ?>
        <li class="nav-item"><a class="nav-link <?= $active === $key ? 'active fw-semibold' : '' ?>" href="<?= $url ?>"><i class="bi bi-<?= $icon ?>"></i> <?= e($en) ?> <small class="hi-nav"><?= e($hi) ?></small><?php if ($key === 'inbox' && $inboxUnread > 0): ?> <span class="badge text-bg-danger rounded-pill"><?= $inboxUnread ?></span><?php endif; ?><?php if ($key === 'chat' && $chatUnread > 0): ?> <span class="badge text-bg-danger rounded-pill"><?= $chatUnread ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
      </ul>
      <div class="d-flex align-items-center gap-2 text-white small">
        <span class="badge <?= $wa === 'WORKING' ? 'bg-light text-success' : 'bg-danger' ?> d-none d-lg-inline">WA: <?= e($wa) ?></span>
        <a class="text-white text-decoration-none" href="/account.php"><i class="bi bi-person-circle"></i> <?= e($user['name']) ?> (<?= e(Auth::roleLabel($user['role'])) ?>)</a>
        <form method="post" action="/logout.php" class="d-inline"><?= Csrf::field() ?><button class="btn btn-sm btn-outline-light">Logout</button></form>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container-fluid py-3">
<h4 class="mb-3"><?= e($title) ?></h4>
<?php foreach (flash() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach;
    }

    public static function footer(string $extraJs = ''): void
    {
        ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js"></script>
<?= $extraJs ?>
</body>
</html>
<?php
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            'submitted'  => ['success', 'Submitted / जमा'],
            'pending'    => ['danger', 'Pending / बाकी'],
            'leave'      => ['info', 'Leave / छुट्टी'],
            'holiday'    => ['secondary', 'Holiday / अवकाश'],
            'weekly_off' => ['secondary', 'Weekly off / साप्ताहिक अवकाश'],
            'sent'       => ['success', 'sent'],
            'failed'     => ['danger', 'failed'],
            'received'   => ['primary', 'received'],
            'ignored'    => ['secondary', 'ignored'],
            'cancelled'  => ['secondary', 'cancelled'],
            'sending'    => ['warning', 'sending'],
        ];
        [$c, $l] = $map[$status] ?? ['secondary', $status];
        return '<span class="badge text-bg-' . $c . '">' . e($l) . '</span>';
    }

    /** Branch <select> options limited to user scope. */
    public static function branchOptions(array $user, $selected, bool $withAll = true): string
    {
        $h = $withAll ? '<option value="">All branches / सभी शाखाएँ</option>' : '';
        foreach (Scope::branches($user) as $b) {
            $h .= '<option value="' . (int) $b['id'] . '"' . ((string) $selected === (string) $b['id'] ? ' selected' : '') . '>'
                . e($b['name']) . ($b['area'] ? ' (' . e($b['area']) . ')' : '') . '</option>';
        }
        return $h;
    }

    /** CBC <select> options (all CBC users for admins when $all, otherwise CBCs of visible BCs). */
    public static function cbcOptions(array $user, $selected, string $firstLabel = 'All CBCs / सभी सीबीसी', bool $all = false): string
    {
        $h = '<option value="">' . e($firstLabel) . '</option>';
        $list = $all ? DB::all("SELECT id, name, phone FROM users WHERE role = 'cbc' AND is_active = 1 ORDER BY name") : Scope::cbcs($user);
        foreach ($list as $c) {
            $h .= '<option value="' . (int) $c['id'] . '"' . ((string) $selected === (string) $c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
        }
        return $h;
    }

    /** Call / WhatsApp buttons for a BC phone number. */
    public static function contactButtons(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        return '<a class="btn btn-sm btn-success py-0 px-2" href="tel:+91' . e($phone) . '" title="Call / कॉल करें"><i class="bi bi-telephone-fill"></i></a> '
            . '<a class="btn btn-sm btn-outline-success py-0 px-2" target="_blank" rel="noopener" href="https://wa.me/91' . e($phone) . '" title="WhatsApp chat"><i class="bi bi-whatsapp"></i></a>';
    }

    public static function blockOptions(array $user, $selected): string
    {
        $h = '<option value="">All blocks / सभी ब्लॉक</option>';
        foreach (Scope::blocks($user) as $b) {
            $h .= '<option' . ((string) $selected === (string) $b ? ' selected' : '') . '>' . e($b) . '</option>';
        }
        return $h;
    }

    public static function pager(int $total, int $page, int $per): string
    {
        $pages = (int) max(1, ceil($total / $per));
        if ($pages <= 1) {
            return '<div class="small text-muted">' . $total . ' rows</div>';
        }
        $h = '<nav class="d-flex align-items-center gap-2"><span class="small text-muted">' . $total . ' rows</span><ul class="pagination pagination-sm mb-0">';
        $start = max(1, $page - 3);
        $end = min($pages, $page + 3);
        if ($page > 1) {
            $h .= '<li class="page-item"><a class="page-link" href="' . e(qs(['page' => $page - 1])) . '">&laquo;</a></li>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $h .= '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="' . e(qs(['page' => $i])) . '">' . $i . '</a></li>';
        }
        if ($page < $pages) {
            $h .= '<li class="page-item"><a class="page-link" href="' . e(qs(['page' => $page + 1])) . '">&raquo;</a></li>';
        }
        return $h . '</ul></nav>';
    }
}
