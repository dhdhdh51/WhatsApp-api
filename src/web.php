<?php
declare(strict_types=1);

/**
 * Bootstrap for admin-panel pages: session, security headers, CSRF check on every POST.
 */

require __DIR__ . '/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');

App\Auth::startSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    App\Csrf::verify();
}
