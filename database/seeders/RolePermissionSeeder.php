<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'products.view', 'products.create', 'products.edit', 'products.delete',
            'orders.view', 'orders.edit',
            'courses.view', 'courses.create', 'courses.edit', 'courses.delete',
            'competition.review', 'competition.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $superAdmin = Role::findOrCreate('super_admin');
        $superAdmin->syncPermissions(Permission::all());

        Role::findOrCreate('content_manager')
            ->syncPermissions(['products.view', 'products.create', 'products.edit', 'courses.view', 'courses.create', 'courses.edit']);

        Role::findOrCreate('order_manager')
            ->syncPermissions(['orders.view', 'orders.edit', 'products.view']);

        Role::findOrCreate('competition_moderator')
            ->syncPermissions(['competition.review', 'competition.manage']);
    }
}
