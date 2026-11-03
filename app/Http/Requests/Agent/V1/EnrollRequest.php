<?php

namespace App\Http\Requests\Agent\V1;

class EnrollRequest extends AgentApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pairing_code' => ['required', 'string', 'size:8', 'regex:/^[2-9A-HJ-NP-Z]+$/i'],
            'hostname' => ['required', 'string', 'max:255'],
            'os' => ['required', 'string', 'max:255'],
            'agent_version' => ['required', 'string', 'max:50'],
            'machine_id' => ['required', 'string', 'max:255'],
        ];
    }
}
