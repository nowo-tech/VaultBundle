<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\BrowserExtension;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultBrowserExtensionAuthResultTest extends TestCase
{
    public function testFailureExposesReason(): void
    {
        $result = VaultBrowserExtensionAuthResult::failure('invalid credentials');

        self::assertFalse($result->isSuccess());
        self::assertNull($result->getUserRef());
        self::assertSame('invalid credentials', $result->getFailureReason());
    }

    public function testSuccessHasNoFailureReason(): void
    {
        $result = VaultBrowserExtensionAuthResult::authenticated(VaultUserRefs::fromUser(new TestUser('1')));

        self::assertTrue($result->isSuccess());
        self::assertNull($result->getFailureReason());
    }
}
