<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Ui;

use Nowo\UiKitBundle\View\Label;
use Throwable;
use Twig\Environment;

use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): the page templates (`web_ui.templates.<page>` overrides
 * them), the layout the manage pages extend (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`) and the
 * deprecated bridge around a 1.x layout (no UiKit canonical blocks). Configuration only: no request state, safe in
 * worker mode.
 */
final readonly class VaultPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoVaultBundle`). */
    public const BUNDLE = 'NowoVaultBundle';

    /** Translation domain of the bundle labels (REQ-I18N-003). */
    public const DOMAIN = 'NowoVaultBundle';

    /** BC bridge around a 1.x layout that renders `{% block body %}` (removed in 2.0). */
    public const LEGACY_LAYOUT_BRIDGE = '@NowoVaultBundle/vault/_legacy_layout.html.twig';

    /** Form theme of the item form when nowo-tech/tag-input-bundle is installed (tag container markup). */
    public const ITEM_FORM_THEME = '@NowoVaultBundle/Form/vault_item_form_theme.html.twig';

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'home'           => '@NowoVaultBundle/vault/home.html.twig',
        'items'          => '@NowoVaultBundle/vault/items.html.twig',
        'item_form'      => '@NowoVaultBundle/vault/item_form.html.twig',
        'trash'          => '@NowoVaultBundle/vault/trash.html.twig',
        'shared'         => '@NowoVaultBundle/vault/shared.html.twig',
        'share'          => '@NowoVaultBundle/vault/share.html.twig',
        'runtime_config' => '@NowoVaultBundle/vault/runtime_config.html.twig',
    ];

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /** Canonical blocks a 1.x layout may already render (the bridge skips them). */
    private const LEGACY_HOST_BLOCKS = ['nowo_ui_flashes', 'nowo_ui_styles', 'nowo_ui_scripts'];

    public function __construct(
        private ?string $layoutTemplate = null,
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Layout the scaffolds extend (`page.layout`): `web_ui.layout_template`, the legacy bridge for a 1.x layout, else
     * null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        if ($this->layoutTemplate === null || $this->layoutTemplate === '') {
            return null;
        }

        return $this->legacy($this->layoutTemplate) !== null ? self::LEGACY_LAYOUT_BRIDGE : $this->layoutTemplate;
    }

    /**
     * Page variables plus the shared ones: the 1.x layout wrapped by the deprecated bridge and the canonical blocks
     * it already renders.
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        $legacy = $this->layoutTemplate !== null && $this->layoutTemplate !== '' ? $this->legacy($this->layoutTemplate, false) : null;

        return $vars + [
            'nowo_vault_legacy_layout' => $legacy,
            'nowo_vault_legacy_blocks' => $this->hostBlocks($legacy),
        ];
    }

    /**
     * Canonical blocks a 1.x layout defines itself (the bridge does not render them a second time).
     *
     * @return list<string>
     */
    private function hostBlocks(?string $legacy): array
    {
        if ($legacy === null || !$this->twig instanceof Environment) {
            return [];
        }

        $template = $this->twig->load($legacy);
        $context  = $this->twig->mergeGlobals([]);
        $blocks   = [];
        foreach (self::LEGACY_HOST_BLOCKS as $block) {
            if ($template->hasBlock($block, $context)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The configured layout when it is a 1.x layout (it does not define the UiKit canonical blocks), else null.
     */
    private function legacy(string $layout, bool $deprecate = true): ?string
    {
        if (!$this->twig instanceof Environment) {
            return null;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return null;
        }

        if ($canonical) {
            return null;
        }

        if ($deprecate) {
            trigger_deprecation('nowo-tech/vault-bundle', '1.15', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in a deprecated bridge. Render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig) or set nowo_vault.web_ui.layout_template to null to use nowo_ui_kit.layouts.admin; see docs/UPGRADING.md.', $layout);
        }

        return $layout;
    }
}
