<?php
declare(strict_types=1);

namespace App;

/**
 * Branch Manager daily branch report: 20 business parameters per branch per day, filled from the
 * app (or the panel). Counts are whole numbers; amounts are in Rs lakh with up to 2 decimals.
 * A branch is "off" on the configured weekly off day(s), on the 2nd / 4th Saturday (setting) and on
 * holidays (all branches or that branch). Reminders ring the Branch Manager's phone only while a
 * working-day report is still pending.
 */
final class BranchReport
{
    /** key => [label, short column header, unit]   unit: 'count' = whole number, 'amount' = Rs lakh */
    public const ITEMS = [
        'sb_account'     => ['बचत खाता (Saving Account)', 'SB A/c', 'count'],
        'cd_account'     => ['CD Account', 'CD A/c', 'count'],
        'pmjjby'         => ['JBY (PMJJBY)', 'JBY', 'count'],
        'pmsby'          => ['SBY (PMSBY)', 'SBY', 'count'],
        'apy'            => ['APY', 'APY', 'count'],
        'ppf'            => ['PPF', 'PPF', 'count'],
        'ssy'            => ['SSY (सुकन्या समृद्धि योजना)', 'SSY', 'count'],
        'cent_eez'       => ['CENT EEZ', 'EEZ', 'count'],
        'cent_vyapar'    => ['CENT व्यापार साथी', 'Vyapar', 'count'],
        'krm_ots'        => ['KRM OTS', 'KRM OTS ₹', 'amount'],
        'od2'            => ['OD-2', 'OD-2', 'count'],
        'npa_recovery'   => ['NPA Recovery', 'NPA ₹', 'amount'],
        'sma_upgrade'    => ['SMA Upgradation', 'SMA Up', 'count'],
        'cent_queen'     => ['CENT Queen', 'Queen', 'count'],
        'salary_account' => ['Salary Account', 'Salary', 'count'],
        'retail_disb'    => ['Retail Disbursement', 'Retail ₹', 'amount'],
        'agri_disb'      => ['Agriculture Disbursement', 'Agri ₹', 'amount'],
        'msme_disb'      => ['MSME Disbursement', 'MSME ₹', 'amount'],
        'gen_insurance'  => ['General Insurance', 'Gen Ins', 'count'],
        'sound_box'      => ['Sound Box', 'Sound Box', 'count'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function isAmount(string $key): bool
    {
        return (self::ITEMS[$key][2] ?? '') === 'amount';
    }

    /** Item list for the app: [{key, label, short, unit}]. */
    public static function itemList(): array
    {
        $out = [];
        foreach (self::ITEMS as $k => [$label, $short, $unit]) {
            $out[] = ['key' => $k, 'label' => $label, 'short' => $short, 'unit' => $unit];
        }
        return $out;
    }

    /** "COALESCE(SUM(r.sb_account),0) sb_account, ..." */
    public static function sumSql(string $alias = 'r'): string
    {
        return implode(', ', array_map(static fn ($k) => "COALESCE(SUM({$alias}.{$k}),0) {$k}", self::keys()));
    }

    // ------------------------------------------------------------------ values

    /**
     * Validate raw input (strings). Empty = 0.
     * @return array{0: array<string,string>|null, 1: string} [values, error]
     */
    public static function parse(array $raw): array
    {
        $vals = [];
        foreach (self::ITEMS as $k => [$label, , $unit]) {
            $v = $raw[$k] ?? '';
            $v = is_scalar($v) ? trim((string) $v) : '';
            $v = $v === '' ? '0' : $v;
            if ($unit === 'amount') {
                if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v)) {
                    return [null, "{$label}: ₹ लाख में number लिखें (जैसे 2.5), ज़्यादा से ज़्यादा 2 decimal."];
                }
            } elseif (!preg_match('/^\d{1,5}$/', $v)) {
                return [null, "{$label}: सिर्फ़ पूरा number (0 या उससे ज़्यादा) लिखें।"];
            }
            $vals[$k] = $v;
        }
        return [$vals, ''];
    }

    /** Stored row (or SUM row) -> numbers: int for counts, float for amounts. Null stays null. */
    public static function values(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $o = [];
        foreach (self::ITEMS as $k => [, , $unit]) {
            $n = (float) ($row[$k] ?? 0);
            $o[$k] = $unit === 'amount' ? round($n, 2) : (int) round($n);
        }
        return $o;
    }

    /** Display one value: "5" for counts, "2.5" for amounts. */
    public static function fmt(string $key, mixed $v): string
    {
        $n = (float) ($v ?? 0);
        if (self::isAmount($key)) {
            $s = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            return $s === '' ? '0' : $s;
        }
        return (string) (int) round($n);
    }

