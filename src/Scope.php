<?php
declare(strict_types=1);

namespace App;

/**
 * Data visibility:
 *   admin               – everything
 *   supervisor          – REGION MANAGER: every BC in their region (all branches whose region_id
 *                         matches users.region_id). With no region set, still sees all (back-compat).
 *   manager             – BCs of the individual branches assigned in user_branches (branch-level)
 *   cbc                 – only the BCs assigned to this CBC (bcs.cbc_id)
 */
final class Scope
{
    /** The region a Region Manager (supervisor) owns, 0 = none. */
    public static function region(array $user): int
    {
        return ($user['role'] ?? '') === 'supervisor' ? (int) ($user['region_id'] ?? 0) : 0;
    }

    public static function seesAll(array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        // A Region Manager with no region assigned still sees everything (keeps things working until a region is set).
        return ($user['role'] ?? '') === 'supervisor' && self::region($user) === 0;
    }

    /** @return array{0:string,1:array} SQL condition on the bcs alias + params */
    public static function bc(array $user, string $alias = 'b'): array
    {
        if (self::seesAll($user)) {
            return ['1=1', []];
        }
        if (($user['role'] ?? '') === 'cbc') {
            return ["{$alias}.cbc_id = ?", [(int) $user['id']]];
        }
        if (($user['role'] ?? '') === 'supervisor') {   // Region Manager -> all branches in their region
            return ["{$alias}.branch_id IN (SELECT id FROM branches WHERE region_id = ?)", [self::region($user)]];
        }
        return ["{$alias}.branch_id IN (SELECT ub.branch_id FROM user_branches ub WHERE ub.user_id = ?)", [(int) $user['id']]];
    }

    public static function canSeeBc(array $user, int $bcId): bool
    {
        [$w, $p] = self::bc($user);
        $p[] = $bcId;
        return (bool) DB::val("SELECT COUNT(*) FROM bcs b WHERE {$w} AND b.id = ?", $p);
    }

    /** May this user place/keep a BC in this branch? admin/region-less = any; scoped = only within their region/branches. */
    public static function canUseBranch(array $user, ?int $branchId): bool
    {
        if (self::seesAll($user)) {
            return true;
        }
        if (!$branchId) {
            return false;
        }
        if (($user['role'] ?? '') === 'supervisor') {
            return (bool) DB::val('SELECT 1 FROM branches WHERE id = ? AND region_id = ?', [$branchId, self::region($user)]);
        }
        if (($user['role'] ?? '') === 'manager') {
            return (bool) DB::val('SELECT 1 FROM user_branches WHERE user_id = ? AND branch_id = ?', [(int) $user['id'], $branchId]);
        }
        return false;
    }

    /** WHERE (alias $a on the users table) for CBC logins this person may manage. */
    public static function cbcManageWhere(array $user, string $a = 'u'): array
    {
        if (self::seesAll($user)) {
            return ["{$a}.role = 'cbc'", []];
        }
        $me = (int) $user['id'];
        // CBCs the Region Manager created, or CBCs who follow up a BC inside their region
        return ["{$a}.role = 'cbc' AND ({$a}.created_by = ? OR EXISTS (SELECT 1 FROM bcs b WHERE b.cbc_id = {$a}.id AND b.branch_id IN (SELECT id FROM branches WHERE region_id = ?)))", [$me, self::region($user)]];
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

    /**
     * WHERE (alias $a on users) for Branch Manager logins this person may manage.
     * A Region Manager: Branch Managers whose every branch is inside their region, and who have a
     * branch there (or were created by them). A BM spanning another region stays with the Admin.
     */
    public static function bmManageWhere(array $user, string $a = 'u'): array
    {
        if (self::seesAll($user)) {
            return ["{$a}.role = 'manager'", []];
        }
        $r = self::region($user);
        return ["{$a}.role = 'manager'
                 AND NOT EXISTS (SELECT 1 FROM user_branches ubx JOIN branches bx ON bx.id = ubx.branch_id
                                 WHERE ubx.user_id = {$a}.id AND (bx.region_id IS NULL OR bx.region_id <> ?))
                 AND ({$a}.created_by = ? OR EXISTS (SELECT 1 FROM user_branches uby JOIN branches byy ON byy.id = uby.branch_id
                                                     WHERE uby.user_id = {$a}.id AND byy.region_id = ?))",
            [$r, (int) $user['id'], $r]];
    }

    public static function canManageBm(array $user, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        [$w, $p] = self::bmManageWhere($user, 'u');
        $p[] = $userId;
        return (bool) DB::val("SELECT 1 FROM users u WHERE {$w} AND u.id = ?", $p);
    }

    /** Branches visible to the user. */
    public static function branches(array $user): array
    {
        if (self::seesAll($user)) {
            return DB::all('SELECT * FROM branches ORDER BY name');
        }
        if (($user['role'] ?? '') === 'cbc') {
            return DB::all('SELECT DISTINCT br.* FROM branches br JOIN bcs b ON b.branch_id = br.id WHERE b.cbc_id = ? ORDER BY br.name', [(int) $user['id']]);
        }
        if (($user['role'] ?? '') === 'supervisor') {   // Region Manager -> every branch in their region
            return DB::all('SELECT * FROM branches WHERE region_id = ? ORDER BY name', [self::region($user)]);
        }
        return DB::all(
            'SELECT br.* FROM branches br JOIN user_branches ub ON ub.branch_id = br.id WHERE ub.user_id = ? ORDER BY br.name',
            [(int) $user['id']]
        );
    }

    /**
     * Regions the user may see, each with its total branch count.
     * admin / region-less supervisor -> all regions; Region Manager -> only their own;
     * branch manager / cbc -> regions that contain a branch they can see.
     * @return array<int,array{id:int,name:string,branches:int}>
     */
    public static function regionsVisible(array $user): array
    {
        $cnt = '(SELECT COUNT(*) FROM branches bx WHERE bx.region_id = rg.id) branches';
        if (self::seesAll($user)) {
            return DB::all("SELECT rg.id, rg.name, {$cnt} FROM regions rg ORDER BY rg.name");
        }
        if (($user['role'] ?? '') === 'supervisor') {
            return DB::all("SELECT rg.id, rg.name, {$cnt} FROM regions rg WHERE rg.id = ? ORDER BY rg.name", [self::region($user)]);
        }
        [$w, $p] = self::bc($user, 'b');
        return DB::all(
            "SELECT DISTINCT rg.id, rg.name, {$cnt} FROM regions rg
             JOIN branches br ON br.region_id = rg.id JOIN bcs b ON b.branch_id = br.id
             WHERE {$w} ORDER BY rg.name",
            $p
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

    /** CBC users whose BCs the user can see (for filters). Empty for a CBC. */
    public static function cbcs(array $user): array
    {
        if (($user['role'] ?? '') === 'cbc') {
            return [];
        }
        [$w, $p] = self::bc($user);
        return DB::all("SELECT DISTINCT u.id, u.name, u.phone FROM users u JOIN bcs b ON b.cbc_id = u.id WHERE u.role = 'cbc' AND {$w} ORDER BY u.name", $p);
    }
}
