<?php

namespace App\Domain\Sources\Services;

final readonly class SourceConnectionTestResult
{
    public function __construct(
        public bool $successful,
        public string $code,
        public string $message,
        public ?int $httpStatus,
        public int $durationMs,
    ) {}
}
