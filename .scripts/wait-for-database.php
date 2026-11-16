<?php

declare(strict_types=1);

/*
 * Waits until the database of VAULT_TEST_DATABASE_URL answers `SELECT 1` (REQ-TEST-005, `make test-with-db`).
 * Same loop as the "Wait for the database" step of the CI job "Id mapping on …". Exits 1 after about two minutes.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$url = getenv('VAULT_TEST_DATABASE_URL');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "VAULT_TEST_DATABASE_URL is not set.\n");
    exit(1);
}

$params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url);
for ($i = 0; $i < 60; ++$i) {
    try {
        DriverManager::getConnection($params)->executeQuery('SELECT 1');
        echo "Database ready.\n";
        exit(0);
    } catch (Throwable) {
        sleep(2);
    }
}

fwrite(STDERR, "Database not reachable.\n");
exit(1);
