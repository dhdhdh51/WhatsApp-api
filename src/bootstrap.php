<?php
declare(strict_types=1);

/**
 * Common bootstrap for web pages, the webhook and CLI scripts.
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require BASE_PATH . '/src/helpers.php';

App\Env::load(BASE_PATH . '/.env');

date_default_timezone_set(App\Env::get('APP_TIMEZONE', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', App\Env::bool('APP_DEBUG') ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e): void {
    App\Logger::error('Uncaught: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo App\Env::bool('APP_DEBUG')
        ? '<pre>' . htmlspecialchars((string) $e) . '</pre>'
        : 'Server error. Please check storage/logs.';
});
