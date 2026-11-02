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
            $out[$ph] = ['phone' => $ph, 'branch_ids' => null, 'cbc_id' => null, 'label' => 'admin'];
        }
        $people = DB::all("SELECT u.id, u.phone, u.role FROM users u WHERE u.role IN ('supervisor','manager','cbc') AND u.is_active = 1 AND u.receive_summary = 1 AND u.phone IS NOT NULL ORDER BY FIELD(u.role,'supervisor','manager','cbc')");
        foreach ($people as $m) {
            $ph = Phone::normalize($m['phone']);
            if (!$ph || isset($out[$ph])) {
                continue;
            }
            if ($m['role'] === 'supervisor') {
                $sids = array_map('intval', array_column(DB::all('SELECT branch_id FROM user_branches WHERE user_id = ?', [$m['id']]), 'branch_id'));
                $out[$ph] = ['phone' => $ph, 'branch_ids' => $sids ?: null, 'cbc_id' => null, 'label' => 'supervisor:' . $m['id']];
                continue;
            }
            if ($m['role'] === 'cbc') {
                $out[$ph] = ['phone' => $ph, 'branch_ids' => null, 'cbc_id' => (int) $m['id'], 'label' => 'cbc:' . $m['id']];
                continue;
            }
            $ids = array_map('intval', array_column(DB::all('SELECT branch_id FROM user_branches WHERE user_id = ?', [$m['id']]), 'branch_id'));
            if ($ids) {
                $out[$ph] = ['phone' => $ph, 'branch_ids' => $ids, 'cbc_id' => null, 'label' => 'manager:' . $m['id']];
            }
        }
        return array_values($out);
    }

    public static function dailyText(string $date, ?array $branchIds, ?int $cbcId = null): string
    {
        $rows = Calendar::dayRows($date, null, $cbcId ? ['cbc_id' => $cbcId] : []);
        if ($branchIds !== null) {
            $rows = array_values(array_filter($rows, static fn ($r) => in_array((int) $r['branch_id'], $branchIds, true)));
        }
        $submitted = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'submitted'));
        $pending = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'pending'));
        $off = count($rows) - count($submitted) - count($pending);
        $expected = count($submitted) + count($pending);

        $tot = Schemes::zero();
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
            . "APY: {$tot['apy']}\nPMJJBY: {$tot['pmjjby']}\nPMSBY: {$tot['pmsby']}\nPMJDY: {$tot['pmjdy']}\n"
            . "APY OFFLINE: {$tot['apy_off']}\nJJBY OFFLINE: {$tot['pmjjby_off']}\nSBY OFFLINE: {$tot['pmsby_off']}\n";

        if ($branchIds === null && !$cbcId) {
            // CBC-wise pending, so the Supervisor sees who has to follow up
            $byCbc = [];
            foreach ($rows as $r) {
                if ($r['status'] === 'submitted' || $r['status'] === 'pending') {
                    $k = $r['cbc_name'] ?: 'CBC nahi';
                    $byCbc[$k] ??= [0, 0];
                    $byCbc[$k][$r['status'] === 'submitted' ? 0 : 1]++;
                }
            }
            if (count($byCbc) > 1 || (count($byCbc) === 1 && !isset($byCbc['CBC nahi']))) {
                ksort($byCbc);
                $t .= "\n*CBC-wise (submitted/pending)*\n";
                foreach ($byCbc as $name => [$sub, $pen]) {
                    $t .= "{$name}: {$sub}/{$pen}\n";
                }
            }
            $unknown = (int) DB::val("SELECT COUNT(*) FROM messages WHERE direction = 'in' AND kind = 'unknown_sender' AND created_at >= ? AND created_at < (? + INTERVAL 1 DAY)", [$date, $date]);
            if ($unknown > 0) {
                $t .= "\n📩 Team se bahar ke numbers se {$unknown} message aaye – panel mein Inbox dekhein.\n";
            }
        }
        if ($submitted) {
            $t .= "\n*✅ Submitted BCs* (APY/PMJJBY/PMSBY/PMJDY)\n";
            foreach (array_slice($submitted, 0, 150) as $i => $r) {
                $t .= ($i + 1) . '. ' . $r['name'] . ' (' . $r['bc_code'] . ') – '
                    . (int) $r['apy'] . '/' . (int) $r['pmjjby'] . '/' . (int) $r['pmsby'] . '/' . (int) $r['pmjdy']
                    . ((int) $r['apy_off'] + (int) $r['pmjjby_off'] + (int) $r['pmsby_off'] > 0
                        ? ' (off ' . (int) $r['apy_off'] . '/' . (int) $r['pmjjby_off'] . '/' . (int) $r['pmsby_off'] . ')' : '') . "\n";
            }
            if (count($submitted) > 150) {
                $t .= '… aur ' . (count($submitted) - 150) . "\n";
            }
        }
        if ($pending) {
            $t .= "\n*⏳ Pending BCs*\n";
            foreach (array_slice($pending, 0, 150) as $i => $r) {
                $t .= ($i + 1) . '. ' . $r['name'] . ' (' . $r['bc_code'] . ($r['branch_name'] ? ', ' . $r['branch_name'] : '') . ')'
                    . ($cbcId ? ' 📞 ' . Phone::display($r['phone']) : '') . "\n";
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
            $text = self::dailyText($date, $rcp['branch_ids'], $rcp['cbc_id'] ?? null);
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
            if (!empty($rcp['cbc_id'])) {
                $list = array_values(array_filter($list, static fn ($x) => (int) $x['bc']['cbc_id'] === (int) $rcp['cbc_id']));
            }
            if (!$list) {
                continue;
            }
            $n += Queue::enqueue(null, Phone::toChatId($rcp['phone']), self::escalationText($list), 'escalation', null, 1,
                "escalation:{$date}:{$rcp['phone']}") > 0 ? 1 : 0;
        }
        return $n;
    }
}
