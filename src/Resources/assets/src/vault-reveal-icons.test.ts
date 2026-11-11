import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { bindRevealButtons, bindStrengthFeedback, readRevealTemplates, setButtonLabel, syncStrengthFeedback } from './vault-reveal';

const fetchMock = vi.fn();
// Generated per run: placeholder values only (no real secret).
const TOKEN = crypto.randomUUID(); // ggignore: random per-run test value, not a credential
const VALUE = `placeholder-${crypto.randomUUID()}`;
const URL = '/tools/vault/items/00000000-0000-4000-8000-000000000000/reveal/FIELD';

/** The icon button templates printed by item_form.html.twig (1.18.0, A2). */
function template(kind: string, label: string): string {
    return `<template data-vault-reveal-template="${kind}"><button type="button" class="nowo-ui-btn nowo-ui-btn--secondary nowo-ui-btn--icon nowo-ui-action--${kind} vault-reveal-button" title="${label}" aria-label="${label}"><i class="icon-${kind}" aria-hidden="true"></i><span class="visually-hidden">${label}</span></button></template>`;
}

const TEMPLATES = template('view', 'Show') + template('hide', 'Hide') + template('copy', 'Copy');

const PAGE = `
    <div hidden data-vault-reveal data-vault-reveal-url="${URL}" data-vault-reveal-csrf="${TOKEN}"
         data-vault-reveal-show="Show" data-vault-reveal-hide="Hide" data-vault-reveal-error="Error"
         data-vault-reveal-copy="Copy" data-vault-reveal-copied="Copied">${TEMPLATES}</div>
    <form class="vault-form">
        <nowo-password-strength data-password-strength-field>
            <div class="password-strength-input-row">
                <div class="password-strength-input-grow">
                    <div class="form-password-toggle"><input type="password" value="" data-vault-secret-field="password"></div>
                </div>
                <button type="button" data-password-strength-generate>Generate</button>
            </div>
            <ul data-password-strength-feedback><li>12 characters</li></ul>
        </nowo-password-strength>
        <input type="text" value="" data-vault-secret-field="cardNumber">
        <div class="form-password-toggle" id="cvv-group"><input type="password" value="" data-vault-secret-field="cvv"></div>
    </form>
`;

