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

    /** May this user place/keep a BC in this branch? admin/region-less = any; scoped = only their own branches. */
    public static function canUseBranch(array $user, ?int $branchId): bool
    {
        if (self::seesAll($user)) {
            return true;
        }
        if (!in_array($user['role'] ?? '', ['supervisor', 'manager'], true) || !$branchId) {
            return false;
        }
        return (bool) DB::val('SELECT 1 FROM user_branches WHERE user_id = ? AND branch_id = ?', [(int) $user['id'], $branchId]);
    }

    /** WHERE (alias $a on the users table) for CBC logins this person may manage. */
    public static function cbcManageWhere(array $user, string $a = 'u'): array
    {
        if (self::seesAll($user)) {
            return ["{$a}.role = 'cbc'", []];
        }
        $me = (int) $user['id'];
        // CBCs the supervisor created, or CBCs who follow up a BC inside the supervisor's region
        return ["{$a}.role = 'cbc' AND ({$a}.created_by = ? OR EXISTS (SELECT 1 FROM bcs b WHERE b.cbc_id = {$a}.id AND b.branch_id IN (SELECT ub.branch_id FROM user_branches ub WHERE ub.user_id = ?)))", [$me, $me]];
    }

    public static function canManageCbc(array $user, int $cbcId): bool
    {
        if ($cbcId <= 0) {
            return false;
        }
        [$w, $p] = self::cbcManageWhere($user, 'u');
        $p[] = $cbcId;
        return (bool) DB::val("SELECT 1 FROM users u WHERE {$w} AND u.id = ?", $p);
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

    /** @var array<int,bool> per-request cache */
    private static array $branchCache = [];

    /** Does this user have a region (one or more branches) assigned? (BC Supervisors + Managers) */
    public static function hasBranches(array $user): bool
    {
        $id = (int) ($user['id'] ?? 0);
        if (!array_key_exists($id, self::$branchCache)) {
            self::$branchCache[$id] = $id > 0 && (bool) DB::val('SELECT 1 FROM user_branches WHERE user_id = ? LIMIT 1', [$id]);
        }
        return self::$branchCache[$id];
    }

    public static function seesAll(array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        // A BC Supervisor assigned a region (branches) sees only that region; with no region assigned
        // they still see every BC (keeps existing supervisors working until a region is set).
        return ($user['role'] ?? '') === 'supervisor' && !self::hasBranches($user);
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
