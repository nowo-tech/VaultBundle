import type { VaultLoginMatch, VaultLoginSummary } from './types.js';

/** Scheme + host of an http(s) page URL (from the sender tab), or null for any other page. */
export function pageOrigin(url: string | undefined): { scheme: 'http' | 'https'; host: string } | null {
    if (!url) {
        return null;
    }

    let parsed: URL;
    try {
        parsed = new URL(url);
    } catch {
        return null;
    }

    if ((parsed.protocol !== 'https:' && parsed.protocol !== 'http:') || parsed.hostname === '') {
        return null;
    }

    return { scheme: parsed.protocol === 'https:' ? 'https' : 'http', host: parsed.hostname };
}

/** Logins without their password, for the content script. */
export function summarizeLogins(logins: VaultLoginMatch[]): VaultLoginSummary[] {
    return logins.map(({ id, title, username }) => ({ id, title, username }));
}
