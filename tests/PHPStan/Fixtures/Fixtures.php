<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\PHPStan\Fixtures;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Concerns\DefinesRole;
use Ipsocode\Acl\Concerns\HasTenant;
use Ipsocode\Acl\Concerns\IsTeam;
use Ipsocode\Acl\Contracts\RoleDefinition;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Testing\Factories\AssignsAcl;

// PHPStan analyzes a trait only through a class that uses it, and applications use these.
enum FixtureRole: string implements RoleDefinition
{
    use DefinesRole;

    case One = 'one';

    public function permissions(): array
    {
        return [];
    }
}

class FixtureTeam extends Model implements Team
{
    use IsTeam;
}

/**
 * @extends Factory<FixtureTeam>
 */
class FixtureFactory extends Factory
{
    use AssignsAcl;

    protected ?string $model = FixtureTeam::class;

    public function definition(): array
    {
        return [];
    }
}

class FixtureUser extends Model
{
    use HasTenant;
}
