<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The deployed source is read-only. Only temporary working files belong here;
// sessions, cache, records and stored backups use Supabase, never this folder.
$temporary = sys_get_temp_dir().'/ascot-drrms';
foreach (['bootstrap', 'framework/views', 'framework/cache/data', 'framework/sessions', 'app/private', 'logs', 'fonts'] as $directory) {
    $path = $temporary.'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException('Unable to create the temporary application directory.');
    }
}
foreach (['APP_CONFIG_CACHE' => 'config.php', 'APP_EVENTS_CACHE' => 'events.php', 'APP_PACKAGES_CACHE' => 'packages.php', 'APP_SERVICES_CACHE' => 'services.php', 'APP_ROUTES_CACHE' => 'routes.php'] as $variable => $filename) {
    $path = $temporary.'/bootstrap/'.$filename;
    putenv($variable.'='.$path);
    $_ENV[$variable] = $_SERVER[$variable] = $path;
    // Package discovery runs during build, without database access.
    if (in_array($filename, ['packages.php', 'services.php'], true) && ! is_file($path) && is_file(__DIR__.'/../bootstrap/cache/'.$filename)) {
        copy(__DIR__.'/../bootstrap/cache/'.$filename, $path);
    }
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->useStoragePath($temporary);
$app->handleRequest(Request::capture());
