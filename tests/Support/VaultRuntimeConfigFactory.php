<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use Nowo\VaultBundle\DependencyInjection\RuntimeConfiguration;
use Symfony\Component\Config\Definition\Processor;

final class VaultRuntimeConfigFactory
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function baseline(array $overrides = []): array
    {
        $defaults = (new Processor())->processConfiguration(new RuntimeConfiguration(), [[]]);

        return array_replace_recursive($defaults, $overrides);
    }
}
