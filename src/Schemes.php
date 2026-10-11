<?php
declare(strict_types=1);

namespace App;

/** Column / SQL helpers for the 7 reported fields (4 schemes + 3 offline). */
final class Schemes
{
    /** @return array<string,string> key => label */
    public static function labels(): array
    {
        return ReportParser::LABELS;
    }

    /** Short header labels for narrow tables. */
    public const SHORT = ['apy' => 'APY', 'pmjjby' => 'PMJJBY', 'pmsby' => 'PMSBY', 'pmjdy' => 'PMJDY',
        'apy_off' => 'APY Off', 'pmjjby_off' => 'JJBY Off', 'pmsby_off' => 'SBY Off'];

    /** "COALESCE(SUM(r.apy),0) apy, ..." */
    public static function sumSql(string $alias = 'r'): string
    {
        return implode(', ', array_map(static fn ($k) => "COALESCE(SUM({$alias}.{$k}),0) {$k}", ReportParser::SCHEMES));
    }

    /** "r.apy, r.pmjjby, ..." */
    public static function colsSql(string $alias = 'r'): string
    {
        return implode(', ', array_map(static fn ($k) => "{$alias}.{$k}", ReportParser::SCHEMES));
    }

    /** "(SUM(r.apy)+...)" – grand total of all fields */
    public static function totalSql(string $alias = 'r'): string
    {
        return '(' . implode('+', array_map(static fn ($k) => "COALESCE(SUM({$alias}.{$k}),0)", ReportParser::SCHEMES)) . ')';
    }

    public static function zero(): array
    {
        return array_fill_keys(ReportParser::SCHEMES, 0);
    }

    /** <th> cells */
    public static function th(string $class = 'text-end'): string
    {
        $h = '';
        foreach (self::SHORT as $k => $l) {
            $h .= '<th class="' . $class . (in_array($k, ReportParser::OFFLINE, true) ? ' off' : '') . '">' . e($l) . '</th>';
        }
        return $h;
    }

    /** <td> cells for a row; $dash = show "–" when there is no report */
    public static function td(array $r, bool $dash = false): string
    {
        $h = '';
        foreach (ReportParser::SCHEMES as $k) {
            $h .= '<td class="text-end' . (in_array($k, ReportParser::OFFLINE, true) ? ' off' : '') . '">' . ($dash ? '–' : (int) ($r[$k] ?? 0)) . '</td>';
        }
        return $h;
    }
}
