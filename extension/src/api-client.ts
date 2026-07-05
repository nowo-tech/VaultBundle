import type { ApiErrorPayload } from './types.js';

export interface JsonRequestOptions {
    method?: string;
    headers?: Record<string, string>;
    body?: string;
    token?: string;
}

export async function requestJson<T>(
    baseUrl: string,
    path: string,
    options: JsonRequestOptions = {},
): Promise<T> {
    if (!baseUrl) {
        throw new Error('Configure the vault URL in extension options.');
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        ...(options.headers ?? {}),
    };

    if (options.token) {
        headers.Authorization = `Bearer ${options.token}`;
    }

    const response = await fetch(`${baseUrl}${path}`, {
        method: options.method,
        headers,
        body: options.body,
    });

    let payload: ApiErrorPayload | T | null = null;
    const text = await response.text();

    if (text) {
        try {
            payload = JSON.parse(text) as T | ApiErrorPayload;
        } catch {
            payload = { raw: text };
        }
    }

    if (!response.ok) {
        const errorPayload = payload as ApiErrorPayload | null;
        const message = errorPayload?.error || `Request failed (${response.status}).`;
        throw new Error(message);
    }

    return payload as T;
}
