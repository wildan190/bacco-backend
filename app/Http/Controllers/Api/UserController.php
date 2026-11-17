<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateSalesUserRequest;
use App\Http\Requests\User\UpdateSalesUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * List users (Admin only).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = $this->userService->getUsers([
            'role' => $request->query('role'),
            'search' => $request->query('search'),
            'per_page' => (int) $request->query('per_page', 15),
        ]);

        return UserResource::collection($users);
    }

    /**
     * List sales users for dropdowns/assignments.
     */
    public function salesList(): AnonymousResourceCollection
    {
        $sales = $this->userService->getSalesUsers();

        return UserResource::collection($sales);
    }

    /**
     * Register a new user (Admin only).
     */
    public function store(CreateSalesUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated());

        return response()->json([
            'message' => 'User berhasil didaftarkan.',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Show user details.
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Update user (Admin only).
     */
    public function update(UpdateSalesUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->updateUser($user, $request->validated());

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'user' => new UserResource($updated),
        ]);
    }

    /**
     * Delete user (Admin only).
     */
    public function destroy(User $user): JsonResponse
    {
        $this->userService->deleteUser($user);

        return response()->json([
            'message' => 'User berhasil dihapus.',
        ]);
    }
}
