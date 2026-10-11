<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

redirect(App\Auth::user() ? '/dashboard.php' : '/login.php');
