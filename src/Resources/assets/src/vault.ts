import './vault.css';
import { fetchGeneratedPassword, readGeneratorOptionsFromDom } from './vault-password-client';

declare global {
    interface Window {
        VAULT_PASSWORD_URL?: string;
        VAULT_PASSWORD_CSRF_TOKEN?: string;
    }
}

async function generatePassword(): Promise<{ password: string; strength: string }> {
    const url = window.VAULT_PASSWORD_URL;
    if (!url) {
        throw new Error('Password generator URL is not configured.');
    }

    return fetchGeneratedPassword(url, readGeneratorOptionsFromDom(document), window.VAULT_PASSWORD_CSRF_TOKEN);
}

function bindPasswordModal(): void {
    const modal = document.querySelector('[data-vault-password-modal]') as HTMLElement | null;
    const openButtons = document.querySelectorAll('[data-vault-password-generator]');
    const closeButtons = document.querySelectorAll('[data-vault-password-close]');
    const output = document.querySelector('[data-vault-password-output]') as HTMLInputElement | null;
    const strength = document.querySelector('[data-vault-password-strength]') as HTMLElement | null;

    const refresh = async (): Promise<void> => {
        const result = await generatePassword();
        if (output) {
            output.value = result.password;
        }
        if (strength) {
            strength.textContent = result.strength;
        }
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (modal) {
                modal.hidden = false;
            }
            void refresh();
        });
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (modal) {
                modal.hidden = true;
            }
        });
    });

    document.querySelector('[data-vault-password-regenerate]')?.addEventListener('click', () => {
        void refresh();
    });

    document.querySelector('[data-vault-password-copy]')?.addEventListener('click', async () => {
        if (output?.value) {
            await navigator.clipboard.writeText(output.value);
        }
    });

    document.querySelector('[data-vault-password-fill]')?.addEventListener('click', () => {
        const passwordField = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement | null;
        if (passwordField && output?.value) {
            passwordField.value = output.value;
        }
        if (modal) {
            modal.hidden = true;
        }
    });
}

function bindInlineGenerator(): void {
    document.querySelector('[data-vault-generate-inline]')?.addEventListener('click', async () => {
        const result = await generatePassword();
        const passwordField = document.querySelector('[name="vault_item_form[password]"]') as HTMLInputElement | null;
        if (passwordField) {
            passwordField.value = result.password;
        }
    });
}

function bindFolderForm(): void {
    document.querySelector('[data-vault-folder-toggle]')?.addEventListener('click', () => {
        const form = document.querySelector('[data-vault-folder-form]') as HTMLElement | null;
        if (form) {
            form.hidden = !form.hidden;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindPasswordModal();
    bindInlineGenerator();
    bindFolderForm();
});

export { generatePassword };
