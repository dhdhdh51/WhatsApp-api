<?php
declare(strict_types=1);

namespace App;

/** Daily summary and escalation messages for the Admin and Managers. */
final class Summary
{
    /** Recipients: admin phones from settings (all branches) + managers with "receive summary" (own branches). */
    public static function recipients(): array
    {
        $out = [];
        foreach (Settings::adminPhones() as $ph) {
            $out[$ph] = ['phone' => $ph, 'branch_ids' => null, 'label' => 'admin'];
        }
        $managers = DB::all("SELECT u.id, u.phone FROM users u WHERE u.role = 'manager' AND u.is_active = 1 AND u.receive_summary = 1 AND u.phone IS NOT NULL");
        foreach ($managers as $m) {
            $ph = Phone::normalize($m['phone']);
            if (!$ph || isset($out[$ph])) {
                continue;
            }
            $ids = array_map('intval', array_column(DB::all('SELECT branch_id FROM user_branches WHERE user_id = ?', [$m['id']]), 'branch_id'));
            if ($ids) {
                $out[$ph] = ['phone' => $ph, 'branch_ids' => $ids, 'label' => 'manager:' . $m['id']];
            }
        }
        return array_values($out);
    }

    public static function dailyText(string $date, ?array $branchIds): string
    {
        $rows = Calendar::dayRows($date, null);
        if ($branchIds !== null) {
            $rows = array_values(array_filter($rows, static fn ($r) => in_array((int) $r['branch_id'], $branchIds, true)));
        }
        $submitted = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'submitted'));
        $pending = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'pending'));
        $off = count($rows) - count($submitted) - count($pending);
        $expected = count($submitted) + count($pending);

        $tot = ['apy' => 0, 'pmjjby' => 0, 'pmsby' => 0, 'pmjdy' => 0];
        foreach ($submitted as $r) {
            foreach ($tot as $k => $_) {
                $tot[$k] += (int) $r[$k];
            }
        }

        $t = '📊 *Daily Summary - ' . fmt_date($date) . "*\n\n"
            . '✅ Submitted: ' . count($submitted) . '/' . $expected . "\n"
            . '⏳ Pending: ' . count($pending) . "\n"
            . ($off > 0 ? "🏖 Leave/Holiday: {$off}\n" : '')
            . "\n*Totals*\n"
            . "APY: {$tot['apy']}\nPMJJBY: {$tot['pmjjby']}\nPMSBY: {$tot['pmsby']}\nPMJDY: {$tot['pmjdy']}\n";

        if ($submitted) {
            $t .= "\n*✅ Submitted BCs* (APY/PMJJBY/PMSBY/PMJDY)\n";
            foreach (array_slice($submitted, 0, 150) as $i => $r) {
                $t .= ($i + 1) . '. ' . $r['name'] . ' (' . $r['bc_code'] . ') – '
                    . (int) $r['apy'] . '/' . (int) $r['pmjjby'] . '/' . (int) $r['pmsby'] . '/' . (int) $r['pmjdy'] . "\n";
            }
            if (count($submitted) > 150) {
                $t .= '… aur ' . (count($submitted) - 150) . "\n";
            }
        }
        if ($pending) {
            $t .= "\n*⏳ Pending BCs*\n";
            foreach (array_slice($pending, 0, 150) as $i => $r) {
                $t .= ($i + 1) . '. ' . $r['name'] . ' (' . $r['bc_code'] . ($r['branch_name'] ? ', ' . $r['branch_name'] : '') . ")\n";
            }
            if (count($pending) > 150) {
                $t .= '… aur ' . (count($pending) - 150) . " (panel mein dekhein)\n";
            }
        }
        return rtrim($t);
    }

    public static function enqueueDaily(string $date): int
    {
        $n = 0;
        foreach (self::recipients() as $rcp) {
            $text = self::dailyText($date, $rcp['branch_ids']);
            $n += Queue::enqueue(null, Phone::toChatId($rcp['phone']), $text, 'summary', null, 1,
                "summary:{$date}:{$rcp['phone']}") > 0 ? 1 : 0;
        }
        return $n;
    }

    public static function escalationText(array $list): string
    {
        $min = max(1, Settings::int('escalation_days'));
        $t = "⚠️ *Escalation* – {$min}+ din lagatar report nahi aayi\n\n";
        foreach (array_slice($list, 0, 150) as $i => $row) {
            $bc = $row['bc'];
            $days = array_map(static fn ($d) => date('d-m', strtotime($d)), array_reverse($row['days']));
            $t .= ($i + 1) . '. ' . $bc['name'] . ' (' . $bc['bc_code'] . ($bc['branch_name'] ? ', ' . $bc['branch_name'] : '') . ') – '
                . count($row['days']) . ' din: ' . implode(', ', $days) . "\n";
        }
        if (count($list) > 150) {
            $t .= '… aur ' . (count($list) - 150) . "\n";
        }
        return rtrim($t);
    }

    public static function enqueueEscalation(string $date): int
    {
        $min = max(1, Settings::int('escalation_days'));
        $n = 0;
        foreach (self::recipients() as $rcp) {
            $list = Calendar::missStreaks($min, $rcp['branch_ids']);
            if (!$list) {
                continue;
            }
            $n += Queue::enqueue(null, Phone::toChatId($rcp['phone']), self::escalationText($list), 'escalation', null, 1,
                "escalation:{$date}:{$rcp['phone']}") > 0 ? 1 : 0;
        }
        return $n;
    }
}
