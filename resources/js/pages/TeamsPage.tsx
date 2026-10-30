import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';

import { useCollection } from '../hooks';
import type { Errors, Role, Team } from '../types';
import { Badge, Button, Card, Empty, Field, FormError, Input, Select, attempt, errorsOf, useAcl } from '../ui';

export default function TeamsPage() {
    const teams = useCollection<Team>('teams');
    const roles = useCollection<Role>('roles');
    const [selected, setSelected] = useState<Team | 'new' | null>(null);

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
            <Card title="Teams" actions={<Button onClick={() => setSelected('new')}>New team</Button>}>
                {teams.items === null ? null : teams.items.length === 0 ? (
                    <Empty>No teams yet.</Empty>
                ) : (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                        {teams.items.map((team) => (
                            <li key={team.id}>
                                <button
                                    type="button"
                                    onClick={() => setSelected(team)}
                                    className={`flex w-full items-center justify-between gap-2 px-2 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800 ${selected !== 'new' && selected?.id === team.id ? 'font-semibold text-indigo-600' : ''}`}
                                >
                                    {team.name}
                                    <span className="flex flex-wrap justify-end gap-1">
                                        {team.inherit_id !== null && <Badge tone="indigo">inherits {teams.items?.find((other) => other.id === team.inherit_id)?.name ?? team.inherit_id}</Badge>}
                                        {team.roles.map((role) => (
                                            <Badge key={role}>{role}</Badge>
                                        ))}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {selected === null ? (
                <Card>
                    <Empty>Select a team, or make a new one.</Empty>
                </Card>
            ) : (
                <TeamEditor
                    key={selected === 'new' ? 'new' : selected.id}
                    team={selected === 'new' ? null : selected}
                    teams={teams.items ?? []}
                    roles={roles.items ?? []}
                    onSaved={async (team) => {
                        await teams.reload();
                        setSelected(team);
                    }}
                    onDeleted={async () => {
                        setSelected(null);
                        await teams.reload();
                    }}
                />
            )}
        </div>
    );
}

function TeamEditor({ team, teams, roles, onSaved, onDeleted }: { team: Team | null; teams: Team[]; roles: Role[]; onSaved: (team: Team) => Promise<void>; onDeleted: () => Promise<void> }) {
    const { api, boot } = useAcl();
    const [name, setName] = useState(team?.name ?? '');
    const [inherit, setInherit] = useState(team?.inherit_id === null || team === null ? '' : String(team.inherit_id));
    const [granted, setGranted] = useState<string[]>(team?.roles ?? []);
    const [errors, setErrors] = useState<Errors>({});
    const [saved, setSaved] = useState(false);
    // The team a failed save already created, so saving again updates it instead of making a second.
    const created = useRef<Team | null>(null);

    async function save(event: FormEvent) {
        event.preventDefault();
        setSaved(false);

        try {
            const body = { name, inherit_id: inherit === '' ? null : inherit };
            const existing = team ?? created.current;
            const response = existing ? await api.put<{ data: Team }>(`teams/${existing.id}`, body) : await api.post<{ data: Team }>('teams', body);

            created.current = response.data;

            const result = await api.put<{ data: Team }>(`teams/${response.data.id}/roles`, { roles: granted });

            setErrors({});
            setSaved(true);
            await onSaved(result.data);
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    async function destroy() {
        if (team && window.confirm(`Delete the team "${team.name}"?`)) {
            await attempt(async () => {
                await api.delete(`teams/${team.id}`);
                await onDeleted();
            });
        }
    }

    return (
        <div className="space-y-6">
            <Card
                title={team ? `Edit ${team.name}` : 'New team'}
                actions={
                    team && (
                        <Button variant="danger" onClick={destroy}>
                            Delete
                        </Button>
                    )
                }
            >
                <form onSubmit={save} className="space-y-4">
                    <FormError errors={errors} />
                    <Field label="Name" name="name" errors={errors}>
                        <Input value={name} onChange={(event) => setName(event.target.value)} required />
                    </Field>
                    <Field label="Inherits the roles of" name="inherit_id" errors={errors}>
                        <Select value={inherit} onChange={(event) => setInherit(event.target.value)}>
                            <option value="">No team</option>
                            {teams
                                .filter((other) => other.id !== team?.id)
                                .map((other) => (
                                    <option key={other.id} value={String(other.id)}>
                                        {other.name}
                                    </option>
                                ))}
                        </Select>
                    </Field>
                    {boot.tenant && team && <p className="text-xs text-slate-500">Tenant: {String(team.tenant ?? 'none')}</p>}

                    <fieldset>
                        <legend className="mb-2 text-xs font-medium text-slate-600 dark:text-slate-400">Roles</legend>
                        {errors.roles?.map((message) => (
                            <p key={message} className="text-xs text-red-600">
                                {message}
                            </p>
                        ))}
                        <div className="grid gap-1 sm:grid-cols-2">
                            {roles.map((role) => (
                                <label key={role.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={granted.includes(role.name)}
                                        onChange={(event) => setGranted(event.target.checked ? [...granted, role.name] : granted.filter((value) => value !== role.name))}
                                    />
                                    {role.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="flex items-center gap-3">
                        <Button type="submit">Save team</Button>
                        {saved && <span className="text-sm text-green-600">Saved.</span>}
                    </div>
                </form>
            </Card>

            {team && <Members team={team} />}
        </div>
    );
}

function Members({ team }: { team: Team }) {
    const { api, boot } = useAcl();
    const [members, setMembers] = useState<Array<number | string>>([]);
    const [user, setUser] = useState('');
    const [workspace, setWorkspace] = useState('');
    const [errors, setErrors] = useState<Errors>({});

    const latest = useRef(0);

    // Only the latest answer sets the members, so a slow one for another team is dropped.
    const load = useCallback(async () => {
        const request = ++latest.current;
        const data = (await api.get<{ data: Array<number | string> }>(`teams/${team.id}/users`)).data;

        if (request === latest.current) {
            setMembers(data);
        }
    }, [api, team.id]);

    useEffect(() => {
        void attempt(load);

        return () => {
            latest.current++;
        };
    }, [load]);

    async function add(event: FormEvent) {
        event.preventDefault();

        try {
            await api.post(`teams/${team.id}/users`, { user_id: user, ...(boot.workspaces && workspace !== '' ? { workspace } : {}) });
            setUser('');
            setErrors({});
            await load();
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    async function remove(member: number | string) {
        await attempt(async () => {
            await api.delete(`teams/${team.id}/users/${member}`, boot.workspaces && workspace !== '' ? { workspace } : undefined);
            await load();
        });
    }

    return (
        <Card title="Members">
            <form onSubmit={add} className="mb-4 flex flex-wrap items-end gap-2">
                <div className="flex-1">
                    <Field label="User id" name="user_id" errors={errors}>
                        <Input value={user} onChange={(event) => setUser(event.target.value)} required />
                    </Field>
                </div>
                {boot.workspaces && (
                    <div className="w-40">
                        <Field label="Workspace id" name="workspace" errors={errors}>
                            <Input value={workspace} onChange={(event) => setWorkspace(event.target.value)} placeholder="home" />
                        </Field>
                    </div>
                )}
                <Button type="submit">Add</Button>
            </form>
            <FormError errors={errors} />
            {members.length === 0 ? (
                <Empty>No members.</Empty>
            ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {members.map((member) => (
                        <li key={member} className="flex items-center justify-between py-1.5 text-sm">
                            <span className="font-mono text-xs">{member}</span>
                            <Button variant="secondary" onClick={() => remove(member)}>
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
