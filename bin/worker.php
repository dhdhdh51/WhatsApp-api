<?php
declare(strict_types=1);

/**
 * Queue worker. Cron starts it every minute; the lock guarantees only ONE worker sends at a time.
 *   * * * * *  php bin/worker.php
 * It keeps running while there is work (up to WORKER_MAX_RUNTIME seconds) and exits when idle.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../src/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/bc-report-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker is running
}

(new App\QueueWorker(App\Env::int('WORKER_MAX_RUNTIME', 3300)))->run();
