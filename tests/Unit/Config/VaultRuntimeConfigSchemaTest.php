<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Config;

use Nowo\VaultBundle\Config\VaultRuntimeConfigSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VaultRuntimeConfigSchemaTest extends TestCase
{
    #[DataProvider('emptyPasswordFieldLevelProvider')]
    public function testRemovesPasswordFieldWhenLevelEmpty(array $passwordField): void
    {
        $filtered = VaultRuntimeConfigSchema::filter([
            'max_attachment_bytes' => 512_000,
            'password_field'       => $passwordField,
        ]);

        self::assertSame(512_000, $filtered['max_attachment_bytes']);
        self::assertArrayNotHasKey('password_field', $filtered);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function emptyPasswordFieldLevelProvider(): iterable
    {
        yield 'empty string' => [['level' => '']];
        yield 'null level' => [['level' => null]];
        yield 'missing level key' => [['generator_mode' => 'modal']];
    }
}
