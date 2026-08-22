<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps a single platform super admin (tenant_id null). Credentials come
 * from env so no secret is committed; falls back to a documented default in
 * local only.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL', 'admin@example.com');

        $admin = User::withoutGlobalScopes()->firstOrNew(['email' => $email, 'tenant_id' => null]);
        $admin->fill([
            'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
            'is_super_admin' => true,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        if (! $admin->exists) {
            $admin->password = Hash::make(env('SUPER_ADMIN_PASSWORD', 'password'));
        }

        $admin->save();
        $admin->assignRole('super-admin');
    }
}
