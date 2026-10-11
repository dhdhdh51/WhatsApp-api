<?php
declare(strict_types=1);

namespace App;

/** Data visibility: Admin sees everything, a Manager sees only BCs of their assigned branches. */
final class Scope
{
    /** @return array{0:string,1:array} SQL condition on the bcs alias + params */
    public static function bc(array $user, string $alias = 'b'): array
    {
        if ($user['role'] === 'admin') {
            return ['1=1', []];
        }
        return ["{$alias}.branch_id IN (SELECT ub.branch_id FROM user_branches ub WHERE ub.user_id = ?)", [(int) $user['id']]];
    }

    public static function canSeeBc(array $user, int $bcId): bool
    {
        [$w, $p] = self::bc($user);
        $p[] = $bcId;
        return (bool) DB::val("SELECT COUNT(*) FROM bcs b WHERE {$w} AND b.id = ?", $p);
    }

    /** Branches visible to the user. */
    public static function branches(array $user): array
    {
        if ($user['role'] === 'admin') {
            return DB::all('SELECT * FROM branches ORDER BY name');
        }
        return DB::all(
            'SELECT br.* FROM branches br JOIN user_branches ub ON ub.branch_id = br.id WHERE ub.user_id = ? ORDER BY br.name',
            [(int) $user['id']]
        );
    }

    /** Distinct block names visible to the user. */
    public static function blocks(array $user): array
    {
        [$w, $p] = self::bc($user);
        return array_column(DB::all(
            "SELECT DISTINCT b.block FROM bcs b WHERE {$w} AND b.block IS NOT NULL AND b.block <> '' ORDER BY b.block",
            $p
        ), 'block');
    }
}
