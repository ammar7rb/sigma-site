<?php

namespace App\Http\Requests\API\v3;

use App\Traits\ResponseHandler;
use App\Services\EgyptPhoneService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class SellerRegistrationRequest extends FormRequest
{
    use ResponseHandler;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:sellers,email',
            'phone' => ['required', 'regex:/^\+201[0125]\d{8}$/', 'unique:sellers,phone'],
            'password' => 'required|min:8',
            'policy_version_ids' => 'nullable|array',
            'policy_version_ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => translate('Email is required.'),
            'email.unique' => translate('This email is already registered.'),
            'phone.unique' => translate('This phone number is already registered.'),
            'phone.regex' => translate('please_provide_valid_phone_number'),
            'password.min' => translate('Password must be at least 8 characters.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => app(EgyptPhoneService::class)->normalize($this->input('phone')),
        ]);
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => $this->errorProcessor($validator)
            ], 403)
        );
    }
}
