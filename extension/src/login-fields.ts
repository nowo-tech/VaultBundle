import type { LoginFields, VaultLoginMatch } from './types.js';

export function isLikelyUsernameField(input: HTMLInputElement): boolean {
    const hint = `${input.name} ${input.id} ${input.autocomplete} ${input.placeholder}`.toLowerCase();

    return /user|email|login|account|identifier/.test(hint);
}

export function findLoginFields(doc: Document): LoginFields | null {
    const passwordFields = Array.from(doc.querySelectorAll<HTMLInputElement>('input[type="password"]'));
    if (passwordFields.length === 0) {
        return null;
    }

    const passwordField = passwordFields[0];
    const form = passwordField.closest('form') ?? doc;
    const textFields = Array.from(
        form.querySelectorAll<HTMLInputElement>('input[type="text"], input[type="email"], input:not([type])'),
    ).filter((input) => input !== passwordField && !input.hidden && input.offsetParent !== null);

    const usernameField = textFields.find(isLikelyUsernameField) ?? textFields[0] ?? null;

    return { usernameField, passwordField };
}

export function setFieldValue(input: HTMLInputElement, value: string): void {
    input.focus();
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

export function fillCredentials(login: VaultLoginMatch, fields: LoginFields): void {
    if (fields.usernameField) {
        setFieldValue(fields.usernameField, login.username);
    }

    setFieldValue(fields.passwordField, login.password);
}

export const AUTOFILL_PANEL_ID = 'nowo-vault-autofill-panel';

export function removeAutofillPanel(doc: Document = document): void {
    doc.getElementById(AUTOFILL_PANEL_ID)?.remove();
}

export function renderAutofillPanel(
    doc: Document,
    logins: VaultLoginMatch[],
    fields: LoginFields,
    onSelect: (login: VaultLoginMatch) => void,
): void {
    removeAutofillPanel(doc);

    if (logins.length === 0) {
        return;
    }

    const panel = doc.createElement('div');
    panel.id = AUTOFILL_PANEL_ID;
    panel.className = 'nowo-vault-panel';

    const title = doc.createElement('strong');
    title.textContent = 'Nowo Vault';
    panel.appendChild(title);

    logins.slice(0, 5).forEach((login) => {
        const button = doc.createElement('button');
        button.type = 'button';
        button.textContent = `${login.title} (${login.username})`;
        button.addEventListener('click', () => {
            onSelect(login);
            removeAutofillPanel(doc);
        });
        panel.appendChild(button);
    });

    const rect = fields.passwordField.getBoundingClientRect();
    panel.style.top = `${window.scrollY + rect.bottom + 6}px`;
    panel.style.left = `${window.scrollX + rect.left}px`;

    doc.body.appendChild(panel);
}
