<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category_id' => ['nullable', 'integer', 'exists:categories,id'], 'name' => ['required', 'string', 'max:180'], 'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'description' => ['nullable', 'string', 'max:10000'], 'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'], 'currency' => ['sometimes', 'in:PKR'], 'type' => ['sometimes', 'in:supply,print'], 'status' => ['sometimes', 'in:draft,published'], 'quantity' => ['sometimes', 'integer', 'min:0'], 'low_stock_threshold' => ['sometimes', 'integer', 'min:0']];
    }
}
