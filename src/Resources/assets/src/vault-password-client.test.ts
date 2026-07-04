import { describe, expect, it, vi } from 'vitest';
import { fetchGeneratedPassword, readGeneratorOptionsFromDom } from './vault-password-client';

describe('vault-password-client', () => {
    it('requests generated password from API', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({ password: 'abc123XYZ!', strength: 'strong' }),
        })));

        const result = await fetchGeneratedPassword('/tools/vault/password/generate', {
            mode: 'characters',
            length: 20,
            useLower: true,
            useUpper: true,
            useDigits: true,
            useSymbols: true,
        });

        expect(result.password).toBe('abc123XYZ!');
        expect(fetch).toHaveBeenCalled();
    });

    it('throws when API fails', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })));

        await expect(fetchGeneratedPassword('/x', {
            mode: 'characters', length: 12, useLower: true, useUpper: true, useDigits: true, useSymbols: true,
        })).rejects.toThrow('Password generation failed.');
    });

    it('reads words mode from DOM', () => {
        document.body.innerHTML = `
            <input name="vault-gen-mode" type="radio" value="characters" checked>
            <input data-vault-password-length value="24">
            <input data-vault-password-upper checked>
            <input data-vault-password-digits checked>
            <input data-vault-password-symbols>
        `;

        expect(readGeneratorOptionsFromDom(document)).toEqual({
            mode: 'characters',
            length: 24,
            useLower: true,
            useUpper: true,
            useDigits: true,
            useSymbols: false,
        });
    });

    it('uses defaults when DOM controls are missing', () => {
        document.body.innerHTML = '';
        expect(readGeneratorOptionsFromDom(document)).toEqual({
            mode: 'characters',
            length: 20,
            useLower: true,
            useUpper: true,
            useDigits: true,
            useSymbols: true,
        });
    });

    it('reads words mode when selected', () => {
        document.body.innerHTML = `
            <input name="vault-gen-mode" type="radio" value="words" checked>
        `;
        expect(readGeneratorOptionsFromDom(document).mode).toBe('words');
    });
});
