<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Exceptions;

use BadMethodCallException;

class TenantNotEnabled extends BadMethodCallException
{
    /**
     * Create a new tenant not enabled exception.
     */
    public static function create(): self
    {
        return new self('The tenant feature is not enabled. Set `tenant` to `true` in your acl config file.');
    }
}
