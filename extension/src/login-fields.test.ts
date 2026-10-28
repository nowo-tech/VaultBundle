import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    debounce,
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
            { username: 'demo', password: 'secret' },
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

    function passwordField(): HTMLInputElement {
        const password = document.createElement('input');
        password.type = 'password';
        document.body.appendChild(password);
        Object.defineProperty(password, 'getBoundingClientRect', {
            value: () => ({ bottom: 100, left: 20, top: 70, right: 200, width: 180, height: 30, x: 20, y: 70, toJSON: () => ({}) }),
        });

        return password;
    }

    it('renders the panel in a closed shadow root and removes it', () => {
        const password = passwordField();
        const before = document.body.children.length;

        const root = renderAutofillPanel(
            document,
            [{ id: 'i1', title: 'App', username: 'demo' }],
            { usernameField: null, passwordField: password },
            () => undefined,
        );

        expect(root).not.toBeNull();
        expect(document.body.children.length).toBe(before + 1);
        const host = document.body.lastElementChild as HTMLElement;
        expect(host.shadowRoot).toBeNull();
        expect(document.querySelector('button')).toBeNull();
        expect(root?.querySelector('button')?.textContent).toBe('App (demo)');
        expect(root?.querySelector('style')?.textContent).toContain('.nowo-vault-panel');

        removeAutofillPanel(document);
        expect(document.body.children.length).toBe(before);
        removeAutofillPanel(document);
    });

    it('ignores clicks that are not trusted user gestures', () => {
        const password = passwordField();
        const onSelect = vi.fn();
        const root = renderAutofillPanel(
            document,
            [{ id: 'i1', title: 'App', username: 'demo' }],
            { usernameField: null, passwordField: password },
            onSelect,
        );

        root?.querySelector('button')?.click();
        expect(onSelect).not.toHaveBeenCalled();
    });

    it('selects a login on a trusted click and closes the panel', () => {
        const password = passwordField();
        const onSelect = vi.fn();
        const root = renderAutofillPanel(
            document,
            [{ id: 'i1', title: 'App', username: 'demo' }, { id: 'i2', title: 'Other', username: 'x' }],
            { usernameField: null, passwordField: password },
            onSelect,
            () => true,
        );
        const host = document.body.lastElementChild;

        root?.querySelectorAll('button')[1]?.click();
        expect(onSelect).toHaveBeenCalledWith({ id: 'i2', title: 'Other', username: 'x' });
        expect(host?.isConnected).toBe(false);
    });

    it('renders nothing without logins', () => {
        expect(renderAutofillPanel(document, [], { usernameField: null, passwordField: passwordField() }, () => undefined)).toBeNull();
    });

    it('debounces bursts of calls', () => {
        vi.useFakeTimers();
        const callback = vi.fn();
        const run = debounce(callback, 300);
        run();
        run();
        run();
        expect(callback).not.toHaveBeenCalled();
        vi.advanceTimersByTime(300);
        expect(callback).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });
});
