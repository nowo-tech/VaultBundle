<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Command;

use Nowo\VaultBundle\Command\PurgeExtensionTokensCommand;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeExtensionTokensCommandTest extends TestCase
{
    public function testPurgesExpiredTokens(): void
    {
        $repository = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $repository->expects(self::once())->method('removeExpired')->willReturn(3);

        $command = new PurgeExtensionTokensCommand($repository);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed 3 expired extension token(s).', $tester->getDisplay());
    }
}
