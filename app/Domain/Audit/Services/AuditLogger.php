<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(User $actor, string $action, Model $subject, array $before, array $after): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'before_data' => $this->redact($before),
            'after_data' => $this->redact($after),
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 1024),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|credential|authorization/i', (string) $key) === 1) {
                $data[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
