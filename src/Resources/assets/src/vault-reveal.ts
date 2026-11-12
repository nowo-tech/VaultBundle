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

/**
 * 1.18.0: icon buttons rendered by the page (`<template data-vault-reveal-template="view|hide|copy">`, UiKit
 * btn_secondary + btn_icon slots and kind icons). Null when the page has none: the buttons are text buttons (1.16).
 */
export interface RevealButtonTemplates {
    view: HTMLButtonElement;
    hide: HTMLButtonElement;
    copy: HTMLButtonElement;
}

/**
 * Read the icon button templates of the reveal element (null unless all three exist).
 */
export function readRevealTemplates(root: ParentNode): RevealButtonTemplates | null {
    const button = (kind: string): HTMLButtonElement | null => {
        const template = root.querySelector(`template[data-vault-reveal-template="${kind}"]`) as HTMLTemplateElement | null;
        const element = template?.content.firstElementChild;

        return element instanceof HTMLButtonElement ? element : null;
    };
    const view = button('view');
    const hide = button('hide');
    const copy = button('copy');

    return view && hide && copy ? { view, hide, copy } : null;
}

/**
 * Give a button its label: the text (text buttons) or the accessible name, tooltip and visually hidden text (icon
 * buttons, whose glyph is replaced by the one of `glyph` when given).
 */
export function setButtonLabel(button: HTMLButtonElement, label: string, glyph: HTMLButtonElement | null = null): void {
    if (button.dataset.vaultIconButton !== '1') {
        button.textContent = label;

        return;
    }
    if (glyph) {
        button.replaceChildren(...Array.from(glyph.cloneNode(true).childNodes));
    }
    button.setAttribute('aria-label', label);
    button.title = label;
    const hidden = button.querySelector('.visually-hidden');
    if (hidden) {
        hidden.textContent = label;
    }
}

/** A Show / Copy button: a clone of the page's icon button, else a 1.16 text button. */
function createButton(root: Document, template: HTMLButtonElement | undefined, label: string): HTMLButtonElement {
    if (template) {
        const button = template.cloneNode(true) as HTMLButtonElement;
        button.dataset.vaultIconButton = '1';
        setButtonLabel(button, label);

        return button;
    }
    const button = root.createElement('button');
    button.type = 'button';
    button.className = 'vault-reveal-button';
    button.textContent = label;

    return button;
}

/**
 * 1.18.0 (A3): the PasswordStrength requirements of a password field are shown only while it holds a value (typed or
 * revealed); with an empty input (a stored password that is not on the page) they would all read as unmet.
 */
export function syncStrengthFeedback(input: HTMLInputElement | HTMLTextAreaElement): void {
    const feedback = input.closest('[data-password-strength-field]')?.querySelector('[data-password-strength-feedback]') as HTMLElement | null | undefined;
    if (feedback) {
        feedback.hidden = input.value === '';
    }
}

/**
 * Re-evaluate a field whose value was set by script: PasswordStrength listens to `input` (the value stays in the page),
 * then sync the requirements' visibility.
 */
function notifyValueChange(input: SecretInput): void {
    input.dispatchEvent(new Event('input', { bubbles: true }));
    syncStrengthFeedback(input);
}

/**
 * Bind the strength feedback visibility of every PasswordStrength field of the vault item form.
 */
export function bindStrengthFeedback(root: Document = document): void {
    root.querySelectorAll('.vault-form [data-password-strength-field] input').forEach((element) => {
        const input = element as HTMLInputElement;
        if (input.type === 'hidden') {
            return;
        }
        syncStrengthFeedback(input);
        input.addEventListener('input', () => syncStrengthFeedback(input));
    });
}

/**
 * Where the Show / Copy row goes: after the PasswordStrength input row or the PasswordToggle group around the input, so
 * the buttons never squeeze the input (1.18.0); else right after the input.
 */
function actionsAnchor(input: SecretInput): Element {
    return input.closest('.password-strength-input-row') ?? input.closest('.form-password-toggle') ?? input;
}

/** Put the input back to its masked, empty state. */
function conceal(input: SecretInput, button: HTMLButtonElement, config: RevealConfig, templates: RevealButtonTemplates | null = null): void {
    input.value = '';
    if (input instanceof HTMLInputElement && input.dataset.vaultRevealType) {
        input.type = input.dataset.vaultRevealType;
    }
    setButtonLabel(button, config.showLabel, templates?.view ?? null);
    button.setAttribute('aria-pressed', 'false');
    notifyValueChange(input);
}

/**
 * Add a Show / Hide button and a Copy button for every secret input of the page (1.18.0: icon buttons in a row under
 * the input when the page provides the templates).
 *
 * @returns the bound inputs
 */
export function bindRevealButtons(root: Document = document): SecretInput[] {
    const config = readRevealConfig(root);
    if (!config) {
        return [];
    }

    const extras = readRevealExtras(root);
    const templates = readRevealTemplates(root);
    const timers = new Map<SecretInput, ReturnType<typeof setTimeout>>();
    const inputs = Array.from(root.querySelectorAll('[data-vault-secret-field]')) as SecretInput[];
    inputs.forEach((input) => {
        const field = input.dataset.vaultSecretField as string;
        const button = createButton(root, templates?.view, config.showLabel);
        button.dataset.vaultRevealButton = field;
        button.setAttribute('aria-pressed', 'false');
        if (input instanceof HTMLInputElement) {
            input.dataset.vaultRevealType = input.type;
        }

        button.addEventListener('click', async () => {
            if (button.getAttribute('aria-pressed') === 'true') {
                conceal(input, button, config, templates);

                return;
            }

            try {
                const value = await fetchSecretField(config, field);
                input.value = value;
                if (input instanceof HTMLInputElement && input.type === 'password') {
                    input.type = 'text';
                }
                setButtonLabel(button, config.hideLabel, templates?.hide ?? null);
                button.setAttribute('aria-pressed', 'true');
                notifyValueChange(input);
                if (extras.autoHideSeconds > 0) {
                    clearTimeout(timers.get(input));
                    timers.set(input, setTimeout(() => conceal(input, button, config, templates), extras.autoHideSeconds * 1000));
                }
            } catch {
                setButtonLabel(button, config.errorLabel);
            }
        });

        // 1.16.0: Copy writes to the clipboard without putting the value in the page.
        const copy = createButton(root, templates?.copy, extras.copyLabel);
        copy.dataset.vaultCopyButton = field;
        copy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(await fetchSecretField(config, field, 'copy'));
                setButtonLabel(copy, extras.copiedLabel);
            } catch {
                setButtonLabel(copy, config.errorLabel);
            }
        });

        if (templates) {
            const row = root.createElement('div');
            row.className = 'vault-secret-actions';
            row.append(button, copy);
            actionsAnchor(input).insertAdjacentElement('afterend', row);
        } else {
            input.insertAdjacentElement('afterend', button);
            button.insertAdjacentElement('afterend', copy);
        }
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
                conceal(input, button as HTMLButtonElement, config, templates);
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
