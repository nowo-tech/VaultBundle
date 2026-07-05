import { describe, expect, it } from 'vitest';
import { isSessionValid } from './session.js';

describe('isSessionValid', () => {
    it('returns false without token', () => {
        expect(isSessionValid({ baseUrl: 'http://localhost', token: '', expiresAt: '' })).toBe(false);
    });

    it('returns true with token and no expiry', () => {
        expect(isSessionValid({ baseUrl: 'http://localhost', token: 'abc', expiresAt: '' })).toBe(true);
    });

    it('returns false when token is expired', () => {
        const expired = new Date(Date.now() - 60_000).toISOString();
        expect(isSessionValid({ baseUrl: 'http://localhost', token: 'abc', expiresAt: expired })).toBe(false);
    });

    it('returns true when expiry is in the future', () => {
        const future = new Date(Date.now() + 60_000).toISOString();
        expect(isSessionValid({ baseUrl: 'http://localhost', token: 'abc', expiresAt: future })).toBe(true);
    });
});
