<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

use App\Audit;
use App\Auth;
use App\Csrf;
use App\DB;
use App\Export\SheetReader;
use App\Phone;
use App\Queue;
use App\View;

$user = Auth::requireAdmin();

if (q('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bc_import_template.csv"');
    echo "\xEF\xBB\xBFbc_code,name,phone,branch,area,block,cbc,cbc_phone,is_active\n";
    echo "BC101,Ramesh Kumar,9876543210,Sitapur Main,Sitapur,Biswan,Suresh Verma,9123456780,1\n";
    exit;
}

/**
 * Maps a header cell to our field name. Accepts common English/Hindi headers
 * ("BC Name", "Mobile No", "CBC Name", "Kiosk ID", "बीसी का नाम", ...).
 */
function import_field(string $h): ?string
{
    $h = mb_strtolower(trim(preg_replace('/[\s._\-\/()]+/u', ' ', $h) ?? ''));
    if ($h === '' || preg_match('/^(s|sr|sl|serial) ?(no|number|n)\b|^sno$|^#$|क्रम/u', $h)) {
        return null;
    }
    $isCbc = (bool) preg_match('/cbc|सीबीसी|corporate/u', $h); // CBCNAME, CBC Name, cbc_mobile
    $isPhone = (bool) preg_match('/mob|phone|contact|whats|number|\bno\b|मोबाइल|फोन|नंबर/u', $h) && !preg_match('/\bid\b|code|kiosk|account|a c/u', $h);
    if ($isCbc) {
        return $isPhone ? 'cbc_phone' : (preg_match('/name|नाम|^cbc$|company/u', $h) ? 'cbc' : null);
    }
    if (preg_match('/supervisor|सुपरवाइजर/u', $h)) {
        return null;
    }
    if ($isPhone) {
        return 'phone';
    }
    if (preg_match('/bc ?code|bc ?id|kiosk|csp|agent ?id|^code$|^id$|कोड|आईडी/u', $h)) {
        return 'bc_code';
    }
    if (preg_match('/branch ?name|शाखा का नाम|शाखा नाम/u', $h)) {
        return 'branch';
    }
    if (preg_match('/branch|sol|शाखा/u', $h)) {
        return 'branch_code'; // used only when there is no branch-name column
    }
    if (preg_match('/block|ब्लॉक/u', $h)) {
        return 'block';
    }
    if (preg_match('/area|district|tehsil|क्षेत्र|जिला/u', $h)) {
        return 'area';
    }
    if (preg_match('/active|status/u', $h)) {
        return 'is_active';
    }
    if (preg_match('/name|नाम/u', $h)) {
        return 'name';
    }
    return null;
}

