<?php

namespace App\Http\Requests\Agent\V1;

class ClaimJobsRequest extends AgentApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'printer_ids' => ['required', 'array', 'max:100'],
            'printer_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
