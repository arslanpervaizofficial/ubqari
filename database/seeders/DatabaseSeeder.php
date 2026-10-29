<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // v3.1.0 (C1): NEVER seed a known password. A fresh admin gets a
        // random one-time password, printed ONCE by this seeder run and
        // stored only as a hash. An existing admin row is left untouched
        // (this seeder cannot change a live admin's password). The
        // must_change_password flag is set when that column exists, so
        // the ForcePasswordChange middleware (H5) forces a change at
        // next login; it is guarded like BackupController::reset() so
        // seeding still works before that migration has run. Creation
        // uses forceFill() because password/role are no longer in
        // User::$fillable (L4) — this is server-side assignment of
        // generated values, never request mass assignment.
        $admin = User::firstOrNew(['email' => 'admin@ubqari.pos']);
        if (!$admin->exists) {
            $oneTimePassword = Str::random(16);
            $admin->forceFill([
                'name' => 'Admin',
                'username' => 'admin',
                'password' => Hash::make($oneTimePassword),
                'role' => 'admin',
            ]);
            if (Schema::hasColumn('users', 'must_change_password')) {
                $admin->must_change_password = true;
            }
            $admin->save();
            $this->command->info('Seeded admin account (admin@ubqari.pos). One-time password (shown once only, copy it now): ' . $oneTimePassword);
        }

        $this->call(ProductSeeder::class);
    }
}
