<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\App;
use App\Audit;
use App\Auth;
use App\BranchReport;
use App\Calendar;
use App\Csrf;
use App\DB;
use App\Phone;
use App\Scope;
use App\Settings;
use App\View;

/**
 * Daily branch report (20 parameters).
 *   Branch Manager  – fills today's report for their branch (normally from the app; this page is the fallback).
 *   Region Manager  – sees / corrects the reports of their own region's branches.
 *   Admin           – sees every region + sets the Branch Manager reminder (call + alarm).
 */
$user = Auth::require();
$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['admin', 'supervisor', 'manager'], true)) {
    http_response_code(403);
    View::header('Access denied', 'branchreport');
    echo '<div class="alert alert-danger">Only Admin, Region Manager or Branch Manager can open this page. / यह पेज एडमिन, रीजन मैनेजर या ब्रांच मैनेजर के लिए है।</div>';
    View::footer();
    exit;
}
$isAdmin = $role === 'admin';
$isBm = $role === 'manager';
$today = Calendar::today();

/** May this user write the report of $branchId for $date? */
$canWrite = static function (int $branchId, string $date) use ($user, $isBm, $today): bool {
    if (!$branchId || !valid_date($date) || $date > $today) {
        return false;
    }
    if ($isBm) {   // a Branch Manager: only today's report (until the cut-off) of their own branch
        return $date === $today && Calendar::isOpen($today)
            && in_array($branchId, array_map('intval', array_column(BranchReport::managerBranches((int) $user['id']), 'id')), true);
    }
    $ids = BranchReport::scopeBranchIds($user);   // admin / region-less supervisor = null = any branch
    return $ids === null ? (bool) DB::val('SELECT 1 FROM branches WHERE id = ?', [$branchId]) : in_array($branchId, $ids, true);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) p('action');
    if ($action === 'save') {
        $bid = (int) p('branch_id');
        $date = (string) p('date');
        if (!$canWrite($bid, $date)) {
            http_response_code(403);
            exit($isBm ? 'Not allowed (only your branch, only today before the cut-off)' : 'Not allowed');
        }
        [$vals, $err] = BranchReport::parse((array) ($_POST['v'] ?? []));
        $back = '/branch_report.php' . ($isBm ? '?branch_id=' . $bid : '?edit=1&branch_id=' . $bid . '&date=' . $date);
        if ($vals === null) {
            flash('danger', $err);
            redirect($back);
        }
        $saved = BranchReport::save($bid, $date, $vals, (int) $user['id'], 'panel');
        Audit::log((int) $user['id'], $saved['updated'] ? 'branch_report_update' : 'branch_report_submit', 'branch', $bid, ['date' => $date, 'source' => 'panel']);
        flash('success', $saved['updated'] ? 'Branch report update ho gayi ✅' : 'Branch report save ho gayi ✅');
        redirect($isBm ? $back : '/branch_report.php?d=' . $date);
    }
    if ($action === 'settings' && $isAdmin) {
        $errs = [];
        foreach (['bm_call_time', 'bm_alarm_time'] as $k) {
            if (!Settings::validTime((string) p($k))) {
                $errs[] = "{$k}: HH:MM";
            }
        }
        foreach (['bm_call_text', 'bm_alarm_text'] as $k) {
            $t = trim((string) p($k));
            if ($t === '' || mb_strlen($t) > 1000) {
                $errs[] = "{$k}: 1–1000 characters";
            }
        }
        if ($errs) {
            flash('danger', implode(' | ', $errs));
            redirect('/branch_report.php#reminder');
        }
        Settings::set('bm_alarm_enabled', p('bm_alarm_enabled') === '1' ? '1' : '0');
        Settings::set('bm_skip_2nd_4th_sat', p('bm_skip_2nd_4th_sat') === '1' ? '1' : '0');
        Settings::set('bm_call_time', Settings::padTime((string) p('bm_call_time')));
        Settings::set('bm_alarm_time', Settings::padTime((string) p('bm_alarm_time')));
        Settings::set('bm_call_text', str_replace("\r\n", "\n", trim((string) p('bm_call_text'))));
        Settings::set('bm_alarm_text', str_replace("\r\n", "\n", trim((string) p('bm_alarm_text'))));
        Settings::set('bm_alarm_version', (string) (Settings::int('bm_alarm_version') + 1));
        Audit::log((int) $user['id'], 'bm_alarm_settings', 'settings', null, ['enabled' => p('bm_alarm_enabled'),
            'call' => p('bm_call_time'), 'alarm' => p('bm_alarm_time'), 'sat' => p('bm_skip_2nd_4th_sat')]);
        flash('success', 'Saved – Branch Manager phones pick up the change at their next check (max ~15 min) / सेव हुआ');
        redirect('/branch_report.php#reminder');
    }
    if ($action === 'test' && $isAdmin) {
        $mode = p('mode') === 'call' ? 'call' : 'alarm';
        $tid = (int) p('target');
        $ok = $tid && DB::val("SELECT COUNT(*) FROM cbc_app_tokens t JOIN users u ON u.id = t.user_id WHERE t.user_id = ? AND u.role = 'manager'", [$tid]);
        if ($ok) {
            App::sendTestRingCbc($tid, $mode);
        }
        Audit::log((int) $user['id'], 'bm_alarm_test', 'user', $tid ?: null, ['mode' => $mode]);
        flash($ok ? 'success' : 'warning', $ok
            ? "Test {$mode} sent to that Branch Manager phone – rings within ~15 minutes / टेस्ट भेजा गया"
            : 'Select a Branch Manager who has logged into the app / ऐप में लॉगिन किया हुआ ब्रांच मैनेजर चुनें');
        redirect('/branch_report.php#reminder');
    }
    http_response_code(400);
    exit('Bad request');
}

