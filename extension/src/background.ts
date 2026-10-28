import { getConfig, isSessionValid, loginsForDomain } from './api.js';
import { pageOrigin, summarizeLogins } from './page-origin.js';
import { ext } from './runtime.js';
import type { VaultLoginMatch } from './types.js';

/** Last logins fetched per tab (with passwords), released to the content script one at a time after a trusted click. */
const tabLogins = new Map<number, { host: string; logins: VaultLoginMatch[] }>();

ext.runtime.onMessage.addListener((message, sender, sendResponse) => {
    // Only our own content scripts / pages; the page URL comes from the tab, not from the message (SEC-004 H1).
    if (sender.id !== ext.runtime.id) {
        return false;
    }

    if (message?.type === 'vault:getLogins') {
        handleGetLogins(sender)
            .then((logins) => sendResponse({ ok: true, logins }))
            .catch((error: Error) => sendResponse({ ok: false, error: error.message }));

        return true;
    }

    if (message?.type === 'vault:fill') {
        const page = pageOrigin(sender.tab?.url ?? sender.url);
        const entry = sender.tab?.id !== undefined ? tabLogins.get(sender.tab.id) : undefined;
        const login = page && entry && entry.host === page.host
            ? entry.logins.find((candidate) => candidate.id === String(message.id ?? ''))
            : undefined;
        sendResponse(login ? { ok: true, username: login.username, password: login.password } : { ok: false });

        return false;
    }

    if (message?.type === 'vault:sessionStatus') {
        getConfig()
            .then((config) => sendResponse({
                ok: true,
                signedIn: isSessionValid(config),
                baseUrl: config.baseUrl,
            }))
            .catch((error: Error) => sendResponse({ ok: false, error: error.message }));

        return true;
    }

    return false;
});

ext.tabs?.onRemoved?.addListener((tabId: number) => {
    tabLogins.delete(tabId);
});

async function handleGetLogins(sender: chrome.runtime.MessageSender) {
    const page = pageOrigin(sender.tab?.url ?? sender.url);
    if (!page || sender.tab?.id === undefined) {
        throw new Error('Unsupported page.');
    }

    const config = await getConfig();
    if (!isSessionValid(config)) {
        throw new Error('Not signed in.');
    }

    const payload = await loginsForDomain(page.host, page.scheme);
    const logins = payload.logins ?? [];
    tabLogins.set(sender.tab.id, { host: page.host, logins });

    return summarizeLogins(logins);
}
