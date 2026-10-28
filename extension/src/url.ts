export function normalizeBaseUrl(url: string): string {
    return String(url || '').trim().replace(/\/+$/, '');
}

/** The vault URL must be https (plain http only for localhost development): the API carries the token and passwords. */
export function isSecureBaseUrl(url: string): boolean {
    try {
        const parsed = new URL(url);

        return parsed.protocol === 'https:'
            || (parsed.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(parsed.hostname));
    } catch {
        return false;
    }
}
