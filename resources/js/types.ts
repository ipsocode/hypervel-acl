export interface Boot {
    path: string;
    workspaces: boolean;
    workspace: number | string | null;
    workspaceModel: boolean;
    workspaceParent: string | null;
    teamInherit: string;
    tenant: boolean;
    guards: string[];
}

export interface Role {
    id: number | string;
    name: string;
    guard_name: string;
    permissions?: string[];
    denied?: string[];
}

export interface Permission {
    id: number | string;
    name: string;
    guard_name: string;
}

export interface Team {
    id: number | string;
    name: string;
    tenant: number | string | null;
    roles: string[];
    [inherit: string]: number | string | string[] | null;
}

export interface Workspace {
    id: number | string;
    name: string;
    kind: string | null;
    tenant: number | string | null;
    parent_id: number | string | null;
    meta: Record<string, unknown> | null;
}

export type Errors = Record<string, string[]>;
