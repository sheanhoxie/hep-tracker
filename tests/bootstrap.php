<?php

declare(strict_types=1);

// A cached config (bootstrap/cache/config.php) overrides phpunit's
// APP_ENV/DB_* below, silently pointing RefreshDatabase at the dev database.
if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Refusing to run tests: config is cached and would override the testing env (tests would wipe the dev database).\nRun: php artisan optimize:clear\n");
    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';
