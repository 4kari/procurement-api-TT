<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        [$user, $token] = $this->authService->login($data['email'], $data['password']);

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_at' => now()->addDays(30)->toISOString(),
                'user'       => [
                    'id'              => $user->id,
                    'name'            => $user->name,
                    'email'           => $user->email,
                    'role'            => $user->role,
                    'department_id'   => $user->department_id,
                ],
            ],
            'message' => 'Login berhasil',
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $token = str_replace('Bearer ', '', $request->header('Authorization', ''));
        $this->authService->logout($token);

        return response()->json(['success' => true, 'message' => 'Logout berhasil']);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $user->id,
                'employee_code' => $user->employee_code,
                'name'          => $user->name,
                'email'         => $user->email,
                'role'          => $user->role,
                'department'    => $user->department?->only(['id', 'name', 'code']),
                'last_login_at' => $user->last_login_at,
            ],
        ]);
    }
}