/** Finds or creates a CBC login. Returns [id, ?generated-credentials]. */
function import_cbc(string $name, ?string $phone, array &$cache, string $fixedPass = ''): array
{
    $key = mb_strtolower(trim($name));
    if (isset($cache[$key])) {
        return [$cache[$key], null];
    }
    $u = DB::one("SELECT id, phone FROM users WHERE role = 'cbc' AND LOWER(name) = ?", [$key]);
    if (!$u && $phone) {
        $u = DB::one("SELECT id, phone FROM users WHERE role = 'cbc' AND phone = ?", [$phone]);
    }
    if ($u) {
        if ($phone && !$u['phone']) {
            DB::exec('UPDATE users SET phone = ?, receive_summary = 1 WHERE id = ?', [$phone, $u['id']]);
        }
        return [$cache[$key] = (int) $u['id'], null];
    }
    $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) (function_exists('transliterator_transliterate')
        ? transliterator_transliterate('Any-Latin; Latin-ASCII', $name) : $name)));
    $base = 'cbc_' . (substr($base, 0, 20) ?: 'user');
    $username = $base;
    for ($i = 2; DB::val('SELECT COUNT(*) FROM users WHERE username = ?', [$username]); $i++) {
        $username = $base . $i;
    }
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $pass = $fixedPass;
    for ($i = 0; $pass === '' || ($fixedPass === '' && $i < 10); $i++) {
        $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $id = DB::insert("INSERT INTO users (name, username, password_hash, role, phone, receive_summary, is_active) VALUES (?, ?, ?, 'cbc', ?, ?, 1)",
        [mb_substr($name, 0, 120), $username, password_hash($pass, PASSWORD_DEFAULT), $phone, $phone ? 1 : 0]);
    return [$cache[$key] = $id, ['name' => $name, 'username' => $username, 'password' => $pass, 'phone' => $phone]];
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) {
        flash('danger', 'Upload an Excel (.xlsx) or CSV file (max 8 MB) / एक्सेल या CSV फ़ाइल अपलोड करें');
        redirect('/bc_import.php');
    }
    $fixedPass = (string) ($_POST['cbc_password'] ?? '');
    if ($fixedPass !== '' && mb_strlen($fixedPass) < 8) {
        flash('danger', 'CBC password must be at least 8 characters / पासवर्ड कम से कम 8 अक्षर');
        redirect('/bc_import.php');
    }
    try {
        $sheet = SheetReader::read($f['tmp_name'], (string) $f['name']);
    } catch (Throwable $e) {
        flash('danger', 'Could not read the file: ' . $e->getMessage());
        redirect('/bc_import.php');
    }

    // header row = first row (within the first 15) that has a name and a phone column
    $headerIdx = null;
    $map = [];
    foreach (array_slice($sheet, 0, 15, true) as $i => $cells) {
        $m = [];
        foreach ($cells as $ci => $c) {
            $fld = import_field((string) $c);
            if ($fld && !in_array($fld, $m, true)) {
                $m[$ci] = $fld;
            }
        }
        if (in_array('name', $m, true) && in_array('phone', $m, true)) {
            $headerIdx = $i;
            $map = $m;
            break;
        }
    }
    if ($headerIdx === null) {
        flash('danger', 'Could not find the header row. The sheet needs at least a "Name" and a "Mobile" column. / नाम और मोबाइल कॉलम नहीं मिला');
        redirect('/bc_import.php');
    }

    $result = ['created' => 0, 'updated' => 0, 'welcome' => 0, 'errors' => [], 'cbc_new' => [], 'columns' => $map, 'header_row' => $headerIdx + 1];
    $sendWelcome = p('send_welcome') === '1';
    $branchCache = [];
    $cbcCache = [];
    foreach ($sheet as $ri => $cells) {
        if ($ri <= $headerIdx) {
            continue;
        }
        $line = $ri + 1;
        if (count(array_filter($cells, static fn ($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($map as $ci => $fld) {
            $row[$fld] = trim((string) ($cells[$ci] ?? ''));
        }
        $name = $row['name'] ?? '';
        $phone = Phone::normalize($row['phone'] ?? '');
        if ($name === '' && ($row['phone'] ?? '') === '') {
            continue; // totals / blank lines
        }
        if ($name === '') {
            $result['errors'][] = "Row {$line}: name missing";
            continue;
        }
        if (!$phone) {
            $result['errors'][] = "Row {$line}: {$name} – invalid Indian mobile '" . ($row['phone'] ?? '') . "'";
            continue;
        }
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_\-\/]/', '', $row['bc_code'] ?? ''));
        if ($code === '') {
            $existingByPhone = DB::one('SELECT bc_code FROM bcs WHERE phone = ?', [$phone]);
            $code = $existingByPhone['bc_code'] ?? ('BC' . $phone);
        }
        $code = substr($code, 0, 40);

        $branchId = null;
        $bn = ($row['branch'] ?? '') !== '' ? $row['branch'] : ($row['branch_code'] ?? '');
        if ($bn !== '') {
            if (!isset($branchCache[$bn])) {
                $b = DB::one('SELECT id FROM branches WHERE name = ?', [$bn]);
                $branchCache[$bn] = $b ? (int) $b['id']
                    : DB::insert('INSERT INTO branches (name, area) VALUES (?, ?)', [mb_substr($bn, 0, 120), ($row['area'] ?? '') ?: null]);
            }
            $branchId = $branchCache[$bn];
        }
        $cbcId = null;
        if (($row['cbc'] ?? '') !== '') {
            [$cbcId, $creds] = import_cbc($row['cbc'], Phone::normalize($row['cbc_phone'] ?? ''), $cbcCache, $fixedPass);
            if ($creds) {
                $result['cbc_new'][] = $creds;
            }
        }
        $active = isset($row['is_active']) && $row['is_active'] !== ''
            ? (in_array(strtolower($row['is_active']), ['1', 'yes', 'y', 'active', 'true', 'सक्रिय'], true) ? 1 : 0) : 1;

        $existing = DB::one('SELECT id, phone FROM bcs WHERE bc_code = ?', [$code]);
        $dupPhone = DB::one('SELECT id, bc_code FROM bcs WHERE phone = ? AND bc_code <> ?', [$phone, $code]);
        if ($dupPhone) {
            $result['errors'][] = "Row {$line}: phone {$phone} already belongs to {$dupPhone['bc_code']}";
            continue;
        }
        if ($existing) {
            DB::exec('UPDATE bcs SET name = ?, phone = ?, branch_id = COALESCE(?, branch_id), cbc_id = COALESCE(?, cbc_id), block = COALESCE(?, block), is_active = ?'
                . ($existing['phone'] !== $phone ? ', wa_lid = NULL' : '') . ' WHERE id = ?',
                [mb_substr($name, 0, 120), $phone, $branchId, $cbcId, ($row['block'] ?? '') ?: null, $active, $existing['id']]);
            $result['updated']++;
        } else {
            $newId = DB::insert('INSERT INTO bcs (bc_code, name, phone, branch_id, cbc_id, block, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$code, mb_substr($name, 0, 120), $phone, $branchId, $cbcId, ($row['block'] ?? '') ?: null, $active]);
            $result['created']++;
            if ($sendWelcome && ($fresh = DB::one('SELECT * FROM bcs WHERE id = ?', [$newId])) && Queue::welcome($fresh, (int) $user['id'])) {
                $result['welcome']++;
            }
        }
    }
    Audit::log((int) $user['id'], 'bc_import', 'bc', null, ['created' => $result['created'], 'updated' => $result['updated'],
        'errors' => count($result['errors']), 'cbc_created' => count($result['cbc_new'])]);
}

View::header('Import BCs (Excel / CSV) / बीसी इम्पोर्ट', 'bcs');
?>
<?php if ($result): ?>
  <div class="alert alert-<?= $result['errors'] ? 'warning' : 'success' ?>">
    Created / जोड़े: <strong><?= $result['created'] ?></strong>, Updated / अपडेट: <strong><?= $result['updated'] ?></strong>, Errors / त्रुटियाँ: <strong><?= count($result['errors']) ?></strong>,
    Welcome queued / स्वागत संदेश: <strong><?= (int) $result['welcome'] ?></strong>, New CBC logins / नए सीबीसी लॉगिन: <strong><?= count($result['cbc_new']) ?></strong>
    <div class="small mt-1 text-muted">Header row <?= (int) $result['header_row'] ?> · columns read: <?= e(implode(', ', array_unique($result['columns']))) ?></div>
    <?php if ($result['errors']): ?><ul class="mb-0 mt-2 small"><?php foreach (array_slice($result['errors'], 0, 200) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php if ($result['cbc_new']): ?>
  <div class="card border-info mb-3"><div class="card-header bg-info-subtle"><i class="bi bi-key"></i> <?= L('New CBC logins – note these passwords now, they are shown only once', 'नए सीबीसी लॉगिन – पासवर्ड अभी नोट करें, दोबारा नहीं दिखेंगे') ?></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>CBC</th><th>Username</th><th>Password</th><th>Mobile</th></tr></thead><tbody>
    <?php $credText = 'Login: ' . rtrim((string) App\Env::get('APP_URL'), '/') . "\n"; foreach ($result['cbc_new'] as $c): $credText .= "{$c['name']} – {$c['username']} / {$c['password']}\n"; ?>
      <tr><td><?= e($c['name']) ?></td><td class="font-monospace"><?= e($c['username']) ?></td><td class="font-monospace"><?= e($c['password']) ?></td><td><?= e(Phone::display($c['phone'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <div class="card-body py-2"><button type="button" class="btn btn-sm btn-outline-dark" data-copy="<?= e($credText) ?>"><i class="bi bi-clipboard"></i> Copy all</button></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
<div class="card"><div class="card-body">
  <p class="small">Upload your <strong>Excel (.xlsx)</strong> or CSV list directly. Column names are recognised automatically, e.g.
    <code>BC Name</code>, <code>Mobile No</code>, <code>BC Code / Kiosk ID</code>, <code>Branch</code>, <code>Block</code>, <code>CBC Name</code>, <code>CBC Mobile</code>.
    Only <strong>Name</strong> and <strong>Mobile</strong> are required. A CBC that does not exist yet gets a <strong>login automatically</strong> (role CBC, sees only own BCs).
    Existing BCs (same BC code or mobile) are updated.
    <br>अपनी एक्सेल लिस्ट सीधे अपलोड करें। नया CBC हो तो उसका लॉगिन अपने-आप बन जाएगा।</p>
  <a href="/bc_import.php?template=1" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-file-earmark-spreadsheet"></i> <?= L('Download template', 'टेम्पलेट डाउनलोड') ?></a>
  <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap"><?= Csrf::field() ?>
    <input type="file" name="csv" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control" style="max-width:360px" required>
    <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="sw" checked>
      <label class="form-check-label small" for="sw"><?= L('Send welcome message to new BCs', 'नए बीसी को स्वागत संदेश भेजें') ?></label></div>
    <input type="text" name="cbc_password" class="form-control" style="max-width:260px" minlength="8" maxlength="100" autocomplete="off"
      placeholder="Password for new CBC logins (blank = random)">
    <button class="btn btn-success"><i class="bi bi-upload"></i> <?= L('Import', 'इम्पोर्ट') ?></button>
  </form>
  <p class="small text-muted mt-2 mb-0">Welcome messages go out one by one through the safe queue (daily limit applies – large imports may take more than a day during warm-up).</p>
</div></div>
<?php View::footer();
