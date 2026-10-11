<?php
declare(strict_types=1);

namespace App;

final class Logger
{
    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('WARN', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $line = sprintf("[%s] %s %s%s\n", date('Y-m-d H:i:s'), $level, $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli' && getenv('QUIET') !== '1') {
            echo $line;
        }
    }
}
