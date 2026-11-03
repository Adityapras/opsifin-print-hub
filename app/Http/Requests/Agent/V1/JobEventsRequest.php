<?php

namespace App\Http\Requests\Agent\V1;

use App\Domain\Jobs\Enums\AgentJobEventType;
use Illuminate\Validation\Rule;

class JobEventsRequest extends AgentApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_id' => ['required', 'uuid', 'distinct'],
            'events.*.attempt_id' => ['required', 'uuid'],
            'events.*.type' => ['required', Rule::enum(AgentJobEventType::class)],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.data' => ['sometimes', 'array'],
        ];
    }
}
