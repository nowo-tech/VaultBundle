/**
 * Vault manage UI: password generator dialog and folder form toggle.
 *
 * No inline script (CSP, REQ-UI-003): the generator endpoint URL and CSRF token are data attributes of the
 * generator content (data-vault-password-url / data-vault-password-csrf), which UiKit's remote form dialog fetches when it opens
 * (owner rule 2026-11-23); this script's listeners are delegated.
 */

import './vault.css';
import { fetchGeneratedPassword, readGeneratorOptionsFromDom } from './vault-password-client';
import { bindRevealButtons, bindStrengthFeedback } from './vault-reveal';
import { createBundleLogger } from './logger';

declare const __VAULT_BUILD_TIME__: string;

createBundleLogger('vault', {
    buildTime: typeof __VAULT_BUILD_TIME__ !== 'undefined' ? __VAULT_BUILD_TIME__ : undefined,
}).scriptLoaded();

/** Endpoint and CSRF token of the generator, read from its dialog. */
export interface GeneratorConfig {
    url: string;
    csrfToken?: string;
}

/**
 * Read the generator endpoint from the dialog data attributes.
 */
export function readGeneratorConfig(dialog: HTMLElement | null): GeneratorConfig | null {
    const url = dialog?.dataset.vaultPasswordUrl;
    if (!dialog || !url) {
        return null;
    }

    return { url, csrfToken: dialog.dataset.vaultPasswordCsrf || undefined };
}

/**
 * Call the server password generator configured on the dialog.
 */
export async function generatePassword(dialog: HTMLElement | null): Promise<{ password: string; strength: string }> {
    const config = readGeneratorConfig(dialog);
    if (!config) {
        throw new Error('Password generator URL is not configured.');
    }

    return fetchGeneratedPassword(config.url, readGeneratorOptionsFromDom(document), config.csrfToken);
}

/** The content of the generator dialog (it is loaded when the dialog opens: the root element carries the endpoint and the token). */
function generatorRoot(): HTMLElement | null {
    return document.querySelector('[data-vault-password-modal]') as HTMLElement | null;
}

/** Ask the server for a new password with the options of the form and show it. */
async function refresh(root: HTMLElement): Promise<void> {
    const output = root.querySelector('[data-vault-password-output]') as HTMLInputElement | null;
    const strength = root.querySelector('[data-vault-password-strength]') as HTMLElement | null;
    const result = await generatePassword(root);
    if (output) {
        output.value = result.password;
    }
    if (strength) {
        strength.textContent = result.strength;
    }
}

/**
 * Bind the generator dialog (owner rule 2026-11-23: its content is fetched when it opens, so every listener is delegated):
 * regenerate, copy, fill the item password field; the form is never submitted.
 */
function bindPasswordDialog(): void {
    document.addEventListener('submit', (event) => {
        if (event.target instanceof Element && event.target.matches('[data-vault-password-form]')) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const root = generatorRoot();
        if (!target || !root) {
            return;
        }
        if (target.closest('[data-vault-password-regenerate]')) {
            void refresh(root);
        } else if (target.closest('[data-vault-password-copy]')) {
            const output = root.querySelector('[data-vault-password-output]') as HTMLInputElement | null;
            if (output?.value) {
                void navigator.clipboard.writeText(output.value);
            }
        } else if (target.closest('[data-vault-password-fill]')) {
            const output = root.querySelector('[data-vault-password-output]') as HTMLInputElement | null;
            const passwordField = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement | null;
            if (passwordField && output?.value) {
                passwordField.value = output.value;
            }
            const dialog = root.closest('dialog');
            if (dialog instanceof HTMLDialogElement && dialog.open) {
                dialog.close();
            }
        }
    });
}

/** Bind the Add folder toggle (shows the folder create form). */
function bindFolderForm(): void {
    const toggle = document.querySelector('[data-vault-folder-toggle]') as HTMLElement | null;
    const form = document.querySelector('[data-vault-folder-form]') as HTMLElement | null;
    if (!toggle || !form) {
        return;
    }

    toggle.addEventListener('click', () => {
        form.hidden = !form.hidden;
        toggle.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
        if (!form.hidden) {
            (form.querySelector('input:not([type="hidden"])') as HTMLInputElement | null)?.focus();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindPasswordDialog();
    bindFolderForm();
    bindRevealButtons(document);
    bindStrengthFeedback(document);
});
