<?php

namespace App\Http\Requests;

class GuestCheckoutRequest extends CheckoutRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_guest && $this->attributes->has('guest_session');
    }

    public function rules(): array
    {
        $checkout = $this->routeIs('api.v1.guest.checkout');

        return array_replace(parent::rules(), [
            'phone' => ['required', 'regex:/^\+255[67]\d{8}$/'],
            'customer_name' => [$checkout ? 'required' : 'nullable', 'string', 'max:160'],
            'customer_email' => [$checkout ? 'required_unless:payment_method,cash' : 'nullable', 'nullable', 'email', 'max:255'],
        ]);
    }
}
