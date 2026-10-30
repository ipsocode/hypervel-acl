<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

use Hypervel\Database\Console\Seeds\SeederMakeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acl:make-seeder')]
class MakeAclSeederCommand extends SeederMakeCommand
{
    protected ?string $signature = 'acl:make-seeder {name=AclSeeder : The name of the seeder}';

    protected string $description = 'Create a new ACL catalogue seeder class';

    protected function getStub(): string
    {
        return __DIR__ . '/../../stubs/acl-seeder.stub';
    }
}
