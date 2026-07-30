<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Twig;

use Nowo\VaultBundle\Twig\VaultExtension;
use PHPUnit\Framework\TestCase;

final class VaultExtensionTest extends TestCase
{
    public function testDefaultCssFrameworkIsTabler(): void
    {
        $globals = (new VaultExtension())->getGlobals();

        self::assertSame('tabler', $globals[VaultExtension::GLOBAL_CSS_FRAMEWORK]);
    }

    public function testExposesConfiguredCssFramework(): void
    {
        $globals = (new VaultExtension('bootstrap5'))->getGlobals();

        self::assertSame('bootstrap5', $globals[VaultExtension::GLOBAL_CSS_FRAMEWORK]);
    }
}