async function flush(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

describe('vault-reveal icon buttons (1.18.0)', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('reads the templates only when all three exist', () => {
        document.body.innerHTML = template('view', 'Show') + template('hide', 'Hide');
        expect(readRevealTemplates(document)).toBeNull();
        document.body.innerHTML = TEMPLATES + '<template data-vault-reveal-template="copy-other">x</template>';
        expect(readRevealTemplates(document)?.copy.getAttribute('aria-label')).toBe('Copy');
        document.body.innerHTML = '<template data-vault-reveal-template="view">text</template>' + template('hide', 'Hide') + template('copy', 'Copy');
        expect(readRevealTemplates(document)).toBeNull();
    });

    it('labels text buttons by their text and icon buttons by aria-label, title and hidden text', () => {
        const text = document.createElement('button');
        setButtonLabel(text, 'Show');
        expect(text.textContent).toBe('Show');

        document.body.innerHTML = TEMPLATES;
        const templates = readRevealTemplates(document);
        const icon = templates?.view.cloneNode(true) as HTMLButtonElement;
        icon.dataset.vaultIconButton = '1';
        setButtonLabel(icon, 'Hide', templates?.hide ?? null);
        expect(icon.getAttribute('aria-label')).toBe('Hide');
        expect(icon.title).toBe('Hide');
        expect(icon.querySelector('.visually-hidden')?.textContent).toBe('Hide');
        expect(icon.querySelector('i')?.className).toBe('icon-hide');

        const bare = document.createElement('button');
        bare.dataset.vaultIconButton = '1';
        setButtonLabel(bare, 'Copied');
        expect(bare.getAttribute('aria-label')).toBe('Copied');
    });

    it('puts Show and Copy icon buttons in a row under the input row and swaps the eye icon', async () => {
        document.body.innerHTML = PAGE;
        fetchMock.mockResolvedValue({ ok: true, json: async () => ({ value: VALUE, encoding: 'plain' }) } as unknown as Response);
        const inputs = bindRevealButtons(document);
        expect(inputs).toHaveLength(3);

        const row = document.querySelector('.password-strength-input-row')?.nextElementSibling as HTMLElement;
        expect(row.className).toBe('vault-secret-actions');
        const show = row.querySelector('[data-vault-reveal-button="password"]') as HTMLButtonElement;
        const copy = row.querySelector('[data-vault-copy-button="password"]') as HTMLButtonElement;
        expect(show.classList.contains('nowo-ui-btn--icon')).toBe(true);
        expect(show.getAttribute('aria-label')).toBe('Show');
        expect(copy.getAttribute('aria-label')).toBe('Copy');
        // A plain secret input gets its row right after it.
        expect((document.querySelector('[data-vault-secret-field="cardNumber"]')?.nextElementSibling as HTMLElement).className).toBe('vault-secret-actions');
        // A PasswordToggle group without the strength row gets the row after the group.
        expect((document.getElementById('cvv-group')?.nextElementSibling as HTMLElement).className).toBe('vault-secret-actions');

        const input = document.querySelector('[data-vault-secret-field="password"]') as HTMLInputElement;
        const feedback = document.querySelector('[data-password-strength-feedback]') as HTMLElement;
        const events: string[] = [];
        input.addEventListener('input', () => events.push(input.value));

        show.click();
        await flush();
        expect(input.value).toBe(VALUE);
        expect(show.getAttribute('aria-label')).toBe('Hide');
        expect(show.querySelector('i')?.className).toBe('icon-hide');
        // The strength meter evaluates the revealed value in the page (input event) and shows its rules.
        expect(events).toEqual([VALUE]);
        expect(feedback.hidden).toBe(false);

        show.click();
        expect(input.value).toBe('');
        expect(show.getAttribute('aria-label')).toBe('Show');
        expect(show.querySelector('i')?.className).toBe('icon-view');
        expect(feedback.hidden).toBe(true);

        fetchMock.mockRejectedValueOnce(new Error('offline'));
        show.click();
        await flush();
        expect(show.getAttribute('aria-label')).toBe('Error');
    });

    it('labels the copy icon button with the result', async () => {
        document.body.innerHTML = PAGE;
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        fetchMock.mockResolvedValue({ ok: true, json: async () => ({ value: VALUE, encoding: 'plain' }) } as unknown as Response);
        bindRevealButtons(document);
        const copy = document.querySelector('[data-vault-copy-button="password"]') as HTMLButtonElement;

        copy.click();
        await flush();
        expect(writeText).toHaveBeenCalledWith(VALUE);
        expect(copy.getAttribute('aria-label')).toBe('Copied');

        writeText.mockRejectedValueOnce(new Error('denied'));
        copy.click();
        await flush();
        expect(copy.getAttribute('aria-label')).toBe('Error');
    });

    it('shows the strength rules only while the password field holds a value', () => {
        document.body.innerHTML = PAGE + '<form class="vault-form"><div data-password-strength-field><input type="hidden" value=""><input type="password" id="new" value=""><ul data-password-strength-feedback></ul></div></form>';
        bindStrengthFeedback(document);
        const feedback = document.querySelectorAll('[data-password-strength-feedback]');
        expect((feedback[0] as HTMLElement).hidden).toBe(true);
        expect((feedback[1] as HTMLElement).hidden).toBe(true);

        const typed = document.getElementById('new') as HTMLInputElement;
        typed.value = 'typed';
        typed.dispatchEvent(new Event('input', { bubbles: true }));
        expect((feedback[1] as HTMLElement).hidden).toBe(false);

        // Without a strength field there is nothing to sync.
        const lone = document.createElement('input');
        syncStrengthFeedback(lone);
        expect(lone.value).toBe('');
    });
});
