<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Dto\PasswordGeneratorOptions;
use Nowo\VaultBundle\Service\VaultPasswordGenerator;
use PHPUnit\Framework\TestCase;

use function strlen;

final class VaultPasswordGeneratorTest extends TestCase
{
    public function testGeneratesCharacterPasswordWithRequestedLength(): void
    {
        $generator = new VaultPasswordGenerator();
        $password  = $generator->generate(new PasswordGeneratorOptions(length: 24));

        self::assertSame(24, strlen($password));
    }

    public function testGeneratesPassphrase(): void
    {
        $generator = new VaultPasswordGenerator();
        $password  = $generator->generate(new PasswordGeneratorOptions(mode: 'words', length: 16));

        self::assertNotSame('', $password);
        self::assertGreaterThanOrEqual(3, substr_count(trim($password), ' ') + substr_count(trim($password), '-') + 1);
    }

    public function testFallsBackWhenCharacterPoolEmpty(): void
    {
        $generator = new VaultPasswordGenerator();
        $password  = $generator->generate(new PasswordGeneratorOptions(
            length: 8,
            useLower: false,
            useUpper: false,
            useDigits: false,
            useSymbols: false,
        ));

        self::assertSame(8, strlen($password));
    }

    public function testPassphraseWithSymbolsSeparator(): void
    {
        $generator = new VaultPasswordGenerator();
        $password  = $generator->generate(new PasswordGeneratorOptions(mode: 'words', length: 12, useSymbols: true));

        self::assertStringContainsString('-', $password);
    }

    public function testEstimateStrength(): void
    {
        $generator = new VaultPasswordGenerator();
        self::assertSame('weak', $generator->estimateStrength('short'));
        self::assertSame('medium', $generator->estimateStrength('1234567890'));
        self::assertSame('strong', $generator->estimateStrength('1234567890123456'));
    }
}
