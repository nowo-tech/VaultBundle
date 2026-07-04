/** Client-side password generator API call. */
export async function fetchGeneratedPassword(url: string, options: PasswordGeneratorRequest): Promise<PasswordGeneratorResponse> {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(options),
    });

    if (!response.ok) {
        throw new Error('Password generation failed.');
    }

    return response.json() as Promise<PasswordGeneratorResponse>;
}

export interface PasswordGeneratorRequest {
    mode: string;
    length: number;
    useLower: boolean;
    useUpper: boolean;
    useDigits: boolean;
    useSymbols: boolean;
}

export interface PasswordGeneratorResponse {
    password: string;
    strength: string;
}

export function readGeneratorOptionsFromDom(doc: Document): PasswordGeneratorRequest {
    const mode = (doc.querySelector('input[name="vault-gen-mode"]:checked') as HTMLInputElement | null)?.value ?? 'characters';
    const length = Number((doc.querySelector('[data-vault-password-length]') as HTMLInputElement | null)?.value ?? 20);
    const useUpper = (doc.querySelector('[data-vault-password-upper]') as HTMLInputElement | null)?.checked ?? true;
    const useDigits = (doc.querySelector('[data-vault-password-digits]') as HTMLInputElement | null)?.checked ?? true;
    const useSymbols = (doc.querySelector('[data-vault-password-symbols]') as HTMLInputElement | null)?.checked ?? true;

    return { mode, length, useLower: true, useUpper, useDigits, useSymbols };
}
