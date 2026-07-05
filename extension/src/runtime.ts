/** Cross-browser WebExtension runtime (Chrome, Brave, Firefox). */
declare const browser: typeof chrome | undefined;

export const ext: typeof chrome = typeof globalThis.browser !== 'undefined'
    ? (globalThis.browser as typeof chrome)
    : globalThis.chrome;
