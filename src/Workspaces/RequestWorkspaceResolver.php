<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Workspaces;

use Hypervel\Http\Request;
use Ipsocode\Acl\Contracts\WorkspaceResolver;

class RequestWorkspaceResolver implements WorkspaceResolver
{
    /**
     * The session entry holding the workspace the user last selected.
     */
    public const SESSION_KEY = 'acl:workspace';

    /**
     * Get the workspace from the X-Workspace header or the workspace route parameter, and keep it
     * in the session as the user's selection; without either, the selection kept there.
     */
    public function resolve(Request $request): int|string|null
    {
        $session = $request->hasSession() ? $request->session() : null;
        $workspace = $request->header('X-Workspace') ?? $request->route('workspace');

        if (is_int($workspace) || (is_string($workspace) && $workspace !== '')) {
            $session?->put(self::SESSION_KEY, $workspace);

            return $workspace;
        }

        $selected = $session?->get(self::SESSION_KEY);

        return is_int($selected) || is_string($selected) ? $selected : null;
    }
}
