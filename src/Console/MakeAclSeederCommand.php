<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

use Hypervel\Database\Console\Seeds\SeederMakeCommand;
use Ipsocode\Acl\Console\Concerns\ChecksPermissionPackage;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acl:make-seeder')]
class MakeAclSeederCommand extends SeederMakeCommand
{
    use ChecksPermissionPackage;

    protected ?string $signature = 'acl:make-seeder {name=AclSeeder : The name of the seeder} {--force : Create the seeder even if it already exists}';

    protected string $description = 'Create a new ACL catalogue seeder class';

    /**
     * Handle the command once the permission package is active.
     */
    public function handle(): bool|int
    {
        if (($problem = $this->permissionPackageProblem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        return parent::handle();
    }

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/acl-seeder.stub';
    }
}
