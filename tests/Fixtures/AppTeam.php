<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Fixtures;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Concerns\IsTeam;
use Ipsocode\Acl\Contracts\Team;

/**
 * A team model an application owns, with a table of its own.
 */
class AppTeam extends Model implements Team
{
    use IsTeam;

    protected ?string $table = 'app_teams';

    protected array $guarded = [];
}
