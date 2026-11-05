/**
 * On-demand reveal of secret item fields (1.15.2).
 *
 * The item page never contains a stored secret: each secret input carries data-vault-secret-field="<payload key>"
 * and an empty value. A hidden [data-vault-reveal] element gives the reveal endpoint (field placeholder FIELD), the
 * per-item CSRF token and the button labels. "Show" POSTs to the endpoint and puts the value in the input; "Hide"
 * empties it again (an empty secret field keeps the stored value on save). Values are wiped on pagehide.
 */

/** Reveal endpoint, CSRF token and labels read from the page. */
export interface RevealConfig {
    url: string;
    csrfToken: string;
    showLabel: string;
    hideLabel: string;
    errorLabel: string;
}

type SecretInput = HTMLInputElement | HTMLTextAreaElement;

/**
 * Read the reveal configuration from its hidden element (null when the page has no revealable secret).
 */
export function readRevealConfig(root: ParentNode): RevealConfig | null {
    const element = root.querySelector('[data-vault-reveal]') as HTMLElement | null;
    const url = element?.dataset.vaultRevealUrl;
    const csrfToken = element?.dataset.vaultRevealCsrf;
    if (!element || !url || !csrfToken) {
        return null;
    }

    return {
        url,
        csrfToken,
        showLabel: element.dataset.vaultRevealShow || 'Show',
        hideLabel: element.dataset.vaultRevealHide || 'Hide',
        errorLabel: element.dataset.vaultRevealError || 'Error',
    };
}

/**
 * POST to the reveal endpoint for one field and return its value.
 */
export async function fetchSecretField(config: RevealConfig, field: string): Promise<string> {
    const response = await fetch(config.url.replace('FIELD', encodeURIComponent(field)), {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-CSRF-Token': config.csrfToken },
    });
    if (!response.ok) {
        throw new Error('Reveal failed.');
    }

    const data = (await response.json()) as { value?: unknown };

    return typeof data.value === 'string' ? data.value : '';
}

/** Put the input back to its masked, empty state. */
function conceal(input: SecretInput, button: HTMLButtonElement, config: RevealConfig): void {
    input.value = '';
    if (input instanceof HTMLInputElement && input.dataset.vaultRevealType) {
        input.type = input.dataset.vaultRevealType;
    }
    button.textContent = config.showLabel;
    button.setAttribute('aria-pressed', 'false');
}

/**
 * Add a Show / Hide button after every secret input of the page.
 *
 * @returns the bound inputs
 */
export function bindRevealButtons(root: Document = document): SecretInput[] {
    const config = readRevealConfig(root);
    if (!config) {
        return [];
    }

    const inputs = Array.from(root.querySelectorAll('[data-vault-secret-field]')) as SecretInput[];
    inputs.forEach((input) => {
        const field = input.dataset.vaultSecretField as string;
        const button = root.createElement('button');
        button.type = 'button';
        button.className = 'vault-reveal-button';
        button.dataset.vaultRevealButton = field;
        button.textContent = config.showLabel;
        button.setAttribute('aria-pressed', 'false');
        if (input instanceof HTMLInputElement) {
            input.dataset.vaultRevealType = input.type;
        }

        button.addEventListener('click', async () => {
            if (button.getAttribute('aria-pressed') === 'true') {
                conceal(input, button, config);

                return;
            }

            try {
                const value = await fetchSecretField(config, field);
                input.value = value;
                if (input instanceof HTMLInputElement && input.type === 'password') {
                    input.type = 'text';
                }
                button.textContent = config.hideLabel;
                button.setAttribute('aria-pressed', 'true');
            } catch {
                button.textContent = config.errorLabel;
            }
        });

        input.insertAdjacentElement('afterend', button);
    });

    // Never leave a revealed value in a page the browser could keep (bfcache) or restore.
    window.addEventListener('pagehide', () => {
        root.querySelectorAll('[data-vault-reveal-button]').forEach((button) => {
            const input = root.querySelector(`[data-vault-secret-field="${(button as HTMLElement).dataset.vaultRevealButton}"]`) as SecretInput | null;
            if (input) {
                conceal(input, button as HTMLButtonElement, config);
            }
        });
    });

    return inputs;
}
