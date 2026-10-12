<?php
declare(strict_types=1);

namespace App;

/** Saving reports + version history (audit of every change). */
final class Reports
{
    /**
     * Save a report coming from WhatsApp. Every submission creates a version row.
     * @param array<string,int> $values
     * @param list<string> $blank
     * @return array{updated:bool, version:int, report_id:int}
     */
    public static function saveFromWhatsApp(int $bcId, string $date, array $values, array $blank, string $raw, ?string $waMsgId): array
    {
        return self::save($bcId, $date, $values, $blank, 'whatsapp', null, null, $raw, $waMsgId);
    }

    /** Report submitted from the mobile app. */
    public static function saveFromApp(int $bcId, string $date, array $values): array
    {
        return self::save($bcId, $date, $values, [], 'app', null, null, '[app]', null);
    }

    /** Manual edit by the Admin (allowed at any time, reason is mandatory). */
    public static function saveByAdmin(int $bcId, string $date, array $values, int $userId, string $reason): array
    {
        $res = self::save($bcId, $date, $values, [], 'admin', $userId, $reason, null, null);
        Audit::log($userId, $res['updated'] ? 'report_edit' : 'report_create', 'report', $res['report_id'],
            ['bc_id' => $bcId, 'date' => $date, 'values' => $values, 'reason' => $reason, 'version' => $res['version']]);
        return $res;
    }

    public static function deleteByAdmin(int $reportId, int $userId, string $reason): void
    {
        $r = DB::one('SELECT * FROM reports WHERE id = ?', [$reportId]);
        if (!$r) {
            return;
        }
        DB::tx(static function () use ($r, $userId, $reason): void {
            $cols = implode(', ', ReportParser::SCHEMES);
            $marks = implode(', ', array_fill(0, count(ReportParser::SCHEMES), '?'));
            DB::insert(
                "INSERT INTO report_versions (report_id, bc_id, report_date, version, {$cols}, source, changed_by, reason, raw_text, created_at)
                 VALUES (NULL, ?, ?, ?, {$marks}, 'admin', ?, ?, '[DELETED]', NOW())",
                array_merge([$r['bc_id'], $r['report_date'], (int) $r['version'] + 1],
                    array_map(static fn ($k) => $r[$k], ReportParser::SCHEMES), [$userId, $reason])
            );
            DB::exec('DELETE FROM reports WHERE id = ?', [$r['id']]);
        });
        Audit::log($userId, 'report_delete', 'report', $reportId, ['bc_id' => $r['bc_id'], 'date' => $r['report_date'], 'reason' => $reason,
            'old' => array_intersect_key($r, array_flip(ReportParser::SCHEMES))]);
    }

    private static function save(int $bcId, string $date, array $values, array $blank, string $source,
                                 ?int $userId, ?string $reason, ?string $raw, ?string $waMsgId): array
    {
        $v = [];
        foreach (ReportParser::SCHEMES as $s) {
            $v[$s] = max(0, (int) ($values[$s] ?? 0));
        }
        $blankStr = $blank ? implode(',', $blank) : null;

        $cols = ReportParser::SCHEMES;
        $colList = implode(', ', $cols);
        $marks = implode(', ', array_fill(0, count($cols), '?'));
        $sets = implode(', ', array_map(static fn ($c) => "{$c} = ?", $cols));
        $vals = array_values($v);

        return DB::tx(static function () use ($bcId, $date, $vals, $colList, $marks, $sets, $blankStr, $source, $userId, $reason, $raw, $waMsgId): array {
            $existing = DB::one('SELECT id, version FROM reports WHERE bc_id = ? AND report_date = ? FOR UPDATE', [$bcId, $date]);
            if ($existing) {
                $version = (int) $existing['version'] + 1;
                DB::exec(
                    "UPDATE reports SET {$sets}, blank_fields = ?, source = ?, version = ?,
                            wa_message_id = COALESCE(?, wa_message_id), updated_at = NOW(), updated_by = ?
                     WHERE id = ?",
                    array_merge($vals, [$blankStr, $source, $version, $waMsgId, $userId, $existing['id']])
                );
                $id = (int) $existing['id'];
                $updated = true;
            } else {
                $version = 1;
                $id = DB::insert(
                    "INSERT INTO reports (bc_id, report_date, {$colList}, blank_fields, source, version, wa_message_id, submitted_at, updated_at, updated_by)
                     VALUES (?, ?, {$marks}, ?, ?, 1, ?, NOW(), NOW(), ?)",
                    array_merge([$bcId, $date], $vals, [$blankStr, $source, $waMsgId, $userId])
                );
                $updated = false;
            }
            DB::insert(
                "INSERT INTO report_versions (report_id, bc_id, report_date, version, {$colList}, source, changed_by, reason, raw_text, wa_message_id, created_at)
                 VALUES (?, ?, ?, ?, {$marks}, ?, ?, ?, ?, ?, NOW())",
                array_merge([$id, $bcId, $date, $version], $vals, [$source, $userId, $reason, $raw, $waMsgId])
            );
            return ['updated' => $updated, 'version' => $version, 'report_id' => $id];
        });
    }

    /** "APY 2, PMJJBY 1, PMSBY 3, PMJDY 5" + offline part when any offline value is set. */
    public static function summaryLine(array $r): string
    {
        $line = sprintf('APY %d, PMJJBY %d, PMSBY %d, PMJDY %d', $r['apy'], $r['pmjjby'], $r['pmsby'], $r['pmjdy']);
        if ((int) ($r['apy_off'] ?? 0) + (int) ($r['pmjjby_off'] ?? 0) + (int) ($r['pmsby_off'] ?? 0) > 0) {
            $line .= sprintf(' | Offline: APY %d, JJBY %d, SBY %d', $r['apy_off'] ?? 0, $r['pmjjby_off'] ?? 0, $r['pmsby_off'] ?? 0);
        }
        return $line;
    }
}
