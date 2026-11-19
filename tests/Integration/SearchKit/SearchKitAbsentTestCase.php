<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\SearchKit;

use PHPUnit\Framework\TestCase;

/**
 * Since 1.21.0: stands in for SearchKit's EntitySearchProviderTestCase when SearchKit (PHP >= 8.3) is not installed
 * (the PHP 8.2 jobs, the dependency floor), so the SearchKit test classes load and are skipped instead of being
 * reported as missing.
 */
abstract class SearchKitAbsentTestCase extends TestCase
{
    public function testSearchKitIsInstalled(): void
    {
        self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
    }
}
