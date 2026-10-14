<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'], 'shipping_address' => ['required', 'array'], 'shipping_address.recipient' => ['required', 'string', 'max:160'], 'shipping_address.phone' => ['nullable', 'string', 'max:32'], 'shipping_address.line1' => ['required', 'string', 'max:190'], 'shipping_address.line2' => ['nullable', 'string', 'max:190'], 'shipping_address.city' => ['required', 'string', 'max:120'], 'shipping_address.region' => ['nullable', 'string', 'max:120'], 'shipping_address.postal_code' => ['nullable', 'string', 'max:24'], 'shipping_address.country' => ['required', 'string', 'size:2']];
    }
}
