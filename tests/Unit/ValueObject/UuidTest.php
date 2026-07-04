<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\VaultBundle\ValueObject\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGenerateProducesValidUuid(): void
    {
        $uuid = Uuid::generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid->toString(),
        );
        self::assertSame($uuid->toString(), (string) $uuid);
    }

    public function testFromStringNormalizesCase(): void
    {
        $uuid = Uuid::fromString('550E8400-E29B-41D4-A716-446655440000');

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $uuid->toString());
    }

    public function testFromStringRejectsInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Uuid::fromString('not-a-uuid');
    }
}
