<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Tests\TestCase;

class UlidKeysTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.key_type', 'ulid');
    }

    public function testTeamsGetStringKeys(): void
    {
        $team = AclTeam::create(['name' => 'Ops']);

        $this->assertIsString($team->id);
        $this->assertFalse($team->getIncrementing());
        $this->assertSame('string', $team->getKeyType());
        $this->assertSame($team->id, AclTeam::query()->where('name', 'Ops')->value('id'));
    }
}
