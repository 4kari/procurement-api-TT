<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TokenAuthenticate
{
    public function __construct(private readonly AuthService $authService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthenticationException('Unauthenticated. Provide a Bearer token.');
        }

        $plaintext = substr($header, 7);
        $user      = $this->authService->authenticate($plaintext);

        // Bind the authenticated user to the request
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }
}
