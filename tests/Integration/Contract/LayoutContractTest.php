<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Contract;

use LogicException;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\PageFixtures;
use Nowo\VaultBundle\Ui\VaultPages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function implode;
use function restore_error_handler;
use function set_error_handler;

use const E_DEPRECATED;
use const E_USER_DEPRECATED;

/**
 * The layout contract (REQ-UI-003): a layout with the UiKit canonical blocks is used as is. Since 2.0 a 1.x layout (no
 * canonical blocks; the 1.x pages filled `body`) fails the page with a message that says what to do: the bridge
 * deprecated since 1.15 was removed.
 */
#[CoversClass(VaultPages::class)]
final class LayoutContractTest extends TestCase
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

    /**
     * 2.0: the bridge around 1.x layouts (deprecated since 1.15) was removed. A layout that does not render the UiKit
     * canonical blocks fails the page with a message that says what to do, instead of rendering an empty page.
     */
    public function testALegacyLayoutFailsThePageWithWhatToDo(): void
    {
        foreach (['legacy/plain.html.twig', 'legacy/with-flashes.html.twig'] as $layout) {
            try {
                (new VaultPages($layout, $this->twig()))->layout();
                self::fail($layout . ' must fail');
            } catch (LogicException $exception) {
                self::assertStringContainsString('The layout "' . $layout . '" (nowo_vault.web_ui.layout_template) does not render the UiKit canonical blocks', $exception->getMessage());
                self::assertStringContainsString('set nowo_vault.web_ui.layout_template to null', $exception->getMessage());
                self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
            }
        }
    }

    public function testACanonicalLayoutIsUsedAsIs(): void
    {
        $twig  = $this->twig();
        $pages = new VaultPages('canonical.html.twig', $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertSame(['nowo_vault_host_note_template' => null, 'nowo_vault_sharing_enabled' => true], $pages->context([]));

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
        self::assertSame(['nowo_vault_host_note_template' => null, 'nowo_vault_sharing_enabled' => true], $noTwig->context([]));

        self::assertNull((new VaultPages(''))->layout());
        self::assertNull((new VaultPages())->layout());
        self::assertSame(['key' => 'k', 'domain' => VaultPages::DOMAIN, 'params' => ['%n%' => 1]], (array) VaultPages::label('k', ['%n%' => 1]));
    }

    /**
     * Twig 3.14 deprecated Environment::mergeGlobals(); the layout probes read the globals with getGlobals() instead
     * (same context: nothing to merge into an empty context).
     */
    public function testProbingTheLayoutRaisesNoTwigDeprecation(): void
    {
        $all = [];
        set_error_handler(static function (int $level, string $message) use (&$all): bool {
            $all[] = $message;

            return true;
        }, E_USER_DEPRECATED | E_DEPRECATED);

        try {
            self::assertSame('canonical.html.twig', (new VaultPages('canonical.html.twig', $this->twig()))->layout());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $all, 'No deprecation is raised: ' . implode("\n", $all));
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
                return LayoutContractTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
