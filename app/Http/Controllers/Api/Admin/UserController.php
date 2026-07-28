<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * List all users with their roles.
     */
    public function index(): JsonResponse
    {
        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Users retrieved successfully',
            'data' => $users,
        ]);
    }

    /**
     * Update a user's role.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $admin = Auth::user();

        // Prevent an admin from changing their own role
        if ($user->id === $admin->id) {
            return response()->json([
                'success' => false,
                'status' => 403,
                'message' => 'You cannot change your own role.',
            ], 403);
        }

        // Prevent demoting the last admin
        if ($user->role === 'Admin' && $request->role === 'Customer') {
            $remainingAdmins = User::where('role', 'Admin')
                ->where('id', '!=', $user->id)
                ->count();

            if ($remainingAdmins === 0) {
                return response()->json([
                    'success' => false,
                    'status' => 403,
                    'message' => 'Cannot demote the last admin.',
                ], 403);
            }
        }

        $oldRole = $user->role;

        // NOTE: token_valid_after is intentionally NOT in User::$fillable, so it
        // cannot be set via mass assignment (e.g. $user->update([...])) — that
        // call would silently drop it. Setting it via direct property
        // assignment bypasses $fillable safely, since this is fully
        // server-controlled (never touches $request input).
        $user->role = $request->role;
        $user->token_valid_after = now();
        $user->save();

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => "User role changed from {$oldRole} to {$request->role}",
            'data' => $user->only(['id', 'name', 'email', 'role']),
        ]);
    }
}