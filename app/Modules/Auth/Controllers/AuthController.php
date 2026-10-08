<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\DTOs\RegisterData;
use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\RegisterRequest;
use App\Modules\Auth\Requests\ResetPasswordRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register(RegisterData::fromArray($request->validated()));

        return $this->ok($this->authPayload($result), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->string('email'), $request->string('password'));

        return $this->ok($this->authPayload($result));
    }

    public function me(): JsonResponse
    {
        $user = auth('api')->user()->load('tenant');

        return $this->ok(['user' => new UserResource($user)]);
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return $this->ok(['message' => 'Logged out.']);
    }

    public function refresh(): JsonResponse
    {
        return $this->ok($this->authPayload($this->auth->refresh()));
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->sendResetLink($request->string('email'));

        // Constant response regardless of whether the email exists.
        return $this->ok(['message' => 'If the email exists, a reset link has been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->only('email', 'password', 'password_confirmation', 'token'));

        return $this->ok(['message' => 'Password has been reset. You can now log in.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $request->user()->update($data);

        return $this->ok(['user' => new UserResource($request->user()->fresh()->load('tenant'))]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            $request->validate([
                'password' => ['required', 'confirmed', Password::min(8)],
            ]);
        } else {
            $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ]);

            if (! Hash::check($request->input('current_password'), $user->password)) {
                return $this->fail('Current password is incorrect.', [], 422);
            }
        }

        $user->update(['password' => $request->input('password')]);

        return $this->ok(['message' => 'Password changed successfully.']);
    }

    /** Shape the {user, token, ...} service result for the API envelope. */
    private function authPayload(array $result): array
    {
        return [
            'user' => new UserResource($result['user']->load('tenant')),
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_in' => $result['expires_in'],
        ];
    }
}
