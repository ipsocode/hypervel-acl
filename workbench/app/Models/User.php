<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Permission\Traits\HasRoles;
use Ipsocode\Acl\Concerns\HasTenant;
use Workbench\Database\Factories\UserFactory;

class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use HasTenant;

    protected array $fillable = ['name', 'email', 'password'];

    protected array $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
