<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\BrowserExtension;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultBrowserExtensionAuthResultTest extends TestCase
{
    public function testFailureExposesReason(): void
    {
        $result = VaultBrowserExtensionAuthResult::failure('invalid credentials');

        self::assertFalse($result->isSuccess());
        self::assertNull($result->getUser());
        self::assertSame('invalid credentials', $result->getFailureReason());
    }

    public function testSuccessHasNoFailureReason(): void
    {
        $result = VaultBrowserExtensionAuthResult::success(new TestUser('1'));

        self::assertTrue($result->isSuccess());
        self::assertNull($result->getFailureReason());
    }
}
