import type { Errors } from './types';

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors: Errors = {},
    ) {
        super(message);
    }
}

export interface Api {
    get<T>(url: string): Promise<T>;
    post<T>(url: string, body?: unknown): Promise<T>;
    put<T>(url: string, body?: unknown): Promise<T>;
    delete(url: string, query?: Record<string, string>): Promise<void>;
    setWorkspace(workspace: string | null): void;
}

export /**
 * The CSRF header the middleware accepts: the encrypted cookie goes in X-XSRF-TOKEN,
 * the plain meta token in X-CSRF-TOKEN.
 */
function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    }

    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

/**
 * The data layer both frontends share: the management controllers behind `{path}/acl-api`.
 * A 422 carries its field errors; any other failure its message. The workspace the session acts
 * in is sent with every call; an empty one asks the server to forget the selection.
 */
export function createApi(path: string, initialWorkspace: string | null = null): Api {
    const base = `${path}/acl-api`;
    let workspace = initialWorkspace;

    async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        };

        if (workspace !== null) {
            headers['X-Workspace'] = workspace;
        }

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(`${base}/${url}`, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        if (response.status === 204) {
            return undefined as T;
        }

        const data = await response.json().catch(() => null);

        if (!response.ok) {
            throw new ApiError(response.status, data?.message ?? response.statusText, data?.errors ?? {});
        }

        if (data === null) {
            throw new ApiError(response.status, 'The server did not answer with JSON.');
        }

        return data as T;
    }

    return {
        get: (url) => request('GET', url),
        post: (url, body) => request('POST', url, body ?? {}),
        put: (url, body) => request('PUT', url, body ?? {}),
        delete: (url, query) => request('DELETE', query ? `${url}?${new URLSearchParams(query)}` : url),
        setWorkspace(value) {
            workspace = value;
        },
    };
}
