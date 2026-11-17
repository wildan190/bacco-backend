<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
            'assigned_sales_id' => ['nullable', 'integer', 'exists:users,id'],
            'schedule_visit' => ['nullable', 'boolean'],
            'scheduled_date' => ['nullable', 'date'],
            'is_daily' => ['nullable', 'boolean'],
            'schedule_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'schedule_notes' => ['nullable', 'string', 'max:500'],
            'photo'          => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ];
    }
}
