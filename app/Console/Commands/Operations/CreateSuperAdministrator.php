<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class CreateSuperAdministrator extends Command
{
    protected $signature = 'operations:make-super-admin {email : Account email} {--rotate : Replace an existing account password}';

    protected $description = 'Create or rotate a Super Administrator with a random password.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if ($user !== null && ! $this->option('rotate')) {
            $this->error('Account already exists. Pass --rotate to replace its password.');

            return self::FAILURE;
        }

        $password = Str::random(40);
        $user ??= new User();
        $user->forceFill([
            'name' => 'Super Administrator',
            'email' => $email,
            'role' => 'super_administrator',
            'password' => $password,
            'email_verified_at' => now(),
        ])->save();

        $this->info('Super Administrator account saved. Copy this one-time password now:');
        $this->line($password);

        return self::SUCCESS;
    }
}
