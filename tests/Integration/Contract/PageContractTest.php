<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Tests\Support\PageFixtures;

/**
 * REQ-UI-003 page contract: every manage page extends a UiKit scaffold (dashboard, list, form, settings); no <html>,
 * CDN, inline handler or confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or
 * missing translation keys (strict rule); the CSRF forms of the actions keep their hidden fields.
 */
final class PageContractTest extends PageContractTestCase
{
    use VaultContractEnvironment;

    public static function pageFixtures(): iterable
    {
        yield 'home (admin)' => new PageFixture('@NowoVaultBundle/vault/home.html.twig', PageFixtures::home());
        yield 'home (host dashboard)' => new PageFixture('@NowoVaultBundle/vault/home.html.twig', PageFixtures::home(false));
        yield 'items' => new PageFixture('@NowoVaultBundle/vault/items.html.twig', PageFixtures::items());
        yield 'items (empty)' => new PageFixture('@NowoVaultBundle/vault/items.html.twig', PageFixtures::items(true));
        yield 'shared' => new PageFixture('@NowoVaultBundle/vault/shared.html.twig', PageFixtures::shared());
        yield 'shared (empty)' => new PageFixture('@NowoVaultBundle/vault/shared.html.twig', PageFixtures::shared(true));
        yield 'trash' => new PageFixture('@NowoVaultBundle/vault/trash.html.twig', PageFixtures::trash());
        yield 'trash (empty)' => new PageFixture('@NowoVaultBundle/vault/trash.html.twig', PageFixtures::trash(true));
        yield 'new login' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm());
        yield 'edit card' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm(VaultItemType::CreditCard, true));
        yield 'edit contact' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm(VaultItemType::Contact, true));
        yield 'new note' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm(VaultItemType::SecureNote));
        yield 'new passport' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm(VaultItemType::Passport));
        yield 'read-only grant' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm(VaultItemType::IdCard, true, true));
        yield 'view' => new PageFixture('@NowoVaultBundle/vault/item_form.html.twig', PageFixtures::itemForm(VaultItemType::Login, true, true, true));
        yield 'share (picker, grants)' => new PageFixture('@NowoVaultBundle/vault/share.html.twig', PageFixtures::share());
        yield 'share (grantee id, no grants)' => new PageFixture('@NowoVaultBundle/vault/share.html.twig', PageFixtures::share(false, false));
        yield 'runtime config (editable)' => new PageFixture('@NowoVaultBundle/vault/runtime_config.html.twig', PageFixtures::runtimeConfig());
        yield 'runtime config (configured)' => new PageFixture('@NowoVaultBundle/vault/runtime_config.html.twig', PageFixtures::runtimeConfig('configured'));
        yield 'runtime config (one-time key)' => new PageFixture('@NowoVaultBundle/vault/runtime_config.html.twig', PageFixtures::runtimeConfig('one_time'));
        yield 'runtime config (unavailable)' => new PageFixture('@NowoVaultBundle/vault/runtime_config.html.twig', PageFixtures::runtimeConfig('unavailable'));
    }
}
