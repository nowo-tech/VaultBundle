<?php

declare(strict_types=1);

/*
 * Second writer of the REQ-RUNTIME-003 race tests ({@see Nowo\VaultBundle\Tests\Support\ConcurrentWriter::start()}).
 * Reads {"url", "statements": [[sql, params], ...], "commit_after": seconds} on stdin, runs the statements in one
 * transaction with a 10 s lock timeout, prints READY, waits, commits. Exit 0 once committed.
 */

use Nowo\VaultBundle\Tests\Support\ConcurrentWriter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input      = json_decode((string) stream_get_contents(\STDIN), true, 512, \JSON_THROW_ON_ERROR);
$connection = ConcurrentWriter::connect($input['url']);
ConcurrentWriter::setLockTimeout($connection, 10);
$connection->beginTransaction();
foreach ($input['statements'] as [$sql, $params]) {
    $connection->executeStatement($sql, $params);
}
fwrite(\STDOUT, "READY\n");
fflush(\STDOUT);
usleep((int) ($input['commit_after'] * 1000000));
$connection->commit();
exit(0);
