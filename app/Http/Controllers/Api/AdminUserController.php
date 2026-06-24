<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAdminUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isMainAdmin(), 403);

        $users = User::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (User $user): array => $this->formatUser($user));

        return response()->json(['data' => $users]);
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json([
            'data' => $this->formatUser($user),
        ], 201);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_main_admin' => $user->isMainAdmin(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
