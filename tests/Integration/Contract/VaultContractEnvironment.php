<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Contract;

use function dirname;

/**
 * Twig environment of the UiKit page contract test for the bundle: bundle views and catalogue, strict
 * missing-translation rule.
 *
 * @phpstan-require-extends \Nowo\UiKitBundle\Test\PageContractTestCase
 */
trait VaultContractEnvironment
{
    protected static function templatePaths(): array
    {
        return ['NowoVaultBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
