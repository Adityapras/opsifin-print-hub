<?php

namespace App\Http\Requests\Agent\V1;

use Illuminate\Validation\Rule;

class CommandResultRequest extends AgentApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['succeeded', 'failed'])],
            'message' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'attachment' => ['sometimes', 'file', 'max:10240'],
        ];
    }
}
