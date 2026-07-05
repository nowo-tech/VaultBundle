import { describe, expect, it } from 'vitest';
import { normalizeBaseUrl } from './url.js';

describe('normalizeBaseUrl', () => {
    it('trims whitespace and trailing slashes', () => {
        expect(normalizeBaseUrl('  http://localhost:8023///  ')).toBe('http://localhost:8023');
    });

    it('returns empty string for blank input', () => {
        expect(normalizeBaseUrl('   ')).toBe('');
    });
});
