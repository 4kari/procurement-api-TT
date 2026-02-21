<?php

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * Attempt login. Returns [user, plaintext_token].
     * Token is stored as SHA-256 hash — plaintext returned only once.
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)
                    ->where('is_active', true)
                    ->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $user->update(['last_login_at' => now()]);

        $plaintext = Str::random(60);
        $hash      = hash('sha256', $plaintext);

        PersonalAccessToken::create([
            'user_id'    => $user->id,
            'token_hash' => $hash,
            'name'       => 'login',
            'abilities'  => ['*'],
            'expires_at' => now()->addDays(30),
        ]);

        return [$user, $plaintext];
    }

    /**
     * Validate a bearer token. Returns the authenticated user.
     */
    public function authenticate(string $plaintext): User
    {
        $hash  = hash('sha256', $plaintext);
        $token = PersonalAccessToken::where('token_hash', $hash)
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
                    ->first();

        if (!$token || $token->isExpired()) {
            throw new AuthenticationException('Token is invalid or expired.');
        }

        $token->update(['last_used' => now()]);

        return $token->user;
    }

    /**
     * Revoke the current token (logout).
     */
    public function logout(string $plaintext): void
    {
        $hash = hash('sha256', $plaintext);
        PersonalAccessToken::where('token_hash', $hash)->delete();
    }
}
