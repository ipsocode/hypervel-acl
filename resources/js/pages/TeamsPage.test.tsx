import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it, vi } from 'vitest';

import type { Api } from '../api';
import { ApiError } from '../api';
import type { Boot } from '../types';
import { AclContext } from '../ui';
import TeamsPage from './TeamsPage';

afterEach(cleanup);

const boot: Boot = { path: '/acl-admin', workspaces: false, workspaceModel: false, workspaceParent: null, tenant: false, guards: ['web'] };

it('updates the team a failed save created instead of creating a second', async () => {
    const team = { id: 5, name: 'Ops', tenant: null, inherit_id: null, roles: [] };
    const roles = vi.fn().mockRejectedValueOnce(new ApiError(422, 'Invalid.', { roles: ['The role does not exist.'] })).mockResolvedValue({ data: team });
    const api: Api = {
        get: vi.fn(async (url: string) => ({ data: url === 'teams' ? [] : url === 'roles' ? [] : [] })) as Api['get'],
        post: vi.fn(async () => ({ data: team })) as Api['post'],
        put: vi.fn(async (url: string, body?: unknown) => (url.endsWith('/roles') ? roles(url, body) : { data: team })) as Api['put'],
        delete: vi.fn(),
        setWorkspace: vi.fn(),
    };

    render(
        <AclContext.Provider value={{ api, boot }}>
            <TeamsPage />
        </AclContext.Provider>,
    );

    await userEvent.click(await screen.findByRole('button', { name: 'New team' }));
    await userEvent.type(await screen.findByLabelText('Name'), 'Ops');
    await userEvent.click(screen.getByRole('button', { name: 'Save team' }));
    expect(await screen.findByText('The role does not exist.')).toBeTruthy();

    await userEvent.click(screen.getByRole('button', { name: 'Save team' }));

    await waitFor(() => expect(api.put).toHaveBeenCalledWith('teams/5', { name: 'Ops', inherit_id: null }));
    expect(api.post).toHaveBeenCalledTimes(1);
});
