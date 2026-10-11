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
            DB::insert(
                'INSERT INTO report_versions (report_id, bc_id, report_date, version, apy, pmjjby, pmsby, pmjdy, source, changed_by, reason, raw_text, created_at)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, \'admin\', ?, ?, \'[DELETED]\', NOW())',
                [$r['bc_id'], $r['report_date'], (int) $r['version'] + 1, $r['apy'], $r['pmjjby'], $r['pmsby'], $r['pmjdy'], $userId, $reason]
            );
            DB::exec('DELETE FROM reports WHERE id = ?', [$r['id']]);
        });
        Audit::log($userId, 'report_delete', 'report', $reportId, ['bc_id' => $r['bc_id'], 'date' => $r['report_date'], 'reason' => $reason,
            'old' => ['apy' => $r['apy'], 'pmjjby' => $r['pmjjby'], 'pmsby' => $r['pmsby'], 'pmjdy' => $r['pmjdy']]]);
    }

    private static function save(int $bcId, string $date, array $values, array $blank, string $source,
                                 ?int $userId, ?string $reason, ?string $raw, ?string $waMsgId): array
    {
        $v = [];
        foreach (ReportParser::SCHEMES as $s) {
            $v[$s] = max(0, (int) ($values[$s] ?? 0));
        }
        $blankStr = $blank ? implode(',', $blank) : null;

        return DB::tx(static function () use ($bcId, $date, $v, $blankStr, $source, $userId, $reason, $raw, $waMsgId): array {
            $existing = DB::one('SELECT id, version FROM reports WHERE bc_id = ? AND report_date = ? FOR UPDATE', [$bcId, $date]);
            if ($existing) {
                $version = (int) $existing['version'] + 1;
                DB::exec(
                    'UPDATE reports SET apy = ?, pmjjby = ?, pmsby = ?, pmjdy = ?, blank_fields = ?, source = ?, version = ?,
                            wa_message_id = COALESCE(?, wa_message_id), updated_at = NOW(), updated_by = ?
                     WHERE id = ?',
                    [$v['apy'], $v['pmjjby'], $v['pmsby'], $v['pmjdy'], $blankStr, $source, $version, $waMsgId, $userId, $existing['id']]
                );
                $id = (int) $existing['id'];
                $updated = true;
            } else {
                $version = 1;
                $id = DB::insert(
                    'INSERT INTO reports (bc_id, report_date, apy, pmjjby, pmsby, pmjdy, blank_fields, source, version, wa_message_id, submitted_at, updated_at, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW(), ?)',
                    [$bcId, $date, $v['apy'], $v['pmjjby'], $v['pmsby'], $v['pmjdy'], $blankStr, $source, $waMsgId, $userId]
                );
                $updated = false;
            }
            DB::insert(
                'INSERT INTO report_versions (report_id, bc_id, report_date, version, apy, pmjjby, pmsby, pmjdy, source, changed_by, reason, raw_text, wa_message_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$id, $bcId, $date, $version, $v['apy'], $v['pmjjby'], $v['pmsby'], $v['pmjdy'], $source, $userId, $reason, $raw, $waMsgId]
            );
            return ['updated' => $updated, 'version' => $version, 'report_id' => $id];
        });
    }

    public static function summaryLine(array $r): string
    {
        return sprintf('APY %d, PMJJBY %d, PMSBY %d, PMJDY %d', $r['apy'], $r['pmjjby'], $r['pmsby'], $r['pmjdy']);
    }
}
