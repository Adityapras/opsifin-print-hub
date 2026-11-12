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
            'update' => ['sometimes', 'nullable', 'array'],
            'update.command_id' => ['required_with:update', 'uuid'],
            'update.target_version' => ['sometimes', 'string', 'max:50'],
            'update.status' => [
                'required_with:update',
                Rule::in(['downloading', 'ready', 'waiting_for_idle', 'installing', 'failed']),
            ],
            'update.progress_percent' => ['sometimes', 'integer', 'between:0,100'],
            'update.message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'update.updated_at' => ['sometimes', 'date'],
        ];
    }
}
