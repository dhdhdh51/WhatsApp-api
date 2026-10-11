<?php
declare(strict_types=1);

/**
 * Cron: every minute.  * * * * *  php bin/scheduler.php
 * Queues the daily form, reminders, the admin summary and escalations at the configured times.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../src/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/bc-report-scheduler.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // previous run still busy
}

(new App\Scheduler())->run();
