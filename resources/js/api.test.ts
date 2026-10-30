import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { ApiError, createApi } from './api';

const fetchMock = vi.fn();

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    sessionStorage.clear();
    document.cookie = 'XSRF-TOKEN=abc%3D; path=/';
});

afterEach(() => {
    fetchMock.mockReset();
    vi.unstubAllGlobals();
});

const reply = (status: number, body: unknown) => new Response(body === null ? null : JSON.stringify(body), { status });

it('calls the internal API beneath the UI path with the session headers', async () => {
    fetchMock.mockResolvedValue(reply(200, { data: [] }));

    await createApi('/acl-admin').get('roles');

    const [url, init] = fetchMock.mock.calls[0];

    expect(url).toBe('/acl-admin/acl-api/roles');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers['X-XSRF-TOKEN']).toBe('abc=');
    expect(init.headers.Accept).toBe('application/json');
});

it('sends a body as JSON and the acting workspace as a header', async () => {
    fetchMock.mockResolvedValue(reply(201, { data: {} }));
    const api = createApi('/acl-admin');

    api.setWorkspace('7');
    await api.post('roles', { name: 'auditor' });

    const init = fetchMock.mock.calls[0][1];

    expect(init.body).toBe('{"name":"auditor"}');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(init.headers['X-Workspace']).toBe('7');
    expect(sessionStorage.getItem('acl:workspace')).toBe('7');

    api.setWorkspace(null);
    expect(sessionStorage.getItem('acl:workspace')).toBeNull();
});

it('returns nothing for an empty response and puts a delete query on the URL', async () => {
    fetchMock.mockResolvedValue(reply(204, null));

    await expect(createApi('/acl-admin').delete('teams/1/users/2', { workspace: '3' })).resolves.toBeUndefined();
    expect(fetchMock.mock.calls[0][0]).toBe('/acl-admin/acl-api/teams/1/users/2?workspace=3');
});

it('maps a 422 to its field errors and any other failure to its message', async () => {
    const api = createApi('/acl-admin');

    fetchMock.mockResolvedValueOnce(reply(422, { message: 'Invalid.', errors: { name: ['Taken.'] } }));
    await expect(api.post('roles', {})).rejects.toMatchObject({ status: 422, errors: { name: ['Taken.'] } });

    fetchMock.mockResolvedValueOnce(reply(403, { message: 'Forbidden.' }));
    const failure = await api.get('roles').catch((error: unknown) => error);

    expect(failure).toBeInstanceOf(ApiError);
    expect(failure).toMatchObject({ status: 403, message: 'Forbidden.', errors: {} });
});

it('sends the plain meta token as X-CSRF-TOKEN when there is no cookie', async () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    document.head.innerHTML = '<meta name="csrf-token" content="plain">';
    fetchMock.mockResolvedValue(reply(200, { data: [] }));

    await createApi('/acl-admin').get('roles');

    const headers = fetchMock.mock.calls[0][1].headers;

    expect(headers['X-CSRF-TOKEN']).toBe('plain');
    expect(headers['X-XSRF-TOKEN']).toBeUndefined();
    document.head.innerHTML = '';
});
