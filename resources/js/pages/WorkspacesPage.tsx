import { type FormEvent, useState } from 'react';

import { useCollection } from '../hooks';
import type { Errors, Workspace } from '../types';
import { Badge, Button, Card, Empty, Field, FormError, Input, Select, errorsOf, useAcl } from '../ui';

export default function WorkspacesPage() {
    const { boot } = useAcl();

    return (
        <div className="space-y-6">
            {boot.workspaceModel && <Tree />}
            <Reach />
        </div>
    );
}

function Reach() {
    const reach = useCollection<number | string>('reach');

    return (
        <Card title="Reach of the workspace this session acts in">
            {reach.items === null ? null : reach.items.length === 0 ? (
                <Empty>No workspaces.</Empty>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {reach.items.map((key) => (
                        <Badge key={key} tone="indigo">
                            {key}
                        </Badge>
                    ))}
                </div>
            )}
        </Card>
    );
}

function Tree() {
    const { api, boot } = useAcl();
    const workspaces = useCollection<Workspace>('workspaces');
    const [editing, setEditing] = useState<Workspace | 'new' | null>(null);
    const [name, setName] = useState('');
    const [kind, setKind] = useState('');
    const [parent, setParent] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const column = boot.workspaceParent ?? 'parent_id';

    function open(workspace: Workspace | 'new') {
        setEditing(workspace);
        setName(workspace === 'new' ? '' : workspace.name);
        setKind(workspace === 'new' ? '' : (workspace.kind ?? ''));
        setParent(workspace === 'new' || workspace.parent_id === null ? '' : String(workspace.parent_id));
        setErrors({});
    }

    async function save(event: FormEvent) {
        event.preventDefault();

        try {
            const body = { name, kind: kind === '' ? null : kind, [column]: parent === '' ? null : parent };

            if (editing === 'new') {
                await api.post('workspaces', body);
            } else {
                await api.put(`workspaces/${editing?.id}`, body);
            }

            setEditing(null);
            await workspaces.reload();
        } catch (failure) {
            setErrors(errorsOf(failure));
        }
    }

    async function destroy(workspace: Workspace) {
        if (!window.confirm(`Delete the workspace "${workspace.name}"?`)) {
            return;
        }

        try {
            await api.delete(`workspaces/${workspace.id}`);
            await workspaces.reload();
        } catch (failure) {
            window.alert(errorsOf(failure)._?.join(' ') ?? 'The workspace could not be deleted.');
        }
    }

    const items = workspaces.items ?? [];
    const depth = (workspace: Workspace): number => {
        const above = items.find((other) => other.id === workspace.parent_id);

        return above === undefined || above.id === workspace.id ? 0 : 1 + depth(above);
    };
    const ordered = items
        .map((workspace) => ({ workspace, depth: depth(workspace) }))
        .sort((a, b) => a.depth - b.depth || a.workspace.name.localeCompare(b.workspace.name));
    const tree: typeof ordered = [];
    const place = (parentId: Workspace['id'] | null) => {
        for (const entry of ordered.filter((candidate) => candidate.workspace.parent_id === parentId || (parentId === null && !items.some((other) => other.id === candidate.workspace.parent_id)))) {
            tree.push(entry);
            place(entry.workspace.id);
        }
    };
    place(null);

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <Card title="Workspaces" actions={<Button onClick={() => open('new')}>New workspace</Button>}>
                {tree.length === 0 ? (
                    <Empty>No workspaces beneath this one.</Empty>
                ) : (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                        {tree.map(({ workspace, depth: level }) => (
                            <li key={workspace.id} className="flex items-center justify-between gap-3 py-2 text-sm" style={{ paddingLeft: `${level * 1.25}rem` }}>
                                <span>
                                    {workspace.name} <span className="font-mono text-xs text-slate-500">#{workspace.id}</span>
                                </span>
                                <span className="flex items-center gap-2">
                                    {workspace.kind && <Badge>{workspace.kind}</Badge>}
                                    <Button variant="secondary" onClick={() => open(workspace)}>
                                        Edit
                                    </Button>
                                    <Button variant="danger" onClick={() => destroy(workspace)}>
                                        Delete
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {editing && (
                <Card title={editing === 'new' ? 'New workspace' : `Edit ${editing.name}`}>
                    <form onSubmit={save} className="space-y-3">
                        <FormError errors={errors} />
                        <Field label="Name" name="name" errors={errors}>
                            <Input value={name} onChange={(event) => setName(event.target.value)} required />
                        </Field>
                        <Field label="Kind" name="kind" errors={errors}>
                            <Input value={kind} onChange={(event) => setKind(event.target.value)} />
                        </Field>
                        <Field label="Beneath" name={column} errors={errors}>
                            <Select value={parent} onChange={(event) => setParent(event.target.value)}>
                                <option value="">No workspace</option>
                                {items
                                    .filter((other) => editing === 'new' || other.id !== editing.id)
                                    .map((other) => (
                                        <option key={other.id} value={String(other.id)}>
                                            {other.name}
                                        </option>
                                    ))}
                            </Select>
                        </Field>
                        <div className="flex gap-2">
                            <Button type="submit">Save</Button>
                            <Button variant="secondary" onClick={() => setEditing(null)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </Card>
            )}
        </div>
    );
}
