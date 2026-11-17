<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'scheduled_date' => ['required', 'date'],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['required', 'exists:stores,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
