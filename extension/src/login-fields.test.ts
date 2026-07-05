import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AUTOFILL_PANEL_ID,
    fillCredentials,
    findLoginFields,
    isLikelyUsernameField,
    removeAutofillPanel,
    renderAutofillPanel,
    setFieldValue,
} from './login-fields.js';

describe('login-fields', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('detects likely username fields from hints', () => {
        const email = document.createElement('input');
        email.type = 'email';
        email.name = 'email';

        const other = document.createElement('input');
        other.type = 'text';
        other.name = 'reference';

        expect(isLikelyUsernameField(email)).toBe(true);
        expect(isLikelyUsernameField(other)).toBe(false);
    });

    it('finds username and password fields in a form', () => {
        document.body.innerHTML = `
            <form>
                <input type="email" id="email" name="email">
                <input type="password" id="password" name="password">
            </form>
        `;

        const email = document.getElementById('email') as HTMLInputElement;
        const password = document.getElementById('password') as HTMLInputElement;
        Object.defineProperty(email, 'offsetParent', { configurable: true, value: document.body });
        Object.defineProperty(password, 'offsetParent', { configurable: true, value: document.body });

        const fields = findLoginFields(document);
        expect(fields?.usernameField?.id).toBe('email');
        expect(fields?.passwordField.id).toBe('password');
    });

    it('returns null when no password field exists', () => {
        document.body.innerHTML = '<input type="text" name="search">';
        expect(findLoginFields(document)).toBeNull();
    });

    it('fills credentials and dispatches input events', () => {
        document.body.innerHTML = `
            <input id="username" type="text">
            <input id="password" type="password">
        `;

        const username = document.getElementById('username') as HTMLInputElement;
        const password = document.getElementById('password') as HTMLInputElement;
        const inputSpy = vi.spyOn(username, 'dispatchEvent');

        fillCredentials(
            { title: 'GitHub', username: 'demo', password: 'secret' },
            { usernameField: username, passwordField: password },
        );

        expect(username.value).toBe('demo');
        expect(password.value).toBe('secret');
        expect(inputSpy).toHaveBeenCalled();
    });

    it('sets field value with change events', () => {
        const input = document.createElement('input');
        document.body.appendChild(input);

        const changeSpy = vi.spyOn(input, 'dispatchEvent');
        setFieldValue(input, 'hello');

        expect(input.value).toBe('hello');
        expect(changeSpy).toHaveBeenCalled();
    });

    it('renders and removes autofill panel', () => {
        const password = document.createElement('input');
        password.type = 'password';
        document.body.appendChild(password);

        Object.defineProperty(password, 'getBoundingClientRect', {
            value: () => ({ bottom: 100, left: 20, top: 70, right: 200, width: 180, height: 30, x: 20, y: 70, toJSON: () => ({}) }),
        });

        renderAutofillPanel(
            document,
            [{ title: 'App', username: 'demo', password: 'secret' }],
            { usernameField: null, passwordField: password },
            () => undefined,
        );

        expect(document.getElementById(AUTOFILL_PANEL_ID)).not.toBeNull();

        removeAutofillPanel(document);
        expect(document.getElementById(AUTOFILL_PANEL_ID)).toBeNull();
    });
});
