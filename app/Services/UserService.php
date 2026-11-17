<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    /**
     * Get paginated or list of sales users.
     *
     * @param  array{role?: string, search?: string, per_page?: int}  $filters
     */
    public function getUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::query();

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get all active sales users (for dropdowns / target assignments).
     *
     * @return Collection<int, User>
     */
    public function getSalesUsers(): Collection
    {
        return User::where('role', 'sales')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role']);
    }

    /**
     * Create a new sales or admin user.
     *
     * @param  array{name: string, email: string, phone: string, password: string, role?: string}  $data
     */
    public function createUser(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => $data['role'] ?? 'sales',
            'is_active' => true,
        ]);
    }

    /**
     * Update an existing user.
     *
     * @param  array{name?: string, email?: string, phone?: string, password?: string, role?: string, is_active?: bool}  $data
     */
    public function updateUser(User $user, array $data): User
    {
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return $user;
    }

    /**
     * Delete a user.
     */
    public function deleteUser(User $user): void
    {
        $user->delete();
    }
}
