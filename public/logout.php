<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App\Auth::logout();
}
redirect('/login.php');
