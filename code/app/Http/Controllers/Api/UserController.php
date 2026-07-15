<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only user management (Users & Roles screen). Accounts are provisioned
 * here — there is no public signup — so create requires an initial password.
 */
class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $users = User::query()->orderBy('name')->get();

        return UserResource::collection($users);
    }

    public function store(UserRequest $request): UserResource
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            // No mailer flow: admin-provisioned accounts are usable immediately.
            'email_verified_at' => now(),
        ]);

        return new UserResource($user);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        // Guardrail: never demote the last remaining admin (would lock everyone
        // out of the admin surface). Covers demoting yourself when you're the only one.
        if (
            array_key_exists('role', $data)
            && $user->role === 'admin'
            && $data['role'] !== 'admin'
            && User::where('role', 'admin')->count() <= 1
        ) {
            throw ValidationException::withMessages([
                'role' => 'Cannot demote the last remaining admin.',
            ]);
        }

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'role' => $data['role'] ?? $user->role,
        ]);

        // Blank/absent password on update = leave the existing one unchanged.
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return new UserResource($user);
    }

    public function destroy(User $user): \Illuminate\Http\Response
    {
        // Guardrail: an admin cannot delete their own account.
        if ($user->id === request()->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $user->delete();

        return response()->noContent();
    }
}
