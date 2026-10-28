import { describe, expect, it } from 'vitest';
import { pageOrigin, summarizeLogins } from './page-origin.js';

describe('pageOrigin', () => {
    it('returns scheme and host of http(s) pages', () => {
        expect(pageOrigin('https://login.example.com/path?x=1')).toEqual({ scheme: 'https', host: 'login.example.com' });
        expect(pageOrigin('http://example.com/')).toEqual({ scheme: 'http', host: 'example.com' });
    });

    it('rejects other pages and invalid URLs', () => {
        expect(pageOrigin(undefined)).toBeNull();
        expect(pageOrigin('')).toBeNull();
        expect(pageOrigin('chrome://settings')).toBeNull();
        expect(pageOrigin('file:///etc/passwd')).toBeNull();
        expect(pageOrigin('not a url')).toBeNull();
    });
});

describe('summarizeLogins', () => {
    it('drops the password', () => {
        expect(summarizeLogins([{ id: 'a', title: 'T', username: 'u', password: 'p' }])).toEqual([{ id: 'a', title: 'T', username: 'u' }]);
    });
});
