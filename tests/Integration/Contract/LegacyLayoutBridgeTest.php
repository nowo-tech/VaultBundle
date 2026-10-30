<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Contract;

use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\PageFixtures;
use Nowo\VaultBundle\Ui\VaultPages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function substr_count;

use const E_USER_DEPRECATED;

/**
 * Deprecated 1.x layouts (no UiKit canonical blocks; the 1.x pages filled `body`) keep working through the bridge: the
 * page renders inside the host `body` block, once, with title, assets and flashes mapped; canonical blocks the 1.x
 * layout renders itself are not printed twice. A layout with the canonical blocks is used as is.
 */
#[CoversClass(VaultPages::class)]
final class LegacyLayoutBridgeTest extends TestCase
{
    public const LAYOUTS = [
        // A host layout written for 1.x: title, stylesheets, body, javascripts
        'legacy/plain.html.twig' => '<header data-host="plain"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<main data-host-main>{% block body %}{% endblock %}</main>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        // A 1.x layout that renders the flashes itself
        'legacy/with-flashes.html.twig' => '<header data-host="flashes"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}</header>'
            . '<div data-host-flashes>{% block nowo_ui_flashes %}{% endblock %}</div>'
            . '<div data-host-body>{% block body %}{% endblock %}</div>{% block javascripts %}{% endblock %}',
        // A host layout that already renders the UiKit canonical blocks
        'canonical.html.twig' => '<div data-host-canonical><title>{% block nowo_ui_document_title %}{% endblock %}</title>{% block nowo_ui_page_header %}{% endblock %}'
            . '{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_content %}{% endblock %}{% block nowo_ui_modals %}{% endblock %}</div>',
    ];

    public function testAPlainLegacyLayoutRendersThePageInItsBodyBlock(): void
    {
        $twig  = $this->twig();
        $pages = new VaultPages('legacy/plain.html.twig', $twig);

        $deprecations = $this->deprecations(static fn (): ?string => $pages->layout());
        self::assertSame([VaultPages::LEGACY_LAYOUT_BRIDGE], $deprecations['result']);
        self::assertCount(1, $deprecations['messages']);
        self::assertStringContainsString('legacy/plain.html.twig', $deprecations['messages'][0]);

        $context = $this->deprecations(static fn (): array => PageFixtures::trash(false, PageFixtures::factory($pages)))['result'][0];
        self::assertSame('legacy/plain.html.twig', $context['nowo_vault_legacy_layout']);
        self::assertSame([], $context['nowo_vault_legacy_blocks']);

        $html = $twig->render('@NowoVaultBundle/vault/trash.html.twig', $context);

        self::assertSame(1, substr_count($html, 'data-host="plain"'));
        self::assertMatchesRegularExpression('/<main data-host-main>.*data-testid="vault-trash"/s', $html);
        self::assertSame(1, substr_count($html, 'data-testid="vault-trash"'));
        self::assertStringContainsString('<title>Trash</title>', $html);
        self::assertStringContainsString('data-host-css', $html);
        self::assertStringContainsString('nowo-ui.css', $html);
        self::assertStringContainsString('data-host-js', $html);
        self::assertStringContainsString('data-nowo-ui-contract-partial="page_header"', $html);
    }

    public function testALayoutThatRendersTheFlashesDoesNotPrintThemTwice(): void
    {
        $twig    = $this->twig();
        $pages   = new VaultPages('legacy/with-flashes.html.twig', $twig);
        $context = $this->deprecations(static fn (): array => PageFixtures::shared(false, PageFixtures::factory($pages)))['result'][0];
        self::assertSame(['nowo_ui_flashes'], $context['nowo_vault_legacy_blocks']);

        $html = $twig->render('@NowoVaultBundle/vault/shared.html.twig', $context);

        self::assertSame(1, substr_count($html, 'data-testid="vault-shared"'));
        self::assertMatchesRegularExpression('/<div data-host-flashes>.*data-nowo-ui-contract-partial="page_header"/s', $html, 'page header before the content');
        self::assertSame(1, substr_count($html, 'data-nowo-ui-contract-partial="page_header"'));
    }

    public function testACanonicalLayoutIsUsedAsIs(): void
    {
        $twig  = $this->twig();
        $pages = new VaultPages('canonical.html.twig', $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame(['nowo_vault_legacy_layout' => null, 'nowo_vault_legacy_blocks' => []], $pages->context([]));

        $html = $twig->render('@NowoVaultBundle/vault/trash.html.twig', PageFixtures::trash(false, PageFixtures::factory($pages)));
        self::assertStringContainsString('<div data-host-canonical><title>Trash</title>', $html);
    }

    public function testUserDataIsEscapedInRowsAndConfirmDialogs(): void
    {
        $twig    = $this->twig();
        $pages   = new VaultPages('canonical.html.twig', $twig);
        $factory = PageFixtures::factory($pages);
        $item    = new VaultItem(VaultItemType::Login, '<img src=x onerror=alert(1)>', new TestUser(), 'cipher');
        $routes  = PageFixtures::routes();
        $context = $factory->context([
            'page'   => $factory->trash($routes, [$item], [], [], $factory->pagination('nowo_vault_trash', 1, 50, 1), null),
            'items'  => [$item],
            'routes' => $routes,
        ]);

        $html = $twig->render('@NowoVaultBundle/vault/trash.html.twig', $context);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testUnknownLayoutsAndMissingTwigFallBackToTheConfiguredValue(): void
    {
        $missing = new VaultPages('missing/layout.html.twig', $this->twig());
        self::assertSame('missing/layout.html.twig', $missing->layout());

        $noTwig = new VaultPages('legacy/plain.html.twig');
        self::assertSame('legacy/plain.html.twig', $noTwig->layout());
        self::assertSame(['nowo_vault_legacy_layout' => null, 'nowo_vault_legacy_blocks' => []], $noTwig->context([]));

        self::assertNull((new VaultPages(''))->layout());
        self::assertNull((new VaultPages())->layout());
        self::assertSame(['key' => 'k', 'domain' => VaultPages::DOMAIN, 'params' => ['%n%' => 1]], (array) VaultPages::label('k', ['%n%' => 1]));
    }

    /**
     * Runs $callback and collects the user deprecations it triggers.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return array{result: list<T>, messages: list<string>}
     */
    private function deprecations(callable $callback): array
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            // Only the bundle's deprecation (Twig reports its own API deprecations through the same channel).
            if (str_contains($message, 'UiKit canonical blocks')) {
                $messages[] = $message;
            }

            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        return ['result' => [$result], 'messages' => $messages];
    }

    private function twig(): Environment
    {
        $case = new class('legacy') extends PageContractTestCase {
            use VaultContractEnvironment;

            public static function pageFixtures(): iterable
            {
                return [];
            }

            public function environment(): Environment
            {
                return $this->createTwig();
            }

            protected static function inlineTemplates(): array
            {
                return LegacyLayoutBridgeTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
