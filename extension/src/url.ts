export function normalizeBaseUrl(url: string): string {
    return String(url || '').trim().replace(/\/+$/, '');
}
