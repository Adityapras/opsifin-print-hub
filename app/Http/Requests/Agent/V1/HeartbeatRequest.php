<?php

namespace App\Http\Requests\Agent\V1;

use Illuminate\Validation\Rule;

class HeartbeatRequest extends AgentApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_version' => ['required', 'string', 'max:50'],
            'uptime_s' => ['required', 'integer', 'min:0'],
            'agent_time' => ['required', 'date'],
            'config_version' => ['sometimes', 'integer', 'min:0'],
            'capabilities' => ['sometimes', 'array', 'max:20'],
            'capabilities.*' => ['string', 'max:50', 'distinct'],
            'printers' => ['present', 'array', 'max:250'],
            'printers.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'printers.*.driver' => ['sometimes', 'nullable', 'string', 'max:255'],
            'printers.*.port' => ['sometimes', 'nullable', 'string', 'max:255'],
            'printers.*.is_default' => ['sometimes', 'boolean'],
            'printers.*.status' => [
                'required',
                Rule::in(['ONLINE', 'OFFLINE', 'PAPER_OUT', 'PAPER_JAM', 'NO_TONER', 'DOOR_OPEN', 'ERROR', 'UNKNOWN']),
            ],
            'printers.*.raw_status' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'printers.*.queue_length' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
