<?php

namespace App\Services\Hetzner;

readonly class HetznerServerResult
{
    public function __construct(
        public int $serverId,
        public string $name,
        public string $location,
        public string $serverType,
        public string $status,
        public ?string $publicIp,
        public int|string $sshKeyId,
        public ?string $sshKeyName,
        public string $sshKeyStatus,
    ) {}
}
