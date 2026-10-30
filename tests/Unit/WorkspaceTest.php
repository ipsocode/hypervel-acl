<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Unit;

use Hypervel\Testing\UnitTestCase;
use Ipsocode\Acl\Workspace;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class WorkspaceTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Workspace::set(null);

        parent::tearDown();
    }

    public function testTheHomeWorkspaceIsTheDefault(): void
    {
        $this->assertSame(Workspace::HOME, Workspace::id());
    }

    public function testAWorkspaceIsSetAndForgotten(): void
    {
        Workspace::set(7);
        $this->assertSame(7, Workspace::id());

        Workspace::set('north');
        $this->assertSame('north', Workspace::id());

        Workspace::set(null);
        $this->assertSame(Workspace::HOME, Workspace::id());

        Workspace::set('north');
        Workspace::set('');
        $this->assertSame(Workspace::HOME, Workspace::id());
    }

    public function testRunRestoresThePreviousWorkspace(): void
    {
        $this->assertSame('inner', Workspace::run('inner', fn (): int|string => Workspace::id()));
        $this->assertSame(Workspace::HOME, Workspace::id());

        Workspace::set('outer');

        Workspace::run('inner', function (): void {
            $this->assertSame('inner', Workspace::id());
        });

        $this->assertSame('outer', Workspace::id());
    }

    public function testRunRestoresThePreviousWorkspaceWhenTheCallbackThrows(): void
    {
        Workspace::set('outer');
        $exception = new RuntimeException('failed');

        try {
            Workspace::run('inner', fn () => throw $exception);
            $this->fail('The callback\'s exception was swallowed.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('outer', Workspace::id());
    }

    public function testWorkspacesAreIsolatedBetweenCoroutines(): void
    {
        [$north, $south] = parallel([
            function (): int|string {
                Workspace::set('north');
                usleep(5000);

                return Workspace::id();
            },
            function (): int|string {
                Workspace::set('south');
                usleep(5000);

                return Workspace::id();
            },
        ]);

        $this->assertSame('north', $north);
        $this->assertSame('south', $south);
        $this->assertSame(Workspace::HOME, Workspace::id());
    }
}
