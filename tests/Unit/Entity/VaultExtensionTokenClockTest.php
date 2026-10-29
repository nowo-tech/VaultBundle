<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultExtensionTokenClockTest extends TestCase
{
    public function testExpiryAgainstExplicitAndSystemTime(): void
    {
        $user  = new TestUser('1');
        $token = new VaultExtensionToken('hash', new DateTimeImmutable('2030-01-01 12:00:00'), $user);

        self::assertSame('hash', $token->getTokenHash());
        self::assertSame($user, $token->getUser());
        self::assertSame('2030-01-01 12:00:00', $token->getExpiresAt()->format('Y-m-d H:i:s'));
        self::assertNotSame('', $token->getId());
        self::assertInstanceOf(DateTimeImmutable::class, $token->getCreatedAt());
        self::assertFalse($token->isExpired(new DateTimeImmutable('2030-01-01 11:59:59')));
        self::assertTrue($token->isExpired(new DateTimeImmutable('2030-01-01 12:00:00')));
        self::assertFalse($token->isExpired());

        $past = new VaultExtensionToken('old', new DateTimeImmutable('-1 minute'), $user);
        self::assertTrue($past->isExpired());
    }

    public function testTouchUsesGivenTimeOrSystemTime(): void
    {
        $token = new VaultExtensionToken('hash', new DateTimeImmutable('+1 hour'), new TestUser('1'));
        self::assertNull($token->getLastUsedAt());

        $at = new DateTimeImmutable('2029-05-05 05:05:05');
        self::assertSame($token, $token->touch($at));
        self::assertSame($at, $token->getLastUsedAt());

        $before = new DateTimeImmutable();
        $token->touch();
        self::assertGreaterThanOrEqual($before->getTimestamp(), $token->getLastUsedAt()?->getTimestamp());
    }
}
