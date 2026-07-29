/**
 * Client-side password generator API call helpers for Vault manage UI.
 */

/** Request body for the password generator endpoint. */
export interface PasswordGeneratorRequest {
    mode: string;
    length: number;
    useLower: boolean;
    useUpper: boolean;
    useDigits: boolean;
    useSymbols: boolean;
}

/** Response from the password generator endpoint. */
export interface PasswordGeneratorResponse {
    password: string;
    strength: string;
}

/**
 * POST to the vault password generator endpoint.
 *
 * @param url - Generator endpoint URL
 * @param options - Generator options
 * @param csrfToken - Optional CSRF token (header + body `_token`)
 */
export async function fetchGeneratedPassword(
    url: string,
    options: PasswordGeneratorRequest,
    csrfToken?: string,
): Promise<PasswordGeneratorResponse> {
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
    };

    if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
    }

    const body: PasswordGeneratorRequest & { _token?: string } = { ...options };
    if (csrfToken) {
        body._token = csrfToken;
    }

    const response = await fetch(url, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error('Password generation failed.');
    }

    return response.json() as Promise<PasswordGeneratorResponse>;
}

/**
 * Read generator option widgets from the manage UI DOM.
 *
 * @param doc - Document root to query
 */
export function readGeneratorOptionsFromDom(doc: Document): PasswordGeneratorRequest {
    const mode = (doc.querySelector('input[name="vault-gen-mode"]:checked') as HTMLInputElement | null)?.value ?? 'characters';
    const length = Number((doc.querySelector('[data-vault-password-length]') as HTMLInputElement | null)?.value ?? 20);
    const useUpper = (doc.querySelector('[data-vault-password-upper]') as HTMLInputElement | null)?.checked ?? true;
    const useDigits = (doc.querySelector('[data-vault-password-digits]') as HTMLInputElement | null)?.checked ?? true;
    const useSymbols = (doc.querySelector('[data-vault-password-symbols]') as HTMLInputElement | null)?.checked ?? true;

    return { mode, length, useLower: true, useUpper, useDigits, useSymbols };
}
