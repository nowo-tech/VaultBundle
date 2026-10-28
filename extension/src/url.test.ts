import { describe, expect, it } from 'vitest';
import { isSecureBaseUrl, normalizeBaseUrl } from './url.js';

describe('normalizeBaseUrl', () => {
    it('trims whitespace and trailing slashes', () => {
        expect(normalizeBaseUrl('  http://localhost:8023///  ')).toBe('http://localhost:8023');
    });

    it('returns empty string for blank input', () => {
        expect(normalizeBaseUrl('   ')).toBe('');
    });
});

describe('isSecureBaseUrl', () => {
    it('accepts https and localhost http only', () => {
        expect(isSecureBaseUrl('https://vault.example.com')).toBe(true);
        expect(isSecureBaseUrl('http://localhost:8023')).toBe(true);
        expect(isSecureBaseUrl('http://127.0.0.1')).toBe(true);
        expect(isSecureBaseUrl('http://vault.example.com')).toBe(false);
        expect(isSecureBaseUrl('ftp://vault.example.com')).toBe(false);
        expect(isSecureBaseUrl('')).toBe(false);
    });
});
