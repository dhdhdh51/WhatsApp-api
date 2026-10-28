<?php
declare(strict_types=1);

namespace App;

/** Session login for Admin / Manager with rate limiting and inactivity timeout. */
final class Auth
{
    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (self::timeoutSec() + 600));
        session_name('BCRSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function timeoutSec(): int
    {
        return max(5, Env::int('SESSION_TIMEOUT_MINUTES', 30)) * 60;
    }

    /** @return array{ok:bool, error:string} */
    public static function attempt(string $username, string $password): array
    {
        $ip = client_ip();
        $max = max(3, Env::int('LOGIN_MAX_ATTEMPTS', 5));
        $lockMin = max(1, Env::int('LOGIN_LOCK_MINUTES', 15));

        $fails = (int) DB::val(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND attempted_at >= (NOW() - INTERVAL ? MINUTE) AND (ip = ? OR username = ?)',
            [$lockMin, $ip, $username]
        );
        if ($fails >= $max) {
            return ['ok' => false, 'error' => "Too many failed attempts. Try again after {$lockMin} minutes. / बहुत बार गलत प्रयास, {$lockMin} मिनट बाद कोशिश करें।"];
        }

        $user = DB::one('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);
        // constant-ish time even for unknown users
        $hash = $user['password_hash'] ?? '$2y$10$' . str_repeat('a', 53);
        $ok = password_verify($password, $hash) && $user !== null;

        DB::insert('INSERT INTO login_attempts (ip, username, success, attempted_at) VALUES (?, ?, ?, NOW())', [$ip, mb_substr($username, 0, 60), $ok ? 1 : 0]);

        if (!$ok) {
            return ['ok' => false, 'error' => 'Invalid username or password / गलत यूज़रनेम या पासवर्ड'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['login_at'] = time();
        DB::exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        Audit::log((int) $user['id'], 'login', 'user', (int) $user['id']);
        return ['ok' => true, 'error' => ''];
    }

    public static function logout(): void
    {
        if (isset($_SESSION['uid'])) {
            Audit::log((int) $_SESSION['uid'], 'logout', 'user', (int) $_SESSION['uid']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        if (empty($_SESSION['uid'])) {
            return null;
        }
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > self::timeoutSec()) {
            self::logout();
            self::startSession();
            flash('warning', 'Session expired, please login again / सत्र समाप्त, दोबारा लॉगिन करें');
            return null;
        }
        $u = DB::one('SELECT id, name, username, role, region_id, phone, is_active FROM users WHERE id = ? AND is_active = 1', [(int) $_SESSION['uid']]);
        if (!$u) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        self::$user = $u;
        return $u;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
            redirect('/login.php');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') {
            http_response_code(403);
            View::header('Access denied', '');
            echo '<div class="alert alert-danger">Only Admin can open this page. / यह पेज केवल एडमिन के लिए है।</div>';
            View::footer();
            exit;
        }
        return $u;
    }

    public static function isAdmin(?array $u = null): bool
    {
        $u ??= self::user();
        return $u !== null && $u['role'] === 'admin';
    }

    /** Admin, Supervisor and Manager may change targets / leave; a CBC only views and follows up. */
    public static function canManage(?array $u = null): bool
    {
        $u ??= self::user();
        return $u !== null && $u['role'] !== 'cbc';
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'supervisor' => 'Region Manager',
            'manager' => 'Branch Manager',
            'cbc' => 'CBC',
            default => $role,
        };
    }
}
