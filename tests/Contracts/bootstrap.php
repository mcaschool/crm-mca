<?php

declare(strict_types=1);

// Deliberately no Laravel bootstrap, dotenv, service providers or database configuration.
spl_autoload_register(static function (string $class): void {
    foreach (['CollaborationCore', 'CollaborationRealtime'] as $module) {
        $prefix = 'Modules\\'.$module.'\\';
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__, 2).'/Modules/'.$module.'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
}, prepend: true);
