import { type FormEvent, useEffect, useState } from 'react';

import { useCollection } from '../hooks';
import type { Errors, Permission, Role } from '../types';
import { Badge, Button, Card, Empty, Field, FormError, Input, Select, attempt, errorsOf, useAcl } from '../ui';

type Effect = 'none' | 'allow' | 'deny';

export default function RolesPage() {
    const { api, boot } = useAcl();
    const roles = useCollection<Role>('roles');
    const permissions = useCollection<Permission>('permissions');
    const [selected, setSelected] = useState<Role | null>(null);
    const [name, setName] = useState('');
    const [guard, setGuard] = useState(boot.guards[0] ?? 'web');
    const [errors, setErrors] = useState<Errors>({});

    async function create(event: FormEvent) {
        event.preventDefault();

        try {
            const created = await api.post<{ data: Role }>('roles', { name, guard_name: guard });
            setName('');
            setErrors({});
            await roles.reload();
            setSelected(created.data);
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <div className="space-y-6">
                <Card title="New role">
                    <form onSubmit={create} className="space-y-3">
                        <FormError errors={errors} />
                        <Field label="Name" name="name" errors={errors}>
                            <Input value={name} onChange={(event) => setName(event.target.value)} required />
                        </Field>
                        <Field label="Guard" name="guard_name" errors={errors}>
                            <Select value={guard} onChange={(event) => setGuard(event.target.value)}>
                                {boot.guards.map((value) => (
                                    <option key={value}>{value}</option>
                                ))}
                            </Select>
                        </Field>
                        <Button type="submit">Create role</Button>
                    </form>
                </Card>

                <Card title="Roles">
                    {roles.items === null ? null : roles.items.length === 0 ? (
                        <Empty>No roles yet.</Empty>
                    ) : (
                        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                            {roles.items.map((role) => (
                                <li key={role.id}>
                                    <button
                                        type="button"
                                        onClick={() => setSelected(role)}
                                        className={`flex w-full items-center justify-between px-2 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800 ${selected?.id === role.id ? 'font-semibold text-indigo-600' : ''}`}
                                    >
                                        {role.name}
                                        <Badge>{role.guard_name}</Badge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {selected ? (
                <RoleEditor
                    key={selected.id}
                    id={selected.id}
                    permissions={permissions.items ?? []}
                    onChanged={() => roles.reload()}
                    onDeleted={() => {
                        setSelected(null);
                        void roles.reload();
                    }}
                />
            ) : (
                <Card>
                    <Empty>Select a role to edit it.</Empty>
                </Card>
            )}
        </div>
    );
}

function RoleEditor({ id, permissions, onChanged, onDeleted }: { id: Role['id']; permissions: Permission[]; onChanged: () => void; onDeleted: () => void }) {
    const { api } = useAcl();
    const [role, setRole] = useState<Role | null>(null);
    const [name, setName] = useState('');
    const [effects, setEffects] = useState<Record<string, Effect>>({});
    const [errors, setErrors] = useState<Errors>({});
    const [saved, setSaved] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        let current = true;

        api.get<{ data: Role }>(`roles/${id}`)
            .then(({ data }) => {
                if (!current) return;

                setRole(data);
                setName(data.name);
                setEffects(
                    Object.fromEntries([
                        ...(data.permissions ?? []).map((permission) => [permission, 'allow'] as const),
                        ...(data.denied ?? []).map((permission) => [permission, 'deny'] as const),
                    ]),
                );
            })
            .catch((failure: Error) => current && setLoadError(failure.message));

        return () => {
            current = false;
        };
    }, [api, id]);

    if (loadError !== null) {
        return (
            <Card>
                <Empty>{loadError}</Empty>
            </Card>
        );
    }

    if (role === null) {
        return null;
    }

    async function save(event: FormEvent) {
        event.preventDefault();
        setSaved(false);

        try {
            await api.put(`roles/${id}`, { name });
            await api.put(`roles/${id}/permissions`, {
                allowed: Object.keys(effects).filter((permission) => effects[permission] === 'allow'),
                denied: Object.keys(effects).filter((permission) => effects[permission] === 'deny'),
            });
            setErrors({});
            setSaved(true);
            onChanged();
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    async function destroy() {
        if (window.confirm(`Delete the role "${role?.name}"?`)) {
            await attempt(async () => {
                await api.delete(`roles/${id}`);
                onDeleted();
            });
        }
    }

    return (
        <Card
            title={`Edit ${role.name}`}
            actions={
                <Button variant="danger" onClick={destroy}>
                    Delete
                </Button>
            }
        >
            <form onSubmit={save} className="space-y-4">
                <FormError errors={errors} />
                <Field label="Name" name="name" errors={errors}>
                    <Input value={name} onChange={(event) => setName(event.target.value)} required />
                </Field>

                <div>
                    <h3 className="mb-2 text-xs font-medium text-slate-600 dark:text-slate-400">Permissions</h3>
                    {errors.allowed?.map((message) => (
                        <p key={message} className="text-xs text-red-600">
                            {message}
                        </p>
                    ))}
                    {permissions.filter((permission) => permission.guard_name === role.guard_name).length === 0 ? (
                        <Empty>No permissions on the {role.guard_name} guard.</Empty>
                    ) : (
                        <ul className="divide-y divide-slate-100 rounded-md border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                            {permissions
                                .filter((permission) => permission.guard_name === role.guard_name)
                                .map((permission) => (
                                    <li key={permission.id} className="flex items-center justify-between gap-3 px-3 py-1.5 text-sm">
                                        <span className="truncate font-mono text-xs">{permission.name}</span>
                                        <select
                                            aria-label={`Effect of ${permission.name}`}
                                            value={effects[permission.name] ?? 'none'}
                                            onChange={(event) => setEffects({ ...effects, [permission.name]: event.target.value as Effect })}
                                            className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs dark:border-slate-600 dark:bg-slate-800"
                                        >
                                            <option value="none">Not granted</option>
                                            <option value="allow">Allow</option>
                                            <option value="deny">Deny</option>
                                        </select>
                                    </li>
                                ))}
                        </ul>
                    )}
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit">Save role</Button>
                    {saved && <span className="text-sm text-green-600">Saved.</span>}
                </div>
            </form>
        </Card>
    );
}
