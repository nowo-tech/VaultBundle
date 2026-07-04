<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Dto;

use Nowo\VaultBundle\Dto\PasswordGeneratorOptions;
use PHPUnit\Framework\TestCase;

final class PasswordGeneratorOptionsTest extends TestCase
{
    public function testFromArrayUsesDefaults(): void
    {
        $options = PasswordGeneratorOptions::fromArray([]);

        self::assertSame('characters', $options->mode);
        self::assertSame(20, $options->length);
        self::assertTrue($options->useLower);
        self::assertTrue($options->useUpper);
        self::assertTrue($options->useDigits);
        self::assertTrue($options->useSymbols);
    }

    public function testFromArrayOverridesValues(): void
    {
        $options = PasswordGeneratorOptions::fromArray([
            'mode'       => 'words',
            'length'     => 32,
            'useLower'   => false,
            'useUpper'   => true,
            'useDigits'  => false,
            'useSymbols' => true,
        ]);

        self::assertSame('words', $options->mode);
        self::assertSame(32, $options->length);
        self::assertFalse($options->useLower);
        self::assertTrue($options->useSymbols);
    }
}
