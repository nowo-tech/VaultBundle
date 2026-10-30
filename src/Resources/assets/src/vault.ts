/**
 * Vault manage UI: password generator dialog and folder form toggle.
 *
 * No inline script (CSP, REQ-UI-003): the generator endpoint URL and CSRF token are data attributes of the
 * generator <dialog> (data-vault-password-url / data-vault-password-csrf). The dialog itself is the UiKit `dialog`
 * partial: UiKit's nowo-ui-dialog.js opens / closes it; this script fills it (and opens it when the host layout does
 * not load the UiKit scripts).
 */

import './vault.css';
import { fetchGeneratedPassword, readGeneratorOptionsFromDom } from './vault-password-client';

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

/** Open the dialog after the click when no UiKit dialog script did it. */
function ensureOpen(dialog: HTMLElement): void {
    window.setTimeout(() => {
        if (dialog instanceof HTMLDialogElement && !dialog.open && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    }, 0);
}

/** Bind the generator dialog: refresh on open, regenerate, copy, fill the item password field. */
function bindPasswordDialog(): void {
    const dialog = document.querySelector('[data-vault-password-modal]') as HTMLElement | null;
    if (!dialog) {
        return;
    }

    const output = dialog.querySelector('[data-vault-password-output]') as HTMLInputElement | null;
    const strength = dialog.querySelector('[data-vault-password-strength]') as HTMLElement | null;

    const refresh = async (): Promise<void> => {
        const result = await generatePassword(dialog);
        if (output) {
            output.value = result.password;
        }
        if (strength) {
            strength.textContent = result.strength;
        }
    };

    document.querySelectorAll('[data-vault-password-generator]').forEach((button) => {
        button.addEventListener('click', () => {
            ensureOpen(dialog);
            void refresh();
        });
    });

    // Browser-only form: never submitted (Enter in a field included).
    dialog.querySelector('[data-vault-password-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
    });

    dialog.querySelector('[data-vault-password-regenerate]')?.addEventListener('click', () => {
        void refresh();
    });

    dialog.querySelector('[data-vault-password-copy]')?.addEventListener('click', async () => {
        if (output?.value) {
            await navigator.clipboard.writeText(output.value);
        }
    });

    dialog.querySelector('[data-vault-password-fill]')?.addEventListener('click', () => {
        const passwordField = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement | null;
        if (passwordField && output?.value) {
            passwordField.value = output.value;
        }
        if (dialog instanceof HTMLDialogElement && dialog.open) {
            dialog.close();
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
});
