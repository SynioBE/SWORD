<?php

namespace App\Services\SSH;

class SSHResult
{
    public function __construct(
        public readonly string $output,
        public readonly string $stderr,
        public readonly int $exitCode,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