    public static function find(int $branchId, string $date): ?array
    {
        return DB::one('SELECT * FROM branch_reports WHERE branch_id = ? AND report_date = ?', [$branchId, $date]);
    }

    /**
     * Insert or update the report of a branch for a day.
     * @param array<string,string> $vals validated by parse()
     * @return array{updated: bool}
     */
    public static function save(int $branchId, string $date, array $vals, ?int $userId, string $source): array
    {
        $cols = self::keys();
        $existed = (bool) DB::val('SELECT 1 FROM branch_reports WHERE branch_id = ? AND report_date = ?', [$branchId, $date]);
        $params = [$branchId, $date];
        foreach ($cols as $c) {
            $params[] = $vals[$c] ?? '0';
        }
        array_push($params, $source === 'panel' ? 'panel' : 'app', $userId, $userId);
        DB::exec(
            'INSERT INTO branch_reports (branch_id, report_date, ' . implode(', ', $cols) . ', source, submitted_by, updated_by, version, submitted_at, updated_at)
             VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $cols)) . ',
                source = VALUES(source), updated_by = VALUES(updated_by), version = version + 1, updated_at = NOW()',
            $params
        );
        return ['updated' => $existed];
    }

    // ------------------------------------------------------------------ working days

    /** Sunday (setting bm_weekly_off_days) or the 2nd / 4th Saturday (setting bm_skip_2nd_4th_sat). */
    public static function isWeeklyOff(string $date): bool
    {
        $ts = (int) strtotime($date);
        $dow = (int) date('w', $ts);
        foreach (explode(',', Settings::get('bm_weekly_off_days')) as $d) {
            $d = trim($d);
            if ($d !== '' && ctype_digit($d) && (int) $d === $dow) {
                return true;
            }
        }
        if ($dow === 6 && Settings::bool('bm_skip_2nd_4th_sat')) {
            $nth = intdiv((int) date('j', $ts) - 1, 7) + 1;   // which Saturday of the month (1-5)
            return $nth === 2 || $nth === 4;
        }
        return false;
    }

    /** null = working day; otherwise 'weekly_off' or 'holiday'. */
    public static function offReason(string $date, int $branchId): ?string
    {
        if (DB::val('SELECT COUNT(*) FROM holidays WHERE holiday_date = ? AND (branch_id IS NULL OR branch_id = ?)', [$date, $branchId])) {
            return 'holiday';
        }
        return self::isWeeklyOff($date) ? 'weekly_off' : null;
    }

    public static function offText(?string $reason): string
    {
        return match ($reason) {
            'holiday' => 'अवकाश (holiday)',
            'weekly_off' => 'साप्ताहिक छुट्टी (weekly off)',
            default => '',
        };
    }

    // ------------------------------------------------------------------ Branch Manager

    /** Branches a Branch Manager reports for (user_branches). */
    public static function managerBranches(int $userId): array
    {
        return DB::all(
            'SELECT br.id, br.name, br.area, br.region_id FROM user_branches ub JOIN branches br ON br.id = ub.branch_id
             WHERE ub.user_id = ? ORDER BY br.name',
            [$userId]
        );
    }

    /** This Branch Manager's branches still pending on $date (working day, nothing submitted). */
    public static function pendingFor(int $userId, string $date): array
    {
        $out = [];
        foreach (self::managerBranches($userId) as $b) {
            if (self::find((int) $b['id'], $date) || self::offReason($date, (int) $b['id']) !== null) {
                continue;
            }
            $out[] = $b;
        }
        return $out;
    }

    /** Should a Branch Manager's phone ring now? Only while today's branch report is pending. */
    public static function shouldRing(int $userId): bool
    {
        $today = Calendar::today();
        if (!Settings::bool('bm_alarm_enabled') || !Calendar::isOpen($today)) {
            return false;
        }
        if (DB::val("SELECT role FROM users WHERE id = ? AND is_active = 1", [$userId]) !== 'manager') {
            return false;
        }
        return self::pendingFor($userId, $today) !== [];
    }

    /**
     * Call / alarm config for a Branch Manager phone. The version is offset so it never equals a
     * BC / CBC config version (a phone switching login then always refreshes its alarm files).
     */
    public static function alarmConfig(): array
    {
        $call = Settings::get('bm_call_time');
        $alarm = Settings::get('bm_alarm_time');
        return [
            'alarm_text' => Settings::get('bm_alarm_text'),
            'alarm_audio_url' => '',
            'enabled' => Settings::bool('bm_alarm_enabled'),
            'call_time' => Settings::validTime($call) ? Settings::padTime($call) : '17:30',
            'alarm_time' => Settings::validTime($alarm) ? Settings::padTime($alarm) : '18:30',
            'text' => Settings::get('bm_call_text'),
            'audio_url' => '',
            'version' => 100000 + Settings::int('bm_alarm_version'),
        ];
    }

    // ------------------------------------------------------------------ overview (panel)

    /** Ids of the branches the user may see (null = every branch). */
    public static function scopeBranchIds(array $user): ?array
    {
        return Scope::seesAll($user) ? null : array_map('intval', array_column(Scope::branches($user), 'id'));
    }

    /**
     * Status of every branch visible to $user on $date: submitted | pending | holiday | weekly_off.
     * @param array{region_id?:int|string, branch_id?:int|string} $filters
     */
    public static function statusRows(string $date, array $user, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        $ids = self::scopeBranchIds($user);
        if ($ids !== null) {
            if (!$ids) {
                return [];
            }
            $where[] = 'br.id IN (' . DB::in($ids, $params) . ')';
        }
        if (!empty($filters['region_id'])) {
            $where[] = 'br.region_id = ?';
            $params[] = (int) $filters['region_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'br.id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        $branches = DB::all('SELECT br.id, br.name, br.area, br.region_id, rg.name AS region_name FROM branches br
                             LEFT JOIN regions rg ON rg.id = br.region_id WHERE ' . implode(' AND ', $where) . '
                             ORDER BY rg.name IS NULL, rg.name, br.name', $params);
        if (!$branches) {
            return [];
        }
        $managers = [];
        foreach (DB::all("SELECT ub.branch_id, u.name FROM user_branches ub JOIN users u ON u.id = ub.user_id
                          WHERE u.role = 'manager' AND u.is_active = 1 ORDER BY u.name") as $m) {
            $managers[(int) $m['branch_id']][] = $m['name'];
        }
        $reports = [];
        foreach (DB::all('SELECT r.*, u.name AS by_name FROM branch_reports r LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.submitted_by)
                          WHERE r.report_date = ?', [$date]) as $r) {
            $reports[(int) $r['branch_id']] = $r;
        }
        $allHoliday = false;
        $branchHoliday = [];
        foreach (DB::all('SELECT branch_id FROM holidays WHERE holiday_date = ?', [$date]) as $h) {
            if ($h['branch_id'] === null) {
                $allHoliday = true;
            } else {
                $branchHoliday[(int) $h['branch_id']] = true;
            }
        }
        $weekly = self::isWeeklyOff($date);
        $rows = [];
        foreach ($branches as $b) {
            $id = (int) $b['id'];
            $rep = $reports[$id] ?? null;
            $status = $rep ? 'submitted' : (($allHoliday || isset($branchHoliday[$id])) ? 'holiday' : ($weekly ? 'weekly_off' : 'pending'));
            $rows[] = $b + ['status' => $status, 'managers' => $managers[$id] ?? [], 'report' => $rep];
        }
        return $rows;
    }

    /**
     * Totals for a period within the user's scope.
     * @return array{branches: list<array>, regions: list<array>, total: array}
     */
    public static function totals(string $from, string $to, array $user, array $filters = []): array
    {
        $where = ['r.report_date BETWEEN ? AND ?'];
        $params = [$from, $to];
        $ids = self::scopeBranchIds($user);
        if ($ids !== null) {
            if (!$ids) {
                return ['branches' => [], 'regions' => [], 'total' => ['days' => 0] + array_fill_keys(self::keys(), 0)];
            }
            $where[] = 'br.id IN (' . DB::in($ids, $params) . ')';
        }
        if (!empty($filters['region_id'])) {
            $where[] = 'br.region_id = ?';
            $params[] = (int) $filters['region_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'br.id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        $rows = DB::all('SELECT br.id, br.name, br.region_id, COALESCE(rg.name, \'—\') AS region_name, COUNT(r.id) AS days, ' . self::sumSql('r') . '
                         FROM branch_reports r JOIN branches br ON br.id = r.branch_id LEFT JOIN regions rg ON rg.id = br.region_id
                         WHERE ' . implode(' AND ', $where) . '
                         GROUP BY br.id, br.name, br.region_id, rg.name ORDER BY rg.name, br.name', $params);
        $regions = [];
        $total = ['days' => 0] + array_fill_keys(self::keys(), 0.0);
        foreach ($rows as $r) {
            $rid = (int) ($r['region_id'] ?? 0);
            $regions[$rid] ??= ['region_id' => $rid, 'name' => $r['region_name'], 'branches' => 0, 'days' => 0] + array_fill_keys(self::keys(), 0.0);
            $regions[$rid]['branches']++;
            $regions[$rid]['days'] += (int) $r['days'];
            $total['days'] += (int) $r['days'];
            foreach (self::keys() as $k) {
                $regions[$rid][$k] += (float) $r[$k];
                $total[$k] += (float) $r[$k];
            }
        }
        return ['branches' => $rows, 'regions' => array_values($regions), 'total' => $total];
    }
}
