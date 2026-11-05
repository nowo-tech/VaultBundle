<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\VaultBundle\Tests\Support\PageFixtures;

/**
 * REQ-UI-003 page contract of the pages added in 1.16.0 (scope vault list, share page with temporary grants).
 */
final class Vault116PageContractTest extends PageContractTestCase
{
    use VaultContractEnvironment;

    public static function pageFixtures(): iterable
    {
        yield 'scope items' => new PageFixture('@NowoVaultBundle/vault/scope_items.html.twig', PageFixtures::scopeItems());
        yield 'scope items (empty, no create)' => new PageFixture('@NowoVaultBundle/vault/scope_items.html.twig', PageFixtures::scopeItems(true));
        yield 'share (temporary grants)' => new PageFixture('@NowoVaultBundle/vault/share.html.twig', PageFixtures::shareTemporary());
    }
}
