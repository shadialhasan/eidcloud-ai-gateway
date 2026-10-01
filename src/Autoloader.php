<?php

declare(strict_types=1);

namespace EidCloud\AiGateway;

/**
 * Lightweight Zero-dependency PSR-4 Autoloader.
 */
class Autoloader
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(function (string $class): void {
            $prefix = 'EidCloud\\AiGateway\\';
            $baseDir = __DIR__ . '/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}

Autoloader::register();
