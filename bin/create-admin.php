<?php
declare(strict_types=1);

/**
 * Create an admin user or reset a password.
 *   php bin/create-admin.php <username> "<Full Name>"
 * The password is asked interactively (min 10 chars).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\DB;

$username = $argv[1] ?? '';
$name = $argv[2] ?? $username;
if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
    exit("Usage: php bin/create-admin.php <username> \"<Full Name>\"\n");
}

echo 'Password (min 10 chars): ';
system('stty -echo 2>/dev/null');
$pass = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";
if (mb_strlen($pass) < 10) {
    exit("Password too short\n");
}

$hash = password_hash($pass, PASSWORD_DEFAULT);
$existing = DB::one('SELECT id FROM users WHERE username = ?', [$username]);
if ($existing) {
    DB::exec("UPDATE users SET password_hash = ?, role = 'admin', is_active = 1 WHERE id = ?", [$hash, $existing['id']]);
    echo "Updated admin '{$username}'.\n";
} else {
    DB::insert("INSERT INTO users (name, username, password_hash, role, is_active) VALUES (?, ?, ?, 'admin', 1)", [$name, $username, $hash]);
    echo "Created admin '{$username}'.\n";
}
