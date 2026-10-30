<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;
use Ipsocode\Acl\Testing\Factories\AssignsAcl;
use Workbench\App\Models\User;

class UserFactory extends Factory
{
    use AssignsAcl;

    protected ?string $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ];
    }
}
