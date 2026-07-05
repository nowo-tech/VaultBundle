<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Event;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Event\VaultBrowserExtensionAuthEvent;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultBrowserExtensionAuthEventTest extends TestCase
{
    public function testListenerCanHandleAuth(): void
    {
        $event = new VaultBrowserExtensionAuthEvent('demo', 'secret');
        self::assertFalse($event->isHandled());

        $event->setResult(VaultBrowserExtensionAuthResult::success(new TestUser('1')));
        self::assertTrue($event->isHandled());
        self::assertTrue($event->getResult()?->isSuccess());
    }
}
