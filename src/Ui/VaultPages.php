<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Ui;

use LogicException;
use Nowo\UiKitBundle\View\Label;
use Throwable;
use Twig\Environment;

use function sprintf;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): the page templates (`web_ui.templates.<page>` overrides
 * them), the layout the manage pages extend (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`), the share
 * UI switch and the host note template (1.18.0). Configuration only: no request state, safe in worker mode.
 *
 * Since 2.0 a configured layout must render the UiKit canonical blocks: the 1.x bridge around a layout that renders
 * `{% block body %}` was removed, and such a layout fails the page with a {@see LogicException} that says what to do.
 */
final readonly class VaultPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoVaultBundle`). */
    public const BUNDLE = 'NowoVaultBundle';

    /** Translation domain of the bundle labels (REQ-I18N-003). */
    public const DOMAIN = 'NowoVaultBundle';

    /** Form theme of the item form when nowo-tech/tag-input-bundle is installed (tag container markup). */
    public const ITEM_FORM_THEME = '@NowoVaultBundle/Form/vault_item_form_theme.html.twig';

    /**
     * Attribute of a row action drawn inside the row's kebab menu (1.18.0, `web_ui.destructive_actions_in_menu`) by the
     * bundle's `row_actions` partial ({@see self::ROW_ACTIONS_PARTIAL}).
     */
    public const ROW_MENU_ATTRIBUTE = 'data-vault-row-menu';

    /** UiKit `row_actions` partial of the vault pages (`nowo_ui_kit.theme.bundles.NowoVaultBundle.partials.row_actions`). */
    public const HEADER_ACTIONS_BUTTONS = 'buttons';

    public const HEADER_ACTIONS_PAGE_ACTIONS = 'page_actions';

    /** Id of the item type chooser dialog (`web_ui.header_actions: page_actions`). */
    public const NEW_ITEM_DIALOG = 'vault-new-item-dialog';

    public const ROW_ACTIONS_PARTIAL = '@NowoVaultBundle/ui/_row_actions.html.twig';

    /**
     * UiKit `table` partial of the vault pages (`nowo_ui_kit.theme.bundles.NowoVaultBundle.partials.table`): extends
     * the host / kit partial with `confirm_shared` on, so a list prints one confirm dialog, not one per row.
     */
    public const TABLE_PARTIAL = '@NowoVaultBundle/ui/_table.html.twig';

    /** Id of the shared confirm dialog of the folder and tag chips of the items list (one dialog for every chip). */
    public const CHIPS_CONFIRM_DIALOG = 'vault-chips-confirm';

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'home'           => '@NowoVaultBundle/vault/home.html.twig',
        'items'          => '@NowoVaultBundle/vault/items.html.twig',
        'item_form'      => '@NowoVaultBundle/vault/item_form.html.twig',
        'trash'          => '@NowoVaultBundle/vault/trash.html.twig',
        'shared'         => '@NowoVaultBundle/vault/shared.html.twig',
        'share'          => '@NowoVaultBundle/vault/share.html.twig',
        'runtime_config' => '@NowoVaultBundle/vault/runtime_config.html.twig',
        // 1.16.0 scope vaults.
        'scope_items' => '@NowoVaultBundle/vault/scope_items.html.twig',
        'catalogue'   => '@NowoVaultBundle/vault/catalogue.html.twig',
        'health'      => '@NowoVaultBundle/vault/health.html.twig',
        'export'      => '@NowoVaultBundle/vault/export.html.twig',
    ];

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /**
     * Keeps the layout, the page templates, the share UI switch and the host note template.
     */
    public function __construct(
        private ?string $layoutTemplate = null,
        private ?Environment $twig = null,
        /** 1.18.0 `sharing.enabled`: false hides every Share action and page link. */
        private bool $sharingEnabled = true,
        /** 1.18.0 `web_ui.host_note_template`: included under the header of the home and items pages. */
        private ?string $hostNoteTemplate = null,
        /** 1.18.0 `web_ui.destructive_actions_in_menu`: Move to trash goes to the end of the row's kebab menu. */
        private bool $destructiveActionsInMenu = false,
        /** 2.2 `web_ui.header_actions`: `buttons` (default) or `page_actions` (one New item entry opening the type chooser). */
        private string $headerActions = self::HEADER_ACTIONS_BUTTONS,
    ) {
    }

    /**
     * Whether the header create actions are one "New item" entry for the page-actions menu (`web_ui.header_actions:
     * page_actions`) instead of one button per item type.
     */
    public function headerActionsInPageMenu(): bool
    {
        return $this->headerActions === self::HEADER_ACTIONS_PAGE_ACTIONS;
    }

    /**
     * Whether destructive row actions are drawn inside the row's kebab menu (`web_ui.destructive_actions_in_menu`).
     */
    public function destructiveActionsInMenu(): bool
    {
        return $this->destructiveActionsInMenu;
    }

    /**
     * Whether the share UI is on (`sharing.enabled`, 1.18.0).
     */
    public function sharingEnabled(): bool
    {
        return $this->sharingEnabled;
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
     * Layout the scaffolds extend (`page.layout`): `web_ui.layout_template`, else null (UiKit `nowo_ui_layout.admin`).
     *
     * @throws LogicException when the configured layout does not render the UiKit canonical blocks (a 1.x layout)
     */
    public function layout(): ?string
    {
        if ($this->layoutTemplate === null || $this->layoutTemplate === '') {
            return null;
        }

        if ($this->isLegacy($this->layoutTemplate)) {
            throw new LogicException(sprintf('The layout "%s" (nowo_vault.web_ui.layout_template) does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...). The bridge around 1.x layouts was removed in 2.0 (deprecated since 1.15): render the canonical blocks, extend @NowoUiKit/layout/admin.html.twig, or set nowo_vault.web_ui.layout_template to null to use nowo_ui_kit.layouts.admin. See UPGRADING.md "To 2.0.0".', $this->layoutTemplate));
        }

        return $this->layoutTemplate;
    }

    /**
     * Page variables plus the shared ones (the host note slot and the share UI switch).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $vars + [
            // 1.18.0: the host note slot (vault_host_note block) and the share UI switch of the templates.
            'nowo_vault_host_note_template' => $this->hostNoteTemplate !== '' ? $this->hostNoteTemplate : null,
            'nowo_vault_sharing_enabled'    => $this->sharingEnabled,
        ];
    }

    /**
     * Whether the configured layout is a 1.x layout: it loads and does not define the UiKit canonical blocks. A layout
     * that cannot be loaded is left to Twig (the page fails with Twig's own error).
     */
    private function isLegacy(string $layout): bool
    {
        if (!$this->twig instanceof Environment) {
            return false;
        }

        try {
            return !$this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->getGlobals());
        } catch (Throwable) {
            return false;
        }
    }
}
