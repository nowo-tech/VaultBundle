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

/** 1.16.0 additions read from the same element: copy labels, auto-hide delay and the attachment endpoint. */
export interface RevealExtras {
    copyLabel: string;
    copiedLabel: string;
    autoHideSeconds: number;
    attachmentUrl: string | null;
}

/**
 * Read the 1.16.0 options of the reveal element (defaults when absent).
 */
export function readRevealExtras(root: ParentNode): RevealExtras {
    const element = root.querySelector('[data-vault-reveal]') as HTMLElement | null;
    const seconds = Number.parseInt(element?.dataset.vaultRevealAutoHide ?? '', 10);

    return {
        copyLabel: element?.dataset.vaultRevealCopy || 'Copy',
        copiedLabel: element?.dataset.vaultRevealCopied || 'Copied',
        autoHideSeconds: Number.isFinite(seconds) && seconds > 0 ? seconds : 0,
        attachmentUrl: element?.dataset.vaultAttachmentUrl || null,
    };
}

/**
 * POST to the reveal endpoint for one field and return its value. `purpose` is `reveal` (Show) or `copy` (audited as
 * a copy). The 1.16.0 envelope carries `encoding`: only `plain` is understood (a later zero-knowledge mode answers
 * `ciphertext`, which this client refuses instead of showing it).
 */
export async function fetchSecretField(config: RevealConfig, field: string, purpose: 'reveal' | 'copy' = 'reveal'): Promise<string> {
    const init: RequestInit = {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-CSRF-Token': config.csrfToken },
    };
    if (purpose === 'copy') {
        init.headers = { ...(init.headers as Record<string, string>), 'Content-Type': 'application/x-www-form-urlencoded' };
        init.body = 'purpose=copy';
    }
    const response = await fetch(config.url.replace('FIELD', encodeURIComponent(field)), init);
    if (!response.ok) {
        throw new Error('Reveal failed.');
    }

    const data = (await response.json()) as { value?: unknown; encoding?: unknown };
    if (data.encoding !== undefined && data.encoding !== 'plain') {
        throw new Error('Unsupported encoding.');
    }
    if (Array.isArray(data.value)) {
        return data.value.filter((entry): entry is string => typeof entry === 'string').join('\n');
    }

    return typeof data.value === 'string' ? data.value : '';
}

/** File name from a Content-Disposition header (RFC 5987 `filename*` first), or the fallback. */
export function attachmentFilename(header: string | null): string {
    const extended = /filename\*=UTF-8''([^;]+)/i.exec(header ?? '');
    if (extended) {
        try {
            return decodeURIComponent(extended[1]);
        } catch {
            // fall through to the ASCII name
        }
    }
    const plain = /filename="([^"]*)"/i.exec(header ?? '');

    return plain && plain[1] !== '' ? plain[1] : 'attachment.bin';
}

/**
 * POST to the attachment endpoint and hand the file to the browser as a download (the bytes never enter the DOM;
 * the object URL is revoked right away).
 */
export async function downloadAttachment(config: RevealConfig, url: string, root: Document = document): Promise<void> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-CSRF-Token': config.csrfToken },
    });
    if (!response.ok) {
        throw new Error('Download failed.');
    }

    const objectUrl = URL.createObjectURL(await response.blob());
    const link = root.createElement('a');
    link.href = objectUrl;
    link.download = attachmentFilename(response.headers.get('Content-Disposition'));
    link.rel = 'noopener';
    root.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
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

    const extras = readRevealExtras(root);
    const timers = new Map<SecretInput, ReturnType<typeof setTimeout>>();
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
                if (extras.autoHideSeconds > 0) {
                    clearTimeout(timers.get(input));
                    timers.set(input, setTimeout(() => conceal(input, button, config), extras.autoHideSeconds * 1000));
                }
            } catch {
                button.textContent = config.errorLabel;
            }
        });

        // 1.16.0: Copy writes to the clipboard without putting the value in the page.
        const copy = root.createElement('button');
        copy.type = 'button';
        copy.className = 'vault-reveal-button';
        copy.dataset.vaultCopyButton = field;
        copy.textContent = extras.copyLabel;
        copy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(await fetchSecretField(config, field, 'copy'));
                copy.textContent = extras.copiedLabel;
            } catch {
                copy.textContent = config.errorLabel;
            }
        });

        input.insertAdjacentElement('afterend', button);
        button.insertAdjacentElement('afterend', copy);
    });

    const attachment = root.querySelector('[data-vault-attachment-download]') as HTMLButtonElement | null;
    if (attachment && extras.attachmentUrl) {
        const url = extras.attachmentUrl;
        attachment.addEventListener('click', async () => {
            try {
                await downloadAttachment(config, url, root);
            } catch {
                attachment.textContent = config.errorLabel;
            }
        });
    }

    const wipe = (): void => {
        root.querySelectorAll('[data-vault-reveal-button]').forEach((button) => {
            const input = root.querySelector(`[data-vault-secret-field="${(button as HTMLElement).dataset.vaultRevealButton}"]`) as SecretInput | null;
            if (input) {
                conceal(input, button as HTMLButtonElement, config);
            }
        });
    };
    // 1.16.0: also wipe when the tab is hidden or a surrounding dialog closes.
    root.addEventListener('visibilitychange', () => {
        if (root.visibilityState === 'hidden') {
            wipe();
        }
    });
    root.addEventListener('close', wipe, true);

    // Never leave a revealed value in a page the browser could keep (bfcache) or restore.
    window.addEventListener('pagehide', wipe);

    return inputs;
}
