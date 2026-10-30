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

const WORKSPACE_KEY = 'acl:workspace';

function csrfToken(): string | null {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
    }

    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null;
}

function storedWorkspace(): string | null {
    try {
        return sessionStorage.getItem(WORKSPACE_KEY);
    } catch {
        return null;
    }
}

/**
 * The data layer both frontends share: the management controllers behind `{path}/acl-api`.
 * A 422 carries its field errors; any other failure its message.
 */
export function createApi(path: string): Api {
    const base = `${path}/acl-api`;
    let workspace = storedWorkspace();

    async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const token = csrfToken();

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }

        if (workspace) {
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

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new ApiError(response.status, data.message ?? response.statusText, data.errors ?? {});
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

            try {
                value === null ? sessionStorage.removeItem(WORKSPACE_KEY) : sessionStorage.setItem(WORKSPACE_KEY, value);
            } catch {
                // The selection then lasts until the page is reloaded.
            }
        },
    };
}
