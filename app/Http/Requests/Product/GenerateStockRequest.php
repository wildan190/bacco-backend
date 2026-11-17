<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class GenerateStockRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'prefix' => ['nullable', 'string', 'max:20'],
        ];
    }
}
