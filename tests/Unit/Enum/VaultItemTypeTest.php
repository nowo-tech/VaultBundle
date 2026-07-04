<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Enum;

use Nowo\VaultBundle\Enum\VaultItemType;
use PHPUnit\Framework\TestCase;

final class VaultItemTypeTest extends TestCase
{
    public function testValuesAndDocumentTypes(): void
    {
        self::assertContains('login', VaultItemType::values());
        self::assertContains(VaultItemType::Passport, VaultItemType::documentTypes());
        self::assertNotContains(VaultItemType::Login, VaultItemType::documentTypes());
    }
}
