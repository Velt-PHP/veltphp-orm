<?php

declare(strict_types=1);

$localAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$workspaceAutoload = dirname(__DIR__, 2) . '/velt-database/vendor/autoload.php';

$loader = require is_file($localAutoload) ? $localAutoload : $workspaceAutoload;

if (method_exists($loader, 'addPsr4')) {
    $localDatabaseCandidates = [
        dirname(__DIR__, 2) . '/velt-database/src',
        dirname(__DIR__) . '/velt-database/src',
    ];
    foreach ($localDatabaseCandidates as $localDatabase) {
        if (is_dir($localDatabase)) {
            $loader->addPsr4('Velt\\Database\\', $localDatabase, true);
            break;
        }
    }

    $loader->addPsr4('Velt\\Orm\\', dirname(__DIR__) . '/src/');
    $loader->addPsr4('Velt\\Orm\\Tests\\', dirname(__DIR__) . '/tests/');
}

return $loader;
