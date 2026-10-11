<?php
declare(strict_types=1);

namespace App;

/**
 * Who is expected to report on a given day (active, not on leave, not a holiday / weekly off),
 * and the per-BC status board for a day.
 */
final class Calendar
{
    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function isWeeklyOff(string $date): bool
    {
        return in_array((int) date('w', strtotime($date)), Settings::weeklyOffDays(), true);
    }

    /** Cut-off moment (timestamp) for the report of $date. */
    public static function cutoffTs(string $date): int
    {
        $t = Settings::get('cutoff_time');
        if (!Settings::validTime($t)) {
            $t = '23:59';
        }
        return (int) strtotime($date . ' ' . Settings::padTime($t) . ':59');
    }

    public static function isOpen(string $date): bool
    {
        return $date === self::today() && time() <= self::cutoffTs($date);
    }

    /**
     * Status rows for every active BC visible to $user on $date.
     * status: submitted | pending | leave | holiday | weekly_off
     *
     * @param array{branch_id?:int|string,block?:string,search?:string,status?:string} $filters
     */
    public static function dayRows(string $date, ?array $user, array $filters = []): array
    {
        $params = [$date, $date, $date];
        $where = ['b.is_active = 1'];
        if (!empty($filters['cbc_id'])) {
            $where[] = 'b.cbc_id = ?';
            $params[] = (int) $filters['cbc_id'];
        }
        if ($user) {
            [$w, $p] = Scope::bc($user);
            $where[] = $w;
            array_push($params, ...$p);
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'b.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['block'])) {
            $where[] = 'b.block = ?';
            $params[] = $filters['block'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(b.name LIKE ? OR b.bc_code LIKE ? OR b.phone LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            array_push($params, $s, $s, $s);
        }
        if (!empty($filters['bc_ids'])) {
            $where[] = 'b.id IN (' . DB::in(array_map('intval', $filters['bc_ids']), $params) . ')';
        }

        $sql = "SELECT b.id, b.bc_code, b.name, b.phone, b.wa_lid, b.block, b.branch_id, b.cbc_id, b.opted_out,
                       br.name AS branch_name, br.area, cu.name AS cbc_name, cu.phone AS cbc_phone,
                       r.id AS report_id, r.apy, r.pmjjby, r.pmsby, r.pmjdy, r.apy_off, r.pmjjby_off, r.pmsby_off, r.version, r.source,
                       r.submitted_at, r.updated_at AS report_updated_at, r.blank_fields,
                       (SELECT COUNT(*) FROM bc_leaves l WHERE l.bc_id = b.id AND ? BETWEEN l.from_date AND l.to_date) AS on_leave,
                       (SELECT COUNT(*) FROM holidays h WHERE h.holiday_date = ? AND (h.branch_id IS NULL OR h.branch_id = b.branch_id)) AS is_holiday
                FROM bcs b
                LEFT JOIN branches br ON br.id = b.branch_id
                LEFT JOIN users cu ON cu.id = b.cbc_id
                LEFT JOIN reports r ON r.bc_id = b.id AND r.report_date = ?
                WHERE " . implode(' AND ', $where) . '
                ORDER BY br.name, b.name';

        $weeklyOff = self::isWeeklyOff($date);
        $rows = [];
        foreach (DB::all($sql, $params) as $r) {
            if ($r['report_id']) {
                $r['status'] = 'submitted';
            } elseif ((int) $r['is_holiday'] > 0) {
                $r['status'] = 'holiday';
            } elseif ((int) $r['on_leave'] > 0) {
                $r['status'] = 'leave';
            } elseif ($weeklyOff) {
                $r['status'] = 'weekly_off';
            } else {
                $r['status'] = 'pending';
            }
            if (!empty($filters['status']) && $filters['status'] !== $r['status']) {
                if (!($filters['status'] === 'not_submitted' && $r['status'] !== 'submitted')) {
                    continue;
                }
            }
            $rows[] = $r;
        }
        return $rows;
    }

    /** BCs that should receive the scheduled form / reminder today. */
    public static function pendingForMessaging(string $date, array $filters = []): array
    {
        return array_values(array_filter(
            self::dayRows($date, null, $filters),
            static fn ($r) => $r['status'] === 'pending' && (int) $r['opted_out'] === 0
        ));
    }

    /** Is a single BC expected (and still pending) on $date? Used by the queue worker before sending. */
    public static function bcStillPending(int $bcId, string $date): bool
    {
        $rows = self::dayRows($date, null, ['bc_ids' => [$bcId]]);
        return $rows && $rows[0]['status'] === 'pending' && (int) $rows[0]['opted_out'] === 0;
    }

    /**
     * BCs with >= $minDays consecutive missed working days ending yesterday.
     * Holidays, leave, weekly offs and days before the BC was created are skipped (they don't break the streak).
     *
     * @param list<int>|null $branchIds limit to these branches (null = all)
     * @return list<array{bc:array, days:list<string>}>
     */
    public static function missStreaks(int $minDays, ?array $branchIds = null, int $lookback = 21): array
    {
        $end = date('Y-m-d', strtotime('-1 day'));
        $start = date('Y-m-d', strtotime("-{$lookback} days"));

        $params = [];
        $where = 'b.is_active = 1';
        if ($branchIds !== null) {
            $where .= ' AND b.branch_id IN (' . DB::in($branchIds, $params) . ')';
        }
        $bcs = DB::all("SELECT b.*, br.name AS branch_name FROM bcs b LEFT JOIN branches br ON br.id = b.branch_id
                        WHERE {$where} ORDER BY br.name, b.name", $params);
        if (!$bcs) {
            return [];
        }

        $reports = [];
        foreach (DB::all('SELECT bc_id, report_date FROM reports WHERE report_date BETWEEN ? AND ?', [$start, $end]) as $r) {
            $reports[$r['bc_id'] . '|' . $r['report_date']] = true;
        }
        $holidays = [];
        foreach (DB::all('SELECT holiday_date, branch_id FROM holidays WHERE holiday_date BETWEEN ? AND ?', [$start, $end]) as $h) {
            $holidays[$h['holiday_date'] . '|' . ($h['branch_id'] ?? '*')] = true;
        }
        $leaves = [];
        foreach (DB::all('SELECT bc_id, from_date, to_date FROM bc_leaves WHERE to_date >= ? AND from_date <= ?', [$start, $end]) as $l) {
            $leaves[(int) $l['bc_id']][] = [$l['from_date'], $l['to_date']];
        }
        $off = Settings::weeklyOffDays();

        $out = [];
        foreach ($bcs as $bc) {
            $created = substr((string) $bc['created_at'], 0, 10);
            $missed = [];
            for ($i = 1; $i <= $lookback; $i++) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                if ($d < $created) {
                    break;
                }
                if (in_array((int) date('w', strtotime($d)), $off, true)
                    || isset($holidays[$d . '|*'])
                    || ($bc['branch_id'] && isset($holidays[$d . '|' . $bc['branch_id']]))) {
                    continue;
                }
                $onLeave = false;
                foreach ($leaves[(int) $bc['id']] ?? [] as [$f, $t]) {
                    if ($d >= $f && $d <= $t) {
                        $onLeave = true;
                        break;
                    }
                }
                if ($onLeave) {
                    continue;
                }
                if (isset($reports[$bc['id'] . '|' . $d])) {
                    break;
                }
                $missed[] = $d;
            }
            if (count($missed) >= $minDays) {
                $out[] = ['bc' => $bc, 'days' => $missed];
            }
        }
        return $out;
    }
}
