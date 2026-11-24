<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    /**
     * Get paginated or list of sales/admin users scoped to creator admin.
     *
     * @param  array{role?: string, search?: string, per_page?: int}  $filters
     */
    public function getUsers(array $filters = [], ?User $currentUser = null): LengthAwarePaginator
    {
        $query = User::query();

        if ($currentUser && $currentUser->isAdmin()) {
            $query->where(function ($q) use ($currentUser) {
                $q->where('admin_id', $currentUser->id)
                  ->orWhere('id', $currentUser->id);
            });
        }

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
     * Get all active sales users for current admin (for dropdowns / target assignments).
     *
     * @return Collection<int, User>
     */
    public function getSalesUsers(?User $currentUser = null): Collection
    {
        $query = User::where('role', 'sales')
            ->where('is_active', true);

        if ($currentUser && $currentUser->isAdmin()) {
            $query->where('admin_id', $currentUser->id);
        }

        return $query->orderBy('name')->get(['id', 'name', 'email', 'phone', 'role', 'admin_id']);
    }

    /**
     * Create a new sales or admin user.
     *
     * @param  array{name: string, email: string, phone: string, password: string, role?: string}  $data
     */
    public function createUser(array $data, ?User $creator = null): User
    {
        $role = $data['role'] ?? 'sales';
        $adminId = null;

        if ($role === 'sales' && $creator && $creator->isAdmin()) {
            $adminId = $creator->id;
        }

        return User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'],
            'password'  => $data['password'],
            'role'      => $role,
            'admin_id'  => $adminId,
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
