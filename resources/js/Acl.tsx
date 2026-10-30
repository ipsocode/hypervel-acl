import { type ReactNode, useMemo, useState } from 'react';

import { createApi } from './api';
import PermissionsPage from './pages/PermissionsPage';
import RolesPage from './pages/RolesPage';
import TeamsPage from './pages/TeamsPage';
import WorkspacesPage from './pages/WorkspacesPage';
import type { Boot } from './types';
import { AclContext } from './ui';

const views: Array<{ key: string; label: string; page: () => ReactNode; when?: (boot: Boot) => boolean }> = [
    { key: 'roles', label: 'Roles', page: () => <RolesPage /> },
    { key: 'permissions', label: 'Permissions', page: () => <PermissionsPage /> },
    { key: 'teams', label: 'Teams', page: () => <TeamsPage /> },
    { key: 'workspaces', label: 'Workspaces', page: () => <WorkspacesPage />, when: (boot) => boot.workspaceModel },
];

/**
 * The management screens, shared by both frontends: the host decides where the URL comes from
 * (the browser, or Inertia) and how to move to another one.
 */
export default function Acl({ boot, pathname, navigate }: { boot: Boot; pathname: string; navigate: (url: string) => void }) {
    // The workspace the screens act in, as the server resolved it; the input below is a draft until committed.
    const [workspace, setWorkspace] = useState(boot.workspace === null ? '' : String(boot.workspace));
    const api = useMemo(() => createApi(boot.path, boot.workspace === null ? null : String(boot.workspace)), [boot.path, boot.workspace]);
    const [draft, setDraft] = useState(workspace);
    const available = views.filter((view) => view.when?.(boot) ?? true);
    const current = pathname.slice(boot.path.length).split('/').filter(Boolean)[0] ?? 'roles';
    const view = available.find((candidate) => candidate.key === current) ?? available[0];

    function commit() {
        if (draft !== workspace) {
            setWorkspace(draft);
            api.setWorkspace(draft);
        }
    }

    return (
        <AclContext.Provider value={{ api, boot }}>
            <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
                <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <nav aria-label="ACL" className="flex items-center gap-1">
                            <span className="mr-3 text-sm font-semibold">ACL</span>
                            {available.map((item) => (
                                <a
                                    key={item.key}
                                    href={`${boot.path}/${item.key}`}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        navigate(`${boot.path}/${item.key}`);
                                    }}
                                    aria-current={item === view ? 'page' : undefined}
                                    className={`rounded-md px-3 py-1.5 text-sm ${item === view ? 'bg-indigo-50 font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'}`}
                                >
                                    {item.label}
                                </a>
                            ))}
                        </nav>

                        {boot.workspaces && (
                            <label className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
                                Acting in workspace
                                <input
                                    value={draft}
                                    placeholder="your default"
                                    onChange={(event) => setDraft(event.target.value)}
                                    onBlur={commit}
                                    onKeyDown={(event) => event.key === 'Enter' && commit()}
                                    className="w-24 rounded-md border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800"
                                />
                            </label>
                        )}
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-4 py-6">
                    {/* Remounted with the acting workspace, so each screen loads what it reaches. */}
                    <div key={`${view.key}:${workspace}`}>{view.page()}</div>
                </main>
            </div>
        </AclContext.Provider>
    );
}
