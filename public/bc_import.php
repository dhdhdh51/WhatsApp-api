<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Phone;
use App\Queue;
use App\View;

$user = Auth::requireAdmin();

if (q('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bc_import_template.csv"');
    echo "\xEF\xBB\xBFbc_code,name,phone,branch,area,block,is_active\n";
    echo "BC101,Ramesh Kumar,9876543210,Sitapur Main,Sitapur,Biswan,1\n";
    exit;
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        flash('danger', 'Upload a CSV file (max 5 MB) / CSV फ़ाइल अपलोड करें');
        redirect('/bc_import.php');
    }
    $fh = fopen($f['tmp_name'], 'r');
    $first = (string) fgets($fh);
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $head = array_map(static fn ($h) => strtolower(trim((string) $h)), str_getcsv($first, $delim, '"', '\\'));
    $aliases = ['bc_id' => 'bc_code', 'bc id' => 'bc_code', 'code' => 'bc_code', 'mobile' => 'phone', 'whatsapp' => 'phone',
        'whatsapp number' => 'phone', 'active' => 'is_active', 'status' => 'is_active'];
    $head = array_map(static fn ($h) => $aliases[$h] ?? $h, $head);
    foreach (['bc_code', 'name', 'phone'] as $req) {
        if (!in_array($req, $head, true)) {
            flash('danger', "Missing column '{$req}'. Required: bc_code, name, phone (optional: branch, area, block, is_active)");
            redirect('/bc_import.php');
        }
    }

    $result = ['created' => 0, 'updated' => 0, 'welcome' => 0, 'errors' => []];
    $sendWelcome = p('send_welcome') === '1';
    $line = 1;
    $branchCache = [];
    while (($cols = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $line++;
        if (count(array_filter($cols, static fn ($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($head as $i => $h) {
            $row[$h] = trim((string) ($cols[$i] ?? ''));
        }
        $code = strtoupper($row['bc_code']);
        $phone = Phone::normalize($row['phone']);
        if (!preg_match('/^[A-Z0-9_\-\/]{1,40}$/', $code)) {
            $result['errors'][] = "Line {$line}: invalid bc_code '{$row['bc_code']}'";
            continue;
        }
        if ($row['name'] === '') {
            $result['errors'][] = "Line {$line}: name missing";
            continue;
        }
        if (!$phone) {
            $result['errors'][] = "Line {$line}: invalid Indian mobile '{$row['phone']}'";
            continue;
        }
        $branchId = null;
        $bn = $row['branch'] ?? '';
        if ($bn !== '') {
            if (!isset($branchCache[$bn])) {
                $b = DB::one('SELECT id FROM branches WHERE name = ?', [$bn]);
                $branchCache[$bn] = $b ? (int) $b['id']
                    : DB::insert('INSERT INTO branches (name, area) VALUES (?, ?)', [mb_substr($bn, 0, 120), ($row['area'] ?? '') ?: null]);
            }
            $branchId = $branchCache[$bn];
        }
        $active = isset($row['is_active']) && $row['is_active'] !== ''
            ? (in_array(strtolower($row['is_active']), ['1', 'yes', 'y', 'active', 'true'], true) ? 1 : 0) : 1;

        $existing = DB::one('SELECT id, phone FROM bcs WHERE bc_code = ?', [$code]);
        $dupPhone = DB::one('SELECT id, bc_code FROM bcs WHERE phone = ? AND bc_code <> ?', [$phone, $code]);
        if ($dupPhone) {
            $result['errors'][] = "Line {$line}: phone {$phone} already belongs to {$dupPhone['bc_code']}";
            continue;
        }
        if ($existing) {
            DB::exec('UPDATE bcs SET name = ?, phone = ?, branch_id = COALESCE(?, branch_id), block = ?, is_active = ?' . ($existing['phone'] !== $phone ? ', wa_lid = NULL' : '') . ' WHERE id = ?',
                [mb_substr($row['name'], 0, 120), $phone, $branchId, ($row['block'] ?? '') ?: null, $active, $existing['id']]);
            $result['updated']++;
        } else {
            $newId = DB::insert('INSERT INTO bcs (bc_code, name, phone, branch_id, block, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$code, mb_substr($row['name'], 0, 120), $phone, $branchId, ($row['block'] ?? '') ?: null, $active]);
            $result['created']++;
            if ($sendWelcome && ($fresh = DB::one('SELECT * FROM bcs WHERE id = ?', [$newId])) && Queue::welcome($fresh, (int) $user['id'])) {
                $result['welcome']++;
            }
        }
    }
    fclose($fh);
    Audit::log((int) $user['id'], 'bc_import', 'bc', null, ['created' => $result['created'], 'updated' => $result['updated'], 'errors' => count($result['errors'])]);
}

View::header('CSV import / CSV इम्पोर्ट', 'bcs');
?>
<?php if ($result): ?>
  <div class="alert alert-<?= $result['errors'] ? 'warning' : 'success' ?>">
    Created / जोड़े: <strong><?= $result['created'] ?></strong>, Updated / अपडेट: <strong><?= $result['updated'] ?></strong>, Errors / त्रुटियाँ: <strong><?= count($result['errors']) ?></strong>,
    Welcome queued / स्वागत संदेश: <strong><?= (int) $result['welcome'] ?></strong>
    <?php if ($result['errors']): ?><ul class="mb-0 mt-2 small"><?php foreach (array_slice($result['errors'], 0, 200) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
<?php endif; ?>
<div class="card"><div class="card-body">
  <p class="small">Columns / कॉलम: <code>bc_code, name, phone, branch, area, block, is_active</code>. Existing BCs (same <code>bc_code</code>) are updated; unknown branches are created.
    Phone: 10-digit Indian mobile, +91 / 0 prefix allowed.
    Welcome messages go out one by one through the safe queue (daily limit applies – large imports may take more than a day during warm-up).</p>
  <a href="/bc_import.php?template=1" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-file-earmark-spreadsheet"></i> <?= L('Download template', 'टेम्पलेट डाउनलोड') ?></a>
  <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"><?= Csrf::field() ?>
    <input type="file" name="csv" accept=".csv,text/csv" class="form-control" style="max-width:360px" required>
    <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="sw" checked>
      <label class="form-check-label small" for="sw"><?= L('Send welcome message to new BCs', 'नए बीसी को स्वागत संदेश भेजें') ?></label></div>
    <button class="btn btn-success"><i class="bi bi-upload"></i> <?= L('Import', 'इम्पोर्ट') ?></button>
  </form>
</div></div>
<?php View::footer();
