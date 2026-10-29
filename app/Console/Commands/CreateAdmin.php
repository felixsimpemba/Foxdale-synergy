<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates the first admin after a deployment (or promotes an existing account). Prints the
 * generated password once; the admin is asked to set up two-factor on first sign-in.
 */
class CreateAdmin extends Command
{
    protected $signature = 'incidents:create-admin
        {email : The admin\'s email address}
        {--name= : Their full name (defaults to the part before @)}
        {--password= : Password (a strong one is generated and printed when omitted)}';

    protected $description = 'Create or promote the first admin account for a deployment';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        // A fresh production database has no roles yet: create them first.
        if (! Role::query()->where('name', RoleName::Admin->value)->exists()) {
            $this->line('Roles are missing; seeding roles and permissions first.');
            $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        }

        $givenPassword = $this->option('password');
        $generated = $givenPassword === null;
        $password = $givenPassword ?? Str::password(20);

        /** @var User|null $existing */
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $existing->forceFill([
                'is_active' => true,
                'password' => $givenPassword === null ? $existing->password : Hash::make((string) $givenPassword),
            ])->save();
            $user = $existing;
            $this->info("{$email} already exists; giving it the admin role.");
        } else {
            $user = User::create([
                'name' => $this->option('name') ?: Str::headline(Str::before($email, '@')),
                'email' => $email,
                'password' => $password,
                'timezone' => (string) config('incidents.display_timezone'),
            ]);
            $this->info("Created {$email}.");
        }

        $user->assignRole(RoleName::Admin->value);

        if ($generated && $existing === null) {
            $this->newLine();
            $this->line("Password: {$password}");
            $this->warn('Shown once. Sign in, set up two-factor authentication, and change it if your policy requires.');
        } elseif ($givenPassword !== null) {
            $this->line('Password set as given.');
        }

        $this->newLine();
        $this->comment('Admin accounts must set up two-factor authentication at first sign-in.');

        return self::SUCCESS;
    }
}
