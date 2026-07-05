import { getConfig, isSessionValid, loginsForDomain } from './api.js';
import { ext } from './runtime.js';

ext.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type === 'vault:getLogins') {
        handleGetLogins(String(message.domain ?? ''))
            .then((logins) => sendResponse({ ok: true, logins }))
            .catch((error: Error) => sendResponse({ ok: false, error: error.message }));

        return true;
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

async function handleGetLogins(domain: string) {
    const config = await getConfig();
    if (!isSessionValid(config)) {
        throw new Error('Not signed in.');
    }

    const payload = await loginsForDomain(domain);

    return payload.logins ?? [];
}
