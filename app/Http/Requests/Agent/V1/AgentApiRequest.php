<?php

namespace App\Http\Requests\Agent\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class AgentApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $validator->errors()->first(),
            ],
        ], 422));
    }
}
