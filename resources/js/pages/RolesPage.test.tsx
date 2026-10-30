import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';

import type { Api } from '../api';
import { ApiError } from '../api';
import type { Boot } from '../types';
import { AclContext } from '../ui';
import RolesPage from './RolesPage';

afterEach(cleanup);

const boot: Boot = { path: '/acl-admin', workspaces: false, workspaceModel: false, workspaceParent: null, tenant: false, guards: ['web'], workspace: null };

function mount(overrides: Partial<Api> = {}) {
    const api: Api = {
        get: vi.fn(async (url: string) => {
            if (url === 'roles') return { data: [{ id: 1, name: 'manager', guard_name: 'web' }] };
            if (url === 'permissions') return { data: [{ id: 1, name: 'articles.view', guard_name: 'web' }, { id: 2, name: 'articles.edit', guard_name: 'web' }] };

            return { data: { id: 1, name: 'manager', guard_name: 'web', permissions: ['articles.view'], denied: ['articles.edit'] } };
        }) as Api['get'],
        post: vi.fn(),
        put: vi.fn(async () => ({ data: {} })) as Api['put'],
        delete: vi.fn(),
        setWorkspace: vi.fn(),
        ...overrides,
    };

    render(
        <AclContext.Provider value={{ api, boot }}>
            <RolesPage />
        </AclContext.Provider>,
    );

    return api;
}

it('loads a role with its allowed and denied permissions and saves them back', async () => {
    const api = mount();

    await userEvent.click(await screen.findByRole('button', { name: /manager/ }));
    const view = await screen.findByLabelText('Effect of articles.view');

    expect(view).toHaveProperty('value', 'allow');
    expect(screen.getByLabelText('Effect of articles.edit')).toHaveProperty('value', 'deny');

    await userEvent.selectOptions(view, 'none');
    await userEvent.click(screen.getByRole('button', { name: 'Save role' }));

    await waitFor(() => expect(api.put).toHaveBeenCalledWith('roles/1/permissions', { allowed: [], denied: ['articles.edit'] }));
    expect(await screen.findByText('Saved.')).toBeTruthy();
});

it('shows the field errors of a refused role', async () => {
    mount({ post: vi.fn().mockRejectedValue(new ApiError(422, 'Invalid.', { name: ['The name has already been taken.'] })) as Api['post'] });

    await userEvent.type(await screen.findByLabelText('Name'), 'manager');
    await userEvent.click(screen.getByRole('button', { name: 'Create role' }));

    expect(await screen.findByText('The name has already been taken.')).toBeTruthy();
});
