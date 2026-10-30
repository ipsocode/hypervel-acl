<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Ipsocode\Acl\Concerns\DefinesRole;
use Ipsocode\Acl\Contracts\RoleDefinition;

enum Role: string implements RoleDefinition
{
    use DefinesRole;

    case Viewer = 'viewer';
    case Editor = 'editor';
    case Manager = 'manager';
    case Restricted = 'restricted';
    case Member = 'member';

    public function permissions(): array
    {
        return match ($this) {
            self::Viewer => ['articles.view'],
            self::Editor => ['articles.edit'],
            self::Manager => ['articles.*', 'acl.*'],
            self::Restricted => ['articles.*'],
            self::Member => [],
        };
    }

    public function deniedPermissions(): array
    {
        return match ($this) {
            self::Restricted => ['articles.delete'],
            default => [],
        };
    }

    public function mirrors(): ?RoleDefinition
    {
        return $this === self::Manager ? self::Editor : null;
    }
}
