<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Only active platform administrators can authenticate against this panel.
 * Restricting the credentials means vendor/school/customer accounts never get a session here.
 */
class Login extends BaseLogin
{
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => 'platform_admin',
            'is_active' => true,
        ];
    }
}
