<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Contracts;

use Hypervel\Http\Request;

/**
 * Names the workspace a request acts in; bind your own to read it from elsewhere.
 */
interface WorkspaceResolver
{
    /**
     * Get the key of the workspace the request names, or null when it names none.
     */
    public function resolve(Request $request): int|string|null;
}