/** The 20-field form (Bootstrap). */
$form = static function (int $bid, string $date, ?array $rep, bool $editable): string {
    $h = '<form method="post" class="row g-2">' . Csrf::field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="branch_id" value="' . $bid . '"><input type="hidden" name="date" value="' . e($date) . '">';
    $i = 0;
    foreach (BranchReport::ITEMS as $k => [$label, , $unit]) {
        $i++;
        $amt = $unit === 'amount';
        $val = $rep ? BranchReport::fmt($k, $rep[$k] ?? 0) : '';
        $h .= '<div class="col-6 col-md-4 col-xl-3"><label class="form-label small mb-1 fw-semibold">' . $i . '. ' . e($label) . '</label>'
            . ($amt ? '<div class="input-group input-group-sm">' : '')
            . '<input type="number" name="v[' . $k . ']" value="' . e($val) . '" min="0" step="' . ($amt ? '0.01' : '1') . '" inputmode="' . ($amt ? 'decimal' : 'numeric') . '" placeholder="0" class="form-control form-control-sm"' . ($editable ? '' : ' disabled') . '>'
            . ($amt ? '<span class="input-group-text">₹ लाख</span></div>' : '')
            . '</div>';
    }
    if ($editable) {
        $h .= '<div class="col-12 mt-2"><button class="btn btn-success btn-sm"><i class="bi bi-save"></i> ' . ($rep ? 'Update karein' : 'Report save karein') . '</button>'
            . ' <span class="small text-muted ms-2">Khali = 0. ₹ wale khane lakh mein (2.5 = ₹2.5 lakh).</span></div>';
    }
    return $h . '</form>';
};
$chip = static function (string $st): string {
    [$c, $t] = ['submitted' => ['success', 'Submitted / जमा'], 'pending' => ['danger', 'Pending / बाकी'],
        'holiday' => ['secondary', 'Holiday / अवकाश'], 'weekly_off' => ['secondary', 'Weekly off / छुट्टी']][$st] ?? ['secondary', $st];
    return '<span class="badge text-bg-' . $c . '">' . e($t) . '</span>';
};
$th = static function (): string {
    $h = '';
    foreach (BranchReport::ITEMS as $k => [$label, $short]) {
        $h .= '<th class="text-end text-nowrap" title="' . e($label) . '">' . e($short) . '</th>';
    }
    return $h;
};
$td = static function (array $r): string {
    $h = '';
    foreach (BranchReport::keys() as $k) {
        $h .= '<td class="text-end">' . e(BranchReport::fmt($k, $r[$k] ?? 0)) . '</td>';
    }
    return $h;
};

