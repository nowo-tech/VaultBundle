<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Tests\Support\PageFixtures;
use Nowo\VaultBundle\Ui\VaultPages;

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
        yield 'items (header actions in the page-actions menu)' => new PageFixture('@NowoVaultBundle/vault/items.html.twig', PageFixtures::items(false, PageFixtures::factory(new VaultPages(headerActions: 'page_actions'))));
        yield 'items (empty, header actions in the page-actions menu)' => new PageFixture('@NowoVaultBundle/vault/items.html.twig', PageFixtures::items(true, PageFixtures::factory(new VaultPages(headerActions: 'page_actions'))));
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

    public function testItemsKeepOneButtonPerTypeByDefault(): void
    {
        $page = PageFixtures::items();

        self::assertCount(8, $page['page']->header->actions);
        self::assertSame('toolbar', $page['page']->header->actionsLayout);
        self::assertSame([], $page['newItemChoices']);
        $html = $this->renderPage(new PageFixture('@NowoVaultBundle/vault/items.html.twig', $page))->html;
        self::assertStringContainsString('data-testid="vault-new-login"', $html);
        self::assertStringNotContainsString('vault-new-item-dialog', $html);
    }

    public function testPageActionsModeHasOneNewItemEntryAndATypeChooserDialog(): void
    {
        $page   = PageFixtures::items(false, PageFixtures::factory(new VaultPages(headerActions: 'page_actions')));
        $header = $page['page']->header;

        self::assertSame('split', $header->actionsLayout);
        self::assertCount(1, $header->actions);
        self::assertSame('vault-new-item-dialog', $header->actions[0]->dialogId);
        self::assertSame('secondary', $header->actions[0]->resolvedVariant());
        self::assertCount(8, $page['newItemChoices']);

        $menu = $this->renderPage(new PageFixture('@NowoUiKitBundle/partials/_header_actions.html.twig', ['actions' => $header->actions, 'bundle' => VaultPages::BUNDLE]))->html;
        self::assertStringContainsString('data-nowo-ui-header-more', $menu, 'The entry is in the page-actions menu.');
        self::assertStringContainsString('data-testid="vault-new-item"', $menu);

        $html = $this->renderPage(new PageFixture('@NowoVaultBundle/vault/items.html.twig', $page))->html;
        self::assertSame(1, substr_count($html, '<dialog id="vault-new-item-dialog"'));
        self::assertStringContainsString('data-testid="vault-new-item-types"', $html);
        foreach (VaultItemType::cases() as $type) {
            self::assertStringContainsString('data-testid="vault-new-' . $type->value . '"', $html);
        }
        self::assertStringContainsString('Choose the item type', $html);
    }

    public function testTheEmptyItemsListOffersNewItemInPageActionsMode(): void
    {
        $page = PageFixtures::items(true, PageFixtures::factory(new VaultPages(headerActions: 'page_actions')));

        self::assertSame('vault-new-item-dialog', $page['page']->empty?->action?->dialogId);
        $html = $this->renderPage(new PageFixture('@NowoVaultBundle/vault/items.html.twig', $page))->html;
        self::assertStringContainsString('nowo-ui-empty__action', $html);
        self::assertStringNotContainsString('nowo-ui-empty__title', $html, 'One sentence, no title line.');
        self::assertStringNotContainsString('No items found', $html);

        $default = $this->renderPage(new PageFixture('@NowoVaultBundle/vault/items.html.twig', PageFixtures::items(true)))->html;
        self::assertStringNotContainsString('nowo-ui-empty__action', $default);
    }

    public function testTheShareGrantsEmptyStateIsUiKitEmpty(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoVaultBundle/vault/share.html.twig', PageFixtures::share(false, false)))->html;

        self::assertStringContainsString('nowo-ui-empty__message">No users or teams have been granted access yet.</p>', $html);
    }
}
