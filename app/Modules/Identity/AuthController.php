<?php

namespace App\Modules\Identity;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', 'unique:users'], 'password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'phone' => ['nullable', 'regex:/^\+255[67]\d{8}$/', 'unique:users'], 'role' => ['prohibited'], 'roles' => ['prohibited'], 'suspended_at' => ['prohibited']]);
        $user = DB::transaction(function () use ($data): User {
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $user->forceFill(['phone' => $data['phone'] ?? null])->save();
            $user->roles()->attach(Role::where('name', 'customer')->value('id'));

            return $user;
        });

        return response()->json(['data' => $this->profile($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:1024'], 'device_name' => ['required', 'string', 'max:100']]);
        $user = User::where('is_guest', false)->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->suspended_at !== null) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        $token = $user->createToken($data['device_name'], ['*'], now()->addMinutes(config('marketplace.token_expiry_minutes')));

        return response()->json(['data' => ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $token->accessToken->expires_at, 'user' => $this->profile($user)]])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'roles' => ['prohibited'], 'role' => ['prohibited'], 'suspended_at' => ['prohibited'], 'email' => ['prohibited'], 'phone' => ['prohibited']]);
        $request->user()->fill(['name' => $data['name']])->save();

        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token !== null && $request->bearerToken() !== null) {
            $token->delete();
        } elseif ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    private function profile(User $user): array
    {
        return ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'roles' => $user->roles()->pluck('name'), 'permissions' => AdminAccess::permissions($user)];
    }
}