// ====================================================================== Branch Manager view
if ($isBm) {
    $mine = BranchReport::managerBranches((int) $user['id']);
    View::header('Branch Report / शाखा रिपोर्ट', 'branchreport');
    if (!$mine) {
        echo '<div class="alert alert-warning">Aapko abhi koi branch assign nahi hai. Apne Region Manager / Admin se branch judwayein. / आपको अभी कोई शाखा नहीं दी गई है।</div>';
        View::footer();
        exit;
    }
    $bid = (int) q('branch_id');
    $cur = $mine[0];
    foreach ($mine as $b) {
        if ((int) $b['id'] === $bid) {
            $cur = $b;
        }
    }
    $bid = (int) $cur['id'];
    $rep = BranchReport::find($bid, $today);
    $off = BranchReport::offReason($today, $bid);
    $open = Calendar::isOpen($today);
    $hist = DB::all('SELECT * FROM branch_reports WHERE branch_id = ? ORDER BY report_date DESC LIMIT 15', [$bid]);
    ?>
  <?php if (count($mine) > 1): ?>
  <form class="row g-2 align-items-end mb-3"><div class="col-8 col-md-4"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label>
    <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($mine as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $bid ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
    </select></div></form>
  <?php endif; ?>
  <?php if ($rep): ?><div class="alert alert-success py-2">✅ <?= e($cur['name']) ?> ki aaj ki report jama hai (<?= e(date('h:i A', strtotime((string) $rep['updated_at']))) ?>). Badlav ke liye number badal kar dobara save karein.</div>
  <?php elseif ($off !== null): ?><div class="alert alert-secondary py-2">🏖️ Aaj <?= e(BranchReport::offText($off)) ?> hai – report zaroori nahi. Chahein to bhar sakte hain.</div>
  <?php elseif ($open): ?><div class="alert alert-warning py-2">⏳ Aaj ki branch report abhi baaki hai. Antim samay: <?= e(fmt_time12(Settings::padTime(Settings::get('cutoff_time') ?: '23:59'))) ?></div><?php endif; ?>
  <?php if (!$open): ?><div class="alert alert-danger py-2">⛔ Aaj ka samay khatam. Ab badlav sirf Admin / Region Manager kar sakte hain.</div><?php endif; ?>
  <div class="card mb-3"><div class="card-header"><i class="bi bi-clipboard-data"></i> <?= e($cur['name']) ?> – <?= e(date('d-m-Y')) ?></div>
    <div class="card-body"><?= $form($bid, $today, $rep, $open) ?></div></div>
  <div class="card"><div class="card-header small"><?= L('Recent reports', 'पिछली रिपोर्ट') ?></div>
    <div class="table-responsive"><table class="table table-sm table-striped mb-0 small">
      <thead><tr><th><?= L('Date', 'तारीख') ?></th><?= $th() ?></tr></thead>
      <tbody><?php foreach ($hist as $r): ?><tr><td class="text-nowrap"><?= e(fmt_date($r['report_date'])) ?></td><?= $td($r) ?></tr><?php endforeach; ?>
      <?php if (!$hist): ?><tr><td colspan="21" class="text-muted">Abhi koi report nahi.</td></tr><?php endif; ?></tbody>
    </table></div></div>
<?php
    View::footer();
    exit;
}

// ====================================================================== Admin / Region Manager: correct one branch
if (q('edit') !== '') {
    $bid = (int) q('branch_id');
    $date = date_or(q('date'), $today);
    if (!$canWrite($bid, $date)) {
        flash('danger', 'Ye branch aapke region ki nahi hai ya tarikh galat hai. / यह शाखा आपके रीजन की नहीं है');
        redirect('/branch_report.php');
    }
    $br = DB::one('SELECT br.*, rg.name AS region_name FROM branches br LEFT JOIN regions rg ON rg.id = br.region_id WHERE br.id = ?', [$bid]);
    $rep = BranchReport::find($bid, $date);
    View::header('Branch Report · ' . $br['name'], 'branchreport');
    ?>
  <form class="row g-2 align-items-end mb-3"><input type="hidden" name="edit" value="1"><input type="hidden" name="branch_id" value="<?= $bid ?>">
    <div class="col-6 col-md-3"><label class="form-label small"><?= L('Date', 'तारीख') ?></label><input type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>" class="form-control form-control-sm" onchange="this.form.submit()"></div>
    <div class="col-6 col-md-3"><a href="/branch_report.php?d=<?= e($date) ?>" class="btn btn-sm btn-outline-secondary">← <?= L('Back', 'वापस') ?></a></div>
  </form>
  <div class="card"><div class="card-header"><i class="bi bi-building"></i> <?= e($br['name']) ?> <span class="text-muted small">· <?= e($br['region_name'] ?? '—') ?> · <?= e(fmt_date($date)) ?></span>
      <?= $rep ? ' <span class="badge text-bg-success">saved ' . e(fmt_dt($rep['updated_at'])) . ' · ' . e($rep['source']) . '</span>' : ' <span class="badge text-bg-danger">not filled</span>' ?></div>
    <div class="card-body"><?= $form($bid, $date, $rep, true) ?></div></div>
<?php
    View::footer();
    exit;
}

// ====================================================================== Admin / Region Manager: overview
$d = date_or(q('d'), $today);
$from = date_or(q('from'), date('Y-m-01'));
$to = date_or(q('to'), $today);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$visibleRegions = Scope::regionsVisible($user);
$regionF = (string) q('region_id');
$branchF = (string) q('branch_id');
$filters = ['region_id' => $regionF, 'branch_id' => $branchF];
$status = BranchReport::statusRows($d, $user, $filters);
$cnt = ['submitted' => 0, 'pending' => 0, 'off' => 0];
foreach ($status as $s) {
    $cnt[$s['status'] === 'submitted' ? 'submitted' : ($s['status'] === 'pending' ? 'pending' : 'off')]++;
}
$tot = BranchReport::totals($from, $to, $user, $filters);
$scopeBranches = Scope::branches($user);

View::header('Branch Report / शाखा रिपोर्ट', 'branchreport');
?>
<form class="row g-2 align-items-end mb-3">
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Status date', 'स्थिति की तारीख') ?></label><input type="date" name="d" value="<?= e($d) ?>" max="<?= e($today) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Total from', 'कुल – से') ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('to', 'तक') ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
  <?php if (count($visibleRegions) > 1): ?>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('RO (Region)', 'रीजन (RO)') ?></label>
    <select name="region_id" class="form-select form-select-sm"><option value="">— All ROs —</option>
      <?php foreach ($visibleRegions as $rg): ?><option value="<?= (int) $rg['id'] ?>" <?= $regionF === (string) $rg['id'] ? 'selected' : '' ?>><?= e($rg['name']) ?></option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <div class="col-6 col-md-2"><label class="form-label small"><?= L('Branch', 'शाखा') ?></label>
    <select name="branch_id" class="form-select form-select-sm"><option value="">— All branches —</option>
      <?php foreach ($scopeBranches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $branchF === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-6 col-md-2"><button class="btn btn-sm btn-success w-100"><i class="bi bi-funnel"></i> <?= L('Apply', 'लागू') ?></button></div>
</form>

<div class="row g-2 mb-3">
  <div class="col-4"><div class="card border-success"><div class="card-body py-2 text-success"><div class="small"><?= L('Submitted', 'जमा') ?> · <?= e(fmt_date($d)) ?></div><div class="fs-4 fw-bold"><?= $cnt['submitted'] ?></div></div></div></div>
  <div class="col-4"><div class="card border-danger"><div class="card-body py-2 text-danger"><div class="small"><?= L('Pending', 'बाकी') ?></div><div class="fs-4 fw-bold"><?= $cnt['pending'] ?></div></div></div></div>
  <div class="col-4"><div class="card"><div class="card-body py-2 text-secondary"><div class="small"><?= L('Holiday / off', 'अवकाश') ?></div><div class="fs-4 fw-bold"><?= $cnt['off'] ?></div></div></div></div>
</div>

<div class="card mb-3"><div class="card-header"><i class="bi bi-list-check"></i> <?= L('Branch-wise status', 'शाखा अनुसार स्थिति') ?> – <?= e(fmt_date($d)) ?></div>
  <div class="table-responsive"><table class="table table-sm table-striped mb-0 align-middle">
    <thead><tr><th>RO</th><th><?= L('Branch', 'शाखा') ?></th><th><?= L('Branch Manager', 'ब्रांच मैनेजर') ?></th><th><?= L('Status', 'स्थिति') ?></th><th><?= L('Saved', 'सेव') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($status as $s): ?>
      <tr><td class="small text-muted"><?= e($s['region_name'] ?? '—') ?></td><td><?= e($s['name']) ?></td>
        <td class="small"><?= $s['managers'] ? e(implode(', ', $s['managers'])) : '<span class="text-warning">— no BM —</span>' ?></td>
        <td><?= $chip($s['status']) ?></td>
        <td class="small"><?= $s['report'] ? e(date('h:i A', strtotime((string) $s['report']['updated_at']))) . ($s['report']['by_name'] ? ' · ' . e($s['report']['by_name']) : '') : '—' ?></td>
        <td><a class="btn btn-sm btn-outline-secondary py-0" href="/branch_report.php?edit=1&branch_id=<?= (int) $s['id'] ?>&date=<?= e($d) ?>"><i class="bi bi-pencil"></i> <?= $s['report'] ? 'View / edit' : 'Fill' ?></a></td></tr>
    <?php endforeach; if (!$status): ?><tr><td colspan="6" class="text-muted">Koi branch nahi.</td></tr><?php endif; ?></tbody>
  </table></div></div>

<div class="card mb-3"><div class="card-header"><i class="bi bi-diagram-3"></i> <?= L('RO-wise total', 'RO अनुसार कुल') ?> <span class="small text-muted"><?= e(fmt_date($from)) ?> → <?= e(fmt_date($to)) ?></span></div>
  <div class="table-responsive"><table class="table table-sm table-striped mb-0 small">
    <thead><tr><th>RO</th><th class="text-end"><?= L('Branches', 'शाखाएँ') ?></th><th class="text-end" title="branch-days reported">Days</th><?= $th() ?></tr></thead>
    <tbody><?php foreach ($tot['regions'] as $r): ?><tr><td class="fw-semibold text-nowrap"><?= e($r['name']) ?></td><td class="text-end"><?= (int) $r['branches'] ?></td><td class="text-end"><?= (int) $r['days'] ?></td><?= $td($r) ?></tr><?php endforeach; ?>
    <?php if (!$tot['regions']): ?><tr><td colspan="23" class="text-muted">Is period me koi branch report nahi.</td></tr><?php endif; ?></tbody>
    <?php if (count($tot['regions']) > 1): ?><tfoot><tr class="table-active fw-bold"><td>All ROs (total)</td><td></td><td class="text-end"><?= (int) $tot['total']['days'] ?></td><?= $td($tot['total']) ?></tr></tfoot><?php endif; ?>
  </table></div></div>

<div class="card mb-3"><div class="card-header"><i class="bi bi-building"></i> <?= L('Branch-wise total', 'शाखा अनुसार कुल') ?> <span class="small text-muted"><?= e(fmt_date($from)) ?> → <?= e(fmt_date($to)) ?></span></div>
  <div class="table-responsive" style="max-height:480px"><table class="table table-sm table-striped mb-0 small">
    <thead><tr><th><?= L('Branch', 'शाखा') ?></th><th>RO</th><th class="text-end">Days</th><?= $th() ?></tr></thead>
    <tbody><?php foreach ($tot['branches'] as $r): ?><tr><td class="text-nowrap"><?= e($r['name']) ?></td><td class="text-muted text-nowrap"><?= e($r['region_name']) ?></td><td class="text-end"><?= (int) $r['days'] ?></td><?= $td($r) ?></tr><?php endforeach; ?>
    <?php if (!$tot['branches']): ?><tr><td colspan="23" class="text-muted">—</td></tr><?php endif; ?></tbody>
  </table></div>
  <div class="card-footer small text-muted">₹ = lakh mein. <?= L('Hover a column header for the full name.', 'पूरा नाम देखने के लिए कॉलम पर माउस रखें।') ?></div></div>

<?php if ($isAdmin):
    $s = Settings::all();
    $bmUsers = DB::all("SELECT u.id, u.name, u.phone, (SELECT MAX(last_used_at) FROM cbc_app_tokens t WHERE t.user_id = u.id) last_seen
                        FROM users u WHERE u.role = 'manager' AND u.is_active = 1 AND EXISTS (SELECT 1 FROM cbc_app_tokens t WHERE t.user_id = u.id)
                        ORDER BY last_seen DESC"); ?>
<div class="row g-3" id="reminder">
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-alarm"></i> <?= L('Branch Manager reminder (app call + alarm)', 'ब्रांच मैनेजर रिमाइंडर (ऐप कॉल + अलार्म)') ?></div><div class="card-body">
    <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="settings">
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="bm_alarm_enabled" value="1" id="bmen" <?= $s['bm_alarm_enabled'] === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="bmen"><?= L('Ring the Branch Manager while the branch report is pending', 'शाखा रिपोर्ट बाकी हो तो ब्रांच मैनेजर का फ़ोन बजे') ?></label></div></div>
      <div class="col-6"><label class="form-label small"><?= L('📞 Call time', 'कॉल का समय') ?></label><input type="time" name="bm_call_time" value="<?= e($s['bm_call_time']) ?>" class="form-control form-control-sm" required></div>
      <div class="col-6"><label class="form-label small"><?= L('⏰ Alarm time', 'अलार्म का समय') ?></label><input type="time" name="bm_alarm_time" value="<?= e($s['bm_alarm_time']) ?>" class="form-control form-control-sm" required></div>
      <div class="col-12"><label class="form-label small"><?= L('Call text (phone speaks it in Hindi)', 'कॉल टेक्स्ट') ?></label><textarea name="bm_call_text" rows="2" maxlength="1000" class="form-control form-control-sm" required><?= e($s['bm_call_text']) ?></textarea></div>
      <div class="col-12"><label class="form-label small"><?= L('Alarm text', 'अलार्म टेक्स्ट') ?></label><textarea name="bm_alarm_text" rows="2" maxlength="1000" class="form-control form-control-sm" required><?= e($s['bm_alarm_text']) ?></textarea></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="bm_skip_2nd_4th_sat" value="1" id="bmsat" <?= $s['bm_skip_2nd_4th_sat'] === '1' ? 'checked' : '' ?>>
        <label class="form-check-label small" for="bmsat"><?= L('No report / reminder on Sunday and the 2nd & 4th Saturday (bank holidays). Other holidays: Holidays page.', 'रविवार और दूसरे/चौथे शनिवार को रिपोर्ट/रिमाइंडर नहीं।') ?></label></div></div>
      <div class="col-12"><button class="btn btn-success btn-sm"><i class="bi bi-save"></i> <?= L('Save', 'सेव करें') ?></button></div>
    </form>
    <p class="small text-muted mt-2 mb-0">The phone rings at the call time and the alarm time (and every 10 min after) only if that Branch Manager's report is still pending. After a report is saved it stops.</p>
  </div></div></div>
  <div class="col-lg-5"><div class="card"><div class="card-header"><?= L('Send a test now', 'अभी टेस्ट भेजें') ?></div><div class="card-body">
    <form method="post" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="test">
      <div class="col-12"><select name="target" class="form-select form-select-sm" required><option value="">— Branch Manager (logged into app) —</option>
        <?php foreach ($bmUsers as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e(Phone::display($u['phone'])) ?>)</option><?php endforeach; ?>
      </select></div>
      <div class="col-6"><button name="mode" value="call" class="btn btn-sm btn-outline-success w-100"><i class="bi bi-telephone-inbound"></i> Test call</button></div>
      <div class="col-6"><button name="mode" value="alarm" class="btn btn-sm btn-outline-warning w-100"><i class="bi bi-alarm"></i> Test alarm</button></div>
    </form>
    <p class="small text-muted mt-2 mb-0"><?= count($bmUsers) ?> Branch Manager(s) logged into the app.</p>
  </div></div></div>
</div>
<?php endif; ?>
<?php View::footer();
