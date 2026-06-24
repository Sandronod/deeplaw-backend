<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $isBootstrapMainAdmin = User::query()->count() === 0
            && strcasecmp((string) $request->input('email'), (string) config('legal_admin.main_admin_email')) === 0;

        if (! config('legal_admin.allow_public_registration') && ! $isBootstrapMainAdmin) {
            return response()->json([
                'message' => 'Public registration is disabled. Users must be created by the main admin.',
            ], 403);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'password'   => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user  = User::create($data);
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'user'  => $this->formatUser($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['მეილი ან პაროლი არასწორია.'],
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'user'  => $this->formatUser($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'is_main_admin' => $user->isMainAdmin(),
        ];
    }
}
