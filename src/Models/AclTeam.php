<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Models;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Concerns\BelongsToTenant;
use Ipsocode\Acl\Concerns\HasConfiguredKey;
use Ipsocode\Acl\Concerns\IsTeam;
use Ipsocode\Acl\Contracts\Team as TeamContract;

class AclTeam extends Model implements TeamContract
{
    use BelongsToTenant;
    use HasConfiguredKey;
    use IsTeam;

    protected ?string $table = 'acl_teams';

    protected array $guarded = [];

    protected array $casts = ['meta' => 'array'];
}
