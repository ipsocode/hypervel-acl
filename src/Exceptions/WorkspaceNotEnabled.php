<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Exceptions;

use BadMethodCallException;

class WorkspaceNotEnabled extends BadMethodCallException
{
    /**
     * Create a new workspace not enabled exception.
     */
    public static function create(): self
    {
        return new self('The workspace feature is not enabled. Set `workspace` to `true` in your acl config file.');
    }
}
