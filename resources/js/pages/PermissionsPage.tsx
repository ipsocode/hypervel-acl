import { type FormEvent, useState } from 'react';

import { useCollection } from '../hooks';
import type { Errors, Permission } from '../types';
import { Badge, Button, Card, Empty, Field, FormError, Input, Select, errorsOf, useAcl } from '../ui';

export default function PermissionsPage() {
    const { api, boot } = useAcl();
    const permissions = useCollection<Permission>('permissions');
    const [name, setName] = useState('');
    const [guard, setGuard] = useState(boot.guards[0] ?? 'web');
    const [filter, setFilter] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [editing, setEditing] = useState<Permission | null>(null);
    const [rename, setRename] = useState('');

    async function create(event: FormEvent) {
        event.preventDefault();

        try {
            await api.post('permissions', { name, guard_name: guard });
            setName('');
            setErrors({});
            await permissions.reload();
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    async function update(event: FormEvent) {
        event.preventDefault();

        try {
            await api.put(`permissions/${editing?.id}`, { name: rename });
            setEditing(null);
            setErrors({});
            await permissions.reload();
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    async function destroy(permission: Permission) {
        if (window.confirm(`Delete the permission "${permission.name}"?`)) {
            await api.delete(`permissions/${permission.id}`);
            await permissions.reload();
        }
    }

    const shown = (permissions.items ?? []).filter((permission) => permission.name.includes(filter));

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
            <Card title={editing ? `Rename ${editing.name}` : 'New permission'}>
                <form onSubmit={editing ? update : create} className="space-y-3">
                    <FormError errors={errors} />
                    <Field label="Name" name="name" errors={errors}>
                        <Input value={editing ? rename : name} onChange={(event) => (editing ? setRename(event.target.value) : setName(event.target.value))} required />
                    </Field>
                    {!editing && (
                        <Field label="Guard" name="guard_name" errors={errors}>
                            <Select value={guard} onChange={(event) => setGuard(event.target.value)}>
                                {boot.guards.map((value) => (
                                    <option key={value}>{value}</option>
                                ))}
                            </Select>
                        </Field>
                    )}
                    <div className="flex gap-2">
                        <Button type="submit">{editing ? 'Save' : 'Create permission'}</Button>
                        {editing && (
                            <Button variant="secondary" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                        )}
                    </div>
                </form>
            </Card>

            <Card
                title="Permissions"
                actions={<input value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="Filter" aria-label="Filter permissions" className="rounded-md border border-slate-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800" />}
            >
                {shown.length === 0 ? (
                    <Empty>No permissions.</Empty>
                ) : (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                        {shown.map((permission) => (
                            <li key={permission.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <span className="truncate font-mono text-xs">{permission.name}</span>
                                <span className="flex items-center gap-2">
                                    <Badge>{permission.guard_name}</Badge>
                                    <Button
                                        variant="secondary"
                                        onClick={() => {
                                            setEditing(permission);
                                            setRename(permission.name);
                                        }}
                                    >
                                        Rename
                                    </Button>
                                    <Button variant="danger" onClick={() => destroy(permission)}>
                                        Delete
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
