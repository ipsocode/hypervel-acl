<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Fixtures;

use Hypervel\Database\Eloquent\Model;

/**
 * A workspace model an application owns, without the package's scopes.
 */
class AppWorkspace extends Model
{
    protected ?string $table = 'acl_workspaces';

    protected array $guarded = [];
}
