<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes manage Web UI globals to Twig (REQ-UI-001).
 */
final class VaultExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_CSS_FRAMEWORK = 'nowo_vault_css_framework';

    /**
     * Creates the extension with the configured CSS framework.
     */
    public function __construct(
        private readonly string $cssFramework = 'tabler',
    ) {
    }

    /**
     * Exposes the CSS framework as a Twig global.
     *
     * @return array<string, string>
     */
    public function getGlobals(): array
    {
        return [
            self::GLOBAL_CSS_FRAMEWORK => $this->cssFramework,
        ];
    }
}
