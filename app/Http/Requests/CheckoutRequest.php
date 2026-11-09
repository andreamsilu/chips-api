<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('customer') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cart_version' => ['required', 'integer', 'min:1'],
            'expected_total' => ['sometimes', 'integer', 'min:1'],
            'fulfilment' => ['required', 'in:delivery,pickup'],
            'payment_method' => ['required', 'in:mpesa,tigo_pesa,airtel_money,cash'],
            'address_id' => ['required_if:fulfilment,delivery', 'nullable', 'ulid'],
            'phone' => ['required_unless:payment_method,cash', 'nullable', 'regex:/^\+255[67]\d{8}$/'],
            'coupon' => ['nullable', 'string', 'max:40'],
            'total' => ['prohibited'],
            'commission' => ['prohibited'],
            'user_id' => ['prohibited'],
            'payment_status' => ['prohibited'],
        ];
    }
}
