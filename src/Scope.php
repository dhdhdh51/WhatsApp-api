<?php
declare(strict_types=1);

namespace App;

/**
 * Data visibility:
 *   admin, supervisor – all BCs
 *   manager           – BCs of the assigned branches
 *   cbc               – only the BCs assigned to this CBC (bcs.cbc_id)
 */
final class Scope
{
    /** @return array{0:string,1:array} SQL condition on the bcs alias + params */
    public static function bc(array $user, string $alias = 'b'): array
    {
        if (self::seesAll($user)) {
            return ['1=1', []];
        }
        if ($user['role'] === 'cbc') {
            return ["{$alias}.cbc_id = ?", [(int) $user['id']]];
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
        if (self::seesAll($user)) {
            return DB::all('SELECT * FROM branches ORDER BY name');
        }
        if ($user['role'] === 'cbc') {
            return DB::all('SELECT DISTINCT br.* FROM branches br JOIN bcs b ON b.branch_id = br.id WHERE b.cbc_id = ? ORDER BY br.name', [(int) $user['id']]);
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

    public static function seesAll(array $user): bool
    {
        return in_array($user['role'], ['admin', 'supervisor'], true);
    }

    /** CBC users whose BCs the user can see (for filters). Empty for a CBC. */
    public static function cbcs(array $user): array
    {
        if ($user['role'] === 'cbc') {
            return [];
        }
        [$w, $p] = self::bc($user);
        return DB::all("SELECT DISTINCT u.id, u.name, u.phone FROM users u JOIN bcs b ON b.cbc_id = u.id WHERE u.role = 'cbc' AND {$w} ORDER BY u.name", $p);
    }
}
