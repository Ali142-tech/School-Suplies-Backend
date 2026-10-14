<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProvisionPlatformAdmin extends Command
{
    protected $signature = 'admin:provision {email? : Super Admin email}';

    protected $description = 'Provision or promote a platform administrator using an interactively entered password';

    public function handle(): int
    {
        $email = strtolower((string) ($this->argument('email') ?: $this->ask('Super Admin email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $name = $this->ask('Administrator name', 'Platform Administrator');
        $password = $this->secret('Password (minimum 12 characters, including letters and numbers)');
        try {
            validator(['password' => $password], ['password' => ['required', Password::min(12)->letters()->numbers()]])->validate();
        } catch (ValidationException) {
            $this->error('The password does not meet the minimum requirements.');

            return self::FAILURE;
        }

        User::updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make($password), 'role' => 'platform_admin', 'is_active' => true,
        ]);
        $this->info('Platform administrator provisioned.');

        return self::SUCCESS;
    }
}
