<?php

namespace App\Http\Requests\Web;

use App\Traits\RecaptchaTrait;
use App\Traits\CalculatorTrait;
use App\Traits\ResponseHandler;
use App\Services\EgyptPhoneService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use Illuminate\Support\Facades\Session;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CustomerRegistrationRequest extends FormRequest
{
    use RecaptchaTrait;
    use CalculatorTrait, ResponseHandler;

    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'f_name' => 'required',
            'email' => 'required|email|unique:users',
            'phone' => ['required', 'regex:/^\+201[0125]\d{8}$/', 'unique:users,phone'],
            'password' => 'required|same:con_password',
            'terms_accepted' => 'required|accepted',
            'privacy_accepted' => 'required|accepted',
            'policy_version_ids' => 'nullable|array',
            'policy_version_ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'f_name.required' => translate('first_name_is_required'),
            'email.unique' => translate('email_already_has_been_taken'),
            'phone.required' => translate('phone_number_is_required'),
            'phone.unique' => translate('phone_number_already_has_been_taken'),
            'phone.regex' => translate('please_provide_valid_phone_number'),
            'terms_accepted.required' => translate('you_must_accept_the_terms_and_conditions'),
            'terms_accepted.accepted' => translate('you_must_accept_the_terms_and_conditions'),
            'privacy_accepted.required' => translate('you_must_accept_the_privacy_policy'),
            'privacy_accepted.accepted' => translate('you_must_accept_the_privacy_policy'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {

            }
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => app(EgyptPhoneService::class)->normalize($this->input('phone')),
        ]);
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json(['errors' => $this->errorProcessor($validator)]));
    }
}
