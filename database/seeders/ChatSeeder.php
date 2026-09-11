<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class ChatSeeder extends Seeder
{
    /**
     * Seed the single admin user. No bot tokens here — those are created
     * by the admin in Settings (/settings) and shown once like API keys.
     *
     * Credentials come from env (with project defaults):
     *   SEED_ADMIN_EMAIL, SEED_ADMIN_NAME, SEED_ADMIN_PASSWORD
     * Password is only set on first create, never overwritten.
     */
    public function run(): void
    {
        $email = env('SEED_ADMIN_EMAIL', 'admin@pi');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('SEED_ADMIN_NAME', 'admin'),
                'password' => env('SEED_ADMIN_PASSWORD', '12345678'),
                'is_admin' => true,
            ]
        );
        if (! $user->is_admin) {
            $user->update(['is_admin' => true]);
        }

        $this->command->info("Admin: {$user->email}");
        $this->command->info('Create bot tokens at /settings after logging in.');
    }
}
